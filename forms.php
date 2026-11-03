<?php
// HR → Produktivitas → Formulir & survei: build forms and surveys, open / close them, read the results. Admins only.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/portal.php';
require __DIR__ . '/inc/hris.php';
require __DIR__ . '/inc/forms.php';
$me = require_login('admin');
ensure_web_tables();
ensure_form_tables();
$today = date('Y-m-d');
$csrf = csrf_field();
$p = in_array(get('p'), ['edit', 'results'], true) ? get('p') : 'list';
$fid = (int)get('id');

// ---------- actions ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = post('action');
    $id = (int)post('id');
    $form = $id ? q('SELECT * FROM web_form WHERE id = ?', [$id])->fetch() : null;
    if ($action === 'form_save') {
        $in = ['title' => post('title'), 'description' => post('description'), 'kind' => post('kind'), 'anonymous' => post('anonymous') === '1', 'due' => post('due')];
        if (trim($in['title']) === '') flash('Isi judulnya.', 'err');
        elseif ($in['due'] !== '' && !valid_date($in['due'])) flash('Isi tanggal tenggat yang benar.', 'err');
        elseif ($form && form_response_count($id) && ((int)$form['anonymous'] !== ($in['anonymous'] ? 1 : 0))) flash('Anonim atau tidak tidak bisa diubah setelah ada tanggapan.', 'err');
        else {
            $id = form_save($id, $in, actor());
            audit('form_save', '#' . $id . ' ' . mb_substr($in['title'], 0, 60));
            flash('Disimpan.');
            redirect('forms.php?p=edit&id=' . $id);
        }
        redirect('forms.php?p=edit' . ($id ? '&id=' . $id : ''));
    } elseif ($action === 'status' && $form) {
        $to = post('status');
        if (!isset(form_statuses()[$to])) { redirect('forms.php'); }
        if ($to === 'open' && !form_questions($id)) flash('Tambahkan minimal satu pertanyaan sebelum dibuka.', 'err');
        else { q('UPDATE web_form SET status = ? WHERE id = ?', [$to, $id]); audit('form_status', '#' . $id . ' ' . $to); flash('Status: ' . form_statuses()[$to][0] . '.'); }
        redirect(safe_back(post('back'), 'forms.php'));
    } elseif ($action === 'question_save' && $form) {
        $locked = form_response_count($id) > 0;
        $label = post('label');
        $err = $locked ? 'Sudah ada tanggapan, jadi pertanyaan tidak bisa diubah. Buat formulir baru bila perlu.' : form_question_validate($label, post('kind'), post('options'));
        if ($err) flash($err, 'err');
        else { form_question_save($id, (int)post('qid'), $label, post('kind'), post('options'), post('required') === '1'); audit('form_question', '#' . $id); flash('Pertanyaan disimpan.'); }
        redirect('forms.php?p=edit&id=' . $id . '#pertanyaan');
    } elseif ($action === 'question_move' && $form && !form_response_count($id)) {
        form_question_move($id, (int)post('qid'), post('dir') === 'up' ? 'up' : 'down');
        redirect('forms.php?p=edit&id=' . $id . '#pertanyaan');
    } elseif ($action === 'question_delete' && $form) {
        if (form_response_count($id)) flash('Sudah ada tanggapan, jadi pertanyaan tidak bisa dihapus.', 'err');
        else { q('DELETE FROM web_form_q WHERE id = ? AND form_id = ?', [(int)post('qid'), $id]); flash('Pertanyaan dihapus.'); }
        redirect('forms.php?p=edit&id=' . $id . '#pertanyaan');
    } elseif ($action === 'form_delete' && $form) {
        form_delete($id);
        audit('form_delete', '#' . $id . ' ' . $form['title']);
        flash('Formulir dan semua tanggapannya dihapus.');
        redirect('forms.php');
    }
    redirect('forms.php');
}

$activeEmployees = (int)q('SELECT COUNT(*) FROM pegawai WHERE pegawai_status = 1')->fetchColumn();

