# ParkirSTRD — Advanced Parking Management System

Aplikasi manajemen parkir berbasis PHP Native + PDO + MySQL/MariaDB untuk XAMPP. Proyek ini mempertahankan operasi kendaraan masuk/keluar, kartu barcode pelanggan, scanner kamera, pembayaran QRIS otomatis via Midtrans (jika Server Key telah dikonfigurasi), laporan, dan desain ungu-putih. Revisi ini menambahkan branding ParkirSTRD, atribusi petugas masuk/keluar yang terpisah, laporan Owner per petugas, serta modul Backup & Restore dengan cakupan role berbeda.

> **Status verifikasi:** semua file PHP telah diperiksa menggunakan `php -l`. SQL, koneksi MySQL, CLI `mysqldump/mysql`, interaksi kamera, QRIS merchant, serta proses backup/restore belum diuji terhadap server XAMPP nyata pada lingkungan pembuat. Jalankan checklist pengujian setelah instalasi dan jangan menganggap tes database berhasil sebelum dijalankan.

## 1. Teknologi
- PHP Native 8.1+; tanpa Laravel/CodeIgniter.
- PDO prepared statements, MySQL/MariaDB InnoDB.
- HTML/CSS/JavaScript, Chart.js, JsBarcode, dan html5-qrcode melalui CDN.
- Zona waktu `Asia/Jakarta`.
- Ekspor Owner: CSV UTF-8 yang kompatibel dengan Excel; laporan print-friendly dapat dicetak atau disimpan sebagai PDF lewat dialog browser.

## 2. Instalasi baru pada XAMPP

1. Backup dulu jika sudah memiliki versi sebelumnya. Ekstrak ZIP sehingga folder proyek menjadi `C:\xampp\htdocs\parkirstrd`.
2. Jalankan Apache dan MySQL dari XAMPP Control Panel.
3. Buka `http://localhost/phpmyadmin/`.
4. Buat database kosong bernama `parkir` atau biarkan skrip `database/parkir.sql` membuatnya.
5. Import file SQL **dengan urutan berikut**:
   1. `database/parkir.sql` (hanya untuk database instalasi baru)
   2. `database/parkir_pengembangan.sql`
   3. `database/fitur_qris.sql`
   4. `database/fitur_kartu_parkir.sql`
   5. `database/parkirstrd_upgrade.sql`
   6. `database/midtrans_qris.sql`
6. Periksa `config/database.php`. Default demo lokal memakai `root` tanpa password. Sesuaikan jika kredensial XAMPP Anda berbeda.
7. Untuk menguji QRIS otomatis, buat akun Midtrans Sandbox lalu salin `config/payment.local.example.php` menjadi `config/payment.local.php` dan isi Sandbox Server Key. Rincian ada pada bagian Integrasi Midtrans.
8. Buka `http://localhost/parkirstrd/`.

**Peringatan:** `database/parkir.sql` adalah skrip instalasi awal dan akan menjatuhkan/membuat ulang tabel inti. **Jangan impor ulang skrip ini pada database yang sudah berisi data** untuk menginstal revisi. Untuk database lama, backup dahulu, lalu terapkan migrasi yang belum ada dalam urutan `fitur_qris.sql` → `fitur_kartu_parkir.sql` → `parkirstrd_upgrade.sql` → `midtrans_qris.sql`. Jika tidak yakin skema mana yang sudah terpasang, periksa `SHOW TABLES` dan `SHOW COLUMNS` sebelum migrasi.

### Akun demo aplikasi

| Role | Username | Password demo |
|---|---|---|
| Admin | `admin` | `admin12345` |
| Petugas | `petugas` | `petugas12345` |
| Owner | `owner` | `Owner123!` |

Password aplikasi tersimpan dalam hash PHP. Ubah password demo setelah login.

## 3. Fitur berdasarkan role

