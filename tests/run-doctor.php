<?php

declare(strict_types=1);

/**
 * Run `authentik:doctor` inside a real Laravel container.
 *
 *   php tests/run-doctor.php
 *
 * Configuration comes from the environment, or from a sibling application's
 * .env via LARAVEL_ENV. This exercises the command the way it runs in an
 * application, which a unit test cannot.
 */

require __DIR__.'/bootstrap.php';

use Authentik\ServiceProvider;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;

$envPath = getenv('LARAVEL_ENV') ?: __DIR__.'/../../sis/.env';

function envFrom(string $path, string $key): ?string
{
    if (! is_file($path)) {
        return null;
    }

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#')) {
            continue;
        }
        if (preg_match('/^'.preg_quote($key, '/').'=(.*)$/', trim($line), $m)) {
            return trim($m[1], "\"'");
        }
    }

    return null;
}

$value = static fn (string $key): ?string => getenv($key) ?: envFrom($envPath, $key);

$app = new Application(getcwd());
$app->singleton('config', fn () => new Repository());
$app->singleton('events', fn ($app) => new Dispatcher($app));
$app->singleton('log', fn () => new class {
    public function warning(...$args): void {}
    public function __call($name, $args) {}
});
Container::setInstance($app);
// Laravel's HTTP facade resolves through this root.
\Illuminate\Support\Facades\Facade::setFacadeApplication($app);

config([
    'authentik.instances.primary.issuer' => $value('AUTHENTIK_ISSUER'),
    'authentik.instances.primary.client_id' => $value('AUTHENTIK_CLIENT_ID'),
    'authentik.instances.primary.client_secret' => $value('AUTHENTIK_CLIENT_SECRET'),
    'authentik.redirect_uri' => $value('AUTHENTIK_REDIRECT_URI'),
    'authentik.app_group' => $value('APP_GROUP') ?: 'records-access',
    'authentik.timeout' => 10,
    // A local authentik container serves plain HTTP; production must not.
    'authentik.allow_insecure' => str_starts_with((string) $value('AUTHENTIK_ISSUER'), 'http://'),
]);

$app->register(ServiceProvider::class);
$app->boot();

// Run the command directly: no console kernel needed for this check.
$command = $app->make(\Authentik\Console\DoctorCommand::class);
$command->setLaravel($app);
$output = new \Illuminate\Console\OutputStyle(
    new \Symfony\Component\Console\Input\ArrayInput([]),
    new \Symfony\Component\Console\Output\ConsoleOutput(),
);
$command->setOutput($output);

exit($command->handle($app->make(\Authentik\FailoverAuthentikClient::class)));
