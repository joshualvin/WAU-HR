<?php
// Page frame laid out like the desktop attendance program: big menu buttons along the top,
// a side menu for the open section, and a status bar at the bottom.

function menu_icon($name)
{
    $i = [
        'Dashboard'    => '<path d="M4 15 16 4l12 11" fill="none" stroke="#1d5fb8" stroke-width="2.6" stroke-linejoin="round"/><path d="M8 13v13h6v-7h4v7h6V13" fill="#4a90e2" stroke="#1d5fb8" stroke-width="1.4"/>',
        'Pegawai'      => '<circle cx="11" cy="10" r="5" fill="#f2b880" stroke="#a0643a"/><path d="M2 27c0-6 4-9 9-9s9 3 9 9z" fill="#5b8fd6" stroke="#2f5f9e"/><circle cx="22" cy="12" r="4" fill="#f2c79a" stroke="#a0643a"/><path d="M16 27c1-5 3-7 6-7s7 2 8 7z" fill="#e0a43a" stroke="#9a6a14"/>',
        'Pengecualian' => '<circle cx="13" cy="9" r="5" fill="#f2b880" stroke="#a0643a"/><path d="M4 28c0-7 4-11 9-11s9 4 9 11z" fill="#6d7f95" stroke="#3f4d5f"/><path d="M24 15 30 27H18z" fill="#f7c600" stroke="#9a7a00"/><path d="M24 19v4M24 25v.5" stroke="#333" stroke-width="1.8" stroke-linecap="round"/>',
        'Mesin'        => '<rect x="7" y="3" width="18" height="26" rx="3" fill="#5f6b78" stroke="#2f3740"/><rect x="10" y="6" width="12" height="8" fill="#9fd3ff" stroke="#2f6aa0"/><g fill="#d9dde2"><rect x="10" y="17" width="3" height="2"/><rect x="14.5" y="17" width="3" height="2"/><rect x="19" y="17" width="3" height="2"/><rect x="10" y="21" width="3" height="2"/><rect x="14.5" y="21" width="3" height="2"/><rect x="19" y="21" width="3" height="2"/></g>',
        'HR'           => '<rect x="4" y="9" width="24" height="17" rx="3" fill="#7c5ce0" stroke="#4a2fa0"/><path d="M12 9V6h8v3" fill="none" stroke="#4a2fa0" stroke-width="2"/><path d="M4 16h24" stroke="#4a2fa0"/><rect x="14" y="14" width="4" height="4" fill="#fff"/>',
        'Laporan'      => '<path d="M7 3h13l6 6v20H7z" fill="#fff" stroke="#5a7fa8" stroke-width="1.4"/><path d="M20 3v6h6" fill="#dbe8f5" stroke="#5a7fa8"/><path d="M11 14h11M11 18h11M11 22h8" stroke="#5a7fa8" stroke-width="1.6"/>',
        'Pengaturan'   => '<g fill="#8a96a3" stroke="#4b5560"><path d="M13 4h4l1 3 3 1 3-2 3 3-2 3 1 3 3 1v4l-3 1-1 3 2 3-3 3-3-2-3 1-1 3h-4l-1-3-3-1-3 2-3-3 2-3-1-3-3-1v-4l3-1 1-3-2-3 3-3 3 2 3-1z"/></g><circle cx="15" cy="16" r="4.5" fill="#f0f0f0" stroke="#4b5560"/>',
    ];
    return '<svg viewBox="0 0 32 32" width="30" height="30" aria-hidden="true">' . $i[$name] . '</svg>';
}

