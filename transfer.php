<?php
// HR → Karyawan → Mutasi karyawan: change an employee's Jabatan, Departemen, Kantor or supervisor from a date on, with history
// (like Talenta's employee transfer). A transfer dated today or earlier takes effect at once, a later one when its day comes.
// Admins only.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/portal.php';
require __DIR__ . '/inc/hris.php';
require __DIR__ . '/inc/people.php';
require __DIR__ . '/inc/company.php';
require __DIR__ . '/inc/transfer.php';
$me = require_login('admin');
ensure_web_tables();
ensure_people_tables();
ensure_company_tables();
ensure_transfer_tables();
transfers_apply_due();
$today = date('Y-m-d');
$csrf = csrf_field();
$p = get('p', 'list') === 'new' ? 'new' : 'list';

// ---------- actions ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = post('action');
    if ($action === 'create') {
        $in = ['pegawai_id' => (int)post('pegawai_id'), 'type' => post('type'), 'effective' => post('effective'),
               'jab' => post('jab'), 'dep' => post('dep'), 'kan' => post('kan'), 'atasan' => post('atasan'), 'note' => trim(post('note'))];
        $err = transfer_validate($in, transfer_current($in['pegawai_id']), $today, supervisors());   // before anything is saved
        $docId = null;
        $docFile = null;
        if (!$err && isset($_FILES['file']) && $_FILES['file']['name'] !== '') {   // optional SK, kept as one of the employee's documents
            list($docFile, $uerr) = save_doc_file($_FILES['file']);
            if ($uerr) $err = $uerr;
            elseif ($docFile) {
                q('INSERT INTO web_emp_doc (pegawai_id, kind, title, file, expires, uploaded_by, created_at) VALUES (?, ?, ?, ?, NULL, ?, ?)',
                    [$in['pegawai_id'], 'sk', 'SK mutasi ' . date('d-m-Y', strtotime($in['effective'])), $docFile, actor(), date('Y-m-d H:i:s')]);
                $docId = (int)db()->lastInsertId();
            }
        }
        if (!$err) list($id, $err) = transfer_create($in, actor(), $docId);
        if ($err) {
            if ($docId) { q('DELETE FROM web_emp_doc WHERE id = ?', [$docId]); @unlink(DOC_DIR . '/' . basename($docFile)); }
            flash($err, 'err');
            $p = 'new';   // stay on the form with what was typed
        } else {
            $t = q('SELECT * FROM web_transfer WHERE id = ?', [$id])->fetch();
            audit('transfer_create', transfer_code($id, $t['created_at']) . ' pegawai ' . $in['pegawai_id'] . ' ' . $t['status']);
            flash(transfer_code($id, $t['created_at']) . ($t['status'] === 'applied' ? ' berlaku sekarang.' : ' dijadwalkan untuk ' . fmt_date($in['effective']) . '.'));
            redirect('transfer.php');
        }
    } elseif ($action === 'cancel') {
        $done = transfer_cancel((int)post('id'));
        if ($done) flash('Mutasi dibatalkan.'); else flash('Hanya mutasi yang belum berlaku yang bisa dibatalkan.', 'err');
        redirect('transfer.php');
    }
}

$names = transfer_names();

