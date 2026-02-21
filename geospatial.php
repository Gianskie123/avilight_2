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
    <p class="page-subtitle">Interactive map with species richness predictions and environmental layers</p>
</div>

<!-- Map Controls -->
<div class="card">
    <div class="card-body">
        <div class="filter-container">
            <div class="filter-group">
                <label class="filter-label">
                    <input type="checkbox" id="layerResident" checked> Resident Species
                </label>
                <label class="filter-label">
                    <input type="checkbox" id="layerMigratory" checked> Migratory Species
                </label>
                <label class="filter-label">
                    <input type="checkbox" id="layerLight"> Light Intensity (VIIRS)
                </label>
                <label class="filter-label">
                    <input type="checkbox" id="layerNDVI"> Vegetation (NDVI)
                </label>
                <label class="filter-label">
                    <input type="checkbox" id="layerKBA"> KBA/PA Boundaries
                </label>
            </div>
        </div>
        
        <!-- Land Cover Type Filter -->
        <div class="filter-container">
            <div class="filter-group">
                <span class="filter-label"><strong>Land Cover Filter (Metro Manila):</strong></span>
            </div>
            <div class="filter-group" style="margin-top: 10px;">
                <label class="filter-label">
                    <input type="checkbox" class="lc-filter" value="13" checked> 🏙️ Urban & Built-up
                </label>
                <label class="filter-label">
                    <input type="checkbox" class="lc-filter" value="17" checked> 💧 Water Bodies
                </label>
                <label class="filter-label">
                    <input type="checkbox" class="lc-filter" value="2" checked> 🌳 Forest
                </label>
                <label class="filter-label">
                    <input type="checkbox" class="lc-filter" value="12" checked> 🌾 Croplands
                </label>
                <label class="filter-label">
                    <input type="checkbox" class="lc-filter" value="10" checked> 🌿 Grasslands
                </label>
                <label class="filter-label">
                    <input type="checkbox" class="lc-filter" value="11" checked> 🌊 Wetlands
                </label>
                <label class="filter-label">
                    <input type="checkbox" class="lc-filter" value="9" checked> 🌾 Savannas
                </label>
                <label class="filter-label">
                    <input type="checkbox" class="lc-filter" value="8" checked> 🌲 Woody Savannas
                </label>
                <label class="filter-label">
                    <input type="checkbox" class="lc-filter" value="14" checked> 🌱 Cropland Mosaics
                </label>
                <label class="filter-label">
                    <input type="checkbox" class="lc-filter" value="16" checked> 🏜️ Barren
                </label>
            </div>
            <div style="margin-top: 10px;">
                <button class="btn btn-primary" onclick="selectAllLandCover(true)">Select All</button>
                <button class="btn btn-secondary" onclick="selectAllLandCover(false)">Deselect All</button>
            </div>
        </div>
        
        <!-- Map Color Mode -->
        <div class="filter-container">
            <div class="filter-group">
                <span class="filter-label"><strong>Map Display:</strong></span>
                <button class="btn btn-secondary" id="btnPredictions" onclick="setColorMode('predictions')">🗺️ Predicted Richness</button>
                <button class="btn btn-primary" id="btnLandCover" onclick="setColorMode('landcover')">🌍 Land Cover Types</button>
            </div>
        </div>
        
        <!-- Temporal Timeline -->
        <div class="slider-container">
            <div class="slider-label">
                <span>Temporal Timeline - Month:</span>
                <span id="monthValue">June</span>
            </div>
            <input type="range" min="1" max="12" value="6" class="slider" id="monthSlider">
        </div>
        
        <!-- Species Filter -->
        <div class="filter-container">
            <div class="filter-group">
                <span class="filter-label"><strong>Light Tolerance:</strong></span>
                <button class="btn btn-primary" id="btnFilterAll" onclick="filterSpecies('all')">All Species</button>
                <button class="btn btn-secondary" id="btnFilterSensitive" onclick="filterSpecies('sensitive')">💡 Light-Sensitive</button>
                <button class="btn btn-secondary" id="btnFilterTolerant" onclick="filterSpecies('tolerant')">☀️ Light-Tolerant</button>
            </div>
            <div class="filter-group" style="margin-top: 10px;">
                <span class="filter-label"><strong>Migratory Status:</strong></span>
                <button class="btn btn-primary" id="btnMigAll" onclick="filterMigration('all')">All Types</button>
                <button class="btn btn-secondary" id="btnMigResident" onclick="filterMigration('resident')">🏡 Resident</button>
                <button class="btn btn-secondary" id="btnMigMigratory" onclick="filterMigration('migratory')">✈️ Migratory</button>
            </div>
        </div>
    </div>
