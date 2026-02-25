<?php
$page_title = 'Dashboard';
require_once 'includes/header.php';

// Load sample data
$kba_data = json_decode(file_get_contents('data/sample_kba.json'), true);
?>

<div class="alert alert-info" role="status">
    📅 <strong>Dataset Period: 2014 – 2024</strong> | <strong>Monitoring Status: 2014 – 2024</strong> —
    All metrics, readings, and site analyses displayed are derived from historical datasets that was last updated in 2024.
</div>

<div class="dashboard-layout">
    <!-- Left column: Map -->
    <div class="dashboard-map-col">
        <div style="position: relative; display: flex; flex-direction: column; height: 100%;">
            <!-- Map filter control bar -->
            <div id="dashMapControls" style="display:flex; flex-wrap:wrap; align-items:center; gap:8px; padding:8px 12px; background:var(--bg-card-alt); border-bottom:1px solid var(--border-color); flex-shrink:0;">
                <span style="font-size:0.78rem; color:var(--text-muted); white-space:nowrap;">View:</span>
                <button class="btn btn-primary btn-sm" id="btnRiskZones" onclick="setMapView('risk')">⚠️ Risk Zones</button>
                <button class="btn btn-secondary btn-sm" id="btnHistorical" onclick="setMapView('historical')">📊 Historical Data</button>

                <!-- Historical data filters (hidden by default) -->
                <div id="historicalFilters" style="display:none; align-items:center; gap:8px; flex-wrap:wrap;">
                    <div style="width:1px; height:24px; background:var(--border-color);"></div>
                    <label style="font-size:0.78rem; color:var(--text-muted); white-space:nowrap;">Year:</label>
                    <select id="histYearSelect" class="btn btn-secondary btn-sm" style="padding:3px 6px; cursor:pointer;" onchange="loadHistoricalData()">
                        <?php for ($y = 2014; $y <= 2024; $y++): ?>
                        <option value="<?= $y ?>"><?= $y ?></option>
                        <?php endfor; ?>
                    </select>
                    <div style="width:1px; height:24px; background:var(--border-color);"></div>
                    <span style="font-size:0.78rem; color:var(--text-muted); white-space:nowrap;">Month: <strong id="histMonthDisplay">All</strong></span>
                    <input type="range" id="histMonthSlider" class="slider" min="0" max="12" value="0" step="1" style="width:120px; margin:0;" oninput="onHistMonthChange(this.value)">
                </div>
            </div>

            <div style="position: relative; flex: 1;">
            <div id="map"></div>

            <!-- Risk Zones legend -->
            <div class="map-legend" id="legendRiskZones">
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

            <!-- Historical data legend (hidden by default) -->
            <div class="map-legend" id="legendHistorical" style="display:none;">
                <h4>Species Richness</h4>
                <div style="display:flex; align-items:center; gap:4px; margin-top:6px;">
                    <span style="font-size:0.72rem; color:var(--text-muted);">Low</span>
                    <div style="flex:1; height:12px; border-radius:3px; background:linear-gradient(to right,#313695,#4575b4,#74add1,#fee090,#f46d43,#a50026);"></div>
                    <span style="font-size:0.72rem; color:var(--text-muted);">High</span>
                </div>
                <div style="display:flex; justify-content:space-between; margin-top:2px;">
                    <span style="font-size:0.7rem; color:var(--text-muted);">0</span>
                    <span style="font-size:0.7rem; color:var(--text-muted);">10</span>
                    <span style="font-size:0.7rem; color:var(--text-muted);">20</span>
                    <span style="font-size:0.7rem; color:var(--text-muted);">30+</span>
                </div>
                <div id="histLoadingMsg" style="margin-top:6px; font-size:0.75rem; color:var(--text-muted); display:none;">Loading…</div>
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
                <div class="dash-stat-desc">
                    Areas classified as <strong>Medium</strong> or <strong>High</strong> risk based on VIIRS night-light radiance (&gt;30 nW/cm²/sr). Shown on the map as <span class="risk-medium-indicator">yellow</span> and <span class="risk-high-indicator">red</span> circles.
                </div>
            </div>
            <div class="dash-stat-card">
                <div class="dash-stat-label">Light Intensity</div>
                <div>
                    <span class="dash-stat-value">78%</span>
                    <span class="dash-stat-trend up">↗ +8%</span>
                </div>
                <div class="dash-stat-desc">
                    Relative artificial light at night (ALAN) index averaged across monitored sites (2014–2024). Higher intensity correlates with larger, brighter circles on the map and increased disturbance risk to bird species.
                </div>
            </div>
        </div>

        <!-- Bird Richness Trend -->
        <div class="chart-card">
            <div class="section-title">Bird Richness Trend</div>
            <div class="bird-richness-controls">
                <label class="slider-label" for="yearSlider">
                    <span>Year: <strong id="yearDisplay">2014</strong></span>
                    <span style="font-size:0.75rem;color:var(--text-muted);">2014 – 2024</span>
                </label>
                <input type="range" id="yearSlider" class="slider" min="2014" max="2024" value="2014" step="1">
            </div>
            <canvas id="birdRichnessChart"></canvas>
        </div>

        <!-- Recent Updates -->
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

