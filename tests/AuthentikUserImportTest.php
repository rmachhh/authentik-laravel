<?php

declare(strict_types=1);

namespace Authentik\Tests;

use Authentik\AuthentikException;
use Authentik\AuthentikUserImport;
use Authentik\Contracts\Transport;
use PHPUnit\Framework\TestCase;

/**
 * A transport that answers from a scripted list, so the import can be tested
 * without an authentik instance. Records every call for assertions.
 */
class FakeTransport implements Transport
{
    /** @var list<array{method: string, url: string, body: array, headers: array}> */
    public array $calls = [];

    /** @param array<string, array{status: int, body: array}> $responses */
    public function __construct(private array $responses = [])
    {
    }

    public function get(string $url, array $headers = []): array
    {
        $this->calls[] = ['method' => 'GET', 'url' => $url, 'body' => [], 'headers' => $headers];

        return $this->respond($url, []);
    }

    public function post(string $url, array $body = [], array $headers = []): array
    {
        $this->calls[] = ['method' => 'POST', 'url' => $url, 'body' => $body, 'headers' => $headers];

        return $this->respond($url, $body);
    }

    public function patch(string $url, array $body = [], array $headers = []): array
    {
        $this->calls[] = ['method' => 'PATCH', 'url' => $url, 'body' => $body, 'headers' => $headers];

        return $this->respond($url, $body);
    }

    /** @param array<string, mixed> $body */
    private function respond(string $url, array $body): array
    {
        foreach ($this->responses as $needle => $response) {
            if (str_contains($url, $needle)) {
                $payload = $response['body'];
                $payload = is_callable($payload) ? $payload($body) : $payload;

                return ['status' => $response['status'], 'body' => json_encode($payload)];
            }
        }

        return ['status' => 404, 'body' => '{"detail":"not scripted: '.$url.'"}'];
    }

    /** @return list<string> */
    public function methodsCalled(): array
    {
        return array_map(static fn ($call) => $call['method'], $this->calls);
    }
}

/**
 * The import is tested against a fake transport, which covers reconciliation,
 * group-membership merging and per-user failures.
 *
 * It cannot cover the real transports: a fake transport replaces the very code
 * that once sent a duplicated Content-Type header (rejected by authentik with
 * 415). That class of bug only appears over real HTTP, so it is covered by
 * tests/live-check.php and by running the import against a live instance. Do
 * not add a fake-transport test for it and believe it.
 */
final class AuthentikUserImportTest extends TestCase
{
    private const USERS = [
        ['email' => 'alex@example.com', 'name' => 'Alex'],
        ['email' => 'sam@example.com', 'name' => 'Sam'],
    ];

    private function importer(FakeTransport $transport, string $group = 'myapp-access'): AuthentikUserImport
    {
        return new AuthentikUserImport(
            baseUrl: 'https://id.example.com',
            token: 'test-token',
            appGroup: $group,
            transport: $transport,
        );
    }

    public function test_it_requires_an_api_token(): void
    {
        // The sign-in client secret is not enough, and confusing the two would
        // be an easy mistake.
        $this->expectException(AuthentikException::class);
        $this->expectExceptionMessageMatches('/API token/');

        new AuthentikUserImport('https://id.example.com', '', 'myapp-access');
    }

    public function test_it_requires_the_group_to_add_users_to(): void
    {
        $this->expectException(AuthentikException::class);
        $this->expectExceptionMessageMatches('/application group/');

        new AuthentikUserImport('https://id.example.com', 'token', '');
    }

    public function test_preview_reports_what_would_happen_without_writing(): void
    {
        $transport = new FakeTransport([
            '/api/v3/core/users/' => ['status' => 200, 'body' => ['results' => []]],
        ]);

        $result = $this->importer($transport)->preview(self::USERS);

        self::assertSame(2, $result['summary']['total']);
        self::assertSame(2, $result['summary']['create']);
        self::assertSame('create', $result['users'][0]['action']);
        self::assertSame('myapp-access', $result['users'][0]['group']);

        // A preview must not write anything.
        self::assertSame(['GET', 'GET'], $transport->methodsCalled());
    }

