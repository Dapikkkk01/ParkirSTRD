-- Jalankan sesudah database/hak_akses.sql dan database/fitur_kartu_parkir.sql berhasil.
-- Gunakan akun administrator MySQL. Jika akun database khusus belum berhasil dibuat,
-- selesaikan pemulihan MariaDB/user terlebih dahulu; jangan gunakan file ini sebagai perbaikan tabel global_priv.
USE parkir;

-- Admin dapat menerbitkan/mengubah status kartu; Petugas hanya membaca dan memvalidasi token.
GRANT SELECT, INSERT, UPDATE, DELETE ON parkir.tb_kartu_parkir TO 'admin_parkir'@'localhost';
GRANT SELECT ON parkir.tb_kartu_parkir TO 'petugas_parkir'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON parkir.tb_kartu_parkir TO 'admin_parkir'@'127.0.0.1';
GRANT SELECT ON parkir.tb_kartu_parkir TO 'petugas_parkir'@'127.0.0.1';

-- Petugas perlu dapat merekam id_kartu serta status pembayaran saat proses keluar.
GRANT INSERT ON parkir.tb_transaksi TO 'petugas_parkir'@'localhost';
GRANT INSERT ON parkir.tb_transaksi TO 'petugas_parkir'@'127.0.0.1';
GRANT UPDATE (waktu_keluar,durasi_jam,biaya_total,status,metode_pembayaran,status_pembayaran,waktu_bayar,referensi_pembayaran,id_kartu)
  ON parkir.tb_transaksi TO 'petugas_parkir'@'localhost';
GRANT UPDATE (waktu_keluar,durasi_jam,biaya_total,status,metode_pembayaran,status_pembayaran,waktu_bayar,referensi_pembayaran,id_kartu)
  ON parkir.tb_transaksi TO 'petugas_parkir'@'127.0.0.1';

-- Owner tidak memperoleh hak tambahan. Owner tetap hanya SELECT terhadap view laporan.
GRANT SELECT ON parkir.v_rekap_transaksi TO 'owner_parkir'@'localhost';
GRANT SELECT ON parkir.v_rekap_transaksi TO 'owner_parkir'@'127.0.0.1';

-- Verifikasi:
-- SHOW GRANTS FOR 'admin_parkir'@'localhost';
-- SHOW GRANTS FOR 'petugas_parkir'@'localhost';
-- SHOW GRANTS FOR 'owner_parkir'@'localhost';
-- Owner tidak boleh dapat SELECT dari tb_kartu_parkir atau tb_user.
