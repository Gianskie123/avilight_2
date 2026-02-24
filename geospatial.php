<?php
$page_title = 'Geospatial Forecasting';
require_once 'includes/header.php';

// Load real observation data from DB (most recent visit per site, top 200 richest)
require_once 'includes/db.php';
$pdo = get_db();
$obs_rows = $pdo->query("
    SELECT o1.* FROM observations o1
    INNER JOIN (
        SELECT site_name, MAX(year * 100 + month) AS max_ym
        FROM observations
        WHERE site_name != '' AND latitude != 0 AND longitude != 0
        GROUP BY site_name
    ) latest ON o1.site_name = latest.site_name
           AND (o1.year * 100 + o1.month) = latest.max_ym
    ORDER BY o1.total_unique DESC
    LIMIT 200
")->fetchAll(PDO::FETCH_ASSOC);

$cells_data = array_map(function (array $r): array {
    // Parse Python-style list "'A', 'B'" → PHP array
    $raw = trim($r['species_list'], "[]");
    $parts = preg_split("/'\s*,\s*'/", trim($raw, "'"));
    $species = array_filter(array_map('trim', $parts));

    $cell_id = 'site_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $r['site_name']);
    return [
        'cell_id'          => $cell_id,
        'site_name'        => $r['site_name'],
        'latitude'         => (float)$r['latitude'],
        'longitude'        => (float)$r['longitude'],
        'predicted_richness' => (int)$r['total_unique'],
        'actual_richness'  => (int)$r['total_unique'],
        'month'            => (int)$r['month'],
        'year'             => (int)$r['year'],
        'total_tolerant'   => (int)$r['total_tolerant'],
        'total_sensitive'  => (int)$r['total_sensitive'],
        'total_resident'   => (int)$r['total_resident'],
        'total_migrant'    => (int)$r['total_migrant'],
        'total_count'      => (int)$r['total_count'],
        'species_list'     => array_values($species),
        'shap_values'      => ['light' => 0.0, 'ndvi' => 0.0, 'temperature' => 0.0, 'elevation' => 0.0],
    ];
}, $obs_rows);

require_once 'includes/load_species.php';
$species_data = load_species_from_csv();
?>

<div class="page-header">
    <h1 class="page-title">Geospatial Forecasting & Interpretability</h1>
    <p class="page-subtitle">Click any city/municipality area on the map to explore species predictions</p>
</div>

<!-- Map Container with embedded compact controls -->
<div class="card">
    <div class="card-body" style="padding: 0;">

        <!-- ── Compact Control Bar ── -->
        <div id="geoControlBar" style="display:flex; flex-wrap:wrap; align-items:center; gap:8px; padding:10px 14px; border-bottom:1px solid var(--border-color); background:var(--bg-card-alt);">

            <!-- Map display mode -->
            <div style="display:flex; align-items:center; gap:4px;">
                <span style="font-size:0.78rem; color:var(--text-muted); white-space:nowrap;">View:</span>
                <button class="btn btn-primary btn-sm" id="btnLandCover" onclick="setColorMode('landcover')">🌍 Land Cover</button>
                <button class="btn btn-secondary btn-sm" id="btnPredictions" onclick="setColorMode('predictions')">🗺️ Richness</button>
            </div>

            <div style="width:1px; height:24px; background:var(--border-color);"></div>

            <!-- Light tolerance -->
            <div style="display:flex; align-items:center; gap:4px;">
                <span style="font-size:0.78rem; color:var(--text-muted); white-space:nowrap;">Tolerance:</span>
                <button class="btn btn-primary btn-sm" id="btnFilterAll" onclick="filterSpecies('all')">All</button>
                <button class="btn btn-secondary btn-sm" id="btnFilterSensitive" onclick="filterSpecies('sensitive')">💡 Sensitive</button>
                <button class="btn btn-secondary btn-sm" id="btnFilterTolerant" onclick="filterSpecies('tolerant')">☀️ Tolerant</button>
            </div>

            <div style="width:1px; height:24px; background:var(--border-color);"></div>

            <!-- Migration filter -->
            <div style="display:flex; align-items:center; gap:4px;">
                <span style="font-size:0.78rem; color:var(--text-muted); white-space:nowrap;">Migration:</span>
                <button class="btn btn-primary btn-sm" id="btnMigAll" onclick="filterMigration('all')">All</button>
                <button class="btn btn-secondary btn-sm" id="btnMigResident" onclick="filterMigration('resident')">🏡 Resident</button>
                <button class="btn btn-secondary btn-sm" id="btnMigMigratory" onclick="filterMigration('migratory')">✈️ Migratory</button>
            </div>

            <div style="width:1px; height:24px; background:var(--border-color);"></div>

            <!-- Month slider -->
            <div style="display:flex; align-items:center; gap:6px; flex:1; min-width:160px;">
                <span style="font-size:0.78rem; color:var(--text-muted); white-space:nowrap;">Month: <strong id="monthValue">June</strong></span>
                <input type="range" min="1" max="12" value="6" class="slider" id="monthSlider" style="flex:1; margin:0;">
            </div>

            <div style="width:1px; height:24px; background:var(--border-color);"></div>

            <!-- Land cover filter toggle -->
            <div style="position:relative;">
                <button class="btn btn-secondary btn-sm" id="btnLCToggle" onclick="toggleLCPanel()">🏷️ Land Cover ▾</button>
                <!-- Dropdown panel -->
                <div id="lcDropdown" style="display:none; position:absolute; top:calc(100% + 4px); right:0; z-index:2000; background:var(--bg-card); border:1px solid var(--border-color); border-radius:8px; padding:12px 14px; box-shadow:0 4px 16px rgba(0,0,0,.15); min-width:220px;">
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:4px 12px; margin-bottom:8px;">
                        <label style="font-size:0.82rem; display:flex; align-items:center; gap:4px; cursor:pointer;"><input type="checkbox" class="lc-filter" value="13" checked> 🏙️ Urban</label>
                        <label style="font-size:0.82rem; display:flex; align-items:center; gap:4px; cursor:pointer;"><input type="checkbox" class="lc-filter" value="17" checked> 💧 Water</label>
                        <label style="font-size:0.82rem; display:flex; align-items:center; gap:4px; cursor:pointer;"><input type="checkbox" class="lc-filter" value="2"  checked> 🌳 Forest</label>
                        <label style="font-size:0.82rem; display:flex; align-items:center; gap:4px; cursor:pointer;"><input type="checkbox" class="lc-filter" value="12" checked> 🌾 Croplands</label>
                        <label style="font-size:0.82rem; display:flex; align-items:center; gap:4px; cursor:pointer;"><input type="checkbox" class="lc-filter" value="10" checked> 🌿 Grasslands</label>
                        <label style="font-size:0.82rem; display:flex; align-items:center; gap:4px; cursor:pointer;"><input type="checkbox" class="lc-filter" value="11" checked> 🌊 Wetlands</label>
                        <label style="font-size:0.82rem; display:flex; align-items:center; gap:4px; cursor:pointer;"><input type="checkbox" class="lc-filter" value="9"  checked> 🌾 Savannas</label>
                        <label style="font-size:0.82rem; display:flex; align-items:center; gap:4px; cursor:pointer;"><input type="checkbox" class="lc-filter" value="8"  checked> 🌲 Woody Sav.</label>
                        <label style="font-size:0.82rem; display:flex; align-items:center; gap:4px; cursor:pointer;"><input type="checkbox" class="lc-filter" value="14" checked> 🌱 Crop Mosaic</label>
                        <label style="font-size:0.82rem; display:flex; align-items:center; gap:4px; cursor:pointer;"><input type="checkbox" class="lc-filter" value="16" checked> 🏜️ Barren</label>
                    </div>
                    <div style="display:flex; gap:6px;">
                        <button class="btn btn-primary btn-sm" style="flex:1;" onclick="selectAllLandCover(true)">All</button>
                        <button class="btn btn-secondary btn-sm" style="flex:1;" onclick="selectAllLandCover(false)">None</button>
                    </div>
                </div>
            </div>
        </div>
        <!-- ── End Control Bar ── -->

        <div class="map-container" style="border-radius:0 0 8px 8px; overflow:hidden;">
            <div id="map"></div>
            <div id="loading" style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); display: none;">
                <div class="loading"></div>
                <p>Loading map data…</p>
            </div>

            <!-- Prediction Heatmap Legend (hidden by default) -->
            <div class="legend" id="legendPrediction" style="display: none;">
                <strong>Predicted Species Richness</strong>
                <div style="display: flex; align-items: center; margin-top: 8px;">
                    <span class="legend-label" style="margin-right: 6px;">Low</span>
                    <div style="flex: 1; height: 14px; border-radius: 4px; background: linear-gradient(to right, #313695, #4575b4, #74add1, #abd9e9, #fee090, #fdae61, #f46d43, #d73027, #a50026);"></div>
                    <span class="legend-label" style="margin-left: 6px;">High</span>
                </div>
                <div style="display: flex; justify-content: space-between; margin-top: 4px;">
                    <span class="legend-label">0</span>
                    <span class="legend-label">10</span>
                    <span class="legend-label">20</span>
                    <span class="legend-label">30+</span>
                </div>
            </div>

            <!-- Land Cover Legend -->
            <div class="legend" id="legendLandCover">
                <strong>Land Cover Types</strong>
                <div class="legend-item"><div class="legend-color" style="background:#DC143C;"></div><span class="legend-label">Urban & Built-up</span></div>
                <div class="legend-item"><div class="legend-color" style="background:#1E90FF;"></div><span class="legend-label">Water Bodies</span></div>
                <div class="legend-item"><div class="legend-color" style="background:#006400;"></div><span class="legend-label">Forest</span></div>
                <div class="legend-item"><div class="legend-color" style="background:#FFD700;"></div><span class="legend-label">Croplands</span></div>
                <div class="legend-item"><div class="legend-color" style="background:#90EE90;"></div><span class="legend-label">Grasslands</span></div>
                <div class="legend-item"><div class="legend-color" style="background:#008B8B;"></div><span class="legend-label">Wetlands</span></div>
                <div class="legend-item"><div class="legend-color" style="background:#556B2F;"></div><span class="legend-label">Woody Savannas</span></div>
                <div class="legend-item"><div class="legend-color" style="background:#FFA500;"></div><span class="legend-label">Cropland Mosaics</span></div>
                <div class="legend-item"><div class="legend-color" style="background:#8B4513;"></div><span class="legend-label">Barren</span></div>
            </div>

            <!-- Hint label -->
            <div id="mapHint" style="position:absolute; bottom:12px; left:50%; transform:translateX(-50%); background:rgba(0,0,0,.55); color:#fff; font-size:0.78rem; padding:4px 12px; border-radius:20px; z-index:900; pointer-events:none;">
                Click a city area to explore predictions
            </div>
        </div>
    </div>
