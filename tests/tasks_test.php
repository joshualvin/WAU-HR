<?php
// Self-check for the projects & tasks logic that needs no database: php tests/tasks_test.php
require __DIR__ . '/../inc/tasks.php';
$ok = 0;
function check($label, $got, $want) { global $ok; if ($got !== $want) { fwrite(STDERR, "FAIL $label: got " . var_export($got, true) . ', want ' . var_export($want, true) . "\n"); exit(1); } $ok++; }

$today = '2026-11-04';
$base = ['title' => 'Siapkan laporan', 'priority' => 'normal', 'status' => 'todo', 'due' => ''];
check('valid', task_validate($base), null);
check('valid with a date', task_validate(['due' => '2026-11-30'] + $base), null);
check('no title', task_validate(['title' => '  '] + $base), 'Isi judul tugas.');
check('title too long', task_validate(['title' => str_repeat('a', 151)] + $base), 'Judul tugas terlalu panjang (maksimal 150 huruf).');
check('bad priority', task_validate(['priority' => 'urgent'] + $base), 'Pilih prioritas.');
check('bad status', task_validate(['status' => 'x'] + $base), 'Pilih status.');
check('bad due date', [task_validate(['due' => '30-11-2026'] + $base), task_validate(['due' => '2026-13-45'] + $base)], ['Isi tanggal tenggat yang benar.', 'Isi tanggal tenggat yang benar.']);

$t = function ($status, $due) { return ['status' => $status, 'due_date' => $due]; };
check('overdue: open and past due', [task_overdue($t('todo', '2026-11-03'), $today), task_overdue($t('doing', '2026-10-01'), $today)], [true, true]);
check('not overdue: due today, future, none, or done', [task_overdue($t('todo', $today), $today), task_overdue($t('todo', '2026-12-01'), $today), task_overdue($t('todo', null), $today), task_overdue($t('done', '2026-01-01'), $today)], [false, false, false, false]);
check('progress', [task_progress([]), task_progress(['todo' => 1, 'done' => 1]), task_progress(['done' => 3]), task_progress(['todo' => 2, 'doing' => 1, 'done' => 0]), task_progress(['todo' => 2, 'done' => 1])], [0, 50, 100, 0, 33]);

$tasks = [
    ['id' => 1, 'status' => 'todo', 'due_date' => '2026-12-01', 'priority' => 'low'],
    ['id' => 2, 'status' => 'todo', 'due_date' => null, 'priority' => 'high'],
    ['id' => 3, 'status' => 'todo', 'due_date' => '2026-12-01', 'priority' => 'high'],
    ['id' => 4, 'status' => 'doing', 'due_date' => '2026-10-20', 'priority' => 'low'],     // overdue
    ['id' => 5, 'status' => 'todo', 'due_date' => '2026-12-01', 'priority' => 'low'],      // same as 1 but newer
];
check('sort: overdue first, then due date, priority, newest; no date last', array_column(task_sort($tasks, $today), 'id'), [4, 3, 5, 1, 2]);
check('labels', [task_statuses()['doing'], task_priorities()['high'], project_statuses()['archived']], ['Sedang dikerjakan', 'Tinggi', 'Diarsipkan']);
echo "tasks_test: $ok checks passed\n";
