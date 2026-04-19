<?php
$page_title = 'Data Ingestion Logs';
require_once 'includes/header.php';
require_once 'includes/auth.php';
require_once 'includes/db.php';

require_login();

$uploads      = [];
$rejections   = [];   // keyed by upload_log_id
$summary      = ['total_uploads' => 0, 'total_inserted' => 0, 'total_skipped' => 0, 'new_species' => 0];
$db_available = false;
$db_error     = '';

try {
    $pdo = get_mysql_db();
    $db_available = true;

    // Aggregate summary across all uploads
    $row = $pdo->query(
        "SELECT COUNT(*)                         AS total_uploads,
                COALESCE(SUM(rows_inserted), 0)  AS total_inserted,
                COALESCE(SUM(rows_skipped),  0)  AS total_skipped,
                COALESCE(SUM(new_species),   0)  AS new_species
           FROM upload_log"
    )->fetch(PDO::FETCH_ASSOC);
    if ($row) $summary = $row;

    // Recent 25 uploads, newest first
    $uploads = $pdo->query(
        "SELECT id, uploaded_by, filename, file_size_bytes, file_type, status,
                rows_total, rows_inserted, rows_skipped, new_species, fail_reason,
                ip_address, uploaded_at
           FROM upload_log
          ORDER BY uploaded_at DESC
          LIMIT 25"
    )->fetchAll(PDO::FETCH_ASSOC);

    // Pull all rejection rows for these uploads in one query (avoid N+1)
    if (!empty($uploads)) {
        $ids     = implode(',', array_map(fn($u) => (int) $u['id'], $uploads));
        $rej_rows = $pdo->query(
            "SELECT upload_log_id, row_number, observation_id, common_name,
                    latitude, longitude, reason
               FROM upload_rejection_log
              WHERE upload_log_id IN ({$ids})
              ORDER BY upload_log_id, row_number
              LIMIT 5000"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rej_rows as $rej) {
            $rejections[(int) $rej['upload_log_id']][] = $rej;
        }
    }
} catch (Throwable $e) {
    $db_available = false;
    $db_error     = $e->getMessage();
}

// Human-readable file size
function fmt_bytes(int $bytes): string {
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024)    return round($bytes / 1024, 1)    . ' KB';
    return $bytes . ' B';
}

// Badge class for upload status
function status_class(string $status): string {
    return match ($status) {
        'success' => 'success',
        'partial' => 'warning',
        default   => 'danger',
    };
}

// Human label for rejection reason enum
function reason_label(string $reason): string {
    return match ($reason) {
        'missing_fields'    => 'Missing Fields',
        'uncertain_species' => 'Uncertain Species',
        'invalid_date'      => 'Invalid Date',
        'out_of_bounds'     => 'Out of Bounds',
        'duplicate_in_file' => 'Duplicate in File',
        'duplicate_in_db'   => 'Duplicate in DB',
        default             => ucwords(str_replace('_', ' ', $reason)),
    };
}

function reason_badge_class(string $reason): string {
    return match ($reason) {
        'missing_fields'    => 'danger',
        'uncertain_species' => 'warning',
        'invalid_date'      => 'warning',
        'out_of_bounds'     => 'info',
        'duplicate_in_file' => 'secondary',
        'duplicate_in_db'   => 'secondary',
        default             => 'secondary',
    };
}
?>

<div class="page-header">
    <h1 class="page-title">Data Ingestion Logs</h1>
    <p class="page-subtitle">Audit trail of CSV/XLSX bird observation uploads — who uploaded, what was kept, and why rows were rejected.</p>
</div>

<?php if (!$db_available): ?>
<div class="alert alert-warning">
    <strong>Database unavailable.</strong> Cannot load ingestion logs. <?php echo htmlspecialchars($db_error); ?>
</div>
<?php else: ?>

<!-- Summary stat cards -->
<div class="stats-grid" style="grid-template-columns: repeat(4, 1fr);">
    <div class="stat-card">
        <div class="stat-label">Total Uploads</div>
        <div class="stat-value"><?php echo (int) $summary['total_uploads']; ?></div>
        <div class="stat-description">All-time upload attempts</div>
    </div>
    <div class="stat-card success">
        <div class="stat-label">Rows Inserted</div>
        <div class="stat-value"><?php echo number_format((int) $summary['total_inserted']); ?></div>
        <div class="stat-description">Observations accepted into DB</div>
    </div>
    <div class="stat-card warning">
        <div class="stat-label">Rows Rejected</div>
        <div class="stat-value"><?php echo number_format((int) $summary['total_skipped']); ?></div>
        <div class="stat-description">Cleaned out during validation</div>
    </div>
    <div class="stat-card info">
        <div class="stat-label">New Species Added</div>
        <div class="stat-value"><?php echo number_format((int) $summary['new_species']); ?></div>
        <div class="stat-description">Auto-registered from uploads</div>
    </div>