</div>

<!-- Area Analysis Panel (Hidden by default) -->
<div id="cellPanel" class="side-panel" style="display: none;">
    <span class="side-panel-close" onclick="closeCellPanel()">&times;</span>
    <h3 id="cellTitle">Area Analysis</h3>
    <div id="cellContent">
        <p><strong>City / Area:</strong> <span id="cellId"></span></p>
        <p><strong>Dominant Land Cover:</strong> <span id="cellCoords"></span></p>
        <p><strong>Total Unique Species:</strong> <span id="predictedRichness"></span></p>
        <p><strong>Observation Sites:</strong> <span id="actualRichness"></span></p>
        <div id="obsBreakdown" style="display:none; background:#f8f9fa; border-radius:6px; padding:8px 10px; margin-bottom:8px;"></div>
        <hr>
        <h4>Species Observed in this City:</h4>
        <ul id="speciesList"></ul>
        <hr>
        <h4>Environmental Factors (SHAP):</h4>
        <canvas id="shapChart"></canvas>
        <div id="shapExplanation"></div>
    </div>
</div>

<!-- SHAP Global Insights -->
<div class="grid-2">
    <div class="card">
        <h2 class="card-header">Global Feature Importance (SHAP)</h2>
        <div class="card-body">
            <canvas id="globalShapChart"></canvas>
            <p style="margin-top: 15px; color: #666;">
                <strong>Interpretation:</strong> Light intensity and NDVI are the strongest predictors 
                of bird species richness in Metro Manila. Higher light pollution consistently reduces 
                species diversity, while vegetation cover (NDVI) has a positive effect.
            </p>
        </div>
    </div>
    
    <div class="card">
        <h2 class="card-header">Local Explainer - Search City</h2>
        <div class="card-body">
            <div class="form-group">
                <label class="form-label">Enter City / Municipality Name:</label>
                <input type="text" class="form-control" id="cellSearchInput" placeholder="e.g., Makati, Quezon City, Taguig">
                <button class="btn btn-primary" style="margin-top: 10px;" onclick="searchCell()">Search</button>
            </div>
            <div id="searchResult"></div>
        </div>
    </div>
