<?php

declare(strict_types=1);

/*
| Serves the package as a real app for sandbox webhook testing.
|
|   composer sandbox:serve
|
| Why this exists rather than plain `vendor/bin/testbench serve`:
|
| Testbench boots from its own skeleton, so Laravel reads .env from
| vendor/orchestra/testbench-core/laravel, not from the package root. Passing
| the values as shell environment variables does not help either, because PHP's
| default variables_order of "GPCS" leaves $_ENV empty. The result is a served
| app with no server key, which rejects every notification with a 403 that looks
| exactly like a signature failure.
|
| So: copy the values the app needs into the skeleton, then hand over to
| testbench. The skeleton lives under vendor/, so nothing here is committed.
*/

$packageRoot = dirname(__DIR__);
$skeleton = $packageRoot.'/vendor/orchestra/testbench-core/laravel';

if (! is_dir($skeleton)) {
    fwrite(STDERR, "Testbench skeleton not found. Run composer install first.\n");
    exit(1);
}

if (! is_file($packageRoot.'/.env')) {
    fwrite(STDERR, "No .env found. Copy .env.example to .env and fill in your sandbox key.\n");
    exit(1);
}

require $packageRoot.'/tests/bootstrap.php';

$serverKey = (string) getenv('MIDTRANS_SANDBOX_SERVER_KEY');

if ($serverKey === '' || $serverKey === 'SB-Mid-server-') {
    fwrite(STDERR, "MIDTRANS_SANDBOX_SERVER_KEY is not set in .env.\n");
    exit(1);
}

if (! str_starts_with($serverKey, 'SB-Mid-server-')) {
    fwrite(STDERR, "Refusing to serve: MIDTRANS_SANDBOX_SERVER_KEY is not a sandbox key.\n");
    exit(1);
}

$override = (string) getenv('MIDTRANS_OVERRIDE_NOTIFICATION_URL');

$env = [
    // Fixed local key: this app only ever serves sandbox webhook tests.
    'APP_KEY' => 'base64:Q1hDMUlEQ0FQVFVSRVRIRUZMQUdIRVJFMTIzNDU2Nzg=',
    'APP_DEBUG' => 'true',
    'LOG_CHANNEL' => 'single',
    'CACHE_STORE' => 'file',
    'MIDTRANS_SERVER_KEY' => $serverKey,
    'MIDTRANS_CLIENT_KEY' => (string) getenv('MIDTRANS_SANDBOX_CLIENT_KEY'),
    'MIDTRANS_IS_PRODUCTION' => 'false',
    'MIDTRANS_OVERRIDE_NOTIFICATION_URL' => $override,
];

$lines = [];
foreach ($env as $key => $value) {
    $lines[] = $key.'='.$value;
}

file_put_contents($skeleton.'/.env', implode("\n", $lines)."\n");

$log = $skeleton.'/storage/logs/laravel.log';
@file_put_contents($log, '');

echo "Serving on http://127.0.0.1:8000\n";
echo "  webhook : http://127.0.0.1:8000/midtrans/webhook\n";
echo '  log     : '.$log."\n";
echo $override === ''
    ? "\n  MIDTRANS_OVERRIDE_NOTIFICATION_URL is empty. Start a tunnel and set it,\n"
        ."  or Midtrans will notify whatever the dashboard has instead.\n\n"
    : "  notify  : {$override}\n\n";

$testbench = $packageRoot.'/vendor/bin/testbench';

passthru(
    escapeshellarg(PHP_BINARY).' '.escapeshellarg($testbench).' serve --host=127.0.0.1 --port=8000',
    $exitCode,
);

exit($exitCode);