### Admin
- Dashboard, pengelolaan pengguna, kendaraan, kartu parkir, tarif, area, transaksi, laporan, dan log aktivitas.
- Modul **Backup & Restore** di `admin/backup/index.php`.
- Backup database penuh melalui `mysqldump`/`mariadb-dump`, unduh berkas, checksum SHA-256, pemeriksaan berkas SQL eksternal, serta restore dari backup penuh yang terdaftar.
- Restore otomatis membuat backup pra-restore terlebih dahulu. Restore hanya mengizinkan backup buatan aplikasi yang checksum-nya valid; file SQL eksternal yang diunggah hanya dikarantina untuk pemeriksaan dan tidak langsung dieksekusi.
- Admin dapat meninjau permintaan pemulihan data operasional yang diajukan Petugas.

### Petugas
- Scanner kartu, kendaraan masuk/keluar, kendaraan aktif, pembayaran QRIS otomatis via Midtrans (jika Server Key telah dikonfigurasi), struk, riwayat, dan dashboard.
- Dashboard, riwayat transaksi, struk, dan pembayaran pribadi dibatasi ke transaksi yang melibatkan Petugas tersebut sebagai petugas masuk atau keluar. Daftar kendaraan aktif/kapasitas area tetap mencerminkan operasional bersama agar petugas bisa melayani kendaraan.
- Modul **Backup Operasional** di `petugas/backup.php`. Backup JSON memiliki signature HMAC dan hanya memuat transaksi yang melibatkan akun Petugas pada rentang tanggal yang dipilih.
- Pembayaran QRIS dinamis dibuat melalui Midtrans Core API; status dapat diperiksa kembali langsung ke API, sedangkan webhook publik memperbarui status otomatis. Struk tidak menampilkan QRIS statis sebagai pengganti QR transaksi; transaksi yang belum lunas diarahkan ke QR dinamis khusus order tersebut.
- Pemulihan operasional memvalidasi signature dan hanya dapat memulihkan transaksi **selesai** yang tidak ditemukan pada database. Transaksi yang sudah ada tidak ditimpa. Record kendaraan yang masih berstatus masuk tidak dipulihkan otomatis; record tersebut diajukan ke Admin untuk review agar tidak terjadi penghitungan okupansi ganda.
- Petugas tidak dapat melakukan restore database penuh, mengakses backup Admin, atau mengunggah SQL arbitrer untuk dieksekusi.

### Owner
- Dashboard baca-saja, filter laporan, pencarian, rincian transaksi, pendapatan berdasarkan jenis kendaraan, dan rekap aktivitas/nominal berdasarkan petugas masuk/keluar.
- CSV UTF-8 untuk dibuka di Excel dan opsi cetak/Simpan sebagai PDF melalui dialog browser.
- Nilai transaksi hanya diatribusikan untuk pendapatan kepada petugas keluar pada ringkasan pendapatan supaya tidak dihitung dua kali. Aktivitas petugas masuk ditampilkan terpisah.

## 4. Atribusi Petugas

Kolom `tb_transaksi.id_user` merekam petugas masuk. Kolom baru `tb_transaksi.id_user_keluar` merekam petugas yang menyelesaikan transaksi. Proses keluar melalui form maupun scanner mengisi kolom petugas keluar tersebut.

Saat migrasi, transaksi selesai historis yang `id_user_keluar`-nya kosong diisi dari `id_user` sebagai atribusi sementara, karena catatan lama tidak menyimpan petugas keluar secara terpisah. Jika Anda mengetahui petugas keluar historis yang sebenarnya berbeda, koreksi atribusinya melalui prosedur administrasi yang terdokumentasi sebelum memakai laporan sebagai data resmi.

## 5. Backup dan Restore dari aplikasi

### Backup Admin
- Buka menu **Backup & Restore**.
- Klik **Buat backup sekarang**.
- Sistem menggunakan executable `mysqldump.exe` atau `mariadb-dump.exe` pada folder `mysql\bin` XAMPP dan kredensial di `config/database.php`.
- File backup default disimpan di `C:\xampp\parkirstrd_private\admin`, di luar `htdocs`. Pada instalasi dengan lokasi berbeda, default mengikuti lokasi akar XAMPP yang terdeteksi.
- Admin dapat mengunduh backup dan melakukan restore dari backup yang terdaftar. Sebelum restore, aplikasi membuat backup pra-restore. Restore akan menimpa tabel/objek database yang tercakup di backup sehingga harus dijalankan dengan hati-hati.
- File SQL eksternal hanya diperiksa dan disimpan di karantina; untuk keamanan, file unggahan tidak langsung dieksekusi sebagai SQL.

