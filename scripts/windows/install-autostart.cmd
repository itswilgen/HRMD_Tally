@echo off
rem One-time setup on the Windows PC that runs HRMD (docs: deploy-on-windows-pc).
rem Run it from an ADMINISTRATOR Command Prompt, after copying this folder to
rem C:\hrmd. It opens port 8080 on the office network, forwards it to HRMD now,
rem and creates the two startup tasks so HRMD runs whenever the PC is on.
setlocal

net session >nul 2>&1
if errorlevel 1 (
    echo Run this from an ADMINISTRATOR Command Prompt:
    echo Start, type cmd, right-click Command Prompt, Run as administrator.
    exit /b 1
)

if /i not "%~dp0"=="C:\hrmd\" (
    echo Copy this folder to C:\hrmd first, then run C:\hrmd\install-autostart.cmd
    exit /b 1
)

echo [1/4] Opening port 8080 on the office network only (Private and Domain)...
netsh advfirewall firewall delete rule name="HRMD 8080" >nul 2>&1
netsh advfirewall firewall add rule name="HRMD 8080" dir=in action=allow protocol=TCP localport=8080 profile=private,domain
if errorlevel 1 exit /b 1

echo [2/4] Forwarding port 8080 to HRMD now...
call "%~dp0hrmd-portproxy.cmd"
if errorlevel 1 exit /b 1

echo [3/4] Creating the "HRMD server" startup task.
echo       Type the Windows password of %USERDOMAIN%\%USERNAME% when asked.
schtasks /Create /F /TN "HRMD server" /XML "%~dp0hrmd-server-task.xml" /RU "%USERDOMAIN%\%USERNAME%" /RP *
if errorlevel 1 exit /b 1

echo [4/4] Creating the "HRMD port" startup task (same password).
schtasks /Create /F /TN "HRMD port" /XML "%~dp0hrmd-port-task.xml" /RU "%USERDOMAIN%\%USERNAME%" /RP *
if errorlevel 1 exit /b 1

echo.
echo Done. Check from another office PC: http://%COMPUTERNAME%:8080
echo Then restart this PC, do not log in, wait 3 minutes, and check again.
exit /b 0
