<?php
// HRIS shell for hr.php: top bar, wide icon rail, section menu and header band (assets/hris.css).

function hris_icon($name)
{
    $p = [
        'home'     => '<path d="M3 11l9-7 9 7"/><path d="M5 10v10h14V10"/>',
        'people'   => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.5-4 3-6 6.5-6s6 2 6.5 6"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M18 14c2 .6 3.3 2.6 3.5 6"/>',
        'clock'    => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'report'   => '<path d="M5 3h10l4 4v14H5z"/><path d="M9 13v5M12 10v8M15 15v3"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9l2.1 2.1M17 17l2.1 2.1M4.9 19.1L7 17M17 7l2.1-2.1"/>',
        'portal'   => '<rect x="4" y="3" width="16" height="18" rx="2"/><circle cx="12" cy="10" r="3"/><path d="M7.5 18c.7-2.4 2.4-3.5 4.5-3.5s3.8 1.1 4.5 3.5"/>',
        'finger'   => '<path d="M12 4a7 7 0 0 0-7 7v3M19 14v-3a7 7 0 0 0-3-5.7"/><path d="M9 20c.6-1.6 1-3.6 1-6v-3a2 2 0 0 1 4 0v3"/><path d="M13 20.5c.8-2 1.3-4 1.3-6.5"/>',
        'star'     => '<path d="M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9z"/>',
        'money'    => '<rect x="2.5" y="6" width="19" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/><path d="M6 9.5v5M18 9.5v5"/>',
        'wallet'   => '<rect x="3" y="6" width="18" height="13" rx="2"/><path d="M3 10h18M16 14.5h2"/>',
        'brief'    => '<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M9 7V5h6v2M3 13h18"/>',
        'building' => '<path d="M4 21V5l8-3v19M12 8h8v13M8 8v.01M8 12v.01M8 16v.01M16 12v.01M16 16v.01M2 21h20"/>',
        'search'   => '<circle cx="11" cy="11" r="7"/><path d="M20 20l-4-4"/>',
        'plus'     => '<path d="M12 5v14M5 12h14"/>',
        'bell'     => '<path d="M6 17h12l-1.5-2.5V10a4.5 4.5 0 0 0-9 0v4.5z"/><path d="M10 20a2 2 0 0 0 4 0"/>',
        'plug'     => '<path d="M9 3v5M15 3v5M6 8h12v3a6 6 0 0 1-12 0zM12 17v4"/>',
        'chev'     => '<path d="M9 6l6 6-6 6"/>',
    ];
    return '<svg viewBox="0 0 24 24" aria-hidden="true">' . $p[$name] . '</svg>';
}

/** Modules: key => [label, icon, [sub key => label] or a link to a Fingerspot-style page]. */
function hris_modules()
{
    global $CONFIG;
    $mods = [
        'home'     => ['Beranda', 'home', ['home' => 'Beranda']],
        'employee' => ['Karyawan', 'people', ['employees' => 'Daftar karyawan', 'transfer.php' => 'Mutasi karyawan', 'bulkupdate.php' => 'Update data massal', 'recruit.php?p=manpower' => 'Manpower planning', 'talent.php?p=onboarding&t=onboarding' => 'Onboarding', 'talent.php?p=onboarding&t=offboarding' => 'Offboarding', 'discipline.php' => 'Surat peringatan']],
        'recruit'  => ['Rekrutmen', 'brief', ['recruit.php?p=jobs' => 'Lowongan & kandidat', 'careers.php' => 'Halaman karir']],
        'time'     => ['Waktu', 'clock', ['timeoff' => 'Cuti & Izin', 'attendance' => 'Kehadiran', 'gps.php' => 'Scan GPS (FiO)', 'overtime' => 'Lembur', 'calendar.php' => 'Kalender', 'shifts' => 'Jadwal shift', 'oncall.php' => 'On call', 'swaps' => 'Tukar shift', 'balance' => 'Saldo cuti', 'report.php?tab=harian' => 'Laporan waktu']],
        'finance'  => ['Keuangan', 'wallet', ['payroll.php?p=reimburse' => 'Reimbursement', 'payroll.php?p=loans' => 'Kasbon', 'payroll.php?p=bonus' => 'Bonus penjualan']],
        'payroll'  => ['Penggajian', 'money', ['payroll.php?p=run' => 'Proses gaji', 'payroll.php?p=components' => 'Komponen gaji', 'taxsim.php' => 'Simulasi pajak', 'payroll.php?p=bupot' => 'Bukti potong', 'payalloc.php' => 'Alokasi biaya gaji',
                                               'payroll.php?p=setup' => 'Data gaji karyawan', 'payroll.php?p=thr' => 'THR', 'payroll.php?p=settle' => 'Pesangon & penyelesaian', 'payroll.php?p=slips' => 'Riwayat & slip gaji', 'payroll.php?p=settings' => 'Pengaturan gaji']],
        'performance' => ['Produktivitas', 'star', ['tasks.php' => 'Proyek & tugas', 'talent.php?p=reviews' => 'Penilaian kinerja', 'letters.php' => 'Template surat & dokumen']],
        'company'  => ['Perusahaan', 'building', ['company.php' => 'Pengumuman & kalender', 'company.php?p=org' => 'Struktur organisasi', 'company.php?p=assets' => 'Aset', 'notifikasi.php' => 'Notifikasi', 'files.php' => 'File', 'activity.php' => 'Log aktivitas', 'reports' => 'Laporan']],
        'settings' => ['Pengaturan', 'settings', 'settings.php'],
    ];
}
    if (empty($CONFIG['use_shifts'])) unset($mods['time'][2]['shifts'], $mods['time'][2]['swaps']);   // no shift work here; set 'use_shifts' => true in config.php to bring them back
    return $mods;

