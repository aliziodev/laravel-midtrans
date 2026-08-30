<?php

declare(strict_types=1);

namespace Aliziodev\LaravelMidtrans\Facades;

use Aliziodev\LaravelMidtrans\MidtransServiceProvider;
use Aliziodev\MidtransPhp\SnapBi\SnapBiClient;
use Illuminate\Support\Facades\Facade;

/**
 * BI-SNAP Core API: direct debit, virtual account, QRIS and account linking.
 *
 * Shares a transport with the Midtrans facade, so Midtrans::fake() fakes this too.
 *
 * @method static array<string, mixed> getAccessToken()
 * @method static array<string, mixed> createDirectDebit(array<string, mixed> $payload, string $externalId, ?string $accessToken = null)
 * @method static array<string, mixed> createVa(array<string, mixed> $payload, string $externalId, ?string $accessToken = null)
 * @method static array<string, mixed> createQris(array<string, mixed> $payload, string $externalId, ?string $accessToken = null)
 * @method static array<string, mixed> getDirectDebitStatus(array<string, mixed> $payload, string $externalId, ?string $accessToken = null)
 * @method static array<string, mixed> getVaStatus(array<string, mixed> $payload, string $externalId, ?string $accessToken = null)
 * @method static array<string, mixed> getQrisStatus(array<string, mixed> $payload, string $externalId, ?string $accessToken = null)
 * @method static array<string, mixed> cancelDirectDebit(array<string, mixed> $payload, string $externalId, ?string $accessToken = null)
 * @method static array<string, mixed> cancelVa(array<string, mixed> $payload, string $externalId, ?string $accessToken = null)
 * @method static array<string, mixed> cancelQris(array<string, mixed> $payload, string $externalId, ?string $accessToken = null)
 * @method static array<string, mixed> refundDirectDebit(array<string, mixed> $payload, string $externalId, ?string $accessToken = null)
 * @method static array<string, mixed> refundQris(array<string, mixed> $payload, string $externalId, ?string $accessToken = null)
 * @method static array<string, mixed> bindAccount(array<string, mixed> $payload, string $externalId, ?string $accessToken = null)
 * @method static array<string, mixed> unbindAccount(array<string, mixed> $payload, string $externalId, ?string $accessToken = null)
 * @method static array<string, mixed> getAccountBindingStatus(array<string, mixed> $payload, string $externalId, ?string $accessToken = null)
 * @method static array<string, mixed> captureAuthorization(array<string, mixed> $payload, string $externalId, ?string $accessToken = null)
 * @method static array<string, mixed> voidAuthorization(array<string, mixed> $payload, string $externalId, ?string $accessToken = null)
 * @method static array<string, mixed> getTransactionHistoryList(array<string, mixed> $payload, string $externalId, ?string $accessToken = null)
 * @method static array<string, mixed> getTransactionHistoryDetail(array<string, mixed> $payload, string $externalId, ?string $accessToken = null)
 * @method static void clearAccessTokenCache()
 *
 * @see SnapBiClient
 */
final class SnapBi extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return MidtransServiceProvider::SNAP_BI_CLIENT;
    }
}
