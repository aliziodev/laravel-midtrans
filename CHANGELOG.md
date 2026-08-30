## [1.0.0](https://github.com/aliziodev/laravel-midtrans/compare/v0.2.0...v1.0.0) (2026-08-30)

### ⚠ BREAKING CHANGES

* the package leaves 0.x. Nothing in the code changes,
but the version number stops saying "this may break at any time" — which
was the wrong message for something already exercised against the real
Midtrans sandbox and depended on in production.

A 0.x number is not cosmetic. Composer treats ^0.1 as pinned to 0.1.x,
so users get no minor updates at all, and cautious teams read 0.x as
"not ready" and skip the package regardless of what it contains.

The README now states what the version promises: facade signatures,
event names and payloads, config keys, route names, middleware aliases,
and the notification objects. It also states what 1.0.0 does not promise
— that every Midtrans endpoint has been proven live. Sixteen still have
not, and that stays documented rather than being quietly absorbed into a
confident version number.

### Features

* commit to a stable API ([15e26e5](https://github.com/aliziodev/laravel-midtrans/commit/15e26e58645cd89b79e02a6fa4c266e73653cf95))

## [0.2.0](https://github.com/aliziodev/laravel-midtrans/compare/v0.1.0...v0.2.0) (2026-08-30)

### Features

* expose the base URL overrides and the insecure-URL escape hatch ([f9e46f6](https://github.com/aliziodev/laravel-midtrans/commit/f9e46f6f5a0ccdf134321c5e3804c283a0c15820))

## [0.1.0](https://github.com/aliziodev/laravel-midtrans/compare/v0.0.0...v0.1.0) (2026-08-30)

### Features

* read Snap-BI keys from a file, and document that Snap-BI is optional ([8d811ce](https://github.com/aliziodev/laravel-midtrans/commit/8d811ceac4d51da17a2264a0a7e541ad3421868f))
* ship a Boost skill, and make sandbox webhook testing possible ([5c74687](https://github.com/aliziodev/laravel-midtrans/commit/5c746875535e17d285bb4487b4b9005e80a59584))

### Bug Fixes

* resolve Snap-BI keys lazily, and prove the webhook end to end ([1bc7626](https://github.com/aliziodev/laravel-midtrans/commit/1bc762651dea7965e18b203f7ed93edc6f831065))
