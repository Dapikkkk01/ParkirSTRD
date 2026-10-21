<?php
require_once __DIR__.'/../includes/init.php';
require_role('petugas');
require_once __DIR__.'/../includes/layout.php';
$pdo = db();
$error = '';
$mode = (string)($_POST['mode'] ?? $_GET['mode'] ?? 'masuk');
if (!in_array($mode, ['masuk','keluar'], true)) $mode = 'masuk';
$postedCode = trim((string)($_POST['kode_kartu'] ?? ''));
$successId = max(0, (int)($_GET['success_id'] ?? 0));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $mode = (string)($_POST['mode'] ?? 'masuk');
    $postedCode = strtoupper(trim((string)($_POST['kode_kartu'] ?? '')));
    $method = (string)($_POST['metode_pembayaran'] ?? 'tunai');
    try {
        if (!in_array($mode, ['masuk','keluar'], true)) throw new InvalidArgumentException('Pilih mode masuk atau keluar.');
        if (!preg_match('/^PPK-[A-F0-9]{40}$/D', $postedCode)) throw new InvalidArgumentException('Barcode tidak valid. Pastikan barcode berasal dari kartu ParkirSTRD yang diterbitkan Admin.');
        if ($mode === 'keluar' && !in_array($method, ['tunai','qris'], true)) throw new InvalidArgumentException('Metode pembayaran tidak valid.');
        $pdo->beginTransaction();
        $cardStmt = $pdo->prepare("SELECT c.id_kartu,c.id_kendaraan,c.kode_kartu,c.status,k.plat_nomor,k.jenis_kendaraan,k.warna,k.pemilik FROM tb_kartu_parkir c JOIN tb_kendaraan k ON k.id_kendaraan=c.id_kendaraan WHERE c.kode_kartu=? AND c.status='aktif' FOR UPDATE");
        $cardStmt->execute([$postedCode]);
        $card = $cardStmt->fetch();
        if (!$card) throw new InvalidArgumentException('Kartu tidak ditemukan atau sudah nonaktif. Hubungi Admin jika kartu hilang atau rusak.');

        if ($mode === 'masuk') {
            $areaId = input_int('id_area');
            // Kunci kendaraan terlebih dahulu supaya dua petugas tidak membuat transaksi aktif ganda.
            $vehicleLock = $pdo->prepare('SELECT id_kendaraan FROM tb_kendaraan WHERE id_kendaraan=? FOR UPDATE');
            $vehicleLock->execute([(int)$card['id_kendaraan']]);
            if (!$vehicleLock->fetch()) throw new InvalidArgumentException('Kendaraan pada kartu tidak tersedia.');
            $active = $pdo->prepare("SELECT id_parkir FROM tb_transaksi WHERE id_kendaraan=? AND status='masuk' LIMIT 1 FOR UPDATE");
            $active->execute([(int)$card['id_kendaraan']]);
            if ($active->fetch()) throw new InvalidArgumentException('Kendaraan ini masih tercatat di dalam area parkir. Scan mode Keluar terlebih dahulu.');
            $rate = $pdo->prepare('SELECT id_tarif,tarif_per_jam FROM tb_tarif WHERE jenis_kendaraan=? FOR UPDATE');
            $rate->execute([$card['jenis_kendaraan']]);
            $tariff = $rate->fetch();
            if (!$tariff) throw new InvalidArgumentException('Tarif untuk jenis kendaraan ini belum disiapkan Admin.');
            $areaStmt = $pdo->prepare('SELECT id_area,nama_area,kapasitas,terisi FROM tb_area_parkir WHERE id_area=? FOR UPDATE');
            $areaStmt->execute([$areaId]);
            $area = $areaStmt->fetch();
            if (!$area) throw new InvalidArgumentException('Area parkir tidak ditemukan.');
            if ((int)$area['terisi'] >= (int)$area['kapasitas']) throw new InvalidArgumentException('Area yang dipilih sudah penuh. Pilih area lain.');
            $ins = $pdo->prepare("INSERT INTO tb_transaksi(id_kartu,id_kendaraan,waktu_masuk,id_tarif,tarif_saat_masuk,status,id_user,id_area) VALUES(?,?,NOW(),?,?, 'masuk',?,?)");
            $ins->execute([(int)$card['id_kartu'],(int)$card['id_kendaraan'],(int)$tariff['id_tarif'],$tariff['tarif_per_jam'],current_user()['id'],$areaId]);
            $transactionId = (int)$pdo->lastInsertId();
            $areaUp = $pdo->prepare('UPDATE tb_area_parkir SET terisi=terisi+1 WHERE id_area=? AND terisi<kapasitas');
            $areaUp->execute([$areaId]);
            if ($areaUp->rowCount() !== 1) throw new RuntimeException('Kapasitas area berubah. Transaksi dibatalkan, silakan scan kembali.');
            $log = $pdo->prepare('INSERT INTO tb_log_aktivitas(id_user,aktivitas) VALUES(?,?)');
            $log->execute([current_user()['id'],'Scan kartu: kendaraan '.$card['plat_nomor'].' masuk; transaksi #'.$transactionId]);
            $pdo->commit();
            flash('success','Kartu valid. Kendaraan '.$card['plat_nomor'].' berhasil dicatat masuk, transaksi #'.$transactionId.'.');
            redirect('petugas/scanner.php?mode=masuk&success_id='.$transactionId);
        }

        // Mode keluar: satu transaksi aktif saja yang boleh diselesaikan untuk kendaraan ini.
        $activeTx = $pdo->prepare("SELECT t.id_parkir,t.id_area,t.waktu_masuk,t.status,t.tarif_saat_masuk FROM tb_transaksi t WHERE t.id_kendaraan=? AND t.status='masuk' ORDER BY t.waktu_masuk DESC LIMIT 1 FOR UPDATE");
        $activeTx->execute([(int)$card['id_kendaraan']]);
        $tx = $activeTx->fetch();
        if (!$tx) throw new InvalidArgumentException('Tidak ada transaksi masuk aktif untuk kendaraan ini. Kendaraan mungkin belum masuk atau sudah diproses keluar.');
        $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta'));
        $entry = new DateTimeImmutable((string)$tx['waktu_masuk'], new DateTimeZone('Asia/Jakarta'));
        if ($now < $entry) throw new RuntimeException('Waktu server lebih awal dari waktu masuk. Periksa jam komputer/server.');
        $duration = max(1, (int)ceil(max(0, $now->getTimestamp() - $entry->getTimestamp()) / 3600));
        $cost = $duration * (float)$tx['tarif_saat_masuk'];
        $areaLock = $pdo->prepare('SELECT terisi FROM tb_area_parkir WHERE id_area=? FOR UPDATE');
        $areaLock->execute([(int)$tx['id_area']]);
        $occupied = $areaLock->fetchColumn();
        if ($occupied === false || (int)$occupied < 1) throw new RuntimeException('Data kapasitas area tidak konsisten. Hubungi Admin.');
        $timeOut = $now->format('Y-m-d H:i:s');
        $paymentStatus = $method === 'qris' ? 'menunggu_verifikasi' : 'lunas';
        $paidAt = $method === 'tunai' ? $timeOut : null;
        $update = $pdo->prepare("UPDATE tb_transaksi SET id_kartu=COALESCE(id_kartu,?),waktu_keluar=?,durasi_jam=?,biaya_total=?,status='keluar',metode_pembayaran=?,status_pembayaran=?,waktu_bayar=?,id_user_keluar=? WHERE id_parkir=? AND status='masuk'");
        $update->execute([(int)$card['id_kartu'],$timeOut,$duration,$cost,$method,$paymentStatus,$paidAt,current_user()['id'],(int)$tx['id_parkir']]);
        if ($update->rowCount() !== 1) throw new RuntimeException('Transaksi sudah berubah atau telah diproses oleh petugas lain.');
        $decr = $pdo->prepare('UPDATE tb_area_parkir SET terisi=terisi-1 WHERE id_area=? AND terisi>0');
        $decr->execute([(int)$tx['id_area']]);
        if ($decr->rowCount() !== 1) throw new RuntimeException('Okupansi area gagal diperbarui. Seluruh perubahan dibatalkan.');
        $log = $pdo->prepare('INSERT INTO tb_log_aktivitas(id_user,aktivitas) VALUES(?,?)');
        $log->execute([current_user()['id'],'Scan kartu: kendaraan '.$card['plat_nomor'].' keluar; transaksi #'.(int)$tx['id_parkir'].', biaya '.$cost.', pembayaran '.$method]);
        $pdo->commit();
        if ($method === 'qris') {
            require_once __DIR__ . '/../includes/midtrans.php';
            $finishedId = (int)$tx['id_parkir'];
            try {
                if (midtrans_is_enabled()) { midtrans_create_qris_for_transaction($pdo, $finishedId, (int)current_user()['id']); flash('success','Kendaraan keluar tercatat. QRIS dinamis Midtrans dibuat; tunggu verifikasi pembayaran.'); }
                else { flash('error','Kendaraan keluar tercatat, tetapi QR belum dibuat. Konfigurasikan Server Key Midtrans Sandbox terlebih dahulu.'); }
            } catch (Throwable $paymentError) {
                error_log('[ParkirSTRD scanner Midtrans] ' . $paymentError->getMessage());
                flash('error','Kendaraan keluar sudah tercatat, tetapi QR Midtrans belum berhasil dibuat. Buka menu Pembayaran QRIS untuk mencoba kembali.');
            }
            redirect('petugas/pembayaran.php?transaction=' . $finishedId);
        }
        flash('success','Kendaraan keluar berhasil dicatat dan dibayar tunai. Total '.rupiah($cost).'.');
        redirect('petugas/struk.php?id='.(int)$tx['id_parkir']);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e instanceof InvalidArgumentException ? $e->getMessage() : 'Pemindaian gagal diproses. Perubahan dibatalkan; periksa log server jika masalah berlanjut.';
        if (!($e instanceof InvalidArgumentException)) error_log('[ParkirSTRD scanner] '.$e->getMessage());
    }
}

