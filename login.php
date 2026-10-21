<?php
require_once __DIR__.'/includes/init.php';
if (!empty($_SESSION['user_id'])) redirect(role_home((string)$_SESSION['role']));
$error='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    verify_csrf(); $now=time();
    if (!empty($_SESSION['login_block_until']) && $_SESSION['login_block_until']>$now) $error='Terlalu banyak percobaan. Coba lagi beberapa saat.';
    else {
        $username=trim((string)($_POST['username']??'')); $password=(string)($_POST['password']??'');
        try {
            $s=db_login()->prepare('SELECT id_user,nama_lengkap,username,password,role,status_aktif FROM tb_user WHERE username = ? LIMIT 1'); $s->execute([$username]); $user=$s->fetch();
            $ok=false; $legacy=false;
            if ($user && (int)$user['status_aktif']===1) {
                $ok=password_verify($password,(string)$user['password']);
                if (!$ok && preg_match('/^[a-f0-9]{64}$/i',(string)$user['password'])) { $legacy=hash_equals(strtolower((string)$user['password']),hash('sha256',$password)); $ok=$legacy; }
            }
            if ($ok) {
                if ($legacy || password_needs_rehash((string)$user['password'],PASSWORD_DEFAULT)) {
                    $upd=db_login()->prepare('UPDATE tb_user SET password=? WHERE id_user=?'); $upd->execute([password_hash($password,PASSWORD_DEFAULT),(int)$user['id_user']]);
                }
                session_regenerate_id(true); unset($_SESSION['login_attempts'],$_SESSION['login_block_until']);
                $_SESSION['user_id']=(int)$user['id_user']; $_SESSION['nama_lengkap']=(string)$user['nama_lengkap']; $_SESSION['username']=(string)$user['username']; $_SESSION['role']=(string)$user['role']; $_SESSION['_csrf']=bin2hex(random_bytes(32));
                try { $log=db_login(); $q=$log->prepare('INSERT INTO tb_log_aktivitas (id_user,aktivitas) VALUES (?,?)'); $q->execute([(int)$user['id_user'],'Login berhasil']); } catch(Throwable $e) { error_log('[ParkirSTRD login log] '.$e->getMessage()); }
                redirect(role_home((string)$user['role']));
            }
            $_SESSION['login_attempts']=(int)($_SESSION['login_attempts']??0)+1;
            if ($_SESSION['login_attempts']>=5) { $_SESSION['login_block_until']=$now+60; $_SESSION['login_attempts']=0; }
            $error='Username atau password tidak valid, atau akun tidak aktif.';
        } catch(Throwable $e) { error_log('[ParkirSTRD login] '.$e->getMessage()); $error='Login belum dapat diproses. Periksa konfigurasi database atau hubungi administrator.'; }
    }
}
$flash=take_flash();
// Peta parkir berikut adalah ilustrasi interaktif di halaman login, bukan data okupansi real-time.
$parkingOccupied = [2, 3, 7, 8, 11, 13, 16, 19, 20, 24];
$parkingColors = ['#a78be8', '#e3e8f5', '#829fc4', '#c8b8f0', '#d8dbe5', '#8d82c9'];
$parkingTotal = 24;
$parkingOccupiedCount = count($parkingOccupied);
$parkingFreeCount = $parkingTotal - $parkingOccupiedCount;
?><!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Login · ParkirSTRD</title><link rel="stylesheet" href="<?= h(url('assets/css/style.css')) ?>"></head><body class="login-body"><button class="icon-button theme-toggle login-theme-toggle" id="themeToggle" type="button" aria-label="Ganti mode terang atau gelap" title="Ganti tema"><span id="themeIcon">☾</span></button><div class="login-layout"><section class="login-visual"><div class="visual-grid"></div><a class="brand login-brand" href="<?= h(url('login.php')) ?>"><span class="brand-mark">S</span><span><strong>ParkirSTRD</strong><small>Parking management</small></span></a><div class="hero-copy"><span class="hero-chip"><span class="tip-dot"></span> SMART PARKING SYSTEM</span><h1>Parkir lebih<br><em>tertata.</em> Operasi<br>lebih mudah.</h1><p>Kelola kendaraan, pantau area, dan rekap pendapatan dari satu dashboard.</p></div><section class="parking-showcase" aria-labelledby="parkingMapTitle">
<div class="parking-showcase-header">
  <div class="parking-map-heading">
    <span class="parking-map-eyebrow"><span class="parking-live-dot"></span> ILUSTRASI INTERAKTIF</span>
    <h2 id="parkingMapTitle">Area parkir</h2>
    <p>Gulir area dan klik slot untuk melihat statusnya.</p>
  </div>
  <div class="parking-open-summary"><strong id="parkingFreeCount"><?= $parkingFreeCount ?></strong><span>slot kosong</span><small>dari <?= $parkingTotal ?> slot</small></div>
