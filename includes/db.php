<?php
declare(strict_types=1);
function db_config(): array { static $cfg = null; if ($cfg === null) $cfg = require __DIR__ . '/../config/database.php'; return $cfg; }
function pdo_connect(array $account): PDO {
    $c = db_config();
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $c['host'], $c['port'], $c['database'], $c['charset']);
    $pdo = new PDO($dsn, $account['username'], $account['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_STRINGIFY_FETCHES => false,
    ]);
    // Selaraskan NOW()/CURDATE() dengan zona waktu aplikasi.
    $pdo->exec("SET time_zone = '+07:00'");
    return $pdo;
}
function db_login(): PDO { static $pdo = null; if (!$pdo) $pdo = pdo_connect(db_config()['login']); return $pdo; }
function db(): PDO {
    static $connections = [];
    $role = $_SESSION['role'] ?? null;
    if (!in_array($role, ['admin','petugas','owner'], true)) throw new RuntimeException('Sesi role tidak valid. Silakan masuk kembali.');
    if (!isset($connections[$role])) $connections[$role] = pdo_connect(db_config()['roles'][$role]);
    return $connections[$role];
}

/** Koneksi untuk webhook pembayaran yang dipanggil server provider tanpa sesi pengguna.
 * Isi config/database.php dengan akun system yang hanya memiliki hak DML minimum
 * pada tb_transaksi dan tabel integrasi pembayaran. Untuk demo XAMPP fallback ke login.
 */
function db_system(): PDO {
    static $pdo = null;
    if (!$pdo) {
        $c = db_config();
        $account = $c['system'] ?? $c['login'];
        $pdo = pdo_connect($account);
    }
    return $pdo;
}
