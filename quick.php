<?php
// Quick entry for work done away from the machines (dinas luar kota): pick employees and dates once,
// check the preview, save. Each day gets a check-in and check-out ("Tidak scan" 100/101, like Fingerspot)
// at the employee's scheduled times, or a leave entry such as Izin dinas.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_login();

$src = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$val = function ($k, $d = '') use ($src) { return isset($src[$k]) ? (is_array($src[$k]) ? $src[$k] : trim((string)$src[$k])) : $d; };

$picked = array_values(array_unique(array_map('intval', (array)$val('emp', []))));
$from = $val('from', date('Y-m-d'));
$to = $val('to', $from);
$mode = $val('mode', 'scan');                    // scan = check-in + check-out, noscan = one forgotten scan ("Tidak scan"), leave = one leave entry per day
$noscan = (int)$val('noscan', 100);              // which forgotten scan: 100 Masuk, 101 Pulang, 102-105 istirahat / lembur
if (!is_scan_type($noscan)) $noscan = 100;
$nsTime = $val('ns_time', '');
if ($mode === 'noscan') $to = $from;             // Tidak scan is for one specific day                   // empty = scheduled start (Masuk) / end (Pulang)
$noscanNames = [100 => 'Masuk', 101 => 'Pulang', 102 => 'Istirahat Mulai', 103 => 'Istirahat Selesai', 104 => 'Lembur Mulai', 105 => 'Lembur Selesai'];
$times = $val('times', 'schedule');              // schedule = each day's work hours, fixed = the times below
$tIn = $val('t_in', '08:00');
$tOut = $val('t_out', '16:00');
$jenisLeave = (int)$val('jenis', 70);            // 70 = Izin dinas (Izin keperluan kantor)
$kat = $val('kat', '');
$note = $val('note', '');
$skipOff = $val('skip_off', $_SERVER['REQUEST_METHOD'] === 'POST' || isset($src['preview']) ? '' : '1') === '1';
$approved = $val('approved', isset($src['preview']) || $_SERVER['REQUEST_METHOD'] === 'POST' ? '' : '1') === '1';

$leaveTypes = array_filter(leave_types(), function ($k) { return !is_scan_type($k); }, ARRAY_FILTER_USE_KEY);
$error = '';
if (isset($src['preview']) || $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$picked) $error = 'Pilih minimal satu pegawai.';
    elseif (!valid_date($from) || !valid_date($to) || $to < $from) $error = 'Isi rentang tanggal yang benar.';
    elseif ((strtotime($to) - strtotime($from)) / 86400 > 62) $error = 'Rentang maksimal 2 bulan.';
    elseif ($to > date('Y-m-d') && $mode !== 'leave') $error = 'Scan hanya bisa ditambah untuk hari ini atau sebelumnya.';
    elseif ($mode === 'scan' && $times === 'fixed' && (!valid_time($tIn) || ($mode === 'scan' && !valid_time($tOut)))) $error = 'Isi jam dengan format JJ:MM.';
    elseif ($mode === 'noscan' && $nsTime !== '' && !valid_time($nsTime)) $error = 'Isi jam dengan format JJ:MM.';
    elseif ($mode === 'noscan' && $noscan > 101 && $nsTime === '') $error = 'Isi jam untuk ' . $noscanNames[$noscan] . '.';
    elseif ($mode === 'leave' && !isset($leaveTypes[$jenisLeave])) $error = 'Pilih jenis izin.';
}

