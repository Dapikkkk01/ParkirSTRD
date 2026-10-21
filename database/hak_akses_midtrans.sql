-- Hak akses tambahan integrasi Midtrans untuk instalasi yang memakai akun DB terpisah.
-- Jalankan sebagai root/admin DB setelah hak_akses.sql, fitur_qris.sql, dan midtrans_qris.sql.
-- Ganti password placeholder dan sesuaikan host akun dengan config/database.php.
CREATE USER IF NOT EXISTS 'parkirstrd_system'@'localhost' IDENTIFIED BY 'GANTI_PASSWORD_SYSTEM_KUAT';
CREATE USER IF NOT EXISTS 'parkirstrd_system'@'127.0.0.1' IDENTIFIED BY 'GANTI_PASSWORD_SYSTEM_KUAT';

-- Akun system untuk endpoint callback Midtrans: hak minimum agar dapat memverifikasi dan menyimpan status.
GRANT SELECT (id_parkir,status,metode_pembayaran,status_pembayaran,biaya_total) ON parkir.tb_transaksi TO 'parkirstrd_system'@'localhost';
GRANT UPDATE (status_pembayaran,waktu_bayar,referensi_pembayaran) ON parkir.tb_transaksi TO 'parkirstrd_system'@'localhost';
GRANT SELECT, INSERT, UPDATE ON parkir.tb_midtrans_payment TO 'parkirstrd_system'@'localhost';
GRANT INSERT ON parkir.tb_midtrans_webhook_log TO 'parkirstrd_system'@'localhost';
GRANT SELECT (id_parkir,status,metode_pembayaran,status_pembayaran,biaya_total) ON parkir.tb_transaksi TO 'parkirstrd_system'@'127.0.0.1';
GRANT UPDATE (status_pembayaran,waktu_bayar,referensi_pembayaran) ON parkir.tb_transaksi TO 'parkirstrd_system'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE ON parkir.tb_midtrans_payment TO 'parkirstrd_system'@'127.0.0.1';
GRANT INSERT ON parkir.tb_midtrans_webhook_log TO 'parkirstrd_system'@'127.0.0.1';

-- Bila akun Petugas dipakai untuk membuat dan memeriksa QR Midtrans, berikan hak pada tabel gateway.
-- Hak ini tidak mengizinkan Petugas mengakses backup Admin atau mengubah tabel user/tarif.
GRANT SELECT, INSERT ON parkir.tb_midtrans_payment TO 'petugas_parkir'@'localhost';
GRANT UPDATE (midtrans_transaction_id,transaction_status,payment_type,qr_code_url,expires_at,response_status_code,last_status_check,last_notification_at,last_error) ON parkir.tb_midtrans_payment TO 'petugas_parkir'@'localhost';
GRANT SELECT, INSERT ON parkir.tb_midtrans_payment TO 'petugas_parkir'@'127.0.0.1';
GRANT UPDATE (midtrans_transaction_id,transaction_status,payment_type,qr_code_url,expires_at,response_status_code,last_status_check,last_notification_at,last_error) ON parkir.tb_midtrans_payment TO 'petugas_parkir'@'127.0.0.1';
GRANT SELECT (id_parkir,status,metode_pembayaran,status_pembayaran,biaya_total,id_user_keluar,referensi_pembayaran) ON parkir.tb_transaksi TO 'petugas_parkir'@'localhost';
GRANT UPDATE (status_pembayaran,referensi_pembayaran,waktu_bayar) ON parkir.tb_transaksi TO 'petugas_parkir'@'localhost';
GRANT SELECT (id_parkir,status,metode_pembayaran,status_pembayaran,biaya_total,id_user_keluar,referensi_pembayaran) ON parkir.tb_transaksi TO 'petugas_parkir'@'127.0.0.1';
GRANT UPDATE (status_pembayaran,referensi_pembayaran,waktu_bayar) ON parkir.tb_transaksi TO 'petugas_parkir'@'127.0.0.1';

FLUSH PRIVILEGES;
-- Verifikasi: SHOW GRANTS FOR 'parkirstrd_system'@'localhost';
