<?php

declare(strict_types=1);

namespace Authentik;

/**
 * The verified result of a sign-in.
 *
 * Deliberately small: identity and group membership, which is everything
 * authentik is authoritative for. Any role the application assigns comes from
 * the application, never from here.
 */
final class AuthentikUser
{
    /**
     * @param  list<string>  $groups  every group the user belongs to, unfiltered
     */
    public function __construct(
        public readonly string $subject,
        public readonly ?string $email = null,
        public readonly ?string $name = null,
        public readonly array $groups = [],
        public readonly array $claims = [],
    ) {
    }

    /**
     * Build from verified ID token claims.
     *
     * The groups claim arrives in several shapes depending on the provider and
     * the scopes requested, so accept them all rather than refusing a login over
     * formatting.
     *
     * @param  array<string, mixed>  $claims
     */
    public static function fromClaims(array $claims): self
    {
        $raw = $claims['groups'] ?? $claims['group'] ?? [];
        $raw = is_array($raw) ? $raw : [$raw];

        $groups = [];
        foreach ($raw as $group) {
            if (! is_string($group)) {
                continue;
            }
            $group = trim($group);
            if ($group !== '') {
                $groups[$group] = true;
            }
        }

        return new self(
            subject: (string) ($claims['sub'] ?? ''),
            email: isset($claims['email']) ? (string) $claims['email'] : null,
            name: isset($claims['name']) ? (string) $claims['name'] : null,
            groups: array_keys($groups),
            claims: $claims,
        );
    }

    /**
     * Does this user hold the group that guards an application?
     *
     * Fails closed: an application that has not declared its group denies
     * everyone, rather than accidentally allowing everyone.
     *
     * @param  string|list<string>|null  $required  the app's group, or several (any of)
     */
    public function hasAccessTo(string|array|null $required): bool
    {
        $required = is_array($required) ? $required : [$required];

        $required = array_values(array_filter(
            array_map(static fn ($g) => is_string($g) ? trim($g) : '', $required),
            static fn ($g) => $g !== '',
        ));

        if ($required === []) {
            return false;
        }

        return array_intersect($required, $this->groups) !== [];
    }

    /**
     * The single group that granted access, or null.
     *
     * Useful for logging which group let someone in, without recording the
     * whole membership list.
     *
     * @param  string|list<string>|null  $required
     */
    public function matchedGroup(string|array|null $required): ?string
    {
        $required = is_array($required) ? $required : [$required];

        foreach ($required as $group) {
            if (is_string($group) && $group !== '' && in_array(trim($group), $this->groups, true)) {
                return trim($group);
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'sub' => $this->subject,
            'email' => $this->email,
            'name' => $this->name,
            'groups' => $this->groups,
        ];
    }
}
