<?php
$page_title = 'Home';
require_once 'includes/header.php';

// Load data
require_once 'includes/db.php';

// Load risk thresholds from settings (with defaults)
$_thresholds_file = __DIR__ . '/data/thresholds.json';
$_thresholds      = is_readable($_thresholds_file) ? (json_decode(file_get_contents($_thresholds_file), true) ?? []) : [];
$threshold_low    = (float) ($_thresholds['low_risk']  ?? 25);
$threshold_mod    = (float) ($_thresholds['mod_risk']  ?? 40);

$total_species = 0;

// KBA / PA site definitions – geographic/policy facts; enriched below with live DB values
$kba_data = [
    ['id' => 1, 'name' => 'La Mesa Watershed',                    'type' => 'KBA', 'latitude' => 14.7167, 'longitude' => 121.0833, 'area_hectares' => 2700,  'status' => 'Protected',           'species_count' => null, 'light_exposure' => null],
    ['id' => 2, 'name' => 'Ninoy Aquino Parks & Wildlife Center', 'type' => 'PA',  'latitude' => 14.6537, 'longitude' => 121.0499, 'area_hectares' => 64,    'status' => 'Protected',           'species_count' => null, 'light_exposure' => null],
    ['id' => 3, 'name' => 'Las Piñas-Parañaque Critical Habitat', 'type' => 'KBA', 'latitude' => 14.4500, 'longitude' => 120.9833, 'area_hectares' => 175,   'status' => 'Protected',           'species_count' => null, 'light_exposure' => null],
    ['id' => 4, 'name' => 'Marikina Watershed',                   'type' => 'PA',  'latitude' => 14.6833, 'longitude' => 121.1333, 'area_hectares' => 1800,  'status' => 'Protected',           'species_count' => null, 'light_exposure' => null],
    ['id' => 5, 'name' => 'Laguna de Bay Wetlands',               'type' => 'KBA', 'latitude' => 14.3833, 'longitude' => 121.2667, 'area_hectares' => 90000, 'status' => 'Partially Protected', 'species_count' => null, 'light_exposure' => null],
];

// Enrich KBA/PA sites with real data from the MySQL avilight database
$db_failed = false;
try {
    $mysql  = get_mysql_db();
    $radius = 0.15; // bounding box half-width in degrees (≈ ±0.15° at ~14°N)

    // Total species from MySQL species_masterlist (PK index — O(1))
    $total_species = (int) $mysql->query('SELECT COUNT(*) FROM species_masterlist')->fetchColumn();

    // Latest year with VIIRS radiance data (null when the table is empty)
    $viirs_max_year = null;
    try {
        $yr = $mysql->query('SELECT MAX(year) FROM viirs')->fetchColumn();
        if ($yr !== false && $yr !== null) {
            $viirs_max_year = (int) $yr;
        }
    } catch (Throwable $e) { /* viirs table may not exist yet */ }

    // Latest observation year
    $obs_max_year = null;
    try {
        $yr = $mysql->query('SELECT MAX(year) FROM raw_bird_observation')->fetchColumn();
        if ($yr !== false && $yr !== null) {
            $obs_max_year = (int) $yr;
        }
    } catch (Throwable $e) { /* table may not exist yet */ }

    $data_end_year = max(2025, $viirs_max_year ?? 2025, $obs_max_year ?? 2025);

    // Build a UNION ALL of site bounding boxes so both counts can be fetched in
    // a single query each, avoiding a per-site (N+1) round-trip pattern.
    $union_parts  = [];
    $union_params = [];
    foreach ($kba_data as $site) {
        $id  = (int) $site['id'];
        $lat = (float) $site['latitude'];
        $lng = (float) $site['longitude'];
        $union_parts[] = "SELECT {$id} AS sid,
            ({$lat} - {$radius}) AS lat_min, ({$lat} + {$radius}) AS lat_max,
            ({$lng} - {$radius}) AS lng_min, ({$lng} + {$radius}) AS lng_max";
    }
    $bounds_sql = implode(' UNION ALL ', $union_parts);

    // Batch species-count query: one row per site
    $species_sql = "
        SELECT b.sid, COUNT(DISTINCT rbo.species_id) AS cnt
          FROM ({$bounds_sql}) AS b
          LEFT JOIN raw_bird_observation rbo
            ON rbo.latitude  BETWEEN b.lat_min AND b.lat_max
           AND rbo.longitude BETWEEN b.lng_min AND b.lng_max
         GROUP BY b.sid";
    $species_rows = $mysql->query($species_sql)->fetchAll(PDO::FETCH_ASSOC);
    $species_by_id = [];
    foreach ($species_rows as $row) {
        $species_by_id[(int) $row['sid']] = (int) $row['cnt'];
    }

    // Batch VIIRS query: one row per site (skip if no VIIRS year available)
    $viirs_by_id = [];
    if ($viirs_max_year !== null) {
        $viirs_sql = "
            SELECT b.sid, AVG(v.viirs_avg_rad) AS avg_rad
              FROM ({$bounds_sql}) AS b
              JOIN final_master_grid g
                ON g.lat BETWEEN b.lat_min AND b.lat_max
               AND g.lon BETWEEN b.lng_min AND b.lng_max
              JOIN viirs v
                ON v.cell_id = g.cell_id
               AND v.year = {$viirs_max_year}
             GROUP BY b.sid";
        $viirs_rows = $mysql->query($viirs_sql)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($viirs_rows as $row) {
            $viirs_by_id[(int) $row['sid']] = round((float) $row['avg_rad'], 1);
        }
    }

    // Apply results back to each site
    foreach ($kba_data as &$site) {
        $id = (int) $site['id'];
        if (isset($species_by_id[$id]) && $species_by_id[$id] > 0) {
            $site['species_count'] = $species_by_id[$id];
        }
        if (isset($viirs_by_id[$id])) {
            $site['light_exposure'] = $viirs_by_id[$id];
        }
    }
    unset($site);
} catch (Throwable $e) {
    // MySQL unavailable – fall back to the sample data values for display
    $db_failed = true;
    $data_end_year = 2025;
    $fallback = json_decode(file_get_contents('data/sample_kba.json'), true) ?? [];
    $fb_map   = [];
    foreach ($fallback as $fb) {
        $fb_map[(int) $fb['id']] = $fb;
    }
    foreach ($kba_data as &$site) {
        $fb = $fb_map[(int) $site['id']] ?? null;
        $site['species_count']  = $fb ? (int)   $fb['species_count']  : null;
        $site['light_exposure'] = $fb ? (float) $fb['light_exposure'] : null;
    }
    unset($site);
}

