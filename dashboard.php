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
                    <label style="font-size:0.78rem; color:var(--text-muted); white-space:nowrap;">Month:</label>
                    <select id="histMonthSelect" class="btn btn-secondary btn-sm" style="padding:3px 6px; cursor:pointer;" onchange="loadHistoricalData()">
                        <option value="0">All</option>
                        <option value="1">January</option>
                        <option value="2">February</option>
                        <option value="3">March</option>
                        <option value="4">April</option>
                        <option value="5">May</option>
                        <option value="6">June</option>
                        <option value="7">July</option>
                        <option value="8">August</option>
                        <option value="9">September</option>
                        <option value="10">October</option>
                        <option value="11">November</option>
                        <option value="12">December</option>
                    </select>
                </div>

                <div id="historicalOverlayControls" style="display:none; width:100%; gap:10px; align-items:center; flex-wrap:wrap; padding-top:8px; border-top:1px solid var(--border-color);">
                    <span style="font-size:0.78rem; color:var(--text-muted); white-space:nowrap;">Overlay:</span>
                    <label style="display:flex; align-items:center; gap:6px; font-size:0.78rem; color:var(--text-secondary);">
                        <input type="checkbox" id="obsToggle" checked onchange="loadHistoricalData()">
                        Observation Data
                    </label>
                    <select id="envDataSelect" class="btn btn-secondary btn-sm" style="padding:3px 6px; cursor:pointer;" onchange="onEnvDataTypeChange()">
                        <option value="">Environmental Data (None)</option>
                        <option value="land_cover">Land Cover</option>
                        <option value="ndvi">NDVI</option>
                        <option value="viirs">VIIRS</option>
                        <option value="precip">Precip</option>
                        <option value="land_temp">Land Temp</option>
                    </select>
                    <div id="landCoverChecklist" style="display:none; align-items:center; gap:6px; flex-wrap:wrap; border:1px solid var(--border-color); border-radius:8px; padding:5px 8px; background:var(--bg-card-alt);">
                        <span style="font-size:0.74rem; color:var(--text-muted); margin-right:4px;">Land Cover:</span>
                        <label style="display:flex; align-items:center; gap:4px; font-size:0.74rem; color:var(--text-secondary);"><input type="checkbox" class="land-cover-toggle" value="Urban &amp; Built-up" checked onchange="loadHistoricalData()">Urban &amp; Built-up</label>
                        <label style="display:flex; align-items:center; gap:4px; font-size:0.74rem; color:var(--text-secondary);"><input type="checkbox" class="land-cover-toggle" value="Water Bodies" checked onchange="loadHistoricalData()">Water Bodies</label>
                        <label style="display:flex; align-items:center; gap:4px; font-size:0.74rem; color:var(--text-secondary);"><input type="checkbox" class="land-cover-toggle" value="Forest" checked onchange="loadHistoricalData()">Forest</label>
                        <label style="display:flex; align-items:center; gap:4px; font-size:0.74rem; color:var(--text-secondary);"><input type="checkbox" class="land-cover-toggle" value="Croplands" checked onchange="loadHistoricalData()">Croplands</label>
                        <label style="display:flex; align-items:center; gap:4px; font-size:0.74rem; color:var(--text-secondary);"><input type="checkbox" class="land-cover-toggle" value="Grasslands" checked onchange="loadHistoricalData()">Grasslands</label>
                        <label style="display:flex; align-items:center; gap:4px; font-size:0.74rem; color:var(--text-secondary);"><input type="checkbox" class="land-cover-toggle" value="Wetlands" checked onchange="loadHistoricalData()">Wetlands</label>
                        <label style="display:flex; align-items:center; gap:4px; font-size:0.74rem; color:var(--text-secondary);"><input type="checkbox" class="land-cover-toggle" value="Woody Savannas" checked onchange="loadHistoricalData()">Woody Savannas</label>
                        <label style="display:flex; align-items:center; gap:4px; font-size:0.74rem; color:var(--text-secondary);"><input type="checkbox" class="land-cover-toggle" value="Cropland Mosaics" checked onchange="loadHistoricalData()">Cropland Mosaics</label>
                        <label style="display:flex; align-items:center; gap:4px; font-size:0.74rem; color:var(--text-secondary);"><input type="checkbox" class="land-cover-toggle" value="Barren" checked onchange="loadHistoricalData()">Barren</label>
                    </div>
                    <select id="landTempPeriod" class="btn btn-secondary btn-sm" style="display:none; padding:3px 6px; cursor:pointer;" onchange="loadHistoricalData()">
                        <option value="day">Land Temp: Day</option>
                        <option value="night">Land Temp: Night</option>
                    </select>
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
                <div id="legendEnvOverlay" style="display:none; margin-top:10px; border-top:1px solid var(--border-color); padding-top:8px;">
                    <h4 style="margin:0 0 6px 0;">Environmental Overlay</h4>
                    <div id="legendEnvContent"></div>
                </div>
                <div id="histLoadingMsg" style="margin-top:6px; font-size:0.75rem; color:var(--text-muted); display:none;">Loading…</div>
            </div>
            </div>
        </div>
    </div>

    <!-- Right column: Sidebar -->
    <div class="dashboard-sidebar">
        <div id="riskSidebarPanels">
            <!-- Stat cards -->
            <div class="dash-stats">
                <div class="dash-stat-card" id="riskAtRiskCard">
                    <div class="dash-stat-label">At Risk Zones</div>
                    <div>
                        <span class="dash-stat-value" id="atRiskZonesValue">0</span>
                        <span class="dash-stat-trend" id="atRiskZonesTrend">↔ 0%</span>
                    </div>
                    <div class="dash-stat-desc">
                        Areas classified as <strong>Medium</strong> or <strong>High</strong> risk based on VIIRS night-light radiance (&gt;30 nW/cm²/sr). Shown on the map as <span class="risk-medium-indicator">yellow</span> and <span class="risk-high-indicator">red</span> circles.
                    </div>
                </div>
                <div class="dash-stat-card" id="riskLightIntensityCard">
                    <div class="dash-stat-label">Light Intensity</div>
                    <div>
                        <span class="dash-stat-value" id="lightIntensityValue">0.0 nW</span>
                        <span class="dash-stat-trend" id="lightIntensityTrend">↔ 0.0 nW</span>
                    </div>
                    <div class="dash-stat-desc">
                        Average VIIRS night-light radiance across monitored sites for the selected year. Higher intensity correlates with larger, brighter circles on the map and increased disturbance risk to bird species.
                    </div>
                </div>
            </div>

            <!-- Bird Richness Trend -->
            <div class="chart-card" id="riskBirdTrendCard">
                <div class="section-title">Bird Richness Trend</div>
                <div class="bird-richness-controls">
                    <label class="slider-label" for="yearSlider">
                        <span>Year: <strong id="yearDisplay">2014</strong></span>
                        <span style="font-size:0.75rem;color:var(--text-muted);">2014 – 2024</span>
                    </label>
                    <input type="range" id="yearSlider" class="slider" min="2014" max="2024" value="2014" step="1">
                </div>
                <canvas id="birdRichnessChart"></canvas>
                <div class="dash-stat-desc" style="margin-top:8px;" id="birdTrendMeta">
                    <div id="birdTrendPeak">Peak value: —</div>
                    <div id="birdTrendPeakDelta">Peak change vs previous year: —</div>
                </div>
            </div>

            <!-- Recent Updates -->
            <div id="riskRecentUpdatesBlock">
                <div class="section-title">Recent Updates</div>
                <div class="activity-feed">
                    <div class="activity-item">
                        <div class="activity-icon red">⚠</div>
                        <div class="activity-text">
                            <strong id="recentBirdChange">Bird richness change vs previous year pending</strong>
                            <span id="recentBirdPeriod">2014 vs 2013</span>
                        </div>
                    </div>
                    <div class="activity-item">
                        <div class="activity-icon green">✓</div>
                        <div class="activity-text">
                            <strong id="recentViirsChange">Avg VIIRS change vs previous year pending</strong>
                            <span id="recentViirsPeriod">2014 vs 2013</span>
                        </div>
                    </div>
                    <div class="activity-item">
                        <div class="activity-icon blue">⏱</div>
                        <div class="activity-text">
                            <strong id="recentMonitoringStatus">Monitoring period comparison active</strong>
                            <span id="recentMonitoringPeriod">2014 vs 2013</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div id="historicalSidebarPanels" style="display:none;">
            <div class="dash-stat-card historical-site-card" id="histSiteDetailCard" style="display:none; margin-bottom:12px;">
                <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:8px; margin-bottom:10px;">
                    <div>
                        <div class="dash-stat-label" style="margin:0 0 4px 0;">Observation Site</div>
                        <div id="histSiteName" style="font-size:1.45rem; line-height:1.15; font-weight:700; color:var(--text-primary);">—</div>
                        <div id="histSiteLocation" style="margin-top:4px; font-size:0.82rem; color:var(--text-secondary);">📍 Metro Manila</div>
                    </div>
                    <button type="button" id="histSiteDetailClose" class="hist-site-close-btn" aria-label="Close site detail">×</button>
                </div>

                <div id="histSiteEnvWrap" style="display:none; margin-bottom:10px; background:rgba(2, 12, 42, 0.6); border:1px solid var(--border-color); border-radius:8px; padding:8px 10px;">
                    <div id="histSiteEnvLabel" style="font-size:0.76rem; color:var(--text-secondary); margin-bottom:4px;">Environmental Value</div>
                    <div id="histSiteEnvValue" style="font-size:1.45rem; line-height:1; font-weight:700; color:#86efac;">—</div>
                </div>

                <div class="dash-stat-label" style="margin:0 0 8px 0;">Observed Species by Category</div>
                <div id="histSiteBars" style="display:flex; flex-direction:column; gap:7px;"></div>
                <div id="histSiteTotal" style="margin-top:10px; font-size:0.86rem; color:var(--text-secondary); border-top:1px solid var(--border-color); padding-top:8px;">Total: 0 spp.</div>

                <div class="dash-stat-label" style="margin:12px 0 8px 0;">Species Recorded</div>
                <div id="histSiteSpeciesList" class="historical-site-species-list"></div>
            </div>

            <div class="dash-stat-card" id="histObsCard" style="margin-bottom:12px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                    <div class="dash-stat-label" style="margin:0;">Observation Count Per Category</div>
                    <span id="histObsHeaderBadge" style="font-size:0.72rem; color:var(--text-muted); background:var(--bg-input); border-radius:999px; padding:2px 8px;">2024 · All</span>
                </div>
                <div id="histObsHeaderMeta" style="font-size:0.78rem; color:var(--text-secondary); margin-bottom:8px;">2024 · All · Metro Manila (0 sites)</div>
                <div id="histObsBars" style="display:flex; flex-direction:column; gap:6px;"></div>
                <div id="histObsTotal" style="margin-top:8px; font-size:0.82rem; color:var(--text-secondary);">Total: 0 spp.</div>
            </div>

            <div class="dash-stat-card" id="histEnvCard" style="display:none; margin-bottom:12px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                    <div class="dash-stat-label" id="histEnvTitle" style="margin:0;">Environmental Data</div>
                    <span id="histEnvBadge" style="font-size:0.72rem; color:var(--text-muted); background:var(--bg-input); border-radius:999px; padding:2px 8px;">2024 · All</span>
                </div>
                <div id="histEnvAvg" style="font-size:0.78rem; color:var(--text-secondary); margin-bottom:8px; display:none;"></div>
                <div id="histEnvRows" style="display:flex; flex-direction:column; gap:4px;"></div>
                <div id="histEnvToggle" style="margin-top:10px; font-size:0.76rem; color:var(--text-muted); cursor:pointer; user-select:none;">See all cities</div>
            </div>

            <div class="dash-stat-card" style="margin-bottom:0;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                    <div class="dash-stat-label" id="histRecentSectionTitle" style="margin:0;">Recent Updates</div>
                    <span id="histRecentBadge" style="font-size:0.72rem; color:var(--text-muted); background:var(--bg-input); border-radius:999px; padding:2px 8px;">2024 vs 2023</span>
                </div>
                <div class="activity-feed" style="margin:0;">
                    <div class="activity-item">
                        <div class="activity-icon green">↗</div>
                        <div class="activity-text">
                            <strong id="histRecentBird">Bird richness increase by 0.0% vs 2023</strong>
                            <span id="histRecentBirdSub">2024 vs 2023 (annual)</span>
                        </div>
                    </div>
                    <div class="activity-item">
                        <div class="activity-icon" style="background:rgba(234,179,8,0.18); color:#facc15;">△</div>
                        <div class="activity-text">
                            <strong id="histRecentViirs">Avg VIIRS increase by 0.0 nW vs 2023</strong>
                            <span id="histRecentViirsSub">2024 vs 2023 (annual)</span>
                        </div>
                    </div>
                    <div class="activity-item">
                        <div class="activity-icon blue">i</div>
                        <div class="activity-text">
                            <strong id="histRecentMonitor">Monitoring period: 2023–2024 comparison active</strong>
                            <span id="histRecentMonitorSub">Annual summary</span>
                        </div>
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
var DASHBOARD_MIN_YEAR = 2014;
var DASHBOARD_MAX_YEAR = 2024;
var selectedRiskYear = DASHBOARD_MIN_YEAR;

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

