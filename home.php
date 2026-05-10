<?php
require_once 'includes/auth.php';
require_login();

// Handle agreement acceptance before any HTML output
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accept_agreement'])) {
    record_agreement_acceptance();
    header('Location: home.php');
    exit;
}

$show_agreement_modal = !has_accepted_agreement();

$page_title = 'Home';
require_once 'includes/header.php';

// Load data
require_once 'includes/db.php';
$total_species = 0;

function loadHomeThresholdConfig(): array {
    $defaults = [
        'high_risk'           => 60.0,
        'mod_risk'            => 40.0,
        'low_risk'            => 25.0,
        'kba_richness_weight' => 15.0,
        'kba_sensitive_weight' => 15.0,
        'kba_ndvi_weight' => 15.0,
        'kba_alan_weight' => 15.0,
        'kba_lst_weight' => 15.0,
        'kba_precip_weight' => 10.0,
    ];

    $path = __DIR__ . '/data/cache/thresholds.json';
    if (!is_readable($path)) {
        return $defaults;
    }

    $stored = json_decode((string) file_get_contents($path), true);
    if (!is_array($stored)) {
        return $defaults;
    }

    foreach ($defaults as $key => $value) {
        if (array_key_exists($key, $stored) && is_numeric($stored[$key])) {
            $defaults[$key] = (float) $stored[$key];
        }
    }

    return $defaults;
}

function homeStatusFromEffectiveness(float $score): string {
    if ($score < 40) return 'Critical';
    if ($score < 60) return 'At Risk';
    if ($score < 75) return 'Moderate';
    return 'Good';
}

function homeComputeReportsStatus(array $row, array $weights): string {
    $gridCells = max(1, (int) ($row['grid_cell_count'] ?? 0));
    $speciesCount = (int) ($row['species_count'] ?? 0);
    $sensitiveCount = (int) ($row['sensitive_species_count'] ?? 0);
    $sensitivePercentDb = isset($row['sensitive_species_percent']) ? (float) $row['sensitive_species_percent'] : null;

    $lightExposure = (float) ($row['light_exposure'] ?? 0);
    $meanNdvi = (float) ($row['mean_ndvi'] ?? 0);
    $maxLst = isset($row['max_lst']) && $row['max_lst'] !== null ? (float) $row['max_lst'] : null;
    $precipTotalRaw = $row['precipitation_total'] ?? null;
    $precipTotal = ($precipTotalRaw !== null && (float) $precipTotalRaw > 0.0) ? (float) $precipTotalRaw : null;

    $scoreRichness = min(1.0, $speciesCount / 50.0);
    $scoreAlan = max(0.0, 1.0 - ($lightExposure / 60.0));
    $scoreSensitive = $sensitivePercentDb !== null
        ? max(0.0, min(1.0, $sensitivePercentDb / 100.0))
        : ($sensitiveCount / max(1, $speciesCount));
    $scoreNdvi = min(1.0, $meanNdvi / 0.5);
    $scoreLst = $maxLst !== null ? max(0.0, 1.0 - ($maxLst / 45.0)) : 0.0;
    $scorePrecip = $precipTotal !== null ? min(1.0, $precipTotal / 300.0) : 0.0;

    $effectiveness = (
        ($scoreRichness * $weights['kba_richness_weight']) +
        ($scoreSensitive * $weights['kba_sensitive_weight']) +
        ($scoreNdvi * $weights['kba_ndvi_weight']) +
        ($scoreAlan * $weights['kba_alan_weight']) +
        ($scoreLst * $weights['kba_lst_weight']) +
        ($scorePrecip * $weights['kba_precip_weight'])
    );

    return homeStatusFromEffectiveness(round($effectiveness, 1));
}

