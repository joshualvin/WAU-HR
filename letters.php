<?php
// HR → Karyawan → Surat: letters from templates (paklaring, keterangan kerja, SK…), numbered, kept and printable.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/portal.php';
require __DIR__ . '/inc/hris.php';
require __DIR__ . '/inc/people.php';
require_once __DIR__ . '/inc/employment.php';
$me = require_login('admin');
ensure_web_tables();
ensure_people_tables();
ensure_employment_tables();
$company = isset($CONFIG['company']) ? $CONFIG['company'] : 'Perusahaan';

/** Placeholder values for one employee on $date. */
function letter_values($pegawaiId, $date)
{
    global $company;
    $e = q('SELECT p.*, p1.pembagian1_nama jab, p2.pembagian2_nama dep, p3.pembagian3_nama kan FROM pegawai p LEFT JOIN pembagian1 p1 ON p1.pembagian1_id = p.pembagian1_id
            LEFT JOIN pembagian2 p2 ON p2.pembagian2_id = p.pembagian2_id LEFT JOIN pembagian3 p3 ON p3.pembagian3_id = p.pembagian3_id WHERE p.pegawai_id = ?', [$pegawaiId])->fetch();
    $pr = get_profile($pegawaiId);
    $st = current_employment();
    $start = $e['tgl_mulai_kerja'] ? substr($e['tgl_mulai_kerja'], 0, 10) : null;
    $end = $e['tgl_resign'] && $e['tgl_resign'] !== '0000-00-00' ? substr($e['tgl_resign'], 0, 10) : null;
    $svc = '';
    if ($start) { $d = (new DateTime($start))->diff(new DateTime($end ?: $date)); $svc = "{$d->y} tahun {$d->m} bulan"; }
    $fd = function ($x) { return $x ? fmt_long_date($x) : '-'; };
    return ['nama' => $e['pegawai_nama'], 'nip' => $e['pegawai_nip'] ?: $e['pegawai_pin'], 'nik' => $pr['nik_ktp'] ?: '-', 'jabatan' => $e['jab'] ?: '-', 'departemen' => $e['dep'] ?: '-',
            'kantor' => $e['kan'] ?: '-', 'alamat' => $pr['address'] ?: '-', 'mulai_kerja' => $fd($start), 'tgl_berhenti' => $end ? $fd($end) : 'saat ini', 'masa_kerja' => $svc ?: '-',
            'status_kerja' => isset($st[$pegawaiId]) ? employment_statuses()[$st[$pegawaiId]['status']] : '-', 'perusahaan' => $company, 'tanggal' => $fd($date)];
}

function fmt_long_date($d)
{
    $m = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    return (int)substr($d, 8, 2) . ' ' . $m[(int)substr($d, 5, 2) - 1] . ' ' . substr($d, 0, 4);
}

if (get('print') !== '') {
    $l = q('SELECT * FROM web_letter WHERE id = ?', [(int)get('print')])->fetch();
    if (!$l) { http_response_code(404); exit('Surat tidak ditemukan.'); }
    ?><!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= h($l['title']) ?></title>
<style>body{font:14px/1.7 Georgia,"Times New Roman",serif;color:#1d2129;background:#f6f7f9;margin:0;padding:24px 12px}.doc{max-width:720px;margin:0 auto;background:#fff;border:1px solid #e2e5ea;border-radius:8px;padding:40px 48px}
.head{text-align:center;border-bottom:2px solid #1d2129;padding-bottom:10px;margin-bottom:18px}.head b{font-size:18px;letter-spacing:.04em}h1{text-align:center;font-size:16px;margin:0;text-decoration:underline;text-transform:uppercase}
.no{text-align:center;margin-bottom:22px}.body{white-space:pre-line}.sign{margin-top:40px;margin-left:auto;width:240px;text-align:center}.btn{padding:7px 14px;border:1px solid #cfd4dc;border-radius:6px;background:#fff;cursor:pointer;font:13px system-ui}
@media print{body{background:#fff;padding:0}.doc{border:none}.noprint{display:none}}</style></head><body>
<div class="noprint" style="text-align:center;margin-bottom:12px"><button class="btn" onclick="window.print()">Cetak / simpan PDF</button></div>
<div class="doc"><div class="head"><b><?= h($company) ?></b></div><h1><?= h($l['title']) ?></h1><div class="no">Nomor: <?= h($l['number']) ?></div>
<div class="body"><?= h($l['body']) ?></div>
<div class="sign"><?= h(fmt_long_date($l['issued'])) ?><br><?= h($company) ?><br><br><br><br><b><?= h($l['signer'] ?: '(............................)') ?></b></div></div></body></html>
<?php
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = post('action');
    if ($action === 'issue') {
        $tpl = q('SELECT * FROM web_letter_tpl WHERE id = ?', [(int)post('tpl_id')])->fetch();
        $emp = employee(post('pegawai_id'));
        $date = valid_date(post('issued')) ? post('issued') : date('Y-m-d');
        if (!$tpl || !$emp || trim(post('body')) === '') { flash('Pilih template dan karyawan.', 'err'); redirect('letters.php?p=make'); }
        q('INSERT INTO web_letter (tpl_id, pegawai_id, number, title, issued, body, signer, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$tpl['id'], $emp['pegawai_id'], letter_number($tpl['code'], $date), $tpl['name'], $date, post('body'), mb_substr(post('signer'), 0, 100), actor(), date('Y-m-d H:i:s')]);
        $id = (int)db()->lastInsertId();
        audit('letter_issue', "{$tpl['name']} {$emp['pegawai_nama']}");
        redirect('letters.php?print=' . $id);
    } elseif ($action === 'tpl_save') {
        foreach (isset($_POST['t']) ? (array)$_POST['t'] : [] as $id => $t) {
            if (trim($t['name']) === '') q('DELETE FROM web_letter_tpl WHERE id = ?', [(int)$id]);
            else q('UPDATE web_letter_tpl SET name = ?, code = ?, body = ? WHERE id = ?', [mb_substr(trim($t['name']), 0, 80), strtoupper(preg_replace('/\W/', '', $t['code'])) ?: 'SK', $t['body'], (int)$id]);
        }
        if (trim(post('new_name')) !== '' && trim(post('new_body')) !== '') q('INSERT INTO web_letter_tpl (name, code, body) VALUES (?, ?, ?)', [mb_substr(trim(post('new_name')), 0, 80), strtoupper(preg_replace('/\W/', '', post('new_code'))) ?: 'SK', post('new_body')]);
        audit('letter_templates', 'diubah');
        flash('Template disimpan.');
        redirect('letters.php?p=templates');
    }
}

$csrf = csrf_field();
$p = get('p', 'list');
if ($p === 'make'):
    $tpls = q('SELECT * FROM web_letter_tpl ORDER BY id')->fetchAll(PDO::FETCH_UNIQUE);
    $tid = (int)get('tpl_id', key($tpls)); $pid = (int)get('pegawai_id'); $date = valid_date(get('issued')) ? get('issued') : date('Y-m-d');
    $people = q('SELECT pegawai_id, pegawai_nama, pegawai_pin, pegawai_status FROM pegawai ORDER BY pegawai_status <> 1, pegawai_nama')->fetchAll();
    $body = $pid && isset($tpls[$tid]) ? render_letter($tpls[$tid]['body'], letter_values($pid, $date)) : '';
    hris_header('Buat surat', 'employee', 'letters.php', '<a href="letters.php">Surat</a>'); ?>
  <form method="get" class="toolbar"><input type="hidden" name="p" value="make">
    <select name="tpl_id"><?php foreach ($tpls as $id => $t): ?><option value="<?= (int)$id ?>"<?= $id == $tid ? ' selected' : '' ?>><?= h($t['name']) ?></option><?php endforeach; ?></select>
    <select name="pegawai_id" required><option value="">Pilih karyawan…</option><?php foreach ($people as $e): ?><option value="<?= (int)$e['pegawai_id'] ?>"<?= (int)$e['pegawai_id'] === $pid ? ' selected' : '' ?>><?= h($e['pegawai_nama']) ?><?= (int)$e['pegawai_status'] === 1 ? '' : ' (tidak aktif)' ?></option><?php endforeach; ?></select>
    <input type="date" name="issued" value="<?= h($date) ?>"><button class="btn">Isi surat</button></form>
  <?php if ($body): ?>
  <form method="post" class="section" style="max-width:none;margin-top:12px"><?= $csrf ?><input type="hidden" name="action" value="issue">
    <input type="hidden" name="tpl_id" value="<?= $tid ?>"><input type="hidden" name="pegawai_id" value="<?= $pid ?>"><input type="hidden" name="issued" value="<?= h($date) ?>">
    <label class="field"><span>Isi surat (bisa diubah sebelum diterbitkan)</span><textarea name="body" rows="16"><?= h($body) ?></textarea></label>
    <label class="field"><span>Ditandatangani oleh</span><input type="text" name="signer" maxlength="100" value="<?= h(isset($CONFIG['tax_signer']) ? $CONFIG['tax_signer'] : '') ?>"></label>
    <div class="formfoot"><button class="btn primary">Terbitkan & cetak</button></div></form>
  <?php endif; ?>
<?php
elseif ($p === 'templates'):
    $tpls = q('SELECT * FROM web_letter_tpl ORDER BY id')->fetchAll();
    hris_header('Template surat', 'employee', 'letters.php', '<a href="letters.php">Surat</a>'); ?>
  <form method="post" class="section" style="max-width:none"><?= $csrf ?><input type="hidden" name="action" value="tpl_save">
    <small class="muted">Kolom isian: <?= h(implode(' ', letter_placeholders())) ?>. Kosongkan nama untuk menghapus template.</small>
    <?php foreach ($tpls as $t): ?><div class="row2"><label class="field"><span>Nama</span><input type="text" name="t[<?= (int)$t['id'] ?>][name]" value="<?= h($t['name']) ?>"></label>
      <label class="field"><span>Kode nomor</span><input type="text" name="t[<?= (int)$t['id'] ?>][code]" value="<?= h($t['code']) ?>" maxlength="10"></label></div>
      <textarea name="t[<?= (int)$t['id'] ?>][body]" rows="9"><?= h($t['body']) ?></textarea><hr><?php endforeach; ?>
    <div class="row2"><label class="field"><span>Template baru</span><input type="text" name="new_name"></label><label class="field"><span>Kode nomor</span><input type="text" name="new_code" maxlength="10" placeholder="SK"></label></div>
    <textarea name="new_body" rows="6" placeholder="Isi surat dengan kolom isian di atas"></textarea>
    <div class="formfoot"><button class="btn primary">Simpan</button></div></form>
<?php
else:
    $rows = q('SELECT l.*, p.pegawai_nama FROM web_letter l JOIN pegawai p ON p.pegawai_id = l.pegawai_id ORDER BY l.issued DESC, l.id DESC LIMIT 300')->fetchAll();
    hris_header('Surat', 'employee', 'letters.php', 'Karyawan', '<a class="btn" href="letters.php?p=templates">Template</a> <a class="btn primary" href="letters.php?p=make">+ Buat surat</a>'); ?>
  <div class="tablecard"><div class="tablescroll"><table>
    <thead><tr><th>Tanggal</th><th>Nomor</th><th>Surat</th><th>Karyawan</th><th>Dibuat</th><th class="actions">Aksi</th></tr></thead><tbody>
    <?php foreach ($rows as $l): ?><tr><td><?= h(date('d-m-Y', strtotime($l['issued']))) ?></td><td class="muted"><?= h($l['number']) ?></td><td><?= h($l['title']) ?></td><td><b><?= h($l['pegawai_nama']) ?></b></td>
      <td class="muted"><?= h($l['created_by']) ?></td><td class="actions"><a class="btn small" target="_blank" href="letters.php?print=<?= (int)$l['id'] ?>">Cetak</a></td></tr><?php endforeach; ?>
    </tbody></table></div><?php if (!$rows): ?><div class="empty"><strong>Belum ada surat</strong>Buat paklaring, surat keterangan kerja atau SK dari template.</div><?php endif; ?></div>
<?php
endif;
hris_footer();
