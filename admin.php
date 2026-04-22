<?php
$page_title = 'Admin Panel';
require_once 'includes/auth.php';
require_admin(); // Require admin access
require_once 'includes/db.php';

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

// Validation log and spatial checks are loaded asynchronously via JS on DOMContentLoaded.

// ── Load saved thresholds ─────────────────────────────────────────────────
$thresholds_file = __DIR__ . '/data/cache/thresholds.json';
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
if (file_exists($thresholds_file)) {
    $saved = json_decode(file_get_contents($thresholds_file), true);
    if (is_array($saved)) {
        $thresholds = array_merge($thresholds, $saved);
    }
}

// ── Security logs from MySQL ──────────────────────────────────────────────
$recent_access    = [];
$recent_failures  = [];
try {
    $log_db = get_mysql_db();
    _ensure_access_log_table($log_db);
    _ensure_login_attempts_table($log_db);

    $recent_access = $log_db->query(
        'SELECT email, action, ip_address, logged_at
         FROM access_log
         ORDER BY logged_at DESC
         LIMIT 15'
    )->fetchAll(PDO::FETCH_ASSOC);

    $recent_failures = $log_db->query(
        'SELECT email, ip_address, attempted_at
         FROM login_attempts
         WHERE success = 0
         ORDER BY attempted_at DESC
         LIMIT 15'
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // Non-fatal – tables may not exist yet; logs will show empty
    error_log('[AVILIGHT] admin security log query error: ' . $e->getMessage());
}

require_once 'includes/header.php';
?>

<!-- Toast notification container -->
<div id="toastContainer" style="position:fixed;top:20px;right:20px;z-index:9999;display:flex;flex-direction:column;gap:10px;max-width:380px;"></div>

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
        <h4>Merge Covariates → Master Grid</h4>
        <button class="btn btn-success" id="buildMasterGridBtn" onclick="buildMasterGrid.call(this, this)">
            Build Master Grid
        </button>
        <div id="masterGridStatus" style="margin-top: 10px;"></div>

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

<!-- Account Management -->
<div class="card">
    <h2 class="card-header">Account Management</h2>
    <div class="card-body">
        <div class="grid-2">
            <!-- Change Password -->
            <div>
                <h4>Change Password</h4>
                <p style="color: #666; font-size: 0.9rem; margin-bottom: 12px;">
                    Logged in as <strong><?= htmlspecialchars(get_logged_user()) ?></strong>
                </p>
                <?php if ($pw_success): ?>
                    <div class="alert alert-success" style="margin-bottom:10px;"><?= htmlspecialchars($pw_success) ?></div>
                <?php endif; ?>
                <?php if ($pw_error): ?>
                    <div class="alert alert-danger" style="margin-bottom:10px;"><?= htmlspecialchars($pw_error) ?></div>
                <?php endif; ?>
                <form method="POST">
                    <input type="hidden" name="action" value="change_password">
                    <div class="form-group">
                        <label class="form-label">Current Password</label>
                        <input type="password" name="current_password" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">New Password <small style="color:#666;">(min. 8 characters)</small></label>
                        <input type="password" name="new_password" class="form-control" minlength="8" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Confirm New Password</label>
                        <input type="password" name="confirm_password" class="form-control" minlength="8" required>
                    </div>
                    <button type="submit" class="btn btn-primary">Update Password</button>
                </form>
            </div>

            <!-- User Management -->
            <div>
                <h4>System Users</h4>
                <?php if ($add_user_success): ?>
                    <div class="alert alert-success" style="margin-bottom:10px;"><?= $add_user_success ?></div>
                <?php endif; ?>
                <table style="font-size: 0.88rem; width:100%; margin-bottom:16px;">
                    <thead>
                        <tr>
                            <th>Email</th>
                            <th>Last Login</th>
                        </tr>
                    </thead>
                    <tbody id="userListBody">
                        <?php foreach (list_users() as $u): ?>
                        <tr>
                            <td><?= htmlspecialchars($u['email']) ?></td>
                            <td style="color:#666; font-size:0.82rem;"><?= !empty($u['last_login']) ? htmlspecialchars(substr($u['last_login'], 0, 16)) . ' UTC' : 'Never' ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <details id="addUserDetails">
                    <summary style="cursor:pointer; font-size:0.9rem; color:var(--accent-blue,#3b82f6); margin-bottom:10px;">+ Add new user</summary>
                    <div id="addUserStatus" style="margin-bottom:8px;"></div>
                    <form id="addUserForm" style="margin-top:10px;">
                        <div class="form-group">
                            <label class="form-label">Full Name</label>
                            <input type="text" id="newUserFullName" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Email</label>
                            <input type="email" id="newUserEmail" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Password <small style="color:#666;">(min. 8 characters)</small></label>
                            <input type="password" id="newUserPassword" class="form-control" minlength="8" required>
                        </div>
                        <button type="submit" class="btn btn-primary" id="addUserBtn">Add User</button>
                    </form>
                </details>
            </div>
        </div>
    </div>
</div>

<!-- Security & Activity Logs -->
<div class="card">
    <h2 class="card-header">Security & Access Logs</h2>
    <div class="card-body">
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:32px;">

            <!-- Recent Activity -->
            <div>
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
                    <h4 style="margin:0;">Recent Activity</h4>
                    <select id="accessLogFilter" style="font-size:0.8rem;padding:3px 8px;border-radius:6px;border:1px solid var(--border-color,#334155);background:var(--bg-card,#1e293b);color:var(--text-primary,#f1f5f9);">
                        <option value="">All Actions</option>
                        <?php
                        $action_types = array_unique(array_map(function($r) {
                            return strtok($r['action'] ?? '', ':');
                        }, $recent_access));
                        sort($action_types);
                        foreach ($action_types as $type): ?>
                            <option value="<?= htmlspecialchars($type) ?>"><?= htmlspecialchars(ucfirst($type)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="overflow-x:auto;">
                    <table style="font-size:0.85rem; width:100%;" id="accessLogTable">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Action</th>
                                <th>IP</th>
                                <th style="white-space:nowrap;">Time (UTC)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recent_access)): ?>
                                <tr><td colspan="4" style="color:#94a3b8; text-align:center;">No activity recorded yet.</td></tr>
                            <?php else: foreach ($recent_access as $row): ?>
                                <tr data-action="<?= htmlspecialchars(strtok($row['action'] ?? '', ':')) ?>">
                                    <td style="max-width:160px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?= htmlspecialchars($row['email']) ?>"><?= htmlspecialchars($row['email']) ?></td>
                                    <td style="font-size:0.8rem;"><?= htmlspecialchars(ucfirst($row['action'])) ?></td>
                                    <td style="color:#64748b; font-size:0.8rem;"><?= htmlspecialchars($row['ip_address']) ?></td>
                                    <td style="color:#64748b; font-size:0.8rem; white-space:nowrap;"><?= htmlspecialchars(substr($row['logged_at'], 0, 16)) ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Failed Login Attempts -->
            <div>
                <h4 style="margin-bottom:10px;">Recent Failed Login Attempts</h4>
                <div style="overflow-x:auto;">
                    <table style="font-size:0.85rem; width:100%;">
                        <thead>
                            <tr>
                                <th>Email</th>
                                <th>IP</th>
                                <th style="white-space:nowrap;">Time (UTC)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recent_failures)): ?>
                                <tr><td colspan="3" style="color:#94a3b8; text-align:center;">No failed attempts recorded.</td></tr>
                            <?php else: foreach ($recent_failures as $row): ?>
                                <tr>
                                    <td style="max-width:180px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?= htmlspecialchars($row['email']) ?>"><?= htmlspecialchars($row['email']) ?></td>
                                    <td style="color:#64748b; font-size:0.8rem;"><?= htmlspecialchars($row['ip_address']) ?></td>
                                    <td style="color:#64748b; font-size:0.8rem; white-space:nowrap;"><?= htmlspecialchars(substr($row['attempted_at'], 0, 16)) ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>