/** The attendance side of the system, the "Aplikasi" group of the menu: label => page. */
function hris_apps()
{
    return ['Absensi (Fingerspot)' => 'dashboard.php', 'Input Cepat' => 'quick.php', 'Revisi Scan Manual' => 'manual.php', 'Scan Tidak Lengkap' => 'missing.php', 'Portal Pegawai' => 'portal.php'];
}

/** The "Integrasi" group of the menu: where data comes in from other systems. */
function hris_integrations()
{
    return ['Fingerspot.io (unduh scan)' => 'cloud.php', 'Fingerspot Personnel (sinkron)' => 'sync.php', 'FTM kantor pusat' => 'ftm.php'];
}

/** Things waiting for HR: leave and overtime requests, reimbursements and profile changes (the bell in the top bar). */
function hris_pending_count()
{
    $c = function ($sql, $params = []) { try { return (int)q($sql, $params)->fetchColumn(); } catch (Exception $e) { return 0; } };   // a table may not exist yet on a fresh install
    $n = 0;
    if (defined('REQ_PENDING')) $n += $c('SELECT COUNT(*) FROM izin WHERE izin_status = 0 AND ket_status = ? AND izin_jenis_id NOT BETWEEN 100 AND 105', [REQ_PENDING]);
    if (defined('CLAIM_PENDING')) foreach (['web_overtime', 'web_reimburse'] as $t) $n += $c("SELECT COUNT(*) FROM $t WHERE status = ?", [CLAIM_PENDING]);
    return $n + $c("SELECT COUNT(*) FROM web_profile_request WHERE status = 'pending'");
}

/** A section menu key is an hr.php module, or a full page link such as payroll.php?p=run. */
function hris_link($k)
{
    return strpos($k, '.php') !== false ? $k : 'hr.php?m=' . $k;
}

function hris_header($title, $module, $sub = '', $crumb = '', $actions = '')
{
    global $CONFIG;
    $u = current_user();
    $company = isset($CONFIG['company']) ? $CONFIG['company'] : 'Perusahaan';
    $mods = hris_modules();
    foreach ($mods as $mk => $mm) if ($sub !== '' && is_array($mm[2]) && isset($mm[2][$sub])) $module = $mk;   // the group that lists this page
    ?><!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> · HR</title>
<link rel="stylesheet" href="assets/hris.css">
</head>
<body>
<header class="top">
  <button class="iconbtn only-mobile" onclick="document.body.classList.toggle('drawer')" aria-label="Menu"><svg viewBox="0 0 24 24"><path d="M4 7h16M4 12h16M4 17h16"/></svg></button>
  <a class="brand" href="hr.php"><span class="mark"><?= h(strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $company), 0, 3)) ?: 'HR') ?></span>
    <span class="brandtext"><b><?= h($company) ?></b><small>HR</small></span></a>
  <span class="module">HRIS</span>
  <span class="grow"></span>
  <form method="get" action="hr.php" class="search" style="margin-right:6px"><?= hris_icon('search') ?>
    <input type="hidden" name="m" value="employees"><input type="search" name="q" placeholder="Cari karyawan" style="width:200px"></form>
  <a class="btn small" href="dashboard.php" title="Layar absensi gaya Fingerspot"><?= hris_icon('finger') ?> Absensi (Fingerspot)</a>
  <div class="quickadd"><button type="button" class="iconbtn" aria-label="Tambah" aria-haspopup="true"><?= hris_icon('plus') ?></button>
    <div class="dropmenu" role="menu"><a href="employee_edit.php">Tambah karyawan</a><a href="quick.php">Input Cepat (tidak scan / dinas)</a><a href="scans.php?add=1#add">Tambah scan manual</a><a href="letters.php?p=make">Buat surat</a><a href="company.php">Pengumuman baru</a></div></div>
  <?php $pending = hris_pending_count(); ?>
  <a class="iconbtn bell" href="hr.php?m=home#tugas" title="<?= $pending ? $pending . ' menunggu persetujuan' : 'Tidak ada yang menunggu' ?>" aria-label="Menunggu persetujuan"><?= hris_icon('bell') ?><?php if ($pending): ?><span class="badge"><?= $pending > 99 ? '99+' : $pending ?></span><?php endif; ?></a>
  <span class="userchip"><span class="avatar"><?= h(initials($u['full_name'] ?: $u['username'])) ?></span>
    <span class="usertext"><b><?= h($u['full_name'] ?: $u['username']) ?></b><small><?= h($company) ?> · <a href="logout.php">Keluar</a></small></span></span>