// ======================================================================= list (and CSV)
if ($p === 'list'):
    $f = ['status' => isset(transfer_statuses()[get('status')]) ? get('status') : '', 'type' => isset(transfer_types()[get('type')]) ? get('type') : '', 'pegawai_id' => (int)get('pegawai_id')];
    $where = ['1 = 1'];
    $params = [];
    if ($f['status'] !== '') { $where[] = 't.status = ?'; $params[] = $f['status']; }
    if ($f['type'] !== '') { $where[] = 't.type = ?'; $params[] = $f['type']; }
    if ($f['pegawai_id']) { $where[] = 't.pegawai_id = ?'; $params[] = $f['pegawai_id']; }
    $rows = q('SELECT t.*, p.pegawai_nama, p.pegawai_nip, p.pegawai_pin FROM web_transfer t JOIN pegawai p ON p.pegawai_id = t.pegawai_id WHERE ' . implode(' AND ', $where)
            . ' ORDER BY t.effective_date DESC, t.id DESC LIMIT 1000', $params)->fetchAll();
    $counts = q('SELECT status, COUNT(*) FROM web_transfer GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
    if (get('csv') === '1') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="mutasi_karyawan_' . $today . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['ID transaksi', 'NIP', 'PIN', 'Nama karyawan', 'Jenis', 'Perubahan', 'Dibuat', 'Berlaku', 'Status', 'Dibuat oleh', 'Catatan']);
        foreach ($rows as $r) fputcsv($out, [transfer_code($r['id'], $r['created_at']), $r['pegawai_nip'], $r['pegawai_pin'], $r['pegawai_nama'], transfer_types()[$r['type']] ?? $r['type'],
            transfer_summary($r, $names), date('d-m-Y', strtotime($r['created_at'])), date('d-m-Y', strtotime($r['effective_date'])), transfer_statuses()[$r['status']][0] ?? $r['status'], $r['created_by'], $r['note']]);
        audit('transfer_export', count($rows) . ' baris');
        exit;
    }
    $all = employees(false);
    hris_header('Mutasi karyawan', 'employee', 'transfer.php', 'Karyawan',
        '<a class="btn" href="transfer.php?' . h(http_build_query(array_filter($f) + ['csv' => 1])) . '">Ekspor CSV</a> <a class="btn primary" href="transfer.php?p=new">+ Buat mutasi</a>'); ?>
  <div class="counters" style="grid-template-columns:repeat(4,minmax(0,1fr))">
    <a class="counter<?= $f['status'] === '' ? ' on' : '' ?>" href="transfer.php"><b><?= (int)array_sum($counts) ?></b><span>Semua</span></a>
    <?php foreach (transfer_statuses() as $k => $s): ?><a class="counter<?= $f['status'] === $k ? ' on' : '' ?>" href="transfer.php?status=<?= $k ?>"><b><?= (int)(isset($counts[$k]) ? $counts[$k] : 0) ?></b><span><?= h($s[0]) ?></span></a><?php endforeach; ?>
  </div>
  <form class="toolbar" method="get">
    <select name="status"><option value="">Semua status</option><?php foreach (transfer_statuses() as $k => $s): ?><option value="<?= $k ?>"<?= $f['status'] === $k ? ' selected' : '' ?>><?= h($s[0]) ?></option><?php endforeach; ?></select>
    <select name="type"><option value="">Semua jenis</option><?php foreach (transfer_types() as $k => $l): ?><option value="<?= $k ?>"<?= $f['type'] === $k ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select>
    <select name="pegawai_id"><option value="">Semua karyawan</option><?php foreach ($all as $e): ?><option value="<?= (int)$e['pegawai_id'] ?>"<?= $f['pegawai_id'] === (int)$e['pegawai_id'] ? ' selected' : '' ?>><?= h($e['pegawai_nama']) ?></option><?php endforeach; ?></select>
    <button class="btn">Terapkan</button></form>
  <div class="tablecard"><div class="tablescroll"><table>
    <thead><tr><th>ID transaksi</th><th>Karyawan</th><th>Jenis</th><th>Perubahan</th><th>Dibuat</th><th>Berlaku</th><th>Status</th><th>Lampiran</th><th class="actions">Aksi</th></tr></thead><tbody>
    <?php foreach ($rows as $r): $st = transfer_statuses()[$r['status']] ?? [$r['status'], 'grey']; ?>
      <tr><td style="white-space:nowrap"><b><?= h(transfer_code($r['id'], $r['created_at'])) ?></b></td>
        <td><a href="hr.php?m=employee&id=<?= (int)$r['pegawai_id'] ?>&tab=employment"><b><?= h($r['pegawai_nama']) ?></b></a><br><small class="muted">PIN <?= h($r['pegawai_pin']) ?></small></td>
        <td><?= h(transfer_types()[$r['type']] ?? $r['type']) ?></td>
        <td><?php foreach (transfer_changes($r, $names) as $c): ?><div><span class="muted"><?= h($c[0]) ?>:</span> <?= h($c[1]) ?> → <b><?= h($c[2]) ?></b></div><?php endforeach; ?><?php if ($r['note'] !== ''): ?><small class="muted"><?= h($r['note']) ?></small><?php endif; ?></td>
        <td class="muted" style="white-space:nowrap"><?= h(date('d-m-Y', strtotime($r['created_at']))) ?><br><small><?= h($r['created_by']) ?></small></td>
        <td style="white-space:nowrap"><?= h(date('d-m-Y', strtotime($r['effective_date']))) ?></td>
        <td><span class="pill <?= $st[1] ?>"><?= h($st[0]) ?></span></td>
        <td><?= $r['doc_id'] ? '<a class="btn small" target="_blank" href="doc.php?id=' . (int)$r['doc_id'] . '">Lihat SK</a>' : '<span class="muted">—</span>' ?></td>
        <td class="actions"><?php if ($r['status'] === 'scheduled'): ?><form method="post" class="inline" onsubmit="return confirm('Batalkan <?= h(transfer_code($r['id'], $r['created_at'])) ?>?')"><?= $csrf ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn small ghost">Batalkan</button></form><?php endif; ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <?php if (!$rows): ?><div class="empty"><strong>Belum ada mutasi</strong>Riwayat promosi, mutasi dan rotasi karyawan muncul di sini.</div><?php endif; ?>
    <div class="pager"><span><?= count($rows) ?> mutasi</span></div></div>