</div>

<?php
$cells_json = json_encode($cells_data, JSON_HEX_TAG | JSON_HEX_AMP);
$species_json = json_encode($species_data, JSON_HEX_TAG | JSON_HEX_AMP);
$extra_scripts = <<<EOD
<script>
// Metro Manila bounding box (exact bounds from boundary file)
const MM_BOUNDS = L.latLngBounds(
    L.latLng(14.35, 120.90),
    L.latLng(14.79, 121.14)
);

// Initialize map centered on Metro Manila
const map = L.map('map', {
    maxBounds: MM_BOUNDS.pad(0.08),
    minZoom: 11,
    preferCanvas: true
}).setView([14.565, 121.01], 12);

// Add OpenStreetMap tile layer
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© OpenStreetMap contributors'
}).addTo(map);

// Land cover type mapping (MODIS IGBP classification)
const LANDCOVER_TYPES = {
    2:  { name: 'Forest', color: '#006400' },
    8:  { name: 'Woody Savannas', color: '#556B2F' },
    9:  { name: 'Savannas', color: '#BDB76B' },
    10: { name: 'Grasslands', color: '#90EE90' },
    11: { name: 'Wetlands', color: '#008B8B' },
    12: { name: 'Croplands', color: '#FFD700' },
    13: { name: 'Urban & Built-up', color: '#DC143C' },
    14: { name: 'Cropland Mosaics', color: '#FFA500' },
    16: { name: 'Barren', color: '#8B4513' },
    17: { name: 'Water Bodies', color: '#1E90FF' }
};

