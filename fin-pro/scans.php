<?php
// Raw scans from att_log, plus the form to add a check-in/out by hand.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_login();

$addError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    if (post('action') === 'add') {
        $jenis = (int)post('jenis');
        $kat = post('kat_izin_id') !== '' ? (int)post('kat_izin_id') : (is_scan_type($jenis) ? scan_types()[$jenis][1] : 0);
        $addError = add_manual_scan((int)post('pegawai_id'), post('date'), post('time'), $jenis, $kat, post('note'), post('approved') === '1');
        if (!$addError) {
            flash(scan_types()[$jenis][0] . ' added for ' . employee(post('pegawai_id'))['pegawai_nama'] . ' on ' . fmt_date(post('date')) . ' at ' . post('time') . '.');
            redirect(safe_back(post('back'), 'scans.php'));
        }
    } elseif (post('action') === 'delete') {
        $sn = post('sn');
        $scan = post('scan_date');
        $emp = q('SELECT pegawai_id FROM pegawai WHERE pegawai_pin = ?', [post('pin')])->fetchColumn();
        if (is_scan_type($sn) && $emp) {
            $leave = q('SELECT pegawai_id, izin_tgl, izin_jenis_id, izin_status, izin_urutan FROM izin
                        WHERE pegawai_id = ? AND izin_tgl = ? AND izin_jenis_id = ? AND izin_noscan_time = ?',
                [$emp, substr($scan, 0, 10), (int)$sn, substr($scan, 11)])->fetchAll(PDO::FETCH_NUM);
            foreach ($leave as $key) delete_leave($key);
            q('DELETE FROM att_log WHERE sn = ? AND pin = ? AND scan_date = ?', [$sn, post('pin'), $scan]);
            audit('manual_scan_delete', post('pin') . ' ' . $scan);
            flash('Scan manual dihapus.');
        } else {
            flash('Hanya scan manual yang bisa dihapus. Scan dari mesin tidak diubah.', 'err');
        }
        redirect(safe_back(post('back'), 'scans.php'));
    }
}

$from = get('from', date('Y-m-d', strtotime('-6 days')));
$to = get('to', date('Y-m-d'));
if (!valid_date($from)) $from = date('Y-m-d', strtotime('-6 days'));
if (!valid_date($to)) $to = date('Y-m-d');
$pid = get('pegawai_id');
$source = get('source');
$page = max(1, (int)get('page', 1));
$per = 100;

