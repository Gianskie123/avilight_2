@echo off
REM Wrapper to run start_all.ps1 in PowerShell with default ExecutionPolicy for this session
powershell -NoProfile -ExecutionPolicy RemoteSigned -File "%~dp0start_all.ps1" %*
