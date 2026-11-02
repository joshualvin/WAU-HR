<?php
// HR → Rekrutmen: job openings, the candidate pipeline per job, one candidate (notes, rating, interview, hire) and
// manpower planning. Admins only; candidates apply on the public careers.php.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/portal.php';
require __DIR__ . '/inc/hris.php';
require __DIR__ . '/inc/talent.php';
require __DIR__ . '/inc/recruit.php';
require __DIR__ . '/inc/career.php';
require __DIR__ . '/inc/employment.php';
$me = require_login('admin');
ensure_web_tables();
ensure_recruit_tables();

$p = get('p', 'jobs');
$stages = stages();
$jobSql = 'SELECT j.*, p1.pembagian1_nama jab, COALESCE(p2.pembagian2_nama, j.dept_text) dep, COALESCE(p3.pembagian3_nama, j.loc_text) kan FROM web_job j
           LEFT JOIN pembagian1 p1 ON p1.pembagian1_id = j.pembagian1_id LEFT JOIN pembagian2 p2 ON p2.pembagian2_id = j.pembagian2_id
           LEFT JOIN pembagian3 p3 ON p3.pembagian3_id = j.pembagian3_id';

// ---------- actions ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = post('action');
    $cand = q('SELECT * FROM web_candidate WHERE id = ?', [(int)post('id')])->fetch();
    $candUrl = $cand ? 'recruit.php?p=candidate&id=' . $cand['id'] : 'recruit.php';
    $now = date('Y-m-d H:i:s');
    if ($action === 'job_save') {
        $f = [trim(post('title')), (int)post('pembagian1_id'), (int)post('pembagian2_id'), (int)post('pembagian3_id'), max(1, (int)post('headcount')),
              mb_substr(post('employment') ?: 'Karyawan tetap', 0, 30), post('description'), post('responsibilities'), post('requirements'),
              post('status') === 'closed' ? 'closed' : 'open', valid_date(post('close_date')) ? post('close_date') : null];
        $back = 'recruit.php?p=job_edit&id=' . (int)post('job_id');
        if ($f[0] === '') { flash('Isi judul lowongan.', 'err'); redirect($back); }
        if (q('SELECT COUNT(*) FROM web_job WHERE title = ? AND id <> ?', [$f[0], (int)post('job_id')])->fetchColumn()) { flash('Sudah ada lowongan dengan judul ini.', 'err'); redirect($back); }
        if ((int)post('job_id')) {
            q('UPDATE web_job SET title = ?, pembagian1_id = ?, pembagian2_id = ?, pembagian3_id = ?, headcount = ?, employment = ?, description = ?, responsibilities = ?, requirements = ?, status = ?, close_date = ? WHERE id = ?',
                array_merge($f, [(int)post('job_id')]));
            $jobId = (int)post('job_id');
        } else {
            q('INSERT INTO web_job (title, pembagian1_id, pembagian2_id, pembagian3_id, headcount, employment, description, responsibilities, requirements, status, close_date, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                array_merge($f, [$now]));
            $jobId = (int)db()->lastInsertId();
        }
        audit('job_save', $f[0]);
        flash('Lowongan disimpan.');
        if (career_enabled()) {
            list($msg, $err) = career_sync(false);
            $err ? flash('Belum terkirim ke situs karir: ' . $err, 'err') : flash('Situs karir diperbarui.');
        }
        redirect('recruit.php?p=job&id=' . $jobId);
    } elseif ($action === 'career_sync') {
        list($msg, $err) = career_sync();
        $err ? flash(($msg ? "$msg. " : '') . $err, 'err') : flash("Sinkron selesai: $msg.");
        redirect('recruit.php?p=jobs');
    } elseif ($action === 'cand_add') {
        $job = q('SELECT id FROM web_job WHERE id = ?', [(int)post('job_id')])->fetch();
        $back = 'recruit.php?p=job&id=' . (int)post('job_id');
        if (!$job || trim(post('name')) === '') { flash('Isi nama kandidat.', 'err'); redirect($back); }
        list($file, $err) = save_cv(isset($_FILES['cv']) ? $_FILES['cv'] : null);
        if ($err) { flash($err, 'err'); redirect($back); }
        q('INSERT INTO web_candidate (job_id, name, email, phone, cv_file, source, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$job['id'], trim(post('name')), trim(post('email')), trim(post('phone')), $file, post('source') ?: 'Input HR', $now, $now]);
        audit('candidate_add', post('name'));
        flash('Kandidat ditambahkan.');
        redirect($back);
    } elseif ($action === 'cand_update' && $cand && $cand['stage'] !== 'hired') {
        $stage = isset($stages[post('stage')]) && post('stage') !== 'hired' ? post('stage') : $cand['stage'];
        $at = post('interview_at') !== '' && strtotime(post('interview_at')) ? date('Y-m-d H:i:s', strtotime(post('interview_at'))) : null;
        $jobId = (int)post('job_id') && q('SELECT id FROM web_job WHERE id = ?', [(int)post('job_id')])->fetchColumn() ? (int)post('job_id') : 0;
        q('UPDATE web_candidate SET job_id = ?, stage = ?, rating = ?, interview_at = ?, updated_at = ? WHERE id = ?', [$jobId, $stage, max(0, min(5, (int)post('rating'))), $at, $now, $cand['id']]);
        if ($stage !== $cand['stage']) q('INSERT INTO web_candidate_note (candidate_id, note, created_by, created_at) VALUES (?, ?, ?, ?)',
            [$cand['id'], 'Tahap: ' . $stages[$cand['stage']] . ' → ' . $stages[$stage], actor(), $now]);
        flash('Kandidat diperbarui.');
        redirect($candUrl);
    } elseif ($action === 'note_add' && $cand) {
        if (trim(post('note')) !== '') q('INSERT INTO web_candidate_note (candidate_id, note, created_by, created_at) VALUES (?, ?, ?, ?)', [$cand['id'], trim(post('note')), actor(), $now]);
        redirect($candUrl);
    } elseif ($action === 'hire' && $cand && $cand['stage'] !== 'hired') {
        $job = q('SELECT * FROM web_job WHERE id = ?', [$cand['job_id']])->fetch() ?: general_job();
        $start = valid_date(post('start')) ? post('start') : date('Y-m-d');
        $end = valid_date(post('end')) && post('end') >= $start ? post('end') : null;
        $id = hire_candidate($cand, $job, $start, $end);
        q('INSERT INTO web_candidate_note (candidate_id, note, created_by, created_at) VALUES (?, ?, ?, ?)', [$cand['id'], 'Diterima, mulai bekerja ' . fmt_date($start), actor(), $now]);
        $hired = (int)q("SELECT COUNT(*) FROM web_candidate WHERE job_id = ? AND stage = 'hired'", [$job['id']])->fetchColumn();
        if ($job['id'] && $hired >= (int)$job['headcount']) q("UPDATE web_job SET status = 'closed' WHERE id = ?", [$job['id']]);
        audit('candidate_hire', "{$cand['name']} → pegawai $id");
        flash("{$cand['name']} menjadi karyawan" . ($job['id'] && $hired >= (int)$job['headcount'] ? '; kebutuhan lowongan terpenuhi, lowongan ditutup' : '') . '. Lengkapi datanya dan jalankan onboarding.');
        redirect('talent.php?p=checklist&type=onboarding&id=' . $id);
    } elseif ($action === 'cand_delete' && $cand && $cand['stage'] !== 'hired') {
        if ($cand['cv_file'] && is_file(CV_DIR . '/' . basename($cand['cv_file']))) unlink(CV_DIR . '/' . basename($cand['cv_file']));
        q('DELETE FROM web_candidate_note WHERE candidate_id = ?', [$cand['id']]);
        q('DELETE FROM web_candidate WHERE id = ?', [$cand['id']]);
        audit('candidate_delete', $cand['name']);
        flash('Data kandidat dan CV-nya dihapus.');
        redirect('recruit.php?p=job&id=' . $cand['job_id']);
    } elseif ($action === 'mp_save') {
        $year = (int)post('year');
        foreach (isset($_POST['planned']) ? (array)$_POST['planned'] : [] as $dep => $n) {
            q('REPLACE INTO web_manpower (tahun, pembagian2_id, planned) VALUES (?, ?, ?)', [$year, (int)$dep, max(0, (int)$n)]);
        }
        audit('manpower_save', (string)$year);
        flash('Rencana manpower disimpan.');
        redirect('recruit.php?p=manpower&year=' . $year);
    }
    redirect('recruit.php');
}

$csrf = csrf_field();

// ======================================================================= Jobs
if ($p === 'jobs'):
    $status = get('status', 'open');
    $jobs = q("$jobSql " . ($status !== '' ? 'WHERE j.status = ? ' : '') . 'ORDER BY j.created_at DESC', $status !== '' ? [$status] : [])->fetchAll();
    $counts = [];
    // new applicants from the careers site, at most every 5 minutes; the button syncs right away
    $last = career_last_sync();
    if (career_enabled() && (!$last || $last['at'] < time() - 300)) { career_sync(); $last = career_last_sync(); }
    foreach (q('SELECT job_id, stage, COUNT(*) n FROM web_candidate GROUP BY job_id, stage') as $r) $counts[$r['job_id']][$r['stage']] = (int)$r['n'];
    if (isset($counts[0]) && $status !== 'open') $jobs[] = general_job() + ['created_at' => ''];
    hris_header('Lowongan & kandidat', 'recruit', 'recruit.php?p=jobs', 'Rekrutmen',
        '<a class="btn" href="careers.php" target="_blank">Halaman karir</a> <a class="btn primary" href="recruit.php?p=job_edit">+ Lowongan baru</a>'); ?>
  <?php if (career_enabled()): ?>
    <form method="post" class="toolbar" style="margin-bottom:10px"><?= $csrf ?><input type="hidden" name="action" value="career_sync">
      <span class="muted">Situs karir <a href="<?= h(career_url()) ?>" target="_blank"><?= h(preg_replace('#^https?://#', '', career_url())) ?></a>:
        <?= $last ? 'sinkron ' . date('d-m H:i', $last['at']) . ($last['error'] ? ' — <b style="color:var(--bad)">' . h($last['error']) . '</b>' : ' — ' . h($last['msg'])) : 'belum pernah sinkron' ?></span>
      <button class="btn small">Sinkronkan sekarang</button></form>
  <?php endif; ?>
  <?php if (isset($counts[0]) && $status === 'open'): ?><p style="margin:0 0 10px"><a href="recruit.php?p=job&id=0">Lamaran umum: <?= array_sum($counts[0]) ?> pelamar tanpa lowongan yang cocok →</a></p><?php endif; ?>
  <form class="toolbar" method="get"><input type="hidden" name="p" value="jobs">
    <select name="status" onchange="this.form.submit()"><?php foreach (['open' => 'Dibuka', 'closed' => 'Ditutup', '' => 'Semua'] as $k => $l): ?><option value="<?= $k ?>"<?= $status === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></form>
  <div class="tablecard"><div class="tablescroll"><table>
    <thead><tr><th>Lowongan</th><th>Departemen</th><th>Kantor</th><th class="num">Kebutuhan</th>
      <?php foreach ($stages as $l): ?><th class="num"><?= h($l) ?></th><?php endforeach; ?><th>Ditutup</th><th>Status</th><th class="actions">Aksi</th></tr></thead><tbody>
    <?php foreach ($jobs as $j): $c = isset($counts[$j['id']]) ? $counts[$j['id']] : []; ?>
      <tr><td><a href="recruit.php?p=job&id=<?= (int)$j['id'] ?>"><b><?= h($j['title']) ?></b></a><br><small class="muted"><?= h($j['id'] ? $j['employment'] : 'posisi lain / tanpa lowongan') ?></small></td>
        <td><?= h($j['dep']) ?></td><td><?= h($j['kan']) ?></td><td class="num"><?= $j['id'] ? (int)$j['headcount'] : '' ?></td>
        <?php foreach ($stages as $k => $l): ?><td class="num"><?= isset($c[$k]) ? $c[$k] : '' ?></td><?php endforeach; ?>
        <td><?= $j['close_date'] ? date('d-m-Y', strtotime($j['close_date'])) : '' ?></td>
        <td><span class="pill <?= $j['status'] === 'open' ? 'ok' : 'grey' ?>"><?= $j['status'] === 'open' ? 'Dibuka' : 'Ditutup' ?></span></td>
        <td class="actions"><a class="btn small" href="recruit.php?p=job&id=<?= (int)$j['id'] ?>">Kandidat</a><?php if ($j['id']): ?> <a class="btn small" href="recruit.php?p=job_edit&id=<?= (int)$j['id'] ?>">Ubah</a><?php endif; ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div><?php if (!$jobs): ?><div class="empty"><strong>Belum ada lowongan</strong>Buat lowongan baru; lowongan yang dibuka tampil di halaman karir.</div><?php endif; ?></div>
<?php
// ======================================================================= Job form
elseif ($p === 'job_edit'):
    $j = q('SELECT * FROM web_job WHERE id = ?', [(int)get('id')])->fetch() ?: ['id' => 0, 'title' => '', 'pembagian1_id' => 0, 'pembagian2_id' => 0, 'pembagian3_id' => 0, 'headcount' => 1,
        'employment' => 'Karyawan tetap', 'description' => '', 'responsibilities' => '', 'requirements' => '', 'status' => 'open', 'close_date' => ''];
    hris_header($j['id'] ? 'Ubah lowongan' : 'Lowongan baru', 'recruit', 'recruit.php?p=jobs', '<a href="recruit.php?p=jobs">Rekrutmen</a>'); ?>
  <form method="post" class="section"><?= $csrf ?><input type="hidden" name="action" value="job_save"><input type="hidden" name="job_id" value="<?= (int)$j['id'] ?>">
    <label class="field"><span>Judul lowongan <i>*</i></span><input type="text" name="title" required maxlength="120" value="<?= h($j['title']) ?>"></label>
    <div class="row2">
      <?php foreach (['pembagian1' => 'Jabatan', 'pembagian2' => 'Departemen', 'pembagian3' => 'Kantor'] as $t => $l): ?>
        <label class="field"><span><?= $l ?></span><select name="<?= $t ?>_id"><option value="0">—</option>
          <?php foreach (lookup($t) as $id => $name): ?><option value="<?= (int)$id ?>"<?= (int)$j[$t . '_id'] === (int)$id ? ' selected' : '' ?>><?= h($name) ?></option><?php endforeach; ?></select></label>
      <?php endforeach; ?>
      <label class="field"><span>Status kerja</span><select name="employment"><?php foreach (array_unique(array_merge(employment_types(), [$j['employment']])) as $t): ?><option<?= $j['employment'] === $t ? ' selected' : '' ?>><?= h($t) ?></option><?php endforeach; ?></select></label>
      <label class="field"><span>Jumlah dibutuhkan</span><input type="number" name="headcount" min="1" value="<?= (int)$j['headcount'] ?>"></label>
      <label class="field"><span>Ditutup tanggal</span><input type="date" name="close_date" value="<?= h($j['close_date']) ?>"><small>Kosongkan bila tidak ada batas.</small></label>
      <label class="field"><span>Status</span><select name="status"><option value="open">Dibuka</option><option value="closed"<?= $j['status'] === 'closed' ? ' selected' : '' ?>>Ditutup</option></select></label>
    </div>
    <label class="field"><span>Deskripsi pekerjaan</span><textarea name="description" rows="6"><?= h($j['description']) ?></textarea></label>
    <label class="field"><span>Tanggung jawab</span><textarea name="responsibilities" rows="5"><?= h($j['responsibilities']) ?></textarea><small>Satu per baris.</small></label>
    <label class="field"><span>Kualifikasi</span><textarea name="requirements" rows="6"><?= h($j['requirements']) ?></textarea>
      <small>Satu per baris. Tampil di situs karir sebagai syarat, dan dipakai AI untuk memberi skor kecocokan CV.</small></label>
    <div class="formfoot"><a class="btn" href="recruit.php?p=jobs">Batal</a><button class="btn primary">Simpan</button></div></form>
<?php
// ======================================================================= Pipeline of one job
elseif ($p === 'job'):
    $j = get('id') === '0' ? general_job() : q("$jobSql WHERE j.id = ?", [(int)get('id')])->fetch();
    if (!$j) redirect('recruit.php?p=jobs');
    $byStage = array_fill_keys(array_keys($stages), []);
    foreach (q('SELECT * FROM web_candidate WHERE job_id = ? ORDER BY ai_score IS NULL, ai_score DESC, created_at', [$j['id']]) as $c) $byStage[$c['stage']][] = $c;
    hris_header($j['title'], 'recruit', 'recruit.php?p=jobs', '<a href="recruit.php?p=jobs">Rekrutmen</a>',
        $j['id'] ? '<a class="btn" href="careers.php?job=' . (int)$j['id'] . '" target="_blank">Lihat di halaman karir</a> <a class="btn" href="recruit.php?p=job_edit&id=' . (int)$j['id'] . '">Ubah lowongan</a>' : ''); ?>
  <?php if (!$j['id']): ?><p class="muted" style="margin:0 0 12px">Pelamar dari situs karir yang memilih "posisi lain" atau posisi yang tidak ada di sini. Pindahkan ke lowongan di halaman kandidat.</p><?php else: ?>
  <p class="muted" style="margin:0 0 12px"><?= h(implode(' · ', array_filter([$j['jab'], $j['dep'], $j['kan'], $j['employment'], 'butuh ' . (int)$j['headcount'] . ' orang']))) ?>
    · <span class="pill <?= $j['status'] === 'open' ? 'ok' : 'grey' ?>"><?= $j['status'] === 'open' ? 'Dibuka' : 'Ditutup' ?></span></p><?php endif; ?>
  <div style="display:grid;grid-template-columns:repeat(6,minmax(170px,1fr));gap:10px;overflow-x:auto;padding-bottom:6px">
    <?php foreach ($stages as $k => $l): ?>
      <div style="background:var(--canvas);border:1px solid var(--line);border-radius:8px;padding:10px;min-height:120px">
        <div style="font-weight:600;font-size:13px;margin-bottom:8px"><?= h($l) ?> <span class="muted">(<?= count($byStage[$k]) ?>)</span></div>
        <?php foreach ($byStage[$k] as $c): ?>
          <a href="recruit.php?p=candidate&id=<?= (int)$c['id'] ?>" style="display:block;background:var(--bg);border:1px solid var(--line);border-radius:6px;padding:8px;margin-bottom:6px;color:var(--ink)">
            <b><?= h($c['name']) ?></b> <?= score_pill($c['ai_score']) ?><br><small class="muted"><?= date('d-m-Y', strtotime($c['created_at'])) ?> · <?= h($c['source']) ?></small>
            <?php if ($c['rating']): ?><br><small style="color:var(--warn)"><?= str_repeat('★', (int)$c['rating']) ?></small><?php endif; ?>
            <?php if ($c['interview_at'] && $k === 'interview'): ?><br><small>Wawancara <?= date('d-m H:i', strtotime($c['interview_at'])) ?></small><?php endif; ?></a>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  </div>
  <?php if ($j['id']): ?><form method="post" enctype="multipart/form-data" class="section" style="margin-top:16px"><?= $csrf ?><input type="hidden" name="action" value="cand_add"><input type="hidden" name="job_id" value="<?= (int)$j['id'] ?>">
    <h2>Tambah kandidat manual</h2><small class="muted">Untuk pelamar dari email, job fair, referensi atau situs lowongan.</small>
    <div class="row2"><label class="field"><span>Nama <i>*</i></span><input type="text" name="name" required></label>
      <label class="field"><span>Sumber</span><select name="source"><?php foreach (['Input HR', 'Referensi karyawan', 'Email', 'Job fair', 'Situs lowongan', 'Media sosial'] as $s): ?><option><?= $s ?></option><?php endforeach; ?></select></label>
      <label class="field"><span>Email</span><input type="text" name="email"></label><label class="field"><span>No. HP</span><input type="text" name="phone"></label>
      <label class="field"><span>CV (opsional)</span><input type="file" name="cv" accept=".pdf,.jpg,.jpeg,.png"></label></div>
    <div class="formfoot"><button class="btn primary">Tambah</button></div></form><?php endif; ?>
<?php
// ======================================================================= One candidate
elseif ($p === 'candidate'):
    $c = q('SELECT * FROM web_candidate WHERE id = ?', [(int)get('id')])->fetch();
    if (!$c) redirect('recruit.php?p=jobs');
    $j = q("$jobSql WHERE j.id = ?", [$c['job_id']])->fetch() ?: general_job();
    $allJobs = q('SELECT id, title, status FROM web_job ORDER BY status DESC, title')->fetchAll();
    $notes = q('SELECT * FROM web_candidate_note WHERE candidate_id = ? ORDER BY created_at DESC, id DESC', [$c['id']])->fetchAll();
    $hired = $c['stage'] === 'hired';
    hris_header($c['name'], 'recruit', 'recruit.php?p=jobs', '<a href="recruit.php?p=job&id=' . (int)$c['job_id'] . '">' . h($j['title']) . '</a>',
        $c['cv_file'] || $c['cv_ext'] ? '<a class="btn" href="cv.php?id=' . (int)$c['id'] . '" target="_blank">Lihat CV</a>' : ''); ?>
  <div class="grid3" style="grid-template-columns:minmax(0,1fr) minmax(0,1fr)">
    <div class="card"><h2>Data pelamar <span class="side"><?= stage_badge($c['stage']) ?></span></h2>
      <p style="margin:0 0 6px">Email: <?= $c['email'] !== '' ? '<a href="mailto:' . h($c['email']) . '">' . h($c['email']) . '</a>' : '—' ?><br>
        HP: <?= h($c['phone'] ?: '—') ?><br>Sumber: <?= h($c['source']) ?><br>Melamar: <?= h(fmt_date(substr($c['created_at'], 0, 10))) ?>
        <?php if ($c['pegawai_id']): ?><br>Karyawan: <a href="hr.php?m=employee&id=<?= (int)$c['pegawai_id'] ?>">lihat profil</a><?php endif; ?></p>
      <?php if ($c['ai_score'] !== null || $c['ai_note']): ?><h2 style="margin-top:14px">Review AI situs karir <span class="side"><?= score_pill($c['ai_score']) ?></span></h2>
        <div style="white-space:pre-line;font-size:13px"><?= h($c['ai_note']) ?></div><small class="muted">Hanya bantuan menyortir; keputusan tetap oleh HR.</small><?php endif; ?>
      <?php if ($c['cover']): ?><h2 style="margin-top:14px">Surat lamaran</h2><div style="white-space:pre-line"><?= h($c['cover']) ?></div><?php endif; ?>
      <?php if (!$c['cv_file'] && !$c['cv_ext']): ?><p class="muted">Tidak ada CV.</p><?php endif; ?></div>
    <div class="card"><h2>Seleksi</h2>
      <?php if ($hired): ?><p class="muted">Kandidat sudah diterima menjadi karyawan.</p><?php else: ?>
      <form method="post" class="form"><?= $csrf ?><input type="hidden" name="action" value="cand_update"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
        <label class="field"><span>Lowongan</span><select name="job_id"><option value="0">Lamaran umum (tanpa lowongan)</option>
          <?php foreach ($allJobs as $o): ?><option value="<?= (int)$o['id'] ?>"<?= (int)$c['job_id'] === (int)$o['id'] ? ' selected' : '' ?>><?= h($o['title']) ?><?= $o['status'] === 'open' ? '' : ' (ditutup)' ?></option><?php endforeach; ?></select></label>
        <div class="row2"><label class="field"><span>Tahap</span><select name="stage"><?php foreach ($stages as $k => $l) if ($k !== 'hired'): ?><option value="<?= $k ?>"<?= $c['stage'] === $k ? ' selected' : '' ?>><?= h($l) ?></option><?php endif; ?></select></label>
          <label class="field"><span>Penilaian</span><select name="rating"><?php for ($i = 0; $i <= 5; $i++): ?><option value="<?= $i ?>"<?= (int)$c['rating'] === $i ? ' selected' : '' ?>><?= $i ? str_repeat('★', $i) : '—' ?></option><?php endfor; ?></select></label>
          <label class="field"><span>Jadwal wawancara</span><input type="datetime-local" name="interview_at" value="<?= $c['interview_at'] ? date('Y-m-d\TH:i', strtotime($c['interview_at'])) : '' ?>"></label></div>
        <div class="formfoot"><button class="btn primary">Simpan</button></div></form>
      <form method="post" class="form" style="border-top:1px solid var(--line);padding-top:14px;margin-top:14px" onsubmit="return confirm('Jadikan <?= h(addslashes($c['name'])) ?> karyawan?')"><?= $csrf ?>
        <input type="hidden" name="action" value="hire"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
        <?php $hireStatus = employment_from_text($j['employment']); ?>
        <label class="field"><span>Terima & jadikan karyawan — mulai bekerja</span><input type="date" name="start" required value="<?= date('Y-m-d', strtotime('+7 days')) ?>"></label>
        <label class="field"><span>Status: <?= h(employment_statuses()[$hireStatus]) ?><?= employment_needs_end($hireStatus) ? ' — berakhir' : '' ?></span>
          <?php if (employment_needs_end($hireStatus)): ?><input type="date" name="end" value="<?= date('Y-m-d', strtotime('+7 days +1 year -1 day')) ?>"><?php endif; ?>
          <small>Membuat data pegawai (PIN berikutnya, jabatan/departemen/kantor dari lowongan, jadwal kerja pertama, status kepegawaian) dan memulai checklist onboarding.</small></label>
        <div class="formfoot"><button class="btn primary">Jadikan karyawan</button></div></form>
      <form method="post" style="margin-top:10px" onsubmit="return confirm('Hapus kandidat ini beserta CV-nya?')"><?= $csrf ?><input type="hidden" name="action" value="cand_delete"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
        <button class="btn small ghost" style="color:var(--bad)">Hapus kandidat & CV</button></form>
      <?php endif; ?></div>
  </div>
  <div class="card" style="margin-top:16px"><h2>Catatan</h2>
    <form method="post" style="display:flex;gap:8px;margin-bottom:12px"><?= $csrf ?><input type="hidden" name="action" value="note_add"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
      <input type="text" name="note" placeholder="Hasil wawancara, tes, gaji yang diharapkan…" style="flex:1"><button class="btn">Tambah</button></form>
    <?php foreach ($notes as $n): ?><div style="border-top:1px solid var(--line);padding:8px 0"><?= h($n['note']) ?><br><small class="muted"><?= h($n['created_by']) ?> · <?= date('d-m-Y H:i', strtotime($n['created_at'])) ?></small></div><?php endforeach; ?>
    <?php if (!$notes): ?><p class="muted">Belum ada catatan.</p><?php endif; ?></div>
<?php
// ======================================================================= Manpower planning
elseif ($p === 'manpower'):
    $year = (int)get('year', date('Y'));
    $deps = lookup('pembagian2');
    $planned = q('SELECT pembagian2_id, planned FROM web_manpower WHERE tahun = ?', [$year])->fetchAll(PDO::FETCH_KEY_PAIR);
    $current = q('SELECT pembagian2_id, COUNT(*) FROM pegawai WHERE pegawai_status = 1 GROUP BY pembagian2_id')->fetchAll(PDO::FETCH_KEY_PAIR);
    $open = q("SELECT j.pembagian2_id, SUM(j.headcount - (SELECT COUNT(*) FROM web_candidate c WHERE c.job_id = j.id AND c.stage = 'hired'))
               FROM web_job j WHERE j.status = 'open' GROUP BY j.pembagian2_id")->fetchAll(PDO::FETCH_KEY_PAIR);
    $rows = [0 => 'Tanpa departemen'] + $deps;
    hris_header('Manpower planning', 'recruit', 'recruit.php?p=manpower', 'Rekrutmen'); ?>
  <form class="toolbar" method="get"><input type="hidden" name="p" value="manpower"><label>Tahun <input type="number" name="year" value="<?= $year ?>" style="width:90px"></label><button class="btn">Tampilkan</button></form>
  <form method="post"><?= $csrf ?><input type="hidden" name="action" value="mp_save"><input type="hidden" name="year" value="<?= $year ?>">
  <div class="tablecard"><div class="tablescroll"><table>
    <thead><tr><th>Departemen</th><th class="num">Rencana <?= $year ?></th><th class="num">Karyawan aktif</th><th class="num">Lowongan dibuka</th><th class="num">Selisih</th><th>Keterangan</th></tr></thead><tbody>
    <?php $tot = [0, 0, 0]; foreach ($rows as $id => $name):
        $pl = isset($planned[$id]) ? (int)$planned[$id] : 0; $cu = isset($current[$id]) ? (int)$current[$id] : 0; $op = isset($open[$id]) ? max(0, (int)$open[$id]) : 0;
        if (!$id && !$cu && !$pl && !$op) continue;
        $gap = $pl - $cu - $op; $tot[0] += $pl; $tot[1] += $cu; $tot[2] += $op; ?>
      <tr><td><?= h($name) ?></td><td class="num"><input type="number" name="planned[<?= (int)$id ?>]" min="0" value="<?= $pl ?>" style="width:80px;text-align:right"></td>
        <td class="num"><?= $cu ?></td><td class="num"><?= $op ?: '' ?></td><td class="num"><b><?= $pl ? ($gap > 0 ? '+' . $gap : $gap) : '' ?></b></td>
        <td><?php if (!$pl): ?><span class="muted">belum direncanakan</span><?php elseif ($gap > 0): ?><span class="pill warn">kurang <?= $gap ?> — buka lowongan</span><?php elseif ($gap < 0): ?><span class="pill off">lebih <?= -$gap ?></span><?php else: ?><span class="pill ok">sesuai rencana</span><?php endif; ?></td></tr>
    <?php endforeach; ?>
    <tr><th>Total</th><th class="num"><?= $tot[0] ?></th><th class="num"><?= $tot[1] ?></th><th class="num"><?= $tot[2] ?></th><th class="num"><?= $tot[0] - $tot[1] - $tot[2] ?></th><th></th></tr>
    </tbody></table></div></div>
  <div class="formfoot"><a class="btn" href="recruit.php?p=job_edit">+ Lowongan baru</a><button class="btn primary">Simpan rencana</button></div></form>
<?php
endif;
hris_footer();