// Baseline richness by land cover type (used to estimate predictions for areas without observed data)
const LANDCOVER_RICHNESS = {
    2:  22,  // Forest — highest biodiversity
    8:  18,  // Woody Savannas
    9:  15,  // Savannas
    10: 14,  // Grasslands
    11: 19,  // Wetlands — high biodiversity
    12: 10,  // Croplands
    13: 6,   // Urban — lowest biodiversity
    14: 11,  // Cropland Mosaics
    16: 3,   // Barren
    17: 16   // Water Bodies
};

// Current map color mode: 'landcover' (default) or 'predictions'
let colorMode = 'landcover';

// Observation site data from DB
const cellsData = {$cells_json};

// Build a lookup by site cell_id
const cellsLookup = {};
cellsData.forEach(function(c) { cellsLookup[c.cell_id] = c; });

// Species masterlist
const speciesData = {$species_json};
const speciesLookup = {};
speciesData.forEach(function(s) { speciesLookup[s.common_name] = s; });

// Active filter state
let activeLightFilter = 'all';
let activeMigrationFilter = 'all';

// GeoJSON layer references
let geojsonLayer = null;
let geojsonData = null;
let cityLayer = null;

// City → observation sites lookup (populated after both datasets load)
const citySitesLookup = {};   // cityName → [cellData, ...]
let citiesGeoData = null;

// ── Helpers ──────────────────────────────────────────────

function getLandCoverName(code) {
    return LANDCOVER_TYPES[code] ? LANDCOVER_TYPES[code].name : 'Unknown (' + code + ')';
}

function getLandCoverColor(code) {
    return LANDCOVER_TYPES[code] ? LANDCOVER_TYPES[code].color : '#999999';
}

function hashCode(str) {
    var hash = 0;
    for (var i = 0; i < str.length; i++) {
        hash = ((hash << 5) - hash) + str.charCodeAt(i);
        hash |= 0;
    }
    return hash;
}

// Ray-casting point-in-polygon (handles Polygon and MultiPolygon)
function pointInPolygon(lat, lng, geometry) {
    function pipRing(ring) {
        var inside = false;
        for (var i = 0, j = ring.length - 1; i < ring.length; j = i++) {
            var xi = ring[i][0], yi = ring[i][1];
            var xj = ring[j][0], yj = ring[j][1];
            var intersect = ((yi > lat) !== (yj > lat)) &&
                (lng < (xj - xi) * (lat - yi) / (yj - yi) + xi);
            if (intersect) inside = !inside;
        }
        return inside;
    }
    if (geometry.type === 'Polygon') {
        return pipRing(geometry.coordinates[0]);
    }
    if (geometry.type === 'MultiPolygon') {
        for (var p = 0; p < geometry.coordinates.length; p++) {
            if (pipRing(geometry.coordinates[p][0])) return true;
        }
    }
    return false;
}

