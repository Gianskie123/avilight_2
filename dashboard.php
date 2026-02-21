<?php
$page_title = 'Dashboard';
require_once 'includes/header.php';

// Load sample data
$kba_data = json_decode(file_get_contents('data/sample_kba.json'), true);
?>

<div class="dashboard-layout">
    <!-- Left column: Map -->
    <div class="dashboard-map-col">
        <div style="position: relative; height: 100%;">
            <div id="map"></div>
            <div class="map-legend">
                <h4>Risk Zones</h4>
                <div class="map-legend-item">
                    <span class="map-legend-dot" style="background: #22c55e;"></span>
                    <span>Low Risk</span>
                </div>
                <div class="map-legend-item">
                    <span class="map-legend-dot" style="background: #eab308;"></span>
                    <span>Medium Risk</span>
                </div>
                <div class="map-legend-item">
                    <span class="map-legend-dot" style="background: #ef4444;"></span>
                    <span>High Risk</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Right column: Sidebar -->
    <div class="dashboard-sidebar">
        <!-- Stat cards -->
        <div class="dash-stats">
            <div class="dash-stat-card">
                <div class="dash-stat-label">At Risk Zones</div>
                <div>
                    <span class="dash-stat-value">18</span>
                    <span class="dash-stat-trend down">↘ -5%</span>
                </div>
            </div>
            <div class="dash-stat-card">
                <div class="dash-stat-label">Light Intensity</div>
                <div>
                    <span class="dash-stat-value">78%</span>
                    <span class="dash-stat-trend up">↗ +8%</span>
                </div>
            </div>
        </div>

        <!-- Bird Richness Trend -->
        <div class="chart-card">
            <div class="section-title">Bird Richness Trend</div>
            <canvas id="birdRichnessChart"></canvas>
        </div>

        <!-- Recent Activity -->
        <div>
            <div class="section-title">Recent Updates</div>
            <div class="activity-feed">
                <div class="activity-item">
                    <div class="activity-icon red">⚠</div>
                    <div class="activity-text">
                        <strong>High light intensity detected in Zone A3</strong>
                        <span>2 hours ago</span>
                    </div>
                </div>
                <div class="activity-item">
                    <div class="activity-icon green">✓</div>
                    <div class="activity-text">
                        <strong>Bird richness increased by 12%</strong>
                        <span>5 hours ago</span>
                    </div>
                </div>
                <div class="activity-item">
                    <div class="activity-icon blue">⏱</div>
                    <div class="activity-text">
                        <strong>Monitoring update scheduled</strong>
                        <span>1 day ago</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
// Build risk zones JSON from KBA data + extra Philippine locations
$risk_zones = [];
if ($kba_data) {
    foreach ($kba_data as $area) {
        $light = isset($area['light_exposure']) ? $area['light_exposure'] : 30;
        if ($light > 40) {
            $risk = 'high';
        } elseif ($light > 30) {
            $risk = 'medium';
        } else {
            $risk = 'low';
        }
        $risk_zones[] = [
            'lat' => $area['latitude'],
            'lng' => $area['longitude'],
            'name' => $area['name'],
            'risk' => $risk,
        ];
    }
}
// Add extra Philippine locations for wider coverage
$extra_zones = [
    ['lat' => 16.4023, 'lng' => 120.5960, 'name' => 'Baguio Highlands', 'risk' => 'low'],
    ['lat' => 10.3157, 'lng' => 123.8854, 'name' => 'Cebu Urban Core', 'risk' => 'high'],
    ['lat' =>  7.0736, 'lng' => 125.6120, 'name' => 'Davao Gulf', 'risk' => 'medium'],
    ['lat' => 10.6920, 'lng' => 122.5644, 'name' => 'Iloilo Wetlands', 'risk' => 'low'],
    ['lat' => 14.1700, 'lng' => 121.2400, 'name' => 'Mt. Makiling Forest', 'risk' => 'low'],
    ['lat' =>  9.3068, 'lng' => 123.3054, 'name' => 'Bohol Forests', 'risk' => 'medium'],
    ['lat' => 13.1391, 'lng' => 123.7438, 'name' => 'Mt. Mayon Buffer', 'risk' => 'medium'],
    ['lat' => 15.4755, 'lng' => 120.5963, 'name' => 'Tarlac Agricultural', 'risk' => 'low'],
    ['lat' => 11.5800, 'lng' => 124.9500, 'name' => 'Leyte Corridor', 'risk' => 'medium'],
    ['lat' =>  8.4542, 'lng' => 124.6319, 'name' => 'Cagayan de Oro', 'risk' => 'high'],
    ['lat' => 18.1964, 'lng' => 121.7470, 'name' => 'Cagayan Valley', 'risk' => 'low'],
    ['lat' =>  6.9214, 'lng' => 122.0740, 'name' => 'Zamboanga Peninsula', 'risk' => 'high'],
];
$risk_zones = array_merge($risk_zones, $extra_zones);
$risk_zones_json = json_encode($risk_zones);

$extra_scripts = <<<SCRIPTS
<script>
// Risk zone data
var riskZones = {$risk_zones_json};

// Initialize map centered on Philippines
var map = L.map('map').setView([12.5, 121.5], 6);

// Dark tile layer
L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OSM</a> &copy; <a href="https://carto.com/">CARTO</a>',
    maxZoom: 19
}).addTo(map);

// Risk zone colors
var riskColors = {
    low:    {color: '#22c55e', fillColor: '#22c55e', fillOpacity: 0.25, weight: 1.5},
    medium: {color: '#eab308', fillColor: '#eab308', fillOpacity: 0.25, weight: 1.5},
    high:   {color: '#ef4444', fillColor: '#ef4444', fillOpacity: 0.25, weight: 1.5}
};

// Add risk zone circles
riskZones.forEach(function(zone) {
    var style = riskColors[zone.risk] || riskColors.low;
    L.circle([zone.lat, zone.lng], {
        radius: 25000,
        color: style.color,
        fillColor: style.fillColor,
        fillOpacity: style.fillOpacity,
        weight: style.weight
    }).addTo(map).bindPopup(
        '<strong>' + zone.name + '</strong><br>Risk: ' + zone.risk.charAt(0).toUpperCase() + zone.risk.slice(1)
    );
});

// Bird Richness Trend Chart
var ctx = document.getElementById('birdRichnessChart').getContext('2d');
new Chart(ctx, {
    type: 'line',
    data: {
        labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'],
        datasets: [{
            label: 'Bird Richness',
            data: [180, 200, 170, 220, 240, 250],
            borderColor: '#3b82f6',
            backgroundColor: 'rgba(59, 130, 246, 0.1)',
            fill: true,
            tension: 0.4,
            pointBackgroundColor: '#3b82f6',
            pointBorderColor: '#3b82f6',
            pointRadius: 4,
            borderWidth: 2
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        scales: {
            x: {
                grid: { color: 'rgba(255,255,255,0.06)' },
                ticks: { color: '#a0a4b0' }
            },
            y: {
                beginAtZero: true,
                max: 260,
                grid: { color: 'rgba(255,255,255,0.06)' },
                ticks: { color: '#a0a4b0' }
            }
        },
        plugins: {
            legend: { display: false }
        }
    }
});
</script>
SCRIPTS;

require_once 'includes/footer.php';
?>
