<?php
// Shows a candidate's CV to an admin.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/recruit.php';
require __DIR__ . '/inc/career.php';
require_login('admin');
ensure_recruit_tables();

$row = q('SELECT id, cv_file, cv_ext FROM web_candidate WHERE id = ?', [(int)get('id')])->fetch();
// applicants from the careers site: fetch the CV the first time it is opened
if ($row && !$row['cv_file'] && $row['cv_ext'] && career_enabled()) {
    list($row['cv_file'], $err) = career_fetch_cv($row);
    if ($err) { http_response_code(502); exit(h($err)); }
}
$path = $row && $row['cv_file'] ? CV_DIR . '/' . basename($row['cv_file']) : '';
if (!$path || !is_file($path)) { http_response_code(404); exit('CV tidak ditemukan.'); }
$types = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'pdf' => 'application/pdf', 'doc' => 'application/msword',
          'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
$ext = pathinfo($path, PATHINFO_EXTENSION);
header('Content-Type: ' . $types[$ext]);
header('Content-Disposition: ' . (in_array($ext, ['doc', 'docx'], true) ? 'attachment' : 'inline') . '; filename="cv-' . (int)get('id') . '.' . pathinfo($path, PATHINFO_EXTENSION) . '"');
header('X-Content-Type-Options: nosniff');
readfile($path);