// ======================================================================= results (and CSV)
if ($p === 'results'):
    $form = q('SELECT * FROM web_form WHERE id = ?', [$fid])->fetch();
    if (!$form) redirect('forms.php');
    $res = form_results($fid);
    $qs = $res['questions'];
    if (get('csv') === '1') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="tanggapan_' . $fid . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array_merge(['Waktu', $form['anonymous'] ? '(anonim)' : 'Karyawan'], array_column($qs, 'label')));
        foreach ($res['responses'] as $r) {
            $row = [$r['submitted_at'], $form['anonymous'] ? '' : $r['pegawai_nama']];
            foreach ($qs as $q) $row[] = isset($res['answers'][$r['id']][$q['id']]) ? form_answer_text($q['kind'], $res['answers'][$r['id']][$q['id']]) : '';
            fputcsv($out, $row);
        }
        audit('form_export', '#' . $fid);
        exit;
    }
    $done = count($res['responses']);
    $waiting = $form['anonymous'] ? null : q("SELECT p.pegawai_nama FROM pegawai p WHERE p.pegawai_status = 1 AND NOT EXISTS (SELECT 1 FROM web_form_done d WHERE d.form_id = ? AND d.pegawai_id = p.pegawai_id) ORDER BY p.pegawai_nama", [$fid])->fetchAll(PDO::FETCH_COLUMN);
    hris_header('Tanggapan: ' . $form['title'], 'performance', 'forms.php', '<a href="forms.php">Formulir & survei</a>', $done ? '<a class="btn" href="forms.php?p=results&id=' . $fid . '&csv=1">Ekspor CSV</a>' : ''); ?>
  <div class="counters" style="grid-template-columns:repeat(3,minmax(0,1fr))">
    <div class="counter"><b><?= $done ?></b><span>Tanggapan</span></div>
    <div class="counter"><b><?= $activeEmployees ? round(100 * $done / $activeEmployees) : 0 ?>%</b><span>dari <?= $activeEmployees ?> karyawan aktif</span></div>
    <div class="counter"><b><?= h(form_statuses()[$form['status']][0]) ?></b><span><?= $form['anonymous'] ? 'Anonim' : 'Dengan nama' ?><?= $form['due_date'] ? ' · tenggat ' . h(fmt_date($form['due_date'])) : '' ?></span></div>
  </div>
  <?php foreach ($qs as $i => $q): $s = $res['summary'][$q['id']]; $max = max(1, $s['counts'] ? max($s['counts']) : 1); ?>
    <div class="card" style="margin-bottom:12px"><h2><?= ($i + 1) . '. ' . h($q['label']) ?> <span class="muted" style="font-weight:400;font-size:13px">· <?= h(form_question_kinds()[$q['kind']]) ?> · <?= $s['n'] ?> jawaban</span></h2>
      <?php if ($s['counts']): ?><div class="kv" style="gap:6px"><?php foreach ($s['counts'] as $opt => $n): $lab = $q['kind'] === 'yesno' ? ($opt === 'ya' ? 'Ya' : 'Tidak') : ($q['kind'] === 'rating' ? '★ ' . $opt : $opt); ?>
        <div style="grid-template-columns:140px minmax(0,1fr) 44px"><span><?= h($lab) ?></span><div class="stack" style="height:10px"><i style="width:<?= round(100 * $n / $max) ?>%;background:var(--primary)"></i></div><span class="n"><?= $n ?></span></div><?php endforeach; ?></div><?php endif; ?>
      <?php if ($s['avg'] !== null): ?><p style="margin:8px 0 0"><b>Rata-rata <?= str_replace('.', ',', (string)$s['avg']) ?></b> <span class="muted">(terendah <?= h((string)$s['min']) ?>, tertinggi <?= h((string)$s['max']) ?>)</span></p><?php endif; ?>
      <?php if ($s['texts']): ?><div class="list"><?php foreach ($s['texts'] as $t): ?><div class="li" style="white-space:pre-line"><?= h($t) ?></div><?php endforeach; ?></div><?php endif; ?>
      <?php if (!$s['n']): ?><p class="muted" style="margin:0">Belum ada jawaban.</p><?php endif; ?></div>
  <?php endforeach; ?>
  <?php if ($done): ?><div class="tablecard"><div class="tablescroll"><table>
    <thead><tr><th>Waktu</th><th><?= $form['anonymous'] ? '' : 'Karyawan' ?></th><?php foreach ($qs as $q): ?><th><?= h(mb_substr($q['label'], 0, 40)) ?></th><?php endforeach; ?></tr></thead><tbody>
    <?php foreach ($res['responses'] as $r): ?><tr><td style="white-space:nowrap"><?= h(date('d-m-Y H:i', strtotime($r['submitted_at']))) ?></td><td><?= $form['anonymous'] ? '' : h($r['pegawai_nama']) ?></td>
      <?php foreach ($qs as $q): ?><td><?= isset($res['answers'][$r['id']][$q['id']]) ? h(form_answer_text($q['kind'], $res['answers'][$r['id']][$q['id']])) : '' ?></td><?php endforeach; ?></tr><?php endforeach; ?>
    </tbody></table></div></div><?php endif; ?>
  <?php if ($waiting !== null && $waiting): ?><div class="card" style="margin-top:12px"><h2>Belum mengisi (<?= count($waiting) ?>)</h2><p class="muted" style="margin:0"><?= h(implode(', ', $waiting)) ?></p></div>
  <?php elseif ($waiting === null): ?><p class="muted">Survei anonim: daftar yang belum mengisi tidak ditampilkan agar jawaban tidak bisa ditelusuri.</p><?php endif; ?>
