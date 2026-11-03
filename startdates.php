<?php
// HR → Karyawan → Tanggal mulai dari scan: set employees' start date (tgl_mulai_kerja) to the day of their first
// machine scan (Fingerspot and FTM, not manual entries). Preview first; only ticked rows change; old dates go to the log.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/portal.php';
require __DIR__ . '/inc/hris.php';
$me = require_login('admin');
ensure_web_tables();

/**
 * First real scan per PIN: machine scans only, not the manual Tidak scan rows (sn 100-105) or FTM's corrections (FTM0),
 * and not impossible dates: a machine whose clock was reset stamps scans 01-01-2000.
 */
function first_scans()
{
    return q("SELECT pin, DATE(MIN(scan_date)) FROM att_log WHERE sn NOT IN ('100', '101', '102', '103', '104', '105', 'FTM0')
              AND scan_date >= '2005-01-01' AND scan_date <= NOW() GROUP BY pin")->fetchAll(PDO::FETCH_KEY_PAIR);
}

$rows = [];
$first = first_scans();
foreach (q('SELECT pegawai_id, pegawai_pin, pegawai_nama, tgl_mulai_kerja, tgl_masuk_pertama, pegawai_status FROM pegawai WHERE pegawai_status = 1 ORDER BY pegawai_nama') as $e) {
    $scan = isset($first[$e['pegawai_pin']]) ? $first[$e['pegawai_pin']] : null;
    $cur = $e['tgl_mulai_kerja'] && $e['tgl_mulai_kerja'] !== '0000-00-00' ? substr($e['tgl_mulai_kerja'], 0, 10) : null;
    $rows[$e['pegawai_id']] = $e + ['scan' => $scan, 'cur' => $cur,
        // earlier than the recorded date, or none recorded: tick; later: probably joined before the machine, leave it to HR
        'suggest' => $scan && (!$cur || $scan < $cur)];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $n = 0;
    foreach (isset($_POST['apply']) ? (array)$_POST['apply'] : [] as $id) {
        $r = isset($rows[(int)$id]) ? $rows[(int)$id] : null;
        if (!$r || !$r['scan'] || $r['scan'] === $r['cur']) continue;
        // tgl_masuk_pertama follows when it was empty or the same as the old start date
        $both = !$r['tgl_masuk_pertama'] || $r['tgl_masuk_pertama'] === '0000-00-00' || substr($r['tgl_masuk_pertama'], 0, 10) === $r['cur'];
        q('UPDATE pegawai SET tgl_mulai_kerja = ?' . ($both ? ', tgl_masuk_pertama = ?' : '') . ' WHERE pegawai_id = ?', $both ? [$r['scan'], $r['scan'], $r['pegawai_id']] : [$r['scan'], $r['pegawai_id']]);
        audit('start_date_from_scan', "{$r['pegawai_nama']}: " . ($r['cur'] ?: 'kosong') . " → {$r['scan']}");
        $n++;
    }
    flash("Tanggal mulai bekerja $n karyawan diubah ke tanggal scan pertama. Tanggal lama tercatat di log.");
    redirect('startdates.php');
}

$csrf = csrf_field();
hris_header('Tanggal mulai dari scan pertama', 'employee', 'employees', '<a href="hr.php?m=employees">Karyawan</a>'); ?>
  <p class="muted" style="margin:0 0 12px">Scan pertama = scan mesin paling awal (Fingerspot dan FTM kantor pusat), tanpa input manual. Yang dicentang otomatis: scan pertama lebih awal dari tanggal tercatat,
    atau tanggal belum diisi. Scan pertama yang lebih lambat biasanya berarti karyawan sudah bekerja sebelum mesin dipakai, jadi tidak dicentang; centang bila memang ingin diubah.</p>
  <form method="post"><?= $csrf ?>
  <div class="tablecard"><div class="tablescroll"><table>
    <thead><tr><th style="width:30px"><input type="checkbox" onclick="document.querySelectorAll('input[name=\'apply[]\']').forEach(c=>c.checked=this.checked)"></th><th>Nama karyawan</th><th>Mulai bekerja (sekarang)</th><th>Scan pertama</th><th>Selisih</th></tr></thead><tbody>
    <?php foreach ($rows as $r): $same = $r['scan'] && $r['scan'] === $r['cur'];
        $diff = $r['scan'] && $r['cur'] ? (int)round((strtotime($r['scan']) - strtotime($r['cur'])) / 86400) : null; ?>
      <tr><td><?php if ($r['scan'] && !$same): ?><input type="checkbox" name="apply[]" value="<?= (int)$r['pegawai_id'] ?>"<?= $r['suggest'] ? ' checked' : '' ?>><?php endif; ?></td>
        <td><b><?= h($r['pegawai_nama']) ?></b> <span class="muted"><?= h($r['pegawai_pin']) ?></span></td>
        <td><?= $r['cur'] ? h(date('d-m-Y', strtotime($r['cur']))) : '<span class="muted">kosong</span>' ?></td>
        <td><?= $r['scan'] ? h(date('d-m-Y', strtotime($r['scan']))) : '<span class="muted">belum ada scan</span>' ?></td>
        <td><?php if ($same): ?><span class="pill ok">sama</span><?php elseif ($diff === null): ?><?php elseif ($diff < 0): ?><span class="pill warn"><?= -$diff ?> hari lebih awal</span><?php else: ?><span class="pill grey"><?= $diff ?> hari lebih lambat</span><?php endif; ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div></div>
  <div class="formfoot"><a class="btn" href="hr.php?m=employees">Batal</a><button class="btn primary" onclick="return confirm('Ubah tanggal mulai bekerja karyawan yang dicentang?')">Terapkan yang dicentang</button></div></form>
<?php hris_footer();
