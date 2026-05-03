# AVILIGHT Startup Checklist

**One-stop guide for running the system locally.**

---

## Prerequisites

- Windows 10+ with PowerShell 5.0+
- Python 3.8+ (in `.venv` or system)
- Laragon/XAMPP running (Apache + MySQL/MariaDB)
- PHP 7.4+ with `proc_open()` enabled (not in `disable_functions`)
- Database configured and accessible via PDO

---

## Quick Start (One Command)

Run this once you open the project:

```powershell
.\start_all.ps1
```

This does:
1. ✓ Create/activate Python venv (`.venv`)
2. ✓ Install `requirements.txt` + report packages
3. ✓ Start Python FastAPI backend (uvicorn on port 5000)

**Done.** Backend runs in a new window. Open the web app at `http://localhost/avilight/reports.php`

---

## Manual Steps (If `start_all.ps1` doesn't work)

### Step 1: Activate Python Environment

```powershell
.\.venv\Scripts\Activate.ps1
```

If this fails with execution policy error, run:
```powershell
Set-ExecutionPolicy -Scope Process -ExecutionPolicy RemoteSigned
.\.venv\Scripts\Activate.ps1
```

### Step 2: Install Dependencies

```powershell
python -m pip install --upgrade pip
pip install -r requirements.txt
pip install fpdf2 matplotlib pyshp PyMySQL
```

### Step 3: Start Python Backend

```powershell
python -m uvicorn model:app --host 127.0.0.1 --port 5000
```

Expected output:
```
Uvicorn running on http://127.0.0.1:5000
```

Backend now ready. Open a new terminal for other tasks.


## Verify Setup

### Check Python Venv

```powershell
python --version
```

### Check Backend Running

```powershell
netstat -ano | Select-String ":5000"
```

Should show `LISTENING` on port 5000.

### Check Database

```powershell
php tmp_fetch_report.php "scope=snapshot&selected_area=All+Areas"
```

Should return valid JSON (not error).

---

## Troubleshooting

| Issue | Solution |
|-------|----------|
| `python: command not found` | Activate venv: `.\.venv\Scripts\Activate.ps1` |
| `Port 5000 already in use` | Kill existing process: `Get-Process -Name python \| Stop-Process` |
| `pip install fails` | Upgrade pip: `python -m pip install --upgrade pip` |
| `PHP errors` | Ensure Apache is running (Laragon), PHP extensions enabled |
| `Database connection fails` | Check MySQL/MariaDB running, verify DB credentials in code |
| `Execution policy blocked` | Run: `Set-ExecutionPolicy -Scope Process -ExecutionPolicy RemoteSigned` |

## File Reference

| File | Purpose |
|------|---------|
| `start_all.ps1` | Main startup script (installs deps + starts backend) |
| `start_backend.bat` | Start backend only (called by `start_all.ps1`) |
| `register_startup_task.ps1` | Setup automatic logon trigger (admin only) |
| `requirements.txt` | Python dependencies |
| `api/refresh_spatial_map.php` | Rebuild spatial mappings |
| `python/rebuild_kba_pa_audit.py` | Rebuild KBA/PA audit tables |

---

## Support

- **Backend logs:** Watch the uvicorn window output
- **PHP errors:** Check browser console or server logs
- **Database issues:** Use `tmp_inspect_ocm.php` or direct DB queries
- **Task Scheduler issues:** Run `Get-ScheduledTask -TaskName "AVILIGHT Start All"`
