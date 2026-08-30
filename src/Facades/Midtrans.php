<?php

declare(strict_types=1);

namespace Aliziodev\LaravelMidtrans\Facades;

use Aliziodev\LaravelMidtrans\MidtransServiceProvider;
use Aliziodev\LaravelMidtrans\Testing\RecordedRequest;
use Aliziodev\LaravelMidtrans\Testing\RecordingTransport;
use Aliziodev\LaravelMidtrans\Webhook\WebhookHandler;
use Aliziodev\MidtransPhp\Http\Transport;
use Aliziodev\MidtransPhp\MidtransClient;
use Aliziodev\MidtransPhp\SnapBi\SnapBiClient;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Assert as PHPUnit;

/**
 * Core API and Snap.
 *
 * @method static array<string, mixed> createSnapTransaction(array<string, mixed> $payload)
 * @method static array<string, mixed> chargeTransaction(array<string, mixed> $payload)
 * @method static array<string, mixed> captureTransaction(array<string, mixed> $payload)
 * @method static array<string, mixed> getTransactionStatusB2b(string $orderOrTransactionId)
 * @method static array<string, mixed> getTransactionStatus(string $orderOrTransactionId)
 * @method static array<string, mixed> approveTransaction(string $orderOrTransactionId)
 * @method static array<string, mixed> denyTransaction(string $orderOrTransactionId)
 * @method static array<string, mixed> cancelTransaction(string $orderOrTransactionId)
 * @method static array<string, mixed> expireTransaction(string $orderOrTransactionId)
 * @method static array<string, mixed> refundTransaction(string $orderOrTransactionId, array<string, mixed> $payload)
 * @method static array<string, mixed> refundTransactionDirect(string $orderOrTransactionId, array<string, mixed> $payload)
 * @method static array<string, mixed> linkPaymentAccount(array<string, mixed> $payload)
 * @method static array<string, mixed> getPaymentAccount(string $accountId)
 * @method static array<string, mixed> unlinkPaymentAccount(string $accountId)
 * @method static array<string, mixed> createSubscription(array<string, mixed> $payload)
 * @method static array<string, mixed> getSubscription(string $subscriptionId)
 * @method static array<string, mixed> updateSubscription(string $subscriptionId, array<string, mixed> $payload)
 * @method static array<string, mixed> disableSubscription(string $subscriptionId)
 * @method static array<string, mixed> enableSubscription(string $subscriptionId)
 * @method static array<string, mixed> cancelSubscription(string $subscriptionId)
 * @method static string getSnapToken(array<string, mixed> $payload)
 * @method static string getSnapUrl(array<string, mixed> $payload)
 * @method static array<string, mixed> registerCard(string $cardNumber, string $expMonth, string $expYear)
 * @method static array<string, mixed> getCardToken(string $cardNumber, string $expMonth, string $expYear, string $cvv)
 * @method static array<string, mixed> getCardPointInquiry(string $tokenId)
 * @method static array<string, mixed> createPaymentLink(array<string, mixed> $payload)
 * @method static array<string, mixed> getPaymentLinkDetails(string $orderId)
 * @method static array<string, mixed> deletePaymentLink(string $orderId)
 * @method static array<string, mixed> getBalanceMutation(string $currency, string $startTime, string $endTime)
 * @method static array<string, mixed> createInvoice(array<string, mixed> $payload)
 * @method static array<string, mixed> getInvoice(string $invoiceId)
 * @method static array<string, mixed> voidInvoice(string $invoiceId)
 * @method static array<string, mixed> getBin(string $binNumber)
 * @method static array<string, mixed> convertInvoice(string $invoiceId, array<string, mixed> $payload = [])
 * @method static array<string, mixed> cancelSnapSession(string $snapToken)
 * @method static array<string, mixed> getSnapPreferences()
 * @method static array<string, mixed> updateSnapPreferences(array<string, mixed> $payload)
 * @method static array<string, mixed> getGopayPromotions(?string $accountId, int|string $grossAmount, string $currency = 'IDR')
 * @method static \Aliziodev\MidtransPhp\MidtransClient withIdempotencyKey(string $idempotencyKey)
 * @method static \Aliziodev\MidtransPhp\MidtransClient withHeaders(array<string, mixed> $headers)
 * @method static void fake(array<string, mixed> $responses = [])
 * @method static void assertSent(callable $callback)
 * @method static void assertNothingSent()
 *
 * @see MidtransClient
 */
final class Midtrans extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return MidtransServiceProvider::CLIENT;
    }

    /**
     * Records requests instead of sending them, so tests never reach Midtrans.
     *
     * Both clients share one transport, so this fakes Snap-BI as well.
     *
     * @param  array<string, array<string, mixed>>  $responses  URL pattern => JSON body
     */
    public static function fake(array $responses = []): RecordingTransport
    {
        $transport = new RecordingTransport($responses);
        $app = self::getFacadeApplication();

        $app->instance(Transport::class, $transport);

        foreach ([
            MidtransClient::class,
            SnapBiClient::class,
            MidtransServiceProvider::CLIENT,
            MidtransServiceProvider::SNAP_BI_CLIENT,
            // Holds a client of its own, so it has to be rebuilt too.
            WebhookHandler::class,
        ] as $abstract) {
            $app->forgetInstance($abstract);
        }

        self::clearResolvedInstance(MidtransServiceProvider::CLIENT);
        self::clearResolvedInstance(MidtransServiceProvider::SNAP_BI_CLIENT);

        return $transport;
    }

    /**
     * @param  callable(RecordedRequest): bool  $callback
     */
    public static function assertSent(callable $callback): void
    {
        self::recordingTransport()->assertSent($callback);
    }

    /**
     * @param  callable(RecordedRequest): bool  $callback
     */
    public static function assertNotSent(callable $callback): void
    {
        self::recordingTransport()->assertNotSent($callback);
    }

    public static function assertNothingSent(): void
    {
        self::recordingTransport()->assertNothingSent();
    }

    public static function assertSentCount(int $expected): void
    {
        self::recordingTransport()->assertSentCount($expected);
    }

    private static function recordingTransport(): RecordingTransport
    {
        $transport = self::getFacadeApplication()->make(Transport::class);

        PHPUnit::assertInstanceOf(
            RecordingTransport::class,
            $transport,
            'Midtrans is not faked. Call Midtrans::fake() before asserting on requests.',
        );

        return $transport;
    }
}
