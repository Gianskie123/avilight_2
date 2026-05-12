<?php
$page_title = 'Dashboard';
require_once 'includes/header.php';

require_once 'includes/db.php';

function loadDashboardThresholdConfig(): array {
    $defaults = [
        'high_risk' => 60.0,
        'mod_risk'  => 40.0,
        'low_risk'  => 25.0,
    ];

    // Primary: MySQL system_settings — persistent on all hosts including Railway.
    try {
        $db  = get_mysql_db();
        $row = $db->query("SELECT setting_value FROM system_settings WHERE setting_key = 'thresholds' LIMIT 1")
                  ->fetch(PDO::FETCH_ASSOC);
        if ($row && isset($row['setting_value'])) {
            $decoded = json_decode((string) $row['setting_value'], true);
            if (is_array($decoded)) {
                foreach ($defaults as $key => $value) {
                    if (array_key_exists($key, $decoded) && is_numeric($decoded[$key])) {
                        $defaults[$key] = (float) $decoded[$key];
                    }
                }
                return $defaults;
            }
        }
    } catch (Throwable $_) {}

    // Fallback: JSON cache file (local dev convenience).
    $path = __DIR__ . '/data/cache/thresholds.json';
    if (is_readable($path)) {
        $decoded = json_decode((string) file_get_contents($path), true);
        if (is_array($decoded)) {
            foreach ($defaults as $key => $value) {
                if (array_key_exists($key, $decoded) && is_numeric($decoded[$key])) {
                    $defaults[$key] = (float) $decoded[$key];
                }
            }
        }
    }

    return $defaults;
}

$dashboard_thresholds = loadDashboardThresholdConfig();
$kba_data = [];
$historical_env_yearly = [];
$risk_site_yearly = [];
$risk_snapshot_year = 2025;
$risk_city_map = [
    'Las Piñas-Parañaque Wetland Park' => 'Las Piñas',
    'Ninoy Aquino Parks and Wildlife Center' => 'Quezon City',
    'Manila Bay' => 'Manila',
    'Manila Bay Beach Resort' => 'Parañaque',
    'Luneta National Park' => 'Manila',
];
$risk_land_cover_map = [
    'Las Piñas-Parañaque Wetland Park' => 11,
    'Ninoy Aquino Parks and Wildlife Center' => 1,
    'Manila Bay' => 17,
    'Manila Bay Beach Resort' => 13,
    'Luneta National Park' => 1,
];
$kba_coords = [
    'Las Piñas-Parañaque Wetland Park'    => ['lat' => 14.4500, 'lng' => 120.9833],
    'Ninoy Aquino Parks and Wildlife Center' => ['lat' => 14.6537, 'lng' => 121.0499],
    'Manila Bay'                          => ['lat' => 14.5700, 'lng' => 120.9800],
    'Manila Bay Beach Resort'             => ['lat' => 14.5200, 'lng' => 120.9700],
    'Luneta National Park'                => ['lat' => 14.5826, 'lng' => 120.9790],
];

