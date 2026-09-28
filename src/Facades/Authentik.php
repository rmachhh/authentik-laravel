<?php

declare(strict_types=1);

namespace Authentik\Facades;

use Authentik\FailoverAuthentikClient;
use Illuminate\Support\Facades\Facade;

/**
 * @method static array{url: string, flow: \Authentik\FlowState} startAuthorization()
 * @method static \Authentik\AuthentikUser completeLogin(array $query, \Authentik\FlowState $flow)
 * @method static \Authentik\AuthentikClient first()
 * @method static \Authentik\AuthentikClient|null find(string $label)
 * @method static list<\Authentik\AuthentikClient> instances()
 *
 * @see FailoverAuthentikClient
 */
class Authentik extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return FailoverAuthentikClient::class;
    }
}
