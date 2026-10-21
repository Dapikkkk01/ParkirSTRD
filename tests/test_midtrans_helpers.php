<?php
declare(strict_types=1);
putenv('MIDTRANS_ENV=sandbox');
putenv('MIDTRANS_SERVER_KEY=');
require_once __DIR__ . '/../includes/midtrans.php';
$tests = 0;
$assert = static function (bool $condition, string $message) use (&$tests): void { $tests++; if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); } };
$key = 'test-server-key-only';
$sig = midtrans_calculate_signature('PSTRD-1001-261021100000-a1b2c3', '200', '5000.00', $key);
$assert(strlen($sig) === 128, 'SHA-512 signature must have 128 hex characters');
$assert(!midtrans_is_enabled(), 'Midtrans must remain disabled without an explicit Server Key');
$assert(hash_equals($sig, midtrans_calculate_signature('PSTRD-1001-261021100000-a1b2c3', '200', '5000.00', $key)), 'signature calculation is deterministic');
$assert(!hash_equals($sig, midtrans_calculate_signature('PSTRD-1001-261021100000-a1b2c3', '201', '5000.00', $key)), 'signature changes when status code changes');
$assert(midtrans_amount_equal('5000.00', 5000), 'IDR amount comparison accepts decimals');
$assert(!midtrans_amount_equal('5001.00', 5000), 'IDR amount comparison rejects mismatches');
$assert(midtrans_validate_action_url('https://api.sandbox.midtrans.com/v2/qris/example/qr-code'), 'sandbox QR action URL accepted in sandbox configuration');
$assert(!midtrans_validate_action_url('http://api.sandbox.midtrans.com/v2/qris/example/qr-code'), 'HTTP QR action URL rejected');
$assert(!midtrans_validate_action_url('https://evil.example/qris'), 'untrusted QR host rejected');
echo "OK: {$tests} Midtrans helper checks passed. No live Midtrans request was made.\n";
