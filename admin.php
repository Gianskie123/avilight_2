<?php
$page_title = 'Admin Panel';
require_once 'includes/auth.php';
require_admin(); // Require admin access
require_once 'includes/db.php';

$is_it_admin = is_it_admin();

// ── Handle change-password POST ───────────────────────────────────────────
$pw_success = '';
$pw_error   = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_password') {
    $cur = $_POST['current_password'] ?? '';
    $new = $_POST['new_password']     ?? '';
    $cfm = $_POST['confirm_password'] ?? '';
    if ($new !== $cfm) {
        $pw_error = 'New passwords do not match.';
    } else {
        $result = change_password($cur, $new);
        if ($result === true) {
            $pw_success = 'Password changed successfully.';
        } else {
            $pw_error = $result;
        }
    }
}

// ── Handle add-user POST (admin only) ─────────────────────────────────────
$add_user_success = '';
$add_user_error   = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_user') {
    $nu_email = trim($_POST['new_user_email']    ?? '');
    $nu_pass  = trim($_POST['new_user_password'] ?? '');
    if (!filter_var($nu_email, FILTER_VALIDATE_EMAIL)) {
        $add_user_error = 'Please enter a valid email address.';
    } elseif (strlen($nu_pass) < 8) {
        $add_user_error = 'Password must be at least 8 characters.';
    } else {
        try {
            $pdo = get_mysql_db();
            _ensure_users_table($pdo);
            $pdo->prepare('INSERT INTO users (email, password_hash) VALUES (:email, :hash)')
                ->execute([
                    ':email' => $nu_email,
                    ':hash'  => password_hash($nu_pass, PASSWORD_BCRYPT),
                ]);
            $add_user_success = 'User ' . htmlspecialchars($nu_email) . ' added successfully.';
        } catch (Exception $e) {
            $add_user_error = str_contains($e->getMessage(), 'UNIQUE') ? 'That email is already registered.' : 'An unexpected error occurred. Please try again.';
        }
    }
}

// Validation log, spatial checks, and model list are loaded asynchronously via JS on DOMContentLoaded.

