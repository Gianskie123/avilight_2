<?php
$page_title = 'Admin Panel';
require_once 'includes/auth.php';
require_admin(); // Require admin access
require_once 'includes/db.php';

$model_rows = [];
$model_error = null;
try {
    $model_db = get_mysql_db();
    $stmt = $model_db->query(
        "SELECT version_name, status, created_at
         FROM models
         ORDER BY created_at DESC, id DESC"
    );
    $model_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $model_error = $e->getMessage();
}
require_once 'includes/header.php';
?>

<div class="page-header">
    <h1 class="page-title">Admin & Staff Controls</h1>
    <p class="page-subtitle">Data management, model configuration, and system monitoring</p>
</div>

<!-- Data Ingestion -->
<div class="card">
    <h2 class="card-header">Data Ingestion</h2>
    <div class="card-body">
        <h4>Upload Bird Observation Data</h4>
        <form id="dataUploadForm" enctype="multipart/form-data">
            <div class="form-group">
                <label class="form-label">Select CSV/Excel File:</label>
                <input type="file" class="form-control" id="dataFile" accept=".csv,.xlsx" required>
                <small style="color: #666;">
                    Accepted formats: CSV, XLSX &nbsp;|&nbsp; Max size: 150 MB<br>
                </small>
            </div>
            <button type="submit" class="btn btn-primary">Upload & Validate</button>
        </form>
        
        <div id="uploadStatus" style="margin-top: 15px;"></div>

        <hr style="margin: 20px 0;">

        <h4>Analytics Cache</h4>
        <p style="margin: 0 0 10px 0; color: #666;">
            Rebuild precomputed analytics summaries used by the Analytics tab for faster loading.
        </p>
        <button type="button" class="btn btn-secondary" id="rebuildAnalyticsBtn" onclick="rebuildAnalyticsCache()">
            Rebuild Analytics Cache
        </button>
        <div id="analyticsCacheStatus" style="margin-top: 10px;"></div>
    </div>
</div>

<!-- Environmental Covariates -->
<div class="card">
    <h2 class="card-header">Environmental Covariates</h2>
    <div class="card-body">
        <div class="grid-2">

            <!-- Left column -->
            <div>
                <h4>Artificial Light (VIIRS)</h4>
                <button class="btn btn-primary" onclick="fetchVIIRS.call(this, this)">
                    Fetch Artificial Light (VIIRS) Data
                </button>
                <p style="margin-top: 10px; color: #666;">
                    <strong>Last Fetch:</strong> <span data-cov-fetch="viirs">Loading...</span><br>
                    <strong>Status:</strong> <span data-cov-status="viirs" class="badge">Loading...</span>
                </p>

                <hr style="margin: 20px 0;">

                <h4>Land Surface Temperature (MODIS)</h4>
                <button class="btn btn-primary" onclick="fetchNOAATemp.call(this, this)">
                    Fetch Land Surface Temperature (MODIS) Data
                </button>
                <p style="margin-top: 10px; color: #666;">
                    <strong>Last Fetch:</strong> <span data-cov-fetch="land_temp">Loading...</span><br>
                    <strong>Status:</strong> <span data-cov-status="land_temp" class="badge">Loading...</span>
                </p>
            </div>

            <!-- Right column -->
            <div>
                <h4>Vegetation Index (MODIS)</h4>
                <button class="btn btn-primary" onclick="fetchMODIS.call(this, this)">
                    Fetch Vegetation Index (MODIS) Data
                </button>
                <p style="margin-top: 10px; color: #666;">
                    <strong>Last Fetch:</strong> <span data-cov-fetch="ndvi">Loading...</span><br>
                    <strong>Status:</strong> <span data-cov-status="ndvi" class="badge">Loading...</span>
                </p>

                <hr style="margin: 20px 0;">

                <h4>Precipitation (CHIRPS)</h4>
                <button class="btn btn-primary" onclick="fetchNOAAPrecip.call(this, this)">
                    Fetch Precipitation (CHIRPS) Data
                </button>
                <p style="margin-top: 10px; color: #666;">
                    <strong>Last Fetch:</strong> <span data-cov-fetch="precip">Loading...</span><br>
                    <strong>Status:</strong> <span data-cov-status="precip" class="badge">Loading...</span>
                </p>
            </div>

        </div>
    </div>
