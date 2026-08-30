# Laravel Midtrans

[![Tests](https://github.com/aliziodev/laravel-midtrans/actions/workflows/tests.yml/badge.svg)](https://github.com/aliziodev/laravel-midtrans/actions/workflows/tests.yml)
[![Latest Stable Version](https://img.shields.io/packagist/v/aliziodev/laravel-midtrans.svg)](https://packagist.org/packages/aliziodev/laravel-midtrans)
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

Aktifkan route notifikasinya secara terpisah, karena tidak semua merchant memakai
Snap-BI:

```dotenv
MIDTRANS_SNAP_BI_WEBHOOK_ENABLED=true
MIDTRANS_SNAP_BI_PUBLIC_KEY="-----BEGIN PUBLIC KEY-----\n...\n-----END PUBLIC KEY-----"
```

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
# 1. Jalankan package sebagai app sungguhan
vendor/bin/testbench serve

# 2. Buka ke internet
cloudflared tunnel --url http://127.0.0.1:8000
#    -> https://random-words-1234.trycloudflare.com
```

```dotenv
# 3. Arahkan notifikasi ke tunnel itu
MIDTRANS_OVERRIDE_NOTIFICATION_URL=https://random-words-1234.trycloudflare.com/midtrans/webhook
```

```bash
# 4. Buat transaksi, lalu bayar di simulator sandbox
composer test:sandbox
```

Simulatornya ada di [simulator.sandbox.midtrans.com](https://simulator.sandbox.midtrans.com).
Bayar VA atau QRIS yang dibuat test, lalu notifikasi akan masuk ke route webhook
Anda lewat tunnel — dengan signature asli, bukan buatan test.

URL `trycloudflare.com` bersifat sementara dan berubah tiap kali tunnel
dijalankan ulang, jadi perbarui `.env` setiap memulai sesi.

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
