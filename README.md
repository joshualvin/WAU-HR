# WAU HR: attendance and HR web app (Fingerspot `fin_pro`)

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
1. Get the code: `cd /d C:\xampp\htdocs` then `git clone https://github.com/joshualvin/WAU-HR.git`. Update later with `cd /d C:\xampp\htdocs\WAU-HR && git pull` (it never touches `config.php`).
2. Replace `config.php` with the server's login (see `config.example.php`):
   ```php
   <?php return ['db_host' => '127.0.0.1', 'db_port' => 3306, 'db_name' => 'fin_pro',
                 'db_user' => 'root', 'db_pass' => 'YOUR_MYSQL_PASSWORD',
                 'timezone' => 'Asia/Jakarta', 'midday' => '12:00'];
   ```
3. Open `http://192.168.20.102/WAU-HR/` from any computer on the network. It opens the HR area; the Fingerspot-style screens start at `/WAU-HR/dashboard.php` and the employee portal is `/WAU-HR/portal.php`.
4. Sign in as **admin** with an empty password. Set a password later on the Users page if you want one.

## Notes
- New employees need the same PIN registered on the fingerprint machine, or their scans won't be matched.
- A day with one scan before `midday` (12:00) counts as "check-out missing", after it as "check-in missing".
- Working days come from the employee's work schedule (`jdw_kerja_*`) and the holiday table (`libur`).
- Downloading scans from the machines and uploading employees to them stays in Fingerspot Personnel.

## Head-office scans from FTM (Fingerspot Time Management)
- Head office scans on a machine managed by FTM, whose database `ftm` is on the same MySQL server (`ftm_db` in `config.php`, default `ftm`; `''` = off). FTM has its own PINs (Riko = 402 there, 9213 here).
- **Mesin → Sinkron FTM (kantor pusat)** (`ftm.php`): map each FTM PIN to an employee once (suggested from the first name, e.g. Freddy → Fredy, Nugraheni → Heni, Januar → Yanuar; check and save), then sync a date range. Copied scans get the employee's PIN and sn `FTM` + the FTM machine (`FTM0` = manual corrections made in FTM). A sync replaces the copied scans of its range, so corrections and deletions in FTM come through. Attendance, reports, THR and payroll then include head office.
- FTM scans carry only a PIN, never a name, and a PIN can be handed to someone new. Each pair has a **Berlaku sejak** (valid-from) date: scans on that PIN before it are not copied to the employee. The page suggests it where a PIN was idle for 6 months or more (e.g. PIN 501: scans to May 2019, then nothing until June 2024).
- Automatic: Task Scheduler every 15 minutes, `C:\xampp\php\php.exe C:\xampp\htdocs\WAU-HR\ftm_cli.php 3` (last 3 days).
- The FTM desktop program must save to the server's `ftm` database (its `user.fin`: host 192.168.20.102, port 3306); the page warns when FTM's newest scan is more than 3 days old.

## Download from fingerspot.io
1. Add the token from the fingerspot.io developer portal to `config.php`: `'fingerspot_token' => 'YOUR_TOKEN',`
2. Mesin → Download Fingerspot.io: pick the dates and machines (those with a Cloud ID) and click Download.
3. Optional, automatic like the program's download times: in Windows Task Scheduler create a task that runs
   `C:\xampp\php\php.exe C:\xampp\htdocs\WAU-HR\cloud_cli.php 2` at the times you want (downloads the last 2 days).

Scans are stored like the program stores cloud scans (sn = machine serial, att_id 0), so nothing is added twice.

## HR Overview and Portal Pegawai
- **HR Overview** (`hr.php`, HR button in the toolbar): who is in today, pending time-off requests (Setujui / Tolak), leave in the next 7 days, birthdays, employee list, and links back to the Fingerspot-style screens to enter attendance.
- **Portal Pegawai** (`portal.php`): employees clock in / clock out from the browser (live attendance, saved as a Tidak scan that counts straight away), sign in with their PIN and a login code HR creates on HR Overview ("Buat kode login", shown once). They see their attendance for the 21st–20th period, request leave, see their leave balance and profile, and can change their code.
- A request is an `izin` row with `izin_status = 0` and `ket_status = 'Menunggu'` until HR decides (approved = 1; rejected keeps 0 with `ket_status = 'Ditolak'`). The app adds one table, `web_emp`, for portal login codes.

