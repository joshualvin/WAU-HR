<?php
// Annual leave quota (jatah cuti) per employee, with leave taken counted from approved "Cuti pribadi" (type 90).
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
$me = require_login();

$year = (int)get('year', post('year', date('Y')));
$pStart = "$year-01-01";
$pEnd = "$year-12-31";

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    if ($me['role'] !== 'admin') { http_response_code(403); exit('Hanya admin yang dapat mengubah jatah cuti.'); }
    if (post('action') === 'generate') {
        $n = (int)post('days');
        $added = 0;
        foreach (employees(true) as $e) {
            $added += q('INSERT IGNORE INTO jatah_cuti (pegawai_id, jatah_c_mulai, jatah_c_akhir, jatah_c_jml, jatah_c_hak_jml, jatah_c_ambil_jml, jatah_c_utang_jml)
                         VALUES (?, ?, ?, ?, ?, 0, 0)', [$e['pegawai_id'], $pStart, $pEnd, $n, $n])->rowCount();
        }
        audit('quota_generate', "$year $n days, $added employees");
        flash("$year quota of $n days added for $added employee(s). Employees who already had one were left as they were.");
    } elseif (post('action') === 'save') {
        foreach (isset($_POST['q']) ? (array)$_POST['q'] : [] as $pid => $val) {
            if ($val === '' || !ctype_digit((string)$val)) continue;
            q('INSERT INTO jatah_cuti (pegawai_id, jatah_c_mulai, jatah_c_akhir, jatah_c_jml, jatah_c_hak_jml, jatah_c_ambil_jml, jatah_c_utang_jml)
               VALUES (?, ?, ?, ?, ?, 0, 0) ON DUPLICATE KEY UPDATE jatah_c_jml = VALUES(jatah_c_jml), jatah_c_hak_jml = VALUES(jatah_c_hak_jml)',
                [(int)$pid, $pStart, $pEnd, (int)$val, (int)$val]);
        }
        audit('quota_save', (string)$year);
        flash('Jatah cuti disimpan.');
    }
    redirect("quota.php?year=$year");
}

$quota = [];
foreach (q('SELECT * FROM jatah_cuti WHERE jatah_c_mulai <= ? AND jatah_c_akhir >= ?', [$pEnd, $pStart]) as $r) $quota[$r['pegawai_id']] = $r;
$taken = q('SELECT pegawai_id, COUNT(*) FROM izin WHERE izin_status = 1 AND izin_jenis_id = 90 AND izin_tgl BETWEEN ? AND ? GROUP BY pegawai_id', [$pStart, $pEnd])->fetchAll(PDO::FETCH_KEY_PAIR);
$pending = q('SELECT pegawai_id, COUNT(*) FROM izin WHERE izin_status = 0 AND izin_jenis_id = 90 AND izin_tgl BETWEEN ? AND ? GROUP BY pegawai_id', [$pStart, $pEnd])->fetchAll(PDO::FETCH_KEY_PAIR);
$normatif = q('SELECT pegawai_id, COUNT(*) FROM izin WHERE izin_status = 1 AND izin_jenis_id = 80 AND izin_tgl BETWEEN ? AND ? GROUP BY pegawai_id', [$pStart, $pEnd])->fetchAll(PDO::FETCH_KEY_PAIR);

page_header('Jatah Cuti', 'leave.php');
?>
<div class="page-head">
  <h1>Jatah Cuti <?= $year ?> (jatah cuti)</h1>
  <div><a class="btn small" href="?year=<?= $year - 1 ?>">‹ <?= $year - 1 ?></a> <a class="btn small" href="?year=<?= $year + 1 ?>"><?= $year + 1 ?> ›</a> <a class="btn small" href="leave.php">Daftar izin</a></div>
</div>
<div class="card">
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="year" value="<?= $year ?>"><input type="hidden" name="action" value="save">
    <div class="table-wrap"><table>
      <tr><th>Pegawai</th><th class="num">Jatah (hari)</th><th class="num">Diambil (Cuti pribadi)</th><th class="num">Menunggu</th><th class="num">Sisa</th><th class="num">Cuti normatif diambil</th><th></th></tr>
      <?php foreach (employees(true) as $e): $pid = $e['pegawai_id']; $qd = isset($quota[$pid]) ? (int)$quota[$pid]['jatah_c_jml'] : null; $t = isset($taken[$pid]) ? (int)$taken[$pid] : 0; ?>
        <tr>
          <td><?= h($e['pegawai_nama']) ?></td>
          <td class="num"><?php if ($me['role'] === 'admin'): ?><input type="number" min="0" name="q[<?= (int)$pid ?>]" value="<?= $qd === null ? '' : $qd ?>" style="width:70px;text-align:right" placeholder="—"><?php else: ?><?= $qd === null ? '—' : $qd ?><?php endif; ?></td>
          <td class="num"><?= $t ?: '' ?></td>
          <td class="num"><?= isset($pending[$pid]) ? (int)$pending[$pid] : '' ?></td>
          <td class="num"><?php if ($qd !== null): $rem = $qd - $t; ?><span class="badge <?= $rem < 0 ? 'b-absent' : ($rem <= 2 ? 'b-incomplete' : 'b-ok') ?>"><?= $rem ?></span><?php endif; ?></td>
          <td class="num"><?= isset($normatif[$pid]) ? (int)$normatif[$pid] : '' ?></td>
          <td><a class="btn small" href="leave.php?<?= h(http_build_query(['pegawai_id' => $pid, 'from' => $pStart, 'to' => $pEnd, 'kind' => 'leave'])) ?>">Izin ›</a></td>
        </tr>
      <?php endforeach; ?>
    </table></div>
    <?php if ($me['role'] === 'admin'): ?><p><button class="btn primary">Simpan</button></p><?php endif; ?>
  </form>
</div>
<?php if ($me['role'] === 'admin'): ?>
<div class="card" style="max-width:520px">
  <h2>Jatah cuti semua pegawai tahun <?= $year ?></h2>
  <form method="post" class="inline">
    <?= csrf_field() ?><input type="hidden" name="year" value="<?= $year ?>"><input type="hidden" name="action" value="generate">
    <label>Hari <input type="number" name="days" min="0" value="12" style="width:80px"></label>
    <button class="btn">Tambahkan untuk pegawai yang belum punya</button>
  </form>
</div>
<?php endif; ?>
<?php page_footer();
