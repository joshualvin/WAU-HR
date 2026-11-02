<?php
// Reports (Laporan), with the same report tabs as Fingerspot Personnel. Every tab can be downloaded as CSV or printed.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_login();
require __DIR__ . '/inc/reports.php';

$qs = function ($extra) use ($tab, $from, $to, $pid, $dept, $sort, $year, $jenis, $minDur) {
    return 'report.php?' . http_build_query(array_merge(['tab' => $tab, 'from' => $from, 'to' => $to, 'pegawai_id' => $pid, 'dept' => $dept, 'sort' => $sort,
        'year' => $year, 'jenis' => $jenis, 'min' => $minDur], $extra));
};
$perPage = 500;
$page = max(1, (int)get('page', 1));
$shown = array_slice($data, ($page - 1) * $perPage, $perPage);

page_header('Reports', 'report.php');
?>
<div class="card no-print" style="display:flex;gap:8px;align-items:center">
  <form class="inline" method="get" style="flex:1">
    <input type="hidden" name="tab" value="<?= h($tab) ?>">
    <?php if ($tab === 'tahunan'): ?>
      <label>Tahun <input type="number" name="year" value="<?= $year ?>" style="width:90px"></label>
    <?php else: ?>
      <label>Tanggal <input type="date" name="from" value="<?= h($from) ?>"></label>
      <label>s/d <input type="date" name="to" value="<?= h($to) ?>"></label>
    <?php endif; ?>
    <label>Pegawai <?= employee_select('pegawai_id', $pid, true, false) ?></label>
    <label>Departemen <select name="dept"><?= options(lookup('pembagian2'), $dept, 'Semua') ?></select></label>
    <label>Urut berdasarkan <select name="sort"><?= options(['pin' => 'PIN, Tanggal', 'date' => 'Tanggal, PIN'], $sort) ?></select></label>
    <?php if (in_array($tab, ['terlambat', 'pulang', 'lembur'], true)): ?>
      <label>Jenis laporan <select name="jenis"><?= options(['rekap' => 'Rekap', 'detail' => 'Detail'], $jenis) ?></select></label>
      <label>Durasi <?= ['terlambat' => 'terlambat', 'pulang' => 'pulang awal', 'lembur' => 'lembur'][$tab] ?> &gt;= <input type="number" name="min" min="1" value="<?= $minDur ?>" style="width:70px"> menit</label>
    <?php endif; ?>
    <button class="btn primary">Proses</button>
  </form>
  <a class="btn" href="<?= h($qs(['csv' => 1])) ?>">Export</a>
  <button class="btn" onclick="window.print()">Cetak</button>
</div>
<div class="tabs no-print">
  <?php foreach ($tabs as $k => $label): ?><a class="<?= $k === $tab ? 'on' : '' ?>" href="<?= h($qs(['tab' => $k, 'page' => 1])) ?>"><?= h($label) ?></a><?php endforeach; ?>
</div>

<div class="row">
  <div class="card" style="flex:3 1 600px">
    <h2><?= h($tabs[$tab]) ?> <span class="muted" style="font-weight:400">· <?= h(fmt_date($from)) ?> – <?= h(fmt_date($to)) ?> · <?= number_format(count($data)) ?> baris</span></h2>
    <div class="table-wrap"><table data-freeze="<?= ['kartushift' => 7, 'kartu' => 3, 'tahunan' => 3, 'scanlog' => 1, 'harian' => 3, 'scan1x' => 3, 'luar' => 3, 'rekap' => 3][$tab] ?? 0 ?>">
      <?php if ($groups): ?>
        <tr class="hgroup"><?php $c = 0; foreach ($groups as $g): ?><th colspan="<?= (int)$g[1] ?>" class="<?= in_array($c, $groupStart, true) ? 'gl' : '' ?>"><b><?= h($g[0]) ?></b></th><?php $c += $g[1]; endforeach; ?></tr>
      <?php endif; ?>
      <tr class="<?= $groups ? 'hsub' : '' ?>"><?php foreach ($headers as $i => $hd): ?><th class="<?= in_array($i, $groupStart, true) ? 'gl' : '' ?>"><?= h($hd) ?></th><?php endforeach; ?></tr>
      <?php foreach ($shown as $row): ?>
        <tr><?php foreach ($row as $i => $cell): ?>
          <?php if (is_array($cell) && isset($cell['miss'])): ?><td class="miss<?= in_array($i, $groupStart, true) ? ' gl' : '' ?>" title="Tidak scan"></td>
          <?php elseif (is_array($cell)): ?><td><?= status_badge($cell['status']) ?></td>
          <?php else: ?><td class="<?= in_array($i, $groupStart, true) ? 'gl ' : '' ?><?= $headers[$i] === 'Kehadiran' ? 'num ' : '' ?><?= in_array($headers[$i], ['Terlambat', 'Pulang awal'], true) || ($headers[$i] === 'Durasi' && in_array($tab, ['terlambat', 'pulang'], true)) && $cell !== '' ? 'late' : '' ?>"><?= h($cell) ?></td><?php endif; ?>
        <?php endforeach; ?></tr>
      <?php endforeach; ?>
      <?php if (!$data): ?><tr><td colspan="<?= count($headers) ?>" class="muted">Tidak ada data.</td></tr><?php endif; ?>
    </table></div>
    <?php if (count($data) > $perPage): ?>
      <div class="pager no-print">
        <?php if ($page > 1): ?><a class="btn small" href="<?= h($qs(['page' => $page - 1])) ?>">‹</a><?php endif; ?>
        <span class="muted"><?= $page ?> dari <?= (int)ceil(count($data) / $perPage) ?></span>
        <?php if ($page * $perPage < count($data)): ?><a class="btn small" href="<?= h($qs(['page' => $page + 1])) ?>">›</a><?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
  <?php if ($side): ?>
  <div style="flex:1 1 260px">
    <?php foreach ($side as $s): ?>
      <div class="card">
        <h2><?= h($s[0]) ?></h2>
        <table><tr><?php foreach ($s[1] as $hd): ?><th><?= h($hd) ?></th><?php endforeach; ?></tr>
          <?php foreach ($s[2] as $row): ?><tr><?php foreach ($row as $c): ?><td><?= h($c) ?></td><?php endforeach; ?></tr><?php endforeach; ?>
        </table>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
<?php page_footer();