$where = ['a.scan_date >= ?', 'a.scan_date < ? + INTERVAL 1 DAY'];
$params = [$from, $to];
if ($pid !== '') {
    $where[] = 'a.pin = (SELECT pegawai_pin FROM pegawai WHERE pegawai_id = ?)';
    $params[] = (int)$pid;
}
if ($source === 'manual') $where[] = "a.sn IN ('100','101','102','103','104','105')";
if ($source === 'device') $where[] = "a.sn NOT IN ('100','101','102','103','104','105')";
$w = implode(' AND ', $where);
$total = (int)q("SELECT COUNT(*) FROM att_log a WHERE $w", $params)->fetchColumn();
$rows = q("SELECT a.*, p.pegawai_nama, d.device_name FROM att_log a
           LEFT JOIN pegawai p ON p.pegawai_pin = a.pin LEFT JOIN device d ON d.sn = a.sn
           WHERE $w ORDER BY a.scan_date DESC LIMIT " . (($page - 1) * $per) . ", $per", $params)->fetchAll();

$showAdd = get('add') === '1' || $addError;
$f = [
    'pegawai_id' => post('pegawai_id', get('pegawai_id')),
    'date'  => post('date', get('date', date('Y-m-d'))),
    'time'  => post('time', get('time')),
    'jenis' => post('jenis', get('jenis', '100')),
    'kat'   => post('kat_izin_id', ''),
    'note'  => post('note'),
];
$back = $_SERVER['REQUEST_URI'];
$qs = function ($extra) use ($from, $to, $pid, $source) {
    return 'scans.php?' . http_build_query(array_merge(['from' => $from, 'to' => $to, 'pegawai_id' => $pid, 'source' => $source], $extra));
};

page_header('Absensi', 'scans.php');
?>
<div class="page-head">
  <h1>Data Scanlog</h1>
  <a class="btn primary" href="<?= h($qs(['add' => 1])) ?>#add">+ Tambah Scan Manual</a>
</div>

<?php if ($showAdd): ?>
<div class="card" id="add">
  <h2>Tambah Scan Manual (Tidak scan)</h2>
  <p class="muted">Disimpan sebagai Tidak scan, sama seperti di program absensi.</p>
  <?php if ($addError): ?><div class="flash err"><?= h($addError) ?></div><?php endif; ?>
  <form method="post" class="stack">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="add">
    <input type="hidden" name="back" value="<?= h(safe_back(post('back', get('back')), 'scans.php')) ?>">
    <div class="grid2">
      <label>Pegawai <?= employee_select('pegawai_id', $f['pegawai_id']) ?></label>
      <label>Jenis scan
        <select name="jenis" required>
          <?php foreach (scan_types() as $id => $t): ?>
            <option value="<?= $id ?>" <?= (string)$f['jenis'] === (string)$id ? 'selected' : '' ?>><?= h($t[0]) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Tanggal <input type="date" name="date" required value="<?= h($f['date']) ?>" max="<?= date('Y-m-d') ?>"></label>
      <label>Jam <input type="time" name="time" required value="<?= h($f['time']) ?>"></label>
      <label>Kategori <select name="kat_izin_id"><?= options(leave_categories(), $f['kat'], 'Default ("Lupa Scan …")') ?></select></label>
      <label>Catatan <input name="note" maxlength="255" value="<?= h($f['note']) ?>"></label>
    </div>
    <label class="check"><input type="checkbox" name="approved" value="1" checked> Diizinkan</label>
    <div><button class="btn primary">Simpan</button> <a class="btn" href="<?= h($qs([])) ?>">Batal</a></div>
  </form>
</div>
<?php endif; ?>

<div class="card">
  <form class="inline" method="get">
    <label>Tanggal <input type="date" name="from" value="<?= h($from) ?>"></label>
    <label>s/d <input type="date" name="to" value="<?= h($to) ?>"></label>
    <label>Pegawai <?= employee_select('pegawai_id', $pid, true, false) ?></label>
    <label>Sumber <select name="source"><?= options(['device' => 'Mesin', 'manual' => 'Manual'], $source, 'Semua') ?></select></label>
    <button class="btn">Cari</button>
  </form>
</div>

<div class="card">
  <p class="muted"><?= number_format($total) ?> scan</p>
  <div class="table-wrap"><table>
    <tr><th>Tanggal</th><th>Jam</th><th>Pegawai</th><th>PIN</th><th>Sumber</th><th></th></tr>
    <?php foreach ($rows as $r): $manual = is_scan_type($r['sn']); ?>
      <tr>
        <td><?= h(fmt_date(substr($r['scan_date'], 0, 10))) ?></td>
        <td><?= h(substr($r['scan_date'], 11, 5)) ?></td>
        <td><?= h($r['pegawai_nama'] ?: 'PIN tidak dikenal') ?></td>
        <td><?= h($r['pin']) ?></td>
        <td><?= $manual ? '<span class="tag">Manual · ' . h(scan_types()[(int)$r['sn']][0]) . '</span>' : h($r['device_name'] ?: $r['sn']) ?></td>
        <td>
          <?php if ($manual): ?>
            <form method="post" class="btn-form" onsubmit="return confirm('Hapus scan manual ini?')">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="sn" value="<?= h($r['sn']) ?>">
              <input type="hidden" name="pin" value="<?= h($r['pin']) ?>">
              <input type="hidden" name="scan_date" value="<?= h($r['scan_date']) ?>">
              <input type="hidden" name="back" value="<?= h($back) ?>">
              <button class="btn small danger">Hapus</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="6" class="muted">Tidak ada data.</td></tr><?php endif; ?>
  </table></div>
  <?php if ($total > $per): ?>
    <div class="pager">
      <?php if ($page > 1): ?><a class="btn small" href="<?= h($qs(['page' => $page - 1])) ?>">‹ Sebelumnya</a><?php endif; ?>
      <span class="muted">Halaman <?= $page ?> of <?= (int)ceil($total / $per) ?></span>
      <?php if ($page * $per < $total): ?><a class="btn small" href="<?= h($qs(['page' => $page + 1])) ?>">Berikutnya ›</a><?php endif; ?>
    </div>
  <?php endif; ?>
</div>
<?php page_footer();