</div>

<!-- Model Versioning -->
<div class="card">
    <h2 class="card-header">Model Versioning & Management</h2>
    <div class="card-body">
        <div class="grid-2">
            <div>
                <h4>Upload New Model</h4>
                <form id="modelUploadForm">
                    <div class="form-group">
                        <label class="form-label">Model File:</label>
                        <input type="file" class="form-control" id="modelFile" accept=".zip,.pkl,.h5,.pth" required>
                        <small style="color: #666;">
                            Recommended: upload one .zip bundle containing<br>
                            xgb_tolerant.json, xgb_sensitive.json, xgb_resident.json, xgb_migrant.json,<br>
                            convlstm_classifier.keras, convlstm_regressor.keras, meta_learner.joblib
                        </small>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Version Name:</label>
                        <input type="text" class="form-control" id="versionName" placeholder="e.g., v2.1.0" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Description:</label>
                        <textarea class="form-control" id="versionDesc" rows="3" placeholder="Describe model changes..."></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary">Upload Model</button>
                </form>
            </div>
            
            <div>
                <h4>Active Model Versions</h4>
                <table style="font-size: 0.9rem;">
                    <thead>
                        <tr>
                            <th>Version</th>
                            <th>Date</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($model_error !== null): ?>
                        <tr>
                            <td colspan="4" style="color: #b91c1c;">Failed to load model versions: <?php echo htmlspecialchars($model_error); ?></td>
                        </tr>
                        <?php elseif (empty($model_rows)): ?>
                        <tr>
                            <td colspan="4" style="color: #666;">No model versions found yet.</td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($model_rows as $row): ?>
                        <?php
                            $status = (string)($row['status'] ?? 'Archived');
                            $version = (string)($row['version_name'] ?? '');
                            $created_at = (string)($row['created_at'] ?? '');
                            $badge = 'badge-info';
                            if ($status === 'Active') {
                                $badge = 'badge-success';
                            } elseif ($status === 'Archived') {
                                $badge = 'badge-secondary';
                            }
                        ?>
                        <tr>
                            <td><?php echo $status === 'Active' ? '<strong>' . htmlspecialchars($version) . '</strong>' : htmlspecialchars($version); ?></td>
                            <td><?php echo htmlspecialchars(substr($created_at, 0, 10)); ?></td>
                            <td><span class="badge <?php echo $badge; ?>"><?php echo htmlspecialchars($status); ?></span></td>
                            <td>
                                <?php if ($status === 'Active'): ?>
                                -
                                <?php else: ?>
                                <button class="btn btn-secondary" style="padding: 4px 8px; font-size: 0.8rem;" onclick='switchModel(<?php echo json_encode($version, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>)'>Switch</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
                <div id="modelStatus" style="margin-top: 10px;"></div>
            </div>
        </div>
    </div>
</div>

<!-- Threshold Configuration -->
<div class="card">
    <h2 class="card-header">Threshold Configuration</h2>
    <div class="card-body">
        <div class="grid-2">
            <div>
                <h4>Danger Zone Color Scales</h4>
                <div class="form-group">
                    <label class="form-label">High Risk Threshold (Light Intensity):</label>
                    <input type="number" class="form-control" value="60" min="0" max="100" id="highRiskThreshold">
                </div>
                <div class="form-group">
                    <label class="form-label">Moderate Risk Threshold:</label>
                    <input type="number" class="form-control" value="40" min="0" max="100" id="modRiskThreshold">
                </div>
                <div class="form-group">
                    <label class="form-label">Low Risk Threshold:</label>
                    <input type="number" class="form-control" value="25" min="0" max="100" id="lowRiskThreshold">
                </div>
            </div>
            
            <div>
                <h4>SHAP Alert Thresholds</h4>
                <div class="form-group">
                    <label class="form-label">Critical Negative Impact:</label>
                    <input type="number" class="form-control" value="-5" step="0.1" id="criticalShap">
                    <small style="color: #666;">Cells turn red when SHAP value below this</small>
                </div>
                <div class="form-group">
                    <label class="form-label">Warning Threshold:</label>
                    <input type="number" class="form-control" value="-3" step="0.1" id="warningShap">
                </div>
                <div class="form-group">
                    <label class="form-label">Positive Impact Threshold:</label>
                    <input type="number" class="form-control" value="2" step="0.1" id="positiveShap">
                    <small style="color: #666;">Cells turn green when above this</small>
                </div>
            </div>
        </div>
        <button class="btn btn-primary" style="margin-top: 15px;" onclick="saveThresholds()">Save Configuration</button>
    </div>
