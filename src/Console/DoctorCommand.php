<?php

declare(strict_types=1);

namespace Authentik\Console;

use Authentik\AuthentikException;
use Authentik\FailoverAuthentikClient;
use Authentik\FlowState;
use Illuminate\Console\Command;

/**
 * Verify an authentik integration without signing in.
 *
 * Checks the things that actually go wrong — a missing setting, a mismatched
 * redirect URI, an unreachable issuer, a guard that would let everyone in — and
 * prints a specific fix for each. Run this before asking anyone to test sign-in
 * in a browser.
 */
class DoctorCommand extends Command
{
    protected $signature = 'authentik:doctor';

    protected $description = 'Check the authentik configuration and report what to fix';

    /** @var list<array{name: string, ok: bool, detail: string, fix: ?string}> */
    private array $results = [];

    public function handle(FailoverAuthentikClient $authentik): int
    {
        $this->newLine();
        $this->line('authentik integration check');
        $this->newLine();

        $this->checkConfiguration();
        $this->checkIssuer();
        $this->checkRedirectUri();
        $this->checkGuard();

        if ($this->nothingFailed()) {
            $this->checkDiscovery($authentik);
        } else {
            $this->report('discovery', false, 'not attempted until the settings above are valid',
                'Fix the failures above first.');
        }

        return $this->summarise();
    }

    private function checkConfiguration(): void
    {
        $missing = [];

        foreach ([
            'authentik.instances.primary.issuer' => config('authentik.instances.primary.issuer'),
            'authentik.instances.primary.client_id' => config('authentik.instances.primary.client_id'),
            'authentik.instances.primary.client_secret' => config('authentik.instances.primary.client_secret'),
            'authentik.redirect_uri' => config('authentik.redirect_uri'),
            'authentik.app_group' => config('authentik.app_group'),
        ] as $key => $value) {
            if ($value === null || $value === '') {
                $missing[] = $key;
            }
        }

        $this->report(
            'configuration',
            $missing === [],
            $missing === [] ? 'every required setting is present' : 'missing: '.implode(', ', $missing),
            'Set AUTHENTIK_ISSUER, AUTHENTIK_CLIENT_ID, AUTHENTIK_CLIENT_SECRET, '
            .'AUTHENTIK_REDIRECT_URI and AUTHENTIK_APP_GROUP in .env, then run '
            .'`php artisan config:clear`. See SETUP.md step 3.',
        );
    }

    private function checkIssuer(): void
    {
        $issuer = (string) config('authentik.instances.primary.issuer');

        $shapeOk = $issuer !== '' && str_ends_with($issuer, '/') && str_contains($issuer, '/application/o/');
        $this->report(
            'issuer shape',
            $shapeOk,
            $shapeOk ? $issuer : "{$issuer} does not look like an application issuer",
            'The issuer ends with /application/o/<slug>/ and keeps its trailing slash. '
            .'Copy it from authentik: Applications > Providers > your provider.',
        );

        $isLocal = str_starts_with($issuer, 'http://localhost')
            || str_starts_with($issuer, 'http://127.0.0.1');
        $secure = str_starts_with($issuer, 'https://') || $isLocal;

        $this->report(
            'transport',
            $secure,
            $secure ? ($isLocal ? 'plain HTTP allowed for a local issuer' : 'https') : 'plain HTTP',
            'Use an https:// issuer. allow_insecure is only for a local authentik container.',
        );
    }

    private function checkRedirectUri(): void
    {
        $redirect = (string) config('authentik.redirect_uri');

        $shapeOk = (bool) preg_match('#^https?://.+/.#', $redirect);
        $this->report(
            'redirect uri shape',
            $shapeOk,
            $shapeOk ? $redirect : "{$redirect} is not an absolute http(s) URL",
            'AUTHENTIK_REDIRECT_URI must be the full callback URL, e.g. '
            .'https://myapp.example.com/auth/callback',
        );
    }

