<?php
// HR → Perusahaan → Notifikasi: what needs attention today (approvals, ending contracts and documents, silent machines, scheduled transfers,
// birthdays and work anniversaries). Computed live; there is nothing to mark as read.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/portal.php';
require __DIR__ . '/inc/hris.php';
require __DIR__ . '/inc/people.php';
require __DIR__ . '/inc/employment.php';
require __DIR__ . '/inc/transfer.php';
require __DIR__ . '/inc/notify.php';
$me = require_login();
ensure_web_tables();
ensure_people_tables();
try { ensure_transfer_tables(); } catch (Exception $e) {}

$today = date('Y-m-d');
$items = notify_build(notify_collect($today), $today);
$levels = notify_levels();
$count = array_fill_keys(array_keys($levels), 0);
foreach ($items as $n) $count[$n['level']]++;
$only = isset($levels[get('l')]) ? get('l') : '';
$shown = $only ? array_values(array_filter($items, function ($n) use ($only) { return $n['level'] === $only; })) : $items;

hris_header('Notifikasi', 'company', 'notifikasi.php', 'Perusahaan'); ?>
  <div class="counters" style="grid-template-columns:repeat(4,minmax(0,1fr))">
    <a class="counter<?= $only === '' ? ' on' : '' ?>" href="notifikasi.php"><b><?= count($items) ?></b><span>Semua</span></a>
    <?php foreach ($levels as $k => $l): ?><a class="counter<?= $only === $k ? ' on' : '' ?>" href="notifikasi.php?l=<?= $k ?>"><b><?= $count[$k] ?></b><span><?= h($l) ?></span></a><?php endforeach; ?>
  </div>
  <div class="tablecard"><div class="list" style="padding:4px 18px">
    <?php foreach ($shown as $n): ?>
      <div class="li"><span class="pill <?= ['bad' => 'bad', 'warn' => 'warn', 'info' => 'grey'][$n['level']] ?>" style="min-width:96px;text-align:center"><?= h($n['group']) ?></span>
        <div class="meta"><a href="<?= h($n['link']) ?>"><b><?= h($n['title']) ?></b></a><small><?= h($n['detail']) ?></small></div></div>
    <?php endforeach; ?>
    <?php if (!$shown): ?><div class="empty"><strong>Tidak ada notifikasi</strong>Tidak ada yang perlu perhatian HR saat ini.</div><?php endif; ?>
  </div></div>
  <p class="muted">Daftar ini dihitung ulang setiap kali halaman dibuka. Kontrak dan dokumen yang berakhir dalam 30 hari, mesin yang tidak mengirim scan 3 hari atau lebih, mutasi dalam 14 hari, ulang tahun dan masa kerja dalam 7 hari.</p>
<?php hris_footer();
