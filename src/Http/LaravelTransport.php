<?php

declare(strict_types=1);

namespace Authentik\Http;

use Authentik\Contracts\Transport;
use Illuminate\Support\Facades\Http;

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
        $response = Http::withHeaders($this->withoutContentType($headers))
            ->timeout($this->timeout)
            ->acceptJson()
            ->get($url);

        return ['status' => $response->status(), 'body' => $response->body()];
    }

    public function post(string $url, array $body = [], array $headers = []): array
    {
        $response = $this->send('post', $url, $body, $headers);

        return ['status' => $response->status(), 'body' => $response->body()];
    }

    public function patch(string $url, array $body = [], array $headers = []): array
    {
        $response = $this->send('patch', $url, $body, $headers);

        return ['status' => $response->status(), 'body' => $response->body()];
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    private function send(string $method, string $url, array $body, array $headers): \Illuminate\Http\Client\Response
    {
        // The caller decides the encoding. Passing a Content-Type through
        // withHeaders() and then also calling asForm() or asJson() produces a
        // combined value the API rejects with 415, so the header is consumed
        // here and the body is encoded to match it.
        $contentType = null;
        foreach ($headers as $name => $value) {
            if (strtolower($name) === 'content-type') {
                $contentType = $value;
            }
        }

        $request = Http::withHeaders($this->withoutContentType($headers))
            ->timeout($this->timeout)
            ->acceptJson();

        $request = $contentType !== null && str_contains(strtolower($contentType), 'json')
            ? $request->asJson()
            : $request->asForm();

        return $request->{$method}($url, $body);
    }

    /**
     * Drop Content-Type, which Laravel's client sets from asJson()/asForm().
     *
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    private function withoutContentType(array $headers): array
    {
        return array_filter(
            $headers,
            static fn ($name) => strtolower((string) $name) !== 'content-type',
            ARRAY_FILTER_USE_KEY,
        );
    }
}
