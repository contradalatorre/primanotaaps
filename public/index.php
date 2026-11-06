<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Il .htaccess della radice riscrive verso public/ senza cambiare l'URL: l'app deve partire dalla
// cartella che contiene public/ (es. /contabilita), non da /contabilita/public.
$_SERVER['SCRIPT_NAME'] = rtrim(dirname($_SERVER['SCRIPT_NAME'], 2), '/').'/index.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