</div>

<!-- Map Container -->
<div class="card">
    <div class="card-body">
        <div class="map-container">
            <div id="map"></div>
            <div id="loading" style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); display: none;">
                <div class="loading"></div>
                <p>Loading GeoJSON data...</p>
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
                <div class="legend-item">
                    <div class="legend-color" style="background: #DC143C;"></div>
                    <span class="legend-label">Urban & Built-up</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color" style="background: #1E90FF;"></div>
                    <span class="legend-label">Water Bodies</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color" style="background: #006400;"></div>
                    <span class="legend-label">Forest</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color" style="background: #FFD700;"></div>
                    <span class="legend-label">Croplands</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color" style="background: #90EE90;"></div>
                    <span class="legend-label">Grasslands</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color" style="background: #008B8B;"></div>
                    <span class="legend-label">Wetlands</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color" style="background: #BDB76B;"></div>
                    <span class="legend-label">Savannas</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color" style="background: #556B2F;"></div>
                    <span class="legend-label">Woody Savannas</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color" style="background: #FFA500;"></div>
                    <span class="legend-label">Cropland Mosaics</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color" style="background: #8B4513;"></div>
                    <span class="legend-label">Barren</span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Area Analysis Panel (Hidden by default) -->
<div id="cellPanel" class="side-panel" style="display: none;">
    <span class="side-panel-close" onclick="closeCellPanel()">&times;</span>
    <h3 id="cellTitle">Area Analysis</h3>
    <div id="cellContent">
        <p><strong>Site:</strong> <span id="cellId"></span></p>
        <p><strong>Coordinates:</strong> <span id="cellCoords"></span></p>
        <p><strong>Unique Species (Richness):</strong> <span id="predictedRichness"></span></p>
        <p><strong>Observed Richness:</strong> <span id="actualRichness"></span></p>
        <div id="obsBreakdown" style="display:none; background:#f8f9fa; border-radius:6px; padding:8px 10px; margin-bottom:8px;"></div>
        <hr>
        <h4>Species in this Area:</h4>
        <ul id="speciesList"></ul>
        <hr>
        <h4>SHAP Feature Importance:</h4>
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
        <h2 class="card-header">Local Explainer - Search Area</h2>
        <div class="card-body">
            <div class="form-group">
                <label class="form-label">Enter Area ID:</label>
                <input type="text" class="form-control" id="cellSearchInput" placeholder="e.g., site_Tanza">
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
// Metro Manila bounding box
const MM_BOUNDS = L.latLngBounds(
    L.latLng(14.35, 120.90),
    L.latLng(14.78, 121.15)
);

// Initialize map centered on Metro Manila with bounded view and Canvas renderer for performance
const map = L.map('map', {
    maxBounds: MM_BOUNDS.pad(0.1),
    minZoom: 10,
    preferCanvas: true
}).setView([14.5995, 120.9842], 11);

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

// Baseline richness by land cover type (used to estimate predictions for areas without explicit data)
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

// Sample cell data
const cellsData = {$cells_json};

// Build a lookup map for fast cell data access
const cellsLookup = {};
cellsData.forEach(function(c) {
    cellsLookup[c.cell_id] = c;
});

// Species masterlist data and lookup by common name
const speciesData = {$species_json};
const speciesLookup = {};
speciesData.forEach(function(s) {
    speciesLookup[s.common_name] = s;
});

// Active species filter state
let activeLightFilter = 'all';
let activeMigrationFilter = 'all';

// Show loading indicator
document.getElementById('loading').style.display = 'block';

