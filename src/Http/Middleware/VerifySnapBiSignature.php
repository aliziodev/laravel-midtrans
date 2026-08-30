<?php

declare(strict_types=1);

namespace Aliziodev\LaravelMidtrans\Http\Middleware;

use Aliziodev\LaravelMidtrans\Exceptions\InvalidSignatureException;
use Aliziodev\LaravelMidtrans\Support\KeyResolver;
use Aliziodev\MidtransPhp\Webhooks\SnapBiWebhookVerifier;
use Closure;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies a Snap-BI notification with the public key Midtrans issued you.
 *
 * The signed string covers the HTTP method, the notification path, a SHA-256 of
 * the raw body and the X-TIMESTAMP header, so all four have to be read exactly
 * as they arrived.
 */
final class VerifySnapBiSignature
{
    public function __construct(
        private readonly Config $config,
        private readonly LoggerInterface $logger,
        private readonly KeyResolver $keys,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $rawBody = $request->getContent();

        if (! is_string($rawBody) || trim($rawBody) === '') {
            $this->reject($request, 'empty request body');
        }

        $publicKey = (string) $this->keys->publicKey();

        if ($publicKey === '') {
            $this->reject($request, 'midtrans.snap_bi.public_key is not configured');
        }

        $tolerance = $this->config->get('midtrans.snap_bi_webhook.timestamp_tolerance', 300);

        $verified = SnapBiWebhookVerifier::verify(
            rawBody: $rawBody,
            signature: (string) $request->header('X-SIGNATURE'),
            timestamp: (string) $request->header('X-TIMESTAMP'),
            // The path Midtrans signed is the one it was told to call, which is
            // the request path with its leading slash.
            notificationUrlPath: '/'.ltrim($request->path(), '/'),
            publicKey: $publicKey,
            httpMethod: $request->method(),
            toleranceSeconds: $tolerance === null ? null : (int) $tolerance,
        );

        if (! $verified) {
            $this->reject($request, 'X-SIGNATURE did not verify against the configured public key');
        }

        return $next($request);
    }

    /**
     * @throws InvalidSignatureException
     */
    private function reject(Request $request, string $reason): never
    {
        if ($this->config->get('midtrans.logging.enabled', true)) {
            $this->logger->warning('Snap-BI webhook rejected', [
                'reason' => $reason,
                'ip' => $request->ip(),
                'path' => $request->path(),
            ]);
        }

        throw InvalidSignatureException::forNotification($reason);
    }
}
