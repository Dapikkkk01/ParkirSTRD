-- ParkirSTRD: kartu parkir pelanggan umum dengan barcode CODE128.
-- Prasyarat: `parkir.sql`, `parkir_pengembangan.sql`, dan `fitur_qris.sql` sudah diimpor.
-- Backup database sebelum migrasi. Skrip ini tidak DROP data/tabel utama.
USE parkir;

CREATE TABLE IF NOT EXISTS tb_kartu_parkir (
  id_kartu INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_kendaraan INT UNSIGNED NOT NULL,
  kode_kartu VARCHAR(48) NOT NULL,
  status ENUM('aktif','nonaktif') NOT NULL DEFAULT 'aktif',
  dibuat_oleh INT UNSIGNED NOT NULL,
  waktu_dibuat DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  waktu_dinonaktifkan DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (id_kartu),
  UNIQUE KEY uq_kartu_kode (kode_kartu),
  KEY idx_kartu_kendaraan_status (id_kendaraan,status),
  CONSTRAINT fk_kartu_kendaraan FOREIGN KEY (id_kendaraan)
    REFERENCES tb_kendaraan(id_kendaraan) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_kartu_pembuat FOREIGN KEY (dibuat_oleh)
    REFERENCES tb_user(id_user) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tambahkan kolom transaksi secara idempotent dengan pengecekan metadata.
SET @pp_kartu_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tb_transaksi' AND COLUMN_NAME='id_kartu') = 0,
  'ALTER TABLE tb_transaksi ADD COLUMN id_kartu INT UNSIGNED NULL AFTER id_kendaraan',
  'SELECT 1'
);
PREPARE pp_kartu_stmt FROM @pp_kartu_sql;
EXECUTE pp_kartu_stmt;
DEALLOCATE PREPARE pp_kartu_stmt;

SET @pp_kartu_sql = IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tb_transaksi' AND INDEX_NAME='idx_transaksi_kartu') = 0,
  'ALTER TABLE tb_transaksi ADD KEY idx_transaksi_kartu (id_kartu)',
  'SELECT 1'
);
PREPARE pp_kartu_stmt FROM @pp_kartu_sql;
EXECUTE pp_kartu_stmt;
DEALLOCATE PREPARE pp_kartu_stmt;

SET @pp_kartu_sql = IF(
  (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='tb_transaksi' AND CONSTRAINT_NAME='fk_transaksi_kartu') = 0,
  'ALTER TABLE tb_transaksi ADD CONSTRAINT fk_transaksi_kartu FOREIGN KEY (id_kartu) REFERENCES tb_kartu_parkir(id_kartu) ON UPDATE CASCADE ON DELETE RESTRICT',
  'SELECT 1'
);
PREPARE pp_kartu_stmt FROM @pp_kartu_sql;
EXECUTE pp_kartu_stmt;
DEALLOCATE PREPARE pp_kartu_stmt;

-- Verifikasi:
-- SHOW TABLES LIKE 'tb_kartu_parkir';
-- SHOW COLUMNS FROM tb_transaksi LIKE 'id_kartu';
-- SELECT * FROM tb_kartu_parkir;
-- Hak akses akun DB terpisah (jika digunakan) ada di database/hak_akses_kartu.sql.
