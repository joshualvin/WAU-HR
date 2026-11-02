<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
$me = require_login();

$status = get('status', '1');
$search = get('q');
$where = [];
$params = [];
if ($status !== '') { $where[] = 'p.pegawai_status = ?'; $params[] = (int)$status; }
if ($search !== '') {
    $where[] = '(p.pegawai_nama LIKE ? OR p.pegawai_pin LIKE ? OR p.pegawai_nip LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like);
}
$rows = q('SELECT p.pegawai_id, p.pegawai_pin, p.pegawai_nip, p.pegawai_nama, p.pegawai_status, p.tgl_mulai_kerja, p.tgl_resign,
                  p1.pembagian1_nama, p2.pembagian2_nama, p3.pembagian3_nama,
                  (SELECT MAX(a.scan_date) FROM att_log a WHERE a.pin = p.pegawai_pin) last_scan
           FROM pegawai p
           LEFT JOIN pembagian1 p1 ON p1.pembagian1_id = p.pembagian1_id
           LEFT JOIN pembagian2 p2 ON p2.pembagian2_id = p.pembagian2_id
           LEFT JOIN pembagian3 p3 ON p3.pembagian3_id = p.pembagian3_id' .
           ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY p.pegawai_nama', $params)->fetchAll();
$statusNames = ['1' => 'Aktif', '0' => 'Non aktif', '2' => 'Berhenti'];

page_header('Pegawai', 'employees.php');
?>
<div class="page-head">
  <h1>Pegawai</h1>
  <?php if ($me['role'] === 'admin'): ?><a class="btn primary" href="employee_edit.php">+ Tambah Pegawai</a><?php endif; ?>
</div>
<div class="card">
  <form class="inline" method="get">
    <label>Cari <input name="q" value="<?= h($search) ?>" placeholder="Nama, PIN atau NIP"></label>
    <label>Status <select name="status"><?= options($statusNames, $status, 'Semua') ?></select></label>
    <button class="btn">Cari</button>
  </form>
</div>
<div class="card">
  <p class="muted"><?= count($rows) ?> pegawai</p>
  <div class="table-wrap"><table>
    <tr><th>PIN</th><th>NIP</th><th>Nama</th><th>Jabatan</th><th>Departemen</th><th>Kantor</th><th>Mulai kerja</th><th>Scan terakhir</th><th>Status</th><th></th></tr>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= h($r['pegawai_pin']) ?></td>
        <td><?= h($r['pegawai_nip']) ?></td>
        <td><?= h($r['pegawai_nama']) ?></td>
        <td><?= h($r['pembagian1_nama']) ?></td>
        <td><?= h($r['pembagian2_nama']) ?></td>
        <td><?= h($r['pembagian3_nama']) ?></td>
        <td><?= $r['tgl_mulai_kerja'] ? date('d-m-Y', strtotime($r['tgl_mulai_kerja'])) : '' ?></td>
        <td><?= $r['last_scan'] ? h(date('d-m-Y H:i', strtotime($r['last_scan']))) : '<span class="muted">belum pernah</span>' ?></td>
        <td><?php $b = ['1' => 'ok', '0' => 'off', '2' => 'absent']; ?><span class="badge b-<?= $b[$r['pegawai_status']] ?? 'off' ?>"><?= h($statusNames[$r['pegawai_status']] ?? $r['pegawai_status']) ?></span>
          <?= $r['tgl_resign'] ? '<span class="tag">until ' . h($r['tgl_resign']) . '</span>' : '' ?></td>
        <td style="white-space:nowrap">
          <a class="btn small" href="report.php?tab=harian&pegawai_id=<?= (int)$r['pegawai_id'] ?>">Absensi</a>
          <?php if ($me['role'] === 'admin'): ?><a class="btn small" href="employee_edit.php?id=<?= (int)$r['pegawai_id'] ?>">Ubah</a><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="10" class="muted">Tidak ada pegawai.</td></tr><?php endif; ?>
  </table></div>
</div>
<?php page_footer();
