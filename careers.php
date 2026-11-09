<?php
// Public careers page (Halaman karir): open jobs and the application form with CV upload. No login.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/recruit.php';
require __DIR__ . '/inc/career.php';
// with the careers site connected, applicants go there (its applications come back through Rekrutmen)
if (career_enabled()) redirect(career_url() . '/');
ensure_recruit_tables();

$company = isset($CONFIG['company']) ? $CONFIG['company'] : 'Perusahaan';
$openSql = "SELECT j.*, p1.pembagian1_nama jab, p2.pembagian2_nama dep, p3.pembagian3_nama kan FROM web_job j
            LEFT JOIN pembagian1 p1 ON p1.pembagian1_id = j.pembagian1_id LEFT JOIN pembagian2 p2 ON p2.pembagian2_id = j.pembagian2_id
            LEFT JOIN pembagian3 p3 ON p3.pembagian3_id = j.pembagian3_id
            WHERE j.status = 'open' AND (j.close_date IS NULL OR j.close_date >= CURDATE())";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $job = q("$openSql AND j.id = ?", [(int)post('job')])->fetch();
    $back = 'careers.php?job=' . (int)post('job') . '#apply';
    $name = trim(post('name'));
    $email = trim(post('email'));
    if (post('website') !== '') redirect('careers.php?done=1'); // honeypot: bots fill every field
    if (!$job) { flash('Lowongan ini sudah ditutup.', 'err'); redirect('careers.php'); }
    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || trim(post('phone')) === '') { flash('Isi nama, email yang benar dan nomor HP.', 'err'); redirect($back); }
    if (q('SELECT COUNT(*) FROM web_candidate WHERE job_id = ? AND email = ?', [$job['id'], $email])->fetchColumn()) { flash('Email ini sudah melamar lowongan ini.', 'err'); redirect($back); }
    if (empty($_FILES['cv']) || $_FILES['cv']['error'] === UPLOAD_ERR_NO_FILE) { flash('Lampirkan CV Anda.', 'err'); redirect($back); }
    list($file, $err) = save_cv($_FILES['cv']);
    if ($err) { flash($err, 'err'); redirect($back); }
    $now = date('Y-m-d H:i:s');
    q('INSERT INTO web_candidate (job_id, name, email, phone, cv_file, cover, source, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$job['id'], mb_substr($name, 0, 120), mb_substr($email, 0, 120), mb_substr(trim(post('phone')), 0, 30), $file, mb_substr(post('cover'), 0, 5000), 'Halaman karir', $now, $now]);
    redirect('careers.php?done=1');
}

$job = get('job') !== '' ? q("$openSql AND j.id = ?", [(int)get('job')])->fetch() : null;
$jobs = $job ? [] : q("$openSql ORDER BY j.created_at DESC")->fetchAll();
?><!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Karir · <?= h($company) ?></title>
<link rel="stylesheet" href="assets/portal.css?v=<?= (int)@filemtime(__DIR__ . '/assets/portal.css') ?>">
<link rel="icon" type="image/png" href="assets/logo.png">
<style>textarea { font: inherit; padding: 8px 10px; border: 1px solid var(--line); border-radius: 8px; min-height: 110px; } .hp { position: absolute; left: -9999px; } .job-desc { white-space: pre-line; }</style>
</head>
<body>
<header class="p-top"><a class="p-brand" href="careers.php"><?= h($company) ?> · Karir</a></header>
<div class="p-wrap">
  <?php foreach (take_flashes() as $f): ?><div class="p-alert <?= h($f[0]) ?>"><?= h($f[1]) ?></div><?php endforeach; ?>
  <?php if (get('done')): ?>
    <div class="p-card"><h2>Lamaran terkirim</h2><p>Terima kasih. Tim HR akan menghubungi Anda lewat email atau HP bila lamaran Anda lolos seleksi berkas.</p>
      <a class="p-btn" href="careers.php">Lihat lowongan lain</a></div>
  <?php elseif ($job): ?>
    <a href="careers.php">← Semua lowongan</a>
    <div class="p-card" style="margin-top:10px"><h1><?= h($job['title']) ?></h1>
      <div class="muted"><?= h(implode(' · ', array_filter([$job['dep'], $job['kan'], $job['employment']]))) ?><?= $job['close_date'] ? ' · ditutup ' . h(date('d-m-Y', strtotime($job['close_date']))) : '' ?></div>
      <?php if ($job['description'] !== ''): ?><h2>Deskripsi pekerjaan</h2><div class="job-desc"><?= h($job['description']) ?></div><?php endif; ?>
      <?php if ($job['requirements'] !== ''): ?><h2>Kualifikasi</h2><div class="job-desc"><?= h($job['requirements']) ?></div><?php endif; ?></div>
    <div class="p-card" id="apply"><h2>Lamar sekarang</h2>
      <form method="post" enctype="multipart/form-data" class="p-form two"><?= csrf_field() ?><input type="hidden" name="job" value="<?= (int)$job['id'] ?>">
        <label>Nama lengkap<input type="text" name="name" required maxlength="120"></label>
        <label>Email<input type="email" name="email" required maxlength="120"></label>
        <label>No. HP / WhatsApp<input type="tel" name="phone" required maxlength="30"></label>
        <label>CV (PDF, JPG atau PNG, maks. 5 MB)<input type="file" name="cv" required accept=".pdf,.jpg,.jpeg,.png"></label>
        <label class="wide">Surat lamaran / pesan singkat (opsional)<textarea name="cover" maxlength="5000"></textarea></label>
        <label class="hp" aria-hidden="true">Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label>
        <div class="wide"><button class="p-btn primary">Kirim lamaran</button></div>
      </form></div>
  <?php else: ?>
    <h1>Lowongan di <?= h($company) ?></h1>
    <p class="muted"><?= count($jobs) ?> lowongan dibuka</p>
    <div class="p-list">
      <?php foreach ($jobs as $j): ?>
        <a class="p-item" href="careers.php?job=<?= (int)$j['id'] ?>"><div class="p-main"><b><?= h($j['title']) ?></b>
          <div class="muted"><?= h(implode(' · ', array_filter([$j['dep'], $j['kan'], $j['employment']]))) ?></div></div><span class="p-btn small">Lihat</span></a>
      <?php endforeach; ?>
      <?php if (!$jobs): ?><div class="p-item muted">Belum ada lowongan yang dibuka saat ini.</div><?php endif; ?>
    </div>
  <?php endif; ?>
</div>
</body>
</html>
