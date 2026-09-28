<?php

declare(strict_types=1);

/**
 * Live check of the Laravel client against a running authentik.
 *
 * Uses this package's classes with the real provider, so it proves the
 * implementation rather than a mock: discovery, the PKCE authorization URL, the
 * refusal of a forged callback, and failover.
 *
 *   php tests/live-check.php
 *
 * Configuration comes from the environment, or from the sibling Laravel app's
 * .env when AUTHENTIK_ISSUER is not set.
 */

require __DIR__.'/bootstrap.php';

use Authentik\AuthentikClient;
use Authentik\AuthentikException;
use Authentik\FailoverAuthentikClient;
use Authentik\FlowState;

function envFromLaravelApp(string $key): ?string
{
    $path = getenv('LARAVEL_ENV') ?: __DIR__.'/../../sis/.env';

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

$value = static fn (string $key): ?string => getenv($key) ?: envFromLaravelApp($key);

$issuer = $value('AUTHENTIK_ISSUER');
$clientId = $value('AUTHENTIK_CLIENT_ID');
$clientSecret = $value('AUTHENTIK_CLIENT_SECRET');
$redirectUri = $value('AUTHENTIK_REDIRECT_URI')
    ?: 'http://localhost:4000/api/auth/authentik/callback';

if (! $issuer || ! $clientId || ! $clientSecret) {
    fwrite(STDERR, "Missing AUTHENTIK_ISSUER / CLIENT_ID / CLIENT_SECRET\n");
    exit(1);
}

$primary = new AuthentikClient(
    label: 'primary',
    issuer: $issuer,
    clientId: $clientId,
    clientSecret: $clientSecret,
    redirectUri: $redirectUri,
    allowInsecure: str_starts_with((string) $issuer, 'http://'),
);

echo "1. discovery against the real provider\n";
$metadata = $primary->discover();
echo "   issuer      : {$metadata['issuer']}\n";
echo "   authorize   : {$metadata['authorization_endpoint']}\n";
echo "   token       : {$metadata['token_endpoint']}\n";

echo "\n2. authorization URL\n";
$start = $primary->startAuthorization();
$query = [];
parse_str((string) parse_url($start['url'], PHP_URL_QUERY), $query);
echo '   scope       : '.($query['scope'] ?? '(none)')."\n";
echo '   PKCE        : '.($query['code_challenge_method'] ?? '(none)')."\n";
echo '   challenge   : '.(isset($query['code_challenge']) ? strlen($query['code_challenge']).' chars' : '(missing)')."\n";
echo '   state       : '.(isset($query['state']) ? 'present' : 'MISSING')."\n";
echo '   nonce       : '.(isset($query['nonce']) ? 'present' : 'MISSING')."\n";

if (! isset($query['code_challenge'], $query['state'], $query['nonce'])) {
    fwrite(STDERR, "FAILED: the authorization URL is incomplete\n");
    exit(1);
}

// The verifier must never appear in the URL.
if (isset($query['code_verifier'])) {
    fwrite(STDERR, "FAILED: the PKCE verifier leaked into the authorization URL\n");
    exit(1);
}
echo "   verifier not in URL: yes\n";

echo "\n3. a forged callback is refused\n";
$forged = AuthentikClient::class;
try {
    $primary->completeLogin(
        ['code' => 'made-up', 'state' => 'not-the-real-state'],
        new FlowState('verifier', 'expected-state', 'nonce', 'primary'),
    );
    fwrite(STDERR, "FAILED: a mismatched state was accepted\n");
    exit(1);
} catch (AuthentikException $e) {
    echo '   refused: '.$e->getMessage()."\n";
}

echo "\n4. failover\n";
$dead = new AuthentikClient(
    label: 'secondary-down',
    issuer: 'http://127.0.0.1:59999/application/o/sis/',
    clientId: $clientId,
    clientSecret: $clientSecret,
    redirectUri: $redirectUri,
    allowInsecure: true,
);

$skipped = [];
$failover = new FailoverAuthentikClient([$dead, $primary], function ($error, $instance) use (&$skipped) {
    $skipped[] = $instance->label;
});

$result = $failover->startAuthorization();
echo '   skipped     : '.(implode(', ', $skipped) ?: '(none)')."\n";
echo '   used        : '.$result['flow']->instance."\n";

if ($result['flow']->instance !== 'primary') {
    fwrite(STDERR, "FAILED: failover did not reach the healthy instance\n");
    exit(1);
}

echo "\n5. a configuration error is not failed over\n";
$misconfigured = new AuthentikClient(
    label: 'misconfigured',
    issuer: 'http://plain-http.example.com/application/o/sis/',
    clientId: $clientId,
    clientSecret: $clientSecret,
    redirectUri: $redirectUri,
);
try {
    (new FailoverAuthentikClient([$misconfigured, $primary]))->startAuthorization();
    fwrite(STDERR, "FAILED: a plain-HTTP issuer was silently failed over\n");
    exit(1);
} catch (AuthentikException $e) {
    echo '   refused: '.substr($e->getMessage(), 0, 70)."...\n";
}

echo "\nAll live checks passed.\n";
