<?php
// Simple lists: positions, departments, locations, leave reasons, statutory leave. Admins only.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_login('admin');

$lists = [
    'pembagian1' => ['Jabatan', 'pembagian1_id', ['pembagian1_nama' => 'Nama', 'pembagian1_ket' => 'Keterangan'], ['pegawai', 'pembagian1_id']],
    'pembagian2' => ['Departemen', 'pembagian2_id', ['pembagian2_nama' => 'Nama', 'pembagian2_ket' => 'Keterangan'], ['pegawai', 'pembagian2_id']],
    'pembagian3' => ['Kantor', 'pembagian3_id', ['pembagian3_nama' => 'Nama', 'pembagian3_ket' => 'Keterangan'], ['pegawai', 'pembagian3_id']],
    'kategori_izin' => ['Kategori Izin', 'kat_izin_id', ['kat_izin_nama' => 'Nama'], ['izin', 'kat_izin_id']],
    'cuti_normatif' => ['Cuti Normatif', 'cuti_n_id', ['cuti_n_nama' => 'Nama', 'cuti_n_lama' => 'Lama (hari)'], ['izin', 'cuti_n_id']],
];
$t = get('t', post('t', 'pembagian1'));
if (!isset($lists[$t])) $t = 'pembagian1';
list($title, $pk, $cols, $usedIn) = $lists[$t];

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = post('action');
    $id = (int)post('id');
    $vals = [];
    foreach ($cols as $c => $label) $vals[$c] = post($c);
    if ($action === 'save') {
        $first = key($cols);
        if ($vals[$first] === '') $error = 'Isi nama.';
        elseif (isset($vals['cuti_n_lama']) && !ctype_digit($vals['cuti_n_lama'])) $error = 'Jumlah hari harus angka.';
        else {
            if ($id) {
                q("UPDATE $t SET " . implode(', ', array_map(function ($c) { return "$c = ?"; }, array_keys($vals))) . " WHERE $pk = ?",
                    array_merge(array_values($vals), [$id]));
            } else {
                $id = (int)q("SELECT COALESCE(MAX($pk), 0) + 1 FROM $t")->fetchColumn();
                q("INSERT INTO $t ($pk, " . implode(', ', array_keys($vals)) . ') VALUES (?' . str_repeat(', ?', count($vals)) . ')',
                    array_merge([$id], array_values($vals)));
            }
            audit('master_save', "$t $id " . $vals[$first]);
            flash('Disimpan.');
            redirect("master.php?t=$t");
        }
    } elseif ($action === 'delete') {
        $used = q("SELECT COUNT(*) FROM {$usedIn[0]} WHERE {$usedIn[1]} = ?", [$id])->fetchColumn();
        if ($used) $error = "Still used by $used record(s), so it can't be deleted.";
        else {
            q("DELETE FROM $t WHERE $pk = ?", [$id]);
            audit('master_delete', "$t $id");
            flash('Dihapus.');
            redirect("master.php?t=$t");
        }
    }
}

$rows = q("SELECT * FROM $t ORDER BY $pk")->fetchAll();
$edit = null;
if (get('edit') !== '') foreach ($rows as $r) if ((string)$r[$pk] === get('edit')) $edit = $r;

page_header($title, 'settings.php');
?>
<div class="page-head">
  <h1><?= h($title) ?></h1>
  <div><?php foreach ($lists as $k => $l): ?><a class="btn small <?= $k === $t ? 'primary' : '' ?>" href="master.php?t=<?= $k ?>"><?= h($l[0]) ?></a> <?php endforeach; ?></div>
</div>
<?php if ($error): ?><div class="flash err"><?= h($error) ?></div><?php endif; ?>
<div class="row">
  <div class="card">
    <div class="table-wrap"><table>
      <tr><th>ID</th><?php foreach ($cols as $label): ?><th><?= h($label) ?></th><?php endforeach; ?><th></th></tr>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= h($r[$pk]) ?></td>
          <?php foreach ($cols as $c => $label): ?><td><?= h($r[$c]) ?></td><?php endforeach; ?>
          <td style="white-space:nowrap">
            <a class="btn small" href="master.php?t=<?= $t ?>&edit=<?= (int)$r[$pk] ?>">Ubah</a>
            <form method="post" class="btn-form" onsubmit="return confirm('Hapus data ini?')">
              <?= csrf_field() ?><input type="hidden" name="t" value="<?= $t ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r[$pk] ?>">
              <button class="btn small danger">Hapus</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </table></div>
  </div>
  <div class="card" style="flex:0 1 320px">
    <h2><?= $edit ? 'Ubah' : 'Tambah' ?></h2>
    <form method="post" class="stack">
      <?= csrf_field() ?>
      <input type="hidden" name="t" value="<?= $t ?>"><input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?= $edit ? (int)$edit[$pk] : 0 ?>">
      <?php foreach ($cols as $c => $label): ?>
        <label><?= h($label) ?> <input name="<?= $c ?>" value="<?= h($edit ? $edit[$c] : post($c)) ?>" <?= $c === key($cols) ? 'required' : '' ?>></label>
      <?php endforeach; ?>
      <div><button class="btn primary">Simpan</button> <?php if ($edit): ?><a class="btn" href="master.php?t=<?= $t ?>">Batal</a><?php endif; ?></div>
    </form>
  </div>
</div>
<?php page_footer();