/** What would be created: one row per employee per day, with what is skipped and why. */
function quick_plan($picked, $from, $to, $mode, $times, $tIn, $tOut, $jenisLeave, $skipOff, $noscan = 100, $nsTime = '')
{
    $existingLeave = [];
    $in = implode(',', array_fill(0, count($picked), '?'));
    foreach (q("SELECT pegawai_id, izin_tgl, izin_jenis_id FROM izin WHERE izin_tgl BETWEEN ? AND ? AND pegawai_id IN ($in)", array_merge([$from, $to], $picked)) as $r) {
        $existingLeave[$r['pegawai_id']][$r['izin_tgl']][(int)$r['izin_jenis_id']] = true;
    }
    $plan = [];
    foreach (array_filter(daily_attendance($from, $to), function ($r) use ($picked) { return in_array((int)$r['pegawai_id'], $picked, true); }) as $r) {
        list($jk) = work_hours_for($r['pegawai_id'], $r['date']);
        $row = ['r' => $r, 'in' => null, 'out' => null, 'leave' => false, 'skip' => ''];
        if ($r['off'] && $skipOff) { $row['skip'] = $r['off'] === 'Libur' ? 'Libur' : 'Libur: ' . $r['off']; }
        elseif ($mode === 'leave') {
            if (isset($existingLeave[$r['pegawai_id']][$r['date']][$jenisLeave])) $row['skip'] = 'Sudah diinput';
            elseif ($r['first'] && $r['last']) $row['skip'] = 'Sudah scan masuk dan pulang';
            else $row['leave'] = true;
        } else {
            // A new Masuk is added when there is no check-in yet or it is earlier than the stored one;
            // a new Pulang when there is no check-out yet or it is later. The day then uses the new time.
            $curIn = $r['first'] && ($r['last'] || $r['missing'] != 100) ? $r['first'] : null;
            $curOut = $r['last'] ?: null;
            $want = [];
            if ($mode === 'noscan') {
                $time = $nsTime !== '' ? substr($nsTime, 0, 5) : ($jk ? min_to_hm($noscan === 101 ? $jk['end'] : $jk['start']) : null);
                if ($time === null) $row['skip'] = 'Tidak ada jam kerja hari ini: isi jam';
                elseif ($noscan > 101 && isset($existingLeave[$r['pegawai_id']][$r['date']][$noscan])) $row['skip'] = 'Sudah diinput';
                else $want[$noscan] = $time;
                $row['jenis'] = $noscan;
            } else {
                $want[100] = $times === 'schedule' && $jk ? min_to_hm($jk['start']) : substr($tIn, 0, 5);
                $want[101] = $times === 'schedule' && $jk ? min_to_hm($jk['end']) : substr($tOut, 0, 5);
            }
            $why = [];
            foreach ($want as $jenis => $time) {
                if ($jenis === 100) {
                    if ($curIn !== null && $time >= $curIn) { $why[] = "Sudah scan masuk $curIn"; continue; }
                    if ($curOut !== null && $time >= $curOut) { $why[] = "Masuk harus sebelum pulang $curOut"; continue; }
                    if ($curIn !== null) $row['was_in'] = $curIn;
                    $row['in'] = $time;
                } elseif ($jenis === 101) {
                    if ($curOut !== null && $time <= $curOut) { $why[] = "Sudah scan pulang $curOut"; continue; }
                    if ($curIn !== null && $time <= $curIn) { $why[] = "Pulang harus setelah masuk $curIn"; continue; }
                    if ($curOut !== null) $row['was_out'] = $curOut;
                    $row['out'] = $time;
                } else {
                    $row['in'] = $time;
                }
            }
            if (!$row['skip'] && !$row['in'] && !$row['out']) $row['skip'] = implode('; ', $why);
        }
        $plan[] = $row;
    }
    usort($plan, function ($a, $b) { return [$a['r']['name'], $a['r']['date']] <=> [$b['r']['name'], $b['r']['date']]; });
    return $plan;
}

