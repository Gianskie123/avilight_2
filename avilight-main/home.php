<?php
$page_title = 'Home';
require_once 'includes/header.php';

// Load data
require_once 'includes/db.php';
$pdo         = get_db();
$total_species = (int) $pdo->query('SELECT COUNT(*) FROM species')->fetchColumn();
$kba_data    = json_decode(file_get_contents('data/sample_kba.json'), true);

// --- Current Light Risk Level (Metro Manila average VIIRS radiance) ---
$metro_manila_light = 0;
$metro_count = 0;
if ($kba_data) {
    foreach ($kba_data as $area) {
        if (isset($area['light_exposure'])) {
            $metro_manila_light += $area['light_exposure'];
            $metro_count++;
        }
    }
}
$avg_radiance   = $metro_count > 0 ? round($metro_manila_light / $metro_count, 1) : 0;
$risk_label     = 'Low';
$risk_class     = 'success';
$risk_icon      = '🟢';
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
$announcements = fetch_bmb_announcements(5);
?>

<div class="home-demo-entry">
<div class="page-header home-enter home-enter-1">
    <h1 class="page-title">Home — Executive Summary</h1>
    <p class="page-subtitle">Overview of AVILIGHT monitoring status for Metro Manila. Latest data came from datasets last updated in 2024.</p>
</div>

<div class="alert alert-info home-enter home-enter-2" role="status">
    📅 <strong>Dataset Period: 2014 – 2024</strong> | <strong>Monitoring Status: 2014 – 2024</strong> —
    All metrics, readings, and site analyses displayed are derived from historical datasets that was last updated in 2024.
</div>

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
            Metro Manila avg. VIIRS radiance: <strong><?php echo $avg_radiance; ?> nW/cm²/sr</strong>
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
        <div class="card-header">KBA / PA Monitoring Status <span style="font-size:0.75rem;font-weight:400;opacity:0.7;">(2014 – 2024)</span></div>
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
                    <?php if ($kba_data): foreach ($kba_data as $area): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($area['name']); ?></td>
                        <td>
                            <span class="badge badge-<?php echo $area['type'] === 'KBA' ? 'info' : 'success'; ?>">
                                <?php echo htmlspecialchars($area['type']); ?>
                            </span>
                        </td>
                        <td><?php echo (int)$area['species_count']; ?></td>
                        <td>
                            <?php
                            $le = $area['light_exposure'];
                            $le_class = $le > 40 ? 'danger' : ($le > 30 ? 'warning' : 'success');
                            ?>
                            <span class="badge badge-<?php echo $le_class; ?>">
                                <?php echo $le; ?> nW
                            </span>
                        </td>
                        <td><?php echo htmlspecialchars($area['status']); ?></td>
                    </tr>
                    <?php endforeach; endif; ?>
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
                <div class="announcement-featured">
                    <div class="announcement-featured-chip">Info</div>
                    <div class="announcement-featured-title">DENR-BMB FAPS – Recent Announcements</div>
                    <div class="announcement-featured-summary">Live announcements could not be loaded at this time. Visit the DENR-BMB FAPS portal for the latest updates.</div>
                    <a href="https://faps.bmb.gov.ph/faps/" target="_blank" rel="noopener noreferrer" class="announcement-featured-link">Visit Portal ↗</a>
                </div>

                <div class="announcement-row">
                    <div class="announcement-row-icon">ⓘ</div>
                    <div class="announcement-row-content">
                        <div class="announcement-row-title">Wildlife Week 2024 Celebration</div>
                        <div class="announcement-row-meta">
                            <span class="announcement-row-badge">Event</span>
                            <span>Dec 10, 2024</span>
                        </div>
                    </div>
                </div>

                <div class="announcement-row">
                    <div class="announcement-row-icon">ⓘ</div>
                    <div class="announcement-row-content">
                        <div class="announcement-row-title">Updated Protected Area Guidelines</div>
                        <div class="announcement-row-meta">
                            <span class="announcement-row-badge">Policy</span>
                            <span>Nov 28, 2024</span>
                        </div>
                    </div>
                </div>

                <div class="announcement-row">
                    <div class="announcement-row-icon">ⓘ</div>
                    <div class="announcement-row-content">
                        <div class="announcement-row-title">Bird Survey Results: Metro Manila</div>
                        <div class="announcement-row-meta">
                            <span class="announcement-row-badge">Report</span>
                            <span>Nov 15, 2024</span>
                        </div>
                    </div>
                </div>
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
