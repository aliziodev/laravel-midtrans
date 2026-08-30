<?php

declare(strict_types=1);

namespace Aliziodev\LaravelMidtrans\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Answers 403 rather than 401: there is nothing for the caller to retry with,
 * and Midtrans stops redelivering once it stops receiving a 2xx anyway.
 *
 * The reason is deliberately vague to the caller and detailed only in the log —
 * an attacker probing the endpoint should not learn which check rejected them.
 */
final class InvalidSignatureException extends HttpException
{
    public static function forNotification(string $reason): self
    {
        return new self(403, 'Invalid Midtrans signature.', null, [], 0, $reason);
    }

    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        int $statusCode,
        string $message,
        ?\Throwable $previous = null,
        array $headers = [],
        int $code = 0,
        public readonly string $reason = '',
    ) {
        parent::__construct($statusCode, $message, $previous, $headers, $code);
    }
}