    private function checkGuard(): void
    {
        $group = config('authentik.app_group');

        if ($group === null || $group === '') {
            $this->report('access guard', false, 'no app group configured',
                'Set AUTHENTIK_APP_GROUP. An unconfigured guard denies everyone, '
                .'which is safe but means nobody can sign in.');
            return;
        }

        $allowsMember = \Authentik\AuthentikUser::fromClaims(['sub' => 'x', 'groups' => [$group]])
            ->hasAccessTo($group);
        $deniesOther = ! \Authentik\AuthentikUser::fromClaims(['sub' => 'x', 'groups' => ['other']])
            ->hasAccessTo($group);
        $deniesEmpty = ! \Authentik\AuthentikUser::fromClaims(['sub' => 'x', 'groups' => []])
            ->hasAccessTo($group);

        $ok = $allowsMember && $deniesOther && $deniesEmpty;

        $this->report(
            'access guard',
            $ok,
            $ok
                ? "appGroup=\"{$group}\" grants access; other groups and no groups are denied"
                : 'the guard does not behave as expected',
            'The guard must fail closed. This is a bug in the package, not in your configuration.',
        );
    }

    private function checkDiscovery(FailoverAuthentikClient $authentik): void
    {
        try {
            $result = $authentik->startAuthorization();
            $query = [];
            parse_str((string) parse_url($result['url'], PHP_URL_QUERY), $query);

            $this->report('discovery', true,
                'authorization endpoint reached via '.$result['flow']->instance);

            $pkceOk = ($query['code_challenge_method'] ?? null) === 'S256';
            $this->report('pkce', $pkceOk, $query['code_challenge_method'] ?? '(missing)',
                'The package always sends S256. A different value means the URL was built elsewhere.');

            $stateOk = isset($query['state']) && isset($query['nonce']);
            $this->report('state and nonce', $stateOk,
                'state='.(isset($query['state']) ? 'present' : 'MISSING')
                .' nonce='.(isset($query['nonce']) ? 'present' : 'MISSING'),
                'Both are required and stored server-side. If either is missing, do not sign in.');

            $this->report('verifier not exposed', ! isset($query['code_verifier']),
                'the PKCE verifier is not in the authorization URL',
                'The verifier must never reach the browser in readable form.');

            // A forged callback must be refused. This proves the state check is
            // actually wired, not merely present.
            try {
                $authentik->completeLogin(
                    ['code' => 'doctor-made-up', 'state' => 'not-the-real-state'],
                    new FlowState('verifier', 'expected-state', 'nonce', $result['flow']->instance),
                );
                $this->report('forged callback refused', false, 'a mismatched state was accepted',
                    'This is a security bug in the package. Do not deploy.');
            } catch (AuthentikException) {
                $this->report('forged callback refused', true, 'a mismatched state is rejected');
            }
        } catch (AuthentikException $e) {
            $this->report('discovery', false, $e->getMessage(),
                'Check AUTHENTIK_ISSUER is reachable from this machine, that the client '
                .'credentials belong to a provider on that instance, and for plain HTTP that '
                .'allow_insecure is enabled.');
        }
    }

    private function report(string $name, bool $ok, string $detail, ?string $fix = null): void
    {
        $this->results[] = compact('name', 'ok', 'detail', 'fix');
    }

    private function nothingFailed(): bool
    {
        foreach ($this->results as $result) {
            if (! $result['ok']) {
                return false;
            }
        }

        return true;
    }

    private function summarise(): int
    {
        $failed = 0;

        foreach ($this->results as $result) {
            if ($result['ok']) {
                $this->line('  <fg=green>PASS</>  '.$result['name'].': '.$result['detail']);
                continue;
            }

            $failed++;
            $this->line('  <fg=red>FAIL</>  '.$result['name'].': '.$result['detail']);
            if ($result['fix']) {
                $this->line('        fix: '.$result['fix']);
            }
        }

        $this->newLine();

        if ($failed === 0) {
            $this->info('All checks passed. Sign-in should work once the redirect URI is registered.');
            $this->line('Next: sign in with a user in the app group, then remove them from the group');
            $this->line('and confirm the application returns 403.');

            return self::SUCCESS;
        }

        $this->error("{$failed} check(s) failed. Fix those before testing sign-in in a browser.");

        return self::FAILURE;
    }
}
