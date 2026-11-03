<?php
// Sends a company file to HR, or to an employee when the file is shared. Files live under uploads/files, which Apache does not serve.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/portal.php';
require __DIR__ . '/inc/files.php';
ensure_files_tables();

$row = q('SELECT * FROM web_file WHERE id = ?', [(int)get('id')])->fetch();
$staff = (bool)current_user();
$emp = current_emp();
if (!file_can_view($row, $staff, (bool)$emp)) { http_response_code(404); exit('File tidak ditemukan.'); }
$path = FILES_DIR . '/' . basename($row['file']);
if (!is_file($path)) { http_response_code(404); exit('File tidak ada di server.'); }
$ext = pathinfo($path, PATHINFO_EXTENSION);
$types = array_flip(files_types());
header('Content-Type: ' . (isset($types[$ext]) ? $types[$ext] : 'application/octet-stream'));
header('Content-Disposition: attachment; filename="' . file_download_name($row['orig'] !== '' ? $row['orig'] : $row['title'], $ext) . '"');
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
readfile($path);
