<?php
// First run: creates the web_user / web_log tables and the "admin" user with no password.
// A password can be set later on the Users page. Does nothing once any user exists.
require __DIR__ . '/inc/bootstrap.php';

ensure_web_tables();
if (q('SELECT COUNT(*) FROM web_user')->fetchColumn() == 0) {
    q('INSERT INTO web_user (username, full_name, password_hash, role, created_at) VALUES (?, ?, ?, ?, ?)',
        ['admin', 'Administrator', password_hash('', PASSWORD_DEFAULT), 'admin', date('Y-m-d H:i:s')]);
}
redirect('login.php');
