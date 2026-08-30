<?php

declare(strict_types=1);

namespace Aliziodev\LaravelMidtrans\Testing;

use Aliziodev\MidtransPhp\Http\HttpResponse;
use Aliziodev\MidtransPhp\Http\Transport;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert as PHPUnit;

/**
 * Records requests instead of sending them, so application tests never reach
 * Midtrans.
 *
 * Install it with Midtrans::fake().
 */
final class RecordingTransport implements Transport
{
    /** @var list<RecordedRequest> */
    private array $recorded = [];

    /** @var list<HttpResponse> */
    private array $queue = [];

    /**
     * @param  array<string, array<string, mixed>|HttpResponse>  $responses  URL pattern => response
     */
    public function __construct(private array $responses = []) {}

    /**
     * Queues one response, consumed before any pattern match. Call it repeatedly
     * to script a sequence, which is how you exercise retries.
     *
     * @param  array<string, mixed>|string  $body
     */
    public function push(array|string $body, int $status = 200): self
    {
        $this->queue[] = new HttpResponse(
            $status,
            is_string($body) ? $body : (string) json_encode($body),
        );

        return $this;
    }

    public function request(
        string $method,
        string $url,
        array $headers,
        ?string $jsonBody,
        int $timeoutSeconds,
        int $maxRetries = 0,
        int $retryDelayMs = 0,
    ): HttpResponse {
        $this->recorded[] = new RecordedRequest($method, $url, $headers, $jsonBody, $maxRetries);

        if ($this->queue !== []) {
            return array_shift($this->queue);
        }

        foreach ($this->responses as $pattern => $response) {
            if (Str::is($pattern, $url)) {
                return $response instanceof HttpResponse
                    ? $response
                    : new HttpResponse(200, (string) json_encode($response));
            }
        }

        return new HttpResponse(200, '{}');
    }

    /**
     * @return list<RecordedRequest>
     */
    public function recorded(): array
    {
        return $this->recorded;
    }

    /**
     * @param  callable(RecordedRequest): bool  $callback
     */
    public function assertSent(callable $callback): void
    {
        PHPUnit::assertTrue(
            $this->matches($callback),
            'No Midtrans request matching the expectation was sent. Sent: '.$this->describe(),
        );
    }

    /**
     * @param  callable(RecordedRequest): bool  $callback
     */
    public function assertNotSent(callable $callback): void
    {
        PHPUnit::assertFalse(
            $this->matches($callback),
            'An unexpected Midtrans request was sent. Sent: '.$this->describe(),
        );
    }

    public function assertNothingSent(): void
    {
        PHPUnit::assertSame(
            [],
            $this->recorded,
            'Expected no Midtrans requests, but got: '.$this->describe(),
        );
    }

    public function assertSentCount(int $expected): void
    {
        PHPUnit::assertCount(
            $expected,
            $this->recorded,
            'Unexpected number of Midtrans requests. Sent: '.$this->describe(),
        );
    }

    /**
     * @param  callable(RecordedRequest): bool  $callback
     */
    private function matches(callable $callback): bool
    {
        foreach ($this->recorded as $request) {
            if ($callback($request)) {
                return true;
            }
        }

        return false;
    }

    private function describe(): string
    {
        if ($this->recorded === []) {
            return 'nothing';
        }

        return implode(', ', array_map(
            static fn (RecordedRequest $r): string => $r->method.' '.$r->url,
            $this->recorded,
        ));
    }
}
