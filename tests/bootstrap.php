<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

/*
| Loads .env into the environment for the sandbox suite.
|
| Hand-rolled rather than pulling in vlucas/phpdotenv: this reads a handful of
| KEY=value lines for tests only, and a package should not gain a dependency for
| that. Values already present in the real environment win, so CI secrets are
| never overwritten by a stray local file.
*/
$envFile = __DIR__.'/../.env';

if (! is_file($envFile)) {
    return;
}

foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    $line = trim($line);

    if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
        continue;
    }

    [$key, $value] = explode('=', $line, 2);
    $key = trim($key);
    $value = trim($value);

    // Strip one layer of matching quotes, then turn the two-character sequence
    // backslash-n into a real newline for double-quoted values. That is what
    // lets a PEM private key sit on one line, as Laravel's own .env does.
    if (strlen($value) > 1 && $value[0] === $value[-1] && in_array($value[0], ['"', "'"], true)) {
        $quote = $value[0];
        $value = substr($value, 1, -1);

        if ($quote === '"') {
            $value = str_replace(['\n', '\r'], ["\n", "\r"], $value);
        }
    }

    if ($key === '' || getenv($key) !== false) {
        continue;
    }

    putenv($key.'='.$value);
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}
