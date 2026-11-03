<?php
// HR → Karyawan → Surat peringatan: verbal warnings and SP1-SP3, the printable letter (?print=ID). Admins only;
// employees see and acknowledge their own in the Portal Pegawai.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/portal.php';
require __DIR__ . '/inc/hris.php';
require __DIR__ . '/inc/discipline.php';
ensure_web_tables();
ensure_discipline_tables();
$today = date('Y-m-d');

// ---------- printable letter: admin, or the employee it is for ----------
if (get('print') !== '') {
    $w = q('SELECT w.*, p.pegawai_nama, p.pegawai_pin, p.pegawai_nip, p1.pembagian1_nama jab, p2.pembagian2_nama dep FROM web_warning w JOIN pegawai p ON p.pegawai_id = w.pegawai_id
            LEFT JOIN pembagian1 p1 ON p1.pembagian1_id = p.pembagian1_id LEFT JOIN pembagian2 p2 ON p2.pembagian2_id = p.pembagian2_id WHERE w.id = ?', [(int)get('print')])->fetch();
    $admin = current_user() && current_user()['role'] === 'admin';
    $emp = current_emp();
    if (!$w || (!$admin && (!$emp || $emp['id'] !== (int)$w['pegawai_id']))) { http_response_code(404); exit('Surat tidak ditemukan.'); }
    $company = isset($CONFIG['company']) ? $CONFIG['company'] : 'Perusahaan';
    ?><!doctype html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h(warning_levels()[$w['level']]) ?> <?= h($w['pegawai_nama']) ?></title>
<style>
  body { font: 14px/1.6 Georgia, "Times New Roman", serif; color: #1d2129; background: #f6f7f9; margin: 0; padding: 24px 12px; }
  .doc { max-width: 720px; margin: 0 auto; background: #fff; border: 1px solid #e2e5ea; border-radius: 8px; padding: 40px 48px; }
  .head { text-align: center; border-bottom: 2px solid #1d2129; padding-bottom: 10px; margin-bottom: 18px; } .head b { font-size: 18px; letter-spacing: .04em; }
  h1 { text-align: center; font-size: 16px; margin: 0; text-decoration: underline; } .no { text-align: center; margin-bottom: 18px; }
  table { border-collapse: collapse; margin: 8px 0 14px; } td { padding: 2px 10px 2px 0; vertical-align: top; }
  .sign { display: flex; justify-content: space-between; margin-top: 40px; text-align: center; } .sign div { min-width: 200px; }
  .btn { display: inline-block; margin: 0 auto 12px; padding: 7px 14px; border: 1px solid #cfd4dc; border-radius: 6px; background: #fff; cursor: pointer; font: 13px system-ui; }
  @media (max-width: 640px) { .doc { padding: 20px; } .sign { flex-direction: column; gap: 30px; } }
  @media print { body { background: #fff; padding: 0; } .doc { border: none; } .noprint { display: none; } }
</style></head><body>
<div class="noprint" style="text-align:center"><button class="btn" onclick="window.print()">Cetak / simpan PDF</button></div>
<div class="doc">
  <div class="head"><b><?= h($company) ?></b></div>
  <h1><?= h(strtoupper(warning_titles()[$w['level']])) ?></h1>
  <div class="no">Nomor: <?= h($w['number']) ?></div>
  <p>Diberikan kepada:</p>
  <table><tr><td>Nama</td><td>: <b><?= h($w['pegawai_nama']) ?></b></td></tr><tr><td>NIP / PIN</td><td>: <?= h($w['pegawai_nip'] ?: $w['pegawai_pin']) ?></td></tr>
    <tr><td>Jabatan</td><td>: <?= h($w['jab'] ?: '-') ?></td></tr><tr><td>Departemen</td><td>: <?= h($w['dep'] ?: '-') ?></td></tr></table>
  <p>Sehubungan dengan pelanggaran yang Saudara lakukan, yaitu <b><?= h($w['violation']) ?></b><?= $w['description'] ? ':' : '.' ?></p>
  <?php if ($w['description']): ?><p style="white-space:pre-line;margin-left:20px"><?= h($w['description']) ?></p><?php endif; ?>
  <p>maka dengan ini perusahaan memberikan <?= h(warning_titles()[$w['level']]) ?> kepada Saudara. <?= $w['level'] === 'lisan' ? 'Teguran ini' : 'Surat peringatan ini' ?> berlaku
    selama masa <b><?= h(date('d-m-Y', strtotime($w['issued']))) ?></b> sampai dengan <b><?= h(date('d-m-Y', strtotime($w['valid_until']))) ?></b>.</p>
  <p><?= h(warning_consequence($w['level'])) ?></p>
  <p>Demikian <?= $w['level'] === 'lisan' ? 'catatan teguran' : 'surat peringatan' ?> ini dibuat untuk diperhatikan dan menjadi bahan perbaikan.</p>
  <p><?= h(fmt_date($w['issued'])) ?></p>
  <div class="sign"><div>Hormat kami,<br><?= h($company) ?><br><br><br><br><b><?= h($w['signer'] ?: '(............................)') ?></b></div>
    <div>Diterima oleh,<br><br><br><br><br><b><?= h($w['pegawai_nama']) ?></b><?= $w['acknowledged_at'] ? '<br><small>dikonfirmasi di portal ' . h(date('d-m-Y H:i', strtotime($w['acknowledged_at']))) . '</small>' : '' ?></div></div>
</div></body></html>
<?php
    exit;
}

$me = require_login('admin');

// ---------- actions ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = post('action');
    if ($action === 'create') {
        $emp = employee(post('pegawai_id'));
        $level = post('level');
        $issued = post('issued');
        $until = post('valid_until');
        if (!$emp || !isset(warning_levels()[$level]) || !valid_date($issued) || !valid_date($until) || $until < $issued || trim(post('violation')) === '') {
            flash('Isi karyawan, jenis, tanggal, masa berlaku dan pelanggaran.', 'err');
            redirect('discipline.php?p=new&pegawai_id=' . (int)post('pegawai_id'));
        }
        q('INSERT INTO web_warning (pegawai_id, level, number, issued, valid_until, violation, description, issued_by, signer, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$emp['pegawai_id'], $level, warning_number($level, $issued), $issued, $until, mb_substr(trim(post('violation')), 0, 200), post('description'), actor(), mb_substr(post('signer'), 0, 100), date('Y-m-d H:i:s')]);
        $id = (int)db()->lastInsertId();
        audit('warning_create', "{$emp['pegawai_nama']} $level $issued");
        flash(warning_levels()[$level] . " untuk {$emp['pegawai_nama']} disimpan.");
        redirect('discipline.php?p=view&id=' . $id);
    } elseif ($action === 'delete') {
        $w = q('SELECT w.*, p.pegawai_nama FROM web_warning w JOIN pegawai p ON p.pegawai_id = w.pegawai_id WHERE w.id = ?', [(int)post('id')])->fetch();
        if ($w) {
            q('DELETE FROM web_warning WHERE id = ?', [$w['id']]);
            audit('warning_delete', "{$w['pegawai_nama']} {$w['level']} {$w['number']}");
            flash('Peringatan dihapus.');
        }
        redirect('discipline.php');
    }
}

$csrf = csrf_field();
$p = get('p', 'list');

if ($p === 'new'):
    $people = employees(true);
    $next = [];
    foreach ($people as $e) $next[$e['pegawai_id']] = next_warning_level(array_column(active_warnings($e['pegawai_id'], $today), 'level'));
    $sel = (int)get('pegawai_id');
    $signer = isset($CONFIG['tax_signer']) ? $CONFIG['tax_signer'] : '';
    hris_header('Peringatan baru', 'employee', 'discipline.php', '<a href="discipline.php">Surat peringatan</a>'); ?>
  <form method="post" class="section"><?= $csrf ?><input type="hidden" name="action" value="create">
    <div class="row2">
      <label class="field"><span>Karyawan <i>*</i></span><select name="pegawai_id" required onchange="var o=this.selectedOptions[0];if(o.dataset.next)this.form.level.value=o.dataset.next;document.getElementById('hint').textContent=o.dataset.hint||''">
        <option value="">Pilih karyawan…</option>
        <?php foreach ($people as $e): $act = active_warnings($e['pegawai_id'], $today); ?>
          <option value="<?= (int)$e['pegawai_id'] ?>" data-next="<?= $next[$e['pegawai_id']] ?>" data-hint="<?= h($act ? 'Masih berlaku: ' . implode(', ', array_map(function ($w) { return warning_levels()[$w['level']] . ' s/d ' . date('d-m-Y', strtotime($w['valid_until'])); }, $act)) : 'Tidak ada peringatan yang masih berlaku.') ?>"<?= (int)$e['pegawai_id'] === $sel ? ' selected' : '' ?>><?= h($e['pegawai_nama']) ?> (<?= h($e['pegawai_pin']) ?>)</option>
        <?php endforeach; ?></select><small id="hint"></small></label>
      <label class="field"><span>Jenis</span><select name="level"><?php foreach (warning_levels() as $k => $l): ?><option value="<?= $k ?>"<?= $sel && $next[$sel] === $k || !$sel && $k === 'sp1' ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select>
        <small>Disarankan otomatis: satu tingkat di atas SP yang masih berlaku.</small></label>
      <label class="field"><span>Tanggal</span><input type="date" name="issued" required value="<?= $today ?>"
        onchange="var d=new Date(this.value+'T00:00:00');if(!isNaN(d)){d.setMonth(d.getMonth()+6);d.setDate(d.getDate()-1);this.form.valid_until.value=d.toISOString().slice(0,10)}"></label>
      <label class="field"><span>Berlaku sampai</span><input type="date" name="valid_until" required value="<?= date('Y-m-d', strtotime($today . ' +6 months -1 day')) ?>"><small>PP 35/2021: paling lama 6 bulan, kecuali diatur lain di PK / peraturan perusahaan.</small></label>
    </div>
    <label class="field"><span>Pelanggaran <i>*</i></span><input type="text" name="violation" required maxlength="200" placeholder="mis. Terlambat masuk kerja 5 kali dalam sebulan"></label>
    <label class="field"><span>Uraian (opsional)</span><textarea name="description" rows="4" placeholder="Kronologi, tanggal kejadian, pasal peraturan perusahaan yang dilanggar"></textarea></label>
    <label class="field"><span>Ditandatangani oleh</span><input type="text" name="signer" maxlength="100" value="<?= h($signer) ?>"></label>
    <div class="formfoot"><a class="btn" href="discipline.php">Batal</a><button class="btn primary">Simpan</button></div></form>
  <?php if ($sel): ?><script>document.querySelector('select[name=pegawai_id]').dispatchEvent(new Event('change'))</script><?php endif; ?>
<?php
elseif ($p === 'view'):
    $w = q('SELECT w.*, p.pegawai_nama, p.pegawai_pin FROM web_warning w JOIN pegawai p ON p.pegawai_id = w.pegawai_id WHERE w.id = ?', [(int)get('id')])->fetch();
    if (!$w) redirect('discipline.php');
    hris_header(warning_levels()[$w['level']] . ' · ' . $w['pegawai_nama'], 'employee', 'discipline.php', '<a href="discipline.php">Surat peringatan</a>',
        '<a class="btn primary" target="_blank" href="discipline.php?print=' . (int)$w['id'] . '">Cetak surat</a>'); ?>
  <div class="section" style="max-width:none"><dl class="dl">
    <?php foreach (['Nomor' => $w['number'], 'Karyawan' => $w['pegawai_nama'] . ' (PIN ' . $w['pegawai_pin'] . ')', 'Tanggal' => fmt_date($w['issued']), 'Berlaku sampai' => fmt_date($w['valid_until']),
                    'Pelanggaran' => $w['violation'], 'Uraian' => $w['description'], 'Ditandatangani' => $w['signer'], 'Dicatat oleh' => $w['issued_by'] . ' · ' . date('d-m-Y H:i', strtotime($w['created_at'])),
                    'Diterima karyawan' => $w['acknowledged_at'] ? 'dikonfirmasi di portal ' . date('d-m-Y H:i', strtotime($w['acknowledged_at'])) : 'belum dikonfirmasi'] as $k => $v): ?>
      <dt><?= h($k) ?></dt><dd style="white-space:pre-line"><?= $v !== '' && $v !== null ? h($v) : '<span class="muted">—</span>' ?></dd><?php endforeach; ?></dl>
    <form method="post" style="margin-top:12px" onsubmit="return confirm('Hapus peringatan ini?')"><?= $csrf ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$w['id'] ?>">
      <button class="btn small ghost" style="color:var(--bad)">Hapus</button></form></div>
<?php
else:
    $state = get('s', 'active');
    $where = $state === 'active' ? 'w.valid_until >= ?' : ($state === 'expired' ? 'w.valid_until < ?' : '? IS NOT NULL');
    $rows = q("SELECT w.*, p.pegawai_nama, p.pegawai_pin FROM web_warning w JOIN pegawai p ON p.pegawai_id = w.pegawai_id WHERE $where ORDER BY w.issued DESC, w.id DESC", [$today])->fetchAll();
    $cnt = q('SELECT SUM(valid_until >= ?), SUM(valid_until < ?), COUNT(*) FROM web_warning', [$today, $today])->fetch(PDO::FETCH_NUM);
    hris_header('Surat peringatan', 'employee', 'discipline.php', 'Karyawan', '<a class="btn primary" href="discipline.php?p=new">+ Peringatan baru</a>'); ?>
  <div class="counters" style="grid-template-columns:repeat(3,minmax(0,1fr))">
    <?php foreach (['active' => ['Masih berlaku', 0], 'expired' => ['Sudah berakhir', 1], 'all' => ['Semua', 2]] as $k => $v): ?>
      <a class="counter<?= $state === $k ? ' on' : '' ?>" href="discipline.php?s=<?= $k ?>"><b><?= (int)$cnt[$v[1]] ?></b><span><?= $v[0] ?></span></a><?php endforeach; ?>
  </div>
  <div class="tablecard"><div class="tablescroll"><table>
    <thead><tr><th>Nama karyawan</th><th>Jenis</th><th>Nomor</th><th>Tanggal</th><th>Berlaku sampai</th><th>Pelanggaran</th><th>Diterima</th><th class="actions">Aksi</th></tr></thead><tbody>
    <?php foreach ($rows as $w): ?>
      <tr><td><a href="hr.php?m=employee&id=<?= (int)$w['pegawai_id'] ?>&tab=warnings"><b><?= h($w['pegawai_nama']) ?></b></a> <span class="muted"><?= h($w['pegawai_pin']) ?></span></td>
        <td><?= warning_pill($w, $today) ?></td><td class="muted"><?= h($w['number']) ?></td><td><?= h(date('d-m-Y', strtotime($w['issued']))) ?></td><td><?= h(date('d-m-Y', strtotime($w['valid_until']))) ?></td>
        <td><?= h($w['violation']) ?></td><td class="muted"><?= $w['acknowledged_at'] ? date('d-m-Y', strtotime($w['acknowledged_at'])) : 'belum' ?></td>
        <td class="actions"><a class="btn small" href="discipline.php?p=view&id=<?= (int)$w['id'] ?>">Lihat</a> <a class="btn small" target="_blank" href="discipline.php?print=<?= (int)$w['id'] ?>">Cetak</a></td></tr>
    <?php endforeach; ?>
    </tbody></table></div><?php if (!$rows): ?><div class="empty"><strong>Tidak ada peringatan</strong>Buat teguran lisan atau SP lewat "+ Peringatan baru".</div><?php endif; ?></div>
<?php
endif;
hris_footer();
