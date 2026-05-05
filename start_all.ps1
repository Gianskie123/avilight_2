# start_all.ps1 — prepares Python env, installs dependencies, and starts the backend.
# Usage: .\start_all.ps1

Set-Location -Path $PSScriptRoot

Write-Host "[start_all] Working directory: $PWD"

function run { param($cmd) Write-Host "[start_all] RUN: $cmd"; iex $cmd }

# Ensure Python venv exists
if (-not (Test-Path '.\.venv')) {
    Write-Host "[start_all] Creating Python virtual environment .venv"
    python -m venv .venv
}

# Activate venv for the rest of the script
Write-Host "[start_all] Activating .venv"
. .\.venv\Scripts\Activate.ps1

Write-Host "[start_all] Upgrading pip and installing requirements.txt"
python -m pip install --upgrade pip
if (Test-Path 'requirements.txt') {
    python -m pip install -r requirements.txt
}

# Install Reports-specific Python packages
Write-Host "[start_all] Installing reports-specific Python packages"
python -m pip install fpdf2 matplotlib pyshp PyMySQL

Write-Host "[start_all] Starting Python backend using start_backend.bat"
Start-Process -FilePath "$PSScriptRoot\\start_backend.bat" -WindowStyle Normal

Write-Host "[start_all] Environment ready. Virtual environment prepared and packages installed."
Write-Host "[start_all] Note: To rebuild spatial maps or KBA/PA audit, run:"
Write-Host "[start_all]   php api/refresh_spatial_map.php"
Write-Host "[start_all]   python python\rebuild_kba_pa_audit.py"
Write-Host "[start_all] Done."