// --- Current Light Risk Level (Metro Manila average VIIRS radiance from all grid cells) ---
// Metro Manila avg VIIRS from final_master_grid using idx_fmg_year_month index
$avg_radiance = 0;
if (!$db_failed && $viirs_max_year !== null) {
    try {
        $fmg_month_stmt = $mysql->prepare(
            'SELECT MAX(month) FROM final_master_grid WHERE year = ? AND viirs_avg_rad IS NOT NULL'
        );
        $fmg_month_stmt->execute([$viirs_max_year]);
        $viirs_max_month = (int) ($fmg_month_stmt->fetchColumn() ?: 1);

        $avg_stmt = $mysql->prepare(
            'SELECT AVG(viirs_avg_rad) FROM final_master_grid WHERE year = ? AND month = ? AND viirs_avg_rad IS NOT NULL'
        );
        $avg_stmt->execute([$viirs_max_year, $viirs_max_month]);
        $avg_radiance = round((float) ($avg_stmt->fetchColumn() ?: 0), 1);
    } catch (Throwable $e) { /* fall back to 0 */ }
}
$risk_label = 'Low';
$risk_class = 'success';
$risk_icon  = '🟢';
if ($avg_radiance > $threshold_mod) {
    $risk_label = 'High';
    $risk_class = 'danger';
    $risk_icon  = '🔴';
} elseif ($avg_radiance > $threshold_low) {
    $risk_label = 'Medium';
    $risk_class = 'warning';
    $risk_icon  = '🟡';
}

// --- DENR-BMB Announcements (live feed from faps.bmb.gov.ph) ---
require_once 'includes/fetch_bmb_announcements.php';
$announcements = fetch_bmb_announcements(5);
?>

<div class="home-demo-entry">
<div class="page-header home-enter home-enter-1">
    <h1 class="page-title">Home — Executive Summary</h1>
    <p class="page-subtitle">Overview of AVILIGHT monitoring status for Metro Manila. Latest data came from datasets last updated in <?php echo $data_end_year; ?>.</p>
</div>

<div class="alert alert-info home-enter home-enter-2" role="status">
    📅 <strong>Dataset Period: 2014 – <?php echo $data_end_year; ?></strong> | <strong>Monitoring Status: 2014 – <?php echo $data_end_year; ?></strong> —
    All metrics, readings, and site analyses displayed are derived from historical datasets that was last updated in <?php echo $data_end_year; ?>.
</div>

<?php if ($db_failed): ?>
<div class="alert alert-warning home-enter home-enter-2" role="alert">
    <span aria-hidden="true">⚠️</span> <strong>Live database could not be reached.</strong> The site metrics and species data shown below are <strong>sample/hardcoded values</strong> and do not reflect real-time database records.
