<?php
// HR → Penggajian → Simulasi pajak: take-home pay and PPh 21 for a salary you type in, month by month. Admins only.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/portal.php';
require __DIR__ . '/inc/hris.php';
require __DIR__ . '/inc/payroll.php';
require __DIR__ . '/inc/taxsim.php';
$me = require_login('admin');
ensure_payroll_tables();

$in = [
    'gaji_pokok' => tax_money(get('gaji_pokok')), 'tunjangan' => tax_money(get('tunjangan')), 'lain' => tax_money(get('lain')),
    'ptkp' => in_array(get('ptkp'), ptkp_options(), true) ? get('ptkp') : 'TK/0',
    'bpjs_kes' => get('go') === '' ? 1 : (int)(get('bpjs_kes') === '1'), 'bpjs_tk' => get('go') === '' ? 1 : (int)(get('bpjs_tk') === '1'), 'bpjs_jp' => get('go') === '' ? 1 : (int)(get('bpjs_jp') === '1'),
];
$month = min(12, max(1, (int)get('month', (int)date('n'))));
$r = $in['gaji_pokok'] + $in['tunjangan'] + $in['lain'] > 0 ? tax_simulate($in, payroll_settings()) : null;
$monthNames = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
$fmt = function ($n) { return $n < 0 ? '−' . rupiah(-$n) : rupiah($n); };

