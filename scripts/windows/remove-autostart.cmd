@echo off
rem Undoes install-autostart.cmd: removes the startup item, the port forward
rem and the firewall rule. HRMD's data inside WSL is not touched.
rem Run it from an ADMINISTRATOR Command Prompt.
setlocal
del /q "%APPDATA%\Microsoft\Windows\Start Menu\Programs\Startup\hrmd-start.vbs" 2>nul
rem Startup tasks from the first version of these scripts, if they exist.
schtasks /Delete /F /TN "HRMD server" >nul 2>&1
schtasks /Delete /F /TN "HRMD port" >nul 2>&1
netsh interface portproxy delete v4tov4 listenport=8080 listenaddress=0.0.0.0
netsh interface portproxy delete v6tov4 listenport=8080 listenaddress=::
netsh advfirewall firewall delete rule name="HRMD 8080"
echo Removed. HRMD still runs inside WSL until the PC restarts.
exit /b 0
