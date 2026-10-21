<?php
declare(strict_types=1);
require_once __DIR__.'/../config/app.php';
require_once __DIR__.'/security.php';
start_secure_session();
require_once __DIR__.'/db.php';
require_once __DIR__.'/auth.php';

// Jangan pernah menampilkan detail exception/database kepada pengguna.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
set_exception_handler(static function (Throwable $e): void {
    error_log('[ParkirSTRD uncaught] ' . $e->__toString());
    if (!headers_sent()) {
        http_response_code(500);
        require __DIR__ . '/500.php';
    } else {
        echo '<div class="alert alert-error">Terjadi kesalahan sistem. Coba kembali atau hubungi administrator.</div>';
    }
    exit;
});