</div>

<!-- Validation & Error Logs -->
<div class="card">
    <h2 class="card-header">Validation & Error Logs</h2>
    <div class="card-body">
        <h4>Recent Data Quality Issues</h4>
        <table>
            <thead>
                <tr>
                    <th>Timestamp</th>
                    <th>Type</th>
                    <th>Issue</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>2026-02-05 14:23</td>
                    <td><span class="badge badge-warning">Spatial</span></td>
                    <td>12 observations outside Philippines bounds (lat > 20°N)</td>
                    <td><span class="badge badge-danger">Rejected</span></td>
                </tr>
                <tr>
                    <td>2026-02-03 09:15</td>
                    <td><span class="badge badge-info">Format</span></td>
                    <td>Date format inconsistent in batch upload #3847</td>
                    <td><span class="badge badge-success">Resolved</span></td>
                </tr>
                <tr>
                    <td>2026-02-01 16:42</td>
                    <td><span class="badge badge-warning">Duplicate</span></td>
                    <td>45 duplicate records detected in eBird sync</td>
                    <td><span class="badge badge-success">Cleaned</span></td>
                </tr>
            </tbody>
        </table>
        
    </div>
</div>

<!-- Spatial Integrity Checks -->
<div class="card">
    <h2 class="card-header">Spatial Integrity Checks</h2>
    <div class="card-body">
        <div style="padding: 15px; background: rgba(34, 197, 94, 0.1); border: 1px solid rgba(34, 197, 94, 0.3); border-radius: 8px;">
            <strong>✓ All Checks Passed</strong>
            <ul style="margin-top: 10px;">
                <li>Latitude range: 14.2° to 14.9° N ✓</li>
                <li>Longitude range: 120.8° to 121.2° E ✓</li>
                <li>No offshore observations ✓</li>
                <li>All cells mapped to valid land cover ✓</li>
            </ul>
        </div>
    </div>
</div>

<!-- Security & Activity Logs -->
<div class="grid-2">
    <div class="card">
        <h2 class="card-header">Security & Access Logs</h2>
        <div class="card-body">
            <h4>Recent Activity</h4>
            <table style="font-size: 0.9rem;">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Action</th>
                        <th>Time</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><?php echo htmlspecialchars(get_logged_user()); ?></td>
                        <td>Logged in</td>
                        <td>Just now</td>
                    </tr>
                    <tr>
                        <td>admin@avilight.ph</td>
                        <td>Model upload v2.1.0</td>
                        <td>2 days ago</td>
                    </tr>
                    <tr>
                        <td>researcher@denr.gov</td>
                        <td>Downloaded report</td>
                        <td>3 days ago</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
    
    <div class="card">
        <h2 class="card-header">System Health</h2>
        <div class="card-body">
            <h4>Monitoring Status</h4>
            <div style="margin-top: 15px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin: 10px 0;">
                    <span>API Response Time:</span>
                    <span class="badge badge-success">125ms</span>
                </div>
                <div style="display: flex; justify-content: space-between; align-items: center; margin: 10px 0;">
                    <span>Database Status:</span>
                    <span class="badge badge-success">Healthy</span>
                </div>
                <div style="display: flex; justify-content: space-between; align-items: center; margin: 10px 0;">
                    <span>Model Serving:</span>
                    <span class="badge badge-success">Online</span>
                </div>
                <div style="display: flex; justify-content: space-between; align-items: center; margin: 10px 0;">
                    <span>Satellite Data Sync:</span>
                    <span class="badge badge-success">Active</span>
                </div>
                <div style="display: flex; justify-content: space-between; align-items: center; margin: 10px 0;">
                    <span>Disk Usage:</span>
                    <span class="badge badge-warning">68%</span>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$extra_scripts = <<<'EOD'
