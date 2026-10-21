-- Jalankan sesudah parkir.sql dan parkir_pengembangan.sql menggunakan akun administrasi MySQL.
-- Password berikut HANYA untuk lingkungan demo lokal; ganti sebelum digunakan sungguhan.
CREATE USER IF NOT EXISTS 'login_parkir'@'localhost' IDENTIFIED BY 'GantiPasswordLokal!2026';
CREATE USER IF NOT EXISTS 'admin_parkir'@'localhost' IDENTIFIED BY 'GantiPasswordLokal!2026';
CREATE USER IF NOT EXISTS 'petugas_parkir'@'localhost' IDENTIFIED BY 'GantiPasswordLokal!2026';
CREATE USER IF NOT EXISTS 'owner_parkir'@'localhost' IDENTIFIED BY 'GantiPasswordLokal!2026';
-- Duplikasi host loopback untuk konfigurasi PDO yang memakai 127.0.0.1 (TCP).
CREATE USER IF NOT EXISTS 'login_parkir'@'127.0.0.1' IDENTIFIED BY 'GantiPasswordLokal!2026';
CREATE USER IF NOT EXISTS 'admin_parkir'@'127.0.0.1' IDENTIFIED BY 'GantiPasswordLokal!2026';
CREATE USER IF NOT EXISTS 'petugas_parkir'@'127.0.0.1' IDENTIFIED BY 'GantiPasswordLokal!2026';
CREATE USER IF NOT EXISTS 'owner_parkir'@'127.0.0.1' IDENTIFIED BY 'GantiPasswordLokal!2026';

GRANT SELECT (id_user,nama_lengkap,username,password,role,status_aktif) ON parkir.tb_user TO 'login_parkir'@'localhost';
GRANT UPDATE (password) ON parkir.tb_user TO 'login_parkir'@'localhost';
GRANT INSERT ON parkir.tb_log_aktivitas TO 'login_parkir'@'localhost';
GRANT ALL PRIVILEGES ON parkir.* TO 'admin_parkir'@'localhost';

GRANT SELECT ON parkir.tb_kendaraan TO 'petugas_parkir'@'localhost';
GRANT SELECT ON parkir.tb_tarif TO 'petugas_parkir'@'localhost';
GRANT SELECT ON parkir.tb_area_parkir TO 'petugas_parkir'@'localhost';
GRANT SELECT ON parkir.tb_transaksi TO 'petugas_parkir'@'localhost';
GRANT INSERT ON parkir.tb_transaksi TO 'petugas_parkir'@'localhost';
GRANT UPDATE (waktu_keluar,durasi_jam,biaya_total,status) ON parkir.tb_transaksi TO 'petugas_parkir'@'localhost';
GRANT UPDATE (terisi) ON parkir.tb_area_parkir TO 'petugas_parkir'@'localhost';
GRANT SELECT (id_user,nama_lengkap) ON parkir.tb_user TO 'petugas_parkir'@'localhost';
GRANT INSERT ON parkir.tb_log_aktivitas TO 'petugas_parkir'@'localhost';
GRANT EXECUTE ON PROCEDURE parkir.sp_parkir_keluar TO 'petugas_parkir'@'localhost';

GRANT SELECT ON parkir.v_rekap_transaksi TO 'owner_parkir'@'localhost';

-- Grant serupa untuk akun yang host-nya 127.0.0.1.
GRANT SELECT (id_user,nama_lengkap,username,password,role,status_aktif) ON parkir.tb_user TO 'login_parkir'@'127.0.0.1';
GRANT UPDATE (password) ON parkir.tb_user TO 'login_parkir'@'127.0.0.1';
GRANT INSERT ON parkir.tb_log_aktivitas TO 'login_parkir'@'127.0.0.1';
GRANT ALL PRIVILEGES ON parkir.* TO 'admin_parkir'@'127.0.0.1';
GRANT SELECT ON parkir.tb_kendaraan TO 'petugas_parkir'@'127.0.0.1';
GRANT SELECT ON parkir.tb_tarif TO 'petugas_parkir'@'127.0.0.1';
GRANT SELECT ON parkir.tb_area_parkir TO 'petugas_parkir'@'127.0.0.1';
GRANT SELECT ON parkir.tb_transaksi TO 'petugas_parkir'@'127.0.0.1';
GRANT INSERT ON parkir.tb_transaksi TO 'petugas_parkir'@'127.0.0.1';
GRANT UPDATE (waktu_keluar,durasi_jam,biaya_total,status) ON parkir.tb_transaksi TO 'petugas_parkir'@'127.0.0.1';
GRANT UPDATE (terisi) ON parkir.tb_area_parkir TO 'petugas_parkir'@'127.0.0.1';
GRANT SELECT (id_user,nama_lengkap) ON parkir.tb_user TO 'petugas_parkir'@'127.0.0.1';
GRANT INSERT ON parkir.tb_log_aktivitas TO 'petugas_parkir'@'127.0.0.1';
GRANT EXECUTE ON PROCEDURE parkir.sp_parkir_keluar TO 'petugas_parkir'@'127.0.0.1';
GRANT SELECT ON parkir.v_rekap_transaksi TO 'owner_parkir'@'127.0.0.1';

-- Verifikasi hak akses saat ini. Jalankan dari masing-masing sesi login database.
SHOW GRANTS FOR 'login_parkir'@'localhost';
SHOW GRANTS FOR 'admin_parkir'@'localhost';
SHOW GRANTS FOR 'petugas_parkir'@'localhost';
SHOW GRANTS FOR 'owner_parkir'@'localhost';
SHOW GRANTS FOR 'owner_parkir'@'127.0.0.1';
-- Tes izin: login_parkir bisa membaca kolom autentikasi tetapi tidak bisa SELECT *;
-- petugas_parkir tidak dapat UPDATE tarif atau menghapus transaksi;
-- owner_parkir hanya dapat SELECT v_rekap_transaksi, bukan tabel akun pengguna.


-- Contoh latihan REVOKE menggunakan user uji tersendiri agar hak akses aplikasi tidak rusak:
-- CREATE USER IF NOT EXISTS 'uji_dcl'@'localhost' IDENTIFIED BY 'UjiDCL123!';
-- GRANT SELECT ON parkir.tb_tarif TO 'uji_dcl'@'localhost';
-- SHOW GRANTS FOR 'uji_dcl'@'localhost';
-- REVOKE SELECT ON parkir.tb_tarif FROM 'uji_dcl'@'localhost';
-- SHOW GRANTS FOR 'uji_dcl'@'localhost';
-- DROP USER IF EXISTS 'uji_dcl'@'localhost';