</div>

<?php
$extra_scripts = <<<'EOD'
<style>
@keyframes toastIn { from { opacity:0; transform:translateX(30px); } to { opacity:1; transform:translateX(0); } }
#toastContainer > div { transition: opacity .3s ease; }
</style>
<script>
// ── Access log action filter ──────────────────────────────────────────────────
document.getElementById('accessLogFilter')?.addEventListener('change', function () {
    const val = this.value;
    document.querySelectorAll('#accessLogTable tbody tr').forEach(tr => {
        tr.style.display = (!val || tr.dataset.action === val) ? '' : 'none';
    });
});

// ── Toast notifications ───────────────────────────────────────────────────────

function showToast(title, lines, type = 'info') {
    const colors = {
        success: { bg: 'rgba(34,197,94,0.12)',  border: 'rgba(34,197,94,0.4)',  icon: '✓', titleColor: '#4ade80' },
        danger:  { bg: 'rgba(248,113,113,0.12)', border: 'rgba(248,113,113,0.4)', icon: '✗', titleColor: '#f87171' },
        warning: { bg: 'rgba(251,191,36,0.12)', border: 'rgba(251,191,36,0.4)', icon: '⚠', titleColor: '#fbbf24' },
        info:    { bg: 'rgba(59,130,246,0.12)',  border: 'rgba(59,130,246,0.4)',  icon: 'ℹ', titleColor: '#60a5fa' },
    };
    const c = colors[type] || colors.info;
    const toast = document.createElement('div');
    toast.style.cssText = `background:${c.bg};border:1px solid ${c.border};border-radius:10px;padding:14px 16px;box-shadow:0 4px 20px rgba(0,0,0,0.3);backdrop-filter:blur(8px);animation:toastIn .2s ease;`;
    const bodyLines = (Array.isArray(lines) ? lines : [lines]).filter(Boolean);
    toast.innerHTML = `
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;">
            <div style="flex:1;">
                <div style="font-weight:600;color:${c.titleColor};margin-bottom:${bodyLines.length ? 6 : 0}px;">${c.icon} ${title}</div>
                ${bodyLines.map(l => `<div style="font-size:0.82rem;color:var(--text-secondary,#94a3b8);margin-top:3px;">${l}</div>`).join('')}
            </div>
            <button onclick="this.closest('div[data-toast]').remove()" style="background:none;border:none;color:#666;cursor:pointer;font-size:1.1rem;line-height:1;padding:0;flex-shrink:0;">×</button>
        </div>`;
    toast.setAttribute('data-toast', '1');
    document.getElementById('toastContainer').appendChild(toast);
    setTimeout(() => toast.style.opacity = '0', 4700);
    setTimeout(() => toast.remove(), 5000);
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
                    <td style="white-space:nowrap;font-size:0.83rem;vertical-align:top;">${upload.uploaded_at || '—'}</td>
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
    const ts = ingestedAt.replace('T', ' ').substring(0, 16) + ' UTC';
    return ts;
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

// Load on page ready, then refresh every 60 seconds
document.addEventListener('DOMContentLoaded', () => {
    loadCovariateStatus();
    loadValidationLog();
    loadSpatialChecks();
});
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
                const added = Number(data.inserted || 0).toLocaleString();
                statusDiv.innerHTML = `<div class="alert alert-info"><strong>✓ Upload complete &mdash; ${added} record(s) added.</strong></div>`;
                loadValidationLog();
                loadSpatialChecks();
                loadCovariateStatus();
            } else {
                statusDiv.innerHTML = `<div class="alert alert-danger">${data.error || 'Upload failed.'}</div>`;
                loadValidationLog();
                loadSpatialChecks();
            }
        })
        .catch(err => {
            statusDiv.innerHTML = `<div class="alert alert-danger">Upload failed: ${err.message}</div>`;
        });
});

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
            showToast(`${label} Error`, [data.error || 'Unexpected error occurred.'], 'danger');
            return;
        }

        // Nothing missing
        if (!data.missing || data.missing.length === 0) {
            showToast(`${label}`, ['Already up to date with all bird observation periods.'], 'success');
            loadCovariateStatus();
            return;
        }

        // Step 2 — show user exactly which periods will be fetched (first batch)
        const batchSize  = 12;
        const totalCount = data.missing.length;
        const thisBatch  = data.missing.slice(0, batchSize);
        const remaining  = totalCount - thisBatch.length;

        // Group this batch's periods by year for readability
        const byYear = {};
        thisBatch.forEach(p => {
            if (!byYear[p.year]) byYear[p.year] = [];
            byYear[p.year].push(MONTH_NAMES[p.month - 1]);
        });
        const periodList = Object.keys(byYear).sort().map(yr =>
            `  ${yr}: ${byYear[yr].join(', ')}`
        ).join('\n');

        const remainNote = remaining > 0
            ? `\n\n${remaining} more period(s) will remain — click Fetch again to continue.`
            : '';

        const confirmed = confirm(
            `${label}\n` +
            `${'─'.repeat(40)}\n` +
            `${totalCount} missing period(s) found.\n\n` +
            `Fetching next ${thisBatch.length}:\n${periodList}` +
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
                const lines = [result.message || 'Batch complete.'];
                if (result.remaining_count > 0) lines.push(`${result.remaining_count} period(s) still remaining — click Fetch again to continue.`);
                if (result.errors && result.errors.length > 0) result.errors.forEach(e => lines.push(`⚠ ${e}`));
                showToast(`${label} Complete`, lines, result.errors?.length ? 'warning' : 'success');
            } else {
                showToast(`${label} Fetch Failed`, [result.error || 'Unexpected error.'], 'danger');
            }
        })
        .catch(err => {
            btn.disabled = false;
            btn.textContent = `Fetch ${label} Data`;
            showToast(`${label} Fetch Failed`, [err.message], 'danger');
        });
    })
    .catch(err => {
        btn.disabled = false;
        btn.textContent = `Fetch ${label} Data`;
        showToast(`${label} Check Failed`, [err.message], 'danger');
    });
}

