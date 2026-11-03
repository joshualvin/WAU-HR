<?php
// HR → Perusahaan: announcements and calendar, org chart (supervisors), assets. Admins only.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/portal.php';
require __DIR__ . '/inc/hris.php';
require __DIR__ . '/inc/people.php';
require __DIR__ . '/inc/company.php';
$me = require_login('admin');
ensure_web_tables();
ensure_people_tables();
ensure_company_tables();
$today = date('Y-m-d');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = post('action');
    if ($action === 'ann_save') {
        if (trim(post('title')) === '') { flash('Isi judul pengumuman.', 'err'); redirect('company.php'); }
        $vals = [mb_substr(trim(post('title')), 0, 150), post('body'), valid_date(post('event_date')) ? post('event_date') : null, valid_date(post('show_until')) ? post('show_until') : null, post('pinned') ? 1 : 0];
        if ((int)post('id')) q('UPDATE web_announcement SET title = ?, body = ?, event_date = ?, show_until = ?, pinned = ? WHERE id = ?', array_merge($vals, [(int)post('id')]));
        else q('INSERT INTO web_announcement (title, body, event_date, show_until, pinned, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)', array_merge($vals, [actor(), date('Y-m-d H:i:s')]));
        audit('announcement_save', $vals[0]);
        flash('Pengumuman disimpan; tampil di Portal Pegawai.');
        redirect('company.php');
    } elseif ($action === 'ann_delete') {
        q('DELETE FROM web_announcement WHERE id = ?', [(int)post('id')]);
        flash('Pengumuman dihapus.');
        redirect('company.php');
    } elseif ($action === 'org_save') {
        $map = supervisors();
        $bad = [];
        foreach (isset($_POST['atasan']) ? (array)$_POST['atasan'] : [] as $id => $boss) $map[(int)$id] = (int)$boss ?: null;
        $map = array_filter($map);
        foreach ($map as $id => $boss) if (supervisor_loop($id, $boss, $map)) $bad[] = $id;
        if ($bad) { flash('Atasan tidak disimpan: ada karyawan yang menjadi atasan dari atasannya sendiri (melingkar). Periksa lagi.', 'err'); redirect('company.php?p=org'); }
        foreach (isset($_POST['atasan']) ? (array)$_POST['atasan'] : [] as $id => $boss) {
            q('INSERT IGNORE INTO web_emp_profile (pegawai_id) VALUES (?)', [(int)$id]);
            q('UPDATE web_emp_profile SET atasan_id = ? WHERE pegawai_id = ?', [(int)$boss ?: null, (int)$id]);
        }
        audit('org_save', count($map) . ' atasan');
        flash('Struktur organisasi disimpan.');
        redirect('company.php?p=org');
    } elseif ($action === 'asset_save') {
        if (trim(post('name')) === '') { flash('Isi nama aset.', 'err'); redirect('company.php?p=assets'); }
        $vals = [mb_substr(post('code'), 0, 30), mb_substr(trim(post('name')), 0, 120), mb_substr(post('category'), 0, 30), mb_substr(post('serial'), 0, 60),
                 valid_date(post('bought')) ? post('bought') : null, (int)preg_replace('/\D/', '', post('value')), mb_substr(post('condition_note'), 0, 120) ?: 'Baik', mb_substr(post('note'), 0, 255)];
        if ((int)post('id')) q('UPDATE web_asset SET code = ?, name = ?, category = ?, serial = ?, bought = ?, value = ?, condition_note = ?, note = ? WHERE id = ?', array_merge($vals, [(int)post('id')]));
        else q('INSERT INTO web_asset (code, name, category, serial, bought, value, condition_note, note) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', $vals);
        audit('asset_save', $vals[1]);
        flash('Aset disimpan.');
        redirect('company.php?p=assets');
    } elseif ($action === 'asset_assign' || $action === 'asset_return') {
        $a = q('SELECT * FROM web_asset WHERE id = ?', [(int)post('id')])->fetch();
        $date = valid_date(post('date')) ? post('date') : $today;
        if ($a && $action === 'asset_assign' && employee(post('pegawai_id'))) {
            q('UPDATE web_asset SET pegawai_id = ?, assigned_at = ? WHERE id = ?', [(int)post('pegawai_id'), $date, $a['id']]);
            q('INSERT INTO web_asset_log (asset_id, pegawai_id, action, at, note, created_by) VALUES (?, ?, ?, ?, ?, ?)', [$a['id'], (int)post('pegawai_id'), 'assign', $date, mb_substr(post('note'), 0, 200), actor()]);
            flash('Aset diserahkan.');
        } elseif ($a && $action === 'asset_return' && $a['pegawai_id']) {
            q('UPDATE web_asset SET pegawai_id = NULL, assigned_at = NULL, condition_note = ? WHERE id = ?', [mb_substr(post('condition_note'), 0, 120) ?: $a['condition_note'], $a['id']]);
            q('INSERT INTO web_asset_log (asset_id, pegawai_id, action, at, note, created_by) VALUES (?, ?, ?, ?, ?, ?)', [$a['id'], $a['pegawai_id'], 'return', $date, mb_substr(post('condition_note'), 0, 200), actor()]);
            flash('Aset dikembalikan.');
        }
        redirect('company.php?p=assets');
    }
}