<script>
// ── Covariate status loader ───────────────────────────────────────────────────

const STATUS_BADGE = {
    'Up to Date':             'badge-success',
    'Fetch Required':         'badge-danger',
    'Ahead of Observations':  'badge-info',
    'No Data':                'badge-warning',
    'No Observations':        'badge-warning',
};

function formatLastFetch(ingestedAt) {
    if (!ingestedAt) return 'Never';
    const ts = ingestedAt.replace('T', ' ').substring(0, 16) + ' UTC';
    return ts;
}

function loadCovariateStatus() {
    fetch('api/covariate_status.php?t=' + Date.now())
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                ['viirs','ndvi','land_temp','precip'].forEach(key => {
                    document.querySelector(`[data-cov-fetch="${key}"]`).textContent = 'Error';
                    const s = document.querySelector(`[data-cov-status="${key}"]`);
                    s.textContent = 'Error';
                    s.className = 'badge badge-warning';
                });
                return;
            }

            Object.entries(data.covariates).forEach(([key, cov]) => {
                const fetchEl  = document.querySelector(`[data-cov-fetch="${key}"]`);
                const statusEl = document.querySelector(`[data-cov-status="${key}"]`);

                fetchEl.innerHTML = formatLastFetch(cov.ingested_at);

                const badgeClass = STATUS_BADGE[cov.status] || 'badge-secondary';
                statusEl.textContent = cov.status;
                statusEl.className   = 'badge ' + badgeClass;
            });
        })
        .catch(() => {
            ['viirs','ndvi','land_temp','precip'].forEach(key => {
                document.querySelector(`[data-cov-fetch="${key}"]`).textContent = 'Unavailable';
                const s = document.querySelector(`[data-cov-status="${key}"]`);
                s.textContent = 'Unavailable';
                s.className = 'badge badge-warning';
            });
        });
}

// Load on page ready, then refresh every 60 seconds
document.addEventListener('DOMContentLoaded', loadCovariateStatus);
setInterval(loadCovariateStatus, 60000);

// ── Data upload form ──────────────────────────────────────────────────────────
// Data upload form
document.getElementById('dataUploadForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const fileInput = document.getElementById('dataFile');
    const statusDiv = document.getElementById('uploadStatus');
    
    if (fileInput.files.length === 0) {
        statusDiv.innerHTML = '<div class="alert alert-danger">Please select a file</div>';
        return;
    }
    
    const file = fileInput.files[0];
    
    // Validate file size
    if (file.size > 150 * 1024 * 1024) {
        statusDiv.innerHTML = '<div class="alert alert-danger">File exceeds the file size limit of 150MB.</div>';
        return;
    }
    
    // Validate file type
    const validExtensions = ['.csv', '.xlsx'];
    const fileName = file.name.toLowerCase();
    if (!validExtensions.some(ext => fileName.endsWith(ext))) {
        statusDiv.innerHTML = '<div class="alert alert-danger">Invalid file type. Only CSV and XLSX allowed.</div>';
        return;
    }
    
    statusDiv.innerHTML = '<div class="alert alert-info"><div class="loading"></div> Uploading and validating data...</div>';
    
    const formData = new FormData();
    formData.append('file', file);
    
    fetch('api/upload_data.php', { method: 'POST', body: formData })
        .then(r => {
            if (!r.ok) {
                return r.text().then(text => {
                    throw new Error(`Server returned ${r.status}: ${text.substring(0, 300)}`);
                });
            }
            return r.json();
        })
        .then(data => {
            if (data.success) {
                statusDiv.innerHTML = `<div class="alert alert-info"><strong>✓ ${data.message}</strong></div>`;
                rebuildAnalyticsCache(true);
            } else {
                statusDiv.innerHTML = `<div class="alert alert-danger">${data.error || 'Upload failed.'}</div>`;
            }
        })
        .catch(err => {
            statusDiv.innerHTML = `<div class="alert alert-danger">Upload failed: ${err.message}</div>`;
        });
});

