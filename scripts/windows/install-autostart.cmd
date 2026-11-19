@echo off
rem One-time setup on the Windows PC that runs HRMD (docs: deploy-on-windows-pc).
rem Run it from an ADMINISTRATOR Command Prompt, after copying this folder to
rem C:\hrmd. Needs no Windows password.
rem
rem How it works: inside WSL, HRMD answers on port 8081 (APP_PORT=8081 in
rem ~/hrmd/.env). WSL passes 8081 to this PC's own localhost by itself. Here,
rem port 8080 on the office network is forwarded to localhost:8081. That
rem forward never changes, so it is set once and Windows keeps it across
rem restarts. HRMD starts at sign-in from the user's Startup folder.
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

echo [2/4] Forwarding port 8080 to HRMD (localhost:8081), IPv4 and IPv6...
netsh interface portproxy delete v4tov4 listenport=8080 listenaddress=0.0.0.0 >nul 2>&1
netsh interface portproxy delete v6tov4 listenport=8080 listenaddress=:: >nul 2>&1
netsh interface portproxy add v4tov4 listenport=8080 listenaddress=0.0.0.0 connectport=8081 connectaddress=127.0.0.1
if errorlevel 1 exit /b 1
netsh interface portproxy add v6tov4 listenport=8080 listenaddress=:: connectport=8081 connectaddress=127.0.0.1
if errorlevel 1 exit /b 1
rem The forward is run by the IP Helper service: make sure it starts with Windows.
sc config iphlpsvc start= auto >nul
net start iphlpsvc >nul 2>&1

echo [3/4] Starting HRMD at every sign-in (Startup folder)...
copy /y "%~dp0hrmd-start.vbs" "%APPDATA%\Microsoft\Windows\Start Menu\Programs\Startup\hrmd-start.vbs" >nul
if errorlevel 1 exit /b 1

echo [4/4] Starting HRMD now...
start "" wscript.exe "%~dp0hrmd-start.vbs"

echo.
netsh interface portproxy show all
echo Done. From another office PC, open: http://%COMPUTERNAME%:8080
echo Then restart this PC, sign in, wait 2 minutes, and check again.
exit /b 0