hris_header('Simulasi pajak', 'payroll', 'taxsim.php', 'Penggajian'); ?>
  <div class="row">
    <div class="card" style="flex:0 1 340px">
      <h2>Gaji yang disimulasikan</h2>
      <form method="get" class="stack">
        <input type="hidden" name="go" value="1">
        <label>Gaji pokok <input name="gaji_pokok" inputmode="numeric" value="<?= $in['gaji_pokok'] ? h(number_format($in['gaji_pokok'], 0, ',', '.')) : '' ?>" placeholder="8.000.000"></label>
        <label>Tunjangan tetap <input name="tunjangan" inputmode="numeric" value="<?= $in['tunjangan'] ? h(number_format($in['tunjangan'], 0, ',', '.')) : '' ?>" placeholder="2.000.000"></label>
        <label>Penghasilan lain per bulan <input name="lain" inputmode="numeric" value="<?= $in['lain'] ? h(number_format($in['lain'], 0, ',', '.')) : '' ?>" placeholder="uang makan, bonus tetap …"></label>
        <label>Status PTKP <select name="ptkp"><?php foreach (ptkp_options() as $o): ?><option<?= $in['ptkp'] === $o ? ' selected' : '' ?>><?= h($o) ?></option><?php endforeach; ?></select></label>
        <label>Bulan yang dilihat <select name="month"><?php foreach ($monthNames as $n => $l): ?><option value="<?= $n ?>"<?= $month === $n ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></label>
        <div class="muted" style="margin-top:4px">BPJS yang diikuti</div>
        <label class="check"><input type="checkbox" name="bpjs_kes" value="1"<?= $in['bpjs_kes'] ? ' checked' : '' ?>> BPJS Kesehatan</label>
        <label class="check"><input type="checkbox" name="bpjs_tk" value="1"<?= $in['bpjs_tk'] ? ' checked' : '' ?>> BPJS Ketenagakerjaan (JHT, JKK, JKM)</label>
        <label class="check"><input type="checkbox" name="bpjs_jp" value="1"<?= $in['bpjs_jp'] ? ' checked' : '' ?>> Jaminan Pensiun (JP)</label>
        <div><button class="btn primary">Hitung</button></div>
      </form>
      <p class="muted">Memakai aturan yang sama dengan proses gaji: TER (PP 58/2023) Januari–November, pajak setahun (Pasal 17) di Desember, dan persentase BPJS dari Pengaturan gaji. Gaji dianggap sama sepanjang tahun.</p>
    </div>
    <div style="flex:1 1 480px;display:grid;gap:16px;align-content:start">
    <?php if (!$r): ?>
      <div class="card"><div class="empty"><strong>Isi gaji untuk melihat hasilnya</strong>Take-home pay dan PPh 21 per bulan akan muncul di sini.</div></div>
    <?php else: $m = $r['months'][$month]; ?>
      <div class="card">
        <h2><?= $monthNames[$month] ?></h2>
        <div class="counters" style="grid-template-columns:repeat(3,minmax(0,1fr))">
          <div class="counter"><b><?= h($fmt($m['net'])) ?></b><span>Take-home pay</span></div>
          <div class="counter"><b><?= h($fmt($m['tax'])) ?></b><span>PPh 21 bulan ini<?= $month === 12 && $m['tax'] < 0 ? ' (dikembalikan)' : '' ?></span></div>
          <div class="counter"><b><?= h($fmt($r['bruto'])) ?></b><span>Penghasilan bruto</span></div>
        </div>
        <div class="table-wrap" style="margin-top:12px"><table>
          <tr><td>Gaji pokok + tunjangan tetap + penghasilan lain</td><td class="num"><?= h($fmt($r['earn'])) ?></td></tr>
          <tr><td>+ BPJS dibayar perusahaan yang jadi penghasilan (JKK, JKM, Kesehatan)</td><td class="num"><?= h($fmt($r['bruto'] - $r['earn'])) ?></td></tr>
          <tr><td><b>Penghasilan bruto untuk PPh 21</b></td><td class="num"><b><?= h($fmt($r['bruto'])) ?></b></td></tr>
          <tr><td>− BPJS Kesehatan karyawan (1%)</td><td class="num"><?= h($fmt($r['bpjs']['kes_emp'])) ?></td></tr>
          <tr><td>− BPJS JHT karyawan (2%)</td><td class="num"><?= h($fmt($r['bpjs']['jht_emp'])) ?></td></tr>
          <tr><td>− BPJS JP karyawan (1%)</td><td class="num"><?= h($fmt($r['bpjs']['jp_emp'])) ?></td></tr>
          <tr><td>− PPh 21 <span class="muted"><?= $month < 12 ? '(TER kategori ' . h($r['cat']) . ', ' . str_replace('.', ',', (string)$r['rate']) . '%)' : '(pajak setahun dikurangi yang sudah dipotong Jan–Nov)' ?></span></td><td class="num"><?= h($fmt($m['tax'])) ?></td></tr>
          <tr><td><b>Take-home pay</b></td><td class="num"><b><?= h($fmt($m['net'])) ?></b></td></tr>
        </table></div>
      </div>
      <div class="card">
        <h2>Pajak setahun (Pasal 17)</h2>
        <div class="table-wrap"><table>
          <tr><td>Penghasilan bruto setahun</td><td class="num"><?= h($fmt($r['annual']['bruto'])) ?></td></tr>
          <tr><td>− Biaya jabatan (5%, maks. Rp6.000.000)</td><td class="num"><?= h($fmt($r['annual']['biaya_jabatan'])) ?></td></tr>
          <tr><td>− Iuran JHT + JP karyawan setahun</td><td class="num"><?= h($fmt($r['annual']['iuran'])) ?></td></tr>
          <tr><td><b>Penghasilan neto</b></td><td class="num"><b><?= h($fmt($r['annual']['neto'])) ?></b></td></tr>
          <tr><td>− PTKP <?= h($in['ptkp']) ?></td><td class="num"><?= h($fmt($r['annual']['ptkp'])) ?></td></tr>
          <tr><td><b>PKP</b></td><td class="num"><b><?= h($fmt(max(0, $r['annual']['pkp']))) ?></b></td></tr>
          <tr><td><b>PPh 21 setahun</b></td><td class="num"><b><?= h($fmt($r['annual']['tax'])) ?></b></td></tr>
        </table></div>
      </div>
      <div class="card">
        <h2>Per bulan</h2>
        <div class="table-wrap"><table>
          <tr><th>Bulan</th><th class="num">PPh 21</th><th class="num">Take-home pay</th></tr>
          <?php foreach ($r['months'] as $n => $x): ?><tr<?= $n === $month ? ' style="background:var(--primary-soft)"' : '' ?>><td><?= $monthNames[$n] ?></td><td class="num"><?= h($fmt($x['tax'])) ?></td><td class="num"><?= h($fmt($x['net'])) ?></td></tr><?php endforeach; ?>
          <tr><td><b>Setahun</b></td><td class="num"><b><?= h($fmt($r['total_tax'])) ?></b></td><td class="num"><b><?= h($fmt(array_sum(array_column($r['months'], 'net')))) ?></b></td></tr>
        </table></div>
        <p class="muted">Ini simulasi, bukan slip gaji. THR, bonus sekali bayar, lembur dan potongan lain tidak termasuk.</p>
      </div>
    <?php endif; ?>
    </div>
  </div>
<?php hris_footer();
