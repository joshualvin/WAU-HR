<?php
// Add or edit an employee (pegawai). Admins only.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_login('admin');

$id = (int)get('id', post('id', 0));
$emp = $id ? employee($id) : null;
if ($id && !$emp) { flash('Pegawai tidak ditemukan.', 'err'); redirect('employees.php'); }
$schedules = lookup('jadwal');
$currentSched = $id ? q('SELECT jdw_kerja_m_id FROM jdw_kerja_pegawai WHERE pegawai_id = ? ORDER BY jdw_kerja_m_mulai DESC LIMIT 1', [$id])->fetchColumn() : key($schedules);
$hasScans = $emp ? (bool)q('SELECT 1 FROM att_log WHERE pin = ? LIMIT 1', [$emp['pegawai_pin']])->fetchColumn() : false;

$fields = ['pegawai_pin', 'pegawai_nip', 'pegawai_nama', 'pegawai_alias', 'pegawai_telp', 'pegawai_status', 'gender', 'tempat_lahir', 'tgl_lahir',
           'pembagian1_id', 'pembagian2_id', 'pembagian3_id', 'tgl_mulai_kerja', 'tgl_resign'];
$v = [];
foreach ($fields as $f) $v[$f] = $_SERVER['REQUEST_METHOD'] === 'POST' ? post($f) : ($emp ? (string)$emp[$f] : '');
if (!$emp && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $v['pegawai_status'] = '1';
    $v['gender'] = '1';
    $v['tgl_mulai_kerja'] = date('Y-m-d');
    $v['pegawai_pin'] = (string)((int)q('SELECT MAX(CAST(pegawai_pin AS UNSIGNED)) FROM pegawai')->fetchColumn() + 1);
}
$sched = $_SERVER['REQUEST_METHOD'] === 'POST' ? post('jdw_kerja_m_id') : (string)$currentSched;

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    if ($emp && $hasScans) $v['pegawai_pin'] = $emp['pegawai_pin']; // scans are linked by PIN
    foreach (['tgl_lahir', 'tgl_mulai_kerja', 'tgl_resign'] as $d) {
        if ($v[$d] !== '' && !valid_date($v[$d])) $error = 'Periksa tanggal.';
    }
    if (!preg_match('/^\d{1,32}$/', $v['pegawai_pin'])) $error = 'PIN must be digits only (it is the ID on the fingerprint machine).';
    elseif ($v['pegawai_nama'] === '') $error = 'Isi nama.';
    elseif (!in_array($v['pegawai_status'], ['0', '1', '2'], true)) $error = 'Pilih status.';
    elseif (q('SELECT COUNT(*) FROM pegawai WHERE pegawai_pin = ? AND pegawai_id <> ?', [$v['pegawai_pin'], $id])->fetchColumn()) $error = 'PIN sudah dipakai pegawai lain: ' . $v['pegawai_pin'] . '.';
    if ($v['pegawai_status'] === '2' && $v['tgl_resign'] === '') $v['tgl_resign'] = date('Y-m-d');

    if (!$error) {
        $data = [];
        foreach ($fields as $f) $data[$f] = $v[$f] === '' ? null : $v[$f];
        foreach (['pembagian1_id', 'pembagian2_id', 'pembagian3_id'] as $f) $data[$f] = (int)$v[$f];
        $data['gender'] = (int)$v['gender'] ?: 1;
        $data['pegawai_status'] = (int)$v['pegawai_status'];
        if ($data['pegawai_nip'] === null) $data['pegawai_nip'] = $data['pegawai_pin'];
        if ($data['pegawai_alias'] === null) $data['pegawai_alias'] = mb_substr($data['pegawai_nama'], 0, 50);

        $pdo = db();
        $pdo->beginTransaction();
        try {
            if ($emp) {
                $set = implode(', ', array_map(function ($f) { return "$f = ?"; }, array_keys($data)));
                q("UPDATE pegawai SET $set WHERE pegawai_id = ?", array_merge(array_values($data), [$id]));
            } else {
                $id = (int)q('SELECT COALESCE(MAX(pegawai_id), 0) + 1 FROM pegawai FOR UPDATE')->fetchColumn();
                $data['pegawai_id'] = $id;
                $data['tgl_masuk_pertama'] = $data['tgl_mulai_kerja'];
                $cols = implode(', ', array_keys($data));
                q("INSERT INTO pegawai ($cols) VALUES (" . implode(', ', array_fill(0, count($data), '?')) . ')', array_values($data));
                q('INSERT IGNORE INTO pegawai_d (pegawai_id) VALUES (?)', [$id]);
            }
            if ($sched !== '' && (!$emp || (string)$sched !== (string)$currentSched)) {
                q('INSERT IGNORE INTO jdw_kerja_pegawai (pegawai_id, jdw_kerja_m_id, jdw_kerja_m_mulai) VALUES (?, ?, ?)',
                    [$id, (int)$sched, $emp ? date('Y-m-d') : ($data['tgl_mulai_kerja'] ?: date('Y-m-d'))]);
            }
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
        audit($emp ? 'employee_edit' : 'employee_add', $data['pegawai_pin'] . ' ' . $data['pegawai_nama']);
        flash($emp ? 'Pegawai disimpan.' : 'Pegawai ditambahkan. Daftarkan PIN yang sama di mesin agar scan cocok.');
        redirect('employees.php');
    }
}

