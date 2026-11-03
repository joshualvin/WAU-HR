<?php
// HR → Waktu → Kalender: a month grid with holidays, cuti bersama, company events, birthdays, work anniversaries and approved leave.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/portal.php';
require __DIR__ . '/inc/hris.php';
require __DIR__ . '/inc/people.php';
require __DIR__ . '/inc/company.php';
$me = require_login();
ensure_web_tables();
ensure_people_tables();
ensure_company_tables();

$today = date('Y-m-d');
$first = calendar_month(get('ym'), $today);
$last = date('Y-m-t', strtotime($first));
$prev = date('Y-m', strtotime($first . ' -1 month'));
$next = date('Y-m', strtotime($first . ' +1 month'));
$months = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
$title = $months[(int)date('n', strtotime($first))] . ' ' . date('Y', strtotime($first));

$byDay = [];
foreach (company_calendar($first, (int)date('t', strtotime($first)) - 1) as $c) if ($c[0] >= $first && $c[0] <= $last) $byDay[$c[0]][] = $c;
$leave = q('SELECT izin_tgl, COUNT(*) n FROM izin WHERE izin_status = 1 AND izin_jenis_id NOT BETWEEN 100 AND 105 AND izin_tgl BETWEEN ? AND ? GROUP BY izin_tgl', [$first, $last])->fetchAll(PDO::FETCH_KEY_PAIR);
$kinds = ['holiday' => ['Libur', 'bad'], 'leave' => ['Cuti bersama', 'warn'], 'event' => ['Acara', 'ok'], 'birthday' => ['Ulang tahun', 'grey'], 'anniversary' => ['Masa kerja', 'grey']];

hris_header('Kalender', 'time', 'calendar.php', 'Waktu', '<a class="btn" href="calendar.php">Bulan ini</a>'); ?>
  <style>
    .calnav { display: flex; align-items: center; gap: 12px; margin-bottom: 12px; flex-wrap: wrap; }
    .calnav h2 { margin: 0; min-width: 170px; text-align: center; }
    .cal { width: 100%; border-collapse: collapse; table-layout: fixed; background: var(--bg); border: 1px solid var(--line); }
    .cal th { padding: 8px; font-size: 12px; color: var(--muted); text-align: left; border-bottom: 1px solid var(--line); background: var(--canvas); }
    .cal td { vertical-align: top; height: 104px; padding: 6px; border: 1px solid var(--line); overflow: hidden; }
    .cal td.out { background: var(--canvas); color: var(--faint); }
    .cal td.today { box-shadow: inset 0 0 0 2px var(--primary); }
    .cal .dn { font-size: 13px; font-weight: 600; }
    .cal .sun .dn { color: var(--bad); }
    .cal .ev { display: block; margin-top: 3px; padding: 1px 6px; border-radius: 4px; font-size: 11px; line-height: 1.5; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .cal .ev.bad { background: #fde8e6; color: #a1251b; } .cal .ev.warn { background: #fdf0d5; color: #8a5a00; } .cal .ev.ok { background: #dff3e4; color: #1d6b34; } .cal .ev.grey { background: var(--canvas); color: var(--muted); }
    .cal .ev.lv { background: var(--primary-soft); color: var(--primary); }
    .legend { display: flex; gap: 14px; flex-wrap: wrap; margin-top: 12px; font-size: 13px; }
    .legend label { display: inline-flex; gap: 6px; align-items: center; }
    @media (max-width: 860px) { .calwrap { overflow-x: auto; } .cal { min-width: 640px; } }
  </style>
  <div class="calnav">
    <a class="btn" href="calendar.php?ym=<?= h($prev) ?>">‹</a><h2><?= h($title) ?></h2><a class="btn" href="calendar.php?ym=<?= h($next) ?>">›</a>
  </div>
  <div class="calwrap"><table class="cal">
    <thead><tr><?php foreach (['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'] as $d): ?><th><?= $d ?></th><?php endforeach; ?></tr></thead><tbody>
    <?php foreach (calendar_weeks($first) as $week): ?><tr>
      <?php foreach ($week as $i => $d): $in = $d >= $first && $d <= $last; ?>
        <td class="<?= $in ? '' : 'out' ?><?= $d === $today ? ' today' : '' ?><?= $i === 6 ? ' sun' : '' ?>"><span class="dn"><?= (int)date('j', strtotime($d)) ?></span>
          <?php if ($in): foreach (isset($byDay[$d]) ? $byDay[$d] : [] as $c): $k = $kinds[$c[1]]; ?><span class="ev <?= $k[1] ?>" data-kind="<?= h($c[1]) ?>" title="<?= h($k[0] . ': ' . $c[2]) ?>"><?= h($c[2]) ?></span><?php endforeach;
            if (!empty($leave[$d])): ?><a class="ev lv" data-kind="leave_n" href="hr.php?m=timeoff" title="Cuti / izin yang disetujui"><?= (int)$leave[$d] ?> cuti / izin</a><?php endif; endif; ?></td>
      <?php endforeach; ?></tr><?php endforeach; ?>
    </tbody></table></div>
  <div class="legend">
    <?php foreach ($kinds + ['leave_n' => ['Cuti / izin disetujui', 'lv']] as $k => $l): ?><label><input type="checkbox" checked data-toggle="<?= h($k) ?>"><span class="ev <?= $l[1] ?>" style="margin:0"><?= h($l[0]) ?></span></label><?php endforeach; ?>
  </div>
  <script>document.querySelectorAll('[data-toggle]').forEach(function (c) { c.addEventListener('change', function () {
    document.querySelectorAll('.cal [data-kind="' + c.getAttribute('data-toggle') + '"]').forEach(function (e) { e.style.display = c.checked ? '' : 'none'; }); }); });</script>
<?php hris_footer();