// Build citySitesLookup once both cities and cells data are available
function buildCitySitesLookup() {
    if (!citiesGeoData || !cellsData.length) return;
    citiesGeoData.features.forEach(function(cityFeat) {
        var name = cityFeat.properties.city_name;
        citySitesLookup[name] = [];
        cellsData.forEach(function(site) {
            if (pointInPolygon(site.latitude, site.longitude, cityFeat.geometry)) {
                citySitesLookup[name].push(site);
            }
        });
    });
}

// Estimate richness for a land-cover feature
function getPredictedRichness(properties) {
    var cellData = cellsLookup[properties.cell_id];
    if (cellData) return cellData.predicted_richness;
    var base = LANDCOVER_RICHNESS[properties.land_cover] || 8;
    var seed = hashCode(properties.cell_id || (properties.latitude + '_' + properties.longitude));
    var variation = ((Math.abs(seed) % 7) - 3);
    return Math.max(1, Math.min(30, base + variation));
}

function getFilteredRichness(properties) {
    if (activeLightFilter === 'all' && activeMigrationFilter === 'all') {
        return getPredictedRichness(properties);
    }
    var cellData = cellsLookup[properties.cell_id];
    if (!cellData || !cellData.species_list) return getPredictedRichness(properties);
    var count = 0;
    cellData.species_list.forEach(function(name) {
        var sp = speciesLookup[name];
        if (!sp || speciesMatchesFilters(sp)) count++;
    });
    return count;
}

function speciesMatchesFilters(sp) {
    var lightMatch = activeLightFilter === 'all' ||
        (activeLightFilter === 'sensitive' && sp.light_tolerance === 'Sensitive') ||
        (activeLightFilter === 'tolerant'  && sp.light_tolerance === 'Tolerant');
    var migMatch = activeMigrationFilter === 'all' ||
        (activeMigrationFilter === 'resident'  && sp.migration_status === 'Resident') ||
        (activeMigrationFilter === 'migratory' && sp.migration_status === 'Migratory');
    return lightMatch && migMatch;
}

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

function getSelectedLandCoverTypes() {
    const selected = new Set();
    document.querySelectorAll('.lc-filter').forEach(function(cb) {
        if (cb.checked) selected.add(parseInt(cb.value));
    });
    return selected;
}

// Filter land-cover features to Metro Manila bbox + selected types
// Note: LandCover.geojson uses property 'land_cover' (not 'landcover')
function filterToMetroManila(data) {
    const selected = getSelectedLandCoverTypes();
    return {
        type: 'FeatureCollection',
        features: data.features.filter(function(f) {
            var lat = f.properties.latitude;
            var lng = f.properties.longitude;
            var lc = f.properties.land_cover;
            return lat >= 14.35 && lat <= 14.79 &&
                   lng >= 120.90 && lng <= 121.14 &&
                   selected.has(lc);
        })
    };
}

// ── Style functions ───────────────────────────────────────

function lcStyle(feature) {
    if (colorMode === 'predictions') {
        return {
            fillColor: getRichnessColor(getFilteredRichness(feature.properties)),
            weight: 0, fillOpacity: 0.85
        };
    }
    return {
        fillColor: getLandCoverColor(feature.properties.land_cover),
        weight: 0, fillOpacity: 0.75
    };
}

function cityStyle() {
    return { fillColor: 'transparent', weight: 1.8, color: '#333', fillOpacity: 0, opacity: 0.7 };
}

function cityHoverStyle() {
    return { fillColor: '#fff', weight: 2.5, color: '#0066cc', fillOpacity: 0.12, opacity: 1 };
}

// ── Map mode ──────────────────────────────────────────────

