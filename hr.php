<?php
// HR (HRIS-style): Beranda, Karyawan (directory + profile), Manajemen Waktu (kehadiran, cuti & izin, saldo cuti).
// Attendance entry stays on the Fingerspot-style screens, linked from the rail and the top bar.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/portal.php';
require __DIR__ . '/inc/hris.php';
$me = require_login();
ensure_web_tables();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = post('action');
    if ($action === 'approve' || $action === 'reject') {
        decide_request(leave_key_from_post(), $action === 'approve');
        flash($action === 'approve' ? 'Pengajuan disetujui.' : 'Pengajuan ditolak.');
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
    $out = '';
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
    $off = q("SELECT i.izin_tgl, p.pegawai_id, p.pegawai_pin, p.pegawai_nama, j.izin_jenis_name FROM izin i JOIN pegawai p ON p.pegawai_id = i.pegawai_id
              LEFT JOIN jns_izin j ON j.izin_jenis_id = i.izin_jenis_id
              WHERE i.izin_status = 1 AND i.izin_jenis_id NOT BETWEEN 100 AND 105 AND i.izin_tgl BETWEEN ? AND ? ORDER BY i.izin_tgl, p.pegawai_nama",
             [$today, date('Y-m-d', strtotime('+6 days'))])->fetchAll();
    $all = people();
    $bdays = array_filter($all, function ($p) { return $p['tgl_lahir'] && date('m', strtotime($p['tgl_lahir'])) === date('m'); });
    $news = array_filter($all, function ($p) { return $p['tgl_mulai_kerja'] && $p['tgl_mulai_kerja'] >= date('Y-m-d', strtotime('-90 days')); });
    $total = count($rows) ?: 1;
    $hr = (int)date('G');
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
    <div class="grid4">
      <div class="card widget"><div class="wbody"><h2>Kehadiran hari ini</h2>
        <div class="stack"><i style="width:<?= 100 * ($c['ok'] + $c['incomplete']) / $total ?>%;background:var(--ok)"></i><i style="width:<?= 100 * $c['leave'] / $total ?>%;background:var(--off)"></i><i style="width:<?= 100 * $c['absent'] / $total ?>%;background:var(--bad)"></i></div>
        <div class="kv">
          <div><i class="dot" style="background:var(--ok)"></i><span>Hadir</span><span class="n"><?= $c['ok'] + $c['incomplete'] ?></span><span class="p"><?= $late ?> telat</span></div>
          <div><i class="dot" style="background:var(--off)"></i><span>Cuti / izin</span><span class="n"><?= $c['leave'] ?></span><span class="p"></span></div>
          <div><i class="dot" style="background:var(--bad)"></i><span>Belum / tidak scan</span><span class="n"><?= $c['absent'] ?></span><span class="p"></span></div>
          <div><i class="dot" style="background:var(--line)"></i><span>Libur</span><span class="n"><?= $c['off'] ?></span><span class="p"></span></div>
        </div></div><div class="wfoot"><a href="hr.php?m=attendance">Lihat kehadiran ›</a></div></div>
      <div class="card widget"><div class="wbody"><h2>Menunggu persetujuan</h2>
        <div style="font-size:32px;font-weight:600"><?= count($pending) ?></div><div class="muted">pengajuan cuti / izin</div></div>
        <div class="wfoot"><a href="hr.php?m=timeoff">Tinjau ›</a></div></div>
      <div class="card widget"><div class="wbody"><h2>Ulang tahun bulan ini</h2><div class="list">
        <?php foreach (array_slice($bdays, 0, 4) as $p): ?><div class="li"><span class="avatar"><?= h(initials($p['pegawai_nama'])) ?></span><div class="meta"><b><?= h($p['pegawai_nama']) ?></b><small><?= date('d-m', strtotime($p['tgl_lahir'])) ?></small></div></div><?php endforeach; ?>
        <?php if (!$bdays): ?><div class="muted">Tidak ada (atau tanggal lahir belum diisi).</div><?php endif; ?></div></div></div>
      <div class="card widget"><div class="wbody"><h2>Karyawan baru (90 hari)</h2><div class="list">
        <?php foreach (array_slice($news, 0, 4) as $p): ?><div class="li"><span class="avatar"><?= h(initials($p['pegawai_nama'])) ?></span><div class="meta"><b><?= h($p['pegawai_nama']) ?></b><small>mulai <?= date('d-m-Y', strtotime($p['tgl_mulai_kerja'])) ?></small></div></div><?php endforeach; ?>
        <?php if (!$news): ?><div class="muted">Tidak ada.</div><?php endif; ?></div></div></div>
    </div>
    <div class="grid3">
      <div class="card"><h2>Cuti / izin 7 hari ke depan</h2><div class="list">
        <?php foreach ($off as $r): ?><div class="li"><span class="code"><?= date('d', strtotime($r['izin_tgl'])) ?></span><div class="meta"><b><?= h($r['pegawai_nama']) ?></b><small><?= h($r['izin_jenis_name']) ?> · <?= h(fmt_date($r['izin_tgl'])) ?></small></div></div><?php endforeach; ?>
        <?php if (!$off): ?><div class="muted">Tidak ada.</div><?php endif; ?></div></div>
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
    $counts = q('SELECT pegawai_status, COUNT(*) FROM pegawai GROUP BY pegawai_status')->fetchAll(PDO::FETCH_KEY_PAIR);
    hris_header('Daftar karyawan', 'employee', 'employees', 'Karyawan', $me['role'] === 'admin' ? '<a class="btn primary" href="employee_edit.php">+ Tambah karyawan</a>' : ''); ?>
  <div class="counters" style="grid-template-columns:repeat(4,minmax(0,1fr))">
    <?php foreach (['' => 'Semua'] + $statusNames as $k => $label): ?>
      <a class="counter<?= (string)$status === (string)$k ? ' on' : '' ?>" href="hr.php?m=employees&status=<?= $k ?>&q=<?= urlencode($search) ?>">
        <b><?= $k === '' ? array_sum($counts) : (int)(isset($counts[$k]) ? $counts[$k] : 0) ?></b><span><?= h($label) ?></span></a>
    <?php endforeach; ?>
  </div>
  <form class="toolbar" method="get"><input type="hidden" name="m" value="employees"><input type="hidden" name="status" value="<?= h($status) ?>">
    <span class="search"><?= hris_icon('search') ?><input type="search" name="q" value="<?= h($search) ?>" placeholder="Cari nama, PIN, NIP"></span><button class="btn">Cari</button></form>
  <div class="tablecard"><div class="tablescroll"><table>
    <thead><tr><th>Nama karyawan</th><th>NIP</th><th>Jabatan</th><th>Departemen</th><th>Kantor</th><th>Mulai bekerja</th><th>Status</th><th>Portal</th><th class="actions">Aksi</th></tr></thead>
    <tbody>
    <?php foreach ($list as $p): ?>
      <tr><td><?= who($p) ?></td><td><?= h($p['pegawai_nip']) ?></td><td><?= h($p['jab']) ?></td><td><?= h($p['dep']) ?></td><td><?= h($p['kan']) ?></td>
        <td><?= $p['tgl_mulai_kerja'] ? date('d-m-Y', strtotime($p['tgl_mulai_kerja'])) : '' ?></td>
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
             . '<form method="post" class="inline" onsubmit="return confirm(\'Buat kode login baru? Kode lama tidak berlaku lagi.\')">' . $csrf
             . '<input type="hidden" name="action" value="code"><input type="hidden" name="pegawai_id" value="' . $id . '"><input type="hidden" name="back" value="' . $back . '">'
             . '<button class="btn outline">' . ($p['portal'] ? 'Kode login baru' : 'Buat kode login portal') . '</button></form>' : '');
    hris_header($p['pegawai_nama'], 'employee', 'employees', '<a href="hr.php?m=employees">Karyawan</a> / Profil', $actions); ?>
  <div class="card" style="display:flex;gap:16px;align-items:center;padding:16px 20px">
    <span class="avatar" style="width:56px;height:56px;font-size:18px"><?= h(initials($p['pegawai_nama'])) ?></span>
    <div style="flex:1"><b style="font-size:16px"><?= h($p['pegawai_nama']) ?></b><div class="muted">PIN <?= h($p['pegawai_pin']) ?> · <?= h(trim($p['jab'] . ' · ' . $p['dep'] . ' · ' . $p['kan'], ' ·')) ?></div></div>
    <span class="pill <?= ['1' => 'ok', '0' => 'grey', '2' => 'bad'][$p['pegawai_status']] ?? 'grey' ?>"><?= h($statusNames[$p['pegawai_status']] ?? '') ?></span>
  </div>
  <nav class="tabs"><?php foreach (['personal' => 'Data pribadi', 'employment' => 'Pekerjaan', 'attendance' => 'Kehadiran', 'timeoff' => 'Cuti & Izin'] as $k => $label): ?>
    <a class="<?= $tab === $k ? 'on' : '' ?>" href="hr.php?m=employee&id=<?= $id ?>&tab=<?= $k ?>"><?= h($label) ?></a><?php endforeach; ?></nav>
  <?php if ($tab === 'personal'): ?>
    <div class="section" style="max-width:none"><dl class="dl">
      <?php foreach (['Nama lengkap' => $p['pegawai_nama'], 'Nama panggilan' => $p['pegawai_alias'], 'Jenis kelamin' => (int)$p['gender'] === 2 ? 'Perempuan' : 'Laki-laki',
                      'Tempat lahir' => $p['tempat_lahir'], 'Tanggal lahir' => $p['tgl_lahir'] ? date('d-m-Y', strtotime($p['tgl_lahir'])) : '', 'No. telp' => $p['pegawai_telp']] as $k => $v): ?>
        <dt><?= h($k) ?></dt><dd><?= $v ? h($v) : '<span class="muted">—</span>' ?></dd><?php endforeach; ?></dl></div>
  <?php elseif ($tab === 'employment'):
      $sched = q('SELECT m.jdw_kerja_m_name, j.jdw_kerja_m_mulai FROM jdw_kerja_pegawai j JOIN jdw_kerja_m m ON m.jdw_kerja_m_id = j.jdw_kerja_m_id WHERE j.pegawai_id = ? ORDER BY j.jdw_kerja_m_mulai DESC LIMIT 1', [$id])->fetch(); ?>
    <div class="section" style="max-width:none"><dl class="dl">
      <?php foreach (['PIN (mesin absensi)' => $p['pegawai_pin'], 'NIP' => $p['pegawai_nip'], 'Jabatan' => $p['jab'], 'Departemen' => $p['dep'], 'Kantor' => $p['kan'],
                      'Jadwal kerja' => $sched ? $sched['jdw_kerja_m_name'] . ' (sejak ' . date('d-m-Y', strtotime($sched['jdw_kerja_m_mulai'])) . ')' : '',
                      'Mulai bekerja' => $p['tgl_mulai_kerja'] ? date('d-m-Y', strtotime($p['tgl_mulai_kerja'])) : '', 'Tanggal berhenti' => $p['tgl_resign'] ? date('d-m-Y', strtotime($p['tgl_resign'])) : '',
                      'Portal Pegawai' => $p['portal'] ? ($p['last_login'] ? 'login terakhir ' . date('d-m-Y H:i', strtotime($p['last_login'])) : 'kode dibuat, belum login') : 'belum punya kode login'] as $k => $v): ?>
        <dt><?= h($k) ?></dt><dd><?= $v ? h($v) : '<span class="muted">—</span>' ?></dd><?php endforeach; ?></dl></div>
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
else:
    redirect('hr.php');
endif;
hris_footer();
