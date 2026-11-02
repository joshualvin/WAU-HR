<?php
// One payslip (slip gaji), printable. Admins see any saved slip; an employee signed in to the Portal Pegawai
// sees only their own published slips.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/portal.php';
require __DIR__ . '/inc/payroll.php';
ensure_payroll_tables();

$to = get('to');
$id = (int)get('id');
$admin = current_user() && current_user()['role'] === 'admin';
$emp = current_emp();
if (!$admin && (!$emp || $emp['id'] !== $id)) { http_response_code(403); exit('Tidak boleh membuka slip ini.'); }
$row = q('SELECT * FROM web_payslip WHERE period_to = ? AND pegawai_id = ?' . ($admin ? '' : ' AND published = 1'), [$to, $id])->fetch();
if (!$row) { http_response_code(404); exit('Slip gaji tidak ditemukan.'); }
$l = json_decode($row['detail'], true);
$e = q('SELECT p.*, p1.pembagian1_nama jab, p2.pembagian2_nama dep FROM pegawai p LEFT JOIN pembagian1 p1 ON p1.pembagian1_id = p.pembagian1_id
        LEFT JOIN pembagian2 p2 ON p2.pembagian2_id = p.pembagian2_id WHERE p.pegawai_id = ?', [$id])->fetch();
$company = isset($CONFIG['company']) ? $CONFIG['company'] : 'Perusahaan';
$earn = array_sum($l['earnings']);
$ded = array_sum($l['deductions']);
?><!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Slip gaji <?= h($e['pegawai_nama']) ?> <?= h($to) ?></title>
<style>
  body { font: 13px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: #232933; background: #f6f7f9; margin: 0; padding: 24px 12px; }
  .slip { max-width: 760px; margin: 0 auto; background: #fff; border: 1px solid #e2e5ea; border-radius: 8px; padding: 28px 32px; }
  h1 { font-size: 18px; margin: 0; } h2 { font-size: 13px; margin: 18px 0 6px; text-transform: uppercase; letter-spacing: .06em; color: #626b79; }
  .head { display: flex; justify-content: space-between; gap: 16px; border-bottom: 2px solid #232933; padding-bottom: 12px; flex-wrap: wrap; }
  .muted { color: #626b79; } table { width: 100%; border-collapse: collapse; } td { padding: 5px 0; border-bottom: 1px solid #eef1f6; } td.n { text-align: right; font-variant-numeric: tabular-nums; }
  .cols { display: grid; grid-template-columns: 1fr 1fr; gap: 28px; } .tot td { font-weight: 700; border-bottom: none; }
  .net { margin-top: 18px; padding: 14px 16px; background: #eef1f6; border-radius: 6px; display: flex; justify-content: space-between; font-size: 16px; font-weight: 700; }
  .btn { display: inline-block; margin: 0 auto 12px; padding: 7px 14px; border: 1px solid #cfd4dc; border-radius: 6px; background: #fff; cursor: pointer; font: inherit; }
  @media (max-width: 640px) { .cols { grid-template-columns: 1fr; } .slip { padding: 18px; } }
  @media print { body { background: #fff; padding: 0; } .slip { border: none; } .noprint { display: none; } }
</style>
</head>
<body>
<div class="noprint" style="text-align:center"><button class="btn" onclick="window.print()">Cetak / simpan PDF</button></div>
<div class="slip">
  <div class="head">
    <div><h1><?= h($company) ?></h1><div class="muted">Slip gaji · <?= h(date('d-m-Y', strtotime($row['period_from']))) ?> s/d <?= h(date('d-m-Y', strtotime($row['period_to']))) ?></div></div>
    <div style="text-align:right"><b><?= h($e['pegawai_nama']) ?></b><div class="muted">PIN <?= h($e['pegawai_pin']) ?> · <?= h(trim($e['jab'] . ' · ' . $e['dep'], ' ·')) ?></div>
      <div class="muted">PTKP <?= h($l['ptkp']) ?><?= $l['npwp'] ? ' · NPWP/NIK ' . h($l['npwp']) : '' ?></div></div>
  </div>
  <p class="muted">Kehadiran: <?= (int)$l['counts']['present'] ?> dari <?= (int)$l['counts']['workdays'] ?> hari kerja · terlambat <?= (int)$l['counts']['late_min'] ?> menit ·
    lembur <?= fmt_minutes($l['counts']['ot_min'] + $l['counts']['ot_rest_min']) ?> jam</p>
  <div class="cols">
    <div><h2>Pendapatan</h2><table>
      <?php foreach ($l['earnings'] as $k => $v): ?><tr><td><?= h($k) ?></td><td class="n"><?= rupiah($v) ?></td></tr><?php endforeach; ?>
      <tr class="tot"><td>Total pendapatan</td><td class="n"><?= rupiah($earn) ?></td></tr></table></div>
    <div><h2>Potongan</h2><table>
      <?php foreach ($l['deductions'] as $k => $v): ?><tr><td><?= h($k) ?></td><td class="n"><?= rupiah($v) ?></td></tr><?php endforeach; ?>
      <tr class="tot"><td>Total potongan</td><td class="n"><?= rupiah($ded) ?></td></tr></table></div>
  </div>
  <div class="net"><span>Take home pay</span><span><?= rupiah($row['netto']) ?></span></div>
  <h2>Dibayar perusahaan (tidak dipotong dari gaji)</h2><table>
    <?php foreach ($l['company'] as $k => $v) if ($v): ?><tr><td><?= h($k) ?></td><td class="n"><?= rupiah($v) ?></td></tr><?php endif; ?></table>
  <p class="muted">Penghasilan bruto untuk PPh 21: <?= rupiah($row['bruto']) ?> · <?= h($l['pph21_note']) ?></p>
</div>
</body>
</html>