$risk_city_map_json = json_encode($risk_city_map, JSON_UNESCAPED_UNICODE);
try {
    $mysql = get_mysql_db();
    // grid_cells_json excluded — coordinate data now lives in kba_pa_locations.
    $stmt = $mysql->query("SELECT area_name, area_type, light_exposure, status, snapshot_year, snapshot_month FROM kba_pa_audit_live ORDER BY area_name ASC");
    $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

    foreach ($rows as $row) {
        $name = (string) ($row['area_name'] ?? '');
        if ($name === '' || !isset($kba_coords[$name])) {
            continue;
        }
        $coords = $kba_coords[$name];
        $kba_data[] = [
            'name'           => $name,
            'type'           => (string) ($row['area_type'] ?? ''),
            'latitude'       => (float) $coords['lat'],
            'longitude'      => (float) $coords['lng'],
            'light_exposure' => isset($row['light_exposure']) ? (float) $row['light_exposure'] : 0.0,
            'status'         => (string) ($row['status'] ?? ''),
            'snapshot_year'  => isset($row['snapshot_year']) ? (int) $row['snapshot_year'] : null,
            'snapshot_month' => isset($row['snapshot_month']) ? (int) $row['snapshot_month'] : null,
            'land_cover'     => (int) ($risk_land_cover_map[$name] ?? 11),
        ];

        if (isset($row['snapshot_year']) && is_numeric($row['snapshot_year'])) {
            $risk_snapshot_year = max($risk_snapshot_year, (int) $row['snapshot_year']);
        }
    }

    // ecological_yearly_summary — Metro Manila aggregate for the information cards.
    $histStmt = $mysql->query("SELECT area, year, viirs_avg, ndvi_avg, lst_avg, precipitation_total
        FROM ecological_yearly_summary
        WHERE year BETWEEN 2014 AND 2025
          AND area IS NOT NULL
        ORDER BY year ASC, area ASC");
    $histRows = $histStmt ? ($histStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    foreach ($histRows as $histRow) {
        $year = (int) ($histRow['year'] ?? 0);
        $area = trim((string) ($histRow['area'] ?? ''));
        if ($year < 2014 || $year > 2025 || $area === '') {
            continue;
        }
        if (!isset($historical_env_yearly[$year])) {
            $historical_env_yearly[$year] = [];
        }
        $historical_env_yearly[$year][$area] = [
            'viirs' => isset($histRow['viirs_avg']) ? (float) $histRow['viirs_avg'] : null,
            'ndvi' => isset($histRow['ndvi_avg']) ? (float) $histRow['ndvi_avg'] : null,
            'lst' => isset($histRow['lst_avg']) ? (float) $histRow['lst_avg'] : null,
            'precipitation' => isset($histRow['precipitation_total']) ? (float) $histRow['precipitation_total'] : null,
        ];
    }

    // Cross-reference accented/unaccented city name variants so the JS computeZoneLight
    // fallback finds entries regardless of how the DB stored city names.
    $accent_map = ['ñ'=>'n','Ñ'=>'n','á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','Á'=>'a','É'=>'e','Í'=>'i','Ó'=>'o','Ú'=>'u'];
    $city_canonical = [];
    foreach ($risk_city_map as $city) {
        $city_canonical[strtolower(strtr($city, $accent_map))] = $city;
    }
    foreach ($historical_env_yearly as $yr => $areas) {
        foreach ($areas as $areaKey => $data) {
            $normKey = strtolower(strtr($areaKey, $accent_map));
            if (isset($city_canonical[$normKey]) && $city_canonical[$normKey] !== $areaKey) {
                $canonical = $city_canonical[$normKey];
                if (!isset($historical_env_yearly[$yr][$canonical])) {
                    $historical_env_yearly[$yr][$canonical] = $data;
                }
            }
        }
    }
} catch (Throwable $e) {
    $kba_data = json_decode((string) file_get_contents('data/sample_kba.json'), true) ?: [];
}
?>

<div class="alert alert-info" role="status">
    📅 <strong>Dataset Period: 2014 – 2025</strong> | <strong>Monitoring Status: 2014 – 2025</strong> —
    All metrics, readings, and site analyses are loaded from the database for the selected period.
</div>

<div class="dashboard-layout">
    <!-- Left column: Map -->
    <div class="dashboard-map-col">
        <div style="position: relative; display: flex; flex-direction: column; height: 100%; overflow: hidden;">
            <style>
                .risk-site-panel {
                    display: flex;
                    flex-wrap: wrap;
                    align-items: flex-start;
                    gap: 8px 10px;
                    padding: 8px 12px;
                    border-bottom: 1px solid var(--border-color);
                    background: var(--bg-card-alt);
                    color: var(--text-primary);
                }
                .risk-site-panel h4 {
                    margin: 0;
                    font-size: 0.74rem;
                    letter-spacing: 0.03em;
                    text-transform: uppercase;
                    color: var(--text-muted);
                }
                .risk-site-panel .risk-site-summary {
                    font-size: 0.7rem;
                    color: var(--text-secondary);
                    margin-right: 0;
                    line-height: 1.35;
                }
                .risk-threshold-note {
                    font-size: 0.68rem;
                    color: var(--text-muted);
                    line-height: 1.35;
                    margin-right: 0;
                }
                .risk-site-list {
                    display: flex;
                    flex-wrap: wrap;
                    gap: 8px;
                    align-items: center;
                    min-width: 0;
                    flex: 1 1 320px;
                }
                .risk-site-item {
                    display: inline-flex;
                    align-items: center;
                    gap: 6px;
                    border: 1px solid var(--border-color);
                    border-radius: 999px;
                    padding: 5px 10px;
                    min-height: 28px;
                    background: var(--bg-card);
                    color: var(--text-secondary);
                    cursor: pointer;
                    text-align: left;
                    white-space: nowrap;
                    box-shadow: none;
                    transition: background 0.2s ease, border-color 0.2s ease, opacity 0.2s ease;
                }
                .risk-site-item:hover,
                .risk-site-item.is-selected {
                    border-color: var(--accent-blue);
                    background: var(--bg-card-alt);
                }
                .risk-site-dot {
                    width: 8px;
                    height: 8px;
                    border-radius: 999px;
                    flex: 0 0 9px;
                    border: 2px solid rgba(255, 255, 255, 0.7);
                }
                .risk-site-item-name {
                    font-size: 0.75rem;
                    font-weight: 600;
                    line-height: 1.2;
                }
                .risk-site-item-actions {
                    display: inline-flex;
                    align-items: center;
                    gap: 5px;
                }
                .risk-site-toggle {
                    width: 14px;
                    height: 14px;
                    margin: 0;
                    accent-color: #60a5fa;
                }
                .risk-site-focus {
                    border: 1px solid var(--border-color);
                    background: var(--bg-card-alt);
                    color: var(--text-secondary);
                    border-radius: 999px;
                    width: 20px;
                    height: 20px;
                    line-height: 20px;
                    padding: 0;
                    cursor: pointer;
                }
                .risk-site-focus:hover {
                    color: var(--text-primary);
                    border-color: var(--accent-blue);
                }
                .risk-site-item.is-hidden {
                    opacity: 0.55;
                }
                .risk-site-item.is-hidden .risk-site-item-name {
                    text-decoration: line-through;
                }
                @media (max-width: 768px) {
                    .risk-site-panel {
                        padding: 8px 10px;
                        gap: 7px 8px;
                    }
                    .risk-site-list {
                        flex-basis: 100%;
                        gap: 6px;
                    }
                    .risk-site-item {
                        padding: 4px 8px;
                        min-height: 26px;
                    }
                }
            </style>
            <!-- Map filter control bar -->
            <div id="dashMapControls" class="dashboard-map-controls">
                <div class="map-control-row">
                    <div class="map-control-title">Map View</div>
                    <div class="map-control-actions">
                        <button class="btn btn-primary btn-sm" id="btnRiskZones" onclick="setMapView('risk')">⚠️ Risk Zones</button>
                        <button class="btn btn-secondary btn-sm" id="btnHistorical" onclick="setMapView('historical')">📊 Historical Data</button>
                    </div>
                    <div class="map-control-context">
                        <span id="riskViewHint" class="map-control-hint">Risk zones highlight VIIRS night-light disturbance.</span>
                        <span id="histViewHint" class="map-control-hint is-hidden">Historical view shows species richness and overlays.</span>
                    </div>
                </div>

                <!-- Historical data filters (hidden by default) -->
                <div id="historicalFilters" class="map-control-row" style="display:none;">
                    <span class="map-control-label">Time:</span>
                    <div class="map-control-divider"></div>
                    <label class="map-control-label">Year:</label>
                    <select id="histYearSelect" class="btn btn-secondary btn-sm map-control-input" onchange="loadHistoricalData()">
                        <?php for ($y = 2014; $y <= 2025; $y++): ?>
                        <option value="<?= $y ?>"><?= $y ?></option>
                        <?php endfor; ?>
                    </select>
                    <div class="map-control-divider"></div>
                    <label class="map-control-label">Month:</label>
                    <select id="histMonthSelect" class="btn btn-secondary btn-sm map-control-input" onchange="loadHistoricalData()">
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

                <!-- ── Historical overlay controls: two sub-rows ── -->
                <div id="historicalOverlayControls" class="map-control-section">

                    <!-- Sub-row A: Observation toggle · Map Overlay · Filters toggle -->
                    <div class="map-control-row">
                        <label class="map-control-toggle">
                            <input type="checkbox" id="obsToggle" checked onchange="loadHistoricalData()">
                            Observations
                        </label>
                        <div class="map-control-divider"></div>
                        <span class="map-control-label">Overlay:</span>
                        <select id="envDataSelect" class="btn btn-secondary btn-sm map-control-input" onchange="onEnvDataTypeChange()">
                            <option value="">Environmental Data (None)</option>
                            <option value="land_cover">Land Cover</option>
                            <option value="ndvi">NDVI</option>
                            <option value="viirs">VIIRS</option>
                            <option value="precip">Precip</option>
                            <option value="land_temp">Land Temp</option>
                        </select>
                        <select id="landTempPeriod" class="btn btn-secondary btn-sm map-control-input" style="display:none;" onchange="loadHistoricalData()">
                            <option value="day">Land Temp: Day</option>
                            <option value="night">Land Temp: Night</option>
                        </select>
                        <div class="map-control-spacer"></div>
                        <button type="button" id="histFiltersToggle" class="map-control-button" onclick="toggleHistFilters()">
                            &#9881; Filters &#9660;
                        </button>
                    </div>

                    <!-- Land-cover checklist (shown when overlay = land_cover) -->
                    <div id="landCoverChecklist" class="map-control-checklist">
                        <span class="map-control-checklist-label">Land Cover:</span>
                        <label><input type="checkbox" class="land-cover-toggle" value="Urban &amp; Built-up" checked onchange="loadHistoricalData()">Urban &amp; Built-up</label>
                        <label><input type="checkbox" class="land-cover-toggle" value="Water Bodies" checked onchange="loadHistoricalData()">Water Bodies</label>
                        <label><input type="checkbox" class="land-cover-toggle" value="Forest" checked onchange="loadHistoricalData()">Forest</label>
                        <label><input type="checkbox" class="land-cover-toggle" value="Croplands" checked onchange="loadHistoricalData()">Croplands</label>
                        <label><input type="checkbox" class="land-cover-toggle" value="Grasslands" checked onchange="loadHistoricalData()">Grasslands</label>
                        <label><input type="checkbox" class="land-cover-toggle" value="Wetlands" checked onchange="loadHistoricalData()">Wetlands</label>
                        <label><input type="checkbox" class="land-cover-toggle" value="Woody Savannas" checked onchange="loadHistoricalData()">Woody Savannas</label>
                        <label><input type="checkbox" class="land-cover-toggle" value="Cropland Mosaics" checked onchange="loadHistoricalData()">Cropland Mosaics</label>
                        <label><input type="checkbox" class="land-cover-toggle" value="Barren" checked onchange="loadHistoricalData()">Barren</label>
                    </div>

                    <!-- Sub-row B: Bird / Migration / Light filters (collapsible) -->
                    <div id="histFilterRow" class="map-control-row" style="display:none; padding-top:8px; border-top:1px solid var(--border-color); margin-top:8px;">
                        <span class="map-control-label">Bird:</span>
                        <input id="birdFilterInput" class="btn btn-secondary btn-sm map-control-input" type="search" list="birdFilterOptions" placeholder="All birds" style="width:160px;" onchange="loadHistoricalData()">
                        <datalist id="birdFilterOptions"></datalist>
                        <div class="map-control-divider"></div>
                        <span class="map-control-label">Migration:</span>
                        <select id="migrationFilterSelect" class="btn btn-secondary btn-sm map-control-input" onchange="loadHistoricalData()">
                            <option value="">All</option>
                            <option value="Resident">Resident</option>
                            <option value="Migratory">Migratory</option>
                        </select>
                        <div class="map-control-divider"></div>
                        <span class="map-control-label">Light:</span>
                        <select id="lightFilterSelect" class="btn btn-secondary btn-sm map-control-input" onchange="loadHistoricalData()">
                            <option value="">All</option>
                            <option value="Tolerant">Light-tolerant</option>
                            <option value="Sensitive">Light-sensitive</option>
                        </select>
                    </div>
                </div>

            </div>

            <div class="risk-site-panel" id="riskSitePanel" aria-label="Risk zone list" style="display:flex;">
                <h4>Places</h4>
                <div class="risk-site-summary">Click a site to show/hide it, or use the target icon to focus the map.</div>
                <div class="risk-threshold-note" id="riskThresholdNote"></div>
                <div class="risk-site-list" id="riskSiteList"></div>
            </div>

            <div style="position: relative; flex: 1; overflow: hidden;">
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
                <div class="map-legend-meta">Based on VIIRS night-light radiance thresholds.</div>
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
                <div class="map-legend-meta">Scale uses the selected year, month, and filters.</div>
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
                    <div class="dash-stat-label">At Risk Zones <span id="atRiskZonesYear" style="font-size:0.72rem; font-weight:400; color:var(--text-muted);"></span></div>
                    <div>
                        <span class="dash-stat-value" id="atRiskZonesValue">0</span>
                        <span class="dash-stat-trend" id="atRiskZonesTrend">↔ 0%</span>
                    </div>
                    <div class="dash-stat-desc" id="atRiskZonesDesc">
                        Areas classified as <strong>Medium</strong> or <strong>High</strong> risk based on VIIRS night-light radiance. Shown on the map as <span class="risk-medium-indicator">yellow</span> and <span class="risk-high-indicator">red</span> circles.
                    </div>
                </div>
                <div class="dash-stat-card" id="riskLightIntensityCard">
                    <div class="dash-stat-label">Light Intensity <span id="lightIntensityYear" style="font-size:0.72rem; font-weight:400; color:var(--text-muted);"></span></div>
                    <div>
                        <span class="dash-stat-value" id="lightIntensityValue">0.0 nW</span>
                        <span class="dash-stat-trend" id="lightIntensityTrend">↔ 0.0%</span>
                    </div>
                    <div class="dash-stat-desc">
                        Metro Manila avg. VIIRS night-light radiance for the selected year. Higher intensity correlates with larger, brighter circles on the map and increased disturbance risk to bird species.
                    </div>
                </div>
            </div>

            <!-- Bird Richness Trend -->
            <div class="chart-card" id="riskBirdTrendCard">
                <div class="section-title">Bird Richness Trend</div>
                <div class="bird-richness-controls">
                    <label class="slider-label" for="yearSlider">
                        <span>Year: <strong id="yearDisplay">2014</strong></span>
                        <span style="font-size:0.75rem;color:var(--text-muted);">2014 – 2025</span>
                    </label>
                    <input type="range" id="yearSlider" class="slider" min="2014" max="2025" value="2014" step="1">
                </div>
                <canvas id="birdRichnessChart"></canvas>
                <div class="dash-stat-desc" style="margin-top:8px;" id="birdTrendMeta">
                    <div id="birdTrendPeak">Peak value: —</div>
                    <div id="birdTrendPeakDelta">Peak change vs previous year: —</div>
                </div>
            </div>

            <!-- Recent Updates -->
            <div id="riskRecentUpdatesBlock">
                <div class="section-title">Risk Zone Recent Updates</div>
                <div class="activity-feed">
                    <div class="activity-item">
                        <div class="activity-icon blue" id="riskIconBird">&#8644;</div>
                        <div class="activity-text">
                            <strong id="recentBirdChange">Bird richness change vs previous year pending</strong>
                            <span id="recentBirdPeriod">2014 vs 2013</span>
                        </div>
                    </div>
                    <div class="activity-item">
                        <div class="activity-icon blue" id="riskIconViirs">&#8644;</div>
                        <div class="activity-text">
                            <strong id="recentViirsChange">Avg VIIRS change vs previous year pending</strong>
                            <span id="recentViirsPeriod">2014 vs 2013</span>
                        </div>
                    </div>
                    <div class="activity-item">
                        <div class="activity-icon blue" id="riskIconMonitor">&#8596;</div>
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
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:2px;">
                    <div class="dash-stat-label" style="margin:0;">Recorded Species by Category</div>
                    <span id="histObsHeaderBadge" style="font-size:0.72rem; color:var(--text-muted); background:var(--bg-input); border-radius:999px; padding:2px 8px;">2025 · All</span>
                </div>
                <div id="histObsHeaderMeta" style="font-size:0.78rem; color:var(--text-secondary); margin-bottom:8px;">2025 · All · Metro Manila (0 sites)</div>
                <div style="font-size:0.72rem; color:var(--text-muted); margin-bottom:8px;">Unique species per site, summed across observed sites</div>
                <div id="histObsBars" style="display:flex; flex-direction:column; gap:6px;"></div>
                <div id="histObsTotal" style="margin-top:8px; font-size:0.82rem; color:var(--text-secondary);">Total: 0 spp.</div>
            </div>

            <div class="dash-stat-card" id="histEnvCard" style="display:none; margin-bottom:12px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                    <div class="dash-stat-label" id="histEnvTitle" style="margin:0;">Environmental Data</div>
                    <span id="histEnvBadge" style="font-size:0.72rem; color:var(--text-muted); background:var(--bg-input); border-radius:999px; padding:2px 8px;">2025 · All</span>
                </div>
                <div id="histEnvAvg" style="font-size:0.78rem; color:var(--text-secondary); margin-bottom:8px; display:none;"></div>
                <div id="histEnvRows" style="display:flex; flex-direction:column; gap:4px;"></div>
                <div id="histEnvToggle" style="margin-top:10px; font-size:0.76rem; color:var(--text-muted); cursor:pointer; user-select:none;">See all cities</div>
            </div>

            <div class="dash-stat-card" style="margin-bottom:0;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                    <div class="dash-stat-label" id="histRecentSectionTitle" style="margin:0;">Historical Data Recent Updates</div>
                    <span id="histRecentBadge" style="font-size:0.72rem; color:var(--text-muted); background:var(--bg-input); border-radius:999px; padding:2px 8px;">2025 vs 2024</span>
                </div>
                <div class="activity-feed" style="margin:0;">
                    <div class="activity-item">
                        <div class="activity-icon blue" id="histIconBird">&#8644;</div>
                        <div class="activity-text">
                            <strong id="histRecentBird">Bird richness increase by 0.0% vs 2024</strong>
                            <span id="histRecentBirdSub">2025 vs 2024 (annual)</span>
                        </div>
                    </div>
                    <div class="activity-item">
                        <div class="activity-icon blue" id="histIconViirs">&#8644;</div>
                        <div class="activity-text">
                            <strong id="histRecentViirs">Avg VIIRS increase by 0.0 nW vs 2024</strong>
                            <span id="histRecentViirsSub">2025 vs 2024 (annual)</span>
                        </div>
                    </div>
                    <div class="activity-item">
                        <div class="activity-icon blue" id="histIconMonitor">&#8596;</div>
                        <div class="activity-text">
                            <strong id="histRecentMonitor">Monitoring period: 2024–2025 comparison active</strong>
                            <span id="histRecentMonitorSub">Annual summary</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
// Build risk zones JSON from Metro Manila KBA/PA data
$risk_zones = [];
$highRiskThreshold = (float) ($dashboard_thresholds['high_risk'] ?? 60.0);
$modRiskThreshold = (float) ($dashboard_thresholds['mod_risk'] ?? 40.0);
if ($kba_data) {
    foreach ($kba_data as $area) {
        $light = isset($area['light_exposure']) ? $area['light_exposure'] : 30;
        if ($light >= $highRiskThreshold) {
            $risk = 'high';
        } elseif ($light >= $modRiskThreshold) {
            $risk = 'medium';
        } else {
            $risk = 'low';
        }
        $risk_zones[] = [
            'lat'  => $area['latitude'],
            'lng'  => $area['longitude'],
            'name' => $area['name'],
            'risk' => $risk,
            'light_exposure' => (float) $light,
            'snapshot_year' => isset($area['snapshot_year']) ? (int) $area['snapshot_year'] : $risk_snapshot_year,
            'snapshot_month' => isset($area['snapshot_month']) ? (int) $area['snapshot_month'] : null,
            'land_cover' => (int) ($risk_land_cover_map[$area['name']] ?? 13),
        ];
    }
}
// Risk zones are scoped to Metro Manila KBA/PA sites only
$risk_zones_json = json_encode($risk_zones);
$dashboard_thresholds_json = json_encode($dashboard_thresholds, JSON_UNESCAPED_UNICODE);
$historical_env_yearly_json = json_encode($historical_env_yearly, JSON_UNESCAPED_UNICODE);
$risk_site_yearly_json = json_encode($risk_site_yearly, JSON_UNESCAPED_UNICODE);
$risk_snapshot_year_json = json_encode($risk_snapshot_year);

$extra_scripts = <<<SCRIPTS
<script>
// Risk zone data
var riskZones = {$risk_zones_json};
var dashboardThresholds = {$dashboard_thresholds_json};
var historicalEnvYearly = {$historical_env_yearly_json};
var riskSiteYearly = {$risk_site_yearly_json};
var riskCityMap = {$risk_city_map_json};
var DASHBOARD_MIN_YEAR = 2014;
var DASHBOARD_MAX_YEAR = 2025;
var riskSnapshotYear = {$risk_snapshot_year_json};
var selectedRiskYear = riskSnapshotYear;

// ── Tile layers (dark for Risk Zones, light for Historical Data) ───────────
var darkTile = L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OSM</a> &copy; <a href="https://carto.com/">CARTO</a>',
    maxZoom: 19
});
var lightTile = L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', {
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OSM</a> &copy; <a href="https://carto.com/">CARTO</a>',
    maxZoom: 19
});

