<?php
require_once __DIR__.'/../../includes/init.php';
require_role('admin');
require_once __DIR__.'/../../includes/layout.php';
$pdo = db();
$error = '';
$printId = max(0, (int)($_GET['print_id'] ?? 0));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? 'issue');
    try {
        if ($action === 'issue') {
            $vehicleId = input_int('id_kendaraan');
            $pdo->beginTransaction();
            $vehicleStmt = $pdo->prepare('SELECT id_kendaraan, plat_nomor FROM tb_kendaraan WHERE id_kendaraan=? FOR UPDATE');
            $vehicleStmt->execute([$vehicleId]);
            $vehicle = $vehicleStmt->fetch();
            if (!$vehicle) throw new InvalidArgumentException('Kendaraan tidak ditemukan.');

            $active = $pdo->prepare("SELECT id_kartu FROM tb_kartu_parkir WHERE id_kendaraan=? AND status='aktif' LIMIT 1 FOR UPDATE");
            $active->execute([$vehicleId]);
            if ($active->fetch()) throw new InvalidArgumentException('Kendaraan ini sudah memiliki kartu aktif. Nonaktifkan kartu lama dahulu bila perlu menerbitkan pengganti.');

            // Token acak kriptografis; tidak menggunakan ID urut sebagai kredensial barcode.
            $token = 'PPK-' . strtoupper(bin2hex(random_bytes(20)));
            $insert = $pdo->prepare("INSERT INTO tb_kartu_parkir(id_kendaraan,kode_kartu,status,dibuat_oleh) VALUES(?,?,'aktif',?)");
            $insert->execute([$vehicleId, $token, current_user()['id']]);
            $cardId = (int)$pdo->lastInsertId();
            $log = $pdo->prepare('INSERT INTO tb_log_aktivitas(id_user,aktivitas) VALUES(?,?)');
            $log->execute([current_user()['id'], 'Menerbitkan kartu parkir #' . $cardId . ' untuk kendaraan ' . $vehicle['plat_nomor']]);
            $pdo->commit();
            flash('success', 'Kartu parkir berhasil diterbitkan untuk ' . $vehicle['plat_nomor'] . '. Cetak kartu dan berikan kepada pelanggan.');
            redirect('admin/kartu/index.php?print_id=' . $cardId);
        }

        if ($action === 'deactivate') {
            $cardId = input_int('id_kartu');
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('SELECT c.id_kartu,c.id_kendaraan,c.status,k.plat_nomor FROM tb_kartu_parkir c JOIN tb_kendaraan k ON k.id_kendaraan=c.id_kendaraan WHERE c.id_kartu=? FOR UPDATE');
            $stmt->execute([$cardId]);
            $card = $stmt->fetch();
            if (!$card) throw new InvalidArgumentException('Kartu tidak ditemukan.');
            if ($card['status'] !== 'aktif') throw new InvalidArgumentException('Kartu sudah nonaktif.');
            $activeTx = $pdo->prepare("SELECT id_parkir FROM tb_transaksi WHERE id_kendaraan=? AND status='masuk' LIMIT 1 FOR UPDATE");
            $activeTx->execute([(int)$card['id_kendaraan']]);
            if ($activeTx->fetch()) throw new InvalidArgumentException('Kartu tidak bisa dinonaktifkan saat kendaraan masih parkir. Proses keluar terlebih dahulu.');
            $upd = $pdo->prepare("UPDATE tb_kartu_parkir SET status='nonaktif',waktu_dinonaktifkan=NOW() WHERE id_kartu=? AND status='aktif'");
            $upd->execute([$cardId]);
            if ($upd->rowCount() !== 1) throw new RuntimeException('Status kartu berubah. Muat ulang dan coba lagi.');
            $log = $pdo->prepare('INSERT INTO tb_log_aktivitas(id_user,aktivitas) VALUES(?,?)');
            $log->execute([current_user()['id'], 'Menonaktifkan kartu parkir #' . $cardId . ' untuk kendaraan ' . $card['plat_nomor']]);
            $pdo->commit();
            flash('success', 'Kartu untuk ' . $card['plat_nomor'] . ' sudah dinonaktifkan.');
            redirect('admin/kartu/index.php');
        }
        throw new InvalidArgumentException('Aksi tidak dikenal.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e instanceof InvalidArgumentException ? $e->getMessage() : 'Operasi kartu gagal. Periksa log server jika masalah berlanjut.';
        if (!($e instanceof InvalidArgumentException)) error_log('[ParkirSTRD kartu] ' . $e->getMessage());
    }
}

