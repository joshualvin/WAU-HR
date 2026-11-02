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
    // Token from the fingerspot.io developer portal, for Mesin → Download Fingerspot.io
    'fingerspot_token' => '',
    // Fingerspot Personnel's own MySQL (for Mesin → Sinkron dari Program)
    'sync_port' => 3309,
    'sync_db'   => 'fin_pro',
    'sync_user' => 'root',
    'sync_pass' => '',
    // Careers site (cv-upload) link for Rekrutmen: its address and the HR_API_TOKEN set there
    'career_url'   => 'https://career.wautama.com',
    'career_token' => '',
];
