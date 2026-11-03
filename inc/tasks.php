<?php
// Proyek & tugas (like Talenta's Projects & tasks): projects with tasks that are assigned to employees, have a due date and a priority,
// and move through Belum -> Sedang dikerjakan -> Selesai.

function ensure_task_tables()
{
    db()->exec("CREATE TABLE IF NOT EXISTS web_project (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL, description VARCHAR(500) NOT NULL DEFAULT '',
        owner_id INT NULL, start_date DATE NULL, due_date DATE NULL, status VARCHAR(10) NOT NULL DEFAULT 'active',
        created_by VARCHAR(50) NOT NULL DEFAULT '', created_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    db()->exec("CREATE TABLE IF NOT EXISTS web_task (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, project_id INT UNSIGNED NULL, title VARCHAR(150) NOT NULL, description TEXT NOT NULL,
        assignee_id INT NULL, due_date DATE NULL, priority VARCHAR(8) NOT NULL DEFAULT 'normal', status VARCHAR(8) NOT NULL DEFAULT 'todo',
        done_at DATETIME NULL, created_by VARCHAR(50) NOT NULL DEFAULT '', created_at DATETIME NOT NULL,
        KEY project (project_id), KEY assignee (assignee_id, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
}

function task_statuses() { return ['todo' => 'Belum dikerjakan', 'doing' => 'Sedang dikerjakan', 'done' => 'Selesai']; }
function task_priorities() { return ['high' => 'Tinggi', 'normal' => 'Biasa', 'low' => 'Rendah']; }
function project_statuses() { return ['active' => 'Berjalan', 'done' => 'Selesai', 'archived' => 'Diarsipkan']; }

/** Check a task from the form. $in: title, project_id, assignee_id, due, priority, status. Returns an error message or null. */
function task_validate($in)
{
    if (trim((string)$in['title']) === '') return 'Isi judul tugas.';
    if (mb_strlen($in['title']) > 150) return 'Judul tugas terlalu panjang (maksimal 150 huruf).';
    if (!isset(task_priorities()[$in['priority']])) return 'Pilih prioritas.';
    if (!isset(task_statuses()[$in['status']])) return 'Pilih status.';
    if ($in['due'] !== '' && $in['due'] !== null && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $in['due']) || !strtotime($in['due']))) return 'Isi tanggal tenggat yang benar.';
    return null;
}

/** Is an open task past its due date on $today? */
function task_overdue($task, $today)
{
    return $task['status'] !== 'done' && $task['due_date'] !== null && $task['due_date'] !== '' && $task['due_date'] < $today;
}

/** Whole percent done from the counts per status; 0 for a project without tasks. */
function task_progress($counts)
{
    $total = array_sum($counts);
    return $total > 0 ? (int)round(100 * (isset($counts['done']) ? $counts['done'] : 0) / $total) : 0;
}

/** Sort for a column: overdue first, then by due date (no date last), high priority first, then newest. */
function task_sort($tasks, $today)
{
    $pri = ['high' => 0, 'normal' => 1, 'low' => 2];
    usort($tasks, function ($a, $b) use ($today, $pri) {
        $oa = task_overdue($a, $today) ? 0 : 1;
        $ob = task_overdue($b, $today) ? 0 : 1;
        if ($oa !== $ob) return $oa - $ob;
        $da = $a['due_date'] ?: '9999-12-31';
        $db = $b['due_date'] ?: '9999-12-31';
        if ($da !== $db) return strcmp($da, $db);
        $p = $pri[$a['priority']] - $pri[$b['priority']];
        return $p ?: ((int)$b['id'] - (int)$a['id']);
    });
    return $tasks;
}

// ---------- database ----------

/** Create or update a task from validated input; returns its id. done_at follows the status. */
function task_save($id, $in, $by)
{
    $assignee = (int)$in['assignee_id'] ?: null;
    $project = (int)$in['project_id'] ?: null;
    $due = $in['due'] !== '' && $in['due'] !== null ? $in['due'] : null;
    if ($id) {
        $old = q('SELECT status FROM web_task WHERE id = ?', [(int)$id])->fetch();
        $doneAt = $in['status'] === 'done' ? ($old && $old['status'] === 'done' ? null : date('Y-m-d H:i:s')) : null;
        q('UPDATE web_task SET title = ?, description = ?, project_id = ?, assignee_id = ?, due_date = ?, priority = ?, status = ?, done_at = CASE WHEN ? = \'done\' THEN COALESCE(?, done_at) ELSE NULL END WHERE id = ?',
            [mb_substr(trim($in['title']), 0, 150), (string)$in['description'], $project, $assignee, $due, $in['priority'], $in['status'], $in['status'], $doneAt, (int)$id]);
        return (int)$id;
    }
    q('INSERT INTO web_task (title, description, project_id, assignee_id, due_date, priority, status, done_at, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [mb_substr(trim($in['title']), 0, 150), (string)$in['description'], $project, $assignee, $due, $in['priority'], $in['status'], $in['status'] === 'done' ? date('Y-m-d H:i:s') : null, $by, date('Y-m-d H:i:s')]);
    return (int)db()->lastInsertId();
}

/** Move a task to another status. With $byEmployee only the assignee may (the portal). Returns true when it changed. */
function task_set_status($id, $status, $byEmployee = null)
{
    if (!isset(task_statuses()[$status])) return false;
    $t = q('SELECT id, assignee_id, status FROM web_task WHERE id = ?', [(int)$id])->fetch();
    if (!$t || $t['status'] === $status) return false;
    if ($byEmployee !== null && (int)$t['assignee_id'] !== (int)$byEmployee) return false;
    q('UPDATE web_task SET status = ?, done_at = ? WHERE id = ?', [$status, $status === 'done' ? date('Y-m-d H:i:s') : null, (int)$id]);
    return true;
}

/** Open tasks of an employee, ready for the portal: overdue and soonest first. */
function tasks_for_employee($pegawaiId, $today = null)
{
    $rows = q("SELECT t.*, p.name project FROM web_task t LEFT JOIN web_project p ON p.id = t.project_id WHERE t.assignee_id = ? AND t.status <> 'done'", [(int)$pegawaiId])->fetchAll();
    return task_sort($rows, $today ?: date('Y-m-d'));
}

/** Projects with their task counts: [['id', 'name', ..., 'counts' => [status => n], 'progress' => %, 'overdue' => n]]. */
function projects_with_progress($today = null)
{
    $today = $today ?: date('Y-m-d');
    $projects = q('SELECT p.*, e.pegawai_nama owner FROM web_project p LEFT JOIN pegawai e ON e.pegawai_id = p.owner_id ORDER BY FIELD(p.status, \'active\', \'done\', \'archived\'), p.due_date IS NULL, p.due_date, p.id')->fetchAll();
    $by = [];
    foreach (q('SELECT project_id, status, due_date FROM web_task WHERE project_id IS NOT NULL')->fetchAll() as $t) {
        $by[$t['project_id']]['counts'][$t['status']] = (isset($by[$t['project_id']]['counts'][$t['status']]) ? $by[$t['project_id']]['counts'][$t['status']] : 0) + 1;
        if (task_overdue($t, $today)) $by[$t['project_id']]['overdue'] = (isset($by[$t['project_id']]['overdue']) ? $by[$t['project_id']]['overdue'] : 0) + 1;
    }
    foreach ($projects as &$p) {
        $p['counts'] = isset($by[$p['id']]['counts']) ? $by[$p['id']]['counts'] : [];
        $p['progress'] = task_progress($p['counts']);
        $p['overdue'] = isset($by[$p['id']]['overdue']) ? $by[$p['id']]['overdue'] : 0;
    }
    return $projects;
}
