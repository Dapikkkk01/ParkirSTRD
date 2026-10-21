<?php
declare(strict_types=1);
/** Konfigurasi Midtrans. Jangan simpan Server Key ke repository/ZIP. */
$localPath = __DIR__ . '/payment.local.php';
$local = is_file($localPath) ? require $localPath : [];
if (!is_array($local)) $local = [];
$environment = strtolower(trim((string)(getenv('MIDTRANS_ENV') ?: ($local['environment'] ?? 'sandbox'))));
if (!in_array($environment, ['sandbox', 'production'], true)) $environment = 'sandbox';
$serverKey = trim((string)(getenv('MIDTRANS_SERVER_KEY') ?: ($local['server_key'] ?? '')));
// Prevent accidentally treating the untouched example placeholder as a real key.
if (str_starts_with(strtoupper($serverKey), 'ISI_SERVER_KEY') || str_contains(strtoupper($serverKey), 'GANTI_PASSWORD')) $serverKey = '';
return [
    'provider' => 'midtrans',
    'enabled' => $serverKey !== '' && !str_contains(strtoupper($serverKey), 'PASTE_YOUR') && !str_contains(strtoupper($serverKey), 'PLACEHOLDER'),
    'environment' => $environment,
    'server_key' => $serverKey,
    'api_base_url' => $environment === 'production' ? 'https://api.midtrans.com' : 'https://api.sandbox.midtrans.com',
    'merchant_name' => 'ParkirSTRD',
    'qris_acquirer' => trim((string)($local['qris_acquirer'] ?? '')),
    'expires_minutes' => max(5, min(60, (int)($local['expires_minutes'] ?? 15))),
    // Pembayaran QRIS Midtrans tidak boleh ditandai lunas melalui tombol manual.
    'manual_verification' => false,
    'qris_image' => __DIR__ . '/../assets/img/qris-merchant.png',
];
