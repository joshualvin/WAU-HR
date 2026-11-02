<?php
// HR → Penggajian: run payroll for a 21st–20th period, payslip history and exports, employee pay data, rates.
// Admins only: salaries are confidential.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/portal.php';
require __DIR__ . '/inc/hris.php';
require __DIR__ . '/inc/payroll.php';
$me = require_login('admin');
ensure_web_tables();
ensure_payroll_tables();

$p = get('p', 'run');
list($defFrom, $defTo) = current_period();
$to = get('to', post('to', $defTo));
if (!valid_date($to)) $to = $defTo;
// a period is named by its last day; without a start date it began on the 21st of the month before
$from = get('from', post('from', ''));
if (!valid_date($from) || $from > $to) $from = date('Y-m-21', strtotime(date('Y-m-01', strtotime($to)) . ' -1 month'));

// ---------- actions ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = post('action');
    if ($action === 'save' || $action === 'publish') {
        $rows = calculate_payroll($from, $to);
        save_payroll($rows, $action === 'publish');
        audit('payroll_' . $action, "$from..$to: " . count($rows) . ' karyawan');
        flash(count($rows) . ' slip gaji ' . ($action === 'publish' ? 'disimpan dan diterbitkan ke Portal Pegawai.' : 'disimpan sebagai draf.'));
        redirect('payroll.php?p=run&' . http_build_query(['from' => $from, 'to' => $to]));
    } elseif ($action === 'rb_approve' || $action === 'rb_reject') {
        $row = q('SELECT r.*, p.pegawai_nama FROM web_reimburse r JOIN pegawai p ON p.pegawai_id = r.pegawai_id WHERE r.id = ? AND r.status = ?', [(int)post('id'), CLAIM_PENDING])->fetch();
        if ($row) {
            q('UPDATE web_reimburse SET status = ?, decided_by = ? WHERE id = ?', [$action === 'rb_approve' ? CLAIM_APPROVED : CLAIM_REJECTED, actor(), $row['id']]);
            audit($action, "{$row['pegawai_nama']} {$row['kategori']} {$row['jumlah']}");
            flash($action === 'rb_approve' ? 'Klaim disetujui; dibayar bersama gaji periode berikutnya.' : 'Klaim ditolak.');
        }
        redirect(safe_back(post('back'), 'payroll.php?p=reimburse'));
    } elseif ($action === 'unpublish') {
        q('UPDATE web_payslip SET published = 0 WHERE period_to = ?', [$to]);
        audit('payroll_unpublish', $to);
        flash('Slip gaji periode ini ditarik dari Portal Pegawai.');
        redirect('payroll.php?p=slips');
    } elseif ($action === 'setup') {
        $n = 0;
        foreach (isset($_POST['e']) ? (array)$_POST['e'] : [] as $id => $v) {
            $num = function ($k) use ($v) { return isset($v[$k]) ? (int)preg_replace('/\D/', '', $v[$k]) : 0; };
            $ptkp = isset($v['ptkp']) && in_array($v['ptkp'], ptkp_options(), true) ? $v['ptkp'] : 'TK/0';
            q('REPLACE INTO web_pay_emp (pegawai_id, gaji_pokok, tunjangan, uang_harian, ptkp, npwp, bpjs_kes, bpjs_tk, bpjs_jp) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [(int)$id, $num('gaji_pokok'), $num('tunjangan'), $num('uang_harian'), $ptkp, substr(trim(isset($v['npwp']) ? $v['npwp'] : ''), 0, 30),
                 empty($v['bpjs_kes']) ? 0 : 1, empty($v['bpjs_tk']) ? 0 : 1, empty($v['bpjs_jp']) ? 0 : 1]);
            $n++;
        }
        audit('payroll_setup', "$n karyawan");
        flash('Data gaji disimpan.');
        redirect('payroll.php?p=setup');
    } elseif ($action === 'settings') {
        foreach (payroll_defaults() as $k => $def) {
            $v = str_replace(',', '.', post($k, (string)$def));
            if (is_numeric($v) && $v >= 0) q('REPLACE INTO web_pay_setting (k, v) VALUES (?, ?)', [$k, $v]);
        }
        audit('payroll_settings', 'tarif diubah');
        flash('Pengaturan gaji disimpan.');
        redirect('payroll.php?p=settings');
    }
}