function rebuildAnalyticsCache(silent = false) {
    const btn = document.getElementById('rebuildAnalyticsBtn');
    const statusEl = document.getElementById('analyticsCacheStatus');

    btn.disabled = true;
    if (!silent) {
        statusEl.innerHTML = '<div class="alert alert-info">Rebuilding analytics cache...</div>';
    }

    fetch('api/rebuild_analytics_cache.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ scope: 'metro' })
    })
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                statusEl.innerHTML = `<div class="alert alert-danger">${data.error || 'Cache rebuild failed.'}</div>`;
                return;
            }
            const rows = Number(data.row_count || 0);
            const refreshedAt = data.refreshed_at || 'n/a';
            const bau = data.bau_cache || {};
            const bauRows = Number(bau.rows || 0);
            const bauRefreshed = bau.refreshed_at || 'n/a';
            const bauOk = Number(bau.prewarm_ok || 0);
            const bauFailed = Number(bau.prewarm_failed || 0);
            const bauScope = bau.scope || 'metro';
            const targetCities = Number(bau.target_cities || 0);
            statusEl.innerHTML = `<div class="alert alert-info">${data.message || 'Analytics cache rebuilt.'}<br><small>Latest Sites: ${rows} | Refreshed: ${refreshedAt}<br>BAU Scope: ${bauScope} | Target Cities: ${targetCities}<br>BAU Baselines: ${bauRows} | Prewarm OK: ${bauOk} | Failed: ${bauFailed} | Refreshed: ${bauRefreshed}</small></div>`;
        })
        .catch(() => {
            statusEl.innerHTML = '<div class="alert alert-danger">Cache rebuild request failed. Check server connection.</div>';
        })
        .finally(() => {
            btn.disabled = false;
        });
}

// Model upload form
document.getElementById('modelUploadForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const file    = document.getElementById('modelFile').files[0];
    const version = document.getElementById('versionName').value;
    const desc    = document.getElementById('versionDesc').value;
    const statusEl = document.getElementById('modelStatus');

    if (!file) {
        statusEl.innerHTML = '<div class="alert alert-danger">Please select a model file.</div>';
        return;
    }
    if (!version.trim()) {
        statusEl.innerHTML = '<div class="alert alert-danger">Version name is required.</div>';
        return;
    }
    statusEl.innerHTML = '<div class="alert alert-info">Uploading model...</div>';
    
    const formData = new FormData();
    formData.append('file', file);
    formData.append('version', version);
    formData.append('description', desc);
    
    fetch('api/upload_model.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                statusEl.innerHTML = `<div class="alert alert-danger">${data.error || 'Model upload failed.'}</div>`;
                return;
            }
            statusEl.innerHTML = `<div class="alert alert-info">${data.message || 'Model upload complete.'}</div>`;
            setTimeout(() => window.location.reload(), 700);
        })
        .catch(() => {
            statusEl.innerHTML = '<div class="alert alert-danger">Model upload request failed. Check server connection.</div>';
        });
});

// ── Covariate fetch functions ─────────────────────────────────────────────────

const MONTH_NAMES = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

/**
 * Shared fetch logic for all 4 covariates.
 * 1. Calls the backend to compute missing (year, month) chunks.
 * 2. Shows the user exactly what will be fetched before proceeding.
 * 3. Refreshes the covariate status panel on completion.
 */
// Safely parse a fetch Response — returns the JSON data or throws with a
// readable message that includes the raw server response (up to 300 chars).
function safeJson(r) {
    return r.text().then(text => {
        try {
            return JSON.parse(text);
        } catch (_) {
            throw new Error(`Server returned HTTP ${r.status}. Response: ${text.substring(0, 300)}`);
        }
    });
}

