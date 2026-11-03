<?php
// HR → Karyawan → On/offboarding, and HR → Kinerja (performance reviews). Admins only.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/portal.php';
require __DIR__ . '/inc/hris.php';
require __DIR__ . '/inc/talent.php';
$me = require_login('admin');
ensure_web_tables();
ensure_talent_tables();

$p = get('p', 'onboarding');
$types = ['onboarding' => 'Onboarding', 'offboarding' => 'Offboarding'];

// ---------- actions ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = post('action');
    $back = safe_back(post('back'), 'talent.php');
    if ($action === 'start') {
        $emp = employee(post('pegawai_id'));
        $type = isset($types[post('type')]) ? post('type') : 'onboarding';
        $start = valid_date(post('start')) ? post('start') : date('Y-m-d');
        if ($emp && !q('SELECT COUNT(*) FROM web_checklist WHERE pegawai_id = ? AND type = ?', [$emp['pegawai_id'], $type])->fetchColumn()) {
            $n = start_checklist($emp['pegawai_id'], $type, $start);
            audit('checklist_start', "{$emp['pegawai_nama']} $type");
            flash("{$types[$type]} {$emp['pegawai_nama']} dimulai ($n tugas).");
        }
        redirect('talent.php?' . http_build_query(['p' => 'checklist', 'id' => post('pegawai_id'), 'type' => $type]));
    } elseif ($action === 'toggle') {
        $t = q('SELECT * FROM web_checklist WHERE id = ?', [(int)post('id')])->fetch();
        if ($t) q('UPDATE web_checklist SET done_at = ?, done_by = ? WHERE id = ?', $t['done_at'] ? [null, '', $t['id']] : [date('Y-m-d H:i:s'), actor(), $t['id']]);
        redirect($back);
    } elseif ($action === 'add_task') {
        if (post('item') !== '') q('INSERT INTO web_checklist (pegawai_id, type, item, who, due_date, sort) VALUES (?, ?, ?, ?, ?, 999)',
            [(int)post('pegawai_id'), post('type'), post('item'), in_array(post('who'), TASK_WHO, true) ? post('who') : 'HR', valid_date(post('due')) ? post('due') : null]);
        redirect($back);
    } elseif ($action === 'delete_task') {
        q('DELETE FROM web_checklist WHERE id = ?', [(int)post('id')]);
        redirect($back);
    } elseif ($action === 'tpl_save') {
        foreach (isset($_POST['t']) ? (array)$_POST['t'] : [] as $id => $t) {
            if (trim($t['item']) === '') q('DELETE FROM web_checklist_tpl WHERE id = ?', [(int)$id]);
            else q('UPDATE web_checklist_tpl SET item = ?, who = ?, due_days = ?, sort = ? WHERE id = ?',
                [trim($t['item']), in_array($t['who'], TASK_WHO, true) ? $t['who'] : 'HR', (int)$t['due_days'], (int)$t['sort'], (int)$id]);
        }
        if (post('new_item') !== '') q('INSERT INTO web_checklist_tpl (type, item, who, due_days, sort) VALUES (?, ?, ?, ?, 999)',
            [post('type'), post('new_item'), in_array(post('new_who'), TASK_WHO, true) ? post('new_who') : 'HR', (int)post('new_due')]);
        audit('checklist_template', post('type'));
        flash('Template disimpan.');
        redirect('talent.php?p=templates&type=' . urlencode(post('type')));
    } elseif ($action === 'cycle_create') {
        $names = isset($_POST['c_name']) ? (array)$_POST['c_name'] : [];
        $weights = isset($_POST['c_weight']) ? (array)$_POST['c_weight'] : [];
        $criteria = [];
        foreach ($names as $i => $n) if (trim($n) !== '' && (int)$weights[$i] > 0) $criteria[] = ['name' => trim($n), 'weight' => (int)$weights[$i]];
        if (post('name') === '' || !valid_date(post('from')) || !valid_date(post('to')) || post('to') < post('from')) flash('Isi nama dan periode penilaian.', 'err');
        elseif (!$criteria) flash('Isi minimal satu kriteria dengan bobot.', 'err');
        else {
            q('INSERT INTO web_review_cycle (name, period_from, period_to, status, criteria, created_at) VALUES (?, ?, ?, ?, ?, ?)',
                [post('name'), post('from'), post('to'), 'open', json_encode($criteria), date('Y-m-d H:i:s')]);
            $cid = (int)db()->lastInsertId();
            foreach (employees(true) as $e) q('INSERT IGNORE INTO web_review (cycle_id, pegawai_id) VALUES (?, ?)', [$cid, $e['pegawai_id']]);
            audit('review_cycle', post('name'));
            flash('Siklus penilaian dibuat untuk semua karyawan aktif. Karyawan bisa mengisi penilaian diri di Portal Pegawai.');
            redirect('talent.php?p=cycle&id=' . $cid);
        }
        redirect('talent.php?p=reviews');
    } elseif ($action === 'cycle_status') {
        q('UPDATE web_review_cycle SET status = ? WHERE id = ?', [post('status') === 'closed' ? 'closed' : 'open', (int)post('id')]);
        redirect('talent.php?p=cycle&id=' . (int)post('id'));
    } elseif ($action === 'review_save') {
        $cycle = q('SELECT * FROM web_review_cycle WHERE id = ?', [(int)post('cycle_id')])->fetch();
        if ($cycle) {
            $criteria = json_decode($cycle['criteria'], true);
            $scores = array_map('intval', isset($_POST['score']) ? (array)$_POST['score'] : []);
            $final = weighted_score($criteria, $scores);
            if ($final === null) flash('Beri nilai 1–5 untuk semua kriteria.', 'err');
            else {
                q('UPDATE web_review SET mgr_scores = ?, mgr_note = ?, mgr_by = ?, mgr_at = ?, final_score = ? WHERE cycle_id = ? AND pegawai_id = ?',
                    [json_encode($scores), post('note'), actor(), date('Y-m-d H:i:s'), $final, $cycle['id'], (int)post('pegawai_id')]);
                audit('review_score', "cycle {$cycle['id']} pegawai " . (int)post('pegawai_id') . " $final");
                flash('Penilaian disimpan: ' . $final . ' (' . rating_label($final) . ').');
            }
        }
        redirect($back);
    }
}

