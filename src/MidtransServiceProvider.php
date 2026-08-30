<?php

declare(strict_types=1);

namespace Aliziodev\LaravelMidtrans;

use Aliziodev\LaravelMidtrans\Console\Commands\TransactionStatusCommand;
use Aliziodev\LaravelMidtrans\Exceptions\MissingConfigurationException;
use Aliziodev\LaravelMidtrans\Http\Middleware\VerifyMidtransSignature;
use Aliziodev\LaravelMidtrans\Http\Middleware\VerifySnapBiSignature;
use Aliziodev\LaravelMidtrans\Support\KeyResolver;
use Aliziodev\LaravelMidtrans\Webhook\WebhookHandler;
use Aliziodev\MidtransPhp\Config\MidtransConfig;
use Aliziodev\MidtransPhp\Http\CurlTransport;
use Aliziodev\MidtransPhp\Http\Transport;
use Aliziodev\MidtransPhp\MidtransClient;
use Aliziodev\MidtransPhp\SnapBi\SnapBiClient;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;

final class MidtransServiceProvider extends ServiceProvider
{
    /**
     * Container key for the Core API client, used by the Midtrans facade.
     */
    public const CLIENT = 'midtrans';

    /**
     * Container key for the Snap-BI client, used by the SnapBi facade.
     */
    public const SNAP_BI_CLIENT = 'midtrans.snap-bi';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/midtrans.php', 'midtrans');

        $this->app->singleton(KeyResolver::class);

        $this->app->singleton(MidtransConfig::class, fn (Application $app): MidtransConfig => $this->buildConfig($app));

        // Bound separately so Midtrans::fake() can swap the transport out from
        // under both clients without rebuilding either of them.
        $this->app->singleton(Transport::class, fn (): Transport => new CurlTransport);

        $this->app->singleton(MidtransClient::class, fn (Application $app): MidtransClient => new MidtransClient(
            config: $app->make(MidtransConfig::class),
            transport: $app->make(Transport::class),
        ));

        $this->app->singleton(SnapBiClient::class, fn (Application $app): SnapBiClient => new SnapBiClient(
            config: $app->make(MidtransConfig::class),
            transport: $app->make(Transport::class),
        ));

        $this->app->alias(MidtransClient::class, self::CLIENT);
        $this->app->alias(SnapBiClient::class, self::SNAP_BI_CLIENT);

        $this->app->singleton(WebhookHandler::class);

        $this->registerLogger();
    }

    public function boot(): void
    {
        $this->registerPublishables();
        $this->registerRoutes();
        $this->registerMiddlewareAliases();
        $this->registerCommands();
    }

    private function buildConfig(Application $app): MidtransConfig
    {
        /** @var array<string, mixed> $midtrans */
        $midtrans = $app['config']->get('midtrans', []);

        $serverKey = (string) ($midtrans['server_key'] ?? '');

        if ($serverKey === '') {
            throw MissingConfigurationException::serverKey();
        }

        /** @var array<string, mixed> $snapBi */
        $snapBi = $midtrans['snap_bi'] ?? [];

        return new MidtransConfig(
            serverKey: $serverKey,
            clientKey: $this->nullableString($midtrans['client_key'] ?? null),
            isProduction: (bool) ($midtrans['is_production'] ?? false),
            timeoutSeconds: (int) ($midtrans['timeout'] ?? 30),
            maxRetries: (int) ($midtrans['max_retries'] ?? 2),
            retryDelayMs: (int) ($midtrans['retry_delay_ms'] ?? 200),
            idempotencyKeyPrefix: (string) ($midtrans['idempotency_key_prefix'] ?? 'midtrans'),
            snapBiClientId: $this->nullableString($snapBi['client_id'] ?? null),
            snapBiPrivateKey: $app->make(KeyResolver::class)->privateKey(),
            snapBiClientSecret: $this->nullableString($snapBi['client_secret'] ?? null),
            snapBiPartnerId: $this->nullableString($snapBi['partner_id'] ?? null),
            snapBiChannelId: (string) ($snapBi['channel_id'] ?? '95221'),
            snapBiDeviceId: $this->nullableString($snapBi['device_id'] ?? null),
            appendNotificationUrl: $this->nullableString($midtrans['append_notification_url'] ?? null),
            overrideNotificationUrl: $this->nullableString($midtrans['override_notification_url'] ?? null),
            paymentLocale: $this->nullableString($midtrans['payment_locale'] ?? null),
            popId: $this->nullableString($midtrans['pop_id'] ?? null),
        );
    }

    /**
     * Routes the package's own logging through the configured channel, without
     * touching what the rest of the application resolves for LoggerInterface.
     */
    private function registerLogger(): void
    {
        $this->app->when([
            WebhookHandler::class,
            VerifyMidtransSignature::class,
            VerifySnapBiSignature::class,
        ])
            ->needs(LoggerInterface::class)
            ->give(fn (Application $app): LoggerInterface => $app['log']->channel(
                $app['config']->get('midtrans.logging.channel'),
            ));
    }

    private function registerPublishables(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/midtrans.php' => $this->app->configPath('midtrans.php'),
        ], ['midtrans', 'midtrans-config']);
    }

    private function registerRoutes(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/webhook.php');
    }

    /**
     * Aliases so the middleware can be attached to a hand-written route when the
     * bundled ones are switched off.
     */
    private function registerMiddlewareAliases(): void
    {
        $router = $this->app['router'];

        $router->aliasMiddleware('midtrans.signature', VerifyMidtransSignature::class);
        $router->aliasMiddleware('midtrans.snap-bi.signature', VerifySnapBiSignature::class);
    }

    private function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([TransactionStatusCommand::class]);
        }
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = (string) $value;

        return $value === '' ? null : $value;
    }
}
