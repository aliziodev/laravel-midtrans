<?php

declare(strict_types=1);

namespace Aliziodev\LaravelMidtrans\Testing;

use Illuminate\Support\Str;

/**
 * One request captured by {@see RecordingTransport}.
 */
final class RecordedRequest
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly array $headers,
        public readonly ?string $body,
        public readonly int $maxRetries,
    ) {}

    public function urlIs(string $pattern): bool
    {
        return Str::is($pattern, $this->url);
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    /**
     * The JSON body decoded, or an empty array when the request carried none.
     *
     * @return array<string, mixed>
     */
    public function data(): array
    {
        if ($this->body === null) {
            return [];
        }

        $decoded = json_decode($this->body, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return data_get($this->data(), $key, $default);
    }
}
