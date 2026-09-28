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

## Layout

```
src/
  AuthentikClient.php           one instance: discovery, PKCE URL, code exchange
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

**`AuthentikClient` must not use the global helpers** (`config()`, `logger()`,
`abort()`, `redirect()`). They require a booted application and make the client
untestable. Dependencies are injected; see `RequireAppGroup` for the pattern.

**HTTP must go through `Contracts\Transport`.** Do not call `Http::` directly
from the client, or the package stops working outside a Laravel application.

## Testing

```bash
LARAVEL_VENDOR=/path/to/app/vendor vendor/bin/phpunit   # 19 tests, no credentials
LARAVEL_ENV=/path/to/app/.env php tests/live-check.php   # live, needs authentik
LARAVEL_ENV=/path/to/app/.env php tests/run-doctor.php   # the artisan command
```

- The bootstrap reuses an existing Laravel `vendor/` rather than installing the
  framework, so the suite runs in seconds. Keep it that way.
- Unit tests must not require a network or credentials.
- A test that asserts a bug is fixed must fail without the fix.
- `tests/live-check.php` and `tests/run-doctor.php` are checks, not PHPUnit
  tests; keep them runnable standalone.

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
