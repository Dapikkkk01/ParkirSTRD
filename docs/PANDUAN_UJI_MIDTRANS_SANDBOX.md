# Panduan Uji Midtrans QRIS Sandbox — ParkirSTRD

Panduan ini untuk pengujian lokal XAMPP. QRIS Sandbox bukan pembayaran sungguhan.

## A. Persiapan

1. Backup database sebelum migrasi.
2. Instal proyek sebagai `C:\xampp\htdocs\parkirstrd`.
3. Pastikan Apache dan MySQL berjalan, lalu import skrip SQL sesuai urutan di `README.md`.
4. Pastikan PHP cURL aktif. Pada `php.ini` XAMPP, pastikan `extension=curl` tidak diawali `;`, lalu restart Apache.
5. Pastikan ekstensi PDO MySQL diaktifkan (`extension=pdo_mysql`) dan database terkoneksi.
6. Buat/login ke Midtrans Sandbox Merchant Administration Portal dan salin **Sandbox Server Key**. Tidak perlu memakai QRIS pribadi untuk uji ini.

## B. Konfigurasi lokal

1. Salin `config/payment.local.example.php` menjadi `config/payment.local.php`.
2. Isikan Server Key Sandbox ke `server_key` dan biarkan `environment` bernilai `sandbox`.
3. Simpan file; jangan menaruh key di HTML, JavaScript, Git, screenshot, atau percakapan.
4. Buka menu pembayaran QRIS pada akun Petugas.
5. Proses transaksi keluar menggunakan metode QRIS. ParkirSTRD akan meminta Midtrans membuat QR dinamis dengan order ID unik dan nominal transaksi.

## C. Simulasi pembayaran

1. Pada menu pembayaran, salin URL QR yang dibuat Midtrans.
2. Buka simulator resmi: <https://simulator.sandbox.midtrans.com/openapi/qris/index>.
3. Masukkan URL QR di simulator dan selesaikan alur simulasi.
4. Kembali ke ParkirSTRD dan klik **Periksa status langsung ke Midtrans**.
5. Periksa status pembayaran, referensi, dan rekap Owner. Status `lunas` hanya boleh diberikan jika status API terverifikasi cocok dengan Order ID, metode, dan nominal.

**Jangan membayar QR Sandbox dengan aplikasi bank atau e-wallet sungguhan.** Midtrans memperingatkan dana nyata yang dibayarkan ke referensi Sandbox bisa masuk ke tujuan yang tidak dapat dipulihkan. Gunakan simulator Sandbox.

## D. Uji webhook otomatis dari XAMPP

Midtrans tidak dapat mengirim callback langsung ke `localhost`.

1. Siapkan HTTPS tunnel tepercaya yang hanya dipakai untuk pengujian.
2. Arahkan URL notifikasi Midtrans Sandbox ke:
   `https://DOMAIN-TUNNEL/parkirstrd/api/midtrans-notification.php`
3. Jalankan transaksi Sandbox dan selesaikan lewat simulator.
4. Periksa tabel `tb_midtrans_webhook_log` dan status transaksi.
5. Pastikan notifikasi invalid ditolak, status ditanyakan ulang ke API, dan webhook berulang tidak menggandakan pembayaran.
6. Tutup tunnel setelah selesai. Jangan membuka phpMyAdmin atau panel Admin ke internet.

Tanpa tunnel, tombol pemeriksaan status tetap melakukan pengecekan server-to-server, tetapi bukan bukti bahwa callback webhook telah berhasil diuji.

## E. Kriteria lulus

- Kredensial Sandbox valid dan tidak terlihat pada browser.
- QR dibuat dengan nominal serta Order ID yang cocok.
- Halaman sukses atau payload yang dipalsukan tidak membuat transaksi menjadi lunas.
- Signature yang salah ditolak.
- Callback memeriksa ulang status via API.
- Nominal/Order ID yang tidak cocok ditolak.
- Callback berulang tidak menggandakan pendapatan.
- Status hanya menjadi `lunas` saat Midtrans melaporkan `settlement` dengan status code yang sesuai.
- Periksa output helper lokal:
  `php tests/test_midtrans_helpers.php`

Tes helper hanya menguji logika lokal; ia tidak menghubungi Midtrans.

## F. Pembayaran Production

Untuk menerima uang sungguhan, lakukan onboarding merchant Midtrans, aktifkan Core API dan kanal QRIS Production, lengkapi rekening penyelesaian dana merchant, dan gunakan Production Server Key hanya setelah disetujui. Ubah `environment` menjadi `production` dan lakukan pengujian yang terkontrol sebelum digunakan oleh pelanggan. QRIS pribadi dari aplikasi bank tidak memberikan API callback otomatis ke ParkirSTRD.

Dokumentasi resmi:
- QRIS Core API: <https://docs.midtrans.com/reference/qris>
- Webhook dan signature: <https://docs.midtrans.com/docs/https-notification-webhooks>
- Sandbox: <https://docs.midtrans.com/docs/testing-payment-on-sandbox>
- Simulator QRIS: <https://simulator.sandbox.midtrans.com/openapi/qris/index>
