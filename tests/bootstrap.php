<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'Miit\\Tests\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($path)) {
        require_once $path;
    }
});
