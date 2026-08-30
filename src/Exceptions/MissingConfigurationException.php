<?php

declare(strict_types=1);

namespace Aliziodev\LaravelMidtrans\Exceptions;

use RuntimeException;

final class MissingConfigurationException extends RuntimeException
{
    public static function serverKey(): self
    {
        return new self(
            'MIDTRANS_SERVER_KEY is not set. Add it to your .env — the sandbox key starts '
            .'with SB-Mid-server- and is found under Settings > Access Keys in the Midtrans dashboard.'
        );
    }

    public static function snapBiPublicKey(): self
    {
        return new self(
            'MIDTRANS_SNAP_BI_PUBLIC_KEY is not set, so Snap-BI notifications cannot be verified. '
            .'Midtrans generates the keypair and shares the public key with you during onboarding.'
        );
    }
}