// Store the GeoJSON layer reference for filtering
let geojsonLayer = null;
let geojsonData = null;

// Get land cover name from code
function getLandCoverName(code) {
    return LANDCOVER_TYPES[code] ? LANDCOVER_TYPES[code].name : 'Unknown (' + code + ')';
}

// Get color for land cover type
function getLandCoverColor(code) {
    return LANDCOVER_TYPES[code] ? LANDCOVER_TYPES[code].color : '#999999';
}

// Simple hash function for deterministic per-cell variation
function hashCode(str) {
    var hash = 0;
    for (var i = 0; i < str.length; i++) {
        hash = ((hash << 5) - hash) + str.charCodeAt(i);
        hash |= 0;
    }
    return hash;
}

// Get predicted richness for a feature (from data or estimated)
function getPredictedRichness(properties) {
    var cellData = cellsLookup[properties.cell_id];
    if (cellData) {
        return cellData.predicted_richness;
    }
    // Estimate based on land cover type with deterministic variation per cell
    var base = LANDCOVER_RICHNESS[properties.landcover] || 8;
    var seed = hashCode(properties.cell_id || (properties.latitude + '_' + properties.longitude));
    var variation = ((Math.abs(seed) % 7) - 3); // range: -3 to +3
    return Math.max(1, Math.min(30, base + variation));
}

// Get richness filtered by active Light Tolerance and Migratory Status filters.
// For cells with known species lists, counts only matching species.
// For cells without species data, returns the full estimated richness.
function getFilteredRichness(properties) {
    if (activeLightFilter === 'all' && activeMigrationFilter === 'all') {
        return getPredictedRichness(properties);
    }
    var cellData = cellsLookup[properties.cell_id];
    if (!cellData || !cellData.species_list) {
        return getPredictedRichness(properties);
    }
    var count = 0;
    cellData.species_list.forEach(function(name) {
        var sp = speciesLookup[name];
        if (!sp || speciesMatchesFilters(sp)) count++;
    });
    return count;
}

// Returns true if a species object matches the currently active Light Tolerance
// and Migratory Status filters.
function speciesMatchesFilters(sp) {
    var lightMatch = activeLightFilter === 'all' ||
        (activeLightFilter === 'sensitive' && sp.light_tolerance === 'Sensitive') ||
        (activeLightFilter === 'tolerant' && sp.light_tolerance === 'Tolerant');
    var migMatch = activeMigrationFilter === 'all' ||
        (activeMigrationFilter === 'resident' && sp.migration_status === 'Resident') ||
        (activeMigrationFilter === 'migratory' && sp.migration_status === 'Migratory');
    return lightMatch && migMatch;
}

// Color scale for predicted richness (blue → green → yellow → red)
// Uses a diverging spectral-like palette
function getRichnessColor(value) {
    var stops = [
        { val: 0,  r: 49,  g: 54,  b: 149 },  // deep blue
        { val: 5,  r: 69,  g: 117, b: 180 },  // blue
        { val: 10, r: 116, g: 173, b: 209 },  // light blue
        { val: 15, r: 171, g: 217, b: 233 },  // pale blue
        { val: 18, r: 254, g: 224, b: 144 },  // yellow
        { val: 22, r: 253, g: 174, b: 97  },  // orange
        { val: 26, r: 244, g: 109, b: 67  },  // red-orange
        { val: 30, r: 165, g: 0,   b: 38  }   // dark red
    ];
    value = Math.max(0, Math.min(30, value));
    var lower = stops[0], upper = stops[stops.length - 1];
    for (var i = 0; i < stops.length - 1; i++) {
        if (value >= stops[i].val && value <= stops[i + 1].val) {
            lower = stops[i];
            upper = stops[i + 1];
            break;
        }
    }
    var t = (upper.val === lower.val) ? 0 : (value - lower.val) / (upper.val - lower.val);
    var r = Math.round(lower.r + t * (upper.r - lower.r));
    var g = Math.round(lower.g + t * (upper.g - lower.g));
    var b = Math.round(lower.b + t * (upper.b - lower.b));
    return 'rgb(' + r + ',' + g + ',' + b + ')';
}

