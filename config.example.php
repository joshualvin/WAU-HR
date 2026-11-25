<?php
// Copy this file to config.php and fill in the MySQL login.
// On the XAMPP server itself, use 127.0.0.1 as the host.
return [
    'db_host'  => '127.0.0.1',
    'db_port'  => 3306,
    'db_name'  => 'fin_pro',
    'db_user'  => 'root',
    'db_pass'  => '',
    'timezone' => 'Asia/Jakarta',
    // A day with a single scan before this time counts as "check-out missing", after it as "check-in missing".
    'midday'   => '12:00',
    // For Mesin → Download Fingerspot.io: the "API Key untuk Fingerspot Personnel" (Fingerspot.io → Pengaturan →
    // Fingerspot One & Fingerspot Personnel), NOT the API SDK key from Pengaturan Umum → API SDK.
    'fingerspot_api_key' => '',
    // Fingerspot Personnel's own MySQL (for Mesin → Sinkron dari Program)
    'sync_port' => 3309,
    'sync_db'   => 'fin_pro',
    'sync_user' => 'root',
    'sync_pass' => '',
    // Optional, printed on the bukti potong PPh 21: company NPWP and the name of the person who signs it
    'company_npwp' => '',
    'tax_signer'   => '',
    // WAU ERP on this server, for the sales bonus: its address and the hr_api_token from its secret
    'erp_url'   => 'http://127.0.0.1/wau-erp',
    'erp_token' => '',
    // Careers site (cv-upload) link for Rekrutmen: its address and the HR_API_TOKEN set there
    'career_url'   => 'https://career.wautama.com',
    'career_token' => '',
    'gudang_expect_open' => '08:00',   // the home card shows "Belum buka" when nobody has scanned by this time
    'gudang_openers' => ['9001', '9008', '9005'],   // who opens the warehouse: the head first (Heni), then the backups (Andhy, Susi); the opener's first Margo scan = opening time
    'gudang_open_grace' => 5,   // minutes after the warehouse opened that still count as on time (staff queue at the one machine)
    'gudang_late_from_open' => true,   // warehouse staff are late only from the time the warehouse was opened (when that is after their start)   // report "Jam Gudang": PIN whose first / last scan on the Margo machines is the warehouse open / close time
    'anthropic_api_key' => '',   // HR assistant (assistant.php): API key from console.anthropic.com; also run "composer install"
];
