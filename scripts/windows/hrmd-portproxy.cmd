@echo off
rem Passes port 8080 from this Windows PC to HRMD inside WSL (Ubuntu-24.04),
rem for IPv4 and IPv6: office PCs find this PC's name over either one.
rem WSL gets a new inside address at every start, so the "HRMD port" task
rem runs this at every boot. Needs administrator rights.
setlocal
set "IP="
set /a TRIES=0

:find
for /f "tokens=1" %%a in ('wsl.exe -d Ubuntu-24.04 -- hostname -I 2^>nul') do set "IP=%%a"
if defined IP goto found
set /a TRIES+=1
if %TRIES% GEQ 30 (
    echo Could not find WSL's address. Is Ubuntu-24.04 installed and running?
    exit /b 1
)
rem Wait 2 seconds. "timeout" does not work in a startup task, ping does.
ping -n 3 127.0.0.1 >nul
goto find

:found
netsh interface portproxy delete v4tov4 listenport=8080 listenaddress=0.0.0.0 >nul 2>&1
netsh interface portproxy delete v6tov4 listenport=8080 listenaddress=:: >nul 2>&1
netsh interface portproxy add v4tov4 listenport=8080 listenaddress=0.0.0.0 connectport=8080 connectaddress=%IP%
netsh interface portproxy add v6tov4 listenport=8080 listenaddress=:: connectport=8080 connectaddress=%IP%
echo HRMD: port 8080 now points to WSL at %IP%.
exit /b 0
