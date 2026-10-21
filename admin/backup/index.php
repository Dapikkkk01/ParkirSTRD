<?php
require_once __DIR__.'/../../includes/init.php';
require_role('admin');
require_once __DIR__.'/../../includes/backup.php';
require_once __DIR__.'/../../includes/layout.php';
$error=''; $notice='';

if (isset($_GET['download'])) {
    try {
        $name=backup_safe_filename((string)$_GET['download']);
        $meta=backup_metadata(backup_root().'/admin',$name);
        if (!$meta || ($meta['type']??'')!=='admin_full') throw new RuntimeException('Backup tidak ditemukan atau integritasnya gagal.');
        log_activity('Mengunduh backup database '.$name);
        header('Content-Type: application/sql');
        header('Content-Disposition: attachment; filename="'.$name.'"');
        header('Content-Length: '.filesize(backup_root().'/admin/'.$name));
        readfile(backup_root().'/admin/'.$name); exit;
    } catch (Throwable $e) { http_response_code(404); $error='Backup tidak ditemukan atau integritasnya gagal.'; error_log('[ParkirSTRD backup download] '.$e->getMessage()); }
}

if ($_SERVER['REQUEST_METHOD']==='POST') {
    verify_csrf();
    try {
        $action=(string)($_POST['action']??'');
        if ($action==='create') {
            $meta=backup_create_admin(current_user(),'manual');
            log_activity('Membuat backup database penuh '.$meta['filename']);
            flash('success','Backup penuh berhasil dibuat dan checksum SHA-256 telah dicatat.');
            redirect('admin/backup/index.php');
        } elseif ($action==='restore') {
            if ((string)($_POST['confirmation']??'')!=='RESTORE') throw new InvalidArgumentException('Ketik RESTORE pada kolom konfirmasi untuk melanjutkan.');
            $name=backup_safe_filename((string)($_POST['filename']??''));
            $result=backup_restore_admin(current_user(),$name);
            flash('success','Restore berhasil. Backup keadaan sebelum restore disimpan sebagai '.$result['pre_restore'].'.');
            redirect('admin/backup/index.php');
        } elseif ($action==='delete_backup') {
            if ((string)($_POST['confirmation']??'')!=='HAPUS') throw new InvalidArgumentException('Ketik HAPUS untuk mengonfirmasi penghapusan file backup.');
            $name=backup_safe_filename((string)($_POST['filename']??''));
            $folder=backup_root().'/admin'; $meta=backup_metadata($folder,$name);
            if (!$meta || ($meta['type']??'')!=='admin_full') throw new InvalidArgumentException('Backup tidak ditemukan atau checksum tidak valid.');
            if (!@unlink($folder.'/'.$name) || !@unlink($folder.'/'.$name.'.json')) throw new RuntimeException('File backup atau metadata tidak dapat dihapus. Periksa izin direktori.');
            log_activity('Menghapus backup database '.$name);
            flash('success','Backup '.$name.' berhasil dihapus.'); redirect('admin/backup/index.php');
        } elseif ($action==='inspect_upload') {
            if (!isset($_FILES['backup_file']) || $_FILES['backup_file']['error']!==UPLOAD_ERR_OK) throw new InvalidArgumentException('Pilih file SQL backup yang valid untuk diperiksa.');
            $f=$_FILES['backup_file'];
            if ((int)$f['size']<100 || (int)$f['size']>100*1024*1024) throw new InvalidArgumentException('Ukuran file SQL harus antara 100 byte dan 100 MB.');
            $original=(string)$f['name'];
            if (strtolower(pathinfo($original,PATHINFO_EXTENSION))!=='sql') throw new InvalidArgumentException('Hanya file berekstensi .sql yang dapat diperiksa.');
            $sample=(string)file_get_contents($f['tmp_name'],false,null,0,1024*1024);
            if (!preg_match('/(?:CREATE TABLE|INSERT INTO|DROP TABLE|CREATE VIEW|CREATE DATABASE)/i',$sample)) throw new InvalidArgumentException('Isi file tidak dikenali sebagai dump SQL ParkirSTRD.');
            $name='review-'.date('Ymd-His').'-'.bin2hex(random_bytes(4)).'.sql';
            $path=backup_root().'/quarantine/'.$name;
            if (!move_uploaded_file($f['tmp_name'],$path)) throw new RuntimeException('File berhasil divalidasi tetapi tidak dapat disimpan untuk pemeriksaan.');
            backup_write_metadata($path,['type'=>'uploaded_review_only','created_at'=>date(DATE_ATOM),'created_by_id'=>current_user()['id'],'created_by_name'=>current_user()['name'],'original_name'=>basename($original),'warning'=>'File ini hanya disimpan untuk pemeriksaan. Tidak dapat dijalankan sebagai restore langsung.']);
            log_activity('Mengunggah file backup untuk pemeriksaan struktur '.$name);
            $notice='File disimpan untuk pemeriksaan awal. Demi keamanan, unggahan eksternal tidak dieksekusi langsung; restore penuh hanya dari backup terdaftar yang dibuat aplikasi.';
        } elseif ($action==='review_request') {
            $status=(string)($_POST['status']??'reviewed');
            backup_review_request((string)($_POST['request_file']??''),$status,current_user());
            flash('success',$status==='reviewed'?'Permintaan ditandai sudah ditinjau. Tidak ada data transaksi yang otomatis ditimpa.':'Permintaan pemulihan ditolak.');
            redirect('admin/backup/index.php');
        } else throw new InvalidArgumentException('Operasi backup tidak dikenali.');
    } catch (Throwable $e) {
        if ($e instanceof InvalidArgumentException) {
            $error = $e->getMessage();
        } elseif ($e instanceof RuntimeException && !str_contains($e->getMessage(), 'Detail:')) {
            $error = $e->getMessage();
            error_log('[ParkirSTRD backup admin] '.$e->getMessage());
        } else {
            $error = 'Operasi backup/restore belum dapat diselesaikan. Periksa log server untuk detail teknis.';
            error_log('[ParkirSTRD backup admin] '.$e->getMessage());
        }
    }
}

