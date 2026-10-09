@echo off
REM Build a standalone service executable with PyInstaller.
pyinstaller --onefile --name DolibarrWindowsAgent --hidden-import win32timezone service.py
echo.
echo Executable genere dans dist\DolibarrWindowsAgent.exe
echo.
echo Installation du service (en administrateur) :
echo   dist\DolibarrWindowsAgent.exe install
echo   dist\DolibarrWindowsAgent.exe start
echo.
echo Desinstallation :
echo   dist\DolibarrWindowsAgent.exe stop
echo   dist\DolibarrWindowsAgent.exe remove