// ── Load saved thresholds from DB (falls back to defaults) ────────────────
$thresholds = [
    'high_risk'            => 60,
    'mod_risk'             => 40,
    'low_risk'             => 25,
    'kba_richness_weight'  => 15,
    'kba_sensitive_weight' => 15,
    'kba_ndvi_weight'      => 20,
    'kba_alan_weight'      => 15,
    'kba_lst_weight'       => 15,
    'kba_precip_weight'    => 10,
];
try {
    $cfg_db = get_mysql_db();
    $cfg_db->exec("CREATE TABLE IF NOT EXISTS system_settings (
        setting_key VARCHAR(100) PRIMARY KEY,
        setting_value TEXT NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $cfg_row = $cfg_db->query("SELECT setting_value FROM system_settings WHERE setting_key = 'thresholds' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($cfg_row) {
        $saved = json_decode($cfg_row['setting_value'], true);
        if (is_array($saved)) {
            $thresholds = array_merge($thresholds, $saved);
        }
    }
} catch (Throwable $_) {
    // Non-fatal — defaults are fine
}


require_once 'includes/header.php';
?>

<!-- Toast notification container -->
<div id="toastContainer" style="position:fixed;top:20px;right:20px;z-index:9999;display:flex;flex-direction:column;gap:10px;max-width:380px;"></div>

<!-- Confirmation modal — replaces native browser confirm() dialogs -->
<div id="avlConfirmModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:10001;overflow-y:auto;backdrop-filter:blur(2px);">
    <div style="max-width:480px;margin:100px auto 40px;background:var(--bg-card);border:1px solid var(--border-color);border-radius:12px;box-shadow:0 8px 32px rgba(0,0,0,0.25);overflow:hidden;">
        <div style="padding:6px 20px 0;border-bottom:1px solid var(--border-color);">
            <div style="display:flex;align-items:center;gap:12px;padding:16px 4px;">
                <div id="avlConfirmModalIconWrap" style="width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <span id="avlConfirmModalIcon" style="display:flex;align-items:center;justify-content:center;"></span>
                </div>
                <div>
                    <div id="avlConfirmModalTitle" style="font-weight:700;font-size:.95rem;color:var(--text-primary);"></div>
                    <div id="avlConfirmModalSubtitle" style="font-size:.78rem;color:var(--text-muted);margin-top:2px;"></div>
                </div>
            </div>
        </div>
        <div style="padding:20px 24px 24px;">
            <div id="avlConfirmModalBody" style="color:var(--text-secondary);font-size:.875rem;line-height:1.6;margin-bottom:20px;"></div>
            <div style="display:flex;gap:10px;">
                <button id="avlConfirmModalOk"
                    style="flex:1;padding:10px;border-radius:7px;border:none;cursor:pointer;font-size:.9rem;font-weight:600;color:#fff;transition:opacity .15s;"
                    onmouseover="this.style.opacity='.85'" onmouseout="this.style.opacity='1'">Proceed</button>
                <button id="avlConfirmModalCancel"
                    style="flex:1;padding:10px;border-radius:7px;border:1px solid var(--border-color);cursor:pointer;font-size:.9rem;background:var(--bg-card);color:var(--text-secondary);transition:background .15s;"
                    onmouseover="this.style.background='var(--bg-card-alt)'" onmouseout="this.style.background='var(--bg-card)'">Cancel</button>
            </div>
        </div>
    </div>
</div>

<!-- Edit User Modal -->
<div id="editUserModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:10001;overflow-y:auto;backdrop-filter:blur(2px);">
    <div style="max-width:480px;margin:100px auto 40px;background:var(--bg-card);border:1px solid var(--border-color);border-radius:12px;box-shadow:0 8px 32px rgba(0,0,0,0.25);overflow:hidden;">
        <div style="padding:6px 20px 0;border-bottom:1px solid var(--border-color);">
            <div style="display:flex;align-items:center;gap:12px;padding:16px 4px;">
                <div style="width:38px;height:38px;border-radius:50%;background:rgba(37,99,235,0.12);color:#2563eb;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                </div>
                <div>
                    <div style="font-weight:700;font-size:.95rem;color:var(--text-primary);">Edit Account</div>
                    <div id="editUserModalSubtitle" style="font-size:.78rem;color:var(--text-muted);margin-top:2px;"></div>
                </div>
            </div>
        </div>
        <div style="padding:20px 24px 24px;">
            <div style="display:flex;flex-direction:column;gap:14px;">
                <div>
                    <label class="form-label">Full Name</label>
                    <input type="text" id="editUserFullName" class="form-control" placeholder="Full name">
                </div>
                <div>
                    <label class="form-label">Email</label>
                    <input type="email" id="editUserEmail" class="form-control" placeholder="Email address">
                </div>
                <div>
                    <label class="form-label">Account Type</label>
                    <select id="editUserType" class="form-control">
                        <option value="EMS">EMS</option>
                        <option value="IT_admin">IT Admin</option>
                    </select>
                </div>
                <div>
                    <label class="form-label">New Password <small style="color:var(--text-muted);font-weight:400;">(leave blank to keep current)</small></label>
                    <input type="password" id="editUserPassword" class="form-control" placeholder="Min. 8 characters" autocomplete="new-password">
                </div>
            </div>
            <div id="editUserModalErr" style="color:#dc2626;font-size:.83rem;margin-top:10px;min-height:1.2em;"></div>
            <div style="display:flex;gap:10px;margin-top:16px;">
                <button id="editUserSaveBtn" onclick="submitEditUser()"
                    style="flex:1;padding:10px;border-radius:7px;border:none;cursor:pointer;font-size:.9rem;font-weight:600;color:#fff;background:#2563eb;transition:opacity .15s;"
                    onmouseover="this.style.opacity='.85'" onmouseout="this.style.opacity='1'">Save Changes</button>
                <button onclick="closeEditUserModal()"
                    style="flex:1;padding:10px;border-radius:7px;border:1px solid var(--border-color);cursor:pointer;font-size:.9rem;background:var(--bg-card);color:var(--text-secondary);transition:background .15s;"
                    onmouseover="this.style.background='var(--bg-card-alt)'" onmouseout="this.style.background='var(--bg-card)'">Cancel</button>
            </div>
        </div>
    </div>
</div>

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

        <hr style="margin: 24px 0;">

        <!-- Land Cover (annual) -->
        <h4>Land Cover Type (MODIS)</h4>
        <button class="btn btn-primary" onclick="fetchLandCover.call(this, this)">
            Fetch Land Cover Type (MODIS) Data
        </button>
        <p style="margin-top: 10px; color: #666;">
            <strong>Last Fetch:</strong> <span data-cov-fetch="land_cover">Loading...</span><br>
            <strong>Status:</strong> <span data-cov-status="land_cover" class="badge">Loading...</span>
        </p>

        <hr style="margin: 24px 0;">

        <!-- Merge to Master Grid -->
        <h4>Prepare Analysis Data</h4>
        <p style="margin: 0 0 10px; color: var(--text-secondary, #94a3b8); font-size: 13px;">
            One-click refresh of analysis data. It rebuilds bird summaries first, then rebuilds the analysis dataset used by dashboards and reports.<br><br>
            <strong>Press this button whenever:</strong>
            <ul style="margin: 6px 0 0 16px; padding: 0; font-size: 13px;">
                <li>New bird observation records have been uploaded <em>and</em> environmental data for the same year is already fetched</li>
                <li>A bird species' category (light tolerance or migratory status) has been changed</li>
            </ul>
        </p>
        <button class="btn btn-success" id="buildMasterGridBtn" onclick="buildMasterGrid.call(this, this)">
            Rebuild Analysis Data
        </button>
        <div id="masterGridStatus" style="margin-top: 10px;"></div>

        <hr style="margin: 24px 0;">

        <!-- BAU Cache Reset -->
        <h4>BAU Prediction Cache</h4>
        <p style="margin: 0 0 10px; color: var(--text-secondary, #94a3b8); font-size: 13px;">
            Clears all precomputed Business-As-Usual baseline values. Use after manually loading
            <code>city_cells_data.sql</code> or running the monthly summary population scripts,
            to force a fresh recompute on the next forecast request.
        </p>
        <button class="btn btn-warning" id="clearBauCacheBtn" onclick="clearBauCache.call(this, this)">
            Reset BAU Cache
        </button>
        <div id="bauCacheStatus" style="margin-top: 10px;"></div>

    </div>
</div>

<?php if ($is_it_admin): ?>
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
                    <tbody id="modelListBody">
                        <tr><td colspan="4" style="text-align:center;color:#888;padding:16px;">Loading…</td></tr>
                    </tbody>
                </table>
                <div id="modelStatus" style="margin-top: 10px;"></div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Threshold Configuration -->
<div class="card" id="threshold-config">
    <h2 class="card-header">Threshold Configuration</h2>
    <div class="card-body">

        <h4 style="margin-bottom:14px;">Danger Zone Color Scales</h4>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;margin-bottom:24px;">
            <div class="form-group" style="margin-bottom:0;">
                <label class="form-label">High Risk Threshold</label>
                <input type="number" class="form-control" value="<?= htmlspecialchars($thresholds['high_risk']) ?>" min="0" max="100" id="highRiskThreshold">
                <small style="color:#666;">Light intensity above this = high risk</small>
            </div>
            <div class="form-group" style="margin-bottom:0;">
                <label class="form-label">Moderate Risk Threshold</label>
                <input type="number" class="form-control" value="<?= htmlspecialchars($thresholds['mod_risk']) ?>" min="0" max="100" id="modRiskThreshold">
                <small style="color:#666;">Above this = moderate risk</small>
            </div>
            <div class="form-group" style="margin-bottom:0;">
                <label class="form-label">Low Risk Threshold</label>
                <input type="number" class="form-control" value="<?= htmlspecialchars($thresholds['low_risk']) ?>" min="0" max="100" id="lowRiskThreshold">
                <small style="color:#666;">Above this = low risk</small>
            </div>
        </div>

        <hr style="margin:20px 0;">

        <h4 style="margin-bottom:6px;">KBA/PA Audit Effectiveness Weights</h4>
        <p style="margin:0 0 14px 0;color:#666;font-size:0.875rem;">
            Weights for the 6-pillar KBA/PA audit score. Total should equal 100%.
        </p>

        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:16px;">
            <div class="form-group" style="margin-bottom:0;">
                <label class="form-label">Richness Weight (%)</label>
                <input type="number" class="form-control" value="<?= htmlspecialchars($thresholds['kba_richness_weight']) ?>" min="0" max="100" step="0.1" id="kbaRichnessWeight">
            </div>
            <div class="form-group" style="margin-bottom:0;">
                <label class="form-label">Sensitive Species Weight (%)</label>
                <input type="number" class="form-control" value="<?= htmlspecialchars($thresholds['kba_sensitive_weight']) ?>" min="0" max="100" step="0.1" id="kbaSensitiveWeight">
            </div>
            <div class="form-group" style="margin-bottom:0;">
                <label class="form-label">NDVI Weight (%)</label>
                <input type="number" class="form-control" value="<?= htmlspecialchars($thresholds['kba_ndvi_weight']) ?>" min="0" max="100" step="0.1" id="kbaNdviWeight">
            </div>
            <div class="form-group" style="margin-bottom:0;">
                <label class="form-label">ALAN Weight (%)</label>
                <input type="number" class="form-control" value="<?= htmlspecialchars($thresholds['kba_alan_weight']) ?>" min="0" max="100" step="0.1" id="kbaAlanWeight">
            </div>
            <div class="form-group" style="margin-bottom:0;">
                <label class="form-label">LST Weight (%)</label>
                <input type="number" class="form-control" value="<?= htmlspecialchars($thresholds['kba_lst_weight']) ?>" min="0" max="100" step="0.1" id="kbaLstWeight">
            </div>
            <div class="form-group" style="margin-bottom:0;">
                <label class="form-label">Precipitation Weight (%)</label>
                <input type="number" class="form-control" value="<?= htmlspecialchars($thresholds['kba_precip_weight']) ?>" min="0" max="100" step="0.1" id="kbaPrecipWeight">
            </div>
        </div>

        <div style="display:flex;align-items:center;gap:16px;padding:10px 14px;background:var(--bg-card-alt);border:1px solid var(--border-color);border-radius:8px;margin-bottom:16px;">
            <span style="font-size:0.875rem;color:var(--text-secondary);">Total Weight</span>
            <input type="text" id="kbaWeightTotal" readonly
                   style="width:80px;padding:5px 10px;border:1px solid var(--border-color);border-radius:6px;background:transparent;font-weight:600;text-align:center;color:var(--text-primary);">
            <small style="color:#666;">Should equal 100%</small>
        </div>

        <button class="btn btn-primary" onclick="saveThresholds()">Save Configuration</button>
    </div>
</div>

<!-- Validation & Error Logs -->
<div class="card">
    <h2 class="card-header">Validation & Error Logs</h2>
    <div class="card-body">

        <h4>Ingestion Summary &mdash; Last 24 Hours</h4>
        <table>
            <thead>
                <tr>
                    <th>Timestamp</th>
                    <th>Uploaded By</th>
                    <th>Category</th>
                    <th>Details</th>
                    <th style="text-align:right;">Count</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody id="validationLogBody">
                <tr><td colspan="6" style="text-align:center;color:#888;padding:16px;">Loading…</td></tr>
            </tbody>
        </table>


    </div>
</div>

<!-- Spatial Integrity Checks -->
<div class="card">
    <h2 class="card-header">Spatial Integrity Checks</h2>
    <div class="card-body" id="spatialChecksBody">
        <div style="text-align:center;color:#888;padding:16px;">Loading…</div>
    </div>
</div>

<?php if ($is_it_admin): ?>
<!-- Account Management -->
<div class="card">
    <h2 class="card-header">Account Management</h2>
    <div class="card-body">

        <!-- Add new user -->
        <details id="addUserDetails" style="margin-bottom:20px;">
            <summary style="cursor:pointer;font-size:0.9rem;color:var(--accent-blue,#3b82f6);margin-bottom:12px;">+ Add new account</summary>
            <div id="addUserStatus" style="margin-bottom:8px;"></div>
            <form id="addUserForm" style="margin-top:10px;display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label">Full Name</label>
                    <input type="text" id="newUserFullName" class="form-control" required>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label">Email</label>
                    <input type="email" id="newUserEmail" class="form-control" required>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label">Password <small style="color:#666;">(min. 8 characters)</small></label>
                    <input type="password" id="newUserPassword" class="form-control" minlength="8" required>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label">Account Type</label>
                    <select id="newUserType" class="form-control" required>
                        <option value="EMS">EMS</option>
                        <option value="IT_admin">IT Admin</option>
                    </select>
                </div>
                <div style="grid-column:1/-1;">
                    <button type="submit" class="btn btn-primary" id="addUserBtn">Add Account</button>
                </div>
            </form>
        </details>

        <!-- User list -->
        <div style="overflow-x:auto; margin-bottom:24px;">
            <table style="font-size:0.88rem; width:100%;" id="userListTable">
                <thead>
                    <tr>
                        <th onclick="sortUserList('name')" style="cursor:pointer;user-select:none;white-space:nowrap;">Name <span id="userSortName" style="font-size:0.72rem;opacity:0.45;">↕</span></th>
                        <th onclick="sortUserList('email')" style="cursor:pointer;user-select:none;white-space:nowrap;">Email <span id="userSortEmail" style="font-size:0.72rem;opacity:0.45;">↕</span></th>
                        <th onclick="sortUserList('role')" style="cursor:pointer;user-select:none;white-space:nowrap;">Role <span id="userSortRole" style="font-size:0.72rem;opacity:0.45;">↕</span></th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="userListBody">
                    <tr><td colspan="5" style="text-align:center;color:#888;padding:16px;">Loading…</td></tr>
                </tbody>
            </table>
        </div>

        <div id="manageUserStatus" style="margin-top:12px;"></div>
    </div>
</div>
<?php endif; ?>

<?php if ($is_it_admin): ?>
<!-- Security & Activity Logs -->
<div class="card">
    <h2 class="card-header">Security & Access Logs</h2>
    <div class="card-body">
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:32px;">

            <!-- Recent Activity -->
            <div>
                <h4 style="margin-bottom:10px;">Recent Activity</h4>
                <div style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin-bottom:10px;">
                    <label style="font-size:0.78rem;color:var(--text-secondary);white-space:nowrap;">User Action</label>
                    <select id="accessLogAction" style="font-size:0.8rem;padding:3px 8px;border-radius:6px;border:1px solid var(--border-color,#334155);background:var(--bg-input,#1e293b);color:var(--text-primary,#f1f5f9);">
                        <option value="">All Actions</option>
                        <option value="login">Login</option>
                        <option value="logout">Logout</option>
                        <option value="add_user">Add User</option>
                        <option value="edit_user">Edit User</option>
                        <option value="activate_user">Activate User</option>
                        <option value="deactivate_user">Deactivate User</option>
                        <option value="change_password">Change Password</option>
                        <option value="delete_user">Delete User</option>
                    </select>
                    <label style="font-size:0.78rem;color:var(--text-secondary);white-space:nowrap;margin-left:4px;">Start Date</label>
                    <input type="date" id="accessLogFrom" style="font-size:0.8rem;padding:3px 8px;border-radius:6px;border:1px solid var(--border-color,#334155);background:var(--bg-input,#1e293b);color:var(--text-primary,#f1f5f9);">
                    <label style="font-size:0.78rem;color:var(--text-secondary);white-space:nowrap;">End Date</label>
                    <input type="date" id="accessLogTo" style="font-size:0.8rem;padding:3px 8px;border-radius:6px;border:1px solid var(--border-color,#334155);background:var(--bg-input,#1e293b);color:var(--text-primary,#f1f5f9);">
                    <button onclick="loadAuditLog()" class="btn btn-primary" style="padding:3px 10px;font-size:0.8rem;">Apply</button>
                    <button onclick="clearAuditFilters()" style="background:#7c3aed;border:none;border-radius:6px;color:#fff;font-size:0.8rem;font-weight:600;cursor:pointer;padding:3px 10px;transition:opacity .15s;" onmouseover="this.style.opacity='.8'" onmouseout="this.style.opacity='1'">Clear</button>
                </div>
                <div style="overflow-x:auto;">
                    <table style="font-size:0.85rem; width:100%;">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Action</th>
                                <th>IP</th>
                                <th style="white-space:nowrap;">Time</th>
                            </tr>
                        </thead>
                        <tbody id="accessLogBody">
                            <tr><td colspan="4" style="color:#94a3b8; text-align:center; padding:12px;">Loading…</td></tr>
                        </tbody>
                    </table>
                </div>
                <div id="accessLogPager"></div>
            </div>

            <!-- Failed Login Attempts -->
            <div>
                <h4 style="margin-bottom:10px;">Failed Login Attempts</h4>
                <div style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin-bottom:10px;">
                    <label style="font-size:0.78rem;color:var(--text-secondary);white-space:nowrap;">Start Date</label>
                    <input type="date" id="failLogFrom" style="font-size:0.8rem;padding:3px 8px;border-radius:6px;border:1px solid var(--border-color,#334155);background:var(--bg-input,#1e293b);color:var(--text-primary,#f1f5f9);">
                    <label style="font-size:0.78rem;color:var(--text-secondary);white-space:nowrap;">End Date</label>
                    <input type="date" id="failLogTo" style="font-size:0.8rem;padding:3px 8px;border-radius:6px;border:1px solid var(--border-color,#334155);background:var(--bg-input,#1e293b);color:var(--text-primary,#f1f5f9);">
                    <button onclick="loadFailedAttempts()" class="btn btn-primary" style="padding:3px 10px;font-size:0.8rem;">Apply</button>
                    <button onclick="clearFailFilters()" style="background:#7c3aed;border:none;border-radius:6px;color:#fff;font-size:0.8rem;font-weight:600;cursor:pointer;padding:3px 10px;transition:opacity .15s;" onmouseover="this.style.opacity='.8'" onmouseout="this.style.opacity='1'">Clear</button>
                </div>
                <div style="overflow-x:auto;">
                    <table style="font-size:0.85rem; width:100%;">
                        <thead>
                            <tr>
                                <th>Email</th>
                                <th>IP</th>
                                <th style="white-space:nowrap;">Time</th>
                            </tr>
                        </thead>
                        <tbody id="failLogBody">
                            <tr><td colspan="3" style="color:#94a3b8; text-align:center; padding:12px;">Loading…</td></tr>
                        </tbody>
                    </table>
                </div>
                <div id="failLogPager"></div>
            </div>

        </div>
    </div>
</div>
<?php endif; ?>

<?php
$extra_scripts = <<<'EOD'
<style>
@keyframes toastIn { from { opacity:0; transform:translateX(30px); } to { opacity:1; transform:translateX(0); } }
.avl-toast { transition: opacity .3s ease; }
</style>
<script>
// ── Audit log loaders (paginated) ────────────────────────────────────────────
let _auditPage = 1;
let _failPage  = 1;

function loadAuditLog(page) {
    _auditPage = page || 1;
    const action   = document.getElementById('accessLogAction')?.value || '';
    const dateFrom = document.getElementById('accessLogFrom')?.value   || '';
    const dateTo   = document.getElementById('accessLogTo')?.value     || '';
    const tbody    = document.getElementById('accessLogBody');
    if (!tbody) return;
    tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;color:#94a3b8;padding:12px;">Loading…</td></tr>';

    const params = new URLSearchParams({ type: 'activity', page: _auditPage });
    if (action)   params.set('action',    action);
    if (dateFrom) params.set('date_from', dateFrom);
    if (dateTo)   params.set('date_to',   dateTo);

    fetch('api/get_audit_logs.php?' + params)
        .then(r => r.json())
        .then(data => {
            if (!data.success || !data.rows.length) {
                tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;color:#94a3b8;padding:12px;">No records found.</td></tr>';
                renderPager('accessLogPager', 1, 1, null);
                return;
            }
            tbody.innerHTML = data.rows.map(r => `
                <tr>
                    <td style="max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${escHtml(r.email)}">${escHtml(r.email)}</td>
                    <td style="font-size:0.8rem;">${escHtml(r.action.charAt(0).toUpperCase() + r.action.slice(1))}</td>
                    <td style="color:#64748b;font-size:0.8rem;">${escHtml(r.ip_address)}</td>
                    <td style="color:#64748b;font-size:0.8rem;white-space:nowrap;">${escHtml(formatUtcToManila(r.logged_at))}</td>
                </tr>`).join('');
            renderPager('accessLogPager', data.page, data.total_pages, 'loadAuditLog');
        })
        .catch(() => {
            tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;color:#f87171;padding:12px;">Failed to load logs.</td></tr>';
        });
}

function loadFailedAttempts(page) {
    _failPage = page || 1;
    const dateFrom = document.getElementById('failLogFrom')?.value || '';
    const dateTo   = document.getElementById('failLogTo')?.value   || '';
    const tbody    = document.getElementById('failLogBody');
    if (!tbody) return;
    tbody.innerHTML = '<tr><td colspan="3" style="text-align:center;color:#94a3b8;padding:12px;">Loading…</td></tr>';

    const params = new URLSearchParams({ type: 'failures', page: _failPage });
    if (dateFrom) params.set('date_from', dateFrom);
    if (dateTo)   params.set('date_to',   dateTo);

    fetch('api/get_audit_logs.php?' + params)
        .then(r => r.json())
        .then(data => {
            if (!data.success || !data.rows.length) {
                tbody.innerHTML = '<tr><td colspan="3" style="text-align:center;color:#94a3b8;padding:12px;">No failed attempts recorded.</td></tr>';
                renderPager('failLogPager', 1, 1, null);
                return;
            }
            tbody.innerHTML = data.rows.map(r => `
                <tr>
                    <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${escHtml(r.email)}">${escHtml(r.email)}</td>
                    <td style="color:#64748b;font-size:0.8rem;">${escHtml(r.ip_address)}</td>
                    <td style="color:#64748b;font-size:0.8rem;white-space:nowrap;">${escHtml(formatUtcToManila(r.attempted_at))}</td>
                </tr>`).join('');
            renderPager('failLogPager', data.page, data.total_pages, 'loadFailedAttempts');
        })
        .catch(() => {
            tbody.innerHTML = '<tr><td colspan="3" style="text-align:center;color:#f87171;padding:12px;">Failed to load logs.</td></tr>';
        });
}

function clearAuditFilters() {
    document.getElementById('accessLogAction').value = '';
    document.getElementById('accessLogFrom').value   = '';
    document.getElementById('accessLogTo').value     = '';
    loadAuditLog(1);
}

function clearFailFilters() {
    document.getElementById('failLogFrom').value = '';
    document.getElementById('failLogTo').value   = '';
    loadFailedAttempts(1);
}

function renderPager(containerId, page, totalPages, fnName) {
    const el = document.getElementById(containerId);
    if (!el) return;
    if (!fnName || totalPages <= 1) { el.innerHTML = ''; return; }

    const btn = (label, p, disabled) => {
        const base = 'border-radius:4px;padding:2px 9px;font-size:0.8rem;cursor:pointer;line-height:1.6;';
        if (disabled) return `<button disabled style="${base}background:none;border:1px solid var(--border-color,#334155);color:#64748b;opacity:0.4;cursor:default;">${label}</button>`;
        if (p === page) return `<span style="${base}background:var(--accent-blue,#3b82f6);border:1px solid var(--accent-blue,#3b82f6);color:#fff;font-weight:600;">${label}</span>`;
        return `<button onclick="${fnName}(${p})" style="${base}background:none;border:1px solid var(--border-color,#334155);color:var(--text-primary,#f1f5f9);">${label}</button>`;
    };

    // Build page number list with ellipsis for large ranges
    let pages = [];
    if (totalPages <= 7) {
        pages = Array.from({length: totalPages}, (_, i) => i + 1);
    } else {
        const set = new Set([1, totalPages, page]);
        if (page > 1) set.add(page - 1);
        if (page < totalPages) set.add(page + 1);
        pages = [...set].sort((a, b) => a - b);
    }

    let html = '<div style="display:flex;align-items:center;gap:3px;justify-content:center;margin-top:8px;flex-wrap:wrap;">';
    html += btn('&#8592;', page - 1, page === 1);

    let prev = 0;
    for (const p of pages) {
        if (prev && p - prev > 1) html += '<span style="color:#64748b;font-size:0.8rem;padding:0 2px;">…</span>';
        html += btn(p, p, false);
        prev = p;
    }

    html += btn('&#8594;', page + 1, page === totalPages);
    html += '</div>';
    el.innerHTML = html;
}

// ── Shared SVG icon constants ─────────────────────────────────────────────────

const ICON_CLOUD   = `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="8 17 12 21 16 17"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.88 18.09A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.29"/></svg>`;
const ICON_REBUILD = `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/></svg>`;
const ICON_SWITCH  = `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>`;
const ICON_PENCIL  = `<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>`;
const ICON_BLOCK   = `<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.582-7 8-7"/><line x1="17" y1="14" x2="23" y2="20"/><line x1="23" y1="14" x2="17" y2="20"/></svg>`;
const ICON_PERSON_OK = `<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="10" cy="8" r="4"/><path d="M2 20c0-4 3.582-7 8-7"/><polyline points="16 18 18 20 22 16"/></svg>`;
const ICON_TRASH   = `<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>`;

const _userCache = new Map();

// ── Confirmation modal (replaces native confirm()) ────────────────────────────

function showConfirmModal({ title, subtitle, iconSvg, iconBg, iconColor, body, confirmText, confirmBg }) {
    return new Promise(resolve => {
        const modal     = document.getElementById('avlConfirmModal');
        const titleEl   = document.getElementById('avlConfirmModalTitle');
        const subEl     = document.getElementById('avlConfirmModalSubtitle');
        const iconWrap  = document.getElementById('avlConfirmModalIconWrap');
        const iconEl    = document.getElementById('avlConfirmModalIcon');
        const bodyEl    = document.getElementById('avlConfirmModalBody');
        const okBtn     = document.getElementById('avlConfirmModalOk');
        const cancelBtn = document.getElementById('avlConfirmModalCancel');

        titleEl.textContent       = title    || '';
        subEl.textContent         = subtitle || '';
        iconWrap.style.background = iconBg    || 'rgba(37,99,235,0.12)';
        iconWrap.style.color      = iconColor || '#2563eb';
        iconEl.innerHTML          = iconSvg   || '';
        bodyEl.innerHTML          = body      || '';
        okBtn.textContent         = confirmText || 'Proceed';
        okBtn.style.background    = confirmBg   || 'var(--accent-blue,#2563eb)';

        modal.style.display = 'block';

        function close(result) {
            modal.style.display = 'none';
            okBtn.removeEventListener('click',     onOk);
            cancelBtn.removeEventListener('click', onCancel);
            modal.removeEventListener('click',     onBackdrop);
            resolve(result);
        }
        const onOk       = ()  => close(true);
        const onCancel   = ()  => close(false);
        const onBackdrop = (e) => { if (e.target === modal) close(false); };

        okBtn.addEventListener('click',     onOk);
        cancelBtn.addEventListener('click', onCancel);
        modal.addEventListener('click',     onBackdrop);
    });
}

// ── Timezone helpers (all stored timestamps are UTC, display in PHT) ──────────

function formatUtcToManila(ts) {
    if (!ts) return '—';
    try {
        const str = ts.includes('T') ? ts : ts.replace(' ', 'T');
        const d   = new Date(str.endsWith('Z') ? str : str + 'Z');
        if (isNaN(d.getTime())) return ts.substring(0, 16);
        return d.toLocaleString('en-PH', {
            timeZone: 'Asia/Manila',
            year: 'numeric', month: 'short', day: 'numeric',
            hour: '2-digit', minute: '2-digit', hour12: false
        }) + ' PHT';
    } catch (_) { return ts.substring(0, 16); }
}

// ── Toast notifications ───────────────────────────────────────────────────────

function showToast(title, lines, type = 'info') {
    const meta = {
        success: {
            border: '#16a34a', circleBg: 'rgba(22,163,74,0.12)', iconColor: '#16a34a',
            icon: `<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>`
        },
        danger: {
            border: '#dc2626', circleBg: 'rgba(220,38,38,0.12)', iconColor: '#dc2626',
            icon: `<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>`
        },
        warning: {
            border: '#ca8a04', circleBg: 'rgba(202,138,4,0.12)', iconColor: '#ca8a04',
            icon: `<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>`
        },
        info: {
            border: '#2563eb', circleBg: 'rgba(37,99,235,0.12)', iconColor: '#2563eb',
            icon: `<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>`
        },
    };
    const m = meta[type] || meta.info;
    const bodyLines = (Array.isArray(lines) ? lines : [lines]).filter(Boolean);

    const toast = document.createElement('div');
    toast.setAttribute('data-toast', '1');
    toast.className = 'avl-toast';
    toast.style.cssText = `background:var(--bg-card,#fff);border:1px solid var(--border-color,#dde1ea);border-left:4px solid ${m.border};border-radius:10px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,0.12);animation:toastIn .2s ease;`;
    toast.innerHTML = `
        <div style="padding:14px 16px;display:flex;align-items:flex-start;gap:12px;">
            <div style="width:32px;height:32px;border-radius:50%;background:${m.circleBg};display:flex;align-items:center;justify-content:center;flex-shrink:0;color:${m.iconColor};">${m.icon}</div>
            <div style="flex:1;min-width:0;">
                <div style="font-weight:600;font-size:.88rem;color:var(--text-primary);line-height:1.3;">${title}</div>
                ${bodyLines.map(l => `<div style="font-size:.8rem;color:var(--text-secondary,#5c6278);margin-top:3px;line-height:1.4;">${l}</div>`).join('')}
            </div>
            <button onclick="this.closest('[data-toast]').remove()" style="background:none;border:none;color:var(--text-muted,#9ca3af);cursor:pointer;font-size:1.1rem;line-height:1;padding:0;flex-shrink:0;margin-top:-2px;">×</button>
        </div>`;

    document.getElementById('toastContainer').appendChild(toast);
    setTimeout(() => { if (toast.parentNode) toast.style.opacity = '0'; }, 4700);
    setTimeout(() => { if (toast.parentNode) toast.remove(); }, 5000);
}

// ── Validation log loader ─────────────────────────────────────────────────────

const REASON_META = {
    out_of_bounds:     ['warning',   'Spatial',   (n, f) => `${n} observation(s) outside Metro Manila bounding box (lat 14.3–14.8 / lon 120.9–121.15)`,    'Rejected', 'danger'],
    missing_fields:    ['info',      'Format',    (n, f) => `${n} row(s) missing GLOBAL UNIQUE IDENTIFIER or COMMON NAME`,                                  'Rejected', 'danger'],
    uncertain_species: ['warning',   'Species',   (n, f) => `${n} uncertain species record(s) (contains " sp." or "/")`,                                   'Rejected', 'danger'],
    invalid_date:      ['info',      'Format',    (n, f) => `${n} row(s) with unparseable OBSERVATION DATE`,                                                'Resolved', 'success'],
    duplicate_in_file: ['secondary', 'Duplicate', (n, f) => `${n} duplicate GLOBAL UNIQUE IDENTIFIER(s) removed within the file`,                          'Cleaned',  'success'],
    duplicate_in_db:   ['danger',    'Duplicate', (n, f) => `${n} observation ID(s) already exist in the database — upload was aborted and rolled back`,    'Aborted',  'danger'],
};

function loadValidationLog() {
    fetch('api/get_validation_log.php')
        .then(r => r.json())
        .then(data => {
            const tbody = document.getElementById('validationLogBody');

            if (!data.success) {
                const msg = `⚠ Could not load validation log${data.error ? ': ' + data.error : '.'}`;
                if (tbody) tbody.innerHTML = `<tr><td colspan="6" style="text-align:center;color:#f87171;padding:16px;">${msg}</td></tr>`;
                return;
            }

            if (!data.uploads || !data.uploads.length) {
                if (tbody) tbody.innerHTML = `<tr><td colspan="6" style="text-align:center;color:#888;padding:16px;">No uploads in the last 24 hours.</td></tr>`;
                return;
            }

            const rows = [];
            for (const upload of data.uploads) {
                const fname      = (upload.filename || '').split(/[\\/]/).pop() || '—';
                const statusMap  = { success: ['badge-success', 'Success'], partial: ['badge-warning', 'Partial'], failed: ['badge-danger', 'Failed'] };
                const [sBadge, sLabel] = statusMap[upload.status] || ['badge-secondary', upload.status || '—'];
                const ins   = Number(upload.rows_inserted || 0).toLocaleString();
                const skip  = Number(upload.rows_skipped  || 0).toLocaleString();
                const tot   = Number(upload.rows_total    || 0).toLocaleString();
                const newSp = Number(upload.new_species   || 0);

                // Upload summary row
                rows.push(`<tr style="border-top:2px solid var(--border-color,#334155);">
                    <td style="white-space:nowrap;font-size:0.83rem;vertical-align:top;">${escHtml(upload.uploaded_at ? formatUtcToManila(upload.uploaded_at) : '—')}</td>
                    <td style="font-size:0.83rem;vertical-align:top;">${upload.uploaded_by || '—'}</td>
                    <td style="vertical-align:top;"><span class="badge badge-info">Upload</span></td>
                    <td style="font-size:0.85rem;">
                        <strong>${fname}</strong><br>
                        <span style="color:var(--text-secondary,#94a3b8);font-size:0.8rem;">
                            Total: ${tot} &nbsp;&middot;&nbsp;
                            Inserted: <strong style="color:#4ade80;">${ins}</strong> &nbsp;&middot;&nbsp;
                            Skipped: <strong style="color:#fbbf24;">${skip}</strong>
                            ${newSp > 0 ? ` &nbsp;&middot;&nbsp; New species: <strong style="color:#60a5fa;">${newSp}</strong>` : ''}
                        </span>
                        ${upload.fail_reason ? `<br><span style="color:#f87171;font-size:0.78rem;">Reason: ${upload.fail_reason}</span>` : ''}
                    </td>
                    <td style="text-align:right;vertical-align:top;font-size:0.83rem;">${tot}</td>
                    <td style="vertical-align:top;"><span class="badge ${sBadge}">${sLabel}</span></td>
                </tr>`);

                // Per-reason rejection sub-rows
                const rejections = upload.rejections || {};
                for (const [reason, cnt] of Object.entries(rejections)) {
                    const meta = REASON_META[reason] || ['secondary', reason, (n) => `${n} issue(s)`, 'Rejected', 'danger'];
                    const [typeBadge, typeLabel, issueFn, statusLbl, rBadge] = meta;
                    rows.push(`<tr style="background:rgba(0,0,0,0.04);">
                        <td></td>
                        <td></td>
                        <td><span class="badge badge-${typeBadge}">${typeLabel}</span></td>
                        <td style="font-size:0.82rem;color:var(--text-secondary,#94a3b8);">&#8627; ${issueFn(cnt, fname)}</td>
                        <td style="text-align:right;color:#fbbf24;font-size:0.83rem;">${Number(cnt).toLocaleString()}</td>
                        <td><span class="badge badge-${rBadge}">${statusLbl}</span></td>
                    </tr>`);
                }
            }

            tbody.innerHTML = rows.join('');
        })
        .catch(err => {
            const tbody = document.getElementById('validationLogBody');
            if (tbody) tbody.innerHTML = `<tr><td colspan="6" style="text-align:center;color:#f87171;padding:16px;">
                Network error: ${err.message}</td></tr>`;
        });
}

// ── Spatial checks loader ────────────────────────────────────────────────────

function loadSpatialChecks() {
    fetch('api/get_spatial_checks.php')
        .then(r => r.json())
        .then(data => {
            const body = document.getElementById('spatialChecksBody');
            if (!body) return;
            if (!data.success) {
                body.innerHTML = `<div class="alert alert-warning">Database unavailable — spatial checks could not be run.</div>`;
                return;
            }
            if (!data.checks.length) {
                body.innerHTML = `<div class="alert alert-info">No observation data found. Upload bird observation data to run spatial checks.</div>`;
                return;
            }
            const allPass = data.checks.every(c => c.pass);
            const failCount = data.checks.filter(c => !c.pass).length;
            const banner = allPass
                ? `<div style="padding:12px 16px;background:rgba(34,197,94,0.1);border:1px solid rgba(34,197,94,0.3);border-radius:8px;margin-bottom:14px;"><strong style="color:#4ade80;">✓ All spatial integrity checks passed.</strong></div>`
                : `<div style="padding:12px 16px;background:rgba(248,113,113,0.1);border:1px solid rgba(248,113,113,0.3);border-radius:8px;margin-bottom:14px;"><strong style="color:#f87171;">⚠ ${failCount} check(s) failed — review the issues below.</strong></div>`;
            const rows = data.checks.map(c => `<tr>
                <td>${c.label}</td>
                <td><code style="font-size:0.8rem;">${c.table}</code></td>
                <td><span class="badge ${c.pass ? 'badge-success' : 'badge-danger'}">${c.pass ? '✓ Pass' : '✗ Fail'}</span></td>
                <td style="font-size:0.875rem;color:${c.pass ? '#4ade80' : '#f87171'};">${c.pass ? 'OK' : (c.detail || 'Issue detected')}</td>
            </tr>`).join('');
            body.innerHTML = banner + `<table><thead><tr><th>Check</th><th>Table</th><th>Result</th><th>Detail</th></tr></thead><tbody>${rows}</tbody></table>`;
        })
        .catch(() => {});
}

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
    return formatUtcToManila(ingestedAt);
}