try { $items=backup_list(backup_root().'/admin'); $requests=backup_requests(); }
catch(Throwable $e) { $items=[]; $requests=[]; $error=$error?:'Daftar backup atau permintaan pemulihan tidak dapat dimuat. Periksa izin folder penyimpanan.'; error_log('[ParkirSTRD backup index] '.$e->getMessage()); }
render_header('Backup & Restore','backup');
?>
<div class="page-intro"><div><h2>Backup & Restore Database</h2><p>Admin dapat membuat backup lengkap dan memulihkan database dari backup terverifikasi. Penyimpanan default berada di luar direktori web.</p></div></div>
<?php if($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>
<?php if($notice): ?><div class="alert alert-success"><?= h($notice) ?></div><?php endif; ?>
<section class="panel"><div class="panel-heading"><div><h2>Backup penuh</h2><p>Mencakup struktur, data, view, trigger, prosedur, dan event yang didukung oleh tool database.</p></div></div><div class="panel-body"><form method="post" class="form-actions"><?= csrf_field() ?><input type="hidden" name="action" value="create"><button class="btn btn-primary">＋ Buat backup sekarang</button><span class="muted">Memerlukan mysqldump atau mariadb-dump di XAMPP.</span></form></div></section>
<section class="panel"><div class="panel-heading"><div><h2>Daftar backup terverifikasi</h2><p>Checksum diverifikasi sebelum file dapat diunduh atau dipulihkan.</p></div></div><div class="table-wrap"><table><thead><tr><th>File</th><th>Dibuat</th><th>Ukuran</th><th>Pembuat</th><th>SHA-256</th><th>Aksi</th></tr></thead><tbody>
<?php foreach($items as $it): ?><tr><td class="td-main"><?= h($it['filename']) ?><div class="muted"><?= h($it['label']??'manual') ?></div></td><td><?= h(tanggal((string)$it['created_at'])) ?></td><td><?= number_format(((int)$it['size_bytes'])/1024,1) ?> KB</td><td><?= h($it['created_by_name']??'-') ?></td><td><code><?= h(substr((string)$it['sha256'],0,16)) ?>…</code></td><td><div class="table-actions"><a class="btn btn-secondary btn-sm" href="<?= h(url('admin/backup/index.php?download='.rawurlencode($it['filename']))) ?>">Unduh SQL</a><form method="post" onsubmit="return confirm('Backup ini akan dihapus permanen. Pastikan sudah menyimpan salinan bila diperlukan.');"><?= csrf_field() ?><input type="hidden" name="action" value="delete_backup"><input type="hidden" name="filename" value="<?= h($it['filename']) ?>"><input name="confirmation" placeholder="Ketik HAPUS" aria-label="Ketik HAPUS untuk mengonfirmasi" autocomplete="off" required style="width:100px"><button class="btn btn-danger btn-sm">Hapus</button></form><form method="post" onsubmit="return confirm('Restore akan mengganti tabel pada database aktif. Backup otomatis dibuat terlebih dahulu. Lanjutkan hanya jika yakin.');"><?= csrf_field() ?><input type="hidden" name="action" value="restore"><input type="hidden" name="filename" value="<?= h($it['filename']) ?>"><input name="confirmation" aria-label="Ketik RESTORE" placeholder="Ketik RESTORE" required style="width:105px"><button class="btn btn-danger btn-sm">Restore</button></form></div></td></tr><?php endforeach; if(!$items): ?><tr><td colspan="6"><div class="empty-state">Belum ada backup buatan aplikasi.</div></td></tr><?php endif; ?></tbody></table></div></section>
<section class="panel"><div class="panel-heading"><div><h2>Periksa file backup eksternal</h2><p>File yang diunggah disimpan di karantina untuk pemeriksaan awal, tidak langsung dieksekusi.</p></div></div><div class="panel-body"><form method="post" enctype="multipart/form-data" class="form-grid single-col"><?= csrf_field() ?><input type="hidden" name="action" value="inspect_upload"><div class="field"><label>File SQL (.sql, maksimum 100 MB)</label><input type="file" name="backup_file" accept=".sql,text/plain" required></div><div class="form-actions"><button class="btn btn-secondary">Validasi & simpan untuk pemeriksaan</button></div></form></div></section>
<section class="panel"><div class="panel-heading"><div><h2>Permintaan pemulihan dari Petugas</h2><p>Permintaan hanya untuk ditinjau. Restore transaksi tidak otomatis diterapkan demi mencegah transaksi ganda dan perubahan histori keuangan.</p></div></div><div class="table-wrap"><table><thead><tr><th>ID permintaan</th><th>Petugas</th><th>Rentang data</th><th>Record</th><th>Status</th><th>Aksi</th></tr></thead><tbody><?php foreach($requests as $r): ?><tr><td class="td-main"><?= h($r['request_id']) ?></td><td><?= h($r['requested_by_name']??'-') ?> <span class="muted">#<?= (int)($r['requested_by_id']??0) ?></span></td><td><?= h($r['range']['from']??'-') ?> – <?= h($r['range']['to']??'-') ?></td><td><?= (int)($r['transaction_count']??0) ?></td><td><?= h((string)$r['status']) ?></td><td><?php if(($r['status']??'')==='pending_review'): ?><form method="post" class="table-actions"><?= csrf_field() ?><input type="hidden" name="action" value="review_request"><input type="hidden" name="request_file" value="<?= h($r['_file']??'') ?>"><button class="btn btn-secondary btn-sm" name="status" value="reviewed">Tandai ditinjau</button><button class="btn btn-danger btn-sm" name="status" value="rejected" onclick="return confirm('Tolak permintaan ini?')">Tolak</button></form><?php else: ?>—<?php endif; ?></td></tr><?php endforeach; if(!$requests): ?><tr><td colspan="6"><div class="empty-state">Belum ada permintaan pemulihan operasional.</div></td></tr><?php endif; ?></tbody></table></div></section>
<div class="alert" style="background:var(--surface-soft,#f4f0ff);color:var(--text,#5c4b82)"><strong>Catatan keamanan:</strong> Restore database penuh akan mengganti objek/tabel yang tercakup dalam backup. Buat backup baru dan pastikan file cocok sebelum melanjutkan. Berkas unggahan eksternal hanya diperiksa, tidak dieksekusi.</div>
<?php render_footer(); ?>
