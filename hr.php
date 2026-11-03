<?php
// HR (HRIS-style): Beranda, Karyawan (directory + profile), Manajemen Waktu (kehadiran, cuti & izin, saldo cuti).
// Attendance entry stays on the Fingerspot-style screens, linked from the rail and the top bar.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/portal.php';
require __DIR__ . '/inc/hris.php';
require __DIR__ . '/inc/payroll.php';
require __DIR__ . '/inc/shifts.php';
require_once __DIR__ . '/inc/employment.php';
require __DIR__ . '/inc/discipline.php';
require __DIR__ . '/inc/people.php';
require __DIR__ . '/inc/company.php';
require __DIR__ . '/inc/approval.php';
require __DIR__ . '/inc/homestats.php';
require __DIR__ . '/inc/transfer.php';
$me = require_login();
ensure_web_tables();
ensure_payroll_tables();
ensure_shift_tables();
ensure_employment_tables();
ensure_discipline_tables();
ensure_people_tables();
ensure_company_tables();
ensure_approval_tables();
ensure_transfer_tables();
transfers_apply_due();   // a transfer whose day has come takes effect before anything is shown

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = post('action');
    if ($action === 'approve' || $action === 'reject') {
        decide_request(leave_key_from_post(), $action === 'approve');
        flash($action === 'approve' ? 'Pengajuan disetujui.' : 'Pengajuan ditolak.');
    } elseif ($action === 'ot_approve' || $action === 'ot_reject') {
        $row = q('SELECT o.*, p.pegawai_nama FROM web_overtime o JOIN pegawai p ON p.pegawai_id = o.pegawai_id WHERE o.id = ? AND o.status = ?', [(int)post('id'), CLAIM_PENDING])->fetch();
        if ($row) {
            q('UPDATE web_overtime SET status = ?, decided_by = ? WHERE id = ?', [$action === 'ot_approve' ? CLAIM_APPROVED : CLAIM_REJECTED, actor(), $row['id']]);
            audit($action, "{$row['pegawai_nama']} {$row['tgl']} " . fmt_minutes($row['minutes']));
            flash($action === 'ot_approve' ? 'Lembur disetujui.' : 'Lembur ditolak.');
        }
    } elseif ($action === 'set_shift') {
        $ids = isset($_POST['emp']) ? (array)$_POST['emp'] : [];
        $shift = post('shift');
        $from = post('from'); $to = post('to', $from);
        if (!$ids) flash('Pilih karyawan.', 'err');
        elseif (!valid_date($from) || !valid_date($to) || $to < $from || (strtotime($to) - strtotime($from)) / 86400 > 92) flash('Isi rentang tanggal yang benar (maks. 3 bulan).', 'err');
        elseif ($shift !== 'off' && $shift !== 'schedule' && !isset(work_hours()[(int)$shift])) flash('Pilih jam kerja.', 'err');
        else {
            $n = set_shift($ids, $from, $to, $shift, post('note'));
            audit('shift_set', count($ids) . " karyawan $from..$to " . $shift);
            flash($shift === 'schedule' ? 'Jadwal dikembalikan ke jadwal kerja.' : "Shift diubah untuk $n hari-karyawan.");
        }
    } elseif ($action === 'swap_approve' || $action === 'swap_reject') {
        $row = q('SELECT * FROM web_shift_swap WHERE id = ? AND status = ?', [(int)post('id'), CLAIM_PENDING])->fetch();
        if ($row) {
            if ($action === 'swap_approve') save_swap($row['tgl'], $row['pegawai_id'], $row['with_id'], $row['alasan']);
            q('UPDATE web_shift_swap SET status = ?, decided_by = ? WHERE id = ?', [$action === 'swap_approve' ? CLAIM_APPROVED : CLAIM_REJECTED, actor(), $row['id']]);
            audit($action, "{$row['pegawai_id']} <-> {$row['with_id']} {$row['tgl']}");
            flash($action === 'swap_approve' ? 'Tukar shift disetujui.' : 'Tukar shift ditolak.');
        }
    } elseif ($action === 'swap_add') {
        $a = (int)post('a'); $b = (int)post('b');
        if (!valid_date(post('tgl')) || !$a || !$b || $a === $b) flash('Pilih tanggal dan dua karyawan yang berbeda.', 'err');
        else { save_swap(post('tgl'), $a, $b, post('alasan')); audit('swap_add', "$a <-> $b " . post('tgl')); flash('Tukar shift disimpan.'); }
    } elseif ($action === 'swap_delete') {
        q('DELETE FROM tukar_jam WHERE tukar_tgl = ? AND pegawai_id1 = ? AND pegawai_id2 = ?', [post('tgl'), (int)post('a'), (int)post('b')]);
        flash('Tukar shift dihapus.');
    } elseif ($action === 'emp_status_save' && $me['role'] === 'admin') {
        $emp = employee(post('pegawai_id'));
        $status = post('status');
        $err = $emp ? employment_error($status, post('start'), post('end'), post('probation'), $emp['pegawai_id']) : 'Karyawan tidak ditemukan.';
        if ($err) flash($err, 'err');
        else {
            save_employment($emp['pegawai_id'], $status, post('contract_no'), post('start'), post('end'), post('probation'), post('note'));
            audit('employment_save', "{$emp['pegawai_nama']} $status " . post('start') . '..' . post('end'));
            flash('Status kepegawaian disimpan.');
        }
    } elseif ($action === 'emp_status_delete' && $me['role'] === 'admin') {
        $row = q('SELECT e.*, p.pegawai_nama FROM web_employment e JOIN pegawai p ON p.pegawai_id = e.pegawai_id WHERE e.id = ?', [(int)post('id')])->fetch();
        if ($row) {
            q('DELETE FROM web_employment WHERE id = ?', [$row['id']]);
            audit('employment_delete', "{$row['pegawai_nama']} {$row['status']} {$row['start_date']}");
            flash('Riwayat status dihapus.');
        }
    } elseif ($action === 'emp_status_bulk' && $me['role'] === 'admin') {
        // first-time setup: one status for every active employee without one, from their start date
        $status = post('status');
        $have = current_employment();
        $n = 0;
        if (isset(employment_statuses()[$status]) && !employment_needs_end($status)) {
            foreach (employees(true) as $e) {
                if (isset($have[$e['pegawai_id']])) continue;
                save_employment($e['pegawai_id'], $status, '', $e['tgl_mulai_kerja'] ?: date('Y-m-d'), null, null, 'Diisi massal');
                $n++;
            }
            audit('employment_bulk', "$status: $n karyawan");
        }
        flash("Status diisi untuk $n karyawan. Ubah karyawan kontrak satu per satu di profilnya.");
    } elseif ($action === 'profile_save' && $me['role'] === 'admin') {
        $emp = employee(post('pegawai_id'));
        if ($emp) { save_profile($emp['pegawai_id'], clean_profile($_POST)); audit('profile_save', $emp['pegawai_nama']); flash('Data pribadi disimpan.'); }
    } elseif (($action === 'profile_req_approve' || $action === 'profile_req_reject') && $me['role'] === 'admin') {
        $rq = q("SELECT r.*, p.pegawai_nama FROM web_profile_request r JOIN pegawai p ON p.pegawai_id = r.pegawai_id WHERE r.id = ? AND r.status = 'pending'", [(int)post('id')])->fetch();
        if ($rq) {
            if ($action === 'profile_req_approve') save_profile($rq['pegawai_id'], array_intersect_key(json_decode($rq['data'], true), profile_fields()));
            q('UPDATE web_profile_request SET status = ?, decided_by = ?, decided_at = ? WHERE id = ?', [$action === 'profile_req_approve' ? 'approved' : 'rejected', actor(), date('Y-m-d H:i:s'), $rq['id']]);
            audit($action, $rq['pegawai_nama']);
            flash($action === 'profile_req_approve' ? 'Perubahan data disimpan.' : 'Pengajuan perubahan data ditolak.');
        }
    } elseif ($action === 'doc_upload' && $me['role'] === 'admin') {
        $emp = employee(post('pegawai_id'));
        list($file, $err) = save_doc_file(isset($_FILES['file']) ? $_FILES['file'] : null);
        if (!$emp || $err || !$file) flash($err ?: 'Pilih file dokumen.', 'err');
        else {
            q('INSERT INTO web_emp_doc (pegawai_id, kind, title, file, expires, uploaded_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$emp['pegawai_id'], isset(doc_kinds()[post('kind')]) ? post('kind') : 'lain', mb_substr(post('title'), 0, 120), $file, valid_date(post('expires')) ? post('expires') : null, actor(), date('Y-m-d H:i:s')]);
            audit('doc_upload', "{$emp['pegawai_nama']} " . post('kind'));
            flash('Dokumen diunggah.');
        }
    } elseif ($action === 'doc_delete' && $me['role'] === 'admin') {
        $d = q('SELECT * FROM web_emp_doc WHERE id = ?', [(int)post('id')])->fetch();
        if ($d) { @unlink(DOC_DIR . '/' . basename($d['file'])); q('DELETE FROM web_emp_doc WHERE id = ?', [$d['id']]); audit('doc_delete', $d['pegawai_id'] . ' ' . $d['kind']); flash('Dokumen dihapus.'); }
    } elseif ($action === 'code' && $me['role'] === 'admin') {
        $emp = employee(post('pegawai_id'));
        if ($emp) {
            $code = (string)random_int(100000, 999999);
            q('REPLACE INTO web_emp (pegawai_id, password_hash, created_at) VALUES (?, ?, ?)', [$emp['pegawai_id'], password_hash($code, PASSWORD_DEFAULT), date('Y-m-d H:i:s')]);
            audit('portal_code', $emp['pegawai_pin'] . ' ' . $emp['pegawai_nama']);
            // shown once; only the hash is stored
            flash("Kode login Portal Pegawai untuk {$emp['pegawai_nama']} (PIN {$emp['pegawai_pin']}): $code — berikan ke karyawan, kode ini tidak ditampilkan lagi.");
        }
    }
    redirect(safe_back(post('back'), 'hr.php'));
}

$m = get('m', 'home');
if ($m === 'report') require __DIR__ . '/inc/reports.php';   // before any output: ?csv=1 downloads and exits
$today = date('Y-m-d');
$back = h($_SERVER['REQUEST_URI']);
$csrf = csrf_field();
$statusNames = ['1' => 'Aktif', '0' => 'Non aktif', '2' => 'Berhenti'];

