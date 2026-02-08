<?php
$page_title = 'Geospatial Forecasting';
require_once 'includes/header.php';

// Load sample data
$cells_data = json_decode(file_get_contents('data/sample_cells.json'), true);
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
                <span class="filter-label">Species Filter:</span>
                <button class="btn btn-primary" onclick="filterSpecies('all')">All Species</button>
                <button class="btn btn-warning" onclick="filterSpecies('sensitive')">Light-Sensitive</button>
                <button class="btn btn-secondary" onclick="filterSpecies('tolerant')">Light-Tolerant</button>
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
            
            <!-- Legend -->
            <div class="legend">
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

<!-- Cell Analysis Panel (Hidden by default) -->
<div id="cellPanel" class="side-panel" style="display: none;">
    <span class="side-panel-close" onclick="closeCellPanel()">&times;</span>
    <h3 id="cellTitle">Cell Analysis</h3>
    <div id="cellContent">
        <p><strong>Cell ID:</strong> <span id="cellId"></span></p>
        <p><strong>Coordinates:</strong> <span id="cellCoords"></span></p>
        <p><strong>Predicted Richness:</strong> <span id="predictedRichness"></span></p>
        <p><strong>Actual Richness:</strong> <span id="actualRichness"></span></p>
        <hr>
        <h4>Species in this Cell:</h4>
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
        <h2 class="card-header">Local Explainer - Search Cell</h2>
        <div class="card-body">
            <div class="form-group">
                <label class="form-label">Enter Cell ID:</label>
                <input type="text" class="form-control" id="cellSearchInput" placeholder="e.g., cell_2937">
                <button class="btn btn-primary" style="margin-top: 10px;" onclick="searchCell()">Search</button>
            </div>
            <div id="searchResult"></div>
        </div>
    </div>
</div>

<?php
$cells_json = json_encode($cells_data, JSON_HEX_TAG | JSON_HEX_AMP);
$extra_scripts = <<<EOD
<script>
// Metro Manila bounding box
const MM_BOUNDS = L.latLngBounds(
    L.latLng(14.35, 120.90),
    L.latLng(14.78, 121.15)
);

// Initialize map centered on Metro Manila with bounded view
const map = L.map('map', {
    maxBounds: MM_BOUNDS.pad(0.1),
    minZoom: 10
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

// Sample cell data
const cellsData = {$cells_json};

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
    return {
        fillColor: getLandCoverColor(feature.properties.landcover),
        weight: 1,
        opacity: 0.5,
        color: 'white',
        fillOpacity: 0.6
    };
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

            layer.bindPopup(
                '<strong>Cell ID:</strong> ' + feature.properties.cell_id + '<br>' +
                '<strong>Land Cover:</strong> ' + getLandCoverName(feature.properties.landcover) + '<br>' +
                '<strong>Coords:</strong> ' + feature.properties.latitude.toFixed(4) + ', ' + feature.properties.longitude.toFixed(4)
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

// Filter species function
function filterSpecies(type) {
    console.log('Filtering species:', type);
    alert('Filtering ' + type + ' species - Implementation will update map colors based on species type');
}

// Show cell analysis panel
function showCellAnalysis(properties) {
    var cellData = cellsData.find(function(c) { return c.cell_id === properties.cell_id; });

    if (!cellData) {
        cellData = {
            cell_id: properties.cell_id,
            latitude: properties.latitude,
            longitude: properties.longitude,
            predicted_richness: 12,
            actual_richness: 11,
            species_list: ['Sample species data not available'],
            shap_values: {
                light: -2.0,
                ndvi: 1.0,
                temperature: 0.5,
                elevation: 0.3
            }
        };
    }

    document.getElementById('cellId').textContent = cellData.cell_id;
    document.getElementById('cellCoords').textContent =
        cellData.latitude.toFixed(4) + ', ' + cellData.longitude.toFixed(4);
    document.getElementById('predictedRichness').textContent = cellData.predicted_richness;
    document.getElementById('actualRichness').textContent = cellData.actual_richness;

    var speciesList = document.getElementById('speciesList');
    speciesList.innerHTML = '';
    cellData.species_list.forEach(function(species) {
        var li = document.createElement('li');
        li.textContent = species;
        speciesList.appendChild(li);
    });

    var ctx = document.getElementById('shapChart').getContext('2d');
    new Chart(ctx, {
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
        '<strong>Interpretation:</strong> In this cell, ' + lightText +
        ', while NDVI ' + ndviText + '.</p>';

    document.getElementById('cellPanel').style.display = 'block';
}

function closeCellPanel() {
    document.getElementById('cellPanel').style.display = 'none';
}

// Search cell function
function searchCell() {
    var cellId = document.getElementById('cellSearchInput').value.trim();
    var cellData = cellsData.find(function(c) { return c.cell_id === cellId; });

    if (cellData) {
        var lightImpact = cellData.shap_values.light > 0 ? 'Positive' : 'Negative';
        var ndviImpact = cellData.shap_values.ndvi > 0 ? 'Positive' : 'Negative';
        document.getElementById('searchResult').innerHTML =
            '<div class="alert alert-info" style="margin-top: 15px;">' +
            '<strong>Cell Found:</strong> ' + cellId + '<br>' +
            '<strong>Predicted Richness:</strong> ' + cellData.predicted_richness + '<br>' +
            '<strong>Light Impact:</strong> ' + lightImpact + ' (' + cellData.shap_values.light.toFixed(1) + '%)<br>' +
            '<strong>NDVI Impact:</strong> ' + ndviImpact + ' (' + cellData.shap_values.ndvi.toFixed(1) + '%)' +
            '</div>';

        map.setView([cellData.latitude, cellData.longitude], 14);
        showCellAnalysis(cellData);
    } else {
        document.getElementById('searchResult').innerHTML =
            '<div class="alert alert-danger" style="margin-top: 15px;">' +
            'Cell ID not found. Try: cell_2937 or cell_120.9000_14.3000' +
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