// ---------- exports ----------
if (get('csv') !== '') {
    $rows = q('SELECT s.*, p.pegawai_pin, p.pegawai_nama, p.nama_bank, p.nama_rek, p.no_rek FROM web_payslip s JOIN pegawai p ON p.pegawai_id = s.pegawai_id
               WHERE s.period_to = ? ORDER BY p.pegawai_pin + 0', [$to])->fetchAll();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . (get('csv') === 'bupot' ? 'pph21_' : 'gaji_') . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    if (get('csv') === 'bupot') {   // data for the PPh 21 monthly withholding (e-Bupot 21)
        fputcsv($out, ['Masa', 'Tahun', 'PIN', 'Nama', 'NPWP/NIK', 'PTKP', 'Kategori TER', 'Penghasilan bruto', 'PPh 21 dipotong', 'Keterangan']);
        foreach ($rows as $r) {
            $l = json_decode($r['detail'], true);
            fputcsv($out, [date('n', strtotime($to)), date('Y', strtotime($to)), $r['pegawai_pin'], $r['pegawai_nama'], $l['npwp'], $l['ptkp'], ter_category($l['ptkp']), $r['bruto'], $r['pph21'], $l['pph21_note']]);
        }
    } else {                         // payroll report and transfer list
        fputcsv($out, ['PIN', 'Nama', 'Periode', 'Hari hadir', 'Pendapatan', 'Potongan', 'PPh 21', 'Take home pay', 'Bank', 'Nama rekening', 'No. rekening']);
        foreach ($rows as $r) {
            $l = json_decode($r['detail'], true);
            fputcsv($out, [$r['pegawai_pin'], $r['pegawai_nama'], $r['period_from'] . ' s/d ' . $r['period_to'], $l['counts']['present'],
                           array_sum($l['earnings']), array_sum($l['deductions']) - $r['pph21'], $r['pph21'], $r['netto'], $r['nama_bank'], $r['nama_rek'], $r['no_rek']]);
        }
    }
    exit;
}

$csrf = csrf_field();
$periodPicker = function ($pp) use ($from, $to) {
    $prev = date('Y-m-21', strtotime($from . ' -1 month')); $prevTo = date('Y-m-20', strtotime($to . ' -1 month'));
    $next = date('Y-m-21', strtotime($from . ' +1 month')); $nextTo = date('Y-m-20', strtotime($to . ' +1 month'));
    return '<form class="toolbar" method="get"><input type="hidden" name="p" value="' . $pp . '">'
         . '<a class="btn small" href="payroll.php?' . h(http_build_query(['p' => $pp, 'from' => $prev, 'to' => $prevTo])) . '">‹</a>'
         . '<input type="date" name="from" value="' . h($from) . '"> s/d <input type="date" name="to" value="' . h($to) . '">'
         . '<a class="btn small" href="payroll.php?' . h(http_build_query(['p' => $pp, 'from' => $next, 'to' => $nextTo])) . '">›</a>'
         . '<button class="btn">Tampilkan</button></form>';
};

