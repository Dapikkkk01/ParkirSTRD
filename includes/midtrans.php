<?php
declare(strict_types=1);

if (!class_exists('MidtransApiException', false)) {
    class MidtransApiException extends RuntimeException {
        public function __construct(string $message, public readonly int $httpStatus, ?Throwable $previous = null) { parent::__construct($message, 0, $previous); }
    }
}

function midtrans_short_text(string $value, int $length): string {
    return function_exists('mb_substr') ? mb_substr($value, 0, $length) : substr($value, 0, $length);
}

function midtrans_config(): array {
    static $cfg = null;
    if ($cfg === null) $cfg = require __DIR__ . '/../config/payment.php';
    return $cfg;
}

function midtrans_is_enabled(): bool {
    $c = midtrans_config();
    return !empty($c['enabled']) && trim((string)($c['server_key'] ?? '')) !== ''
        && !str_starts_with(strtoupper(trim((string)($c['server_key'] ?? ''))), 'ISI_SERVER_KEY')
        && !str_contains(strtoupper(trim((string)($c['server_key'] ?? ''))), 'PLACEHOLDER');
}

function midtrans_api_request(string $method, string $path, ?array $payload = null): array {
    $cfg = midtrans_config();
    if (!midtrans_is_enabled()) throw new RuntimeException('Midtrans belum dikonfigurasi. Salin config/payment.local.example.php menjadi config/payment.local.php dan masukkan Server Key Sandbox.');
    if (!function_exists('curl_init')) throw new RuntimeException('Ekstensi cURL PHP belum aktif. Aktifkan extension=curl pada php.ini XAMPP lalu restart Apache.');
    $method = strtoupper($method);
    if (!in_array($method, ['GET','POST'], true)) throw new InvalidArgumentException('Metode API tidak didukung.');
    if (!str_starts_with($path, '/')) throw new InvalidArgumentException('Path API tidak valid.');
    $url = rtrim((string)$cfg['api_base_url'], '/') . $path;
    $ch = curl_init($url);
    $headers = ['Accept: application/json', 'Content-Type: application/json', 'Authorization: Basic ' . base64_encode((string)$cfg['server_key'] . ':')];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_USERAGENT => 'ParkirSTRD-PHP/1.0',
    ]);
    if ($payload !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    $raw = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false) {
        error_log('[ParkirSTRD Midtrans] cURL error: ' . $curlError);
        throw new RuntimeException('Tidak dapat menghubungi server Midtrans. Periksa internet, SSL, dan log server.');
    }
    $decoded = json_decode((string)$raw, true);
    if (!is_array($decoded)) {
        error_log('[ParkirSTRD Midtrans] Respons non-JSON, HTTP ' . $httpCode);
        throw new RuntimeException('Respons dari Midtrans tidak dapat dibaca. Periksa log server.');
    }
    if ($httpCode < 200 || $httpCode >= 300) {
        $message = preg_replace('/[^\pL\pN\s.,:;_()\/-]/u', '', (string)($decoded['status_message'] ?? ''));
        error_log('[ParkirSTRD Midtrans] API HTTP ' . $httpCode . ' code=' . (string)($decoded['status_code'] ?? '') . ' message=' . midtrans_short_text($message, 250));
        throw new MidtransApiException('Midtrans menolak permintaan (HTTP ' . $httpCode . '). Periksa kredensial, status merchant dan log server.', $httpCode);
    }
    return ['http_code'=>$httpCode, 'body'=>$decoded];
}


function midtrans_calculate_signature(string $orderId, string $statusCode, string $grossAmount, string $serverKey): string {
    return hash('sha512', $orderId . $statusCode . $grossAmount . $serverKey);
}

function midtrans_validate_action_url(string $url): bool {
    $cfg = midtrans_config();
    $host = strtolower((string)parse_url($url, PHP_URL_HOST));
    $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
    $expected = $cfg['environment'] === 'production' ? 'api.midtrans.com' : 'api.sandbox.midtrans.com';
    return $scheme === 'https' && $host === $expected && str_starts_with((string)parse_url($url, PHP_URL_PATH), '/');
}

