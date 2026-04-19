
## 1) Project Location

Run all commands from the project folder:

```powershell
cd "c:\laragon\www\avilight-main"
```

## 2) Python Environment

### Activate existing `.venv` (Windows PowerShell)

```powershell
& ".\.venv\Scripts\Activate.ps1"
```

### If `.venv` is missing, create it and install deps

```powershell
python -m venv .venv
& ".\.venv\Scripts\Activate.ps1"
python -m pip install --upgrade pip
python -m pip install -r requirements.txt
```

## 3) Start Python FastAPI ML Backend

Run the FastAPI app in a separate terminal before opening the Scenario page.

Option A (recommended script):

```powershell
start_backend.bat
```

Option B (direct uvicorn command, with the environment activated):

```powershell
python -m uvicorn model:app --host 127.0.0.1 --reload --port 5000
```

Expected startup logs include FastAPI app startup, model loading, and a running server on port `5000`.

If `meta_learner.joblib` is not present, the backend falls back to a deterministic blend of the XGBoost and ConvLSTM outputs so the service still starts.

## 4) Verify Backend Is Running

Open this URL in browser:

`http://localhost:5000/health`

Expected: JSON response showing backend status.

## 5) Start Web App (XAMPP)

1. Open XAMPP Control Panel.
2. Start `Apache`.
3. Visit:
   - `http://localhost/avilight-main/scenario.php`

The Scenario page calls `api/run_scenario.php`, which forwards requests to the Python backend at `http://localhost:5000`.

Keep the FastAPI terminal running while using the Scenario page and geospatial predictions.

## 7) Quick Troubleshooting

### Problem: Failed to connect to Python backend

- Confirm `uvicorn` is running on port `5000`.
- Confirm this config value in `includes/backend_config.php`:
  - `PYTHON_BACKEND_URL = http://localhost:5000`

### Problem: Module not found errors

- Reactivate `.venv`
- Reinstall requirements:

```powershell
python -m pip install -r requirements.txt
```

### Problem: Models do not load

- Confirm these files exist in `api_models/`:
  - `xgb_tolerant.json`
  - `xgb_sensitive.json`
  - `xgb_resident.json`
  - `xgb_migrant.json`
  - `convlstm_classifier.keras`
  - `convlstm_regressor.keras`

## 8) Next Step (Run This After README_BADS)

After finishing all steps above and confirming the app loads, continue with additional Reports-specific setup in `README_KISH.md`.