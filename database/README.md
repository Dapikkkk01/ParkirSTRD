# Database ParkirSTRD

Untuk instalasi baru, import berurutan melalui phpMyAdmin:
1. `parkir.sql` — schema awal dan data demo (destruktif saat diimpor ulang; hanya untuk database baru).
2. `parkir_pengembangan.sql` — prosedur latihan TCL.
3. `fitur_qris.sql` — status dan metadata pembayaran.
4. `fitur_kartu_parkir.sql` — kartu barcode dan relasi ke transaksi.
5. `parkirstrd_upgrade.sql` — atribusi petugas keluar dan view laporan terbaru.
6. `midtrans_qris.sql` — transaksi gateway, status pembayaran, dan audit webhook Midtrans.

Sebelum menerapkan migrasi di database yang sudah berisi data, buat backup. Jangan impor ulang `parkir.sql` pada database aktif. File `hak_akses.sql`, `hak_akses_kartu.sql`, dan `hak_akses_midtrans.sql` hanya untuk lingkungan yang sudah memastikan akun database khusus dan tabel sistem MariaDB sehat; konfigurasi demo lokal memakai root sesuai `config/database.php`.

`parkirstrd_upgrade.sql` memperbarui view laporan yang membutuhkan kolom dari migrasi QRIS dan kartu. Jalankan setelah kedua migrasi tersebut.


Untuk integrasi QRIS otomatis, konfigurasi Server Key pada `config/payment.local.php` (buat dari `config/payment.local.example.php`). Jangan memasukkan Server Key ke file publik. Endpoint webhook `api/midtrans-notification.php` harus tersedia lewat HTTPS publik agar Midtrans dapat mengaksesnya. Pembayaran Sandbox hanya diuji memakai QRIS Simulator Midtrans; jangan gunakan rekening sungguhan.


Untuk menggunakan pembayaran otomatis, konfigurasikan `config/payment.local.php` berdasarkan `config/payment.local.example.php`. Integrasi ini memakai Midtrans Core API QRIS, API status, dan webhook SHA-512. Gunakan Sandbox Simulator untuk pengujian; jangan membayar QR Sandbox memakai dana sungguhan.
