<?php

declare(strict_types=1);

use Aliziodev\LaravelMidtrans\Http\Controllers\SnapBiWebhookController;
use Aliziodev\LaravelMidtrans\Http\Controllers\WebhookController;
use Aliziodev\LaravelMidtrans\Http\Middleware\VerifyMidtransSignature;
use Aliziodev\LaravelMidtrans\Http\Middleware\VerifySnapBiSignature;
use Illuminate\Support\Facades\Route;

/*
| Deliberately outside the 'web' middleware group. A webhook carries no session
| and no CSRF token, and routing it through 'web' is what produces the classic
| 419 that looks like Midtrans never called you.
*/

if (config('midtrans.webhook.enabled', true)) {
    Route::post(config('midtrans.webhook.path', 'midtrans/webhook'), WebhookController::class)
        ->middleware(array_merge(
            [VerifyMidtransSignature::class],
            (array) config('midtrans.webhook.middleware', []),
        ))
        ->name((string) config('midtrans.webhook.route_name', 'midtrans.webhook'));
}

if (config('midtrans.snap_bi_webhook.enabled', false)) {
    Route::post(config('midtrans.snap_bi_webhook.path', 'midtrans/snap-bi/webhook'), SnapBiWebhookController::class)
        ->middleware(array_merge(
            [VerifySnapBiSignature::class],
            (array) config('midtrans.snap_bi_webhook.middleware', []),
        ))
        ->name((string) config('midtrans.snap_bi_webhook.route_name', 'midtrans.snap-bi.webhook'));
}
