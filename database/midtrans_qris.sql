-- ParkirSTRD: integrasi QRIS dinamis Midtrans Core API.
-- Prasyarat: database parkir, fitur_qris.sql, fitur_kartu_parkir.sql, parkirstrd_upgrade.sql.
-- Buat backup database sebelum migrasi. Tidak menghapus transaksi lama.
USE parkir;

ALTER TABLE tb_transaksi
  MODIFY COLUMN status_pembayaran ENUM('belum_bayar','menunggu_verifikasi','lunas','gagal','kedaluwarsa') NOT NULL DEFAULT 'belum_bayar';

CREATE TABLE IF NOT EXISTS tb_midtrans_payment (
  id_midtrans_payment BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_parkir BIGINT UNSIGNED NOT NULL,
  order_id VARCHAR(50) NOT NULL,
  midtrans_transaction_id VARCHAR(100) NULL,
  gross_amount DECIMAL(12,2) NOT NULL,
  transaction_status VARCHAR(30) NOT NULL DEFAULT 'creating',
  payment_type VARCHAR(30) NULL,
  qr_code_url VARCHAR(700) NULL,
  expires_at DATETIME NULL,
  response_status_code VARCHAR(10) NULL,
  last_status_check DATETIME NULL,
  last_notification_at DATETIME NULL,
  last_error VARCHAR(250) NULL,
  created_by_user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id_midtrans_payment),
  UNIQUE KEY uq_midtrans_order_id (order_id),
  KEY idx_midtrans_tx (id_parkir, id_midtrans_payment),
  KEY idx_midtrans_status_expiry (transaction_status, expires_at),
  CONSTRAINT fk_midtrans_parkir FOREIGN KEY (id_parkir) REFERENCES tb_transaksi(id_parkir) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_midtrans_creator FOREIGN KEY (created_by_user_id) REFERENCES tb_user(id_user) ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT chk_midtrans_amount CHECK (gross_amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tb_midtrans_webhook_log (
  id_webhook_log BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id VARCHAR(50) NULL,
  provider_status VARCHAR(30) NULL,
  signature_valid TINYINT(1) NOT NULL DEFAULT 0,
  http_result SMALLINT UNSIGNED NOT NULL DEFAULT 200,
  received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_webhook_log),
  KEY idx_midtrans_webhook_order (order_id),
  KEY idx_midtrans_webhook_received (received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migrasi data lama yang status QRIS-nya masih pending tetap belum lunas.
-- Tidak ada transaksi yang otomatis ditandai lunas oleh migrasi ini.