<?php
// ======================================================================= editor
elseif ($p === 'edit'):
    $form = $fid ? q('SELECT * FROM web_form WHERE id = ?', [$fid])->fetch() : null;
    if ($fid && !$form) redirect('forms.php');
    $v = $form ?: ['title' => '', 'description' => '', 'kind' => get('kind') === 'survey' ? 'survey' : 'form', 'anonymous' => get('kind') === 'survey' ? 1 : 0, 'due_date' => '', 'status' => 'draft'];
    $qs = $form ? form_questions($fid) : [];
    $locked = $form && form_response_count($fid) > 0;
    $eq = $form && (int)get('q') ? q('SELECT * FROM web_form_q WHERE id = ? AND form_id = ?', [(int)get('q'), $fid])->fetch() : null;
    hris_header($form ? 'Ubah: ' . $form['title'] : 'Formulir baru', 'performance', 'forms.php', '<a href="forms.php">Formulir & survei</a>',
        $form ? '<a class="btn" href="forms.php?p=results&id=' . $fid . '">Tanggapan</a>' : ''); ?>
  <form method="post" class="section" style="max-width:none"><?= $csrf ?><input type="hidden" name="action" value="form_save"><input type="hidden" name="id" value="<?= $fid ?>">
    <div class="row2"><label class="field"><span>Judul <i>*</i></span><input type="text" name="title" required maxlength="150" value="<?= h($v['title']) ?>"></label>
      <label class="field"><span>Jenis</span><select name="kind"><?php foreach (form_kinds() as $k => $l): ?><option value="<?= $k ?>"<?= $v['kind'] === $k ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></label>
      <label class="field"><span>Tenggat (opsional)</span><input type="date" name="due" value="<?= h($v['due_date']) ?>"></label>
      <label class="check"><input type="checkbox" name="anonymous" value="1"<?= $v['anonymous'] ? ' checked' : '' ?><?= $locked ? ' disabled' : '' ?>> Anonim (jawaban tidak mencatat siapa yang mengisi)</label></div>
    <label class="field"><span>Penjelasan untuk karyawan (opsional)</span><textarea name="description" rows="2" maxlength="500"><?= h($v['description']) ?></textarea></label>
    <div class="formfoot"><a class="btn" href="forms.php">Kembali</a><button class="btn primary">Simpan</button></div></form>
  <?php if ($form): ?>
  <div class="card" style="margin-top:16px"><h2>Status <span class="pill <?= form_statuses()[$form['status']][1] ?>"><?= h(form_statuses()[$form['status']][0]) ?></span></h2>
    <?php foreach (form_statuses() as $k => $s): if ($k === $form['status']) continue; ?><form method="post" class="inline"><?= $csrf ?><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?= $fid ?>"><input type="hidden" name="status" value="<?= $k ?>"><input type="hidden" name="back" value="forms.php?p=edit&id=<?= $fid ?>"><button class="btn small"><?= $k === 'open' ? 'Buka untuk karyawan' : ($k === 'closed' ? 'Tutup' : 'Jadikan draf') ?></button></form> <?php endforeach; ?>
    <span class="muted">Hanya formulir yang dibuka yang muncul di Portal Pegawai.</span></div>
  <div class="card" id="pertanyaan" style="margin-top:16px"><h2>Pertanyaan (<?= count($qs) ?>)</h2>
    <?php if ($locked): ?><div class="notice"><div><b>Sudah ada <?= form_response_count($fid) ?> tanggapan.</b> Pertanyaan dikunci agar jawaban tetap sesuai. Buat formulir baru untuk perubahan.</div></div><?php endif; ?>
    <?php if ($qs): ?><div class="tablescroll"><table><thead><tr><th>#</th><th>Pertanyaan</th><th>Jenis</th><th>Wajib</th><th class="actions"></th></tr></thead><tbody>
      <?php foreach ($qs as $i => $q): ?><tr><td><?= $i + 1 ?></td><td><b><?= h($q['label']) ?></b><?= $q['options'] !== '' ? '<br><small class="muted">' . h(str_replace("\n", ' · ', $q['options'])) . '</small>' : '' ?></td><td><?= h(form_question_kinds()[$q['kind']] ?? $q['kind']) ?></td><td><?= $q['required'] ? 'Ya' : '' ?></td>
        <td class="actions" style="white-space:nowrap"><?php if (!$locked): foreach (['up' => '▲', 'down' => '▼'] as $d => $ic): ?><form method="post" class="inline"><?= $csrf ?><input type="hidden" name="action" value="question_move"><input type="hidden" name="id" value="<?= $fid ?>"><input type="hidden" name="qid" value="<?= (int)$q['id'] ?>"><input type="hidden" name="dir" value="<?= $d ?>"><button class="btn small"<?= ($d === 'up' && $i === 0) || ($d === 'down' && $i === count($qs) - 1) ? ' disabled' : '' ?>><?= $ic ?></button></form> <?php endforeach; ?>
          <a class="btn small" href="forms.php?p=edit&id=<?= $fid ?>&q=<?= (int)$q['id'] ?>#pertanyaan">Ubah</a>
          <form method="post" class="inline" onsubmit="return confirm('Hapus pertanyaan ini?')"><?= $csrf ?><input type="hidden" name="action" value="question_delete"><input type="hidden" name="id" value="<?= $fid ?>"><input type="hidden" name="qid" value="<?= (int)$q['id'] ?>"><button class="btn small ghost">Hapus</button></form><?php endif; ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
    <?php if (!$locked): ?><form method="post" style="display:grid;gap:12px;margin-top:12px"><?= $csrf ?><input type="hidden" name="action" value="question_save"><input type="hidden" name="id" value="<?= $fid ?>"><input type="hidden" name="qid" value="<?= $eq ? (int)$eq['id'] : 0 ?>">
      <b><?= $eq ? 'Ubah pertanyaan' : 'Tambah pertanyaan' ?></b>
      <div class="row2"><label class="field"><span>Pertanyaan <i>*</i></span><input type="text" name="label" required maxlength="255" value="<?= h($eq ? $eq['label'] : '') ?>"></label>
        <label class="field"><span>Jenis</span><select name="kind" id="qkind"><?php foreach (form_question_kinds() as $k => $l): ?><option value="<?= $k ?>"<?= ($eq ? $eq['kind'] : 'text') === $k ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></label></div>
      <label class="field" id="qopts"><span>Pilihan (satu per baris, untuk pilihan tunggal / ganda)</span><textarea name="options" rows="3"><?= h($eq ? $eq['options'] : '') ?></textarea></label>
      <label class="check"><input type="checkbox" name="required" value="1"<?= $eq && $eq['required'] ? ' checked' : '' ?>> Wajib diisi</label>
      <div class="formfoot"><?php if ($eq): ?><a class="btn" href="forms.php?p=edit&id=<?= $fid ?>#pertanyaan">Batal</a><?php endif; ?><button class="btn primary"><?= $eq ? 'Simpan pertanyaan' : 'Tambah' ?></button></div></form>
      <script>(function () { var k = document.getElementById('qkind'), o = document.getElementById('qopts'); function f() { o.style.display = (k.value === 'choice' || k.value === 'multi') ? '' : 'none'; } k.addEventListener('change', f); f(); })();</script><?php endif; ?></div>
  <?php endif; ?>