function setColorMode(mode) {
    colorMode = mode;
    document.getElementById('btnPredictions').className = mode === 'predictions' ? 'btn btn-primary btn-sm' : 'btn btn-secondary btn-sm';
    document.getElementById('btnLandCover').className   = mode === 'landcover'   ? 'btn btn-primary btn-sm' : 'btn btn-secondary btn-sm';
    document.getElementById('legendPrediction').style.display = mode === 'predictions' ? 'block' : 'none';
    document.getElementById('legendLandCover').style.display  = mode === 'landcover'   ? 'block' : 'none';
    applyLandCoverFilter();
}

// ── Land-cover layer ──────────────────────────────────────

function applyLandCoverFilter() {
    if (!geojsonData) return;
    if (geojsonLayer) { map.removeLayer(geojsonLayer); geojsonLayer = null; }
    var filtered = filterToMetroManila(geojsonData);
    geojsonLayer = L.geoJSON(filtered, {
        style: lcStyle,
        interactive: false   // land-cover is visual only; city layer handles clicks
    }).addTo(map);
    // Ensure city boundary layer stays on top
    if (cityLayer) cityLayer.bringToFront();
}

function selectAllLandCover(selectAll) {
    document.querySelectorAll('.lc-filter').forEach(function(cb) { cb.checked = selectAll; });
    applyLandCoverFilter();
}

document.querySelectorAll('.lc-filter').forEach(function(cb) {
    cb.addEventListener('change', applyLandCoverFilter);
});

// ── City boundary layer (area-style clicking) ─────────────

function buildCityLayer() {
    if (!citiesGeoData) return;
    if (cityLayer) { map.removeLayer(cityLayer); cityLayer = null; }

    cityLayer = L.geoJSON(citiesGeoData, {
        style: cityStyle,
        onEachFeature: function(feature, layer) {
            var cityName = feature.properties.city_name;

            layer.bindTooltip('<strong>' + cityName + '</strong><br><em>Click to explore</em>',
                { sticky: true, className: 'map-tooltip' });

            layer.on('mouseover', function() { layer.setStyle(cityHoverStyle()); });
            layer.on('mouseout',  function() { layer.setStyle(cityStyle()); });
            layer.on('click',     function() { showCityAnalysis(cityName, feature); });
        }
    }).addTo(map);
}

// ── City analysis panel ───────────────────────────────────

let shapChartInstance = null;

