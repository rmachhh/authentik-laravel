<?php

declare(strict_types=1);

namespace Authentik\Contracts;

/**
 * The HTTP calls the client needs, behind a one-method-per-verb interface.
 *
 * Exists so the client can be exercised without booting a framework. In a
 * Laravel application this is bound to the framework's HTTP client; in a CLI
 * script or a test it can be anything that makes requests.
 */
interface Transport
{
    /**
     * @param  array<string, string>  $headers
     * @return array{status: int, body: string}
     */
    public function get(string $url, array $headers = []): array;

    /**
     * @param  array<string, string>  $body
     * @param  array<string, string>  $headers
     * @return array{status: int, body: string}
     */
    public function post(string $url, array $body = [], array $headers = []): array;
}