</div>

<!-- Upload History -->
<div class="card">
    <div class="card-header">Recent Upload History <span style="font-size:0.75rem;font-weight:400;opacity:0.7;">(last 25)</span></div>
    <div class="card-body" style="padding: 0;">
        <?php if (empty($uploads)): ?>
        <div style="padding: 24px; text-align: center; color: #666;">
            No uploads recorded yet. Upload a CSV or XLSX file via the Upload tab.
        </div>
        <?php else: ?>
        <table style="width:100%; border-collapse: collapse; font-size: 0.875rem;">
            <thead>
                <tr style="background: rgba(255,255,255,0.05); border-bottom: 1px solid rgba(255,255,255,0.1);">
                    <th style="padding: 10px 16px; text-align:left; font-weight:600;">Uploaded At</th>
                    <th style="padding: 10px 16px; text-align:left; font-weight:600;">User</th>
                    <th style="padding: 10px 16px; text-align:left; font-weight:600;">File</th>
                    <th style="padding: 10px 16px; text-align:center; font-weight:600;">Status</th>
                    <th style="padding: 10px 16px; text-align:right; font-weight:600;">Total</th>
                    <th style="padding: 10px 16px; text-align:right; font-weight:600;">Inserted</th>
                    <th style="padding: 10px 16px; text-align:right; font-weight:600;">Rejected</th>
                    <th style="padding: 10px 16px; text-align:right; font-weight:600;">New Spp.</th>
                    <th style="padding: 10px 16px; text-align:left; font-weight:600;">Details</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($uploads as $ul):
                $ul_id      = (int) $ul['id'];
                $rej_for_ul = $rejections[$ul_id] ?? [];
                $has_rej    = !empty($rej_for_ul);
            ?>
                <tr style="border-bottom: 1px solid rgba(255,255,255,0.05);">
                    <td style="padding: 10px 16px; white-space: nowrap;">
                        <?php echo htmlspecialchars($ul['uploaded_at'] ?? '—'); ?>
                    </td>
                    <td style="padding: 10px 16px;">
                        <?php echo htmlspecialchars($ul['uploaded_by'] ?? '—'); ?>
                    </td>
                    <td style="padding: 10px 16px;">
                        <span title="<?php echo htmlspecialchars($ul['filename']); ?>">
                            <?php echo htmlspecialchars(mb_strimwidth($ul['filename'], 0, 32, '…')); ?>
                        </span>
                        <span style="color:#888; font-size:0.8em; margin-left:4px;">
                            (<?php echo strtoupper(htmlspecialchars($ul['file_type'] ?? '')); ?>,
                            <?php echo fmt_bytes((int) ($ul['file_size_bytes'] ?? 0)); ?>)
                        </span>
                    </td>
                    <td style="padding: 10px 16px; text-align:center;">
                        <span class="badge badge-<?php echo status_class($ul['status'] ?? 'failed'); ?>">
                            <?php echo ucfirst(htmlspecialchars($ul['status'] ?? 'failed')); ?>
                        </span>
                    </td>
                    <td style="padding: 10px 16px; text-align:right;"><?php echo $ul['rows_total'] !== null ? (int)$ul['rows_total'] : '—'; ?></td>
                    <td style="padding: 10px 16px; text-align:right; color: #4ade80;"><?php echo $ul['rows_inserted'] !== null ? (int)$ul['rows_inserted'] : '—'; ?></td>
                    <td style="padding: 10px 16px; text-align:right; color: #f87171;"><?php echo $ul['rows_skipped'] !== null ? (int)$ul['rows_skipped'] : '—'; ?></td>
                    <td style="padding: 10px 16px; text-align:right;"><?php echo $ul['new_species'] !== null ? (int)$ul['new_species'] : '—'; ?></td>
                    <td style="padding: 10px 16px;">
                        <?php if (!empty($ul['fail_reason'])): ?>
                        <span style="color:#f87171; font-size:0.8em;" title="<?php echo htmlspecialchars($ul['fail_reason']); ?>">
                            ✗ <?php echo htmlspecialchars(mb_strimwidth($ul['fail_reason'], 0, 60, '…')); ?>
                        </span>
                        <?php elseif ($has_rej): ?>
                        <button class="btn btn-secondary"
                                style="padding:3px 10px; font-size:0.78rem;"
                                onclick="toggleRejections(<?php echo $ul_id; ?>)">
                            Show <?php echo count($rej_for_ul); ?> rejected rows
                        </button>
                        <?php else: ?>
                        <span style="color:#4ade80; font-size:0.8em;">✓ All rows accepted</span>
                        <?php endif; ?>
                    </td>
                </tr>

                <?php if ($has_rej): ?>
                <tr id="rej-<?php echo $ul_id; ?>" style="display:none;">
                    <td colspan="9" style="padding: 0 16px 16px 16px; background: rgba(0,0,0,0.2);">
                        <div style="margin-top: 10px;">
                            <strong style="font-size:0.85rem;">Rejection details for upload #<?php echo $ul_id; ?></strong>
                            <table style="width:100%; border-collapse:collapse; font-size:0.8rem; margin-top:8px;">
                                <thead>
                                    <tr style="background:rgba(255,255,255,0.05);">
                                        <th style="padding:6px 10px; text-align:left;">Row #</th>
                                        <th style="padding:6px 10px; text-align:left;">Observation ID</th>
                                        <th style="padding:6px 10px; text-align:left;">Common Name</th>
                                        <th style="padding:6px 10px; text-align:right;">Lat</th>
                                        <th style="padding:6px 10px; text-align:right;">Lon</th>
                                        <th style="padding:6px 10px; text-align:center;">Reason</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($rej_for_ul as $rej): ?>
                                    <tr style="border-top:1px solid rgba(255,255,255,0.05);">
                                        <td style="padding:5px 10px;"><?php echo (int) $rej['row_number']; ?></td>
                                        <td style="padding:5px 10px; font-size:0.75rem; color:#aaa;" title="<?php echo htmlspecialchars($rej['observation_id']); ?>">
                                            <?php echo htmlspecialchars(mb_strimwidth($rej['observation_id'], 0, 30, '…')); ?>
                                        </td>
                                        <td style="padding:5px 10px;"><?php echo htmlspecialchars($rej['common_name']); ?></td>
                                        <td style="padding:5px 10px; text-align:right;"><?php echo htmlspecialchars($rej['latitude']); ?></td>
                                        <td style="padding:5px 10px; text-align:right;"><?php echo htmlspecialchars($rej['longitude']); ?></td>
                                        <td style="padding:5px 10px; text-align:center;">
                                            <span class="badge badge-<?php echo reason_badge_class($rej['reason']); ?>">
                                                <?php echo reason_label($rej['reason']); ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                            <?php
                            $total_rej = (int) ($ul['rows_skipped'] ?? 0);
                            $shown     = count($rej_for_ul);
                            if ($total_rej > $shown):
                            ?>
                            <p style="margin-top:8px; font-size:0.78rem; color:#aaa;">
                                Showing <?php echo $shown; ?> of <?php echo $total_rej; ?> rejected rows.
                            </p>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endif; ?>

            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<?php endif; // db_available ?>