// Initialize map constrained to Metro Manila
var MM_BOUNDS = L.latLngBounds(L.latLng(14.35, 120.90), L.latLng(14.82, 121.22));
var map = L.map('map', {
    maxBounds:          MM_BOUNDS.pad(0.08),
    maxBoundsViscosity: 0.9,
    minZoom:            10
});
map.fitBounds(MM_BOUNDS, { padding: [8, 8] });
darkTile.addTo(map);

// Snap map back to Metro Manila if center drifts outside bounds
map.on('moveend', function () {
    if (!MM_BOUNDS.contains(map.getCenter())) {
        map.flyToBounds(MM_BOUNDS, { padding: [20, 20], duration: 0.6 });
    }
});

// Keep map properly sized when the container changes dimensions
window.addEventListener('resize', function () {
    map.invalidateSize({ animate: false });
});
(function () {
    var mapEl = document.getElementById('map');
    if (mapEl && typeof ResizeObserver !== 'undefined') {
        new ResizeObserver(function () {
            map.invalidateSize({ animate: false });
        }).observe(mapEl);
    }
}());

// Risk zone colors
var riskColors = {
    low:    {color: '#22c55e', fillColor: '#22c55e', fillOpacity: 0.25, weight: 1.5},
    medium: {color: '#eab308', fillColor: '#eab308', fillOpacity: 0.25, weight: 1.5},
    high:   {color: '#ef4444', fillColor: '#ef4444', fillOpacity: 0.25, weight: 1.5}
};

var riskZoneVisibility = [];
var riskSiteListEl = null;
var selectedRiskZoneIndex = 0;

function getRiskZoneStyle(risk) {
    return riskColors[risk] || riskColors.low;
}

function getRiskZoneMeta(zoneData, year) {
    var label = zoneData.risk.charAt(0).toUpperCase() + zoneData.risk.slice(1);
    var periodLabel = year === riskSnapshotYear ? ('Snapshot ' + riskSnapshotYear) : ('Year ' + year);
    return periodLabel + ' · ' + label + ' · ' + zoneData.light.toFixed(1) + ' nW/cm²/sr';
}

function getDashboardThresholdNote() {
    var highRisk = Number(dashboardThresholds.high_risk || 60);
    var modRisk = Number(dashboardThresholds.mod_risk || 40);
    var lowRisk = Number(dashboardThresholds.low_risk || 25);
    return 'Applied thresholds: Low < ' + lowRisk.toFixed(1) + ' nW, Moderate >= ' + modRisk.toFixed(1) + ' nW, High >= ' + highRisk.toFixed(1) + ' nW.';
}

function renderDashboardThresholdNote() {
    var note = document.getElementById('riskThresholdNote');
    if (note) {
        note.textContent = getDashboardThresholdNote();
    }
}

function updateRiskSiteListHighlight() {
    if (!riskSiteListEl) return;
    var items = riskSiteListEl.querySelectorAll('[data-risk-index]');
    Array.prototype.forEach.call(items, function(item) {
        var idx = Number(item.getAttribute('data-risk-index'));
        var isSelected = idx === selectedRiskZoneIndex;
        var isVisible = riskZoneVisibility[idx] !== false;
        item.classList.toggle('is-selected', isSelected);
        item.classList.toggle('is-hidden', !isVisible);
        var toggle = item.querySelector('input[type="checkbox"]');
        if (toggle) toggle.checked = isVisible;
    });
}

function setRiskZoneVisible(index, visible) {
    riskZoneVisibility[index] = visible;
    var layer = riskZoneLayers[index];
    if (!layer) return;
    if (visible) {
        if (!map.hasLayer(layer)) {
            layer.addTo(map);
        }
        layer.bringToFront();
    } else if (map.hasLayer(layer)) {
        map.removeLayer(layer);
    }
    updateRiskSiteListHighlight();
}

function focusRiskZone(index) {
    var circle = riskZoneLayers[index];
    if (!circle) return;
    selectedRiskZoneIndex = index;
    updateRiskSiteListHighlight();
    map.flyTo(circle.getLatLng(), Math.max(map.getZoom(), 11), { duration: 0.7 });
    if (map.hasLayer(circle)) {
        circle.openPopup();
    } else {
        circle.addTo(map);
        circle.openPopup();
    }
}