</div>
<?php endif; ?>

<!-- Top stat cards -->
<div class="stats-grid home-stats-grid home-enter home-enter-3">
    <!-- Total Species -->
    <div class="stat-card home-enter home-enter-4">
        <div class="stat-label">Total Species Tracked</div>
        <div class="stat-value"><?php echo $total_species; ?></div>
        <div class="stat-description">Unique bird species in the current database</div>
    </div>

    <!-- Light Risk Level -->
    <div class="stat-card <?php echo $risk_class; ?> home-enter home-enter-5">
        <div class="stat-label">Current Light Risk Level</div>
        <div class="stat-value"><?php echo $risk_icon . ' ' . $risk_label; ?></div>
        <div class="stat-description">
            Metro Manila avg. VIIRS: <strong><?php echo $avg_radiance; ?> nW/cm²/sr</strong>
            (<?php echo $viirs_max_year ?? $data_end_year; ?>)
            · Thresholds: Low ≤<?php echo (int)$threshold_low; ?>, Med ≤<?php echo (int)$threshold_mod; ?>, High &gt;<?php echo (int)$threshold_mod; ?> nW
        </div>
    </div>

    <!-- KBA count -->
    <div class="stat-card info home-enter home-enter-6">
        <div class="stat-label">KBAs Monitored</div>
        <div class="stat-value"><?php echo $kba_data ? count(array_filter($kba_data, fn($a) => $a['type'] === 'KBA')) : 0; ?></div>
        <div class="stat-description">Key Biodiversity Areas currently covered</div>
    </div>

    <!-- PA count -->
    <div class="stat-card warning home-enter home-enter-7">
        <div class="stat-label">Protected Areas Monitored</div>
        <div class="stat-value"><?php echo $kba_data ? count(array_filter($kba_data, fn($a) => $a['type'] === 'PA')) : 0; ?></div>
        <div class="stat-description">Protected Areas currently covered</div>
    </div>
</div>

<!-- Two-column lower section -->
<div class="home-lower-grid home-enter home-enter-8">

    <!-- KBA / PA Monitoring Status -->
    <div class="card home-enter home-enter-9">
        <div class="card-header">KBA / PA Monitoring Status <span style="font-size:0.75rem;font-weight:400;opacity:0.7;">(2014 – <?php echo $data_end_year; ?>)</span></div>
        <div class="card-body">
            <table class="home-kba-table">
                <thead>
                    <tr>
                        <th>Site Name</th>
                        <th>Type</th>
                        <th>Species</th>
                        <th>Light Exposure</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($kba_data as $area): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($area['name']); ?></td>
                        <td>
                            <span class="badge badge-<?php echo $area['type'] === 'KBA' ? 'info' : 'success'; ?>">
                                <?php echo htmlspecialchars($area['type']); ?>
                            </span>
                        </td>
                        <td><?php echo $area['species_count'] !== null ? (int) $area['species_count'] : '—'; ?></td>
                        <td>
                            <?php if ($area['light_exposure'] !== null):
                                $le       = $area['light_exposure'];
                                $le_class = $le > 40 ? 'danger' : ($le > 30 ? 'warning' : 'success');
                            ?>
                            <span class="badge badge-<?php echo $le_class; ?>">
                                <?php echo $le; ?> nW
                            </span>
                            <?php else: ?>
                            <span class="badge badge-secondary">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($area['status']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- DENR-BMB Announcements -->
    <div class="card home-enter home-enter-10">
        <div class="card-header">
            DENR-BMB Announcements
            <a href="https://faps.bmb.gov.ph/faps/" target="_blank" rel="noopener noreferrer" class="bmb-view-all">View All ›</a>
        </div>
        <div class="card-body">
            <div class="announcements-feed screenshot-announcements">
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
                <?php
                $ann_featured = $announcements[0];
                $ann_rows     = count($announcements) > 1 ? array_slice($announcements, 1) : [];
                ?>
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
                        <div class="announcement-row-title"><?php echo htmlspecialchars($ann['title']); ?></div>
                        <div class="announcement-row-meta">
                            <span class="announcement-row-badge"><?php echo htmlspecialchars($ann['tag']); ?></span>
                            <?php if (!empty($ann['date'])): ?>
                            <span><?php echo htmlspecialchars($ann['date']); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

</div>

</div>

<?php
$extra_scripts = <<<SCRIPTS
<script>
(function () {
    document.documentElement.classList.add('home-entry-ready');
})();
</script>
SCRIPTS;
require_once 'includes/footer.php';
?>
