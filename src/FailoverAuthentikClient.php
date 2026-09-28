<?php

declare(strict_types=1);

namespace Authentik;

use Throwable;

/**
 * Several authentik instances, tried in order.
 *
 * Failover happens when a sign-in **starts**. A callback completes against the
 * instance that issued it, because an authorization code can only be exchanged
 * by its issuer — failing over mid-flow would break the exchange and look like
 * a login loop.
 *
 * Configuration mistakes (a bad issuer, plain HTTP) are not failed over. A typo
 * must not look like an outage, and silently falling through would hide it.
 */
final class FailoverAuthentikClient
{
    /** @var list<AuthentikClient> */
    private array $instances;

    /**
     * @param  list<AuthentikClient>  $instances  tried in this order
     * @param  (callable(Throwable, AuthentikClient): void)|null  $onFailover
     */
    public function __construct(array $instances, private $onFailover = null)
    {
        if ($instances === []) {
            throw new AuthentikException('At least one authentik instance is required');
        }

        $this->instances = array_values($instances);
    }

    /** @return list<AuthentikClient> */
    public function instances(): array
    {
        return $this->instances;
    }

    public function first(): AuthentikClient
    {
        return $this->instances[0];
    }

    public function find(string $label): ?AuthentikClient
    {
        foreach ($this->instances as $instance) {
            if ($instance->label === $label) {
                return $instance;
            }
        }

        return null;
    }

    /**
     * Try each instance until one starts a sign-in.
     *
     * @return array{url: string, flow: FlowState}
     */
    public function startAuthorization(): array
    {
        $lastError = null;

        foreach ($this->instances as $instance) {
            try {
                return $instance->startAuthorization();
            } catch (AuthentikException $error) {
                if (! self::isUnreachable($error)) {
                    throw $error;
                }

                $lastError = $error;

                if ($this->onFailover !== null) {
                    ($this->onFailover)($error, $instance);
                }
            }
        }

        throw new AuthentikException(
            'No authentik instance is reachable. Tried: '.
            implode(', ', array_map(static fn (AuthentikClient $i) => $i->label, $this->instances)).
            '. Last error: '.($lastError?->getMessage() ?? 'unknown')
        );
    }

    /**
     * Complete a sign-in against the instance that started it.
     *
     * @param  array<string, mixed>  $query
     */
    public function completeLogin(array $query, FlowState $flow): AuthentikUser
    {
        $instance = $this->find($flow->instance);

        if ($instance === null) {
            throw new AuthentikException(
                "Sign-in was started by '{$flow->instance}', which is not configured on this instance list"
            );
        }

        return $instance->completeLogin($query, $flow);
    }

    /**
     * Is this a reachability problem, or a configuration mistake?
     *
     * Only the former is worth trying the next instance for. Everything else is
     * a bug that should be visible.
     */
    private static function isUnreachable(AuthentikException $error): bool
    {
        $message = $error->getMessage();

        foreach (['Discovery failed', 'Connection', 'timed out', 'cURL error', 'Name or service'] as $needle) {
            if (stripos($message, $needle) !== false) {
                return true;
            }
        }

        return false;
    }
}
