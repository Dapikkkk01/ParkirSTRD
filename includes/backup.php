<?php
declare(strict_types=1);

/** Backup helpers. Files are kept outside htdocs by default. */
function backup_root(): string {
    $configured = getenv('PARKIRSTRD_BACKUP_DIR');
    $root = is_string($configured) && trim($configured) !== ''
        ? trim($configured)
        : dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'parkirstrd_private';
    $root = rtrim($root, "\\/");
    foreach ([$root, $root.'/admin', $root.'/petugas', $root.'/quarantine', $root.'/recovery-requests', $root.'/tmp'] as $dir) {
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Direktori backup tidak dapat disiapkan. Periksa izin folder penyimpanan.');
        }
    }
    // Defense in depth if a custom path is accidentally set inside the web root.
    if (!is_file($root.'/.htaccess')) @file_put_contents($root.'/.htaccess', "Require all denied\nDeny from all\n");
    return $root;
}

function backup_bin(string $kind): ?string {
    $override = getenv('PARKIRSTRD_MYSQL_BIN');
    $names = $kind === 'dump' ? ['mysqldump.exe','mariadb-dump.exe','mysqldump','mariadb-dump'] : ['mysql.exe','mariadb.exe','mysql','mariadb'];
    if (is_string($override) && $override !== '' && is_file($override)) return $override;
    $base = is_string($override) && $override !== '' && is_dir($override) ? rtrim($override, "\\/") : dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'mysql'.DIRECTORY_SEPARATOR.'bin';
    foreach ($names as $name) {
        $candidate = $base.DIRECTORY_SEPARATOR.$name;
        if (is_file($candidate)) return $candidate;
    }
    // Optional PATH fallback when XAMPP's mysql/bin is not in its usual location.
    foreach ($names as $name) {
        $lookup = DIRECTORY_SEPARATOR === '\\' ? 'where '.escapeshellarg($name).' 2>NUL' : 'command -v '.escapeshellarg($name).' 2>/dev/null';
        $found = trim((string)@shell_exec($lookup));
        $candidate = strtok($found, "\r\n");
        if (is_string($candidate) && $candidate !== '' && is_file($candidate)) return $candidate;
    }
    return null;
}

function backup_defaults_file(array $account): string {
    $root = backup_root().'/tmp';
    $file = $root.'/client-'.bin2hex(random_bytes(12)).'.cnf';
    $cfg = db_config();
    $quote = static fn(string $v): string => '"'.str_replace(["\\", '"', "\r", "\n"], ["\\\\", '\\"', '', ''], $v).'"';
    $contents = "[client]\n".
        'host='.$quote((string)$cfg['host'])."\n".
        'port='.(int)$cfg['port']."\n".
        'user='.$quote((string)$account['username'])."\n".
        'password='.$quote((string)$account['password'])."\n".
        'default-character-set=utf8mb4'."\n";
    if (file_put_contents($file, $contents, LOCK_EX) === false) throw new RuntimeException('Konfigurasi sementara backup tidak dapat dibuat.');
    @chmod($file, 0600);
    return $file;
}

/** @return array{code:int,output:string} */
function backup_run_process(array $command, ?string $inputFile = null, ?string $stdoutFile = null): array {
    if (!function_exists('proc_open')) throw new RuntimeException('Fitur backup membutuhkan proc_open pada konfigurasi PHP.');
    $tmpRoot = backup_root().'/tmp';
    $outPath = $stdoutFile ?? ($tmpRoot.'/stdout-'.bin2hex(random_bytes(8)).'.log');
    $errPath = $tmpRoot.'/stderr-'.bin2hex(random_bytes(8)).'.log';
    $descriptors = [
        0 => ['pipe','r'],
        1 => ['file', $outPath, 'wb'],
        2 => ['file', $errPath, 'wb'],
    ];
    $process = @proc_open($command, $descriptors, $pipes, $tmpRoot, null, ['bypass_shell'=>true]);
    if (!is_resource($process)) { @unlink($outPath); @unlink($errPath); throw new RuntimeException('Program MySQL tidak dapat dijalankan. Periksa instalasi XAMPP.'); }
    try {
        if ($inputFile !== null) {
            $in = @fopen($inputFile, 'rb');
            if (!$in) throw new RuntimeException('File input restore tidak dapat dibaca.');
            stream_copy_to_stream($in, $pipes[0]);
            fclose($in);
        }
        fclose($pipes[0]);
        $code = proc_close($process);
        $stderr = is_file($errPath) ? trim((string)file_get_contents($errPath)) : '';
        $stdout = (!$stdoutFile && is_file($outPath)) ? trim((string)file_get_contents($outPath)) : '';
        return ['code'=>$code, 'output'=>trim($stderr."\n".$stdout)];
    } finally {
        if (!$stdoutFile) @unlink($outPath);
        @unlink($errPath);
    }
}

