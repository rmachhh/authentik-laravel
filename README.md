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
    "rmachhh/authentik-laravel": "dev-main"
  }
}
```

## Configure

Publish the config, then set the environment variables:

```bash
php artisan vendor:publish --tag=authentik-config
```

```dotenv
AUTHENTIK_ISSUER=https://id.example.com/application/o/myapp/
AUTHENTIK_CLIENT_ID=myapp
AUTHENTIK_CLIENT_SECRET=
AUTHENTIK_REDIRECT_URI=https://myapp.example.com/auth/callback
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
Route::get('/auth/redirect', function () {
    ['url' => $url, 'flow' => $flow] = Authentik::startAuthorization();

    // Never sent to the browser as readable data: the verifier is what proves
    // the callback belongs to a sign-in this server started.
    Session::put('authentik.flow', $flow->toArray());

    return redirect()->away($url);
});

// 2. finish it
Route::get('/auth/callback', function () {
    $flow = FlowState::fromArray(Session::pull('authentik.flow', []));

    if ($flow->codeVerifier === '') {
        abort(400, 'That sign-in attempt has expired. Please try again.');
    }

    $remote = Authentik::completeLogin(request()->query(), $flow);

    if (! $remote->hasAccessTo(config('authentik.app_group'))) {
        abort(403, 'You do not have access to this application.');
    }

    // The account, and everything it may do, is yours — not authentik's.
    $user = User::where('email', $remote->email)->firstOrFail();
    Auth::login($user);

    Session::put(RequireAppGroup::SESSION_KEY, $remote);

    return redirect()->intended('/');
});

// 3. protect the application
Route::middleware(['web', 'authentik.group'])->group(function () {
    Route::get('/dashboard', DashboardController::class);
});
```

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