function renderRiskSiteList() {
    riskSiteListEl = document.getElementById('riskSiteList');
    if (!riskSiteListEl) return;
    var summary = summarizeRiskYear(selectedRiskYear);

    var listHtml = riskZones.map(function(zone, index) {
        var zoneData = summary.zones[index] || zone;
        var style = getRiskZoneStyle(zoneData.risk);
        var metaText = getRiskZoneMeta({ risk: zoneData.risk, light: Number(zoneData.light || 0) }, selectedRiskYear);
        return '' +
            '<button class="risk-site-item" type="button" data-risk-index="' + index + '" title="' + metaText.replace(/"/g, '&quot;') + '" onclick="focusRiskZone(' + index + ')">' +
                '<span class="risk-site-dot" style="background:' + style.fillColor + '; border-color:' + style.color + '; width:8px; height:8px; border-radius:50%; display:inline-block; flex-shrink:0;"></span>' +
                '<span class="risk-site-item-name">' + zone.name + '</span>' +
                '<span class="risk-site-item-actions" onclick="event.stopPropagation();">' +
                    '<input class="risk-site-toggle" type="checkbox" checked aria-label="Toggle ' + zone.name.replace(/"/g, '&quot;') + '" onchange="setRiskZoneVisible(' + index + ', this.checked)">' +
                    '<button class="risk-site-focus" type="button" aria-label="Focus ' + zone.name.replace(/"/g, '&quot;') + '" onclick="event.stopPropagation(); focusRiskZone(' + index + ')">' +
                        '<svg width="11" height="11" viewBox="0 0 11 11" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" aria-hidden="true">' +
                            '<line x1="5.5" y1="0" x2="5.5" y2="11"/>' +
                            '<line x1="0" y1="5.5" x2="11" y2="5.5"/>' +
                            '<circle cx="5.5" cy="5.5" r="2.4"/>' +
                        '</svg>' +
                    '</button>' +
                '</span>' +
            '</button>';
    }).join('');

    riskSiteListEl.innerHTML = listHtml;
    updateRiskSiteListHighlight();
}

function syncRiskSitePanelVisibility() {
    var panel = document.getElementById('riskSitePanel');
    if (!panel) return;
    panel.style.display = currentMapView === 'risk' ? 'flex' : 'none';
}

function getRiskViewPadding() {
    return {
        paddingTopLeft: [24, 24],
        paddingBottomRight: [24, 24]
    };
}

function classifyRiskByLight(lightValue) {
    var highRisk = Number(dashboardThresholds.high_risk || 60);
    var modRisk = Number(dashboardThresholds.mod_risk || 40);
    if (lightValue >= highRisk) return 'high';
    if (lightValue >= modRisk) return 'medium';
    return 'low';
}

function computeZoneLight(zone, zoneIndex, year) {
    // Use site-footprint snapshot values for the snapshot year so Dashboard matches Reports.
    if (year === Number(zone.snapshot_year || riskSnapshotYear)) {
        return Math.max(0, Number(zone.light_exposure || 0));
    }

    var yearlyBySite = riskSiteYearly && riskSiteYearly[zone.name] ? riskSiteYearly[zone.name] : null;
    if (yearlyBySite && yearlyBySite[String(year)] !== undefined && yearlyBySite[String(year)] !== null) {
        return Math.max(0, Number(yearlyBySite[String(year)]));
    }

    // Get city for this zone from mapping
    var city = riskCityMap[zone.name] || null;
    if (!city) return Math.max(0, Number(zone.light_exposure || 0));

    // Look up viirs_avg from ecological_yearly_summary for this city and year
    var yearBucket = historicalEnvYearly && historicalEnvYearly[String(year)] ? historicalEnvYearly[String(year)] : null;
    if (!yearBucket || !yearBucket[city]) {
        return Math.max(0, Number(zone.light_exposure || 0));
    }

    var viirs = yearBucket[city].viirs;
    return viirs !== null && viirs !== undefined ? Math.max(0, Number(viirs)) : Math.max(0, Number(zone.light_exposure || 0));
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
        radius: 6200,
        color: style.color,
        fillColor: style.fillColor,
        fillOpacity: style.fillOpacity,
        weight: style.weight
    }).bindPopup(
        '<strong>' + zone.name + '</strong><br>Risk: Low<br>Avg VIIRS: 0.0 nW/cm²/sr'
    );
    circle._zoneIndex = index;
    riskZoneLayers.push(circle);
    riskZoneVisibility.push(true);
});

renderRiskSiteList();

function applyRiskZonesForYear(year) {
    selectedRiskYear = year;
    var summary = summarizeRiskYear(year);

    riskZoneLayers.forEach(function(circle, index) {
        var zoneData = summary.zones[index];
        var style = getRiskZoneStyle(zoneData.risk);
        circle.setStyle({
            color: style.color,
            fillColor: style.fillColor,
            fillOpacity: style.fillOpacity,
            weight: style.weight
        });
        circle.setRadius(6200);
        var popupHtml =
            '<strong>' + zoneData.name + '</strong>' +
            '<br>Year: ' + year +
            '<br>Risk: ' + zoneData.risk.charAt(0).toUpperCase() + zoneData.risk.slice(1) +
            '<br>Avg VIIRS: ' + zoneData.light.toFixed(1) + ' nW/cm²/sr';
        circle.bindPopup(popupHtml);
        if (circle.isPopupOpen()) {
            circle.setPopupContent(popupHtml);
        }
    });

    renderRiskSiteList();

    return summary;
}

renderDashboardThresholdNote();

window.addEventListener('storage', function(event) {
    if (event && event.key === 'avilight-thresholds-updated') {
        window.location.reload();
    }
});

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
                layer.on('click', function() {
                    if (currentMapView !== 'historical') {
                        layer.bindPopup('<strong>' + cityName + '</strong><br>Metro Manila monitoring area').openPopup();
                        return;
                    }

                    var context = latestHistoricalContext || {};
                    var popupSelections = context.selections || getHistoricalSelections();
                    var popupRows = context.rows || latestHistoricalRows || [];
                    var popupEnvRows = context.envRows || [];
                    layer.bindPopup(getHistoricalBoundaryPopupContent(cityName, popupRows, popupEnvRows, popupSelections)).openPopup();
                });
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

    var padding = getRiskViewPadding();

    map.flyToBounds(metroManilaBounds, {
        paddingTopLeft: padding.paddingTopLeft,
        paddingBottomRight: padding.paddingBottomRight,
        padding: [20, 20],
        maxZoom: 10,
        duration: 1.4
    });

    var reveal = function() {
        if (token !== riskAnimationToken || currentMapView !== 'risk') return;
        riskZoneLayers.forEach(function(circle, index) {
            var timer = setTimeout(function() {
                if (token !== riskAnimationToken || currentMapView !== 'risk') return;
                if (riskZoneVisibility[index] !== false) {
                    circle.addTo(map);
                }
            }, 120 * index);
            riskAnimationTimers.push(timer);
        });
    };

    map.once('moveend', reveal);

    var fallbackTimer = setTimeout(reveal, 1650);
    riskAnimationTimers.push(fallbackTimer);
}

// ── Species masterlist lookup (loaded once on init) ───────────────────────
// Keyed by lower-cased common name → { tolerance, migration }
var speciesLookup = {};
var speciesDisplayToKey = {};

function toDisplaySpeciesName(rawName) {
    return String(rawName || '')
        .split(/\s+/)
        .filter(function(part) { return !!part; })
        .map(function(part) {
            return part.charAt(0).toUpperCase() + part.slice(1);
        })
        .join(' ');
}

function populateBirdFilterOptions() {
    var birdInput = document.getElementById('birdFilterInput');
    var birdOptions = document.getElementById('birdFilterOptions');
    if (!birdInput || !birdOptions) return;

    var selectedValue = String(birdInput.value || '').trim();
    var options = [];
    speciesDisplayToKey = {};
    Object.keys(speciesLookup || {})
        .sort(function(a, b) { return a.localeCompare(b); })
        .forEach(function(speciesKey) {
            var displayName = toDisplaySpeciesName(speciesKey);
            speciesDisplayToKey[displayName.toLowerCase()] = speciesKey;
            options.push('<option value="' + escapeHtml(displayName) + '"></option>');
        });

    birdOptions.innerHTML = options.join('');
    birdInput.value = selectedValue;
}

function getSelectedBirdKey() {
    var birdInput = document.getElementById('birdFilterInput');
    if (!birdInput) return '';
    var raw = String(birdInput.value || '').trim();
    if (!raw) return '';
    var normalized = raw.toLowerCase();
    if (speciesLookup[normalized]) return normalized;
    if (speciesDisplayToKey[normalized]) return speciesDisplayToKey[normalized];
    return '';
}

fetch('api/get_species_lookup.php')
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data && data.success && data.lookup) {
            speciesLookup = data.lookup;
            populateBirdFilterOptions();
        }
    })
    .catch(function() { /* non-critical – badges simply won't show */ });

// ── Map view switching (Risk Zones / Historical Data) ─────────────────────

var currentMapView = 'risk';
var historicalObservationLayers = [];
var historicalObservationClusterLayer = null;
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
var HISTORICAL_DEFAULT_CITY = 'Metro Manila';
var HISTORICAL_CLUSTER_POPUP_MAX_HEIGHT = 220;
var HISTORICAL_CLUSTER_POPUP_MIN_WIDTH = 220;
var HISTORICAL_CLUSTER_UNKNOWN_SITE = 'Unknown Site';
var historicalTypingTimers = [];
var historicalTypingInProgress = false;
var historicalAutoSequenceTimers = [];
var expandedSpokeGroup = null;

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
    expandedSpokeGroup = null;
    historicalPointAnimationToken += 1;
    while (historicalPointAnimationTimers.length) {
        clearTimeout(historicalPointAnimationTimers.pop());
    }
    if (historicalObservationClusterLayer && map.hasLayer(historicalObservationClusterLayer)) {
        map.removeLayer(historicalObservationClusterLayer);
    }
    if (historicalObservationClusterLayer && historicalObservationClusterLayer.clearLayers) {
        historicalObservationClusterLayer.clearLayers();
    }
    historicalObservationClusterLayer = null;
    historicalObservationLayers.forEach(function(l) { map.removeLayer(l); });
    historicalObservationLayers = [];
}

function collapseHistoricalSpokes() {
    if (!expandedSpokeGroup) return;
    if (expandedSpokeGroup.aggregate) {
        var agg = expandedSpokeGroup.aggregate;
        if (typeof agg.setOpacity === 'function') {
            agg.setOpacity(1);
        } else if (typeof agg.setStyle === 'function') {
            agg.setStyle({ opacity: 1, fillOpacity: 0.85 });
        }
    }
    expandedSpokeGroup.layers.forEach(function(l) {
        if (map.hasLayer(l)) map.removeLayer(l);
        var idx = historicalObservationLayers.indexOf(l);
        if (idx !== -1) historicalObservationLayers.splice(idx, 1);
    });
    expandedSpokeGroup = null;
}

