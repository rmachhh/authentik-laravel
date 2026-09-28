<?php

declare(strict_types=1);

namespace Authentik;

use Authentik\Console\DoctorCommand;
use Authentik\Http\LaravelTransport;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/authentik.php', 'authentik');

        $this->app->singleton(FailoverAuthentikClient::class, function ($app) {
            /** @var ConfigRepository $config */
            $config = $app['config'];

            $instances = [];
            $transport = new LaravelTransport((int) $config->get('authentik.timeout', 10));

            foreach ($config->get('authentik.instances', []) as $label => $settings) {
                $instances[] = new AuthentikClient(
                    label: (string) ($settings['label'] ?? $label),
                    issuer: (string) ($settings['issuer'] ?? ''),
                    clientId: (string) ($settings['client_id'] ?? ''),
                    clientSecret: (string) ($settings['client_secret'] ?? ''),
                    redirectUri: (string) ($settings['redirect_uri'] ?? $config->get('authentik.redirect_uri', '')),
                    scopes: $settings['scopes'] ?? $config->get('authentik.scopes', ['openid', 'email', 'profile']),
                    allowInsecure: (bool) ($settings['allow_insecure'] ?? $config->get('authentik.allow_insecure', false)),
                    timeout: (int) ($settings['timeout'] ?? $config->get('authentik.timeout', 10)),
                    transport: $transport,
                );
            }

            $onFailover = $config->get('authentik.on_failover');

            return new FailoverAuthentikClient($instances, $onFailover);
        });

        // Built from configuration: the constructor takes a URL and a token,
        // which the container cannot guess.
        $this->app->singleton(AuthentikUserImport::class, function ($app) {
            $config = $app['config'];

            return new AuthentikUserImport(
                baseUrl: rtrim((string) $config->get('authentik.admin_base_url', ''), '/'),
                token: (string) $config->get('authentik.admin_token', ''),
                appGroup: (string) $config->get('authentik.app_group', ''),
                timeout: (int) $config->get('authentik.timeout', 15),
                transport: new LaravelTransport((int) $config->get('authentik.timeout', 15)),
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                DoctorCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/authentik.php' => config_path('authentik.php'),
            ], 'authentik-config');
        }

        // Registered as an alias so routes read `authentik.group` rather than a
        // fully-qualified class name.
        $this->app['router']->aliasMiddleware('authentik.group', Http\Middleware\RequireAppGroup::class);
    }
}