    public function test_preview_marks_an_existing_user_as_already_present(): void
    {
        $transport = new FakeTransport([
            '/api/v3/core/users/' => ['status' => 200, 'body' => ['results' => [['pk' => 7]]]],
        ]);

        $result = $this->importer($transport)->preview([self::USERS[0]]);

        self::assertSame(0, $result['summary']['create']);
        self::assertSame(1, $result['summary']['already present']);
        self::assertSame('add to group', $result['users'][0]['action']);
    }

    public function test_import_creates_a_missing_user_and_adds_them_to_the_group(): void
    {
        $transport = new FakeTransport([
            '/api/v3/core/groups/' => ['status' => 200, 'body' => [
                'results' => [['pk' => 'grp-1', 'name' => 'myapp-access', 'users' => []]],
            ]],
            '/api/v3/core/users/' => ['status' => 200, 'body' => [
                'results' => [],
                'created' => ['pk' => 11, 'email' => 'alex@example.com'],
            ]],
        ]);
        // Distinguish the create (POST) from the search (GET) by method.
        $transport = new class extends FakeTransport {
            public function post(string $url, array $body = [], array $headers = []): array
            {
                $this->calls[] = ['method' => 'POST', 'url' => $url, 'body' => $body, 'headers' => $headers];

                if (str_contains($url, '/users/')) {
                    return ['status' => 201, 'body' => json_encode(['pk' => 11, 'email' => $body['email'] ?? ''])];
                }

                return ['status' => 200, 'body' => json_encode(['pk' => 'grp-1'])];
            }

            public function get(string $url, array $headers = []): array
            {
                $this->calls[] = ['method' => 'GET', 'url' => $url, 'body' => [], 'headers' => $headers];

                if (str_contains($url, '/groups/')) {
                    return ['status' => 200, 'body' => json_encode([
                        'results' => [['pk' => 'grp-1', 'name' => 'myapp-access', 'users' => []]],
                    ])];
                }

                return ['status' => 200, 'body' => json_encode(['results' => []])];
            }

            public function patch(string $url, array $body = [], array $headers = []): array
            {
                $this->calls[] = ['method' => 'PATCH', 'url' => $url, 'body' => $body, 'headers' => $headers];

                return ['status' => 200, 'body' => json_encode(['pk' => 'grp-1'])];
            }
        };

        $result = $this->importer($transport)->import([self::USERS[0]]);

        self::assertSame(1, $result['summary']['created']);
        self::assertSame(1, $result['summary']['group additions']);
        self::assertSame(0, $result['summary']['failed']);
        self::assertSame('created', $result['results'][0]['status']);

        // The membership write must include the new user.
        $patch = array_values(array_filter($transport->calls, static fn ($c) => $c['method'] === 'PATCH'));
        self::assertCount(1, $patch);
        self::assertSame([11], $patch[0]['body']['users']);
    }

    public function test_import_keeps_existing_group_members(): void
    {
        // The group endpoint replaces the whole member list, so a naive write
        // would drop everyone already in the group.
        $transport = new class extends FakeTransport {
            public function get(string $url, array $headers = []): array
            {
                if (str_contains($url, '/groups/')) {
                    return ['status' => 200, 'body' => json_encode([
                        'results' => [['pk' => 'grp-1', 'name' => 'myapp-access', 'users' => [3, 4]]],
                    ])];
                }

                return ['status' => 200, 'body' => json_encode(['results' => [['pk' => 11, 'email' => 'alex@example.com']]])];
            }

            public function patch(string $url, array $body = [], array $headers = []): array
            {
                $this->calls[] = ['method' => 'PATCH', 'url' => $url, 'body' => $body, 'headers' => $headers];

                return ['status' => 200, 'body' => json_encode(['pk' => 'grp-1'])];
            }
        };

        $this->importer($transport)->import([self::USERS[0]]);

        $patch = array_values(array_filter($transport->calls, static fn ($c) => $c['method'] === 'PATCH'));
        self::assertSame([3, 4, 11], $patch[0]['body']['users'], 'existing members were dropped');
    }