## Penggajian (payroll and PPh 21)
HR → Penggajian (admins only):
- **Data gaji karyawan**: basic salary, fixed allowance, meal/transport per day present, PTKP status, NPWP/NIK and which BPJS programmes apply.
- **Proses gaji**: calculates a 21st–20th period from attendance — salary + allowance, meal/transport × days present, overtime (1/173 of the monthly wage; work day 1.5× first hour then 2×, day off 2× / 3× / 4×), deductions for absent days without leave (pro-rata) and late minutes (optional rate), BPJS Kesehatan, JHT, JP, JKK, JKM, and PPh 21. **Simpan & terbitkan** publishes payslips to Portal Pegawai (Slip Gaji).
- **PPh 21**: TER monthly rates (PP 58/2023) on gross income including company-paid JKK, JKM and BPJS Kesehatan; the period ending in December settles the year with the Pasal 17 rates (biaya jabatan 5% max 6 jt, JHT/JP contributions, PTKP) minus what was withheld in earlier saved periods.
- **Riwayat & slip gaji**: printable payslips, the payroll report with bank account details, and the monthly PPh 21 data for e-Bupot 21.
- **Pengaturan gaji**: BPJS rates and caps (the JP wage cap changes every March), JKK risk rate, overtime divisor and deduction rules.

Rates and TER brackets are in `inc/payroll.php`; `php tests/payroll_test.php` checks them. Not covered: the 20% surcharge for employees without NPWP/NIK, and annualising for employees who join or leave mid-year.

## Salary components, history, pro-rating and final settlement
- **Salary history**: on Data gaji karyawan, choose "Perubahan gaji berlaku mulai" before saving; a change of basic pay, fixed allowance or daily pay is kept from that date (`web_pay_history`), and earlier periods keep the old amounts (the first change also records the old amounts as the history before it). Each employee has a "riwayat" view.
- **Komponen gaji** (`payroll.php?p=components`): extra earnings or deductions (e.g. tunjangan jabatan, transport per hari hadir, koperasi), monthly or per day present. "Tetap" ones count as wage for BPJS, overtime, absence deductions, THR and severance. Amounts are set per employee with an effective-from date; only changed amounts get a new row; 0 stops one. Earnings are taxable; deductions come off after tax.
- **Pro-rating**: someone who joins or leaves mid-period gets basic pay, the fixed allowance and monthly components by working days employed (e.g. "prorata 14/26 hari kerja"); days outside their employment are not absences. Working days before a new joiner's start are counted on the weekdays they work, minus public holidays. Employees who left are paid up to their last day (tgl_resign).
- **Daily workers (harian lepas)**: employees whose employment status is Harian lepas are paid their daily wage (Upah harian, Data gaji karyawan) × days present, plus uang makan / transport, components and overtime; no monthly salary, no absence deduction, no pro-rating. Overtime and severance use a monthly wage of daily wage × 25 (or 21 for a 5-day week; setting, PP 35/2021 art. 32). PPh 21 by TER harian (PMK 168/2023): average daily gross up to Rp450,000 0%, up to Rp2,500,000 0.5%, above that Pasal 17 on 50% of gross; no December annual calculation and no BPA1 (reported monthly; e-Bupot shows "Harian"). BPJS only for the programmes ticked, on the wage earned. THR wage = average monthly wage of the last 12 saved payslips (Permenaker 6/2016 art. 3(3)), else daily wage × 25.
- **Pesangon & penyelesaian** (`payroll.php?p=settle`): uang pesangon and UPMK by PP 35/2021 art. 40 times the multiplier of the reason (efficiency, closure, merger, violation after SP, long illness, retirement, death…), PKWT compensation (months / 12 × wage), unused leave and other rights (uang penggantian hak), uang pisah, and the final PPh 21 on severance (PP 68/2009: 0 / 5 / 15 / 25 %). Wage and unused leave are filled in from the pay data and leave balance. A printable statement; finalising marks the employee Berhenti on the last day and starts the offboarding checklist. The last salary comes from the normal payroll, pro-rated. `php tests/paycomp_test.php` checks the tables.

