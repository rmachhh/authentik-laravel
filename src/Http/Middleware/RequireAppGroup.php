<?php

declare(strict_types=1);

namespace Authentik\Http\Middleware;

use Authentik\AuthentikUser;
use Closure;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Require membership of the group that guards this application.
 *
 * One group per application is the whole access model. This middleware is the
 * single place that enforces it, so no route can accidentally skip the check.
 *
 * The user is read from the session, where the application's callback put it
 * after a successful sign-in. Reading it from the session means the check costs
 * nothing per request and cannot fail open if the identity provider is briefly
 * unreachable.
 *
 * Dependencies are injected rather than resolved through the global helpers, so
 * the check can be exercised on its own. Laravel binds them automatically.
 */
class RequireAppGroup
{
    public const SESSION_KEY = 'authentik.user';

    public function __construct(
        private readonly ?ConfigRepository $config = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @param  string|list<string>|null  $group  the group that grants access, or
     *         several (array, or comma-separated as route middleware parameters).
     *         Defaults to config.
     *
     * @return mixed whatever the next layer returns
     */
    public function handle(Request $request, Closure $next, string|array|null $group = null): mixed
    {
        $required = $this->resolveRequiredGroups($group);

        $user = $request->session()->get(self::SESSION_KEY);

        // Not signed in at all: send them to sign in rather than claiming they
        // lack permission, which would be both wrong and confusing.
        if (! $user instanceof AuthentikUser) {
            return new RedirectResponse(
                $this->config?->get('authentik.login_route', '/auth/redirect') ?? '/auth/redirect'
            );
        }

        if (! $user->hasAccessTo($required)) {
            // Log which groups were seen, never the token. This is the line that
            // answers "why was I denied?" without leaking a credential.
            $this->logger?->warning('authentik access denied', [
                'email' => $user->email,
                'required_group' => $required,
                'groups_present' => $user->groups,
            ]);

            // 403 rather than 401: they are authenticated, just not permitted.
            throw new AccessDeniedHttpException('You do not have access to this application.');
        }

        return $next($request);
    }

    /**
     * Groups may arrive from config, from the middleware parameter, or from the
     * route string `authentik.group:myapp-access,myapp-contractors`.
     *
     * @return list<string>
     */
    private function resolveRequiredGroups(string|array|null $group): array
    {
        if (is_array($group)) {
            return array_values(array_filter(array_map(
                static fn ($g) => is_string($g) ? trim($g) : '',
                $group,
            ), static fn ($g) => $g !== ''));
        }

        if ($group === null || trim($group) === '') {
            $configured = $this->config?->get('authentik.app_group');

            if (is_array($configured)) {
                return array_values(array_filter(array_map('strval', $configured)));
            }

            return $configured === null || $configured === ''
                ? []
                : [(string) $configured];
        }

        return array_values(array_filter(array_map('trim', explode(',', $group))));
    }
}
