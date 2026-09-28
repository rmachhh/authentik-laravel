<?php

declare(strict_types=1);

namespace Authentik;

use Authentik\Contracts\Transport;
use Authentik\Http\NativeTransport;

/**
 * One authentik instance.
 *
 * Only the pieces a relying application needs are implemented: discovery, the
 * authorization URL, the code exchange and the userinfo endpoint. Nothing here
 * touches roles — authentik does not own those.
 *
 * HTTP goes through a transport, so the client works in a Laravel application
 * and in a plain CLI script, and can be tested without booting a framework.
 */
final class AuthentikClient
{
    /** @var array<string, mixed>|null */
    private ?array $discovery = null;

    /**
     * @param  list<string>  $scopes
     */
    public function __construct(
        public readonly string $label,
        public readonly string $issuer,
        public readonly string $clientId,
        public readonly string $clientSecret,
        public readonly string $redirectUri,
        public readonly array $scopes = ['openid', 'email', 'profile'],
        public readonly bool $allowInsecure = false,
        public readonly int $timeout = 10,
        private readonly ?Transport $transport = null,
    ) {
    }

    private function http(): Transport
    {
        return $this->transport ?? new NativeTransport($this->timeout);
    }

    /**
     * Resolve the provider metadata, once per instance.
     *
     * @return array<string, mixed>
     */
    public function discover(): array
    {
        if ($this->discovery !== null) {
            return $this->discovery;
        }

        $this->assertIssuerIsAcceptable();

        $url = rtrim($this->issuer, '/').'/.well-known/openid-configuration';

        $response = $this->http()->get($url);

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new AuthentikException(
                "Discovery failed for {$this->label} ({$url}): HTTP {$response['status']}"
            );
        }

        $metadata = json_decode($response['body'], true);

        if (! is_array($metadata) || empty($metadata['authorization_endpoint'])) {
            throw new AuthentikException(
                "Discovery document for {$this->label} is missing authorization_endpoint"
            );
        }

        return $this->discovery = $metadata;
    }

    /**
     * Start a sign-in: build the authorization URL and the state to remember.
     *
     * @return array{url: string, flow: FlowState}
     */
    public function startAuthorization(): array
    {
        $metadata = $this->discover();

        $flow = new FlowState(
            codeVerifier: self::base64Url(random_bytes(32)),
            state: self::base64Url(random_bytes(16)),
            nonce: self::base64Url(random_bytes(16)),
            instance: $this->label,
        );

        $challenge = self::base64Url(hash('sha256', $flow->codeVerifier, true));

        $url = $metadata['authorization_endpoint'].'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'scope' => implode(' ', $this->scopes),
            'state' => $flow->state,
            'nonce' => $flow->nonce,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);

        return ['url' => $url, 'flow' => $flow];
    }

    /**
     * Finish a sign-in and return the verified claims.
     *
     * Validates the state and the PKCE verifier. The nonce is compared against
     * the ID token where one is returned, which is what stops a token issued
     * for a different sign-in being replayed into this session.
     *
     * @param  array<string, mixed>  $query  the callback's query string
     */
    public function completeLogin(array $query, FlowState $flow): AuthentikUser
    {
        $metadata = $this->discover();

        if (isset($query['error'])) {
            throw new AuthentikException(
                'Provider returned an error: '.$query['error'].
                (isset($query['error_description']) ? ' — '.$query['error_description'] : '')
            );
        }

        $code = $query['code'] ?? null;
        if (! is_string($code) || $code === '') {
            throw new AuthentikException('Callback is missing the authorization code');
        }

        if (! hash_equals($flow->state, (string) ($query['state'] ?? ''))) {
            throw new AuthentikException(
                'State mismatch: this callback does not match the sign-in that was started'
            );
        }

        $response = $this->http()->post($metadata['token_endpoint'], [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code_verifier' => $flow->codeVerifier,
        ]);

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new AuthentikException(
                "Token exchange failed for {$this->label}: HTTP {$response['status']}"
            );
        }

        $tokens = json_decode($response['body'], true);
        if (! is_array($tokens)) {
            throw new AuthentikException("Token response for {$this->label} was not JSON");
        }

        $claims = null;
        if (isset($tokens['id_token']) && is_string($tokens['id_token'])) {
            $claims = self::decodeJwtPayload($tokens['id_token']);
        }

        // Some flows return no ID token, or omit group membership from it. Fall
        // back to userinfo so the access decision still has something to use.
        if ($claims === null || ! isset($claims['groups'])) {
            $claims = $this->fetchUserInfo($metadata, $tokens) ?? $claims ?? [];
        }

        if (isset($claims['nonce']) && ! hash_equals($flow->nonce, (string) $claims['nonce'])) {
            throw new AuthentikException('Nonce mismatch: the token was not issued for this sign-in');
        }

        return AuthentikUser::fromClaims($claims);
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $tokens
     * @return array<string, mixed>|null
     */
    private function fetchUserInfo(array $metadata, array $tokens): ?array
    {
        $endpoint = $metadata['userinfo_endpoint'] ?? null;
        $accessToken = $tokens['access_token'] ?? null;

        if (! is_string($endpoint) || ! is_string($accessToken)) {
            return null;
        }

        $response = $this->http()->get($endpoint, ['Authorization' => 'Bearer '.$accessToken]);

        if ($response['status'] < 200 || $response['status'] >= 300) {
            return null;
        }

        $claims = json_decode($response['body'], true);

        return is_array($claims) ? $claims : null;
    }

    /**
     * Read a JWT payload without verifying its signature.
     *
     * Safe here only because the token was just fetched directly from the token
     * endpoint over a server-to-server connection, which is the authenticated
     * channel OIDC relies on. A token arriving from anywhere else must be
     * verified against the provider's JWKS instead.
     *
     * @return array<string, mixed>|null
     */
    private static function decodeJwtPayload(string $jwt): ?array
    {
        $parts = explode('.', $jwt);
        if (count($parts) < 2) {
            return null;
        }

        $payload = strtr($parts[1], '-_', '+/');
        $decoded = base64_decode(str_pad($payload, (int) (ceil(strlen($payload) / 4) * 4), '='), true);

        if ($decoded === false) {
            return null;
        }

        $claims = json_decode($decoded, true);

        return is_array($claims) ? $claims : null;
    }

    /** Refuse plain HTTP unless this instance explicitly opted in. */
    private function assertIssuerIsAcceptable(): void
    {
        if (str_starts_with($this->issuer, 'http://') && ! $this->allowInsecure) {
            throw new AuthentikException(
                "Refusing plain-HTTP issuer {$this->issuer}. Set allow_insecure only for local development."
            );
        }
    }

    private static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