<?php
// ======================================================================= list
else:
    $rows = q('SELECT f.*, (SELECT COUNT(*) FROM web_form_q x WHERE x.form_id = f.id) nq, (SELECT COUNT(*) FROM web_form_resp r WHERE r.form_id = f.id) nr FROM web_form f ORDER BY FIELD(f.status, \'open\', \'draft\', \'closed\'), f.id DESC')->fetchAll();
    hris_header('Formulir & survei', 'performance', 'forms.php', 'Produktivitas', '<a class="btn" href="forms.php?p=edit&kind=survey">+ Survei</a> <a class="btn primary" href="forms.php?p=edit">+ Formulir</a>'); ?>
  <div class="tablecard"><div class="tablescroll"><table>
    <thead><tr><th>Judul</th><th>Jenis</th><th>Status</th><th class="num">Pertanyaan</th><th class="num">Tanggapan</th><th>Tenggat</th><th class="actions">Aksi</th></tr></thead><tbody>
    <?php foreach ($rows as $f): $st = form_statuses()[$f['status']]; ?>
      <tr><td><a href="forms.php?p=edit&id=<?= (int)$f['id'] ?>"><b><?= h($f['title']) ?></b></a><?= $f['anonymous'] ? ' <span class="pill grey">anonim</span>' : '' ?></td><td><?= h(form_kinds()[$f['kind']]) ?></td>
        <td><span class="pill <?= $st[1] ?>"><?= h($st[0]) ?></span></td><td class="num"><?= (int)$f['nq'] ?></td><td class="num"><?= (int)$f['nr'] ?> / <?= $activeEmployees ?></td>
        <td><?= $f['due_date'] ? h(fmt_date($f['due_date'])) : '' ?></td>
        <td class="actions" style="white-space:nowrap"><a class="btn small" href="forms.php?p=results&id=<?= (int)$f['id'] ?>">Tanggapan</a> <a class="btn small" href="forms.php?p=edit&id=<?= (int)$f['id'] ?>">Ubah</a>
          <form method="post" class="inline" onsubmit="return confirm('Hapus formulir ini beserta semua tanggapannya?')"><?= $csrf ?><input type="hidden" name="action" value="form_delete"><input type="hidden" name="id" value="<?= (int)$f['id'] ?>"><button class="btn small ghost">Hapus</button></form></td></tr>
    <?php endforeach; ?>
    </tbody></table></div><?php if (!$rows): ?><div class="empty"><strong>Belum ada formulir atau survei</strong>Buat yang pertama: tambah pertanyaan, buka, lalu karyawan mengisinya di Portal Pegawai.</div><?php endif; ?></div>
<?php endif;
hris_footer();