function loadCovariateStatus() {
    fetch('api/covariate_status.php?t=' + Date.now())
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                ['viirs','ndvi','land_temp','precip','land_cover'].forEach(key => {
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
            ['viirs','ndvi','land_temp','precip','land_cover'].forEach(key => {
                document.querySelector(`[data-cov-fetch="${key}"]`).textContent = 'Unavailable';
                const s = document.querySelector(`[data-cov-status="${key}"]`);
                s.textContent = 'Unavailable';
                s.className = 'badge badge-warning';
            });
        });
}

// Load on page ready — staggered by priority to avoid session-lock contention
// and to keep the critical parts of the UI fast.
document.addEventListener('DOMContentLoaded', () => {
    // Tier 1 — critical, load immediately
    loadCovariateStatus();
    loadUserList();
    loadModelList();

    // Tier 2 — important but not above the fold on first glance
    setTimeout(() => {
        loadValidationLog();
        loadSpatialChecks();
    }, 250);

    // Tier 3 — audit logs are below the fold; load last
    setTimeout(() => {
        loadAuditLog();
        loadFailedAttempts();
    }, 500);
});
setInterval(loadCovariateStatus, 60000);

// ── Data upload form ──────────────────────────────────────────────────────────
// Data upload form
document.getElementById('dataUploadForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const fileInput = document.getElementById('dataFile');
    const statusDiv = document.getElementById('uploadStatus');
    
    if (fileInput.files.length === 0) {
        showToast('No File Selected', ['Please select a CSV or XLSX file before uploading.'], 'warning');
        return;
    }

    const file = fileInput.files[0];

    // Validate file size
    if (file.size > 150 * 1024 * 1024) {
        showToast('File Too Large', ['File exceeds the 150 MB limit. Please reduce the file size and try again.'], 'danger');
        return;
    }

    // Validate file type
    const validExtensions = ['.csv', '.xlsx'];
    const fileName = file.name.toLowerCase();
    if (!validExtensions.some(ext => fileName.endsWith(ext))) {
        showToast('Invalid File Type', ['Only CSV and XLSX files are accepted.'], 'danger');
        return;
    }

    const submitBtn = this.querySelector('button[type="submit"]');
    submitBtn.disabled = true;
    submitBtn.textContent = 'Uploading…';
    statusDiv.innerHTML = '<div style="display:flex;align-items:center;gap:8px;color:var(--text-secondary);font-size:.875rem;padding:6px 0;"><div class="loading" style="width:16px;height:16px;border-width:2px;flex-shrink:0;"></div> Uploading and validating — do not close this page.</div>';

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
            statusDiv.innerHTML = '';
            submitBtn.disabled = false;
            submitBtn.textContent = 'Upload & Validate';
            if (data.success) {
                const added = Number(data.inserted || 0).toLocaleString();
                const newSp = Number(data.new_species || 0);
                const lines = [`${added} record(s) successfully ingested into the database.`];
                if (newSp > 0) lines.push(`${newSp} new species automatically added to the masterlist.`);
                showToast('Upload Complete', lines, 'success');
                loadValidationLog();
                loadSpatialChecks();
                loadCovariateStatus();
            } else {
                showToast('Upload Failed', [data.error || 'Upload failed. Check the validation log below for details.'], 'danger');
                loadValidationLog();
                loadSpatialChecks();
            }
        })
        .catch(err => {
            statusDiv.innerHTML = '';
            submitBtn.disabled = false;
            submitBtn.textContent = 'Upload & Validate';
            showToast('Upload Failed', [`Network error: ${err.message}`], 'danger');
        });
});