// ── Tile layers (dark for Risk Zones, light for Historical Data) ───────────
var darkTile = L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OSM</a> &copy; <a href="https://carto.com/">CARTO</a>',
    maxZoom: 19
});
var lightTile = L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', {
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OSM</a> &copy; <a href="https://carto.com/">CARTO</a>',
    maxZoom: 19
});

// Initialize map centered on Philippines with dark tile layer
var map = L.map('map').setView([12.5, 121.5], 6);
darkTile.addTo(map);

// Risk zone colors
var riskColors = {
    low:    {color: '#22c55e', fillColor: '#22c55e', fillOpacity: 0.25, weight: 1.5},
    medium: {color: '#eab308', fillColor: '#eab308', fillOpacity: 0.25, weight: 1.5},
    high:   {color: '#ef4444', fillColor: '#ef4444', fillOpacity: 0.25, weight: 1.5}
};

// Track risk zone circles so they can be toggled
var riskZoneLayers = [];

// Add risk zone circles
riskZones.forEach(function(zone) {
    var style = riskColors[zone.risk] || riskColors.low;
    var circle = L.circle([zone.lat, zone.lng], {
        radius: 25000,
        color: style.color,
        fillColor: style.fillColor,
        fillOpacity: style.fillOpacity,
        weight: style.weight
    }).addTo(map).bindPopup(
        '<strong>' + zone.name + '</strong><br>Risk: ' + zone.risk.charAt(0).toUpperCase() + zone.risk.slice(1)
    );
    riskZoneLayers.push(circle);
});

// Highlight Metro Manila with a bounding rectangle
var metroManilaBounds = [[14.35, 120.90], [14.82, 121.22]];
var metroRect = L.rectangle(metroManilaBounds, {
    color: '#3b82f6',
    weight: 2.5,
    fillColor: '#3b82f6',
    fillOpacity: 0.07,
    dashArray: '6 4'
}).addTo(map).bindPopup('<strong>Metro Manila</strong><br>Primary focus area of AVILIGHT monitoring');

// Metro Manila label marker — positioned at the top-centre of the bounding box
var metroLabel = L.marker([14.82, 121.06], {
    icon: L.divIcon({
        className: '',
        html: '<div class="metro-manila-label">Metro Manila</div>',
        // anchor bottom-centre of the label to the marker point so the text
        // sits just above (outside) the top border of the rectangle
        iconAnchor: [50, 26]
    })
}).addTo(map);

// ── Map view switching (Risk Zones / Historical Data) ─────────────────────

var currentMapView = 'risk';
var historicalLayers = [];

function getRichnessColor(value) {
    var stops = [
        { val: 0,  r: 49,  g: 54,  b: 149 },
        { val: 5,  r: 69,  g: 117, b: 180 },
        { val: 10, r: 116, g: 173, b: 209 },
        { val: 15, r: 171, g: 217, b: 233 },
        { val: 18, r: 254, g: 224, b: 144 },
        { val: 22, r: 253, g: 174, b: 97  },
        { val: 26, r: 244, g: 109, b: 67  },
        { val: 30, r: 165, g: 0,   b: 38  }
    ];
    value = Math.max(0, Math.min(30, value));
    var lower = stops[0], upper = stops[stops.length - 1];
    for (var i = 0; i < stops.length - 1; i++) {
        if (value >= stops[i].val && value <= stops[i + 1].val) {
            lower = stops[i]; upper = stops[i + 1]; break;
        }
    }
    var t = (upper.val === lower.val) ? 0 : (value - lower.val) / (upper.val - lower.val);
    return 'rgb(' + Math.round(lower.r + t*(upper.r-lower.r)) + ',' +
                    Math.round(lower.g + t*(upper.g-lower.g)) + ',' +
                    Math.round(lower.b + t*(upper.b-lower.b)) + ')';
}

function clearHistoricalLayers() {
    historicalLayers.forEach(function(l) { map.removeLayer(l); });
    historicalLayers = [];
}