// ======================================================================= Proses gaji
if ($p === 'run'):
    $rows = calculate_payroll($from, $to);
    $saved = q('SELECT COUNT(*) n, MAX(published) pub, MAX(created_at) at FROM web_payslip WHERE period_to = ?', [$to])->fetch();
    $missing = count(employees(true)) - count($rows);
    $tot = ['earn' => 0, 'ded' => 0, 'pph' => 0, 'net' => 0, 'co' => 0];
    foreach ($rows as $r) { $tot['earn'] += array_sum($r['earnings']); $tot['ded'] += array_sum($r['deductions']) - $r['pph21']; $tot['pph'] += $r['pph21']; $tot['net'] += $r['netto']; $tot['co'] += array_sum($r['company']); }
    hris_header('Proses gaji', 'payroll', 'payroll.php?p=run', 'Penggajian',
        '<form method="post" class="inline">' . $csrf . '<input type="hidden" name="from" value="' . h($from) . '"><input type="hidden" name="to" value="' . h($to) . '">'
        . '<button class="btn" name="action" value="save"' . ($rows ? '' : ' disabled') . '>Simpan draf</button> '
        . '<button class="btn primary" name="action" value="publish"' . ($rows ? '' : ' disabled') . ' onclick="return confirm(\'Simpan dan terbitkan slip gaji ke Portal Pegawai?\')">Simpan &amp; terbitkan</button></form>'); ?>
  <?= $periodPicker('run') ?>
  <?php if ($saved['n']): ?><div class="notice"><div><b><?= $saved['pub'] ? 'Sudah diterbitkan' : 'Draf tersimpan' ?></b>Periode ini disimpan <?= h(date('d-m-Y H:i', strtotime($saved['at']))) ?>. Menyimpan lagi menghitung ulang dari data kehadiran terbaru dan menimpa slip.</div></div><?php endif; ?>
  <?php if ($missing > 0): ?><div class="notice"><div><b><?= $missing ?> karyawan aktif belum punya data gaji</b>Isi di <a href="payroll.php?p=setup">Data gaji karyawan</a>; mereka tidak ikut dihitung.</div></div><?php endif; ?>
  <div class="counters" style="grid-template-columns:repeat(5,minmax(0,1fr))">
    <div class="counter"><b><?= count($rows) ?></b><span>Karyawan</span></div>
    <div class="counter"><b><?= rupiah($tot['earn']) ?></b><span>Total pendapatan</span></div>
    <div class="counter"><b><?= rupiah($tot['pph']) ?></b><span>PPh 21</span></div>
    <div class="counter"><b><?= rupiah($tot['net']) ?></b><span>Take home pay</span></div>
    <div class="counter"><b><?= rupiah($tot['co']) ?></b><span>BPJS dibayar perusahaan</span></div>
  </div>
  <div class="tablecard"><div class="tablescroll"><table>
    <thead><tr><th>Nama karyawan</th><th>PTKP</th><th class="num">Hadir</th><th class="num">Gaji + tunjangan</th><th class="num">Uang harian</th><th class="num">Lembur</th>
      <th class="num">Potongan absensi</th><th class="num">BPJS karyawan</th><th class="num">PPh 21</th><th class="num">Take home pay</th><th class="actions">Slip</th></tr></thead><tbody>
    <?php foreach ($rows as $r):
        $e = $r['earnings']; $d = $r['deductions'];
        $harian = 0; foreach ($e as $k => $v) if (strpos($k, 'Uang makan') === 0) $harian = $v;
        $absensi = 0; foreach ($d as $k => $v) if (strpos($k, 'Potongan') === 0) $absensi += $v;
        $bpjsEmp = (isset($d['BPJS Kesehatan (1%)']) ? $d['BPJS Kesehatan (1%)'] : 0) + (isset($d['BPJS JHT (2%)']) ? $d['BPJS JHT (2%)'] : 0) + (isset($d['BPJS JP (1%)']) ? $d['BPJS JP (1%)'] : 0); ?>
      <tr><td><div class="who"><span class="avatar"><?= h(initials($r['name'])) ?></span><div><b><?= h($r['name']) ?></b><small>PIN <?= h($r['pin']) ?></small></div></div></td>
        <td><?= h($r['ptkp']) ?></td><td class="num"><?= $r['counts']['present'] ?>/<?= $r['counts']['workdays'] ?></td>
        <td class="num"><?= rupiah((isset($e['Gaji pokok']) ? $e['Gaji pokok'] : 0) + (isset($e['Tunjangan tetap']) ? $e['Tunjangan tetap'] : 0)) ?></td>
        <td class="num"><?= $harian ? rupiah($harian) : '' ?></td><td class="num"><?= isset($e['Lembur']) ? rupiah($e['Lembur']) : '' ?></td>
        <td class="num red"><?= $absensi ? rupiah($absensi) : '' ?></td><td class="num"><?= rupiah($bpjsEmp) ?></td>
        <td class="num" title="<?= h($r['pph21_note']) ?>"><?= rupiah($r['pph21']) ?></td><td class="num"><b><?= rupiah($r['netto']) ?></b></td>
        <td class="actions"><?php if ($saved['n']): ?><a class="btn small" href="payslip.php?<?= h(http_build_query(['to' => $to, 'id' => $r['pegawai_id']])) ?>" target="_blank">Lihat</a><?php endif; ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <?php if (!$rows): ?><div class="empty"><strong>Belum ada data gaji</strong>Isi gaji pokok dan tunjangan di <a href="payroll.php?p=setup">Data gaji karyawan</a>.</div><?php endif; ?></div>
  <p class="muted">Dihitung dari kehadiran <?= h(fmt_date($from)) ?> – <?= h(fmt_date($to)) ?>. Arahkan kursor ke angka PPh 21 untuk melihat cara hitungnya. Simpan untuk membuat slip gaji.</p>