### Backup Petugas
- Buka **Backup Operasional** lalu pilih rentang tanggal.
- Unduh JSON milik akun Petugas tersebut.
- Pemulihan membutuhkan file asli yang ditandatangani oleh instalasi ParkirSTRD yang sama. Jika kunci tanda tangan berbeda/hilang, verifikasi ditolak.
- Transaksi selesai yang hilang dapat disalin kembali jika semua relasi yang diperlukan masih tersedia. ID transaksi yang sudah ada tidak ditimpa.
- Transaksi yang masih aktif memerlukan pemeriksaan Admin karena pemulihannya dapat memengaruhi kapasitas area.

### Konfigurasi lokasi backup (opsional)

Jika direktori default tidak bisa ditulis, atur environment variable `PARKIRSTRD_BACKUP_DIR` ke folder privat yang hanya dapat diakses akun PHP. Jika tool MySQL tidak terdeteksi otomatis, atur `PARKIRSTRD_MYSQL_BIN` ke folder `mysql\bin` atau executable yang sesuai. Jangan mengarahkan folder backup ke direktori publik. Pastikan user Windows yang menjalankan Apache mendapat izin tulis/baca yang dibutuhkan.

Untuk backup terjadwal, gunakan Windows Task Scheduler atau mekanisme server yang terkelola. Jadwal tidak berjalan otomatis hanya karena modul backup dibuka.

## 6. Migrasi dan objek database

File `database/parkirstrd_upgrade.sql`:
- Menambah `id_user_keluar` bila belum tersedia.
- Menambahkan indeks dan foreign key petugas keluar secara idempotent.
- Mengisi atribusi sementara pada transaksi historis selesai yang belum menyimpan petugas keluar.
- Memperbarui `v_rekap_transaksi` untuk menampilkan nama/ID petugas masuk dan keluar serta metadata pembayaran.

File ini bergantung pada kolom kartu dan pembayaran; jalankan setelah `fitur_qris.sql` dan `fitur_kartu_parkir.sql`. Pastikan membuat backup terlebih dahulu. File `database/hak_akses.sql`, `database/hak_akses_kartu.sql`, dan `database/hak_akses_midtrans.sql` adalah opsional untuk konfigurasi akun DB terpisah; konfigurasi demo memakai `root` lokal dan tidak menegakkan privilege per role pada tingkat MySQL. Untuk keamanan lebih tinggi, buat akun DB terbatas dan gunakan konfigurasi `config/database.example.php` setelah disesuaikan dengan grant yang benar.

## 7. Checklist pengujian XAMPP

1. Jalankan `SELECT VERSION();` dan `SHOW TABLES;` pada database `parkir`.
2. Pastikan `id_user_keluar` ada: `SHOW COLUMNS FROM tb_transaksi LIKE 'id_user_keluar';`.
3. Pastikan view baru terpasang: `SELECT id_parkir,nama_petugas_masuk,nama_petugas_keluar FROM v_rekap_transaksi LIMIT 5;`.
4. Login sebagai Admin, Petugas, Owner; pastikan redirect dan pembatasan role benar.
5. Buat kartu parkir, scan masuk, scan keluar, lalu pastikan transaksi menyimpan petugas masuk dan keluar yang tepat.
6. Login sebagai dua Petugas berbeda dan pastikan dashboard, riwayat, struk, rekap, QRIS, dan backup tidak mengungkap data transaksi petugas lain di luar kewenangannya.
7. Owner: filter laporan berdasarkan tanggal/petugas, unduh CSV dan uji cetak/Simpan sebagai PDF. Pastikan nominal transaksi tidak dihitung dua kali.
8. Admin: buat backup penuh. Periksa metadata dan checksum; pastikan berkas tersimpan di direktori privat.
9. Pada database uji terpisah, restore backup penuh yang dibuat aplikasi; jangan pertama kali mencoba restore pada data produksi/aktif.
10. Petugas: buat backup JSON dan coba pemulihan pada database uji. File yang diubah manual, file dari akun lain, dan file yang ditandatangani instalasi lain harus ditolak.
11. Uji kartu nonaktif, kendaraan ganda, area penuh, dan proses keluar dua kali.
12. Uji kamera pada `localhost`; jika CDN atau izin kamera gagal, gunakan input manual pada scanner.
13. Periksa `C:\xampp\apache\logs\error.log` dan log PHP untuk error yang aman ditelusuri.

