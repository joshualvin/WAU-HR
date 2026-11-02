# Attendance web app (Fingerspot `fin_pro`)

Plain PHP + MySQL that works directly on the Fingerspot tables (`att_log`, `pegawai`, `izin`, ...). It needs PHP 7.4+ and works on MySQL 5.1+.

Looks and works like the desktop attendance program: the same top menu buttons, a side menu per section, gridded tables, Indonesian labels and a status bar (user, DB, server, clock). Optional: add `'company' => 'Nama PT'` to config.php to show it in the title bar.

| Menu | What's there |
|---|---|
| Dashboard | Who is present, late, has one scan only, no scan or is on leave on a given day; latest scans |
| Pegawai | List, add and edit employees |
| Pengecualian | **Quick entry (dinas luar)**: tick employees, pick dates, preview, save once — adds check-in and check-out at each day's scheduled times, a Tidak scan (Masuk, Pulang, Istirahat Mulai/Selesai, Lembur Mulai/Selesai) at a time you choose, or a leave entry per day; leave / permission (add for a date range, approve, delete), leave quota (jatah cuti), missing scans, add a check-in / check-out by hand |
| Mesin | Fingerprint machines and when each last sent scans; **Download Fingerspot.io** (official developer API, 2 days per call, up to 60 days back); the scan log |
| Laporan | Data Scanlog, Kartu Scanlog, Rincian Harian, Rekap Periode, Cuti Normatif, Cuti Pribadi, Datang Terlambat, Pulang Awal, Scan Kerja 1 x, Lembur, Scan Di Luar Jadwal, Kartu Shift, Rincian Tahunan; Rekap or Detail with a minimum-minutes filter; CSV export and print |
| Pengaturan | Work hours (jam kerja), schedules (jadwal kerja) and who follows them, holidays, positions / departments / locations, leave reasons, statutory leave, users |

Late and early-leave minutes follow Fingerspot's own rules for the work hours , and so do overtime (Lembur) and days present: checked against Fingerspot's own results and its Datang Terlambat, Pulang Awal, Lembur and Kartu Shift reports for 21-09 to 20-10-2026 — identical for every employee.

## How manual scans are saved
The same way Fingerspot saves a forgotten scan ("Tidak scan"): an approved `izin` row of type 100 (check-in) or 101 (check-out) with the time in `izin_noscan_time`, plus an `att_log` row whose `sn` is `100`/`101`. Fingerspot's own reports therefore count them too. Scans from the fingerprint machines are never changed or deleted.

The app adds two tables of its own to the database: `web_user` (sign-ins) and `web_log` (change log).

## Install on the XAMPP server (192.168.20.102)
1. Copy this folder to `C:\xampp\htdocs\fin-pro\`. When updating, keep the server's existing `config.php`.
2. Replace `config.php` with the server's login (see `config.example.php`):
   ```php
   <?php return ['db_host' => '127.0.0.1', 'db_port' => 3306, 'db_name' => 'fin_pro',
                 'db_user' => 'root', 'db_pass' => 'YOUR_MYSQL_PASSWORD',
                 'timezone' => 'Asia/Jakarta', 'midday' => '12:00'];
   ```
3. Open `http://192.168.20.102/fin-pro/` from any computer on the network.
4. Sign in as **admin** with an empty password. Set a password later on the Users page if you want one.

## Notes
- New employees need the same PIN registered on the fingerprint machine, or their scans won't be matched.
- A day with one scan before `midday` (12:00) counts as "check-out missing", after it as "check-in missing".
- Working days come from the employee's work schedule (`jdw_kerja_*`) and the holiday table (`libur`).
- Downloading scans from the machines and uploading employees to them stays in Fingerspot Personnel.

## Download from fingerspot.io
1. Add the token from the fingerspot.io developer portal to `config.php`: `'fingerspot_token' => 'YOUR_TOKEN',`
2. Mesin → Download Fingerspot.io: pick the dates and machines (those with a Cloud ID) and click Download.
3. Optional, automatic like the program's download times: in Windows Task Scheduler create a task that runs
   `C:\xampp\php\php.exe C:\xampp\htdocs\fin-pro\cloud_cli.php 2` at the times you want (downloads the last 2 days).

Scans are stored like the program stores cloud scans (sn = machine serial, att_id 0), so nothing is added twice.

## HR Overview and Portal Pegawai
- **HR Overview** (`hr.php`, HR button in the toolbar): who is in today, pending time-off requests (Setujui / Tolak), leave in the next 7 days, birthdays, employee list, and links back to the Fingerspot-style screens to enter attendance.
- **Portal Pegawai** (`portal.php`): employees sign in with their PIN and a login code HR creates on HR Overview ("Buat kode login", shown once). They see their attendance for the 21st–20th period, request leave, see their leave balance and profile, and can change their code.
- A request is an `izin` row with `izin_status = 0` and `ket_status = 'Menunggu'` until HR decides (approved = 1; rejected keeps 0 with `ket_status = 'Ditolak'`). The app adds one table, `web_emp`, for portal login codes.