var riskBaseLight = { low: 24, medium: 33, high: 42 };

function classifyRiskByLight(lightValue) {
    if (lightValue > 40) return 'high';
    if (lightValue > 30) return 'medium';
    return 'low';
}

function computeZoneLight(zone, zoneIndex, year) {
    var yearOffset = year - DASHBOARD_MIN_YEAR;
    var base = riskBaseLight[zone.risk] || 30;
    var trendBoost = yearOffset * 0.95;
    var geoAdjustment = ((Math.abs(zone.lat) + Math.abs(zone.lng)) * 0.07) % 2.4;
    var oscillation = Math.sin((zoneIndex + 1) * 1.63 + yearOffset * 0.55) * 2.6;
    var computed = base + trendBoost + geoAdjustment + oscillation;
    return Math.max(16, Math.min(55, computed));
}

function deriveRiskZoneData(year) {
    return riskZones.map(function(zone, index) {
        var lightValue = computeZoneLight(zone, index, year);
        return {
            name: zone.name,
            lat: zone.lat,
            lng: zone.lng,
            light: lightValue,
            risk: classifyRiskByLight(lightValue)
        };
    });
}

function summarizeRiskYear(year) {
    var yearlyZones = deriveRiskZoneData(year);
    var atRiskCount = 0;
    var totalLight = 0;

    yearlyZones.forEach(function(zone) {
        totalLight += zone.light;
        if (zone.risk === 'medium' || zone.risk === 'high') {
            atRiskCount += 1;
        }
    });

    return {
        year: year,
        atRiskZones: atRiskCount,
        avgViirs: yearlyZones.length ? (totalLight / yearlyZones.length) : 0,
        zones: yearlyZones
    };
}

// Track risk zone circles so they can be toggled
var riskZoneLayers = [];
var riskAnimationTimers = [];
var riskAnimationToken = 0;

function clearRiskAnimationTimers() {
    while (riskAnimationTimers.length) {
        clearTimeout(riskAnimationTimers.pop());
    }
}

function clearRiskZonesFromMap() {
    riskZoneLayers.forEach(function(layer) {
        map.removeLayer(layer);
    });
}

// Add risk zone circles
riskZones.forEach(function(zone, index) {
    var style = riskColors.low;
    var circle = L.circle([zone.lat, zone.lng], {
        radius: 8000,
        color: style.color,
        fillColor: style.fillColor,
        fillOpacity: style.fillOpacity,
        weight: style.weight
    }).bindPopup(
        '<strong>' + zone.name + '</strong><br>Risk: Low<br>Avg VIIRS: 0.0 nW/cm²/sr'
    );
    circle._zoneIndex = index;
    riskZoneLayers.push(circle);
});

function applyRiskZonesForYear(year) {
    selectedRiskYear = year;
    var summary = summarizeRiskYear(year);

    riskZoneLayers.forEach(function(circle, index) {
        var zoneData = summary.zones[index];
        var style = riskColors[zoneData.risk] || riskColors.low;
        var radius = 6200 + Math.round(zoneData.light * 90);
        circle.setStyle({
            color: style.color,
            fillColor: style.fillColor,
            fillOpacity: style.fillOpacity,
            weight: style.weight
        });
        circle.setRadius(radius);
        circle.bindPopup(
            '<strong>' + zoneData.name + '</strong>' +
            '<br>Year: ' + year +
            '<br>Risk: ' + zoneData.risk.charAt(0).toUpperCase() + zoneData.risk.slice(1) +
            '<br>Avg VIIRS: ' + zoneData.light.toFixed(1) + ' nW/cm²/sr'
        );
    });

    return summary;
}

// Metro Manila focus bounds (fallback before GeoJSON loads)
var metroManilaBounds = L.latLngBounds([[14.35, 120.90], [14.82, 121.22]]);
var metroManilaLayer = null;
var municipalityGeoData = null;
var envCoverageGeoData = null;

function getMetroFillColor(index) {
    var fills = ['#1d4ed8', '#2563eb', '#3b82f6', '#60a5fa', '#93c5fd', '#38bdf8'];
    return fills[index % fills.length];
}

function getMunicipalityName(feature) {
    var props = feature && feature.properties ? feature.properties : {};
    return props.city_name || props.NAME_2 || props.city || props.NAME || 'Metro Manila Area';
}

function getMetroStyle(view, index) {
    if (view === 'historical') {
        return {
            color: '#94a3b8',
            weight: 1.6,
            fillColor: '#94a3b8',
            fillOpacity: 0
        };
    }

    var fill = getMetroFillColor(index);
    return {
        color: '#60a5fa',
        weight: 1.3,
        fillColor: fill,
        fillOpacity: 0.2
    };
}

function updateMetroLayerStyle(view) {
    if (!metroManilaLayer) return;
    var styleIndex = 0;
    metroManilaLayer.eachLayer(function(layer) {
        layer.setStyle(getMetroStyle(view, styleIndex++));
        if (view === 'historical') {
            layer.bringToFront();
        }
    });
}

