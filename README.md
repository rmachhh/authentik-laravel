# authentik-laravel

Single sign-on against [authentik](https://goauthentik.io) for Laravel, with
**one group per application**.

Companion package to [authentik-sdk](https://github.com/rmachhh/authentik-sdk)
for Node, with the same access model.

## The model

Every application has one authentik group. If the signed-in user is in that
group, they may use the application. That is the whole access rule.

```
authentik                          Your Laravel app
─────────                          ────────────────
group: myapp-access      ──▶       RequireAppGroup middleware  ──▶  allow / 403
```

**Roles are not set from authentik.** authentik answers *may this person use the
application*. What they may do once inside stays in your own database, guards and
policies. An identity-provider change can therefore never grant application
privileges.

## Requirements

- PHP 8.1+
- Laravel 10, 11, 12 or 13

No runtime dependency beyond the framework itself. HTTP goes through Laravel's
own client, so `Http::fake()` works in your tests.

## Install

```bash
composer require rmachhh/authentik-laravel
```

Until the package is on Packagist, install from the repository:

```json
{
  "repositories": [
    { "type": "vcs", "url": "https://github.com/rmachhh/authentik-laravel" }
  ],
  "require": {
    "rmachhh/authentik-laravel": "0.2.4"
  }
}
```

Every `0.2.x` release is a security-relevant change to an authentication path, so
the version is pinned exactly and raised deliberately: `composer update` will not
adopt a new one on its own.

## Configure

Publish the config, then set the environment variables:

```bash
php artisan vendor:publish --tag=authentik-config
```

```dotenv
AUTHENTIK_ISSUER=https://id.example.com/application/o/myapp/
AUTHENTIK_CLIENT_ID=myapp
AUTHENTIK_CLIENT_SECRET=
AUTHENTIK_REDIRECT_URI=https://myapp.example.com/auth/authentik/callback
AUTHENTIK_APP_GROUP=myapp-access
# Optional: only for a system with a single role.
AUTHENTIK_APP_ROLE=superadmin
```

The redirect URI must match the authentik provider **exactly**.

## Use

Three routes and a middleware alias. That is the whole integration.

```php
use Authentik\AuthentikUser;
use Authentik\FlowState;
use Authentik\Facades\Authentik;
use Authentik\Http\Middleware\RequireAppGroup;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

// 1. start a sign-in
Route::get('/auth/authentik', function () {
    ['url' => $url, 'flow' => $flow] = Authentik::startAuthorization();

    // Never sent to the browser as readable data: the verifier is what proves
    // the callback belongs to a sign-in this server started.
    Session::put('authentik.flow', $flow->toArray());

    return redirect()->away($url);
});

// 2. finish it
Route::get('/auth/authentik/callback', function () {
    $flow = FlowState::fromArray(Session::pull('authentik.flow', []));

    if ($flow->codeVerifier === '') {
        abort(400, 'That sign-in attempt has expired. Please try again.');
    }

    $remote = Authentik::completeLogin(request()->query(), $flow);

    if (! $remote->hasAccessTo(config('authentik.app_group'))) {
        abort(403, 'You do not have access to this application.');
    }

    // The account, and everything it may do, is yours — not authentik's.
    $user = resolveAccount($remote);
    Auth::login($user);

    Session::put(RequireAppGroup::SESSION_KEY, $remote);

    return redirect()->intended('/');
});

// 3. protect the application
Route::middleware(['web', 'authentik.group'])->group(function () {
    Route::get('/dashboard', DashboardController::class);
});
```

`resolveAccount()` is yours to write. Match on `authentik_sub` first, and fall
back to email only when the provider asserts the address is verified:

```php
function resolveAccount(AuthentikUser $remote): User
{
    // `sub` is issued by authentik and cannot be changed by the account holder.
    if ($user = User::where('authentik_sub', $remote->subject)->first()) {
        return $user;
    }

    // Email is only ever consulted for a first link, and only when verified.
    if (! $remote->hasVerifiedEmail()) {
        abort(403, 'Your email address is not verified.');
    }

    $user = User::where('email', $remote->email)->firstOrFail();

    // Already linked to a different identity. Refuse rather than relink: after
    // an address changes hands, email alone would hand over the account.
    if ($user->authentik_sub !== null && $user->authentik_sub !== $remote->subject) {
        abort(403, 'That account is already linked to another identity.');
    }

    $user->authentik_sub = $remote->subject;
    $user->save();

    return $user;
}
```

authentik's default user settings flow lets a user edit their own email address.
Matching on an unverified address therefore lets any signed-in user claim an
administrator's address, and with it the administrator's account. `sub` is issued
by authentik and is not editable by the account holder, so it is the only safe
key. Roles still come from your own database, never from the `groups` claim.

For a route that accepts any one of several groups:

```php
Route::middleware(['web', 'authentik.group:myapp-access,myapp-contractor'])->group(...);
```

## What each piece guarantees

| Call | Guarantee |
|---|---|
| `Authentik::startAuthorization()` | PKCE challenge generated; verifier, state and nonce returned for server-side storage |
| `Authentik::completeLogin()` | State and PKCE validated; nonce checked against the ID token; claims returned |
| `$user->hasAccessTo($group)` | Fails closed when no group is configured |
| `authentik.group` middleware | 403 when the group is missing, redirect to sign-in when not authenticated |
| `$user->hasVerifiedEmail()` | Returns true only when the provider asserts the address is verified |

### Account matching and verified email

authentik's stock `email` scope emits `email_verified: false`. Only the separate
`email_verified` scope — attached to the provider in authentik **and** listed in
`authentik.scopes` — makes it `true`. Requesting a scope the provider does not
have fails the sign-in with `invalid_scope`.

`AuthentikUser::hasVerifiedEmail()` reports that claim; it verifies nothing by
itself.

Deployment prerequisite: make email read-only in authentik's user settings flow,
so the address the email fallback matches on cannot be changed by its owner.

## Importing users

Move this application's users into authentik. Repeatable, and one-directional:
the application stays the source of truth for who exists.

```php
use Authentik\AuthentikUserImport;

$import = app(AuthentikUserImport::class);   // built from config

// Nothing is written:
$preview = $import->preview([
    ['email' => 'alex@example.com', 'name' => 'Alex'],
]);
// -> users[].action: "create" | "add to group"

// Writes:
$result = $import->import($users);
// -> summary: total, created, already present, group additions, failed
```

Requires an API token, which is a **far more powerful credential** than the
sign-in client secret. It is configured separately and never used on a sign-in
path:

```dotenv
AUTHENTIK_ADMIN_URL=http://localhost:9000
AUTHENTIK_ADMIN_TOKEN=
```

Every imported user joins `authentik.app_group` and nothing else. Roles are not
mirrored: access is one decision, and the application owns everything else.

**What it will not do**, deliberately:

- **Delete.** Removing a user from a shared identity provider affects every
  connected system, so it stays a deliberate act.
- **Set a password.** It establishes who exists, not how they sign in.
- **Mirror roles.** A static role here does not become an authentik group.

A per-user failure is reported in the results rather than aborting the run, so
one bad address does not stop the other ninety-nine.

### Getting imported users a way to sign in

An imported identity is created with an **unusable password** — `!` followed by
random characters, which no guess can match. So it cannot sign in anywhere, and
that includes SSO into your application: authentik's own login asks for a
password *before* it will issue an OIDC token. `import()` on its own leaves
everyone it created unable to sign in.

`recoveryLink()` is the other half of onboarding. It returns a single-use link —
`https://id.example.com/if/flow/default-recovery-flow/?flow_token=...` — that
lets the person set their own password. Nobody ever picks or transmits a
password, and nothing is emailed here: the application decides how the link
reaches the person.

```php
$link = $import->recoveryLink('alex@example.com');

if ($link !== null) {
    Mail::to('alex@example.com')->send(new SetYourPassword($link));
}
```

- **`null`** means authentik has no account for that address. It does not create
  one.
- **`AuthentikException`** means authentik refused. `No recovery flow set.` means
  the deployment has no recovery flow configured; `authentik returned no recovery
  link` means the response carried no link.
- It takes the same admin token and base URL as the import.

A user who already had an authentik account — created by another system — is
matched by email and keeps their existing password, because the import only adds
group membership. They need no invitation at all. Only newly created identities
do.


### Inspecting and removing identities

Two lower-level calls, mostly useful for building an administrative reset.

`groupMembers()` returns everyone in the configured access group, with the
identifiers needed to act on them:

```php
foreach ($import->groupMembers() as $member) {
    // ['pk' => 5, 'username' => 'alex', 'email' => 'alex@example.com', 'name' => 'Alex']
}
```

It is a single request — the group payload carries its members — and it is
deliberately strict in one direction: if authentik reports member primary keys
without their details, it throws rather than returning an empty list. "Nobody
to delete" and "could not tell who is there" must not look alike to a caller
that is about to delete things.

`deleteUser()` takes a primary key, because callers that have just listed a
group already hold them and an address lookup per user doubles the requests in
a loop whose length is the size of the directory.

```php
$import->deleteUser(5);   // true
$import->deleteUser(5);   // false — already gone, which is not an error
```

**The access group is the safety boundary.** Everything in it was created for
your application. The authentik administrator, the outpost, and any other
integration are not members, so a reset built on `groupMembers()` cannot reach
them. Do not delete users you have not listed this way.

## Failover between instances

Configuration errors are **not** failed over — a typo should not look like an
outage.

```php
// config/authentik.php
'instances' => [
    'primary' => [
        'issuer' => env('AUTHENTIK_ISSUER'),
        'client_id' => env('AUTHENTIK_CLIENT_ID'),
        'client_secret' => env('AUTHENTIK_CLIENT_SECRET'),
    ],
    'secondary' => [
        'issuer' => env('AUTHENTIK_BACKUP_ISSUER'),
        'client_id' => env('AUTHENTIK_BACKUP_CLIENT_ID'),
        'client_secret' => env('AUTHENTIK_BACKUP_CLIENT_SECRET'),
    ],
],
```

The first reachable instance starts the sign-in. **The callback always completes
against the instance that started it**, because an authorization code can only be
exchanged by its issuer — failing over mid-flow would break the exchange and look
like a login loop.

> **A second instance is only useful if it holds the same users and the same
> group names.** authentik instances do not share state. Otherwise failover turns
> an outage into a confusing access-denied error.

## Before you rely on this in production

A second authentik container does **not** make the service available. Each
deployment has its own database, and the database is the real single point of
failure. In order of actual value:

1. **Backups you have restored**
2. A replica or managed Postgres
3. A second application server sharing that database
4. A second full deployment

## Testing

The suite reuses a Laravel application's framework install, so it runs without a
lengthy dependency install:

```bash
LARAVEL_VENDOR=/path/to/app/vendor vendor/bin/phpunit
```

With a running authentik, the live check exercises discovery, the PKCE URL, the
refusal of a forged callback and failover:

```bash
LARAVEL_ENV=/path/to/app/.env php tests/live-check.php
```

## Design notes

- **No runtime dependency beyond the framework.** HTTP goes through an injectable
  transport, bound to Laravel's client in an application and to cURL in a CLI.
- **Fails closed.** An application that has not declared its group denies
  everyone.
- **Plain HTTP is refused** unless an instance explicitly opts in, so a
  production deployment cannot send credentials in the clear.
- **The PKCE verifier never reaches the browser** in readable form.
- **Nothing is logged that could be replayed** — denials record the groups seen,
  never the token.

## Licence

MIT
