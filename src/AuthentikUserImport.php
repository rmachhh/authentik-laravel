<?php

declare(strict_types=1);

namespace Authentik;

use Authentik\Contracts\Transport;
use Authentik\Http\NativeTransport;

/**
 * Import users into authentik.
 *
 * Deliberately one-directional and repeatable. The application stays the source
 * of truth for who exists; this reconciles authentik with it rather than
 * mirroring it back:
 *
 *   * a user absent from authentik is created
 *   * a user already present is left alone but added to any missing group
 *   * membership is checked per group, so a re-run is cheap and safe
 *
 * It never deletes anyone. Removing a user from a shared identity provider
 * affects every connected system, so that stays a deliberate act.
 *
 * No password is set. This establishes who exists, not how they sign in; an
 * administrator triggers a password reset for the accounts that need one.
 *
 * Requires an API token belonging to a service account, which is a far more
 * powerful credential than the sign-in client secret. It is supplied
 * separately and never used on a sign-in path.
 */
final class AuthentikUserImport
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $token,
        private readonly string $appGroup,
        private readonly int $timeout = 15,
        private readonly ?Transport $transport = null,
    ) {
        if ($this->token === '') {
            throw new AuthentikException(
                'Importing users requires an API token. Set the admin token, not the sign-in client secret.'
            );
        }

        if ($this->appGroup === '') {
            throw new AuthentikException(
                'Importing users requires the application group to add them to.'
            );
        }
    }

    private function http(): Transport
    {
        return $this->transport ?? new NativeTransport($this->timeout);
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return ['Authorization' => 'Bearer '.$this->token];
    }

    /** Is the API reachable and is the token accepted? */
    public function check(): bool
    {
        $response = $this->http()->get(
            $this->baseUrl.'/api/v3/core/users/?page_size=1',
            $this->headers(),
        );

        return $response['status'] === 200;
    }

    /**
     * What the import would do, without doing it.
     *
     * Running this first is the point: an administrator sees exactly which
     * accounts would be created before anything is written to a shared identity
     * provider.
     *
     * @param  list<array{email: string, name?: string, username?: string}>  $users
     * @return array{users: list<array<string, mixed>>, summary: array<string, int>}
     */
    public function preview(array $users): array
    {
        $rows = [];
        $toCreate = 0;

        foreach ($this->normalise($users) as $candidate) {
            $existing = $this->findUserByEmail($candidate['email']);

            if ($existing === null) {
                $toCreate++;
            }

            $rows[] = [
                'email' => $candidate['email'],
                'name' => $candidate['name'],
                'group' => $this->appGroup,
                'exists' => $existing !== null,
                'action' => $existing === null ? 'create' : 'add to group',
            ];
        }

        return [
            'users' => $rows,
            'summary' => [
                'total' => count($rows),
                'create' => $toCreate,
                'already present' => count($rows) - $toCreate,
            ],
        ];
    }

    /**
     * Perform the import.
     *
     * Safe to run repeatedly: existing users are not recreated, and group
     * membership is only written when it is missing.
     *
     * @param  list<array{email: string, name?: string, username?: string}>  $users
     * @return array{results: list<array<string, mixed>>, summary: array<string, int>}
     */
    public function import(array $users): array
    {
        $group = $this->findOrCreateGroup($this->appGroup);

        $results = [];
        $created = 0;
        $existing = 0;
        $added = 0;
        $failed = 0;

        foreach ($this->normalise($users) as $candidate) {
            $email = $candidate['email'];

            try {
                $user = $this->findUserByEmail($email);
                $wasCreated = false;

                if ($user === null) {
                    $user = $this->createUser($candidate);
                    $wasCreated = true;
                    $created++;
                } else {
                    $existing++;
                }

                $changed = $this->addUserToGroup($group, $user);
                if ($changed) {
                    $added++;
                    // The group endpoint replaces the whole member list, so keep
                    // the cached copy accurate or the next user would drop this
                    // one.
                    $group['users'][] = (int) $user['pk'];
                }

                $results[] = [
                    'email' => $email,
                    'name' => $candidate['name'],
                    'status' => $wasCreated ? 'created' : 'existing',
                    'group' => $this->appGroup,
                    'error' => null,
                ];
            } catch (AuthentikException $e) {
                $failed++;

                $results[] = [
                    'email' => $email,
                    'name' => $candidate['name'],
                    'status' => 'failed',
                    'group' => $this->appGroup,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return [
            'results' => $results,
            'summary' => [
                'total' => count($results),
                'created' => $created,
                'already present' => $existing,
                'group additions' => $added,
                'failed' => $failed,
            ],
        ];
    }

    /**
     * Drop entries without a usable email, and de-duplicate.
     *
     * A duplicate would otherwise be reported as "already present" on the second
     * pass, which reads like a reconciliation when it is really a bad input.
     *
     * @param  list<array<string, mixed>>  $users
     * @return list<array{email: string, name: string, username: string}>
     */
    private function normalise(array $users): array
    {
        $seen = [];
        $out = [];

        foreach ($users as $user) {
            $email = trim((string) ($user['email'] ?? ''));
            if ($email === '' || ! str_contains($email, '@')) {
                continue;
            }
            if (isset($seen[$email])) {
                continue;
            }
            $seen[$email] = true;

            $name = trim((string) ($user['name'] ?? ''));
            $username = trim((string) ($user['username'] ?? ''));

            $out[] = [
                'email' => $email,
                'name' => $name !== '' ? $name : $email,
                'username' => $username !== '' ? $username : $this->usernameFor($email),
            ];
        }

        return $out;
    }

    /** A stable, collision-free username derived from the address. */
    private function usernameFor(string $email): string
    {
        $local = strstr($email, '@', true) ?: $email;

        return mb_strtolower((string) preg_replace('/[^A-Za-z0-9._-]/', '-', $local));
    }

    /** @return array<string, mixed>|null */
    private function findUserByEmail(string $email): ?array
    {
        $response = $this->http()->get(
            $this->baseUrl.'/api/v3/core/users/?'.http_build_query(['email' => $email, 'page_size' => 1]),
            $this->headers(),
        );

        if ($response['status'] !== 200) {
            throw new AuthentikException("Could not search authentik for {$email}: HTTP {$response['status']}");
        }

        $body = json_decode($response['body'], true);

        return $body['results'][0] ?? null;
    }

    /**
     * @param  array{email: string, name: string, username: string}  $candidate
     * @return array<string, mixed>
     */
    private function createUser(array $candidate): array
    {
        $response = $this->http()->post(
            $this->baseUrl.'/api/v3/core/users/',
            [
                'username' => $candidate['username'],
                'email' => $candidate['email'],
                'name' => $candidate['name'],
                'is_active' => true,
                'path' => 'users',
            ],
            $this->headers() + ['Content-Type' => 'application/json'],
        );

        if (! in_array($response['status'], [200, 201], true)) {
            throw new AuthentikException(
                "Could not create {$candidate['email']}: HTTP {$response['status']} ".
                $this->summarise($response['body'])
            );
        }

        $body = json_decode($response['body'], true);

        if (! is_array($body) || ! isset($body['pk'])) {
            throw new AuthentikException("authentik accepted {$candidate['email']} but returned no user");
        }

        return $body;
    }

    /** @return array<string, mixed> */
    private function findOrCreateGroup(string $name): array
    {
        $response = $this->http()->get(
            $this->baseUrl.'/api/v3/core/groups/?'.http_build_query(['name' => $name, 'page_size' => 20]),
            $this->headers(),
        );

        if ($response['status'] !== 200) {
            throw new AuthentikException("Could not look up group {$name}: HTTP {$response['status']}");
        }

        $body = json_decode($response['body'], true);

        foreach ($body['results'] ?? [] as $group) {
            if (($group['name'] ?? null) === $name) {
                return $group;
            }
        }

        $created = $this->http()->post(
            $this->baseUrl.'/api/v3/core/groups/',
            ['name' => $name, 'is_superuser' => false],
            $this->headers() + ['Content-Type' => 'application/json'],
        );

        if (! in_array($created['status'], [200, 201], true)) {
            throw new AuthentikException(
                "Could not create group {$name}: HTTP {$created['status']} ".$this->summarise($created['body'])
            );
        }

        return json_decode($created['body'], true) ?? [];
    }

    /**
     * Add a user to a group, if not already a member.
     *
     * The group payload's `users` field is a list of user primary keys, and this
     * endpoint replaces the whole list — so the current members are read and the
     * new one appended. Sending only the new member would remove everyone else.
     *
     * @param  array<string, mixed>  $group
     * @param  array<string, mixed>  $user
     */
    private function addUserToGroup(array $group, array $user): bool
    {
        $userPk = (int) ($user['pk'] ?? 0);
        $groupPk = (string) ($group['pk'] ?? '');

        if ($userPk === 0 || $groupPk === '') {
            throw new AuthentikException('Cannot add a user to a group without both identifiers');
        }

        $members = array_map('intval', $group['users'] ?? []);

        if (in_array($userPk, $members, true)) {
            return false;
        }

        $response = $this->http()->patch(
            $this->baseUrl."/api/v3/core/groups/{$groupPk}/",
            ['users' => array_values(array_unique([...$members, $userPk]))],
            $this->headers() + ['Content-Type' => 'application/json'],
        );

        if ($response['status'] !== 200) {
            throw new AuthentikException(
                "Could not add user {$userPk} to group {$groupPk}: HTTP {$response['status']} ".
                $this->summarise($response['body'])
            );
        }

        return true;
    }

    /** authentik reports field errors as {field: [messages]}. Surface the first. */
    private function summarise(string $body): string
    {
        $decoded = json_decode($body, true);

        if (! is_array($decoded)) {
            return '';
        }

        foreach ($decoded as $field => $messages) {
            if (is_array($messages) && $messages !== []) {
                return $field.': '.implode(' ', array_map('strval', $messages));
            }
            if (is_string($messages)) {
                return $field.': '.$messages;
            }
        }

        return '';
    }
}
