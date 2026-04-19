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

// ── Validation & rejection log summary ───────────────────────────────────
// One row per (upload, reason) — gives a count of how many rows were rejected
// for each reason in each upload so the table can show a natural-language issue.
$validation_log_rows = [];
try {
    $vl_db = get_mysql_db();
    $validation_log_rows = $vl_db->query(
        "SELECT ul.uploaded_at, ul.uploaded_by, ul.filename,
                url.reason,
                COUNT(*) AS cnt
           FROM upload_rejection_log url
           JOIN upload_log ul ON ul.id = url.upload_log_id
          WHERE ul.uploaded_at >= NOW() - INTERVAL 1 HOUR
          GROUP BY url.upload_log_id, url.reason
          ORDER BY ul.uploaded_at DESC, url.reason
          LIMIT 5"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // Non-fatal — tables may not exist yet
}

// ── Spatial integrity checks against live DB ─────────────────────────────
// Runs on every admin page load — all queries are indexed COUNT(*) so they
// are fast even on large tables. Checks cover raw_bird_observation and
// aggregated_bird_observation only; land_cover is intentionally excluded
// (null cells are expected and filled during the merge step).
$spatial_checks  = [];
$spatial_db_ok   = false;
$LAT_MIN = 14.3; $LAT_MAX = 14.8;
$LON_MIN = 120.9; $LON_MAX = 121.15;

try {
    $sp_db = get_mysql_db();
    $spatial_db_ok = true;

    // ── raw_bird_observation ──────────────────────────────────────────────
    $raw_total = (int) $sp_db->query('SELECT COUNT(*) FROM raw_bird_observation')->fetchColumn();

    // 1. Null coordinates
    $null_coords = (int) $sp_db->query(
        'SELECT COUNT(*) FROM raw_bird_observation WHERE latitude IS NULL OR longitude IS NULL'
    )->fetchColumn();
    $spatial_checks[] = [
        'label'  => 'No null coordinates in raw observations',
        'pass'   => $null_coords === 0,
        'detail' => $null_coords > 0 ? "{$null_coords} row(s) have NULL latitude or longitude" : null,
        'table'  => 'raw_bird_observation',
    ];

    // 2. Zero coordinates (often a GPS/export artifact)
    $zero_coords = (int) $sp_db->query(
        'SELECT COUNT(*) FROM raw_bird_observation WHERE latitude = 0 OR longitude = 0'
    )->fetchColumn();
    $spatial_checks[] = [
        'label'  => 'No zero-value coordinates in raw observations',
        'pass'   => $zero_coords === 0,
        'detail' => $zero_coords > 0 ? "{$zero_coords} row(s) have lat=0 or lon=0 (likely missing GPS fix)" : null,
        'table'  => 'raw_bird_observation',
    ];

    // 3. Latitude within Metro Manila range
    $oob_lat = (int) $sp_db->query(
        "SELECT COUNT(*) FROM raw_bird_observation
          WHERE latitude IS NOT NULL AND (latitude < {$LAT_MIN} OR latitude > {$LAT_MAX})"
    )->fetchColumn();
    $spatial_checks[] = [
        'label'  => "Latitude within {$LAT_MIN}°–{$LAT_MAX}° N (Metro Manila)",
        'pass'   => $oob_lat === 0,
        'detail' => $oob_lat > 0 ? "{$oob_lat} observation(s) outside latitude range {$LAT_MIN}°–{$LAT_MAX}° N" : null,
        'table'  => 'raw_bird_observation',
    ];

    // 4. Longitude within Metro Manila range
    $oob_lon = (int) $sp_db->query(
        "SELECT COUNT(*) FROM raw_bird_observation
          WHERE longitude IS NOT NULL AND (longitude < {$LON_MIN} OR longitude > {$LON_MAX})"
    )->fetchColumn();
    $spatial_checks[] = [
        'label'  => "Longitude within {$LON_MIN}°–{$LON_MAX}° E (Metro Manila)",
        'pass'   => $oob_lon === 0,
        'detail' => $oob_lon > 0 ? "{$oob_lon} observation(s) outside longitude range {$LON_MIN}°–{$LON_MAX}° E" : null,
        'table'  => 'raw_bird_observation',
    ];

    // 5. No duplicate observation IDs in raw table
    $dup_obs = (int) $sp_db->query(
        'SELECT COUNT(*) FROM (
             SELECT observation_id FROM raw_bird_observation
             GROUP BY observation_id HAVING COUNT(*) > 1
         ) AS d'
    )->fetchColumn();
    $spatial_checks[] = [
        'label'  => 'No duplicate observation IDs in raw observations',
        'pass'   => $dup_obs === 0,
        'detail' => $dup_obs > 0 ? "{$dup_obs} observation ID(s) appear more than once" : null,
        'table'  => 'raw_bird_observation',
    ];

    // ── aggregated_bird_observation ───────────────────────────────────────
    $agg_total = (int) $sp_db->query('SELECT COUNT(*) FROM aggregated_bird_observation')->fetchColumn();

    // 6. All aggregated rows snapped to a grid cell
    $unsnapped = (int) $sp_db->query(
        'SELECT COUNT(*) FROM aggregated_bird_observation WHERE grid_lat IS NULL OR grid_lon IS NULL'
    )->fetchColumn();
    $spatial_checks[] = [
        'label'  => 'All aggregated observations snapped to a grid cell',
        'pass'   => $unsnapped === 0,
        'detail' => $unsnapped > 0 ? "{$unsnapped} aggregated row(s) have no grid_lat/grid_lon — re-run aggregation to fix" : null,
        'table'  => 'aggregated_bird_observation',
    ];

    // 7. Aggregated grid coordinates within Metro Manila
    $agg_oob = (int) $sp_db->query(
        "SELECT COUNT(*) FROM aggregated_bird_observation
          WHERE grid_lat IS NOT NULL AND grid_lon IS NOT NULL
            AND (grid_lat < {$LAT_MIN} OR grid_lat > {$LAT_MAX}
              OR grid_lon < {$LON_MIN} OR grid_lon > {$LON_MAX})"
    )->fetchColumn();
    $spatial_checks[] = [
        'label'  => 'All aggregated grid cells within Metro Manila bounds',
        'pass'   => $agg_oob === 0,
        'detail' => $agg_oob > 0 ? "{$agg_oob} aggregated row(s) have grid coordinates outside Metro Manila bounding box" : null,
        'table'  => 'aggregated_bird_observation',
    ];

    // 8. Coordinates are realistic (within Philippines — catch gross errors)
    $non_ph = (int) $sp_db->query(
        'SELECT COUNT(*) FROM raw_bird_observation
          WHERE latitude  IS NOT NULL AND (latitude  < 4.5  OR latitude  > 21.5)
             OR longitude IS NOT NULL AND (longitude < 116.0 OR longitude > 127.0)'
    )->fetchColumn();
    $spatial_checks[] = [
        'label'  => 'All coordinates within Philippines extent (4.5°–21.5° N, 116°–127° E)',
        'pass'   => $non_ph === 0,
        'detail' => $non_ph > 0 ? "{$non_ph} observation(s) fall outside the Philippines entirely" : null,
        'table'  => 'raw_bird_observation',
    ];

} catch (Throwable $e) {
    $spatial_db_ok = false;
}

