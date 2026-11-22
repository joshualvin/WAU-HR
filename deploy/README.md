# Automatic update of the server (auto-pull)

`deploy/auto-pull.cmd` checks GitHub every 2 minutes and, when main has new commits, fast-forwards the server's copy. Nothing else is needed after an update: PHP pages are read from the files, new database tables and columns are created when a page first needs them, and stylesheets carry a `?v=` version from the file time. The same mechanism WAU ERP uses.

## Set up once (on the Windows server)

Open **Command Prompt as administrator** and run this, signed in as the Windows user who normally does `git pull` (git must find that user's GitHub login). Change the folder if WAU-HR is somewhere else:

```
schtasks /Create /TN "WAU HR auto pull" /SC MINUTE /MO 2 /TR "C:\xampp\htdocs\WAU-HR\deploy\auto-pull.cmd" /RU "%USERDOMAIN%\%USERNAME%" /RP * /RL LIMITED /F
```

It asks for that user's Windows password, so it also runs when nobody is signed in to the server.

Check it once: `schtasks /Run /TN "WAU HR auto pull"`, then look at `uploads\auto-pull.log` (empty, or "updated ...", is good).

## When it does not update

- `NOT updated` in the log: a file on the server was changed by hand, or Apache held a file open. Run `git status` in the folder and put back what was changed (`git restore <file>`), or stop Apache for a moment; it carries on by itself at the next run.
- `fetch failed`: no network, or that Windows user's GitHub login has expired.

`config.php` and `uploads\` are not in git, so an update never touches them.

## Stop it

```
schtasks /Delete /TN "WAU HR auto pull" /F
```
