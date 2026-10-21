-- ParkirSTRD schema + seed demo. Cocok untuk MySQL 8.0 / MariaDB 10.5+ pada XAMPP.
-- Import sebagai user administratif melalui phpMyAdmin. Lihat README.md.
CREATE DATABASE IF NOT EXISTS parkir CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE parkir;
SET NAMES utf8mb4;
SET time_zone = '+07:00';
SET FOREIGN_KEY_CHECKS=0;
DROP VIEW IF EXISTS v_rekap_transaksi;
DROP TABLE IF EXISTS tb_log_aktivitas;
DROP TABLE IF EXISTS tb_transaksi;
DROP TABLE IF EXISTS tb_area_parkir;
DROP TABLE IF EXISTS tb_tarif;
DROP TABLE IF EXISTS tb_kendaraan;
DROP TABLE IF EXISTS tb_user;
SET FOREIGN_KEY_CHECKS=1;

CREATE TABLE tb_user (
  id_user INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nama_lengkap VARCHAR(50) NOT NULL,
  username VARCHAR(50) NOT NULL,
  password VARCHAR(255) NOT NULL,
  role ENUM('admin','petugas','owner') NOT NULL,
  status_aktif TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id_user), UNIQUE KEY uq_tb_user_username (username),
  KEY idx_tb_user_role_status (role,status_aktif),
  CONSTRAINT chk_tb_user_status CHECK (status_aktif IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tb_kendaraan (
  id_kendaraan INT UNSIGNED NOT NULL AUTO_INCREMENT,
  plat_nomor VARCHAR(15) NOT NULL,
  jenis_kendaraan ENUM('motor','mobil','lainnya') NOT NULL,
  warna VARCHAR(20) NOT NULL,
  pemilik VARCHAR(100) NOT NULL,
  id_user INT UNSIGNED NOT NULL COMMENT 'Admin yang mendaftarkan kendaraan',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_kendaraan), UNIQUE KEY uq_tb_kendaraan_plat (plat_nomor),
  KEY idx_tb_kendaraan_jenis (jenis_kendaraan), KEY idx_tb_kendaraan_pemilik (pemilik),
  CONSTRAINT fk_kendaraan_user FOREIGN KEY (id_user) REFERENCES tb_user(id_user) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tb_tarif (
  id_tarif INT UNSIGNED NOT NULL AUTO_INCREMENT,
  jenis_kendaraan ENUM('motor','mobil','lainnya') NOT NULL,
  tarif_per_jam DECIMAL(10,0) UNSIGNED NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id_tarif), UNIQUE KEY uq_tb_tarif_jenis (jenis_kendaraan),
  CONSTRAINT chk_tb_tarif_nonneg CHECK (tarif_per_jam >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tb_area_parkir (
  id_area INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nama_area VARCHAR(50) NOT NULL,
  kapasitas INT UNSIGNED NOT NULL,
  terisi INT UNSIGNED NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_area), UNIQUE KEY uq_tb_area_nama (nama_area),
  CONSTRAINT chk_tb_area_capacity CHECK (kapasitas > 0),
  CONSTRAINT chk_tb_area_occupied CHECK (terisi <= kapasitas)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tb_transaksi (
  id_parkir BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_kendaraan INT UNSIGNED NOT NULL,
  waktu_masuk DATETIME NOT NULL,
  waktu_keluar DATETIME NULL,
  id_tarif INT UNSIGNED NOT NULL,
  tarif_saat_masuk DECIMAL(10,0) UNSIGNED NOT NULL,
  durasi_jam INT UNSIGNED NULL,
  biaya_total DECIMAL(10,0) UNSIGNED NULL,
  status ENUM('masuk','keluar') NOT NULL DEFAULT 'masuk',
  id_user INT UNSIGNED NOT NULL COMMENT 'Petugas yang memproses kendaraan masuk',
  id_user_keluar INT UNSIGNED NULL COMMENT 'Petugas yang memproses kendaraan keluar',
  id_area INT UNSIGNED NOT NULL,
  PRIMARY KEY (id_parkir),
  KEY idx_transaksi_status_area (status,id_area), KEY idx_transaksi_masuk (waktu_masuk),
  KEY idx_transaksi_keluar (waktu_keluar), KEY idx_transaksi_kendaraan (id_kendaraan),
  KEY idx_transaksi_tarif (id_tarif), KEY idx_transaksi_petugas (id_user), KEY idx_transaksi_petugas_keluar (id_user_keluar),
  CONSTRAINT fk_transaksi_kendaraan FOREIGN KEY (id_kendaraan) REFERENCES tb_kendaraan(id_kendaraan) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_transaksi_tarif FOREIGN KEY (id_tarif) REFERENCES tb_tarif(id_tarif) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_transaksi_user FOREIGN KEY (id_user) REFERENCES tb_user(id_user) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_transaksi_user_keluar FOREIGN KEY (id_user_keluar) REFERENCES tb_user(id_user) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_transaksi_area FOREIGN KEY (id_area) REFERENCES tb_area_parkir(id_area) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT chk_transaksi_snapshot CHECK (tarif_saat_masuk >= 0),
  CONSTRAINT chk_transaksi_finished CHECK ((status='masuk' AND waktu_keluar IS NULL AND durasi_jam IS NULL AND biaya_total IS NULL) OR (status='keluar' AND waktu_keluar IS NOT NULL AND durasi_jam IS NOT NULL AND biaya_total IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tb_log_aktivitas (
  id_log BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_user INT UNSIGNED NOT NULL,
  aktivitas VARCHAR(250) NOT NULL,
  waktu_aktivitas DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_log), KEY idx_log_waktu (waktu_aktivitas), KEY idx_log_user (id_user),
  CONSTRAINT fk_log_user FOREIGN KEY (id_user) REFERENCES tb_user(id_user) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Password akun demo disimpan sebagai hash bcrypt, bukan plaintext.
INSERT INTO tb_user (id_user,nama_lengkap,username,password,role,status_aktif) VALUES
(1,'Administrator ParkirSTRD','admin','$2y$12$kn4z9ub8r67XQh0OZzDSCesNMnX0m2/qB2wCu2A1M6x2g6cf82k1e','admin',1),
(2,'Petugas Demo','petugas','$2y$12$BmC2jlG5PRHqegIQRrsqE.7iwJENSwOWnN.TSBFFp5M6cK296d6sC','petugas',1),
(3,'Owner Demo','owner','$2y$12$ssiJhUX5EwjRJLsl.JccX.jAdhm0wRuOJEVwYsTbQpGu6E2Ngw2Ge','owner',1);

INSERT INTO tb_tarif (id_tarif,jenis_kendaraan,tarif_per_jam) VALUES (1,'motor',2000),(2,'mobil',5000),(3,'lainnya',7000);
INSERT INTO tb_area_parkir (id_area,nama_area,kapasitas,terisi) VALUES (1,'Area A - Motor & Umum',20,1),(2,'Area B - Mobil',10,1);
INSERT INTO tb_kendaraan (id_kendaraan,plat_nomor,jenis_kendaraan,warna,pemilik,id_user) VALUES
(1,'M 1234 AB','motor','Hitam','Andi Pratama',1),
(2,'M 5678 CD','mobil','Putih','Budi Santoso',1),
(3,'M 9012 EF','motor','Merah','Citra Lestari',1),
(4,'L 2233 GH','mobil','Abu-abu','Dimas Saputra',1),
(5,'S 4455 JK','lainnya','Biru','Eka Wulandari',1);
INSERT INTO tb_transaksi (id_parkir,id_kendaraan,waktu_masuk,waktu_keluar,id_tarif,tarif_saat_masuk,durasi_jam,biaya_total,status,id_user,id_area) VALUES
(1001,1,DATE_SUB(NOW(),INTERVAL 5 HOUR),DATE_SUB(NOW(),INTERVAL 2 HOUR),1,2000,3,6000,'keluar',2,1),
(1002,2,DATE_SUB(NOW(),INTERVAL 8 HOUR),DATE_SUB(NOW(),INTERVAL 3 HOUR),2,5000,5,25000,'keluar',2,2),
(1003,3,DATE_SUB(NOW(),INTERVAL 4 DAY),DATE_SUB(NOW(),INTERVAL 4 DAY) + INTERVAL 2 HOUR,1,2000,2,4000,'keluar',2,1),
(1004,4,DATE_SUB(NOW(),INTERVAL 45 MINUTE),NULL,2,5000,NULL,NULL,'masuk',2,1),
(1005,5,DATE_SUB(NOW(),INTERVAL 90 MINUTE),NULL,3,7000,NULL,NULL,'masuk',2,2);
INSERT INTO tb_log_aktivitas (id_user,aktivitas,waktu_aktivitas) VALUES
(1,'Data demo ParkirSTRD berhasil diinisialisasi',NOW()),(2,'Transaksi demo #1001 diselesaikan',DATE_SUB(NOW(),INTERVAL 2 HOUR)),(2,'Kendaraan demo #1004 masuk area parkir',DATE_SUB(NOW(),INTERVAL 45 MINUTE));

CREATE OR REPLACE VIEW v_rekap_transaksi AS
SELECT t.id_parkir, k.plat_nomor, k.jenis_kendaraan, k.warna, k.pemilik,
       t.waktu_masuk, t.waktu_keluar, t.durasi_jam, t.tarif_saat_masuk,
       t.biaya_total, t.status, a.nama_area, u.nama_lengkap AS nama_petugas,
       u.nama_lengkap AS nama_petugas_masuk, t.id_user AS id_user_petugas_masuk,
       ux.nama_lengkap AS nama_petugas_keluar, t.id_user_keluar AS id_user_petugas_keluar,
       t.id_area, t.id_kendaraan
FROM tb_transaksi t
JOIN tb_kendaraan k ON k.id_kendaraan=t.id_kendaraan
JOIN tb_area_parkir a ON a.id_area=t.id_area
JOIN tb_user u ON u.id_user=t.id_user
LEFT JOIN tb_user ux ON ux.id_user=t.id_user_keluar;

DELIMITER $$
CREATE TRIGGER trg_tarif_nonneg_bi BEFORE INSERT ON tb_tarif FOR EACH ROW
BEGIN
  IF NEW.tarif_per_jam < 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Tarif per jam tidak boleh negatif'; END IF;
END$$
CREATE TRIGGER trg_tarif_nonneg_bu BEFORE UPDATE ON tb_tarif FOR EACH ROW
BEGIN
  IF NEW.tarif_per_jam < 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Tarif per jam tidak boleh negatif'; END IF;
END$$
CREATE TRIGGER trg_area_capacity_bi BEFORE INSERT ON tb_area_parkir FOR EACH ROW
BEGIN
  IF NEW.kapasitas < 1 OR NEW.terisi > NEW.kapasitas THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Kapasitas area tidak valid'; END IF;
END$$
CREATE TRIGGER trg_area_capacity_bu BEFORE UPDATE ON tb_area_parkir FOR EACH ROW
BEGIN
  IF NEW.kapasitas < 1 OR NEW.terisi > NEW.kapasitas THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Kapasitas area tidak boleh di bawah jumlah terisi'; END IF;
END$$
DELIMITER ;