$plan = (!$error && (isset($src['preview']) || $_SERVER['REQUEST_METHOD'] === 'POST'))
    ? quick_plan($picked, $from, $to, $mode, $times, $tIn, $tOut, $jenisLeave, $skipOff, $noscan, $nsTime) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $plan !== null) {
    check_csrf();
    $katId = $kat !== '' ? (int)$kat : 0;
    $made = 0;
    $errors = [];
    foreach ($plan as $p) {
        $r = $p['r'];
        if ($p['leave']) {
            q('INSERT INTO izin (pegawai_id, izin_urutan, izin_tgl_pengajuan, izin_tgl, izin_jenis_id, izin_catatan, izin_status,
                                 cuti_n_id, izin_ket_lain, izin_noscan_time, kat_izin_id, ket_status)
               VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, NULL, ?, NULL)', [
                $r['pegawai_id'], next_izin_seq($r['pegawai_id'], $r['date'], $jenisLeave), date('Y-m-d'), $r['date'], $jenisLeave, $note,
                $approved ? 1 : 0, 'Web: ' . current_user()['username'], $katId]);
            $made++;
        }
        $slots = isset($p['jenis']) ? [$p['jenis'] => $p['in'] ?: $p['out']] : [100 => $p['in'], 101 => $p['out']];
        foreach ($slots as $jenis => $time) {
            if (!$time) continue;
            $err = add_manual_scan($r['pegawai_id'], $r['date'], $time, $jenis, $katId ?: scan_types()[$jenis][1], $note, $approved);
            if ($err) $errors[] = $r['name'] . ' ' . $r['date'] . ': ' . $err; else $made++;
        }
    }
    audit('quick_entry', sprintf('%s %s..%s %d entries for %d employee(s)', $mode, $from, $to, $made, count($picked)));
    flash("Tersimpan $made data untuk " . count($picked) . ' pegawai.' . ($errors ? ' Dilewati: ' . implode('; ', array_slice($errors, 0, 5)) : ''), $errors ? 'err' : 'ok');
    redirect('quick.php?' . http_build_query(['from' => $from, 'to' => $to]));
}

$depts = lookup('pembagian2');
$emps = q('SELECT pegawai_id, pegawai_pin, pegawai_nama, pembagian2_id FROM pegawai WHERE pegawai_status = 1 ORDER BY pegawai_nama')->fetchAll();

page_header('Input Cepat', 'quick.php');
?>
<div class="page-head"><h1>Input Cepat: Tidak scan / Dinas luar</h1></div>
<?php if ($error): ?><div class="flash err"><?= h($error) ?></div><?php endif; ?>

