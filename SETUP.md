# Adding authentik sign-in to a Laravel application

Exact steps. Follow them literally rather than inventing wiring — the order and
the details matter, and most integration failures come from skipping step 2 or 6.

Before starting, you need four values from authentik. If any are missing, stop
and ask rather than guessing:

| Value | Where it comes from |
|---|---|
| `AUTHENTIK_ISSUER` | The application's issuer URL, ending `/application/o/<slug>/` |
| `AUTHENTIK_CLIENT_ID` | The provider's client ID |
| `AUTHENTIK_CLIENT_SECRET` | The provider's client secret |
| `AUTHENTIK_APP_GROUP` | The authentik group that grants access to this application |

---

## Step 1 — Install

```json
{
  "repositories": [
    { "type": "vcs", "url": "https://github.com/rmachhh/authentik-laravel" }
  ],
  "require": {
    "rmachhh/authentik-laravel": "^0.1"
  }
}
```

```bash
composer update rmachhh/authentik-laravel
php artisan vendor:publish --tag=authentik-config
```

## Step 2 — Register the redirect URI in authentik

In the authentik admin interface: **Applications → Providers → your provider →
Redirect URIs**.

Add the exact callback URL for this environment:

```
http://localhost:8000/auth/callback          # development
https://myapp.example.com/auth/callback      # production
```

It must match `AUTHENTIK_REDIRECT_URI` **character for character**. A mismatch is
the most common cause of a login that loops back to the login page.

## Step 3 — Add the environment variables

```dotenv
AUTHENTIK_ISSUER=https://id.example.com/application/o/myapp/
AUTHENTIK_CLIENT_ID=myapp
AUTHENTIK_CLIENT_SECRET=
AUTHENTIK_REDIRECT_URI=http://localhost:8000/auth/callback
AUTHENTIK_APP_GROUP=myapp-access
# Optional: only for a system with a single role.
AUTHENTIK_APP_ROLE=superadmin

# Importing users needs an API token, not the sign-in secret.
AUTHENTIK_ADMIN_URL=http://localhost:9000
AUTHENTIK_ADMIN_TOKEN=
```

`AUTHENTIK_CLIENT_SECRET` belongs in `.env` only. Never commit it.

Then clear the config cache, or Laravel will keep serving the old values:

```bash
php artisan config:clear
```

## Step 4 — Create the controller

```bash
php artisan make:controller AuthentikController
```

```php
<?php

namespace App\Http\Controllers;

use Authentik\Facades\Authentik;
use Authentik\FlowState;
use Authentik\Http\Middleware\RequireAppGroup;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

class AuthentikController extends Controller
{
    /** Start a sign-in. */
    public function redirect()
    {
        ['url' => $url, 'flow' => $flow] = Authentik::startAuthorization();

        // Server-side only: the verifier is what proves the callback belongs to
        // a sign-in this application started. Never expose it to the browser.
        Session::put('authentik.flow', $flow->toArray());

        return redirect()->away($url);
    }

    /** Finish a sign-in. */
    public function callback(Request $request)
    {
        $flow = FlowState::fromArray(Session::pull('authentik.flow', []));

        if ($flow->codeVerifier === '') {
            return redirect('/login')->withErrors([
                'authentik' => 'That sign-in attempt expired. Please try again.',
            ]);
        }

        try {
            $remote = Authentik::completeLogin($request->query(), $flow);
        } catch (\Authentik\AuthentikException $e) {
            report($e);

            return redirect('/login')->withErrors([
                'authentik' => 'Sign-in could not be completed. Please try again.',
            ]);
        }

        // May this person use this application at all?
        if (! $remote->hasAccessTo(config('authentik.app_group'))) {
            logger()->warning('authentik access denied', [
                'email' => $remote->email,
                'groups_present' => $remote->groups,
            ]);

            abort(403, 'You do not have access to this application.');
        }

        // The account is this application's data, not authentik's.
        $user = User::where('email', $remote->email)->first();

        if (! $user) {
            return redirect('/login')->withErrors([
                'authentik' => 'No account exists for that address. Contact your administrator.',
            ]);
        }

        Auth::login($user);
        $request->session()->regenerate();

        // Roles ride along from the application. Do not set them from groups.
        Session::put(RequireAppGroup::SESSION_KEY, $remote);

        return redirect()->intended('/home');
    }
}
```

## Step 5 — Add the routes

```php
use App\Http\Controllers\AuthentikController;

Route::get('/auth/redirect', [AuthentikController::class, 'redirect'])->name('authentik.redirect');
Route::get('/auth/callback', [AuthentikController::class, 'callback'])->name('authentik.callback');
```

Add a link labelled "Sign in with authentik" pointing at `route('authentik.redirect')`.

## Step 6 — Protect the application

```php
Route::middleware(['web', 'authentik.group'])->group(function () {
    Route::get('/home', HomeController::class);
});

// Any one of several groups:
Route::middleware(['web', 'authentik.group:myapp-access,myapp-contractor'])->group(...);
```

## Step 7 — Verify

Run the built-in check first. It reports configuration, discovery, PKCE, the
redirect URI and the access guard, with a fix for each failure:

```bash
php artisan authentik:doctor
```

Then, in order, fixing each before moving on:

1. **The login page renders** the authentik link.
2. **`/auth/redirect` redirects to authentik** — the URL should contain
   `response_type=code`, `code_challenge_method=S256` and `state`.
3. **Sign in** with a user who is in the app's group. You should land on `/home`.
4. **Denial works.** Remove the user from the group in authentik, sign in again,
   and confirm the application returns 403.
5. **The session survives.** Reload a protected page; you should still be signed in.

Report which steps succeeded, and include any error message verbatim.

---

## Failure messages and what they mean

| Symptom | Cause | Fix |
|---|---|---|
| `Refusing plain-HTTP issuer` | `http://` issuer outside development | Use `https://`, or set `allow_insecure` only for local work |
| Login loops to `/login` | State missing — the session did not persist the flow | Check the session driver and that `session_start` runs on the callback route |
| `State mismatch` | Callback does not belong to the sign-in this session started | Check for a second browser tab or a stale `authentik.flow` |
| `Request has been denied` (from authentik) | The user is in no group bound to the application | Add a binding, or add the user to the group |
| 403 from the middleware | The user is not in `authentik.app_group` | Correct the group name, or add them |
| `Target class [config] does not exist` | Middleware used outside a booted application | Run through Laravel, not a bare script |

## Things not to do

- Do not set roles or permissions from the `groups` claim.
- Do not create an account when no local user matches. This package's policy is to
  refuse and let an administrator decide. If the project wants provisioning, ask
  first — it needs an audit trail.
- Do not log tokens, authorization codes or client secrets.
- Do not make `authentik.app_group` optional or fall back to allowing everyone.