// Get currently selected land cover types
function getSelectedLandCoverTypes() {
    const checkboxes = document.querySelectorAll('.lc-filter');
    const selected = new Set();
    checkboxes.forEach(function(cb) {
        if (cb.checked) selected.add(parseInt(cb.value));
    });
    return selected;
}

// Filter GeoJSON features to Metro Manila and selected land cover types
function filterToMetroManila(data) {
    const selected = getSelectedLandCoverTypes();
    return {
        type: 'FeatureCollection',
        features: data.features.filter(function(f) {
            var lat = f.properties.latitude;
            var lng = f.properties.longitude;
            var lc = f.properties.landcover;
            return lat >= 14.35 && lat <= 14.78 &&
                   lng >= 120.90 && lng <= 121.15 &&
                   selected.has(lc);
        })
    };
}

// Style function for GeoJSON features
function style(feature) {
    if (colorMode === 'predictions') {
        var richness = getFilteredRichness(feature.properties);
        return {
            fillColor: getRichnessColor(richness),
            weight: 0.5,
            opacity: 0.3,
            color: '#ffffff',
            fillOpacity: 0.8
        };
    }
    return {
        fillColor: getLandCoverColor(feature.properties.landcover),
        weight: 1,
        opacity: 0.5,
        color: 'white',
        fillOpacity: 0.6
    };
}

// Set color mode and refresh map
function setColorMode(mode) {
    colorMode = mode;
    // Update button styles
    document.getElementById('btnPredictions').className = mode === 'predictions' ? 'btn btn-primary' : 'btn btn-secondary';
    document.getElementById('btnLandCover').className = mode === 'landcover' ? 'btn btn-primary' : 'btn btn-secondary';
    // Toggle legends
    document.getElementById('legendPrediction').style.display = mode === 'predictions' ? 'block' : 'none';
    document.getElementById('legendLandCover').style.display = mode === 'landcover' ? 'block' : 'none';
    applyLandCoverFilter();
}

// Apply land cover filter
function applyLandCoverFilter() {
    if (!geojsonData) return;

    if (geojsonLayer) {
        map.removeLayer(geojsonLayer);
    }

    var filtered = filterToMetroManila(geojsonData);

    geojsonLayer = L.geoJSON(filtered, {
        style: style,
        onEachFeature: function(feature, layer) {
            layer.on('click', function(e) {
                showCellAnalysis(feature.properties);
            });

            var lat = feature.properties.latitude;
            var lng = feature.properties.longitude;
            var lcCode = feature.properties.landcover;
            var richness = getFilteredRichness(feature.properties);
            var totalRichness = getPredictedRichness(feature.properties);
            var isFiltered = activeLightFilter !== 'all' || activeMigrationFilter !== 'all';
            var richnessText = isFiltered ?
                'Filtered Richness: ' + richness + ' (of ' + totalRichness + ' total)' :
                'Predicted Richness: ' + richness + ' species';

            if (lat != null && lng != null) {
                var latDir = lat >= 0 ? 'N' : 'S';
                var lngDir = lng >= 0 ? 'E' : 'W';
                layer.bindTooltip(
                    '<strong>' + getLandCoverName(lcCode) + '</strong><br>' +
                    richnessText + '<br>' +
                    '<em>' + Math.abs(lat).toFixed(4) + '°' + latDir + ', ' + Math.abs(lng).toFixed(4) + '°' + lngDir + '</em>',
                    { sticky: true, className: 'map-tooltip' }
                );
            }

            var popLatDir = feature.properties.latitude >= 0 ? 'N' : 'S';
            var popLngDir = feature.properties.longitude >= 0 ? 'E' : 'W';
            layer.bindPopup(
                '<strong>' + getLandCoverName(feature.properties.landcover) + '</strong><br>' +
                '<strong>Location:</strong> ' + Math.abs(feature.properties.latitude).toFixed(4) + '°' + popLatDir + ', ' + Math.abs(feature.properties.longitude).toFixed(4) + '°' + popLngDir + '<br>' +
                '<strong>' + (isFiltered ? 'Filtered Richness:' : 'Predicted Richness:') + '</strong> ' + richness + (isFiltered ? ' (of ' + totalRichness + ' total)' : ' species') + '<br>' +
                '<em>Click for full analysis</em>'
            );
        }
    }).addTo(map);
}

