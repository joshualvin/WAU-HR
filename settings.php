<?php
// HR app settings, laid out like Mekari's settings page: tabs along the top, the pages of the chosen tab listed below.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require __DIR__ . '/inc/portal.php';
require __DIR__ . '/inc/hris.php';
require_login('admin');

$tabs = [
    'company' => ['Perusahaan', [
        ['master.php?t=pembagian1', 'Jabatan', 'Daftar jabatan karyawan'],
        ['master.php?t=pembagian2', 'Departemen', 'Departemen / divisi'],
        ['master.php?t=pembagian3', 'Kantor', 'Kantor dan cabang'],
        ['company.php?p=org', 'Struktur organisasi', 'Atasan dan bawahan'],
        ['master.php?t=cuti_normatif', 'Cuti normatif', 'Jenis cuti normatif dan hak harinya'],
    ]],
    'time' => ['Waktu', [
        ['workhours.php', 'Jam kerja', 'Jam masuk, pulang, toleransi terlambat / pulang awal, rentang scan'],
        ['schedules.php', 'Jadwal kerja', 'Jam kerja tiap hari dan pegawai yang memakai'],
        ['holidays.php', 'Hari libur', 'Hari libur dan cuti bersama'],
        ['master.php?t=kategori_izin', 'Kategori izin', 'Jenis izin yang bisa diajukan'],
    ]],
    'payroll' => ['Penggajian', [
        ['payroll.php?p=settings', 'Pengaturan gaji', 'Tarif BPJS, UMR/UMK, lembur, potongan, akun jurnal'],
        ['payroll.php?p=components', 'Komponen gaji', 'Tunjangan dan potongan tetap'],
        ['payalloc.php', 'Alokasi biaya gaji', 'Pembagian biaya gaji ke departemen / akun'],
        ['payroll.php?p=setup', 'Data gaji karyawan', 'Gaji pokok, PTKP, NPWP, BPJS tiap karyawan'],
    ]],
    'users' => ['Pengguna', [
        ['users.php', 'User aplikasi', 'Akun admin / HR dan log perubahan'],
        ['activity.php', 'Log aktivitas', 'Siapa mengubah apa, dan riwayat login'],
    ]],
    'fingerspot' => ['Fingerspot', [
        ['cloud.php', 'Fingerspot.io (unduh scan)', 'Unduh scan dari cloud: Cloud ID mesin, jadwal unduh otomatis'],
        ['sync.php', 'Fingerspot Personnel (sinkron)', 'Sinkron data karyawan dengan Fingerspot Personnel'],
        ['ftm.php', 'FTM kantor pusat', 'Tarik scan dari FTM di kantor pusat'],
        ['devices.php', 'Daftar mesin', 'Mesin absensi, scan terakhir, arsipkan mesin yang tidak dipakai'],
        ['gps.php', 'Scan GPS (FiO)', 'Absen lewat ponsel (Fingerspot Online) dan titik lokasinya'],
    ]],
];
$fs = settings_ui() === 'fs';
$tab = isset($tabs[get('tab')]) ? get('tab') : 'company';

settings_header('Pengaturan', 'settings.php');
?>
<?php if ($fs) settings_tabstrip($tab); ?>
<div class="card setlist">
  <h2><?= h($tabs[$tab][0]) ?> <span class="side"><a href="settings.php?tab=<?= h($tab) ?>&amp;ui=<?= $fs ? 'hr' : 'fs' ?>"><?= $fs ? 'Tampilan HR' : 'Tampilan Fingerspot' ?></a></span></h2>
  <?php foreach ($tabs[$tab][1] as $i): ?>
    <a class="setrow" href="<?= h(ui_url($i[0], $fs ? 'fs' : 'hr')) ?>"><span><b><?= h($i[1]) ?></b><small><?= h($i[2]) ?></small></span><span class="chev" aria-hidden="true">›</span></a>
  <?php endforeach; ?>
</div>
<?php settings_footer();
