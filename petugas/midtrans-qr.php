<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/init.php';
require_role('petugas');
require_once __DIR__ . '/../includes/midtrans.php';
try {
    $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if (!$id || $id < 1) throw new InvalidArgumentException('QR tidak valid.');
    $image = midtrans_fetch_qr_image(db(), (int)$id, (int)current_user()['id']);
    header('Content-Type: ' . $image['content_type']);
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    echo $image['bytes'];
} catch (Throwable $e) {
    error_log('[ParkirSTRD Midtrans QR image] ' . $e->getMessage());
    http_response_code($e instanceof InvalidArgumentException ? 404 : 502);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'QR pembayaran belum tersedia. Muat ulang halaman atau buat ulang QR.';
}