// Model upload form
document.getElementById('modelUploadForm')?.addEventListener('submit', function(e) {
    e.preventDefault();
    const file    = document.getElementById('modelFile').files[0];
    const version = document.getElementById('versionName').value;
    const desc    = document.getElementById('versionDesc').value;
    const uploadBtn = this.querySelector('button[type="submit"]');

    if (!file) {
        showToast('No File Selected', ['Please select a model file (.zip, .h5, .keras, etc.)'], 'warning');
        return;
    }
    if (!version.trim()) {
        showToast('Version Required', ['Enter a version name (e.g. v2.1.0) before uploading.'], 'warning');
        return;
    }

    uploadBtn.disabled = true;
    uploadBtn.textContent = 'Uploading…';

    const formData = new FormData();
    formData.append('file', file);
    formData.append('version', version);
    formData.append('description', desc);

    fetch('api/upload_model.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            uploadBtn.disabled = false;
            uploadBtn.textContent = 'Upload Model';
            if (!data.success) {
                showToast('Model Upload Failed', [data.error || 'Upload failed. Verify the file format and try again.'], 'danger');
                return;
            }
            showToast('Model Uploaded', [
                data.message || `Version ${version} uploaded successfully.`,
            ], 'success');
            loadModelList();
        })
        .catch(() => {
            uploadBtn.disabled = false;
            uploadBtn.textContent = 'Upload Model';
            showToast('Upload Failed', ['Request failed. Check server connection and try again.'], 'danger');
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

function describeApiError(data, fallback) {
    if (!data) return fallback;
    if (typeof data.error === 'string' && data.error.trim()) return data.error.trim();
    if (typeof data.message === 'string' && data.message.trim()) return data.message.trim();
    try {
        return JSON.stringify(data).substring(0, 300);
    } catch (_) {
        return fallback;
    }
}

async function fetchCovariate(btn, source, label) {
    btn.disabled = true;
    btn.textContent = 'Checking missing periods…';

    // Step 1 — dry run: ask backend which (year, month) periods are missing
    let data;
    try {
        data = await fetch('api/fetch_satellite.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ source })
        }).then(safeJson);
    } catch (err) {
        btn.disabled = false;
        btn.textContent = `Fetch ${label} Data`;
        showToast(`${label} — Check Failed`, [err.message], 'danger');
        return;
    }

    btn.disabled = false;
    btn.textContent = `Fetch ${label} Data`;

    if (!data.success) {
        showToast(`${label} Error`, [describeApiError(data, 'Unexpected error occurred.')], 'danger');
        return;
    }

    if (!data.missing || data.missing.length === 0) {
        showToast(label, ['Already up to date with all bird observation periods.'], 'success');
        loadCovariateStatus();
        return;
    }

    // Step 2 — show confirmation modal with period list
    const batchSize  = 12;
    const totalCount = data.missing.length;
    const thisBatch  = data.missing.slice(0, batchSize);
    const remaining  = totalCount - thisBatch.length;

    const byYear = {};
    thisBatch.forEach(p => {
        if (!byYear[p.year]) byYear[p.year] = [];
        byYear[p.year].push(MONTH_NAMES[p.month - 1]);
    });
    const periodRowsHtml = Object.keys(byYear).sort().map(yr =>
        `<div><strong style="color:var(--text-primary);">${escHtml(yr)}:</strong> ${escHtml(byYear[yr].join(', '))}</div>`
    ).join('');

    const remainNote = remaining > 0
        ? `<p style="margin:8px 0 0;font-size:.8rem;color:var(--text-muted);">${remaining} more period(s) will remain after this batch — click Fetch again to continue.</p>`
        : '';

    const bodyHtml = `
        <p style="margin:0 0 10px;"><strong>${totalCount}</strong> missing period(s) found.
        Fetching the next <strong>${thisBatch.length}</strong>:</p>
        <div style="background:var(--bg-card-alt);border:1px solid var(--border-color);border-radius:8px;padding:10px 14px;font-size:.83rem;max-height:150px;overflow-y:auto;line-height:1.9;">
            ${periodRowsHtml}
        </div>
        ${remainNote}
        <p style="margin:8px 0 0;font-size:.8rem;color:var(--text-muted);">This may take several minutes. Keep this page open.</p>`;

    const confirmed = await showConfirmModal({
        title: label,
        subtitle: 'Confirm satellite data fetch from Google Earth Engine',
        iconSvg: ICON_CLOUD,
        iconBg: 'rgba(37,99,235,0.12)',
        iconColor: '#2563eb',
        body: bodyHtml,
        confirmText: `Fetch ${thisBatch.length} Period(s)`,
        confirmBg: '#2563eb',
    });

    if (!confirmed) return;

    // Step 3 — confirmed: trigger actual GEE fetch
    btn.disabled = true;
    btn.textContent = `Fetching ${thisBatch.length} of ${totalCount} period(s)…`;

    let result;
    try {
        result = await fetch('api/fetch_satellite.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ source, confirmed: true })
        }).then(safeJson);
    } catch (err) {
        btn.disabled = false;
        btn.textContent = `Fetch ${label} Data`;
        showToast(`${label} — Fetch Failed`, [err.message], 'danger');
        return;
    }

    btn.disabled = false;
    btn.textContent = `Fetch ${label} Data`;
    loadCovariateStatus();

    if (result.success) {
        const lines = [result.message || 'Batch complete.'];
        if (result.remaining_count > 0) lines.push(`${result.remaining_count} period(s) still remaining — click Fetch again to continue.`);
        if (result.errors && result.errors.length > 0) result.errors.forEach(e => lines.push(`⚠ ${e}`));
        showToast(`${label} Complete`, lines, result.errors?.length ? 'warning' : 'success');
    } else {
        const lines = [describeApiError(result, 'Unexpected error.')];
        if (result.remaining_count > 0) lines.push(`${result.remaining_count} period(s) still remaining — click Fetch again to continue.`);
        if (result.errors && result.errors.length > 0) result.errors.forEach(e => lines.push(`⚠ ${e}`));
        showToast(`${label} — Fetch Failed`, lines, 'danger');
    }
}

function fetchVIIRS()      { fetchCovariate(this, 'viirs',      'Artificial Light (VIIRS)'); }
function fetchMODIS()      { fetchCovariate(this, 'ndvi',       'Vegetation Index (MODIS)'); }
function fetchNOAATemp()   { fetchCovariate(this, 'land_temp',  'Land Surface Temperature (MODIS)'); }
function fetchNOAAPrecip() { fetchCovariate(this, 'precip',     'Precipitation (CHIRPS)'); }
function fetchLandCover()  { fetchCovariate(this, 'land_cover', 'Land Cover Type (MODIS)'); }

async function buildMasterGrid(btn) {
    const statusEl = document.getElementById('masterGridStatus');
    btn.disabled   = true;
    btn.textContent = 'Checking data coverage…';
    statusEl.textContent = '';

    // Step 1 — dry run: check which years have complete covariate coverage
    let data;
    try {
        data = await fetch('api/build_master_grid.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({})
        }).then(safeJson);
    } catch (err) {
        btn.disabled = false;
        btn.textContent = 'Rebuild Analysis Data';
        showToast('Coverage Check Failed', [err.message], 'danger');
        return;
    }

    btn.disabled    = false;
    btn.textContent = 'Rebuild Analysis Data';

    if (!data.success) {
        showToast('Coverage Check Failed', [data.error || 'Could not verify data coverage.'], 'danger');
        return;
    }

    if (!data.ready_years || data.ready_years.length === 0) {
        showToast('No Years Ready', [data.message || 'No years have complete covariate coverage yet. Fetch environmental data first.'], 'warning');
        return;
    }

    // Step 2 — build modal body with year-detail breakdown
    const yearRowsHtml = (data.year_detail || []).map(d => {
        if (!d.ready) {
            return `<div style="display:flex;align-items:center;gap:8px;padding:2px 0;"><span style="color:#dc2626;font-weight:700;flex-shrink:0;">✗</span><span><strong>${escHtml(String(d.year))}</strong> — Blocked: ${escHtml(String(d.missing[0] || '?'))}</span></div>`;
        }
        const warns = d.warnings || [];
        if (warns.length === 0) {
            return `<div style="display:flex;align-items:center;gap:8px;padding:2px 0;"><span style="color:#16a34a;font-weight:700;flex-shrink:0;">✓</span><span><strong>${escHtml(String(d.year))}</strong> — All covariates present</span></div>`;
        }
        return `<div style="display:flex;align-items:center;gap:8px;padding:2px 0;"><span style="color:#ca8a04;font-weight:700;flex-shrink:0;">⚠</span><span><strong>${escHtml(String(d.year))}</strong> — Gap-fill will cover: ${escHtml(warns.join('; '))}</span></div>`;
    }).join('');

    const rawNoteHtml = data.raw_note
        ? `<p style="margin:8px 0 0;font-size:.8rem;color:var(--text-muted);">⚙ Aggregation: ${escHtml(String(data.raw_note))}</p>`
        : '';

    const bodyHtml = `
        <p style="margin:0 0 10px;"><strong>${data.ready_years.length}</strong> year(s) ready for rebuild:
        <strong style="color:var(--text-primary);">${escHtml(data.ready_years.join(', '))}</strong></p>
        <div style="background:var(--bg-card-alt);border:1px solid var(--border-color);border-radius:8px;padding:10px 14px;font-size:.83rem;max-height:160px;overflow-y:auto;line-height:1.9;">
            ${yearRowsHtml}
        </div>
        ${rawNoteHtml}
        <p style="margin:8px 0 0;font-size:.8rem;color:var(--text-muted);">
            Current dataset rows: <strong>${(data.current_rows || 0).toLocaleString()}</strong> — This may take several minutes. Keep this page open.
        </p>`;

    const confirmed = await showConfirmModal({
        title: 'Rebuild Analysis Data',
        subtitle: `${data.ready_years.length} year(s) will be processed`,
        iconSvg: ICON_REBUILD,
        iconBg: 'rgba(13,148,136,0.12)',
        iconColor: '#0d9488',
        body: bodyHtml,
        confirmText: 'Rebuild Now',
        confirmBg: '#0d9488',
    });

    if (!confirmed) return;

    // Step 3 — execute
    btn.disabled    = true;
    btn.textContent = 'Rebuilding… (do not close this page)';
    statusEl.innerHTML = '<span style="color:var(--text-secondary);font-size:.875rem;">Step 1: Refreshing bird summaries. Step 2: Rebuilding the analysis dataset. Do not close this page.</span>';

    let result;
    try {
        result = await fetch('api/build_master_grid.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ confirmed: true })
        }).then(safeJson);
    } catch (err) {
        btn.disabled    = false;
        btn.textContent = 'Rebuild Analysis Data';
        statusEl.innerHTML = '';
        showToast('Rebuild Failed', [`Request failed: ${err.message}`], 'danger');
        return;
    }

    btn.disabled    = false;
    btn.textContent = 'Rebuild Analysis Data';
    statusEl.innerHTML = '';

    if (result.success) {
        const logLines = (result.log || []).map(l => {
            try { const p = JSON.parse(l); return p.msg || l; } catch { return l; }
        });
        const lastMsg = logLines[logLines.length - 1] || 'Build complete.';
        showToast('Analysis Data Rebuilt', [
            'Dataset rebuilt successfully.',
            lastMsg
        ], 'success');
        loadSpatialChecks();
    } else {
        const errMsg = result.error || 'Did not complete successfully.';
        showToast('Rebuild Failed', [errMsg], 'danger');
    }
}