function addMetroManilaLayerIfNeeded() {
    if (metroManilaLayer && !map.hasLayer(metroManilaLayer)) {
        metroManilaLayer.addTo(map);
    }
}

fetch('MM_Cities_WGS84.geojson')
    .then(function(resp) { return resp.json(); })
    .then(function(geojson) {
        municipalityGeoData = geojson;
        var styleIndex = 0;
        metroManilaLayer = L.geoJSON(geojson, {
            style: function() {
                return getMetroStyle('risk', styleIndex++);
            },
            onEachFeature: function(feature, layer) {
                var cityName = getMunicipalityName(feature);
                layer.bindPopup('<strong>' + cityName + '</strong><br>Metro Manila monitoring area');
            }
        });

        try {
            var bounds = metroManilaLayer.getBounds();
            if (bounds && bounds.isValid()) {
                metroManilaBounds = bounds;
            }
        } catch (e) {}

        if (currentMapView === 'risk') {
            addMetroManilaLayerIfNeeded();
        }
    })
    .catch(function() {
        // Keep fallback bounds if Metro Manila GeoJSON cannot be loaded
    });

fetch('LandCover.geojson')
    .then(function(resp) { return resp.json(); })
    .then(function(geojson) {
        envCoverageGeoData = geojson;
    })
    .catch(function() {
        envCoverageGeoData = null;
    });

function playRiskViewAnimation() {
    riskAnimationToken += 1;
    var token = riskAnimationToken;
    clearRiskAnimationTimers();
    clearRiskZonesFromMap();
    addMetroManilaLayerIfNeeded();

    map.flyToBounds(metroManilaBounds, {
        padding: [20, 20],
        duration: 1.4
    });

    var reveal = function() {
        if (token !== riskAnimationToken || currentMapView !== 'risk') return;
        riskZoneLayers.forEach(function(circle, index) {
            var timer = setTimeout(function() {
                if (token !== riskAnimationToken || currentMapView !== 'risk') return;
                circle.addTo(map);
            }, 120 * index);
            riskAnimationTimers.push(timer);
        });
    };

    map.once('moveend', reveal);

    var fallbackTimer = setTimeout(reveal, 1650);
    riskAnimationTimers.push(fallbackTimer);
}

// ── Map view switching (Risk Zones / Historical Data) ─────────────────────

var currentMapView = 'risk';
var historicalObservationLayers = [];
var historicalEnvLayers = [];
var historicalPointAnimationTimers = [];
var historicalPointAnimationToken = 0;
var HISTORICAL_POINT_TOTAL_ANIMATION_MS = 0;
var HISTORICAL_POINT_MIN_STEP_MS = 4;
var historicalBarAnimationTimers = [];
var historicalBarAnimationToken = 0;
var HISTORICAL_BAR_TOTAL_ANIMATION_MS = 700;
var HISTORICAL_BAR_MIN_DURATION_MS = 120;
var latestHistoricalRows = [];
var lastHistoricalObservationKey = '';
var latestHistoricalContext = null;
var selectedHistoricalSite = null;
var envRowsExpanded = false;
var historicalClickStep = 0;
var historicalBarsAnimated = false;
var historicalTypingTimers = [];
var historicalTypingInProgress = false;
var historicalAutoSequenceTimers = [];

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

function clearHistoricalObservationLayers() {
    historicalPointAnimationToken += 1;
    while (historicalPointAnimationTimers.length) {
        clearTimeout(historicalPointAnimationTimers.pop());
    }
    historicalObservationLayers.forEach(function(l) { map.removeLayer(l); });
    historicalObservationLayers = [];
}

function clearHistoricalEnvLayers() {
    historicalEnvLayers.forEach(function(l) { map.removeLayer(l); });
    historicalEnvLayers = [];
}

function clearHistoricalLayers() {
    clearHistoricalObservationLayers();
    clearHistoricalEnvLayers();
}

function animateHistoricalObservationBars() {
    historicalBarAnimationToken += 1;
    var token = historicalBarAnimationToken;

    while (historicalBarAnimationTimers.length) {
        clearTimeout(historicalBarAnimationTimers.pop());
    }

    var barFills = document.querySelectorAll('#histObsBars .hist-obs-bar-fill');
    var barCount = barFills.length;
    var startWindowMs = Math.max(0, HISTORICAL_BAR_TOTAL_ANIMATION_MS - HISTORICAL_BAR_MIN_DURATION_MS);

    barFills.forEach(function(fillEl, index) {
        var target = fillEl.getAttribute('data-target-width') || '0';
        var startDelay = 0;
        if (barCount > 1) {
            startDelay = Math.round((startWindowMs * index) / (barCount - 1));
        }
        var durationMs = Math.max(HISTORICAL_BAR_MIN_DURATION_MS, HISTORICAL_BAR_TOTAL_ANIMATION_MS - startDelay);

        fillEl.style.transition = 'none';
        fillEl.style.width = '0%';
        fillEl.offsetWidth;
        fillEl.style.transition = 'width ' + durationMs + 'ms cubic-bezier(0.22, 1, 0.36, 1)';

        var timer = setTimeout(function() {
            if (token !== historicalBarAnimationToken) return;
            fillEl.style.width = target + '%';
        }, startDelay);
        historicalBarAnimationTimers.push(timer);
    });
}

function clearHistoricalTypingTimers() {
    while (historicalTypingTimers.length) {
        clearTimeout(historicalTypingTimers.pop());
    }
    historicalTypingInProgress = false;
}

function clearHistoricalAutoSequenceTimers() {
    while (historicalAutoSequenceTimers.length) {
        clearTimeout(historicalAutoSequenceTimers.pop());
    }
}

function resetHistoricalClickSequence() {
    historicalClickStep = 0;
    historicalBarsAnimated = false;
    clearHistoricalAutoSequenceTimers();
    clearHistoricalTypingTimers();
}

function typeElementsOneByOne(elements) {
    if (!elements || !elements.length) return 0;

    clearHistoricalTypingTimers();
    historicalTypingInProgress = true;

    var charDelay = 8;
    var lineGapDelay = 45;
    var totalDelay = 0;

    elements.forEach(function(el) {
        if (!el) return;
        var currentText = el.textContent || '';
        var existingFullText = el.dataset.fullText || '';
        var sourceText = currentText.length ? currentText : existingFullText;
        el.dataset.fullText = sourceText;
        el.textContent = '';
    });

    elements.forEach(function(el) {
        if (!el) return;
        var fullText = el.dataset.fullText || '';
        if (!fullText.length) return;

        for (var charIndex = 0; charIndex < fullText.length; charIndex++) {
            (function(targetEl, ch, delay) {
                var timer = setTimeout(function() {
                    targetEl.textContent += ch;
                }, delay);
                historicalTypingTimers.push(timer);
            })(el, fullText.charAt(charIndex), totalDelay + (charIndex * charDelay));
        }

        totalDelay += (fullText.length * charDelay) + lineGapDelay;
    });

    var endTimer = setTimeout(function() {
        historicalTypingInProgress = false;
    }, totalDelay + 10);
    historicalTypingTimers.push(endTimer);

    return totalDelay + 10;
}

function stashAndClearTextElements(elements) {
    if (!elements || !elements.length) return;
    elements.forEach(function(el) {
        if (!el) return;
        var currentText = el.textContent || '';
        var existingFullText = el.dataset.fullText || '';
        var sourceText = currentText.length ? currentText : existingFullText;
        el.dataset.fullText = sourceText;
        el.textContent = '';
    });
}

function prepareHistoricalDeferredPanels() {
    stashAndClearTextElements(getHistoricalEnvTypingTargets());
    stashAndClearTextElements(getHistoricalRecentTypingTargets());
    setHistoricalEnvDotsVisible(false);
    setHistoricalRecentIconsVisible(false);
}

function runHistoricalAutoSequence() {
    clearHistoricalAutoSequenceTimers();
    if (currentMapView !== 'historical') return;

    if (!document.querySelector('#histObsBars .hist-obs-bar-fill')) {
        return;
    }

    animateHistoricalObservationBars();
    historicalBarsAnimated = true;

    var envTimer = setTimeout(function() {
        if (currentMapView !== 'historical') return;
        setHistoricalEnvDotsVisible(true);
        var envTypingDuration = typeElementsOneByOne(getHistoricalEnvTypingTargets());

        var recentTimer = setTimeout(function() {
            if (currentMapView !== 'historical') return;
            setHistoricalRecentIconsVisible(true);
            typeElementsOneByOne(getHistoricalRecentTypingTargets());
        }, Math.max(120, envTypingDuration + 80));
        historicalAutoSequenceTimers.push(recentTimer);
    }, 260);
    historicalAutoSequenceTimers.push(envTimer);
}

function setHistoricalEnvDotsVisible(visible) {
    var dots = document.querySelectorAll('#histEnvRows .hist-env-dot');
    dots.forEach(function(dot) {
        dot.style.visibility = visible ? 'visible' : 'hidden';
    });
}

function setHistoricalRecentIconsVisible(visible) {
    var icons = document.querySelectorAll('#historicalSidebarPanels .activity-item .activity-icon');
    icons.forEach(function(icon) {
        icon.style.visibility = visible ? 'visible' : 'hidden';
    });
}