/** Recover an interrupted QR creation by querying Midtrans first. Never create a second charge while the old order's state is unknown. */
function midtrans_recover_existing_payment(PDO $pdo, array $existing, int $transactionId): ?array {
    $orderId = (string)($existing['order_id'] ?? '');
    if ($orderId === '') throw new RuntimeException('Order QRIS lama tidak memiliki Order ID; hubungi Admin sebelum membuat pembayaran baru.');
    try {
        $remote = midtrans_api_request('GET', '/v2/' . rawurlencode($orderId) . '/status')['body'];
    } catch (MidtransApiException $e) {
        if ($e->httpStatus === 404) {
            $upd = $pdo->prepare("UPDATE tb_midtrans_payment SET transaction_status='failure',last_error='Status API menyatakan Order ID tidak ditemukan; aman untuk membuat order baru.',updated_at=NOW() WHERE id_midtrans_payment=? AND transaction_status IN ('creating','pending')");
            $upd->execute([(int)$existing['id_midtrans_payment']]);
            return null;
        }
        throw new RuntimeException('Status pembayaran lama belum dapat dipastikan. Jangan membuat QR baru untuk menghindari pembayaran ganda; coba periksa lagi nanti.', 0, $e);
    }
    if ((string)($remote['order_id'] ?? '') !== $orderId || !midtrans_amount_equal($remote['gross_amount'] ?? null, $existing['gross_amount']) || strtolower((string)($remote['payment_type'] ?? '')) !== 'qris') {
        throw new RuntimeException('Status order lama dari Midtrans tidak cocok dengan data lokal. Jangan membuat QR baru sebelum diperiksa Admin.');
    }
    $status = strtolower((string)($remote['transaction_status'] ?? 'unknown'));
    if (in_array($status, ['settlement','expire','deny','cancel','failure'], true)) {
        midtrans_apply_remote_status($pdo, $remote);
        if ($status === 'settlement') {
            $get = $pdo->prepare('SELECT * FROM tb_midtrans_payment WHERE id_midtrans_payment=?');
            $get->execute([(int)$existing['id_midtrans_payment']]);
            return $get->fetch() ?: $existing;
        }
        return null;
    }
    if ($status !== 'pending') throw new RuntimeException('Status order lama tidak dikenal: ' . $status . '. Jangan membuat QR baru sebelum status diperiksa Admin.');
    $providerTransactionId = trim((string)($remote['transaction_id'] ?? $existing['midtrans_transaction_id'] ?? ''));
    if ($providerTransactionId === '') throw new RuntimeException('Midtrans belum mengembalikan Transaction ID untuk order lama. Coba periksa lagi nanti.');
    $cfg = midtrans_config();
    $qrUrl = rtrim((string)$cfg['api_base_url'], '/') . '/v2/qris/' . rawurlencode($providerTransactionId) . '/qr-code';
    if (!midtrans_validate_action_url($qrUrl)) throw new RuntimeException('URL QR hasil pemulihan tidak valid.');
    $remoteExpiry = !empty($remote['expiry_time']) ? strtotime((string)$remote['expiry_time']) : false;
    $expiry = $remoteExpiry !== false ? date('Y-m-d H:i:s', $remoteExpiry) : (string)($existing['expires_at'] ?? date('Y-m-d H:i:s', time()+900));
    $upd = $pdo->prepare("UPDATE tb_midtrans_payment SET midtrans_transaction_id=?,transaction_status='pending',payment_type='qris',qr_code_url=?,expires_at=?,response_status_code=?,last_status_check=NOW(),last_error=NULL,updated_at=NOW() WHERE id_midtrans_payment=?");
    $upd->execute([$providerTransactionId,$qrUrl,$expiry,(string)($remote['status_code'] ?? ''),(int)$existing['id_midtrans_payment']]);
    $tx = $pdo->prepare("UPDATE tb_transaksi SET status_pembayaran='menunggu_verifikasi',referensi_pembayaran=? WHERE id_parkir=? AND status_pembayaran<>'lunas'");
    $tx->execute([$orderId,$transactionId]);
    $get = $pdo->prepare('SELECT * FROM tb_midtrans_payment WHERE id_midtrans_payment=?');
    $get->execute([(int)$existing['id_midtrans_payment']]);
    return $get->fetch() ?: $existing;
}