</div>
<div class="parking-map-toolbar">
  <div class="parking-map-legend" aria-label="Keterangan status slot">
    <span class="parking-legend-item"><i class="parking-legend-swatch swatch-free"></i>Kosong <b id="parkingLegendFree"><?= $parkingFreeCount ?></b></span>
    <span class="parking-legend-item"><i class="parking-legend-swatch swatch-occupied"></i>Terisi <b id="parkingLegendOccupied"><?= $parkingOccupiedCount ?></b></span>
  </div>
  <div class="parking-map-scroll-buttons">
    <button type="button" class="parking-map-arrow" data-parking-scroll="-1" aria-label="Geser peta ke kiri" title="Geser ke kiri">‹</button>
    <button type="button" class="parking-map-arrow" data-parking-scroll="1" aria-label="Geser peta ke kanan" title="Geser ke kanan">›</button>
  </div>
</div>
<div class="parking-map-frame">
  <div class="parking-map-viewport" id="parkingMapViewport" role="region" aria-label="Peta slot parkir yang dapat digulir mendatar" tabindex="0">
    <div class="parking-lot" id="parkingLot">
      <?php foreach ([['class' => 'parking-row-top', 'start' => 1, 'end' => 12, 'label' => 'Baris A'], ['class' => 'parking-row-bottom', 'start' => 13, 'end' => 24, 'label' => 'Baris B']] as $row): ?>
      <?php if ($row['start'] === 13): ?><div class="parking-driving-lane" aria-hidden="true"><span class="lane-arrow">←</span><span class="lane-caption">JALUR KENDARAAN</span><span class="lane-arrow">→</span></div><?php endif; ?>
      <div class="parking-row <?= h($row['class']) ?>" aria-label="<?= h($row['label']) ?>">
        <?php for ($i = $row['start']; $i <= $row['end']; $i++): $isOccupied = in_array($i, $parkingOccupied, true); $spotName = 'P' . str_pad((string)$i, 2, '0', STR_PAD_LEFT); ?>
        <button type="button" class="parking-spot <?= $isOccupied ? 'is-occupied' : 'is-available' ?>" data-spot="<?= h($spotName) ?>" data-status="<?= $isOccupied ? 'occupied' : 'available' ?>" aria-label="Slot <?= h($spotName) ?>, <?= $isOccupied ? 'terisi' : 'kosong' ?>" aria-pressed="false" title="Slot <?= h($spotName) ?> · <?= $isOccupied ? 'Terisi' : 'Kosong' ?>">
          <span class="parking-spot-number"><?= h($spotName) ?></span>
          <?php if ($isOccupied): ?><span class="parking-car" style="--car-color:<?= h($parkingColors[($i * 3) % count($parkingColors)]) ?>" aria-hidden="true"><span class="parking-car-window"></span><span class="parking-car-rear-window"></span></span><?php else: ?><span class="parking-free-mark" aria-hidden="true">+</span><?php endif; ?>
          <span class="parking-slot-state" aria-hidden="true"><?= $isOccupied ? 'TERISI' : 'KOSONG' ?></span>
        </button>
        <?php endfor; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="parking-map-scroll-hint"><span>← Geser untuk melihat semua slot →</span><span>24 SLOT VISUAL</span></div>
</div>
<div class="parking-selected-detail" id="parkingSpotDetails" aria-live="polite" aria-atomic="true">
  <span class="parking-detail-mark is-available" id="selectedSpotMark" aria-hidden="true"></span>
  <div class="parking-detail-copy"><strong id="selectedSpotTitle">P01 · Slot kosong</strong><span id="selectedSpotDescription">Pilih slot lain untuk melihat detail status visual.</span></div>
  <span class="parking-detail-badge is-available" id="selectedSpotBadge">KOSONG</span>
</div>
<p class="parking-map-disclaimer">Pratinjau visual interaktif · tidak melakukan reservasi dan bukan status real-time.</p>
</section><div class="visual-footer">Sistem pengelolaan parkir terintegrasi <span>● Aman · ● Terukur</span></div></section><section class="login-panel"><div class="login-card"><div class="mobile-brand"><span class="brand-mark">S</span><strong>ParkirSTRD</strong></div><div class="login-heading"><span class="eyebrow">SELAMAT DATANG KEMBALI</span><h2>Masuk ke akun Anda</h2><p>Gunakan akun yang telah didaftarkan oleh administrator.</p></div><?php if ($error): ?><div class="alert alert-error" role="alert"><?= h($error) ?></div><?php endif; ?><?php if($flash): ?><div class="alert alert-<?= h($flash['type']==='success'?'success':'error') ?>"><?= h($flash['message']) ?></div><?php endif; ?><form method="post" class="form-stack" autocomplete="on"><?= csrf_field() ?><label for="username">Username</label><div class="input-icon"><span>♙</span><input id="username" name="username" autocomplete="username" required maxlength="50" placeholder="Masukkan username" value="<?= h($_POST['username']??'') ?>"></div><label for="password">Password</label><div class="input-icon"><span>⚿</span><input id="password" name="password" type="password" autocomplete="current-password" required placeholder="Masukkan password"><button class="password-toggle" type="button" data-toggle-password="#password">Lihat</button></div><button class="btn btn-primary btn-full login-submit" type="submit">Masuk ke Dashboard <span>→</span></button></form><div class="login-note"><span>ⓘ</span> Akun demo tersedia di README untuk pengujian lokal.</div><div class="login-copy">© <?= date('Y') ?> ParkirSTRD · PHP Native</div></div></section></div><script src="<?= h(url('assets/js/app.js')) ?>" defer></script></body></html>