<!-- Validation Algorithm Explanation -->
<div class="card">
    <div class="card-header">Row Validation Algorithm</div>
    <div class="card-body">
        <p style="color:#aaa; margin-bottom:16px;">
            Every uploaded file passes through a 10-step pipeline. Rows that fail any cleaning
            rule are logged with a reason code and excluded from the database insert.
        </p>
        <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 12px;">

            <div style="padding:14px; background:rgba(248,113,113,0.08); border:1px solid rgba(248,113,113,0.25); border-radius:8px;">
                <div style="display:flex; align-items:center; gap:8px; margin-bottom:6px;">
                    <span class="badge badge-danger">Missing Fields</span>
                </div>
                <p style="font-size:0.85rem; color:#ccc; margin:0;">
                    Row has an empty <em>GLOBAL UNIQUE IDENTIFIER</em> or an empty <em>COMMON NAME</em>.
                    Both are mandatory primary identifiers — a row without them cannot be stored or
                    linked to a species.
                </p>
            </div>

            <div style="padding:14px; background:rgba(251,191,36,0.08); border:1px solid rgba(251,191,36,0.25); border-radius:8px;">
                <div style="display:flex; align-items:center; gap:8px; margin-bottom:6px;">
                    <span class="badge badge-warning">Uncertain Species</span>
                </div>
                <p style="font-size:0.85rem; color:#ccc; margin:0;">
                    Common name contains <code>&quot; sp.&quot;</code> (e.g. <em>Columbidae sp.</em>) or
                    a slash <code>/</code> (e.g. <em>Common/Pacific Swift</em>).
                    These indicate unresolved identifications that would contaminate
                    species-richness counts.
                </p>
            </div>

            <div style="padding:14px; background:rgba(251,191,36,0.08); border:1px solid rgba(251,191,36,0.25); border-radius:8px;">
                <div style="display:flex; align-items:center; gap:8px; margin-bottom:6px;">
                    <span class="badge badge-warning">Invalid Date</span>
                </div>
                <p style="font-size:0.85rem; color:#ccc; margin:0;">
                    The <em>OBSERVATION DATE</em> cannot be parsed into a valid calendar date.
                    Excel serial numbers (e.g. <code>45292</code>) are converted automatically
                    before parsing. If conversion still fails the row is dropped.
                </p>
            </div>

            <div style="padding:14px; background:rgba(96,165,250,0.08); border:1px solid rgba(96,165,250,0.25); border-radius:8px;">
                <div style="display:flex; align-items:center; gap:8px; margin-bottom:6px;">
                    <span class="badge badge-info">Out of Bounds</span>
                </div>
                <p style="font-size:0.85rem; color:#ccc; margin:0;">
                    Coordinates fall outside the Metro Manila study area bounding box:
                    lat <strong>14.3 – 14.8 °N</strong>, lon <strong>120.9 – 121.15 °E</strong>.
                    Observations outside this area cannot be linked to the environmental
                    covariate grid.
                </p>
            </div>

            <div style="padding:14px; background:rgba(148,163,184,0.08); border:1px solid rgba(148,163,184,0.2); border-radius:8px;">
                <div style="display:flex; align-items:center; gap:8px; margin-bottom:6px;">
                    <span class="badge badge-secondary">Duplicate in File</span>
                </div>
                <p style="font-size:0.85rem; color:#ccc; margin:0;">
                    The same <em>GLOBAL UNIQUE IDENTIFIER</em> appears more than once within
                    the uploaded file. The first occurrence is kept; all subsequent occurrences
                    are rejected to prevent double-counting.
                </p>
            </div>

            <div style="padding:14px; background:rgba(148,163,184,0.08); border:1px solid rgba(148,163,184,0.2); border-radius:8px;">
                <div style="display:flex; align-items:center; gap:8px; margin-bottom:6px;">
                    <span class="badge badge-secondary">Duplicate in DB</span>
                </div>
                <p style="font-size:0.85rem; color:#ccc; margin:0;">
                    An observation ID already exists in <code>raw_bird_observation</code>.
                    The entire upload is aborted and rolled back to prevent partial inserts.
                    Remove the overlapping records from the file and re-upload.
                </p>
            </div>

        </div>

        <div style="margin-top:16px; padding:14px; background:rgba(74,222,128,0.06); border:1px solid rgba(74,222,128,0.2); border-radius:8px;">
            <strong style="color:#4ade80;">Accepted rows</strong>
            <p style="font-size:0.85rem; color:#ccc; margin:6px 0 0;">
                Rows that pass all cleaning rules are batch-inserted into
                <code>raw_bird_observation</code> in chunks of 500. Unknown species names
                are automatically registered in <code>species_masterlist</code> with blank
                <em>light_tolerance</em> and <em>migratory_status</em> fields for later
                review by an administrator.
            </p>
        </div>
    </div>
</div>

<?php
$extra_scripts = <<<'SCRIPTS'
<script>
function toggleRejections(uploadId) {
    var row = document.getElementById('rej-' + uploadId);
    if (!row) return;
    var btn = event.target;
    if (row.style.display === 'none' || row.style.display === '') {
        row.style.display = 'table-row';
        btn.textContent = btn.textContent.replace('Show', 'Hide');
    } else {
        row.style.display = 'none';
        btn.textContent = btn.textContent.replace('Hide', 'Show');
    }
}
</script>
SCRIPTS;

require_once 'includes/footer.php';
?>
