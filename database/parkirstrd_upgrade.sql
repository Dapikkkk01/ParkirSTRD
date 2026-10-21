-- ParkirSTRD upgrade aman untuk database yang sudah pernah digunakan.
-- Prasyarat: parkir.sql, parkir_pengembangan.sql, fitur_qris.sql, dan fitur_kartu_parkir.sql sudah diterapkan.
-- Backup terlebih dahulu. Skrip ini tidak DROP tabel atau data utama.
USE parkir;

-- Tambahkan kolom petugas yang memproses kendaraan keluar secara idempotent.
SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tb_transaksi' AND COLUMN_NAME='id_user_keluar') = 0,
  'ALTER TABLE tb_transaksi ADD COLUMN id_user_keluar INT UNSIGNED NULL COMMENT ''Petugas yang memproses kendaraan keluar'' AFTER id_user',
  'SELECT 1'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tb_transaksi' AND INDEX_NAME='idx_transaksi_petugas_keluar') = 0,
  'ALTER TABLE tb_transaksi ADD KEY idx_transaksi_petugas_keluar (id_user_keluar)',
  'SELECT 1'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='tb_transaksi' AND CONSTRAINT_NAME='fk_transaksi_user_keluar') = 0,
  'ALTER TABLE tb_transaksi ADD CONSTRAINT fk_transaksi_user_keluar FOREIGN KEY (id_user_keluar) REFERENCES tb_user(id_user) ON UPDATE CASCADE ON DELETE RESTRICT',
  'SELECT 1'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Untuk transaksi historis yang sudah selesai dan belum mencatat petugas keluar,
-- gunakan petugas pencatat masuk sebagai atribusi sementara. Audit manual jika diketahui berbeda.
UPDATE tb_transaksi SET id_user_keluar=id_user WHERE status='keluar' AND id_user_keluar IS NULL;

-- Perbarui view laporan agar Owner dapat membedakan petugas masuk dan petugas keluar.
-- Pastikan fitur_qris.sql sudah dijalankan karena view ini menyertakan metadata pembayaran.
CREATE OR REPLACE VIEW v_rekap_transaksi AS
SELECT t.id_parkir, t.id_kendaraan, t.id_kartu, k.plat_nomor, k.jenis_kendaraan,
       k.warna, k.pemilik, t.waktu_masuk, t.waktu_keluar, t.durasi_jam,
       t.tarif_saat_masuk, t.biaya_total, t.status, t.metode_pembayaran,
       t.status_pembayaran, t.waktu_bayar, t.referensi_pembayaran,
       a.nama_area, t.id_area,
       t.id_user AS id_user_petugas_masuk, u.nama_lengkap AS nama_petugas,
       u.nama_lengkap AS nama_petugas_masuk,
       t.id_user_keluar AS id_user_petugas_keluar, ux.nama_lengkap AS nama_petugas_keluar
FROM tb_transaksi t
JOIN tb_kendaraan k ON k.id_kendaraan=t.id_kendaraan
JOIN tb_area_parkir a ON a.id_area=t.id_area
JOIN tb_user u ON u.id_user=t.id_user
LEFT JOIN tb_user ux ON ux.id_user=t.id_user_keluar;

-- Verifikasi setelah upgrade:
-- SHOW COLUMNS FROM tb_transaksi LIKE 'id_user_keluar';
-- SELECT id_parkir, nama_petugas_masuk, nama_petugas_keluar FROM v_rekap_transaksi LIMIT 10;