<form method="get" id="qf">
<div class="row">
  <div class="card" style="flex:1 1 300px">
    <h2>1. Pilih Pegawai</h2>
    <input type="search" placeholder="Cari nama atau PIN…" oninput="filterEmp(this.value)" style="width:100%;margin-bottom:8px">
    <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:8px">
      <button type="button" class="btn small" onclick="tick(true)">Semua</button>
      <button type="button" class="btn small" onclick="tick(false)">Kosongkan</button>
      <?php foreach ($depts as $id => $name): ?><button type="button" class="btn small" onclick="tickDept(<?= (int)$id ?>)"><?= h($name) ?></button><?php endforeach; ?>
    </div>
    <div id="emps" style="border:1px solid var(--line);border-radius:6px;padding:6px 8px">
      <?php foreach ($emps as $e): ?>
        <label class="check" data-name="<?= h(strtolower($e['pegawai_nama'] . ' ' . $e['pegawai_pin'])) ?>" data-dept="<?= (int)$e['pembagian2_id'] ?>">
          <input type="checkbox" name="emp[]" value="<?= (int)$e['pegawai_id'] ?>" <?= in_array((int)$e['pegawai_id'], $picked, true) ? 'checked' : '' ?>>
          <?= h($e['pegawai_nama']) ?> <span class="muted"><?= h($e['pegawai_pin']) ?></span></label>
      <?php endforeach; ?>
    </div>
    <p class="muted" id="count"></p>
  </div>

  <div class="card" style="flex:1 1 360px">
    <h2>2. Tanggal dan Jenis</h2>
    <div class="stack" style="display:flex;flex-direction:column;gap:12px">
      <div class="grid2">
        <label>Tanggal <input type="date" name="from" required value="<?= h($from) ?>"></label>
        <label id="toDate">s/d <input type="date" name="to" required value="<?= h($to) ?>"></label>
      </div>
      <label class="check"><input type="radio" name="mode" value="scan" <?= $mode === 'scan' ? 'checked' : '' ?> onchange="sync()"> <b>Bekerja</b>: masuk dan pulang (dihitung hadir) / dinas luar</label>
      <div id="scanOpts" style="padding-left:24px;display:flex;flex-direction:column;gap:8px"><!-- times: used by Worked and Forgot to scan -->
        <label class="check"><input type="radio" name="times" value="schedule" <?= $times === 'schedule' ? 'checked' : '' ?> onchange="sync()"> Sesuai jam kerja tiap hari (mis. 08:00–16:00, Sabtu 10:00–14:00)</label>
        <label class="check"><input type="radio" name="times" value="fixed" <?= $times === 'fixed' ? 'checked' : '' ?> onchange="sync()"> Jam tetap:</label>
        <div class="grid2" id="fixedTimes"><label>Masuk <input type="time" name="t_in" value="<?= h(substr($tIn, 0, 5)) ?>"></label><label>Pulang <input type="time" name="t_out" value="<?= h(substr($tOut, 0, 5)) ?>"></label></div>
        <span class="muted">Scan yang sudah ada tidak diubah; hanya yang kurang yang ditambah.</span>
      </div>
      <label class="check"><input type="radio" name="mode" value="noscan" <?= $mode === 'noscan' ? 'checked' : '' ?> onchange="sync()"> <b>Tidak scan</b> (lupa scan):</label>
      <div id="noscanOpts" style="padding-left:24px;display:flex;flex-direction:column;gap:6px">
        <div style="display:flex;gap:8px"><select name="noscan"><?= options($noscanNames, $noscan) ?></select>
          <input type="time" name="ns_time" value="<?= h($nsTime) ?>" title="Jam"></div>
        <span class="muted">Kosongkan jam untuk memakai jam kerja (Masuk) atau jam pulang (Pulang).</span>
      </div>
      <label class="check"><input type="radio" name="mode" value="leave" <?= $mode === 'leave' ? 'checked' : '' ?> onchange="sync()"> <b>Izin / cuti</b> setiap hari:</label>
      <div id="leaveOpts" style="padding-left:24px"><select name="jenis"><?= options($leaveTypes, $jenisLeave) ?></select></div>
      <div class="grid2">
        <label>Kategori <select name="kat"><?= options(leave_categories(), $kat, 'Default') ?></select></label>
        <label>Catatan <input name="note" maxlength="255" value="<?= h($note) ?>" placeholder="mis. Dinas luar kota Surabaya"></label>
      </div>
      <label class="check"><input type="checkbox" name="skip_off" value="1" <?= $skipOff ? 'checked' : '' ?>> Lewati hari libur</label>
      <label class="check"><input type="checkbox" name="approved" value="1" <?= $approved ? 'checked' : '' ?>> Diizinkan</label>
      <div><button class="btn primary" name="preview" value="1">Pratinjau</button></div>
    </div>
  </div>
</div>
</form>

<?php if ($plan !== null):
    $nIn = count(array_filter($plan, function ($p) { return $p['in']; }));
    $nOut = count(array_filter($plan, function ($p) { return $p['out']; }));
    $nLeave = count(array_filter($plan, function ($p) { return $p['leave']; }));
    $total = $nIn + $nOut + $nLeave; ?>
