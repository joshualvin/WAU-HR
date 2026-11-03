<?php
// HR → Penggajian → Alokasi biaya gaji: what a saved payroll period costs, by department, branch or job position. Admins only.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/portal.php';
require __DIR__ . '/inc/hris.php';
require __DIR__ . '/inc/payroll.php';
require __DIR__ . '/inc/payalloc.php';
$me = require_login('admin');
ensure_payroll_tables();

$by = ['pembagian2_id' => ['Departemen', 'pembagian2'], 'pembagian3_id' => ['Kantor', 'pembagian3'], 'pembagian1_id' => ['Jabatan', 'pembagian1']];
$group = isset($by[get('by')]) ? get('by') : 'pembagian2_id';
$periods = q('SELECT period_from, period_to, COUNT(*) n, MAX(published) pub FROM web_payslip GROUP BY period_from, period_to ORDER BY period_to DESC')->fetchAll();
$to = get('to') !== '' ? get('to') : ($periods ? $periods[0]['period_to'] : '');
$names = lookup($by[$group][1]);
$slips = [];
if ($to) {
    foreach (q("SELECT s.detail, p.$group gid FROM web_payslip s JOIN pegawai p ON p.pegawai_id = s.pegawai_id WHERE s.period_to = ?", [$to]) as $r) {
        $slips[] = ['group' => isset($names[$r['gid']]) ? $names[$r['gid']] : '', 'detail' => json_decode($r['detail'], true)];
    }
}
$rows = alloc_group($slips);
$sum = array_fill_keys(['n', 'salary', 'bpjs', 'reimb', 'total', 'pph', 'net'], 0);
foreach ($rows as $r) foreach ($sum as $k => $_) $sum[$k] += $r[$k];

if (get('csv') === '1') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="alokasi_gaji_' . strtolower($by[$group][0]) . '_' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, [$by[$group][0], 'Karyawan', 'Penghasilan', 'BPJS perusahaan', 'Reimbursement', 'Total biaya', '% biaya', 'PPh 21', 'Take-home pay']);
    foreach ($rows as $label => $r) fputcsv($out, [$label, $r['n'], $r['salary'], $r['bpjs'], $r['reimb'], $r['total'], $sum['total'] ? round(100 * $r['total'] / $sum['total'], 1) : 0, $r['pph'], $r['net']]);
    fputcsv($out, ['TOTAL', $sum['n'], $sum['salary'], $sum['bpjs'], $sum['reimb'], $sum['total'], 100, $sum['pph'], $sum['net']]);
    audit('payroll_allocation_export', $to . ' ' . $by[$group][0]);
    exit;
}

hris_header('Alokasi biaya gaji', 'payroll', 'payalloc.php', 'Penggajian', $rows ? '<a class="btn" href="payalloc.php?' . h(http_build_query(['to' => $to, 'by' => $group, 'csv' => 1])) . '">Ekspor CSV</a>' : ''); ?>
  <form class="toolbar" method="get">
    <label class="muted">Periode <select name="to" onchange="this.form.submit()"><?php foreach ($periods as $p): ?><option value="<?= h($p['period_to']) ?>"<?= $to === $p['period_to'] ? ' selected' : '' ?>><?= h(date('d-m-Y', strtotime($p['period_from']))) ?> – <?= h(date('d-m-Y', strtotime($p['period_to']))) ?><?= $p['pub'] ? '' : ' (draf)' ?></option><?php endforeach; ?></select></label>
    <label class="muted">Kelompokkan menurut <select name="by" onchange="this.form.submit()"><?php foreach ($by as $k => $b): ?><option value="<?= $k ?>"<?= $group === $k ? ' selected' : '' ?>><?= h($b[0]) ?></option><?php endforeach; ?></select></label>
    <noscript><button class="btn">Tampilkan</button></noscript></form>
  <?php if (!$rows): ?>
    <div class="card"><div class="empty"><strong>Belum ada gaji yang disimpan</strong>Hitung dan simpan di <a href="payroll.php?p=run">Proses gaji</a>, lalu alokasinya muncul di sini.</div></div>
  <?php else: ?>
  <div class="counters" style="grid-template-columns:repeat(4,minmax(0,1fr))">
    <div class="counter"><b><?= h(rupiah($sum['total'])) ?></b><span>Total biaya perusahaan</span></div>
    <div class="counter"><b><?= h(rupiah($sum['salary'])) ?></b><span>Penghasilan karyawan</span></div>
    <div class="counter"><b><?= h(rupiah($sum['bpjs'])) ?></b><span>BPJS perusahaan</span></div>
    <div class="counter"><b><?= (int)$sum['n'] ?></b><span>Karyawan</span></div>
  </div>
  <div class="tablecard"><div class="tablescroll"><table>
    <thead><tr><th><?= h($by[$group][0]) ?></th><th class="num">Karyawan</th><th class="num">Penghasilan</th><th class="num">BPJS perusahaan</th><th class="num">Reimbursement</th><th class="num">Total biaya</th><th style="min-width:140px">Porsi biaya</th><th class="num">PPh 21</th><th class="num">Take-home pay</th></tr></thead><tbody>
    <?php foreach ($rows as $label => $r): $share = $sum['total'] ? 100 * $r['total'] / $sum['total'] : 0; ?>
      <tr><td><b><?= h($label) ?></b></td><td class="num"><?= (int)$r['n'] ?></td><td class="num"><?= h(rupiah($r['salary'])) ?></td><td class="num"><?= h(rupiah($r['bpjs'])) ?></td><td class="num"><?= $r['reimb'] ? h(rupiah($r['reimb'])) : '' ?></td>
        <td class="num"><b><?= h(rupiah($r['total'])) ?></b></td>
        <td><div class="stack" style="height:8px"><i style="width:<?= round($share, 1) ?>%;background:var(--primary)"></i></div><small class="muted"><?= number_format($share, 1, ',', '.') ?>%</small></td>
        <td class="num"><?= h(rupiah($r['pph'])) ?></td><td class="num"><?= h(rupiah($r['net'])) ?></td></tr>
    <?php endforeach; ?>
      <tr><td><b>Total</b></td><td class="num"><b><?= (int)$sum['n'] ?></b></td><td class="num"><b><?= h(rupiah($sum['salary'])) ?></b></td><td class="num"><b><?= h(rupiah($sum['bpjs'])) ?></b></td><td class="num"><b><?= $sum['reimb'] ? h(rupiah($sum['reimb'])) : '' ?></b></td><td class="num"><b><?= h(rupiah($sum['total'])) ?></b></td><td></td><td class="num"><b><?= h(rupiah($sum['pph'])) ?></b></td><td class="num"><b><?= h(rupiah($sum['net'])) ?></b></td></tr>
    </tbody></table></div></div>
  <p class="muted">Biaya = penghasilan (setelah potongan tidak hadir / terlambat) + BPJS yang dibayar perusahaan + reimbursement. PPh 21 dan iuran BPJS karyawan dipotong dari gaji karyawan, jadi bukan biaya tambahan; pengembalian kelebihan PPh 21 tidak dihitung sebagai biaya.</p>
  <?php endif; ?>
<?php hris_footer();
