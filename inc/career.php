<?php
// Link with the company careers site (cv-upload, e.g. career.wautama.com) through its /hr-api:
// openings made here are written to its Positions sheet, and its applicants (with the AI score) come in as candidates.
// The careers site cannot reach this server on the LAN, so this server always makes the calls.

const CAREER_STATE = __DIR__ . '/../uploads/career_sync.json';

function career_enabled()
{
    global $CONFIG;
    return !empty($CONFIG['career_url']) && !empty($CONFIG['career_token']);
}

function career_url()
{
    global $CONFIG;
    return rtrim($CONFIG['career_url'], '/');
}

/** One call to the careers site's HR API. Returns [decoded JSON or raw body, error or null]. */
function career_call($method, $path, $body = null, $raw = false)
{
    global $CONFIG;
    $ch = curl_init(career_url() . '/hr-api' . $path);
    $headers = ['Authorization: Bearer ' . $CONFIG['career_token'], 'Accept: application/json'];
    $opts = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => $raw ? 60 : 25];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($body);
        $headers[] = 'Content-Type: application/json';
    }
    curl_setopt_array($ch, $opts + [CURLOPT_HTTPHEADER => $headers]);
    $res = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($res === false) return [null, 'Tidak bisa terhubung ke ' . career_url() . ': ' . $err];
    if ($http !== 200) {
        $json = json_decode($res, true);
        $msg = is_array($json) && isset($json['error']) ? $json['error'] : substr(strip_tags($res), 0, 150);
        return [null, "Situs karir HTTP $http: $msg" . ($http === 401 ? ' (periksa career_token di config.php dan HR_API_TOKEN di situs karir)' : '')];
    }
    if ($raw) return [$res, null];
    $json = json_decode($res, true);
    return is_array($json) ? [$json, null] : [null, 'Jawaban situs karir tidak bisa dibaca.'];
}

/** A web_job row as a Positions-sheet entry. */
function career_position($j, $deps, $offices)
{
    return [
        'name' => $j['title'],
        'requirements' => $j['requirements'],
        'open' => $j['status'] === 'open' && (!$j['close_date'] || $j['close_date'] >= date('Y-m-d')),
        // the careers site's own text is kept for a department / location that has no match here
        'department' => isset($deps[$j['pembagian2_id']]) ? $deps[$j['pembagian2_id']] : $j['dept_text'],
        'location' => isset($offices[$j['pembagian3_id']]) ? $offices[$j['pembagian3_id']] : $j['loc_text'],
        'type' => $j['employment'],
        'description' => $j['description'],
        'responsibilities' => (string)$j['responsibilities'],
    ];
}

/** The AI review of one applicant as a few lines for HR. */
function career_ai_note($a)
{
    $lines = [];
    foreach (['matchReason' => 'Alasan skor', 'summary' => 'Ringkasan', 'redFlags' => 'Perlu dicek', 'education' => 'Pendidikan', 'lastJob' => 'Pekerjaan terakhir'] as $k => $label) {
        if (isset($a[$k]) && trim($a[$k]) !== '') $lines[] = "$label: " . trim($a[$k]);
    }
    if (isset($a['experience']) && $a['experience'] !== null) $lines[] = 'Pengalaman: ' . $a['experience'] . ' tahun';
    if (isset($a['age']) && $a['age'] !== null) $lines[] = 'Usia: ' . $a['age'];
    return implode("\n", $lines);
}

/**
 * Sync with the careers site: (1) positions that exist only there become jobs here, (2) every job here is written to
 * its Positions sheet, (3) new applicants become candidates, and AI results that arrived later are filled in.
 * $applicants = false only does 1 and 2 (after saving a job). Returns [summary, error or null].
 */