## 8. Integrasi Midtrans QRIS otomatis

### Kondisi akun saat ini

QRIS pribadi dari aplikasi bank tidak otomatis memberi API callback ke website. Pembayaran otomatis membutuhkan akun Midtrans Merchant Administration Portal (MAP), Sandbox Server Key untuk pengujian, dan kredensial Production setelah onboarding/approval untuk menerima uang sungguhan. Jangan bagikan Server Key kepada siapa pun dan jangan menyimpannya di HTML atau JavaScript.

### Konfigurasi Sandbox

1. Buat/aktifkan akun Midtrans dan buka mode **Sandbox** di Merchant Administration Portal.
2. Ambil **Sandbox Server Key** pada bagian Access Keys.
3. Salin `config/payment.local.example.php` menjadi `config/payment.local.php`.
4. Isikan Server Key Sandbox pada `server_key`; pertahankan `environment` menjadi `sandbox`.
5. Pastikan ekstensi PHP `curl` aktif, lalu restart Apache.
6. Pastikan migrasi `database/midtrans_qris.sql` sudah diterapkan.
7. Proses kendaraan keluar menggunakan QRIS. Setelah transaksi parkir tersimpan, ParkirSTRD membuat QR dinamis melalui Midtrans Core API (`POST /v2/charge`).
8. QR akan muncul pada menu **Pembayaran QRIS**. Status tidak menjadi lunas hanya karena halaman pembayaran dibuka atau pelanggan menunjukkan screenshot.

### Menguji pembayaran Sandbox

- Gunakan simulator resmi: `https://simulator.sandbox.midtrans.com/openapi/qris/index`.
- Salin URL QR dari transaksi yang dibuat Midtrans lalu masukkan ke simulator untuk menyimulasikan pembayaran.
- Setelah simulator menyelesaikan transaksi, klik **Periksa status langsung ke Midtrans**. Backend akan menanyakan status API dan hanya menandai lunas bila order ID, nominal, metode QRIS, `transaction_status=settlement`, `status_code=200`, serta `fraud_status=accept` (jika dikirim) sesuai.
- **Jangan membayar QRIS Sandbox dengan aplikasi bank atau e-wallet sungguhan.** Midtrans memperingatkan referensi QRIS Sandbox dapat berhubungan dengan merchant ID Production dan dana nyata berisiko masuk ke tujuan yang tidak dapat dipulihkan. Gunakan simulator saja.

### Webhook otomatis

Endpoint: `api/midtrans-notification.php`. Handler memverifikasi SHA-512 signature `order_id + status_code + gross_amount + ServerKey`, memanggil status API Midtrans untuk verifikasi kedua, mencocokkan nominal dan order dengan catatan lokal, serta memproses event secara idempoten. Tidak ada tombol manual yang dapat menandai transaksi QRIS Midtrans lunas.

Midtrans tidak dapat mengirim webhook ke `localhost`. Untuk menguji callback pada XAMPP, gunakan HTTPS tunnel tepercaya dan atur **Payment Notification URL** di MAP menjadi `https://DOMAIN-TUNNEL/parkirstrd/api/midtrans-notification.php`. Batasi penggunaan tunnel hanya untuk pengujian, jangan membuka panel admin XAMPP ke internet, dan hentikan tunnel setelah selesai. Tanpa tunnel, tombol pemeriksaan status tetap dapat memverifikasi transaksi secara server-to-server saat ditekan.