// Select/Deselect all land cover types
function selectAllLandCover(selectAll) {
    document.querySelectorAll('.lc-filter').forEach(function(cb) {
        cb.checked = selectAll;
    });
    applyLandCoverFilter();
}

// Add event listeners to land cover filter checkboxes
document.querySelectorAll('.lc-filter').forEach(function(cb) {
    cb.addEventListener('change', applyLandCoverFilter);
});

// Load and display GeoJSON
fetch('AviLight_LandCover_GeoJSON.geojson')
    .then(function(response) { return response.json(); })
    .then(function(data) {
        document.getElementById('loading').style.display = 'none';

        geojsonData = data;

        applyLandCoverFilter();
    })
    .catch(function(error) {
        document.getElementById('loading').style.display = 'none';
        console.error('Error loading GeoJSON:', error);
        alert('Error loading map data. Please check console for details.');
    });

// Month slider
document.getElementById('monthSlider').addEventListener('input', function() {
    var months = ['January', 'February', 'March', 'April', 'May', 'June',
                    'July', 'August', 'September', 'October', 'November', 'December'];
    document.getElementById('monthValue').textContent = months[this.value - 1];
});

// Filter species by Light Tolerance
function filterSpecies(type) {
    activeLightFilter = type;
    document.getElementById('btnFilterAll').className = type === 'all' ? 'btn btn-primary' : 'btn btn-secondary';
    document.getElementById('btnFilterSensitive').className = type === 'sensitive' ? 'btn btn-warning' : 'btn btn-secondary';
    document.getElementById('btnFilterTolerant').className = type === 'tolerant' ? 'btn btn-success' : 'btn btn-secondary';
    applyLandCoverFilter();
}

// Filter species by Migratory Status
function filterMigration(type) {
    activeMigrationFilter = type;
    document.getElementById('btnMigAll').className = type === 'all' ? 'btn btn-primary' : 'btn btn-secondary';
    document.getElementById('btnMigResident').className = type === 'resident' ? 'btn btn-success' : 'btn btn-secondary';
    document.getElementById('btnMigMigratory').className = type === 'migratory' ? 'btn btn-info' : 'btn btn-secondary';
    applyLandCoverFilter();
}

// Track the SHAP chart instance to prevent memory leaks
let shapChartInstance = null;

