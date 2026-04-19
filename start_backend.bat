@echo off
setlocal

set PROJECT_DIR=%~dp0
set PY_EXE=%PROJECT_DIR%\.venv\Scripts\python.exe

if not exist "%PY_EXE%" (
    echo [ERROR] Python venv not found at %PY_EXE%
    echo Run setup_env.bat first.
    exit /b 1
)

echo Starting AviLight Python backend on http://127.0.0.1:5000 ...
cd /d "%PROJECT_DIR%"
"%PY_EXE%" -m uvicorn model:app --host 127.0.0.1 --port 5000