// Load KBA/PA monitoring rows directly from the same audit table used by Reports.
$weights = loadHomeThresholdConfig();
$kba_data = [];
$db_failed = false;
$avg_radiance = 0.0;
$avg_radiance_year = null;
$total_critical      = 0;
$total_atrisk        = 0;
$kba_critical_count  = 0;
$kba_atrisk_count    = 0;
$pa_critical_count   = 0;
$pa_atrisk_count     = 0;
$kba_worst_status    = 'Moderate'; // tracks worst health for KBA card color
$pa_worst_status     = 'Moderate'; // tracks worst health for PA  card color
$audit_updated_at    = null;
$audit_snapshot_year = null;
try {
    $mysql  = get_mysql_db();

    // Match Species tab total source.
    $total_species = (int) $mysql->query('SELECT COUNT(*) FROM species_masterlist')->fetchColumn();

    $stmt = $mysql->query("SELECT area_name, area_type, species_count, light_exposure,
        sensitive_species_count, sensitive_species_percent,
        mean_ndvi, max_lst, precipitation_total, grid_cell_count, status
        FROM kba_pa_audit_live
        ORDER BY area_name ASC");
    $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

    foreach ($rows as $idx => $row) {
        $kba_data[] = [
            'id' => $idx + 1,
            'name' => (string) ($row['area_name'] ?? 'Unknown Site'),
            'type' => strtoupper((string) ($row['area_type'] ?? '')) === 'PA' ? 'PA' : 'KBA',
            'species_count' => isset($row['species_count']) ? (int) $row['species_count'] : null,
            'light_exposure' => isset($row['light_exposure']) ? (float) $row['light_exposure'] : null,
            'status' => homeComputeReportsStatus($row, $weights),
        ];
    }

    // Audit metadata: last refresh timestamp + snapshot year (single fast aggregation).
    $auditMetaRow = $mysql->query(
        "SELECT MAX(updated_at) AS last_run, MAX(snapshot_year) AS snap_year FROM kba_pa_audit_live"
    )->fetch(PDO::FETCH_ASSOC) ?: [];
    $audit_updated_at    = $auditMetaRow['last_run']   ?? null;
    $audit_snapshot_year = isset($auditMetaRow['snap_year']) ? (int) $auditMetaRow['snap_year'] : null;

    // Status counts + worst-per-type for card accent colors (no extra query needed).
    $statusRank = ['Critical' => 3, 'At Risk' => 2, 'Moderate' => 1, 'Good' => 0];
    foreach ($kba_data as $area) {
        $s = $area['status'];
        if ($s === 'Critical') {
            $total_critical++;
            ($area['type'] === 'KBA') ? $kba_critical_count++ : $pa_critical_count++;
        } elseif ($s === 'At Risk') {
            $total_atrisk++;
            ($area['type'] === 'KBA') ? $kba_atrisk_count++ : $pa_atrisk_count++;
        }
        if ($area['type'] === 'KBA' && ($statusRank[$s] ?? 0) > ($statusRank[$kba_worst_status] ?? 0)) {
            $kba_worst_status = $s;
        } elseif ($area['type'] === 'PA' && ($statusRank[$s] ?? 0) > ($statusRank[$pa_worst_status] ?? 0)) {
            $pa_worst_status = $s;
        }
    }

    // Match Reports trend source, but avoid single-city fallback: use Metro Manila average when All Areas is missing.
    $latestAllAreasStmt = $mysql->query("SELECT year, viirs_avg
        FROM ecological_yearly_summary
        WHERE area = 'All Areas'
        ORDER BY year DESC
        LIMIT 1");
    $latestAllAreas = $latestAllAreasStmt ? ($latestAllAreasStmt->fetch(PDO::FETCH_ASSOC) ?: null) : null;

    if ($latestAllAreas) {
        $avg_radiance_year = isset($latestAllAreas['year']) ? (int) $latestAllAreas['year'] : null;
        $avg_radiance = isset($latestAllAreas['viirs_avg']) ? round((float) $latestAllAreas['viirs_avg'], 1) : 0.0;
    } else {
        $latestYear = (int) ($mysql->query("SELECT MAX(year) FROM ecological_yearly_summary")->fetchColumn() ?: 0);
        if ($latestYear > 0) {
            $metroAvgStmt = $mysql->prepare("SELECT AVG(viirs_avg) AS viirs_avg
                FROM ecological_yearly_summary
                WHERE year = :year
                  AND area <> 'All Areas'
                  AND viirs_avg IS NOT NULL");
            $metroAvgStmt->execute([':year' => $latestYear]);
            $metroAvgRow = $metroAvgStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $metroAvgValue = isset($metroAvgRow['viirs_avg']) ? (float) $metroAvgRow['viirs_avg'] : 0.0;

            $avg_radiance_year = $latestYear;
            $avg_radiance = round($metroAvgValue, 1);
        }
    }

    if ($avg_radiance_year === null) {
        // Last-resort fallback if yearly summary is unavailable.
        $metro_manila_light = 0;
        $metro_count = 0;
        foreach ($kba_data as $area) {
            if ($area['light_exposure'] !== null) {
                $metro_manila_light += $area['light_exposure'];
                $metro_count++;
            }
        }
        $avg_radiance = $metro_count > 0 ? round($metro_manila_light / $metro_count, 1) : 0.0;
    }
} catch (Throwable $e) {
    // MySQL unavailable – fall back to sample values for display.
    $db_failed = true;
    $fallback = json_decode(file_get_contents('data/sample_kba.json'), true) ?? [];
    foreach ($fallback as $fb) {
        $kba_data[] = [
            'id' => (int) ($fb['id'] ?? 0),
            'name' => (string) ($fb['name'] ?? 'Unknown Site'),
            'type' => (string) ($fb['type'] ?? 'KBA'),
            'species_count' => isset($fb['species_count']) ? (int) $fb['species_count'] : null,
            'light_exposure' => isset($fb['light_exposure']) ? (float) $fb['light_exposure'] : null,
            'status' => (string) ($fb['status'] ?? 'Unknown'),
        ];
    }

    $total_species = count($fallback);

    $metro_manila_light = 0;
    $metro_count = 0;
    foreach ($kba_data as $area) {
        if ($area['light_exposure'] !== null) {
            $metro_manila_light += $area['light_exposure'];
            $metro_count++;
        }
        $s = $area['status'];
        if ($s === 'Critical') {
            $total_critical++;
            ($area['type'] === 'KBA') ? $kba_critical_count++ : $pa_critical_count++;
        } elseif ($s === 'At Risk') {
            $total_atrisk++;
            ($area['type'] === 'KBA') ? $kba_atrisk_count++ : $pa_atrisk_count++;
        }
        if ($area['type'] === 'KBA' && ($statusRank[$s] ?? 0) > ($statusRank[$kba_worst_status] ?? 0)) {
            $kba_worst_status = $s;
        } elseif ($area['type'] === 'PA' && ($statusRank[$s] ?? 0) > ($statusRank[$pa_worst_status] ?? 0)) {
            $pa_worst_status = $s;
        }
    }
    $avg_radiance = $metro_count > 0 ? round($metro_manila_light / $metro_count, 1) : 0.0;
}

// --- Current Light Risk Level — driven by configured thresholds, not hardcoded values ---
$risk_label = 'Low';
$risk_class = 'success';
if ($avg_radiance >= $weights['high_risk']) {
    $risk_label = 'High';
    $risk_class = 'danger';
} elseif ($avg_radiance >= $weights['mod_risk']) {
    $risk_label = 'Medium';
    $risk_class = 'warning';
}

// SVG status dot — consistent rendering across all OS/browsers (no emoji variance).
$risk_svg_colors = ['success' => '#22c55e', 'warning' => '#eab308', 'danger' => '#ef4444'];
$risk_svg_color  = $risk_svg_colors[$risk_class] ?? '#94a3b8';

// Gauge: scale 0–100% against high_risk * 1.5 so there is visual room above the red zone.
$gauge_max_nw = max(1.0, $weights['high_risk'] * 1.5);
$gauge_pct    = (int) min(100, round($avg_radiance / $gauge_max_nw * 100));
$gauge_color  = $risk_svg_color;

// Threshold marker positions on the gauge track.
$gauge_mod_pct  = (int) min(99, round($weights['mod_risk']  / $gauge_max_nw * 100));
$gauge_high_pct = (int) min(99, round($weights['high_risk'] / $gauge_max_nw * 100));

// Map worst KBA/PA status to a stat-card accent class.
function worstStatusToCardClass(string $worst): string {
    return match($worst) {
        'Critical' => 'danger',
        'At Risk'  => 'warning',
        default    => 'success',
    };
}

// --- DENR-BMB Announcements (live feed from faps.bmb.gov.ph) ---
require_once 'includes/fetch_bmb_announcements.php';
$announcements = fetch_bmb_announcements(5, 3600, false);
?>

<div class="home-demo-entry">
<div class="page-header home-enter home-enter-1">
    <h1 class="page-title">Home — Executive Summary</h1>
    <p class="page-subtitle">Overview of AVILIGHT monitoring status for Metro Manila using live database records.</p>
</div>

<div class="home-meta-bar home-enter home-enter-2">
    📅 Dataset coverage: <strong>2014 – <?php echo $avg_radiance_year ? (int)$avg_radiance_year : '2025'; ?></strong>
</div>

<?php if ($db_failed): ?>
<div class="alert alert-warning home-enter home-enter-2" role="alert">
    <span aria-hidden="true">⚠️</span> <strong>Live database could not be reached.</strong> The site metrics and species data shown below are <strong>sample/hardcoded values</strong> and do not reflect real-time database records.
</div>
<?php endif; ?>

<!-- Top stat cards -->
<?php
$kba_count = $kba_data ? count(array_filter($kba_data, fn($a) => $a['type'] === 'KBA')) : 0;
$pa_count  = $kba_data ? count(array_filter($kba_data, fn($a) => $a['type'] === 'PA'))  : 0;
?>
<div class="stats-grid home-stats-grid home-enter home-enter-3">

    <!-- Total Species -->
    <div class="stat-card home-enter home-enter-4">
        <div class="stat-label">Total Species Tracked</div>
        <div class="stat-value"><?php echo $total_species; ?></div>
        <div class="stat-description">Unique bird species in the database</div>
    </div>

    <!-- Light Risk Level — most prominent card; includes a radiance gauge -->
    <div class="stat-card <?php echo $risk_class; ?> home-enter home-enter-5" style="position:relative;">
        <div class="stat-label">Current Light Risk Level</div>
        <div class="stat-value" style="display:flex;align-items:center;gap:10px;">
            <svg width="18" height="18" viewBox="0 0 18 18" aria-hidden="true" style="flex-shrink:0;margin-top:2px;">
                <circle cx="9" cy="9" r="9" fill="<?php echo $risk_svg_color; ?>" opacity="0.25"/>
                <circle cx="9" cy="9" r="5.5" fill="<?php echo $risk_svg_color; ?>"/>
            </svg>
            <?php echo htmlspecialchars($risk_label); ?>
        </div>
        <div class="stat-description" style="margin-bottom:10px;">
            Metro Manila avg. VIIRS<?php echo $avg_radiance_year ? ' (' . (int)$avg_radiance_year . ')' : ''; ?>:
            <strong><?php echo number_format((float)$avg_radiance, 1); ?> nW/cm²/sr</strong>
        </div>
        <!-- Radiance gauge bar -->
        <div class="risk-gauge-track" title="<?php echo number_format((float)$avg_radiance,1); ?> nW / <?php echo number_format($gauge_max_nw,1); ?> nW max scale">
            <div class="risk-gauge-fill" style="width:<?php echo $gauge_pct; ?>%;background:<?php echo $gauge_color; ?>;"></div>
            <div class="risk-gauge-marker" style="left:<?php echo $gauge_mod_pct; ?>%;" title="Moderate threshold: <?php echo number_format($weights['mod_risk'],0); ?> nW"></div>
            <div class="risk-gauge-marker risk-gauge-marker-high" style="left:<?php echo $gauge_high_pct; ?>%;" title="High threshold: <?php echo number_format($weights['high_risk'],0); ?> nW"></div>
        </div>
        <div class="risk-gauge-labels">
            <span>0</span>
            <span><?php echo number_format($weights['mod_risk'],0); ?> nW</span>
            <span><?php echo number_format($weights['high_risk'],0); ?> nW</span>
        </div>
    </div>

    <!-- KBAs Monitored — accent driven by worst KBA health state -->
    <div class="stat-card <?php echo worstStatusToCardClass($kba_worst_status); ?> home-enter home-enter-6">
        <div class="stat-label">KBAs Monitored</div>
        <div class="stat-value"><?php echo $kba_count; ?></div>
        <div class="stat-description">
            <?php if ($kba_critical_count > 0 || $kba_atrisk_count > 0): ?>
                <?php if ($kba_critical_count > 0): ?><span style="color:var(--accent-red,#ef4444);font-weight:600;"><?php echo $kba_critical_count; ?> Critical</span><?php endif; ?>
                <?php if ($kba_critical_count > 0 && $kba_atrisk_count > 0): ?> · <?php endif; ?>
                <?php if ($kba_atrisk_count > 0): ?><span style="color:var(--accent-yellow,#eab308);font-weight:600;"><?php echo $kba_atrisk_count; ?> At Risk</span><?php endif; ?>
            <?php else: ?>
                All sites in good standing
            <?php endif; ?>
        </div>
    </div>

    <!-- PAs Monitored — accent driven by worst PA health state -->
    <div class="stat-card <?php echo worstStatusToCardClass($pa_worst_status); ?> home-enter home-enter-7">
        <div class="stat-label">Protected Areas Monitored</div>
        <div class="stat-value"><?php echo $pa_count; ?></div>
        <div class="stat-description">
            <?php if ($pa_critical_count > 0 || $pa_atrisk_count > 0): ?>
                <?php if ($pa_critical_count > 0): ?><span style="color:var(--accent-red,#ef4444);font-weight:600;"><?php echo $pa_critical_count; ?> Critical</span><?php endif; ?>
                <?php if ($pa_critical_count > 0 && $pa_atrisk_count > 0): ?> · <?php endif; ?>
                <?php if ($pa_atrisk_count > 0): ?><span style="color:var(--accent-yellow,#eab308);font-weight:600;"><?php echo $pa_atrisk_count; ?> At Risk</span><?php endif; ?>
            <?php else: ?>
                All sites in good standing
            <?php endif; ?>
        </div>
    </div>

</div>

<!-- Two-column lower section -->
<div class="home-lower-grid home-enter home-enter-8">

    <!-- KBA / PA Monitoring Status -->
    <div class="card home-enter home-enter-9">
        <div class="card-header" style="display:flex;align-items:baseline;justify-content:space-between;gap:12px;flex-wrap:wrap;">
            <span>KBA / PA Monitoring Status
                <?php if ($audit_snapshot_year): ?>
                <span style="font-size:0.75rem;font-weight:400;opacity:0.65;">(<?php echo (int)$audit_snapshot_year; ?>)</span>
                <?php endif; ?>
            </span>
            <?php if (!$db_failed && $audit_updated_at): ?>
            <span style="font-size:0.75rem;font-weight:400;color:var(--text-muted);">
                Last audit: <?php echo htmlspecialchars(date('M j, Y', strtotime($audit_updated_at))); ?>
            </span>
            <?php endif; ?>
        </div>
        <div class="card-body">

            <?php
            $needs_attention = $total_critical + $total_atrisk;
            $total_sites     = count($kba_data);
            ?>
            <?php if ($needs_attention > 0): ?>
            <div class="kba-summary-callout kba-summary-<?php echo $total_critical > 0 ? 'critical' : 'warning'; ?>">
                <svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true" style="flex-shrink:0;margin-top:1px;">
                    <path fill="currentColor" d="M8 1a7 7 0 1 0 0 14A7 7 0 0 0 8 1zm0 3a.75.75 0 0 1 .75.75v3.5a.75.75 0 0 1-1.5 0v-3.5A.75.75 0 0 1 8 4zm0 7.5a1 1 0 1 1 0-2 1 1 0 0 1 0 2z"/>
                </svg>
                <span>
                    <?php if ($total_critical > 0): ?>
                        <strong><?php echo $total_critical; ?> site<?php echo $total_critical > 1 ? 's' : ''; ?> Critical</strong><?php echo $total_atrisk > 0 ? ' and <strong>' . $total_atrisk . ' At Risk</strong>' : ''; ?>
                    <?php else: ?>
                        <strong><?php echo $total_atrisk; ?> site<?php echo $total_atrisk > 1 ? 's' : ''; ?> At Risk</strong>
                    <?php endif; ?>
                    — <?php echo $needs_attention; ?> of <?php echo $total_sites; ?> monitored sites require attention.
                    <a href="reports.php#kba-audit-table" style="color:inherit;font-weight:600;white-space:nowrap;">View Report →</a>
                </span>
            </div>
            <?php else: ?>
            <div class="kba-summary-callout kba-summary-good">
                <svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true" style="flex-shrink:0;margin-top:1px;">
                    <path fill="currentColor" d="M8 1a7 7 0 1 0 0 14A7 7 0 0 0 8 1zm3.28 5.03-4 4a.75.75 0 0 1-1.06 0l-1.5-1.5a.75.75 0 1 1 1.06-1.06l.97.97 3.47-3.47a.75.75 0 1 1 1.06 1.06z"/>
                </svg>
                <span>All <?php echo $total_sites; ?> monitored sites are in <strong>Moderate</strong> or <strong>Good</strong> standing.</span>
            </div>
            <?php endif; ?>

            <table class="home-kba-table" id="kbaMonitoringTable">
                <thead>
                    <tr>
                        <th data-sort-col="name" class="kba-sortable kba-sort-asc" title="Sort by site name">Site Name <span class="kba-sort-icon" aria-hidden="true">↑</span></th>
                        <th>Type</th>
                        <th data-sort-col="species" class="kba-sortable" title="Sort by species count">Species <span class="kba-sort-icon" aria-hidden="true">↕</span></th>
                        <th data-sort-col="light" class="kba-sortable" title="Sort by light exposure — avg. VIIRS ALAN in nW/cm²/sr. Higher = more artificial light at night.">Light Exposure ⓘ <span class="kba-sort-icon" aria-hidden="true">↕</span></th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($kba_data as $area):
                        $le_raw       = $area['light_exposure'];
                        $species_raw  = $area['species_count'];
                        $status_text  = $area['status'];
                        // Moderate = green because it is the best achievable status in Metro Manila
                        // (no KBA/PA site has ever scored ≥ 75 — "Good" is not reachable here).
                        $status_class = match($status_text) {
                            'Good', 'Moderate' => 'success',
                            'Critical'         => 'danger',
                            'At Risk'          => 'warning',
                            default            => 'secondary',
                        };
                        $row_class = match($status_text) {
                            'Critical' => 'kba-row-critical',
                            'At Risk'  => 'kba-row-atrisk',
                            default    => '',
                        };
                    ?>
                    <tr class="<?php echo $row_class; ?>"
                        data-val-name="<?php echo htmlspecialchars(mb_strtolower($area['name'])); ?>"
                        data-val-species="<?php echo $species_raw !== null ? (int) $species_raw : ''; ?>"
                        data-val-light="<?php echo $le_raw !== null ? (float) $le_raw : ''; ?>">
                        <td><?php echo htmlspecialchars($area['name']); ?></td>
                        <td>
                            <span class="kba-type-badge">
                                <?php echo htmlspecialchars($area['type']); ?>
                            </span>
                        </td>
                        <td><?php echo $species_raw !== null ? (int) $species_raw : '—'; ?></td>
                        <td>
                            <?php if ($le_raw !== null):
                                $le       = (float) $le_raw;
                                $le_class = $le >= $weights['high_risk'] ? 'danger' : ($le >= $weights['mod_risk'] ? 'warning' : 'success');
                            ?>
                            <span class="badge badge-<?php echo $le_class; ?>">
                                <?php echo number_format($le, 1); ?> nW
                            </span>
                            <?php else: ?>
                            <span class="badge badge-secondary">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge badge-<?php echo $status_class; ?>"><?php echo htmlspecialchars($status_text); ?></span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="kba-table-footer">
                <p class="kba-footnote">
                    Status is scored from Richness, NDVI, ALAN, LST, and Precipitation weights.
                    Adjust weights in <a href="admin.php#threshold-config">Admin › Threshold Config</a>.
                </p>
                <a href="reports.php#kba-audit-table" class="kba-report-link">View Full Report →</a>
            </div>

        </div>
    </div>

    <!-- DENR-BMB Announcements -->
    <div class="card home-enter home-enter-10">
        <div class="card-header">
            DENR-BMB Announcements
            <a href="https://faps.bmb.gov.ph/faps/" target="_blank" rel="noopener noreferrer" class="bmb-view-all">View All ›</a>
        </div>
        <div class="card-body">
            <?php
            $ann_per_page  = 3;
            $ann_all_json  = json_encode($announcements, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE);
            $ann_pages     = empty($announcements) ? 0 : (int) ceil(count($announcements) / $ann_per_page);
            $ann_page0     = array_slice($announcements, 0, $ann_per_page);
            $ann_featured  = $ann_page0[0] ?? null;
            $ann_rows      = array_slice($ann_page0, 1);
            ?>
            <div class="announcements-feed screenshot-announcements" id="bmbAnnouncementsFeed"
                 data-bmb-items='<?php echo $ann_all_json; ?>'>
                <?php if (empty($announcements)): ?>
                <div class="announcement-row" role="status" aria-live="polite">
                    <div class="announcement-row-icon">ⓘ</div>
                    <div class="announcement-row-content">
                        <div class="announcement-row-title">Live announcements could not be loaded at this time. Visit the DENR-BMB FAPS portal for the latest updates.</div>
                        <div class="announcement-row-meta">
                            <a href="https://faps.bmb.gov.ph/faps/" target="_blank" rel="noopener noreferrer" class="announcement-featured-link">Visit Portal ↗</a>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <div class="announcement-featured">
                    <div class="announcement-featured-chip"><?php echo htmlspecialchars($ann_featured['tag']); ?></div>
                    <div class="announcement-featured-title"><?php echo htmlspecialchars($ann_featured['title']); ?></div>
                    <?php if (!empty($ann_featured['summary'])): ?>
                    <div class="announcement-featured-summary"><?php echo htmlspecialchars($ann_featured['summary']); ?></div>
                    <?php endif; ?>
                    <a href="<?php echo htmlspecialchars($ann_featured['link']); ?>" target="_blank" rel="noopener noreferrer" class="announcement-featured-link">
                        <?php echo empty($ann_featured['date']) ? 'Visit Portal ↗' : 'Read More ↗'; ?>
                    </a>
                </div>
                <?php foreach ($ann_rows as $ann): ?>
                <div class="announcement-row">
                    <div class="announcement-row-icon">ⓘ</div>
                    <div class="announcement-row-content">
                        <a href="<?php echo htmlspecialchars($ann['link']); ?>" target="_blank" rel="noopener noreferrer" class="announcement-row-link"><?php echo htmlspecialchars($ann['title']); ?></a>
                        <div class="announcement-row-meta">
                            <span class="announcement-row-badge"><?php echo htmlspecialchars($ann['tag']); ?></span>
                            <?php if (!empty($ann['date'])): ?><span><?php echo htmlspecialchars($ann['date']); ?></span><?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php if ($ann_pages > 1): ?>
                <div class="bmb-pagination">
                    <button class="bmb-page-btn bmb-prev" disabled aria-label="Previous">&#8249;</button>
                    <span class="bmb-page-info">1 / <?php echo $ann_pages; ?></span>
                    <button class="bmb-page-btn bmb-next" aria-label="Next">&#8250;</button>
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

</div>

</div>

<?php if ($show_agreement_modal): ?>
<!-- ── User Responsibility Agreement Modal ─────────────────────────────── -->
<div id="agreementOverlay" role="dialog" aria-modal="true" aria-labelledby="agreementTitle"
     style="position:fixed;inset:0;z-index:9999;background:rgba(15,23,42,0.6);
            display:flex;align-items:center;justify-content:center;padding:16px;">
    <div style="background:var(--bg-card,#fff);border-radius:16px;
                width:100%;max-width:560px;max-height:90vh;
                display:flex;flex-direction:column;
                box-shadow:0 20px 60px rgba(0,0,0,0.25);overflow:hidden;">

        <!-- Header -->
        <div style="text-align:center;padding:28px 28px 0;flex-shrink:0;">
            <div style="display:inline-flex;align-items:center;justify-content:center;
                        width:56px;height:56px;background:#eff6ff;border-radius:14px;margin-bottom:14px;">
                <svg viewBox="0 0 24 24" fill="none" stroke="#3b82f6" stroke-width="2"
                     stroke-linecap="round" stroke-linejoin="round"
                     width="28" height="28" aria-hidden="true">
                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                </svg>
            </div>
            <h2 id="agreementTitle"
                style="font-size:1.25rem;font-weight:700;color:var(--text-primary,#1e293b);margin:0 0 6px;">
                User Responsibility Agreement
            </h2>
            <p style="font-size:0.87rem;color:var(--text-secondary,#64748b);margin:0 0 20px;">
                Please review and accept these terms before continuing.
            </p>

            <!-- Welcome strip -->
            <div style="display:flex;gap:14px;background:var(--bg-input,#eff6ff);border:1px solid var(--border-color,#bfdbfe);
                        border-radius:10px;padding:14px 16px;text-align:left;margin-bottom:4px;">
                <div style="font-size:0.88rem;font-weight:700;color:var(--text-primary,#1e293b);
                             min-width:90px;word-break:break-word;line-height:1.4;">
                    Welcome,<br><?= htmlspecialchars($_SESSION['user_email'] ?? '') ?>!
                </div>
                <div style="font-size:0.83rem;color:var(--text-secondary,#475569);line-height:1.5;">
                    Please read and accept the User Responsibility Agreement to continue using AVILIGHT.
                </div>
            </div>
        </div>

        <!-- Scrollable body -->
        <div style="overflow-y:auto;padding:16px 28px;flex:1;min-height:0;">
            <div style="background:var(--bg-card,#fff);border:1px solid var(--border-color,#e2e8f0);
                        border-radius:10px;padding:20px;">
                <h3 style="font-size:0.97rem;font-weight:700;color:var(--text-primary,#1e293b);margin:0 0 10px;">
                    AVILIGHT Responsible Use and System Integrity Agreement
                </h3>
                <p style="font-size:0.85rem;color:var(--text-secondary,#64748b);margin:0 0 14px;line-height:1.6;">
                    I acknowledge that I am accessing AVILIGHT, a secure application used to support
                    authorized operational, administrative, research, or business activities.
                </p>
                <p style="font-size:0.85rem;font-weight:700;color:var(--text-primary,#1e293b);margin:0 0 12px;">
                    By accepting this agreement, I understand and commit to the following:
                </p>
                <ul style="list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:11px;">
                    <?php
                    $terms = [
                        ['Authorized Use', 'I will use this application only for legitimate, authorized work related to my role or approved business activities.'],
                        ['Accurate Records', 'I will enter, review, and manage information responsibly and will not intentionally submit false, misleading, or manipulated data.'],
                        ['Confidentiality', 'I will protect confidential, personal, financial, operational, and other sensitive information and will only access data I am permitted to view.'],
                        ['Account Security', 'I am responsible for safeguarding my credentials, using strong authentication practices, and reporting suspected unauthorized access immediately.'],
                        ['System Integrity', 'I will not attempt to bypass security controls, access restricted areas, alter configurations without approval, or interfere with the availability or integrity of the system.'],
                        ['Compliance', 'I will follow applicable laws, organizational policies, contractual obligations, and internal procedures that govern use of this application and its data.'],
                        ['Monitoring and Audit', 'I understand that access and activity within this application may be monitored and logged for security, compliance, operational support, and audit purposes.'],
                        ['Consequences', 'I understand that misuse may result in revoked access, disciplinary action, investigation, or referral to the appropriate authorities.'],
                    ];
                    foreach ($terms as [$label, $text]): ?>
                    <li style="display:flex;gap:8px;font-size:0.84rem;color:var(--text-secondary,#475569);line-height:1.5;">
                        <span style="flex-shrink:0;margin-top:1px;">•</span>
                        <span><strong style="color:var(--text-primary,#1e293b);"><?= htmlspecialchars($label) ?>:</strong>
                        <?= htmlspecialchars($text) ?></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>

        <!-- Footer / action -->
        <div style="padding:16px 28px 24px;flex-shrink:0;border-top:1px solid var(--border-color,#e2e8f0);">
            <p style="font-size:0.81rem;color:var(--text-secondary,#64748b);margin:0 0 14px;line-height:1.5;">
                By clicking <strong>I Agree</strong>, I confirm that I have read, understood,
                and agree to comply with this User Responsibility Agreement.
            </p>
            <form method="POST">
                <button type="submit" name="accept_agreement" value="1"
                        style="padding:11px 32px;background:var(--accent-blue,#3b82f6);color:#fff;
                               font-size:0.95rem;font-weight:600;border:none;border-radius:8px;
                               cursor:pointer;transition:opacity 0.2s;"
                        onmouseover="this.style.opacity='0.88'"
                        onmouseout="this.style.opacity='1'">
                    I Agree
                </button>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php
$extra_scripts = <<<SCRIPTS
<style>
/* ── Metadata chip bar ───────────────────────────────────────────────────── */
.home-meta-bar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px 4px;
    font-size: 0.8rem;
    color: var(--text-secondary);
    margin-bottom: 20px;
    padding: 8px 12px;
    background: var(--bg-card-alt, rgba(148,163,184,0.08));
    border-radius: 8px;
    border: 1px solid var(--border-color, rgba(148,163,184,0.15));
}
.home-meta-chip { white-space: nowrap; }
.home-meta-sep  { opacity: 0.4; }

/* ── Risk gauge bar ──────────────────────────────────────────────────────── */
.risk-gauge-track {
    position: relative;
    height: 6px;
    background: var(--bg-card-alt, rgba(148,163,184,0.18));
    border-radius: 99px;
    overflow: visible;
    margin-top: 4px;
}
.risk-gauge-fill {
    height: 100%;
    border-radius: 99px;
    transition: width 0.6s ease;
    min-width: 4px;
}
.risk-gauge-marker {
    position: absolute;
    top: -3px;
    width: 2px;
    height: 12px;
    background: var(--text-muted, rgba(100,116,139,0.5));
    border-radius: 1px;
    transform: translateX(-50%);
}
.risk-gauge-marker-high { background: rgba(239,68,68,0.55); }
.risk-gauge-labels {
    display: flex;
    justify-content: space-between;
    font-size: 0.68rem;
    color: var(--text-muted);
    margin-top: 5px;
    opacity: 0.75;
}

/* ── Sortable headers ────────────────────────────────────────────────────── */
.kba-sortable { cursor: pointer; user-select: none; white-space: nowrap; }
.kba-sortable:hover { opacity: 0.8; }
.kba-sort-icon { display: inline-block; margin-left: 4px; font-size: 0.75em; opacity: 0.5; }
.kba-sort-asc .kba-sort-icon, .kba-sort-desc .kba-sort-icon { opacity: 1; }

/* ── Type badges (distinct from status badges) ───────────────────────────── */
.kba-type-badge {
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.03em;
    padding: 2px 7px;
    border-radius: 4px;
    border: 1.5px solid transparent;
}
/* ── Row-level priority highlights ──────────────────────────────────────── */
.kba-row-critical td:first-child { border-left: 3px solid var(--accent-red, #ef4444); }
.kba-row-atrisk   td:first-child { border-left: 3px solid var(--accent-yellow, #eab308); }
.kba-row-critical { background: rgba(239,68,68,0.035); }
.kba-row-atrisk   { background: rgba(234,179,8,0.035); }

/* ── Status summary callout ──────────────────────────────────────────────── */
.kba-summary-callout {
    display: flex;
    align-items: flex-start;
    gap: 9px;
    padding: 10px 14px;
    border-radius: 8px;
    font-size: 0.84rem;
    margin-bottom: 14px;
    line-height: 1.5;
}
.kba-summary-critical {
    background: rgba(239,68,68,0.08);
    color: var(--accent-red, #dc2626);
    border: 1px solid rgba(239,68,68,0.2);
}
.kba-summary-warning {
    background: rgba(234,179,8,0.08);
    color: var(--accent-yellow, #ca8a04);
    border: 1px solid rgba(234,179,8,0.2);
}
.kba-summary-good {
    background: rgba(34,197,94,0.07);
    color: var(--accent-green, #16a34a);
    border: 1px solid rgba(34,197,94,0.18);
}

/* ── Table footer (footnote + action link) ───────────────────────────────── */
.kba-table-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-top: 12px;
    flex-wrap: wrap;
}
.kba-footnote {
    margin: 0;
    font-size: 0.78rem;
    color: var(--text-muted);
    flex: 1;
}
.kba-footnote a { color: var(--accent-blue, #3b82f6); text-decoration: none; }
.kba-footnote a:hover { text-decoration: underline; }
.kba-report-link {
    font-size: 0.82rem;
    font-weight: 600;
    color: var(--accent-blue, #3b82f6);
    text-decoration: none;
    white-space: nowrap;
    padding: 5px 12px;
    border: 1px solid rgba(59,130,246,0.3);
    border-radius: 6px;
    transition: background 0.15s;
}
.kba-report-link:hover { background: rgba(59,130,246,0.08); }

/* ── Announcement row links ──────────────────────────────────────────────── */
.announcement-row-link {
    display: block;
    color: var(--text-primary, #f1f5f9);
    font-weight: 600;
    font-size: 0.875rem;
    text-decoration: none;
    line-height: 1.35;
    margin-bottom: 4px;
}
.announcement-row-link:hover { color: var(--accent-blue, #3b82f6); text-decoration: underline; }

/* ── Lower grid: both cards fill cell height so footers can float ─────────── */
.home-lower-grid > .card {
    display: flex;
    flex-direction: column;
}
.home-lower-grid > .card > .card-body {
    flex: 1;
    display: flex;
    flex-direction: column;
}

/* KBA/PA: footnote floats near bottom with breathing room above */
.home-lower-grid > .card:first-child .kba-table-footer {
    margin-top: auto;
    padding-top: 20px;
}

/* DENR-BMB: feed fills card-body; pagination anchored to bottom */
.announcements-feed {
    flex: 1;
    display: flex;
    flex-direction: column;
}

/* ── Announcement pagination ─────────────────────────────────────────────── */
.bmb-pagination {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 8px;
    padding: 10px 0 2px;
    margin-top: auto;                                          /* always at bottom */
    border-top: 1px solid var(--border-color, rgba(148,163,184,0.15));
}
.bmb-page-btn {
    background: none;
    border: 1px solid var(--border-color, rgba(148,163,184,0.3));
    border-radius: 4px;
    color: var(--text-secondary, #64748b);
    cursor: pointer;
    font-size: 18px;
    line-height: 1;
    padding: 1px 9px 3px;
    transition: background 0.15s, color 0.15s, border-color 0.15s;
}
.bmb-page-btn:hover:not(:disabled) {
    background: rgba(59,130,246,0.08);
    color: var(--accent-blue, #3b82f6);
    border-color: rgba(59,130,246,0.4);
}
.bmb-page-btn:disabled { opacity: 0.35; cursor: default; }
.bmb-page-info {
    font-size: 12px;
    color: var(--text-secondary, #64748b);
    min-width: 32px;
    text-align: center;
}
</style>
<script>
(function () {
    document.documentElement.classList.add('home-entry-ready');

    // ── KBA/PA table sort ──────────────────────────────────────────────────
    var kbaTable = document.getElementById('kbaMonitoringTable');
    if (kbaTable) {
        var tbody = kbaTable.querySelector('tbody');
        var headers = kbaTable.querySelectorAll('th[data-sort-col]');
        var currentSort = { col: 'name', dir: 'asc' };

        function getVal(row, col) {
            return row.getAttribute('data-val-' + col) || '';
        }

        function doSort(col) {
            var dir = currentSort.col === col
                ? (currentSort.dir === 'asc' ? 'desc' : 'asc')
                : 'asc';
            currentSort = { col: col, dir: dir };

            var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr'));
            rows.sort(function (a, b) {
                var av = getVal(a, col);
                var bv = getVal(b, col);
                var na = parseFloat(av);
                var nb = parseFloat(bv);
                var isNum = av !== '' && bv !== '' && !isNaN(na) && !isNaN(nb);
                var cmp;
                if (isNum) {
                    cmp = na - nb;
                } else if (av === '' && bv !== '') {
                    cmp = 1;
                } else if (av !== '' && bv === '') {
                    cmp = -1;
                } else {
                    cmp = av.localeCompare(bv);
                }
                return dir === 'asc' ? cmp : -cmp;
            });
            rows.forEach(function (r) { tbody.appendChild(r); });
            updateIndicators();
        }

        function updateIndicators() {
            headers.forEach(function (th) {
                th.classList.remove('kba-sort-asc', 'kba-sort-desc');
                var icon = th.querySelector('.kba-sort-icon');
                if (th.getAttribute('data-sort-col') === currentSort.col) {
                    th.classList.add('kba-sort-' + currentSort.dir);
                    if (icon) { icon.textContent = currentSort.dir === 'asc' ? '↑' : '↓'; }
                } else {
                    if (icon) { icon.textContent = '↕'; }
                }
            });
        }

        headers.forEach(function (th) {
            th.addEventListener('click', function () {
                doSort(th.getAttribute('data-sort-col'));
            });
        });
    }
    // ── End KBA/PA table sort ──────────────────────────────────────────────

    var feedEl = document.getElementById('bmbAnnouncementsFeed');
    if (!feedEl) return;

    var BMB_PER  = 3;   // items visible per page (1 featured + up to 2 rows)
    var bmbItems = [];
    var bmbPage  = 0;
    var bmbFixedH = 0; // locked container height set after page 0 renders

    // Seed from server-rendered data so pagination works before the API responds.
    try {
        var _seed = feedEl.getAttribute('data-bmb-items');
        if (_seed) bmbItems = JSON.parse(_seed);
    } catch (e) {}

    function escapeHtml(v) {
        return String(v == null ? '' : v)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function isRealContent(items) {
        return Array.isArray(items) && items.length > 0 && items[0].date !== '';
    }

    function bmbPageCount() {
        return Math.max(1, Math.ceil(bmbItems.length / BMB_PER));
    }

    function renderBmbPage(page) {
        bmbPage = Math.max(0, Math.min(page, bmbPageCount() - 1));
        var slice    = bmbItems.slice(bmbPage * BMB_PER, (bmbPage + 1) * BMB_PER);
        var featured = slice[0] || {};
        var rows     = slice.slice(1);
        var fallbackUrl = 'https://faps.bmb.gov.ph/faps/';

        var html =
            '<div class="announcement-featured">' +
                '<div class="announcement-featured-chip">' + escapeHtml(featured.tag || 'Info') + '</div>' +
                '<div class="announcement-featured-title">' + escapeHtml(featured.title || '') + '</div>' +
                ((featured.summary && String(featured.summary).trim())
                    ? ('<div class="announcement-featured-summary">' + escapeHtml(featured.summary) + '</div>')
                    : '') +
                '<a href="' + escapeHtml(featured.link || fallbackUrl) + '" target="_blank" rel="noopener noreferrer" class="announcement-featured-link">' +
                    (featured.date ? 'Read More ↗' : 'Visit Portal ↗') +
                '</a>' +
            '</div>';

        rows.forEach(function(item) {
            html +=
                '<div class="announcement-row">' +
                    '<div class="announcement-row-icon">ⓘ</div>' +
                    '<div class="announcement-row-content">' +
                        '<a href="' + escapeHtml(item.link || fallbackUrl) + '" target="_blank" rel="noopener noreferrer" class="announcement-row-link">' +
                            escapeHtml(item.title || '') +
                        '</a>' +
                        '<div class="announcement-row-meta">' +
                            '<span class="announcement-row-badge">' + escapeHtml(item.tag || 'News') + '</span>' +
                            (item.date ? ('<span>' + escapeHtml(item.date) + '</span>') : '') +
                        '</div>' +
                    '</div>' +
                '</div>';
        });

        var total = bmbPageCount();
        if (total > 1) {
            html +=
                '<div class="bmb-pagination">' +
                    '<button class="bmb-page-btn bmb-prev"' + (bmbPage === 0 ? ' disabled' : '') + ' aria-label="Previous">&#8249;</button>' +
                    '<span class="bmb-page-info">' + (bmbPage + 1) + ' / ' + total + '</span>' +
                    '<button class="bmb-page-btn bmb-next"' + (bmbPage >= total - 1 ? ' disabled' : '') + ' aria-label="Next">&#8250;</button>' +
                '</div>';
        }

        feedEl.innerHTML = html;

        var prevBtn = feedEl.querySelector('.bmb-prev');
        var nextBtn = feedEl.querySelector('.bmb-next');
        if (prevBtn) prevBtn.onclick = function() { renderBmbPage(bmbPage - 1); };
        if (nextBtn) nextBtn.onclick = function() { renderBmbPage(bmbPage + 1); };

        // After page 0 renders, lock the natural height so subsequent pages
        // (which may have fewer items) never shrink the container.
        if (page === 0) {
            feedEl.style.minHeight = '';          // reset to measure natural height
            var h = feedEl.offsetHeight;          // forces layout; reads real height
            if (h > 0) {
                bmbFixedH = h;
                feedEl.style.minHeight = h + 'px';
            }
        } else if (bmbFixedH > 0) {
            feedEl.style.minHeight = bmbFixedH + 'px';
        }
    }

    // Boot pagination from server-rendered data if available.
    if (bmbItems.length) renderBmbPage(0);

    function fetchAnnouncements(retryOnPending) {
        var ctrl = (typeof AbortController !== 'undefined') ? new AbortController() : null;
        var tid = setTimeout(function() { if (ctrl) ctrl.abort(); }, 8000);

        fetch('api/get_bmb_announcements.php?limit=5', ctrl ? { signal: ctrl.signal } : undefined)
            .then(function(resp) {
                if (!resp.ok) throw new Error('HTTP ' + resp.status);
                return resp.json();
            })
            .then(function(data) {
                clearTimeout(tid);
                if (!data || !data.success || !Array.isArray(data.items)) return;

                if (data.pending) {
                    if (retryOnPending) {
                        setTimeout(function() { fetchAnnouncements(false); }, 12000);
                    }
                    return;
                }

                if (isRealContent(data.items)) {
                    bmbItems = data.items;
                    feedEl.setAttribute('data-bmb-items', JSON.stringify(data.items));
                    renderBmbPage(0);
                }
            })
            .catch(function() { clearTimeout(tid); });
    }

    fetchAnnouncements(true);
})();
</script>
SCRIPTS;
require_once 'includes/footer.php';
?>