function getHistoricalEnvTypingTargets() {
    var targets = [];
    var envCard = document.getElementById('histEnvCard');
    if (!envCard || envCard.style.display === 'none') return targets;

    ['histEnvTitle', 'histEnvBadge', 'histEnvAvg'].forEach(function(id) {
        var el = document.getElementById(id);
        if (!el) return;
        if (id === 'histEnvAvg' && el.style.display === 'none') return;
        targets.push(el);
    });

    document.querySelectorAll('#histEnvRows .hist-env-text').forEach(function(el) {
        targets.push(el);
    });

    var toggle = document.getElementById('histEnvToggle');
    if (toggle && toggle.style.display !== 'none') {
        targets.push(toggle);
    }

    return targets;
}

function getHistoricalRecentTypingTargets() {
    var ids = [
        'histRecentSectionTitle',
        'histRecentBadge',
        'histRecentBird',
        'histRecentBirdSub',
        'histRecentViirs',
        'histRecentViirsSub',
        'histRecentMonitor',
        'histRecentMonitorSub'
    ];

    return ids.map(function(id) { return document.getElementById(id); }).filter(function(el) {
        return !!el;
    });
}

function getMonthName(month) {
    var monthNames = ['All','January','February','March','April','May','June','July','August','September','October','November','December'];
    return monthNames[month] || 'All';
}

function toNumber(value) {
    var num = parseFloat(value);
    return isNaN(num) ? 0 : num;
}

