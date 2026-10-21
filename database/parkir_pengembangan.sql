-- ParkirSTRD: fitur pengembangan jobsheet (stored procedure kendaraan keluar).
-- Prasyarat: database/parkir.sql sudah berhasil diimpor.
-- Import file ini melalui phpMyAdmin > Import (jangan tempel perintah DELIMITER ke kotak SQL).
-- Setelah berhasil, baru impor database/hak_akses.sql.

USE parkir;

DROP PROCEDURE IF EXISTS sp_parkir_keluar;
DELIMITER //
CREATE PROCEDURE sp_parkir_keluar(
    IN p_id_parkir BIGINT UNSIGNED,
    IN p_id_petugas INT UNSIGNED,
    IN p_waktu_keluar DATETIME
)
BEGIN
    DECLARE v_id_area INT UNSIGNED DEFAULT NULL;
    DECLARE v_masuk DATETIME DEFAULT NULL;
    DECLARE v_status VARCHAR(10) DEFAULT NULL;
    DECLARE v_tarif DECIMAL(10,0) DEFAULT NULL;
    DECLARE v_terisi INT UNSIGNED DEFAULT NULL;
    DECLARE v_durasi INT UNSIGNED DEFAULT 1;
    DECLARE v_not_found TINYINT DEFAULT 0;
    DECLARE v_petugas_valid TINYINT DEFAULT 0;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_not_found = 1;

    START TRANSACTION;

    IF p_waktu_keluar IS NULL THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Waktu keluar wajib diisi';
    END IF;

    SELECT COUNT(*) INTO v_petugas_valid
    FROM tb_user
    WHERE id_user = p_id_petugas
      AND role = 'petugas'
      AND status_aktif = 1;

    IF v_petugas_valid <> 1 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Petugas tidak ditemukan atau tidak aktif';
    END IF;

    SELECT id_area, waktu_masuk, status, tarif_saat_masuk
      INTO v_id_area, v_masuk, v_status, v_tarif
    FROM tb_transaksi
    WHERE id_parkir = p_id_parkir
    FOR UPDATE;

    IF v_not_found = 1 OR v_id_area IS NULL THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Transaksi tidak ditemukan';
    END IF;

    IF v_status <> 'masuk' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Transaksi sudah selesai';
    END IF;

    IF p_waktu_keluar < v_masuk THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Waktu keluar lebih awal dari waktu masuk';
    END IF;

    SET v_not_found = 0;
    SELECT terisi INTO v_terisi
    FROM tb_area_parkir
    WHERE id_area = v_id_area
    FOR UPDATE;

    IF v_not_found = 1 OR v_terisi IS NULL OR v_terisi < 1 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Jumlah kendaraan terisi pada area tidak konsisten';
    END IF;

    SET v_durasi = GREATEST(
        1,
        CEIL(TIMESTAMPDIFF(SECOND, v_masuk, p_waktu_keluar) / 3600)
    );

    UPDATE tb_transaksi
    SET waktu_keluar = p_waktu_keluar,
        durasi_jam = v_durasi,
        biaya_total = v_durasi * v_tarif,
        id_user_keluar = p_id_petugas,
        status = 'keluar'
    WHERE id_parkir = p_id_parkir
      AND status = 'masuk';

    IF ROW_COUNT() <> 1 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Transaksi gagal diperbarui';
    END IF;

    UPDATE tb_area_parkir
    SET terisi = terisi - 1
    WHERE id_area = v_id_area
      AND terisi > 0;

    IF ROW_COUNT() <> 1 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Kapasitas area tidak dapat dikurangi';
    END IF;

    INSERT INTO tb_log_aktivitas (id_user, aktivitas)
    VALUES (
        p_id_petugas,
        CONCAT('Stored procedure memproses kendaraan keluar #', p_id_parkir)
    );

    COMMIT;

    SELECT id_parkir, waktu_keluar, durasi_jam, biaya_total, status
    FROM tb_transaksi
    WHERE id_parkir = p_id_parkir;
END//
DELIMITER ;

-- Verifikasi setelah import:
-- SHOW PROCEDURE STATUS WHERE Db = 'parkir' AND Name = 'sp_parkir_keluar';
-- Untuk pengujian, gunakan ID transaksi yang masih berstatus 'masuk' dan ID user petugas aktif.
-- CALL sp_parkir_keluar(<ID_TRANSAKSI_AKTIF>, <ID_USER_PETUGAS>, NOW());
-- Jangan jalankan prosedur ini untuk transaksi yang sedang diproses melalui aplikasi PHP.
