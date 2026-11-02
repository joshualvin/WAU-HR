<?php
// Bukti potong PPh 21 tahunan pegawai tetap (BPA1 / 1721-A1), printable. Admins see anyone (?all=1 prints every
// employee of the year); an employee signed in to the Portal Pegawai sees their own once a payslip of that year is published.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/portal.php';
require __DIR__ . '/inc/payroll.php';
ensure_payroll_tables();

$year = (int)get('year', date('Y'));
$admin = current_user() && current_user()['role'] === 'admin';
$emp = current_emp();
if ($admin && get('all')) {
    $ids = q("SELECT pegawai_id FROM web_payslip WHERE period_to BETWEEN ? AND ? UNION SELECT s.pegawai_id FROM web_thr_slip s JOIN web_thr t ON t.id = s.thr_id WHERE t.pay_date BETWEEN ? AND ?",
             ["$year-01-01", "$year-12-31", "$year-01-01", "$year-12-31"])->fetchAll(PDO::FETCH_COLUMN);
} else {
    $id = (int)get('id');
    $mine = $emp && $emp['id'] === $id && q('SELECT COUNT(*) FROM web_payslip WHERE pegawai_id = ? AND published = 1 AND period_to BETWEEN ? AND ?', [$id, "$year-01-01", "$year-12-31"])->fetchColumn();
    if (!$admin && !$mine) { http_response_code(403); exit('Tidak boleh membuka bukti potong ini.'); }
    $ids = [$id];
}
$company = isset($CONFIG['company']) ? $CONFIG['company'] : 'Perusahaan';
$companyNpwp = isset($CONFIG['company_npwp']) ? $CONFIG['company_npwp'] : '';
$signer = isset($CONFIG['tax_signer']) ? $CONFIG['tax_signer'] : '';
$labels = [
    1 => 'Gaji / pensiun atau THT / JHT', 2 => 'Tunjangan PPh', 3 => 'Tunjangan lainnya, uang lembur dan sebagainya', 4 => 'Honorarium dan imbalan lain sejenisnya',
    5 => 'Premi asuransi yang dibayar pemberi kerja', 6 => 'Penerimaan dalam bentuk natura dan kenikmatan lainnya',
    7 => 'Tantiem, bonus, gratifikasi, jasa produksi dan THR', 8 => 'Jumlah penghasilan bruto (1 s/d 7)',
    9 => 'Biaya jabatan / biaya pensiun', 10 => 'Iuran terkait program pensiun / hari tua (JHT, JP)', 11 => 'Jumlah pengurangan (9 + 10)',
    12 => 'Jumlah penghasilan neto (8 − 11)', 13 => 'Penghasilan neto masa pajak sebelumnya', 14 => 'Jumlah penghasilan neto setahun / disetahunkan',
    15 => 'Penghasilan tidak kena pajak (PTKP)', 16 => 'Penghasilan kena pajak setahun / disetahunkan', 17 => 'PPh Pasal 21 atas PKP setahun / disetahunkan',
    18 => 'PPh Pasal 21 yang telah dipotong masa pajak sebelumnya', 19 => 'PPh Pasal 21 terutang', 20 => 'PPh Pasal 21 yang telah dipotong / dilunasi',
];
?><!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Bukti potong PPh 21 <?= $year ?></title>
<style>
  body { font: 12.5px/1.45 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: #232933; background: #f6f7f9; margin: 0; padding: 24px 12px; }
  .doc { max-width: 780px; margin: 0 auto 24px; background: #fff; border: 1px solid #e2e5ea; border-radius: 8px; padding: 26px 30px; page-break-after: always; }
  h1 { font-size: 15px; margin: 0; text-align: center; } .sub { text-align: center; color: #626b79; margin-bottom: 14px; }
  h2 { font-size: 12px; margin: 16px 0 6px; text-transform: uppercase; letter-spacing: .06em; color: #626b79; }
  table { width: 100%; border-collapse: collapse; } td { padding: 4px 6px; border-bottom: 1px solid #eef1f6; vertical-align: top; }
  td.no { width: 26px; color: #626b79; } td.n { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; } tr.sum td { font-weight: 700; background: #f6f7f9; }
  .grid { display: grid; grid-template-columns: 170px 1fr; gap: 3px 12px; } .grid div:nth-child(odd) { color: #626b79; }
  .note { margin-top: 12px; padding: 8px 10px; border-radius: 6px; background: #fff7e6; color: #8a5a00; }
  .sign { margin-top: 22px; display: flex; justify-content: flex-end; text-align: center; } .sign div { min-width: 220px; }
  .btn { display: inline-block; margin: 0 auto 12px; padding: 7px 14px; border: 1px solid #cfd4dc; border-radius: 6px; background: #fff; cursor: pointer; font: inherit; }
  @media (max-width: 640px) { .doc { padding: 16px; } .grid { grid-template-columns: 1fr; } }
  @media print { body { background: #fff; padding: 0; } .doc { border: none; margin: 0; } .noprint { display: none; } }
</style>
</head>
<body>
<div class="noprint" style="text-align:center"><button class="btn" onclick="window.print()">Cetak / simpan PDF</button></div>
<?php $shown = 0; foreach ($ids as $pid):
    $b = bpa1($pid, $year);
    if (!$b) continue;
    $shown++;
    $e = q('SELECT p.*, p1.pembagian1_nama jab FROM pegawai p LEFT JOIN pembagian1 p1 ON p1.pembagian1_id = p.pembagian1_id WHERE p.pegawai_id = ?', [$pid])->fetch();
    $l = $b['lines'];
    list($st, $dep) = explode('/', $b['ptkp']); ?>
<div class="doc">
  <h1>BUKTI PEMOTONGAN PPh PASAL 21 BAGI PEGAWAI TETAP</h1>
  <div class="sub">(BPA1 / formulir 1721-A1) · Tahun pajak <?= $year ?> · Masa perolehan <?= sprintf('%02d', $b['from']) ?> – <?= sprintf('%02d', $b['to']) ?></div>
  <h2>A. Identitas penerima penghasilan</h2>
  <div class="grid">
    <div>Nama</div><div><b><?= h($e['pegawai_nama']) ?></b></div>
    <div>NPWP / NIK</div><div><?= h($b['npwp'] ?: '—') ?></div>
    <div>Status / tanggungan (PTKP)</div><div><?= $st === 'K' ? 'Kawin' : 'Tidak kawin' ?> / <?= (int)$dep ?> (<?= h($b['ptkp']) ?>)</div>
    <div>Jabatan</div><div><?= h($e['jab'] ?: '—') ?></div>
    <div>Jenis kelamin</div><div><?= (int)$e['gender'] === 2 ? 'Perempuan' : 'Laki-laki' ?></div>
    <div>Karyawan asing</div><div>Tidak</div>
  </div>
  <h2>B. Rincian penghasilan dan penghitungan PPh Pasal 21</h2>
  <table>
    <?php foreach ($labels as $no => $label): ?>
      <?php if ($no === 9): ?><tr><td colspan="3" style="color:#626b79;font-weight:600;padding-top:10px">Pengurangan</td></tr><?php endif; ?>
      <?php if ($no === 12): ?><tr><td colspan="3" style="color:#626b79;font-weight:600;padding-top:10px">Penghitungan PPh Pasal 21</td></tr><?php endif; ?>
      <tr class="<?= in_array($no, [8, 11, 19], true) ? 'sum' : '' ?>"><td class="no"><?= $no ?></td><td><?= h($label) ?></td><td class="n"><?= rupiah($l[$no]) ?></td></tr>
    <?php endforeach; ?>
  </table>
  <?php if (!$b['final']): ?><div class="note">Sementara: gaji Desember <?= $year ?> belum disimpan, jadi angka di atas belum setahun penuh.</div>
  <?php elseif ($b['diff']): ?><div class="note">PPh terutang dan yang telah dipotong berbeda <?= rupiah($b['diff']) ?>. Periksa slip gaji Desember (hitung ulang dan simpan lagi bila ada perubahan data).</div><?php endif; ?>
  <h2>C. Identitas pemotong</h2>
  <div class="grid"><div>Nama pemotong</div><div><?= h($company) ?></div><div>NPWP pemotong</div><div><?= h($companyNpwp ?: '—') ?></div></div>
  <div class="sign"><div><?= h(fmt_date(date('Y-m-d'))) ?><br>Pemotong pajak<br><br><br><br><b><?= h($signer ?: '(............................)') ?></b></div></div>
</div>
<?php endforeach; ?>
<?php if (!$shown): ?><div class="doc">Belum ada slip gaji atau THR tersimpan untuk tahun <?= $year ?>.</div><?php endif; ?>
</body>
</html>
