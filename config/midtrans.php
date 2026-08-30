<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | API Credentials
    |--------------------------------------------------------------------------
    |
    | The server key authenticates every backend call and must never reach the
    | browser. The client key is public and only needed for the card
    | tokenization endpoints, which you should be calling from the browser.
    |
    */

    'server_key' => env('MIDTRANS_SERVER_KEY'),

    'client_key' => env('MIDTRANS_CLIENT_KEY'),

    /*
    | Sandbox by default: going live has to be a deliberate act, not something
    | that happens because an env var was forgotten.
    */
    'is_production' => (bool) env('MIDTRANS_IS_PRODUCTION', false),

    /*
    |--------------------------------------------------------------------------
    | HTTP Behaviour
    |--------------------------------------------------------------------------
    */

    'timeout' => (int) env('MIDTRANS_TIMEOUT', 30),

    /*
    | Retries only ever apply where a replay is provably harmless: GET requests,
    | POSTs carrying an Idempotency-Key, and the state-setting PATCH/DELETE
    | endpoints. Tokenization and payment-account calls are never retried.
    */
    'max_retries' => (int) env('MIDTRANS_MAX_RETRIES', 2),

    'retry_delay_ms' => (int) env('MIDTRANS_RETRY_DELAY_MS', 200),

    /*
    | Prefix for the Idempotency-Key generated per mutating request. Maximum 13
    | characters: Midtrans caps the whole key at 46 and silently ignores longer
    | ones, which would turn the retry guard into a false sense of safety.
    */
    'idempotency_key_prefix' => env('MIDTRANS_IDEMPOTENCY_PREFIX', 'midtrans'),

    /*
    |--------------------------------------------------------------------------
    | Optional Request Headers
    |--------------------------------------------------------------------------
    |
    | Sent on every request when set. Override notification replaces your
    | dashboard notification URL, append adds an extra one.
    |
    */

    'append_notification_url' => env('MIDTRANS_APPEND_NOTIFICATION_URL'),

    'override_notification_url' => env('MIDTRANS_OVERRIDE_NOTIFICATION_URL'),

    /*
    | Language for payment pages Midtrans renders itself: 'id-ID' or 'en-EN'.
    */
    'payment_locale' => env('MIDTRANS_PAYMENT_LOCALE'),

    'pop_id' => env('MIDTRANS_POP_ID'),

    /*
    |--------------------------------------------------------------------------
    | Snap-BI (BI-SNAP Core API)
    |--------------------------------------------------------------------------
    |
    | Every value here is optional. Snap-BI is a separate product with its own
    | onboarding; if you only use Core API and Snap, leave this block untouched.
    |
    */

    'snap_bi' => [

        /*
        | Midtrans returns these after credential exchange, once you have
        | registered your public key. None of them appear in the dashboard
        | beforehand, and none of them are needed unless you use Snap-BI.
        */
        'client_id' => env('MIDTRANS_SNAP_BI_CLIENT_ID'),
        'client_secret' => env('MIDTRANS_SNAP_BI_CLIENT_SECRET'),
        'partner_id' => env('MIDTRANS_SNAP_BI_PARTNER_ID'),
        'channel_id' => env('MIDTRANS_SNAP_BI_CHANNEL_ID', '95221'),
        'device_id' => env('MIDTRANS_SNAP_BI_DEVICE_ID'),

        /*
        | Your own private key, used to sign the access token request.
        |
        | Prefer the _PATH variant: a PEM is 1700-odd characters and putting it
        | inline turns .env into something nobody can read or diff. The path is
        | resolved at runtime, so a cached config stores the path rather than
        | the key. Inline stays available for platforms with no writable disk.
        */
        'private_key_path' => env('MIDTRANS_SNAP_BI_PRIVATE_KEY_PATH'),
        'private_key' => env('MIDTRANS_SNAP_BI_PRIVATE_KEY'),

        /*
        | Midtrans's public key, used to verify the notifications it sends you.
        | This is NOT the public key you registered with them.
        */
        'public_key_path' => env('MIDTRANS_SNAP_BI_PUBLIC_KEY_PATH'),
        'public_key' => env('MIDTRANS_SNAP_BI_PUBLIC_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhook
    |--------------------------------------------------------------------------
    */

    'webhook' => [

        /*
        | Registers POST {path} for you. Disable it if you would rather define
        | the route yourself and only reuse the middleware.
        */
        'enabled' => (bool) env('MIDTRANS_WEBHOOK_ENABLED', true),

        'path' => env('MIDTRANS_WEBHOOK_PATH', 'midtrans/webhook'),

        /*
        | Deliberately not the 'web' group: a webhook has no session and no CSRF
        | token, and putting it there is what produces the classic 419 response.
        */
        'middleware' => [],

        'route_name' => 'midtrans.webhook',

        /*
        | A valid signature proves the notification is authentic, not that it is
        | current — a genuine one can be replayed, and the payload can already be
        | stale by the time it arrives. Re-reading the status from the API before
        | dispatching events costs one call and is the behaviour Midtrans
        | documents. Turn it off only if you accept acting on the payload alone.
        */
        'verify_with_api' => (bool) env('MIDTRANS_WEBHOOK_VERIFY_WITH_API', true),

        /*
        | Midtrans retries a notification until it gets a 2xx, so the same status
        | can arrive several times. Events for a transaction/status pair already
        | seen within this window are skipped, using the cache store below.
        | Set ttl to 0 to dispatch every delivery.
        */
        'deduplicate' => [
            'ttl' => (int) env('MIDTRANS_WEBHOOK_DEDUPE_TTL', 300),
            'store' => env('MIDTRANS_WEBHOOK_DEDUPE_STORE'),
            'prefix' => 'midtrans:webhook:',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Snap-BI Webhook
    |--------------------------------------------------------------------------
    */

    'snap_bi_webhook' => [
        'enabled' => (bool) env('MIDTRANS_SNAP_BI_WEBHOOK_ENABLED', false),

        'path' => env('MIDTRANS_SNAP_BI_WEBHOOK_PATH', 'midtrans/snap-bi/webhook'),

        'middleware' => [],

        'route_name' => 'midtrans.snap-bi.webhook',

        /*
        | Tolerated X-TIMESTAMP drift in seconds. Bounds how long a captured
        | notification stays replayable. Set to null to disable the check.
        */
        'timestamp_tolerance' => (int) env('MIDTRANS_SNAP_BI_WEBHOOK_TOLERANCE', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    |
    | Logs rejected webhooks and dispatched events. Payloads are never logged in
    | full: they carry customer PII and, for card flows, masked card data.
    |
    */

    'logging' => [
        'enabled' => (bool) env('MIDTRANS_LOGGING', true),
        'channel' => env('MIDTRANS_LOG_CHANNEL'),
    ],
];
