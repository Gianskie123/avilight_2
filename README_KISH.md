# Reports Tab - Additional Installations (KISH)

This file lists only setup items that are required for the [reports.php](reports.php) features (especially PDF/CSV export) and are not already documented in the other README files.

## 1) Python packages required for report export generation and KBA/PA rebuild

Install these in the same Python environment used by PHP for export scripts:

```bash
python -m pip install fpdf2 matplotlib pyshp PyMySQL
```

Why this is needed:
- `fpdf2`: builds the exported PDF report.
- `matplotlib`: renders chart images embedded in the exported PDF.
- `pyshp`: reads KBA/PA shapefile geometry for audit table rebuilding.
- `PyMySQL`: writes computed KBA/PA metrics into MySQL.

## 2) PHP extension/runtime requirements for Reports APIs

Enable these in your PHP installation (`php.ini`) if not already enabled:

```ini
extension=mbstring
extension=curl
```

Why this is needed:
- `mbstring`: used by report data processing (string normalization in Reports backend).
- `curl`: used by export/report endpoints for faster internal API fetches (fallback exists, but this is strongly recommended).

After changes, restart Apache.

## 3) Ensure PHP process execution is allowed for Python report engine

PDF/CSV export calls Python from PHP (`proc_open`/process execution). If exports fail instantly, check `php.ini` and ensure process functions are not blocked.

Look for:

```ini
disable_functions=
```

If this line includes process functions like `proc_open`, remove them as needed for your local environment and restart Apache.

## 4) KBA/PA audit table refresh endpoint

A new protected endpoint rebuilds the MySQL table used by the Systems Recommendations KBA/PA table:

- Endpoint: `POST /api/refresh_kba_pa_audit.php`
- Script executed: `python/rebuild_kba_pa_audit.py`
- Output table: `kba_pa_audit_live`

Required source files in project root:
- `NCR_Key_Biodiversity_Areas.shp`
- `NCR_Protected Areas.shp`

Note:
- If `.dbf/.shx` sidecar files are not available, the script still reads `.shp` geometry and falls back to layer-wide polygon assignment.

## Quick verification

1. Open Reports tab and click Export PDF.
2. Confirm the loading modal appears and completes download.
3. Repeat with Export CSV.
4. Trigger `POST /api/refresh_kba_pa_audit.php` and verify `kba_pa_audit_live` has 5 rows.
5. If PDF fails but CSV works, re-check Step 1 (`fpdf2`, `matplotlib`).
6. If KBA/PA rebuild fails, re-check Step 1 (`pyshp`, `PyMySQL`) and source shapefiles.
7. If both exports fail immediately from browser request, re-check Step 2 and Step 3.
