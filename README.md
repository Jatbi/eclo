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
$app 		= new App($dbConfig);
```
