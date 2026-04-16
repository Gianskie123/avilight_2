@echo off
REM ============================================================
REM  AviLight — Python Environment Setup
REM  Run this ONCE from the project root:
REM      cd C:\xampp\htdocs\avilight
REM      setup_env.bat
REM
REM  What it does:
REM    1. Creates venv\ in this folder
REM    2. Installs all packages from requirements.txt
REM    3. PHP (backend_config.php) auto-detects venv\Scripts\python.exe
REM       so no other changes are needed.
REM ============================================================

setlocal

set PROJECT_DIR=%~dp0
set VENV_DIR=%PROJECT_DIR%venv
set VENV_PYTHON=%VENV_DIR%\Scripts\python.exe
set VENV_PIP=%VENV_DIR%\Scripts\pip.exe
set REQUIREMENTS=%PROJECT_DIR%requirements.txt

echo.
echo ============================================================
echo  AviLight Python Environment Setup
echo ============================================================
echo  Project : %PROJECT_DIR%
echo  Venv    : %VENV_DIR%
echo ============================================================
echo.

REM ── Check Python ─────────────────────────────────────────────
python --version >nul 2>&1
if errorlevel 1 (
    echo [ERROR] Python not found in PATH.
    echo         Install Python 3.9+ from https://python.org
    echo         Make sure "Add Python to PATH" is checked during install.
    pause
    exit /b 1
)

for /f "tokens=*" %%v in ('python --version 2^>^&1') do set PY_VER=%%v
echo [OK] Found: %PY_VER%

REM ── Create venv ──────────────────────────────────────────────
if exist "%VENV_PYTHON%" (
    echo [OK] venv already exists — skipping creation.
) else (
    echo [..] Creating virtual environment...
    python -m venv "%VENV_DIR%"
    if errorlevel 1 (
        echo [ERROR] Failed to create venv.
        pause
        exit /b 1
    )
    echo [OK] venv created.
)

REM ── Upgrade pip ──────────────────────────────────────────────
echo [..] Upgrading pip...
"%VENV_PYTHON%" -m pip install --upgrade pip --quiet
echo [OK] pip upgraded.

REM ── Install requirements ─────────────────────────────────────
echo.
echo [..] Installing packages from requirements.txt
echo      (tensorflow is ~2 GB — this may take a while)
echo.
"%VENV_PIP%" install -r "%REQUIREMENTS%"
if errorlevel 1 (
    echo.
    echo [ERROR] Some packages failed to install. Check the output above.
    pause
    exit /b 1
)

REM ── Install GEE (dev machine only) ───────────────────────────
echo.
echo [..] Installing Google Earth Engine (dev/pipeline only)...
"%VENV_PIP%" install -r "%PROJECT_DIR%requirements.gee.txt"
if errorlevel 1 (
    echo [WARN] earthengine-api failed — GEE fetch will not work.
    echo        This is OK if this is the production server.
)

REM ── Verify key packages ──────────────────────────────────────
echo.
echo [..] Verifying key packages...
"%VENV_PYTHON%" -c "import pymysql; print('[OK] pymysql', pymysql.__version__)"
"%VENV_PYTHON%" -c "import pandas; print('[OK] pandas ', pandas.__version__)"
"%VENV_PYTHON%" -c "import numpy; print('[OK] numpy  ', numpy.__version__)"
"%VENV_PYTHON%" -c "import scipy; print('[OK] scipy  ', scipy.__version__)"
"%VENV_PYTHON%" -c "import ee; print('[OK] earthengine-api')"
"%VENV_PYTHON%" -c "import xgboost; print('[OK] xgboost', xgboost.__version__)"

echo.
echo ============================================================
echo  Setup complete!
echo  Python path: %VENV_PYTHON%
echo  PHP will use this automatically via backend_config.php.
echo ============================================================
echo.
pause
