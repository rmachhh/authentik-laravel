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

// Load this package's source explicitly, before anything can autoload it.
//
// A sibling application may also have the package installed in its vendor
// directory. Composer's autoloader is registered first, so without this the
// suite would silently exercise that installed copy instead of the code being
// edited here — which is exactly how a fix can appear to do nothing.
$sourceRoot = __DIR__.'/../src';

spl_autoload_register(static function (string $class) use ($sourceRoot): void {
    $prefix = 'Authentik\\';

    if (! str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = $sourceRoot.'/'.str_replace('\\', '/', $relative).'.php';

    if (is_file($path)) {
        require $path;
    }
}, true, true);  // prepend: this package's source wins over an installed copy

foreach ([
    'AuthentikException',
    'Contracts/Transport',
    'Http/NativeTransport',
    'AuthentikUserImport',
] as $required) {
    $file = $sourceRoot.'/'.$required.'.php';
    if (is_file($file) && ! class_exists('Authentik\\'.str_replace('/', '\\', $required), false)) {
        require_once $file;
    }
}