$vehicleStmt = $pdo->query("SELECT k.id_kendaraan,k.plat_nomor,k.jenis_kendaraan,k.warna,k.pemilik FROM tb_kendaraan k WHERE NOT EXISTS (SELECT 1 FROM tb_kartu_parkir c WHERE c.id_kendaraan=k.id_kendaraan AND c.status='aktif') ORDER BY k.plat_nomor");
$vehicles = $vehicleStmt->fetchAll();
$cards = $pdo->query("SELECT c.id_kartu,c.kode_kartu,c.status,c.waktu_dibuat,c.waktu_dinonaktifkan,k.plat_nomor,k.jenis_kendaraan,k.warna,k.pemilik,u.nama_lengkap AS penerbit FROM tb_kartu_parkir c JOIN tb_kendaraan k ON k.id_kendaraan=c.id_kendaraan JOIN tb_user u ON u.id_user=c.dibuat_oleh ORDER BY c.id_kartu DESC LIMIT 200")->fetchAll();
$printCard = null;
if ($printId > 0) {
    $stmt = $pdo->prepare("SELECT c.id_kartu,c.kode_kartu,c.status,c.waktu_dibuat,k.plat_nomor,k.jenis_kendaraan,k.warna,k.pemilik FROM tb_kartu_parkir c JOIN tb_kendaraan k ON k.id_kendaraan=c.id_kendaraan WHERE c.id_kartu=?");
    $stmt->execute([$printId]);
    $printCard = $stmt->fetch() ?: null;
}
render_header('Kartu Parkir Pelanggan', 'kartu');
?>
<?php if ($printCard): ?><div class="parking-card-print">
  <div class="parking-card-brand"><span class="brand-mark">S</span><div><strong>ParkirSTRD</strong><small>KARTU PARKIR PELANGGAN</small></div><span class="parking-card-status">KARTU <?= h(strtoupper($printCard['status'])) ?></span></div>
  <div class="parking-card-info"><div><span class="parking-card-label">PLAT NOMOR</span><strong class="parking-card-plate"><?= h($printCard['plat_nomor']) ?></strong><span><?= h(ucfirst($printCard['jenis_kendaraan'])) ?> · <?= h($printCard['warna']) ?></span></div><div class="parking-card-owner"><span class="parking-card-label">PEMILIK KENDARAAN</span><strong><?= h($printCard['pemilik']) ?></strong><small>ID kartu #<?= (int)$printCard['id_kartu'] ?></small></div></div>
  <div class="parking-card-barcode"><svg data-barcode="<?= h($printCard['kode_kartu']) ?>"></svg><small><?= h($printCard['kode_kartu']) ?></small></div>
  <div class="parking-card-footer"><span>Hanya berlaku selama status kartu aktif.</span><span>Terbit <?= h(tanggal($printCard['waktu_dibuat'])) ?></span></div>
