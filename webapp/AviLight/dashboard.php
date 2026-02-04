<?php
session_start();
if (!isset($_SESSION['user_email'])) {
    header('Location: login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>AVILIGHT | Dashboard</title>

<link rel="stylesheet" href="assets/css/leaflet.css">
<script src="assets/js/chart.js"></script>

<style>
body { margin:0; font-family:Arial,sans-serif; background:#0f172a; color:#fff; }
header { background:#020617; padding:15px 30px; display:flex; justify-content:space-between; align-items:center; }
.container { display:flex; padding:20px; gap:20px; }
.left { flex:3; }
.side { flex:1; }
.card { background:#020617; padding:15px; border-radius:12px; margin-bottom:15px; }
.map { height:420px; border-radius:12px; overflow:hidden; position:relative; }
#map { width:100%; height:100%; }
canvas { height:260px !important; }
.stat { font-size:32px; margin-top:10px; }
.green { color:#22c55e; }
.red { color:#ef4444; }
.yellow { color:#fbbf24; }
.activity { font-size:14px; margin-top:8px; }
.landcover-scroll { max-height:220px; overflow-y:auto; font-size:13px; }
.loading { 
    position: absolute; 
    top: 50%; 
    left: 50%; 
    transform: translate(-50%, -50%);
    background: rgba(0,0,0,0.8);
    padding: 20px;
    border-radius: 8px;
    z-index: 1000;
}
.performance-info {
    font-size: 11px;
    color: #64748b;
    margin-top: 10px;
}
.zoom-hint {
    background: #1e293b;
    padding: 10px;
    border-radius: 6px;
    font-size: 12px;
    margin-top: 10px;
    border-left: 3px solid #38bdf8;
}
</style>
</head>

<body>

<header>
    <h3>AVILIGHT</h3>
    <div>
        <span style="font-size:12px; color:#64748b; margin-right:15px;">
            Zoom: <span id="zoom-level">11</span> | 
            <span id="feature-count">Loading...</span>
        </span>
        <span><?= htmlspecialchars($_SESSION['user_email']) ?></span>
    </div>
</header>

<div class="container">

<div class="left">

<div class="card">
<h4>Map Filters</h4>

<strong>Risk Level (Light Pollution Impact)</strong><br>
<label><input type="checkbox" class="risk-filter" value="Low" checked> 
    <span class="green">● Low</span> - Minimal light pollution</label><br>
<label><input type="checkbox" class="risk-filter" value="Medium" checked> 
    <span class="yellow">● Medium</span> - Moderate light pollution</label><br>
<label><input type="checkbox" class="risk-filter" value="High" checked> 
    <span class="red">● High</span> - Severe light pollution</label>
<hr>

<strong>Land Cover Types</strong>
<div class="landcover-scroll">
<?php
$landcovers = [
    2=>'Evergreen Broadleaf',
    8=>'Woody Savannas',9=>'Savannas',10=>'Grasslands',
    11=>'Permanent Wetlands',12=>'Croplands',13=>'Urban & Built-up',14=>'Cropland Mosaic',
    16=>'Barren Land',17=>'Water Bodies'
];

$colors = [
    2=>'086a10',8=>'dade48',9=>'fbff13',10=>'b6ff05',
    11=>'27ff87',12=>'c24f44',13=>'a5a5a5',14=>'ff6d4c',
    16=>'f9ffa4',17=>'1c0dff'
];

foreach($landcovers as $id=>$name){
    echo "<label><input type='checkbox' class='lc-filter' value='$id' checked>
          <span style='color:#{$colors[$id]}'>■</span> $name</label><br>";
}
?>
</div>

<div class="zoom-hint">
    💡 <strong>Viewing Tips:</strong><br>
    • Each cell is ~500m × 500m<br>
    • Zoom to 13+ to see individual cells<br>
    • At zoom 11-12, cells appear as colored grid<br>
    • Uncheck filters to improve performance
</div>

</div>

<div class="map">
    <div id="loading" class="loading">Loading 34,300 land cover cells...</div>
    <div id="map"></div>
</div>

</div>

<div class="side">

<div class="card">
<h4>Bird Richness Trend</h4>
<canvas id="trend"></canvas>
<div id="chart-details" style="display: none; margin-top: 12px; padding: 10px; background: #1e293b; border-radius: 6px; border-left: 3px solid #38bdf8;">
    <div style="display: flex; justify-content: space-between; align-items: center;">
        <div>
            <strong id="detail-month" style="color: #38bdf8; font-size: 14px;"></strong>
            <div id="detail-count" style="font-size: 24px; margin: 5px 0;"></div>
            <small id="detail-change" style="color: #64748b;"></small>
        </div>
        <button onclick="document.getElementById('chart-details').style.display='none'" 
                style="background: transparent; border: none; color: #64748b; cursor: pointer; font-size: 20px;">&times;</button>
    </div>
</div>
</div>

<div class="card">
<h4>High Risk Zones</h4>
<div class="stat">18,906 <span class="red">55%</span></div>
<small>Urban areas with high light pollution</small>
</div>

<div class="card">
<h4>Protected Areas</h4>
<div class="stat">10,061 <span class="green">29%</span></div>
<small>Water bodies - low light pollution</small>
</div>

<div class="card">
<h4>Data Coverage</h4>
<div style="font-size:14px; margin-top:10px;">
    <div>Total Cells: <b>34,300</b></div>
    <div>Cell Size: <b>500m × 500m</b></div>
    <div>Area: <b>Metro Manila</b></div>
    <div>Year: <b>2014</b></div>
</div>
</div>

</div>
</div>

<script src="assets/js/leaflet.js"></script>

<script>
// =====================
// MAP CONFIG
// =====================
const map = L.map('map', {
    center: [14.5995, 121.0342],
    zoom: 11,
    minZoom: 10,
    maxZoom: 18,
    scrollWheelZoom: true,
    zoomControl: true,
    preferCanvas: false // Ensure SVG rendering for proper coordinate handling
});

// Base tile layer
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© OpenStreetMap contributors',
    maxZoom: 19
}).addTo(map);

// =====================
// RISK MAPPING
// =====================
const landCoverRiskMap = {
    2:'Low',
    8:'Medium', 9:'Medium', 10:'Medium',
    11:'High', 12:'High', 13:'High', 14:'High',
    16:'Medium', 17:'Low'
};

const landCoverColors = {
    2:'#086a10',
    8:'#dade48', 9:'#fbff13', 10:'#b6ff05',
    11:'#27ff87', 12:'#c24f44', 13:'#a5a5a5', 14:'#ff6d4c',
    16:'#f9ffa4', 17:'#1c0dff'
};

const landCoverNames = {
    2:'Evergreen Broadleaf',
    8:'Woody Savannas', 9:'Savannas', 10:'Grasslands',
    11:'Permanent Wetlands', 12:'Croplands', 13:'Urban & Built-up', 14:'Cropland Mosaic',
    16:'Barren Land', 17:'Water Bodies'
};

// =====================
// DATA STORAGE
// =====================
let fullData = null;
let geojsonLayer = null;

// =====================
// LOAD GEOJSON ONCE
// =====================
document.getElementById('loading').textContent = 'Loading GeoJSON data...';

fetch('AviLight_LandCover_GeoJSON.geojson')
.then(res => res.json())
.then(data => {
    console.log('✅ Loaded', data.features.length, 'features');
    fullData = data;
    
    // Initial render
    renderMap();
    
    // Hide loading
    document.getElementById('loading').style.display = 'none';
})
.catch(err => {
    console.error('❌ Error:', err);
    document.getElementById('loading').innerHTML = '<span style="color:#ef4444">Error loading data!</span>';
});

// =====================
// RENDER MAP
// =====================
function renderMap() {
    if (!fullData) return;
    
    // Remove old layer if it exists
    if (geojsonLayer) {
        map.removeLayer(geojsonLayer);
        geojsonLayer = null;
    }
    
    const zoom = map.getZoom();
    
    // Get selected filters
    const selectedRisks = [...document.querySelectorAll('.risk-filter:checked')].map(e => e.value);
    const selectedLC = [...document.querySelectorAll('.lc-filter:checked')].map(e => Number(e.value));
    
    // Filter data
    const filteredFeatures = fullData.features.filter(f => {
        const lc = f.properties.landcover;
        const risk = f.properties.risk || landCoverRiskMap[lc];
        return selectedRisks.includes(risk) && selectedLC.includes(lc);
    });
    
    console.log(`Rendering ${filteredFeatures.length} features at zoom ${zoom}`);
    
    // Render strategy based on zoom
    if (zoom >= 13) {
        // DETAILED VIEW - Show individual cells with borders
        renderDetailed(filteredFeatures);
    } else {
        // OVERVIEW - Show filled cells, no borders
        renderSimplified(filteredFeatures);
    }
    
    // Update UI
    updateStats(filteredFeatures.length);
}

// =====================
// DETAILED RENDERING (Zoom 13+)
// =====================
function renderDetailed(features) {
    geojsonLayer = L.geoJSON({
        type: 'FeatureCollection',
        features: features
    }, {
        style: feature => {
            const lc = feature.properties.landcover;
            return {
                fillColor: landCoverColors[lc],
                fillOpacity: 0.7,
                color: '#000000',
                weight: 1,
                opacity: 0.3
            };
        },
        onEachFeature: (feature, layer) => {
            const lc = feature.properties.landcover;
            const risk = feature.properties.risk || landCoverRiskMap[lc];
            
            layer.bindPopup(`
                <div style="color:#000; min-width:200px;">
                    <h4 style="margin:0 0 10px 0;">${landCoverNames[lc]}</h4>
                    <table style="width:100%; font-size:12px;">
                        <tr><td><b>Type ID:</b></td><td>${lc}</td></tr>
                        <tr><td><b>Risk Level:</b></td><td><span style="color:${
                            risk==='High' ? '#ef4444' : 
                            risk==='Medium' ? '#fbbf24' : '#22c55e'
                        }; font-weight:bold;">${risk}</span></td></tr>
                        <tr><td><b>Latitude:</b></td><td>${feature.properties.latitude.toFixed(5)}°</td></tr>
                        <tr><td><b>Longitude:</b></td><td>${feature.properties.longitude.toFixed(5)}°</td></tr>
                        <tr><td><b>Cell ID:</b></td><td style="font-size:10px;">${feature.properties.cell_id}</td></tr>
                    </table>
                </div>
            `);
        }
    }).addTo(map);
}

// =====================
// SIMPLIFIED RENDERING (Zoom < 13)
// =====================
function renderSimplified(features) {
    geojsonLayer = L.geoJSON({
        type: 'FeatureCollection',
        features: features
    }, {
        style: feature => {
            const lc = feature.properties.landcover;
            return {
                fillColor: landCoverColors[lc],
                fillOpacity: 0.6,
                color: landCoverColors[lc],
                weight: 0,
                stroke: false
            };
        }
    }).addTo(map);
}

// =====================
// UPDATE STATS
// =====================
function updateStats(visibleCount) {
    const total = fullData ? fullData.features.length : 0;
    document.getElementById('feature-count').textContent = 
        `${visibleCount.toLocaleString()} / ${total.toLocaleString()} cells`;
}

// =====================
// FILTER HANDLERS
// =====================
document.querySelectorAll('.risk-filter, .lc-filter').forEach(checkbox => {
    checkbox.addEventListener('change', () => {
        renderMap();
    });
});

// =====================
// ZOOM HANDLER
// =====================
let lastZoom = map.getZoom();

map.on('zoomend', () => {
    const newZoom = map.getZoom();
    document.getElementById('zoom-level').textContent = newZoom;
    
    // Re-render if crossing zoom threshold 13
    if ((lastZoom < 13 && newZoom >= 13) || (lastZoom >= 13 && newZoom < 13)) {
        console.log('Zoom threshold crossed, re-rendering...');
        renderMap();
    }
    
    lastZoom = newZoom;
});

// Update zoom level display
map.on('zoom', () => {
    document.getElementById('zoom-level').textContent = map.getZoom();
});

// =====================
// INTERACTIVE CHART WITH DUMMY DATA
// =====================
const CHART_MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
const CHART_DATA_VALUES = [120, 180, 150, 210, 190, 240, 230, 260, 220, 200, 170, 195];

const chartData = {
    labels: CHART_MONTHS,
    datasets: [{
        label: 'Bird Species Count',
        data: CHART_DATA_VALUES,
        borderColor: '#38bdf8',
        backgroundColor: 'rgba(56,189,248,0.2)',
        tension: 0.3,
        fill: true,
        pointRadius: 5,
        pointHoverRadius: 8,
        pointBackgroundColor: '#38bdf8',
        pointBorderColor: '#fff',
        pointBorderWidth: 2
    }]
};

const birdChart = new Chart(document.getElementById('trend'), {
    type: 'line',
    data: chartData,
    options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: {
            mode: 'index',
            intersect: false
        },
        plugins: {
            legend: {
                labels: { 
                    color: '#fff',
                    font: { size: 12 }
                },
                onClick: (e, legendItem, legend) => {
                    // Toggle dataset visibility
                    const index = legendItem.datasetIndex;
                    const chart = legend.chart;
                    const meta = chart.getDatasetMeta(index);
                    meta.hidden = !meta.hidden;
                    chart.update();
                }
            },
            tooltip: {
                enabled: true,
                backgroundColor: 'rgba(0, 0, 0, 0.8)',
                titleColor: '#fff',
                bodyColor: '#fff',
                borderColor: '#38bdf8',
                borderWidth: 1,
                padding: 12,
                displayColors: true,
                callbacks: {
                    title: (context) => {
                        return `Month: ${context[0].label}`;
                    },
                    label: (context) => {
                        const value = context.parsed.y;
                        const prevValue = context.dataIndex > 0 ? 
                            CHART_DATA_VALUES[context.dataIndex - 1] : value;
                        const change = value - prevValue;
                        const changePercent = prevValue > 0 ? 
                            ((change / prevValue) * 100).toFixed(1) : 0;
                        
                        let changeText = '';
                        if (context.dataIndex > 0) {
                            changeText = change >= 0 ? 
                                ` (↑ +${change} / +${changePercent}%)` : 
                                ` (↓ ${change} / ${changePercent}%)`;
                        }
                        
                        return `Species: ${value}${changeText}`;
                    },
                    footer: (context) => {
                        return 'Click to view details';
                    }
                }
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: { 
                    color: '#fff',
                    font: { size: 11 }
                },
                grid: { 
                    color: 'rgba(255,255,255,0.1)',
                    drawBorder: false
                },
                title: {
                    display: true,
                    text: 'Species Count',
                    color: '#fff',
                    font: { size: 12, weight: 'bold' }
                }
            },
            x: {
                ticks: { 
                    color: '#fff',
                    font: { size: 11 }
                },
                grid: { 
                    color: 'rgba(255,255,255,0.1)',
                    drawBorder: false
                }
            }
        },
        onClick: (event, activeElements) => {
            if (activeElements.length > 0) {
                const element = activeElements[0];
                const dataIndex = element.index;
                const month = CHART_MONTHS[dataIndex];
                const value = CHART_DATA_VALUES[dataIndex];
                
                // Calculate change from previous month
                let changeText = '';
                if (dataIndex > 0) {
                    const prevValue = CHART_DATA_VALUES[dataIndex - 1];
                    const change = value - prevValue;
                    const changePercent = ((change / prevValue) * 100).toFixed(1);
                    changeText = change >= 0 ? 
                        `↑ +${change} species (+${changePercent}% from previous month)` : 
                        `↓ ${change} species (${changePercent}% from previous month)`;
                } else {
                    changeText = 'First month in dataset';
                }
                
                // Update detail panel
                document.getElementById('detail-month').textContent = `🐦 ${month} 2024`;
                document.getElementById('detail-count').textContent = `${value} species`;
                document.getElementById('detail-change').textContent = changeText;
                document.getElementById('chart-details').style.display = 'block';
                
                // Highlight selected point
                const meta = birdChart.getDatasetMeta(0);
                
                // Reset all points
                meta.data.forEach((point, idx) => {
                    point.options.radius = 5;
                });
                
                // Highlight clicked point
                meta.data[dataIndex].options.radius = 10;
                birdChart.update('none'); // Update without animation
                
                // Reset point size after 3 seconds
                setTimeout(() => {
                    meta.data[dataIndex].options.radius = 5;
                    birdChart.update('none');
                }, 3000);
            }
        },
        onHover: (event, activeElements) => {
            event.native.target.style.cursor = activeElements.length > 0 ? 'pointer' : 'default';
        }
    }
});

// Add zoom/pan functionality hint
document.getElementById('trend').parentElement.insertAdjacentHTML('beforeend', `
    <div style="font-size: 11px; color: #64748b; margin-top: 8px; text-align: center;">
        💡 Hover for details • Click points for more info • Click legend to toggle
    </div>
`);
</script>

</body>
</html>