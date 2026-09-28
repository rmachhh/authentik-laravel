<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | The group that guards this application
    |--------------------------------------------------------------------------
    |
    | One group per application is the whole access model: if the signed-in user
    | is in this group they may use the application. Create the group in
    | authentik and bind the application to it.
    |
    | Leave it null and every request is denied — an unconfigured guard must
    | never let everyone in.
    |
    */

    'app_group' => env('AUTHENTIK_APP_GROUP'),

    /*
    |--------------------------------------------------------------------------
    | Where to send someone who is not signed in
    |--------------------------------------------------------------------------
    */

    'login_route' => env('AUTHENTIK_LOGIN_ROUTE', '/auth/redirect'),

    /*
    |--------------------------------------------------------------------------
    | Instances
    |--------------------------------------------------------------------------
    |
    | Tried in order. The first reachable instance starts a sign-in; the callback
    | always completes against the instance that started it, because an
    | authorization code can only be exchanged by its issuer.
    |
    | A second instance is only useful if it holds the same users and the same
    | group names — authentik instances do not share state. Otherwise failover
    | turns an outage into an access-denied error.
    |
    */

    'instances' => [

        'primary' => [
            'issuer' => env('AUTHENTIK_ISSUER'),
            'client_id' => env('AUTHENTIK_CLIENT_ID'),
            'client_secret' => env('AUTHENTIK_CLIENT_SECRET'),
            'allow_insecure' => env('AUTHENTIK_ALLOW_INSECURE', false),
        ],

        // 'secondary' => [
        //     'issuer' => env('AUTHENTIK_BACKUP_ISSUER'),
        //     'client_id' => env('AUTHENTIK_BACKUP_CLIENT_ID'),
        //     'client_secret' => env('AUTHENTIK_BACKUP_CLIENT_SECRET'),
        // ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Shared settings
    |--------------------------------------------------------------------------
    |
    | `redirect_uri` must match what is registered on the authentik provider,
    | exactly. The scopes below are all that is needed: `profile` already
    | includes group membership, so no custom scope is required.
    |
    */

    'redirect_uri' => env('AUTHENTIK_REDIRECT_URI'),

    'scopes' => ['openid', 'email', 'profile'],

    'allow_insecure' => env('AUTHENTIK_ALLOW_INSECURE', false),

    'timeout' => env('AUTHENTIK_TIMEOUT', 10),

    /*
    |--------------------------------------------------------------------------
    | Failover notification
    |--------------------------------------------------------------------------
    |
    | Called with (Throwable $error, AuthentikClient $instance) when an instance
    | is skipped. Point it at your logger so a failing primary is noticed rather
    | than silently absorbed.
    |
    */

    'on_failover' => null,

];