</div><div class="print-card-actions"><button class="btn btn-primary" type="button" onclick="window.print()">Cetak kartu</button> <a class="btn btn-secondary" href="<?= h(url('admin/kartu/index.php')) ?>">Kembali ke daftar</a></div><script>document.body.classList.add('print-card-mode');</script><?php endif; ?>
<div class="kartu-page-screen">
  <div class="page-intro"><div><h2>Terbitkan kartu barcode unik</h2><p>Setiap kendaraan pelanggan mendapat satu kartu aktif. Barcode memakai token acak, bukan ID kendaraan yang mudah ditebak.</p></div><a class="btn btn-secondary" href="<?= h(url('admin/kendaraan/index.php')) ?>">Kelola kendaraan</a></div>
  <?php if ($error): ?><div class="alert alert-error" role="alert"><?= h($error) ?></div><?php endif; ?>
  <section class="panel"><div class="panel-heading"><div><h2>Terbitkan kartu baru</h2><p>Pilih kendaraan terdaftar yang belum memiliki kartu aktif.</p></div></div>
    <form method="post" class="form-grid single-col"><?= csrf_field() ?><input type="hidden" name="action" value="issue"><div class="field"><label for="id_kendaraan">Kendaraan pelanggan</label><select id="id_kendaraan" name="id_kendaraan" required><option value="">Pilih kendaraan...</option><?php foreach ($vehicles as $v): ?><option value="<?= (int)$v['id_kendaraan'] ?>"><?= h($v['plat_nomor'] . ' · ' . ucfirst($v['jenis_kendaraan']) . ' · ' . $v['pemilik']) ?></option><?php endforeach; ?></select><?php if (!$vehicles): ?><small>Semua kendaraan sudah memiliki kartu aktif, atau Admin belum mendaftarkan kendaraan.</small><?php endif; ?></div><div class="alert" style="margin:0">Kartu baru dicetak setelah penerbitan berhasil. Simpan fisik kartu dengan aman; siapa pun yang memegang barcode aktif dapat menggunakannya untuk identifikasi kendaraan.</div><div class="form-actions"><button class="btn btn-primary" <?= !$vehicles ? 'disabled' : '' ?>>Terbitkan kartu dan tampilkan untuk dicetak</button></div></form>
  </section>
  <section class="panel"><div class="panel-heading"><div><h2>Daftar kartu parkir</h2><p><?= count($cards) ?> kartu terakhir, termasuk kartu nonaktif.</p></div><div class="search-box"><input id="cardSearch" type="search" placeholder="Cari plat / pemilik / status"></div></div><div class="table-wrap"><table id="cardsTable"><thead><tr><th>ID</th><th>Plat / Pemilik</th><th>Barcode kartu</th><th>Diterbitkan</th><th>Status</th><th>Aksi</th></tr></thead><tbody>
  <?php foreach ($cards as $c): ?><tr data-card-search="<?= h(strtolower($c['plat_nomor'].' '.$c['pemilik'].' '.$c['status'])) ?>"><td class="td-main">#<?= (int)$c['id_kartu'] ?></td><td class="td-main"><?= h($c['plat_nomor']) ?><div class="muted"><?= h($c['pemilik'].' · '.ucfirst($c['jenis_kendaraan'])) ?></div></td><td><div class="barcode-wrap"><svg data-barcode="<?= h($c['kode_kartu']) ?>"></svg><span class="barcode-caption">Kode unik kartu</span></div></td><td><?= h(tanggal($c['waktu_dibuat'])) ?><div class="muted"><?= h($c['penerbit']) ?></div></td><td><?= $c['status']==='aktif'?'<span class="badge badge-success">Aktif</span>':'<span class="badge badge-muted">Nonaktif</span>' ?></td><td><div class="table-actions"><a class="btn btn-secondary btn-sm" href="<?= h(url('admin/kartu/index.php?print_id='.(int)$c['id_kartu'])) ?>">Cetak / lihat</a><?php if ($c['status']==='aktif'): ?><form method="post" class="inline-form" data-confirm="Nonaktifkan kartu ini? Kendaraan tidak boleh sedang parkir."><?= csrf_field() ?><input type="hidden" name="action" value="deactivate"><input type="hidden" name="id_kartu" value="<?= (int)$c['id_kartu'] ?>"><button class="btn btn-danger btn-sm">Nonaktifkan</button></form><?php endif; ?></div></td></tr><?php endforeach; ?>
  <?php if (!$cards): ?><tr><td colspan="6"><div class="empty-state"><strong>Belum ada kartu parkir</strong>Terbitkan kartu pertama untuk kendaraan yang sudah terdaftar.</div></td></tr><?php endif; ?>
  </tbody></table></div></section>
</div>
<script>
document.getElementById('cardSearch')?.addEventListener('input', function(){const q=this.value.trim().toLowerCase();document.querySelectorAll('#cardsTable tbody tr[data-card-search]').forEach(row=>row.hidden=!row.dataset.cardSearch.includes(q));});
</script>
<?php render_footer(); ?>
