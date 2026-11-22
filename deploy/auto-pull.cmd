@echo off
rem WAU HR: brings the server up to date with GitHub main when something new was pushed. Run by Windows Task Scheduler every 2 minutes
rem (set up once, see deploy/README.md). It only fast-forwards: if the server's copy has its own changes, nothing is touched and the log says why.
rem The log is uploads\auto-pull.log (uploads is never served to browsers).
setlocal
cd /d "%~dp0.."
set LOG=%~dp0..\uploads\auto-pull.log
git fetch --quiet origin main 2>>"%LOG%" || (echo %date% %time% fetch failed >> "%LOG%" & exit /b 1)
for /f %%a in ('git rev-parse HEAD') do set HERE=%%a
for /f %%a in ('git rev-parse origin/main') do set THERE=%%a
if "%HERE%"=="%THERE%" exit /b 0
git merge --ff-only --quiet origin/main >> "%LOG%" 2>&1
if errorlevel 1 (echo %date% %time% NOT updated: %HERE:~0,7% -^> %THERE:~0,7% ^(see the lines above; a file may be in use or changed on the server^) >> "%LOG%" & exit /b 1)
echo %date% %time% updated %HERE:~0,7% -^> %THERE:~0,7% >> "%LOG%"
exit /b 0