// ── BAU cache reset ──────────────────────────────────────────────────────────

async function clearBauCache(btn) {
    const statusEl = document.getElementById('bauCacheStatus');

    const confirmed = await showConfirmModal({
        title: 'Reset BAU Cache',
        subtitle: 'All cached baseline values will be deleted',
        iconSvg: ICON_REBUILD,
        iconBg: 'rgba(245,158,11,0.12)',
        iconColor: '#d97706',
        body: '<p style="margin:0;font-size:.875rem;">This deletes all rows in <code>analytics_bau_baselines</code>. The cache rebuilds automatically on the next forecast request for each city/month combination.</p>',
        confirmText: 'Clear Cache',
        confirmBg: '#d97706',
    });
    if (!confirmed) return;

    btn.disabled = true;
    btn.textContent = 'Clearing…';
    statusEl.textContent = '';

    let data;
    try {
        data = await fetch('api/clear_bau_cache.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
        }).then(safeJson);
    } catch (err) {
        btn.disabled = false;
        btn.textContent = 'Reset BAU Cache';
        showToast('Cache Reset Failed', [err.message], 'danger');
        return;
    }

    btn.disabled = false;
    btn.textContent = 'Reset BAU Cache';

    if (data.success) {
        showToast('BAU Cache Cleared', [
            `${data.deleted_rows ?? 0} cached row(s) removed.`,
            'Cache will rebuild on the next forecast request.',
        ], 'success');
    } else {
        showToast('Cache Reset Failed', [data.error || 'Unknown error.'], 'danger');
    }
}