function page_header($title, $active = '')
{
    global $CONFIG;
    $u = current_user();
    $sections = [
        'Dashboard'    => ['dashboard.php' => 'Dashboard'],
        'Pegawai'      => ['employees.php' => 'Data Pegawai'],
        'Pengecualian' => ['quick.php' => 'Input Cepat (Tidak scan / Dinas)', 'manual.php' => 'Revisi Scan Manual', 'leave.php' => 'Izin, Terlambat, Cuti', 'quota.php' => 'Jatah Cuti',
                           'missing.php' => 'Scan Tidak Lengkap', 'scans.php?add=1#add' => 'Tambah Scan Manual'],
        'Mesin'        => ['devices.php' => 'Daftar Mesin', 'sync.php' => 'Sinkron dari Program', 'ftm.php' => 'Sinkron FTM (kantor pusat)', 'cloud.php' => 'Download Fingerspot.io', 'gps.php' => 'Scan GPS (FiO)', 'scans.php' => 'Data Scanlog'],
        'Laporan'      => ['report.php' => 'Laporan Absensi'],
        'HR'           => ['hr.php' => 'HR Overview', 'portal.php' => 'Portal Pegawai'],
    ];
    if ($u && $u['role'] === 'admin') {
        $sections['Pegawai']['employee_edit.php'] = 'Tambah Pegawai';
        $sections['Pengaturan'] = ['settings.php' => 'Semua Pengaturan', 'workhours.php' => 'Jam Kerja', 'schedules.php' => 'Jadwal Kerja',
                                   'holidays.php' => 'Hari Libur', 'master.php?t=pembagian1' => 'Jabatan', 'master.php?t=pembagian2' => 'Departemen',
                                   'master.php?t=pembagian3' => 'Kantor', 'master.php?t=kategori_izin' => 'Kategori Izin',
                                   'master.php?t=cuti_normatif' => 'Cuti Normatif', 'users.php' => 'User'];
    }
    $current = basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
    if ($current === 'master.php') $current .= '?t=' . (isset($_GET['t']) ? $_GET['t'] : (isset($_POST['t']) ? $_POST['t'] : 'pembagian1'));
    if ($current === 'scans.php' && (isset($_GET['add']) || isset($_POST['action']) && $_POST['action'] === 'add')) $current = 'scans.php?add=1#add';
    $activeSection = 'Dashboard';
    foreach ($sections as $name => $pages) if (isset($pages[$active])) $activeSection = $name;
    foreach ($sections as $name => $pages) if (isset($pages[$current])) { $activeSection = $name; break; }
    $side = $u && count($sections[$activeSection]) > 1;
    ?><!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> · Absensi</title>
<link rel="stylesheet" href="assets/app.css">
</head>
<body class="<?= $u ? 'app' : 'login' ?>">
<?php if ($u): ?>
<div class="titlebar">ABSENSI <?= h(isset($CONFIG['company']) ? strtoupper($CONFIG['company']) : 'WEB') ?> - [<?= h($activeSection) ?>]</div>
<header class="toolbar">
  <?php foreach ($sections as $name => $pages): ?>
    <a href="<?= h(key($pages)) ?>" class="tbtn<?= $name === $activeSection ? ' on' : '' ?>"><?= menu_icon($name) ?><span><?= h($name) ?></span></a>
  <?php endforeach; ?>
</header>
<?php endif; ?>
<div class="frame<?= $side ? ' with-side' : '' ?>">
<?php if ($side): ?>
<aside class="side">
  <div class="side-title"><?= h($activeSection) ?></div>
  <?php foreach ($sections[$activeSection] as $href => $label): ?>
    <a href="<?= h($href) ?>" class="<?= $href === $current ? 'on' : '' ?>"><?= h($label) ?></a>
  <?php endforeach; ?>
</aside>
<?php endif; ?>
<main>
<?php foreach (take_flashes() as $f): ?>
  <div class="flash <?= h($f[0]) ?>"><?= h($f[1]) ?></div>
<?php endforeach;
}

