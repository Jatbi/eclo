<?php
namespace ECLO;
require 'vendor/autoload.php';
use Medoo\Medoo;
use Verot\Upload\Upload;
use \Firebase\JWT\JWT;
use \Firebase\JWT\Key;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;
class App{
    private $routes = [];
    private $permissions = [];
    private $currentPermissions = [];
    private $userPermissions = [];
    private $currentRoute = null;
    private $components = [];
    private $controllers = [];
    private $globalFile = null;
    private $errorFile = null;
    private $cookies = [];
    private $sessions = [];
    private $blockedIps = [];
    private $database = null;
    private $allowed_protocols = array(), $allowed_tags = array();
    private $jwtKey;
    private $jwtAlgorithm;
    private $valueData = [];
    // Khởi tạo với hoặc không có Medoo
    public function __construct($dbConfig = null) {
        if ($dbConfig) {
            $this->database = new Medoo($dbConfig);
        }
    }
    // Cài đặt dữ liệu toàn cục
    public function setValueData(string $key, $value) {
        $this->valueData[$key] = $value;
    }

    // Lấy dữ liệu toàn cục
    public function getValueData($key) {
        return isset($this->valueData[$key]) ? $this->valueData[$key] : null;
    }
    // Phương thức để upload file
    public function upload($fileField) {
        return new Upload($fileField);
    }
    // Thực hiện truy vấn cơ sở dữ liệu
    public function __call($method, $args) {
        if ($this->database && method_exists($this->database, $method)) {
            return call_user_func_array([$this->database, $method], $args);
        }
        if (class_exists('Verot\Upload\Upload') && method_exists('Verot\Upload\Upload', $method)) {
            return call_user_func_array([new Upload($_FILES['image_field']), $method], $args);
        }
        if (!$this->database) {
            throw new BadMethodCallException("Database not initialized. $method cannot be called.");
        }
        throw new BadMethodCallException("$method does not exist.");
    }
    // Cấu hình PHP Mailer
    public function Mail($options){
        $mail = new PHPMailer(true);
        try {
            // Server settings
            $mail->isSMTP();                                      // Gửi bằng SMTP
            $mail->Host       = $options['host'];                 // Địa chỉ SMTP server
            $mail->SMTPAuth   = true;                             // Kích hoạt xác thực SMTP
            $mail->Username   = $options['username'];             // SMTP username
            $mail->Password   = $options['password'];             // SMTP password
            if (in_array($options['encryption'], ['tls', 'ssl', 'smtp'])) {
                if($options['encryption']=='smtp'){
                    $options['encryption'] = PHPMailer::ENCRYPTION_SMTPS;
                }
                $mail->SMTPSecure = $options['encryption'];
            } else {
                throw new Exception('Invalid encryption type. Allowed values are tls, ssl, PHPMailer::ENCRYPTION_STARTTLS, or PHPMailer::ENCRYPTION_SMTPS.');
            }
            $mail->Port       = isset($options['port']) ? $options['port'] : 587;    // Cổng
            // Sender info
            $mail->setFrom($options['from_email'], $options['from_name']); // Người gửi email

            return $mail; // Trả về đối tượng mail đã được cấu hình
        } catch (Exception $e) {
            throw new Exception("Mailer Error: " . $mail->ErrorInfo);
        }
    }
    // Cấu hình JWT
    public function JWT($key, $algorithm = 'HS256'){
        $this->jwtKey = $key;
        $this->jwtAlgorithm = $algorithm;
    }
    // Tạo JWT
    public function addJWT($payload){
        if (!$this->jwtKey) {
            throw new \Exception('JWT key not configured');
        }
        return JWT::encode($payload, $this->jwtKey, $this->jwtAlgorithm);
    }
    // Giải mã JWT
    public function decodeJWT($token,$header = null){
        if (!$this->jwtKey) {
            throw new \Exception('JWT key not configured');
        }
        try {
            // Đảm bảo rằng tham số thứ ba là mảng chứa thuật toán
            if($header){
                $headers = new \stdClass();
                $decoded = JWT::decode($token, new Key($this->jwtKey, $this->jwtAlgorithm), $headers);
                // Trả về một mảng chứa các giá trị giải mã và tiêu đề nếu cần
                return [
                    'decoded' => $decoded,
                    'headers' => $headers
                ];
            }
            else {
                return JWT::decode($token, new Key($this->jwtKey, $this->jwtAlgorithm));
            }
            
        } catch (\Exception $e) {
            return null;
        }
    }
    // Kiểm tra JWT
    public function validateJWT($token)
    {
        return $this->decodeJWT($token) !== null;
    }
    // Đăng ký route với phương thức GET
    public function router($route, $method, $callback = null){
        return $this->registerRoute($method, $route, $callback);
    }
    // Đăng ký component
    public function setComponent($name, $callback){
        $this->components[$name] = $callback;
        return $this;
    }
    // Thiết lập header cho phản hồi
    public function header($headers) {
        foreach ($headers as $key => $value) {
            header("$key: $value");
        }
    }
    // Render component
    public function component($name, $vars = []){
        $vars = array_merge($this->valueData, $vars);
        if (isset($this->components[$name])) {
            ob_start();
            $callback = $this->components[$name];
            $callback($vars);
            return ob_get_clean();
        }
        return "Component not found";
    }
    // Đăng ký route chung
       private function registerRoute($method, $route, $callback = null){
        if ($callback === null && is_callable($route)) {
            $callback = $route;
            $route = $this->currentRoute;
        }
        if ($route && $callback) {
            // Chuyển route động sang biểu thức chính quy
            $route = preg_replace('/\{([a-zA-Z0-9_]+)\}/', '(?P<\1>[a-zA-Z0-9_-]+)', $route);
            $this->routes[$method][$route] = function($vars) use ($callback) {
                $callback($vars);
            };
            // Lưu lại route hiện tại
            $this->currentRoute = $route;
        } else {
            $this->currentRoute = $route;
        }
        return $this;
    }
    // Đăng ký controller với các route
    public function request($prefix, $controllerPath){
        if (file_exists($controllerPath)) {
            $app = $this;
            $controller = require $controllerPath;
            if (is_callable($controller)) {
                $this->controllers[$prefix] = $controller;
                $controller($this);
            }
        }
        return $this;
    }
    // Đặt file tổng
    public function setGlobalFile($filePath){
        $this->globalFile = $filePath;
        return $this;
    }
    // Đặt file lỗi
    public function setErrorFile($filePath){
        $this->errorFile = $filePath;
        return $this;
    }
    // Đặt quyền hạn cho route
    public function setPermissions($permissions){
        if ($this->currentRoute) {
            $this->setCurrentPermissions($this->currentRoute, $permissions);
            // Không cần reset lại currentRoute ở đây vì đã lưu trong registerRoute
        }
        return $this;
    }
    public function setCurrentPermissions($route, $permissions){
        $this->permissions[$route] = $permissions;
        return $this;
    }
    // Đăng nhập quyền hạn của người dùng
    public function setUserPermissions($userPermissions){
        $this->userPermissions = $userPermissions;
    }
    // Kiểm tra xem người dùng có quyền truy cập route không
    private function checkPermission($route){
        if (!isset($this->permissions[$route])) {
            return true; // Nếu không có giới hạn, cho phép truy cập
        }
        if (is_array($this->permissions[$route]) || is_object($this->permissions[$route])) {
            foreach ($this->permissions[$route] as $permission) {
                if (!in_array($permission, $this->userPermissions)) {
                    return false; // Nếu không có quyền, từ chối truy cập
                }
            }
        }
        return true; // Có quyền truy cập
    }
    // Chạy ứng dụng, xử lý route
    public function run(){
        // Kiểm tra và chặn IP trước khi xử lý yêu cầu
        $this->checkBlockedIp();
        $method = $_SERVER['REQUEST_METHOD'];
        $path = $_SERVER['REQUEST_URI'];
        // Xóa query string khỏi path
        $path = strtok($path, '?');
        // Xử lý route từ các controller đã đăng ký
        foreach ($this->controllers as $prefix => $controller) {
            if (strpos($path, $prefix) === 0) {
                $subPath = substr($path, strlen($prefix));
                $routeVars = $this->matchRoute($method, $prefix . $subPath);
                if ($routeVars !== false) {
                    if ($this->checkPermission($prefix . $subPath)) {
                        call_user_func($this->routes[$method][$routeVars['route']], $routeVars['vars']);
                        return;
                    } else {
                        include $this->errorFile;
                        return;
                    }
                }
            }
        }
        // Kiểm tra route trong GET, POST
        $routeVars = $this->matchRoute($method, $path);
        if ($routeVars !== false) {
            if ($this->checkPermission($routeVars['route'])) {
                call_user_func($this->routes[$method][$routeVars['route']], $routeVars['vars']);
            } else {
               include $this->errorFile;
            }
        } else {
            include $this->errorFile;
        }
    }
    // Hàm khớp route và lấy biến từ route
    private function matchRoute($method, $path){
        foreach ($this->routes[$method] as $route => $callback) {
            $pattern = '#^' . $route . '$#';
            if (preg_match($pattern, $path, $matches)) {
                return ['route' => $route, 'vars' => array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY)];
            }
        }
        return false;
    }
    // Render HTML template
    public function render($templatePath, $vars = [], $ajax = null){
        $vars = array_merge($this->valueData, $vars);
        if (file_exists($templatePath)) {
            extract($vars);
            ob_start();
            $app = $this;
            if ($ajax != 'global') {
                include $this->globalFile;
            } else {
                include $templatePath;
            }

            return ob_get_clean();
        }
        return "Template not found";
    }
    
    // Thiết lập cookie
    public function setCookie($name, $value, $expire = 0, $path = '', $domain = '', $secure = false, $httponly = false) {
        setcookie($name, $value, $expire, $path, $domain, $secure, $httponly);
        $this->cookies[$name] = $value;
    }
    // Lấy cookie
    public function getCookie($name) {
        return isset($_COOKIE[$name]) ? $_COOKIE[$name] : null;
    }
    // Xóa cookie
    public function deleteCookie($name) {
        setcookie($name, '', time() - 3600);
        unset($this->cookies[$name]);
    }
    // Thiết lập session
    public function setSession($key, $value) {
        $_SESSION[$key] = $value;
        $this->sessions[$key] = $value;
    }
    // Lấy session
    public function getSession($key) {
        return isset($_SESSION[$key]) ? $_SESSION[$key] : null;
    }
    // Xóa session
    public function deleteSession($key) {
        unset($_SESSION[$key]);
        unset($this->sessions[$key]);
    }
    // Khởi tạo session
    public function startSession() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }
     // Gửi yêu cầu GET đến API
    public function apiGet($url, $headers = []){
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $response = curl_exec($ch);
        curl_close($ch);
        return $response;
    }

    // Gửi yêu cầu POST đến API
    public function apiPost($url, $data = [], $headers = []){
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $response = curl_exec($ch);
        curl_close($ch);
        return $response;
    }

    // Gửi yêu cầu PUT đến API
    public function apiPut($url, $data = [], $headers = []){
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $response = curl_exec($ch);
        curl_close($ch);
        return $response;
    }

    // Gửi yêu cầu DELETE đến API
    public function apiDelete($url, $headers = []){
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $response = curl_exec($ch);
        curl_close($ch);
        return $response;
    }
    // Chuyển hướng
    public function redirect($url, $statusCode = 302) {
        header('Location: ' . $url, true, $statusCode);
        exit();
    }
    // Quay lại trang trước
    public function back($statusCode = 302) {
        $url = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '/';
        $this->redirect($url, $statusCode);
    }
    // Lấy thời gian hiện tại
    public function currentTime($format = 'Y-m-d H:i:s') {
        return date($format);
    }
    // Định dạng ngày
    public function formatDate($timestamp, $format = 'Y-m-d H:i:s') {
        return date($format, $timestamp);
    }
    // Thêm thời gian
    public function addTime($timestamp, $interval, $format = 'Y-m-d H:i:s') {
        $date = new DateTime();
        $date->setTimestamp($timestamp);
        $date->add(new DateInterval($interval));
        return $date->format($format);
    }
    // Tính hiệu số thời gian
    public function diffTime($start, $end) {
        $startDate = new DateTime();
        $startDate->setTimestamp($start);
        $endDate = new DateTime();
        $endDate->setTimestamp($end);
        $diff = $startDate->diff($endDate);
        return $diff->format('%y years %m months %d days %h hours %i minutes %s seconds');
    }
    public function randomNumber($min, $max) {
        return mt_rand($min, $max);
    }
    // Tạo chuỗi ký tự ngẫu nhiên với độ dài nhất định
    public function randomString($length = 10, $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789') {
        $str = '';
        $charLength = strlen($chars) - 1;
        for ($i = 0; $i < $length; $i++) {
            $str .= $chars[mt_rand(0, $charLength)];
        }
        return $str;
    }
    // Format URL SEO-friendly với ký tự Latin
    public function formatUrl($string) {
        // Chuyển chuỗi thành chữ thường
        $string = strtolower(trim($string));
        // Thay thế các ký tự không phải Latin bằng dấu gạch ngang
        $string = preg_replace('/[^a-z0-9-]/', '-', $string);
        // Loại bỏ các dấu gạch ngang liên tiếp
        $string = preg_replace('/-+/', '-', $string);
        // Loại bỏ dấu gạch ngang ở đầu và cuối chuỗi
        $string = trim($string, '-');
        return $string;
    }
    // Cắt chuỗi đến độ dài tối đa
    public function truncateString($string, $length, $suffix = '...') {
        if (strlen($string) > $length) {
            return substr($string, 0, $length) . $suffix;
        }
        return $string;
    }
    // Cắt ký tự từ chuỗi
    public function cutCharacters($string, $start, $length) {
        return substr($string, $start, $length);
    }
    // Kiểm tra địa chỉ IP hợp lệ
    public function isValidIp($ip) {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }
    // Kiểm tra địa chỉ IP của client đang truy cập
    public function checkClientIp() {
        return $_SERVER['REMOTE_ADDR'];
    }
    // Kiểm tra thiết bị truy cập (ví dụ: mobile hoặc desktop)
    public function checkDevice() {
        $userAgent = $_SERVER['HTTP_USER_AGENT'];
        if (preg_match('/Mobile|Android|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/', $userAgent)) {
            return 'mobile';
        }
        return 'desktop';
    }
    // Thêm IP vào danh sách chặn
    public function blockIp($ip) {
        $this->blockedIps[] = $ip;
        return $this;
    }

    // Kiểm tra và chặn IP
    public function checkBlockedIp() {
        $clientIp = $this->checkClientIp();
        if (in_array($clientIp, $this->blockedIps)) {
            http_response_code(403);
            die("403 - Forbidden: Your IP is blocked.");
        }
    }
    public function addAllowedProtocols($protocols){
        $this->allowed_protocols = (array)$protocols;
    }
    public function addAllowedTags($tags){
        $this->allowed_tags = (array)$tags;
    }
    public function xss($string,$html=null){
        if (!$this->isUtf8($string)) {
            return '';
        }
        $string = str_replace(chr(0), '', $string);
        $string = preg_replace('%&\s*\{[^}]*(\}\s*;?|$)%', '', $string);
        $string = str_replace('&', '&amp;', $string);
        $string = preg_replace('/&amp;#([0-9]+;)/', '&#\1', $string);
        $string = preg_replace('/&amp;#[Xx]0*((?:[0-9A-Fa-f]{2})+;)/', '&#x\1', $string);
        $string = preg_replace('/&amp;([A-Za-z][A-Za-z0-9]*;)/', '&\1', $string);
        if($html=="true"){
            $string = htmlspecialchars($string);
        }
        return preg_replace_callback('%
            (
            <(?=[^a-zA-Z!/])  # a lone <
            |                 # or
            <!--.*?-->        # a comment
            |                 # or
            <[^>]*(>|$)       # a string that starts with a <, up until the > or the end of the string
            |                 # or
            >                 # just a >
            )%x', array($this, 'split'), $string);
    }
    private function isUtf8($string){
        if (strlen($string) == 0) {
            return true;
        }
        return (preg_match('/^./us', $string) == 1);
    }
    private function split($m){ 
        $string = $m[1];
        if (substr($string, 0, 1) != '<') {
            return '&gt;';
        } elseif (strlen($string) == 1) {
            return '&lt;';
        }
        if (!preg_match('%^<\s*(/\s*)?([a-zA-Z0-9\-]+)([^>]*)>?|(<!--.*?-->)$%', $string, $matches)) {
            return '';
        }
        $slash = trim($matches[1]);
        $elem = &$matches[2];
        $attrlist = &$matches[3];
        $comment = &$matches[4];
        if ($comment) {
            $elem = '!--';
        }
        if (!in_array(strtolower($elem), $this->allowed_tags, true)) {
            return '';
        }
        if ($comment) {
            return $comment;
        }
        if ($slash != '') {
            return "</$elem>";
        }
        $attrlist = preg_replace('%(\s?)/\s*$%', '\1', $attrlist, -1, $count);
        $xhtml_slash = $count ? ' /' : '';
        $attr2 = implode(' ', $this->attributes($attrlist));
        $attr2 = preg_replace('/[<>]/', '', $attr2);
        $attr2 = strlen($attr2) ? ' ' . $attr2 : '';
        return "<$elem$attr2$xhtml_slash>";
    }
    private function attributes($attr) {
        $attrarr = array();
        $mode = 0;
        $attrname = '';
        while (strlen($attr) != 0) {
            $working = 0;
            switch ($mode) {
                case 0:
                    if (preg_match('/^([-a-zA-Z]+)/', $attr, $match)) {
                        $attrname = strtolower($match[1]);
                        $skip = ($attrname == 'style' || substr($attrname, 0, 2) == 'on');
                        $working = $mode = 1;
                        $attr = preg_replace('/^[-a-zA-Z]+/', '', $attr);
                    }
                    break;
                case 1:
                    if (preg_match('/^\s*=\s*/', $attr)) {
                        $working = 1;
                        $mode = 2;
                        $attr = preg_replace('/^\s*=\s*/', '', $attr);
                        break;
                    }
                    
                    if (preg_match('/^\s+/', $attr)) {
                        $working = 1;
                        $mode = 0;
                        
                        if (!$skip) {
                            $attrarr[] = $attrname;
                        }
                        
                        $attr = preg_replace('/^\s+/', '', $attr);
                    }
                    break;
                case 2:
                    if (preg_match('/^"([^"]*)"(\s+|$)/', $attr, $match)) {
                        $thisval = $this->badProtocol($match[1]);
                        
                        if (!$skip) {
                            $attrarr[] = "$attrname=\"$thisval\"";
                        }
                        
                        $working = 1;
                        $mode = 0;
                        $attr = preg_replace('/^"[^"]*"(\s+|$)/', '', $attr);
                        break;
                    }
                    if (preg_match("/^'([^']*)'(\s+|$)/", $attr, $match)) {
                        $thisval = $this->badProtocol($match[1]);
                        
                        if (!$skip) {
                            $attrarr[] = "$attrname='$thisval'";
                        }
                        
                        $working = 1;
                        $mode = 0;
                        $attr = preg_replace("/^'[^']*'(\s+|$)/", '', $attr);
                        break;
                    }
                    if (preg_match("%^([^\s\"']+)(\s+|$)%", $attr, $match)) {
                        $thisval = $this->badProtocol($match[1]);
                        
                        if (!$skip) {
                            $attrarr[] = "$attrname=\"$thisval\"";
                        }
                        
                        $working = 1;
                        $mode = 0;
                        $attr = preg_replace("%^[^\s\"']+(\s+|$)%", '', $attr);
                    }
                break;
            }
            
            if ($working == 0) {
                $attr = preg_replace('/
                ^
                (
                "[^"]*("|$)     # - a string that starts with a double quote, up until the next double quote or the end of the string
                |               # or
                \'[^\']*(\'|$)| # - a string that starts with a quote, up until the next quote or the end of the string
                |               # or
                \S              # - a non-whitespace character
                )*              # any number of the above three
                \s*             # any number of whitespaces
                /x', '', $attr);
                
                $mode = 0;
            }
        }
        if ($mode == 1 && !$skip) {
            $attrarr[] = $attrname;
        }
        return $attrarr;
    }
    private function badProtocol($string) {
        $string = html_entity_decode($string, ENT_QUOTES, 'UTF-8');
        return htmlspecialchars($this->stripDangerousProtocols($string), ENT_QUOTES, 'UTF-8');
    }
    private function stripDangerousProtocols($uri){
        do {
            $before = $uri;
            $colonpos = strpos($uri, ':');
            if ($colonpos > 0) {
                $protocol = substr($uri, 0, $colonpos);
                if (preg_match('![/?#]!', $protocol)) {
                    break;
                }
                if (!in_array(strtolower($protocol), $this->allowed_protocols, true)) {
                    $uri = substr($uri, $colonpos + 1);
                }
            }
        } while ($before != $uri);
        return $uri;
    } 
}
?>
