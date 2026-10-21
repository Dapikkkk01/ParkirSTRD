-- ParkirSTRD: migrasi fitur pembayaran QRIS dan status pembayaran.
-- Backup database parkir terlebih dahulu, lalu impor file ini melalui phpMyAdmin.
USE parkir;
ALTER TABLE tb_transaksi
  ADD COLUMN IF NOT EXISTS metode_pembayaran ENUM('tunai','qris') NOT NULL DEFAULT 'tunai' AFTER biaya_total,
  ADD COLUMN IF NOT EXISTS status_pembayaran ENUM('belum_bayar','menunggu_verifikasi','lunas') NOT NULL DEFAULT 'belum_bayar' AFTER metode_pembayaran,
  ADD COLUMN IF NOT EXISTS waktu_bayar DATETIME NULL AFTER status_pembayaran,
  ADD COLUMN IF NOT EXISTS referensi_pembayaran VARCHAR(100) NULL AFTER waktu_bayar;
-- Transaksi lama yang sudah selesai dipertahankan sebagai transaksi tunai lunas.
UPDATE tb_transaksi SET metode_pembayaran='tunai', status_pembayaran='lunas', waktu_bayar=COALESCE(waktu_bayar,waktu_keluar)
WHERE status='keluar' AND status_pembayaran='belum_bayar';
-- Jalankan GRANT berikut sebagai root jika akun Petugas menggunakan user DB terpisah.
-- Jika aplikasi menggunakan akun database terbatas petugas_parkir, jalankan grant ini secara manual bila akun tersebut ada:
-- GRANT UPDATE (waktu_keluar,durasi_jam,biaya_total,status,metode_pembayaran,status_pembayaran,waktu_bayar,referensi_pembayaran) ON parkir.tb_transaksi TO 'petugas_parkir'@'localhost';
-- GRANT UPDATE (waktu_keluar,durasi_jam,biaya_total,status,metode_pembayaran,status_pembayaran,waktu_bayar,referensi_pembayaran) ON parkir.tb_transaksi TO 'petugas_parkir'@'127.0.0.1';
