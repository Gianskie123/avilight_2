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

<div class="page-header">
    <h1 class="page-title">Home — Executive Summary</h1>
    <p class="page-subtitle">Overview of AVILIGHT monitoring status for Metro Manila and key biodiversity sites.</p>
</div>

<div class="alert alert-info" role="status">
    📅 <strong>Dataset Period: 2014 – 2024</strong> —
    All metrics, species records, light-exposure readings, and site analyses displayed in this dashboard are derived from historical datasets covering the years 2014 to 2024 only.
</div>

<!-- Top stat cards -->
<div class="stats-grid home-stats-grid">
    <!-- Total Species -->
    <div class="stat-card">
        <div class="stat-label">Total Species Tracked</div>
        <div class="stat-value"><?php echo $total_species; ?></div>
        <div class="stat-description">Unique bird species in the current database</div>
    </div>

    <!-- Light Risk Level -->
    <div class="stat-card <?php echo $risk_class; ?>">
        <div class="stat-label">Current Light Risk Level</div>
        <div class="stat-value"><?php echo $risk_icon . ' ' . $risk_label; ?></div>
        <div class="stat-description">
            Metro Manila avg. VIIRS radiance: <strong><?php echo $avg_radiance; ?> nW/cm²/sr</strong>
        </div>
    </div>

    <!-- KBA count -->
    <div class="stat-card info">
        <div class="stat-label">KBAs Monitored</div>
        <div class="stat-value"><?php echo $kba_data ? count(array_filter($kba_data, fn($a) => $a['type'] === 'KBA')) : 0; ?></div>
        <div class="stat-description">Key Biodiversity Areas currently covered</div>
    </div>

    <!-- PA count -->
    <div class="stat-card warning">
        <div class="stat-label">Protected Areas Monitored</div>
        <div class="stat-value"><?php echo $kba_data ? count(array_filter($kba_data, fn($a) => $a['type'] === 'PA')) : 0; ?></div>
        <div class="stat-description">Protected Areas currently covered</div>
    </div>
</div>

<!-- Two-column lower section -->
<div class="home-lower-grid">

    <!-- KBA / PA Monitoring Status -->
    <div class="card">
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
    <div class="card">
        <div class="card-header">
            DENR-BMB Announcements
            <a href="https://faps.bmb.gov.ph/faps/" target="_blank" rel="noopener noreferrer" class="bmb-view-all">View All ↗</a>
        </div>
        <div class="card-body">
            <div class="announcements-feed">
                <?php foreach ($announcements as $ann): ?>
                <div class="announcement-item">
                    <div class="announcement-meta">
                        <span class="badge badge-<?php echo htmlspecialchars($ann['tag_class']); ?>"><?php echo htmlspecialchars($ann['tag']); ?></span>
                        <?php if ($ann['date'] !== ''): ?>
                        <span class="announcement-date"><?php echo htmlspecialchars($ann['date']); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="announcement-title">
                        <?php if (!empty($ann['link'])): ?>
                        <a href="<?php echo htmlspecialchars($ann['link']); ?>" target="_blank" rel="noopener noreferrer">
                            <?php echo htmlspecialchars($ann['title']); ?>
                        </a>
                        <?php else: ?>
                        <?php echo htmlspecialchars($ann['title']); ?>
                        <?php endif; ?>
                    </div>
                    <?php if ($ann['summary'] !== ''): ?>
                    <div class="announcement-summary"><?php echo htmlspecialchars($ann['summary']); ?></div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

</div>

<?php
$extra_scripts = '';
require_once 'includes/footer.php';
?>
