<?php
$page_title = 'Home';
require_once 'includes/header.php';

// Load data
require_once 'includes/db.php';
$total_species = 0;

function loadHomeThresholdConfig(): array {
    $defaults = [
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
$kba_data = [];
$db_failed = false;
$avg_radiance = 0.0;
$avg_radiance_year = null;
try {
    $mysql  = get_mysql_db();
    $weights = loadHomeThresholdConfig();

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
    }
    $avg_radiance = $metro_count > 0 ? round($metro_manila_light / $metro_count, 1) : 0.0;
}

// --- Current Light Risk Level (latest year from ecological_yearly_summary) ---
$risk_label   = 'Low';
$risk_class   = 'success';
$risk_icon    = '🟢';
if ($avg_radiance > 40) {
    $risk_label = 'High';
    $risk_class = 'danger';
    $risk_icon  = '🔴';
} elseif ($avg_radiance > 30) {
    $risk_label = 'Medium';
    $risk_class = 'warning';
    $risk_icon  = '🟡';
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

<div class="alert alert-info home-enter home-enter-2" role="status">
    📅 <strong>Dataset Period: 2014 – 2025</strong> | <strong>Monitoring Status: 2014 – 2025</strong> —
    Metrics and site analyses are loaded from the database and aligned with the latest Reports KBA/PA audit table.
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
            Metro Manila avg. VIIRS radiance<?php echo $avg_radiance_year ? ' (' . (int) $avg_radiance_year . ')' : ''; ?>: <strong><?php echo number_format((float) $avg_radiance, 1); ?> nW/cm²/sr</strong>
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
        <div class="card-header">KBA / PA Monitoring Status <span style="font-size:0.75rem;font-weight:400;opacity:0.7;">(2025)</span></div>
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
                                <?php echo number_format((float) $le, 1); ?> nW
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
            <div class="announcements-feed screenshot-announcements" id="bmbAnnouncementsFeed">
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

    var feedEl = document.getElementById('bmbAnnouncementsFeed');
    if (!feedEl) return;

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function renderAnnouncements(items) {
        if (!Array.isArray(items) || items.length === 0) return;

        var featured = items[0] || {};
        var rows = items.slice(1);

        var html = '' +
            '<div class="announcement-featured">' +
                '<div class="announcement-featured-chip">' + escapeHtml(featured.tag || 'Info') + '</div>' +
                '<div class="announcement-featured-title">' + escapeHtml(featured.title || 'DENR-BMB FAPS announcements') + '</div>' +
                ((featured.summary && String(featured.summary).trim())
                    ? ('<div class="announcement-featured-summary">' + escapeHtml(featured.summary) + '</div>')
                    : '') +
                '<a href="' + escapeHtml(featured.link || 'https://faps.bmb.gov.ph/faps/') + '" target="_blank" rel="noopener noreferrer" class="announcement-featured-link">' +
                    (featured.date ? 'Read More ↗' : 'Visit Portal ↗') +
                '</a>' +
            '</div>';

        rows.forEach(function(item) {
            html += '' +
                '<div class="announcement-row">' +
                    '<div class="announcement-row-icon">ⓘ</div>' +
                    '<div class="announcement-row-content">' +
                        '<div class="announcement-row-title">' + escapeHtml(item.title || '') + '</div>' +
                        '<div class="announcement-row-meta">' +
                            '<span class="announcement-row-badge">' + escapeHtml(item.tag || 'News') + '</span>' +
                            (item.date ? ('<span>' + escapeHtml(item.date) + '</span>') : '') +
                        '</div>' +
                    '</div>' +
                '</div>';
        });

        feedEl.innerHTML = html;
    }

    var controller = (typeof AbortController !== 'undefined') ? new AbortController() : null;
    var timeoutId = setTimeout(function() {
        if (controller) controller.abort();
    }, 12000);

    fetch('api/get_bmb_announcements.php?limit=5', controller ? { signal: controller.signal } : undefined)
        .then(function(resp) {
            if (!resp.ok) throw new Error('HTTP ' + resp.status);
            return resp.json();
        })
        .then(function(data) {
            if (data && data.success && Array.isArray(data.items)) {
                renderAnnouncements(data.items);
            }
        })
        .catch(function() {
            // Keep server-rendered fallback content.
        })
        .finally(function() {
            clearTimeout(timeoutId);
      });
})();
</script>
SCRIPTS;
require_once 'includes/footer.php';
?>