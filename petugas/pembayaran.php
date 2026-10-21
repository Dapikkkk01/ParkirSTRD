<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/init.php';
require_role('petugas');
require_once __DIR__ . '/../includes/midtrans.php';
require_once __DIR__ . '/../includes/layout.php';
$pdo = db();
$error = '';
$cfg = midtrans_config();
$activeTransaction = max(0, (int)($_GET['transaction'] ?? 0));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'create') {
            $id = input_int('id_parkir');
            $payment = midtrans_create_qris_for_transaction($pdo, $id, (int)current_user()['id']);
            flash('success', 'QRIS Midtrans berhasil dibuat. Pastikan pelanggan membayar QRIS untuk nominal ' . rupiah($payment['gross_amount']) . '.');
            redirect('petugas/pembayaran.php?transaction=' . $id);
        } elseif ($action === 'refresh') {
            $paymentId = input_int('id_midtrans_payment');
            $result = midtrans_refresh_payment($pdo, $paymentId, (int)current_user()['id']);
            if ($result['payment_status'] === 'lunas') {
                flash('success', 'Midtrans mengonfirmasi pembayaran berhasil. Status transaksi sekarang LUNAS.');
            } elseif ($result['transaction_status'] === 'expire') {
                flash('error', 'QRIS sudah kedaluwarsa. Buat QR baru untuk transaksi ini jika pembayaran belum berhasil.');
            } elseif (in_array($result['transaction_status'], ['deny','cancel','failure'], true)) {
                flash('error', 'Midtrans melaporkan status ' . $result['transaction_status'] . '. Periksa transaksi sebelum mencoba QR baru.');
            } else {
                flash('success', 'Status sudah diperiksa langsung ke API Midtrans: ' . $result['transaction_status'] . '.');
            }
            redirect('petugas/pembayaran.php?transaction=' . (int)$result['id_parkir']);
        } else {
            throw new InvalidArgumentException('Aksi pembayaran tidak dikenal.');
        }
    } catch (Throwable $e) {
        $error = $e instanceof InvalidArgumentException || $e instanceof RuntimeException ? $e->getMessage() : 'Operasi pembayaran belum dapat diselesaikan.';
        error_log('[ParkirSTRD payment page] ' . $e->getMessage());
    }
}

try {
    $sql = "SELECT t.id_parkir,t.waktu_keluar,t.biaya_total,t.status_pembayaran,t.referensi_pembayaran,t.id_user_keluar,k.plat_nomor,k.pemilik,
                   mp.id_midtrans_payment,mp.order_id,mp.transaction_status AS midtrans_status,mp.qr_code_url,mp.expires_at,
                   mp.last_status_check,mp.last_error,mp.gross_amount,mp.created_at
            FROM tb_transaksi t
            JOIN tb_kendaraan k ON k.id_kendaraan=t.id_kendaraan
            LEFT JOIN (
                SELECT m.* FROM tb_midtrans_payment m
                JOIN (SELECT id_parkir,MAX(id_midtrans_payment) AS max_id FROM tb_midtrans_payment GROUP BY id_parkir) latest
                  ON latest.max_id=m.id_midtrans_payment
            ) mp ON mp.id_parkir=t.id_parkir
            WHERE t.status='keluar' AND t.metode_pembayaran='qris' AND t.id_user_keluar=?
            ORDER BY FIELD(t.status_pembayaran,'menunggu_verifikasi','belum_bayar','gagal','kedaluwarsa','lunas'),t.id_parkir DESC LIMIT 150";
    $st = $pdo->prepare($sql);
    $st->execute([(int)current_user()['id']]);
    $rows = $st->fetchAll();
} catch (Throwable $e) {
    $rows = [];
    $error = 'Migrasi Midtrans belum tersedia. Backup database, lalu impor database/midtrans_qris.sql setelah migrasi QRIS, kartu, dan ParkirSTRD upgrade.';
    error_log('[ParkirSTRD payment list] ' . $e->getMessage());
}

