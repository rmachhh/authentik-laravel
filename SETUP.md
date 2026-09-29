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
    "rmachhh/authentik-laravel": "0.2.4"
  }
}
```

Pin the exact version rather than using `^0.2`. Every `0.2.x` release is a
security-relevant change to an authentication path, and a caret range would
adopt it on the next `composer update` without anyone reviewing it. Raise the pin
deliberately.

```bash
composer update rmachhh/authentik-laravel
php artisan vendor:publish --tag=authentik-config
```

Do not add the repository to `composer.json` and then rely on
`composer install` to pick up a version bump — `install` only ever installs what
`composer.lock` already records. Changing the constraint requires
`composer update rmachhh/authentik-laravel`.

## Step 2 — Register the redirect URI in authentik

In the authentik admin interface: **Applications → Providers → your provider →
Redirect URIs**.

Add the exact callback URL for this environment:

```
http://localhost:8000/auth/authentik/callback          # development
https://myapp.example.com/auth/authentik/callback      # production
```

It must match `AUTHENTIK_REDIRECT_URI` **character for character**. A mismatch is
the most common cause of a login that loops back to the login page.

## Step 2b — Decide how a sign-in maps to a local account

This is the step that decides whether the integration is safe, so do not skip it.

authentik's default user settings flow lets people **edit their own email
address**. If your application matches a sign-in to a local account by email,
anyone who can sign in and change their address to someone else's can sign in as
that person. Before going live, do both of:

1. **Match on the `sub` claim** once an account has linked, and only fall back to
   email for the first link. `sub` is issued by authentik and cannot be changed by
   the account holder, so it survives an address change and cannot be reassigned.
   Step 4 shows the pattern.
2. **Make email read-only** in authentik's user settings flow, so the first link
   cannot be hijacked either. This is what makes email matching as safe as it is
   with Google.

Also **require the `email_verified` claim before trusting an address**.
authentik's stock `email` scope emits `email_verified: false`; the claim only
becomes `true` when you additionally request the separate `email_verified` scope:

- Attach the `email_verified` scope mapping to the provider in authentik
  (**Applications → Providers → your provider → Scopes**).
- Add `email_verified` to `authentik.scopes` in `config/authentik.php`.

Both halves are required, and requesting a scope the provider does not have fails
the sign-in with `invalid_scope`. What this claim does and does not prove is
documented on `AuthentikUser::hasVerifiedEmail()`.

## Step 3 — Add the environment variables

```dotenv
AUTHENTIK_ISSUER=https://id.example.com/application/o/myapp/
AUTHENTIK_CLIENT_ID=myapp
AUTHENTIK_CLIENT_SECRET=
AUTHENTIK_REDIRECT_URI=http://localhost:8000/auth/authentik/callback
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

        $user = $this->resolveAccount($remote);

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

    /**
     * Which local account does this authentik identity belong to?
     *
     * The `sub` claim is checked first because it is issued by authentik and
     * cannot be changed by the account holder: a linked account stays reachable
     * only by the identity that linked it, whatever happens to the address
     * afterwards.
     *
     * Email is consulted only to link an account the first time, and only when
     * the provider asserts the address is verified. Without that check, anyone
     * who can edit their own email in authentik could claim an administrator's
     * address and sign in as them.
     *
     * The account is this application's data, not authentik's. Nothing is
     * provisioned here.
     */
    private function resolveAccount(AuthentikUser $remote): ?User
    {
        $sub = $remote->subject;

        if ($sub !== '') {
            $linked = User::where('authentik_sub', $sub)->first();

            if ($linked) {
                return $linked->trashed() ? null : $linked;
            }
        }

        if (! $remote->hasVerifiedEmail() || ! $remote->email) {
            logger()->warning('authentik unverified address refused', ['sub' => $sub]);

            return null;
        }

        $user = User::where('email', $remote->email)->first();

        if (! $user || $user->trashed()) {
            return null;
        }

        // Already linked to a different identity. Refuse rather than relink:
        // after an address changes hands, matching by email alone would give
        // the account to whoever holds it now.
        if ($user->authentik_sub !== null && $user->authentik_sub !== $sub) {
            logger()->warning('authentik relink refused', [
                'user_id' => $user->id,
                'linked_sub' => $user->authentik_sub,
                'presented_sub' => $sub,
            ]);

            return null;
        }

        if ($sub !== '' && $user->authentik_sub === null) {
            $user->update(['authentik_sub' => $sub]);
        }

        return $user;
    }
}
```

`AuthentikUser` comes from `use Authentik\AuthentikUser;`. This needs one column,
so add it before wiring the controller:

```php
Schema::table('users', function (Blueprint $table) {
    $table->string('authentik_sub')->nullable()->unique();
});
```

and add `authentik_sub` to the model's `$fillable`. Nullable because linking
happens on first sign-in; `null` means "not linked yet" and must never be treated
as a wildcard. If you delete and recreate someone's authentik account their `sub`
changes and the relink guard above will refuse them — clear `authentik_sub` for
that user to let them link again.

## Step 5 — Add the routes

```php
use App\Http\Controllers\AuthentikController;

Route::get('/auth/authentik', [AuthentikController::class, 'redirect'])->name('authentik.redirect');
Route::get('/auth/authentik/callback', [AuthentikController::class, 'callback'])->name('authentik.callback');
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