$spatial_all_pass = $spatial_db_ok && !empty($spatial_checks)
    && count(array_filter($spatial_checks, fn($c) => !$c['pass'])) === 0;

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
         LIMIT 20'
    )->fetchAll(PDO::FETCH_ASSOC);

    $recent_failures = $log_db->query(
        'SELECT email, ip_address, attempted_at
         FROM login_attempts
         WHERE success = 0
         ORDER BY attempted_at DESC
         LIMIT 20'
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // Non-fatal – tables may not exist yet; logs will show empty
    error_log('[AVILIGHT] admin security log query error: ' . $e->getMessage());
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

        </div>

        <hr style="margin: 20px 0;">

        <h4>KBA/PA Audit Effectiveness Weights</h4>
        <p style="margin: 0 0 14px 0; color: #666;">
            Suggested weights for the 6-pillar KBA/PA audit score. The total may be kept at 100% for the current formula.
        </p>

        <div class="grid-2">
            <div>
                <div class="form-group">
                    <label class="form-label">Richness Weight (%)</label>
                    <input type="number" class="form-control" value="15" min="0" max="100" step="0.1" id="kbaRichnessWeight">
                </div>
                <div class="form-group">
                    <label class="form-label">Sensitive Species Weight (%)</label>
                    <input type="number" class="form-control" value="15" min="0" max="100" step="0.1" id="kbaSensitiveWeight">
                </div>
                <div class="form-group">
                    <label class="form-label">NDVI Weight (%)</label>
                    <input type="number" class="form-control" value="20" min="0" max="100" step="0.1" id="kbaNdviWeight">
                </div>
            </div>

            <div>
                <div class="form-group">
                    <label class="form-label">ALAN Weight (%)</label>
                    <input type="number" class="form-control" value="15" min="0" max="100" step="0.1" id="kbaAlanWeight">
                </div>
                <div class="form-group">
                    <label class="form-label">LST Weight (%)</label>
                    <input type="number" class="form-control" value="15" min="0" max="100" step="0.1" id="kbaLstWeight">
                </div>
                <div class="form-group">
                    <label class="form-label">Precipitation Weight (%)</label>
                    <input type="number" class="form-control" value="10" min="0" max="100" step="0.1" id="kbaPrecipWeight">
                </div>
                <div class="form-group">
                    <label class="form-label">Total Weight (%)</label>
                    <input type="text" class="form-control" id="kbaWeightTotal" value="100.0" readonly>
                    <small style="color: #666;">A total of 100% is suggested for the current scoring model.</small>
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
        <?php
        // Maps rejection reason → [type badge class, type label, issue template, status label, status badge class]
        $reason_meta = [
            'out_of_bounds'     => ['warning',   'Spatial',   '%d observation(s) outside Metro Manila bounding box (lat 14.3–14.8 / lon 120.9–121.15) in "%s"',              'Rejected', 'danger'],
            'missing_fields'    => ['info',      'Format',    '%d row(s) missing GLOBAL UNIQUE IDENTIFIER or COMMON NAME in "%s"',                                            'Rejected', 'danger'],
            'uncertain_species' => ['warning',   'Species',   '%d uncertain species record(s) (contains " sp." or "/") in "%s"',                                             'Rejected', 'danger'],
            'invalid_date'      => ['info',      'Format',    '%d row(s) with unparseable OBSERVATION DATE in "%s"',                                                          'Resolved', 'success'],
            'duplicate_in_file' => ['secondary', 'Duplicate', '%d duplicate GLOBAL UNIQUE IDENTIFIER(s) removed within file "%s"',                                           'Cleaned',  'success'],
            'duplicate_in_db'   => ['danger',    'Duplicate', '%d observation ID(s) already exist in the database — upload from "%s" was aborted and rolled back',           'Aborted',  'danger'],
        ];
        ?>
        <table>
            <thead>
                <tr>
                    <th>Timestamp</th>
                    <th>Uploaded By</th>
                    <th>Type</th>
                    <th>Issue</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody id="validationLogBody">
                <?php if (empty($validation_log_rows)): ?>
                <tr>
                    <td colspan="5" style="text-align:center; color:#888; padding: 16px;">
                        No validation issues in the last hour. Issues are logged automatically when CSV/XLSX files are uploaded.
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($validation_log_rows as $vl):
                    $reason  = $vl['reason'] ?? 'unknown';
                    $meta    = $reason_meta[$reason] ?? ['secondary', ucwords(str_replace('_',' ',$reason)), '%d issue(s) in "%s"', 'Rejected', 'danger'];
                    [$type_badge, $type_label, $issue_tpl, $status_label, $status_badge] = $meta;
                    $issue_text = sprintf($issue_tpl, (int)$vl['cnt'], basename($vl['filename'] ?? ''));
                ?>
                <tr>
                    <td style="white-space:nowrap;"><?php echo htmlspecialchars($vl['uploaded_at'] ?? '—'); ?></td>
                    <td><?php echo htmlspecialchars($vl['uploaded_by'] ?? '—'); ?></td>
                    <td><span class="badge badge-<?php echo $type_badge; ?>"><?php echo $type_label; ?></span></td>
                    <td><?php echo htmlspecialchars($issue_text); ?></td>
                    <td><span class="badge badge-<?php echo $status_badge; ?>"><?php echo $status_label; ?></span></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Spatial Integrity Checks -->
<div class="card">
    <h2 class="card-header">Spatial Integrity Checks</h2>
    <div class="card-body">
        <?php if (!$spatial_db_ok): ?>
        <div class="alert alert-warning">Database unavailable — spatial checks could not be run.</div>
        <?php elseif (empty($spatial_checks)): ?>
        <div class="alert alert-info">No observation data found. Upload bird observation data to run spatial checks.</div>
        <?php else: ?>

        <?php if ($spatial_all_pass): ?>
        <div style="padding: 12px 16px; background: rgba(34,197,94,0.1); border: 1px solid rgba(34,197,94,0.3); border-radius: 8px; margin-bottom: 14px;">
            <strong style="color:#4ade80;">✓ All spatial integrity checks passed.</strong>
        </div>
        <?php else:
            $fail_count = count(array_filter($spatial_checks, fn($c) => !$c['pass']));
        ?>
        <div style="padding: 12px 16px; background: rgba(248,113,113,0.1); border: 1px solid rgba(248,113,113,0.3); border-radius: 8px; margin-bottom: 14px;">
            <strong style="color:#f87171;">⚠ <?php echo $fail_count; ?> check(s) failed — review the issues below.</strong>
        </div>
        <?php endif; ?>

        <table>
            <thead>
                <tr>
                    <th>Check</th>
                    <th>Table</th>
                    <th>Result</th>
                    <th>Detail</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($spatial_checks as $chk): ?>
                <tr>
                    <td><?php echo htmlspecialchars($chk['label']); ?></td>
                    <td><code style="font-size:0.8rem;"><?php echo htmlspecialchars($chk['table']); ?></code></td>
                    <td>
                        <?php if ($chk['pass']): ?>
                        <span class="badge badge-success">✓ Pass</span>
                        <?php else: ?>
                        <span class="badge badge-danger">✗ Fail</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:0.875rem; color:<?php echo $chk['pass'] ? '#4ade80' : '#f87171'; ?>;">
                        <?php echo $chk['pass']
                            ? 'OK'
                            : htmlspecialchars($chk['detail'] ?? 'Issue detected'); ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php endif; ?>
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
<div class="grid-2">
    <div class="card">
        <h2 class="card-header">Security & Access Logs</h2>
        <div class="card-body">
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:24px;">

                <!-- Recent Activity -->
                <div>
                    <h4 style="margin-bottom:10px;">Recent Activity</h4>
                    <div style="overflow-x:auto;">
                        <table style="font-size:0.85rem; width:100%;">
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
                                    <tr>
                                        <td style="max-width:120px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?= htmlspecialchars($row['email']) ?>"><?= htmlspecialchars($row['email']) ?></td>
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
                                        <td style="max-width:140px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?= htmlspecialchars($row['email']) ?>"><?= htmlspecialchars($row['email']) ?></td>
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
// ── Validation log loader ─────────────────────────────────────────────────────

const REASON_META = {
    out_of_bounds:     ['warning',   'Spatial',   (n, f) => `${n} observation(s) outside Metro Manila bounding box (lat 14.3–14.8 / lon 120.9–121.15) in "${f}"`,    'Rejected', 'danger'],
    missing_fields:    ['info',      'Format',    (n, f) => `${n} row(s) missing GLOBAL UNIQUE IDENTIFIER or COMMON NAME in "${f}"`,                                  'Rejected', 'danger'],
    uncertain_species: ['warning',   'Species',   (n, f) => `${n} uncertain species record(s) (contains " sp." or "/") in "${f}"`,                                   'Rejected', 'danger'],
    invalid_date:      ['info',      'Format',    (n, f) => `${n} row(s) with unparseable OBSERVATION DATE in "${f}"`,                                                'Resolved', 'success'],
    duplicate_in_file: ['secondary', 'Duplicate', (n, f) => `${n} duplicate GLOBAL UNIQUE IDENTIFIER(s) removed within file "${f}"`,                                 'Cleaned',  'success'],
    duplicate_in_db:   ['danger',    'Duplicate', (n, f) => `${n} observation ID(s) already exist in the database — upload from "${f}" was aborted and rolled back`, 'Aborted',  'danger'],
};

function loadValidationLog() {
    fetch('api/get_validation_log.php')
        .then(r => r.json())
        .then(data => {
            const tbody = document.getElementById('validationLogBody');
            if (!tbody) return;
            if (!data.success || !data.rows.length) {
                tbody.innerHTML = `<tr><td colspan="5" style="text-align:center;color:#888;padding:16px;">No validation issues in the last hour. Issues are logged automatically when CSV/XLSX files are uploaded.</td></tr>`;
                return;
            }
            tbody.innerHTML = data.rows.map(row => {
                const meta   = REASON_META[row.reason] || ['secondary', row.reason, (n, f) => `${n} issue(s) in "${f}"`, 'Rejected', 'danger'];
                const [typeBadge, typeLabel, issueFn, statusLabel, statusBadge] = meta;
                const issue  = issueFn(row.cnt, row.filename ? row.filename.split(/[\\/]/).pop() : '');
                return `<tr>
                    <td style="white-space:nowrap;">${row.uploaded_at || '—'}</td>
                    <td>${row.uploaded_by || '—'}</td>
                    <td><span class="badge badge-${typeBadge}">${typeLabel}</span></td>
                    <td>${issue}</td>
                    <td><span class="badge badge-${statusBadge}">${statusLabel}</span></td>
                </tr>`;
            }).join('');
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
                const added = Number(data.inserted || 0).toLocaleString();
                statusDiv.innerHTML = `<div class="alert alert-info"><strong>✓ Upload complete &mdash; ${added} record(s) added.</strong></div>`;
                rebuildAnalyticsCache(true);
                loadValidationLog();
            } else {
                statusDiv.innerHTML = `<div class="alert alert-danger">${data.error || 'Upload failed.'}</div>`;
                loadValidationLog();
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
                alert(data.message || 'Thresholds saved. Dashboard and Reports will use the updated values on refresh.');
                try {
                    localStorage.setItem('avilight-thresholds-updated', String(Date.now()));
                } catch (e) {
                    // Ignore storage write failures.
                }
            } else {
                alert('Error: ' + (data.error || 'Failed to save thresholds'));
            }
        })
        .catch(() => alert('Request failed. Check server connection.'));
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
