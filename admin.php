<?php
$page_title = 'Admin Panel';
require_once 'includes/auth.php';
require_admin(); // Require admin access
require_once 'includes/header.php';
?>

<div class="page-header">
    <h1 class="page-title">Admin & Staff Controls</h1>
    <p class="page-subtitle">Data management, model configuration, and system monitoring</p>
</div>

<!-- Data Ingestion -->
<div class="card">
    <h2 class="card-header">📁 Data Ingestion</h2>
    <div class="card-body">
        <h4>Upload Bird Observation Data</h4>
        <form id="dataUploadForm" enctype="multipart/form-data">
            <div class="form-group">
                <label class="form-label">Select CSV/Excel File:</label>
                <input type="file" class="form-control" id="dataFile" accept=".csv,.xlsx" required>
                <small style="color: #666;">
                    Accepted formats: CSV, XLSX. Max size: 50MB. 
                    Required columns: species_name, latitude, longitude, date, observer
                </small>
            </div>
            <button type="submit" class="btn btn-primary">Upload & Validate</button>
        </form>
        
        <div id="uploadStatus" style="margin-top: 15px;"></div>
    </div>
</div>

<!-- API Management -->
<div class="grid-2">
    <div class="card">
        <h2 class="card-header">🛰️ Satellite Data Fetch</h2>
        <div class="card-body">
            <h4>NASA VIIRS (Light Pollution)</h4>
            <button class="btn btn-primary" onclick="fetchVIIRS()">
                🔄 Fetch Latest VIIRS Data
            </button>
            <p style="margin-top: 10px; color: #666;">
                <strong>Last Fetch:</strong> 2026-01-28 14:30 UTC<br>
                <strong>Status:</strong> <span class="badge badge-success">Up to date</span>
            </p>
            
            <hr style="margin: 20px 0;">
            
            <h4>MODIS NDVI (Vegetation)</h4>
            <button class="btn btn-primary" onclick="fetchMODIS()">
                🔄 Fetch Latest MODIS Data
            </button>
            <p style="margin-top: 10px; color: #666;">
                <strong>Last Fetch:</strong> 2026-01-25 08:15 UTC<br>
                <strong>Status:</strong> <span class="badge badge-warning">Update Available</span>
            </p>
        </div>
    </div>
    
    <div class="card">
        <h2 class="card-header">🌡️ Weather Data (NOAA)</h2>
        <div class="card-body">
            <h4>Temperature & Precipitation</h4>
            <button class="btn btn-primary" onclick="fetchNOAA()">
                🔄 Fetch NOAA Climate Data
            </button>
            <p style="margin-top: 10px; color: #666;">
                <strong>Last Fetch:</strong> 2026-01-30 06:00 UTC<br>
                <strong>Status:</strong> <span class="badge badge-success">Up to date</span>
            </p>
            
            <div style="margin-top: 20px; padding: 15px; background: var(--bg-card-alt); border: 1px solid var(--border-color); border-radius: 8px;">
                <strong>Auto-Fetch Schedule:</strong>
                <ul style="margin-top: 10px;">
                    <li>VIIRS: Weekly (Mondays 02:00)</li>
                    <li>MODIS: Bi-weekly (1st & 15th)</li>
                    <li>NOAA: Daily (06:00 UTC)</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<!-- Model Versioning -->
<div class="card">
    <h2 class="card-header">🤖 Model Versioning & Management</h2>
    <div class="card-body">
        <div class="grid-2">
            <div>
                <h4>Upload New Model</h4>
                <form id="modelUploadForm">
                    <div class="form-group">
                        <label class="form-label">Model File:</label>
                        <input type="file" class="form-control" id="modelFile" accept=".pkl,.h5,.pth" required>
                        <small style="color: #666;">Supported: .pkl (XGBoost), .h5 (ConvLSTM), .pth (PyTorch)</small>
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
                        <tr>
                            <td><strong>v2.1.0</strong></td>
                            <td>2026-01-15</td>
                            <td><span class="badge badge-success">Active</span></td>
                            <td>-</td>
                        </tr>
                        <tr>
                            <td>v2.0.3</td>
                            <td>2025-12-10</td>
                            <td><span class="badge badge-info">Backup</span></td>
                            <td><button class="btn btn-secondary" style="padding: 4px 8px; font-size: 0.8rem;" onclick="switchModel('v2.0.3')">Switch</button></td>
                        </tr>
                        <tr>
                            <td>v2.0.2</td>
                            <td>2025-11-05</td>
                            <td><span class="badge badge-info">Archived</span></td>
                            <td><button class="btn btn-secondary" style="padding: 4px 8px; font-size: 0.8rem;" onclick="switchModel('v2.0.2')">Switch</button></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Threshold Configuration -->