<div class="card" id="preview">
  <div class="page-head">
    <h2 style="margin:0">3. Periksa dan Simpan</h2>
    <form method="post" class="btn-form">
      <?= csrf_field() ?>
      <?php foreach ($picked as $p): ?><input type="hidden" name="emp[]" value="<?= (int)$p ?>"><?php endforeach; ?>
      <?php foreach (['from' => $from, 'to' => $to, 'mode' => $mode, 'noscan' => $noscan, 'ns_time' => $nsTime, 'times' => $times, 't_in' => $tIn, 't_out' => $tOut, 'jenis' => $jenisLeave, 'kat' => $kat, 'note' => $note,
                      'skip_off' => $skipOff ? '1' : '0', 'approved' => $approved ? '1' : '0'] as $k => $v): ?>
        <input type="hidden" name="<?= $k ?>" value="<?= h($v) ?>">
      <?php endforeach; ?>
      <button class="btn primary" <?= $total ? '' : 'disabled' ?>>Simpan <?= $total ?> data</button>
    </form>
  </div>
  <p class="muted"><?= $mode === 'scan' ? "$nIn masuk dan $nOut pulang" : ($mode === 'noscan' ? ($nIn + $nOut) . ' tidak scan' : "$nLeave hari izin") ?> untuk <?= count($picked) ?> pegawai, <?= h(fmt_date($from)) ?> – <?= h(fmt_date($to)) ?>.</p>
  <div class="table-wrap"><table>
    <tr><th>Pegawai</th><th>Tanggal</th><th>Scan saat ini</th><th>Akan ditambah</th></tr>
    <?php foreach ($plan as $p): $r = $p['r']; ?>
      <tr style="<?= $p['skip'] ? 'opacity:.55' : '' ?>">
        <td><?= h($r['name']) ?></td>
        <td><?= h(fmt_date($r['date'])) ?></td>
        <td><?= $r['scans'] ? h(trim($r['first'] . ' – ' . $r['last'], ' –')) : '<span class="muted">tidak ada</span>' ?></td>
        <td><?php if ($p['skip']): ?><span class="muted"><?= h($p['skip']) ?></span>
          <?php elseif ($p['leave']): ?><span class="badge b-leave"><?= h($leaveTypes[$jenisLeave]) ?></span>
          <?php else:
              $was = function ($k) use ($p) { return isset($p[$k]) ? ' <span class="muted">(ganti ' . h($p[$k]) . ')</span>' : ''; };
              if (isset($p['jenis'])): ?><span class="badge b-ok"><?= h($noscanNames[$p['jenis']]) ?> <?= h($p['in'] ?: $p['out']) ?></span><?= $was('was_in') . $was('was_out') ?>
              <?php else: ?><?= $p['in'] ? '<span class="badge b-ok">Masuk ' . h($p['in']) . '</span>' . $was('was_in') . ' ' : '' ?><?= $p['out'] ? '<span class="badge b-ok">Pulang ' . h($p['out']) . '</span>' . $was('was_out') : '' ?><?php endif; ?>
          <?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
  </table></div>
</div>
<?php endif; ?>

<script>
function rows() { return document.querySelectorAll('#emps label'); }
function count() { document.getElementById('count').textContent = document.querySelectorAll('#emps input:checked').length + ' selected'; }
function filterEmp(q) { q = q.toLowerCase(); rows().forEach(function (l) { l.style.display = l.dataset.name.indexOf(q) >= 0 ? '' : 'tidak ada'; }); }
function tick(on) { rows().forEach(function (l) { if (l.style.display !== 'tidak ada') l.querySelector('input').checked = on; }); count(); }
function tickDept(d) { rows().forEach(function (l) { if (+l.dataset.dept === d) l.querySelector('input').checked = true; }); count(); }
function sync() {
  var f = document.getElementById('qf'), m = f.mode.value, scan = m !== 'leave';
  // Tidak scan: one date only (the "s/d" date follows it)
  document.getElementById('toDate').style.visibility = m === 'noscan' ? 'hidden' : '';
  if (m === 'noscan') f.to.value = f.from.value;
  document.getElementById('scanOpts').style.display = m === 'scan' ? 'flex' : 'tidak ada';
  document.getElementById('noscanOpts').style.display = m === 'noscan' ? 'flex' : 'tidak ada';
  document.getElementById('leaveOpts').style.display = scan ? 'tidak ada' : '';
  document.getElementById('fixedTimes').style.display = m === 'scan' && f.times.value === 'fixed' ? '' : 'tidak ada';
}
document.getElementById('emps').addEventListener('change', count);
document.querySelector('#qf [name=from]').addEventListener('change', function () { if (document.getElementById('qf').mode.value === 'noscan') sync(); });
count(); sync();
<?php if ($plan !== null): ?>document.getElementById('preview').scrollIntoView();<?php endif; ?>
</script>
<?php page_footer();
