' HRMD: starts Docker inside WSL (Ubuntu-24.04) and keeps WSL running, with
' no window. WSL stops itself when nothing on the Windows side is using it,
' so the command never ends on purpose. install-autostart.cmd puts a copy in
' this user's Startup folder, so it runs every time the user is signed in.
CreateObject("WScript.Shell").Run "wsl.exe -d Ubuntu-24.04 -u root -- bash -c ""systemctl start docker; exec sleep infinity""", 0, False
