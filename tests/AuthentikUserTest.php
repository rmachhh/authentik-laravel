<?php

declare(strict_types=1);

namespace Authentik\Tests;

use Authentik\AuthentikUser;
use PHPUnit\Framework\TestCase;

final class AuthentikUserTest extends TestCase
{
    public function test_it_reads_the_groups_claim_authentik_sends(): void
    {
        $user = AuthentikUser::fromClaims([
            'sub' => 'abc123',
            'email' => 'alex@example.com',
            'groups' => ['myapp-access', 'hr-access'],
        ]);

        self::assertSame('abc123', $user->subject);
        self::assertSame('alex@example.com', $user->email);
        self::assertSame(['myapp-access', 'hr-access'], $user->groups);
    }

    public function test_it_accepts_a_singular_group_claim(): void
    {
        $user = AuthentikUser::fromClaims(['sub' => 'x', 'group' => 'myapp-access']);

        self::assertSame(['myapp-access'], $user->groups);
    }

    public function test_it_accepts_a_single_string_in_the_plural_claim(): void
    {
        $user = AuthentikUser::fromClaims(['sub' => 'x', 'groups' => 'myapp-access']);

        self::assertSame(['myapp-access'], $user->groups);
    }

    public function test_it_trims_and_de_duplicates_groups(): void
    {
        $user = AuthentikUser::fromClaims([
            'sub' => 'x',
            'groups' => [' myapp-access ', 'myapp-access', '', '   ', null, 42, 'hr-access'],
        ]);

        self::assertSame(['myapp-access', 'hr-access'], $user->groups);
    }

    public function test_it_tolerates_missing_and_malformed_claims(): void
    {
        self::assertSame([], AuthentikUser::fromClaims([])->groups);
        self::assertSame('', AuthentikUser::fromClaims([])->subject);
        self::assertNull(AuthentikUser::fromClaims([])->email);
        self::assertSame([], AuthentikUser::fromClaims(['groups' => null])->groups);
    }

    public function test_it_allows_a_member_of_the_app_group(): void
    {
        $user = AuthentikUser::fromClaims([
            'sub' => 'x',
            'groups' => ['hr-access', 'myapp-access'],
        ]);

        self::assertTrue($user->hasAccessTo('myapp-access'));
    }

    public function test_it_denies_someone_only_in_another_app_group(): void
    {
        $user = AuthentikUser::fromClaims(['sub' => 'x', 'groups' => ['hr-access']]);

        self::assertFalse($user->hasAccessTo('myapp-access'));
    }

    public function test_it_allows_any_one_of_several_configured_groups(): void
    {
        $user = AuthentikUser::fromClaims(['sub' => 'x', 'groups' => ['myapp-contractors']]);

        self::assertTrue($user->hasAccessTo(['myapp-access', 'myapp-contractors']));
        self::assertFalse($user->hasAccessTo(['myapp-access', 'finance-access']));
    }

    public function test_it_fails_closed_when_no_group_is_configured(): void
    {
        // An application that has not declared its group must not admit
        // everyone by accident.
        $user = AuthentikUser::fromClaims(['sub' => 'x', 'groups' => ['anything']]);

        self::assertFalse($user->hasAccessTo(null));
        self::assertFalse($user->hasAccessTo(''));
        self::assertFalse($user->hasAccessTo([]));
    }

    public function test_it_reports_which_group_granted_access(): void
    {
        $user = AuthentikUser::fromClaims(['sub' => 'x', 'groups' => ['hr-access', 'myapp-access']]);

        self::assertSame('myapp-access', $user->matchedGroup(['myapp-access', 'finance-access']));
        self::assertNull($user->matchedGroup('finance-access'));
    }

    public function test_it_exposes_only_non_sensitive_fields(): void
    {
        $user = AuthentikUser::fromClaims([
            'sub' => 'x',
            'email' => 'alex@example.com',
            'groups' => ['myapp-access'],
            'access_token' => 'should-not-be-exposed',
        ]);

        // toArray is what an application would log or store, so it must not
        // carry anything credential-shaped.
        self::assertSame(
            ['sub' => 'x', 'email' => 'alex@example.com', 'name' => null, 'groups' => ['myapp-access']],
            $user->toArray(),
        );
    }
}
