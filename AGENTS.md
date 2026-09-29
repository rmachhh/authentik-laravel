# Conventions for agents working in this repository

## What this package is

`authentik-laravel` adds authentik sign-in to Laravel applications and decides
whether the signed-in user may use an application. It does not manage roles,
permissions or users, and it must not start doing so.

## The rules this package encodes

These are load-bearing. A change that breaks one is a bug even if tests pass.

1. **One group per application.** Membership of the application's group is the
   entire access decision. Do not add a second gate.
2. **Never derive roles from the groups claim.** Roles belong to the consuming
   application. This is why the package exists in its current shape.
3. **Fail closed.** An application with no configured group denies everyone. A
   missing or malformed claim denies. Never let an unconfigured guard allow.
4. **Do not remove existing access on an unmatched claim.** A user whose groups
   match nothing keeps the roles they had.
5. **The callback completes against the instance that started the sign-in.** A
   code can only be exchanged by its issuer. Failing over mid-flow breaks the
   exchange and looks like a login loop.
6. **Configuration errors are not failed over.** Only reachability failures try
   the next instance. A typo must not look like an outage.
7. **Refuse plain HTTP** unless `allow_insecure` is set, and only for local
   development.
8. **Never log tokens, codes or client secrets.** Log outcomes and group names.
9. **The user import never deletes, never sets a password, and never mirrors
   roles.** Removing a user from a shared identity provider affects every
   connected system, so it stays a deliberate act. A role here does not become
   an authentik group.
10. **The import is repeatable.** An existing user is not recreated, and group
    membership is only written when missing. Never make it a one-shot that
    creates duplicates on a second run.
11. **The import needs a separate admin token.** It is a far more powerful
    credential than the sign-in client secret. Never reuse one for the other,
    and never put the admin token on a sign-in path.
12. **Never match a local account on an unverified email, and never relink a
    different subject.** Match on `authentik_sub` first. Fall back to email only
    when `AuthentikUser::hasVerifiedEmail()` is true, because authentik's user
    settings flow lets an account holder edit their own address. An account
    whose `authentik_sub` is already set must not be relinked to a different
    value. Roles are still never assigned from the `groups` claim.
13. **An imported identity cannot sign in until it gets a recovery link.**
    `import()` creates identities with no usable password, so nobody it creates
    can authenticate anywhere, including through SSO. `recoveryLink()` is what
    gives them a way in. This package never sends mail; the application delivers
    the link.

## Layout

```
src/
  AuthentikClient.php           one instance: discovery, PKCE URL, code exchange
  AuthentikUserImport.php       user import; needs an API token, not the secret
  FailoverAuthentikClient.php   several instances, tried in order
  AuthentikUser.php             the access decision and claim parsing (pure)
  FlowState.php                 the flow state to store between redirect and callback
  ServiceProvider.php           config, bindings, middleware alias, doctor command
  Facades/Authentik.php         the facade
  Contracts/Transport.php       HTTP behind an interface, so the client is testable
  Http/LaravelTransport.php     bound to Laravel's HTTP client
  Http/NativeTransport.php      cURL, for CLI and tests without a framework
  Http/Middleware/RequireAppGroup.php
  Console/DoctorCommand.php     `php artisan authentik:doctor`
config/authentik.php
tests/                          PHPUnit; bootstrap reuses a sibling app's vendor
SETUP.md                        the exact steps for integrating into an application
llms.txt                        orientation for AI agents
```

**`AuthentikUserImport` must not use the OIDC client.** The import is
administered with an API token, and mixing the two would let a sign-in path
reach an operation that creates users. It talks to the API through the same
`Contracts\Transport`.

**`AuthentikClient` must not use the global helpers** (`config()`, `logger()`,
`abort()`, `redirect()`). They require a booted application and make the client
untestable. Dependencies are injected; see `RequireAppGroup` for the pattern.

**HTTP must go through `Contracts\Transport`.** Do not call `Http::` directly
from the client, or the package stops working outside a Laravel application.

## Testing

```bash
LARAVEL_VENDOR=/path/to/app/vendor vendor/bin/phpunit   # no credentials or network
LARAVEL_ENV=/path/to/app/.env php tests/live-check.php   # live, needs authentik
LARAVEL_ENV=/path/to/app/.env php tests/run-doctor.php   # the artisan command
```

- The bootstrap reuses an existing Laravel `vendor/` rather than installing the
  framework, so the suite runs in seconds. Keep it that way.
- Unit tests must not require a network or credentials.
- A test that asserts a bug is fixed must fail without the fix.
- `tests/live-check.php` and `tests/run-doctor.php` are checks, not PHPUnit
  tests; keep them runnable standalone.
- The import tests need no credentials: `AuthentikUserImport` takes an injected
  `Transport`, so it is tested against a scripted one. Do not make it reach the
  network in a unit test.

## Changing the public API

- Adding an option is fine; changing or removing one is breaking and needs a major
  version.
- Keep the single-instance configuration working. One entry under `instances` must
  behave exactly like a top-level issuer.
- Error messages are part of the API: an agent or a human reads them to decide
  what to do. Name the setting and say what to do about it.

## Before committing

```bash
LARAVEL_VENDOR=/path/to/app/vendor vendor/bin/phpunit
LARAVEL_ENV=/path/to/app/.env php tests/run-doctor.php
```

Check that no real credential, client ID, email address or organisation name has
entered the package. It is published publicly.
