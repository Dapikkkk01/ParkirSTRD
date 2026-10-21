<?php
declare(strict_types=1);
function current_user(): array { return ['id'=>(int)($_SESSION['user_id']??0),'name'=>(string)($_SESSION['nama_lengkap']??''),'username'=>(string)($_SESSION['username']??''),'role'=>(string)($_SESSION['role']??'')]; }
function require_login(): void { if (empty($_SESSION['user_id']) || empty($_SESSION['role'])) { flash('error','Silakan login untuk melanjutkan.'); redirect('login.php'); } }
function require_role(string|array $roles): void {
    require_login(); $allowed = is_array($roles) ? $roles : [$roles];
    if (!in_array($_SESSION['role'] ?? '', $allowed, true)) { http_response_code(403); require __DIR__.'/403.php'; exit; }
}
function role_home(string $role): string { return match($role) { 'admin'=>'admin/dashboard.php', 'petugas'=>'petugas/dashboard.php', 'owner'=>'owner/dashboard.php', default=>'login.php' }; }
