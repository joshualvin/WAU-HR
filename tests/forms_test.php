<?php
// Self-check for the forms & surveys logic that needs no database: php tests/forms_test.php
require __DIR__ . '/../inc/forms.php';
$ok = 0;
function check($label, $got, $want) { global $ok; if ($got !== $want) { fwrite(STDERR, "FAIL $label: got " . var_export($got, true) . ', want ' . var_export($want, true) . "\n"); exit(1); } $ok++; }

check('options trimmed, deduped, blanks dropped', form_parse_options("  Ya \r\n\nTidak\nYa\n  \nMungkin"), ['Ya', 'Tidak', 'Mungkin']);
check('options capped at 20', count(form_parse_options(implode("\n", range(1, 30)))), 20);
check('question ok', [form_question_validate('Apa kabar?', 'text', ''), form_question_validate('Pilih', 'choice', "A\nB")], [null, null]);
check('question needs text', form_question_validate('  ', 'text', ''), 'Isi pertanyaannya.');
check('question needs a known kind', form_question_validate('x', 'zzz', ''), 'Pilih jenis pertanyaan.');
check('choice needs two options', [form_question_validate('x', 'choice', "A"), form_question_validate('x', 'multi', "A\nA")], ['Pilihan butuh minimal dua opsi (satu per baris).', 'Pilihan butuh minimal dua opsi (satu per baris).']);
check('open on time', [form_is_open(['status' => 'open', 'due_date' => null], '2026-11-04'), form_is_open(['status' => 'open', 'due_date' => '2026-11-04'], '2026-11-04'), form_is_open(['status' => 'open', 'due_date' => '2026-11-03'], '2026-11-04'), form_is_open(['status' => 'draft', 'due_date' => null], '2026-11-04'), form_is_open(['status' => 'closed', 'due_date' => null], '2026-11-04')], [true, true, false, false, false]);

$qs = [
    ['id' => 1, 'label' => 'Nama panggilan', 'kind' => 'text', 'options' => '', 'required' => 1],
    ['id' => 2, 'label' => 'Divisi', 'kind' => 'choice', 'options' => "Gudang\nIT\nSales", 'required' => 1],
    ['id' => 3, 'label' => 'Fasilitas', 'kind' => 'multi', 'options' => "Parkir\nKantin\nMess", 'required' => 0],
    ['id' => 4, 'label' => 'Kepuasan', 'kind' => 'rating', 'options' => '', 'required' => 1],
    ['id' => 5, 'label' => 'Setuju?', 'kind' => 'yesno', 'options' => '', 'required' => 0],
    ['id' => 6, 'label' => 'Lama kerja (tahun)', 'kind' => 'number', 'options' => '', 'required' => 0],
    ['id' => 7, 'label' => 'Tanggal mulai', 'kind' => 'date', 'options' => '', 'required' => 0],
    ['id' => 8, 'label' => 'Saran', 'kind' => 'long', 'options' => '', 'required' => 0],
];
$r = form_check_answers($qs, [1 => ' Budi ', 2 => 'IT', 3 => ['Parkir', 'Mess'], 4 => '5', 5 => 'ya', 6 => '2,5', 7 => '2026-11-03', 8 => '']);
check('good answers are cleaned', [$r['errors'], $r['clean']], [[], [1 => 'Budi', 2 => 'IT', 3 => '["Parkir","Mess"]', 4 => '5', 5 => 'ya', 6 => '2.5', 7 => '2026-11-03']]);
$r = form_check_answers($qs, []);
check('required questions are listed', $r['errors'], ['"Nama panggilan" wajib diisi.', '"Divisi" wajib diisi.', '"Kepuasan" wajib diisi.']);
$r = form_check_answers($qs, [1 => 'x', 2 => 'HRD', 3 => ['Gym'], 4 => '6', 5 => 'mungkin', 6 => 'abc', 7 => '03/11/2026']);
check('bad answers are each refused (choice, multi, rating, yes/no, number, date)', count($r['errors']), 6);
check('nothing bad is stored', array_keys($r['clean']), [1]);
$r = form_check_answers($qs, [1 => 'x', 2 => 'IT', 4 => '0']);
check('rating 0 refused', $r['errors'], ['"Kepuasan": isi nilai 1 sampai 5.']);
$r = form_check_answers($qs, [1 => str_repeat('a', 2001), 2 => 'IT', 4 => '3']);
check('too long refused', count($r['errors']), 1);
$r = form_check_answers($qs, [1 => ['x'], 2 => 'IT', 4 => '3']);
check('an array for a single answer counts as empty (required)', $r['errors'], ['"Nama panggilan" wajib diisi.']);

check('answer text', [form_answer_text('multi', '["A","B"]'), form_answer_text('yesno', 'ya'), form_answer_text('yesno', 'tidak'), form_answer_text('text', 'hi')], ['A, B', 'Ya', 'Tidak', 'hi']);
$s = form_summarize($qs[1], ['IT', 'Gudang', 'IT']);
check('choice counts keep the option order and zero options', $s['counts'], ['Gudang' => 1, 'IT' => 2, 'Sales' => 0]);
$s = form_summarize($qs[2], ['["Parkir","Mess"]', '["Parkir"]']);
check('multi counts every pick', $s['counts'], ['Parkir' => 2, 'Kantin' => 0, 'Mess' => 1]);
$s = form_summarize($qs[3], ['5', '4', '4', '1']);
check('rating counts and average', [$s['counts'], $s['avg'], $s['min'], $s['max'], $s['n']], [['1' => 1, '2' => 0, '3' => 0, '4' => 2, '5' => 1], 3.5, 1.0, 5.0, 4]);
$s = form_summarize($qs[4], ['ya', 'ya', 'tidak']);
check('yes/no counts', $s['counts'], ['ya' => 2, 'tidak' => 1]);
$s = form_summarize($qs[5], ['2.5', '10']);
check('number stats', [$s['avg'], $s['min'], $s['max']], [6.3, 2.5, 10.0]);   // (2.5 + 10) / 2 = 6.25 -> 6.3
$s = form_summarize($qs[7], ['a', 'b', 'c']);
check('texts newest first', $s['texts'], ['c', 'b', 'a']);
check('no answers', form_summarize($qs[3], []), ['n' => 0, 'counts' => ['1' => 0, '2' => 0, '3' => 0, '4' => 0, '5' => 0], 'avg' => null, 'min' => null, 'max' => null, 'texts' => []]);
echo "forms_test: $ok checks passed\n";
