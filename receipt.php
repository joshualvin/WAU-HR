<?php
// Shows a reimbursement receipt to an admin or to the employee who uploaded it.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/portal.php';
require_once __DIR__ . '/inc/claims.php';
ensure_claim_tables();

$row = q('SELECT pegawai_id, file FROM web_reimburse WHERE id = ?', [(int)get('id')])->fetch();
$admin = current_user() && current_user()['role'] === 'admin';
$emp = current_emp();
if (!$row || !$row['file'] || (!$admin && (!$emp || $emp['id'] !== (int)$row['pegawai_id']))) { http_response_code(404); exit('Bukti tidak ditemukan.'); }
$path = RECEIPT_DIR . '/' . basename($row['file']);
if (!is_file($path)) { http_response_code(404); exit('File bukti tidak ada di server.'); }
$types = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'pdf' => 'application/pdf'];
header('Content-Type: ' . $types[pathinfo($path, PATHINFO_EXTENSION)]);
header('Content-Disposition: inline; filename="bukti-' . (int)get('id') . '.' . pathinfo($path, PATHINFO_EXTENSION) . '"');
header('X-Content-Type-Options: nosniff');
readfile($path);
