<?php
// HR → Produktivitas → Proyek & tugas: projects and tasks assigned to employees (like Talenta's Projects & tasks), a board by status
// and a project list with progress. Admins only.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/portal.php';
require __DIR__ . '/inc/hris.php';
require __DIR__ . '/inc/tasks.php';
$me = require_login('admin');
ensure_web_tables();
ensure_task_tables();
$today = date('Y-m-d');
$csrf = csrf_field();
$p = in_array(get('p'), ['projects', 'task', 'project'], true) ? get('p') : 'board';
$people = employees(true);
$err = '';

// ---------- actions ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = post('action');
    if ($action === 'task_save') {
        $id = (int)post('id');
        $in = ['title' => post('title'), 'description' => post('description'), 'project_id' => post('project_id'), 'assignee_id' => post('assignee_id'), 'due' => post('due'),
               'priority' => post('priority', 'normal'), 'status' => post('status', 'todo')];
        $err = task_validate($in);
        if (!$err) {
            $id = task_save($id, $in, actor());
            audit('task_save', '#' . $id . ' ' . mb_substr($in['title'], 0, 60));
            flash('Tugas disimpan.');
            redirect('tasks.php' . ($in['project_id'] ? '?project=' . (int)$in['project_id'] : ''));
        }
        flash($err, 'err');
        $p = 'task';
    } elseif ($action === 'status') {
        if (task_set_status((int)post('id'), post('status'))) audit('task_status', '#' . (int)post('id') . ' ' . post('status'));
        redirect(safe_back(post('back'), 'tasks.php'));
    } elseif ($action === 'task_delete') {
        q('DELETE FROM web_task WHERE id = ?', [(int)post('id')]);
        audit('task_delete', '#' . (int)post('id'));
        flash('Tugas dihapus.');
        redirect('tasks.php');
    } elseif ($action === 'project_save') {
        $id = (int)post('id');
        $name = trim(post('name'));
        $status = isset(project_statuses()[post('status')]) ? post('status') : 'active';
        $start = valid_date(post('start_date')) ? post('start_date') : null;
        $due = valid_date(post('due_date')) ? post('due_date') : null;
        if ($name === '') $err = 'Isi nama proyek.';
        elseif (mb_strlen($name) > 120) $err = 'Nama proyek terlalu panjang (maksimal 120 huruf).';
        elseif ($start && $due && $due < $start) $err = 'Tenggat proyek tidak boleh sebelum tanggal mulai.';
        if (!$err) {
            if ($id) q('UPDATE web_project SET name = ?, description = ?, owner_id = ?, start_date = ?, due_date = ?, status = ? WHERE id = ?', [$name, mb_substr(post('description'), 0, 500), (int)post('owner_id') ?: null, $start, $due, $status, $id]);
            else { q('INSERT INTO web_project (name, description, owner_id, start_date, due_date, status, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [$name, mb_substr(post('description'), 0, 500), (int)post('owner_id') ?: null, $start, $due, $status, actor(), date('Y-m-d H:i:s')]); $id = (int)db()->lastInsertId(); }
            audit('project_save', '#' . $id . ' ' . $name);
            flash('Proyek disimpan.');
            redirect('tasks.php?p=projects');
        }
        flash($err, 'err');
        $p = 'project';
    } elseif ($action === 'project_delete') {
        $n = (int)q('SELECT COUNT(*) FROM web_task WHERE project_id = ?', [(int)post('id')])->fetchColumn();
        if ($n) flash('Proyek ini masih punya ' . $n . ' tugas. Arsipkan saja, atau hapus tugasnya dulu.', 'err');
        else { q('DELETE FROM web_project WHERE id = ?', [(int)post('id')]); audit('project_delete', '#' . (int)post('id')); flash('Proyek dihapus.'); }
        redirect('tasks.php?p=projects');
    }
}

$projects = q('SELECT id, name, status FROM web_project ORDER BY FIELD(status, \'active\', \'done\', \'archived\'), name')->fetchAll();
$projName = array_column($projects, 'name', 'id');
$empName = array_column($people, 'pegawai_nama', 'pegawai_id');
$back = h($_SERVER['REQUEST_URI']);
// ======================================================================= board
if ($p === 'board'):
    $f = ['project' => get('project'), 'assignee' => (int)get('assignee'), 'priority' => isset(task_priorities()[get('priority')]) ? get('priority') : '', 'overdue' => get('overdue') === '1', 'q' => trim(get('q'))];
    $where = ['1 = 1'];
    $params = [];
    if ($f['project'] === 'none') $where[] = 't.project_id IS NULL';
    elseif ((int)$f['project']) { $where[] = 't.project_id = ?'; $params[] = (int)$f['project']; }
    if ($f['assignee']) { $where[] = 't.assignee_id = ?'; $params[] = $f['assignee']; }
    if ($f['priority'] !== '') { $where[] = 't.priority = ?'; $params[] = $f['priority']; }
    if ($f['q'] !== '') { $where[] = '(t.title LIKE ? OR t.description LIKE ?)'; array_push($params, '%' . $f['q'] . '%', '%' . $f['q'] . '%'); }
    $all = q('SELECT t.*, p.name project, e.pegawai_nama assignee FROM web_task t LEFT JOIN web_project p ON p.id = t.project_id LEFT JOIN pegawai e ON e.pegawai_id = t.assignee_id WHERE '
             . implode(' AND ', $where) . ' ORDER BY t.id DESC LIMIT 1000', $params)->fetchAll();
    if ($f['overdue']) $all = array_values(array_filter($all, function ($t) use ($today) { return task_overdue($t, $today); }));
    $cols = array_fill_keys(array_keys(task_statuses()), []);
    foreach ($all as $t) $cols[$t['status']][] = $t;
    $doneTotal = count($cols['done']);
    $cols['done'] = array_slice(task_sort($cols['done'], $today), 0, 30);
    foreach (['todo', 'doing'] as $k) $cols[$k] = task_sort($cols[$k], $today);
    $overdue = count(array_filter($all, function ($t) use ($today) { return task_overdue($t, $today); }));
    $moves = ['todo' => [['doing', 'Mulai'], ['done', 'Selesai']], 'doing' => [['todo', '‹ Belum'], ['done', 'Selesai']], 'done' => [['todo', 'Buka lagi']]];
    hris_header('Proyek & tugas', 'performance', 'tasks.php', 'Produktivitas', '<a class="btn" href="tasks.php?p=projects">Proyek</a> <a class="btn primary" href="tasks.php?p=task' . ((int)$f['project'] ? '&project_id=' . (int)$f['project'] : '') . '">+ Tugas baru</a>'); ?>
  <style>.board{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px;align-items:start}@media(max-width:900px){.board{grid-template-columns:1fr}}
    .col{background:var(--canvas);border:1px solid var(--line);border-radius:8px;padding:10px;display:grid;gap:10px}.col h3{margin:0 4px;font-size:14px;display:flex;justify-content:space-between}
    .tcard{background:var(--bg);border:1px solid var(--line);border-radius:6px;padding:10px 12px;display:grid;gap:6px}.tcard.late{border-left:3px solid var(--bad)}.tcard b a{color:var(--ink)}
    .tcard .meta{display:flex;flex-wrap:wrap;gap:6px;align-items:center;font-size:12px;color:var(--muted)}.tcard form{display:inline}</style>
  <form class="toolbar" method="get">
    <select name="project"><option value="">Semua proyek</option><option value="none"<?= $f['project'] === 'none' ? ' selected' : '' ?>>Tanpa proyek</option><?php foreach ($projects as $pr): ?><option value="<?= (int)$pr['id'] ?>"<?= (string)$f['project'] === (string)$pr['id'] ? ' selected' : '' ?>><?= h($pr['name']) ?></option><?php endforeach; ?></select>
    <select name="assignee"><option value="">Semua penanggung jawab</option><?php foreach ($people as $e): ?><option value="<?= (int)$e['pegawai_id'] ?>"<?= $f['assignee'] === (int)$e['pegawai_id'] ? ' selected' : '' ?>><?= h($e['pegawai_nama']) ?></option><?php endforeach; ?></select>
    <select name="priority"><option value="">Semua prioritas</option><?php foreach (task_priorities() as $k => $l): ?><option value="<?= $k ?>"<?= $f['priority'] === $k ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select>
    <label class="check"><input type="checkbox" name="overdue" value="1"<?= $f['overdue'] ? ' checked' : '' ?>> Terlambat saja</label>
    <span class="search"><?= hris_icon('search') ?><input type="search" name="q" value="<?= h($f['q']) ?>" placeholder="Cari tugas"></span>
    <button class="btn">Terapkan</button></form>
  <div class="board">
    <?php foreach (task_statuses() as $st => $label): ?>
      <div class="col"><h3><span><?= h($label) ?></span><span class="muted"><?= $st === 'done' ? $doneTotal : count($cols[$st]) ?></span></h3>
        <?php foreach ($cols[$st] as $t): $late = task_overdue($t, $today); ?>
          <div class="tcard<?= $late ? ' late' : '' ?>"><b><a href="tasks.php?p=task&id=<?= (int)$t['id'] ?>"><?= h($t['title']) ?></a></b>
            <div class="meta"><?php if ($t['project']): ?><span><?= h($t['project']) ?></span><?php endif; ?>
              <span><?= $t['assignee'] ? h($t['assignee']) : 'belum ada penanggung jawab' ?></span>
              <?php if ($t['due_date']): ?><span class="pill <?= $late ? 'bad' : 'grey' ?>"><?= $late ? 'terlambat ' : '' ?><?= h(date('d-m-Y', strtotime($t['due_date']))) ?></span><?php endif; ?>
              <?php if ($t['priority'] !== 'normal'): ?><span class="pill <?= $t['priority'] === 'high' ? 'warn' : 'grey' ?>"><?= h(task_priorities()[$t['priority']]) ?></span><?php endif; ?></div>
            <div><?php foreach ($moves[$st] as $mv): ?><form method="post"><?= $csrf ?><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><input type="hidden" name="status" value="<?= $mv[0] ?>"><input type="hidden" name="back" value="<?= $back ?>"><button class="btn small"><?= h($mv[1]) ?></button></form> <?php endforeach; ?></div></div>
        <?php endforeach; ?>
        <?php if (!$cols[$st]): ?><div class="muted" style="padding:6px 4px">Tidak ada.</div><?php endif; ?>
        <?php if ($st === 'done' && $doneTotal > 30): ?><div class="muted" style="padding:0 4px">30 terbaru dari <?= $doneTotal ?>.</div><?php endif; ?></div>
    <?php endforeach; ?>
  </div>
  <p class="muted" style="margin-top:12px"><?= count($all) ?> tugas<?= $overdue ? ' · <b style="color:var(--bad)">' . $overdue . ' terlambat</b>' : '' ?>. Yang terlambat dan yang tenggatnya terdekat ada paling atas.</p>
<?php
// ======================================================================= task form
elseif ($p === 'task'):
    $id = (int)get('id');
    $t = $id ? q('SELECT * FROM web_task WHERE id = ?', [$id])->fetch() : null;
    if ($id && !$t) redirect('tasks.php');
    $v = $_SERVER['REQUEST_METHOD'] === 'POST' ? ['title' => post('title'), 'description' => post('description'), 'project_id' => post('project_id'), 'assignee_id' => post('assignee_id'), 'due_date' => post('due'), 'priority' => post('priority', 'normal'), 'status' => post('status', 'todo')]
        : ($t ?: ['title' => '', 'description' => '', 'project_id' => get('project_id'), 'assignee_id' => get('assignee_id'), 'due_date' => '', 'priority' => 'normal', 'status' => 'todo']);
    hris_header($t ? 'Ubah tugas' : 'Tugas baru', 'performance', 'tasks.php', '<a href="tasks.php">Proyek & tugas</a>'); ?>
  <form method="post" class="section"><?= $csrf ?><input type="hidden" name="action" value="task_save"><input type="hidden" name="id" value="<?= $id ?>">
    <label class="field"><span>Judul <i>*</i></span><input type="text" name="title" required maxlength="150" value="<?= h($v['title']) ?>" placeholder="mis. Rekap absensi bulan Oktober"></label>
    <div class="row2">
      <label class="field"><span>Proyek</span><select name="project_id"><option value="">— tanpa proyek —</option><?php foreach ($projects as $pr): ?><option value="<?= (int)$pr['id'] ?>"<?= (string)$v['project_id'] === (string)$pr['id'] ? ' selected' : '' ?>><?= h($pr['name']) ?><?= $pr['status'] !== 'active' ? ' (' . h(project_statuses()[$pr['status']]) . ')' : '' ?></option><?php endforeach; ?></select></label>
      <label class="field"><span>Penanggung jawab</span><select name="assignee_id"><option value="">— belum ditentukan —</option><?php foreach ($people as $e): ?><option value="<?= (int)$e['pegawai_id'] ?>"<?= (string)$v['assignee_id'] === (string)$e['pegawai_id'] ? ' selected' : '' ?>><?= h($e['pegawai_nama']) ?></option><?php endforeach; ?></select></label>
      <label class="field"><span>Tenggat</span><input type="date" name="due" value="<?= h($v['due_date']) ?>"></label>
      <label class="field"><span>Prioritas</span><select name="priority"><?php foreach (task_priorities() as $k => $l): ?><option value="<?= $k ?>"<?= $v['priority'] === $k ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></label>
      <label class="field"><span>Status</span><select name="status"><?php foreach (task_statuses() as $k => $l): ?><option value="<?= $k ?>"<?= $v['status'] === $k ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></label>
    </div>
    <label class="field"><span>Keterangan (opsional)</span><textarea name="description" rows="4"><?= h($v['description']) ?></textarea></label>
    <div class="formfoot"><a class="btn" href="tasks.php">Batal</a><button class="btn primary">Simpan</button></div></form>
  <?php if ($t): ?><form method="post" style="margin-top:12px" onsubmit="return confirm('Hapus tugas ini?')"><?= $csrf ?><input type="hidden" name="action" value="task_delete"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn small ghost" style="color:var(--bad)">Hapus tugas</button></form>
    <p class="muted">Dibuat oleh <?= h($t['created_by']) ?> · <?= h(date('d-m-Y H:i', strtotime($t['created_at']))) ?><?= $t['done_at'] ? ' · selesai ' . h(date('d-m-Y H:i', strtotime($t['done_at']))) : '' ?></p><?php endif; ?>
<?php
// ======================================================================= project list
elseif ($p === 'projects'):
    $list = projects_with_progress($today);
    hris_header('Proyek', 'performance', 'tasks.php', '<a href="tasks.php">Proyek & tugas</a>', '<a class="btn" href="tasks.php">Papan tugas</a> <a class="btn primary" href="tasks.php?p=project">+ Proyek baru</a>'); ?>
  <div class="tablecard"><div class="tablescroll"><table>
    <thead><tr><th>Proyek</th><th>Penanggung jawab</th><th>Periode</th><th>Status</th><th style="min-width:180px">Kemajuan</th><th class="num">Tugas</th><th class="actions">Aksi</th></tr></thead><tbody>
    <?php foreach ($list as $pr): $n = array_sum($pr['counts']); ?>
      <tr><td><a href="tasks.php?project=<?= (int)$pr['id'] ?>"><b><?= h($pr['name']) ?></b></a><?= $pr['description'] !== '' ? '<br><small class="muted">' . h($pr['description']) . '</small>' : '' ?></td>
        <td><?= h($pr['owner']) ?></td>
        <td style="white-space:nowrap"><?= $pr['start_date'] ? h(date('d-m-Y', strtotime($pr['start_date']))) : '…' ?> – <?= $pr['due_date'] ? h(date('d-m-Y', strtotime($pr['due_date']))) : '…' ?></td>
        <td><span class="pill <?= ['active' => 'ok', 'done' => 'grey', 'archived' => 'grey'][$pr['status']] ?>"><?= h(project_statuses()[$pr['status']]) ?></span></td>
        <td><div class="stack" style="height:8px"><i style="width:<?= (int)$pr['progress'] ?>%;background:var(--ok)"></i></div><small class="muted"><?= (int)$pr['progress'] ?>% selesai<?= $pr['overdue'] ? ' · <span style="color:var(--bad)">' . (int)$pr['overdue'] . ' terlambat</span>' : '' ?></small></td>
        <td class="num"><?= (int)(isset($pr['counts']['done']) ? $pr['counts']['done'] : 0) ?> / <?= $n ?></td>
        <td class="actions"><a class="btn small" href="tasks.php?p=project&id=<?= (int)$pr['id'] ?>">Ubah</a></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <?php if (!$list): ?><div class="empty"><strong>Belum ada proyek</strong>Buat proyek untuk mengelompokkan tugas dan melihat kemajuannya.</div><?php endif; ?></div>
<?php
// ======================================================================= project form
else:
    $id = (int)get('id');
    $pr = $id ? q('SELECT * FROM web_project WHERE id = ?', [$id])->fetch() : null;
    if ($id && !$pr) redirect('tasks.php?p=projects');
    $v = $_SERVER['REQUEST_METHOD'] === 'POST' ? ['name' => post('name'), 'description' => post('description'), 'owner_id' => post('owner_id'), 'start_date' => post('start_date'), 'due_date' => post('due_date'), 'status' => post('status', 'active')]
        : ($pr ?: ['name' => '', 'description' => '', 'owner_id' => '', 'start_date' => $today, 'due_date' => '', 'status' => 'active']);
    hris_header($pr ? 'Ubah proyek' : 'Proyek baru', 'performance', 'tasks.php', '<a href="tasks.php?p=projects">Proyek</a>'); ?>
  <form method="post" class="section"><?= $csrf ?><input type="hidden" name="action" value="project_save"><input type="hidden" name="id" value="<?= $id ?>">
    <label class="field"><span>Nama proyek <i>*</i></span><input type="text" name="name" required maxlength="120" value="<?= h($v['name']) ?>"></label>
    <div class="row2">
      <label class="field"><span>Penanggung jawab</span><select name="owner_id"><option value="">— belum ditentukan —</option><?php foreach ($people as $e): ?><option value="<?= (int)$e['pegawai_id'] ?>"<?= (string)$v['owner_id'] === (string)$e['pegawai_id'] ? ' selected' : '' ?>><?= h($e['pegawai_nama']) ?></option><?php endforeach; ?></select></label>
      <label class="field"><span>Status</span><select name="status"><?php foreach (project_statuses() as $k => $l): ?><option value="<?= $k ?>"<?= $v['status'] === $k ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></label>
      <label class="field"><span>Mulai</span><input type="date" name="start_date" value="<?= h($v['start_date']) ?>"></label>
      <label class="field"><span>Tenggat</span><input type="date" name="due_date" value="<?= h($v['due_date']) ?>"></label>
    </div>
    <label class="field"><span>Keterangan (opsional)</span><textarea name="description" rows="3" maxlength="500"><?= h($v['description']) ?></textarea></label>
    <div class="formfoot"><a class="btn" href="tasks.php?p=projects">Batal</a><button class="btn primary">Simpan</button></div></form>
  <?php if ($pr): ?><form method="post" style="margin-top:12px" onsubmit="return confirm('Hapus proyek ini?')"><?= $csrf ?><input type="hidden" name="action" value="project_delete"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn small ghost" style="color:var(--bad)">Hapus proyek</button> <span class="muted">Hanya bisa jika proyek tidak punya tugas; selain itu arsipkan.</span></form><?php endif; ?>
<?php endif;
hris_footer();
