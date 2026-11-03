<?php
// Formulir & survei (like Talenta's Forms & surveys): HR builds a form or survey, opens it, employees answer it in the Portal Pegawai,
// HR reads a summary per question, the single answers and a CSV. A survey can be anonymous: the answers are then stored without the
// employee, while web_form_done still remembers who has answered so nobody answers twice.

function ensure_form_tables()
{
    db()->exec("CREATE TABLE IF NOT EXISTS web_form (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, title VARCHAR(150) NOT NULL, description VARCHAR(500) NOT NULL DEFAULT '',
        kind VARCHAR(8) NOT NULL DEFAULT 'form', status VARCHAR(8) NOT NULL DEFAULT 'draft', anonymous TINYINT NOT NULL DEFAULT 0, due_date DATE NULL,
        created_by VARCHAR(50) NOT NULL DEFAULT '', created_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    db()->exec("CREATE TABLE IF NOT EXISTS web_form_q (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, form_id INT UNSIGNED NOT NULL, pos INT NOT NULL DEFAULT 0, label VARCHAR(255) NOT NULL,
        kind VARCHAR(8) NOT NULL DEFAULT 'text', options TEXT NOT NULL, required TINYINT NOT NULL DEFAULT 0, KEY form (form_id, pos)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    db()->exec("CREATE TABLE IF NOT EXISTS web_form_resp (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, form_id INT UNSIGNED NOT NULL, pegawai_id INT NULL, submitted_at DATETIME NOT NULL, KEY form (form_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    db()->exec("CREATE TABLE IF NOT EXISTS web_form_ans (
        resp_id INT UNSIGNED NOT NULL, q_id INT UNSIGNED NOT NULL, value TEXT NOT NULL, PRIMARY KEY (resp_id, q_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    db()->exec("CREATE TABLE IF NOT EXISTS web_form_done (
        form_id INT UNSIGNED NOT NULL, pegawai_id INT NOT NULL, PRIMARY KEY (form_id, pegawai_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
}

function form_kinds() { return ['form' => 'Formulir', 'survey' => 'Survei']; }
function form_statuses() { return ['draft' => ['Draf', 'grey'], 'open' => ['Dibuka', 'ok'], 'closed' => ['Ditutup', 'bad']]; }

/** Question types: key => label. */
function form_question_kinds()
{
    return ['text' => 'Jawaban singkat', 'long' => 'Jawaban panjang', 'choice' => 'Pilihan tunggal', 'multi' => 'Pilihan ganda', 'rating' => 'Penilaian 1-5', 'yesno' => 'Ya / Tidak', 'number' => 'Angka', 'date' => 'Tanggal'];
}

/** Options typed one per line: trimmed, blank and duplicate lines dropped, at most 20. */
function form_parse_options($text)
{
    $out = [];
    foreach (preg_split('/\R/', (string)$text) as $l) { $l = trim($l); if ($l !== '' && !in_array($l, $out, true) && count($out) < 20) $out[] = mb_substr($l, 0, 100); }
    return $out;
}

/** Check a question from the editor. Returns an error message or null. */
function form_question_validate($label, $kind, $optionsText)
{
    if (trim((string)$label) === '') return 'Isi pertanyaannya.';
    if (mb_strlen($label) > 255) return 'Pertanyaan terlalu panjang (maksimal 255 huruf).';
    if (!isset(form_question_kinds()[$kind])) return 'Pilih jenis pertanyaan.';
    if (in_array($kind, ['choice', 'multi'], true) && count(form_parse_options($optionsText)) < 2) return 'Pilihan butuh minimal dua opsi (satu per baris).';
    return null;
}

/** Is the form open for answers on $today? */
function form_is_open($form, $today)
{
    return $form['status'] === 'open' && ($form['due_date'] === null || $form['due_date'] === '' || $form['due_date'] >= $today);
}

/**
 * Check and clean an employee's answers. $questions: rows with id, label, kind, options (text), required. $answers: [q_id => string | array for multi].
 * Returns [clean => [q_id => string], errors => [message, ...]]; a multiple choice is stored as a JSON list. Empty optional answers are left out.
 */
function form_check_answers($questions, $answers)
{
    $clean = [];
    $errors = [];
    foreach ($questions as $q) {
        $id = (int)$q['id'];
        $raw = isset($answers[$id]) ? $answers[$id] : '';
        $opts = form_parse_options($q['options']);
        $label = '"' . mb_substr($q['label'], 0, 40) . '"';
        if ($q['kind'] === 'multi') {
            $vals = array_values(array_filter(array_map('trim', is_array($raw) ? $raw : []), 'strlen'));
            $bad = array_diff($vals, $opts);
            if ($bad) { $errors[] = "$label: pilihan tidak dikenal."; continue; }
            if (!$vals) { if ($q['required']) $errors[] = "$label wajib diisi."; continue; }
            $clean[$id] = json_encode(array_values($vals), JSON_UNESCAPED_UNICODE);
            continue;
        }
        $v = trim(is_array($raw) ? '' : (string)$raw);
        if ($v === '') { if ($q['required']) $errors[] = "$label wajib diisi."; continue; }
        if ($q['kind'] === 'choice' && !in_array($v, $opts, true)) { $errors[] = "$label: pilihan tidak dikenal."; continue; }
        if ($q['kind'] === 'rating' && !(ctype_digit($v) && $v >= 1 && $v <= 5)) { $errors[] = "$label: isi nilai 1 sampai 5."; continue; }
        if ($q['kind'] === 'yesno' && !in_array($v, ['ya', 'tidak'], true)) { $errors[] = "$label: pilih ya atau tidak."; continue; }
        if ($q['kind'] === 'number' && !is_numeric(str_replace(',', '.', $v))) { $errors[] = "$label: isi angka."; continue; }
        if ($q['kind'] === 'date' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) || !strtotime($v))) { $errors[] = "$label: isi tanggal yang benar."; continue; }
        if (mb_strlen($v) > 2000) { $errors[] = "$label terlalu panjang (maksimal 2000 huruf)."; continue; }
        $clean[$id] = $q['kind'] === 'number' ? str_replace(',', '.', $v) : $v;
    }
    return ['clean' => $clean, 'errors' => $errors];
}

/** Stored answer to a readable string (a multiple choice list becomes "A, B"). */
function form_answer_text($kind, $value)
{
    if ($kind === 'multi') { $l = json_decode($value, true); return is_array($l) ? implode(', ', $l) : (string)$value; }
    if ($kind === 'yesno') return $value === 'ya' ? 'Ya' : ($value === 'tidak' ? 'Tidak' : (string)$value);
    return (string)$value;
}

/**
 * Summary of one question from its stored answers ($values: list of stored strings, one per response). Returns ['n' => answers,
 * 'counts' => [option => n] for choices / yes-no / rating, 'avg' / 'min' / 'max' for rating and number, 'texts' => latest 20 for text / date].
 */
function form_summarize($q, $values)
{
    $out = ['n' => count($values), 'counts' => [], 'avg' => null, 'min' => null, 'max' => null, 'texts' => []];
    $kind = $q['kind'];
    if (in_array($kind, ['choice', 'multi', 'yesno', 'rating'], true)) {
        $opts = $kind === 'yesno' ? ['ya', 'tidak'] : ($kind === 'rating' ? ['1', '2', '3', '4', '5'] : form_parse_options($q['options']));
        $out['counts'] = array_fill_keys($opts, 0);
        foreach ($values as $v) foreach ($kind === 'multi' ? (json_decode($v, true) ?: []) : [$v] as $x) if (isset($out['counts'][$x])) $out['counts'][$x]++;
    }
    if (in_array($kind, ['rating', 'number'], true)) {
        $nums = array_map('floatval', array_filter($values, 'is_numeric'));
        if ($nums) { $out['avg'] = round(array_sum($nums) / count($nums), 1); $out['min'] = min($nums); $out['max'] = max($nums); }
    }
    if (in_array($kind, ['text', 'long', 'date'], true)) $out['texts'] = array_slice(array_reverse($values), 0, 20);
    return $out;
}

// ---------- database ----------

function form_questions($formId)
{
    return q('SELECT * FROM web_form_q WHERE form_id = ? ORDER BY pos, id', [(int)$formId])->fetchAll();
}

function form_response_count($formId)
{
    return (int)q('SELECT COUNT(*) FROM web_form_resp WHERE form_id = ?', [(int)$formId])->fetchColumn();
}

/** Create or update a form's settings; returns its id. */
function form_save($id, $in, $by)
{
    $kind = isset(form_kinds()[$in['kind']]) ? $in['kind'] : 'form';
    $due = $in['due'] !== '' && $in['due'] !== null ? $in['due'] : null;
    if ($id) {
        q('UPDATE web_form SET title = ?, description = ?, kind = ?, anonymous = ?, due_date = ? WHERE id = ?', [mb_substr(trim($in['title']), 0, 150), mb_substr((string)$in['description'], 0, 500), $kind, $in['anonymous'] ? 1 : 0, $due, (int)$id]);
        return (int)$id;
    }
    q('INSERT INTO web_form (title, description, kind, status, anonymous, due_date, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        [mb_substr(trim($in['title']), 0, 150), mb_substr((string)$in['description'], 0, 500), $kind, 'draft', $in['anonymous'] ? 1 : 0, $due, $by, date('Y-m-d H:i:s')]);
    return (int)db()->lastInsertId();
}

/** Add (id 0) or change a question; options are the typed lines. Returns the id. */
function form_question_save($formId, $id, $label, $kind, $optionsText, $required)
{
    $opts = in_array($kind, ['choice', 'multi'], true) ? implode("\n", form_parse_options($optionsText)) : '';
    if ($id) { q('UPDATE web_form_q SET label = ?, kind = ?, options = ?, required = ? WHERE id = ? AND form_id = ?', [trim($label), $kind, $opts, $required ? 1 : 0, (int)$id, (int)$formId]); return (int)$id; }
    $pos = (int)q('SELECT COALESCE(MAX(pos), 0) + 1 FROM web_form_q WHERE form_id = ?', [(int)$formId])->fetchColumn();
    q('INSERT INTO web_form_q (form_id, pos, label, kind, options, required) VALUES (?, ?, ?, ?, ?, ?)', [(int)$formId, $pos, trim($label), $kind, $opts, $required ? 1 : 0]);
    return (int)db()->lastInsertId();
}

/** Swap a question with its neighbour above ('up') or below ('down'); positions are renumbered first so equal ones cannot stick. */
function form_question_move($formId, $qid, $dir)
{
    $ids = array_map('intval', q('SELECT id FROM web_form_q WHERE form_id = ? ORDER BY pos, id', [(int)$formId])->fetchAll(PDO::FETCH_COLUMN));
    $i = array_search((int)$qid, $ids, true);
    $j = $i === false ? false : $i + ($dir === 'up' ? -1 : 1);
    if ($i === false || $j < 0 || $j >= count($ids)) return false;
    list($ids[$i], $ids[$j]) = [$ids[$j], $ids[$i]];
    foreach ($ids as $n => $id) q('UPDATE web_form_q SET pos = ? WHERE id = ?', [$n + 1, $id]);
    return true;
}

/** Forms an employee can still answer: open, not past the due date, not answered yet. */
function forms_for_employee($pegawaiId, $today = null)
{
    $today = $today ?: date('Y-m-d');
    return q("SELECT f.* FROM web_form f WHERE f.status = 'open' AND (f.due_date IS NULL OR f.due_date >= ?)
              AND NOT EXISTS (SELECT 1 FROM web_form_done d WHERE d.form_id = f.id AND d.pegawai_id = ?) AND EXISTS (SELECT 1 FROM web_form_q x WHERE x.form_id = f.id)
              ORDER BY f.due_date IS NULL, f.due_date, f.id", [$today, (int)$pegawaiId])->fetchAll();
}

/** An employee's answers. Returns [ok, errors]. Anonymous forms store no employee on the response, only the "done" mark. */
function form_submit($form, $pegawaiId, $answers, $today = null)
{
    $today = $today ?: date('Y-m-d');
    if (!form_is_open($form, $today)) return [false, ['Formulir ini sudah ditutup.']];
    if (q('SELECT COUNT(*) FROM web_form_done WHERE form_id = ? AND pegawai_id = ?', [(int)$form['id'], (int)$pegawaiId])->fetchColumn()) return [false, ['Anda sudah mengisi formulir ini.']];
    $r = form_check_answers(form_questions($form['id']), $answers);
    if ($r['errors']) return [false, $r['errors']];
    if (!$r['clean']) return [false, ['Isi minimal satu jawaban.']];
    db()->beginTransaction();
    try {
        q('INSERT INTO web_form_done (form_id, pegawai_id) VALUES (?, ?)', [(int)$form['id'], (int)$pegawaiId]);   // the primary key stops a double submit
        q('INSERT INTO web_form_resp (form_id, pegawai_id, submitted_at) VALUES (?, ?, ?)', [(int)$form['id'], $form['anonymous'] ? null : (int)$pegawaiId, date('Y-m-d H:i:s')]);
        $rid = (int)db()->lastInsertId();
        foreach ($r['clean'] as $qid => $v) q('INSERT INTO web_form_ans (resp_id, q_id, value) VALUES (?, ?, ?)', [$rid, (int)$qid, $v]);
        db()->commit();
    } catch (Exception $e) {
        db()->rollBack();
        return [false, ['Formulir tidak bisa disimpan, coba lagi.']];
    }
    return [true, []];
}

/** Everything for the results page: questions, per-question summaries and the single responses. */
function form_results($formId)
{
    $qs = form_questions($formId);
    $byQ = [];
    foreach (q('SELECT a.q_id, a.value FROM web_form_ans a JOIN web_form_resp r ON r.id = a.resp_id WHERE r.form_id = ? ORDER BY r.id', [(int)$formId]) as $a) $byQ[$a['q_id']][] = $a['value'];
    $summary = [];
    foreach ($qs as $q) $summary[$q['id']] = form_summarize($q, isset($byQ[$q['id']]) ? $byQ[$q['id']] : []);
    $resp = q('SELECT r.id, r.pegawai_id, r.submitted_at, p.pegawai_nama FROM web_form_resp r LEFT JOIN pegawai p ON p.pegawai_id = r.pegawai_id WHERE r.form_id = ? ORDER BY r.id', [(int)$formId])->fetchAll();
    $ans = [];
    foreach (q('SELECT a.resp_id, a.q_id, a.value FROM web_form_ans a JOIN web_form_resp r ON r.id = a.resp_id WHERE r.form_id = ?', [(int)$formId]) as $a) $ans[$a['resp_id']][$a['q_id']] = $a['value'];
    return ['questions' => $qs, 'summary' => $summary, 'responses' => $resp, 'answers' => $ans];
}

function form_delete($id)
{
    foreach (['web_form_ans' => 'resp_id IN (SELECT id FROM web_form_resp WHERE form_id = ?)', 'web_form_resp' => 'form_id = ?', 'web_form_done' => 'form_id = ?', 'web_form_q' => 'form_id = ?', 'web_form' => 'id = ?'] as $t => $w)
        q("DELETE FROM $t WHERE $w", [(int)$id]);
}
