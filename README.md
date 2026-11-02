# WAU Attendance

Attendance, time off and an employee list for Wahyu Agung Utama, laid out like Talenta's HRIS screens.
Plain PHP + MySQL; no build step.

## Files
| File | What it does |
|---|---|
| `index.html`, `app.css`, `app.js` | The app (pages are `#/home`, `#/live-attendance`, `#/attendance/data`, `#/attendance/activity`, `#/attendance/report`, `#/time-off/requests`, `#/time-off/balance`, `#/employees/list`, `#/settings`) |
| `api.php` | JSON API; creates and upgrades the tables on first use |
| `schema.sql` | Table definitions (MySQL 5.1+) |
| `config.php` | Database login (not in git). `config.example.php` shows the format |
| `create-database.sql` | Optional: a separate database plus a login limited to it |

## Put it on the XAMPP server (192.168.20.102)
1. Copy this folder to `C:\xampp\htdocs\wau-attendance\`.
2. Replace `config.php` with literal values (the local copy reads wau-erp's secrets file, which won't be next to it on the server):
   ```php
   <?php return ['db_host' => '127.0.0.1', 'db_port' => 3306, 'db_name' => 'hr',
                 'db_user' => 'your_db_user', 'db_pass' => '…', 'timezone' => 'Asia/Jakarta'];
   ```
3. Open `http://192.168.20.102/wau-attendance/` from any computer on the network.

## Notes
- Live attendance asks the browser for a location. Browsers only share it over **https** (or on localhost), so over plain
  `http://192.168.20.102` clock-ins are saved without coordinates. Add a certificate to Apache to record locations.
- There is no sign-in yet: anyone who can open the page can record attendance and approve time off.
  Each computer chooses "who is clocking in" from the user menu (top right).

## fin-pro/
A second app in [`fin-pro/`](fin-pro/README.md): Fingerspot-style attendance screens, a Talenta-style HR area and an employee portal that work directly on the Fingerspot Personnel database (`fin_pro`).