function loadHistoricalData() {
    var year  = document.getElementById('histYearSelect').value;
    var month = document.getElementById('histMonthSlider').value;
    document.getElementById('histLoadingMsg').style.display = 'block';
    clearHistoricalLayers();

    var url = 'api/get_historical_data.php?year=' + year + (month > 0 ? '&month=' + month : '');
    fetch(url)
        .then(function(r) { return r.json(); })
        .then(function(resp) {
            document.getElementById('histLoadingMsg').style.display = 'none';
            if (!resp.success || !resp.data.length) return;
            resp.data.forEach(function(site) {
                var richness = parseInt(site.total_unique) || 0;
                var color = getRichnessColor(richness);
                // Use circleMarker (pixel radius) so markers pinpoint exact sites
                // without covering the visible map
                var marker = L.circleMarker([site.latitude, site.longitude], {
                    radius: 9,
                    color: '#fff',
                    weight: 1.5,
                    fillColor: color,
                    fillOpacity: 0.9
                }).bindPopup(
                    '<strong>' + site.site_name + '</strong>' +
                    '<br>Year: ' + site.year + '  Month: ' + site.month +
                    '<br>Unique Species: <strong>' + richness + '</strong>' +
                    '<br>Tolerant: ' + (site.total_tolerant || 0) +
                    ' &nbsp; Sensitive: ' + (site.total_sensitive || 0) +
                    '<br>Resident: ' + (site.total_resident || 0) +
                    ' &nbsp; Migrant: ' + (site.total_migrant || 0) +
                    '<br>Total Birds: ' + (site.total_count || 0)
                );
                marker.addTo(map);
                historicalLayers.push(marker);
            });
        })
        .catch(function() {
            document.getElementById('histLoadingMsg').style.display = 'none';
        });
}

function setMapView(view) {
    currentMapView = view;
    var isHist = (view === 'historical');

    // Toggle button styles
    document.getElementById('btnRiskZones').className  = isHist ? 'btn btn-secondary btn-sm' : 'btn btn-primary btn-sm';
    document.getElementById('btnHistorical').className = isHist ? 'btn btn-primary btn-sm'   : 'btn btn-secondary btn-sm';

    // Show/hide filter controls
    var filters = document.getElementById('historicalFilters');
    filters.style.display = isHist ? 'flex' : 'none';

    // Show/hide legends
    document.getElementById('legendRiskZones').style.display  = isHist ? 'none'  : 'block';
    document.getElementById('legendHistorical').style.display = isHist ? 'block' : 'none';

    // Swap tile layer (dark ↔ light)
    if (isHist) {
        map.removeLayer(darkTile);
        lightTile.addTo(map);
    } else {
        map.removeLayer(lightTile);
        darkTile.addTo(map);
    }

    // Show/hide risk zone layers
    riskZoneLayers.forEach(function(l) { isHist ? map.removeLayer(l) : l.addTo(map); });
    if (isHist) { map.removeLayer(metroRect); map.removeLayer(metroLabel); }
    else        { metroRect.addTo(map);       metroLabel.addTo(map);       }

    if (isHist) {
        map.setView([14.5748, 121.0], 12);
        loadHistoricalData();
    } else {
        clearHistoricalLayers();
        map.setView([12.5, 121.5], 6);
    }
}

function onHistMonthChange(val) {
    var monthNames = ['All','January','February','March','April','May','June',
                      'July','August','September','October','November','December'];
    document.getElementById('histMonthDisplay').textContent = monthNames[parseInt(val)];
    if (currentMapView === 'historical') loadHistoricalData();
}

document.getElementById('histMonthSlider').addEventListener('input', function() {
    onHistMonthChange(this.value);
});

// Bird Richness data per year (2014–2024), monthly values
var birdRichnessData = {
    2014: [120, 135, 128, 142, 155, 148, 160, 158, 145, 138, 130, 125],
    2015: [128, 140, 135, 150, 162, 155, 168, 165, 152, 144, 136, 130],
    2016: [132, 145, 138, 155, 168, 160, 172, 170, 158, 148, 140, 135],
    2017: [138, 150, 143, 160, 174, 166, 178, 175, 163, 153, 145, 140],
    2018: [145, 158, 150, 168, 182, 174, 186, 183, 170, 160, 152, 146],
    2019: [150, 163, 156, 174, 188, 180, 192, 189, 176, 166, 157, 151],
    2020: [140, 152, 144, 162, 175, 167, 179, 177, 163, 153, 144, 138],
    2021: [148, 161, 153, 170, 184, 175, 188, 185, 172, 161, 152, 146],
    2022: [155, 169, 161, 178, 193, 184, 197, 194, 180, 169, 160, 153],
    2023: [162, 176, 168, 186, 201, 192, 205, 202, 188, 176, 167, 160],
    2024: [168, 183, 174, 193, 208, 199, 212, 209, 195, 183, 174, 167]
};

var months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

// Bird Richness Trend Chart
var ctx = document.getElementById('birdRichnessChart').getContext('2d');
var birdChart = new Chart(ctx, {
    type: 'line',
    data: {
        labels: months,
        datasets: [{
            label: 'Bird Richness',
            data: birdRichnessData[2014],
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
                beginAtZero: false,
                min: 100,
                max: 230,
                grid: { color: 'rgba(255,255,255,0.06)' },
                ticks: { color: '#a0a4b0' }
            }
        },
        plugins: {
            legend: { display: false }
        }
    }
});

// Year slider interactivity
var yearSlider = document.getElementById('yearSlider');
var yearDisplay = document.getElementById('yearDisplay');
yearSlider.addEventListener('input', function() {
    var yr = parseInt(this.value);
    yearDisplay.textContent = yr;
    birdChart.data.datasets[0].data = birdRichnessData[yr];
    birdChart.update();
});
</script>
SCRIPTS;

require_once 'includes/footer.php';
?>