    public function test_import_does_not_write_when_already_a_member(): void
    {
        $transport = new class extends FakeTransport {
            public function get(string $url, array $headers = []): array
            {
                if (str_contains($url, '/groups/')) {
                    return ['status' => 200, 'body' => json_encode([
                        'results' => [['pk' => 'grp-1', 'name' => 'myapp-access', 'users' => [11]]],
                    ])];
                }

                return ['status' => 200, 'body' => json_encode(['results' => [['pk' => 11, 'email' => 'alex@example.com']]])];
            }
        };

        $result = $this->importer($transport)->import([self::USERS[0]]);

        self::assertSame(0, $result['summary']['group additions']);
        self::assertSame(1, $result['summary']['already present']);
        self::assertNotContains('PATCH', $transport->methodsCalled(), 'a redundant write was made');
    }

    public function test_it_drops_entries_without_a_usable_email_and_de_duplicates(): void
    {
        $transport = new FakeTransport([
            '/api/v3/core/users/' => ['status' => 200, 'body' => ['results' => []]],
        ]);

        $result = $this->importer($transport)->preview([
            ['email' => 'alex@example.com'],
            ['email' => 'alex@example.com'],   // duplicate
            ['email' => ''],
            ['name' => 'no email at all'],
            ['email' => 'not-an-address'],
        ]);

        self::assertSame(1, $result['summary']['total']);
        self::assertSame('alex@example.com', $result['users'][0]['email']);
    }

    public function test_a_failure_is_reported_per_user_rather_than_aborting(): void
    {
        $transport = new class extends FakeTransport {
            public function get(string $url, array $headers = []): array
            {
                if (str_contains($url, '/groups/')) {
                    return ['status' => 200, 'body' => json_encode([
                        'results' => [['pk' => 'grp-1', 'name' => 'myapp-access', 'users' => []]],
                    ])];
                }

                return ['status' => 500, 'body' => json_encode(['detail' => 'boom'])];
            }
        };

        $result = $this->importer($transport)->import(self::USERS);

        self::assertSame(2, $result['summary']['failed']);
        self::assertSame('failed', $result['results'][0]['status']);
        self::assertNotNull($result['results'][0]['error']);
    }

    public function test_it_resolves_a_username_collision(): void
    {
        // A local part is not unique: two people at different schools both
        // called John derive the same username. authentik's are globally
        // unique, so the second must be qualified rather than failed.
        $transport = new class extends FakeTransport {
            /** @var list<string> */
            public array $usernames = [];

            public function get(string $url, array $headers = []): array
            {
                if (str_contains($url, '/groups/?')) {
                    return ['status' => 200, 'body' => json_encode([
                        'results' => [['pk' => 'grp-1', 'name' => 'myapp-access', 'users' => []]],
                    ])];
                }

                return ['status' => 200, 'body' => json_encode(['results' => []])];
            }

            public function post(string $url, array $body = [], array $headers = []): array
            {
                if (str_ends_with($url, '/groups/')) {
                    return ['status' => 201, 'body' => json_encode(['pk' => 'grp-1', 'name' => 'myapp-access', 'users' => []])];
                }

                if (in_array($body['username'] ?? '', $this->usernames, true)) {
                    return ['status' => 400, 'body' => json_encode(['username' => ['This field must be unique.']])];
                }

                $this->usernames[] = $body['username'] ?? '';

                return ['status' => 201, 'body' => json_encode(['pk' => count($this->usernames)])];
            }

            public function patch(string $url, array $body = [], array $headers = []): array
            {
                return ['status' => 200, 'body' => json_encode(['pk' => 'grp-1'])];
            }
        };

        $result = $this->importer($transport)->import([
            ['email' => 'john@a.example.com'],
            ['email' => 'john@b.example.com'],
        ]);

        self::assertSame(2, $result['summary']['created'], 'the second user was not created');
        self::assertSame(0, $result['summary']['failed']);
        self::assertSame(['john', 'john-bexamplecom'], $transport->usernames);
    }