function career_sync($applicants = true)
{
    if (!career_enabled()) return ['', 'Situs karir belum diatur (career_url dan career_token di config.php).'];
    list($res, $err) = career_call('GET', '/positions');
    if ($err) return career_done('', $err);
    $deps = lookup('pembagian2');
    $offices = lookup('pembagian3');
    $titles = array_map('mb_strtolower', q('SELECT title FROM web_job')->fetchAll(PDO::FETCH_COLUMN));
    $imported = 0;
    foreach ($res['positions'] as $p) {
        if (in_array(mb_strtolower($p['name']), $titles, true)) continue;
        q('INSERT INTO web_job (title, pembagian2_id, pembagian3_id, dept_text, loc_text, employment, description, responsibilities, requirements, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$p['name'], (int)array_search(mb_strtolower($p['department']), array_map('mb_strtolower', $deps)), (int)array_search(mb_strtolower($p['location']), array_map('mb_strtolower', $offices)),
             mb_substr($p['department'], 0, 60), mb_substr($p['location'], 0, 60),
             mb_substr($p['type'] ?: 'Karyawan tetap', 0, 30), $p['description'], $p['responsibilities'], $p['requirements'], $p['open'] ? 'open' : 'closed', date('Y-m-d H:i:s')]);
        $titles[] = mb_strtolower($p['name']);
        $imported++;
    }
    $positions = [];
    foreach (q('SELECT * FROM web_job ORDER BY created_at') as $j) $positions[] = career_position($j, $deps, $offices);
    list(, $err) = career_call('PUT', '/positions', ['positions' => $positions]);
    if ($err) return career_done('', $err);
    $msg = count($positions) . ' lowongan dikirim ke situs karir' . ($imported ? ", $imported lowongan baru diambil dari sana" : '');
    if (!$applicants) return career_done($msg, null);

    list($res, $err) = career_call('GET', '/applicants');
    if ($err) return career_done($msg, $err);
    $jobs = [];
    foreach (q('SELECT id, title FROM web_job') as $j) $jobs[mb_strtolower($j['title'])] = (int)$j['id'];
    $known = q('SELECT ext_id, ai_score FROM web_candidate WHERE ext_id IS NOT NULL')->fetchAll(PDO::FETCH_KEY_PAIR);
    $added = 0;
    $dateSamples = [];   // first few raw Submitted cells with how they were read, shown in the result
    foreach ($res['applicants'] as $a) {
        if (count($dateSamples) < 3) { $pt = career_parse_date($a['submitted']); $dateSamples[] = "'" . mb_substr($a['submitted'], 0, 30) . "' → " . ($pt ? date('d-m-Y', $pt) : 'tidak terbaca'); }
        $note = career_ai_note($a);
        if (array_key_exists($a['id'], $known)) {
            $ts = career_parse_date($a['submitted']);   // an earlier import may have stored the import day instead of the day the CV was sent
            if ($ts) q('UPDATE web_candidate SET created_at = ? WHERE ext_id = ? AND DATE(created_at) <> ?', [date('Y-m-d H:i:s', $ts), $a['id'], date('Y-m-d', $ts)]);
            if ($known[$a['id']] === null && $a['matchScore'] !== null) q('UPDATE web_candidate SET ai_score = ?, ai_note = ? WHERE ext_id = ?', [(int)$a['matchScore'], $note, $a['id']]);
            continue;
        }
        $ts = career_parse_date($a['submitted']);
        $at = $ts ? date('Y-m-d H:i:s', $ts) : date('Y-m-d H:i:s');
        $source = stripos($a['source'], 'offline') === 0 ? 'Karir (offline)' : 'Karir (online)';
        q('INSERT INTO web_candidate (job_id, name, email, phone, cv_ext, cover, source, ext_id, ai_score, ai_note, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [isset($jobs[mb_strtolower($a['position'])]) ? $jobs[mb_strtolower($a['position'])] : 0, mb_substr($a['name'], 0, 120), mb_substr($a['email'], 0, 120), mb_substr($a['phone'], 0, 30),
             $a['cvId'], $a['otherInterest'] !== '' ? 'Posisi yang diminati: ' . $a['otherInterest'] : null, $source, $a['id'],
             $a['matchScore'] === null ? null : (int)$a['matchScore'], $note, $at, $at]);
        $added++;
    }
    return career_done($msg . "; $added pelamar baru. Contoh tanggal lamaran dari sheet: " . implode(' | ', $dateSamples), null);
}

function career_done($msg, $err)
{
    @file_put_contents(CAREER_STATE, json_encode(['at' => time(), 'msg' => $msg, 'error' => $err]));
    return [$msg, $err];
}

/** ['at' => unix time, 'msg', 'error'] of the last sync, or null. */
function career_last_sync()
{
    $s = is_file(CAREER_STATE) ? json_decode(file_get_contents(CAREER_STATE), true) : null;
    return is_array($s) ? $s : null;
}

/** Download a candidate's CV from the careers site and keep it in uploads/cv. Returns [file name, error]. */
function career_fetch_cv($cand)
{
    list($bytes, $err) = career_call('GET', '/cv/' . rawurlencode($cand['cv_ext']), null, true);
    if ($err) return ['', $err];
    $types = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'application/msword' => 'doc', 'application/vnd.ms-office' => 'doc', 'application/CDFV2' => 'doc',
              'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx', 'application/zip' => 'docx'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
    if (!isset($types[$mime])) return ['', "Jenis file CV tidak dikenal ($mime)."];
    if (!is_dir(CV_DIR)) mkdir(CV_DIR, 0775, true);
    $name = bin2hex(random_bytes(12)) . '.' . $types[$mime];
    if (file_put_contents(CV_DIR . '/' . $name, $bytes) === false) return ['', 'CV tidak bisa disimpan di server.'];
    q('UPDATE web_candidate SET cv_file = ? WHERE id = ?', [$name, $cand['id']]);
    return [$name, null];
}

/** The day an applicant sent the CV, from the sheet's Submitted cell: 2026-10-17 14:32, 17/10/2026 14.32, 17-10-2026, a spreadsheet date number, or anything strtotime knows. Null when unreadable. */
function career_parse_date($v)
{
    $v = trim((string)$v);
    if ($v === '') return null;
    if (is_numeric($v) && $v > 30000 && $v < 80000) return strtotime(gmdate('Y-m-d H:i:s', (int)round(((float)$v - 25569) * 86400)));   // spreadsheet serial date; the sheet's clock time, not UTC
    if (preg_match('#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})(?:[ ,T]+(\d{1,2})[:.](\d{2}))?#', $v, $m) && checkdate((int)$m[2], (int)$m[1], (int)$m[3]))
        return mktime(isset($m[4]) ? (int)$m[4] : 12, isset($m[5]) ? (int)$m[5] : 0, 0, (int)$m[2], (int)$m[1], (int)$m[3]);
    $t = strtotime($v);
    return $t ?: null;
}
