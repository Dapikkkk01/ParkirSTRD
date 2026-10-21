<?php
require_once __DIR__.'/../includes/init.php';
require_role('petugas');
require_once __DIR__.'/../includes/backup.php';
require_once __DIR__.'/../includes/layout.php';
$actor=current_user(); $error=''; $notice='';

if (isset($_GET['download'])) {
    try {
        $name=backup_safe_filename((string)$_GET['download']);
        $meta=backup_metadata(backup_root().'/petugas',$name);
        if (!$meta || ($meta['type']??'')!=='operator_operational' || (int)($meta['created_by_id']??0)!==(int)$actor['id']) throw new RuntimeException('Backup tidak ditemukan atau bukan milik akun Anda.');
        log_activity('Mengunduh backup operasional '.$name);
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="'.$name.'"');
        header('Content-Length: '.filesize(backup_root().'/petugas/'.$name));
        readfile(backup_root().'/petugas/'.$name); exit;
    } catch(Throwable $e) { http_response_code(404); $error='Backup tidak ditemukan atau tidak tersedia untuk akun Anda.'; error_log('[ParkirSTRD operator backup download] '.$e->getMessage()); }
}
if ($_SERVER['REQUEST_METHOD']==='POST') {
    verify_csrf();
    try {
        $action=(string)($_POST['action']??'');
        if ($action==='create') {
            $from=(string)($_POST['from']??date('Y-m-01')); $to=(string)($_POST['to']??date('Y-m-d'));
            $meta=operator_backup_save(db(),$actor,$from,$to);
            log_activity('Membuat backup operasional '.$meta['filename']);
            flash('success','Backup operasional berhasil dibuat dan terikat ke akun petugas Anda.'); redirect('petugas/backup.php');
        } elseif ($action==='restore_request') {
            if (!isset($_FILES['backup_file']) || $_FILES['backup_file']['error']!==UPLOAD_ERR_OK) throw new InvalidArgumentException('Pilih file backup operasional JSON yang valid.');
            if ((int)$_FILES['backup_file']['size']<20 || (int)$_FILES['backup_file']['size']>20*1024*1024) throw new InvalidArgumentException('Ukuran file backup harus di bawah 20 MB.');
            if (strtolower(pathinfo((string)$_FILES['backup_file']['name'],PATHINFO_EXTENSION))!=='json') throw new InvalidArgumentException('Pemulihan hanya menerima backup operasional JSON ParkirSTRD.');
            if ((string)($_POST['confirmation']??'')!=='PULIHKAN') throw new InvalidArgumentException('Ketik PULIHKAN untuk mengonfirmasi pemulihan record operasional yang hilang.');
            $tmp=backup_root().'/tmp/operator-upload-'.bin2hex(random_bytes(8)).'.json';
            if (!move_uploaded_file($_FILES['backup_file']['tmp_name'],$tmp)) throw new RuntimeException('File unggahan tidak dapat disimpan sementara.');
            try { $payload=operator_backup_verify($tmp,(int)$actor['id']); } finally { @unlink($tmp); }
            $result=operator_restore_completed(db(),$payload,$actor);
            if ($result['active']) { $pending=$payload; $pending['transactions']=$result['active']; $request=operator_restore_request($pending,$actor); }
            $notice=(int)$result['restored'].' transaksi selesai yang hilang dipulihkan; '.(int)$result['already'].' transaksi sudah ada dan tidak diubah.';
            if ($result['active']) $notice.=' '.count($result['active']).' transaksi kendaraan yang masih aktif dikirim kepada Admin untuk review kapasitas dan rekonsiliasi (permintaan '.$request['request_id'].').';
        } else throw new InvalidArgumentException('Operasi tidak valid.');
    } catch(Throwable $e) {
        if ($e instanceof InvalidArgumentException) {
            $error=$e->getMessage();
        } elseif ($e instanceof RuntimeException && !($e instanceof PDOException)) {
            $error=$e->getMessage();
            error_log('[ParkirSTRD backup operator] '.$e->getMessage());
        } else {
            $error='Operasi backup belum dapat diselesaikan. Periksa log server atau hubungi Admin.';
            error_log('[ParkirSTRD backup operator] '.$e->getMessage());
        }
    }
}
try { $items=backup_list(backup_root().'/petugas'); $items=array_values(array_filter($items,static fn($m)=>(int)($m['created_by_id']??0)===(int)$actor['id'] && ($m['type']??'')==='operator_operational')); }
catch(Throwable $e) { $items=[]; $error=$error?:'Daftar backup tidak dapat dimuat. Periksa izin folder penyimpanan.'; error_log('[ParkirSTRD backup list operator] '.$e->getMessage()); }
render_header('Backup Operasional','backup');
?>
<div class="page-intro"><div><h2>Backup & pemulihan operasional</h2><p>Backup mencakup ringkasan transaksi yang melibatkan Anda sebagai petugas masuk atau keluar, dalam rentang tanggal yang dipilih.</p></div></div>
<?php if($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?><?php if($notice): ?><div class="alert alert-success"><?= h($notice) ?></div><?php endif; ?>
<section class="panel"><div class="panel-heading"><div><h2>Buat backup pribadi</h2><p>File JSON bertanda tangan HMAC; tidak berisi password, kredensial, atau data transaksi petugas lain yang tidak terlibat.</p></div></div><div class="panel-body"><form method="post" class="filter-row"><?= csrf_field() ?><input type="hidden" name="action" value="create"><div class="field"><label>Tanggal mulai</label><input type="date" name="from" value="<?= h((string)($_GET['from']??date('Y-m-01'))) ?>" required></div><div class="field"><label>Tanggal akhir</label><input type="date" name="to" value="<?= h((string)($_GET['to']??date('Y-m-d'))) ?>" required></div><button class="btn btn-primary">＋ Buat backup</button></form></div></section>
<section class="panel"><div class="panel-heading"><div><h2>Backup milik Anda</h2><p>Hanya file yang dibuat oleh akun ini yang ditampilkan.</p></div></div><div class="table-wrap"><table><thead><tr><th>Nama file</th><th>Tanggal</th><th>Rentang data</th><th>Transaksi</th><th>Ukuran</th><th>Aksi</th></tr></thead><tbody><?php foreach($items as $it): ?><tr><td class="td-main"><?= h($it['filename']) ?></td><td><?= h(tanggal((string)$it['created_at'])) ?></td><td><?= h($it['range']['from']??'-') ?> – <?= h($it['range']['to']??'-') ?></td><td><?= (int)($it['transaction_count']??0) ?></td><td><?= number_format(((int)$it['size_bytes'])/1024,1) ?> KB</td><td><a class="btn btn-secondary btn-sm" href="<?= h(url('petugas/backup.php?download='.rawurlencode($it['filename']))) ?>">Unduh JSON</a></td></tr><?php endforeach; if(!$items): ?><tr><td colspan="6"><div class="empty-state">Anda belum mempunyai backup operasional.</div></td></tr><?php endif; ?></tbody></table></div></section>
<section class="panel"><div class="panel-heading"><div><h2>Pemulihan backup operasional</h2><p>Jika file valid milik Anda, sistem mengajukan permintaan pemulihan kepada Admin.</p></div></div><div class="panel-body"><form method="post" enctype="multipart/form-data" class="form-grid single-col"><?= csrf_field() ?><input type="hidden" name="action" value="restore_request"><div class="field"><label>File backup operasional (.json, maksimum 20 MB)</label><input type="file" name="backup_file" accept=".json,application/json" required></div><div class="field"><label>Ketik PULIHKAN untuk konfirmasi</label><input name="confirmation" placeholder="PULIHKAN" required></div><div class="alert" style="background:var(--surface-soft,#f4f0ff);color:var(--text,#5c4b82)">Petugas hanya dapat memulihkan transaksi selesai yang hilang dari backup bertanda tangan dan milik akun ini. Transaksi yang sudah ada tidak ditimpa. Record kendaraan yang masih aktif tidak dipulihkan otomatis: sistem mengirimnya ke Admin untuk review kapasitas agar okupansi tidak menjadi ganda.</div><div class="form-actions"><button class="btn btn-primary">Validasi & ajukan pemulihan</button></div></form></div></section>
<?php render_footer(); ?>
