@echo off
rem Undoes install-autostart.cmd: removes the two startup tasks, the port
rem forward and the firewall rule. HRMD's data inside WSL is not touched.
rem Run it from an ADMINISTRATOR Command Prompt.
setlocal
schtasks /Delete /F /TN "HRMD server"
schtasks /Delete /F /TN "HRMD port"
netsh interface portproxy delete v4tov4 listenport=8080 listenaddress=0.0.0.0
netsh interface portproxy delete v6tov4 listenport=8080 listenaddress=::
netsh advfirewall firewall delete rule name="HRMD 8080"
echo Removed. HRMD still runs inside WSL until the PC restarts.
exit /b 0
