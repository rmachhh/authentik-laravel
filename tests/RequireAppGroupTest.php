<?php

declare(strict_types=1);

namespace Authentik\Tests;

use Authentik\AuthentikUser;
use Authentik\Http\Middleware\RequireAppGroup;
use Illuminate\Http\Request;
use Illuminate\Session\Store;
use Illuminate\Session\ArraySessionHandler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Exercises the middleware with real Illuminate requests and sessions, using
 * the framework already installed in a sibling application — no framework boot,
 * no extra dependency.
 */
final class RequireAppGroupTest extends TestCase
{
    /** @param array<string, mixed> $session */
    private function request(array $session): Request
    {
        $store = new Store('test', new ArraySessionHandler(120));
        $store->start();

        foreach ($session as $key => $value) {
            $store->put($key, $value);
        }

        $request = Request::create('/dashboard', 'GET');
        $request->setLaravelSession($store);

        return $request;
    }

    private function user(string ...$groups): AuthentikUser
    {
        return AuthentikUser::fromClaims(['sub' => 'x', 'email' => 'alex@example.com', 'groups' => $groups]);
    }

    private function handle(Request $request, string|array|null $group): mixed
    {
        // The middleware reads config() for the default group; pass the group
        // explicitly so no application boot is needed.
        return (new RequireAppGroup)->handle($request, fn () => 'reached the route', $group);
    }

    public function test_it_admits_a_user_in_the_app_group(): void
    {
        $request = $this->request([RequireAppGroup::SESSION_KEY => $this->user('myapp-access')]);

        self::assertSame('reached the route', $this->handle($request, 'myapp-access'));
    }

    public function test_it_admits_a_user_holding_one_of_several_groups(): void
    {
        $request = $this->request([RequireAppGroup::SESSION_KEY => $this->user('myapp-contractors')]);

        // Either group is sufficient — the shape used by
        // `authentik.group:myapp-access,myapp-contractors`.
        self::assertSame(
            'reached the route',
            $this->handle($request, ['myapp-access', 'myapp-contractors']),
        );
    }

    public function test_it_accepts_groups_separated_by_commas(): void
    {
        // The form route parameters actually arrive in.
        $request = $this->request([RequireAppGroup::SESSION_KEY => $this->user('myapp-contractors')]);

        self::assertSame(
            'reached the route',
            $this->handle($request, 'myapp-access,myapp-contractors'),
        );
    }

    public function test_it_denies_a_user_without_the_group(): void
    {
        $request = $this->request([RequireAppGroup::SESSION_KEY => $this->user('hr-access')]);

        $this->expectException(AccessDeniedHttpException::class);

        try {
            $this->handle($request, 'myapp-access');
        } catch (AccessDeniedHttpException $e) {
            // 403, not 401: they are authenticated, just not permitted.
            self::assertSame(403, $e->getStatusCode());
            throw $e;
        }
    }

    public function test_it_denies_a_user_with_no_groups_at_all(): void
    {
        $request = $this->request([RequireAppGroup::SESSION_KEY => $this->user()]);

        $this->expectException(AccessDeniedHttpException::class);
        $this->handle($request, 'myapp-access');
    }

    public function test_it_redirects_a_visitor_who_is_not_signed_in(): void
    {
        // Not signed in is not the same as not permitted: send them to sign in
        // rather than claiming they lack access.
        $response = $this->handle($this->request([]), 'myapp-access');

        self::assertTrue(method_exists($response, 'isRedirect'));
        self::assertTrue($response->isRedirect());
    }

    public function test_it_fails_closed_when_no_group_is_configured(): void
    {
        $request = $this->request([RequireAppGroup::SESSION_KEY => $this->user('anything')]);

        $this->expectException(AccessDeniedHttpException::class);
        $this->handle($request, null);
    }

    public function test_it_does_not_treat_a_non_authentik_session_value_as_signed_in(): void
    {
        // A session value of the wrong shape must not pass the check.
        $request = $this->request([RequireAppGroup::SESSION_KEY => ['groups' => ['myapp-access']]]);

        $response = $this->handle($request, 'myapp-access');

        self::assertTrue($response->isRedirect(), 'expected a redirect to sign in');
    }
}