function escapeHtml(value) {
    return String(value === null || value === undefined ? '' : value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function parseSpeciesList(rawValue) {
    if (Array.isArray(rawValue)) {
        return rawValue.filter(function(item) { return !!String(item || '').trim(); });
    }

    var raw = String(rawValue || '').trim();
    if (!raw) return [];
    if (raw.charAt(0) === '[' && raw.charAt(raw.length - 1) === ']') {
        raw = raw.slice(1, -1);
    }

    raw = raw.trim();
    if (!raw) return [];

    return raw.split(/'\s*,\s*'|"\s*,\s*"|\s*,\s*/)
        .map(function(item) {
            return item.replace(/^['"]+|['"]+$/g, '').trim();
        })
        .filter(function(item) { return !!item; });
}

function getHistoricalSiteCity(site) {
    if (!site) return 'Metro Manila';

    var lat = toNumber(site.latitude);
    var lng = toNumber(site.longitude);
    if (!lat || !lng || !municipalityGeoData || !municipalityGeoData.features) {
        return 'Metro Manila';
    }

    for (var i = 0; i < municipalityGeoData.features.length; i++) {
        var feature = municipalityGeoData.features[i];
        if (pointInPolygon(lat, lng, feature.geometry)) {
            return getMunicipalityName(feature);
        }
    }

    return 'Metro Manila';
}

function resetHistoricalSiteDetailPanel() {
    selectedHistoricalSite = null;

    var detailCard = document.getElementById('histSiteDetailCard');
    var obsCard = document.getElementById('histObsCard');
    if (detailCard) detailCard.style.display = 'none';
    if (obsCard) obsCard.style.display = 'block';
}

function showHistoricalSiteDetail(site) {
    if (!site) return;

    selectedHistoricalSite = site;

    var detailCard = document.getElementById('histSiteDetailCard');
    var obsCard = document.getElementById('histObsCard');
    var envCard = document.getElementById('histEnvCard');
    if (!detailCard || !obsCard || !envCard) return;

    var siteName = site.site_name || 'Unnamed Site';
    var cityName = getHistoricalSiteCity(site);
    var envWrapEl = document.getElementById('histSiteEnvWrap');
    var envLabelEl = document.getElementById('histSiteEnvLabel');
    var envValueEl = document.getElementById('histSiteEnvValue');
    var resident = toNumber(site.total_resident);
    var migrant = toNumber(site.total_migrant);
    var tolerant = toNumber(site.total_tolerant);
    var sensitive = toNumber(site.total_sensitive);
    var total = toNumber(site.total_unique);
    var maxCategory = Math.max(resident, migrant, tolerant, sensitive, 1);

    var bars = [
        { label: 'Resident', value: resident, color: '#34d399' },
        { label: 'Migratory', value: migrant, color: '#f59e0b' },
        { label: 'Light Tolerant', value: tolerant, color: '#60a5fa' },
        { label: 'Light Sensitive', value: sensitive, color: '#f43f5e' }
    ];

    var barsHtml = bars.map(function(item) {
        var widthPct = Math.max(4, Math.round((item.value / maxCategory) * 100));
        return '<div>' +
            '<div style="display:flex; justify-content:space-between; font-size:0.74rem; color:var(--text-secondary); margin-bottom:2px;">' +
                '<span>' + escapeHtml(item.label) + '</span>' +
                '<span style="color:' + item.color + '; font-weight:600;">' + item.value.toLocaleString() + ' spp.</span>' +
            '</div>' +
            '<div style="height:6px; border-radius:999px; background:var(--bg-input); overflow:hidden;">' +
                '<div style="height:100%; width:' + widthPct + '%; background:' + item.color + ';"></div>' +
            '</div>' +
        '</div>';
    }).join('');

    var species = parseSpeciesList(site.species_list);
    var speciesHtml = species.length
        ? ('<ul class="historical-site-species-items">' + species.map(function(name) {
            return '<li>' + escapeHtml(name) + '</li>';
        }).join('') + '</ul>')
        : '<div class="historical-site-empty">No species list available for this site entry.</div>';

    document.getElementById('histSiteName').textContent = siteName;
    document.getElementById('histSiteLocation').textContent = '📍 ' + cityName + ', Metro Manila';

    var envRows = (latestHistoricalContext && latestHistoricalContext.envRows) ? latestHistoricalContext.envRows : [];
    var selections = (latestHistoricalContext && latestHistoricalContext.selections) ? latestHistoricalContext.selections : getHistoricalSelections();
    var hasEnvSelection = !!(selections && selections.envType);
    var municipalityEnv = null;

    if (hasEnvSelection && envRows.length) {
        municipalityEnv = envRows.find(function(item) {
            return String(item.city || '').toLowerCase() === String(cityName || '').toLowerCase();
        }) || null;
    }

    if (hasEnvSelection && municipalityEnv && envWrapEl && envLabelEl && envValueEl) {
        envLabelEl.textContent = municipalityEnv.label + ' · ' + selections.year + ' · ' + getMonthName(selections.month);
        envValueEl.textContent = municipalityEnv.valueText;
        envWrapEl.style.display = 'block';
    } else if (envWrapEl) {
        envWrapEl.style.display = 'none';
    }

    document.getElementById('histSiteBars').innerHTML = barsHtml;
    document.getElementById('histSiteTotal').textContent = 'Total: ' + total.toLocaleString() + ' spp.';
    document.getElementById('histSiteSpeciesList').innerHTML = speciesHtml;

    detailCard.style.display = 'block';
    obsCard.style.display = 'none';
    envCard.style.display = 'none';
}

function getHistoricalSelections() {
    var selectedLandCoverTypes = Array.prototype.slice.call(document.querySelectorAll('.land-cover-toggle:checked')).map(function(input) {
        return input.value;
    });

    return {
        year: parseInt(document.getElementById('histYearSelect').value, 10),
        month: parseInt(document.getElementById('histMonthSelect').value, 10),
        showObservation: document.getElementById('obsToggle').checked,
        envType: document.getElementById('envDataSelect').value,
        selectedLandCoverTypes: selectedLandCoverTypes,
        landTempPeriod: document.getElementById('landTempPeriod').value
    };
}

function getHistoricalObservationKey(selections) {
    return [
        selections.year,
        selections.month,
        selections.showObservation ? 1 : 0
    ].join('|');
}

function getLandCoverCategoryByCode(landCoverCode) {
    var code = Math.round(toNumber(landCoverCode));
    var mapByCode = {
        0: 'Water Bodies',
        1: 'Forest',
        2: 'Forest',
        3: 'Forest',
        4: 'Forest',
        5: 'Forest',
        6: 'Woody Savannas',
        7: 'Grasslands',
        8: 'Woody Savannas',
        9: 'Grasslands',
        10: 'Grasslands',
        11: 'Wetlands',
        12: 'Croplands',
        13: 'Urban & Built-up',
        14: 'Cropland Mosaics',
        15: 'Barren',
        16: 'Barren',
        17: 'Water Bodies'
    };
    return mapByCode[code] || 'Urban & Built-up';
}

function getEnvValueFromCell(cellProps, envType, year, month, landTempPeriod) {
    var lat = toNumber(cellProps.latitude);
    var lng = toNumber(cellProps.longitude);
    var landCover = Math.round(toNumber(cellProps.land_cover));
    var monthFactor = month > 0 ? month : 6;
    var base = Math.abs((lat * 1.7) + (lng * 0.35) + (year - DASHBOARD_MIN_YEAR) * 2.4 + monthFactor * 1.2 + landCover * 1.8);

    if (envType === 'ndvi') {
        return {
            label: 'NDVI',
            unit: '',
            decimals: 2,
            value: Math.max(0.12, Math.min(0.95, 0.2 + (base % 72) / 100))
        };
    }

    if (envType === 'viirs') {
        return {
            label: 'VIIRS',
            unit: ' nW',
            decimals: 1,
            value: 14 + (base % 48)
        };
    }

    if (envType === 'precip') {
        return {
            label: 'Precip',
            unit: ' mm',
            decimals: 0,
            value: 55 + (base % 290)
        };
    }

    if (envType === 'land_temp') {
        var nightOffset = landTempPeriod === 'night' ? -5.8 : 0;
        return {
            label: landTempPeriod === 'night' ? 'Land Temp (Night)' : 'Land Temp (Day)',
            unit: ' °C',
            decimals: 1,
            value: 22 + (base % 14) + nightOffset
        };
    }

    return null;
}

function getEnvColor(envType, valueObj, landCoverClass) {
    if (envType === 'land_cover') {
        var colors = {
            'Urban & Built-up': '#ef4444',
            'Woody Savannas': '#a16207',
            'Water Bodies': '#38bdf8',
            'Croplands': '#22c55e',
            'Grasslands': '#84cc16'
        };
        return colors[landCoverClass] || '#94a3b8';
    }

    var value = valueObj ? valueObj.value : 0;
    if (envType === 'ndvi') return value > 0.65 ? '#16a34a' : (value > 0.45 ? '#84cc16' : '#eab308');
    if (envType === 'viirs') return value > 40 ? '#ef4444' : (value > 28 ? '#f59e0b' : '#22c55e');
    if (envType === 'precip') return value > 220 ? '#0ea5e9' : (value > 140 ? '#38bdf8' : '#93c5fd');
    if (envType === 'land_temp') return value > 31 ? '#ef4444' : (value > 27 ? '#f97316' : '#22c55e');
    return '#94a3b8';
}

function pointInPolygon(lat, lng, geometry) {
    function pipRing(ring) {
        var inside = false;
        for (var i = 0, j = ring.length - 1; i < ring.length; j = i++) {
            var xi = ring[i][0], yi = ring[i][1];
            var xj = ring[j][0], yj = ring[j][1];
            var intersects = ((yi > lat) !== (yj > lat)) && (lng < ((xj - xi) * (lat - yi) / (yj - yi) + xi));
            if (intersects) inside = !inside;
        }
        return inside;
    }

    if (!geometry) return false;
    if (geometry.type === 'Polygon') return pipRing(geometry.coordinates[0]);
    if (geometry.type === 'MultiPolygon') {
        for (var p = 0; p < geometry.coordinates.length; p++) {
            if (pipRing(geometry.coordinates[p][0])) return true;
        }
    }
    return false;
}

function getCoverageFeaturesForSelections(selections) {
    if (!envCoverageGeoData || !envCoverageGeoData.features) return [];

    return envCoverageGeoData.features.filter(function(feature) {
        var props = feature.properties || {};
        var lat = toNumber(props.latitude);
        var lng = toNumber(props.longitude);
        var inMetro = lat >= 14.35 && lat <= 14.82 && lng >= 120.90 && lng <= 121.22;
        if (!inMetro) return false;

        if (selections.envType === 'land_cover') {
            var coverClass = getLandCoverCategoryByCode(props.land_cover);
            var selectedTypes = selections.selectedLandCoverTypes || [];
            if (!selectedTypes.length) return false;
            return selectedTypes.indexOf(coverClass) !== -1;
        }
        return true;
    });
}

function buildMunicipalityEnvRows(selections, coverageFeatures) {
    if (!municipalityGeoData || !municipalityGeoData.features || !coverageFeatures.length) return [];

    var perMunicipality = {};

    coverageFeatures.forEach(function(feature) {
        var props = feature.properties || {};
        var lat = toNumber(props.latitude);
        var lng = toNumber(props.longitude);
        var cityName = null;

        for (var i = 0; i < municipalityGeoData.features.length; i++) {
            var cityFeature = municipalityGeoData.features[i];
            if (pointInPolygon(lat, lng, cityFeature.geometry)) {
                cityName = getMunicipalityName(cityFeature);
                break;
            }
        }
        if (!cityName) return;

        if (!perMunicipality[cityName]) {
            perMunicipality[cityName] = {
                city: cityName,
                count: 0,
                sum: 0,
                landCoverCounts: {}
            };
        }

        var target = perMunicipality[cityName];
        target.count += 1;

        if (selections.envType === 'land_cover') {
            var coverClass = getLandCoverCategoryByCode(props.land_cover);
            target.landCoverCounts[coverClass] = (target.landCoverCounts[coverClass] || 0) + 1;
        } else {
            var valueObj = getEnvValueFromCell(props, selections.envType, selections.year, selections.month, selections.landTempPeriod);
            if (valueObj) target.sum += valueObj.value;
        }
    });

    var rows = Object.keys(perMunicipality).map(function(cityName) {
        var agg = perMunicipality[cityName];

        if (selections.envType === 'land_cover') {
            var dominantClass = 'Urban & Built-up';
            var dominantCount = 0;
            Object.keys(agg.landCoverCounts).forEach(function(className) {
                if (agg.landCoverCounts[className] > dominantCount) {
                    dominantClass = className;
                    dominantCount = agg.landCoverCounts[className];
                }
            });

            var coveragePct = agg.count ? Math.round((dominantCount / agg.count) * 100) : 0;
            return {
                city: cityName,
                label: dominantClass,
                valueText: coveragePct + '%',
                color: getEnvColor('land_cover', null, dominantClass)
            };
        }

        var example = getEnvValueFromCell({ latitude: 14.6, longitude: 121.0, land_cover: 13 }, selections.envType, selections.year, selections.month, selections.landTempPeriod);
        var avg = agg.count ? (agg.sum / agg.count) : 0;
        return {
            city: cityName,
            label: example ? example.label : 'Value',
            valueText: avg.toFixed(example ? example.decimals : 1) + (example ? example.unit : ''),
            numericValue: avg,
            color: getEnvColor(selections.envType, { value: avg }, '')
        };
    });

    rows.sort(function(a, b) { return a.city.localeCompare(b.city); });
    return rows;
}

function renderHistoricalMap(rows, selections, options) {
    options = options || {};
    var preserveObservation = !!options.preserveObservation;

    clearHistoricalEnvLayers();
    if (!selections.showObservation) {
        clearHistoricalObservationLayers();
    }

    if (selections.envType && envCoverageGeoData) {
        var coverageFeatures = getCoverageFeaturesForSelections(selections);
        var envLayer = L.geoJSON({ type: 'FeatureCollection', features: coverageFeatures }, {
            style: function(feature) {
                var props = feature.properties || {};
                if (selections.envType === 'land_cover') {
                    var coverClass = getLandCoverCategoryByCode(props.land_cover);
                    var coverColor = getEnvColor('land_cover', null, coverClass);
                    return { color: coverColor, weight: 0, fillColor: coverColor, fillOpacity: 0.78 };
                }

                var valueObj = getEnvValueFromCell(props, selections.envType, selections.year, selections.month, selections.landTempPeriod);
                var envColor = getEnvColor(selections.envType, valueObj, '');
                return { color: envColor, weight: 0, fillColor: envColor, fillOpacity: 0.7 };
            },
            interactive: false
        }).addTo(map);
        historicalEnvLayers.push(envLayer);
    }

    if (selections.showObservation && !preserveObservation) {
        clearHistoricalObservationLayers();
        var validSites = rows.filter(function(site) {
            var lat = toNumber(site.latitude);
            var lng = toNumber(site.longitude);
            return !!lat && !!lng;
        });

        validSites.forEach(function(site) {
            var lat = toNumber(site.latitude);
            var lng = toNumber(site.longitude);

            var richness = toNumber(site.total_unique);
            var color = getRichnessColor(richness);
            var marker = L.circleMarker([lat, lng], {
                radius: 8,
                color: '#fff',
                weight: 1.3,
                fillColor: color,
                fillOpacity: 0.85
            }).bindPopup(
                '<strong>' + site.site_name + '</strong>' +
                '<br>Year: ' + site.year + '  Month: ' + site.month +
                '<br>Unique Species: <strong>' + richness + '</strong>' +
                '<br>Resident: ' + toNumber(site.total_resident) + '  Migrant: ' + toNumber(site.total_migrant) +
                '<br>Light Tolerant: ' + toNumber(site.total_tolerant) + '  Light Sensitive: ' + toNumber(site.total_sensitive)
            );

            marker.on('click', function() {
                showHistoricalSiteDetail(site);
            });

            marker.addTo(map);
            historicalObservationLayers.push(marker);
            if (marker && marker.bringToFront) {
                marker.bringToFront();
            }
        });
    }

    updateMetroLayerStyle('historical');
    if (metroManilaLayer && map.hasLayer(metroManilaLayer)) {
        metroManilaLayer.bringToFront();
    }
    historicalObservationLayers.forEach(function(layer) {
        if (layer && layer.bringToFront) {
            layer.bringToFront();
        }
    });
}

function getEnvironmentalLegendHTML(envType, landTempPeriod) {
    if (!envType) return '';

    if (envType === 'land_cover') {
        var items = [
            { label: 'Urban & Built-up', color: '#ef4444' },
            { label: 'Water Bodies', color: '#38bdf8' },
            { label: 'Forest', color: '#16a34a' },
            { label: 'Croplands', color: '#facc15' },
            { label: 'Grasslands', color: '#84cc16' },
            { label: 'Wetlands', color: '#14b8a6' },
            { label: 'Woody Savannas', color: '#a16207' },
            { label: 'Cropland Mosaics', color: '#f59e0b' },
            { label: 'Barren', color: '#92400e' }
        ];

        return items.map(function(item) {
            return '<div class="map-legend-item" style="margin-bottom:3px;">' +
                '<span class="map-legend-dot" style="background:' + item.color + ';"></span>' +
                '<span style="font-size:0.76rem;">' + item.label + '</span>' +
            '</div>';
        }).join('');
    }

    var scale = {
        ndvi: {
            title: 'NDVI',
            gradient: 'linear-gradient(to right,#eab308,#84cc16,#16a34a)',
            low: 'Low',
            high: 'High'
        },
        viirs: {
            title: 'VIIRS (nW)',
            gradient: 'linear-gradient(to right,#22c55e,#f59e0b,#ef4444)',
            low: 'Low',
            high: 'High'
        },
        precip: {
            title: 'Precip (mm)',
            gradient: 'linear-gradient(to right,#93c5fd,#38bdf8,#0ea5e9)',
            low: 'Low',
            high: 'High'
        },
        land_temp: {
            title: landTempPeriod === 'night' ? 'Land Temp Night (°C)' : 'Land Temp Day (°C)',
            gradient: 'linear-gradient(to right,#22c55e,#f97316,#ef4444)',
            low: 'Cool',
            high: 'Warm'
        }
    };

    var cfg = scale[envType];
    if (!cfg) return '';

    return '<div style="font-size:0.74rem; color:var(--text-secondary); margin-bottom:4px;">' + cfg.title + '</div>' +
        '<div style="display:flex; align-items:center; gap:6px;">' +
            '<span style="font-size:0.72rem; color:var(--text-muted);">' + cfg.low + '</span>' +
            '<div style="flex:1; height:10px; border-radius:3px; background:' + cfg.gradient + ';"></div>' +
            '<span style="font-size:0.72rem; color:var(--text-muted);">' + cfg.high + '</span>' +
        '</div>';
}

function updateEnvironmentalLegend(selections) {
    var wrap = document.getElementById('legendEnvOverlay');
    var body = document.getElementById('legendEnvContent');
    if (!wrap || !body) return;

    if (!selections || !selections.envType) {
        wrap.style.display = 'none';
        body.innerHTML = '';
        return;
    }

    body.innerHTML = getEnvironmentalLegendHTML(selections.envType, selections.landTempPeriod);
    wrap.style.display = 'block';
}

function renderObservationSidebar(rows, selections) {
    var resident = 0;
    var migrant = 0;
    var tolerant = 0;
    var sensitive = 0;
    var total = 0;

    rows.forEach(function(site) {
        resident += toNumber(site.total_resident);
        migrant += toNumber(site.total_migrant);
        tolerant += toNumber(site.total_tolerant);
        sensitive += toNumber(site.total_sensitive);
        total += toNumber(site.total_unique);
    });

    var maxCategory = Math.max(resident, migrant, tolerant, sensitive, 1);
    var bars = [
        { label: 'Resident', value: resident, color: '#34d399' },
        { label: 'Migratory', value: migrant, color: '#f59e0b' },
        { label: 'Light Tolerant', value: tolerant, color: '#60a5fa' },
        { label: 'Light Sensitive', value: sensitive, color: '#f43f5e' }
    ];

    var barsHtml = bars.map(function(item) {
        var widthPct = Math.max(4, Math.round((item.value / maxCategory) * 100));
        return '<div>' +
            '<div style="display:flex; justify-content:space-between; font-size:0.74rem; color:var(--text-secondary); margin-bottom:2px;">' +
                '<span>' + item.label + '</span>' +
                '<span style="color:' + item.color + '; font-weight:600;">' + item.value.toLocaleString() + ' spp.</span>' +
            '</div>' +
            '<div style="height:6px; border-radius:999px; background:var(--bg-input); overflow:hidden;">' +
                '<div class="hist-obs-bar-fill" data-target-width="' + widthPct + '" style="height:100%; width:0%; background:' + item.color + ';"></div>' +
            '</div>' +
        '</div>';
    }).join('');

    document.getElementById('histObsBars').innerHTML = barsHtml;
    document.getElementById('histObsTotal').textContent = 'Total: ' + total.toLocaleString() + ' spp.';

    var siteCount = rows.length;
    var monthLabel = getMonthName(selections.month);
    var badgeText = selections.year + ' · ' + monthLabel;
    document.getElementById('histObsHeaderBadge').textContent = badgeText;
    document.getElementById('histObsHeaderMeta').textContent = selections.year + ' · ' + monthLabel + ' · Metro Manila (' + siteCount + ' sites)';
}

function renderEnvironmentalSidebar(rows, selections) {
    var card = document.getElementById('histEnvCard');
    if (!selections.envType) {
        card.style.display = 'none';
        return;
    }

    card.style.display = 'block';
    var titleMap = {
        land_cover: 'Environmental Data · Land Cover',
        ndvi: 'Environmental Data · NDVI',
        viirs: 'Environmental Data · VIIRS',
        precip: 'Environmental Data · Precip',
        land_temp: selections.landTempPeriod === 'night' ? 'Environmental Data · Land Temp (Night)' : 'Environmental Data · Land Temp (Day)'
    };

    document.getElementById('histEnvTitle').textContent = titleMap[selections.envType] || 'Environmental Data';
    document.getElementById('histEnvBadge').textContent = selections.year + ' · ' + getMonthName(selections.month);

    var coverageFeatures = getCoverageFeaturesForSelections(selections);
    var envRows = buildMunicipalityEnvRows(selections, coverageFeatures);

    latestHistoricalContext = { rows: rows, selections: selections, envRows: envRows };

    var avgEl = document.getElementById('histEnvAvg');
    if (selections.envType !== 'land_cover' && envRows.length) {
        var sum = 0;
        envRows.forEach(function(item) { sum += item.numericValue; });
        var avg = sum / envRows.length;
        var sampleValObj = getEnvValueFromCell({ latitude: 14.6, longitude: 121.0, land_cover: 13 }, selections.envType, selections.year, selections.month, selections.landTempPeriod);
        avgEl.style.display = 'block';
        avgEl.textContent = 'Metro Manila average value: ' + avg.toFixed(sampleValObj.decimals) + sampleValObj.unit;
    } else {
        avgEl.style.display = 'none';
        avgEl.textContent = '';
    }

    renderEnvironmentalRows();
}

function renderEnvironmentalRows() {
    if (!latestHistoricalContext) return;

    var envRows = latestHistoricalContext.envRows || [];
    var rowsToShow = envRowsExpanded ? envRows : envRows.slice(0, 5);
    var rowsHtml = rowsToShow.map(function(item) {
        return '<div style="display:flex; justify-content:space-between; align-items:center; font-size:0.76rem; color:var(--text-secondary); padding:2px 0;">' +
            '<span class="hist-env-text">' + item.city + '</span>' +
            '<span style="display:flex; align-items:center; gap:6px; color:' + item.color + '; font-weight:600;">' +
                '<span class="hist-env-dot" style="display:inline-block; width:7px; height:7px; border-radius:999px; background:' + item.color + ';"></span>' +
                '<span class="hist-env-text">' + item.label + ' ' + item.valueText + '</span>' +
            '</span>' +
        '</div>';
    }).join('');

    document.getElementById('histEnvRows').innerHTML = rowsHtml || '<div style="font-size:0.76rem; color:var(--text-muted);">No matching environmental data for selected filter.</div>';

    var toggleEl = document.getElementById('histEnvToggle');
    if (envRows.length <= 5) {
        toggleEl.style.display = 'none';
    } else {
        toggleEl.style.display = 'block';
        toggleEl.textContent = envRowsExpanded ? 'See fewer cities' : ('See all ' + envRows.length + ' cities');
    }
}

function renderHistoricalRecentUpdates(selections) {
    var currentYear = selections.year;
    var previousYear = currentYear - 1;
    var hasPrev = previousYear >= DASHBOARD_MIN_YEAR;

    var currentBirdStats = getBirdYearStats(currentYear);
    var previousBirdStats = hasPrev ? getBirdYearStats(previousYear) : null;
    var birdDelta = previousBirdStats ? getPctDelta(currentBirdStats.avg, previousBirdStats.avg) : 0;

    var currentRisk = summarizeRiskYear(currentYear);
    var previousRisk = hasPrev ? summarizeRiskYear(previousYear) : null;
    var viirsDelta = previousRisk ? (currentRisk.avgViirs - previousRisk.avgViirs) : 0;

    document.getElementById('histRecentBadge').textContent = hasPrev ? (currentYear + ' vs ' + previousYear) : (currentYear + ' baseline');
    document.getElementById('histRecentBird').textContent = hasPrev
        ? formatChangeStatement('Bird richness', birdDelta, '%', 2)
        : 'Bird richness baseline year selected (no previous-year comparison)';
    document.getElementById('histRecentBirdSub').textContent = hasPrev ? (currentYear + ' vs ' + previousYear + ' (annual)') : (currentYear + ' baseline');

    document.getElementById('histRecentViirs').textContent = hasPrev
        ? formatChangeStatement('Avg VIIRS', viirsDelta, ' nW', 1)
        : 'Avg VIIRS baseline year selected (no previous-year comparison)';
    document.getElementById('histRecentViirsSub').textContent = hasPrev ? (currentYear + ' vs ' + previousYear + ' (annual)') : (currentYear + ' baseline');

    document.getElementById('histRecentMonitor').textContent = hasPrev
        ? ('Monitoring period: ' + previousYear + '–' + currentYear + ' comparison active')
        : ('Monitoring period: ' + currentYear + ' baseline year active');
    document.getElementById('histRecentMonitorSub').textContent = 'Annual summary';
}

function loadHistoricalData() {
    resetHistoricalClickSequence();
    resetHistoricalSiteDetailPanel();
    var selections = getHistoricalSelections();
    var year = selections.year;
    var month = selections.month;
    document.getElementById('histLoadingMsg').style.display = 'block';
    updateEnvironmentalLegend(selections);

    var url = 'api/get_historical_data.php?year=' + year + (month > 0 ? '&month=' + month : '');
    fetch(url)
        .then(function(r) { return r.json(); })
        .then(function(resp) {
            document.getElementById('histLoadingMsg').style.display = 'none';
            if (!resp.success) {
                latestHistoricalRows = [];
                lastHistoricalObservationKey = '';
                clearHistoricalLayers();
                renderObservationSidebar([], selections);
                renderEnvironmentalSidebar([], selections);
                renderHistoricalRecentUpdates(selections);
                prepareHistoricalDeferredPanels();
                return;
            }

            var observationKey = getHistoricalObservationKey(selections);
            var preserveObservation = selections.showObservation &&
                historicalObservationLayers.length > 0 &&
                lastHistoricalObservationKey === observationKey;

            latestHistoricalRows = resp.data || [];
            renderHistoricalMap(latestHistoricalRows, selections, {
                preserveObservation: preserveObservation
            });
            renderObservationSidebar(latestHistoricalRows, selections);
            renderEnvironmentalSidebar(latestHistoricalRows, selections);
            renderHistoricalRecentUpdates(selections);
            prepareHistoricalDeferredPanels();
            runHistoricalAutoSequence();

            lastHistoricalObservationKey = selections.showObservation ? observationKey : '';
        })
        .catch(function() {
            document.getElementById('histLoadingMsg').style.display = 'none';
            latestHistoricalRows = [];
            lastHistoricalObservationKey = '';
            clearHistoricalLayers();
        });
}

function onEnvDataTypeChange() {
    var envType = document.getElementById('envDataSelect').value;
    document.getElementById('landCoverChecklist').style.display = envType === 'land_cover' ? 'inline-flex' : 'none';
    document.getElementById('landTempPeriod').style.display = envType === 'land_temp' ? 'inline-flex' : 'none';
    envRowsExpanded = false;
    loadHistoricalData();
}

function setMapView(view) {
    riskAnimationToken += 1;
    clearRiskAnimationTimers();

    currentMapView = view;
    var isHist = (view === 'historical');

    // Toggle button styles
    document.getElementById('btnRiskZones').className  = isHist ? 'btn btn-secondary btn-sm' : 'btn btn-primary btn-sm';
    document.getElementById('btnHistorical').className = isHist ? 'btn btn-primary btn-sm'   : 'btn btn-secondary btn-sm';

    // Show/hide filter controls
    var filters = document.getElementById('historicalFilters');
    filters.style.display = isHist ? 'flex' : 'none';
    document.getElementById('historicalOverlayControls').style.display = isHist ? 'flex' : 'none';

    document.getElementById('riskSidebarPanels').style.display = isHist ? 'none' : 'block';
    document.getElementById('historicalSidebarPanels').style.display = isHist ? 'block' : 'none';

    // Show/hide legends
    document.getElementById('legendRiskZones').style.display  = isHist ? 'none'  : 'block';
    document.getElementById('legendHistorical').style.display = isHist ? 'block' : 'none';
    if (!isHist) {
        updateEnvironmentalLegend({ envType: '' });
    }

    // Swap tile layer (dark ↔ light)
    if (isHist) {
        map.removeLayer(darkTile);
        lightTile.addTo(map);
    } else {
        map.removeLayer(lightTile);
        darkTile.addTo(map);
    }

    // Show/hide Metro Manila polygons and risk circles
    if (isHist) {
        clearRiskZonesFromMap();
        addMetroManilaLayerIfNeeded();
        updateMetroLayerStyle('historical');
    } else {
        addMetroManilaLayerIfNeeded();
        updateMetroLayerStyle('risk');
        clearRiskZonesFromMap();
    }

    if (isHist) {
        map.setView([14.5748, 121.0], 12);
        resetHistoricalSiteDetailPanel();
        prepareHistoricalDeferredPanels();
        loadHistoricalData();
    } else {
        resetHistoricalSiteDetailPanel();
        clearHistoricalTypingTimers();
        clearHistoricalAutoSequenceTimers();
        clearHistoricalLayers();
        applyYearDrivenUpdates(getSelectedDashboardYear());
        playRiskViewAnimation();
        prepareRiskSidebarAutoReveal();
    }
}

var histEnvToggleEl = document.getElementById('histEnvToggle');
if (histEnvToggleEl) {
    histEnvToggleEl.addEventListener('click', function() {
        envRowsExpanded = !envRowsExpanded;
        renderEnvironmentalRows();
    });
}

var histSiteDetailCloseEl = document.getElementById('histSiteDetailClose');
if (histSiteDetailCloseEl) {
    histSiteDetailCloseEl.addEventListener('click', function() {
        resetHistoricalSiteDetailPanel();
        renderEnvironmentalSidebar(latestHistoricalRows || [], getHistoricalSelections());
    });
}

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

function getSelectedDashboardYear() {
    var slider = document.getElementById('yearSlider');
    var year = slider ? parseInt(slider.value) : DASHBOARD_MAX_YEAR;
    if (!year || year < DASHBOARD_MIN_YEAR || year > DASHBOARD_MAX_YEAR) {
        year = DASHBOARD_MAX_YEAR;
    }
    return year;
}

function getBirdYearStats(year) {
    var values = birdRichnessData[year] || [];
    if (!values.length) {
        return { avg: 0, peak: 0, peakIndex: 0 };
    }

    var sum = 0;
    var peak = values[0];
    var peakIndex = 0;
    values.forEach(function(value, index) {
        sum += value;
        if (value > peak) {
            peak = value;
            peakIndex = index;
        }
    });

    return {
        avg: sum / values.length,
        peak: peak,
        peakIndex: peakIndex
    };
}

function getPctDelta(current, previous) {
    if (previous === 0) return 0;
    return ((current - previous) / previous) * 100;
}

var birdChartSequenceTimers = [];

function clearBirdChartSequenceTimers() {
    while (birdChartSequenceTimers.length) {
        clearTimeout(birdChartSequenceTimers.pop());
    }
}

function animateBirdChartDotsThenLine(yearData, peakMarkers) {
    clearBirdChartSequenceTimers();

    var dotsOnly = new Array(yearData.length).fill(null);
    birdChart.data.datasets[0].showLine = false;
    birdChart.data.datasets[0].data = dotsOnly.slice();
    birdChart.data.datasets[1].data = new Array(yearData.length).fill(null);
    birdChart.update('none');

    var stepMs = 110;
    yearData.forEach(function(value, index) {
        var t = setTimeout(function() {
            dotsOnly[index] = value;
            birdChart.data.datasets[0].data = dotsOnly.slice();
            birdChart.update('none');
        }, index * stepMs);
        birdChartSequenceTimers.push(t);
    });

    var finalT = setTimeout(function() {
        birdChart.data.datasets[0].showLine = true;
        birdChart.data.datasets[0].data = yearData.slice();
        birdChart.data.datasets[1].data = peakMarkers;
        birdChart.update();
    }, (yearData.length * stepMs) + 80);
    birdChartSequenceTimers.push(finalT);
}

function applyAvpStaggerReveal(selector, initialDelayMs, stepMs) {
    var nodes = document.querySelectorAll(selector);
    var baseDelay = initialDelayMs || 90;
    var step = stepMs || 60;

    nodes.forEach(function(node, index) {
        node.style.opacity = '0';
        node.style.transform = 'translateY(12px)';
        node.style.animation = 'avpPageEnter 0.52s cubic-bezier(0.22, 1, 0.36, 1) forwards';
        node.style.animationDelay = (baseDelay + (index * step)) + 'ms';
    });
}

var riskSidebarRevealTimers = [];

function clearRiskSidebarRevealTimers() {
    while (riskSidebarRevealTimers.length) {
        clearTimeout(riskSidebarRevealTimers.pop());
    }
}

function setRiskSectionHidden(el) {
    if (!el) return;
    el.style.opacity = '0';
    el.style.transform = 'translateY(10px)';
    el.style.pointerEvents = 'none';
    el.style.animation = 'none';
}

function revealRiskSection(el) {
    if (!el) return;
    el.style.pointerEvents = 'auto';
    el.style.animation = 'avpPageEnter 0.42s cubic-bezier(0.22, 1, 0.36, 1) forwards';
    el.style.animationDelay = '0ms';

    if (el.id === 'riskBirdTrendCard') {
        var selectedYear = getSelectedDashboardYear();
        var replayTimer = setTimeout(function() {
            birdChart.resize();
            updateChartForYear(selectedYear);
        }, 80);
        birdChartSequenceTimers.push(replayTimer);
    }
}

function prepareRiskSidebarAutoReveal() {
    var container = document.getElementById('riskSidebarPanels');
    if (!container) return;

    clearRiskSidebarRevealTimers();

    container.style.opacity = '1';
    container.style.transform = 'none';
    container.style.animation = 'none';

    var sequence = [
        document.getElementById('riskAtRiskCard'),
        document.getElementById('riskLightIntensityCard'),
        document.getElementById('riskBirdTrendCard'),
        document.getElementById('riskRecentUpdatesBlock')
    ];

    sequence.forEach(function(el) { setRiskSectionHidden(el); });

    sequence.forEach(function(el, index) {
        var timer = setTimeout(function() {
            if (currentMapView !== 'risk') return;
            revealRiskSection(el);
        }, 120 + (index * 220));
        riskSidebarRevealTimers.push(timer);
    });
}

function updateTrendBadge(elementId, value, suffix, decimals, neutralClassName) {
    var element = document.getElementById(elementId);
    if (!element) return;

    var rounded = Math.abs(value).toFixed(decimals);
    if (value > 0) {
        element.className = 'dash-stat-trend up';
        element.textContent = '↗ +' + rounded + suffix;
        return;
    }
    if (value < 0) {
        element.className = 'dash-stat-trend down';
        element.textContent = '↘ -' + rounded + suffix;
        return;
    }

    element.className = neutralClassName || 'dash-stat-trend';
    element.textContent = '↔ 0' + suffix;
}

function formatChangeStatement(metricName, deltaValue, unitSuffix, decimals) {
    if (deltaValue > 0) {
        return metricName + ' increase by ' + Math.abs(deltaValue).toFixed(decimals) + unitSuffix + ' vs previous year';
    }
    if (deltaValue < 0) {
        return metricName + ' decrease by ' + Math.abs(deltaValue).toFixed(decimals) + unitSuffix + ' vs previous year';
    }
    return metricName + ' no change (0' + unitSuffix + ') vs previous year';
}

function updateBirdTrendMeta(currentYear, currentStats, previousStats) {
    var peakTextEl = document.getElementById('birdTrendPeak');
    var peakDeltaTextEl = document.getElementById('birdTrendPeakDelta');

    if (peakTextEl) {
        peakTextEl.textContent = 'Peak value: ' + currentStats.peak + ' (' + months[currentStats.peakIndex] + ' ' + currentYear + ')';
    }

    if (!peakDeltaTextEl) return;

    if (!previousStats) {
        peakDeltaTextEl.textContent = 'Peak change vs previous year: baseline year (no comparison)';
        return;
    }

    var peakPctDelta = getPctDelta(currentStats.peak, previousStats.peak);
    var phrase = peakPctDelta >= 0 ? 'increase' : 'decrease';
    peakDeltaTextEl.textContent = 'Peak ' + phrase + ' by ' + Math.abs(peakPctDelta).toFixed(1) + '% vs ' + (currentYear - 1);
}

function updateRecentUpdates(currentYear, birdPctDelta, viirsDelta) {
    var prevYear = currentYear - 1;
    var hasPrev = birdRichnessData.hasOwnProperty(prevYear);
    var periodLabel = hasPrev ? (currentYear + ' vs ' + prevYear) : (currentYear + ' baseline year');

    var birdChangeEl = document.getElementById('recentBirdChange');
    var birdPeriodEl = document.getElementById('recentBirdPeriod');
    var viirsChangeEl = document.getElementById('recentViirsChange');
    var viirsPeriodEl = document.getElementById('recentViirsPeriod');
    var monitorStatusEl = document.getElementById('recentMonitoringStatus');
    var monitorPeriodEl = document.getElementById('recentMonitoringPeriod');

    if (birdChangeEl) {
        birdChangeEl.textContent = hasPrev
            ? formatChangeStatement('Bird richness', birdPctDelta, '%', 1)
            : 'Bird richness baseline year selected (no previous-year comparison)';
    }
    if (birdPeriodEl) birdPeriodEl.textContent = periodLabel;

    if (viirsChangeEl) {
        viirsChangeEl.textContent = hasPrev
            ? formatChangeStatement('Avg VIIRS', viirsDelta, ' nW', 1)
            : 'Avg VIIRS baseline year selected (no previous-year comparison)';
    }
    if (viirsPeriodEl) viirsPeriodEl.textContent = periodLabel;

    if (monitorStatusEl) {
        monitorStatusEl.textContent = hasPrev
            ? ('Monitoring period: ' + currentYear + ' vs ' + prevYear + ' comparison active')
            : ('Monitoring period: ' + currentYear + ' baseline year active');
    }
    if (monitorPeriodEl) monitorPeriodEl.textContent = periodLabel;
}

function updateYearDrivenUpdatesOnly(currentYear) {
    var previousYear = currentYear - 1;
    var currentRiskSummary = summarizeRiskYear(currentYear);
    var previousRiskSummary = (previousYear >= DASHBOARD_MIN_YEAR) ? summarizeRiskYear(previousYear) : null;

    document.getElementById('atRiskZonesValue').textContent = currentRiskSummary.atRiskZones;
    document.getElementById('lightIntensityValue').textContent = currentRiskSummary.avgViirs.toFixed(1) + ' nW';

    if (previousRiskSummary) {
        var zonesPctDelta = getPctDelta(currentRiskSummary.atRiskZones, previousRiskSummary.atRiskZones);
        var viirsDelta = currentRiskSummary.avgViirs - previousRiskSummary.avgViirs;
        updateTrendBadge('atRiskZonesTrend', zonesPctDelta, '%', 1);
        updateTrendBadge('lightIntensityTrend', viirsDelta, ' nW', 1);

        var currentBirdStats = getBirdYearStats(currentYear);
        var previousBirdStats = getBirdYearStats(previousYear);
        var birdPctDelta = getPctDelta(currentBirdStats.avg, previousBirdStats.avg);
        updateRecentUpdates(currentYear, birdPctDelta, viirsDelta);
    } else {
        updateTrendBadge('atRiskZonesTrend', 0, '%', 1);
        updateTrendBadge('lightIntensityTrend', 0, ' nW', 1);
        updateRecentUpdates(currentYear, 0, 0);
    }
}

function updateChartForYear(year) {
    var yearData = birdRichnessData[year] || birdRichnessData[DASHBOARD_MIN_YEAR];
    var currentStats = getBirdYearStats(year);
    var previousStats = (year > DASHBOARD_MIN_YEAR) ? getBirdYearStats(year - 1) : null;

    var peakMarkers = new Array(yearData.length).fill(null);
    peakMarkers[currentStats.peakIndex] = currentStats.peak;

    animateBirdChartDotsThenLine(yearData, peakMarkers);

    updateBirdTrendMeta(year, currentStats, previousStats);
}

function applyYearDrivenUpdates(year) {
    yearDisplay.textContent = year;
    updateChartForYear(year);
    updateYearDrivenUpdatesOnly(year);

    if (currentMapView === 'risk') {
        applyRiskZonesForYear(year);
        riskZoneLayers.forEach(function(layer) {
            if (map.hasLayer(layer)) {
                layer.bringToFront();
            }
        });
    }
}

// Bird Richness Trend Chart
var ctx = document.getElementById('birdRichnessChart').getContext('2d');
var birdChart = new Chart(ctx, {
    type: 'line',
    data: {
        labels: months,
        datasets: [
            {
                label: 'Bird Richness',
                data: birdRichnessData[DASHBOARD_MIN_YEAR],
                borderColor: '#3b82f6',
                backgroundColor: 'rgba(59, 130, 246, 0.1)',
                fill: true,
                tension: 0.4,
                pointBackgroundColor: '#3b82f6',
                pointBorderColor: '#3b82f6',
                pointRadius: 4,
                borderWidth: 2
            },
            {
                label: 'Peak',
                data: new Array(months.length).fill(null),
                showLine: false,
                pointRadius: 6,
                pointHoverRadius: 7,
                pointBackgroundColor: '#f59e0b',
                pointBorderColor: '#f59e0b'
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        animation: {
            duration: 620,
            easing: 'easeOutQuart'
        },
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
    applyYearDrivenUpdates(yr);
});

document.getElementById('histYearSelect').value = String(DASHBOARD_MIN_YEAR);
document.getElementById('histMonthSelect').value = '0';

applyYearDrivenUpdates(getSelectedDashboardYear());
playRiskViewAnimation();
prepareRiskSidebarAutoReveal();
applyAvpStaggerReveal('#dashMapControls, #legendRiskZones, #legendHistorical', 80, 70);
</script>
SCRIPTS;

require_once 'includes/footer.php';
?>