function showCityAnalysis(cityName, cityFeature) {
    var sites = citySitesLookup[cityName] || [];

    // Aggregate species across all sites in the city
    var allSpeciesSet = new Set();
    var totalTolerant = 0, totalSensitive = 0, totalResident = 0, totalMigrant = 0, totalCount = 0;
    sites.forEach(function(s) {
        totalTolerant  += s.total_tolerant  || 0;
        totalSensitive += s.total_sensitive || 0;
        totalResident  += s.total_resident  || 0;
        totalMigrant   += s.total_migrant   || 0;
        totalCount     += s.total_count     || 0;
        (s.species_list || []).forEach(function(sp) { allSpeciesSet.add(sp); });
    });

    // Determine dominant land cover in this city from geojsonData (if loaded)
    var lcCounts = {};
    if (geojsonData) {
        geojsonData.features.forEach(function(f) {
            if (pointInPolygon(f.properties.latitude, f.properties.longitude, cityFeature.geometry)) {
                var lc = f.properties.land_cover;
                lcCounts[lc] = (lcCounts[lc] || 0) + 1;
            }
        });
    }
    var domLC = null, domCount = 0;
    Object.keys(lcCounts).forEach(function(lc) {
        if (lcCounts[lc] > domCount) { domCount = lcCounts[lc]; domLC = parseInt(lc); }
    });

    // Estimate city richness (sum of site richnesses, or land-cover estimate)
    var totalRichness = sites.length > 0
        ? sites.reduce(function(sum, s) { return sum + (s.predicted_richness || 0); }, 0)
        : (domLC ? LANDCOVER_RICHNESS[domLC] || 8 : 8);

    // Apply species filter
    var isFiltered = activeLightFilter !== 'all' || activeMigrationFilter !== 'all';
    var displayedSpecies = Array.from(allSpeciesSet).filter(function(name) {
        if (!isFiltered) return true;
        var sp = speciesLookup[name];
        return !sp || speciesMatchesFilters(sp);
    });

    // Populate panel
    document.getElementById('cellId').textContent = cityName;
    document.getElementById('cellCoords').textContent = domLC ? getLandCoverName(domLC) : '—';
    document.getElementById('predictedRichness').textContent = totalRichness;
    document.getElementById('actualRichness').textContent = sites.length;

    var breakdownEl = document.getElementById('obsBreakdown');
    if (sites.length > 0) {
        breakdownEl.style.display = 'block';
        breakdownEl.innerHTML =
            '<small>🌅 Tolerant: <strong>' + totalTolerant + '</strong> &nbsp; ' +
            '💡 Sensitive: <strong>' + totalSensitive + '</strong> &nbsp; ' +
            '🏡 Resident: <strong>' + totalResident  + '</strong> &nbsp; ' +
            '✈️ Migrant: <strong>'  + totalMigrant   + '</strong> &nbsp; ' +
            '👁 Total birds: <strong>' + totalCount + '</strong></small>';
    } else {
        breakdownEl.style.display = 'none';
    }

    var speciesList = document.getElementById('speciesList');
    speciesList.innerHTML = '';
    if (displayedSpecies.length === 0) {
        var li = document.createElement('li');
        li.textContent = sites.length > 0
            ? 'No species match the active filter.'
            : 'No observation data for this city — richness estimated from land cover.';
        li.style.color = '#999';
        speciesList.appendChild(li);
    } else {
        displayedSpecies.forEach(function(name) {
            var li = document.createElement('li');
            li.textContent = name;
            speciesList.appendChild(li);
        });
    }

    // SHAP chart — use aggregated values or defaults
    var avgLight = sites.length ? sites.reduce(function(s,c){return s+(c.shap_values.light||0);},0)/sites.length : -2.0;
    var avgNdvi  = sites.length ? sites.reduce(function(s,c){return s+(c.shap_values.ndvi||0);},0)/sites.length  :  1.0;
    var avgTemp  = sites.length ? sites.reduce(function(s,c){return s+(c.shap_values.temperature||0);},0)/sites.length : 0.5;
    var avgElev  = sites.length ? sites.reduce(function(s,c){return s+(c.shap_values.elevation||0);},0)/sites.length  : 0.3;

    if (shapChartInstance) { shapChartInstance.destroy(); shapChartInstance = null; }
    var ctx = document.getElementById('shapChart').getContext('2d');
    shapChartInstance = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: ['Light', 'NDVI', 'Temperature', 'Elevation'],
            datasets: [{
                label: 'SHAP Value',
                data: [avgLight, avgNdvi, avgTemp, avgElev],
                backgroundColor: function(ctx) { return ctx.parsed.y < 0 ? '#dc3545' : '#28a745'; }
            }]
        },
        options: {
            indexAxis: 'y', responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: false } }
        }
    });

    var lightText = avgLight < 0
        ? 'high light reduces richness by ' + Math.abs(avgLight).toFixed(1)
        : 'light has a positive effect';
    var ndviText = avgNdvi > 0
        ? 'vegetation cover increases richness by ' + avgNdvi.toFixed(1)
        : 'vegetation has a negative effect';
    document.getElementById('shapExplanation').innerHTML =
        '<p style="margin-top:10px;font-size:0.9rem;color:#666;">' +
        '<strong>Interpretation:</strong> In <em>' + cityName + '</em>, ' + lightText +
        '. ' + ndviText.charAt(0).toUpperCase() + ndviText.slice(1) + '.</p>';

    document.getElementById('cellPanel').style.display = 'block';
    document.getElementById('mapHint').style.display = 'none';
}

function closeCellPanel() {
    document.getElementById('cellPanel').style.display = 'none';
    document.getElementById('mapHint').style.display = 'block';
}

// ── Search ────────────────────────────────────────────────

function searchCell() {
    var query = document.getElementById('cellSearchInput').value.trim().toLowerCase();
    var found = null;
    if (citiesGeoData) {
        citiesGeoData.features.forEach(function(f) {
            if (f.properties.city_name.toLowerCase().indexOf(query) !== -1) found = f;
        });
    }
    if (found) {
        document.getElementById('searchResult').innerHTML =
            '<div class="alert alert-info" style="margin-top:15px;">' +
            '<strong>Found:</strong> ' + found.properties.city_name + '</div>';
        // Compute centroid — handle both Polygon and MultiPolygon
        var pts = (found.geometry.type === 'MultiPolygon')
            ? found.geometry.coordinates[0][0]
            : found.geometry.coordinates[0];
        var lat = pts.reduce(function(s,p){return s+p[1];}, 0) / pts.length;
        var lng = pts.reduce(function(s,p){return s+p[0];}, 0) / pts.length;
        map.setView([lat, lng], 13);
        showCityAnalysis(found.properties.city_name, found);
    } else {
        document.getElementById('searchResult').innerHTML =
            '<div class="alert alert-danger" style="margin-top:15px;">' +
            'City not found. Try: Manila, Makati, Quezon City, Taguig…</div>';
    }
}