    public function test_it_does_not_retry_a_failure_that_is_not_a_taken_username(): void
    {
        // Retrying a malformed address or a permissions problem would fail
        // again, and would hide the real error.
        $transport = new class extends FakeTransport {
            public int $attempts = 0;

            public function get(string $url, array $headers = []): array
            {
                if (str_contains($url, '/groups/?')) {
                    return ['status' => 200, 'body' => json_encode([
                        'results' => [['pk' => 'grp-1', 'name' => 'myapp-access', 'users' => []]],
                    ])];
                }

                return ['status' => 200, 'body' => json_encode(['results' => []])];
            }

            public function post(string $url, array $body = [], array $headers = []): array
            {
                if (str_ends_with($url, '/groups/')) {
                    return ['status' => 201, 'body' => json_encode(['pk' => 'grp-1', 'users' => []])];
                }

                $this->attempts++;

                return ['status' => 400, 'body' => json_encode(['email' => ['Enter a valid email address.']])];
            }
        };

        $result = $this->importer($transport)->import([['email' => 'alex@example.com']]);

        self::assertSame(1, $result['summary']['failed']);
        self::assertSame(1, $transport->attempts, 'a non-username failure was retried');
        self::assertStringContainsString('valid email', $result['results'][0]['error']);
    }

    // ------------------------------------------------------------- onboarding

    public function test_it_returns_a_recovery_link_for_an_existing_address(): void
    {
        // The specific key first: FakeTransport matches on substring, and the
        // collection URL is a prefix of the recovery URL.
        $transport = new FakeTransport([
            '/recovery/' => ['status' => 200, 'body' => ['link' => 'https://id.example.com/if/flow/recovery/?flow_token=abc']],
            '/api/v3/core/users/' => ['status' => 200, 'body' => ['results' => [['pk' => 7, 'email' => 'alex@example.com']]]],
        ]);

        $link = $this->importer($transport)->recoveryLink('alex@example.com');

        self::assertSame('https://id.example.com/if/flow/recovery/?flow_token=abc', $link);
        // It has to address the user it found, not just any user.
        self::assertStringContainsString('/api/v3/core/users/7/recovery/', $transport->calls[1]['url']);
    }

    public function test_it_returns_null_and_writes_nothing_when_there_is_no_account(): void
    {
        $transport = new FakeTransport([
            '/recovery/' => ['status' => 200, 'body' => ['link' => 'https://should-not-be-used']],
            '/api/v3/core/users/' => ['status' => 200, 'body' => ['results' => []]],
        ]);

        self::assertNull($this->importer($transport)->recoveryLink('nobody@example.com'));
        self::assertSame(['GET'], $transport->methodsCalled());
    }

    public function test_it_explains_a_missing_recovery_flow(): void
    {
        // The state this deployment was actually in, and the message an operator
        // needs to see rather than a bare "HTTP 400".
        $transport = new FakeTransport([
            '/recovery/' => ['status' => 400, 'body' => ['non_field_errors' => ['No recovery flow set.']]],
            '/api/v3/core/users/' => ['status' => 200, 'body' => ['results' => [['pk' => 7]]]],
        ]);

        $this->expectException(AuthentikException::class);
        $this->expectExceptionMessageMatches('/No recovery flow set/');

        $this->importer($transport)->recoveryLink('alex@example.com');
    }

    public function test_it_refuses_a_successful_response_with_no_link(): void
    {
        $transport = new FakeTransport([
            '/recovery/' => ['status' => 200, 'body' => []],
            '/api/v3/core/users/' => ['status' => 200, 'body' => ['results' => [['pk' => 7]]]],
        ]);

        $this->expectException(AuthentikException::class);
        $this->expectExceptionMessageMatches('/no recovery link/');

        $this->importer($transport)->recoveryLink('alex@example.com');
    }
}
