<?php
// Leave / permission (izin): list, add, approve, un-approve and delete.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_login();

$types = leave_types();
$addError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = post('action');
    if ($action === 'add') {
        $emp = employee(post('pegawai_id'));
        $jenis = (int)post('izin_jenis_id');
        $df = post('date_from');
        $dt = post('date_to', $df);
        if (!$emp) $addError = 'Pilih pegawai.';
        elseif (!isset($types[$jenis]) || is_scan_type($jenis)) $addError = 'Pilih jenis izin. Tidak scan ditambah lewat Input Cepat.';
        elseif (!valid_date($df) || !valid_date($dt) || $dt < $df) $addError = 'Isi rentang tanggal yang benar.';
        elseif ((strtotime($dt) - strtotime($df)) / 86400 > 92) $addError = 'Rentang maksimal 3 bulan.';
        elseif (post('t1') !== '' && !valid_time(post('t1')) || post('t2') !== '' && !valid_time(post('t2'))) $addError = 'Isi jam dengan format JJ:MM.';
        else {
            $skipOff = post('skip_off') === '1';
            $holidays = q('SELECT libur_tgl FROM libur WHERE libur_tgl BETWEEN ? AND ?', [$df, $dt])->fetchAll(PDO::FETCH_COLUMN);
            $added = 0;
            $pdo = db();
            $pdo->beginTransaction();
            try {
                for ($d = $df; $d <= $dt; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
                    if ($skipOff && (work_hours_for($emp['pegawai_id'], $d)[1] || in_array($d, $holidays))) continue;
                    q('INSERT INTO izin (pegawai_id, izin_urutan, izin_tgl_pengajuan, izin_tgl, izin_jenis_id, izin_catatan, izin_status,
                                         izin_tinggal_t1, izin_tinggal_t2, cuti_n_id, izin_ket_lain, izin_noscan_time, kat_izin_id, ket_status)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, NULL)', [
                        $emp['pegawai_id'], next_izin_seq($emp['pegawai_id'], $d, $jenis), date('Y-m-d'), $d, $jenis, post('note'),
                        post('approved') === '1' ? 1 : 0, post('t1') !== '' ? post('t1') : null, post('t2') !== '' ? post('t2') : null,
                        $jenis === 80 ? (int)post('cuti_n_id') : 0, 'Web: ' . current_user()['username'], (int)post('kat_izin_id'),
                    ]);
                    $added++;
                }
                $pdo->commit();
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            audit('leave_add', sprintf('%s type %d %s..%s (%d days)', $emp['pegawai_nama'], $jenis, $df, $dt, $added));
            flash($added ? "Leave added for {$emp['pegawai_nama']}: $added day(s)." : 'Tidak ada hari ditambah: semua tanggal adalah hari libur.', $added ? 'ok' : 'err');
            redirect('leave.php?' . http_build_query(['from' => $df, 'to' => $dt, 'pegawai_id' => $emp['pegawai_id']]));
        }
    } elseif (in_array($action, ['approve', 'unapprove', 'delete'], true)) {
        $key = leave_key_from_post();
        if ($action === 'delete') delete_leave($key);
        else set_leave_status($key, $action === 'approve' ? 1 : 0);
        flash($action === 'delete' ? 'Izin dihapus.' : ($action === 'approve' ? 'Izin disetujui.' : 'Izin dibatalkan.'));
        redirect(safe_back(post('back'), 'leave.php'));
    }
}

$from = get('from', date('Y-m-01', strtotime('-1 month')));
$to = get('to', date('Y-m-d', strtotime('+1 month')));
if (!valid_date($from)) $from = date('Y-m-01', strtotime('-1 month'));
if (!valid_date($to)) $to = date('Y-m-d', strtotime('+1 month'));
$pid = get('pegawai_id');
$status = get('status');
$kind = get('kind', 'leave');