function expandHistoricalSpokes(aggregateMarker, baseLat, baseLng, records) {
    collapseHistoricalSpokes();
    if (typeof aggregateMarker.setOpacity === 'function') {
        aggregateMarker.setOpacity(0.3);
    } else if (typeof aggregateMarker.setStyle === 'function') {
        aggregateMarker.setStyle({ opacity: 0.3, fillOpacity: 0.3 });
    }

    var selections = getHistoricalSelections();
    var zoom = map.getZoom();
    var metersPerPx = 156543.03392 * Math.cos(baseLat * Math.PI / 180) / Math.pow(2, zoom);
    var offsetLat = (35 * metersPerPx) / 111320;
    var offsetLng = offsetLat / Math.cos(baseLat * Math.PI / 180);

    var spawnedLayers = [];

    var hub = L.circleMarker([baseLat, baseLng], {
        radius: 3, color: '#555', weight: 1.5,
        fillColor: '#fff', fillOpacity: 1, interactive: false
    }).addTo(map);
    spawnedLayers.push(hub);
    historicalObservationLayers.push(hub);

    records.forEach(function(site, i) {
        var richness = toNumber(site.total_unique);
        var color = getRichnessColor(richness);
        var selectedBirdLabel = selections.selectedBird ? toDisplaySpeciesName(selections.selectedBird) : '';
        var birdFilterLine = selectedBirdLabel ? ('<br>Bird Filter: ' + escapeHtml(selectedBirdLabel)) : '';

        var angle = (2 * Math.PI * i / records.length) - Math.PI / 2;
        var markerLat = baseLat + offsetLat * Math.sin(angle);
        var markerLng = baseLng + offsetLng * Math.cos(angle);

        var spoke = L.polyline([[baseLat, baseLng], [markerLat, markerLng]], {
            color: '#888', weight: 1.5, dashArray: '5 4', opacity: 0.75, interactive: false
        }).addTo(map);
        spawnedLayers.push(spoke);
        historicalObservationLayers.push(spoke);

        var marker = L.circleMarker([markerLat, markerLng], {
            radius: 8, color: '#fff', weight: 1.3, fillColor: color, fillOpacity: 0.85
        }).bindPopup(
            '<strong>' + site.site_name + '</strong>' +
            '<br>Year: ' + site.year + '  ' + getMonthName(toNumber(site.month)) +
            '<br>Unique Species: <strong>' + richness + '</strong>' +
            '<br>Resident: ' + toNumber(site.total_resident) + '  Migrant: ' + toNumber(site.total_migrant) +
            '<br>Light Tolerant: ' + toNumber(site.total_tolerant) + '  Light Sensitive: ' + toNumber(site.total_sensitive) +
            birdFilterLine
        );
        marker.on('click', function(e) {
            L.DomEvent.stopPropagation(e);
            showHistoricalSiteDetail(site);
        });
        marker.addTo(map);
        if (marker.bringToFront) marker.bringToFront();
        spawnedLayers.push(marker);
        historicalObservationLayers.push(marker);
    });

    expandedSpokeGroup = { aggregate: aggregateMarker, layers: spawnedLayers };
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

function matchesMigrationValue(infoMigration, expected) {
    if (!expected) return true;
    return String(infoMigration || '').toLowerCase() === String(expected).toLowerCase();
}

function matchesLightValue(infoTolerance, expected) {
    if (!expected) return true;
    return String(infoTolerance || '').toLowerCase() === String(expected).toLowerCase();
}

function siteHasSpeciesByTrait(speciesNames, traitKey, expectedValue) {
    if (!expectedValue) return true;
    return speciesNames.some(function(name) {
        var info = speciesLookup[String(name || '').toLowerCase()];
        if (!info) return false;
        return String(info[traitKey] || '').toLowerCase() === String(expectedValue).toLowerCase();
    });
}

function siteMatchesHistoricalFilters(site, selections) {
    var speciesNames = parseSpeciesList(site && site.species_list);
    var selectedBird = String((selections && selections.selectedBird) || '').trim().toLowerCase();
    var migrationFilter = String((selections && selections.migrationFilter) || '').trim();
    var lightFilter = String((selections && selections.lightFilter) || '').trim();

    if (selectedBird) {
        var hasSelectedBird = speciesNames.some(function(name) {
            return String(name || '').trim().toLowerCase() === selectedBird;
        });
        if (!hasSelectedBird) return false;

        var selectedInfo = speciesLookup[selectedBird] || null;
        if (!matchesMigrationValue(selectedInfo && selectedInfo.migration, migrationFilter)) return false;
        if (!matchesLightValue(selectedInfo && selectedInfo.tolerance, lightFilter)) return false;
        return true;
    }

    var migrationMatches = siteHasSpeciesByTrait(speciesNames, 'migration', migrationFilter);
    var lightMatches = siteHasSpeciesByTrait(speciesNames, 'tolerance', lightFilter);

    if (migrationFilter && !migrationMatches) {
        var migrationFilterNorm = migrationFilter.toLowerCase();
        var fallbackMigrationCount = 0;
        if (migrationFilterNorm === 'migratory') {
            fallbackMigrationCount = toNumber(site.total_migrant);
        } else if (migrationFilterNorm === 'resident') {
            fallbackMigrationCount = toNumber(site.total_resident);
        }
        migrationMatches = fallbackMigrationCount > 0;
    }

    if (lightFilter && !lightMatches) {
        var lightFilterNorm = lightFilter.toLowerCase();
        var fallbackLightCount = 0;
        if (lightFilterNorm === 'tolerant') {
            fallbackLightCount = toNumber(site.total_tolerant);
        } else if (lightFilterNorm === 'sensitive') {
            fallbackLightCount = toNumber(site.total_sensitive);
        }
        lightMatches = fallbackLightCount > 0;
    }

    return migrationMatches && lightMatches;
}

function getHistoricalSiteCity(site) {
    if (!site) return HISTORICAL_DEFAULT_CITY;

    var lat = toNumber(site.latitude);
    var lng = toNumber(site.longitude);
    if (!lat || !lng || !municipalityGeoData || !municipalityGeoData.features) {
        return HISTORICAL_DEFAULT_CITY;
    }

    for (var i = 0; i < municipalityGeoData.features.length; i++) {
        var feature = municipalityGeoData.features[i];
        if (pointInPolygon(lat, lng, feature.geometry)) {
            return getMunicipalityName(feature);
        }
    }

    return HISTORICAL_DEFAULT_CITY;
}

function getHistoricalClusterSites(markers) {
    var siteMap = {};
    (markers || []).forEach(function(marker) {
        var site = marker && marker.historicalSiteData ? marker.historicalSiteData : null;
        var name = (site && site.site_name) ? site.site_name : HISTORICAL_CLUSTER_UNKNOWN_SITE;
        siteMap[name] = true;
    });
    return Object.keys(siteMap).sort();
}

function getHistoricalClusterPopupContent(markers) {
    var sites = getHistoricalClusterSites(markers);
    var wrapStyle = 'min-width:' + HISTORICAL_CLUSTER_POPUP_MIN_WIDTH + 'px; line-height:1.45;';
    if (!sites.length) {
        return '<div style="' + wrapStyle + '">No observation sites in this cluster.</div>';
    }

    var siteListHtml = sites.map(function(name) {
        return '<li>' + escapeHtml(name) + '</li>';
    }).join('');

    return '<div style="' + wrapStyle + '">' +
        '<strong>Sites in this cluster (' + sites.length + ')</strong>' +
        '<ul style="margin:6px 0 0 16px; padding:0;">' + siteListHtml + '</ul>' +
    '</div>';
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
            var info = speciesLookup[name.toLowerCase()];
            var badges = '';
            if (info) {
                var migClass  = info.migration  === 'Migratory' ? 'spp-badge-migrant'  : 'spp-badge-resident';
                var tolClass  = info.tolerance  === 'Tolerant'  ? 'spp-badge-tolerant' : 'spp-badge-sensitive';
                var migLabel  = info.migration  === 'Migratory' ? 'Migratory'          : 'Resident';
                var tolLabel  = info.tolerance  === 'Tolerant'  ? 'Light-tolerant'     : 'Light-sensitive';
                badges = ' <span class="spp-badge ' + migClass  + '">' + migLabel + '</span>' +
                         ' <span class="spp-badge ' + tolClass  + '">' + tolLabel + '</span>';
            }
            return '<li>' + escapeHtml(name) + badges + '</li>';
        }).join('') + '</ul>')
        : '<div class="historical-site-empty">No species list available for this site entry.</div>';

    document.getElementById('histSiteName').textContent = siteName;
    document.getElementById('histSiteLocation').textContent = '📍 ' + cityName + ', Metro Manila';

    var envRows = (latestHistoricalContext && latestHistoricalContext.envRows) ? latestHistoricalContext.envRows : [];
    var selections = (latestHistoricalContext && latestHistoricalContext.selections) ? latestHistoricalContext.selections : getHistoricalSelections();
    var hasEnvSelection = !!(selections && selections.envType);
    var municipalityEnv = null;

    if (hasEnvSelection && envRows.length) {
        var cityNorm = normalizeAreaKey(cityName || '');
        municipalityEnv = envRows.find(function(item) {
            return normalizeAreaKey(item.city || '') === cityNorm;
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
        selectedBird: getSelectedBirdKey(),
        migrationFilter: document.getElementById('migrationFilterSelect').value,
        lightFilter: document.getElementById('lightFilterSelect').value,
        envType: document.getElementById('envDataSelect').value,
        selectedLandCoverTypes: selectedLandCoverTypes,
        landTempPeriod: document.getElementById('landTempPeriod').value
    };
}

function normalizeAreaKey(name) {
    if (!name) return '';
    return String(name)
        .toLowerCase()
        .replace(/\s+city$/g, '')
        .replace(/ñ/g, 'n')
        .replace(/[^a-z0-9]/g, '');
}

function getHistoricalEnvConfig(envType, landTempPeriod) {
    if (envType === 'viirs') return { key: 'viirs', label: 'VIIRS', decimals: 1, unit: ' nW' };
    if (envType === 'ndvi') return { key: 'ndvi', label: 'NDVI', decimals: 2, unit: '' };
    if (envType === 'land_temp') {
        return {
            key: 'lst',
            label: landTempPeriod === 'night' ? 'Land Temp (Night)' : 'Land Temp (Day)',
            decimals: 1,
            unit: ' °C'
        };
    }
    if (envType === 'precip') return { key: 'precipitation', label: 'Precip', decimals: 0, unit: ' mm' };
    return null;
}

function getHistoricalObservationKey(selections) {
    return [
        selections.year,
        selections.month,
        selections.showObservation ? 1 : 0,
        selections.selectedBird || '',
        selections.migrationFilter || '',
        selections.lightFilter || ''
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
    if (selections.envType !== 'land_cover') {
        var envConfig = getHistoricalEnvConfig(selections.envType, selections.landTempPeriod);
        var yearBucket = historicalEnvYearly && historicalEnvYearly[String(selections.year)] ? historicalEnvYearly[String(selections.year)] : null;
        if (!envConfig || !yearBucket) return [];

        var rowsFromSummary = Object.keys(yearBucket).map(function(cityName) {
            var record = yearBucket[cityName] || {};
            var numeric = record[envConfig.key];
            if (numeric == null || !Number.isFinite(Number(numeric))) {
                return null;
            }
            var value = Number(numeric);
            return {
                city: cityName,
                label: envConfig.label,
                valueText: value.toFixed(envConfig.decimals) + envConfig.unit,
                numericValue: value,
                color: getEnvColor(selections.envType, { value: value }, '')
            };
        }).filter(function(row) { return row !== null; });

        rowsFromSummary.sort(function(a, b) { return a.city.localeCompare(b.city); });
        return rowsFromSummary;
    }

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

function buildHistoricalBoundarySummary(cityName, rows, envRows, selections) {
    var cityNorm = normalizeAreaKey(cityName || '');
    var cityRows = (rows || []).filter(function(site) {
        return normalizeAreaKey(getHistoricalSiteCity(site)) === cityNorm;
    });
    var speciesSet = new Set();
    var maxSiteRichness = 0;

    var summary = {
        city: cityName || 'Metro Manila',
        dateLabel: selections ? (selections.year + ' · ' + getMonthName(selections.month)) : 'Not applied',
        envLabel: null,
        envValueText: null,
        richness: 0,
        resident: 0,
        migrant: 0,
        tolerant: 0,
        sensitive: 0
    };

    cityRows.forEach(function(site) {
        var siteSpecies = parseSpeciesList(site.species_list);
        siteSpecies.forEach(function(name) {
            var normalized = String(name || '').trim().toLowerCase();
            if (normalized) speciesSet.add(normalized);
        });

        maxSiteRichness = Math.max(maxSiteRichness, toNumber(site.total_unique));
        summary.resident += toNumber(site.total_resident);
        summary.migrant += toNumber(site.total_migrant);
        summary.tolerant += toNumber(site.total_tolerant);
        summary.sensitive += toNumber(site.total_sensitive);
    });

    // City richness should represent unique species, not sum of per-site richness.
    summary.richness = speciesSet.size > 0 ? speciesSet.size : maxSiteRichness;

    // Keep category counts consistent with city-level unique species richness.
    if (speciesSet.size > 0 && speciesLookup && typeof speciesLookup === 'object') {
        var lookedUpSpecies = 0;
        var residentCount = 0;
        var migrantCount = 0;
        var tolerantCount = 0;
        var sensitiveCount = 0;

        speciesSet.forEach(function(speciesKey) {
            var info = speciesLookup[speciesKey];
            if (!info) {
                sensitiveCount += 1;
                residentCount += 1;
                return;
            }

            lookedUpSpecies += 1;

            var tolerance = String(info.tolerance || '').toLowerCase();
            var migration = String(info.migration || '').toLowerCase();

            if (tolerance === 'tolerant') tolerantCount += 1;
            else sensitiveCount += 1;

            if (migration === 'migratory') migrantCount += 1;
            else residentCount += 1;
        });

        if (lookedUpSpecies > 0) {
            summary.resident = residentCount;
            summary.migrant = migrantCount;
            summary.tolerant = tolerantCount;
            summary.sensitive = sensitiveCount;
        }
    }

    if (selections && selections.envType && Array.isArray(envRows) && envRows.length) {
        var cityEnv = envRows.find(function(item) {
            return normalizeAreaKey(item.city || '') === cityNorm;
        }) || null;

        if (cityEnv) {
            summary.envLabel = cityEnv.label;
            summary.envValueText = cityEnv.valueText;
        }
    }

    return summary;
}

function getHistoricalBoundaryPopupContent(cityName, rows, envRows, selections) {
    var summary = buildHistoricalBoundarySummary(cityName, rows, envRows, selections);
    var envText = summary.envLabel && summary.envValueText
        ? (escapeHtml(summary.envLabel) + ' ' + escapeHtml(summary.envValueText))
        : 'Not selected';
    var richnessText = summary.richness > 0 ? summary.richness.toLocaleString() + ' spp.' : 'No observation data';

    return '<div style="min-width:220px; line-height:1.45;">' +
        '<strong>' + escapeHtml(summary.city) + '</strong>' +
        '<div style="margin-top:6px;">Environmental data: <strong>' + envText + '</strong></div>' +
        '<div>Bird richness: <strong>' + escapeHtml(richnessText) + '</strong></div>' +
        '<div style="margin-top:4px; font-size:0.78rem; color:var(--text-secondary);">' +
            'Sensitive: <strong>' + summary.sensitive.toLocaleString() + '</strong> · ' +
            'Tolerant: <strong>' + summary.tolerant.toLocaleString() + '</strong><br>' +
            'Migrant: <strong>' + summary.migrant.toLocaleString() + '</strong> · ' +
            'Resident: <strong>' + summary.resident.toLocaleString() + '</strong>' +
        '</div>' +
        '<div style="margin-top:6px; font-size:0.78rem; color:var(--text-secondary);">' +
            'Date filter: <strong>' + escapeHtml(summary.dateLabel) + '</strong>' +
        '</div>' +
    '</div>';
}

function renderHistoricalMap(rows, selections, options) {
    options = options || {};
    var preserveObservation = !!options.preserveObservation;
    rows = rows || [];

    latestHistoricalContext = { rows: rows, selections: selections, envRows: [] };

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
        latestHistoricalContext.envRows = buildMunicipalityEnvRows(selections, coverageFeatures);
    }

    if (selections.showObservation && !preserveObservation) {
        clearHistoricalObservationLayers();
        var validSites = rows.filter(function(site) {
            var lat = toNumber(site.latitude);
            var lng = toNumber(site.longitude);
            return !!lat && !!lng;
        });

        if (typeof L !== 'undefined' && L.markerClusterGroup) {
            historicalObservationClusterLayer = L.markerClusterGroup({
                disableClusteringAtZoom: 15,
                maxClusterRadius: 50,
                spiderfyOnMaxZoom: true,
                showCoverageOnHover: false,
                // Keep click on cluster for area popup instead of immediately zooming.
                zoomToBoundsOnClick: false
            });
            historicalObservationClusterLayer.addTo(map);
            historicalObservationClusterLayer.on('clusterclick', function(event) {
                var cluster = event && event.layer ? event.layer : null;
                if (!cluster || !cluster.getAllChildMarkers) return;
                var markers = cluster.getAllChildMarkers();
                var popupHtml = getHistoricalClusterPopupContent(markers);
                if (cluster.getPopup()) {
                    cluster.setPopupContent(popupHtml);
                } else {
                    cluster.bindPopup(popupHtml, { maxHeight: HISTORICAL_CLUSTER_POPUP_MAX_HEIGHT, className: 'historical-cluster-popup' });
                }
                cluster.openPopup();
            });
        }

        // Group by exact location for click-to-expand multi-month spoke visualization
        var siteLocationGroups = {};
        validSites.forEach(function(site) {
            var lat = toNumber(site.latitude);
            var lng = toNumber(site.longitude);
            var locKey = lat.toFixed(5) + ',' + lng.toFixed(5);
            if (!siteLocationGroups[locKey]) {
                siteLocationGroups[locKey] = { lat: lat, lng: lng, records: [] };
            }
            siteLocationGroups[locKey].records.push(site);
        });

        // Register map click handler once to collapse open spokes
        if (!map._historicalSpokeClickBound) {
            map._historicalSpokeClickBound = true;
            map.on('click', function() { collapseHistoricalSpokes(); });
        }

        Object.keys(siteLocationGroups).forEach(function(locKey) {
            var group = siteLocationGroups[locKey];
            var baseLat = group.lat;
            var baseLng = group.lng;
            var records = group.records;
            records.sort(function(a, b) { return toNumber(a.month) - toNumber(b.month); });

            var isMulti = records.length > 1;

            // Use the highest-richness month as the representative for the aggregate marker
            var repRecord = records.reduce(function(best, r) {
                return toNumber(r.total_unique) >= toNumber(best.total_unique) ? r : best;
            }, records[0]);

            var richness = toNumber(repRecord.total_unique);
            var color = getRichnessColor(richness);
            var selectedBirdLabel = selections.selectedBird ? toDisplaySpeciesName(selections.selectedBird) : '';
            var birdFilterLine = selectedBirdLabel ? ('<br>Bird Filter: ' + escapeHtml(selectedBirdLabel)) : '';

            var aggregateMarker;
            if (isMulti) {
                aggregateMarker = L.marker([baseLat, baseLng], {
                    icon: L.divIcon({
                        className: '',
                        html: '<div style="width:14px;height:14px;background:' + color + ';border:2.5px solid rgba(255,255,255,0.9);box-sizing:border-box;"></div>',
                        iconSize: [14, 14],
                        iconAnchor: [7, 7],
                        popupAnchor: [0, -7]
                    })
                });
            } else {
                aggregateMarker = L.circleMarker([baseLat, baseLng], {
                    radius: 8,
                    color: '#fff',
                    weight: 1.3,
                    fillColor: color,
                    fillOpacity: 0.85
                });
            }
            aggregateMarker.historicalSiteData = repRecord;

            if (isMulti) {
                aggregateMarker.bindTooltip(
                    records.length + ' months — click to expand',
                    { direction: 'top', offset: [0, -10] }
                );
                (function(aggMarker, bLat, bLng, recs) {
                    aggMarker.on('click', function(e) {
                        L.DomEvent.stopPropagation(e);
                        if (expandedSpokeGroup && expandedSpokeGroup.aggregate === aggMarker) {
                            collapseHistoricalSpokes();
                        } else {
                            expandHistoricalSpokes(aggMarker, bLat, bLng, recs);
                        }
                    });
                })(aggregateMarker, baseLat, baseLng, records);
            } else {
                aggregateMarker.bindPopup(
                    '<strong>' + repRecord.site_name + '</strong>' +
                    '<br>Year: ' + repRecord.year + '  ' + getMonthName(toNumber(repRecord.month)) +
                    '<br>Unique Species: <strong>' + richness + '</strong>' +
                    '<br>Resident: ' + toNumber(repRecord.total_resident) + '  Migrant: ' + toNumber(repRecord.total_migrant) +
                    '<br>Light Tolerant: ' + toNumber(repRecord.total_tolerant) + '  Light Sensitive: ' + toNumber(repRecord.total_sensitive) +
                    birdFilterLine
                );
                aggregateMarker.on('click', function(e) {
                    L.DomEvent.stopPropagation(e);
                    showHistoricalSiteDetail(repRecord);
                });
            }

            if (historicalObservationClusterLayer) {
                historicalObservationClusterLayer.addLayer(aggregateMarker);
            } else {
                aggregateMarker.addTo(map);
            }
            historicalObservationLayers.push(aggregateMarker);
            if (aggregateMarker && aggregateMarker.bringToFront) {
                aggregateMarker.bringToFront();
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
    if (selections.selectedBird) {
        badgeText += ' · ' + toDisplaySpeciesName(selections.selectedBird);
    }
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
    var monthLabel = selections.month > 0 ? getMonthName(selections.month) : 'Annual';
    var currentPeriodLabel = selections.month > 0 ? (currentYear + ' · ' + monthLabel) : String(currentYear);
    var previousPeriodLabel = selections.month > 0 ? (previousYear + ' · ' + monthLabel) : String(previousYear);

    var currentRows = Array.isArray(latestHistoricalRows) ? latestHistoricalRows : [];
    var currentEnvRows = latestHistoricalContext && latestHistoricalContext.selections &&
        latestHistoricalContext.selections.year === selections.year &&
        latestHistoricalContext.selections.month === selections.month &&
        latestHistoricalContext.selections.envType === selections.envType
        ? (latestHistoricalContext.envRows || [])
        : buildMunicipalityEnvRows(selections, getCoverageFeaturesForSelections(selections));

    var currentSummary = buildHistoricalSectionSummary(currentRows, selections, currentEnvRows);
    var previousSelections = hasPrev ? {
        year: previousYear,
        month: selections.month,
        envType: selections.envType,
        landTempPeriod: selections.landTempPeriod,
        selectedLandCoverTypes: selections.selectedLandCoverTypes
    } : null;
    var previousEnvRows = previousSelections ? buildMunicipalityEnvRows(previousSelections, getCoverageFeaturesForSelections(previousSelections)) : [];
    var previousSummary = previousSelections ? buildHistoricalSectionSummary([], previousSelections, previousEnvRows) : null;

    var birdDelta = 0;
    if (selections.month > 0) {
        var currentBirdMonth = birdRichnessData[currentYear] && birdRichnessData[currentYear][selections.month - 1] != null
            ? birdRichnessData[currentYear][selections.month - 1]
            : null;
        var previousBirdMonth = hasPrev && birdRichnessData[previousYear] && birdRichnessData[previousYear][selections.month - 1] != null
            ? birdRichnessData[previousYear][selections.month - 1]
            : null;
        birdDelta = (currentBirdMonth != null && previousBirdMonth != null)
            ? getPctDelta(currentBirdMonth, previousBirdMonth)
            : 0;
    } else {
        var currentBirdStats = getBirdYearStats(currentYear);
        var previousBirdStats = hasPrev ? getBirdYearStats(previousYear) : null;
        birdDelta = previousBirdStats ? getPctDelta(currentBirdStats.avg, previousBirdStats.avg) : 0;
    }

    document.getElementById('histRecentBadge').textContent = hasPrev ? (currentPeriodLabel + ' vs ' + previousPeriodLabel) : (currentPeriodLabel + ' baseline');
    document.getElementById('histRecentBird').textContent = hasPrev
        ? formatChangeStatement('Bird richness', birdDelta, '%', 2)
        : 'Bird richness baseline period selected (no previous comparison)';
    document.getElementById('histRecentBirdSub').textContent = hasPrev ? (currentPeriodLabel + ' vs ' + previousPeriodLabel) : (currentPeriodLabel + ' baseline');

    var envDelta = null;
    var envIsBeneficialWhenDown = false;
    if (selections.envType && currentSummary.envAverage !== null) {
        if (hasPrev && previousSummary && previousSummary.envAverage !== null) {
            envDelta = currentSummary.envAverage - previousSummary.envAverage;
            document.getElementById('histRecentViirs').textContent = formatChangeStatement(currentSummary.envLabel || 'Environmental overlay', envDelta, currentSummary.envUnit, currentSummary.envDecimals);
            // VIIRS and land temp going up are generally bad for birds; NDVI/precip going up is good
            envIsBeneficialWhenDown = (selections.envType === 'viirs' || selections.envType === 'land_temp');
        } else {
            document.getElementById('histRecentViirs').textContent = (currentSummary.envLabel || 'Environmental overlay') + ': ' + currentSummary.envAverage.toFixed(currentSummary.envDecimals) + currentSummary.envUnit;
        }
    } else {
        document.getElementById('histRecentViirs').textContent = 'Environmental overlay not selected';
    }
    document.getElementById('histRecentViirsSub').textContent = hasPrev ? (currentPeriodLabel + ' vs ' + previousPeriodLabel) : (currentPeriodLabel + ' baseline');

    document.getElementById('histRecentMonitor').textContent = hasPrev
        ? ('Monitoring period: ' + currentPeriodLabel + ' comparison active')
        : ('Monitoring period: ' + currentPeriodLabel + ' baseline active');
    document.getElementById('histRecentMonitorSub').textContent = selections.month > 0 ? 'Selected month comparison' : 'Annual summary';

    // Update historical icons dynamically
    applyActivityIcon('histIconBird', birdDelta, hasPrev, false);
    if (envDelta !== null) {
        applyActivityIcon('histIconViirs', envDelta, true, envIsBeneficialWhenDown);
    } else {
        var histViirsIconEl = document.getElementById('histIconViirs');
        if (histViirsIconEl) { histViirsIconEl.className = 'activity-icon blue'; histViirsIconEl.innerHTML = '&#8644;'; }
    }
    var histMonitorIconEl = document.getElementById('histIconMonitor');
    if (histMonitorIconEl) { histMonitorIconEl.className = 'activity-icon blue'; histMonitorIconEl.innerHTML = '&#8596;'; }
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

            var rawRows = resp.data || [];
            var filteredRows = rawRows.filter(function(site) {
                return siteMatchesHistoricalFilters(site, selections);
            });

            latestHistoricalRows = filteredRows;
            renderHistoricalMap(filteredRows, selections, {
                preserveObservation: preserveObservation
            });
            renderObservationSidebar(filteredRows, selections);
            renderEnvironmentalSidebar(filteredRows, selections);
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

    var riskHint = document.getElementById('riskViewHint');
    var histHint = document.getElementById('histViewHint');
    if (riskHint) riskHint.className = isHist ? 'map-control-hint is-hidden' : 'map-control-hint';
    if (histHint) histHint.className = isHist ? 'map-control-hint' : 'map-control-hint is-hidden';

    // Show/hide filter controls
    var filters = document.getElementById('historicalFilters');
    filters.style.display = isHist ? 'flex' : 'none';
    document.getElementById('historicalOverlayControls').style.display = isHist ? 'flex' : 'none';

    document.getElementById('riskSidebarPanels').style.display = isHist ? 'none' : 'block';
    document.getElementById('historicalSidebarPanels').style.display = isHist ? 'block' : 'none';

    // Show/hide legends
    document.getElementById('legendRiskZones').style.display  = isHist ? 'none'  : 'block';
    document.getElementById('legendHistorical').style.display = isHist ? 'block' : 'none';
    syncRiskSitePanelVisibility();
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
        renderRiskSiteList();
    }

    if (isHist) {
        map.fitBounds(MM_BOUNDS, { padding: [8, 8] });
        resetHistoricalSiteDetailPanel();
        prepareHistoricalDeferredPanels();
        loadHistoricalData();
    } else {
        // Collapse filter sub-row when leaving historical view
        histFiltersVisible = false;
        var filterRow = document.getElementById('histFilterRow');
        var filterBtn = document.getElementById('histFiltersToggle');
        if (filterRow) filterRow.style.display = 'none';
        if (filterBtn) filterBtn.innerHTML = '&#9881; Filters &#9660;';

        resetHistoricalSiteDetailPanel();
        clearHistoricalTypingTimers();
        clearHistoricalAutoSequenceTimers();
        clearHistoricalLayers();
        applyYearDrivenUpdates(getSelectedDashboardYear());
        playRiskViewAnimation();
        prepareRiskSidebarAutoReveal();
    }

    // Force Leaflet to re-measure the container after every view switch.
    // Tab/panel transitions don't fire window.resize, so tiles can mis-align.
    setTimeout(function () { map.invalidateSize({ animate: false }); }, 60);
}

var histFiltersVisible = false;
function toggleHistFilters() {
    histFiltersVisible = !histFiltersVisible;
    var row = document.getElementById('histFilterRow');
    var btn = document.getElementById('histFiltersToggle');
    if (row) row.style.display = histFiltersVisible ? 'flex' : 'none';
    if (btn) btn.innerHTML = histFiltersVisible ? '&#9881; Filters &#9650;' : '&#9881; Filters &#9660;';
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

// Bird Richness fallback data per year (2014-2025), monthly values
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
    2024: [168, 183, 174, 193, 208, 199, 212, 209, 195, 183, 174, 167],
    2025: [170, 185, 176, 195, 210, 201, 214, 211, 197, 185, 176, 169]
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

// inverted=true → increase is bad (red ↗), decrease is good (green ↘).
// Use for metrics where "higher = worse" (e.g. At Risk Zones, Light Intensity).
function updateTrendBadge(elementId, value, suffix, decimals, neutralClassName, inverted) {
    var element = document.getElementById(elementId);
    if (!element) return;

    var rounded = Math.abs(value).toFixed(decimals);
    if (value > 0) {
        element.className = 'dash-stat-trend ' + (inverted ? 'down' : 'up');
        element.textContent = '↗ +' + rounded + suffix;
        return;
    }
    if (value < 0) {
        element.className = 'dash-stat-trend ' + (inverted ? 'up' : 'down');
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

function applyActivityIcon(iconId, delta, hasPrev, isBeneficialWhenDown) {
    var el = document.getElementById(iconId);
    if (!el) return;
    el.className = 'activity-icon';
    el.style.cssText = '';
    if (!hasPrev) {
        el.className = 'activity-icon blue';
        el.innerHTML = '&#8644;';
        return;
    }
    var isPositive = delta > 0.05;
    var isNegative = delta < -0.05;
    if (isBeneficialWhenDown) {
        // e.g. VIIRS: going down is good (green), going up is bad (red)
        if (isNegative)      { el.className = 'activity-icon green'; el.innerHTML = '&#8595;'; }
        else if (isPositive) { el.className = 'activity-icon red';   el.innerHTML = '&#8593;'; }
        else                 { el.className = 'activity-icon blue';  el.innerHTML = '&#8644;'; }
    } else {
        // e.g. Bird richness: going up is good (green), going down is bad (red)
        if (isPositive)      { el.className = 'activity-icon green'; el.innerHTML = '&#8593;'; }
        else if (isNegative) { el.className = 'activity-icon red';   el.innerHTML = '&#8595;'; }
        else                 { el.className = 'activity-icon blue';  el.innerHTML = '&#8644;'; }
    }
}

// viirsPctDelta is a percentage (getPctDelta result), not absolute nW.
function updateRecentUpdates(currentYear, birdPctDelta, viirsPctDelta) {
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
            ? formatChangeStatement('Avg VIIRS radiance', viirsPctDelta, '%', 1)
            : 'Avg VIIRS baseline year selected (no previous-year comparison)';
    }
    if (viirsPeriodEl) viirsPeriodEl.textContent = periodLabel;

    if (monitorStatusEl) {
        monitorStatusEl.textContent = hasPrev
            ? ('Monitoring period: ' + currentYear + ' vs ' + prevYear + ' comparison active')
            : ('Monitoring period: ' + currentYear + ' baseline year active');
    }
    if (monitorPeriodEl) monitorPeriodEl.textContent = periodLabel;

    // Update icons dynamically based on direction and ecological meaning
    applyActivityIcon('riskIconBird',    birdPctDelta,   hasPrev, false); // richness up = good
    applyActivityIcon('riskIconViirs',   viirsPctDelta, hasPrev, true);  // VIIRS up = bad
    var monitorIconEl = document.getElementById('riskIconMonitor');
    if (monitorIconEl) {
        monitorIconEl.className = 'activity-icon blue';
        monitorIconEl.innerHTML = '&#8596;';
    }
}

// Resolve Metro Manila avg VIIRS for a year from ecological_yearly_summary data.
// Prefers the 'All Areas' aggregate row (same source as Home tab) then falls back
// to the mean of all per-city entries, then to the KBA/PA site footprint average.
function getMetroViirsForYear(year, kbaFallback) {
    var bucket = historicalEnvYearly && historicalEnvYearly[String(year)] ? historicalEnvYearly[String(year)] : null;
    if (bucket) {
        // Prefer the pre-aggregated 'All Areas' row (matches home tab exactly)
        var allAreas = bucket['All Areas'];
        if (allAreas && allAreas.viirs !== null && allAreas.viirs !== undefined) {
            return Math.max(0, Number(allAreas.viirs));
        }
        // Fall back to average of all per-city rows
        var cityKeys = Object.keys(bucket).filter(function(k) { return k !== 'All Areas'; });
        if (cityKeys.length) {
            var sum = 0, cnt = 0;
            cityKeys.forEach(function(k) {
                var v = bucket[k] && bucket[k].viirs !== null && bucket[k].viirs !== undefined ? Number(bucket[k].viirs) : null;
                if (v !== null && !isNaN(v)) { sum += v; cnt++; }
            });
            if (cnt > 0) return Math.max(0, sum / cnt);
        }
    }
    // Last resort: KBA/PA site footprint average (original behaviour)
    return Math.max(0, kbaFallback);
}

function updateAtRiskCardDescription() {
    var descEl = document.getElementById('atRiskZonesDesc');
    if (!descEl) return;
    var modRisk  = Number(dashboardThresholds.mod_risk  || 40);
    var highRisk = Number(dashboardThresholds.high_risk || 60);
    descEl.innerHTML =
        'Areas classified as <strong>Medium</strong> (&ge;' + modRisk.toFixed(0) + '&nbsp;nW) or ' +
        '<strong>High</strong> (&ge;' + highRisk.toFixed(0) + '&nbsp;nW) risk based on VIIRS ' +
        'night-light radiance. Shown on the map as <span class="risk-medium-indicator">yellow</span> ' +
        'and <span class="risk-high-indicator">red</span> circles.';
}

function updateYearDrivenUpdatesOnly(currentYear) {
    var previousYear = currentYear - 1;
    var currentRiskSummary = summarizeRiskYear(currentYear);
    var previousRiskSummary = (previousYear >= DASHBOARD_MIN_YEAR) ? summarizeRiskYear(previousYear) : null;

    // Resolve light intensity from Metro Manila aggregate (matches home tab)
    var currentViirs  = getMetroViirsForYear(currentYear,  currentRiskSummary.avgViirs);
    var previousViirs = previousRiskSummary
        ? getMetroViirsForYear(previousYear, previousRiskSummary.avgViirs)
        : null;

    // Update stat card values
    document.getElementById('atRiskZonesValue').textContent  = currentRiskSummary.atRiskZones;
    document.getElementById('lightIntensityValue').textContent = currentViirs.toFixed(1) + ' nW';

    // Year badges on both cards
    var yearLabel = '(' + currentYear + ')';
    var atRiskYearEl = document.getElementById('atRiskZonesYear');
    var lightYearEl  = document.getElementById('lightIntensityYear');
    if (atRiskYearEl) atRiskYearEl.textContent = yearLabel;
    if (lightYearEl)  lightYearEl.textContent  = yearLabel;

    // Dynamic threshold description
    updateAtRiskCardDescription();

    if (previousRiskSummary && previousViirs !== null) {
        var zonesPctDelta = getPctDelta(currentRiskSummary.atRiskZones, previousRiskSummary.atRiskZones);
        // Use percentage delta for light intensity — consistent with at-risk zones badge
        // and more meaningful than raw nW difference across different baseline years.
        var viirsPctDelta = getPctDelta(currentViirs, previousViirs);
        // Both metrics: higher = ecologically worse → invert colors (green ↘ = good)
        updateTrendBadge('atRiskZonesTrend',    zonesPctDelta, '%', 1, null, true);
        updateTrendBadge('lightIntensityTrend', viirsPctDelta, '%', 1, null, true);

        var currentBirdStats = getBirdYearStats(currentYear);
        var previousBirdStats = getBirdYearStats(previousYear);
        var birdPctDelta = getPctDelta(currentBirdStats.avg, previousBirdStats.avg);
        updateRecentUpdates(currentYear, birdPctDelta, viirsPctDelta);
    } else {
        updateTrendBadge('atRiskZonesTrend',    0, '%', 1);
        updateTrendBadge('lightIntensityTrend', 0, '%', 1);
        updateRecentUpdates(currentYear, 0, 0);
    }
}

function updateChartForYear(year) {
    var yearData = birdRichnessData[year] || birdRichnessData[DASHBOARD_MIN_YEAR];
    var currentStats = getBirdYearStats(year);
    var previousStats = (year > DASHBOARD_MIN_YEAR) ? getBirdYearStats(year - 1) : null;

    var minVal = Math.min.apply(null, yearData);
    var maxVal = Math.max.apply(null, yearData);
    if (Number.isFinite(minVal) && Number.isFinite(maxVal) && birdChart && birdChart.options && birdChart.options.scales && birdChart.options.scales.y) {
        var pad = Math.max(5, Math.round((maxVal - minVal) * 0.2));
        birdChart.options.scales.y.min = Math.max(0, Math.floor(minVal - pad));
        birdChart.options.scales.y.max = Math.ceil(maxVal + pad);
    }

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

function buildHistoricalSectionSummary(rows, selections, envRows) {
    var summary = {
        speciesTotal: 0,
        resident: 0,
        migrant: 0,
        tolerant: 0,
        sensitive: 0,
        envAverage: null,
        envLabel: null,
        envUnit: '',
        envDecimals: 1,
        siteCount: rows.length
    };

    rows.forEach(function(site) {
        summary.resident += toNumber(site.total_resident);
        summary.migrant += toNumber(site.total_migrant);
        summary.tolerant += toNumber(site.total_tolerant);
        summary.sensitive += toNumber(site.total_sensitive);
        summary.speciesTotal += toNumber(site.total_unique);
    });

    if (selections && selections.envType && Array.isArray(envRows) && envRows.length) {
        var envConfig = getHistoricalEnvConfig(selections.envType, selections.landTempPeriod);
        if (envConfig) {
            var envSum = 0;
            envRows.forEach(function(item) {
                envSum += toNumber(item.numericValue);
            });
            summary.envAverage = envSum / envRows.length;
            summary.envLabel = envConfig.label;
            summary.envUnit = envConfig.unit || '';
            summary.envDecimals = envConfig.decimals;
        }
    }

    return summary;
}

function fetchHistoricalRowsForSelections(selections) {
    var url = 'api/get_historical_data.php?year=' + selections.year + (selections.month > 0 ? '&month=' + selections.month : '');
    return fetch(url)
        .then(function(resp) { return resp.json(); })
        .then(function(data) {
            if (!data || !data.success || !Array.isArray(data.data)) {
                return [];
            }
            return data.data;
        })
        .catch(function() {
            return [];
        });
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

var yearSlider = document.getElementById('yearSlider');
var yearDisplay = document.getElementById('yearDisplay');
yearSlider.addEventListener('input', function() {
    var yr = parseInt(this.value);
    applyYearDrivenUpdates(yr);
});

document.getElementById('histYearSelect').value = String(DASHBOARD_MIN_YEAR);
document.getElementById('histMonthSelect').value = '0';
if (yearSlider) {
    yearSlider.value = String(riskSnapshotYear);
}

function loadBirdRichnessTrendFromDb() {
    fetch('api/get_dashboard_bird_trend.php', {
        method: 'GET',
        headers: { 'Accept': 'application/json' }
    })
        .then(function(resp) { return resp.json(); })
        .then(function(data) {
            if (!data || !data.success || !data.series) return;
            Object.keys(data.series).forEach(function(yearKey) {
                var yearNum = Number(yearKey);
                if (!Number.isInteger(yearNum)) return;
                if (yearNum < DASHBOARD_MIN_YEAR || yearNum > DASHBOARD_MAX_YEAR) return;
                var values = data.series[yearKey];
                if (!Array.isArray(values) || values.length !== 12) return;
                birdRichnessData[yearNum] = values.map(function(v) {
                    var n = Number(v);
                    return Number.isFinite(n) ? n : 0;
                });
            });

            applyYearDrivenUpdates(getSelectedDashboardYear());
        })
        .catch(function() {
            // Keep fallback trend values when API is unavailable.
        });
}

applyYearDrivenUpdates(getSelectedDashboardYear());
syncRiskSitePanelVisibility();
loadBirdRichnessTrendFromDb();
playRiskViewAnimation();
prepareRiskSidebarAutoReveal();
applyAvpStaggerReveal('#dashMapControls, #legendRiskZones, #legendHistorical', 80, 70);
</script>
SCRIPTS;

require_once 'includes/footer.php';
?>
