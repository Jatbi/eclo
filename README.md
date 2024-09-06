## Yêu cầu
- PHP 8.1+
## Cài đặt
```bash
$ composer require eclo/app
```

## Cấu hình

```php
// Require Composer's autoloader.
require 'vendor/autoload.php';

// sử dụng ECLO namespace.
use ECLO\App;
$app 	= new App();

// hoặc sử dụng với database

use ECLO\App;

$dbConfig = [
	// [required]
	'type' => 'mysql',
	'host' => 'localhost',
	'database' => '.......',
	'username' => '.......',
	'password' => '.......',
	// [optional]
	'charset' => 'utf8mb4',
	'collation' => 'utf8mb4_general_ci',
	'port' => 3306,
	'prefix' => '',
	'logging' => true,
	'error' => PDO::ERRMODE_SILENT,
	'option' => [
		PDO::ATTR_CASE => PDO::CASE_NATURAL
	],
	'command' => [
		'SET SQL_MODE=ANSI_QUOTES'
	]
];
$app = new App($dbConfig);
```
Thiết lặp router
```php
$app->get("/home", function($vars) {
    $hello = 'Hello ECLO';
    echo $hello;
});
```
Thiết lặp router với ID 
```php
$app->get("/home/{id}", function($vars) {
    $hello = 'Hello '.$vard['id'];
    echo $hello;
});
```
Thiết lặp router với POST
```php
$app->post("/home", function($vars) {
    $hello = 'Hello '.$vard['id'];
    echo $hello;
});
```
Sử dụng router với app
```php
$app->get("/home", function($vars) use ($app) {
   $app->header([
    'Content-Type' => 'application/json',
   ]);
   $response = [
     'message' => 'This is the home page.',
     'data' => $vars
   ];
   echo json_encode($response);
});
```
Sử dụng router với app có database 

```php
$app->get("/home", function($vars) use ($app) {
   $app->header([
    'Content-Type' => 'application/json',
   ]);
   $datas = $app->select("table","*",["email"=>"info@eclo.vn"]);
   echo json_encode($datas);
});
```
Set quyền truy cập cho router

```php
$userPermissions = ["home","home.add"];
$app->setUserPermissions($userPermissions);
$app->get("/home", function($vars) {
    $hello = 'Hello '.$vard['id'];
    echo $hello;
})->setPermissions(["home"]);
```