<?php
// ======================================================================= create
else:
    $people = employees(true);
    $nameOf = function ($map, $id) { return $id !== null && $id !== '' && isset($map[(int)$id]) ? $map[(int)$id] : '—'; };
    $now = [];   // what each employee has now, for the hint under the form
    foreach ($people as $e) {
        $c = transfer_current($e['pegawai_id']);
        $now[(int)$e['pegawai_id']] = ['jab' => $nameOf($names['jab'], $c['jab']), 'dep' => $nameOf($names['dep'], $c['dep']), 'kan' => $nameOf($names['kan'], $c['kan']), 'atasan' => $nameOf($names['atasan'], $c['atasan'])];
    }
    $v = ['pegawai_id' => post('pegawai_id', get('pegawai_id')), 'type' => post('type', 'mutasi'), 'effective' => post('effective', $today), 'jab' => post('jab'), 'dep' => post('dep'), 'kan' => post('kan'), 'atasan' => post('atasan'), 'note' => post('note')];
    $sel = function ($map, $cur) { $o = ''; foreach ($map as $id => $name) $o .= '<option value="' . (int)$id . '"' . ((string)$cur === (string)$id ? ' selected' : '') . '>' . h($name) . '</option>'; return $o; };
    hris_header('Buat mutasi', 'employee', 'transfer.php', '<a href="transfer.php">Mutasi karyawan</a>'); ?>
  <form method="post" enctype="multipart/form-data" class="section"><?= $csrf ?><input type="hidden" name="action" value="create">
    <div class="row2">
      <label class="field"><span>Karyawan <i>*</i></span><select name="pegawai_id" id="emp" required><option value="">Pilih karyawan…</option>
        <?php foreach ($people as $e): ?><option value="<?= (int)$e['pegawai_id'] ?>"<?= (string)$v['pegawai_id'] === (string)$e['pegawai_id'] ? ' selected' : '' ?>><?= h($e['pegawai_nama']) ?> (<?= h($e['pegawai_pin']) ?>)</option><?php endforeach; ?></select>
        <small id="nowinfo" class="muted"></small></label>
      <label class="field"><span>Jenis</span><select name="type"><?php foreach (transfer_types() as $k => $l): ?><option value="<?= $k ?>"<?= $v['type'] === $k ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></label>
      <label class="field"><span>Tanggal berlaku</span><input type="date" name="effective" required value="<?= h($v['effective']) ?>"><small>Hari ini atau lampau: langsung berlaku. Tanggal depan: dijadwalkan dan diterapkan otomatis.</small></label>
    </div>
    <div class="row2">
      <label class="field"><span>Jabatan baru</span><select name="jab"><option value="">— tidak berubah —</option><?= $sel($names['jab'], $v['jab']) ?></select></label>
      <label class="field"><span>Departemen baru</span><select name="dep"><option value="">— tidak berubah —</option><?= $sel($names['dep'], $v['dep']) ?></select></label>
      <label class="field"><span>Kantor baru</span><select name="kan"><option value="">— tidak berubah —</option><?= $sel($names['kan'], $v['kan']) ?></select></label>
      <label class="field"><span>Atasan langsung baru</span><select name="atasan"><option value="">— tidak berubah —</option><option value="0"<?= (string)$v['atasan'] === '0' ? ' selected' : '' ?>>— tanpa atasan —</option>
        <?php foreach ($people as $e): ?><option value="<?= (int)$e['pegawai_id'] ?>"<?= (string)$v['atasan'] === (string)$e['pegawai_id'] ? ' selected' : '' ?>><?= h($e['pegawai_nama']) ?></option><?php endforeach; ?></select></label>
    </div>
    <label class="field"><span>Catatan (opsional)</span><input type="text" name="note" maxlength="255" value="<?= h($v['note']) ?>" placeholder="mis. Promosi setelah penilaian kinerja Oktober"></label>
    <label class="field"><span>Lampiran SK / surat mutasi (opsional)</span><input type="file" name="file" accept=".pdf,.jpg,.jpeg,.png"><small>PDF atau foto. Disimpan juga di Dokumen karyawan.</small></label>
    <div class="formfoot"><a class="btn" href="transfer.php">Batal</a><button class="btn primary">Simpan mutasi</button></div></form>
  <script>
    var NOW = <?= json_encode($now) ?>;
    var emp = document.getElementById('emp'), info = document.getElementById('nowinfo');
    function showNow() { var c = NOW[emp.value]; info.textContent = c ? 'Saat ini: ' + c.jab + ' · ' + c.dep + ' · ' + c.kan + ' · atasan ' + c.atasan : ''; }
    emp.addEventListener('change', showNow); showNow();
  </script>
<?php endif;
hris_footer();