$csrf = csrf_field();
$p = get('p', 'announce');
if ($p === 'org'):
    $people = q('SELECT p.pegawai_id, p.pegawai_nama, p.pegawai_pin, p1.pembagian1_nama jab, p2.pembagian2_nama dep FROM pegawai p
                 LEFT JOIN pembagian1 p1 ON p1.pembagian1_id = p.pembagian1_id LEFT JOIN pembagian2 p2 ON p2.pembagian2_id = p.pembagian2_id WHERE p.pegawai_status = 1 ORDER BY p.pegawai_nama')->fetchAll(PDO::FETCH_UNIQUE);
    $map = array_intersect_key(supervisors(), $people);
    $tree = function ($boss, $depth) use (&$tree, $map, $people) {
        $kids = array_keys(array_filter($map, function ($b) use ($boss) { return (int)$b === (int)$boss; }));
        if (!$kids) return '';
        $out = '<ul class="org">';
        foreach ($kids as $k) $out .= '<li><span class="node"><b>' . h($people[$k]['pegawai_nama']) . '</b><small>' . h(trim($people[$k]['jab'] . ' · ' . $people[$k]['dep'], ' ·')) . '</small></span>' . ($depth < 10 ? $tree($k, $depth + 1) : '') . '</li>';
        return $out . '</ul>';
    };
    $roots = array_keys(array_filter($people, function ($e, $id) use ($map) { return !isset($map[$id]); }, ARRAY_FILTER_USE_BOTH));
    hris_header('Struktur organisasi', 'company', 'company.php?p=org', 'Perusahaan'); ?>
  <style>.org{list-style:none;padding-left:22px;margin:0;border-left:1px dashed var(--line-strong)}.org li{margin:6px 0}.node{display:inline-grid;padding:6px 10px;border:1px solid var(--line);border-radius:6px;background:var(--bg)}.node small{color:var(--muted)}</style>
  <div class="card"><h2>Bagan</h2>
    <ul class="org" style="border:0;padding:0"><?php foreach ($roots as $r): ?><li><span class="node"><b><?= h($people[$r]['pegawai_nama']) ?></b><small><?= h(trim($people[$r]['jab'] . ' · ' . $people[$r]['dep'], ' ·')) ?></small></span><?= $tree($r, 1) ?></li><?php endforeach; ?></ul>
    <p class="muted">Karyawan tanpa atasan tampil paling atas. Atasan juga yang pertama menyetujui pengajuan bawahannya.</p></div>
  <form method="post" style="margin-top:16px"><?= $csrf ?><input type="hidden" name="action" value="org_save">
  <div class="tablecard"><div class="tablescroll"><table><thead><tr><th>Karyawan</th><th>Jabatan</th><th>Atasan langsung</th></tr></thead><tbody>
    <?php foreach ($people as $id => $e): ?><tr><td><b><?= h($e['pegawai_nama']) ?></b> <span class="muted"><?= h($e['pegawai_pin']) ?></span></td><td><?= h(trim($e['jab'] . ' · ' . $e['dep'], ' ·')) ?></td>
      <td><select name="atasan[<?= (int)$id ?>]"><option value="0">— tidak ada —</option><?php foreach ($people as $bid => $b): if ($bid === $id) continue; ?><option value="<?= (int)$bid ?>"<?= isset($map[$id]) && (int)$map[$id] === (int)$bid ? ' selected' : '' ?>><?= h($b['pegawai_nama']) ?></option><?php endforeach; ?></select></td></tr><?php endforeach; ?>
  </tbody></table></div></div>
  <div class="formfoot"><button class="btn primary">Simpan struktur</button></div></form>
<?php
elseif ($p === 'assets'):
    $f = get('f', 'all');
    $rows = q('SELECT a.*, p.pegawai_nama FROM web_asset a LEFT JOIN pegawai p ON p.pegawai_id = a.pegawai_id WHERE a.active = 1' . ($f === 'out' ? ' AND a.pegawai_id IS NOT NULL' : ($f === 'free' ? ' AND a.pegawai_id IS NULL' : '')) . ' ORDER BY a.category, a.name')->fetchAll();
    $edit = get('id') ? q('SELECT * FROM web_asset WHERE id = ?', [(int)get('id')])->fetch() : null;
    $people = employees(true);
    hris_header('Aset', 'company', 'company.php?p=assets', 'Perusahaan'); ?>
  <div class="toolbar" style="margin-bottom:10px"><?php foreach (['all' => 'Semua', 'out' => 'Dipakai karyawan', 'free' => 'Tersedia'] as $k => $l): ?><a class="btn small<?= $f === $k ? ' primary' : '' ?>" href="company.php?p=assets&f=<?= $k ?>"><?= $l ?></a><?php endforeach; ?></div>
  <div class="tablecard"><div class="tablescroll"><table>
    <thead><tr><th>Kode</th><th>Aset</th><th>Kategori</th><th>No. seri</th><th>Kondisi</th><th>Dipakai oleh</th><th class="actions">Aksi</th></tr></thead><tbody>
    <?php foreach ($rows as $a): ?><tr><td class="muted"><?= h($a['code']) ?></td><td><b><?= h($a['name']) ?></b></td><td><?= h($a['category']) ?></td><td><?= h($a['serial']) ?></td><td><?= h($a['condition_note']) ?></td>
      <td><?= $a['pegawai_id'] ? '<a href="hr.php?m=employee&id=' . (int)$a['pegawai_id'] . '&tab=employment">' . h($a['pegawai_nama']) . '</a> <span class="muted">sejak ' . h(date('d-m-Y', strtotime($a['assigned_at']))) . '</span>' : '<span class="pill ok">tersedia</span>' ?></td>
      <td class="actions" style="white-space:nowrap"><form method="post" class="inline"><?= $csrf ?><input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
        <?php if ($a['pegawai_id']): ?><input type="text" name="condition_note" placeholder="Kondisi kembali" style="width:120px"><button class="btn small" name="action" value="asset_return">Kembalikan</button>
        <?php else: ?><select name="pegawai_id" style="width:140px"><option value="">Serahkan ke…</option><?php foreach ($people as $e): ?><option value="<?= (int)$e['pegawai_id'] ?>"><?= h($e['pegawai_nama']) ?></option><?php endforeach; ?></select><button class="btn small" name="action" value="asset_assign">Serahkan</button><?php endif; ?></form>
        <a class="btn small" href="company.php?p=assets&id=<?= (int)$a['id'] ?>#form">Ubah</a></td></tr><?php endforeach; ?>
    </tbody></table></div><?php if (!$rows): ?><div class="empty"><strong>Belum ada aset</strong></div><?php endif; ?></div>
  <form method="post" class="section" id="form" style="margin-top:16px"><?= $csrf ?><input type="hidden" name="action" value="asset_save"><input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : 0 ?>">
    <h2><?= $edit ? 'Ubah aset' : 'Tambah aset' ?></h2>
    <div class="row2"><label class="field"><span>Nama</span><input type="text" name="name" required value="<?= h($edit ? $edit['name'] : '') ?>"></label>
      <label class="field"><span>Kode</span><input type="text" name="code" value="<?= h($edit ? $edit['code'] : '') ?>"></label>
      <label class="field"><span>Kategori</span><select name="category"><?php foreach (asset_categories() as $c): ?><option<?= $edit && $edit['category'] === $c ? ' selected' : '' ?>><?= h($c) ?></option><?php endforeach; ?></select></label>
      <label class="field"><span>No. seri / plat</span><input type="text" name="serial" value="<?= h($edit ? $edit['serial'] : '') ?>"></label>
      <label class="field"><span>Tanggal beli</span><input type="date" name="bought" value="<?= h($edit ? $edit['bought'] : '') ?>"></label>
      <label class="field"><span>Nilai (Rp)</span><input type="text" name="value" inputmode="numeric" value="<?= $edit && $edit['value'] ? h(number_format($edit['value'], 0, ',', '.')) : '' ?>"></label>
      <label class="field"><span>Kondisi</span><input type="text" name="condition_note" value="<?= h($edit ? $edit['condition_note'] : 'Baik') ?>"></label>
      <label class="field"><span>Catatan</span><input type="text" name="note" value="<?= h($edit ? $edit['note'] : '') ?>"></label></div>
    <div class="formfoot"><button class="btn primary">Simpan</button></div></form>
<?php
else:
    $anns = q('SELECT * FROM web_announcement ORDER BY pinned DESC, created_at DESC LIMIT 100')->fetchAll();
    $edit = get('id') ? q('SELECT * FROM web_announcement WHERE id = ?', [(int)get('id')])->fetch() : null;
    hris_header('Pengumuman & kalender', 'company', 'company.php', 'Perusahaan'); ?>
  <div class="grid3" style="grid-template-columns:minmax(0,1.4fr) minmax(0,1fr)">
    <div>
      <form method="post" class="section" style="max-width:none"><?= $csrf ?><input type="hidden" name="action" value="ann_save"><input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : 0 ?>">
        <h2><?= $edit ? 'Ubah pengumuman' : 'Pengumuman baru' ?></h2>
        <label class="field"><span>Judul</span><input type="text" name="title" required maxlength="150" value="<?= h($edit ? $edit['title'] : '') ?>"></label>
        <label class="field"><span>Isi</span><textarea name="body" rows="5"><?= h($edit ? $edit['body'] : '') ?></textarea></label>
        <div class="row2"><label class="field"><span>Tanggal acara (opsional, masuk kalender)</span><input type="date" name="event_date" value="<?= h($edit ? $edit['event_date'] : '') ?>"></label>
          <label class="field"><span>Tampil sampai (kosong = terus)</span><input type="date" name="show_until" value="<?= h($edit ? $edit['show_until'] : '') ?>"></label></div>
        <label style="display:flex;gap:6px;align-items:center"><input type="checkbox" name="pinned" value="1"<?= $edit && $edit['pinned'] ? ' checked' : '' ?>> Sematkan di atas</label>
        <div class="formfoot"><button class="btn primary">Simpan</button></div></form>
      <div class="tablecard" style="margin-top:16px"><table><thead><tr><th>Pengumuman</th><th>Acara</th><th>Tampil sampai</th><th class="actions"></th></tr></thead><tbody>
        <?php foreach ($anns as $a): ?><tr><td><?= $a['pinned'] ? '📌 ' : '' ?><b><?= h($a['title']) ?></b><br><small class="muted"><?= h(mb_strimwidth($a['body'], 0, 90, '…')) ?></small></td>
          <td><?= $a['event_date'] ? h(date('d-m-Y', strtotime($a['event_date']))) : '' ?></td><td><?= $a['show_until'] ? h(date('d-m-Y', strtotime($a['show_until']))) : '' ?></td>
          <td class="actions" style="white-space:nowrap"><a class="btn small" href="company.php?id=<?= (int)$a['id'] ?>">Ubah</a><form method="post" class="inline" onsubmit="return confirm('Hapus?')"><?= $csrf ?><input type="hidden" name="action" value="ann_delete"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><button class="btn small ghost">Hapus</button></form></td></tr><?php endforeach; ?>
      </tbody></table><?php if (!$anns): ?><div class="empty"><strong>Belum ada pengumuman</strong></div><?php endif; ?></div>
    </div>
    <div class="card"><h2>Kalender 60 hari</h2><div class="list">
      <?php foreach (company_calendar($today, 60) as $c): list($cls, $lbl) = calendar_kind_label($c[1]); ?><div class="li"><span class="code"><?= date('d', strtotime($c[0])) ?></span>
        <div class="meta"><b><?= h($c[2]) ?></b><small><?= h(fmt_date($c[0])) ?> · <span class="pill <?= $cls ?>"><?= $lbl ?></span></small></div></div><?php endforeach; ?></div></div>
  </div>
<?php
endif;
hris_footer();