$csrf = csrf_field();
$back = h($_SERVER['REQUEST_URI']);
$who = function ($id, $name, $pin) { return '<div class="who"><span class="avatar">' . h(initials($name)) . '</span><div><b>' . h($name) . '</b><small>PIN ' . h($pin) . '</small></div></div>'; };

// ======================================================================= On/offboarding overview
if ($p === 'onboarding'):
    $only = isset($types[get('t')]) ? get('t') : '';   // the menu opens the list as Onboarding or Offboarding
    $lists = q('SELECT c.pegawai_id, c.type, p.pegawai_nama, p.pegawai_pin, COUNT(*) total, SUM(c.done_at IS NOT NULL) done,
                       SUM(c.done_at IS NULL AND c.due_date < CURDATE()) late, MIN(CASE WHEN c.done_at IS NULL THEN c.due_date END) next_due
                FROM web_checklist c JOIN pegawai p ON p.pegawai_id = c.pegawai_id GROUP BY c.pegawai_id, c.type ORDER BY (SUM(c.done_at IS NULL) = 0), next_due')->fetchAll();
    if ($only) $lists = array_values(array_filter($lists, function ($l) use ($only) { return $l['type'] === $only; }));
    $new = q('SELECT p.pegawai_id, p.pegawai_nama, p.pegawai_pin, p.tgl_mulai_kerja FROM pegawai p
              WHERE p.pegawai_status = 1 AND p.tgl_mulai_kerja >= ? AND NOT EXISTS (SELECT 1 FROM web_checklist c WHERE c.pegawai_id = p.pegawai_id AND c.type = ?)',
             [date('Y-m-d', strtotime('-90 days')), 'onboarding'])->fetchAll();
    hris_header($only ? $types[$only] : 'On/offboarding', 'employee', 'talent.php?p=onboarding' . ($only ? '&t=' . $only : '&t=onboarding'), 'Karyawan', '<a class="btn" href="talent.php?p=templates">Atur template</a>'); ?>
  <?php if ($new): ?><div class="notice"><div><b><?= count($new) ?> karyawan baru belum punya checklist onboarding</b>
    <?php foreach ($new as $n): ?><form method="post" class="inline"><?= $csrf ?><input type="hidden" name="action" value="start"><input type="hidden" name="type" value="onboarding">
      <input type="hidden" name="pegawai_id" value="<?= (int)$n['pegawai_id'] ?>"><input type="hidden" name="start" value="<?= h($n['tgl_mulai_kerja']) ?>">
      <button class="btn small">Mulai onboarding <?= h($n['pegawai_nama']) ?></button></form> <?php endforeach; ?></div></div><?php endif; ?>
  <form class="toolbar" method="post"><?= $csrf ?><input type="hidden" name="action" value="start">
    <select name="pegawai_id" required><option value="">Pilih karyawan…</option><?php foreach (employees(false) as $e): ?><option value="<?= (int)$e['pegawai_id'] ?>"><?= h($e['pegawai_nama']) ?></option><?php endforeach; ?></select>
    <select name="type"><?php foreach ($types as $k => $label): ?><option value="<?= $k ?>"<?= $only === $k ? ' selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select><input type="date" name="start" value="<?= date('Y-m-d') ?>" title="Tanggal mulai / hari terakhir">
    <button class="btn primary">Mulai checklist</button></form>
  <div class="tablecard"><div class="tablescroll"><table>
    <thead><tr><th>Nama karyawan</th><th>Jenis</th><th>Progres</th><th>Tugas berikutnya</th><th>Terlambat</th><th class="actions">Aksi</th></tr></thead><tbody>
    <?php foreach ($lists as $l): $pct = $l['total'] ? round(100 * $l['done'] / $l['total']) : 0; ?>
      <tr><td><?= $who($l['pegawai_id'], $l['pegawai_nama'], $l['pegawai_pin']) ?></td><td><?= h($types[$l['type']]) ?></td>
        <td style="min-width:160px"><div class="stack"><i style="width:<?= $pct ?>%;background:var(--ok)"></i></div><small class="muted"><?= (int)$l['done'] ?>/<?= (int)$l['total'] ?> selesai</small></td>
        <td><?= $l['next_due'] ? h(fmt_date($l['next_due'])) : '<span class="pill ok">Selesai</span>' ?></td><td class="<?= $l['late'] ? 'red' : '' ?>"><?= $l['late'] ?: '' ?></td>
        <td class="actions"><a class="btn small" href="talent.php?<?= h(http_build_query(['p' => 'checklist', 'id' => $l['pegawai_id'], 'type' => $l['type']])) ?>">Buka</a></td></tr>
    <?php endforeach; ?>
    </tbody></table></div><?php if (!$lists): ?><div class="empty"><strong>Belum ada checklist</strong>Mulai onboarding untuk karyawan baru atau offboarding untuk yang keluar.</div><?php endif; ?></div>
<?php
// ======================================================================= One checklist
elseif ($p === 'checklist'):
    $id = (int)get('id');
    $type = isset($types[get('type')]) ? get('type') : 'onboarding';
    $emp = employee($id);
    if (!$emp) redirect('talent.php');
    $tasks = q('SELECT * FROM web_checklist WHERE pegawai_id = ? AND type = ? ORDER BY sort, due_date, id', [$id, $type])->fetchAll();
    hris_header($types[$type] . ' · ' . $emp['pegawai_nama'], 'employee', 'talent.php?p=onboarding&t=' . $type, '<a href="talent.php">On/offboarding</a>',
        '<a class="btn" href="hr.php?m=employee&id=' . $id . '">Profil karyawan</a>'); ?>
  <?php if (!$tasks): ?>
    <form class="section" method="post"><?= $csrf ?><input type="hidden" name="action" value="start"><input type="hidden" name="type" value="<?= h($type) ?>"><input type="hidden" name="pegawai_id" value="<?= $id ?>">
      <h2>Mulai <?= h(strtolower($types[$type])) ?> untuk <?= h($emp['pegawai_nama']) ?></h2>
      <label class="field"><span><?= $type === 'onboarding' ? 'Tanggal mulai bekerja' : 'Hari terakhir bekerja' ?></span><input type="date" name="start" value="<?= h($type === 'onboarding' && $emp['tgl_mulai_kerja'] ? $emp['tgl_mulai_kerja'] : date('Y-m-d')) ?>"></label>
      <div class="formfoot"><button class="btn primary">Mulai dari template</button></div></form>
  <?php else: ?>
  <div class="tablecard"><div class="tablescroll"><table>
    <thead><tr><th style="width:40px"></th><th>Tugas</th><th>Oleh</th><th>Batas waktu</th><th>Selesai</th><th class="actions"></th></tr></thead><tbody>
    <?php foreach ($tasks as $t): $late = !$t['done_at'] && $t['due_date'] && $t['due_date'] < date('Y-m-d'); ?>
      <tr><td><form method="post" class="inline"><?= $csrf ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><input type="hidden" name="back" value="<?= $back ?>">
          <input type="checkbox" <?= $t['done_at'] ? 'checked' : '' ?> onchange="this.form.submit()" aria-label="Selesai"></form></td>
        <td style="white-space:normal;<?= $t['done_at'] ? 'text-decoration:line-through;color:var(--muted)' : '' ?>"><?= h($t['item']) ?></td>
        <td><span class="pill <?= $t['who'] === 'Karyawan' ? 'off' : ($t['who'] === 'Atasan' ? 'warn' : 'grey') ?>"><?= h($t['who']) ?></span></td>
        <td class="<?= $late ? 'red' : '' ?>"><?= $t['due_date'] ? h(fmt_date($t['due_date'])) : '' ?></td>
        <td class="muted"><?= $t['done_at'] ? h(date('d-m-Y', strtotime($t['done_at']))) . ' · ' . h($t['done_by']) : '' ?></td>
        <td class="actions"><form method="post" class="inline" onsubmit="return confirm('Hapus tugas ini?')"><?= $csrf ?><input type="hidden" name="action" value="delete_task"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><input type="hidden" name="back" value="<?= $back ?>"><button class="btn small ghost">Hapus</button></form></td></tr>
    <?php endforeach; ?>
    </tbody></table></div></div>
  <form class="toolbar" method="post"><?= $csrf ?><input type="hidden" name="action" value="add_task"><input type="hidden" name="pegawai_id" value="<?= $id ?>"><input type="hidden" name="type" value="<?= h($type) ?>"><input type="hidden" name="back" value="<?= $back ?>">
    <input type="text" name="item" placeholder="Tugas tambahan" style="width:280px" required><select name="who"><?php foreach (TASK_WHO as $w): ?><option><?= $w ?></option><?php endforeach; ?></select>
    <input type="date" name="due"><button class="btn">Tambah tugas</button></form>
  <?php endif; ?>
<?php
// ======================================================================= Templates
elseif ($p === 'templates'):
    $type = isset($types[get('type')]) ? get('type') : 'onboarding';
    $tpl = q('SELECT * FROM web_checklist_tpl WHERE type = ? ORDER BY sort, id', [$type])->fetchAll();
    hris_header('Template ' . strtolower($types[$type]), 'employee', 'talent.php?p=onboarding&t=' . $type, '<a href="talent.php">On/offboarding</a>'); ?>
  <nav class="tabs"><?php foreach ($types as $k => $label): ?><a class="<?= $k === $type ? 'on' : '' ?>" href="talent.php?p=templates&type=<?= $k ?>"><?= $label ?></a><?php endforeach; ?></nav>
  <form method="post"><?= $csrf ?><input type="hidden" name="action" value="tpl_save"><input type="hidden" name="type" value="<?= h($type) ?>">
  <div class="tablecard"><div class="tablescroll"><table>
    <thead><tr><th>Urutan</th><th>Tugas (kosongkan untuk menghapus)</th><th>Oleh</th><th>Batas (hari dari tanggal mulai)</th></tr></thead><tbody>
    <?php foreach ($tpl as $t): ?><tr><td><input type="number" name="t[<?= (int)$t['id'] ?>][sort]" value="<?= (int)$t['sort'] ?>" style="width:70px"></td>
      <td><input type="text" name="t[<?= (int)$t['id'] ?>][item]" value="<?= h($t['item']) ?>" style="width:420px"></td>
      <td><select name="t[<?= (int)$t['id'] ?>][who]"><?php foreach (TASK_WHO as $w): ?><option <?= $t['who'] === $w ? 'selected' : '' ?>><?= $w ?></option><?php endforeach; ?></select></td>
      <td><input type="number" name="t[<?= (int)$t['id'] ?>][due_days]" value="<?= (int)$t['due_days'] ?>" style="width:90px"></td></tr><?php endforeach; ?>
    <tr><td class="muted">baru</td><td><input type="text" name="new_item" placeholder="Tambah tugas" style="width:420px"></td>
      <td><select name="new_who"><?php foreach (TASK_WHO as $w): ?><option><?= $w ?></option><?php endforeach; ?></select></td><td><input type="number" name="new_due" value="0" style="width:90px"></td></tr>
    </tbody></table></div></div>
  <div class="formfoot"><span class="muted" style="margin-right:auto">Perubahan template berlaku untuk checklist yang dimulai sesudahnya.</span><button class="btn primary">Simpan template</button></div></form>
<?php
// ======================================================================= Review cycles
elseif ($p === 'reviews'):
    $cycles = q('SELECT c.*, COUNT(r.pegawai_id) n, SUM(r.self_at IS NOT NULL) self_n, SUM(r.final_score IS NOT NULL) done_n, AVG(r.final_score) avg_score
                 FROM web_review_cycle c LEFT JOIN web_review r ON r.cycle_id = c.id GROUP BY c.id ORDER BY c.period_to DESC')->fetchAll();
    $y = date('Y'); $half = (int)date('n') <= 6 ? 1 : 2;
    hris_header('Penilaian kinerja', 'performance', 'talent.php?p=reviews'); ?>
  <div class="tablecard"><div class="tablescroll"><table>
    <thead><tr><th>Siklus</th><th>Periode</th><th class="num">Karyawan</th><th class="num">Penilaian diri</th><th class="num">Dinilai HR</th><th class="num">Rata-rata</th><th>Status</th><th class="actions">Aksi</th></tr></thead><tbody>
    <?php foreach ($cycles as $c): ?><tr><td><b><?= h($c['name']) ?></b></td><td><?= h(date('d-m-Y', strtotime($c['period_from']))) ?> – <?= h(date('d-m-Y', strtotime($c['period_to']))) ?></td>
      <td class="num"><?= (int)$c['n'] ?></td><td class="num"><?= (int)$c['self_n'] ?></td><td class="num"><?= (int)$c['done_n'] ?></td>
      <td class="num"><?= $c['avg_score'] !== null ? number_format($c['avg_score'], 2) : '' ?></td>
      <td><span class="pill <?= $c['status'] === 'open' ? 'ok' : 'grey' ?>"><?= $c['status'] === 'open' ? 'Berjalan' : 'Ditutup' ?></span></td>
      <td class="actions"><a class="btn small" href="talent.php?p=cycle&id=<?= (int)$c['id'] ?>">Buka</a></td></tr><?php endforeach; ?>
    </tbody></table></div><?php if (!$cycles): ?><div class="empty"><strong>Belum ada siklus penilaian</strong>Buat siklus di bawah.</div><?php endif; ?></div>
  <form method="post" class="section"><?= $csrf ?><input type="hidden" name="action" value="cycle_create">
    <h2>Siklus penilaian baru</h2>
    <div class="row2"><label class="field"><span>Nama</span><input type="text" name="name" required value="Penilaian <?= $y ?> semester <?= $half ?>"></label><span></span>
      <label class="field"><span>Periode dari</span><input type="date" name="from" required value="<?= $half === 1 ? "$y-01-01" : "$y-07-01" ?>"></label>
      <label class="field"><span>Sampai</span><input type="date" name="to" required value="<?= $half === 1 ? "$y-06-30" : "$y-12-31" ?>"></label></div>
    <div class="field"><span>Kriteria dan bobot (%)</span>
      <?php foreach (array_merge(default_criteria(), [['name' => '', 'weight' => ''], ['name' => '', 'weight' => '']]) as $c): ?>
        <div style="display:flex;gap:8px"><input type="text" name="c_name[]" value="<?= h($c['name']) ?>" style="flex:1" placeholder="Kriteria"><input type="number" name="c_weight[]" value="<?= h($c['weight']) ?>" style="width:90px" placeholder="Bobot"></div>
      <?php endforeach; ?><small>Nilai akhir = rata-rata nilai HR (1–5) dibobot. Semua karyawan aktif ikut dinilai.</small></div>
    <div class="formfoot"><button class="btn primary">Buat siklus</button></div></form>
<?php
// ======================================================================= One cycle
elseif ($p === 'cycle'):
    $cycle = q('SELECT * FROM web_review_cycle WHERE id = ?', [(int)get('id')])->fetch();
    if (!$cycle) redirect('talent.php?p=reviews');
    $rows = q('SELECT r.*, p.pegawai_nama, p.pegawai_pin, p2.pembagian2_nama dep FROM web_review r JOIN pegawai p ON p.pegawai_id = r.pegawai_id
               LEFT JOIN pembagian2 p2 ON p2.pembagian2_id = p.pembagian2_id WHERE r.cycle_id = ? ORDER BY p.pegawai_nama', [$cycle['id']])->fetchAll();
    $open = $cycle['status'] === 'open';
    hris_header($cycle['name'], 'performance', 'talent.php?p=reviews', '<a href="talent.php?p=reviews">Penilaian kinerja</a>',
        '<form method="post" class="inline">' . $csrf . '<input type="hidden" name="action" value="cycle_status"><input type="hidden" name="id" value="' . (int)$cycle['id'] . '">'
        . '<button class="btn" name="status" value="' . ($open ? 'closed' : 'open') . '">' . ($open ? 'Tutup siklus' : 'Buka lagi') . '</button></form>'); ?>
  <p class="muted"><?= h(fmt_date($cycle['period_from'])) ?> – <?= h(fmt_date($cycle['period_to'])) ?> · <?= $open ? 'karyawan bisa mengisi penilaian diri di Portal Pegawai' : 'ditutup: penilaian diri tidak bisa diubah' ?></p>
  <div class="tablecard"><div class="tablescroll"><table>
    <thead><tr><th>Nama karyawan</th><th>Departemen</th><th>Penilaian diri</th><th>Nilai diri</th><th>Nilai HR</th><th>Predikat</th><th class="actions">Aksi</th></tr></thead><tbody>
    <?php $crit = json_decode($cycle['criteria'], true);
    foreach ($rows as $r): $self = $r['self_scores'] ? weighted_score($crit, json_decode($r['self_scores'], true)) : null; ?>
      <tr><td><?= $who($r['pegawai_id'], $r['pegawai_nama'], $r['pegawai_pin']) ?></td><td><?= h($r['dep']) ?></td>
        <td><?= $r['self_at'] ? '<span class="pill ok">Sudah</span>' : '<span class="pill grey">Belum</span>' ?></td><td class="num"><?= $self !== null ? number_format($self, 2) : '' ?></td>
        <td class="num"><b><?= $r['final_score'] !== null ? number_format($r['final_score'], 2) : '' ?></b></td><td><?= h(rating_label($r['final_score'] !== null ? (float)$r['final_score'] : null)) ?></td>
        <td class="actions"><a class="btn small <?= $r['final_score'] === null ? 'primary' : '' ?>" href="talent.php?p=review&cycle=<?= (int)$cycle['id'] ?>&id=<?= (int)$r['pegawai_id'] ?>"><?= $r['final_score'] === null ? 'Nilai' : 'Lihat' ?></a></td></tr>
    <?php endforeach; ?>
    </tbody></table></div></div>
<?php
// ======================================================================= One review
elseif ($p === 'review'):
    $cycle = q('SELECT * FROM web_review_cycle WHERE id = ?', [(int)get('cycle')])->fetch();
    $r = $cycle ? q('SELECT r.*, p.pegawai_nama, p.pegawai_pin FROM web_review r JOIN pegawai p ON p.pegawai_id = r.pegawai_id WHERE r.cycle_id = ? AND r.pegawai_id = ?', [$cycle['id'], (int)get('id')])->fetch() : null;
    if (!$r) redirect('talent.php?p=reviews');
    $crit = json_decode($cycle['criteria'], true);
    $self = $r['self_scores'] ? json_decode($r['self_scores'], true) : [];
    $mgr = $r['mgr_scores'] ? json_decode($r['mgr_scores'], true) : [];
    $att = review_attendance($r['pegawai_id'], $cycle['period_from'], $cycle['period_to']);
    hris_header($r['pegawai_nama'], 'performance', 'talent.php?p=reviews', '<a href="talent.php?p=cycle&id=' . (int)$cycle['id'] . '">' . h($cycle['name']) . '</a>'); ?>
  <div class="summary"><div class="head">Kehadiran <?= h(fmt_date($cycle['period_from'])) ?> – <?= h(fmt_date(min($cycle['period_to'], date('Y-m-d')))) ?> (referensi)</div><div class="cells">
    <div><span>Hadir / hari kerja</span><b><?= $att['present'] ?> / <?= $att['workdays'] ?></b></div><div><span>Terlambat</span><b><?= $att['late'] ?>× · <?= fmt_minutes($att['late_min']) ?></b></div>
    <div><span>Tidak hadir tanpa izin</span><b><?= $att['absent'] ?></b></div><div><span>Hari cuti / izin</span><b><?= $att['leave'] ?></b></div></div></div>
  <form method="post" class="section" style="max-width:none"><?= $csrf ?><input type="hidden" name="action" value="review_save"><input type="hidden" name="cycle_id" value="<?= (int)$cycle['id'] ?>">
    <input type="hidden" name="pegawai_id" value="<?= (int)$r['pegawai_id'] ?>"><input type="hidden" name="back" value="<?= $back ?>">
    <table><thead><tr><th>Kriteria</th><th class="num">Bobot</th><th>Penilaian diri</th><th>Nilai HR</th></tr></thead><tbody>
    <?php foreach ($crit as $i => $c): ?><tr><td><?= h($c['name']) ?></td><td class="num"><?= (int)$c['weight'] ?>%</td>
      <td><?= isset($self[$i]) ? (int)$self[$i] . ' · ' . h(score_scale()[(int)$self[$i]]) : '<span class="muted">—</span>' ?></td>
      <td><select name="score[<?= $i ?>]" required><option value="">Pilih…</option><?php foreach (score_scale() as $v => $label): ?><option value="<?= $v ?>" <?= isset($mgr[$i]) && (int)$mgr[$i] === $v ? 'selected' : '' ?>><?= $v ?> · <?= $label ?></option><?php endforeach; ?></select></td></tr>
    <?php endforeach; ?></tbody></table>
    <?php if ($r['self_note']): ?><div class="field"><span>Catatan karyawan</span><div class="notice"><div><?= nl2br(h($r['self_note'])) ?></div></div></div><?php endif; ?>
    <label class="field"><span>Catatan HR / atasan</span><textarea name="note"><?= h($r['mgr_note']) ?></textarea></label>
    <div class="formfoot"><?php if ($r['final_score'] !== null): ?><span class="muted" style="margin-right:auto">Nilai akhir <b><?= number_format($r['final_score'], 2) ?></b> (<?= h(rating_label((float)$r['final_score'])) ?>) · <?= h($r['mgr_by']) ?>, <?= h(date('d-m-Y', strtotime($r['mgr_at']))) ?></span><?php endif; ?>
      <button class="btn primary">Simpan penilaian</button></div>
  </form>
<?php
else:
    redirect('talent.php');
endif;
hris_footer();
