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
        'search'   => '<circle cx="11" cy="11" r="7"/><path d="M20 20l-4-4"/>',
        'chev'     => '<path d="M9 6l6 6-6 6"/>',
    ];
    return '<svg viewBox="0 0 24 24" aria-hidden="true">' . $p[$name] . '</svg>';
}

/** Modules: key => [label, icon, [sub key => label] or a link to a Fingerspot-style page]. */
function hris_modules()
{
    return [
        'home'     => ['Beranda', 'home', ['home' => 'Beranda']],
        'employee' => ['Karyawan', 'people', ['employees' => 'Daftar karyawan']],
        'time'     => ['Manajemen Waktu', 'clock', ['attendance' => 'Kehadiran', 'timeoff' => 'Cuti & Izin', 'balance' => 'Saldo cuti']],
        'report'   => ['Laporan', 'report', ['reports' => 'Semua laporan']],
        'settings' => ['Pengaturan', 'settings', 'settings.php'],
    ];
}

function hris_header($title, $module, $sub = '', $crumb = '', $actions = '')
{
    global $CONFIG;
    $u = current_user();
    $company = isset($CONFIG['company']) ? $CONFIG['company'] : 'Perusahaan';
    $mods = hris_modules();
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
  <span class="userchip"><span class="avatar"><?= h(initials($u['full_name'] ?: $u['username'])) ?></span>
    <span class="usertext"><b><?= h($u['full_name'] ?: $u['username']) ?></b><small><a href="logout.php">Keluar</a></small></span></span>
</header>
<div class="shell">
  <aside class="rail wide" aria-label="Menu utama">
    <?php foreach ($mods as $key => $m):
        $href = is_array($m[2]) ? 'hr.php?m=' . key($m[2]) : $m[2]; ?>
      <a class="item<?= $key === $module ? ' on' : '' ?>" href="<?= h($href) ?>"><?= hris_icon($m[1]) ?><span class="lbl"><?= h($m[0]) ?></span></a>
    <?php endforeach; ?>
    <hr class="sep">
    <a class="item" href="dashboard.php"><?= hris_icon('finger') ?><span class="lbl">Absensi (Fingerspot)</span></a>
    <a class="item" href="quick.php"><?= hris_icon('clock') ?><span class="lbl">Input Cepat</span></a>
    <a class="item" href="manual.php"><?= hris_icon('clock') ?><span class="lbl">Revisi Scan Manual</span></a>
    <a class="item" href="portal.php"><?= hris_icon('portal') ?><span class="lbl">Portal Pegawai</span></a>
  </aside>
  <?php if (is_array($mods[$module][2]) && count($mods[$module][2]) > 1): ?>
    <nav class="subnav"><div class="title"><?= h($mods[$module][0]) ?></div>
      <?php foreach ($mods[$module][2] as $k => $label): ?><a href="hr.php?m=<?= $k ?>" class="<?= $k === $sub ? 'on' : '' ?>"><?= h($label) ?></a><?php endforeach; ?></nav>
  <?php endif; ?>
  <main>
    <div class="band"><h1><?php if ($crumb): ?><span class="crumb"><?= $crumb ?></span><?php endif; ?><?= h($title) ?></h1><?= $actions ?></div>
    <?php foreach (take_flashes() as $f): ?><div class="flash <?= h($f[0]) ?>"><?= h($f[1]) ?></div><?php endforeach; ?>
    <div class="content">
<?php
}

function hris_footer()
{
    echo "    </div>\n  </main>\n</div>\n</body>\n</html>\n";
}

/** Hidden inputs that identify one izin row (for approve / reject forms). */
function izin_key_fields($r)
{
    $out = '';
    foreach (['pegawai_id', 'izin_tgl', 'izin_jenis_id', 'izin_status', 'izin_urutan'] as $k) $out .= '<input type="hidden" name="' . $k . '" value="' . h($r[$k]) . '">';
    return $out;
}
