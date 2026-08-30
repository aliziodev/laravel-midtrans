<?php

declare(strict_types=1);

namespace Aliziodev\LaravelMidtrans\Http\Middleware;

use Aliziodev\LaravelMidtrans\Exceptions\InvalidSignatureException;
use Aliziodev\MidtransPhp\Webhooks\MidtransSignatureVerifier;
use Closure;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects any notification that is not signed with your server key.
 *
 * Verification runs against the raw request body. Re-encoding a decoded array
 * would not reproduce the bytes Midtrans signed, and reading gross_amount
 * through a cast turns "10000.00" into "10000" — both make a genuine
 * notification fail to verify.
 */
final class VerifyMidtransSignature
{
    public function __construct(
        private readonly Config $config,
        private readonly LoggerInterface $logger,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $rawBody = $request->getContent();

        if (! is_string($rawBody) || trim($rawBody) === '') {
            $this->reject($request, 'empty request body');
        }

        $serverKey = (string) $this->config->get('midtrans.server_key');

        if ($serverKey === '') {
            // Refuse rather than accept everything: an unset key must not turn
            // the endpoint into one that trusts any caller.
            $this->reject($request, 'midtrans.server_key is not configured');
        }

        if (! MidtransSignatureVerifier::verifyRaw($rawBody, $serverKey)) {
            $this->reject($request, 'signature_key did not match');
        }

        return $next($request);
    }

    /**
     * @throws InvalidSignatureException
     */
    private function reject(Request $request, string $reason): never
    {
        if ($this->config->get('midtrans.logging.enabled', true)) {
            $this->logger->warning('Midtrans webhook rejected', [
                'reason' => $reason,
                'ip' => $request->ip(),
                'path' => $request->path(),
            ]);
        }

        throw InvalidSignatureException::forNotification($reason);
    }
}
