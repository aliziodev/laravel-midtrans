<?php

declare(strict_types=1);

namespace Aliziodev\LaravelMidtrans\Support;

use Aliziodev\LaravelMidtrans\Exceptions\MissingConfigurationException;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;

/**
 * Reads the Snap-BI PEM keys, from a file when a path is configured.
 *
 * A PEM is around 1700 characters, so inline in .env it is one unreadable,
 * undiffable line. Reading is deliberately lazy: an application that does not
 * use Snap-BI never touches the disk, and a service provider should not be
 * doing file I/O while registering.
 */
final class KeyResolver
{
    public function __construct(
        private readonly Config $config,
        private readonly Application $app,
    ) {}

    /**
     * Your own key, used to sign the Snap-BI access token request.
     */
    public function privateKey(): ?string
    {
        return $this->resolve('private_key');
    }

    /**
     * Midtrans's key, used to verify the notifications they send you. This is
     * not the public key you registered with them.
     */
    public function publicKey(): ?string
    {
        return $this->resolve('public_key');
    }

    private function resolve(string $key): ?string
    {
        $path = $this->trimmed($this->config->get("midtrans.snap_bi.{$key}_path"));

        if ($path === null) {
            return $this->trimmed($this->config->get("midtrans.snap_bi.{$key}"));
        }

        $resolved = $this->absolute($path);

        if (! is_file($resolved) || ! is_readable($resolved)) {
            throw MissingConfigurationException::unreadableKeyFile($key, $resolved);
        }

        return $this->trimmed(file_get_contents($resolved));
    }

    /**
     * Relative paths are taken from the application root, so a value like
     * secrets/snapbi-private.pem behaves the same however the app was booted.
     */
    private function absolute(string $path): string
    {
        $isAbsolute = str_starts_with($path, '/')
            || str_starts_with($path, DIRECTORY_SEPARATOR)
            || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1;

        return $isAbsolute ? $path : $this->app->basePath($path);
    }

    private function trimmed(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
