# Laravel Midtrans

[![Tests](https://github.com/aliziodev/laravel-midtrans/actions/workflows/tests.yml/badge.svg)](https://github.com/aliziodev/laravel-midtrans/actions/workflows/tests.yml)
[![Latest Stable Version](https://img.shields.io/packagist/v/aliziodev/laravel-midtrans.svg)](https://packagist.org/packages/aliziodev/laravel-midtrans)
[![codecov](https://codecov.io/gh/aliziodev/laravel-midtrans/graph/badge.svg)](https://codecov.io/gh/aliziodev/laravel-midtrans)
[![Total Downloads](https://img.shields.io/packagist/dt/aliziodev/laravel-midtrans)](https://packagist.org/packages/aliziodev/laravel-midtrans)
[![PHP Version](https://img.shields.io/packagist/php-v/aliziodev/laravel-midtrans)](https://packagist.org/packages/aliziodev/laravel-midtrans)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)

Integrasi Laravel untuk [aliziodev/midtrans-php](https://github.com/aliziodev/midtrans-php).

Package ini tidak membungkus ulang API Midtrans — SDK inti sudah melakukannya. Yang
ditambahkan di sini adalah bagian yang selalu ditulis ulang di setiap project Laravel,
dan yang paling sering salah: **penanganan webhook**.

- Kredensial dari `.env`, klien terikat di container, dua facade
- Route webhook + middleware verifikasi signature yang siap pakai
- Event bertipe per status transaksi, dibangun dari status yang **dibaca ulang ke API**
- Deduplikasi pengiriman ulang webhook
- `Midtrans::fake()` supaya test aplikasi tidak menyentuh jaringan

Butuh **PHP 8.3+** dan **Laravel 12 atau 13**.

## Instalasi

```bash
composer require aliziodev/laravel-midtrans
php artisan vendor:publish --tag=midtrans-config
```

```dotenv
MIDTRANS_SERVER_KEY=SB-Mid-server-xxxx
MIDTRANS_CLIENT_KEY=SB-Mid-client-xxxx
MIDTRANS_IS_PRODUCTION=false
```

Sandbox adalah default. Naik ke production harus jadi tindakan sadar, bukan efek
samping dari env yang lupa diisi.

## Memanggil API

```php
use Aliziodev\LaravelMidtrans\Facades\Midtrans;

$snap = Midtrans::createSnapTransaction([
    'transaction_details' => ['order_id' => 'ORDER-1001', 'gross_amount' => 150000],
]);

return view('checkout', ['snapToken' => $snap['token']]);
```

Seluruh method `MidtransClient` dan `SnapBiClient` tersedia lewat facade
`Midtrans` dan `SnapBi`, lengkap dengan autocomplete. Butuh instance-nya
langsung? Inject saja:

```php
public function __construct(private readonly \Aliziodev\MidtransPhp\MidtransClient $midtrans) {}
```

## Webhook

Arahkan **Notification URL** di dashboard Midtrans ke:

```
https://app-anda.test/midtrans/webhook
```

Route-nya sudah terdaftar sendiri. Sengaja **di luar** middleware group `web` —
webhook tidak punya session maupun token CSRF, dan menaruhnya di `web` persis
yang menghasilkan 419 yang terlihat seperti Midtrans tidak pernah memanggil.

Lalu dengarkan event-nya:

```php
// app/Providers/AppServiceProvider.php
use Aliziodev\LaravelMidtrans\Events\PaymentSettled;
use Aliziodev\LaravelMidtrans\Events\PaymentExpired;

Event::listen(PaymentSettled::class, function (PaymentSettled $event) {
    Order::where('order_id', $event->orderId())->first()?->markAsPaid();
});

Event::listen(PaymentExpired::class, function (PaymentExpired $event) {
    Order::where('order_id', $event->orderId())->first()?->releaseStock();
});
```

### Yang dikerjakan package sebelum event dikirim

1. **Verifikasi signature terhadap body mentah.** Signature dihitung dari
   `sha512(order_id + status_code + gross_amount + server_key)`, dan
   `gross_amount` harus persis string yang dikirim Midtrans (`"10000.00"`).
   Membacanya lewat cast mengubahnya jadi `"10000"` dan verifikasi gagal.
2. **Membaca ulang status ke API.** Signature valid membuktikan notifikasi
   *asli*, bukan *terkini* — notifikasi asli bisa di-replay, dan payload bisa
   sudah basi saat tiba. Event dibangun dari `getTransactionStatus()`, bukan
   dari body. Biayanya satu panggilan API; matikan dengan
   `MIDTRANS_WEBHOOK_VERIFY_WITH_API=false` kalau Anda menerima risikonya.
3. **Deduplikasi.** Midtrans mengirim ulang sampai dapat 2xx, jadi status yang
   sama bisa tiba beberapa kali. Pasangan transaksi/status yang sudah terlihat
   dalam 5 menit terakhir dilewati.

### Daftar event

| Event | Kapan |
|---|---|
| `PaymentSettled` | `settlement`, atau `capture` dengan `fraud_status=accept`. **Hanya ini** yang boleh memicu fulfillment |
| `PaymentPending` | Instruksi bayar sudah terbit, belum dibayar |
| `PaymentAuthorized` | Pre-auth kartu berhasil, dana ditahan, belum ditarik |
| `PaymentChallenged` | Ditandai fraud detection, menunggu review manual |
| `PaymentDenied` | Ditolak issuer atau fraud detection |
| `PaymentCancelled` | Dibatalkan sebelum settlement |
| `PaymentExpired` | Jendela pembayaran tutup tanpa pembayaran |
| `PaymentFailed` | Gagal di Midtrans atau di penyedia pembayaran |
| `PaymentRefunded` | Refund penuh atau sebagian diterima |
| `PaymentChargedBack` | Dana ditarik balik lewat sengketa |
| `WebhookReceived` | Setiap notifikasi yang lolos verifikasi, membawa body mentah — untuk audit |

Listener yang melempar exception akan menghasilkan 500, dan Midtrans akan
mengirim ulang. Itu memang perilaku yang diinginkan.

### Membuat route sendiri

Matikan yang bawaan, lalu pakai middleware-nya:

```dotenv
MIDTRANS_WEBHOOK_ENABLED=false
```

```php
Route::post('/hooks/midtrans', PaymentWebhookController::class)
    ->middleware('midtrans.signature');
```

## Notifikasi

`Notification` yang dibawa event membaca payload dengan tipe yang jelas:

```php
$event->notification->orderId();
$event->notification->grossAmount();      // "10071" — string, apa adanya
$event->notification->originalAmount();   // "10000" sebelum Automatic Fee Imposition
$event->notification->customerImposedFee();
$event->notification->isSettled();
$event->notification->raw();              // array penuh, kalau butuh field lain
```

`originalAmount()` penting kalau **Automatic Fee Imposition** aktif: `gross_amount`
sudah termasuk fee yang dibebankan ke pembeli, sehingga merekonsiliasinya dengan
total invoice akan terbaca sebagai kelebihan bayar.

## Testing

```php
use Aliziodev\LaravelMidtrans\Facades\Midtrans;

it('creates a snap token at checkout', function () {
    Midtrans::fake([
        '*/snap/v1/transactions' => ['token' => 'snap-token', 'redirect_url' => 'https://pay.test'],
    ]);

    $this->post('/checkout', ['order_id' => 'ORDER-1'])->assertOk();

    Midtrans::assertSent(fn ($request) => $request->input('transaction_details.gross_amount') === 150000);
});
```

Tersedia `assertSent`, `assertNotSent`, `assertNothingSent`, dan `assertSentCount`.
`Midtrans::fake()` juga memalsukan `SnapBi`, karena keduanya berbagi transport.

Menguji listener webhook Anda sendiri tidak perlu HTTP sama sekali — dispatch
event-nya langsung:

```php
event(new PaymentSettled(Notification::fromArray(['order_id' => 'ORDER-1', ...])));
```

## Snap-BI

Opsional sepenuhnya. Kalau Anda hanya memakai Core API dan Snap — charge, VA,
QRIS, GoPay, webhook — lewati bagian ini; package bekerja tanpa satu pun nilai
di bawah.

`CLIENT_ID`, `CLIENT_SECRET`, dan `PARTNER_ID` **tidak ada di dashboard**.
Ketiganya dikirim Midtrans setelah Anda mendaftarkan public key sendiri di
*Settings > Access Keys > Payment BI SNAP*. Urutannya:

1. Buat keypair — RSA 2048 minimum, PKCS#8, PEM:

   ```bash
   openssl genpkey -algorithm rsa -out secrets/snapbi-private.pem \
       -outform PEM -pkeyopt rsa_keygen_bits:2048
   openssl rsa -in secrets/snapbi-private.pem -outform PEM -pubout \
       -out secrets/snapbi-public.pem
   ```

2. Daftarkan **public key**-nya di dashboard, utuh termasuk baris `BEGIN` dan
   `END`. Generate di **Sandbox dulu** — mulai dari Production membuat supported
   scopes kosong dan harus dibatalkan lewat Midtrans support.

3. Midtrans membalas dengan ClientID, ClientSecret, PartnerID, dan public key
   milik mereka.

Tunjuk PEM lewat path, jangan tempel inline; satu key ~1700 karakter membuat
`.env` tidak terbaca dan tidak bisa di-diff:

```dotenv
MIDTRANS_SNAP_BI_WEBHOOK_ENABLED=true
MIDTRANS_SNAP_BI_CLIENT_ID=
MIDTRANS_SNAP_BI_CLIENT_SECRET=
MIDTRANS_SNAP_BI_PARTNER_ID=

# Key Anda sendiri, untuk menandatangani request
MIDTRANS_SNAP_BI_PRIVATE_KEY_PATH=secrets/snapbi-private.pem

# Key milik Midtrans, untuk memverifikasi notifikasi mereka.
# Ini BUKAN public key yang Anda daftarkan.
MIDTRANS_SNAP_BI_PUBLIC_KEY_PATH=secrets/midtrans-snapbi-public.pem
```

Path relatif diukur dari root aplikasi, dan config yang di-cache menyimpan
path-nya, bukan isi key-nya. Varian inline `MIDTRANS_SNAP_BI_PRIVATE_KEY` tetap
ada untuk platform tanpa disk yang bisa ditulis.

```php
Event::listen(SnapBiWebhookReceived::class, function ($event) {
    if ($event->notification->isSettled()) {
        // latestTransactionStatus "00"
    }
});
```

Verifikasinya asimetris (SHA256withRSA) dengan public key dari Midtrans, plus
jendela toleransi `X-TIMESTAMP` 5 menit untuk membatasi replay.

## Artisan

```bash
php artisan midtrans:status ORDER-1001
php artisan midtrans:status ORDER-1001 --json
```

Berguna saat webhook terlewat atau listener gagal: API adalah sumber kebenaran,
bukan notifikasi yang Anda terima atau tidak.

## Menguji webhook ke sandbox sungguhan

Midtrans harus bisa menjangkau mesin Anda. Cara yang biasa dipakai adalah
mengisi Notification URL di dashboard, tapi itu global — satu developer
mengubahnya, developer lain kehilangan notifikasi.

Package ini memakai jalur lain: header `X-Override-Notification`, yang menunjuk
tujuan notifikasi **per transaksi**. Dashboard tidak disentuh sama sekali.

```bash
# 1. Buka terowongan ke internet
cloudflared tunnel --url http://127.0.0.1:8000
#    -> https://random-words-1234.trycloudflare.com
```

```dotenv
# 2. Arahkan notifikasi ke tunnel itu
MIDTRANS_OVERRIDE_NOTIFICATION_URL=https://random-words-1234.trycloudflare.com/midtrans/webhook
```

```bash
# 3. Jalankan package sebagai app sungguhan
composer sandbox:serve

# 4. Buat transaksi, lalu bayar di simulator sandbox
composer test:sandbox
```

Simulatornya ada di [simulator.sandbox.midtrans.com](https://simulator.sandbox.midtrans.com).
Bayar VA atau QRIS yang dibuat test, lalu notifikasi akan masuk ke route webhook
Anda lewat tunnel — dengan signature asli, bukan buatan test. Pantau hasilnya:

```bash
tail -f vendor/orchestra/testbench-core/laravel/storage/logs/laravel.log
```

URL `trycloudflare.com` bersifat sementara dan berubah tiap kali tunnel
dijalankan ulang, jadi perbarui `.env` setiap memulai sesi.

> **Kenapa `composer sandbox:serve`, bukan `vendor/bin/testbench serve` langsung?**
> Testbench boot dari skeleton-nya sendiri, jadi Laravel membaca `.env` dari
> `vendor/orchestra/testbench-core/laravel`, bukan dari root package. Mengoper
> nilainya sebagai environment variable juga tidak menolong, karena
> `variables_order` bawaan PHP (`GPCS`) membuat `$_ENV` kosong. Hasilnya app
> tanpa server key, yang menolak setiap notifikasi dengan **403 yang terlihat
> persis seperti signature gagal**. Skrip ini menyalin nilainya ke skeleton
> lebih dulu, dan menolak jalan kalau key-nya bukan kunci sandbox.

## Laravel Boost

Package ini menyertakan skill untuk [Laravel Boost](https://github.com/laravel/boost)
di `resources/boost/skills/laravel-midtrans/SKILL.md`. Isinya bukan daftar API —
itu sudah ada di sini — melainkan jebakan yang membuat integrasi Midtrans salah:
hanya `PaymentSettled` yang berarti uang masuk, jumlah harus tetap string karena
signature dihitung dari teks persisnya, refund butuh `refund_key`, dan route
webhook tidak boleh masuk group `web`.

Boost saat ini hanya menemukan `resources/boost/` secara otomatis untuk paket
first-party (scope `laravel/` dan sedikit allowlist), jadi untuk sekarang pasang
manual:

```bash
php artisan boost:add-skill vendor/aliziodev/laravel-midtrans/resources/boost/skills/laravel-midtrans
```

Path-nya sudah mengikuti konvensi Boost, jadi begitu penemuan otomatis dibuka
untuk paket pihak ketiga, tidak ada yang perlu diubah.

## Konfigurasi

Semua opsi terdokumentasi di `config/midtrans.php`. Yang paling sering disentuh:

| Env | Default | Keterangan |
|---|---|---|
| `MIDTRANS_IS_PRODUCTION` | `false` | Sandbox sampai diubah secara sadar |
| `MIDTRANS_MAX_RETRIES` | `2` | Hanya dipakai di request yang aman diulang |
| `MIDTRANS_WEBHOOK_VERIFY_WITH_API` | `true` | Baca ulang status sebelum dispatch |
| `MIDTRANS_WEBHOOK_DEDUPE_TTL` | `300` | Jendela deduplikasi, detik. `0` mematikan |
| `MIDTRANS_IDEMPOTENCY_PREFIX` | `midtrans` | Maksimal 13 karakter |

## Lisensi

MIT. Lihat [LICENSE](LICENSE).
