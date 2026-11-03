<?php
// HR → Perusahaan → Log aktivitas: who did what, with filters and CSV export. Admins only.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/portal.php';
require __DIR__ . '/inc/hris.php';
require __DIR__ . '/inc/activity.php';
$me = require_login('admin');

$today = date('Y-m-d');
$from = valid_date(get('from')) ? get('from') : date('Y-m-d', strtotime('-30 days'));
$to = valid_date(get('to')) ? get('to') : $today;
$f = ['from' => $from, 'to' => $to, 'user' => get('user'), 'type' => get('type'), 'q' => trim(get('q'))];
list($where, $params) = activity_where($f);
$per = 100;
$page = max(1, (int)get('page', 1));
$total = (int)q("SELECT COUNT(*) FROM web_log WHERE $where", $params)->fetchColumn();

if (get('csv') === '1') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="log_aktivitas_' . $from . '_' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Waktu', 'User', 'Jenis', 'Aktivitas', 'Keterangan']);
    foreach (q("SELECT * FROM web_log WHERE $where ORDER BY id DESC LIMIT 50000", $params) as $r) fputcsv($out, [$r['created_at'], $r['username'], activity_type($r['action']), $r['action'], $r['detail']]);
    audit('activity_export', "$from..$to");
    exit;
}

$rows = q("SELECT * FROM web_log WHERE $where ORDER BY id DESC LIMIT " . (($page - 1) * $per) . ", $per", $params)->fetchAll();
$users = q('SELECT DISTINCT username FROM web_log ORDER BY username')->fetchAll(PDO::FETCH_COLUMN);
$qs = function ($extra) use ($f) { return 'activity.php?' . http_build_query(array_merge($f, $extra)); };
$pages = max(1, (int)ceil($total / $per));

hris_header('Log aktivitas', 'company', 'activity.php', 'Perusahaan', '<a class="btn" href="' . h($qs(['csv' => 1])) . '">Ekspor CSV</a>'); ?>
  <form class="toolbar" method="get">
    <label class="muted">Dari <input type="date" name="from" value="<?= h($from) ?>"></label>
    <label class="muted">s/d <input type="date" name="to" value="<?= h($to) ?>"></label>
    <select name="user"><option value="">Semua user</option><?php foreach ($users as $u): ?><option value="<?= h($u) ?>"<?= $f['user'] === $u ? ' selected' : '' ?>><?= h($u) ?></option><?php endforeach; ?></select>
    <select name="type"><option value="">Semua jenis</option><?php foreach (array_merge(array_keys(activity_types()), ['Lainnya']) as $t): ?><option<?= $f['type'] === $t ? ' selected' : '' ?>><?= h($t) ?></option><?php endforeach; ?></select>
    <span class="search"><?= hris_icon('search') ?><input type="search" name="q" value="<?= h($f['q']) ?>" placeholder="Cari keterangan"></span>
    <button class="btn">Terapkan</button></form>
  <div class="tablecard"><div class="tablescroll"><table>
    <thead><tr><th>Waktu</th><th>User</th><th>Jenis</th><th>Aktivitas</th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?>
      <tr><td style="white-space:nowrap"><?= h(date('d-m-Y H:i:s', strtotime($r['created_at']))) ?></td><td><?= h($r['username']) ?></td>
        <td><span class="pill grey"><?= h(activity_type($r['action'])) ?></span></td>
        <td><b><?= h($r['action']) ?></b> <span class="muted"><?= h($r['detail']) ?></span></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <?php if (!$rows): ?><div class="empty"><strong>Tidak ada aktivitas</strong>Ubah tanggal atau filter.</div><?php endif; ?>
    <div class="pager"><span><?= number_format($total) ?> aktivitas · halaman <?= $page ?> / <?= $pages ?></span>
      <span><?php if ($page > 1): ?><a class="btn small" href="<?= h($qs(['page' => $page - 1])) ?>">‹ Sebelumnya</a> <?php endif; ?><?php if ($page < $pages): ?><a class="btn small" href="<?= h($qs(['page' => $page + 1])) ?>">Berikutnya ›</a><?php endif; ?></span></div></div>
<?php hris_footer();
