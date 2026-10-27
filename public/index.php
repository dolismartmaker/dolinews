<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    // The API keeps its error envelope while the site shows its page
    // (bootstrap/maintenance-api.php). Guarded: a deploy rewriting the
    // tree must not turn a missing file into a fatal error on every path.
    if (is_file($apiMaintenance = __DIR__.'/../bootstrap/maintenance-api.php')) {
        $apiAnswer = (require $apiMaintenance)(
            __DIR__.'/../storage/framework/down',
            (string) ($_SERVER['REQUEST_URI'] ?? '/'),
            $_COOKIE,
        );

        if ($apiAnswer !== null) {
            http_response_code($apiAnswer['status']);

            foreach ($apiAnswer['headers'] as $name => $value) {
                header($name.': '.$value);
            }

            echo $apiAnswer['body'];

            exit;
        }
    }

    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
