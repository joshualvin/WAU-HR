<?php
// HR → Waktu → On call: the standby roster. A month grid; admins assign people over a date range and remove single days.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/portal.php';
require __DIR__ . '/inc/hris.php';
require __DIR__ . '/inc/people.php';
require __DIR__ . '/inc/company.php';
require __DIR__ . '/inc/oncall.php';
$me = require_login();
ensure_web_tables();
ensure_oncall_tables();
$isAdmin = $me['role'] === 'admin';
$csrf = csrf_field();

$today = date('Y-m-d');
$first = calendar_month(get('ym'), $today);
$last = date('Y-m-t', strtotime($first));
$ym = substr($first, 0, 7);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAdmin) {
    check_csrf();
    if (post('action') === 'assign') {
        $ids = array_values(array_unique(array_map('intval', isset($_POST['emp']) ? (array)$_POST['emp'] : [])));
        $err = oncall_validate($ids, post('from'), post('to'));
        if ($err) flash($err, 'err');
        else {
            $n = oncall_assign($ids, post('from'), post('to'), post('note'), actor(), post('skip_sunday') === '1');
            audit('oncall_assign', count($ids) . ' orang, ' . post('from') . ' s/d ' . post('to') . ": $n hari");
            flash($n ? "$n hari jaga ditambahkan." : 'Semua hari itu sudah terjadwal.');
        }
    } elseif (post('action') === 'remove') {
        $r = q('SELECT o.day, p.pegawai_nama FROM web_oncall o JOIN pegawai p ON p.pegawai_id = o.pegawai_id WHERE o.id = ?', [(int)post('id')])->fetch();
        if ($r) { q('DELETE FROM web_oncall WHERE id = ?', [(int)post('id')]); audit('oncall_remove', $r['pegawai_nama'] . ' ' . $r['day']); }
    }
    redirect('oncall.php?ym=' . urlencode($ym));
}

$roster = oncall_month($first, $last);
$months = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
$title = $months[(int)date('n', strtotime($first))] . ' ' . date('Y', strtotime($first));
$people = employees(true);
$perPerson = [];
foreach ($roster as $day) foreach ($day as $r) $perPerson[$r['name']] = (isset($perPerson[$r['name']]) ? $perPerson[$r['name']] : 0) + 1;
arsort($perPerson);

hris_header('On call', 'time', 'oncall.php', 'Waktu', '<a class="btn" href="oncall.php">Bulan ini</a>'); ?>
  <div class="calnav">
    <a class="btn" href="oncall.php?ym=<?= h(date('Y-m', strtotime($first . ' -1 month'))) ?>">‹</a><h2><?= h($title) ?></h2><a class="btn" href="oncall.php?ym=<?= h(date('Y-m', strtotime($first . ' +1 month'))) ?>">›</a>
  </div>
  <div class="calwrap"><table class="cal">
    <thead><tr><?php foreach (['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'] as $d): ?><th><?= $d ?></th><?php endforeach; ?></tr></thead><tbody>
    <?php foreach (calendar_weeks($first) as $week): ?><tr>
      <?php foreach ($week as $i => $d): $in = $d >= $first && $d <= $last; ?>
        <td class="<?= $in ? '' : 'out' ?><?= $d === $today ? ' today' : '' ?><?= $i === 6 ? ' sun' : '' ?>"><span class="dn"><?= (int)date('j', strtotime($d)) ?></span>
          <?php if ($in) foreach (isset($roster[$d]) ? $roster[$d] : [] as $r): ?>
            <span class="ev oc" title="<?= h($r['name'] . ($r['note'] !== '' ? ' · ' . $r['note'] : '')) ?>"><?= h($r['name']) ?>
              <?php if ($isAdmin): ?><form method="post" class="inline" style="display:inline"><?= $csrf ?><input type="hidden" name="action" value="remove"><input type="hidden" name="id" value="<?= $r['id'] ?>"><button class="x" title="Hapus hari jaga ini" aria-label="Hapus">×</button></form><?php endif; ?></span>
          <?php endforeach; ?></td>
      <?php endforeach; ?></tr><?php endforeach; ?>
    </tbody></table></div>
  <div class="row" style="margin-top:16px">
    <?php if ($isAdmin): ?>
    <form method="post" class="card" style="flex:1 1 380px"><?= $csrf ?><input type="hidden" name="action" value="assign">
      <h2>Jadwalkan jaga</h2>
      <div class="row2"><label class="field"><span>Dari</span><input type="date" name="from" required value="<?= h($first > $today ? $first : $today) ?>"></label>
        <label class="field"><span>Sampai</span><input type="date" name="to" required value="<?= h($first > $today ? $first : $today) ?>"></label></div>
      <label class="field"><span>Catatan (opsional)</span><input type="text" name="note" maxlength="120" placeholder="mis. gudang, server, sopir cadangan"></label>
      <label class="check"><input type="checkbox" name="skip_sunday" value="1"> Lewati hari Minggu</label>
      <div class="field"><span>Karyawan</span><div style="max-height:200px;overflow:auto;border:1px solid var(--line);border-radius:6px;padding:6px 8px">
        <?php foreach ($people as $e): ?><label class="check"><input type="checkbox" name="emp[]" value="<?= (int)$e['pegawai_id'] ?>"> <?= h($e['pegawai_nama']) ?></label><?php endforeach; ?></div></div>
      <div class="formfoot"><button class="btn primary">Jadwalkan</button></div></form>
    <?php endif; ?>
    <div class="card" style="flex:1 1 260px"><h2>Jumlah hari jaga bulan ini</h2><div class="list">
      <?php foreach ($perPerson as $name => $n): ?><div class="li"><div class="meta"><b><?= h($name) ?></b></div><span class="pill grey"><?= (int)$n ?> hari</span></div><?php endforeach; ?>
      <?php if (!$perPerson): ?><div class="muted">Belum ada jadwal jaga bulan ini.</div><?php endif; ?></div>
      <p class="muted">Tunjangan siaga dapat dibuat di Penggajian → Komponen gaji.</p></div>
  </div>
<?php hris_footer();
