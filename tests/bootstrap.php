<?php

declare(strict_types=1);

/**
 * Test bootstrap.
 *
 * The package has no runtime dependency beyond the framework, and these tests
 * deliberately do not introduce one. They reuse the framework already installed
 * in a sibling Laravel application, so the suite runs without a lengthy
 * dependency install and still exercises the real Illuminate classes rather
 * than stubs.
 *
 * Override with LARAVEL_VENDOR if the application lives elsewhere:
 *   LARAVEL_VENDOR=/path/to/app/vendor LARAVEL_BIN=/path/to/app/vendor/bin/phpunit phpunit
 */

$vendor = getenv('LARAVEL_VENDOR') ?: __DIR__.'/../../sis/vendor';

if (! is_file($vendor.'/autoload.php')) {
    fwrite(STDERR, "Could not find a Laravel autoloader at {$vendor}/autoload.php\n");
    fwrite(STDERR, "Set LARAVEL_VENDOR to an application's vendor directory.\n");
    exit(1);
}

require $vendor.'/autoload.php';

// Register this package's own namespace, which the application's autoloader
// knows nothing about.
spl_autoload_register(static function (string $class): void {
    $prefix = 'Authentik\\';

    if (! str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = __DIR__.'/../src/'.str_replace('\\', '/', $relative).'.php';

    if (is_file($path)) {
        require $path;
    }
});
