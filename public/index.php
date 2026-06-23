<?php

declare(strict_types=1);

// Register PSR-4 Autoloader for namespaces starting with "App\" mapped to "src/"
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    $baseDir = __DIR__ . '/../src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});

use App\Core\App;
use App\Core\Env;
use App\Core\ExceptionHandler;

Env::load(__DIR__ . '/../.env');
ExceptionHandler::register();

$app = new App();
$app->run();
