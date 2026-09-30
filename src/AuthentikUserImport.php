<?php

declare(strict_types=1);

namespace Authentik;

use Authentik\Contracts\Transport;
use Authentik\Http\NativeTransport;

/**
 * Manage users in authentik: import them, and get them a way to sign in.
 *
 * The import is deliberately one-directional and repeatable. The application
 * stays the source of truth for who exists; this reconciles authentik with it
 * rather than mirroring it back:
 *
 *   * a user absent from authentik is created
 *   * a user already present is left alone but added to any missing group
 *   * membership is checked per group, so a re-run is cheap and safe
 *
 * It never deletes anyone. Removing a user from a shared identity provider
 * affects every connected system, so that stays a deliberate act.
 *
 * No password is set, which means an imported identity cannot sign in anywhere
 * until it has one — including through SSO, because authentik's own login asks
 * for a password first. `recoveryLink()` is the other half: it mints a one-time
 * link the application can deliver so the person chooses their own password.
 * Neither half sends mail; that belongs to the application.
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
     * A single-use link that lets somebody set their own password.
     *
     * The counterpart to import(). An imported identity is created with an
     * unusable password — `!` followed by random characters, which no guess can
     * ever match — so without this the account exists but its owner cannot sign
     * in anywhere, including through SSO, because authentik's own login needs a
     * password. This is how a freshly created user gets one.
     *
     * Nothing is emailed here. The caller decides how the link reaches the
     * person, because the application usually already owns a working mail
     * channel and the link carries a one-time token that should be delivered
     * over a channel the recipient trusts.
     *
     * The deployment must have a recovery flow configured; authentik answers
     * `No recovery flow set.` otherwise, which surfaces in the exception.
     *
     * @return string|null null when no account exists for the address
     */
    public function recoveryLink(string $email): ?string
    {
        $user = $this->findUserByEmail($email);

        if ($user === null) {
            return null;
        }

        $pk = (int) ($user['pk'] ?? 0);

        if ($pk === 0) {
            throw new AuthentikException("authentik returned no pk for {$email}");
        }

        $response = $this->http()->post(
            $this->baseUrl."/api/v3/core/users/{$pk}/recovery/",
            [],
            $this->headers() + ['Content-Type' => 'application/json'],
        );

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new AuthentikException(
                "Could not create a recovery link for {$email}: HTTP {$response['status']} ".
                $this->summarise($response['body'])
            );
        }

        $link = json_decode($response['body'], true)['link'] ?? null;

        if (! is_string($link) || $link === '') {
            throw new AuthentikException("authentik returned no recovery link for {$email}");
        }

        return $link;
    }

    /**
     * Everyone in the application's access group, with the identifiers needed
     * to act on them.
     *
     * This group is what separates identities created for this application from
     * everything else in the provider — the administrator account, the outpost,
     * other integrations — which is what makes it the only safe boundary for
     * anything destructive. See deleteUser().
     *
     * One request: the group payload carries its members as `users_obj`. When
     * that is missing but `users` is not, this throws rather than reporting an
     * empty directory, because "nobody to delete" and "could not tell" must not
     * look the same to a caller that is about to delete things.
     *
     * @return list<array{pk: int, username: string, email: string, name: string}>
     */
    public function groupMembers(): array
    {
        $group = $this->findGroup($this->appGroup);

        if ($group === null) {
            return [];
        }

        $members = $group['users_obj'] ?? null;

        if (! is_array($members)) {
            if (array_filter(array_map('intval', $group['users'] ?? [])) !== []) {
                throw new AuthentikException(
                    "authentik listed members of {$this->appGroup} without their details, "
                    .'so they cannot be identified safely.'
                );
            }

            return [];
        }

        $listed = [];

        foreach ($members as $user) {
            $pk = (int) ($user['pk'] ?? 0);

            if ($pk === 0) {
                continue;
            }

            $listed[] = [
                'pk' => $pk,
                'username' => (string) ($user['username'] ?? ''),
                'email' => (string) ($user['email'] ?? ''),
                'name' => (string) ($user['name'] ?? ''),
            ];
        }

        return $listed;
    }

    /**
     * Delete one identity.
     *
     * Takes a primary key rather than an address: callers listing a group
     * already hold them, and looking each one up by address would double the
     * requests in a loop whose length is the size of the directory.
     *
     * @return bool false when the identity was already gone
     */
    public function deleteUser(int|string $pk): bool
    {
        $response = $this->http()->delete(
            $this->baseUrl."/api/v3/core/users/{$pk}/",
            $this->headers(),
        );

        // Already gone. Somebody else got there first, which is the outcome
        // being asked for either way.
        if ($response['status'] === 404) {
            return false;
        }

        if (! in_array($response['status'], [200, 204], true)) {
            throw new AuthentikException(
                "Could not delete user {$pk}: HTTP {$response['status']} ".$this->summarise($response['body'])
            );
        }

        return true;
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
        $claimed = [];

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
                'username' => $username !== ''
                    ? $username
                    : $this->usernameFor($email, $claimed),
            ];

            $claimed[] = $out[count($out) - 1]['username'];
        }

        return $out;
    }

    /**
     * A username derived from the address.
     *
     * Readable for the common case — `ralph@rhet-corp.com` becomes `ralph` —
     * and unique when it has to be. The local part alone is not unique:
     * `john@a.example` and `john@b.example` both reduce to `john`, and two
     * people at different schools routinely share a first name. On a collision
     * the domain is appended, so the second address still gets a username
     * rather than the import failing with "this field must be unique".
     *
     * The suffix is derived from the address, not random, so the same input
     * always produces the same username and a re-run does not create a second
     * account.
     *
     * @param  list<string>  $taken  usernames already claimed in this run
     */
    private function usernameFor(string $email, array $taken = []): string
    {
        $local = $this->slug(strstr($email, '@', true) ?: $email);
        $taken = array_map('strtolower', $taken);

        if ($local !== '' && ! in_array($local, $taken, true)) {
            return $local;
        }

        $base = $local !== '' ? $local : 'user';
        $domain = $this->domainSlug((string) strstr($email, '@'));

        // Collision: qualify with the domain, which is what actually differs.
        $suffixed = $domain === '' ? $base : $base.'-'.$domain;

        if (! in_array($suffixed, $taken, true)) {
            return $suffixed;
        }

        // Two addresses that reduce to the same string even with the domain:
        // fall back to a short digest, which cannot realistically collide.
        return $base.'-'.substr(hash('sha256', $email), 0, 8);
    }

    /** A readable slug: letters, digits, dot, underscore and hyphen. */
    private function slug(string $value): string
    {
        return trim(
            mb_strtolower((string) preg_replace('/[^A-Za-z0-9._-]/', '-', $value)),
            '-',
        );
    }

    /** A slug for a domain: alphanumeric only, so `@b.example` becomes `bexample`. */
    private function domainSlug(string $domain): string
    {
        return mb_strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', $domain));
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
     * Create the user, resolving a username that is already taken.
     *
     * The preferred username comes from the address, but authentik's usernames
     * are globally unique — and a local part is not: two people at different
     * schools both called John produce `john` twice. When authentik refuses the
     * name, it is retried with the domain appended, then with a digest. Without
     * this the second John is simply reported as a failure.
     *
     * @param  array{email: string, name: string, username: string}  $candidate
     * @return array<string, mixed>
     */
    private function createUser(array $candidate): array
    {
        $lastError = null;

        foreach ($this->usernameAttempts($candidate) as $username) {
            $response = $this->http()->post(
                $this->baseUrl.'/api/v3/core/users/',
                [
                    'username' => $username,
                    'email' => $candidate['email'],
                    'name' => $candidate['name'],
                    'is_active' => true,
                    'path' => 'users',
                ],
                $this->headers() + ['Content-Type' => 'application/json'],
            );

            if (in_array($response['status'], [200, 201], true)) {
                $body = json_decode($response['body'], true);

                if (! is_array($body) || ! isset($body['pk'])) {
                    throw new AuthentikException(
                        "authentik accepted {$candidate['email']} but returned no user"
                    );
                }

                return $body;
            }

            $body = json_decode($response['body'], true);
            $lastError = $response['status'].' '.$this->summarise($response['body']);

            // Only a taken username is worth retrying. Anything else — a
            // malformed address, a permissions problem — will fail again.
            if (! $this->usernameIsTaken($body)) {
                break;
            }
        }

        throw new AuthentikException(
            "Could not create {$candidate['email']}: HTTP {$lastError}"
        );
    }

    /**
     * Usernames to try, in order of preference.
     *
     * @param  array{email: string, username: string}  $candidate
     * @return list<string>
     */
    private function usernameAttempts(array $candidate): array
    {
        $preferred = $candidate['username'];
        $domain = $this->domainSlug((string) strstr($candidate['email'], '@'));
        $qualified = $domain === '' ? $preferred : $preferred.'-'.$domain;

        return array_values(array_unique([
            $preferred,
            $qualified,
            $preferred.'-'.substr(hash('sha256', $candidate['email']), 0, 8),
        ]));
    }

    /** @param array<string, mixed>|null $body */
    private function usernameIsTaken(?array $body): bool
    {
        if (! is_array($body)) {
            return false;
        }

        foreach ($body as $field => $messages) {
            if ($field !== 'username') {
                continue;
            }
            $text = is_array($messages) ? implode(' ', array_map('strval', $messages)) : (string) $messages;

            return stripos($text, 'unique') !== false || stripos($text, 'exists') !== false;
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function findOrCreateGroup(string $name): array
    {
        $group = $this->findGroup($name);

        if ($group !== null) {
            return $group;
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

    /** @return array<string, mixed>|null null when no group carries that name */
    private function findGroup(string $name): ?array
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

        return null;
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