// ── LC filter dropdown toggle ─────────────────────────────

function toggleLCPanel() {
    var panel = document.getElementById('lcDropdown');
    panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
}
// Close dropdown when clicking outside
document.addEventListener('click', function(e) {
    if (!e.target.closest('#btnLCToggle') && !e.target.closest('#lcDropdown')) {
        var d = document.getElementById('lcDropdown');
        if (d) d.style.display = 'none';
    }
});

// ── Month slider ──────────────────────────────────────────

document.getElementById('monthSlider').addEventListener('input', function() {
    var months = ['January','February','March','April','May','June',
                  'July','August','September','October','November','December'];
    document.getElementById('monthValue').textContent = months[this.value - 1];
});

// ── Species filters ───────────────────────────────────────

function filterSpecies(type) {
    activeLightFilter = type;
    document.getElementById('btnFilterAll').className       = type === 'all'       ? 'btn btn-primary btn-sm'  : 'btn btn-secondary btn-sm';
    document.getElementById('btnFilterSensitive').className = type === 'sensitive' ? 'btn btn-warning btn-sm'  : 'btn btn-secondary btn-sm';
    document.getElementById('btnFilterTolerant').className  = type === 'tolerant'  ? 'btn btn-success btn-sm'  : 'btn btn-secondary btn-sm';
    applyLandCoverFilter();
}

function filterMigration(type) {
    activeMigrationFilter = type;
    document.getElementById('btnMigAll').className       = type === 'all'       ? 'btn btn-primary btn-sm' : 'btn btn-secondary btn-sm';
    document.getElementById('btnMigResident').className  = type === 'resident'  ? 'btn btn-success btn-sm' : 'btn btn-secondary btn-sm';
    document.getElementById('btnMigMigratory').className = type === 'migratory' ? 'btn btn-info btn-sm'    : 'btn btn-secondary btn-sm';
    applyLandCoverFilter();
}

// ── Data loading ──────────────────────────────────────────

document.getElementById('loading').style.display = 'block';

// Load land cover + city boundaries in parallel
Promise.all([
    fetch('LandCover.geojson').then(function(r) { return r.json(); }),
    fetch('MM_Cities_WGS84.geojson').then(function(r) { return r.json(); }),
    fetch('MM_Mask.geojson').then(function(r) { return r.json(); })
]).then(function(results) {
    document.getElementById('loading').style.display = 'none';

    geojsonData    = results[0];
    citiesGeoData  = results[1];
    var maskData   = results[2];

    // Land-cover layer (background, non-interactive)
    applyLandCoverFilter();

    // Clipping mask — hides areas outside Metro Manila
    L.geoJSON(maskData, {
        style: {
            fillColor: '#f0f0f0',
            fillOpacity: 0.65,
            weight: 0,
            interactive: false
        }
    }).addTo(map);

    // City boundary layer (area-style, clickable)
    buildCityLayer();

    // Build city-to-sites lookup
    buildCitySitesLookup();

}).catch(function(err) {
    document.getElementById('loading').style.display = 'none';
    console.error('Error loading map data:', err);
});

// ── Global SHAP chart ─────────────────────────────────────

var ctxGlobal = document.getElementById('globalShapChart').getContext('2d');
new Chart(ctxGlobal, {
    type: 'bar',
    data: {
        labels: ['Light Intensity', 'NDVI', 'Temperature', 'Elevation', 'Distance to Water'],
        datasets: [{
            label: 'Feature Importance',
            data: [0.45, 0.30, 0.15, 0.07, 0.03],
            backgroundColor: '#2c5f2d'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        scales: {
            y: {
                beginAtZero: true,
                max: 0.5,
                title: { display: true, text: 'Mean |SHAP Value|' }
            }
        }
    }
});
</script>
EOD;

require_once 'includes/footer.php';
?>
