# Berkontribusi

Dokumen ini untuk orang yang mengerjakan package-nya. Kalau Anda memakainya di
aplikasi Laravel, [README](README.md) sudah cukup.

## Menjalankan test

```bash
composer test        # Pest, tanpa jaringan
composer test:sandbox # memanggil sandbox Midtrans sungguhan
composer analyse     # Larastan
composer lint        # Pint
composer qa
```

## Menguji webhook terhadap sandbox sungguhan

Package ini bukan aplikasi, jadi ia perlu dijalankan sebagai aplikasi lebih dulu.

```bash
# 1. Terowongan ke internet
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

Bayar VA atau QRIS yang dibuat test di
[simulator.sandbox.midtrans.com](https://simulator.sandbox.midtrans.com), lalu
pantau:

```bash
tail -f vendor/orchestra/testbench-core/laravel/storage/logs/laravel.log
```

### Kenapa `composer sandbox:serve`, bukan `vendor/bin/testbench serve`

Testbench boot dari skeleton-nya sendiri, jadi Laravel membaca `.env` dari
`vendor/orchestra/testbench-core/laravel`, bukan dari root package. Mengoper
nilainya sebagai environment variable juga tidak menolong, karena
`variables_order` bawaan PHP (`GPCS`) membuat `$_ENV` kosong.

Hasilnya app tanpa server key, yang menolak setiap notifikasi dengan **403 yang
terlihat persis seperti signature gagal**. Jebakan ini pernah membuat kami
menyimpulkan verifikasi signature bekerja padahal yang terjadi adalah konfigurasi
kosong. Skrip `sandbox:serve` menyalin nilainya ke skeleton lebih dulu, dan
menolak jalan kalau host tujuannya bukan sandbox.

## Guard sandbox

Suite sandbox menegaskan **host** tujuan mengandung `sandbox`, bukan menebak dari
bentuk key. Akun sandbox lama memakai prefiks `SB-Mid-server-`, yang baru memakai
`Mid-server-` — bentuk yang selama ini dipakai production. Prefiks tidak lagi
membedakan lingkungan.

Jangan pernah melonggarkan guard ini agar sebuah test lewat.

## Commit dan rilis

Rilis berjalan otomatis lewat semantic-release dari pesan commit:

| Prefiks | Efek |
| --- | --- |
| `fix:` | patch |
| `feat:` | minor |
| `feat!:` atau `BREAKING CHANGE:` di body | major |
| `docs:`, `test:`, `refactor:`, `chore:`, `ci:` | tidak merilis |

## Coverage

CI mengukur coverage tiap push dan mengirim ke Codecov. Tidak ada ambang minimum,
jadi coverage yang turun tidak menggagalkan CI — angkanya laporan, bukan gerbang.

## Batasan pembuktian

Package ini membungkus `aliziodev/midtrans-php`, dan mewarisi batasannya: 16 dari
57 method belum pernah dijawab API sungguhan karena butuh pengaktifan dari
Midtrans. Rinciannya di
[README midtrans-php](https://github.com/aliziodev/midtrans-php#seberapa-jauh-package-ini-terbukti).
