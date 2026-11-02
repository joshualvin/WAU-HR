<?php
// Portal Pegawai: employee self-service in the style of an HRIS app.
// Employees sign in with their PIN and the login code HR gives them (HR Overview → Kode login).
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/portal.php';

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
$tabs = ['home' => 'Beranda', 'attendance' => 'Kehadiran Saya', 'timeoff' => 'Cuti & Izin', 'profile' => 'Profil'];
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
  <div class="p-card">
    <dl class="p-dl"><?php foreach ($fields as $k => $v): ?><dt><?= h($k) ?></dt><dd><?= $v !== '' && $v !== null ? h($v) : '<span class="muted">—</span>' ?></dd><?php endforeach; ?></dl>
    <p class="muted">Data salah? Hubungi HR untuk mengubahnya.</p>
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