<?php
// ======================================================================= Riwayat & slip
elseif ($p === 'slips'):
    $periods = q('SELECT period_from, period_to, COUNT(*) n, SUM(bruto) bruto, SUM(pph21) pph, SUM(netto) net, MAX(published) pub
                  FROM web_payslip GROUP BY period_from, period_to ORDER BY period_to DESC')->fetchAll();
    $sel = get('to') !== '' ? get('to') : ($periods ? $periods[0]['period_to'] : '');
    $slips = $sel ? q('SELECT s.*, p.pegawai_pin, p.pegawai_nama FROM web_payslip s JOIN pegawai p ON p.pegawai_id = s.pegawai_id WHERE s.period_to = ? ORDER BY p.pegawai_nama', [$sel])->fetchAll() : [];
    hris_header('Riwayat & slip gaji', 'payroll', 'payroll.php?p=slips', 'Penggajian'); ?>
  <div class="tablecard"><div class="tablescroll"><table>
    <thead><tr><th>Periode</th><th class="num">Karyawan</th><th class="num">Bruto PPh 21</th><th class="num">PPh 21</th><th class="num">Take home pay</th><th>Status</th><th class="actions">Aksi</th></tr></thead><tbody>
    <?php foreach ($periods as $r): ?><tr><td><a href="payroll.php?p=slips&to=<?= h($r['period_to']) ?>"><b><?= h(date('d-m-Y', strtotime($r['period_from']))) ?> – <?= h(date('d-m-Y', strtotime($r['period_to']))) ?></b></a></td>
      <td class="num"><?= (int)$r['n'] ?></td><td class="num"><?= rupiah($r['bruto']) ?></td><td class="num"><?= rupiah($r['pph']) ?></td><td class="num"><?= rupiah($r['net']) ?></td>
      <td><span class="pill <?= $r['pub'] ? 'ok' : 'grey' ?>"><?= $r['pub'] ? 'Diterbitkan' : 'Draf' ?></span></td>
      <td class="actions" style="white-space:nowrap"><a class="btn small" href="payroll.php?<?= h(http_build_query(['csv' => 'gaji', 'to' => $r['period_to']])) ?>">Laporan gaji</a>
        <a class="btn small" href="payroll.php?<?= h(http_build_query(['csv' => 'bupot', 'to' => $r['period_to']])) ?>">Data e-Bupot 21</a>
        <?php if ($r['pub']): ?><form method="post" class="inline" onsubmit="return confirm('Tarik slip periode ini dari Portal Pegawai?')"><?= $csrf ?><input type="hidden" name="action" value="unpublish"><input type="hidden" name="to" value="<?= h($r['period_to']) ?>"><button class="btn small">Tarik</button></form><?php endif; ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php if (!$periods): ?><div class="empty"><strong>Belum ada gaji yang disimpan</strong>Hitung dan simpan di <a href="payroll.php?p=run">Proses gaji</a>.</div><?php endif; ?></div>
  <?php if ($slips): ?>
  <div class="tablecard"><div class="tablescroll"><table>
    <thead><tr><th>Nama karyawan</th><th class="num">Bruto</th><th class="num">PPh 21</th><th class="num">Take home pay</th><th class="actions">Slip</th></tr></thead><tbody>
    <?php foreach ($slips as $r): ?><tr><td><b><?= h($r['pegawai_nama']) ?></b> <span class="muted">PIN <?= h($r['pegawai_pin']) ?></span></td><td class="num"><?= rupiah($r['bruto']) ?></td>
      <td class="num"><?= rupiah($r['pph21']) ?></td><td class="num"><b><?= rupiah($r['netto']) ?></b></td>
      <td class="actions"><a class="btn small" target="_blank" href="payslip.php?<?= h(http_build_query(['to' => $r['period_to'], 'id' => $r['pegawai_id']])) ?>">Lihat / cetak</a></td></tr><?php endforeach; ?>
    </tbody></table></div></div>
  <?php endif; ?>
<?php
// ======================================================================= Reimbursement
elseif ($p === 'reimburse'):
    $state = get('s', CLAIM_PENDING);
    $cnt = q('SELECT status, COUNT(*) n, SUM(jumlah) t FROM web_reimburse GROUP BY status')->fetchAll(PDO::FETCH_UNIQUE);
    $rows = q('SELECT r.*, p.pegawai_nama, p.pegawai_pin FROM web_reimburse r JOIN pegawai p ON p.pegawai_id = r.pegawai_id WHERE r.status = ? ORDER BY r.tgl DESC, r.id DESC LIMIT 500', [$state])->fetchAll();
    $back = h($_SERVER['REQUEST_URI']);
    hris_header('Reimbursement', 'payroll', 'payroll.php?p=reimburse', 'Penggajian'); ?>
  <div class="counters" style="grid-template-columns:repeat(3,minmax(0,1fr))">
    <?php foreach ([CLAIM_PENDING => 'Menunggu persetujuan', CLAIM_APPROVED => 'Disetujui', CLAIM_REJECTED => 'Ditolak'] as $k => $label): $c = isset($cnt[$k]) ? $cnt[$k] : ['n' => 0, 't' => 0]; ?>
      <a class="counter<?= $state === $k ? ' on' : '' ?>" href="payroll.php?p=reimburse&s=<?= $k ?>"><b><?= (int)$c['n'] ?></b><span><?= $label ?> · <?= rupiah($c['t']) ?></span></a>
    <?php endforeach; ?>
  </div>
  <div class="tablecard"><div class="tablescroll"><table>
    <thead><tr><th>Nama karyawan</th><th>Tanggal</th><th>Kategori</th><th class="num">Jumlah</th><th>Keterangan</th><th>Bukti</th><th>Status</th><th>Dibayar</th><th class="actions">Aksi</th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?>
      <tr><td><b><?= h($r['pegawai_nama']) ?></b> <span class="muted">PIN <?= h($r['pegawai_pin']) ?></span></td><td><?= h(fmt_date($r['tgl'])) ?></td><td><?= h($r['kategori']) ?></td>
        <td class="num"><b><?= rupiah($r['jumlah']) ?></b></td><td><?= h($r['keterangan']) ?></td>
        <td><?= $r['file'] ? '<a href="receipt.php?id=' . (int)$r['id'] . '" target="_blank">Lihat</a>' : '—' ?></td><td><?= claim_badge($r['status']) ?></td>
        <td class="muted"><?= $r['paid_period_to'] ? 'gaji s/d ' . h(date('d-m-Y', strtotime($r['paid_period_to']))) : ($r['status'] === CLAIM_APPROVED ? 'gaji berikutnya' : '') ?></td>
        <td class="actions"><?php if ($r['status'] === CLAIM_PENDING): foreach (['rb_approve' => ['primary', 'Setujui'], 'rb_reject' => ['outline', 'Tolak']] as $act => $b): ?>
          <form method="post" class="inline"><?= $csrf ?><input type="hidden" name="action" value="<?= $act ?>"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="back" value="<?= $back ?>">
            <button class="btn small <?= $b[0] ?>"><?= $b[1] ?></button></form>
        <?php endforeach; endif; ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div><?php if (!$rows): ?><div class="empty"><strong>Tidak ada klaim</strong>Karyawan mengirim klaim di Portal Pegawai.</div><?php endif; ?></div>
  <p class="muted">Klaim yang disetujui ikut dibayar saat gaji periode berikutnya disimpan, sebagai pendapatan di luar penghasilan bruto PPh 21.</p>
<?php
// ======================================================================= Data gaji karyawan
elseif ($p === 'setup'):
    $pays = pay_data();
    hris_header('Data gaji karyawan', 'payroll', 'payroll.php?p=setup', 'Penggajian'); ?>
  <form method="post"><?= $csrf ?><input type="hidden" name="action" value="setup">
  <div class="tablecard"><div class="tablescroll"><table>
    <thead><tr><th>Nama karyawan</th><th>Gaji pokok / bulan</th><th>Tunjangan tetap / bulan</th><th>Uang makan + transport / hari hadir</th><th>PTKP</th><th>NPWP / NIK</th><th>BPJS Kes</th><th>BPJS TK</th><th>JP</th></tr></thead><tbody>
    <?php foreach (employees(true) as $e): $id = (int)$e['pegawai_id']; $v = isset($pays[$id]) ? $pays[$id] : ['gaji_pokok' => '', 'tunjangan' => '', 'uang_harian' => '', 'ptkp' => 'TK/0', 'npwp' => '', 'bpjs_kes' => 1, 'bpjs_tk' => 1, 'bpjs_jp' => 1]; ?>
      <tr><td><b><?= h($e['pegawai_nama']) ?></b> <span class="muted"><?= h($e['pegawai_pin']) ?></span></td>
        <?php foreach (['gaji_pokok', 'tunjangan', 'uang_harian'] as $k): ?><td><input type="text" inputmode="numeric" name="e[<?= $id ?>][<?= $k ?>]" value="<?= $v[$k] !== '' ? h(number_format((float)$v[$k], 0, ',', '.')) : '' ?>" style="width:130px;text-align:right" placeholder="0"></td><?php endforeach; ?>
        <td><select name="e[<?= $id ?>][ptkp]"><?php foreach (ptkp_options() as $o): ?><option <?= $v['ptkp'] === $o ? 'selected' : '' ?>><?= $o ?></option><?php endforeach; ?></select></td>
        <td><input type="text" name="e[<?= $id ?>][npwp]" value="<?= h($v['npwp']) ?>" style="width:170px"></td>
        <?php foreach (['bpjs_kes', 'bpjs_tk', 'bpjs_jp'] as $k): ?><td style="text-align:center"><input type="checkbox" name="e[<?= $id ?>][<?= $k ?>]" value="1" <?= $v[$k] ? 'checked' : '' ?>></td><?php endforeach; ?></tr>
    <?php endforeach; ?>
    </tbody></table></div></div>
  <div class="formfoot"><span class="muted" style="margin-right:auto">Karyawan dengan gaji pokok dan tunjangan kosong tidak ikut proses gaji. PTKP: TK = tidak kawin, K = kawin, angka = tanggungan.</span><button class="btn primary">Simpan data gaji</button></div>
  </form>
<?php
// ======================================================================= Pengaturan gaji
else:
    $s = payroll_settings();
    $fields = [
        'BPJS Kesehatan' => ['kes_co' => 'Perusahaan (%)', 'kes_emp' => 'Karyawan (%)', 'kes_cap' => 'Batas upah (Rp)'],
        'BPJS Ketenagakerjaan' => ['jht_co' => 'JHT perusahaan (%)', 'jht_emp' => 'JHT karyawan (%)', 'jp_co' => 'JP perusahaan (%)', 'jp_emp' => 'JP karyawan (%)',
                                   'jp_cap' => 'Batas upah JP (Rp)', 'jkk' => 'JKK (%) — sesuai tingkat risiko', 'jkm' => 'JKM (%)'],
        'Lembur & potongan' => ['ot_divisor' => 'Pembagi upah per jam (173)', 'late_per_minute' => 'Potongan terlambat per menit (Rp, 0 = tidak dipotong)',
                                'absent_deduct' => 'Potong gaji hari tidak hadir tanpa izin (1 = ya, 0 = tidak)',
                                'ot_requires_approval' => 'Lembur harus diajukan (1 = hanya bayar lembur yang disetujui, 0 = bayar dari scan)'],
    ];
    hris_header('Pengaturan gaji', 'payroll', 'payroll.php?p=settings', 'Penggajian'); ?>
  <form method="post" class="section"><?= $csrf ?><input type="hidden" name="action" value="settings">
    <?php foreach ($fields as $title => $fs): ?><h2><?= h($title) ?></h2><div class="row2">
      <?php foreach ($fs as $k => $label): ?><label class="field"><span><?= h($label) ?></span><input type="text" name="<?= $k ?>" value="<?= h(rtrim(rtrim(number_format($s[$k], 2, '.', ''), '0'), '.')) ?>"></label><?php endforeach; ?></div>
    <?php endforeach; ?>
    <p class="muted">PPh 21 memakai tarif efektif rata-rata (TER, PP 58/2023) per bulan dan tarif Pasal 17 di periode Desember. Batas upah JP berubah tiap Maret; periksa angkanya.</p>
    <div class="formfoot"><button class="btn primary">Simpan</button></div>
  </form>
<?php
endif;
hris_footer();
