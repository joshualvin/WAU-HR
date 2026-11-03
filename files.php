<?php
// HR → Perusahaan → File: company files (policies, SOPs, forms, templates) with sharing to the employees. Admins only.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/portal.php';
require __DIR__ . '/inc/hris.php';
require __DIR__ . '/inc/files.php';
$me = require_login('admin');
ensure_files_tables();
$csrf = csrf_field();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = post('action');
    if ($action === 'upload') {
        $u = isset($_FILES['file']) ? $_FILES['file'] : null;
        list($stored, $err) = save_upload($u, FILES_DIR, files_types(), 'File', 'PDF, foto (JPG, PNG) atau dokumen Office (DOCX, XLSX, PPTX)');
        if ($err || $stored === '') flash($err ?: 'Pilih file.', 'err');
        else {
            $orig = mb_substr(basename(str_replace('\\', '/', (string)$u['name'])), 0, 150);
            $title = trim(post('title')) !== '' ? mb_substr(trim(post('title')), 0, 150) : $orig;
            q('INSERT INTO web_file (title, category, file, orig, size, shared, uploaded_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$title, isset(files_categories()[post('category')]) ? post('category') : 'lain', $stored, $orig, (int)$u['size'], post('shared') === '1' ? 1 : 0, actor(), date('Y-m-d H:i:s')]);
            audit('file_upload', $title);
            flash('File diunggah.');
        }
    } elseif ($action === 'share') {
        q('UPDATE web_file SET shared = 1 - shared WHERE id = ?', [(int)post('id')]);
        audit('file_share', '#' . (int)post('id'));
    } elseif ($action === 'delete') {
        $f = q('SELECT * FROM web_file WHERE id = ?', [(int)post('id')])->fetch();
        if ($f) { @unlink(FILES_DIR . '/' . basename($f['file'])); q('DELETE FROM web_file WHERE id = ?', [$f['id']]); audit('file_delete', $f['title']); flash('File dihapus.'); }
    }
    redirect('files.php?' . http_build_query(array_filter(['cat' => get('cat'), 'q' => get('q')])));
}

$cat = isset(files_categories()[get('cat')]) ? get('cat') : '';
$search = trim(get('q'));
$where = ['1 = 1'];
$params = [];
if ($cat !== '') { $where[] = 'category = ?'; $params[] = $cat; }
if ($search !== '') { $where[] = '(title LIKE ? OR orig LIKE ?)'; array_push($params, "%$search%", "%$search%"); }
$rows = q('SELECT * FROM web_file WHERE ' . implode(' AND ', $where) . ' ORDER BY created_at DESC, id DESC', $params)->fetchAll();
$counts = q('SELECT category, COUNT(*) FROM web_file GROUP BY category')->fetchAll(PDO::FETCH_KEY_PAIR);

hris_header('File perusahaan', 'company', 'files.php', 'Perusahaan'); ?>
  <div class="counters" style="grid-template-columns:repeat(<?= count(files_categories()) + 1 ?>,minmax(0,1fr))">
    <a class="counter<?= $cat === '' ? ' on' : '' ?>" href="files.php"><b><?= array_sum($counts) ?></b><span>Semua</span></a>
    <?php foreach (files_categories() as $k => $l): ?><a class="counter<?= $cat === $k ? ' on' : '' ?>" href="files.php?cat=<?= $k ?>"><b><?= (int)(isset($counts[$k]) ? $counts[$k] : 0) ?></b><span><?= h($l) ?></span></a><?php endforeach; ?>
  </div>
  <form class="toolbar" method="get"><input type="hidden" name="cat" value="<?= h($cat) ?>">
    <span class="search"><?= hris_icon('search') ?><input type="search" name="q" value="<?= h($search) ?>" placeholder="Cari judul atau nama file"></span><button class="btn">Cari</button></form>
  <div class="tablecard"><div class="tablescroll"><table>
    <thead><tr><th>Judul</th><th>Kategori</th><th class="num">Ukuran</th><th>Dibagikan ke karyawan</th><th>Diunggah</th><th class="actions">Aksi</th></tr></thead><tbody>
    <?php foreach ($rows as $f): ?>
      <tr><td><b><?= h($f['title']) ?></b><br><small class="muted"><?= h($f['orig']) ?></small></td><td><?= h(files_categories()[$f['category']] ?? $f['category']) ?></td><td class="num"><?= h(file_size_label($f['size'])) ?></td>
        <td><form method="post" class="inline"><?= $csrf ?><input type="hidden" name="action" value="share"><input type="hidden" name="id" value="<?= (int)$f['id'] ?>"><button class="pill <?= $f['shared'] ? 'ok' : 'grey' ?>" style="border:0;cursor:pointer" title="Klik untuk mengubah"><?= $f['shared'] ? 'Ya' : 'Tidak' ?></button></form></td>
        <td class="muted"><?= h($f['uploaded_by']) ?> · <?= h(date('d-m-Y', strtotime($f['created_at']))) ?></td>
        <td class="actions"><a class="btn small" href="filedl.php?id=<?= (int)$f['id'] ?>">Unduh</a>
          <form method="post" class="inline" onsubmit="return confirm('Hapus file ini?')"><?= $csrf ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$f['id'] ?>"><button class="btn small ghost">Hapus</button></form></td></tr>
    <?php endforeach; ?>
    </tbody></table></div><?php if (!$rows): ?><div class="empty"><strong>Belum ada file</strong>Unggah kebijakan, SOP atau formulir di bawah.</div><?php endif; ?></div>
  <form method="post" enctype="multipart/form-data" class="section" style="margin-top:16px;max-width:none"><?= $csrf ?><input type="hidden" name="action" value="upload">
    <h2>Unggah file</h2>
    <div class="row2"><label class="field"><span>File</span><input type="file" name="file" required accept=".pdf,.jpg,.jpeg,.png,.docx,.xlsx,.pptx"><small>PDF, JPG, PNG, DOCX, XLSX atau PPTX, maksimal 5 MB.</small></label>
      <label class="field"><span>Judul</span><input type="text" name="title" maxlength="150" placeholder="kosongkan untuk memakai nama file"></label>
      <label class="field"><span>Kategori</span><select name="category"><?php foreach (files_categories() as $k => $l): ?><option value="<?= $k ?>"<?= $cat === $k ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></label>
      <label class="check"><input type="checkbox" name="shared" value="1"> Bagikan ke karyawan (tampil di Portal Pegawai)</label></div>
    <div class="formfoot"><button class="btn primary">Unggah</button></div></form>
<?php hris_footer();