function backup_safe_filename(string $name): string {
    if (!preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._-]{0,180}\.(?:sql|json)\z/', $name)) throw new InvalidArgumentException('Nama file backup tidak valid.');
    return $name;
}

function backup_write_metadata(string $path, array $metadata): void {
    $metadata['filename'] = basename($path);
    $metadata['size_bytes'] = filesize($path) ?: 0;
    $metadata['sha256'] = hash_file('sha256', $path) ?: '';
    $json = json_encode($metadata, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    $tmp = $path.'.json.tmp';
    if (file_put_contents($tmp, $json, LOCK_EX) === false || !@rename($tmp, $path.'.json')) throw new RuntimeException('Metadata backup tidak dapat disimpan.');
}

function backup_metadata(string $folder, string $filename): ?array {
    $filename = backup_safe_filename($filename);
    $path = $folder.'/'.$filename;
    $metaPath = $path.'.json';
    if (!is_file($path) || !is_file($metaPath)) return null;
    $meta = json_decode((string)file_get_contents($metaPath), true);
    if (!is_array($meta) || ($meta['filename'] ?? '') !== $filename) return null;
    if (!hash_equals((string)($meta['sha256'] ?? ''), (string)hash_file('sha256', $path))) return null;
    return $meta;
}

function backup_list(string $folder): array {
    $result = [];
    foreach (glob($folder.'/*.json') ?: [] as $metaFile) {
        if (str_ends_with($metaFile, '.sql.json') || str_ends_with($metaFile, '.json.json')) {
            $filename = basename(substr($metaFile, 0, -5));
            $meta = backup_metadata($folder, $filename);
            if ($meta) $result[] = $meta;
        }
    }
    usort($result, static fn($a,$b) => strcmp((string)($b['created_at']??''),(string)($a['created_at']??'')));
    return $result;
}

function backup_create_admin(array $actor, string $label='manual'): array {
    @set_time_limit(300);
    $bin = backup_bin('dump');
    if (!$bin) throw new RuntimeException('mysqldump tidak ditemukan. Pastikan MySQL XAMPP terpasang di C:\\xampp\\mysql\\bin.');
    $cfg = db_config(); $database = (string)$cfg['database'];
    if (!preg_match('/\A[a-zA-Z0-9_]+\z/', $database)) throw new RuntimeException('Nama database tidak valid untuk backup.');
    $account = $cfg['roles']['admin'] ?? $cfg['login'];
    $defaults = backup_defaults_file($account);
    $name = 'parkirstrd-full-'.date('Ymd-His').'-'.bin2hex(random_bytes(3)).'.sql';
    $path = backup_root().'/admin/'.$name;
    try {
        $result = backup_run_process([$bin, '--defaults-extra-file='.$defaults, '--single-transaction', '--quick', '--routines', '--triggers', '--events', '--hex-blob', '--default-character-set=utf8mb4', $database], null, $path);
        if ($result['code'] !== 0 || !is_file($path) || (filesize($path) ?: 0) < 100) {
            @unlink($path);
            throw new RuntimeException('Backup database gagal. Periksa izin akun database dan log MySQL. '.mb_substr($result['output'],0,500));
        }
        backup_write_metadata($path, ['type'=>'admin_full','label'=>$label,'created_at'=>date(DATE_ATOM),'created_by_id'=>(int)$actor['id'],'created_by_name'=>(string)$actor['name'],'database'=>$database,'verified'=>true]);
        return backup_metadata(backup_root().'/admin', $name) ?? throw new RuntimeException('Metadata backup tidak dapat diverifikasi.');
    } finally { @unlink($defaults); }
}

function backup_restore_admin(array $actor, string $filename): array {
    @set_time_limit(600);
    $folder = backup_root().'/admin'; $meta = backup_metadata($folder, $filename);
    if (!$meta || ($meta['type'] ?? '') !== 'admin_full') throw new InvalidArgumentException('Backup tidak terdaftar atau pemeriksaan integritas gagal. Restore hanya tersedia untuk backup penuh ParkirSTRD yang valid.');
    $bin = backup_bin('mysql');
    if (!$bin) throw new RuntimeException('Program mysql.exe tidak ditemukan di folder bin XAMPP.');
    // Do not restore unless a fresh pre-restore backup succeeded.
    $pre = backup_create_admin($actor, 'pre-restore');
    $cfg = db_config(); $database = (string)$cfg['database'];
    if (!preg_match('/\A[a-zA-Z0-9_]+\z/', $database)) throw new RuntimeException('Nama database tidak valid untuk restore.');
    $account = $cfg['roles']['admin'] ?? $cfg['login']; $defaults = backup_defaults_file($account);
    $logFile = backup_root().'/tmp/restore-'.bin2hex(random_bytes(8)).'.log';
    $rollbackLog = backup_root().'/tmp/rollback-'.bin2hex(random_bytes(8)).'.log';
    try {
        $command = [$bin, '--defaults-extra-file='.$defaults, '--default-character-set=utf8mb4', '--database='.$database];
        $result = backup_run_process($command, $folder.'/'.$filename, $logFile);
        if ($result['code'] !== 0) {
            // SQL DDL is not fully transactional. Try restoring the verified pre-restore dump if import fails.
            $rollback = backup_run_process($command, $folder.'/'.$pre['filename'], $rollbackLog);
            $detail = mb_substr($result['output'], 0, 300);
            if ($rollback['code'] === 0) {
                throw new RuntimeException('Restore gagal dan backup pra-restore berhasil diterapkan kembali ('.$pre['filename'].'). Detail: '.$detail);
            }
            throw new RuntimeException('Restore gagal. Pemulihan otomatis dari backup pra-restore belum dapat dipastikan. Jangan hapus file '.$pre['filename'].' dan segera lakukan pemulihan manual. Detail: '.mb_substr($detail.'; rollback: '.$rollback['output'],0,500));
        }
        log_activity('Restore database penuh dari '.$filename.'; backup pra-restore '.$pre['filename']);
        return ['restored'=>$filename,'pre_restore'=>$pre['filename']];
    } finally { @unlink($defaults); @unlink($logFile); @unlink($rollbackLog); }
}

function backup_secret(): string {
    $file = backup_root().'/backup-signing.key';
    if (!is_file($file)) {
        $key = random_bytes(32);
        $fp = @fopen($file, 'x');
        if ($fp) { fwrite($fp, base64_encode($key)); fflush($fp); fclose($fp); @chmod($file,0600); }
    }
    $encoded = @file_get_contents($file);
    $decoded = is_string($encoded) ? base64_decode(trim($encoded), true) : false;
    if (!is_string($decoded) || strlen($decoded) < 32) throw new RuntimeException('Kunci penandatanganan backup tidak tersedia. Periksa direktori penyimpanan.');
    return $decoded;
}

function operator_backup_payload(PDO $pdo, array $actor, string $from, string $to): array {
    [$start,$end] = valid_date_range($from,$to);
    $stmt = $pdo->prepare("SELECT t.id_parkir,t.id_kendaraan,t.id_kartu,t.id_area,t.waktu_masuk,t.waktu_keluar,t.id_tarif,t.tarif_saat_masuk,t.durasi_jam,t.biaya_total,t.status,t.metode_pembayaran,t.status_pembayaran,t.waktu_bayar,t.referensi_pembayaran,t.id_user AS petugas_masuk_id,t.id_user_keluar AS petugas_keluar_id,k.plat_nomor,k.jenis_kendaraan,a.nama_area FROM tb_transaksi t JOIN tb_kendaraan k ON k.id_kendaraan=t.id_kendaraan JOIN tb_area_parkir a ON a.id_area=t.id_area WHERE (t.id_user=? OR t.id_user_keluar=?) AND ((t.waktu_masuk BETWEEN ? AND ?) OR (t.waktu_keluar BETWEEN ? AND ?)) ORDER BY t.waktu_masuk");
    $stmt->execute([(int)$actor['id'],(int)$actor['id'],$start,$end,$start,$end]);
    return ['format'=>'parkirstrd-operator-backup-v1','application'=>'ParkirSTRD','created_at'=>date(DATE_ATOM),'owner_id'=>(int)$actor['id'],'owner_name'=>(string)$actor['name'],'range'=>['from'=>$from,'to'=>$to],'transactions'=>$stmt->fetchAll()];
}

function operator_backup_save(PDO $pdo, array $actor, string $from, string $to): array {
    $payload = operator_backup_payload($pdo,$actor,$from,$to);
    $canonical = json_encode($payload, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    $doc = ['payload'=>$payload,'signature'=>hash_hmac('sha256',$canonical,backup_secret())];
    $name = 'operasional-petugas-'.(int)$actor['id'].'-'.date('Ymd-His').'-'.bin2hex(random_bytes(3)).'.json';
    $path = backup_root().'/petugas/'.$name;
    if (file_put_contents($path,json_encode($doc,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),LOCK_EX) === false) throw new RuntimeException('File backup operasional tidak dapat dibuat.');
    backup_write_metadata($path,['type'=>'operator_operational','created_at'=>date(DATE_ATOM),'created_by_id'=>(int)$actor['id'],'created_by_name'=>(string)$actor['name'],'range'=>$payload['range'],'transaction_count'=>count($payload['transactions'])]);
    return backup_metadata(backup_root().'/petugas',$name) ?? throw new RuntimeException('Backup operasional gagal diverifikasi.');
}


/** Restore only completed transactions missing from the live DB. Active vehicles are routed to Admin review. */
function operator_restore_completed(PDO $pdo, array $payload, array $actor): array {
    $uid=(int)$actor['id']; $restored=0; $already=0; $active=[];
    $exists=$pdo->prepare('SELECT id_parkir FROM tb_transaksi WHERE id_parkir=?');
    $insert=$pdo->prepare("INSERT INTO tb_transaksi (id_parkir,id_kendaraan,id_kartu,id_area,waktu_masuk,waktu_keluar,id_tarif,tarif_saat_masuk,durasi_jam,biaya_total,status,metode_pembayaran,status_pembayaran,waktu_bayar,referensi_pembayaran,id_user,id_user_keluar) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $pdo->beginTransaction();
    try {
        foreach ($payload['transactions'] as $r) {
            if (!is_array($r)) throw new InvalidArgumentException('Record backup tidak valid.');
            if ((int)($r['petugas_masuk_id']??0)!==$uid && (int)($r['petugas_keluar_id']??0)!==$uid) throw new InvalidArgumentException('Backup berisi transaksi di luar cakupan petugas ini.');
            if (($r['status']??'')==='masuk') { $active[]=$r; continue; }
            if (($r['status']??'')!=='keluar') throw new InvalidArgumentException('Status transaksi dalam backup tidak dikenal.');
            $exists->execute([(int)($r['id_parkir']??0)]);
            if ($exists->fetchColumn()!==false) { $already++; continue; }
            foreach (['id_parkir','id_kendaraan','id_area','id_tarif','waktu_masuk','waktu_keluar','tarif_saat_masuk','durasi_jam','biaya_total','petugas_masuk_id'] as $required) {
                if (!array_key_exists($required,$r) || $r[$required]===null || $r[$required]==='') throw new InvalidArgumentException('Data penting pada backup tidak lengkap: '.$required.'.');
            }
            if ((int)$r['id_parkir']<1 || (float)$r['biaya_total']<0 || (float)$r['tarif_saat_masuk']<0) throw new InvalidArgumentException('Nominal atau ID transaksi pada backup tidak valid.');
            $insert->execute([
                (int)$r['id_parkir'],(int)$r['id_kendaraan'],isset($r['id_kartu'])&&$r['id_kartu']!==null?(int)$r['id_kartu']:null,
                (int)$r['id_area'],(string)$r['waktu_masuk'],(string)$r['waktu_keluar'],(int)$r['id_tarif'],(float)$r['tarif_saat_masuk'],
                (int)$r['durasi_jam'],(float)$r['biaya_total'],'keluar',
                in_array(($r['metode_pembayaran']??'tunai'),['tunai','qris'],true)?$r['metode_pembayaran']:'tunai',
                in_array(($r['status_pembayaran']??'lunas'),['belum_bayar','menunggu_verifikasi','lunas'],true)?$r['status_pembayaran']:'lunas',
                $r['waktu_bayar']??null,$r['referensi_pembayaran']??null,(int)$r['petugas_masuk_id'],
                isset($r['petugas_keluar_id'])&&$r['petugas_keluar_id']!==null?(int)$r['petugas_keluar_id']:null
            ]);
            $restored++;
        }
        $log=$pdo->prepare('INSERT INTO tb_log_aktivitas(id_user,aktivitas) VALUES(?,?)');
        $log->execute([$uid,'Restore backup operasional: '.$restored.' transaksi selesai dipulihkan, '.$already.' sudah ada; kendaraan aktif '.count($active).' menunggu review Admin']);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return ['restored'=>$restored,'already'=>$already,'active'=>$active];
}

function operator_backup_verify(string $file, int $expectedOwnerId): array {
    if (!is_file($file) || (filesize($file) ?: 0) > 20*1024*1024) throw new InvalidArgumentException('File backup tidak ditemukan atau melebihi batas 20 MB.');
    $doc = json_decode((string)file_get_contents($file), true);
    if (!is_array($doc) || !is_array($doc['payload'] ?? null) || !is_string($doc['signature'] ?? null)) throw new InvalidArgumentException('Struktur file backup tidak valid.');
    $payload = $doc['payload'];
    if (($payload['format'] ?? '') !== 'parkirstrd-operator-backup-v1' || (int)($payload['owner_id'] ?? 0) !== $expectedOwnerId || !is_array($payload['transactions'] ?? null)) throw new InvalidArgumentException('Backup bukan milik petugas yang sedang login atau format tidak didukung.');
    $canonical = json_encode($payload, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    $expected = hash_hmac('sha256',$canonical,backup_secret());
    if (!hash_equals($expected,$doc['signature'])) throw new InvalidArgumentException('Validasi integritas backup gagal. File mungkin berubah atau dibuat oleh instalasi lain.');
    if (count($payload['transactions']) > 100000) throw new InvalidArgumentException('Jumlah record pada backup melebihi batas aman.');
    return $payload;
}

function operator_restore_request(array $payload, array $actor): array {
    $id = 'request-'.date('Ymd-His').'-'.bin2hex(random_bytes(5));
    $doc = ['request_id'=>$id,'status'=>'pending_review','requested_at'=>date(DATE_ATOM),'requested_by_id'=>(int)$actor['id'],'requested_by_name'=>(string)$actor['name'],'range'=>$payload['range']??[],'transaction_count'=>count($payload['transactions']),'payload'=>$payload];
    $path = backup_root().'/recovery-requests/'.$id.'.json';
    if (file_put_contents($path,json_encode($doc,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),LOCK_EX) === false) throw new RuntimeException('Permintaan pemulihan tidak dapat disimpan.');
    log_activity('Mengajukan pemulihan backup operasional '.$id.'; menunggu pemeriksaan Admin');
    return $doc;
}

function backup_requests(): array {
    $items=[];
    foreach (glob(backup_root().'/recovery-requests/request-*.json') ?: [] as $path) {
        $doc=json_decode((string)file_get_contents($path),true);
        if (is_array($doc) && isset($doc['request_id'],$doc['status'])) { $doc['_file']=basename($path); unset($doc['payload']); $items[]=$doc; }
    }
    usort($items,static fn($a,$b)=>strcmp((string)$b['requested_at'],(string)$a['requested_at']));
    return $items;
}

function backup_review_request(string $requestFile, string $status, array $actor): void {
    if (!preg_match('/\Arequest-[a-zA-Z0-9-]+\.json\z/',$requestFile) || !in_array($status,['reviewed','rejected'],true)) throw new InvalidArgumentException('Permintaan pemulihan tidak valid.');
    $path=backup_root().'/recovery-requests/'.$requestFile;
    $doc=json_decode((string)@file_get_contents($path),true);
    if (!is_array($doc) || ($doc['status']??'')!=='pending_review') throw new InvalidArgumentException('Permintaan tidak ada atau telah diproses.');
    $doc['status']=$status; $doc['reviewed_at']=date(DATE_ATOM); $doc['reviewed_by_id']=(int)$actor['id']; $doc['reviewed_by_name']=(string)$actor['name'];
    if (file_put_contents($path,json_encode($doc,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),LOCK_EX)===false) throw new RuntimeException('Status permintaan tidak dapat diperbarui.');
    log_activity('Permintaan pemulihan '.$doc['request_id'].' ditandai '.$status.' oleh Admin');
}
