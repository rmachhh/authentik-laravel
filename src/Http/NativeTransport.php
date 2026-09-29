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
     * @param  array<string, mixed>|null  $body
     * @param  array<string, string>  $headers
     * @return array{status: int, body: string}
     */
    private function request(string $method, string $url, ?array $body, array $headers): array
    {
        $handle = curl_init();

        $headerLines = ['Accept: application/json'];
        $contentType = null;

        foreach ($headers as $name => $value) {
            // The caller decides the encoding. Appending a second Content-Type
            // produces a combined value that the API rejects with 415.
            if (strtolower($name) === 'content-type') {
                $contentType = $value;
                continue;
            }
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
            $isJson = $contentType !== null && str_contains(strtolower($contentType), 'json');

            // JSON when the caller asked for it, form-encoded otherwise (which
            // is what the OIDC token endpoint expects).
            //
            // An empty body is sent as `{}`, not `[]`. json_encode([]) produces
            // a list, and authentik rejects that with "Expected a dictionary,
            // but got list" on endpoints that take no arguments — which is
            // exactly the case for issuing a recovery link.
            curl_setopt(
                $handle,
                CURLOPT_POSTFIELDS,
                $isJson
                    ? ($body === [] ? '{}' : json_encode($body))
                    : http_build_query($body),
            );

            $headerLines[] = 'Content-Type: '.($contentType
                ?? 'application/x-www-form-urlencoded');

            curl_setopt($handle, CURLOPT_HTTPHEADER, $headerLines);
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