### Database dan konfigurasi rahasia

Pada konfigurasi demo XAMPP, akun database lokal menggunakan root sesuai `config/database.php`. Untuk role database terpisah, atur akun `system` khusus di konfigurasi dan audit hak akses. Contoh grant diberikan di `database/hak_akses_midtrans.sql`; ganti password placeholder dan cocokkan host account sebelum digunakan. File `config/payment.local.php` tidak dikemas ke ZIP dan diabaikan Git.

### Beralih ke Production

Jangan mengganti `environment` ke `production` sebelum onboarding Midtrans disetujui, kanal QRIS aktif, dan Production Server Key tersedia. Gunakan Server Key Production hanya pada server yang sesuai. Jalankan tes end-to-end dan verifikasi Notification URL berhasil sebelum dipakai pelanggan sungguhan.

### Uji helper lokal

Jalankan `php tests/test_midtrans_helpers.php`. Tes ini memeriksa formula signature, pencocokan nominal, dan validasi URL QR; tes tidak menghubungi layanan Midtrans. Uji jaringan membutuhkan kredensial Sandbox milik pengguna.

## 9. Batasan yang diketahui

- Belum dilakukan uji integrasi terhadap server MySQL/MariaDB nyata di lingkungan pembuat. Backup/restore CLI dan migrasi harus diuji di XAMPP pengguna.
- Interaksi API nyata, pemindaian kamera, dan callback internet belum diuji di lingkungan ini karena memerlukan kredensial Sandbox dan endpoint yang bisa dijangkau dari internet.
- Scanner kamera, Chart.js, dan JsBarcode memakai CDN sehingga memerlukan internet; scanner memerlukan izin kamera.
- Ekspor Excel menggunakan CSV UTF-8 yang kompatibel dengan Excel, bukan workbook XLSX asli. Laporan dapat dicetak dan disimpan sebagai PDF melalui dialog browser.
- Restore operasional Petugas tidak mengembalikan transaksi yang masih aktif secara otomatis; record tersebut memerlukan pemeriksaan Admin agar okupansi tidak dihitung ganda.
- Ilustrasi peta parkir pada halaman login tetap merupakan ilustrasi; peta slot real-time belum dihubungkan ke model slot individual.

## 10. Troubleshooting

- **Error 1045 Access denied:** cek username/password MySQL di `config/database.php`; jika akun khusus dipakai, periksa host account dan `SHOW GRANTS`. Default root tanpa password hanya asumsi untuk XAMPP lokal.
- **Backup tidak dapat dibuat:** pastikan `mysqldump.exe` atau `mariadb-dump.exe` tersedia, akun admin DB dapat membaca seluruh objek, direktori privat bisa ditulis, dan durasi/ukuran backup tidak dibatasi server.
- **Restore gagal:** jangan hapus backup pra-restore. Baca pesan dari modul dan error log. Perbedaan versi MySQL/MariaDB, privilege, trigger, procedure, atau view dapat menyebabkan restore gagal.
- **Unknown column `id_user_keluar` / view tidak cocok:** backup, lalu jalankan migrasi `parkirstrd_upgrade.sql` sesudah fitur QRIS dan kartu tersedia.
- **Kunci backup operasional berubah:** file Petugas lama dari instalasi berbeda tidak bisa diverifikasi oleh HMAC baru; gunakan file dari instalasi asal dan lakukan pemulihan sesuai prosedur Admin.
- **QRIS Midtrans belum aktif:** pastikan `config/payment.local.php` dibuat dari contoh, Server Key Sandbox valid, ekstensi `curl` aktif, dan `database/midtrans_qris.sql` sudah diterapkan. Periksa `C:\xampp\apache\logs\error.log` bila API menolak permintaan.

---
**ParkirSTRD · PHP Native · MySQL/MariaDB · XAMPP · Asia/Jakarta**
