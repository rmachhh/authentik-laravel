<?php

declare(strict_types=1);

namespace Authentik\Http;

use Authentik\AuthentikException;
use Authentik\Contracts\Transport;

/**
 * A transport with no framework dependency, for CLI scripts and tests.
 *
 * Also the default when the package is used outside Laravel.
 */
final class NativeTransport implements Transport
{
    public function __construct(private readonly int $timeout = 10)
    {
    }

    public function get(string $url, array $headers = []): array
    {
        return $this->request('GET', $url, null, $headers);
    }

    public function post(string $url, array $body = [], array $headers = []): array
    {
        return $this->request('POST', $url, $body, $headers);
    }

    public function patch(string $url, array $body = [], array $headers = []): array
    {
        return $this->request('PATCH', $url, $body, $headers);
    }

    /**
     * @param  array<string, string>|null  $body
     * @param  array<string, string>  $headers
     * @return array{status: int, body: string}
     */
    private function request(string $method, string $url, ?array $body, array $headers): array
    {
        $handle = curl_init();

        $headerLines = ['Accept: application/json'];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name.': '.$value;
        }

        curl_setopt_array($handle, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => min(5, $this->timeout),
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_CUSTOMREQUEST => $method,
        ]);

        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($body));
            curl_setopt($handle, CURLOPT_HTTPHEADER, array_merge(
                $headerLines,
                ['Content-Type: application/x-www-form-urlencoded'],
            ));
        }

        $response = curl_exec($handle);

        if ($response === false) {
            $error = curl_error($handle);
            curl_close($handle);

            // Authored so the failover logic can recognise an unreachable
            // instance: "Connection refused" / "timed out" / "Name or service".
            throw new AuthentikException('Connection failed for '.$url.': '.$error);
        }

        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return ['status' => $status, 'body' => (string) $response];
    }
}
