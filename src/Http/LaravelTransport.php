<?php

declare(strict_types=1);

namespace Authentik\Http;

use Authentik\Contracts\Transport;

/**
 * Laravel's HTTP client, behind the transport interface.
 *
 * Used by the service provider, so a Laravel application keeps the usual
 * benefits: fakes in tests, retries, logging, and the shared client config.
 */
final class LaravelTransport implements Transport
{
    public function __construct(private readonly int $timeout = 10)
    {
    }

    public function get(string $url, array $headers = []): array
    {
        $response = \Illuminate\Support\Facades\Http::withHeaders($headers)
            ->timeout($this->timeout)
            ->acceptJson()
            ->get($url);

        return ['status' => $response->status(), 'body' => $response->body()];
    }

    public function post(string $url, array $body = [], array $headers = []): array
    {
        $response = \Illuminate\Support\Facades\Http::withHeaders($headers)
            ->asForm()
            ->timeout($this->timeout)
            ->post($url, $body);

        return ['status' => $response->status(), 'body' => $response->body()];
    }
}