// ── Model list (AJAX) ────────────────────────────────────────────────────────

function loadModelList() {
    const tbody = document.getElementById('modelListBody');
    if (!tbody) return;
    tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;color:#888;padding:16px;">Loading…</td></tr>';

    fetch('api/list_models.php')
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                tbody.innerHTML = `<tr><td colspan="4" style="color:#f87171;padding:16px;">Failed to load models: ${escHtml(data.error || 'Unknown error')}</td></tr>`;
                return;
            }
            if (!data.models || !data.models.length) {
                tbody.innerHTML = '<tr><td colspan="4" style="color:#888;padding:16px;">No model versions found yet.</td></tr>';
                return;
            }
            tbody.innerHTML = data.models.map(row => {
                const status    = row.status      || 'Backup';
                const version   = row.version_name || '';
                const date      = (row.created_at  || '').substring(0, 10);
                const badgeCls  = status === 'Active' ? 'badge-success' : 'badge-secondary';
                const nameCell  = status === 'Active'
                    ? `<strong>${escHtml(version)}</strong>`
                    : escHtml(version);
                const safeV = version.replace(/'/g, "\\'");
                const actions = status === 'Active'
                    ? `<span style="color:var(--text-muted,#64748b);font-size:0.82rem;">—</span>`
                    : `<div style="display:flex;gap:4px;align-items:center;">
                        ${_iconBtn('Switch to this version', ICON_SWITCH, `switchModel('${safeV}')`, 'color:#6366f1;')}
                        ${_iconBtn('Delete this version',    ICON_TRASH,  `deleteModel('${safeV}')`, 'color:#dc2626;')}
                       </div>`;
                return `<tr>
                    <td>${nameCell}</td>
                    <td>${escHtml(date)}</td>
                    <td><span class="badge ${badgeCls}">${escHtml(status)}</span></td>
                    <td style="white-space:nowrap;">${actions}</td>
                </tr>`;
            }).join('');
        })
        .catch(err => {
            if (tbody) tbody.innerHTML = `<tr><td colspan="4" style="color:#f87171;padding:16px;">Network error: ${escHtml(err.message)}</td></tr>`;
        });
}

