@echo off
REM Build service + tray executables with PyInstaller.
if not exist build rd /s /q build
pyinstaller --onefile --name DolibarrWindowsAgent --hidden-import win32timezone service.py
pyinstaller --onefile --name DolibarrAgentTray --hidden-import win32timezone tray.py
echo.
echo Executables generes dans dist\
echo.
echo Service (en administrateur, dans le dossier de l'agent) :
echo   dist\DolibarrWindowsAgent.exe install
echo   dist\DolibarrWindowsAgent.exe start
echo.
echo Icone barre des taches (au demarrage ou manuellement) :
echo   dist\DolibarrAgentTray.exe
echo.
echo Desinstallation du service :
echo   dist\DolibarrWindowsAgent.exe stop
echo   dist\DolibarrWindowsAgent.exe remove