</header>
<div class="shell">
  <aside class="rail wide" aria-label="Menu utama">
    <?php
    $navItem = function ($label, $icon, $href, $on, $subs, $subOn) { ?>
      <div class="navgroup<?= count($subs) > 1 ? ' has' : '' ?><?= $on ? ' cur' : '' ?>">
        <a class="item<?= $on ? ' on' : '' ?>" href="<?= h($href) ?>"><?= hris_icon($icon) ?><span class="lbl"><?= h($label) ?></span><?php if (count($subs) > 1): ?><span class="chev"><?= hris_icon('chev') ?></span><?php endif; ?></a>
        <?php if (count($subs) > 1): ?><div class="fly" role="menu">
          <?php foreach ($subs as $k => $l): ?><a href="<?= h($k) ?>"<?= $k === $subOn ? ' class="on"' : '' ?>><?= h($l) ?></a><?php endforeach; ?></div><?php endif; ?>
      </div>
    <?php };
    foreach ($mods as $key => $m) {
        if ($key === 'settings') continue;
        $subs = [];
        if (is_array($m[2])) foreach ($m[2] as $k => $l) $subs[hris_link($k)] = $l;
        $navItem($m[0], $m[1], is_array($m[2]) ? hris_link(key($m[2])) : $m[2], $key === $module, $subs, $key === $module ? hris_link($sub) : null);
    } ?>
    <hr class="sep">
    <?php $apps = []; foreach (hris_apps() as $l => $href) $apps[$href] = $l;
    $navItem('Aplikasi', 'finger', 'dashboard.php', false, $apps, null);
    ?>
    <hr class="sep">
    <?php $navItem($mods['settings'][0], $mods['settings'][1], $mods['settings'][2], $module === 'settings', [], null); ?>
    <div class="bottom"><button type="button" class="railtoggle" id="railtoggle" title="Ciutkan menu" aria-label="Ciutkan menu"><?= hris_icon('chev') ?></button>
      <span class="cid"><?= h($company) ?></span></div>
  </aside>
  <main>
    <?php if ($module !== 'home'): ?><div class="band"><h1><?php if ($crumb): ?><span class="crumb"><?= $crumb ?></span><?php endif; ?><?= h($title) ?></h1><?= $actions ?></div><?php endif; ?>
    <?php foreach (take_flashes() as $f): ?><div class="flash <?= h($f[0]) ?>"><?= h($f[1]) ?></div><?php endforeach; ?>
    <div class="content">
<?php
}

function hris_footer()
{
    echo "    </div>\n  </main>\n</div>\n";
    ?><script>
(function () {
  var rail = document.querySelector('.rail'), groups = document.querySelectorAll('.navgroup.has');
  groups.forEach(function (g) {   // the flyout is fixed (the rail scrolls), so place it next to its item when it opens
    var f = g.querySelector('.fly');
    function place() { var r = g.getBoundingClientRect(); f.style.left = rail.getBoundingClientRect().right + 'px'; f.style.top = Math.max(60, Math.min(r.top, window.innerHeight - f.offsetHeight - 8)) + 'px'; }
    g.addEventListener('mouseenter', place); g.addEventListener('focusin', place);
  });
  var setpage = document.body.classList.contains('setpage');   // settings pages start with icons only, like Mekari; the button still opens the menu
  var mini = setpage; try { if (!setpage) mini = localStorage.getItem('railmini') === '1'; } catch (e) {}
  function apply() { rail.classList.toggle('wide', !mini); document.body.classList.toggle('railmini', mini); }
  apply();
  document.getElementById('railtoggle').addEventListener('click', function () { mini = !mini; if (!setpage) try { localStorage.setItem('railmini', mini ? '1' : '0'); } catch (e) {} apply(); });
})();
</script>
</body>
</html>
<?php
}

/** Hidden inputs that identify one izin row (for approve / reject forms). */
function izin_key_fields($r)
{
    $out = '';
    foreach (['pegawai_id', 'izin_tgl', 'izin_jenis_id', 'izin_status', 'izin_urutan'] as $k) $out .= '<input type="hidden" name="' . $k . '" value="' . h($r[$k]) . '">';
    return $out;
}
