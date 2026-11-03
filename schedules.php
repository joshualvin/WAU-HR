<?php
// Work schedules (jadwal kerja): which work hours apply on each day of a repeating period, and who follows it. Admins only.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/portal.php';
require __DIR__ . '/inc/hris.php';
require_login('admin');

$all = schedules();
$hours = work_hours();
$hourOpts = [];
foreach ($hours as $w) $hourOpts[$w['jk_id']] = $w['jk_name'] . ' (' . min_to_hm($w['start']) . '–' . min_to_hm($w['end']) . ')';
$id = (int)get('id', post('id', 0));
$edit = $id && isset($all[$id]) ? $all[$id] : null;
$dayNames = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'];

function day_label($m, $idx, $dayNames)
{
    if ((int)$m['jdw_kerja_m_periode'] === 7) {
        return $dayNames[((int)date('N', strtotime($m['jdw_kerja_m_mulai'])) - 1 + $idx - 1) % 7];
    }
    return 'Hari ' . $idx;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = post('action');
    if ($action === 'save') {
        $period = (int)post('periode');
        $start = post('mulai');
        if (post('name') === '') $error = 'Isi nama.';
        elseif ($period < 1 || $period > 62) $error = 'Periode 1 sampai 62 hari.';
        elseif (!valid_date($start)) $error = 'Isi tanggal mulai.';
        else {
            $pdo = db();
            $pdo->beginTransaction();
            try {
                $m = [post('kode'), post('name'), post('ket'), $period, $start];
                if ($edit) {
                    q('UPDATE jdw_kerja_m SET jdw_kerja_m_kode = ?, jdw_kerja_m_name = ?, jdw_kerja_m_keterangan = ?, jdw_kerja_m_periode = ?, jdw_kerja_m_mulai = ? WHERE jdw_kerja_m_id = ?',
                        array_merge($m, [$id]));
                    q('DELETE FROM jdw_kerja_d WHERE jdw_kerja_m_id = ? AND jdw_kerja_d_idx < 999', [$id]);
                } else {
                    $id = (int)q('SELECT COALESCE(MAX(jdw_kerja_m_id), 0) + 1 FROM jdw_kerja_m')->fetchColumn();
                    q('INSERT INTO jdw_kerja_m (jdw_kerja_m_id, jdw_kerja_m_kode, jdw_kerja_m_name, jdw_kerja_m_keterangan, jdw_kerja_m_periode, jdw_kerja_m_mulai, jdw_kerja_m_type, use_sama)
                       VALUES (?, ?, ?, ?, ?, ?, 0, -1)', array_merge([$id], $m));
                    q("INSERT INTO jdw_kerja_d (jdw_kerja_m_id, jdw_kerja_d_idx, jk_id, jdw_kerja_d_hari, jdw_kerja_d_libur) VALUES (?, 999, 0, 'Libur Umum', -1)", [$id]);
                }
                $tmp = ['jdw_kerja_m_periode' => $period, 'jdw_kerja_m_mulai' => $start];
                for ($i = 1; $i <= $period; $i++) {
                    $jk = (int)post("day_$i");
                    q('INSERT INTO jdw_kerja_d (jdw_kerja_m_id, jdw_kerja_d_idx, jk_id, jdw_kerja_d_hari, jdw_kerja_d_libur) VALUES (?, ?, ?, ?, ?)',
                        [$id, $i, $jk, day_label($tmp, $i, $dayNames), $jk ? 0 : -1]);
                }
                $pdo->commit();
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            audit('schedule_save', $id . ' ' . post('name'));
            flash('Jadwal disimpan.');
            redirect('schedules.php?id=' . $id . '#form');
        }
    } elseif ($action === 'assign') {
        $from = post('from');
        $emps = isset($_POST['emp']) ? array_map('intval', (array)$_POST['emp']) : [];
        if (!$edit) $error = 'Pilih jadwal.';
        elseif (!valid_date($from)) $error = 'Isi tanggal berlaku.';
        elseif (!$emps) $error = 'Pilih minimal satu pegawai.';
        else {
            foreach ($emps as $e) {
                q('INSERT IGNORE INTO jdw_kerja_pegawai (pegawai_id, jdw_kerja_m_id, jdw_kerja_m_mulai) VALUES (?, ?, ?)', [$e, $id, $from]);
            }
            audit('schedule_assign', "$id from $from: " . implode(',', $emps));
            flash(count($emps) . ' employee(s) follow this schedule from ' . fmt_date($from) . '.');
            redirect('schedules.php?id=' . $id . '#assign');
        }
    } elseif ($action === 'unassign') {
        q('DELETE FROM jdw_kerja_pegawai WHERE pegawai_id = ? AND jdw_kerja_m_id = ? AND jdw_kerja_m_mulai = ?', [(int)post('pegawai_id'), $id, post('mulai')]);
        audit('schedule_unassign', "$id " . post('pegawai_id') . ' ' . post('mulai'));
        flash('Pegawai dihapus dari jadwal.');
        redirect('schedules.php?id=' . $id . '#assign');
    } elseif ($action === 'delete') {
        if (q('SELECT COUNT(*) FROM jdw_kerja_pegawai WHERE jdw_kerja_m_id = ?', [$id])->fetchColumn()) $error = 'Masih ada pegawai di jadwal ini. Hapus dulu.';
        else {
            q('DELETE FROM jdw_kerja_d WHERE jdw_kerja_m_id = ?', [$id]);
            q('DELETE FROM jdw_kerja_m WHERE jdw_kerja_m_id = ?', [$id]);
            audit('schedule_delete', (string)$id);
            flash('Jadwal dihapus.');
            redirect('schedules.php');
        }
    }
}

$counts = q('SELECT jdw_kerja_m_id, COUNT(DISTINCT pegawai_id) FROM jdw_kerja_pegawai GROUP BY jdw_kerja_m_id')->fetchAll(PDO::FETCH_KEY_PAIR);
$form = $edit ?: ['jdw_kerja_m_kode' => '', 'jdw_kerja_m_name' => '', 'jdw_kerja_m_keterangan' => '', 'jdw_kerja_m_periode' => 7,
                  'jdw_kerja_m_mulai' => date('Y-m-d', strtotime('monday this week')), 'days' => []];

hris_header('Jadwal Kerja', 'settings', 'settings.php', '<a href="settings.php">Pengaturan</a>');
?>
<div class="page-head">
  <h1>Jadwal Kerja</h1>
  <a class="btn primary" href="schedules.php?new=1#form">+ Tambah</a>
</div>
<?php if ($error): ?><div class="flash err"><?= h($error) ?></div><?php endif; ?>
<div class="card">
  <div class="table-wrap"><table>
    <tr><th>Kode</th><th>Nama</th><th>Periode</th><th>Hari</th><th class="num">Pegawai</th><th></th></tr>
    <?php foreach ($all as $m): ?>
      <tr>
        <td><?= h($m['jdw_kerja_m_kode']) ?></td>
        <td><?= h($m['jdw_kerja_m_name']) ?></td>
        <td><?= (int)$m['jdw_kerja_m_periode'] ?> hari</td>
        <td><?php for ($i = 1; $i <= $m['jdw_kerja_m_periode']; $i++): $d = isset($m['days'][$i]) ? $m['days'][$i] : [0, true]; ?>
          <span class="tag"><?= h(substr(day_label($m, $i, $dayNames), 0, 3)) ?>: <?= $d[1] || !isset($hours[$d[0]]) ? 'off' : h($hours[$d[0]]['jk_kode'] ?: $hours[$d[0]]['jk_name']) ?></span>
        <?php endfor; ?></td>
        <td class="num"><?= isset($counts[$m['jdw_kerja_m_id']]) ? (int)$counts[$m['jdw_kerja_m_id']] : 0 ?></td>
        <td><a class="btn small" href="schedules.php?id=<?= (int)$m['jdw_kerja_m_id'] ?>#form">Ubah</a></td>
      </tr>
    <?php endforeach; ?>
  </table></div>
</div>

<?php if ($edit || get('new') || ($error && post('action') === 'save')): ?>
<div class="card" id="form" style="max-width:820px">
  <h2><?= $edit ? 'Ubah ' . h($edit['jdw_kerja_m_name']) : 'Tambah Jadwal Kerja' ?></h2>
  <form method="post" class="stack">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$id ?>"><input type="hidden" name="action" value="save">
    <div class="grid2">
      <label>Nama <input name="name" required maxlength="100" value="<?= h(post('name', $form['jdw_kerja_m_name'])) ?>"></label>
      <label>Kode <input name="kode" maxlength="5" value="<?= h(post('kode', $form['jdw_kerja_m_kode'])) ?>"></label>
      <label>Periode (hari) <input type="number" name="periode" min="1" max="62" value="<?= h(post('periode', $form['jdw_kerja_m_periode'])) ?>" onchange="this.form.querySelector('[data-hint]').hidden=false"></label>
      <label>Mulai tanggal <input type="date" name="mulai" required value="<?= h(post('mulai', $form['jdw_kerja_m_mulai'])) ?>"></label>
      <label>Catatan <input name="ket" maxlength="255" value="<?= h(post('ket', $form['jdw_kerja_m_keterangan'])) ?>"></label>
    </div>
    <p class="muted" data-hint hidden>Simpan untuk memperbarui daftar hari.</p>
    <div class="grid2">
      <?php for ($i = 1; $i <= $form['jdw_kerja_m_periode']; $i++): $d = isset($form['days'][$i]) ? $form['days'][$i] : [0, true]; ?>
        <label><?= h(day_label($form, $i, $dayNames)) ?>
          <select name="day_<?= $i ?>"><?= options($hourOpts, $d[1] ? '' : $d[0], 'Libur') ?></select></label>
      <?php endfor; ?>
    </div>
    <div>
      <button class="btn primary">Simpan</button> <a class="btn" href="schedules.php">Batal</a>
      <?php if ($edit): ?><button class="btn danger" name="action" value="delete" formnovalidate onclick="return confirm('Hapus jadwal ini?')">Hapus</button><?php endif; ?>
    </div>
  </form>
</div>
<?php endif; ?>

<?php if ($edit):
    $assigned = q('SELECT j.pegawai_id, j.jdw_kerja_m_mulai, p.pegawai_nama FROM jdw_kerja_pegawai j JOIN pegawai p ON p.pegawai_id = j.pegawai_id
                   WHERE j.jdw_kerja_m_id = ? ORDER BY p.pegawai_nama, j.jdw_kerja_m_mulai', [$id])->fetchAll(); ?>
<div class="row" id="assign">
  <div class="card">
    <h2>Pegawai di jadwal <?= h($edit['jdw_kerja_m_name']) ?></h2>
    <table>
      <tr><th>Pegawai</th><th>Dari</th><th></th></tr>
      <?php foreach ($assigned as $a): ?>
        <tr><td><?= h($a['pegawai_nama']) ?></td><td><?= h(fmt_date($a['jdw_kerja_m_mulai'])) ?></td>
          <td><form method="post" class="btn-form" onsubmit="return confirm('Hapus pegawai dari jadwal?')"><?= csrf_field() ?>
            <input type="hidden" name="action" value="unassign"><input type="hidden" name="id" value="<?= (int)$id ?>">
            <input type="hidden" name="pegawai_id" value="<?= (int)$a['pegawai_id'] ?>"><input type="hidden" name="mulai" value="<?= h($a['jdw_kerja_m_mulai']) ?>">
            <button class="btn small danger">Hapus</button></form></td></tr>
      <?php endforeach; ?>
    </table>
  </div>
  <div class="card" style="flex:0 1 360px">
    <h2>Berlakukan ke pegawai</h2>
    <form method="post" class="stack">
      <?= csrf_field() ?><input type="hidden" name="action" value="assign"><input type="hidden" name="id" value="<?= (int)$id ?>">
      <label>Berlaku mulai <input type="date" name="from" required value="<?= date('Y-m-d') ?>"></label>
      <div style="max-height:260px;overflow:auto;border:1px solid var(--line);border-radius:6px;padding:8px">
        <?php foreach (employees(true) as $e): ?>
          <label class="check"><input type="checkbox" name="emp[]" value="<?= (int)$e['pegawai_id'] ?>"> <?= h($e['pegawai_nama']) ?></label>
        <?php endforeach; ?>
      </div>
      <button class="btn primary">Terapkan</button>
    </form>
  </div>
</div>
<?php endif; ?>
<?php hris_footer();
