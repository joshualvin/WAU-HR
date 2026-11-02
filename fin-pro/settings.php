<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_login('admin');

$tiles = [
    ['workhours.php', 'Jam Kerja', 'Jam masuk, pulang, toleransi terlambat / pulang awal, rentang scan'],
    ['schedules.php', 'Jadwal Kerja', 'Jam kerja tiap hari dan pegawai yang memakai'],
    ['holidays.php', 'Hari Libur', 'Hari libur dan cuti bersama'],
    ['master.php?t=pembagian1', 'Jabatan', 'Jabatan'],
    ['master.php?t=pembagian2', 'Departemen', 'Departemen'],
    ['master.php?t=pembagian3', 'Kantor', 'Kantor'],
    ['master.php?t=kategori_izin', 'Kategori Izin', 'Kategori izin'],
    ['master.php?t=cuti_normatif', 'Cuti Normatif', 'Cuti normatif'],
    ['users.php', 'User', 'User aplikasi dan log perubahan'],
];
page_header('Pengaturan', 'settings.php');
?>
<h1>Pengaturan</h1>
<div class="stats">
  <?php foreach ($tiles as $t): ?>
    <a class="stat" href="<?= h($t[0]) ?>" style="text-decoration:none;color:inherit">
      <div style="font-weight:700;font-size:16px;color:var(--accent)"><?= h($t[1]) ?></div>
      <div class="l"><?= h($t[2]) ?></div>
    </a>
  <?php endforeach; ?>
</div>
<?php page_footer();
