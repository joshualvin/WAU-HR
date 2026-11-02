<?php
// Working days where an employee has no scan, or only one, with buttons to fill the gap.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_login();

$from = get('from', date('Y-m-d', strtotime('-6 days')));
$to = get('to', date('Y-m-d'));
if (!valid_date($from)) $from = date('Y-m-d', strtotime('-6 days'));
if (!valid_date($to) || $to > date('Y-m-d')) $to = date('Y-m-d');
if ($from > $to) $from = $to;
if ((strtotime($to) - strtotime($from)) / 86400 > 62) $from = date('Y-m-d', strtotime($to . ' -62 days'));
$pid = get('pegawai_id');
$what = get('what', 'all');

$rows = array_filter(daily_attendance($from, $to, $pid !== '' ? (int)$pid : null), function ($r) use ($what) {
    if ($what === 'incomplete') return $r['status'] === 'incomplete';
    if ($what === 'absent') return $r['status'] === 'absent';
    return $r['status'] === 'incomplete' || $r['status'] === 'absent';
});
usort($rows, function ($a, $b) { return [$b['date'], $a['name']] <=> [$a['date'], $b['name']]; });
$back = $_SERVER['REQUEST_URI'];

function add_link($r, $jenis, $back)
{
    return 'scans.php?' . http_build_query(['add' => 1, 'pegawai_id' => $r['pegawai_id'], 'date' => $r['date'], 'jenis' => $jenis, 'back' => $back]) . '#add';
}

page_header('Scan Tidak Lengkap', 'missing.php');
?>
<div class="page-head"><h1>Scan Tidak Lengkap</h1></div>
<div class="card">
  <form class="inline" method="get">
    <label>Tanggal <input type="date" name="from" value="<?= h($from) ?>"></label>
    <label>s/d <input type="date" name="to" value="<?= h($to) ?>" max="<?= date('Y-m-d') ?>"></label>
    <label>Pegawai <?= employee_select('pegawai_id', $pid, true, false) ?></label>
    <label>Tampilkan <select name="what"><?= options(['all' => 'Scan 1x atau tidak scan', 'incomplete' => 'Scan 1x', 'absent' => 'Tidak scan sama sekali'], $what) ?></select></label>
    <button class="btn">Cari</button>
  </form>
  <p class="muted">Hanya hari kerja: hari libur jadwal, hari libur nasional dan izin yang disetujui dilewati.
    Scan 1x sebelum <?= h(isset($CONFIG['midday']) ? $CONFIG['midday'] : '12:00') ?> dianggap tidak scan pulang, setelahnya tidak scan masuk.</p>
</div>

<div class="card">
  <p class="muted"><?= count($rows) ?> hari</p>
  <div class="table-wrap"><table>
    <tr><th>Tanggal</th><th>Pegawai</th><th>Scan</th><th>Kurang</th><th></th></tr>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= h(fmt_date($r['date'])) ?></td>
        <td><?= h($r['name']) ?></td>
        <td><?= $r['first'] ? h($r['first']) : '<span class="muted">tidak ada</span>' ?></td>
        <td><?= $r['status'] === 'absent' ? status_badge('absent') . ' masuk dan pulang' : status_badge('incomplete') . ' ' . ($r['missing'] == 101 ? 'pulang' : 'masuk') ?></td>
        <td>
          <?php if ($r['status'] === 'absent' || $r['missing'] == 100): ?><a class="btn small" href="<?= h(add_link($r, 100, $back)) ?>">Tambah masuk</a><?php endif; ?>
          <?php if ($r['status'] === 'absent' || $r['missing'] == 101): ?><a class="btn small" href="<?= h(add_link($r, 101, $back)) ?>">Tambah pulang</a><?php endif; ?>
          <a class="btn small" href="leave.php?<?= h(http_build_query(['add' => 1, 'pegawai_id' => $r['pegawai_id'], 'date_from' => $r['date'], 'date_to' => $r['date']])) ?>#add">Tambah Izin</a>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="5" class="muted">Tidak ada data.</td></tr><?php endif; ?>
  </table></div>
</div>
<?php page_footer();