$where = ['i.izin_tgl BETWEEN ? AND ?'];
$params = [$from, $to];
if ($pid !== '') { $where[] = 'i.pegawai_id = ?'; $params[] = (int)$pid; }
if ($status !== '') { $where[] = 'i.izin_status = ?'; $params[] = (int)$status; }
if ($kind === 'leave') $where[] = 'i.izin_jenis_id NOT BETWEEN 100 AND 105';
if ($kind === 'scan') $where[] = 'i.izin_jenis_id BETWEEN 100 AND 105';
$rows = q('SELECT i.*, p.pegawai_nama, k.kat_izin_nama, c.cuti_n_nama FROM izin i
           JOIN pegawai p ON p.pegawai_id = i.pegawai_id
           LEFT JOIN kategori_izin k ON k.kat_izin_id = i.kat_izin_id
           LEFT JOIN cuti_normatif c ON c.cuti_n_id = i.cuti_n_id AND i.cuti_n_id > 0
           WHERE ' . implode(' AND ', $where) . ' ORDER BY i.izin_tgl DESC, p.pegawai_nama LIMIT 500', $params)->fetchAll();

$leaveTypes = array_filter($types, function ($k) { return !is_scan_type($k); }, ARRAY_FILTER_USE_KEY);
$showAdd = get('add') === '1' || $addError;
$back = $_SERVER['REQUEST_URI'];

page_header('Izin', 'leave.php');
?>
<div class="page-head">
  <h1>Izin, Terlambat, Cuti</h1>
  <a class="btn primary" href="leave.php?add=1#add">+ Tambah</a>
</div>

<?php if ($showAdd): ?>
<div class="card" id="add">
  <h2>Tambah Izin</h2>
  <?php if ($addError): ?><div class="flash err"><?= h($addError) ?></div><?php endif; ?>
  <form method="post" class="stack">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="add">
    <div class="grid2">
      <label>Pegawai <?= employee_select('pegawai_id', post('pegawai_id', get('pegawai_id'))) ?></label>
      <label>Jenis izin <select name="izin_jenis_id" required id="jenis"><?= options($leaveTypes, post('izin_jenis_id'), 'Pilih jenis…') ?></select></label>
      <label>Tanggal <input type="date" name="date_from" required value="<?= h(post('date_from', get('date_from', date('Y-m-d')))) ?>"></label>
      <label>s/d <input type="date" name="date_to" required value="<?= h(post('date_to', get('date_to', date('Y-m-d')))) ?>"></label>
      <label>Kategori <select name="kat_izin_id"><?= options(leave_categories(), post('kat_izin_id'), '—') ?></select></label>
      <label id="cuti" style="display:none">Cuti normatif <select name="cuti_n_id"><?= options(lookup('cuti'), post('cuti_n_id')) ?></select></label>
      <label>Jam dari (opsional) <input type="time" name="t1" value="<?= h(post('t1')) ?>"></label>
      <label>Jam sampai (opsional) <input type="time" name="t2" value="<?= h(post('t2')) ?>"></label>
      <label>Catatan <input name="note" maxlength="255" value="<?= h(post('note')) ?>"></label>
    </div>
    <label class="check"><input type="checkbox" name="skip_off" value="1" checked> Lewati hari libur</label>
    <label class="check"><input type="checkbox" name="approved" value="1" checked> Diizinkan</label>
    <div><button class="btn primary">Simpan</button> <a class="btn" href="leave.php">Batal</a></div>
  </form>
  <script>
    (function () {
      var j = document.getElementById('jenis'), c = document.getElementById('cuti');
      function sync() { c.style.display = j.value === '80' ? '' : 'tidak ada'; }
      j.addEventListener('change', sync); sync();
    })();
  </script>
</div>
<?php endif; ?>

<div class="card">
  <form class="inline" method="get">
    <label>Tanggal <input type="date" name="from" value="<?= h($from) ?>"></label>
    <label>s/d <input type="date" name="to" value="<?= h($to) ?>"></label>
    <label>Pegawai <?= employee_select('pegawai_id', $pid, true, false) ?></label>
    <label>Status <select name="status"><?= options(['1' => 'Diizinkan', '0' => 'Not approved'], $status, 'Semua') ?></select></label>
    <label>Tampilkan <select name="kind"><?= options(['leave' => 'Izin / cuti saja', 'scan' => 'Tidak scan saja', 'all' => 'Semua'], $kind) ?></select></label>
    <button class="btn">Cari</button>
  </form>
</div>

<div class="card">
  <p class="muted"><?= count($rows) ?> data<?= count($rows) >= 500 ? ' (first 500 shown; narrow the filter)' : '' ?></p>
  <div class="table-wrap"><table>
    <tr><th>Tanggal</th><th>Pegawai</th><th>Jenis</th><th>Kategori</th><th>Catatan</th><th>Status</th><th></th></tr>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= h(fmt_date($r['izin_tgl'])) ?></td>
        <td><?= h($r['pegawai_nama']) ?></td>
        <td><?= h(isset($types[$r['izin_jenis_id']]) ? $types[$r['izin_jenis_id']] : $r['izin_jenis_id']) ?>
          <?= $r['izin_noscan_time'] ? ' <span class="tag">' . h(substr($r['izin_noscan_time'], 0, 5)) . '</span>' : '' ?>
          <?= $r['izin_tinggal_t1'] ? ' <span class="tag">' . h(substr($r['izin_tinggal_t1'], 0, 5) . '–' . substr((string)$r['izin_tinggal_t2'], 0, 5)) . '</span>' : '' ?>
          <?= $r['cuti_n_nama'] ? ' <span class="tag">' . h($r['cuti_n_nama']) . '</span>' : '' ?></td>
        <td><?= h($r['kat_izin_nama']) ?></td>
        <td><?= h($r['izin_catatan']) ?></td>
        <td><?= $r['izin_status'] ? '<span class="badge b-ok">Diizinkan</span>' : '<span class="badge b-pending">Tidak diizinkan</span>' ?></td>
        <td style="white-space:nowrap">
          <?php foreach ([$r['izin_status'] ? 'unapprove' : 'approve', 'delete'] as $act): ?>
            <form method="post" class="btn-form" <?= $act === 'delete' ? 'onsubmit="return confirm(\'Hapus data ini?\')"' : '' ?>>
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="<?= $act ?>">
              <input type="hidden" name="pegawai_id" value="<?= (int)$r['pegawai_id'] ?>">
              <input type="hidden" name="izin_tgl" value="<?= h($r['izin_tgl']) ?>">
              <input type="hidden" name="izin_jenis_id" value="<?= (int)$r['izin_jenis_id'] ?>">
              <input type="hidden" name="izin_status" value="<?= (int)$r['izin_status'] ?>">
              <input type="hidden" name="izin_urutan" value="<?= (int)$r['izin_urutan'] ?>">
              <input type="hidden" name="back" value="<?= h($back) ?>">
              <button class="btn small <?= $act === 'delete' ? 'danger' : '' ?>"><?= ['approve' => 'Setujui', 'unapprove' => 'Batalkan izin', 'delete' => 'Hapus'][$act] ?></button>
            </form>
          <?php endforeach; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="7" class="muted">Tidak ada data.</td></tr><?php endif; ?>
  </table></div>
</div>
<?php page_footer();