// Model delete
async function deleteModel(version) {
    const confirmed = await showConfirmModal({
        title: 'Delete Model Version',
        subtitle: `Version: ${version}`,
        iconSvg: ICON_TRASH,
        iconBg: 'rgba(220,38,38,0.12)',
        iconColor: '#dc2626',
        body: `<p style="margin:0 0 8px;">Permanently delete model version <strong style="color:var(--text-primary);">${escHtml(version)}</strong>?</p><p style="margin:0;font-size:.82rem;color:var(--text-muted);">The bundle files stored on disk will also be removed. This cannot be undone.</p>`,
        confirmText: 'Delete Version',
        confirmBg: '#dc2626',
    });
    if (!confirmed) return;

    fetch('api/delete_model.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ version })
    })
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                showToast('Delete Failed', [data.error || 'Could not delete this model version.'], 'danger');
                return;
            }
            showToast('Version Deleted', [data.message || `Version ${escHtml(version)} removed.`], 'success');
            loadModelList();
        })
        .catch(() => showToast('Request Failed', ['Check server connection and try again.'], 'danger'));
}

// Model switching
async function switchModel(version) {
    const confirmed = await showConfirmModal({
        title: 'Switch Model Version',
        subtitle: `Activate version: ${version}`,
        iconSvg: ICON_SWITCH,
        iconBg: 'rgba(99,102,241,0.12)',
        iconColor: '#6366f1',
        body: `<p style="margin:0 0 10px;">Switch all predictions and forecasts to model version <strong style="color:var(--text-primary);">${escHtml(version)}</strong>?</p><p style="margin:0;font-size:.82rem;color:var(--text-muted);">The previous active model will be archived. You can switch back at any time.</p>`,
        confirmText: 'Switch Model',
        confirmBg: '#6366f1',
    });
    if (!confirmed) return;

    showToast('Switching Model', [`Loading version ${escHtml(version)}…`], 'info');
    fetch('api/switch_model.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ version })
    })
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                showToast('Switch Failed', [data.error || 'Model switch failed. Try again.'], 'danger');
                return;
            }
            showToast('Model Activated', [data.message || `Version ${escHtml(version)} is now active.`], 'success');
            loadModelList();
        })
        .catch(() => showToast('Request Failed', ['Check server connection and try again.'], 'danger'));
}

// Save thresholds (with client-side validation mirroring server rules)
function saveThresholds() {
    const riskFields = [
        { id: 'highRiskThreshold',  key: 'high_risk',  label: 'High Risk Threshold' },
        { id: 'modRiskThreshold',   key: 'mod_risk',   label: 'Moderate Risk Threshold' },
        { id: 'lowRiskThreshold',   key: 'low_risk',   label: 'Low Risk Threshold' },
    ];
    const weightFields = [
        { id: 'kbaRichnessWeight',  key: 'kba_richness_weight',  label: 'Richness Weight' },
        { id: 'kbaSensitiveWeight', key: 'kba_sensitive_weight', label: 'Sensitive Species Weight' },
        { id: 'kbaNdviWeight',      key: 'kba_ndvi_weight',      label: 'NDVI Weight' },
        { id: 'kbaAlanWeight',      key: 'kba_alan_weight',      label: 'ALAN Weight' },
        { id: 'kbaLstWeight',       key: 'kba_lst_weight',       label: 'LST Weight' },
        { id: 'kbaPrecipWeight',    key: 'kba_precip_weight',    label: 'Precipitation Weight' },
    ];

    // 4.1 — no field may be empty
    for (const f of [...riskFields, ...weightFields]) {
        const raw = (document.getElementById(f.id)?.value ?? '').trim();
        if (raw === '') {
            showToast('Missing Value', [`"${f.label}" cannot be empty.`], 'warning');
            document.getElementById(f.id)?.focus();
            return;
        }
    }

    // 4.2 — risk thresholds must be whole integers
    const payload = {};
    for (const f of riskFields) {
        const raw = document.getElementById(f.id).value.trim();
        if (!/^-?\d+$/.test(raw)) {
            showToast('Invalid Value', [`"${f.label}" must be a whole number (e.g. 60), not "${escHtml(raw)}".`], 'warning');
            document.getElementById(f.id)?.focus();
            return;
        }
        payload[f.key] = parseInt(raw, 10);
    }

    // weights — must be valid numbers
    for (const f of weightFields) {
        const raw = document.getElementById(f.id).value.trim();
        const num = parseFloat(raw);
        if (isNaN(num)) {
            showToast('Invalid Value', [`"${f.label}" must be a valid number.`], 'warning');
            document.getElementById(f.id)?.focus();
            return;
        }
        payload[f.key] = num;
    }

    // 4.3 — KBA/PA weights must sum to exactly 100
    const weightSum = weightFields.reduce((s, f) => s + payload[f.key], 0);
    if (Math.abs(weightSum - 100) > 0.01) {
        showToast('Weight Total Invalid', [
            `KBA/PA weights must total exactly 100%. Current total: ${weightSum.toFixed(2)}%.`,
            'Adjust the weights so they add up to 100% before saving.',
        ], 'warning');
        return;
    }

    fetch('api/save_thresholds.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showToast('Thresholds Saved', [data.message || 'Configuration applied for all users.'], 'success');
            } else {
                showToast('Save Failed', [data.error || 'Failed to save thresholds.'], 'danger');
            }
        })
        .catch(() => showToast('Request Failed', ['Check server connection.'], 'danger'));
}

function updateKbaWeightTotal() {
    const ids = [
        'kbaRichnessWeight',
        'kbaSensitiveWeight',
        'kbaNdviWeight',
        'kbaAlanWeight',
        'kbaLstWeight',
        'kbaPrecipWeight'
    ];

    const total = ids.reduce((sum, id) => {
        const el = document.getElementById(id);
        const value = el ? parseFloat(el.value) : 0;
        return sum + (Number.isFinite(value) ? value : 0);
    }, 0);

    const totalEl = document.getElementById('kbaWeightTotal');
    if (totalEl) {
        totalEl.value = total.toFixed(1);
        totalEl.style.color = Math.abs(total - 100) < 0.01 ? '#15803d' : '#b91c1c';
    }
}