function people($where = 'p.pegawai_status = 1', $params = [])
{
    return q("SELECT p.*, p1.pembagian1_nama jab, p2.pembagian2_nama dep, p3.pembagian3_nama kan, w.pegawai_id portal, w.last_login
              FROM pegawai p LEFT JOIN pembagian1 p1 ON p1.pembagian1_id = p.pembagian1_id
              LEFT JOIN pembagian2 p2 ON p2.pembagian2_id = p.pembagian2_id LEFT JOIN pembagian3 p3 ON p3.pembagian3_id = p.pembagian3_id
              LEFT JOIN web_emp w ON w.pegawai_id = p.pegawai_id WHERE $where ORDER BY p.pegawai_nama", $params)->fetchAll();
}

function who($p, $link = true)
{
    $name = h($p['pegawai_nama']);
    return '<div class="who"><span class="avatar">' . h(initials($p['pegawai_nama'])) . '</span><div>'
         . ($link ? '<a href="hr.php?m=employee&id=' . (int)$p['pegawai_id'] . '"><b>' . $name . '</b></a>' : '<b>' . $name . '</b>')
         . '<small>PIN ' . h($p['pegawai_pin']) . '</small></div></div>';
}

function decide_buttons($r, $csrf, $back)
{
    $out = supervisor_badge('izin', izin_ref($r), $r['pegawai_id']);
    $out .= $out ? ' ' : '';
    foreach (['approve' => ['primary', 'Setujui'], 'reject' => ['outline', 'Tolak']] as $act => $b) {
        $out .= '<form method="post" class="inline">' . $csrf . '<input type="hidden" name="action" value="' . $act . '"><input type="hidden" name="back" value="' . $back . '">'
              . izin_key_fields($r) . '<button class="btn small ' . $b[0] . '">' . $b[1] . '</button></form> ';
    }
    return $out;
}

$pendingSql = "SELECT i.*, p.pegawai_nama, p.pegawai_pin, j.izin_jenis_name FROM izin i JOIN pegawai p ON p.pegawai_id = i.pegawai_id
               LEFT JOIN jns_izin j ON j.izin_jenis_id = i.izin_jenis_id WHERE i.izin_status = 0 AND i.ket_status = ? ORDER BY i.izin_tgl, p.pegawai_nama";

// ======================================================================= Beranda
if ($m === 'home'):
    $rows = daily_attendance($today, $today);
    $c = ['ok' => 0, 'incomplete' => 0, 'absent' => 0, 'leave' => 0, 'off' => 0];
    $late = 0;
    foreach ($rows as $r) { $c[$r['status']]++; if ($r['late']) $late++; }
    $pending = q($pendingSql, [REQ_PENDING])->fetchAll();
    list($offFrom, $offTo, $offLabel) = home_off_range(get('off'), $today);
    $off = q("SELECT i.izin_tgl, p.pegawai_id, p.pegawai_pin, p.pegawai_nama, j.izin_jenis_name FROM izin i JOIN pegawai p ON p.pegawai_id = i.pegawai_id
              LEFT JOIN jns_izin j ON j.izin_jenis_id = i.izin_jenis_id
              WHERE i.izin_status = 1 AND i.izin_jenis_id NOT BETWEEN 100 AND 105 AND i.izin_tgl BETWEEN ? AND ? ORDER BY i.izin_tgl, p.pegawai_nama",
             [$offFrom, $offTo])->fetchAll();
    $all = people();
    $bdays = array_filter($all, function ($p) { return $p['tgl_lahir'] && date('m', strtotime($p['tgl_lahir'])) === date('m'); });
    $news = array_filter($all, function ($p) { return $p['tgl_mulai_kerja'] && $p['tgl_mulai_kerja'] >= date('Y-m-d', strtotime('-90 days')); });
    $total = count($rows) ?: 1;
    $hr = (int)date('G');
    $alerts = employment_alerts(30);
    foreach (documents_expiring(30) as $d) $alerts[] = ['emp' => ['pegawai_id' => $d['pegawai_id'], 'pegawai_nama' => $d['pegawai_nama']], 'what' => (doc_kinds()[$d['kind']] ?? $d['kind']) . ' berakhir', 'date' => $d['expires'], 'tab' => 'docs'];
    usort($alerts, function ($a, $b) { return strcmp($a['date'], $b['date']); });
    $profileReqs = q("SELECT r.id, r.pegawai_id, r.created_at, p.pegawai_nama FROM web_profile_request r JOIN pegawai p ON p.pegawai_id = r.pegawai_id WHERE r.status = 'pending' ORDER BY r.created_at")->fetchAll();
    // the four overview widgets, optionally for one department
    $depF = (int)get('dep');
    $scope = $depF ? array_values(array_filter($all, function ($p) use ($depF) { return (int)$p['pembagian2_id'] === $depF; })) : $all;
    $hStatus = home_status($scope, current_employment(), employment_statuses());
    $hService = home_service($scope, $today);
    $hJab = home_jabatan($scope, array_values(lookup('pembagian1')));
    $hGender = home_gender($scope);
    $profile = [];
    foreach (q('SELECT pegawai_id, education, religion FROM web_emp_profile') as $r) $profile[$r['pegawai_id']] = $r;
    $pf = profile_fields();
    $hAge = home_age($scope, $today);
    $hEdu = home_field($scope, array_map(function ($r) { return $r['education']; }, $profile), $pf['education'][2]);
    $hRel = home_field($scope, array_map(function ($r) { return $r['religion']; }, $profile), $pf['religion'][2]);
    $staffAll = q('SELECT tgl_mulai_kerja start, tgl_resign end FROM pegawai' . ($depF ? ' WHERE pembagian2_id = ?' : ''), $depF ? [$depF] : [])->fetchAll();
    $hTurn = home_turnover($staffAll, $today, 6);
    $thisMonth = $hTurn[count($hTurn) - 1];
    $segList = function ($counts) {
        $t = array_sum($counts); $pal = home_palette(); $i = 0; $bar = ''; $rows = '';
        foreach ($counts as $label => $n) {
            $col = $label === 'Belum diisi' ? '#8b97a7' : $pal[$i++ % count($pal)];   // not filled in is grey, never a status colour
            if ($n > 0) $bar .= '<i style="width:' . (100 * $n / $t) . '%;background:' . $col . '"></i>';
            $rows .= '<div><i class="dot" style="background:' . $col . '"></i><span>' . h($label) . '</span><span class="n">' . $n . '</span><span class="p">' . home_pct($n, $t) . '%</span></div>';
        }
        return '<div class="stack">' . $bar . '</div><div class="kv"><div class="tot"><span>Total</span><span>' . $t . '</span></div>' . $rows . '</div>';
    };
    hris_header('Beranda', 'home', 'home'); ?>
  <div class="homegrid">
    <div class="card hello">
      <h1><?= $hr < 11 ? 'Selamat pagi' : ($hr < 15 ? 'Selamat siang' : ($hr < 18 ? 'Selamat sore' : 'Selamat malam')) ?>, <?= h(explode(' ', $me['full_name'] ?: $me['username'])[0]) ?></h1>
      <p><?= h(fmt_date($today)) ?> · <?= count($all) ?> karyawan aktif</p>
      <div class="label">Pintasan</div>
      <div class="chips">
        <a class="chip" href="dashboard.php">Absensi (Fingerspot)</a>
        <a class="chip" href="quick.php">Input Cepat</a>
        <a class="chip" href="manual.php">Revisi Scan Manual</a>
        <a class="chip" href="hr.php?m=timeoff">Persetujuan cuti</a>
        <a class="chip" href="report.php">Laporan</a>
      </div>
    </div>
    <div class="card" style="padding:14px 22px">
      <form method="get" class="overview-bar"><input type="hidden" name="m" value="home"><input type="hidden" name="off" value="<?= h(get('off')) ?>">
        <b>Ringkasan karyawan</b>
        <span class="muted"><b style="color:var(--ink)"><?= count($scope) ?></b> aktif · <b style="color:var(--ink)"><?= $thisMonth['joined'] ?></b> baru bulan ini · <b style="color:var(--ink)"><?= $thisMonth['left'] ?></b> keluar bulan ini</span>
        <label class="muted">Departemen <select name="dep" onchange="this.form.submit()"><option value="">Semua</option><?php foreach (lookup('pembagian2') as $id => $name): ?><option value="<?= (int)$id ?>"<?= (int)$id === $depF ? ' selected' : '' ?>><?= h($name) ?></option><?php endforeach; ?></select></label></form>
    </div>
    <div class="grid4">
      <div class="card widget"><div class="wbody"><h2>Status kepegawaian</h2><?= $segList($hStatus) ?></div><div class="wfoot"><a href="hr.php?m=employees&status=1">Lihat karyawan ›</a></div></div>
      <div class="card widget"><div class="wbody"><h2>Masa kerja</h2>
        <?php $hMax = max(1, max($hService)); ?><div class="hist"><?php foreach ($hService as $label => $n): ?><div class="hbar"><span class="hv"><?= $n ?></span><i style="height:<?= max(2, 100 * $n / $hMax) ?>%"></i><small><?= h($label) ?></small></div><?php endforeach; ?></div></div>
        <div class="wfoot muted">Dari tanggal mulai bekerja</div></div>
      <div class="card widget"><div class="wbody"><h2>Level jabatan</h2><?= $segList($hJab) ?></div><div class="wfoot"><a href="master.php?t=pembagian1">Atur urutan jabatan ›</a></div></div>
      <div class="card widget"><div class="wbody"><h2>Keragaman gender</h2>
        <div class="donut" style="background:<?= h(home_conic($hGender)) ?>"><span><?= array_sum($hGender) ?></span></div>
        <div class="kv"><?php $gi = 0; foreach ($hGender as $label => $n): $col = home_palette()[$gi++]; ?><div><i class="dot" style="background:<?= $col ?>"></i><span><?= h($label) ?></span><span class="n"><?= $n ?></span><span class="p"><?= home_pct($n, array_sum($hGender)) ?>%</span></div><?php endforeach; ?></div></div>
        <div class="wfoot muted">Karyawan aktif<?= $depF ? ' di departemen ini' : '' ?></div></div>
    </div>
    <div class="grid4">
      <div class="card widget"><div class="wbody"><h2>Usia</h2>
        <div style="font-size:32px;font-weight:600"><?= $hAge['avg'] === null ? '—' : str_replace('.', ',', (string)$hAge['avg']) ?> <span class="muted" style="font-size:14px;font-weight:400">tahun rata-rata</span></div>
        <?php $aMax = max(1, max($hAge['buckets'])); ?><div class="hist" style="height:90px"><?php foreach ($hAge['buckets'] as $label => $n): ?><div class="hbar"><span class="hv"><?= $n ?></span><i style="height:<?= max(2, 100 * $n / $aMax) ?>%<?= $label === 'Belum diisi' ? ';background:#8b97a7' : '' ?>"></i><small><?= h($label) ?></small></div><?php endforeach; ?></div></div>
        <div class="wfoot muted">Dari tanggal lahir</div></div>
      <div class="card widget"><div class="wbody"><h2>Pendidikan</h2><?= $hEdu ? $segList($hEdu) : '<div class="muted">Belum ada data.</div>' ?></div><div class="wfoot muted">Dari data pribadi karyawan</div></div>
      <div class="card widget"><div class="wbody"><h2>Agama</h2><?= $hRel ? $segList($hRel) : '<div class="muted">Belum ada data.</div>' ?></div><div class="wfoot muted">Dari data pribadi karyawan</div></div>
      <div class="card widget"><div class="wbody"><h2>Turnover bulanan</h2>
        <?php $tMax = max(1, max(array_merge([1], array_column($hTurn, 'joined'), array_column($hTurn, 'left')))); ?>
        <div class="hist" style="height:110px"><?php foreach ($hTurn as $t): ?><div class="hbar" title="<?= h($t['ym']) ?>: <?= $t['joined'] ?> masuk, <?= $t['left'] ?> keluar, turnover <?= str_replace('.', ',', (string)$t['rate']) ?>%">
          <span class="hv"><?= $t['left'] ?></span><i style="height:<?= max(2, 100 * $t['left'] / $tMax) ?>%;background:var(--bad)"></i><small><?= h(substr($t['ym'], 5)) ?>/<?= h(substr($t['ym'], 2, 2)) ?></small></div><?php endforeach; ?></div>
        <div class="kv"><div><i class="dot" style="background:var(--bad)"></i><span>Keluar bulan ini</span><span class="n"><?= $thisMonth['left'] ?></span><span class="p"><?= str_replace('.', ',', (string)$thisMonth['rate']) ?>%</span></div>
          <div><i class="dot" style="background:var(--primary)"></i><span>Masuk bulan ini</span><span class="n"><?= $thisMonth['joined'] ?></span><span class="p"></span></div></div></div>
        <div class="wfoot muted">6 bulan terakhir · batang = karyawan keluar</div></div>
    </div>
    <div class="grid4">
      <div class="card widget"><div class="wbody"><h2>Kehadiran hari ini</h2>
        <div class="stack"><i style="width:<?= 100 * ($c['ok'] + $c['incomplete']) / $total ?>%;background:var(--ok)"></i><i style="width:<?= 100 * $c['leave'] / $total ?>%;background:var(--off)"></i><i style="width:<?= 100 * $c['absent'] / $total ?>%;background:var(--bad)"></i></div>
        <div class="kv">
          <div><i class="dot" style="background:var(--ok)"></i><span>Hadir</span><span class="n"><?= $c['ok'] + $c['incomplete'] ?></span><span class="p"><?= $late ?> telat</span></div>
          <div><i class="dot" style="background:var(--off)"></i><span>Cuti / izin</span><span class="n"><?= $c['leave'] ?></span><span class="p"></span></div>
          <div><i class="dot" style="background:var(--bad)"></i><span>Belum / tidak scan</span><span class="n"><?= $c['absent'] ?></span><span class="p"></span></div>
          <div><i class="dot" style="background:var(--line)"></i><span>Libur</span><span class="n"><?= $c['off'] ?></span><span class="p"></span></div>
        </div></div><div class="wfoot"><a href="hr.php?m=attendance">Lihat kehadiran ›</a></div></div>
      <?php $otPend = (int)q('SELECT COUNT(*) FROM web_overtime WHERE status = ?', [CLAIM_PENDING])->fetchColumn();
            $rbPend = (int)q('SELECT COUNT(*) FROM web_reimburse WHERE status = ?', [CLAIM_PENDING])->fetchColumn(); ?>
      <div class="card widget"><div class="wbody"><h2>Menunggu persetujuan</h2>
        <div style="font-size:32px;font-weight:600"><?= count($pending) + $otPend + $rbPend ?></div>
        <div class="kv"><div><i class="dot" style="background:var(--off)"></i><a href="hr.php?m=timeoff">Cuti / izin</a><span class="n"><?= count($pending) ?></span><span class="p"></span></div>
          <div><i class="dot" style="background:var(--warn)"></i><a href="hr.php?m=overtime">Lembur</a><span class="n"><?= $otPend ?></span><span class="p"></span></div>
          <div><i class="dot" style="background:var(--primary)"></i><a href="payroll.php?p=reimburse">Reimbursement</a><span class="n"><?= $rbPend ?></span><span class="p"></span></div></div></div>
        <div class="wfoot"><a href="hr.php?m=timeoff">Tinjau ›</a></div></div>
      <div class="card widget"><div class="wbody"><h2>Ulang tahun bulan ini</h2><div class="list">
        <?php foreach (array_slice($bdays, 0, 4) as $p): ?><div class="li"><span class="avatar"><?= h(initials($p['pegawai_nama'])) ?></span><div class="meta"><b><?= h($p['pegawai_nama']) ?></b><small><?= date('d-m', strtotime($p['tgl_lahir'])) ?></small></div></div><?php endforeach; ?>
        <?php if (!$bdays): ?><div class="muted">Tidak ada (atau tanggal lahir belum diisi).</div><?php endif; ?></div></div></div>
      <div class="card widget"><div class="wbody"><h2>Karyawan baru (90 hari)</h2><div class="list">
        <?php foreach (array_slice($news, 0, 4) as $p): ?><div class="li"><span class="avatar"><?= h(initials($p['pegawai_nama'])) ?></span><div class="meta"><b><?= h($p['pegawai_nama']) ?></b><small>mulai <?= date('d-m-Y', strtotime($p['tgl_mulai_kerja'])) ?></small></div></div><?php endforeach; ?>
        <?php if (!$news): ?><div class="muted">Tidak ada.</div><?php endif; ?></div></div></div>
    </div>
    <?php $anns = current_announcements($today, 5); $taskCount = count($pending) + $otPend + $rbPend + count($profileReqs); ?>
    <div class="card tabcard">
      <div class="tabs" id="hometabs">
        <a href="#" data-tab="ann" class="on">Pengumuman <span class="count"><?= count($anns) ?></span></a>
        <a href="#" data-tab="contract">Kontrak & masa percobaan <span class="count"><?= count($alerts) ?></span></a>
        <a href="#" data-tab="tasks">Tugas <span class="count"><?= $taskCount ?></span></a>
      </div>
      <div class="tabpane" data-pane="ann"><div class="list">
        <?php foreach ($anns as $a): ?><div class="li"><div class="meta"><b><?= h($a['title']) ?></b><small><?= $a['event_date'] ? h(fmt_date($a['event_date'])) . ' · ' : '' ?><?= h(mb_substr(trim(strip_tags($a['body'])), 0, 120)) ?></small></div></div><?php endforeach; ?>
        <?php if (!$anns): ?><div class="muted">Belum ada pengumuman.</div><?php endif; ?></div>
        <div class="side-link"><a href="company.php">Kelola pengumuman ›</a></div></div>
      <div class="tabpane" data-pane="contract" hidden><div class="list">
        <?php foreach ($alerts as $a): ?><div class="li"><span class="avatar"><?= h(initials($a['emp']['pegawai_nama'])) ?></span>
          <div class="meta"><a href="hr.php?m=employee&id=<?= (int)$a['emp']['pegawai_id'] ?>&tab=<?= isset($a['tab']) ? $a['tab'] : 'employment' ?>"><b><?= h($a['emp']['pegawai_nama']) ?></b></a><small><?= h($a['what']) ?> <?= h(fmt_date($a['date'])) ?></small></div>
          <span class="pill <?= $a['date'] < $today ? 'bad' : 'warn' ?>"><?= $a['date'] < $today ? 'lewat ' . (int)((strtotime($today) - strtotime($a['date'])) / 86400) . ' hari' : (int)((strtotime($a['date']) - strtotime($today)) / 86400) . ' hari lagi' ?></span></div><?php endforeach; ?>
        <?php if (!$alerts): ?><div class="muted">Tidak ada kontrak, masa percobaan atau dokumen yang berakhir dalam 30 hari.</div><?php endif; ?></div>
        <div class="side-link"><a href="hr.php?m=employees&ks=kontrak">Karyawan kontrak ›</a></div></div>
      <div class="tabpane" data-pane="tasks" hidden><div class="list">
        <?php foreach ([['Persetujuan cuti / izin', count($pending), 'hr.php?m=timeoff'], ['Persetujuan lembur', $otPend, 'hr.php?m=overtime'], ['Persetujuan reimbursement', $rbPend, 'payroll.php?p=reimburse']] as $t): ?>
          <div class="li"><div class="meta"><a href="<?= $t[2] ?>"><b><?= h($t[0]) ?></b></a></div><span class="pill <?= $t[1] ? 'warn' : 'grey' ?>"><?= (int)$t[1] ?></span></div><?php endforeach; ?>
        <?php foreach ($profileReqs as $r): ?><div class="li"><span class="avatar"><?= h(initials($r['pegawai_nama'])) ?></span><div class="meta"><a href="hr.php?m=employee&id=<?= (int)$r['pegawai_id'] ?>&tab=personal"><b>Perubahan data pribadi: <?= h($r['pegawai_nama']) ?></b></a><small><?= h(date('d-m-Y H:i', strtotime($r['created_at']))) ?></small></div></div><?php endforeach; ?></div></div>
    </div>
    <script>document.querySelectorAll('#hometabs a').forEach(function (a) { a.addEventListener('click', function (e) { e.preventDefault();
      document.querySelectorAll('#hometabs a').forEach(function (x) { x.classList.toggle('on', x === a); });
      document.querySelectorAll('.tabcard .tabpane').forEach(function (p) { p.hidden = p.getAttribute('data-pane') !== a.getAttribute('data-tab'); }); }); });</script>
    <div class="grid3">
      <div class="card"><h2>Cuti / izin
        <span class="side"><?php foreach (['' => 'Hari ini', 'tomorrow' => 'Besok', 'week' => '7 hari'] as $k => $l): ?><a href="hr.php?m=home&dep=<?= $depF ?>&off=<?= $k ?>"<?= get('off') === (string)$k || ($k === '' && !in_array(get('off'), ['tomorrow', 'week'], true)) ? ' style="font-weight:600"' : '' ?>><?= $l ?></a> <?php endforeach; ?></span></h2><div class="list">
        <?php foreach ($off as $r): ?><div class="li"><span class="code"><?= date('d', strtotime($r['izin_tgl'])) ?></span><div class="meta"><b><?= h($r['pegawai_nama']) ?></b><small><?= h($r['izin_jenis_name']) ?> · <?= h(fmt_date($r['izin_tgl'])) ?></small></div></div><?php endforeach; ?>
        <?php if (!$off): ?><div class="muted">Tidak ada karyawan cuti / izin (<?= h(strtolower($offLabel)) ?>).</div><?php endif; ?></div></div>
      <div class="card"><h2>Pengajuan terbaru <span class="side"><a href="hr.php?m=timeoff">Semua ›</a></span></h2><div class="list">
        <?php foreach (array_slice($pending, 0, 6) as $r): ?><div class="li"><span class="avatar"><?= h(initials($r['pegawai_nama'])) ?></span>
          <div class="meta"><b><?= h($r['pegawai_nama']) ?></b><small><?= h($r['izin_jenis_name']) ?> · <?= h(fmt_date($r['izin_tgl'])) ?> · <?= h($r['izin_catatan']) ?></small></div><?= decide_buttons($r, $csrf, $back) ?></div><?php endforeach; ?>
        <?php if (!$pending): ?><div class="muted">Tidak ada pengajuan yang menunggu.</div><?php endif; ?></div></div>
      <div class="card quick"><h2>Absensi</h2>
        <a href="dashboard.php"><?= hris_icon('finger') ?> Absensi (Fingerspot)</a>
        <a href="quick.php"><?= hris_icon('clock') ?> Input Cepat (Tidak scan / Dinas)</a>
        <a href="manual.php"><?= hris_icon('clock') ?> Revisi Scan Manual</a>
        <a href="missing.php"><?= hris_icon('clock') ?> Scan Tidak Lengkap</a>
        <a href="report.php?tab=kartushift"><?= hris_icon('report') ?> Kartu Shift</a></div>
    </div>
  </div>
<?php
// ======================================================================= Karyawan
elseif ($m === 'employees'):
    $search = get('q');
    $status = get('status', '1');
    $where = ['1=1'];
    $params = [];
    if ($status !== '') { $where[] = 'p.pegawai_status = ?'; $params[] = (int)$status; }
    if ($search !== '') { $where[] = '(p.pegawai_nama LIKE ? OR p.pegawai_pin LIKE ? OR p.pegawai_nip LIKE ?)'; array_push($params, "%$search%", "%$search%", "%$search%"); }
    $list = people(implode(' AND ', $where), $params);
    $emps = current_employment();
    $ks = get('ks');
    if ($ks !== '') $list = array_values(array_filter($list, function ($p) use ($emps, $ks) { $r = isset($emps[$p['pegawai_id']]) ? $emps[$p['pegawai_id']] : null; return $ks === 'none' ? !$r : $r && $r['status'] === $ks; }));
    $noStatus = 0; foreach (employees(true) as $e) if (!isset($emps[$e['pegawai_id']])) $noStatus++;
    $counts = q('SELECT pegawai_status, COUNT(*) FROM pegawai GROUP BY pegawai_status')->fetchAll(PDO::FETCH_KEY_PAIR);
    hris_header('Daftar karyawan', 'employee', 'employees', 'Karyawan', $me['role'] === 'admin' ? '<a class="btn" href="startdates.php">Tanggal mulai dari scan</a> <a class="btn primary" href="employee_edit.php">+ Tambah karyawan</a>' : ''); ?>
  <div class="counters" style="grid-template-columns:repeat(4,minmax(0,1fr))">
    <?php foreach (['' => 'Semua'] + $statusNames as $k => $label): ?>
      <a class="counter<?= (string)$status === (string)$k ? ' on' : '' ?>" href="hr.php?m=employees&status=<?= $k ?>&q=<?= urlencode($search) ?>">
        <b><?= $k === '' ? array_sum($counts) : (int)(isset($counts[$k]) ? $counts[$k] : 0) ?></b><span><?= h($label) ?></span></a>
    <?php endforeach; ?>
  </div>
  <form class="toolbar" method="get"><input type="hidden" name="m" value="employees"><input type="hidden" name="status" value="<?= h($status) ?>">
    <span class="search"><?= hris_icon('search') ?><input type="search" name="q" value="<?= h($search) ?>" placeholder="Cari nama, PIN, NIP"></span>
    <select name="ks" onchange="this.form.submit()"><option value="">Semua status kerja</option><?php foreach (employment_statuses() + ['none' => 'Belum diisi'] as $k => $l): ?><option value="<?= $k ?>"<?= $ks === $k ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select>
    <button class="btn">Cari</button></form>
  <?php if ($noStatus && $me['role'] === 'admin'): ?>
    <form method="post" class="notice" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap"><?= $csrf ?><input type="hidden" name="action" value="emp_status_bulk"><input type="hidden" name="back" value="<?= $back ?>">
      <div style="flex:1"><b><?= $noStatus ?> karyawan aktif belum punya status kepegawaian.</b> Isi sekaligus dari tanggal mulai bekerja, lalu ubah yang kontrak di profilnya.</div>
      <select name="status"><option value="tetap">Karyawan tetap (PKWTT)</option><option value="harian">Harian lepas</option></select>
      <button class="btn" onclick="return confirm('Isi status untuk <?= $noStatus ?> karyawan?')">Isi status</button></form>
  <?php endif; ?>
  <div class="tablecard"><div class="tablescroll"><table>
    <thead><tr><th>Nama karyawan</th><th>NIP</th><th>Jabatan</th><th>Departemen</th><th>Kantor</th><th>Mulai bekerja</th><th>Status kerja</th><th>Status</th><th>Portal</th><th class="actions">Aksi</th></tr></thead>
    <tbody>
    <?php foreach ($list as $p): ?>
      <tr><td><?= who($p) ?></td><td><?= h($p['pegawai_nip']) ?></td><td><?= h($p['jab']) ?></td><td><?= h($p['dep']) ?></td><td><?= h($p['kan']) ?></td>
        <td><?= $p['tgl_mulai_kerja'] ? date('d-m-Y', strtotime($p['tgl_mulai_kerja'])) : '' ?></td>
        <td><?= employment_pill(isset($emps[$p['pegawai_id']]) ? $emps[$p['pegawai_id']] : null) ?></td>
        <td><span class="pill <?= ['1' => 'ok', '0' => 'grey', '2' => 'bad'][$p['pegawai_status']] ?? 'grey' ?>"><?= h($statusNames[$p['pegawai_status']] ?? $p['pegawai_status']) ?></span></td>
        <td class="muted"><?= $p['portal'] ? ($p['last_login'] ? 'aktif' : 'belum login') : '—' ?></td>
        <td class="actions"><a class="btn small" href="hr.php?m=employee&id=<?= (int)$p['pegawai_id'] ?>">Lihat</a></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <?php if (!$list): ?><div class="empty"><strong>Tidak ada karyawan</strong>Ubah pencarian atau status.</div><?php endif; ?>
    <div class="pager"><span><?= count($list) ?> karyawan</span></div></div>
<?php
// ======================================================================= Profil karyawan
elseif ($m === 'employee'):
    $id = (int)get('id');
    $found = people('p.pegawai_id = ?', [$id]);
    if (!$found) { flash('Karyawan tidak ditemukan.', 'err'); redirect('hr.php?m=employees'); }
    $p = $found[0];
    $tab = get('tab', 'personal');
    $actions = ($me['role'] === 'admin' ? '<a class="btn" href="employee_edit.php?id=' . $id . '">Ubah data</a> '
             . '<a class="btn" href="talent.php?p=checklist&type=' . ((int)$p['pegawai_status'] === 1 ? 'onboarding' : 'offboarding') . '&id=' . $id . '">' . ((int)$p['pegawai_status'] === 1 ? 'Onboarding' : 'Offboarding') . '</a> '
             . '<form method="post" class="inline" onsubmit="return confirm(\'Buat kode login baru? Kode lama tidak berlaku lagi.\')">' . $csrf
             . '<input type="hidden" name="action" value="code"><input type="hidden" name="pegawai_id" value="' . $id . '"><input type="hidden" name="back" value="' . $back . '">'
             . '<button class="btn outline">' . ($p['portal'] ? 'Kode login baru' : 'Buat kode login portal') . '</button></form>' : '');
    hris_header($p['pegawai_nama'], 'employee', 'employees', '<a href="hr.php?m=employees">Karyawan</a> / Profil', $actions); ?>
  <div class="card" style="display:flex;gap:16px;align-items:center;padding:16px 20px">
    <span class="avatar" style="width:56px;height:56px;font-size:18px"><?= h(initials($p['pegawai_nama'])) ?></span>
    <div style="flex:1"><b style="font-size:16px"><?= h($p['pegawai_nama']) ?></b><div class="muted">PIN <?= h($p['pegawai_pin']) ?> · <?= h(trim($p['jab'] . ' · ' . $p['dep'] . ' · ' . $p['kan'], ' ·')) ?></div></div>
    <span class="pill <?= ['1' => 'ok', '0' => 'grey', '2' => 'bad'][$p['pegawai_status']] ?? 'grey' ?>"><?= h($statusNames[$p['pegawai_status']] ?? '') ?></span>
  </div>
  <nav class="tabs"><?php foreach (['personal' => 'Data pribadi', 'employment' => 'Pekerjaan', 'attendance' => 'Kehadiran', 'timeoff' => 'Cuti & Izin', 'docs' => 'Dokumen', 'warnings' => 'Peringatan'] as $k => $label): ?>
    <a class="<?= $tab === $k ? 'on' : '' ?>" href="hr.php?m=employee&id=<?= $id ?>&tab=<?= $k ?>"><?= h($label) ?></a><?php endforeach; ?></nav>
  <?php if ($tab === 'personal'):
      $pr = get_profile($id);
      $reqs = q("SELECT * FROM web_profile_request WHERE pegawai_id = ? AND status = 'pending' ORDER BY created_at", [$id])->fetchAll();
      $pay = q('SELECT ptkp FROM web_pay_emp WHERE pegawai_id = ?', [$id])->fetchColumn();
      $ptkpHint = $pr['marital'] !== '' ? ptkp_from_profile($pr['marital'], $pr['dependants']) : null;
      $editing = get('edit') === '1' && $me['role'] === 'admin'; ?>
    <?php foreach ($reqs as $rq): $chg = json_decode($rq['data'], true); ?>
      <div class="notice" style="display:block"><b>Pengajuan perubahan data dari karyawan (<?= h(date('d-m-Y H:i', strtotime($rq['created_at']))) ?>)</b>
        <table style="margin:6px 0"><?php foreach ($chg as $k => $v): if (!isset(profile_fields()[$k])) continue; ?><tr><td><?= h(profile_fields()[$k][0]) ?></td><td class="muted"><?= h(profile_value_label($k, $pr[$k])) ?></td><td>→ <b><?= h(profile_value_label($k, $v)) ?></b></td></tr><?php endforeach; ?></table>
        <?php if ($rq['note']): ?><div class="muted">Catatan: <?= h($rq['note']) ?></div><?php endif; ?>
        <?php if ($me['role'] === 'admin'): ?><form method="post" class="inline"><?= $csrf ?><input type="hidden" name="back" value="<?= $back ?>"><input type="hidden" name="id" value="<?= (int)$rq['id'] ?>">
          <button class="btn small primary" name="action" value="profile_req_approve">Setujui & simpan</button> <button class="btn small outline" name="action" value="profile_req_reject">Tolak</button></form><?php endif; ?></div>
    <?php endforeach; ?>
    <?php if ($ptkpHint && $pay && $pay !== $ptkpHint): ?><div class="notice"><div><b>PTKP di data gaji <?= h($pay) ?>, data pribadi menunjukkan <?= h($ptkpHint) ?></b>Periksa status perkawinan dan tanggungan, lalu sesuaikan PTKP di <a href="payroll.php?p=setup">Data gaji karyawan</a> bila perlu.</div></div><?php endif; ?>
    <?php if ($editing): ?>
      <form method="post" class="section" style="max-width:none"><?= $csrf ?><input type="hidden" name="action" value="profile_save"><input type="hidden" name="pegawai_id" value="<?= $id ?>"><input type="hidden" name="back" value="hr.php?m=employee&id=<?= $id ?>&tab=personal">
        <div class="row2"><?php foreach (profile_fields() as $k => $f): $n = str_replace(':', '_', $k); ?>
          <label class="field"><span><?= h($f[0]) ?></span><?php if ($f[1] === 'select'): ?><select name="<?= $n ?>"><option value="">—</option><?php foreach ($f[2] as $ok => $ol): ?><option value="<?= h($ok) ?>"<?= $pr[$k] === (string)$ok ? ' selected' : '' ?>><?= h($ol) ?></option><?php endforeach; ?></select>
          <?php else: ?><input type="<?= $f[1] ?>" name="<?= $n ?>" value="<?= h($pr[$k]) ?>"<?= $f[1] === 'number' ? ' min="0" max="9"' : '' ?>><?php endif; ?></label>
        <?php endforeach; ?></div>
        <div class="formfoot"><a class="btn" href="hr.php?m=employee&id=<?= $id ?>&tab=personal">Batal</a><button class="btn primary">Simpan</button></div></form>
    <?php else: ?>
      <div class="section" style="max-width:none"><dl class="dl">
        <?php foreach (['Nama lengkap' => $p['pegawai_nama'], 'Nama panggilan' => $p['pegawai_alias'], 'Jenis kelamin' => (int)$p['gender'] === 2 ? 'Perempuan' : 'Laki-laki'] as $k => $v): ?><dt><?= h($k) ?></dt><dd><?= $v ? h($v) : '—' ?></dd><?php endforeach; ?>
        <?php foreach (profile_fields() as $k => $f): ?><dt><?= h($f[0]) ?></dt><dd><?= h(profile_value_label($k, $pr[$k])) ?></dd><?php endforeach; ?></dl>
        <?php if ($me['role'] === 'admin'): ?><div class="formfoot"><a class="btn" href="letters.php?p=make&pegawai_id=<?= $id ?>">Buat surat</a><a class="btn primary" href="hr.php?m=employee&id=<?= $id ?>&tab=personal&edit=1">Ubah data pribadi</a></div><?php endif; ?></div>
    <?php endif; ?>
  <?php elseif ($tab === 'docs'):
      $docs = q('SELECT * FROM web_emp_doc WHERE pegawai_id = ? ORDER BY kind, created_at DESC', [$id])->fetchAll(); ?>
    <div class="tablecard"><div class="tablescroll"><table>
      <thead><tr><th>Jenis</th><th>Keterangan</th><th>Berlaku sampai</th><th>Diunggah</th><th class="actions">Aksi</th></tr></thead><tbody>
      <?php foreach ($docs as $d): ?><tr><td><b><?= h(doc_kinds()[$d['kind']] ?? $d['kind']) ?></b></td><td><?= h($d['title']) ?></td>
        <td><?= $d['expires'] ? '<span class="pill ' . ($d['expires'] < $today ? 'bad' : ($d['expires'] <= date('Y-m-d', strtotime('+30 days')) ? 'warn' : 'grey')) . '">' . h(date('d-m-Y', strtotime($d['expires']))) . '</span>' : '' ?></td>
        <td class="muted"><?= h($d['uploaded_by']) ?> · <?= h(date('d-m-Y', strtotime($d['created_at']))) ?></td>
        <td class="actions"><a class="btn small" target="_blank" href="doc.php?id=<?= (int)$d['id'] ?>">Lihat</a>
          <?php if ($me['role'] === 'admin'): ?><form method="post" class="inline" onsubmit="return confirm('Hapus dokumen ini?')"><?= $csrf ?><input type="hidden" name="action" value="doc_delete"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>"><input type="hidden" name="back" value="<?= $back ?>"><button class="btn small ghost">Hapus</button></form><?php endif; ?></td></tr><?php endforeach; ?>
      </tbody></table></div><?php if (!$docs): ?><div class="empty"><strong>Belum ada dokumen</strong></div><?php endif; ?></div>
    <?php if ($me['role'] === 'admin'): ?>
    <form method="post" enctype="multipart/form-data" class="section" style="margin-top:12px"><?= $csrf ?><input type="hidden" name="action" value="doc_upload"><input type="hidden" name="pegawai_id" value="<?= $id ?>"><input type="hidden" name="back" value="<?= $back ?>">
      <div class="row2"><label class="field"><span>Jenis</span><select name="kind"><?php foreach (doc_kinds() as $k => $l): ?><option value="<?= $k ?>"><?= h($l) ?></option><?php endforeach; ?></select></label>
        <label class="field"><span>Keterangan</span><input type="text" name="title" maxlength="120"></label>
        <label class="field"><span>Berlaku sampai (opsional)</span><input type="date" name="expires"></label>
        <label class="field"><span>File (PDF / JPG / PNG, maks 5 MB)</span><input type="file" name="file" required accept=".pdf,.jpg,.jpeg,.png"></label></div>
      <div class="formfoot"><button class="btn primary">Unggah</button></div></form>
    <?php endif; ?>
  <?php elseif ($tab === 'employment'):
      $sched = q('SELECT m.jdw_kerja_m_name, j.jdw_kerja_m_mulai FROM jdw_kerja_pegawai j JOIN jdw_kerja_m m ON m.jdw_kerja_m_id = j.jdw_kerja_m_id WHERE j.pegawai_id = ? ORDER BY j.jdw_kerja_m_mulai DESC LIMIT 1', [$id])->fetch(); ?>
    <div class="section" style="max-width:none"><dl class="dl">
      <?php foreach (['PIN (mesin absensi)' => $p['pegawai_pin'], 'NIP' => $p['pegawai_nip'], 'Jabatan' => $p['jab'], 'Departemen' => $p['dep'], 'Kantor' => $p['kan'],
                      'Jadwal kerja' => $sched ? $sched['jdw_kerja_m_name'] . ' (sejak ' . date('d-m-Y', strtotime($sched['jdw_kerja_m_mulai'])) . ')' : '',
                      'Mulai bekerja' => $p['tgl_mulai_kerja'] ? date('d-m-Y', strtotime($p['tgl_mulai_kerja'])) : '', 'Tanggal berhenti' => $p['tgl_resign'] ? date('d-m-Y', strtotime($p['tgl_resign'])) : '',
                      'Portal Pegawai' => $p['portal'] ? ($p['last_login'] ? 'login terakhir ' . date('d-m-Y H:i', strtotime($p['last_login'])) : 'kode dibuat, belum login') : 'belum punya kode login'] as $k => $v): ?>
        <dt><?= h($k) ?></dt><dd><?= $v ? h($v) : '<span class="muted">—</span>' ?></dd><?php endforeach; ?></dl></div>
    <?php $held = q('SELECT * FROM web_asset WHERE pegawai_id = ? AND active = 1', [$id])->fetchAll(); $boss = q('SELECT p.pegawai_id, p.pegawai_nama FROM web_emp_profile x JOIN pegawai p ON p.pegawai_id = x.atasan_id WHERE x.pegawai_id = ?', [$id])->fetch(); ?>
    <div class="section" style="max-width:none;margin-top:16px"><h2>Atasan & aset</h2>
      <p style="margin:0">Atasan langsung: <?= $boss ? '<a href="hr.php?m=employee&id=' . (int)$boss['pegawai_id'] . '">' . h($boss['pegawai_nama']) . '</a>' : '<span class="muted">belum diatur</span>' ?> · <a href="company.php?p=org">atur</a></p>
      <?php if ($held): ?><p style="margin:6px 0 0">Aset dipakai: <?= h(implode(', ', array_map(function ($a) { return $a['name'] . ($a['serial'] ? ' (' . $a['serial'] . ')' : ''); }, $held))) ?> · <a href="company.php?p=assets&f=out">kelola</a></p><?php else: ?><p class="muted" style="margin:6px 0 0">Tidak memegang aset.</p><?php endif; ?></div>
    <?php $hist = q('SELECT * FROM web_employment WHERE pegawai_id = ? ORDER BY start_date DESC, id DESC', [$id])->fetchAll(); $cur = $hist ? $hist[0] : null; ?>
    <?php $moves = q('SELECT * FROM web_transfer WHERE pegawai_id = ? ORDER BY effective_date DESC, id DESC LIMIT 10', [$id])->fetchAll(); $tnames = transfer_names(); ?>
    <div class="section" style="max-width:none;margin-top:16px"><h2>Riwayat mutasi <?php if ($me['role'] === 'admin'): ?><span class="side"><a href="transfer.php?p=new&pegawai_id=<?= $id ?>">+ Buat mutasi</a> · <a href="transfer.php?pegawai_id=<?= $id ?>">Semua</a></span><?php endif; ?></h2>
      <?php if ($moves): ?><div class="tablescroll"><table>
        <thead><tr><th>ID</th><th>Jenis</th><th>Perubahan</th><th>Berlaku</th><th>Status</th></tr></thead><tbody>
        <?php foreach ($moves as $mv): $mst = transfer_statuses()[$mv['status']] ?? [$mv['status'], 'grey']; ?>
          <tr><td style="white-space:nowrap"><b><?= h(transfer_code($mv['id'], $mv['created_at'])) ?></b></td><td><?= h(transfer_types()[$mv['type']] ?? $mv['type']) ?></td>
            <td><?= h(transfer_summary($mv, $tnames)) ?><?= $mv['note'] !== '' ? '<br><small class="muted">' . h($mv['note']) . '</small>' : '' ?></td>
            <td style="white-space:nowrap"><?= h(date('d-m-Y', strtotime($mv['effective_date']))) ?></td><td><span class="pill <?= $mst[1] ?>"><?= h($mst[0]) ?></span></td></tr>
        <?php endforeach; ?></tbody></table></div>
      <?php else: ?><p class="muted">Belum ada mutasi.</p><?php endif; ?></div>
    <div class="section" style="max-width:none;margin-top:16px"><h2>Status kepegawaian <?= employment_pill($cur) ?></h2>
      <?php if ($hist): ?><div class="tablescroll"><table>
        <thead><tr><th>Status</th><th>No. kontrak / SK</th><th>Mulai</th><th>Berakhir</th><th>Masa percobaan s/d</th><th>Catatan</th><th>Dicatat</th><?php if ($me['role'] === 'admin'): ?><th class="actions"></th><?php endif; ?></tr></thead><tbody>
        <?php foreach ($hist as $r): ?><tr><td><b><?= h(employment_statuses()[$r['status']]) ?></b></td><td><?= h($r['contract_no']) ?></td><td><?= h(date('d-m-Y', strtotime($r['start_date']))) ?></td>
          <td><?= $r['end_date'] ? h(date('d-m-Y', strtotime($r['end_date']))) : '' ?></td><td><?= $r['probation_end'] ? h(date('d-m-Y', strtotime($r['probation_end']))) : '' ?></td>
          <td><?= h($r['note']) ?></td><td class="muted"><?= h($r['created_by']) ?> · <?= h(date('d-m-Y', strtotime($r['created_at']))) ?></td>
          <?php if ($me['role'] === 'admin'): ?><td class="actions"><form method="post" class="inline" onsubmit="return confirm('Hapus baris riwayat ini?')"><?= $csrf ?><input type="hidden" name="action" value="emp_status_delete">
            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="back" value="<?= $back ?>"><button class="btn small ghost">Hapus</button></form></td><?php endif; ?></tr><?php endforeach; ?>
      </tbody></table></div><?php else: ?><p class="muted">Belum diisi.</p><?php endif; ?>
      <?php if ($me['role'] === 'admin'):
          $nextStart = $cur && $cur['end_date'] ? date('Y-m-d', strtotime($cur['end_date'] . ' +1 day')) : ($cur ? date('Y-m-d') : ($p['tgl_mulai_kerja'] ?: date('Y-m-d'))); ?>
      <form method="post" style="display:grid;gap:12px;margin-top:8px"><?= $csrf ?><input type="hidden" name="action" value="emp_status_save"><input type="hidden" name="pegawai_id" value="<?= $id ?>"><input type="hidden" name="back" value="<?= $back ?>">
        <b><?= $cur ? 'Perpanjang kontrak / ubah status' : 'Isi status' ?></b>
        <div class="row2"><label class="field"><span>Status</span><select name="status"><?php foreach (employment_statuses() as $k => $l): ?><option value="<?= $k ?>"<?= ($cur ? $cur['status'] : 'tetap') === $k ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></label>
          <label class="field"><span>No. kontrak / SK</span><input type="text" name="contract_no" maxlength="60"></label>
          <label class="field"><span>Mulai</span><input type="date" name="start" required value="<?= h($nextStart) ?>"></label>
          <label class="field"><span>Berakhir</span><input type="date" name="end"><small>Wajib untuk kontrak dan magang. PKWT paling lama 5 tahun termasuk perpanjangan.</small></label>
          <label class="field"><span>Masa percobaan s/d</span><input type="date" name="probation"><small>Hanya karyawan tetap, paling lama 3 bulan.</small></label>
          <label class="field"><span>Catatan</span><input type="text" name="note" maxlength="255"></label></div>
        <div class="formfoot"><button class="btn primary">Simpan status</button></div></form>
      <?php endif; ?></div>
  <?php elseif ($tab === 'warnings'):
      $warns = q('SELECT * FROM web_warning WHERE pegawai_id = ? ORDER BY issued DESC, id DESC', [$id])->fetchAll(); ?>
    <?php if ($me['role'] === 'admin'): ?><div class="toolbar" style="margin-bottom:10px"><span class="grow"></span><a class="btn primary" href="discipline.php?p=new&pegawai_id=<?= $id ?>">+ Peringatan baru</a></div><?php endif; ?>
    <div class="tablecard"><div class="tablescroll"><table>
      <thead><tr><th>Jenis</th><th>Nomor</th><th>Tanggal</th><th>Berlaku sampai</th><th>Pelanggaran</th><th>Diterima</th><th class="actions">Surat</th></tr></thead><tbody>
      <?php foreach ($warns as $w): ?><tr><td><?= warning_pill($w, $today) ?></td><td class="muted"><?= h($w['number']) ?></td><td><?= h(date('d-m-Y', strtotime($w['issued']))) ?></td>
        <td><?= h(date('d-m-Y', strtotime($w['valid_until']))) ?></td><td><?= h($w['violation']) ?></td><td class="muted"><?= $w['acknowledged_at'] ? date('d-m-Y', strtotime($w['acknowledged_at'])) : 'belum' ?></td>
        <td class="actions"><?php if ($me['role'] === 'admin'): ?><a class="btn small" target="_blank" href="discipline.php?print=<?= (int)$w['id'] ?>">Cetak</a><?php endif; ?></td></tr><?php endforeach; ?>
      </tbody></table></div><?php if (!$warns): ?><div class="empty"><strong>Tidak ada peringatan</strong></div><?php endif; ?></div>
  <?php elseif ($tab === 'attendance'):
      list($pf, $pt) = current_period();
      $from = get('from', $pf); $to = get('to', min($pt, $today));
      if (!valid_date($from)) $from = $pf;
      if (!valid_date($to) || $to < $from) $to = min($pt, $today);
      if ((strtotime($to) - strtotime($from)) / 86400 > 92) $from = date('Y-m-d', strtotime($to . ' -92 days'));
      $days = array_reverse(daily_attendance($from, $to, $id)); ?>
    <form class="toolbar" method="get"><input type="hidden" name="m" value="employee"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="tab" value="attendance">
      <input type="date" name="from" value="<?= h($from) ?>"> s/d <input type="date" name="to" value="<?= h($to) ?>"><button class="btn">Tampilkan</button>
      <span class="grow"></span><a class="btn" href="quick.php?<?= h(http_build_query(['emp' => [$id]])) ?>">+ Input Cepat</a></form>
    <div class="tablecard"><div class="tablescroll"><table>
      <thead><tr><th>Tanggal</th><th>Jadwal</th><th>Masuk</th><th>Pulang</th><th>Terlambat</th><th>Pulang awal</th><th>Lembur</th><th>Status</th><th>Keterangan</th></tr></thead><tbody>
      <?php foreach ($days as $r): ?><tr><td><?= h(fmt_date($r['date'])) ?></td><td><?= h($r['jk'] ?: ($r['off'] ?: '')) ?></td><td><?= h($r['first']) ?></td><td><?= h($r['last']) ?></td>
        <td class="<?= $r['late'] ? 'red' : '' ?>"><?= $r['late'] ? fmt_minutes($r['late']) : '' ?></td><td class="<?= $r['early'] ? 'red' : '' ?>"><?= $r['early'] ? fmt_minutes($r['early']) : '' ?></td>
        <td><?= $r['ot'] ? fmt_minutes($r['ot']) : '' ?></td><td><?= status_badge($r['status']) ?></td><td class="muted"><?= h(implode(', ', $r['leave'])) ?></td></tr><?php endforeach; ?>
      </tbody></table></div><?php if (!$days): ?><div class="empty"><strong>Tidak ada data</strong></div><?php endif; ?></div>
  <?php else:
      list($quota, $taken, $pend) = leave_balance($id);
      $reqs = q("SELECT i.*, j.izin_jenis_name FROM izin i LEFT JOIN jns_izin j ON j.izin_jenis_id = i.izin_jenis_id
                 WHERE i.pegawai_id = ? AND i.izin_jenis_id NOT BETWEEN 100 AND 105 ORDER BY i.izin_tgl DESC LIMIT 200", [$id])->fetchAll(); ?>
    <div class="summary"><div class="head">Saldo cuti <?= date('Y') ?></div><div class="cells">
      <div><span>Jatah</span><b><?= $quota === null ? '—' : $quota ?></b></div><div><span>Diambil</span><b><?= $taken ?></b></div>
      <div><span>Menunggu</span><b><?= $pend ?></b></div><div><span>Sisa</span><b><?= $quota === null ? '—' : max(0, $quota - $taken) ?></b></div></div></div>
    <div class="tablecard"><div class="tablescroll"><table>
      <thead><tr><th>Tanggal</th><th>Jenis</th><th>Catatan</th><th>Diajukan</th><th>Status</th><th class="actions">Aksi</th></tr></thead><tbody>
      <?php foreach ($reqs as $r): ?><tr><td><?= h(fmt_date($r['izin_tgl'])) ?></td><td><?= h($r['izin_jenis_name']) ?></td><td><?= h($r['izin_catatan']) ?></td>
        <td><?= $r['izin_tgl_pengajuan'] ? date('d-m-Y', strtotime($r['izin_tgl_pengajuan'])) : '' ?></td><td><?= request_badge($r) ?></td>
        <td class="actions"><?= request_state($r) === 'pending' ? decide_buttons($r, $csrf, $back) : '' ?></td></tr><?php endforeach; ?>
      </tbody></table></div><?php if (!$reqs): ?><div class="empty"><strong>Belum ada cuti / izin</strong></div><?php endif; ?></div>
  <?php endif; ?>
<?php
// ======================================================================= Kehadiran
elseif ($m === 'attendance'):
    $date = get('date', $today);
    if (!valid_date($date)) $date = $today;
    $filter = get('f');
    $rows = daily_attendance($date, $date);
    $c = ['all' => count($rows), 'ok' => 0, 'late' => 0, 'incomplete' => 0, 'absent' => 0, 'leave' => 0, 'off' => 0];
    foreach ($rows as $r) { $c[$r['status']]++; if ($r['late']) $c['late']++; }
    $shown = array_filter($rows, function ($r) use ($filter) { return $filter === '' || ($filter === 'late' ? $r['late'] > 0 : $r['status'] === $filter); });
    $byPin = [];
    foreach (people() as $p) $byPin[$p['pegawai_pin']] = $p;
    hris_header('Kehadiran', 'time', 'attendance', 'Manajemen Waktu', '<a class="btn" href="quick.php">Input Cepat</a> <a class="btn" href="dashboard.php?date=' . h($date) . '">Absensi (Fingerspot)</a>'); ?>
  <form class="toolbar" method="get"><input type="hidden" name="m" value="attendance">
    <a class="btn small" href="hr.php?m=attendance&date=<?= date('Y-m-d', strtotime($date . ' -1 day')) ?>">‹</a>
    <input type="date" name="date" value="<?= h($date) ?>" max="<?= $today ?>" onchange="this.form.submit()">
    <a class="btn small" href="hr.php?m=attendance&date=<?= date('Y-m-d', strtotime($date . ' +1 day')) ?>">›</a>
    <span class="muted"><?= h(fmt_date($date)) ?></span></form>
  <div class="counters" style="grid-template-columns:repeat(7,minmax(0,1fr))">
    <?php foreach (['' => ['all', 'Semua'], 'ok' => ['ok', 'Hadir'], 'late' => ['late', 'Terlambat'], 'incomplete' => ['incomplete', 'Scan 1x'], 'absent' => ['absent', 'Tidak hadir'], 'leave' => ['leave', 'Cuti / izin'], 'off' => ['off', 'Libur']] as $k => $v): ?>
      <a class="counter<?= $filter === $k ? ' on' : '' ?>" href="hr.php?m=attendance&date=<?= h($date) ?>&f=<?= $k ?>"><b><?= $c[$v[0]] ?></b><span><?= $v[1] ?></span></a>
    <?php endforeach; ?>
  </div>
  <div class="tablecard"><div class="tablescroll"><table>
    <thead><tr><th>Nama karyawan</th><th>Jadwal</th><th>Masuk</th><th>Pulang</th><th>Terlambat</th><th>Pulang awal</th><th>Lembur</th><th>Status</th><th class="actions">Aksi</th></tr></thead><tbody>
    <?php foreach ($shown as $r): $p = isset($byPin[$r['pin']]) ? $byPin[$r['pin']] : ['pegawai_id' => $r['pegawai_id'], 'pegawai_nama' => $r['name'], 'pegawai_pin' => $r['pin']]; ?>
      <tr><td><?= who($p) ?></td><td><?= h($r['jk'] ?: ($r['off'] ?: '')) ?></td><td><?= h($r['first']) ?></td><td><?= h($r['last']) ?></td>
        <td class="<?= $r['late'] ? 'red' : '' ?>"><?= $r['late'] ? fmt_minutes($r['late']) : '' ?></td><td class="<?= $r['early'] ? 'red' : '' ?>"><?= $r['early'] ? fmt_minutes($r['early']) : '' ?></td>
        <td><?= $r['ot'] ? fmt_minutes($r['ot']) : '' ?></td><td><?= status_badge($r['status']) ?> <span class="muted"><?= h(implode(', ', $r['leave'])) ?></span></td>
        <td class="actions"><?php if ($r['status'] === 'absent' || $r['status'] === 'incomplete'): ?><a class="btn small" href="quick.php?<?= h(http_build_query(['emp' => [$r['pegawai_id']], 'from' => $date, 'to' => $date, 'mode' => $r['status'] === 'absent' ? 'scan' : 'noscan', 'noscan' => $r['missing'] ?: 100])) ?>">Input</a><?php endif; ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div><?php if (!$shown): ?><div class="empty"><strong>Tidak ada data</strong></div><?php endif; ?></div>
<?php
// ======================================================================= Cuti & Izin
elseif ($m === 'timeoff'):
    $state = get('s', 'pending');
    list($pf, $pt) = current_period();
    $from = get('from', date('Y-m-d', strtotime('-60 days')));
    $to = get('to', date('Y-m-d', strtotime('+60 days')));
    if (!valid_date($from)) $from = date('Y-m-d', strtotime('-60 days'));
    if (!valid_date($to)) $to = date('Y-m-d', strtotime('+60 days'));
    $all = q("SELECT i.*, p.pegawai_nama, p.pegawai_pin, j.izin_jenis_name FROM izin i JOIN pegawai p ON p.pegawai_id = i.pegawai_id
              LEFT JOIN jns_izin j ON j.izin_jenis_id = i.izin_jenis_id
              WHERE i.izin_jenis_id NOT BETWEEN 100 AND 105 AND (i.izin_tgl BETWEEN ? AND ? OR (i.izin_status = 0 AND i.ket_status = ?))
              ORDER BY i.izin_tgl DESC, p.pegawai_nama LIMIT 1000", [$from, $to, REQ_PENDING])->fetchAll();
    $cnt = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
    foreach ($all as $r) $cnt[request_state($r)]++;
    $shown = array_filter($all, function ($r) use ($state) { return request_state($r) === $state; });
    hris_header('Cuti & Izin', 'time', 'timeoff', 'Manajemen Waktu', '<a class="btn primary" href="leave.php?add=1#add">+ Tambah cuti / izin</a>'); ?>
  <div class="counters" style="grid-template-columns:repeat(3,minmax(0,1fr))">
    <?php foreach (['pending' => 'Menunggu persetujuan', 'approved' => 'Disetujui', 'rejected' => 'Ditolak'] as $k => $label): ?>
      <a class="counter<?= $state === $k ? ' on' : '' ?>" href="hr.php?m=timeoff&s=<?= $k ?>&from=<?= h($from) ?>&to=<?= h($to) ?>"><b><?= $cnt[$k] ?></b><span><?= $label ?></span></a>
    <?php endforeach; ?>
  </div>
  <form class="toolbar" method="get"><input type="hidden" name="m" value="timeoff"><input type="hidden" name="s" value="<?= h($state) ?>">
    <input type="date" name="from" value="<?= h($from) ?>"> s/d <input type="date" name="to" value="<?= h($to) ?>"><button class="btn">Tampilkan</button></form>
  <div class="tablecard"><div class="tablescroll"><table>
    <thead><tr><th>Nama karyawan</th><th>Tanggal</th><th>Jenis</th><th>Catatan</th><th>Diajukan</th><th>Status</th><th class="actions">Aksi</th></tr></thead><tbody>
    <?php foreach ($shown as $r): ?><tr><td><?= who($r) ?></td><td><?= h(fmt_date($r['izin_tgl'])) ?></td><td><?= h($r['izin_jenis_name']) ?></td><td><?= h($r['izin_catatan']) ?></td>
      <td><?= $r['izin_tgl_pengajuan'] ? date('d-m-Y', strtotime($r['izin_tgl_pengajuan'])) : '' ?></td><td><?= request_badge($r) ?></td>
      <td class="actions"><?= request_state($r) === 'pending' ? decide_buttons($r, $csrf, $back) : '' ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php if (!$shown): ?><div class="empty"><strong>Tidak ada pengajuan</strong>Pengajuan dari Portal Pegawai muncul di sini.</div><?php endif; ?></div>
<?php
// ======================================================================= Jadwal shift (calendar)
elseif ($m === 'shifts'):
    $start = get('start', date('Y-m-d', strtotime('monday this week')));
    if (!valid_date($start)) $start = date('Y-m-d', strtotime('monday this week'));
    $days = (int)get('days', 14) === 31 ? 31 : 14;
    $dates = [];
    for ($i = 0; $i < $days; $i++) $dates[] = date('Y-m-d', strtotime($start . " +$i days"));
    $holidays = q('SELECT libur_tgl, libur_keterangan FROM libur WHERE libur_tgl BETWEEN ? AND ?', [$dates[0], end($dates)])->fetchAll(PDO::FETCH_KEY_PAIR);
    $people = people();
    $dept = get('dept');
    if ($dept !== '') $people = array_filter($people, function ($p) use ($dept) { return (string)$p['pembagian2_id'] === $dept; });
    $hoursOpts = [];
    foreach (work_hours() as $w) $hoursOpts[$w['jk_id']] = ($w['jk_kode'] ?: $w['jk_name']) . ' · ' . $w['jk_name'] . ' ' . min_to_hm($w['start']) . '–' . min_to_hm($w['end']);
    $nav = function ($shift) use ($start, $days, $dept) { return 'hr.php?' . http_build_query(['m' => 'shifts', 'start' => date('Y-m-d', strtotime($start . " $shift days")), 'days' => $days, 'dept' => $dept]); };
    hris_header('Jadwal shift', 'time', 'shifts', 'Manajemen Waktu', '<a class="btn" href="schedules.php">Atur jadwal kerja</a> <a class="btn" href="workhours.php">Atur jam kerja</a>'); ?>
  <form method="post" class="section" style="max-width:none" id="shiftform"><?= $csrf ?><input type="hidden" name="action" value="set_shift"><input type="hidden" name="back" value="<?= $back ?>">
    <h2>Ubah shift</h2>
    <div class="toolbar">
      <span class="muted">Tanggal</span><input type="date" name="from" required value="<?= h($dates[0]) ?>"><span class="muted">s/d</span><input type="date" name="to" required value="<?= h($dates[0]) ?>">
      <select name="shift" required><?php foreach ($hoursOpts as $k => $label): ?><option value="<?= (int)$k ?>"><?= h($label) ?></option><?php endforeach; ?>
        <option value="off">Libur</option><option value="schedule">Ikuti jadwal kerja (hapus perubahan)</option></select>
      <input type="text" name="note" placeholder="Keterangan" style="width:200px">
      <button class="btn primary">Simpan</button>
    </div>
    <div class="muted" style="font-size:12px">Centang karyawan di tabel di bawah, atau klik sel untuk memilih karyawan dan tanggal itu.</div>
  </form>
  <form class="toolbar" method="get"><input type="hidden" name="m" value="shifts">
    <a class="btn small" href="<?= h($nav(-$days)) ?>">‹</a><input type="date" name="start" value="<?= h($start) ?>"><a class="btn small" href="<?= h($nav($days)) ?>">›</a>
    <select name="days"><option value="14" <?= $days === 14 ? 'selected' : '' ?>>2 minggu</option><option value="31" <?= $days === 31 ? 'selected' : '' ?>>31 hari</option></select>
    <select name="dept"><option value="">Semua departemen</option><?php foreach (lookup('pembagian2') as $k => $v): ?><option value="<?= h($k) ?>" <?= (string)$dept === (string)$k ? 'selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?></select>
    <button class="btn">Tampilkan</button>
    <span class="grow"></span><span class="muted">Garis bawah = diubah dari jadwal · ⇄ = tukar shift</span></form>
  <div class="tablecard"><div class="tablescroll"><table style="font-size:12px">
    <thead><tr><th><input type="checkbox" onclick="document.querySelectorAll('.empcb').forEach(function(c){c.checked=this.checked}.bind(this))" aria-label="Semua"></th><th>Nama karyawan</th>
      <?php foreach ($dates as $d): $hol = isset($holidays[$d]); ?><th style="text-align:center;<?= date('N', strtotime($d)) == 7 || $hol ? 'color:var(--bad)' : '' ?>" title="<?= $hol ? h($holidays[$d]) : '' ?>"><?= substr(fmt_date($d), 0, 3) ?><br><?= date('d', strtotime($d)) ?></th><?php endforeach; ?></tr></thead><tbody>
    <?php foreach ($people as $p): ?>
      <tr><td><input type="checkbox" class="empcb" form="shiftform" name="emp[]" value="<?= (int)$p['pegawai_id'] ?>"></td><td style="white-space:nowrap"><b><?= h($p['pegawai_nama']) ?></b></td>
        <?php foreach ($dates as $d): $c = shift_cell($p['pegawai_id'], $d); if (isset($holidays[$d])) { $c['label'] = 'Libur'; $c['bg'] = '#fde8e6'; $c['title'] = $holidays[$d]; } ?>
          <td style="text-align:center;padding:4px;background:<?= $c['bg'] ?>;cursor:pointer;<?= $c['changed'] ? 'text-decoration:underline;font-weight:600' : '' ?>" title="<?= h($c['title']) ?>"
              onclick="pickShift(<?= (int)$p['pegawai_id'] ?>,'<?= $d ?>')"><?= h($c['label']) ?><?= $c['swap'] ? ' ⇄' : '' ?></td>
        <?php endforeach; ?></tr>
    <?php endforeach; ?>
    </tbody></table></div></div>
  <script>
    function pickShift(id, d) {
      var f = document.getElementById('shiftform');
      document.querySelectorAll('.empcb').forEach(function (c) { c.checked = +c.value === id; });
      f.from.value = d; f.to.value = d; f.scrollIntoView({behavior: 'smooth'}); f.shift.focus();
    }
  </script>
<?php
// ======================================================================= Tukar shift
elseif ($m === 'swaps'):
    $state = get('s', CLAIM_PENDING);
    $cnt = q('SELECT status, COUNT(*) FROM web_shift_swap GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
    $reqs = q('SELECT s.*, a.pegawai_nama na, a.pegawai_pin pa, b.pegawai_nama nb FROM web_shift_swap s JOIN pegawai a ON a.pegawai_id = s.pegawai_id JOIN pegawai b ON b.pegawai_id = s.with_id
               WHERE s.status = ? ORDER BY s.tgl DESC LIMIT 300', [$state])->fetchAll();
    $done = q('SELECT t.*, a.pegawai_nama na, b.pegawai_nama nb FROM tukar_jam t JOIN pegawai a ON a.pegawai_id = t.pegawai_id1 JOIN pegawai b ON b.pegawai_id = t.pegawai_id2
               WHERE t.tukar_tgl >= ? ORDER BY t.tukar_tgl', [date('Y-m-d', strtotime('-30 days'))])->fetchAll();
    $label = function ($pid, $d) { $c = shift_cell($pid, $d); return $c['label']; };
    hris_header('Tukar shift', 'time', 'swaps', 'Manajemen Waktu'); ?>
  <div class="counters" style="grid-template-columns:repeat(3,minmax(0,1fr))">
    <?php foreach ([CLAIM_PENDING => 'Menunggu persetujuan', CLAIM_APPROVED => 'Disetujui', CLAIM_REJECTED => 'Ditolak'] as $k => $lb): ?>
      <a class="counter<?= $state === $k ? ' on' : '' ?>" href="hr.php?m=swaps&s=<?= $k ?>"><b><?= (int)(isset($cnt[$k]) ? $cnt[$k] : 0) ?></b><span><?= $lb ?></span></a>
    <?php endforeach; ?>
  </div>
  <div class="tablecard"><div class="tablescroll"><table>
    <thead><tr><th>Tanggal</th><th>Pengaju</th><th>Tukar dengan</th><th>Jadwal saat ini</th><th>Alasan</th><th>Status</th><th class="actions">Aksi</th></tr></thead><tbody>
    <?php foreach ($reqs as $r): ?>
      <tr><td><?= h(fmt_date($r['tgl'])) ?></td><td><b><?= h($r['na']) ?></b></td><td><?= h($r['nb']) ?></td>
        <td><?= h(scheduled_hours_for($r['pegawai_id'], $r['tgl'])[0]['jk_name'] ?? 'Libur') ?> ⇄ <?= h(scheduled_hours_for($r['with_id'], $r['tgl'])[0]['jk_name'] ?? 'Libur') ?></td>
        <td><?= h($r['alasan']) ?></td><td><?= claim_badge($r['status']) ?></td>
        <td class="actions"><?php if ($r['status'] === CLAIM_PENDING): echo supervisor_badge('swap', $r['id'], $r['pegawai_id']) . ' '; foreach (['swap_approve' => ['primary', 'Setujui'], 'swap_reject' => ['outline', 'Tolak']] as $act => $b): ?>
          <form method="post" class="inline"><?= $csrf ?><input type="hidden" name="action" value="<?= $act ?>"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="back" value="<?= $back ?>">
            <button class="btn small <?= $b[0] ?>"><?= $b[1] ?></button></form>
        <?php endforeach; endif; ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div><?php if (!$reqs): ?><div class="empty"><strong>Tidak ada pengajuan</strong>Karyawan mengajukan tukar shift di Portal Pegawai.</div><?php endif; ?></div>
  <h2 style="margin:0">Tukar shift tercatat (30 hari terakhir dan ke depan)</h2>
  <div class="tablecard"><div class="tablescroll"><table>
    <thead><tr><th>Tanggal</th><th>Karyawan 1</th><th>Karyawan 2</th><th>Alasan</th><th class="actions"></th></tr></thead><tbody>
    <?php foreach ($done as $t): ?><tr><td><?= h(fmt_date($t['tukar_tgl'])) ?></td><td><?= h($t['na']) ?> <span class="muted">→ <?= h($label($t['pegawai_id1'], $t['tukar_tgl'])) ?></span></td>
      <td><?= h($t['nb']) ?> <span class="muted">→ <?= h($label($t['pegawai_id2'], $t['tukar_tgl'])) ?></span></td><td><?= h($t['alasan']) ?></td>
      <td class="actions"><form method="post" class="inline" onsubmit="return confirm('Hapus tukar shift ini?')"><?= $csrf ?><input type="hidden" name="action" value="swap_delete"><input type="hidden" name="back" value="<?= $back ?>">
        <input type="hidden" name="tgl" value="<?= h($t['tukar_tgl']) ?>"><input type="hidden" name="a" value="<?= (int)$t['pegawai_id1'] ?>"><input type="hidden" name="b" value="<?= (int)$t['pegawai_id2'] ?>"><button class="btn small ghost">Hapus</button></form></td></tr><?php endforeach; ?>
    </tbody></table></div><?php if (!$done): ?><div class="empty"><strong>Belum ada</strong></div><?php endif; ?></div>
  <form method="post" class="toolbar"><?= $csrf ?><input type="hidden" name="action" value="swap_add"><input type="hidden" name="back" value="<?= $back ?>">
    <input type="date" name="tgl" required value="<?= date('Y-m-d') ?>">
    <?php foreach (['a', 'b'] as $k): ?><select name="<?= $k ?>" required><option value="">Karyawan…</option><?php foreach (employees(true) as $e): ?><option value="<?= (int)$e['pegawai_id'] ?>"><?= h($e['pegawai_nama']) ?></option><?php endforeach; ?></select><?php endforeach; ?>
    <input type="text" name="alasan" placeholder="Alasan" style="width:200px"><button class="btn">Tambah tukar shift</button></form>
<?php
// ======================================================================= Lembur (pengajuan)
elseif ($m === 'overtime'):
    $state = get('s', CLAIM_PENDING);
    $cnt = q('SELECT status, COUNT(*) FROM web_overtime GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
    $rows = q('SELECT o.*, p.pegawai_nama, p.pegawai_pin FROM web_overtime o JOIN pegawai p ON p.pegawai_id = o.pegawai_id WHERE o.status = ? ORDER BY o.tgl DESC, o.id DESC LIMIT 500', [$state])->fetchAll();
    // overtime the machine actually recorded that day, to compare with the request
    $actual = [];
    if ($rows) {
        $dates = array_column($rows, 'tgl');
        foreach (daily_attendance(min($dates), max($dates)) as $r) $actual[$r['pegawai_id']][$r['date']] = $r;
    }
    $otRule = payroll_settings()['ot_requires_approval'];
    hris_header('Lembur', 'time', 'overtime', 'Manajemen Waktu'); ?>
  <div class="counters" style="grid-template-columns:repeat(3,minmax(0,1fr))">
    <?php foreach ([CLAIM_PENDING => 'Menunggu persetujuan', CLAIM_APPROVED => 'Disetujui', CLAIM_REJECTED => 'Ditolak'] as $k => $label): ?>
      <a class="counter<?= $state === $k ? ' on' : '' ?>" href="hr.php?m=overtime&s=<?= $k ?>"><b><?= (int)(isset($cnt[$k]) ? $cnt[$k] : 0) ?></b><span><?= $label ?></span></a>
    <?php endforeach; ?>
  </div>
  <div class="notice"><div><b><?= $otRule ? 'Gaji hanya membayar lembur yang disetujui' : 'Gaji membayar lembur dari scan' ?></b>
    <?= $otRule ? 'Lembur dibayar sesuai scan, paling banyak sebanyak jam yang disetujui.' : 'Pengajuan di sini hanya catatan; ubah di Penggajian → Pengaturan gaji ("Lembur harus diajukan").' ?></div></div>
  <div class="tablecard"><div class="tablescroll"><table>
    <thead><tr><th>Nama karyawan</th><th>Tanggal</th><th>Diajukan</th><th>Durasi</th><th>Tercatat di mesin</th><th>Alasan</th><th>Status</th><th class="actions">Aksi</th></tr></thead><tbody>
    <?php foreach ($rows as $r): $a = isset($actual[$r['pegawai_id']][$r['tgl']]) ? $actual[$r['pegawai_id']][$r['tgl']] : null; ?>
      <tr><td><?= who($r) ?></td><td><?= h(fmt_date($r['tgl'])) ?></td><td><?= h(substr($r['jam_mulai'], 0, 5)) ?> – <?= h(substr($r['jam_selesai'], 0, 5)) ?></td>
        <td><?= fmt_minutes($r['minutes']) ?></td>
        <td class="muted"><?= $a ? h(trim(($a['first'] ?: '--:--') . ' – ' . ($a['last'] ?: '--:--'))) . ($a['ot'] ? ' · lembur ' . fmt_minutes($a['ot']) : '') : '—' ?></td>
        <td><?= h($r['alasan']) ?></td><td><?= claim_badge($r['status']) ?></td>
        <td class="actions"><?php if ($r['status'] === CLAIM_PENDING): echo supervisor_badge('overtime', $r['id'], $r['pegawai_id']) . ' '; foreach (['ot_approve' => ['primary', 'Setujui'], 'ot_reject' => ['outline', 'Tolak']] as $act => $b): ?>
          <form method="post" class="inline"><?= $csrf ?><input type="hidden" name="action" value="<?= $act ?>"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="back" value="<?= $back ?>">
            <button class="btn small <?= $b[0] ?>"><?= $b[1] ?></button></form>
        <?php endforeach; endif; ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div><?php if (!$rows): ?><div class="empty"><strong>Tidak ada pengajuan lembur</strong>Karyawan mengajukan lembur di Portal Pegawai.</div><?php endif; ?></div>
<?php
// ======================================================================= Saldo cuti
elseif ($m === 'balance'):
    hris_header('Saldo cuti ' . date('Y'), 'time', 'balance', 'Manajemen Waktu', '<a class="btn" href="quota.php">Atur jatah cuti</a>'); ?>
  <div class="tablecard"><div class="tablescroll"><table>
    <thead><tr><th>Nama karyawan</th><th>Departemen</th><th class="num">Jatah</th><th class="num">Diambil</th><th class="num">Menunggu</th><th class="num">Sisa</th></tr></thead><tbody>
    <?php foreach (people() as $p): list($quota, $taken, $pend) = leave_balance($p['pegawai_id']); ?>
      <tr><td><?= who($p) ?></td><td><?= h($p['dep']) ?></td><td class="num"><?= $quota === null ? '—' : $quota ?></td><td class="num"><?= $taken ?></td><td class="num"><?= $pend ?: '' ?></td>
        <td class="num"><?php if ($quota !== null): $rem = $quota - $taken; ?><span class="pill <?= $rem < 0 ? 'bad' : ($rem <= 2 ? 'warn' : 'ok') ?>"><?= $rem ?></span><?php endif; ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div></div>
<?php
// ======================================================================= Laporan (hub)
elseif ($m === 'reports'):
    $groups = [
        'Kehadiran' => [
            'rekap' => ['Ringkasan kehadiran', 'Hari kerja, hadir, terlambat, pulang awal, lembur dan tidak hadir per karyawan untuk satu periode.'],
            'harian' => ['Detail kehadiran', 'Jam masuk dan pulang setiap karyawan setiap hari, dengan terlambat, pulang awal dan lembur.'],
            'kartushift' => ['Kartu shift', 'Satu baris per karyawan, jam masuk / pulang per tanggal dalam periode.'],
            'scanlog' => ['Log kehadiran', 'Semua scan dari mesin dan input manual, per jam.'],
            'terlambat' => ['Keterlambatan', 'Berapa kali dan berapa lama tiap karyawan datang terlambat.'],
            'pulang' => ['Pulang awal', 'Berapa kali dan berapa lama tiap karyawan pulang sebelum jam pulang.'],
            'lembur' => ['Lembur', 'Lembur setelah jam pulang per karyawan, rekap atau per hari.'],
            'scan1x' => ['Scan tidak lengkap', 'Hari dengan hanya satu scan (lupa scan masuk atau pulang).'],
            'luar' => ['Masuk di hari libur', 'Scan pada hari libur atau di luar jadwal.'],
            'tahunan' => ['Rekap tahunan', 'Hadir, terlambat, tidak hadir dan cuti per bulan dalam satu tahun.'],
        ],
        'Cuti & Izin' => [
            'izin' => ['Cuti & izin', 'Semua cuti dan izin dalam periode, dengan status persetujuan.'],
            'saldo' => ['Saldo cuti', 'Jatah, diambil, menunggu dan sisa cuti tahun ini per karyawan.'],
            'cutin' => ['Cuti normatif', 'Cuti normatif (menikah, melahirkan, dll.) dalam periode.'],
            'cutip' => ['Cuti pribadi', 'Cuti tahunan yang diambil dalam periode.'],
        ],
        'Karyawan' => [
            'karyawan' => ['Data karyawan', 'Data pribadi dan pekerjaan semua karyawan, termasuk masa kerja.'],
        ],
    ];
    hris_header('Laporan', 'report', 'reports', '', '<a class="btn" href="report.php">Laporan gaya Fingerspot</a>'); ?>
  <?php foreach ($groups as $gname => $items): ?>
    <div class="card"><h2><?= h($gname) ?></h2>
      <div class="list"><?php foreach ($items as $k => $it): ?>
        <a class="li" href="hr.php?m=report&tab=<?= $k ?>" style="color:inherit;text-decoration:none"><?= hris_icon('report') ?>
          <div class="meta"><b style="color:var(--primary)"><?= h($it[0]) ?></b><small><?= h($it[1]) ?></small></div><?= hris_icon('chev') ?></a>
      <?php endforeach; ?></div></div>
  <?php endforeach; ?>
<?php
// ======================================================================= Laporan (one report)
elseif ($m === 'report'):
    $titles = ['rekap' => 'Ringkasan kehadiran', 'harian' => 'Detail kehadiran', 'kartushift' => 'Kartu shift', 'scanlog' => 'Log kehadiran', 'terlambat' => 'Keterlambatan',
               'pulang' => 'Pulang awal', 'lembur' => 'Lembur', 'scan1x' => 'Scan tidak lengkap', 'luar' => 'Masuk di hari libur', 'tahunan' => 'Rekap tahunan',
               'izin' => 'Cuti & izin', 'saldo' => 'Saldo cuti', 'cutin' => 'Cuti normatif', 'cutip' => 'Cuti pribadi', 'karyawan' => 'Data karyawan', 'kartu' => 'Kartu scanlog'];
    $q = ['m' => 'report', 'tab' => $tab, 'from' => $from, 'to' => $to, 'pegawai_id' => $pid, 'dept' => $dept, 'sort' => $sort, 'jenis' => $jenis, 'min' => $minDur, 'year' => $year];
    $perPage = 200;
    $page = max(1, (int)get('page', 1));
    $shown = array_slice($data, ($page - 1) * $perPage, $perPage);
    $noDates = in_array($tab, ['saldo', 'karyawan'], true);
    hris_header(isset($titles[$tab]) ? $titles[$tab] : $tab, 'report', 'reports', '<a href="hr.php?m=reports">Laporan</a>',
        '<a class="btn primary" href="hr.php?' . h(http_build_query($q + ['csv' => 1])) . '">Unduh (Excel / CSV)</a> <button class="btn" onclick="window.print()">Cetak</button>'); ?>
  <form class="toolbar" method="get">
    <input type="hidden" name="m" value="report"><input type="hidden" name="tab" value="<?= h($tab) ?>">
    <?php if ($tab === 'tahunan'): ?><input type="number" name="year" value="<?= $year ?>" style="width:90px">
    <?php elseif (!$noDates): ?><input type="date" name="from" value="<?= h($from) ?>"> s/d <input type="date" name="to" value="<?= h($to) ?>"><?php endif; ?>
    <select name="pegawai_id"><option value="">Semua karyawan</option><?php foreach (employees(true) as $e): ?><option value="<?= (int)$e['pegawai_id'] ?>" <?= (string)$pid === (string)$e['pegawai_id'] ? 'selected' : '' ?>><?= h($e['pegawai_nama']) ?></option><?php endforeach; ?></select>
    <select name="dept"><option value="">Semua departemen</option><?php foreach (lookup('pembagian2') as $k => $v): ?><option value="<?= h($k) ?>" <?= (string)$dept === (string)$k ? 'selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?></select>
    <?php if (in_array($tab, ['terlambat', 'pulang', 'lembur'], true)): ?>
      <select name="jenis"><option value="rekap" <?= $jenis === 'rekap' ? 'selected' : '' ?>>Rekap</option><option value="detail" <?= $jenis === 'detail' ? 'selected' : '' ?>>Detail</option></select>
      <span class="muted">min.</span><input type="number" name="min" min="1" value="<?= $minDur ?>" style="width:70px"><span class="muted">menit</span>
    <?php endif; ?>
    <button class="btn">Tampilkan</button>
    <span class="grow"></span><span class="muted"><?= $noDates ? '' : h(fmt_date($from)) . ' – ' . h(fmt_date($to)) . ' · ' ?><?= number_format(count($data)) ?> baris</span>
  </form>
  <div class="tablecard"><div class="tablescroll"><table>
    <thead>
      <?php if ($groups): ?><tr><?php foreach ($groups as $g): ?><th colspan="<?= (int)$g[1] ?>" style="text-align:center;border-left:1px solid var(--line)"><?= h($g[0]) ?></th><?php endforeach; ?></tr><?php endif; ?>
      <tr><?php foreach ($headers as $i => $hd): ?><th style="<?= in_array($i, $groupStart, true) ? 'border-left:1px solid var(--line)' : '' ?>"><?= h($hd) ?></th><?php endforeach; ?></tr>
    </thead><tbody>
    <?php foreach ($shown as $row): ?><tr><?php foreach ($row as $i => $cell): $gl = in_array($i, $groupStart, true) ? 'border-left:1px solid var(--line);' : ''; ?>
      <?php if (is_array($cell) && isset($cell['miss'])): ?><td style="<?= $gl ?>background:var(--bad-soft)"></td>
      <?php elseif (is_array($cell)): ?><td style="<?= $gl ?>"><?= status_badge($cell['status']) ?></td>
      <?php else: ?><td style="<?= $gl ?>" class="<?= in_array($headers[$i], ['Terlambat', 'Pulang awal'], true) && $cell !== '' ? 'red' : '' ?>"><?= h($cell) ?></td><?php endif; ?>
    <?php endforeach; ?></tr><?php endforeach; ?>
    </tbody></table></div>
    <?php if (!$data): ?><div class="empty"><strong>Tidak ada data</strong>Ubah periode atau filter.</div><?php endif; ?>
    <?php if (count($data) > $perPage): ?><div class="pager"><span class="grow"></span>
      <?php if ($page > 1): ?><a class="btn small" href="hr.php?<?= h(http_build_query($q + ['page' => $page - 1])) ?>">‹</a><?php endif; ?>
      <span><?= $page ?> / <?= (int)ceil(count($data) / $perPage) ?></span>
      <?php if ($page * $perPage < count($data)): ?><a class="btn small" href="hr.php?<?= h(http_build_query($q + ['page' => $page + 1])) ?>">›</a><?php endif; ?></div><?php endif; ?>
  </div>
<?php
else:
    redirect('hr.php');
endif;
hris_footer();
