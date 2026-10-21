<?php
declare(strict_types=1);
function start_secure_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_name('PARKIRSTRDSESSID');
    session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>$https,'httponly'=>true,'samesite'=>'Lax']);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    session_start();
}
function h(mixed $value): string { return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function url(string $path=''): string { return rtrim(APP_BASE_URL, '/') . '/' . ltrim($path, '/'); }
function redirect(string $path): never { header('Location: ' . url($path)); exit; }
function csrf_token(): string { if (empty($_SESSION['_csrf'])) $_SESSION['_csrf'] = bin2hex(random_bytes(32)); return $_SESSION['_csrf']; }
function csrf_field(): string { return '<input type="hidden" name="_csrf" value="'.h(csrf_token()).'">'; }
function verify_csrf(): void {
    $sent = (string)($_POST['_csrf'] ?? ''); $known = (string)($_SESSION['_csrf'] ?? '');
    if ($sent === '' || $known === '' || !hash_equals($known, $sent)) { http_response_code(419); exit('Sesi formulir kedaluwarsa. Muat ulang halaman lalu coba kembali.'); }
}
function flash(string $type, string $message): void { $_SESSION['_flash'] = ['type'=>$type,'message'=>$message]; }
function take_flash(): ?array { $v = $_SESSION['_flash'] ?? null; unset($_SESSION['_flash']); return $v; }
function log_activity(string $activity): void {
    $uid = (int)($_SESSION['user_id'] ?? 0); if ($uid < 1) return;
    try { $s = db()->prepare('INSERT INTO tb_log_aktivitas (id_user, aktivitas) VALUES (?, ?)'); $s->execute([$uid, mb_substr($activity,0,250)]); }
    catch (Throwable $e) { error_log('[ParkirSTRD log] '.$e->getMessage()); }
}
function rupiah(mixed $amount): string { return 'Rp ' . number_format((float)$amount, 0, ',', '.'); }
function tanggal(?string $datetime): string { if (!$datetime) return '-'; try { return (new DateTimeImmutable($datetime))->format('d/m/Y H:i'); } catch(Throwable $e) { return h($datetime); } }
function status_badge(string $status): string {
    $map = ['masuk'=>'warning','keluar'=>'success','aktif'=>'success','tidak aktif'=>'muted','tidak_aktif'=>'muted'];
    $label = ['masuk'=>'Masuk','keluar'=>'Keluar','aktif'=>'Aktif','tidak_aktif'=>'Tidak Aktif'];
    $key = strtolower($status); return '<span class="badge badge-'.($map[$key] ?? 'muted').'">'.h($label[$key] ?? ucfirst($status)).'</span>';
}
function pagination(int $total, int $perPage, int $page, string $baseUrl): string {
    $pages = max(1, (int)ceil($total / $perPage)); if ($pages <= 1) return '';
    $html = '<nav class="pagination" aria-label="Pagination">';
    for ($i=1; $i<=$pages; $i++) { $u = $baseUrl . (str_contains($baseUrl,'?')?'&':'?') . 'page='.$i; $html .= '<a class="page-link '.($i===$page?'active':'').'" href="'.h($u).'">'.$i.'</a>'; }
    return $html.'</nav>';
}
function page_number(): int { return max(1, min(100000, (int)($_GET['page'] ?? 1))); }
function input_int(string $key, int $min=1): int { $v = filter_input(INPUT_POST, $key, FILTER_VALIDATE_INT); if ($v === false || $v === null || $v < $min) throw new InvalidArgumentException('Nilai formulir tidak valid.'); return (int)$v; }
function valid_date_range(string $from, string $to): array {
    $a = DateTimeImmutable::createFromFormat('!Y-m-d', $from); $b = DateTimeImmutable::createFromFormat('!Y-m-d', $to);
    if (!$a || !$b || $a->format('Y-m-d')!==$from || $b->format('Y-m-d')!==$to || $a>$b) throw new InvalidArgumentException('Rentang tanggal tidak valid.');
    if ($a->diff($b)->days > 366) throw new InvalidArgumentException('Rentang laporan maksimal 366 hari.');
    return [$from.' 00:00:00', $to.' 23:59:59'];
}