function midtrans_create_qris_for_transaction(PDO $pdo, int $transactionId, int $staffId): array {
    if (!midtrans_is_enabled()) throw new RuntimeException('API Midtrans belum dikonfigurasi. Transaksi parkir tetap tercatat, tetapi QR belum dibuat. Siapkan Server Key Sandbox terlebih dahulu.');
    if (!function_exists('curl_init')) throw new RuntimeException('Ekstensi cURL PHP belum aktif. Aktifkan extension=curl pada php.ini XAMPP lalu restart Apache.');
    if ($pdo->inTransaction()) throw new RuntimeException('Pembuatan pembayaran harus dilakukan setelah transaksi parkir disimpan.');

    $cfg = midtrans_config();
    $acquirer = trim((string)($cfg['qris_acquirer'] ?? ''));
    if ($acquirer !== '' && !in_array($acquirer, ['gopay','airpay_shopee'], true)) {
        throw new RuntimeException('Acquirer QRIS tidak valid pada config/payment.local.php. Kosongkan atau pilih gopay/airpay_shopee.');
    }

    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT id_parkir,status,metode_pembayaran,status_pembayaran,biaya_total,id_user_keluar FROM tb_transaksi WHERE id_parkir=? FOR UPDATE");
        $st->execute([$transactionId]);
        $tx = $st->fetch();
        if (!$tx || $tx['status'] !== 'keluar' || $tx['metode_pembayaran'] !== 'qris' || (int)$tx['id_user_keluar'] !== $staffId) {
            throw new InvalidArgumentException('Transaksi QRIS tidak ditemukan atau bukan transaksi keluar milik Anda.');
        }
        if ($tx['status_pembayaran'] === 'lunas') throw new InvalidArgumentException('Pembayaran sudah lunas. QR baru tidak diperlukan.');

        $lastSt = $pdo->prepare('SELECT * FROM tb_midtrans_payment WHERE id_parkir=? ORDER BY id_midtrans_payment DESC LIMIT 1 FOR UPDATE');
        $lastSt->execute([$transactionId]);
        $last = $lastSt->fetch();
        if ($last && in_array((string)$last['transaction_status'], ['creating','pending'], true)) {
            $state = (string)$last['transaction_status'];
            $expiryOk = empty($last['expires_at']) || strtotime((string)$last['expires_at']) > time();
            $createdAt = strtotime((string)($last['created_at'] ?? 'now')) ?: time();
            $staleCreating = $state === 'creating' && $createdAt < time() - 120;
            if ($state === 'creating' && !$staleCreating) {
                $pdo->commit();
                throw new RuntimeException('Pembayaran QRIS sedang dibuat. Muat ulang beberapa saat lagi.');
            }
            if ($state === 'pending' && $expiryOk && !empty($last['qr_code_url'])) {
                $pdo->commit();
                return $last;
            }
            // Do not create a second charge until an expired/interrupted order is reconciled with Midtrans.
            $pdo->commit();
            $recovered = midtrans_recover_existing_payment($pdo, $last, $transactionId);
            if ($recovered !== null) return $recovered;
            return midtrans_create_qris_for_transaction($pdo, $transactionId, $staffId);
        }

        $amount = (int)round((float)$tx['biaya_total']);
        if ($amount < 1) throw new InvalidArgumentException('Nominal QRIS harus lebih besar dari nol.');
        $orderId = 'PSTRD-' . $transactionId . '-' . date('ymdHis') . '-' . bin2hex(random_bytes(3));
        $expiryMinutes = (int)$cfg['expires_minutes'];
        $exp = date('Y-m-d H:i:s', time() + ($expiryMinutes * 60));
        $ins = $pdo->prepare("INSERT INTO tb_midtrans_payment(id_parkir,order_id,gross_amount,transaction_status,created_by_user_id,expires_at) VALUES(?,?,?,'creating',?,?)");
        $ins->execute([$transactionId,$orderId,$amount,$staffId,$exp]);
        $paymentId = (int)$pdo->lastInsertId();
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    $payload = [
        'payment_type' => 'qris',
        'transaction_details' => ['order_id'=>$orderId, 'gross_amount'=>$amount],
        'item_details' => [[ 'id'=>'parkir-'.$transactionId, 'price'=>$amount, 'quantity'=>1, 'name'=>'Biaya Parkir ParkirSTRD #'.$transactionId ]],
        'custom_expiry' => ['expiry_duration'=>$expiryMinutes, 'unit'=>'minute'],
    ];
    if ($acquirer !== '') $payload['qris'] = ['acquirer'=>$acquirer];

    try {
        $response = midtrans_api_request('POST', '/v2/charge', $payload);
        $data = $response['body'];
    } catch (Throwable $e) {
        $safe = midtrans_short_text(preg_replace('/[\r\n\t]+/', ' ', $e->getMessage()) ?? 'Error', 240);
        // Only a clear client-side rejection is treated as failed. Timeouts/server errors keep the order recoverable
        // because Midtrans may have accepted the charge even if PHP did not receive the response.
        $definiteReject = $e instanceof MidtransApiException && $e->httpStatus >= 400 && $e->httpStatus < 500 && !in_array($e->httpStatus, [408,409,429], true);
        try {
            if ($definiteReject) {
                $upd = $pdo->prepare("UPDATE tb_midtrans_payment SET transaction_status='failure',last_error=?,updated_at=NOW() WHERE id_midtrans_payment=?");
                $upd->execute([$safe,$paymentId]);
            } else {
                $upd = $pdo->prepare("UPDATE tb_midtrans_payment SET last_error=?,updated_at=NOW() WHERE id_midtrans_payment=? AND transaction_status='creating'");
                $upd->execute([$safe,$paymentId]);
            }
        } catch (Throwable $storeError) {
            error_log('[ParkirSTRD Midtrans] Unable to persist charge error for payment row ' . $paymentId);
        }
        if (!$definiteReject) throw new RuntimeException('Respons pembuatan QR belum dapat dipastikan. Jangan buat order pembayaran lain dulu; muat ulang setelah 2 menit agar sistem memeriksa order ini ke Midtrans.', 0, $e);
        throw $e;
    }

    if ((string)($data['order_id'] ?? '') !== $orderId || !midtrans_amount_equal($data['gross_amount'] ?? null, $amount)) {
        $msg = 'Respons pembuatan QR Midtrans tidak cocok dengan order lokal. Order akan diperiksa ulang ke API sebelum QR baru dapat dibuat.';
        try { $upd = $pdo->prepare('UPDATE tb_midtrans_payment SET last_error=?,updated_at=NOW() WHERE id_midtrans_payment=?'); $upd->execute([$msg,$paymentId]); } catch (Throwable $ignored) {}
        throw new RuntimeException($msg);
    }
    $providerTransactionId = trim((string)($data['transaction_id'] ?? ''));
    $qrUrl = '';
    $actions = is_array($data['actions'] ?? null) ? $data['actions'] : [];

    // Prefer Midtrans' newer QR code action when both versions are returned.
    // Some Sandbox simulator flows have had parsing issues with older QR URL paths.
    // Only accept HTTPS URLs on the configured Midtrans API host.
    foreach (['generate-qr-code-v2', 'generate-qr-code'] as $preferredAction) {
        foreach ($actions as $action) {
            if ((string)($action['name'] ?? '') === $preferredAction
                && !empty($action['url'])
                && midtrans_validate_action_url((string)$action['url'])) {
                $qrUrl = (string)$action['url'];
                break 2;
            }
        }
    }

    // Legacy fallback: the Core API also exposes a QR image endpoint by transaction ID.
    // This fallback is used only if the response contains no acceptable QR action URL.
    if ($qrUrl === '' && $providerTransactionId !== '') {
        $candidate = rtrim((string)$cfg['api_base_url'], '/') . '/v2/qris/' . rawurlencode($providerTransactionId) . '/qr-code';
        if (midtrans_validate_action_url($candidate)) $qrUrl = $candidate;
    }
    if ($qrUrl === '') {
        $msg = 'Midtrans menerima/mengembalikan order tetapi URL QR tidak dapat dipastikan. Sistem akan memeriksa status order sebelum mengizinkan QR baru.';
        try { $upd = $pdo->prepare('UPDATE tb_midtrans_payment SET midtrans_transaction_id=?,last_error=?,updated_at=NOW() WHERE id_midtrans_payment=?'); $upd->execute([$providerTransactionId ?: null,$msg,$paymentId]); } catch (Throwable $ignored) {}
        throw new RuntimeException($msg);
    }
    $expiryTs = !empty($data['expiry_time']) ? strtotime((string)$data['expiry_time']) : false;
    $providerExpiry = $expiryTs !== false ? date('Y-m-d H:i:s', $expiryTs) : $exp;

    // Save gateway status and transaction payment state atomically. If this fails, keep the gateway row recoverable.
    try {
        $pdo->beginTransaction();
        $upd = $pdo->prepare("UPDATE tb_midtrans_payment SET midtrans_transaction_id=?,transaction_status=?,payment_type=?,qr_code_url=?,expires_at=?,response_status_code=?,last_error=NULL,updated_at=NOW() WHERE id_midtrans_payment=?");
        $upd->execute([$providerTransactionId ?: null, (string)($data['transaction_status'] ?? 'pending'), (string)($data['payment_type'] ?? 'qris'), $qrUrl, $providerExpiry, (string)($data['status_code'] ?? ''), $paymentId]);
        $txUpd = $pdo->prepare("UPDATE tb_transaksi SET status_pembayaran='menunggu_verifikasi',referensi_pembayaran=? WHERE id_parkir=? AND status_pembayaran<>'lunas'");
        $txUpd->execute([$orderId, $transactionId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        try { $upd = $pdo->prepare('UPDATE tb_midtrans_payment SET midtrans_transaction_id=?,last_error=?,updated_at=NOW() WHERE id_midtrans_payment=?'); $upd->execute([$providerTransactionId ?: null, 'Order dibuat oleh Midtrans; status lokal perlu dipulihkan melalui pemeriksaan API.', $paymentId]); } catch (Throwable $ignored) {}
        error_log('[ParkirSTRD Midtrans] Payment state persistence failed for order ' . $orderId . ': ' . $e->getMessage());
        throw new RuntimeException('Midtrans kemungkinan telah membuat order, tetapi penyimpanan lokal gagal. Jangan buat QR baru; muat ulang setelah 2 menit agar status order dipulihkan.', 0, $e);
    }

    return ['id_midtrans_payment'=>$paymentId,'id_parkir'=>$transactionId,'order_id'=>$orderId,'transaction_status'=>(string)($data['transaction_status'] ?? 'pending'),'qr_code_url'=>$qrUrl,'expires_at'=>$providerExpiry,'gross_amount'=>$amount];
}
function midtrans_amount_equal(mixed $remoteAmount, mixed $localAmount): bool {
    if (!is_numeric($remoteAmount) || !is_numeric($localAmount)) return false;
    return number_format((float)$remoteAmount, 2, '.', '') === number_format((float)$localAmount, 2, '.', '');
}

/** Applies a transaction state only from a status response fetched directly from Midtrans. */
function midtrans_apply_remote_status(PDO $pdo, array $remote, ?string $notificationGrossAmount = null): array {
    $orderId = (string)($remote['order_id'] ?? '');
    if ($orderId === '') throw new RuntimeException('Order ID tidak tersedia pada respons Midtrans.');
    if (strtolower((string)($remote['payment_type'] ?? '')) !== 'qris') throw new RuntimeException('Jenis pembayaran Midtrans tidak cocok.');

    $pdo->beginTransaction();
    try {
        $s = $pdo->prepare('SELECT * FROM tb_midtrans_payment WHERE order_id=? FOR UPDATE');
        $s->execute([$orderId]);
        $payment = $s->fetch();
        if (!$payment) throw new RuntimeException('Order Midtrans tidak terdaftar di ParkirSTRD.');
        if (!midtrans_amount_equal($remote['gross_amount'] ?? null, $payment['gross_amount'])) throw new RuntimeException('Nominal pada status Midtrans tidak sama dengan nominal lokal.');
        if ($notificationGrossAmount !== null && !midtrans_amount_equal($notificationGrossAmount, $payment['gross_amount'])) throw new RuntimeException('Nominal notifikasi tidak sesuai dengan catatan pembayaran.');

        $providerStatus = strtolower((string)($remote['transaction_status'] ?? 'unknown'));
        $providerTransactionId = (string)($remote['transaction_id'] ?? $payment['midtrans_transaction_id'] ?? '');
        $localPaymentStatus = match ($providerStatus) {
            'settlement' => 'lunas',
            'expire' => 'kedaluwarsa',
            'deny','cancel','failure' => 'gagal',
            'pending' => 'menunggu_verifikasi',
            default => null,
        };
        $txSt = $pdo->prepare('SELECT status,status_pembayaran,metode_pembayaran,biaya_total FROM tb_transaksi WHERE id_parkir=? FOR UPDATE');
        $txSt->execute([(int)$payment['id_parkir']]);
        $tx = $txSt->fetch();
        if (!$tx || $tx['status'] !== 'keluar' || $tx['metode_pembayaran'] !== 'qris' || !midtrans_amount_equal($tx['biaya_total'], $payment['gross_amount'])) {
            throw new RuntimeException('Transaksi ParkirSTRD tidak cocok dengan catatan pembayaran Midtrans.');
        }
        // A paid transaction is monotonic: older/pending notifications cannot downgrade it.
        if ($tx['status_pembayaran'] !== 'lunas' && $localPaymentStatus !== null) {
            if ($localPaymentStatus === 'lunas') {
                if ((string)($remote['status_code'] ?? '') !== '200') throw new RuntimeException('Status code Midtrans belum menunjukkan pembayaran berhasil.');
                if (isset($remote['fraud_status']) && strtolower((string)$remote['fraud_status']) !== 'accept') throw new RuntimeException('Status fraud Midtrans belum ACCEPT.');
                $u = $pdo->prepare("UPDATE tb_transaksi SET status_pembayaran='lunas',waktu_bayar=COALESCE(waktu_bayar,NOW()),referensi_pembayaran=? WHERE id_parkir=? AND status_pembayaran<>'lunas'");
                $u->execute([$providerTransactionId !== '' ? $providerTransactionId : $orderId, (int)$payment['id_parkir']]);
            } else {
                $u = $pdo->prepare('UPDATE tb_transaksi SET status_pembayaran=? WHERE id_parkir=? AND status_pembayaran<>\'lunas\'');
                $u->execute([$localPaymentStatus, (int)$payment['id_parkir']]);
            }
        }
        $upd = $pdo->prepare('UPDATE tb_midtrans_payment SET midtrans_transaction_id=?,transaction_status=?,payment_type=?,response_status_code=?,last_status_check=NOW(),last_notification_at=IF(?,NOW(),last_notification_at),updated_at=NOW() WHERE id_midtrans_payment=?');
        $upd->execute([$providerTransactionId !== '' ? $providerTransactionId : null, $providerStatus, (string)($remote['payment_type'] ?? 'qris'), (string)($remote['status_code'] ?? ''), $notificationGrossAmount !== null ? 1 : 0, (int)$payment['id_midtrans_payment']]);
        $finalStatusStmt = $pdo->prepare('SELECT status_pembayaran FROM tb_transaksi WHERE id_parkir=?');
        $finalStatusStmt->execute([(int)$payment['id_parkir']]);
        $finalPaymentStatus = (string)$finalStatusStmt->fetchColumn();
        $pdo->commit();
        return ['order_id'=>$orderId,'transaction_status'=>$providerStatus,'payment_status'=>$finalPaymentStatus ?: ($localPaymentStatus ?? (string)$tx['status_pembayaran']),'id_parkir'=>(int)$payment['id_parkir']];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function midtrans_refresh_payment(PDO $pdo, int $paymentId, int $staffId): array {
    $s = $pdo->prepare('SELECT m.order_id FROM tb_midtrans_payment m JOIN tb_transaksi t ON t.id_parkir=m.id_parkir WHERE m.id_midtrans_payment=? AND t.id_user_keluar=?');
    $s->execute([$paymentId,$staffId]);
    $row = $s->fetch();
    if (!$row) throw new InvalidArgumentException('Pembayaran tidak ditemukan dalam cakupan akun petugas Anda.');
    $remote = midtrans_api_request('GET', '/v2/' . rawurlencode((string)$row['order_id']) . '/status')['body'];
    if ((string)($remote['order_id'] ?? '') !== (string)$row['order_id']) throw new RuntimeException('Order ID pada status API tidak cocok.');
    return midtrans_apply_remote_status($pdo, $remote);
}

function midtrans_verify_and_process_notification(PDO $pdo, array $notice): array {
    $cfg = midtrans_config();
    if (!midtrans_is_enabled()) throw new RuntimeException('Integrasi Midtrans tidak aktif.');
    foreach (['order_id','status_code','gross_amount','signature_key'] as $required) {
        if (!isset($notice[$required]) || !is_scalar($notice[$required]) || (string)$notice[$required] === '') throw new InvalidArgumentException('Field notifikasi Midtrans tidak lengkap.');
    }
    $expected = midtrans_calculate_signature((string)$notice['order_id'], (string)$notice['status_code'], (string)$notice['gross_amount'], (string)$cfg['server_key']);
    if (!hash_equals($expected, strtolower((string)$notice['signature_key']))) throw new DomainException('Signature notifikasi Midtrans tidak valid.');
    $remote = midtrans_api_request('GET', '/v2/' . rawurlencode((string)$notice['order_id']) . '/status')['body'];
    if ((string)($remote['order_id'] ?? '') !== (string)$notice['order_id']) throw new RuntimeException('Order ID callback tidak sesuai dengan status API Midtrans.');
    if (!midtrans_amount_equal($remote['gross_amount'] ?? null, $notice['gross_amount'])) throw new RuntimeException('Nominal callback tidak sesuai dengan status API.');
    return midtrans_apply_remote_status($pdo, $remote, (string)$notice['gross_amount']);
}

function midtrans_fetch_qr_image(PDO $pdo, int $paymentId, int $staffId): array {
    $s = $pdo->prepare('SELECT m.qr_code_url,m.transaction_status FROM tb_midtrans_payment m JOIN tb_transaksi t ON t.id_parkir=m.id_parkir WHERE m.id_midtrans_payment=? AND t.id_user_keluar=?');
    $s->execute([$paymentId,$staffId]);
    $row = $s->fetch();
    if (!$row || empty($row['qr_code_url']) || !midtrans_validate_action_url((string)$row['qr_code_url'])) throw new InvalidArgumentException('QR pembayaran tidak tersedia. Buat ulang QR jika sudah kedaluwarsa.');
    if (!function_exists('curl_init')) throw new RuntimeException('Ekstensi cURL belum aktif.');
    $cfg = midtrans_config();
    $ch = curl_init((string)$row['qr_code_url']);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['Authorization: Basic '.base64_encode((string)$cfg['server_key'].':'),'Accept: image/png,image/*'],CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>20,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_FOLLOWLOCATION=>false]);
    $bytes = curl_exec($ch); $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); $type=(string)curl_getinfo($ch,CURLINFO_CONTENT_TYPE); $err=curl_error($ch); curl_close($ch);
    if ($bytes===false || $code<200 || $code>=300 || !str_starts_with(strtolower($type),'image/')) { error_log('[ParkirSTRD Midtrans QR] HTTP '.$code.' '.$err); throw new RuntimeException('Gambar QR dari Midtrans tidak dapat diambil. Coba muat ulang halaman.'); }
    return ['bytes'=>$bytes,'content_type'=>$type];
}