<div class="card">
    <h2 class="card-header">⚙️ Threshold Configuration</h2>
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
        <button class="btn btn-primary" style="margin-top: 15px;" onclick="saveThresholds()">💾 Save Configuration</button>
    </div>
</div>

<!-- Validation & Error Logs -->
<div class="card">
    <h2 class="card-header">⚠️ Validation & Error Logs</h2>
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
        
        <h4 style="margin-top: 30px;">Spatial Integrity Checks</h4>
        <div style="padding: 15px; background: rgba(34, 197, 94, 0.1); border: 1px solid rgba(34, 197, 94, 0.3); border-radius: 8px; margin-top: 10px;">
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
        <h2 class="card-header">🔒 Security & Access Logs</h2>
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
        <h2 class="card-header">📊 System Health</h2>
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
    if (file.size > 50 * 1024 * 1024) {
        statusDiv.innerHTML = '<div class="alert alert-danger">File too large. Maximum size: 50MB</div>';
        return;
    }
    
    // Validate file type
    const validExtensions = ['.csv', '.xlsx'];
    const fileName = file.name.toLowerCase();
    if (!validExtensions.some(ext => fileName.endsWith(ext))) {
        statusDiv.innerHTML = '<div class="alert alert-danger">Invalid file type. Only CSV and XLSX allowed.</div>';
        return;
    }
    
    // Simulate upload
    statusDiv.innerHTML = '<div class="alert alert-info"><div class="loading"></div> Uploading and validating data...</div>';
    
    setTimeout(() => {
        statusDiv.innerHTML = `
            <div class="alert alert-info">
                <strong>✓ Upload Successful!</strong><br>
                File: ${file.name}<br>
                Size: ${(file.size / 1024).toFixed(2)} KB<br>
                Status: Validation in progress...<br>
                <em>In production, this would process the file via backend API</em>
            </div>
        `;
    }, 2000);
});

// Model upload form
document.getElementById('modelUploadForm').addEventListener('submit', function(e) {
    e.preventDefault();
    alert('Model upload initiated. In production, this would upload to model repository and trigger validation tests.');
});

// API fetch functions
function fetchVIIRS() {
    if (confirm('Fetch latest VIIRS data from NASA? This may take 5-10 minutes.')) {
        alert('Initiating VIIRS data fetch... In production, this would call NASA API and process satellite imagery.');
    }
}

function fetchMODIS() {
    if (confirm('Fetch latest MODIS NDVI data? This may take 5-10 minutes.')) {
        alert('Initiating MODIS data fetch... In production, this would call NASA MODIS API.');
    }
}

function fetchNOAA() {
    if (confirm('Fetch latest NOAA climate data?')) {
        alert('Initiating NOAA data fetch... In production, this would call NOAA API.');
    }
}

// Model switching
function switchModel(version) {
    if (confirm(`Switch to model version ${version}? This will affect all predictions.`)) {
        alert(`Switching to ${version}... In production, this would update the active model configuration.`);
    }
}

// Save thresholds
function saveThresholds() {
    const highRisk = document.getElementById('highRiskThreshold').value;
    const modRisk = document.getElementById('modRiskThreshold').value;
    const lowRisk = document.getElementById('lowRiskThreshold').value;
    const criticalShap = document.getElementById('criticalShap').value;
    const warningShap = document.getElementById('warningShap').value;
    const positiveShap = document.getElementById('positiveShap').value;
    
    alert(`Thresholds saved:\nHigh Risk: ${highRisk}\nMod Risk: ${modRisk}\nLow Risk: ${lowRisk}\nCritical SHAP: ${criticalShap}\nWarning SHAP: ${warningShap}\nPositive SHAP: ${positiveShap}\n\nIn production, these would be saved to configuration file.`);
}
</script>
EOD;

require_once 'includes/footer.php';
?>