function page_footer()
{
    global $CONFIG;
    $u = current_user();
    ?></main>
</div>
<?php if ($u): ?>
<footer class="statusbar">
  <span class="sb-user"><svg viewBox="0 0 16 16" width="14" height="14"><circle cx="8" cy="5" r="3.2" fill="#e0a060"/><path d="M2 15c0-4 3-6 6-6s6 2 6 6z" fill="#3b6fb6"/></svg> <?= h($u['username']) ?></span>
  <span>App : web 1.0</span>
  <span>DB : <?= h($CONFIG['db_name']) ?></span>
  <span>Server : <?= h(preg_replace('/;.*/', '', $CONFIG['db_host'])) ?> ~ Port : <?= (int)(isset($CONFIG['db_port']) ? $CONFIG['db_port'] : 3306) ?></span>
  <span class="sb-right"><a href="logout.php">Keluar</a> · <span id="clock"><?= date('d-m-Y H:i') ?></span></span>
</footer>
<script>
  (function () {
    var c = document.getElementById('clock');
    function tick() { var d = new Date(), p = function (n) { return (n < 10 ? '0' : '') + n; };
      c.textContent = p(d.getDate()) + '-' + p(d.getMonth() + 1) + '-' + d.getFullYear() + ' ' + p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds()); }
    tick(); setInterval(tick, 1000);
    // filter bars refresh by themselves when a date, list or number changes (typed text still waits for Enter)
    document.querySelectorAll('form.inline[method=get]').forEach(function (f) {
      var timer;
      f.addEventListener('change', function (e) {
        var t = e.target;
        if (!t.matches('select, input[type=date], input[type=month], input[type=number]')) return;
        if (t.value === '' && t.type !== 'select-one') return;
        clearTimeout(timer);
        timer = setTimeout(function () { f.requestSubmit ? f.requestSubmit() : f.submit(); }, t.tagName === 'SELECT' ? 0 : 500);
      });
      f.addEventListener('submit', function () { document.body.classList.add('busy'); });
    });
    window.addEventListener('pageshow', function () { document.body.classList.remove('busy'); });
    // freeze the first N columns of tables marked data-freeze="N"
    function freeze(t) {
      var n = +t.dataset.freeze; if (!n) return;
      var ref = t.rows[0]; for (var r = 0; r < t.rows.length; r++) { if (t.rows[r].querySelector('th') && t.rows[r].cells.length >= n) ref = t.rows[r]; }
      t.querySelectorAll('.fz').forEach(function (c) { c.style.left = ''; c.classList.remove('fz', 'fz-edge'); });
      var lefts = [], base = ref.cells[0].offsetLeft;
      for (var i = 0; i < n && i < ref.cells.length; i++) lefts[i] = ref.cells[i].offsetLeft - base;
      for (var r = 0; r < t.rows.length; r++) {
        var col = 0, cells = t.rows[r].cells;
        for (var c = 0; c < cells.length && col < n; c++) {
          var cell = cells[c], span = cell.colSpan || 1;
          cell.classList.add('fz'); cell.style.left = (lefts[col] || 0) + 'px';
          cell.classList.toggle('fz-edge', col + span >= n);
          col += span;
        }
      }
    }
    var frozen = document.querySelectorAll('table[data-freeze]');
    frozen.forEach(freeze);
    window.addEventListener('resize', function () { frozen.forEach(freeze); });
    // click a grid row to highlight it, like the desktop program
    document.addEventListener('click', function (e) {
      if (e.target.closest('a, button, input, select, label')) return;
      var tr = e.target.closest('main table tr');
      if (!tr || tr.querySelector('th')) return;
      var t = tr.closest('table'); var o = t.querySelector('tr.sel'); if (o) o.classList.remove('sel'); tr.classList.add('sel');
    });
  })();
</script>
<?php endif; ?>
</body>
</html>
<?php
}

function employee_select($name, $selected = '', $all = false, $required = true)
{
    $out = '<select name="' . h($name) . '"' . ($required ? ' required' : '') . '>';
    $out .= '<option value="">' . ($all ? 'Semua pegawai' : 'Pilih pegawai…') . '</option>';
    foreach (employees(true) as $e) {
        $out .= '<option value="' . (int)$e['pegawai_id'] . '"' . ((string)$selected === (string)$e['pegawai_id'] ? ' selected' : '') . '>'
              . h($e['pegawai_nama'] . ' (' . $e['pegawai_pin'] . ')') . '</option>';
    }
    return $out . '</select>';
}

function options($map, $selected = '', $blank = null)
{
    $out = $blank !== null ? '<option value="">' . h($blank) . '</option>' : '';
    foreach ($map as $k => $v) {
        $out .= '<option value="' . h($k) . '"' . ((string)$selected === (string)$k ? ' selected' : '') . '>' . h($v) . '</option>';
    }
    return $out;
}
