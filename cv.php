<?php
// Shows a candidate's CV to an admin.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/recruit.php';
require_login('admin');
ensure_recruit_tables();

$row = q('SELECT cv_file FROM web_candidate WHERE id = ?', [(int)get('id')])->fetch();
$path = $row && $row['cv_file'] ? CV_DIR . '/' . basename($row['cv_file']) : '';
if (!$path || !is_file($path)) { http_response_code(404); exit('CV tidak ditemukan.'); }
$types = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'pdf' => 'application/pdf'];
header('Content-Type: ' . $types[pathinfo($path, PATHINFO_EXTENSION)]);
header('Content-Disposition: inline; filename="cv-' . (int)get('id') . '.' . pathinfo($path, PATHINFO_EXTENSION) . '"');
header('X-Content-Type-Options: nosniff');
readfile($path);