page_header($emp ? 'Ubah Pegawai' : 'Tambah Pegawai', 'employees.php');
?>
<div class="card" style="max-width:820px">
  <h1><?= $emp ? 'Ubah ' . h($emp['pegawai_nama']) : 'Tambah Pegawai' ?></h1>
  <?php if ($error): ?><div class="flash err"><?= h($error) ?></div><?php endif; ?>
  <form method="post" class="stack">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int)$id ?>">
    <div class="grid2">
      <label>PIN
        <input name="pegawai_pin" required value="<?= h($v['pegawai_pin']) ?>" <?= $hasScans ? 'readonly title="Tidak bisa diubah: scan terhubung ke PIN ini"' : '' ?>></label>
      <label>NIP <input name="pegawai_nip" value="<?= h($v['pegawai_nip']) ?>"></label>
      <label>Nama <input name="pegawai_nama" required maxlength="255" value="<?= h($v['pegawai_nama']) ?>"></label>
      <label>Alias <input name="pegawai_alias" maxlength="50" value="<?= h($v['pegawai_alias']) ?>"></label>
      <label>Jabatan <select name="pembagian1_id"><?= options(lookup('pembagian1'), $v['pembagian1_id'], '—') ?></select></label>
      <label>Departemen <select name="pembagian2_id"><?= options(lookup('pembagian2'), $v['pembagian2_id'], '—') ?></select></label>
      <label>Kantor <select name="pembagian3_id"><?= options(lookup('pembagian3'), $v['pembagian3_id'], '—') ?></select></label>
      <label>Jadwal kerja <select name="jdw_kerja_m_id"><?= options($schedules, $sched, '—') ?></select></label>
      <label>Status <select name="pegawai_status"><?= options(['1' => 'Aktif', '0' => 'Non aktif', '2' => 'Berhenti'], $v['pegawai_status']) ?></select></label>
      <label>Jenis kelamin <select name="gender"><?= options(['1' => 'Laki-laki', '2' => 'Perempuan'], $v['gender']) ?></select></label>
      <label>Tanggal mulai kerja <input type="date" name="tgl_mulai_kerja" value="<?= h($v['tgl_mulai_kerja']) ?>"></label>
      <label>Tanggal berhenti <input type="date" name="tgl_resign" value="<?= h($v['tgl_resign']) ?>"></label>
      <label>No. Telp <input name="pegawai_telp" maxlength="20" value="<?= h($v['pegawai_telp']) ?>"></label>
      <label>Tempat lahir <input name="tempat_lahir" maxlength="50" value="<?= h($v['tempat_lahir']) ?>"></label>
      <label>Tanggal lahir <input type="date" name="tgl_lahir" value="<?= h($v['tgl_lahir']) ?>"></label>
    </div>
    <div><button class="btn primary">Simpan</button> <a class="btn" href="employees.php">Batal</a></div>
  </form>
</div>
<?php page_footer();
