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

    public static function unreadableKeyFile(string $key, string $path): self
    {
        return new self(sprintf(
            'midtrans.snap_bi.%s_path points at [%s], which does not exist or cannot be read. '
            .'Give a path relative to the application root, or set the PEM inline instead.',
            $key,
            $path,
        ));
    }
}
