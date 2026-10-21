<?php
declare(strict_types=1);
/** TEMPLATE: salin nilainya ke config/database.php dan isi password DB lokal Anda. */
return [
    'host' => '127.0.0.1',
    'port' => 3306,
    'database' => 'parkir',
    'charset' => 'utf8mb4',
    'login' => ['username' => 'login_parkir', 'password' => 'ISI_PASSWORD_DATABASE_LOGIN'],
    // Akun webhook: SELECT terbatas + update status pembayaran; lihat hak_akses_midtrans.sql.
    'system' => ['username' => 'parkirstrd_system', 'password' => 'ISI_PASSWORD_DATABASE_SYSTEM'],
    'roles' => [
        'admin' => ['username' => 'admin_parkir', 'password' => 'ISI_PASSWORD_DATABASE_ADMIN'],
        'petugas' => ['username' => 'petugas_parkir', 'password' => 'ISI_PASSWORD_DATABASE_PETUGAS'],
        'owner' => ['username' => 'owner_parkir', 'password' => 'ISI_PASSWORD_DATABASE_OWNER'],
    ],
];