render_header('Pembayaran QRIS Midtrans', 'pembayaran');
?>
<div class="page-intro"><div><h2>Pembayaran QRIS otomatis</h2><p>QR dinamis dibuat melalui API Midtrans. ParkirSTRD hanya menandai pembayaran lunas setelah status tervalidasi dari Midtrans.</p></div><span class="badge <?= $cfg['environment']==='production'?'badge-success':'badge-warning' ?>"><?= h(strtoupper((string)$cfg['environment'])) ?> MODE</span></div>
<?php if ($error): ?><div class="alert alert-error" role="alert"><?= h($error) ?></div><?php endif; ?>
<section class="panel"><div class="panel-heading"><div><h2>Status integrasi</h2><p>Server Key tidak pernah ditampilkan pada halaman ini.</p></div></div><div class="panel-body">
<?php if (midtrans_is_enabled()): ?>
<div class="alert alert-success">Kunci Midtrans tersedia. Mode <strong><?= h(strtoupper((string)$cfg['environment'])) ?></strong>. Pembayaran QRIS menggunakan Core API.</div>
<?php else: ?>
<div class="alert alert-error">Integrasi Midtrans belum aktif. QR tidak dapat dibuat dan pembayaran tidak dapat otomatis ditandai lunas.</div>
<p>Salin <code>config/payment.local.example.php</code> menjadi <code>config/payment.local.php</code>, masukkan Server Key Sandbox dari Midtrans Merchant Administration Portal, lalu simpan file. Jangan menaruh Server Key di HTML, JavaScript, atau chat.</p>
<?php endif; ?>
<div class="detail-grid"><div class="detail-item"><small>Provider</small><strong>Midtrans Core API</strong></div><div class="detail-item"><small>Environment</small><strong><?= h(ucfirst((string)$cfg['environment'])) ?></strong></div><div class="detail-item"><small>Verifikasi</small><strong>API status + signature webhook</strong></div></div>
</div></section>
<?php foreach ($rows as $r): $selected = $activeTransaction === (int)$r['id_parkir']; $midStatus = (string)($r['midtrans_status'] ?? ''); $createdTs = !empty($r['created_at']) ? strtotime((string)$r['created_at']) : time(); $expiryTs = !empty($r['expires_at']) ? strtotime((string)$r['expires_at']) : 0; $needsReconcile = ($midStatus === 'creating' && $createdTs !== false && $createdTs < time()-120) || ($midStatus === 'pending' && $expiryTs > 0 && $expiryTs <= time()); $canCreate = !empty($cfg['enabled']) && $r['status_pembayaran'] !== 'lunas' && (!$r['id_midtrans_payment'] || in_array($midStatus, ['failure','deny','cancel','expire','expired'], true) || $needsReconcile); ?>
<section class="panel" id="trx-<?= (int)$r['id_parkir'] ?>"><div class="panel-heading"><div><h2>Transaksi #<?= (int)$r['id_parkir'] ?> · <?= h($r['plat_nomor']) ?></h2><p><?= h($r['pemilik']) ?> · Keluar <?= h(tanggal($r['waktu_keluar'])) ?></p></div><span class="badge <?= $r['status_pembayaran']==='lunas'?'badge-success':(in_array($r['status_pembayaran'],['gagal','kedaluwarsa'],true)?'badge-danger':'badge-warning') ?>"><?= h(str_replace('_',' ',ucfirst((string)$r['status_pembayaran']))) ?></span></div>
<div class="panel-body"><div class="detail-grid"><div class="detail-item"><small>Total parkir</small><strong><?= h(rupiah($r['biaya_total'])) ?></strong></div><div class="detail-item"><small>Status Midtrans</small><strong><?= h($midStatus ?: 'Belum dibuat') ?></strong></div><div class="detail-item"><small>Order ID</small><strong><code><?= h($r['order_id'] ?: '-') ?></code></strong></div><div class="detail-item"><small>Batas waktu</small><strong><?= h($r['expires_at'] ? tanggal((string)$r['expires_at']) : '-') ?></strong></div></div>
<?php if ($r['status_pembayaran'] === 'lunas'): ?>
<div class="alert alert-success" style="margin-top:14px">Pembayaran sudah terverifikasi dari Midtrans. Referensi: <code><?= h($r['referensi_pembayaran'] ?: '-') ?></code></div>
<?php elseif ($selected && $r['id_midtrans_payment'] && $r['qr_code_url'] && in_array($midStatus,['pending','creating'],true)): ?>
<div class="qris-panel" style="margin-top:18px"><div class="qris-placeholder" style="max-width:330px;min-height:280px"><div><img src="<?= h(url('petugas/midtrans-qr.php?id='.(int)$r['id_midtrans_payment'])) ?>" alt="QRIS pembayaran Midtrans" style="display:block;width:240px;height:240px;object-fit:contain;margin:0 auto;background:#fff;border-radius:12px;padding:8px"><strong style="margin-top:12px">Scan untuk membayar <?= h(rupiah($r['biaya_total'])) ?></strong><span>Order <?= h($r['order_id']) ?></span></div></div><div><h3>Instruksi pembayaran</h3><ol><li>Pastikan nominal cocok dengan total parkir.</li><li>Untuk Sandbox, gunakan simulator pembayaran Midtrans; jangan bayar QR sandbox dari aplikasi bank pribadi.</li><li>Setelah pengujian, tekan tombol periksa status atau tunggu webhook dari Midtrans.</li></ol><p class="muted">URL QR dari Midtrans untuk simulator:</p><p style="overflow-wrap:anywhere"><a href="<?= h($r['qr_code_url']) ?>" target="_blank" rel="noopener noreferrer">Buka URL QR</a></p><button type="button" class="btn btn-secondary btn-sm" data-copy="<?= h($r['qr_code_url']) ?>">Salin URL QR</button><form method="post" style="margin-top:14px"><?= csrf_field() ?><input type="hidden" name="action" value="refresh"><input type="hidden" name="id_midtrans_payment" value="<?= (int)$r['id_midtrans_payment'] ?>"><button class="btn btn-primary" type="submit">Periksa status langsung ke Midtrans</button></form><?php if ($needsReconcile && $r['status_pembayaran']!=='lunas'): ?><form method="post" style="margin-top:8px" onsubmit="return confirm('Sistem akan memeriksa order yang sama terlebih dahulu. QR baru hanya dibuat jika status provider membuktikan order lama gagal/kedaluwarsa/tidak ditemukan. Lanjutkan?')"><?= csrf_field() ?><input type="hidden" name="action" value="create"><input type="hidden" name="id_parkir" value="<?= (int)$r['id_parkir'] ?>"><button class="btn btn-secondary" type="submit">Sinkronkan order / pulihkan QR</button></form><?php endif; ?><?php if ($cfg['environment']==='sandbox'): ?><p style="margin-top:12px"><a class="btn btn-secondary" href="https://simulator.sandbox.midtrans.com/openapi/qris/index" target="_blank" rel="noopener noreferrer">Buka Simulator QRIS Sandbox ↗</a></p><?php endif; ?></div></div>
<?php elseif ($canCreate): ?>
<div class="alert" style="margin-top:14px;background:var(--surface-soft,#f4f0ff);color:var(--text,#5c4b82)">Transaksi parkir sudah tercatat. Buat QRIS dinamis Midtrans untuk nominal persis <strong><?= h(rupiah($r['biaya_total'])) ?></strong>.</div><form method="post" class="form-actions"><?= csrf_field() ?><input type="hidden" name="action" value="create"><input type="hidden" name="id_parkir" value="<?= (int)$r['id_parkir'] ?>"><button class="btn btn-primary" type="submit"><?= $needsReconcile ? 'Periksa order lama / pulihkan QR' : '＋ Buat QRIS Midtrans' ?></button></form>
<?php elseif ($r['id_midtrans_payment'] && in_array($midStatus,['expire','expired','failure','deny','cancel'],true) && $r['status_pembayaran']!=='lunas'): ?>
<div class="alert alert-error" style="margin-top:14px">Pembayaran berstatus <?= h($midStatus) ?>. Bila belum ada dana masuk, QR baru dapat dibuat. Pastikan transaksi sebelumnya benar-benar tidak dibayar sebelum mengulang.</div><form method="post" class="form-actions"><?= csrf_field() ?><input type="hidden" name="action" value="create"><input type="hidden" name="id_parkir" value="<?= (int)$r['id_parkir'] ?>"><button class="btn btn-primary" type="submit">Buat QR baru</button></form>
<?php elseif (!$cfg['enabled']): ?><p class="muted" style="margin-top:14px">Konfigurasikan Server Key Sandbox terlebih dahulu untuk membuat QR.</p>
<?php else: ?><p class="muted" style="margin-top:14px">Status pembayaran sedang diproses. Muat ulang halaman atau periksa status lagi beberapa saat kemudian.</p><?php endif; ?>
</div></section>
<?php endforeach; if (!$rows): ?><section class="panel"><div class="empty-state"><strong>Belum ada transaksi QRIS</strong><p>Setelah petugas memproses kendaraan keluar dengan metode QRIS, transaksi milik petugas akan muncul di sini.</p></div></section><?php endif; ?>
<section class="panel"><div class="panel-heading"><div><h2>Pengujian Sandbox</h2><p>Gunakan simulator resmi Midtrans untuk simulasi pembayaran.</p></div></div><div class="panel-body"><ol><li>Daftar/login ke Midtrans Sandbox dan ambil Sandbox Server Key.</li><li>Konfigurasikan Server Key secara lokal di <code>config/payment.local.php</code>.</li><li>Proses transaksi keluar dengan metode QRIS dan buat QR dinamis.</li><li>Buka simulator QRIS Sandbox, salin URL QR yang ditampilkan, lalu jalankan simulasi pembayaran.</li><li>Tekan <strong>Periksa status langsung ke Midtrans</strong>. Status lunas hanya muncul jika API menyatakan settlement dengan nominal/order yang cocok.</li></ol><div class="alert alert-error" style="margin-top:12px">Jangan scan QR transaksi Sandbox dengan rekening atau e-wallet sungguhan. Gunakan simulator Sandbox. URL webhook juga harus dapat dijangkau dari internet melalui HTTPS agar notifikasi otomatis diterima.</div></div></section>
<?php render_footer(); ?>
