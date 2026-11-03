<?php
// Portal Pegawai: employee self-service in the style of an HRIS app.
// Employees sign in with their PIN and the login code HR gives them (HR Overview → Kode login).
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/portal.php';
require __DIR__ . '/inc/payroll.php';
require __DIR__ . '/inc/discipline.php';
require __DIR__ . '/inc/people.php';
require __DIR__ . '/inc/company.php';
require __DIR__ . '/inc/approval.php';
require __DIR__ . '/inc/talent.php';
require __DIR__ . '/inc/shifts.php';

$page = get('p', 'home');
$error = '';

// ---------- sign in / out ----------
if ($page === 'logout') {
    unset($_SESSION['emp']);
    redirect('portal.php');
}
if (!current_emp()) {
    ensure_web_tables();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $row = q('SELECT w.password_hash, p.pegawai_id, p.pegawai_nama, p.pegawai_pin FROM pegawai p JOIN web_emp w ON w.pegawai_id = p.pegawai_id
                  WHERE p.pegawai_pin = ? AND p.pegawai_status = 1', [post('pin')])->fetch();
        if ($row && password_verify(isset($_POST['code']) ? (string)$_POST['code'] : '', $row['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['emp'] = ['id' => (int)$row['pegawai_id'], 'name' => $row['pegawai_nama'], 'pin' => $row['pegawai_pin']];
            q('UPDATE web_emp SET last_login = ? WHERE pegawai_id = ?', [date('Y-m-d H:i:s'), $row['pegawai_id']]);
            redirect('portal.php');
        }
        $error = 'PIN atau kode login salah. Minta kode login ke HR.';
    }
    portal_header('Masuk', null);
    ?>
    <div class="p-login">
      <div class="p-card">
        <h1>Portal Pegawai</h1>
        <p class="muted">Masuk dengan PIN absensi dan kode login dari HR.</p>
        <?php if ($error): ?><div class="p-alert err"><?= h($error) ?></div><?php endif; ?>
        <form method="post" class="p-form">
          <?= csrf_field() ?>
          <label>PIN <input name="pin" inputmode="numeric" required autofocus value="<?= h(post('pin')) ?>"></label>
          <label>Kode login <input type="password" name="code" required autocomplete="current-password"></label>
          <button class="p-btn primary">Masuk</button>
        </form>
      </div>
    </div>
    <?php
    portal_footer();
    exit;
}

$me = current_emp();
$emp = q('SELECT p.*, p1.pembagian1_nama jab, p2.pembagian2_nama dep, p3.pembagian3_nama kan FROM pegawai p
          LEFT JOIN pembagian1 p1 ON p1.pembagian1_id = p.pembagian1_id LEFT JOIN pembagian2 p2 ON p2.pembagian2_id = p.pembagian2_id
          LEFT JOIN pembagian3 p3 ON p3.pembagian3_id = p.pembagian3_id WHERE p.pegawai_id = ?', [$me['id']])->fetch();
if (!$emp || (int)$emp['pegawai_status'] !== 1) { unset($_SESSION['emp']); redirect('portal.php'); }
$types = request_types();

// ---------- actions ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = post('action');
    if ($action === 'request') {
        $jenis = (int)post('jenis');
        $df = post('date_from');
        $dt = post('date_to', $df);
        if (!isset($types[$jenis])) $error = 'Pilih jenis cuti / izin.';
        elseif (!valid_date($df) || !valid_date($dt) || $dt < $df) $error = 'Isi tanggal yang benar.';
        elseif ((strtotime($dt) - strtotime($df)) / 86400 > 31) $error = 'Satu pengajuan maksimal 31 hari.';
        elseif (post('note') === '') $error = 'Isi alasan.';
        else {
            $added = 0;
            for ($d = $df; $d <= $dt; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
                list(, $off) = work_hours_for($me['id'], $d);
                if ($off || q('SELECT 1 FROM libur WHERE libur_tgl = ?', [$d])->fetchColumn()) continue;   // no request on days off
                q('INSERT INTO izin (pegawai_id, izin_urutan, izin_tgl_pengajuan, izin_tgl, izin_jenis_id, izin_catatan, izin_status,
                                     cuti_n_id, izin_ket_lain, izin_noscan_time, kat_izin_id, ket_status)
                   VALUES (?, ?, ?, ?, ?, ?, 0, 0, ?, NULL, 0, ?)',
                    [$me['id'], next_izin_seq($me['id'], $d, $jenis), date('Y-m-d'), $d, $jenis, post('note'), 'Portal: ' . $me['pin'], REQ_PENDING]);
                $added++;
            }
            if ($added) {
                q('INSERT INTO web_log (created_at, username, action, detail) VALUES (?, ?, ?, ?)',
                    [date('Y-m-d H:i:s'), 'portal:' . $me['pin'], 'request_add', "{$me['name']} type $jenis $df..$dt ($added hari)"]);
                flash("Pengajuan terkirim ($added hari). Menunggu persetujuan HR.");
            } else {
                flash('Tidak ada hari kerja di tanggal itu.', 'err');
            }
            redirect('portal.php?p=timeoff');
        }
        $page = 'timeoff';
    } elseif ($action === 'clock') {
        // Live attendance: saved like a Tidak scan (izin 100/101 + att_log), approved straight away
        $jenis = post('which') === 'out' ? 101 : 100;
        $err = add_manual_scan($me['id'], date('Y-m-d'), date('H:i'), $jenis, 0, 'Live attendance (portal)', true);
        flash($err ?: ($jenis === 100 ? 'Clock in tercatat ' : 'Clock out tercatat ') . date('H:i') . '.', $err ? 'err' : 'ok');
        redirect('portal.php');
    } elseif ($action === 'ot_request') {
        ensure_claim_tables();
        $d = post('tgl');
        if (!valid_date($d)) $error = 'Isi tanggal lembur.';
        elseif (!valid_time(post('mulai')) || !valid_time(post('selesai'))) $error = 'Isi jam mulai dan selesai.';
        elseif (post('alasan') === '') $error = 'Isi alasan lembur.';
        else {
            $min = span_minutes(post('mulai'), post('selesai'));
            if ($min > 12 * 60) $error = 'Lembur maksimal 12 jam per pengajuan.';
            else {
                q('INSERT INTO web_overtime (pegawai_id, tgl, jam_mulai, jam_selesai, minutes, alasan, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [$me['id'], $d, post('mulai'), post('selesai'), $min, post('alasan'), CLAIM_PENDING, date('Y-m-d H:i:s')]);
                audit('overtime_request', "{$me['name']} $d " . post('mulai') . '-' . post('selesai'));
                flash('Pengajuan lembur terkirim. Menunggu persetujuan HR.');
                redirect('portal.php?p=overtime');
            }
        }
        $page = 'overtime';
    } elseif ($action === 'rb_request') {
        ensure_claim_tables();
        $amount = (int)preg_replace('/\D/', '', post('jumlah'));
        if (!valid_date(post('tgl')) || post('tgl') > date('Y-m-d')) $error = 'Isi tanggal pengeluaran (hari ini atau sebelumnya).';
        elseif (!in_array(post('kategori'), reimburse_categories(), true)) $error = 'Pilih kategori.';
        elseif ($amount <= 0) $error = 'Isi jumlah.';
        elseif (post('keterangan') === '') $error = 'Isi keterangan.';
        else {
            list($file, $err) = save_receipt(isset($_FILES['bukti']) ? $_FILES['bukti'] : null);
            if ($err) $error = $err;
            elseif (!$file) $error = 'Lampirkan foto / PDF bukti pembayaran.';
            else {
                q('INSERT INTO web_reimburse (pegawai_id, tgl, kategori, jumlah, keterangan, file, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [$me['id'], post('tgl'), post('kategori'), $amount, post('keterangan'), $file, CLAIM_PENDING, date('Y-m-d H:i:s')]);
                audit('reimburse_request', "{$me['name']} " . post('kategori') . " $amount");
                flash('Klaim reimbursement terkirim. Menunggu persetujuan HR.');
                redirect('portal.php?p=reimburse');
            }
        }
        $page = 'reimburse';
    } elseif ($action === 'team_decide') {
        ensure_approval_tables();
        $ok = supervisor_decide($me['id'], $me['name'], post('kind'), post('ref'), post('decision') === 'approve', post('note'));
        if ($ok) audit('supervisor_' . post('decision'), $me['name'] . ' ' . post('kind') . ' ' . post('ref'));
        flash($ok ? (post('decision') === 'approve' ? 'Disetujui; diteruskan ke HR untuk keputusan akhir.' : 'Pengajuan ditolak.') : 'Pengajuan ini tidak bisa Anda putuskan (sudah diputuskan atau bukan bawahan Anda).', $ok ? 'ok' : 'err');
        redirect('portal.php?p=team');
    } elseif ($action === 'profile_request') {
        ensure_people_tables();
        $chg = profile_changes(get_profile($me['id']), clean_profile($_POST));
        if (!$chg) $error = 'Tidak ada data yang diubah.';
        elseif (q("SELECT COUNT(*) FROM web_profile_request WHERE pegawai_id = ? AND status = 'pending'", [$me['id']])->fetchColumn()) $error = 'Masih ada pengajuan perubahan data yang menunggu HR.';
        else {
            q('INSERT INTO web_profile_request (pegawai_id, data, note, created_at) VALUES (?, ?, ?, ?)', [$me['id'], json_encode($chg), mb_substr(post('note'), 0, 255), date('Y-m-d H:i:s')]);
            audit('profile_request', $me['name'] . ': ' . implode(', ', array_keys($chg)));
            flash('Perubahan data terkirim. HR akan memeriksa dan menyimpannya.');
            redirect('portal.php?p=profile');
        }
        $page = 'profile';
    } elseif ($action === 'my_doc') {
        ensure_people_tables();
        list($file, $err) = save_doc_file(isset($_FILES['file']) ? $_FILES['file'] : null);
        if ($err || !$file) $error = $err ?: 'Pilih file.';
        else {
            q('INSERT INTO web_emp_doc (pegawai_id, kind, title, file, expires, uploaded_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$me['id'], isset(doc_kinds()[post('kind')]) ? post('kind') : 'lain', mb_substr(post('title'), 0, 120), $file, valid_date(post('expires')) ? post('expires') : null, actor(), date('Y-m-d H:i:s')]);
            audit('doc_upload', $me['name'] . ' ' . post('kind'));
            flash('Dokumen diunggah.');
            redirect('portal.php?p=profile');
        }
        $page = 'profile';
    } elseif ($action === 'warn_ack') {
        ensure_discipline_tables();
        q('UPDATE web_warning SET acknowledged_at = ? WHERE id = ? AND pegawai_id = ? AND acknowledged_at IS NULL', [date('Y-m-d H:i:s'), (int)post('id'), $me['id']]);
        audit('warning_ack', $me['name'] . ' #' . (int)post('id'));
        flash('Terima kasih, penerimaan peringatan dicatat.');
        redirect('portal.php?p=warnings');
    } elseif ($action === 'loan_request') {
        ensure_payroll_tables();
        $amount = (int)preg_replace('/\D/', '', post('jumlah'));
        $inst = (int)preg_replace('/\D/', '', post('cicilan'));
        if ($amount <= 0) $error = 'Isi jumlah kasbon.';
        elseif ($inst <= 0 || $inst > $amount) $error = 'Isi cicilan per gaji (tidak lebih dari jumlah kasbon).';
        elseif (post('keterangan') === '') $error = 'Isi keperluan kasbon.';
        elseif (q("SELECT COUNT(*) FROM web_loan WHERE pegawai_id = ? AND status = 'pending'", [$me['id']])->fetchColumn()) $error = 'Masih ada pengajuan kasbon yang menunggu persetujuan.';
        else {
            q('INSERT INTO web_loan (pegawai_id, tgl, jumlah, cicilan, keterangan, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$me['id'], date('Y-m-d'), $amount, $inst, mb_substr(post('keterangan'), 0, 255), LOAN_PENDING, date('Y-m-d H:i:s')]);
            audit('loan_request', "{$me['name']} $amount");
            flash('Pengajuan kasbon terkirim. Menunggu persetujuan HR.');
            redirect('portal.php?p=kasbon');
        }
        $page = 'kasbon';
    } elseif ($action === 'loan_cancel') {
        ensure_payroll_tables();
        q('DELETE FROM web_loan WHERE id = ? AND pegawai_id = ? AND status = ?', [(int)post('id'), $me['id'], LOAN_PENDING]);
        flash('Pengajuan kasbon dibatalkan.');
        redirect('portal.php?p=kasbon');
    } elseif ($action === 'ot_cancel' || $action === 'rb_cancel') {
        ensure_claim_tables();
        $table = $action === 'ot_cancel' ? 'web_overtime' : 'web_reimburse';
        // only the employee's own requests that HR has not decided yet
        $row = q("SELECT * FROM $table WHERE id = ? AND pegawai_id = ? AND status = ?", [(int)post('id'), $me['id'], CLAIM_PENDING])->fetch();
        if ($row) {
            q("DELETE FROM $table WHERE id = ?", [$row['id']]);
            if (!empty($row['file'])) @unlink(RECEIPT_DIR . '/' . basename($row['file']));
            flash('Pengajuan dibatalkan.');
        }
        redirect('portal.php?p=' . ($action === 'ot_cancel' ? 'overtime' : 'reimburse'));
    } elseif ($action === 'task_toggle') {
        ensure_talent_tables();
        // employees tick only their own "Karyawan" tasks
        $t = q('SELECT * FROM web_checklist WHERE id = ? AND pegawai_id = ? AND who = ?', [(int)post('id'), $me['id'], 'Karyawan'])->fetch();
        if ($t) q('UPDATE web_checklist SET done_at = ?, done_by = ? WHERE id = ?', $t['done_at'] ? [null, '', $t['id']] : [date('Y-m-d H:i:s'), actor(), $t['id']]);
        redirect('portal.php');
    } elseif ($action === 'self_review') {
        ensure_talent_tables();
        $cycle = q('SELECT * FROM web_review_cycle WHERE id = ? AND status = ?', [(int)post('cycle_id'), 'open'])->fetch();
        $row = $cycle ? q('SELECT 1 FROM web_review WHERE cycle_id = ? AND pegawai_id = ?', [$cycle['id'], $me['id']])->fetchColumn() : false;
        $scores = array_map('intval', isset($_POST['score']) ? (array)$_POST['score'] : []);
        if (!$row) $error = 'Penilaian ini sudah ditutup.';
        elseif (weighted_score(json_decode($cycle['criteria'], true), $scores) === null) $error = 'Beri nilai 1–5 untuk semua kriteria.';
        else {
            q('UPDATE web_review SET self_scores = ?, self_note = ?, self_at = ? WHERE cycle_id = ? AND pegawai_id = ?',
                [json_encode($scores), post('note'), date('Y-m-d H:i:s'), $cycle['id'], $me['id']]);
            audit('review_self', "{$me['name']} cycle {$cycle['id']}");
            flash('Penilaian diri tersimpan. Terima kasih.');
            redirect('portal.php?p=review');
        }
        $page = 'review';
    } elseif ($action === 'swap_request') {
        ensure_shift_tables();
        $with = employee(post('with_id'));
        if (!valid_date(post('tgl')) || post('tgl') < date('Y-m-d')) $error = 'Pilih tanggal hari ini atau sesudahnya.';
        elseif (!$with || (int)$with['pegawai_status'] !== 1 || (int)$with['pegawai_id'] === $me['id']) $error = 'Pilih rekan kerja.';
        elseif (post('alasan') === '') $error = 'Isi alasan.';
        else {
            q('INSERT INTO web_shift_swap (tgl, pegawai_id, with_id, alasan, status, created_at) VALUES (?, ?, ?, ?, ?, ?)',
                [post('tgl'), $me['id'], $with['pegawai_id'], post('alasan'), CLAIM_PENDING, date('Y-m-d H:i:s')]);
            audit('swap_request', "{$me['name']} <-> {$with['pegawai_nama']} " . post('tgl'));
            flash('Pengajuan tukar shift terkirim. Menunggu persetujuan HR.');
            redirect('portal.php?p=shift');
        }
        $page = 'shift';
    } elseif ($action === 'swap_cancel') {
        ensure_shift_tables();
        q('DELETE FROM web_shift_swap WHERE id = ? AND pegawai_id = ? AND status = ?', [(int)post('id'), $me['id'], CLAIM_PENDING]);
        flash('Pengajuan dibatalkan.');
        redirect('portal.php?p=shift');
    } elseif ($action === 'cancel') {
        $key = leave_key_from_post();
        $row = find_leave($key);
        // employees may only withdraw their own requests that HR has not decided yet
        if ($row && (int)$row['pegawai_id'] === $me['id'] && request_state($row) === 'pending') {
            q('DELETE FROM izin WHERE pegawai_id = ? AND izin_tgl = ? AND izin_jenis_id = ? AND izin_status = ? AND izin_urutan = ?', $key);
            flash('Pengajuan dibatalkan.');
        }
        redirect('portal.php?p=timeoff');
    } elseif ($action === 'code') {
        $code = isset($_POST['code']) ? (string)$_POST['code'] : '';
        if (strlen($code) < 6) $error = 'Kode login minimal 6 karakter.';
        elseif ($code !== (isset($_POST['code2']) ? $_POST['code2'] : '')) $error = 'Kode login tidak sama.';
        else {
            q('UPDATE web_emp SET password_hash = ? WHERE pegawai_id = ?', [password_hash($code, PASSWORD_DEFAULT), $me['id']]);
            flash('Kode login diganti.');
            redirect('portal.php?p=profile');
        }
        $page = 'profile';
    }
}

list($pFrom, $pTo) = current_period();
$tabs = ['home' => 'Beranda', 'shift' => 'Jadwal', 'attendance' => 'Kehadiran Saya', 'timeoff' => 'Cuti & Izin', 'overtime' => 'Lembur', 'reimburse' => 'Reimbursement', 'kasbon' => 'Kasbon', 'slip' => 'Slip Gaji', 'review' => 'Penilaian', 'profile' => 'Profil'];
ensure_discipline_tables();
// Tim saya for supervisors (someone has them as direct supervisor)
ensure_approval_tables(); ensure_people_tables();
$isBoss = (bool)q('SELECT COUNT(*) FROM web_emp_profile x JOIN pegawai p ON p.pegawai_id = x.pegawai_id WHERE x.atasan_id = ? AND p.pegawai_status = 1', [$me['id']])->fetchColumn();
if ($isBoss) $tabs = ['home' => $tabs['home'], 'team' => 'Tim saya'] + $tabs;
// the Peringatan tab only for employees who have received one
if (q('SELECT COUNT(*) FROM web_warning WHERE pegawai_id = ?', [$me['id']])->fetchColumn()) $tabs = array_slice($tabs, 0, -1, true) + ['warnings' => 'Peringatan'] + array_slice($tabs, -1, 1, true);
if (!isset($tabs[$page])) $page = 'home';
portal_header($tabs[$page], $tabs, $page, $emp);
if ($error) echo '<div class="p-alert err">' . h($error) . '</div>';
foreach (take_flashes() as $f) echo '<div class="p-alert ' . h($f[0]) . '">' . h($f[1]) . '</div>';

// ---------- pages ----------
if ($page === 'home'):
    $today = daily_attendance(date('Y-m-d'), date('Y-m-d'), $me['id']);
    $t = $today ? $today[0] : null;
    $rows = daily_attendance($pFrom, min($pTo, date('Y-m-d')), $me['id']);
    $c = ['ok' => 0, 'late' => 0, 'absent' => 0, 'leave' => 0, 'incomplete' => 0];
    foreach ($rows as $r) { if (isset($c[$r['status']])) $c[$r['status']]++; if ($r['late']) $c['late']++; }
    list($quota, $taken, $pend) = leave_balance($me['id']);
    $pending = q('SELECT COUNT(*) FROM izin WHERE pegawai_id = ? AND izin_status = 0 AND ket_status = ?', [$me['id'], REQ_PENDING])->fetchColumn();
    $h = (int)date('G'); ?>
  <div class="p-hero">
    <div>
      <div class="p-hello"><?= $h < 11 ? 'Selamat pagi' : ($h < 15 ? 'Selamat siang' : ($h < 18 ? 'Selamat sore' : 'Selamat malam')) ?>, <?= h(explode(' ', $emp['pegawai_nama'])[0]) ?></div>
      <div class="muted"><?= h(fmt_date(date('Y-m-d'))) ?> · <?= h($t && $t['jk'] ? 'Jam kerja ' . $t['jk'] : ($t && $t['off'] ? $t['off'] : '')) ?></div>
    </div>
    <div class="p-today">
      <div><span class="muted">Masuk</span><b><?= $t && $t['first'] ? h($t['first']) : '--:--' ?></b></div>
      <div><span class="muted">Pulang</span><b><?= $t && $t['last'] ? h($t['last']) : '--:--' ?></b></div>
    </div>
  </div>
  <div class="p-card p-clock">
    <div><div class="muted">Live attendance</div><div class="p-time" id="liveclock"><?= date('H:i:s') ?></div></div>
    <form method="post" class="p-actions" style="margin:0"><?= csrf_field() ?><input type="hidden" name="action" value="clock">
      <button class="p-btn primary" name="which" value="in">Clock in</button>
      <button class="p-btn" name="which" value="out">Clock out</button></form>
  </div>
  <script>setInterval(function () { var d = new Date(), p = function (n) { return (n < 10 ? '0' : '') + n; };
    document.getElementById('liveclock').textContent = p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds()); }, 1000);</script>
  <div class="p-grid">
    <div class="p-stat"><b><?= $c['ok'] + $c['incomplete'] ?></b><span>Hadir</span></div>
    <div class="p-stat"><b><?= $c['late'] ?></b><span>Terlambat</span></div>
    <div class="p-stat"><b><?= $c['absent'] ?></b><span>Tidak hadir</span></div>
    <div class="p-stat"><b><?= $quota === null ? '—' : max(0, $quota - $taken) ?></b><span>Sisa cuti <?= date('Y') ?></span></div>
  </div>
  <p class="muted">Periode <?= h(fmt_date($pFrom)) ?> – <?= h(fmt_date($pTo)) ?></p>
  <?php ensure_company_tables(); $anns = current_announcements(date('Y-m-d'), 5); $cal = array_slice(company_calendar(date('Y-m-d'), 30), 0, 8);
  if ($anns): ?><div class="p-card"><h2>Pengumuman</h2><?php foreach ($anns as $a): ?><div style="border-top:1px solid var(--line);padding:8px 0"><b><?= $a['pinned'] ? '📌 ' : '' ?><?= h($a['title']) ?></b>
    <?= $a['event_date'] ? ' <span class="muted">· ' . h(fmt_date($a['event_date'])) . '</span>' : '' ?><div style="white-space:pre-line"><?= h($a['body']) ?></div></div><?php endforeach; ?></div><?php endif; ?>
  <?php if ($cal): ?><div class="p-card"><h2>Kalender 30 hari</h2><?php foreach ($cal as $c): ?><div class="muted" style="padding:2px 0"><b style="color:var(--text)"><?= h(date('d-m', strtotime($c[0]))) ?></b> · <?= h($c[2]) ?></div><?php endforeach; ?></div><?php endif; ?>
  <?php ensure_talent_tables();
  $myTasks = q('SELECT * FROM web_checklist WHERE pegawai_id = ? AND who = ? ORDER BY done_at IS NOT NULL, due_date, sort', [$me['id'], 'Karyawan'])->fetchAll();
  if ($myTasks && array_filter($myTasks, function ($t) { return !$t['done_at']; })): ?>
  <div class="p-card"><h2>Tugas saya</h2>
    <?php foreach ($myTasks as $t): ?>
      <form method="post" class="p-item" style="padding:6px 0"><?= csrf_field() ?><input type="hidden" name="action" value="task_toggle"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
        <input type="checkbox" <?= $t['done_at'] ? 'checked' : '' ?> onchange="this.form.submit()" style="height:18px;width:18px" aria-label="Selesai">
        <div class="p-main" style="<?= $t['done_at'] ? 'text-decoration:line-through;color:var(--muted)' : '' ?>"><?= h($t['item']) ?></div>
        <span class="muted"><?= $t['due_date'] ? 'batas ' . h(date('d-m-Y', strtotime($t['due_date']))) : '' ?></span></form>
    <?php endforeach; ?></div>
  <?php endif; ?>
  <div class="p-actions">
    <a class="p-btn primary" href="portal.php?p=timeoff#new">+ Ajukan cuti / izin</a>
    <a class="p-btn" href="portal.php?p=attendance">Lihat kehadiran</a>
    <?php if ($pending): ?><a class="p-btn" href="portal.php?p=timeoff"><?= (int)$pending ?> pengajuan menunggu</a><?php endif; ?>
  </div>

<?php elseif ($page === 'attendance'):
    $from = get('from', $pFrom);
    $to = get('to', min($pTo, date('Y-m-d')));
    if (!valid_date($from)) $from = $pFrom;
    if (!valid_date($to) || $to < $from) $to = min($pTo, date('Y-m-d'));
    if ((strtotime($to) - strtotime($from)) / 86400 > 92) $from = date('Y-m-d', strtotime($to . ' -92 days'));
    $rows = array_reverse(daily_attendance($from, $to, $me['id'])); ?>
  <form class="p-filter" method="get">
    <input type="hidden" name="p" value="attendance">
    <label>Dari <input type="date" name="from" value="<?= h($from) ?>"></label>
    <label>s/d <input type="date" name="to" value="<?= h($to) ?>"></label>
    <button class="p-btn">Tampilkan</button>
  </form>
  <div class="p-list">
    <?php foreach ($rows as $r): ?>
      <div class="p-item">
        <div class="p-date"><b><?= date('d', strtotime($r['date'])) ?></b><span><?= h(substr(fmt_date($r['date']), 0, 3)) ?></span></div>
        <div class="p-main">
          <div><?= $r['first'] ? h($r['first']) : '--:--' ?> – <?= $r['last'] ? h($r['last']) : '--:--' ?>
            <?php if ($r['late']): ?><span class="badge b-absent">Terlambat <?= fmt_minutes($r['late']) ?></span><?php endif; ?>
            <?php if ($r['early']): ?><span class="badge b-incomplete">Pulang awal <?= fmt_minutes($r['early']) ?></span><?php endif; ?>
            <?php if ($r['ot']): ?><span class="badge b-leave">Lembur <?= fmt_minutes($r['ot']) ?></span><?php endif; ?></div>
          <div class="muted"><?= h(implode(' · ', array_filter(array_merge([$r['jk']], $r['leave'], [$r['off']])))) ?></div>
        </div>
        <div><?= status_badge($r['status']) ?></div>
      </div>
    <?php endforeach; ?>
    <?php if (!$rows): ?><div class="p-item muted">Tidak ada data.</div><?php endif; ?>
  </div>

<?php elseif ($page === 'timeoff'):
    list($quota, $taken, $pend) = leave_balance($me['id']);
    $reqs = q("SELECT i.*, j.izin_jenis_name FROM izin i LEFT JOIN jns_izin j ON j.izin_jenis_id = i.izin_jenis_id
               WHERE i.pegawai_id = ? AND i.izin_jenis_id NOT BETWEEN 100 AND 105 AND i.izin_tgl >= ?
               ORDER BY i.izin_tgl DESC LIMIT 200", [$me['id'], date('Y-m-d', strtotime('-1 year'))])->fetchAll(); ?>
  <div class="p-grid">
    <div class="p-stat"><b><?= $quota === null ? '—' : $quota ?></b><span>Jatah cuti <?= date('Y') ?></span></div>
    <div class="p-stat"><b><?= $taken ?></b><span>Sudah diambil</span></div>
    <div class="p-stat"><b><?= $pend ?></b><span>Menunggu</span></div>
    <div class="p-stat"><b><?= $quota === null ? '—' : max(0, $quota - $taken) ?></b><span>Sisa</span></div>
  </div>
  <div class="p-card" id="new">
    <h2>Ajukan cuti / izin</h2>
    <form method="post" class="p-form two">
      <?= csrf_field() ?><input type="hidden" name="action" value="request">
      <label>Jenis <select name="jenis" required><option value="">Pilih…</option>
        <?php foreach ($types as $id => $name): ?><option value="<?= (int)$id ?>" <?= (string)post('jenis', '90') === (string)$id ? 'selected' : '' ?>><?= h($name) ?></option><?php endforeach; ?></select></label>
      <label>Dari <input type="date" name="date_from" required value="<?= h(post('date_from', date('Y-m-d'))) ?>"></label>
      <label>Sampai <input type="date" name="date_to" required value="<?= h(post('date_to', date('Y-m-d'))) ?>"></label>
      <label class="wide">Alasan <input name="note" required maxlength="255" value="<?= h(post('note')) ?>"></label>
      <div class="wide"><button class="p-btn primary">Kirim pengajuan</button> <span class="muted">Hari libur dan hari off dilewati.</span></div>
    </form>
  </div>
  <h2>Riwayat pengajuan</h2>
  <div class="p-list">
    <?php foreach ($reqs as $r): ?>
      <div class="p-item">
        <div class="p-date"><b><?= date('d', strtotime($r['izin_tgl'])) ?></b><span><?= date('m-y', strtotime($r['izin_tgl'])) ?></span></div>
        <div class="p-main"><div><?= h($r['izin_jenis_name']) ?></div><div class="muted"><?= h($r['izin_catatan']) ?></div></div>
        <div style="display:flex;gap:6px;align-items:center"><?= request_badge($r) ?>
          <?php if (request_state($r) === 'pending'): ?>
            <form method="post" onsubmit="return confirm('Batalkan pengajuan ini?')"><?= csrf_field() ?>
              <input type="hidden" name="action" value="cancel">
              <?php foreach (['pegawai_id', 'izin_tgl', 'izin_jenis_id', 'izin_status', 'izin_urutan'] as $k): ?><input type="hidden" name="<?= $k ?>" value="<?= h($r[$k]) ?>"><?php endforeach; ?>
              <button class="p-btn small">Batal</button></form>
          <?php endif; ?></div>
      </div>
    <?php endforeach; ?>
    <?php if (!$reqs): ?><div class="p-item muted">Belum ada pengajuan.</div><?php endif; ?>
  </div>

<?php elseif ($page === 'shift'):
    ensure_shift_tables();
    $holidays = q('SELECT libur_tgl, libur_keterangan FROM libur WHERE libur_tgl BETWEEN ? AND ?', [date('Y-m-d'), date('Y-m-d', strtotime('+20 days'))])->fetchAll(PDO::FETCH_KEY_PAIR);
    $swaps = q('SELECT s.*, p.pegawai_nama FROM web_shift_swap s JOIN pegawai p ON p.pegawai_id = s.with_id WHERE s.pegawai_id = ? ORDER BY s.tgl DESC LIMIT 50', [$me['id']])->fetchAll(); ?>
  <h2>Jadwal 3 minggu ke depan</h2>
  <div class="p-list">
    <?php for ($i = 0; $i < 21; $i++): $d = date('Y-m-d', strtotime("+$i days")); $c = shift_cell($me['id'], $d);
        list($jk) = work_hours_for($me['id'], $d); $hol = isset($holidays[$d]); ?>
      <div class="p-item" style="<?= $hol || !$jk ? 'opacity:.6' : '' ?>">
        <div class="p-date"><b><?= date('d', strtotime($d)) ?></b><span><?= h(substr(fmt_date($d), 0, 3)) ?></span></div>
        <div class="p-main"><?= $hol ? 'Libur: ' . h($holidays[$d]) : ($jk ? '<b>' . h($jk['jk_name']) . '</b> ' . min_to_hm($jk['start']) . ' – ' . min_to_hm($jk['end']) : 'Libur') ?>
          <?php if ($c['changed']): ?><div class="muted"><?= $c['swap'] ? 'Tukar shift' : 'Diubah HR' ?></div><?php endif; ?></div>
      </div>
    <?php endfor; ?>
  </div>
  <div class="p-card" style="margin-top:14px">
    <h2>Ajukan tukar shift</h2>
    <form method="post" class="p-form two"><?= csrf_field() ?><input type="hidden" name="action" value="swap_request">
      <label>Tanggal <input type="date" name="tgl" required min="<?= date('Y-m-d') ?>" value="<?= h(post('tgl', date('Y-m-d', strtotime('+1 day')))) ?>"></label>
      <label>Tukar dengan <select name="with_id" required><option value="">Pilih rekan…</option>
        <?php foreach (employees(true) as $e) if ((int)$e['pegawai_id'] !== $me['id']): ?><option value="<?= (int)$e['pegawai_id'] ?>" <?= post('with_id') == $e['pegawai_id'] ? 'selected' : '' ?>><?= h($e['pegawai_nama']) ?></option><?php endif; ?></select></label>
      <label class="wide">Alasan <input name="alasan" required maxlength="255" value="<?= h(post('alasan')) ?>"></label>
      <div class="wide"><button class="p-btn primary">Kirim pengajuan</button> <span class="muted">Kalian bertukar jadwal di tanggal itu setelah HR menyetujui.</span></div>
    </form>
  </div>
  <?php if ($swaps): ?><h2>Riwayat tukar shift</h2><div class="p-list">
    <?php foreach ($swaps as $r): ?><div class="p-item">
      <div class="p-date"><b><?= date('d', strtotime($r['tgl'])) ?></b><span><?= date('m-y', strtotime($r['tgl'])) ?></span></div>
      <div class="p-main"><div>Dengan <?= h($r['pegawai_nama']) ?></div><div class="muted"><?= h($r['alasan']) ?></div></div>
      <div style="display:flex;gap:6px;align-items:center"><?= claim_badge($r['status']) ?>
        <?php if ($r['status'] === CLAIM_PENDING): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="swap_cancel"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="p-btn small">Batal</button></form><?php endif; ?></div>
    </div><?php endforeach; ?></div><?php endif; ?>

<?php elseif ($page === 'overtime'):
    ensure_claim_tables();
    $reqs = q('SELECT * FROM web_overtime WHERE pegawai_id = ? ORDER BY tgl DESC, id DESC LIMIT 100', [$me['id']])->fetchAll(); ?>
  <div class="p-card">
    <h2>Ajukan lembur</h2>
    <form method="post" class="p-form two">
      <?= csrf_field() ?><input type="hidden" name="action" value="ot_request">
      <label>Tanggal <input type="date" name="tgl" required value="<?= h(post('tgl', date('Y-m-d'))) ?>"></label>
      <label>Mulai <input type="time" name="mulai" required value="<?= h(post('mulai', '16:00')) ?>"></label>
      <label>Selesai <input type="time" name="selesai" required value="<?= h(post('selesai', '18:00')) ?>"></label>
      <label class="wide">Alasan / pekerjaan <input name="alasan" required maxlength="255" value="<?= h(post('alasan')) ?>"></label>
      <div class="wide"><button class="p-btn primary">Kirim pengajuan</button></div>
    </form>
  </div>
  <h2>Riwayat lembur</h2>
  <div class="p-list">
    <?php foreach ($reqs as $r): ?>
      <div class="p-item">
        <div class="p-date"><b><?= date('d', strtotime($r['tgl'])) ?></b><span><?= date('m-y', strtotime($r['tgl'])) ?></span></div>
        <div class="p-main"><div><?= h(substr($r['jam_mulai'], 0, 5)) ?> – <?= h(substr($r['jam_selesai'], 0, 5)) ?> · <?= fmt_minutes($r['minutes']) ?> jam</div><div class="muted"><?= h($r['alasan']) ?></div></div>
        <div style="display:flex;gap:6px;align-items:center"><?= claim_badge($r['status']) ?>
          <?php if ($r['status'] === CLAIM_PENDING): ?><form method="post" onsubmit="return confirm('Batalkan pengajuan ini?')"><?= csrf_field() ?>
            <input type="hidden" name="action" value="ot_cancel"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="p-btn small">Batal</button></form><?php endif; ?></div>
      </div>
    <?php endforeach; ?>
    <?php if (!$reqs): ?><div class="p-item muted">Belum ada pengajuan lembur.</div><?php endif; ?>
  </div>

<?php elseif ($page === 'reimburse'):
    ensure_claim_tables();
    $claims = q('SELECT * FROM web_reimburse WHERE pegawai_id = ? ORDER BY tgl DESC, id DESC LIMIT 100', [$me['id']])->fetchAll(); ?>
  <div class="p-card">
    <h2>Klaim reimbursement</h2>
    <form method="post" class="p-form two" enctype="multipart/form-data">
      <?= csrf_field() ?><input type="hidden" name="action" value="rb_request">
      <label>Tanggal pengeluaran <input type="date" name="tgl" required max="<?= date('Y-m-d') ?>" value="<?= h(post('tgl', date('Y-m-d'))) ?>"></label>
      <label>Kategori <select name="kategori" required><?php foreach (reimburse_categories() as $k): ?><option <?= post('kategori') === $k ? 'selected' : '' ?>><?= h($k) ?></option><?php endforeach; ?></select></label>
      <label>Jumlah (Rp) <input name="jumlah" inputmode="numeric" required value="<?= h(post('jumlah')) ?>" placeholder="150000"></label>
      <label>Bukti (foto / PDF, maks 5 MB) <input type="file" name="bukti" accept="image/*,application/pdf" required style="height:auto;padding:6px"></label>
      <label class="wide">Keterangan <input name="keterangan" required maxlength="255" value="<?= h(post('keterangan')) ?>"></label>
      <div class="wide"><button class="p-btn primary">Kirim klaim</button> <span class="muted">Klaim yang disetujui dibayar bersama gaji berikutnya.</span></div>
    </form>
  </div>
  <h2>Riwayat klaim</h2>
  <div class="p-list">
    <?php foreach ($claims as $r): ?>
      <div class="p-item">
        <div class="p-date"><b><?= date('d', strtotime($r['tgl'])) ?></b><span><?= date('m-y', strtotime($r['tgl'])) ?></span></div>
        <div class="p-main"><div><?= h($r['kategori']) ?> · <b><?= rupiah($r['jumlah']) ?></b></div>
          <div class="muted"><?= h($r['keterangan']) ?><?= $r['paid_period_to'] ? ' · dibayar dengan gaji s/d ' . h(date('d-m-Y', strtotime($r['paid_period_to']))) : '' ?> · <a href="receipt.php?id=<?= (int)$r['id'] ?>" target="_blank">bukti</a></div></div>
        <div style="display:flex;gap:6px;align-items:center"><?= claim_badge($r['status']) ?>
          <?php if ($r['status'] === CLAIM_PENDING): ?><form method="post" onsubmit="return confirm('Batalkan klaim ini?')"><?= csrf_field() ?>
            <input type="hidden" name="action" value="rb_cancel"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="p-btn small">Batal</button></form><?php endif; ?></div>
      </div>
    <?php endforeach; ?>
    <?php if (!$claims): ?><div class="p-item muted">Belum ada klaim.</div><?php endif; ?>
  </div>

<?php elseif ($page === 'review'):
    ensure_talent_tables();
    $reviews = q('SELECT r.*, c.name, c.period_from, c.period_to, c.status, c.criteria FROM web_review r JOIN web_review_cycle c ON c.id = r.cycle_id
                  WHERE r.pegawai_id = ? ORDER BY c.period_to DESC', [$me['id']])->fetchAll(); ?>
  <?php foreach ($reviews as $rv): $crit = json_decode($rv['criteria'], true); $self = $rv['self_scores'] ? json_decode($rv['self_scores'], true) : [];
      $open = $rv['status'] === 'open'; ?>
    <div class="p-card">
      <h2><?= h($rv['name']) ?> <span class="muted" style="font-weight:400"><?= h(date('d-m-Y', strtotime($rv['period_from']))) ?> – <?= h(date('d-m-Y', strtotime($rv['period_to']))) ?></span></h2>
      <?php if (!$open && $rv['final_score'] !== null): ?>
        <p>Nilai akhir <b><?= number_format($rv['final_score'], 2) ?></b> · <?= h(rating_label((float)$rv['final_score'])) ?></p>
        <?php if ($rv['mgr_note']): ?><p class="muted"><?= nl2br(h($rv['mgr_note'])) ?></p><?php endif; ?>
      <?php endif; ?>
      <?php if ($open): ?>
      <form method="post" class="p-form"><?= csrf_field() ?><input type="hidden" name="action" value="self_review"><input type="hidden" name="cycle_id" value="<?= (int)$rv['cycle_id'] ?>">
        <p class="muted">Nilai diri sendiri untuk setiap kriteria (1 = buruk, 5 = sangat baik).<?= $rv['self_at'] ? ' Terakhir disimpan ' . h(date('d-m-Y H:i', strtotime($rv['self_at']))) . '.' : '' ?></p>
        <?php foreach ($crit as $i => $c): ?>
          <label><?= h($c['name']) ?> (bobot <?= (int)$c['weight'] ?>%)
            <select name="score[<?= $i ?>]" required><option value="">Pilih…</option><?php foreach (score_scale() as $v => $label): ?><option value="<?= $v ?>" <?= isset($self[$i]) && (int)$self[$i] === $v ? 'selected' : '' ?>><?= $v ?> · <?= $label ?></option><?php endforeach; ?></select></label>
        <?php endforeach; ?>
        <label>Catatan: pencapaian, kendala, rencana <textarea name="note" rows="4" style="font:inherit;padding:8px;border:1px solid var(--line);border-radius:8px"><?= h($rv['self_note']) ?></textarea></label>
        <div><button class="p-btn primary">Simpan penilaian diri</button></div>
      </form>
      <?php elseif ($rv['final_score'] === null): ?><p class="muted">Penilaian sudah ditutup.</p><?php endif; ?>
    </div>
  <?php endforeach; ?>
  <?php if (!$reviews): ?><div class="p-list"><div class="p-item muted">Belum ada penilaian kinerja.</div></div><?php endif; ?>

<?php elseif ($page === 'team' && $isBoss):
    $items = team_pending($me['id']);
    $teamIds = q('SELECT x.pegawai_id FROM web_emp_profile x JOIN pegawai p ON p.pegawai_id = x.pegawai_id WHERE x.atasan_id = ? AND p.pegawai_status = 1', [$me['id']])->fetchAll(PDO::FETCH_COLUMN);
    $todayRows = [];
    foreach ($teamIds as $tid) foreach (daily_attendance(date('Y-m-d'), date('Y-m-d'), $tid) as $r) $todayRows[] = $r; ?>
  <div class="p-card"><h2>Perlu persetujuan Anda (<?= count($items) ?>)</h2>
    <?php foreach ($items as $it): ?>
      <div class="p-item" style="padding:10px 0;flex-wrap:wrap"><div class="p-main"><b><?= h($it[2]) ?></b> · <?= h($it[3]) ?><div class="muted"><?= h($it[4]) ?></div></div>
        <form method="post" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap"><?= csrf_field() ?><input type="hidden" name="action" value="team_decide"><input type="hidden" name="kind" value="<?= h($it[0]) ?>"><input type="hidden" name="ref" value="<?= h($it[1]) ?>">
          <input name="note" placeholder="Catatan (opsional)" style="width:160px;height:28px"><button class="p-btn small primary" name="decision" value="approve">Setujui</button>
          <button class="p-btn small" name="decision" value="reject" onclick="return confirm('Tolak pengajuan ini?')">Tolak</button></form></div>
    <?php endforeach; ?>
    <?php if (!$items): ?><p class="muted">Tidak ada pengajuan yang menunggu Anda.</p><?php endif; ?>
    <p class="muted">Persetujuan Anda diteruskan ke HR untuk keputusan akhir; penolakan langsung menolak pengajuan.</p></div>
  <h2>Kehadiran tim hari ini</h2>
  <div class="p-list"><?php foreach ($todayRows as $r): ?><div class="p-item"><div class="p-main"><b><?= h($r['name']) ?></b><div class="muted"><?= h($r['first'] ?: '--:--') ?> – <?= h($r['last'] ?: '--:--') ?><?= $r['leave'] ? ' · ' . h(implode(', ', $r['leave'])) : '' ?></div></div><?= status_badge($r['status']) ?></div><?php endforeach; ?>
    <?php if (!$todayRows): ?><div class="p-item muted">Tidak ada data.</div><?php endif; ?></div>

<?php elseif ($page === 'warnings'):
    $warns = q('SELECT * FROM web_warning WHERE pegawai_id = ? ORDER BY issued DESC, id DESC', [$me['id']])->fetchAll(); ?>
  <div class="p-list">
    <?php foreach ($warns as $w): $active = $w['valid_until'] >= date('Y-m-d'); ?>
      <div class="p-item">
        <div class="p-date"><b><?= date('d', strtotime($w['issued'])) ?></b><span><?= date('m-y', strtotime($w['issued'])) ?></span></div>
        <div class="p-main"><div><b><?= h(warning_titles()[$w['level']]) ?></b> · <?= h($w['violation']) ?></div>
          <div class="muted">No. <?= h($w['number']) ?> · berlaku s/d <?= h(date('d-m-Y', strtotime($w['valid_until']))) ?><?= $active ? '' : ' (sudah berakhir)' ?></div></div>
        <div style="display:flex;gap:6px;align-items:center"><a class="p-btn small" target="_blank" href="discipline.php?print=<?= (int)$w['id'] ?>">Lihat surat</a>
          <?php if (!$w['acknowledged_at']): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="warn_ack"><input type="hidden" name="id" value="<?= (int)$w['id'] ?>">
            <button class="p-btn small primary">Saya sudah menerima</button></form><?php else: ?><span class="badge b-ok">Diterima <?= h(date('d-m-Y', strtotime($w['acknowledged_at']))) ?></span><?php endif; ?></div>
      </div>
    <?php endforeach; ?>
    <?php if (!$warns): ?><div class="p-item muted">Tidak ada peringatan.</div><?php endif; ?>
  </div>

<?php elseif ($page === 'kasbon'):
    ensure_payroll_tables();
    $loans = q('SELECT l.*, (SELECT COALESCE(SUM(jumlah), 0) FROM web_loan_pay x WHERE x.loan_id = l.id) repaid FROM web_loan l WHERE pegawai_id = ? ORDER BY tgl DESC, id DESC', [$me['id']])->fetchAll(); ?>
  <div class="p-card">
    <h2>Ajukan kasbon</h2>
    <form method="post" class="p-form two"><?= csrf_field() ?><input type="hidden" name="action" value="loan_request">
      <label>Jumlah (Rp) <input name="jumlah" inputmode="numeric" required value="<?= h(post('jumlah')) ?>" placeholder="1000000"></label>
      <label>Cicilan per gaji (Rp) <input name="cicilan" inputmode="numeric" required value="<?= h(post('cicilan')) ?>" placeholder="250000"></label>
      <label class="wide">Keperluan <input name="keterangan" required maxlength="255" value="<?= h(post('keterangan')) ?>"></label>
      <div class="wide"><button class="p-btn primary">Kirim pengajuan</button> <span class="muted">Setelah disetujui HR, cicilan dipotong dari gaji sampai lunas.</span></div>
    </form>
  </div>
  <h2>Kasbon saya</h2>
  <div class="p-list">
    <?php foreach ($loans as $l): ?>
      <div class="p-item">
        <div class="p-date"><b><?= date('d', strtotime($l['tgl'])) ?></b><span><?= date('m-y', strtotime($l['tgl'])) ?></span></div>
        <div class="p-main"><div><b><?= rupiah($l['jumlah']) ?></b> · cicilan <?= rupiah($l['cicilan']) ?> per gaji</div>
          <div class="muted"><?= h($l['keterangan']) ?><?= in_array($l['status'], [LOAN_ACTIVE, LOAN_PAID], true) ? ' · sudah dibayar ' . rupiah($l['repaid']) . ' · sisa ' . rupiah($l['jumlah'] - $l['repaid']) : '' ?></div></div>
        <div style="display:flex;gap:6px;align-items:center"><?php $lb = [LOAN_PENDING => ['b-pending', 'Menunggu'], LOAN_ACTIVE => ['b-ok', 'Berjalan'], LOAN_PAID => ['b-off', 'Lunas'], LOAN_REJECTED => ['b-absent', 'Ditolak']][$l['status']]; ?><span class="badge <?= $lb[0] ?>"><?= $lb[1] ?></span>
          <?php if ($l['status'] === LOAN_PENDING): ?><form method="post" onsubmit="return confirm('Batalkan pengajuan ini?')"><?= csrf_field() ?>
            <input type="hidden" name="action" value="loan_cancel"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>"><button class="p-btn small">Batal</button></form><?php endif; ?></div>
      </div>
    <?php endforeach; ?>
    <?php if (!$loans): ?><div class="p-item muted">Belum ada kasbon.</div><?php endif; ?>
  </div>

<?php elseif ($page === 'slip'):
    ensure_payroll_tables();
    $slips = q('SELECT period_from, period_to, netto FROM web_payslip WHERE pegawai_id = ? AND published = 1 ORDER BY period_to DESC LIMIT 36', [$me['id']])->fetchAll();
    $thrs = q('SELECT t.id, t.name, t.pay_date, s.netto FROM web_thr_slip s JOIN web_thr t ON t.id = s.thr_id WHERE s.pegawai_id = ? AND t.published = 1 AND s.amount > 0 ORDER BY t.pay_date DESC', [$me['id']])->fetchAll();
    $years = array_unique(array_map(function ($r) { return substr($r['period_to'], 0, 4); }, $slips)); ?>
  <?php if ($thrs): ?><h2>THR</h2><div class="p-list" style="margin-bottom:14px">
    <?php foreach ($thrs as $t): ?><div class="p-item"><div class="p-date"><b><?= date('m', strtotime($t['pay_date'])) ?></b><span><?= date('Y', strtotime($t['pay_date'])) ?></span></div>
      <div class="p-main"><div><?= h($t['name']) ?></div><div class="muted">Diterima <?= rupiah($t['netto']) ?></div></div>
      <a class="p-btn small" target="_blank" href="payslip.php?<?= h(http_build_query(['thr' => $t['id'], 'id' => $me['id']])) ?>">Lihat slip</a></div><?php endforeach; ?></div><?php endif; ?>
  <?php if ($years): ?><h2>Bukti potong PPh 21</h2><div class="p-list" style="margin-bottom:14px">
    <?php foreach ($years as $y): ?><div class="p-item"><div class="p-main"><div>Tahun pajak <?= h($y) ?></div><div class="muted"><?= (int)$y < (int)date('Y') || date('n') == 12 ? 'BPA1 (1721-A1)' : 'Sementara, sampai gaji Desember' ?></div></div>
      <a class="p-btn small" target="_blank" href="bupot.php?<?= h(http_build_query(['year' => $y, 'id' => $me['id']])) ?>">Lihat</a></div><?php endforeach; ?></div><h2>Slip gaji</h2><?php endif; ?>
  <div class="p-list">
    <?php foreach ($slips as $r): ?>
      <div class="p-item">
        <div class="p-date"><b><?= date('m', strtotime($r['period_to'])) ?></b><span><?= date('Y', strtotime($r['period_to'])) ?></span></div>
        <div class="p-main"><div>Gaji <?= h(date('d-m-Y', strtotime($r['period_from']))) ?> – <?= h(date('d-m-Y', strtotime($r['period_to']))) ?></div>
          <div class="muted">Take home pay <?= rupiah($r['netto']) ?></div></div>
        <a class="p-btn small" target="_blank" href="payslip.php?<?= h(http_build_query(['to' => $r['period_to'], 'id' => $me['id']])) ?>">Lihat slip</a>
      </div>
    <?php endforeach; ?>
    <?php if (!$slips): ?><div class="p-item muted">Belum ada slip gaji.</div><?php endif; ?>
  </div>

<?php else: /* profile */
    $sched = q('SELECT m.jdw_kerja_m_name FROM jdw_kerja_pegawai j JOIN jdw_kerja_m m ON m.jdw_kerja_m_id = j.jdw_kerja_m_id
                WHERE j.pegawai_id = ? ORDER BY j.jdw_kerja_m_mulai DESC LIMIT 1', [$me['id']])->fetchColumn();
    $fields = ['PIN' => $emp['pegawai_pin'], 'NIP' => $emp['pegawai_nip'], 'Jabatan' => $emp['jab'], 'Departemen' => $emp['dep'], 'Kantor' => $emp['kan'],
               'Jadwal kerja' => $sched, 'Mulai bekerja' => $emp['tgl_mulai_kerja'] ? date('d-m-Y', strtotime($emp['tgl_mulai_kerja'])) : '',
               'Jenis kelamin' => (int)$emp['gender'] === 2 ? 'Perempuan' : 'Laki-laki', 'Tempat, tanggal lahir' => trim($emp['tempat_lahir'] . ($emp['tgl_lahir'] ? ', ' . date('d-m-Y', strtotime($emp['tgl_lahir'])) : ''), ', '),
               'No. telp' => $emp['pegawai_telp']]; ?>
  <div class="p-card p-profile">
    <div class="p-avatar big"><?= h(initials($emp['pegawai_nama'])) ?></div>
    <div><h1 style="margin:0"><?= h($emp['pegawai_nama']) ?></h1><div class="muted"><?= h(trim($emp['jab'] . ' · ' . $emp['dep'], ' ·')) ?></div></div>
  </div>
  <?php ensure_people_tables(); $pr = get_profile($me['id']);
        $pend = q("SELECT created_at FROM web_profile_request WHERE pegawai_id = ? AND status = 'pending'", [$me['id']])->fetchColumn();
        $docs = q('SELECT * FROM web_emp_doc WHERE pegawai_id = ? ORDER BY kind, created_at DESC', [$me['id']])->fetchAll();
        unset($fields['Tempat, tanggal lahir'], $fields['No. telp']); ?>
  <div class="p-card">
    <dl class="p-dl"><?php foreach ($fields as $k => $v): ?><dt><?= h($k) ?></dt><dd><?= $v !== '' && $v !== null ? h($v) : '<span class="muted">—</span>' ?></dd><?php endforeach; ?>
      <?php foreach (profile_fields() as $k => $f): ?><dt><?= h($f[0]) ?></dt><dd><?= h(profile_value_label($k, $pr[$k])) ?></dd><?php endforeach; ?></dl>
  </div>
  <div class="p-card"><h2>Ubah data pribadi</h2>
    <?php if ($pend): ?><p class="muted">Pengajuan perubahan data Anda (<?= h(date('d-m-Y H:i', strtotime($pend))) ?>) sedang diperiksa HR.</p>
    <?php else: ?><form method="post" class="p-form two"><?= csrf_field() ?><input type="hidden" name="action" value="profile_request">
      <?php foreach (profile_fields() as $k => $f): $n = str_replace(':', '_', $k); ?>
        <label><?= h($f[0]) ?><?php if ($f[1] === 'select'): ?> <select name="<?= $n ?>"><option value="">—</option><?php foreach ($f[2] as $ok => $ol): ?><option value="<?= h($ok) ?>"<?= $pr[$k] === (string)$ok ? ' selected' : '' ?>><?= h($ol) ?></option><?php endforeach; ?></select>
        <?php else: ?> <input type="<?= $f[1] ?>" name="<?= $n ?>" value="<?= h($pr[$k]) ?>"<?= $f[1] === 'number' ? ' min="0" max="9"' : '' ?>><?php endif; ?></label>
      <?php endforeach; ?>
      <label class="wide">Catatan untuk HR <input name="note" maxlength="255"></label>
      <div class="wide"><button class="p-btn primary">Kirim perubahan</button> <span class="muted">Perubahan disimpan setelah disetujui HR. Lampirkan bukti (mis. buku nikah, KK baru) di Dokumen saya.</span></div></form><?php endif; ?>
  </div>
  <?php $myAssets = q('SELECT * FROM web_asset WHERE pegawai_id = ? AND active = 1', [$me['id']])->fetchAll(); if ($myAssets): ?>
  <div class="p-card"><h2>Aset yang saya pakai</h2><?php foreach ($myAssets as $a): ?><div class="muted" style="padding:2px 0"><b style="color:var(--text)"><?= h($a['name']) ?></b> <?= h($a['serial']) ?> · sejak <?= h(date('d-m-Y', strtotime($a['assigned_at']))) ?></div><?php endforeach; ?></div><?php endif; ?>
  <?php require_once __DIR__ . '/inc/files.php'; ensure_files_tables(); $shared = q('SELECT * FROM web_file WHERE shared = 1 ORDER BY category, title')->fetchAll(); if ($shared): ?>
  <div class="p-card"><h2>Dokumen perusahaan</h2><div class="p-list"><?php foreach ($shared as $f): ?><div class="p-item"><div class="p-main"><b><?= h($f['title']) ?></b><div class="muted"><?= h(files_categories()[$f['category']] ?? $f['category']) ?> · <?= h(file_size_label($f['size'])) ?></div></div><a class="p-btn small" href="filedl.php?id=<?= (int)$f['id'] ?>">Unduh</a></div><?php endforeach; ?></div></div>
  <?php endif; ?>
  <div class="p-card"><h2>Dokumen saya</h2>
    <div class="p-list" style="margin-bottom:10px"><?php foreach ($docs as $d): ?><div class="p-item"><div class="p-main"><b><?= h(doc_kinds()[$d['kind']] ?? $d['kind']) ?></b> <?= h($d['title']) ?>
      <?= $d['expires'] ? '<div class="muted">berlaku s/d ' . h(date('d-m-Y', strtotime($d['expires']))) . '</div>' : '' ?></div><a class="p-btn small" target="_blank" href="doc.php?id=<?= (int)$d['id'] ?>">Lihat</a></div><?php endforeach; ?>
      <?php if (!$docs): ?><div class="p-item muted">Belum ada dokumen.</div><?php endif; ?></div>
    <form method="post" enctype="multipart/form-data" class="p-form two"><?= csrf_field() ?><input type="hidden" name="action" value="my_doc">
      <label>Jenis <select name="kind"><?php foreach (doc_kinds() as $k => $l): ?><option value="<?= $k ?>"><?= h($l) ?></option><?php endforeach; ?></select></label>
      <label>Keterangan <input name="title" maxlength="120"></label>
      <label>Berlaku sampai (opsional) <input type="date" name="expires"></label>
      <label>File (PDF / foto, maks 5 MB) <input type="file" name="file" required accept=".pdf,image/*" style="height:auto;padding:6px"></label>
      <div class="wide"><button class="p-btn">Unggah</button></div></form>
  </div>
  <div class="p-card">
    <h2>Ganti kode login</h2>
    <form method="post" class="p-form two">
      <?= csrf_field() ?><input type="hidden" name="action" value="code">
      <label>Kode baru <input type="password" name="code" minlength="6" required autocomplete="new-password"></label>
      <label>Ulangi <input type="password" name="code2" minlength="6" required autocomplete="new-password"></label>
      <div class="wide"><button class="p-btn">Simpan</button></div>
    </form>
  </div>
<?php endif;
portal_footer();

// ---------- layout ----------
function portal_header($title, $tabs, $active = '', $emp = null)
{
    ?><!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> · Portal Pegawai</title>
<link rel="stylesheet" href="assets/portal.css">
</head>
<body>
<?php if ($tabs): ?>
<header class="p-top">
  <div class="p-brand">Portal Pegawai</div>
  <nav><?php foreach ($tabs as $k => $label): ?><a href="portal.php?p=<?= $k ?>" class="<?= $k === $active ? 'on' : '' ?>"><?= h($label) ?></a><?php endforeach; ?></nav>
  <div class="p-me"><span class="p-avatar"><?= h(initials($emp['pegawai_nama'])) ?></span><a href="portal.php?p=logout">Keluar</a></div>
</header>
<?php endif; ?>
<main class="p-wrap">
<?php
}

function portal_footer()
{
    echo "</main>\n</body>\n</html>\n";
}