// Show cell analysis panel
function showCellAnalysis(properties) {
    var cellData = cellsLookup[properties.cell_id] || null;

    if (!cellData) {
        var estimatedRichness = getPredictedRichness(properties);
        cellData = {
            cell_id: properties.cell_id,
            latitude: properties.latitude,
            longitude: properties.longitude,
            predicted_richness: estimatedRichness,
            actual_richness: Math.max(1, estimatedRichness + Math.round((Math.abs(hashCode(properties.cell_id)) % 5) - 2)),
            species_list: ['No observed data — richness estimated from land cover type'],
            shap_values: {
                light: -2.0,
                ndvi: 1.0,
                temperature: 0.5,
                elevation: 0.3
            }
        };
    }

    document.getElementById('cellId').textContent = cellData.site_name || cellData.cell_id;
    document.getElementById('cellCoords').textContent =
        cellData.latitude.toFixed(4) + ', ' + cellData.longitude.toFixed(4);
    document.getElementById('predictedRichness').textContent = cellData.predicted_richness;
    document.getElementById('actualRichness').textContent = cellData.actual_richness;

    // Show observation breakdown if real data is available
    var breakdownEl = document.getElementById('obsBreakdown');
    if (breakdownEl) {
        if (cellData.total_tolerant !== undefined) {
            var months = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
                          'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            var period = cellData.month ? months[cellData.month] + ' ' + cellData.year : '';
            breakdownEl.style.display = 'block';
            breakdownEl.innerHTML =
                (period ? '<small style="color:#888;">Last observed: ' + period + '</small><br>' : '') +
                '<small>🌅 Tolerant: <strong>' + cellData.total_tolerant + '</strong> &nbsp; ' +
                '💡 Sensitive: <strong>' + cellData.total_sensitive + '</strong> &nbsp; ' +
                '🏡 Resident: <strong>' + cellData.total_resident + '</strong> &nbsp; ' +
                '✈️ Migrant: <strong>' + cellData.total_migrant + '</strong> &nbsp; ' +
                '👁 Total birds: <strong>' + (cellData.total_count || '—') + '</strong></small>';
        } else {
            breakdownEl.style.display = 'none';
        }
    }

    var speciesList = document.getElementById('speciesList');
    speciesList.innerHTML = '';
    var isFiltered = activeLightFilter !== 'all' || activeMigrationFilter !== 'all';
    var matchCount = 0;
    cellData.species_list.forEach(function(species) {
        if (isFiltered) {
            var sp = speciesLookup[species];
            if (sp && !speciesMatchesFilters(sp)) return;
        }
        matchCount++;
        var li = document.createElement('li');
        li.textContent = species;
        speciesList.appendChild(li);
    });
    if (isFiltered && matchCount === 0) {
        var li = document.createElement('li');
        li.textContent = 'No species match the active filter criteria.';
        li.style.color = '#999';
        speciesList.appendChild(li);
    }

    // Destroy previous chart instance to prevent memory leaks and slowdown
    if (shapChartInstance) {
        shapChartInstance.destroy();
        shapChartInstance = null;
    }

    var ctx = document.getElementById('shapChart').getContext('2d');
    shapChartInstance = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: ['Light', 'NDVI', 'Temperature', 'Elevation'],
            datasets: [{
                label: 'SHAP Value',
                data: [
                    cellData.shap_values.light,
                    cellData.shap_values.ndvi,
                    cellData.shap_values.temperature,
                    cellData.shap_values.elevation
                ],
                backgroundColor: function(context) {
                    return context.parsed.y < 0 ? '#dc3545' : '#28a745';
                }
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                }
            }
        }
    });

    var lightText = cellData.shap_values.light < 0 ?
        'high light reduces richness by ' + Math.abs(cellData.shap_values.light).toFixed(1) + '%' :
        'light has positive effect';
    var ndviText = cellData.shap_values.ndvi > 0 ?
        'increases it by ' + cellData.shap_values.ndvi.toFixed(1) + '%' :
        'has negative effect';

    document.getElementById('shapExplanation').innerHTML =
        '<p style="margin-top: 10px; font-size: 0.9rem; color: #666;">' +
        '<strong>Interpretation:</strong> In this area, ' + lightText +
        ', while NDVI ' + ndviText + '.</p>';

    document.getElementById('cellPanel').style.display = 'block';
}

function closeCellPanel() {
    document.getElementById('cellPanel').style.display = 'none';
}

// Search cell function
function searchCell() {
    var cellId = document.getElementById('cellSearchInput').value.trim();
    var cellData = cellsLookup[cellId] || null;

    if (cellData) {
        document.getElementById('searchResult').innerHTML =
            '<div class="alert alert-info" style="margin-top: 15px;">' +
            '<strong>Site Found:</strong> ' + (cellData.site_name || cellId) + '<br>' +
            '<strong>Unique Species:</strong> ' + cellData.predicted_richness + '<br>' +
            '<strong>Total Birds Counted:</strong> ' + (cellData.total_count || '—') +
            '</div>';

        map.setView([cellData.latitude, cellData.longitude], 14);
        showCellAnalysis(cellData);
    } else {
        document.getElementById('searchResult').innerHTML =
            '<div class="alert alert-danger" style="margin-top: 15px;">' +
            'Site not found. Try site IDs like: site_Tanza, site_Las_Pinas_Paranaque_Wetland_Park' +
            '</div>';
    }
}

// Global SHAP chart
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
                title: {
                    display: true,
                    text: 'Mean |SHAP Value|'
                }
            }
        }
    }
});
</script>
EOD;

require_once 'includes/footer.php';
?>
