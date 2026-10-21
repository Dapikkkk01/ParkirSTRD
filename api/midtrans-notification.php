<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/midtrans.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405); echo json_encode(['ok'=>false,'message'=>'POST required']); exit;
}
$contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
if ($contentType !== '' && !str_contains($contentType, 'application/json')) {
    http_response_code(415); echo json_encode(['ok'=>false,'message'=>'JSON required']); exit;
}
$contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > 65536) {
    http_response_code(413); echo json_encode(['ok'=>false,'message'=>'Payload too large']); exit;
}
$body = file_get_contents('php://input', false, null, 0, 65537);
if (!is_string($body) || strlen($body) > 65536) {
    http_response_code(413); echo json_encode(['ok'=>false,'message'=>'Payload too large']); exit;
}
$notice = json_decode((string)$body, true);
if (!is_array($notice)) { http_response_code(400); echo json_encode(['ok'=>false,'message'=>'Invalid JSON']); exit; }
$httpResult = 200;
$signatureValid = 0;
try {
    if (empty($notice['signature_key']) || empty($notice['order_id']) || empty($notice['status_code']) || !isset($notice['gross_amount'])) {
        throw new InvalidArgumentException('Invalid notification fields.');
    }
    $cfg = midtrans_config();
    if (!midtrans_is_enabled()) throw new RuntimeException('Payment provider is not configured.');
    $expect = hash('sha512', (string)$notice['order_id'] . (string)$notice['status_code'] . (string)$notice['gross_amount'] . (string)$cfg['server_key']);
    if (!hash_equals($expect, strtolower((string)$notice['signature_key']))) {
        $signatureValid = 0; $httpResult = 403;
        throw new DomainException('Invalid signature.');
    }
    $signatureValid = 1;
    $result = midtrans_verify_and_process_notification(db_system(), $notice);
    try {
        $log = db_system()->prepare('INSERT INTO tb_midtrans_webhook_log(order_id,provider_status,signature_valid,http_result) VALUES(?,?,1,200)');
        $log->execute([(string)$notice['order_id'], (string)($notice['transaction_status'] ?? '')]);
    } catch (Throwable $ignored) { error_log('[ParkirSTRD webhook] Unable to write webhook audit log.'); }
    echo json_encode(['ok'=>true,'order_id'=>$result['order_id'],'status'=>$result['transaction_status']]);
} catch (InvalidArgumentException $e) {
    $httpResult = 400; http_response_code($httpResult);
    try { $log=db_system()->prepare('INSERT INTO tb_midtrans_webhook_log(order_id,provider_status,signature_valid,http_result) VALUES(?,?,?,?)'); $log->execute([is_scalar($notice['order_id']??null)?(string)$notice['order_id']:null,is_scalar($notice['transaction_status']??null)?(string)$notice['transaction_status']:null,$signatureValid,$httpResult]); } catch (Throwable $ignored) {}
    echo json_encode(['ok'=>false,'message'=>'Invalid notification.']);
} catch (DomainException $e) {
    $httpResult = 403; http_response_code($httpResult);
    try { $log=db_system()->prepare('INSERT INTO tb_midtrans_webhook_log(order_id,provider_status,signature_valid,http_result) VALUES(?,?,0,403)'); $log->execute([(string)($notice['order_id']??''),(string)($notice['transaction_status']??'')]); } catch (Throwable $ignored) {}
    error_log('[ParkirSTRD webhook] rejected signature for order ' . preg_replace('/[^A-Za-z0-9_-]/','',(string)($notice['order_id']??'')));
    echo json_encode(['ok'=>false,'message'=>'Invalid notification signature.']);
} catch (Throwable $e) {
    http_response_code(500);
    error_log('[ParkirSTRD webhook] '.$e->getMessage());
    try { $log=db_system()->prepare('INSERT INTO tb_midtrans_webhook_log(order_id,provider_status,signature_valid,http_result) VALUES(?,?,?,500)'); $log->execute([is_scalar($notice['order_id']??null)?(string)$notice['order_id']:null,is_scalar($notice['transaction_status']??null)?(string)$notice['transaction_status']:null,$signatureValid]); } catch (Throwable $ignored) {}
    echo json_encode(['ok'=>false,'message'=>'Notification could not be processed; Midtrans may retry.']);
}