## Bonus, kasbon, THR and bukti potong
- **Bonus penjualan** (`payroll.php?p=bonus`): per payroll period, enter each employee's sales target and actual sales; the bonus comes from the tier table on the same page (the highest tier reached gives a % of sales plus an optional fixed amount; default 80% → 1%, 100% → 2%, 120% → 3%). "Bonus lain" takes any other bonus. Bonuses are paid with that period's payroll and are part of its PPh 21 gross.
- **Sales from WAU ERP**: give each salesperson their ERP salesman code (mastersalesman, e.g. L, R, Y) in the "Kode sales ERP" column of the Bonus page, then **Ambil dari ERP** fills "Realisasi penjualan" with the invoiced value of their surat jalan in the payroll period (Σ rolls × metres × SO price, returns shown apart) and recalculates the bonus. Salesmen with sales but no employee are listed. Needs `erp_token` in `config.php` (the ERP secret's `hr_api_token`) and `erp_url` when the ERP is not at `http://127.0.0.1/wau-erp`.
- **Kasbon** (`payroll.php?p=loans`): HR adds a loan, or approves one an employee requested in **Portal Pegawai → Kasbon** (HR can change the amount and installment when approving). From the first period that ends on or after the loan date, each saved payroll deducts the installment (after tax) until it is paid off; saving a period again recalculates instead of deducting twice. An installment is never more than the pay left; the rest moves to the next period. Cash repayments can be recorded too.
- **THR** (`payroll.php?p=thr`, Permenaker 6/2016): full months of service up to the holiday; 12+ months = one month's wage (basic + fixed allowance), 1–11 months = months / 12 of it. HR can raise an amount and recalculate, then publish the THR slips to the portal. PPh 21 on the THR slip is the extra TER tax THR causes in its tax month (estimated from the latest saved payslip when that month's salary isn't saved yet); that month's payroll then taxes salary + THR together minus the THR tax, and the December calculation includes THR.
- **THR dates**: the THR form offers the next Idul Fitri dates and fills in the name, the holiday and the pay date (H-7, moved back to a working day when that is a Sunday or a holiday). The holiday comes from the company calendar (Hari Libur, an Idul Fitri / Lebaran entry that is not cuti bersama), else from the SKB 3 Menteri date in `lebaran_official()` (`inc/payextra.php`), else an estimate marked as such.
- **Government calendar** (Pengaturan → Hari Libur): lists the national holidays and cuti bersama of the SKB 3 Menteri for the year that are missing from the Fingerspot calendar and adds them in one click, with or without cuti bersama (not compulsory for private companies). Existing dates are left alone. 2026 and 2027 are in `inc/govcal.php`; add the next year there (and its Idul Fitri in `lebaran_official()`) when its SKB is published, usually in September.
- **Bukti potong** (`payroll.php?p=bupot`, `bupot.php`): the annual BPA1 (1721-A1) per permanent employee from the year's saved payslips and THR, printable one by one or all at once, and visible to the employee in **Portal Pegawai → Slip Gaji** once a payslip of that year is published. It is final after December's payroll is saved. Set `company_npwp` and `tax_signer` in `config.php` to print them on it. Filing itself still goes through e-Bupot in Coretax.

## Lembur and reimbursement
- **Portal Pegawai → Lembur**: employees request overtime (date, start, end, reason). **HR → Manajemen Waktu → Lembur** approves or rejects, next to what the machine recorded that day.
- **Portal Pegawai → Reimbursement**: employees claim expenses with a photo or PDF receipt (max 5 MB, checked by content). **HR → Penggajian → Reimbursement** approves or rejects. Approved claims are paid with the next saved payroll as a non-taxable earning and marked with that period.
- **Pengaturan gaji → Lembur harus diajukan**: 0 (default) pays overtime from scans as before; 1 pays only up to the approved request per day.
- Receipts are stored in `uploads/receipts/` (kept out of git, blocked for direct web access by `uploads/.htaccess`) and opened through `receipt.php`, only by an admin or the employee who uploaded them.

## Personal data, documents and letters
- **Data pribadi** (profile tab): NIK, KK, NPWP, birth place and date, marital status, marriage date, dependants, religion, blood type, education, KTP and domicile address, phone, email, emergency contact and bank account (phone, birth data and bank account stay in Fingerspot's pegawai table, the rest in `web_emp_profile`). HR edits it; employees ask for changes in **Portal Pegawai → Profil**, which HR approves on the profile (also listed on HR Beranda). When marital status / dependants point to another PTKP than the pay data, the profile says so.
- **Dokumen** (profile tab and Portal → Profil → Dokumen saya): KTP, KK, NPWP, BPJS, ijazah, contract, certificates, SIM… as PDF or photo, with an optional expiry date; documents expiring within 30 days show on HR Beranda. Files are in `uploads/docs/` (blocked for direct access) and opened through `doc.php`, by admins or the employee.
- **Surat & template** (`letters.php`): letters from templates with fields such as {nama} {jabatan} {mulai_kerja} {masa_kerja}: paklaring (surat keterangan kerja), surat keterangan karyawan aktif and SK pengangkatan to start with; templates can be edited and added. A letter can be edited before it is issued, gets a number (001/SKK/HR/XI/2026) and is kept and printable.

## Company: announcements, calendar, org chart, assets
- **Perusahaan → Pengumuman & kalender** (`company.php`): announcements (pinned, with an optional event date and an end date) on the Portal Pegawai home page and HR Beranda; a 60-day calendar of holidays, cuti bersama, events, birthdays and work anniversaries (30 days on the portal).
- **Struktur organisasi**: each employee's direct supervisor (`web_emp_profile.atasan_id`), shown as a chart; loops are refused. The supervisor is also the first to approve requests.
- **Aset**: laptops, phones, vehicles, uniforms, keys… with code, serial and condition, handed to and returned by employees with a history; shown on the profile (Pekerjaan) and in the portal, so offboarding can check what must come back.

## Employment status
- **HR → Karyawan → profile → Pekerjaan**: the status kepegawaian with its history: Karyawan tetap (PKWTT, optional probation end), Kontrak (PKWT, contract number, start and end), Harian lepas or Magang. A renewal or change adds a row; the latest one is current. Checks follow PP 35/2021: a contract chain is at most 5 years including extensions, probation only for permanent staff and at most 3 months.
- The employee list shows each status with its end date (amber within 30 days, red when it has passed) and can be filtered by status. Employees without a status can be filled in at once from their start date.
- HR → Beranda lists contracts and probation periods that end within 30 days, and contracts that already ended.
- **Jadikan karyawan** in Rekrutmen sets the status from the job's employment type (a contract ends after one year unless another date is chosen).
- `php tests/employment_test.php` checks the rules.

## Reprimands (surat peringatan)
- **HR → Karyawan → Surat peringatan** (`discipline.php`): teguran lisan, SP1, SP2 and SP3 with the violation, details, date, validity (6 months by default, PP 35/2021 art. 52) and signer. A new one is suggested one level above the highest SP still valid (verbal warnings don't count; SP3 stays SP3). Numbers run per year, e.g. `001/SP1/HR/XI/2026`. Each has a printable letter (`discipline.php?print=ID`) with what follows a further offence.
- Each employee's history is on their profile (tab Peringatan). Employees who have one get a **Peringatan** tab in the Portal Pegawai to read the letter and confirm they received it; the date shows to HR and on the letter.
- `php tests/discipline_test.php` checks the level rule.

## Onboarding, offboarding and performance reviews
- **HR → Karyawan → On/offboarding** (`talent.php`): checklist templates for onboarding and offboarding (tasks for HR, the employee or the supervisor, due a number of days from the start), started per employee from the list or their profile. New employees without an onboarding checklist are flagged. Employees tick their own tasks on the Portal Pegawai home page.
- **HR → Kinerja**: review cycles with weighted criteria (default: kualitas kerja, produktivitas, kehadiran, kerja sama, inisiatif). Employees rate themselves 1–5 with a note in **Portal Pegawai → Penilaian** while the cycle is open; HR scores each employee next to their attendance for the period. The final score is the weighted average of HR's scores (Sangat baik ≥ 4.5, Baik ≥ 3.5, Cukup ≥ 2.5, Kurang ≥ 1.5). Employees see their result after HR closes the cycle. `php tests/talent_test.php` checks the scoring.

## Shift management
- **HR → Manajemen Waktu → Jadwal shift**: a calendar of every employee's shift for 2 weeks or 31 days (Sundays and holidays in red, changed days underlined). Tick employees or click a cell, pick a date range and a shift (any work hours, Libur, or "Ikuti jadwal kerja" to undo), and save.
- **Tukar shift**: employees ask to swap with a colleague for a date in **Portal Pegawai → Jadwal** (which also shows their next 3 weeks); HR approves, or adds a swap directly.
- Changes are saved in Fingerspot's own tables, "Ganti Jam Kerja per Pegawai" (`ganti_jk_pegawai` + `ganti_jk_d`, type 2) and "Tukar Jam" (`tukar_jam`), and attendance, late / early minutes, overtime, reports and payroll all use them. Priority: a changed shift, then a swap (each takes the other's scheduled shift), then the work schedule. Holidays in `libur` stay days off.


## Recruitment
- **HR → Rekrutmen → Lowongan & kandidat** (`recruit.php`): job openings with position, department, office, employment type, headcount and closing date. Each job has a pipeline board (Lamaran masuk → Seleksi berkas → Wawancara → Penawaran → Diterima / Ditolak). Per candidate: stage, a 1–5 star rating, interview time, notes (stage changes are logged there) and the CV. HR can also add candidates who applied by email, referral or a job fair.
- **Halaman karir** (`careers.php`, no login): lists open jobs and takes applications with a CV (PDF, JPG or PNG, max 5 MB, type checked from the file). It has a hidden honeypot field against bots and refuses a second application from the same email for the same job. Share `http://<server>/WAU-HR/careers.php` with applicants. CVs are stored in `uploads/cv/` (kept out of git, blocked for direct access) and opened through `cv.php`, by admins only. Deleting a candidate also deletes their CV.
- **Jadikan karyawan** creates the employee (the next free PIN, the job's position, department and office, the first work schedule from the start date), starts their onboarding checklist and closes the job once its headcount is filled. Then fill in their remaining data and register the PIN on the fingerprint machine.
- **Link with the careers site** (cv-upload, e.g. career.wautama.com): set `career_url` and `career_token` in `config.php` (the token is the careers site's `HR_API_TOKEN`). WAU-HR then calls the site's `/hr-api` (the site cannot reach this server on the LAN):
  - Openings are managed here. Saving a job writes every job to the site's **Positions** sheet (title, Kualifikasi = requirements the AI scores against, open/closed incl. the closing date, department, office, employment type, description, Tanggung jawab). A position that exists only in the sheet is first imported as a job, so nothing is lost; after that, edit openings here, not in the sheet.
  - Applicants (online form and the site's offline staff page) come in as candidates with the AI match score, reason, summary and red flags, at most every 5 minutes when **Lowongan & kandidat** is opened, or at once with **Sinkronkan sekarang**. Scores that finish later are filled in on the next sync. Applicants for "Other" or an unknown position go to **Lamaran umum**; move them to a job on their page. Their CV is downloaded from Drive the first time HR opens it.
  - With the link set, `careers.php` redirects to the careers site.
- **Manpower planning**: planned headcount per department per year against active employees and open positions, showing the gap.