// ── User Management ───────────────────────────────────────────────────────────

const _iconBtn = (title, svg, onclick, extraStyle = '') =>
    `<button title="${title}" onclick="${onclick}" style="background:none;border:1px solid var(--border-color);border-radius:6px;padding:5px;cursor:pointer;color:var(--text-secondary);display:inline-flex;align-items:center;justify-content:center;transition:background .15s,color .15s;${extraStyle}"
        onmouseover="this.style.background='var(--bg-card-alt)'" onmouseout="this.style.background='none'">${svg}</button>`;

let _allUsers = [];
let _sortCol  = '';
let _sortDir  = 1; // 1 = asc, -1 = desc

function sortUserList(col) {
    if (_sortCol === col) {
        _sortDir = -_sortDir;
    } else {
        _sortCol = col;
        _sortDir = 1;
    }
    // Update header indicators
    ['name', 'email', 'role'].forEach(c => {
        const el = document.getElementById('userSort' + c.charAt(0).toUpperCase() + c.slice(1));
        if (!el) return;
        if (c === _sortCol) {
            el.textContent = _sortDir === 1 ? '↑' : '↓';
            el.style.opacity = '1';
            el.style.color = 'var(--accent-blue,#3b82f6)';
        } else {
            el.textContent = '↕';
            el.style.opacity = '0.45';
            el.style.color = '';
        }
    });
    renderUserList();
}

function renderUserList() {
    const tbody = document.getElementById('userListBody');
    if (!tbody) return;

    if (!_allUsers.length) {
        tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:#888;padding:16px;">No accounts found.</td></tr>';
        return;
    }

    let users = [..._allUsers];
    if (_sortCol) {
        users.sort((a, b) => {
            let va = '', vb = '';
            if (_sortCol === 'name')  { va = (a.full_name  || '').toLowerCase(); vb = (b.full_name  || '').toLowerCase(); }
            if (_sortCol === 'email') { va = (a.email      || '').toLowerCase(); vb = (b.email      || '').toLowerCase(); }
            if (_sortCol === 'role')  { va = (a.user_type  || '').toLowerCase(); vb = (b.user_type  || '').toLowerCase(); }
            return va < vb ? -_sortDir : va > vb ? _sortDir : 0;
        });
    }

    tbody.innerHTML = users.map(u => {
        const isActive    = u.is_active == 1;
        const statusBadge = isActive
            ? `<span class="badge badge-success">Active</span>`
            : `<span class="badge badge-secondary">Inactive</span>`;
        const typeBadge   = u.user_type === 'IT_admin'
            ? `<span class="badge badge-secondary">IT Admin</span>`
            : `<span class="badge badge-secondary">EMS</span>`;
        const toggleBtn = isActive
            ? _iconBtn('Deactivate', ICON_BLOCK,     `manageUser(${u.user_id},'deactivate')`)
            : _iconBtn('Activate',   ICON_PERSON_OK, `manageUser(${u.user_id},'activate')`);
        const editBtn   = _iconBtn('Edit',   ICON_PENCIL, `showEditUserModal(${u.user_id})`);
        const deleteBtn = _iconBtn('Delete', ICON_TRASH,  `manageUser(${u.user_id},'delete')`, 'color:#dc2626;');
        return `<tr>
            <td>${u.full_name ? escHtml(u.full_name) : '—'}</td>
            <td style="font-size:0.82rem;">${escHtml(u.email)}</td>
            <td>${typeBadge}</td>
            <td>${statusBadge}</td>
            <td style="white-space:nowrap;">
                <div style="display:flex;gap:4px;align-items:center;">${editBtn}${toggleBtn}${deleteBtn}</div>
            </td>
        </tr>`;
    }).join('');
}

function loadUserList() {
    fetch('api/list_users.php')
        .then(r => r.json())
        .then(data => {
            if (!data.success) return;
            _userCache.clear();
            _allUsers = data.users || [];
            _allUsers.forEach(u => _userCache.set(u.user_id, u));
            renderUserList();
        })
        .catch(() => {});
}

function escHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

async function manageUser(userId, action) {
    if (action === 'delete') {
        const u = _userCache.get(userId);
        const label = u ? escHtml(u.email) : `#${userId}`;
        const confirmed = await showConfirmModal({
            title: 'Delete Account',
            subtitle: label,
            iconSvg: ICON_TRASH,
            iconBg: 'rgba(220,38,38,0.12)',
            iconColor: '#dc2626',
            body: `<p style="margin:0 0 8px;">Permanently delete this account?</p><p style="margin:0;font-size:.82rem;color:var(--text-muted);">This action cannot be undone. All associated session data will be removed.</p>`,
            confirmText: 'Delete Account',
            confirmBg: '#dc2626',
        });
        if (!confirmed) return;
    }

    const body = new FormData();
    body.append('user_id', userId);
    body.append('action',  action);

    fetch('api/manage_user.php', { method: 'POST', body })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showToast('Done', [data.message || 'Action completed.'], 'success');
                loadUserList();
            } else {
                showToast('Action Failed', [data.error || 'Could not complete this action.'], 'danger');
            }
        })
        .catch(() => showToast('Request Failed', ['Check server connection and try again.'], 'danger'));
}

let _editingUserId = null;

function showEditUserModal(userId) {
    const u = _userCache.get(userId);
    if (!u) return;
    _editingUserId = userId;

    document.getElementById('editUserModalSubtitle').textContent = u.email || '';
    document.getElementById('editUserFullName').value   = u.full_name  || '';
    document.getElementById('editUserEmail').value      = u.email      || '';
    document.getElementById('editUserType').value       = u.user_type  || 'EMS';
    document.getElementById('editUserPassword').value   = '';
    document.getElementById('editUserModalErr').textContent = '';

    document.getElementById('editUserModal').style.display = 'block';
    document.getElementById('editUserFullName').focus();
}

function closeEditUserModal() {
    document.getElementById('editUserModal').style.display = 'none';
    _editingUserId = null;
}

function submitEditUser() {
    const err     = document.getElementById('editUserModalErr');
    const saveBtn = document.getElementById('editUserSaveBtn');
    const name    = document.getElementById('editUserFullName').value.trim();
    const email   = document.getElementById('editUserEmail').value.trim();
    const type    = document.getElementById('editUserType').value;
    const pass    = document.getElementById('editUserPassword').value;

    err.textContent = '';

    if (!name)  { err.textContent = 'Full name is required.'; return; }
    if (!email) { err.textContent = 'Email is required.'; return; }
    if (pass && pass.length < 8) { err.textContent = 'New password must be at least 8 characters.'; return; }

    saveBtn.disabled    = true;
    saveBtn.textContent = 'Saving…';

    const body = new FormData();
    body.append('user_id',   _editingUserId);
    body.append('action',    'edit');
    body.append('full_name', name);
    body.append('email',     email);
    body.append('user_type', type);
    if (pass) body.append('new_password', pass);

    fetch('api/manage_user.php', { method: 'POST', body })
        .then(r => r.json())
        .then(data => {
            saveBtn.disabled    = false;
            saveBtn.textContent = 'Save Changes';
            if (data.success) {
                closeEditUserModal();
                showToast('Account Updated', [data.message || 'Changes saved successfully.'], 'success');
                loadUserList();
            } else {
                err.textContent = data.error || 'Could not save changes.';
            }
        })
        .catch(() => {
            saveBtn.disabled    = false;
            saveBtn.textContent = 'Save Changes';
            err.textContent = 'Request failed. Check server connection.';
        });
}

document.getElementById('editUserModal')?.addEventListener('click', e => {
    if (e.target === document.getElementById('editUserModal')) closeEditUserModal();
});

document.getElementById('addUserForm')?.addEventListener('submit', function (e) {
    e.preventDefault();
    const fullName = document.getElementById('newUserFullName').value.trim();
    const email    = document.getElementById('newUserEmail').value.trim();
    const password = document.getElementById('newUserPassword').value;
    const userType = document.getElementById('newUserType').value;
    const statusEl = document.getElementById('addUserStatus');
    const btn      = document.getElementById('addUserBtn');

    btn.disabled = true;
    statusEl.innerHTML = '';

    const body = new FormData();
    body.append('full_name',  fullName);
    body.append('email',      email);
    body.append('password',   password);
    body.append('user_type',  userType);

    fetch('api/add_user.php', { method: 'POST', body })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showToast('Account Created', [data.message || 'New account added successfully.'], 'success');
                document.getElementById('newUserFullName').value = '';
                document.getElementById('newUserEmail').value    = '';
                document.getElementById('newUserPassword').value = '';
                document.getElementById('newUserType').value     = 'EMS';
                document.getElementById('addUserDetails').removeAttribute('open');
                loadUserList();
            } else {
                showToast('Add Account Failed', [data.error || 'Could not create account.'], 'danger');
            }
        })
        .catch(() => {
            showToast('Request Failed', ['Check server connection and try again.'], 'danger');
        })
        .finally(() => { btn.disabled = false; });
});

['kbaRichnessWeight','kbaSensitiveWeight','kbaNdviWeight','kbaAlanWeight','kbaLstWeight','kbaPrecipWeight'].forEach(id => {
    const el = document.getElementById(id);
    if (el) {
        el.addEventListener('input', updateKbaWeightTotal);
    }
});
updateKbaWeightTotal();
</script>
EOD;

require_once 'includes/footer.php';
?>