function fetchCovariate(btn, source, label) {
    btn.disabled = true;
    btn.textContent = 'Checking missing periods…';

    // Step 1 — dry run: ask backend which (year, month) periods are missing
    fetch('api/fetch_satellite.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ source })
    })
    .then(safeJson)
    .then(data => {
        btn.disabled = false;
        btn.textContent = `Fetch ${label} Data`;

        if (!data.success) {
            alert(`${label} error:\n\n${data.error || JSON.stringify(data)}`);
            return;
        }

        // Nothing missing
        if (!data.missing || data.missing.length === 0) {
            alert(`${label} is already up to date with all bird observation periods.`);
            loadCovariateStatus();
            return;
        }

        // Step 2 — show user exactly which periods will be fetched (first batch)
        const batchSize  = 10;
        const totalCount = data.missing.length;
        const thisBatch  = data.missing.slice(0, batchSize);
        const periodList = thisBatch
            .map(p => `${MONTH_NAMES[p.month - 1]} ${p.year}`)
            .join(', ');
        const remaining  = totalCount - thisBatch.length;
        const remainNote = remaining > 0
            ? `\n\n${remaining} period(s) will remain after this batch — click Fetch again to continue.`
            : '';

        const confirmed = confirm(
            `${label}: ${totalCount} missing period(s) found.\n\n` +
            `Fetching next ${thisBatch.length} from GEE:\n${periodList}` +
            remainNote + `\n\nProceed? This may take a few minutes.`
        );

        if (!confirmed) return;

        // Step 3 — confirmed: trigger actual GEE fetch
        btn.disabled = true;
        btn.textContent = `Fetching ${thisBatch.length} of ${totalCount} period(s)…`;

        fetch('api/fetch_satellite.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ source, confirmed: true })
        })
        .then(safeJson)
        .then(result => {
            btn.disabled = false;
            btn.textContent = `Fetch ${label} Data`;
            loadCovariateStatus();
            if (result.success) {
                let msg = result.message || 'Batch complete.';
                if (result.remaining_count > 0) {
                    msg += `\n\n${result.remaining_count} period(s) still remaining. Click Fetch again to continue.`;
                }
                if (result.errors && result.errors.length > 0) {
                    msg += `\n\nWarnings:\n${result.errors.join('\n')}`;
                }
                alert(`${label}:\n\n${msg}`);
            } else {
                alert(`${label} fetch failed:\n\n${result.error || JSON.stringify(result)}`);
            }
        })
        .catch(err => {
            btn.disabled = false;
            btn.textContent = `Fetch ${label} Data`;
            alert(`${label} fetch failed:\n\n${err.message}`);
        });
    })
    .catch(err => {
        btn.disabled = false;
        btn.textContent = `Fetch ${label} Data`;
        alert(`${label} check failed:\n\n${err.message}`);
    });
}

function fetchVIIRS()     { fetchCovariate(this, 'viirs',     'Artificial Light (VIIRS)'); }
function fetchMODIS()     { fetchCovariate(this, 'ndvi',      'Vegetation Index (MODIS)'); }
function fetchNOAATemp()  { fetchCovariate(this, 'land_temp', 'Land Surface Temperature (MODIS)'); }
function fetchNOAAPrecip(){ fetchCovariate(this, 'precip',    'Precipitation (CHIRPS)'); }

// Model switching
function switchModel(version) {
    const statusEl = document.getElementById('modelStatus');
    if (confirm(`Switch to model version ${version}? This will affect all predictions.`)) {
        statusEl.innerHTML = `<div class="alert alert-info">Switching to ${version}...</div>`;
        fetch('api/switch_model.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ version: version })
        })
            .then(r => r.json())
            .then(data => {
                if (!data.success) {
                    statusEl.innerHTML = `<div class="alert alert-danger">${data.error || 'Model switch failed.'}</div>`;
                    return;
                }
                statusEl.innerHTML = `<div class="alert alert-info">${data.message || `Switched to ${version}.`}</div>`;
                setTimeout(() => window.location.reload(), 700);
            })
            .catch(() => {
                statusEl.innerHTML = '<div class="alert alert-danger">Request failed. Check server connection.</div>';
            });
    }
}

// Save thresholds
function saveThresholds() {
    const payload = {
        high_risk:     document.getElementById('highRiskThreshold').value,
        mod_risk:      document.getElementById('modRiskThreshold').value,
        low_risk:      document.getElementById('lowRiskThreshold').value,
        critical_shap: document.getElementById('criticalShap').value,
        warning_shap:  document.getElementById('warningShap').value,
        positive_shap: document.getElementById('positiveShap').value
    };
    
    fetch('/api/save_thresholds.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
        .then(r => r.json())
        .then(data => alert(data.message || 'Thresholds saved.'))
        .catch(() => alert('Request failed. Check server connection.'));
}
</script>
EOD;

require_once 'includes/footer.php';
?>