$areas = $pdo->query('SELECT id_area,nama_area,kapasitas,terisi FROM tb_area_parkir ORDER BY nama_area')->fetchAll();
$success = null;
if ($successId > 0) {
    $s = $pdo->prepare("SELECT t.id_parkir,t.id_kartu,t.waktu_masuk,t.status,k.plat_nomor,k.jenis_kendaraan,k.pemilik,a.nama_area,c.kode_kartu FROM tb_transaksi t JOIN tb_kendaraan k ON k.id_kendaraan=t.id_kendaraan JOIN tb_area_parkir a ON a.id_area=t.id_area LEFT JOIN tb_kartu_parkir c ON c.id_kartu=t.id_kartu WHERE t.id_parkir=? AND t.status='masuk'");
    $s->execute([$successId]);
    $success = $s->fetch() ?: null;
}
render_header('Scan Kartu Parkir','scanner');
?>
<div class="page-intro"><div><h2>Validasi kartu kendaraan pelanggan</h2><p>Pindai barcode Code 128 pada kartu pelanggan untuk mencatat masuk atau menyelesaikan transaksi keluar.</p></div><a class="btn btn-secondary" href="<?= h(url('petugas/transaksi-masuk.php')) ?>">Input manual</a></div>
<?php if ($error): ?><div class="alert alert-error" role="alert"><?= h($error) ?></div><?php endif; ?>
<?php if ($success): ?><section class="panel scan-success"><div class="panel-body"><span class="badge badge-success">Kartu valid · Kendaraan masuk</span><h2 style="margin:10px 0 5px"><?= h($success['plat_nomor']) ?> · Transaksi #<?= (int)$success['id_parkir'] ?></h2><p class="muted"><?= h($success['pemilik'].' · '.$success['nama_area'].' · '.tanggal($success['waktu_masuk'])) ?></p><div class="barcode-wrap" style="margin-top:14px;max-width:440px"><svg data-barcode="<?= h($success['kode_kartu'] ?: 'PARKIRSTRD-TRX-'.$success['id_parkir']) ?>"></svg><span class="barcode-caption">Barcode kartu parkir</span></div><a class="btn btn-primary" href="<?= h(url('petugas/scanner.php?mode=keluar')) ?>">Siap untuk scan keluar →</a></div></section><?php endif; ?>
<section class="scan-layout"><div class="panel"><div class="panel-heading"><div><h2>Scanner kamera</h2><p>Kamera membutuhkan izin akses browser. Pada pengembangan lokal, gunakan localhost.</p></div></div><div class="panel-body"><div id="reader" class="camera-reader"></div><div class="scanner-controls"><button class="btn btn-primary" id="startScan" type="button">Buka kamera</button><button class="btn btn-secondary" id="stopScan" type="button" disabled>Hentikan kamera</button></div><p class="muted" id="cameraMessage" aria-live="polite">Jika kamera tidak tersedia atau CDN tidak dapat diakses, masukkan kode kartu secara manual.</p></div></div>
<div class="panel"><div class="panel-heading"><div><h2>Proses dari kartu</h2><p>Pilih arah transaksi dan scan/kode barcode kartu.</p></div></div><form method="post" id="scanForm" class="form-grid single-col"><?= csrf_field() ?><div class="field"><label for="mode">Proses</label><select id="mode" name="mode"><option value="masuk" <?= $mode==='masuk'?'selected':'' ?>>Kendaraan masuk</option><option value="keluar" <?= $mode==='keluar'?'selected':'' ?>>Kendaraan keluar</option></select></div><div class="field"><label for="kode_kartu">Kode kartu</label><input id="kode_kartu" name="kode_kartu" required maxlength="44" pattern="PPK-[A-Fa-f0-9]{40}" value="<?= h($postedCode) ?>" placeholder="PPK-..." autocomplete="off" spellcheck="false"><small>Token kartu diverifikasi di server. ID kendaraan saja tidak bisa dipakai sebagai pengganti kartu.</small></div><div class="field" id="areaField"><label for="id_area">Area parkir masuk</label><select id="id_area" name="id_area" <?= $mode==='masuk'?'required':'' ?>><option value="">Pilih area...</option><?php foreach ($areas as $a): $free=(int)$a['kapasitas']-(int)$a['terisi']; ?><option value="<?= (int)$a['id_area'] ?>" <?= $free<1?'disabled':'' ?>><?= h($a['nama_area'].' · '.$free.' slot tersedia') ?></option><?php endforeach; ?></select></div><div class="field" id="paymentField"><label for="metode_pembayaran">Metode pembayaran saat keluar</label><select id="metode_pembayaran" name="metode_pembayaran"><option value="tunai">Tunai</option><option value="qris">QRIS (Midtrans otomatis)</option></select><small>Pembayaran QRIS akan menggunakan Midtrans bila Server Key sudah dikonfigurasi.</small></div><div class="form-actions"><button class="btn btn-primary btn-full" type="submit">Validasi kartu dan proses</button></div></form></div></section>
<section class="panel"><div class="panel-heading"><div><h2>Alur penggunaan</h2><p>Validasi dilakukan kembali oleh PHP sebelum database berubah.</p></div></div><div class="panel-body"><div class="scan-steps"><div><span>1</span><strong>Scan</strong><small>Kamera Code 128 atau input kode.</small></div><div><span>2</span><strong>Validasi</strong><small>Kartu harus aktif dan terhubung ke kendaraan.</small></div><div><span>3</span><strong>Transaksi</strong><small>Masuk mengunci kapasitas; keluar menghitung durasi dan biaya.</small></div><div><span>4</span><strong>Audit</strong><small>Transaksi dan log disimpan dalam satu commit.</small></div></div></div></section>
<script src="https://unpkg.com/html5-qrcode" defer></script>
<script>
window.addEventListener('load',()=>{
 const start=document.getElementById('startScan'),stop=document.getElementById('stopScan'),msg=document.getElementById('cameraMessage'),form=document.getElementById('scanForm'),mode=document.getElementById('mode'),area=document.getElementById('areaField'),areaSelect=document.getElementById('id_area'),payment=document.getElementById('paymentField'),code=document.getElementById('kode_kartu');
 let scanner=null, locked=false;
 const syncMode=()=>{const entering=mode.value==='masuk';area.hidden=!entering;areaSelect.required=entering;areaSelect.disabled=!entering;payment.hidden=entering;};mode.addEventListener('change',syncMode);syncMode();
 const stopCamera=async()=>{if(scanner){try{await scanner.stop();}catch(e){}try{await scanner.clear();}catch(e){}scanner=null;}start.disabled=false;stop.disabled=true;};
 start.addEventListener('click',async()=>{
   if(!window.Html5Qrcode){msg.textContent='Library scanner tidak tersedia. Gunakan kode kartu manual atau periksa koneksi internet.';return;}
   try{
    locked=false;start.disabled=true;msg.textContent='Meminta akses kamera...';
    const formats=window.Html5QrcodeSupportedFormats?[Html5QrcodeSupportedFormats.CODE_128]:undefined;
    scanner=new Html5Qrcode('reader',formats?{formatsToSupport:formats,verbose:false}:{verbose:false});
    const cameras=await Html5Qrcode.getCameras();if(!cameras.length)throw new Error('Kamera tidak ditemukan.');
    const camera=cameras.find(c=>/back|rear|environment/i.test(c.label))||cameras[0];
    await scanner.start(camera.id,{fps:10,aspectRatio:1.7},async(decoded)=>{if(locked)return;locked=true;code.value=String(decoded).trim().toUpperCase();msg.textContent='Barcode terbaca. Memvalidasi kartu...';await stopCamera();form.requestSubmit();},()=>{});
    stop.disabled=false;msg.textContent='Arahkan barcode kartu ke dalam area kamera hingga terbaca.';
   }catch(e){await stopCamera();msg.textContent='Kamera tidak dapat dibuka: '+(e?.message||'izin ditolak')+'. Gunakan input kode manual atau periksa izin kamera browser.';}
 });
 stop.addEventListener('click',stopCamera);
 window.addEventListener('pagehide',()=>{if(scanner){scanner.stop().catch(()=>{});}});
});
</script>
<?php render_footer(); ?>
