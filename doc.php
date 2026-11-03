<?php
// Shows an employee document to an admin or to the employee it belongs to.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/portal.php';
require __DIR__ . '/inc/people.php';
ensure_people_tables();

$row = q('SELECT pegawai_id, file, kind FROM web_emp_doc WHERE id = ?', [(int)get('id')])->fetch();
$admin = current_user() && current_user()['role'] === 'admin';
$emp = current_emp();
if (!$row || (!$admin && (!$emp || $emp['id'] !== (int)$row['pegawai_id']))) { http_response_code(404); exit('Dokumen tidak ditemukan.'); }
$path = DOC_DIR . '/' . basename($row['file']);
if (!is_file($path)) { http_response_code(404); exit('File dokumen tidak ada di server.'); }
$types = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'pdf' => 'application/pdf'];
$ext = pathinfo($path, PATHINFO_EXTENSION);
header('Content-Type: ' . $types[$ext]);
header('Content-Disposition: inline; filename="' . $row['kind'] . '-' . (int)get('id') . '.' . $ext . '"');
header('X-Content-Type-Options: nosniff');
readfile($path);