function fetchVIIRS()      { fetchCovariate(this, 'viirs',      'Artificial Light (VIIRS)'); }
function fetchMODIS()      { fetchCovariate(this, 'ndvi',       'Vegetation Index (MODIS)'); }
function fetchNOAATemp()   { fetchCovariate(this, 'land_temp',  'Land Surface Temperature (MODIS)'); }
function fetchNOAAPrecip() { fetchCovariate(this, 'precip',     'Precipitation (CHIRPS)'); }
function fetchLandCover()  { fetchCovariate(this, 'land_cover', 'Land Cover Type (MODIS)'); }

function buildMasterGrid(btn) {
    const statusEl = document.getElementById('masterGridStatus');
    btn.disabled   = true;
    btn.textContent = 'Checking coverage…';
    statusEl.textContent = '';

    // Step 1 — dry run: check which years have complete covariate coverage
    fetch('api/build_master_grid.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({})
    })
    .then(safeJson)
    .then(data => {
        btn.disabled    = false;
        btn.textContent = 'Build Master Grid';

        if (!data.success) {
            statusEl.innerHTML = `<span style="color:red;">Error: ${data.error}</span>`;
            return;
        }

        if (!data.ready_years || data.ready_years.length === 0) {
            statusEl.innerHTML = `<span style="color:orange;">${data.message}</span>`;
            return;
        }

        // Build a readable summary of ready vs blocked years
        const detail = (data.year_detail || []).map(d => {
            if (!d.ready) {
                return `  ✗ ${d.year} — BLOCKED: ${d.missing[0]}`;
            }
            const warns = (d.warnings || []);
            if (warns.length === 0) return `  ✓ ${d.year} — all covariates present`;
            return `  ✓ ${d.year} — gap-fill will cover: ${warns.join('; ')}`;
        }).join('\n');

        const rawNote  = data.raw_note
            ? `\n⚙  Aggregation step: ${data.raw_note}\n`
            : '';

        const confirmed = confirm(
            `Build Master Grid\n` +
            `${'─'.repeat(40)}\n` +
            `${data.ready_years.length} year(s) ready: ${data.ready_years.join(', ')}\n\n` +
            `Coverage detail:\n${detail}\n` +
            rawNote +
            `\nCurrent rows in final_master_grid: ${(data.current_rows || 0).toLocaleString()}\n\n` +
            `Proceed? This may take several minutes.`
        );

        if (!confirmed) return;

        // Step 2 — execute
        btn.disabled    = true;
        btn.textContent = 'Building… (do not close this page)';
        statusEl.innerHTML = '<span style="color:#666;">Step 1: Aggregating raw observations… then merging covariates. Do not close this page.</span>';

        fetch('api/build_master_grid.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ confirmed: true })
        })
        .then(safeJson)
        .then(result => {
            btn.disabled    = false;
            btn.textContent = 'Build Master Grid';

            // Parse every log line and render as a scrollable pre block
            const logLines = (result.log || []).map(l => {
                try {
                    const p = JSON.parse(l);
                    return p.msg || l;
                } catch { return l; }
            });

            const logHtml = logLines.length
                ? `<pre style="max-height:200px;overflow-y:auto;background:var(--bg-secondary,#1e1e2e);color:var(--text-primary,#e2e8f0);padding:8px;font-size:12px;border-radius:4px;margin-top:8px;border:1px solid var(--border-color,#334155);">${logLines.map(l => {
                    const escaped = l.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
                    if (/error|fail|✗/i.test(l)) return `<span style="color:#f87171;">${escaped}</span>`;
                    if (/success|complete|✓/i.test(l)) return `<span style="color:#4ade80;">${escaped}</span>`;
                    if (/warning|warn/i.test(l)) return `<span style="color:#fbbf24;">${escaped}</span>`;
                    return `<span style="color:var(--text-secondary,#94a3b8);">${escaped}</span>`;
                }).join('\n')}</pre>`
                : '';

            if (result.success) {
                const lastMsg = logLines[logLines.length - 1] || 'Build complete.';
                statusEl.innerHTML = `<span style="color:#4ade80;">✓ ${lastMsg}</span>${logHtml}`;
            } else {
                const errMsg = result.error || 'Did not complete successfully.';
                statusEl.innerHTML = `<span style="color:#f87171;">✗ ${errMsg}</span>${logHtml}`;
            }
        })
        .catch(err => {
            btn.disabled    = false;
            btn.textContent = 'Build Master Grid';
            statusEl.innerHTML = `<span style="color:red;">Request failed: ${err.message}</span>`;
        });
    })
    .catch(err => {
        btn.disabled    = false;
        btn.textContent = 'Build Master Grid';
        statusEl.innerHTML = `<span style="color:red;">Coverage check failed: ${err.message}</span>`;
    });
}

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
        kba_richness_weight: document.getElementById('kbaRichnessWeight').value,
        kba_sensitive_weight: document.getElementById('kbaSensitiveWeight').value,
        kba_ndvi_weight: document.getElementById('kbaNdviWeight').value,
        kba_alan_weight: document.getElementById('kbaAlanWeight').value,
        kba_lst_weight: document.getElementById('kbaLstWeight').value,
        kba_precip_weight: document.getElementById('kbaPrecipWeight').value
    };

    fetch('api/save_thresholds.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showToast('Thresholds Saved', ['Dashboard and Reports will use the updated values on next refresh.'], 'success');
                try {
                    localStorage.setItem('avilight-thresholds-updated', String(Date.now()));
                } catch (e) {
                    // Ignore storage write failures.
                }
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

// ── Add User ──────────────────────────────────────────────────────────────────

function loadUserList() {
    fetch('api/list_users.php')
        .then(r => r.json())
        .then(data => {
            const tbody = document.getElementById('userListBody');
            if (!tbody || !data.success) return;
            tbody.innerHTML = data.users.map(u => {
                const lastLogin = u.last_login_at
                    ? u.last_login_at.substring(0, 16) + ' UTC'
                    : 'Never';
                return `<tr>
                    <td>${u.full_name ? u.full_name + ' <span style="color:#888;font-size:0.85em;">(' + u.email + ')</span>' : u.email}</td>
                    <td style="color:#666;font-size:0.82rem;">${lastLogin}</td>
                </tr>`;
            }).join('');
        })
        .catch(() => {});
}


document.getElementById('addUserForm').addEventListener('submit', function (e) {
    e.preventDefault();
    const fullName = document.getElementById('newUserFullName').value.trim();
    const email    = document.getElementById('newUserEmail').value.trim();
    const password = document.getElementById('newUserPassword').value;
    const statusEl = document.getElementById('addUserStatus');
    const btn      = document.getElementById('addUserBtn');

    btn.disabled = true;
    statusEl.innerHTML = '';

    const body = new FormData();
    body.append('full_name', fullName);
    body.append('email',     email);
    body.append('password',  password);

    fetch('api/add_user.php', { method: 'POST', body })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                statusEl.innerHTML = `<div class="alert alert-success">${data.message}</div>`;
                document.getElementById('newUserFullName').value = '';
                document.getElementById('newUserEmail').value    = '';
                document.getElementById('newUserPassword').value = '';
                loadUserList();
            } else {
                statusEl.innerHTML = `<div class="alert alert-danger">${data.error}</div>`;
            }
        })
        .catch(() => {
            statusEl.innerHTML = '<div class="alert alert-danger">Request failed. Check server connection.</div>';
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