## Step 7 (optional) — Import existing users

Only needed when the application already has users and authentik has none. A new
application can skip this and let accounts be created as people join.

```php
use Authentik\AuthentikUserImport;

$import = app(AuthentikUserImport::class);   // built from config

$users = User::query()
    ->whereNull('deleted_at')
    ->get()
    ->map(fn ($u) => ['email' => $u->email, 'name' => $u->name])
    ->all();

// Always preview first. This writes nothing.
$preview = $import->preview($users);
// $preview['summary'] -> total, create, already present
// $preview['users'][] -> action: "create" | "add to group"

// Then, once the preview looks right:
$result = $import->import($users);
// $result['summary'] -> total, created, already present, group additions, failed
```

Requires `AUTHENTIK_ADMIN_URL` and `AUTHENTIK_ADMIN_TOKEN` from step 3.

**Rules this import follows. Do not change them:**

- **Never deletes.** Removing a user from a shared identity provider affects
  every connected system, so it stays a deliberate act.
- **Never sets a password.** It establishes who exists, not how they sign in.
- **Never mirrors roles.** Every imported user joins `authentik.app_group`.
- **Repeatable.** An existing user is not recreated and membership is only
  written when missing, so a second run is safe.
- **A failure is per user.** One bad address is reported in the results rather
  than abandoning the rest.

**Verify:** run the import twice. The second run must report `created: 0` and
`group additions: 0`. If it reports more, the import is not reconciling.

**Get imported users a way to sign in.** An imported identity is created with an
unusable password — `!` followed by random characters, which no guess can match —
so it cannot sign in anywhere yet, including through SSO: authentik asks for a
password before it issues a token. Import alone leaves every newly created
identity locked out. Hand each one a single-use recovery link:

```php
$link = $import->recoveryLink($u->email);

if ($link !== null) {
    Mail::to($u->email)->send(new SetYourPassword($link));
}
```

`recoveryLink()` sends no mail; the application delivers the link. It returns
`null` when authentik has no account for that address — it does not create one.
A user that already existed in authentik keeps their existing password, because
the import only adds group membership, so they need no invitation; only newly
created identities do. It uses the admin token and base URL from step 3.

This needs a recovery flow in authentik, or it throws `No recovery flow set.`
Create one:

1. **Flows and Stages → Flows → Create** a flow for recovery.
2. Set that flow as the recovery flow for your brand (**System → Brands**), or
   authentik will not use it.
3. Add a **password prompt** stage and then a **password write** stage. Without
   both, the flow cannot collect and store a password.

`authentik returned no recovery link` means the response carried no link —
recheck the stages. **Do not put an identification stage in the recovery flow
unless an email stage follows it.** Identification alone lets anyone enter any
address and continue, so a visitor could set the password on someone else's
account. Either pair it with an email stage that proves the address belongs to
the person, or omit identification and deliver the link from the application.

---

## Step 8 — Verify

Run the built-in check first. It reports configuration, discovery, PKCE, the
redirect URI and the access guard, with a fix for each failure:

```bash
php artisan authentik:doctor
```

Then, in order, fixing each before moving on:

1. **The login page renders** the authentik link.
2. **`/auth/authentik` redirects to authentik** — the URL should contain
   `response_type=code`, `code_challenge_method=S256` and `state`.
3. **Sign in** with a user who is in the app's group. You should land on `/home`.
4. **Denial works.** Remove the user from the group in authentik, sign in again,
   and confirm the application returns 403.
5. **The session survives.** Reload a protected page; you should still be signed in.
6. **Account linking is durable.** Confirm `users.authentik_sub` is populated for
   the account you signed in with, then sign in again and confirm it is the same
   account and that nothing was relinked.

Report which steps succeeded, and include any error message verbatim.

---

## Failure messages and what they mean

| Symptom | Cause | Fix |
|---|---|---|
| `Refusing plain-HTTP issuer` | `http://` issuer outside development | Use `https://`, or set `allow_insecure` only for local work |
| Login loops to `/login` | State missing — the session did not persist the flow | Check the session driver and that `session_start` runs on the callback route |
| `State mismatch` | Callback does not belong to the sign-in this session started | Check for a second browser tab or a stale `authentik.flow` |
| `invalid_scope` from authentik | You requested `email_verified` but the provider has no such scope mapping | Attach it to the provider, or remove it from `authentik.scopes` |
| Sign-in refuses with "unverified address" | The provider sends `email_verified: false` | Request and attach the `email_verified` scope, or match on `sub` |
| Sign-in refuses after an account was recreated | The `sub` changed, so the relink guard fired | Clear `authentik_sub` for that user |
| `Request has been denied` (from authentik) | The user is in no group bound to the application | Add a binding, or add the user to the group |
| 403 from the middleware | The user is not in `authentik.app_group` | Correct the group name, or add them |
| `Target class [config] does not exist` | Middleware used outside a booted application | Run through Laravel, not a bare script |

## Things not to do

- Do not set roles or permissions from the `groups` claim.
- Do not create an account when no local user matches. This package's policy is to
  refuse and let an administrator decide. If the project wants provisioning, ask
  first — it needs an audit trail.
- Do not match a local account on an unverified email address, and do not relink
  an account that already holds a different `sub`.
- Do not log tokens, authorization codes or client secrets.
- Do not make `authentik.app_group` optional or fall back to allowing everyone.
