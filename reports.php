<?php
$page_title = 'Statistical Reports';
require_once 'includes/header.php';

// Load sample data
$kba_data = json_decode(file_get_contents('data/sample_kba.json'), true);

// Parse final_avilight_2014_merged.csv for real data visualizations
$csv_sites = [];
$csv_file = __DIR__ . '/final_avilight_2014_merged.csv';
if (($handle = fopen($csv_file, 'r')) !== false) {
    $headers = fgetcsv($handle);
    $col = array_flip($headers);
    while (($row = fgetcsv($handle)) !== false) {
        $site = trim($row[$col['Site Name']] ?? '');
        if ($site === '') continue;
        if (!isset($csv_sites[$site])) {
            $csv_sites[$site] = ['richness' => [], 'light' => [], 'tolerant' => [], 'sensitive' => [], 'resident' => [], 'migrant' => []];
        }
        $csv_sites[$site]['richness'][]  = (float)($row[$col['Unique Species Count']] ?? 0);
        $csv_sites[$site]['light'][]     = (float)($row[$col['viirs_avg_rad']] ?? 0);
        $csv_sites[$site]['tolerant'][]  = (float)($row[$col['total_tolerant_species']] ?? 0);
        $csv_sites[$site]['sensitive'][] = (float)($row[$col['total_sensitive_species']] ?? 0);
        $csv_sites[$site]['resident'][]  = (float)($row[$col['total_resident_species']] ?? 0);
        $csv_sites[$site]['migrant'][]   = (float)($row[$col['total_migrant_species']] ?? 0);
    }
    fclose($handle);
}

// Build per-site aggregated data
$site_data = [];
$avg = function(array $arr): float {
    $n = count($arr);
    return $n > 0 ? round(array_sum($arr) / $n, 1) : 0.0;
};
foreach ($csv_sites as $name => $d) {
    $site_data[] = [
        'name'      => $name,
        'richness'  => $avg($d['richness']),
        'light'     => $avg($d['light']),
        'tolerant'  => $avg($d['tolerant']),
        'sensitive' => $avg($d['sensitive']),
        'resident'  => $avg($d['resident']),
        'migrant'   => $avg($d['migrant']),
    ];
}
usort($site_data, fn($a, $b) => $b['richness'] <=> $a['richness']);

$top20_sites      = array_slice($site_data, 0, 20);
$total_tolerant   = round(array_sum(array_column($site_data, 'tolerant')), 1);
$total_sensitive  = round(array_sum(array_column($site_data, 'sensitive')), 1);
$total_resident   = round(array_sum(array_column($site_data, 'resident')), 1);
$total_migrant    = round(array_sum(array_column($site_data, 'migrant')), 1);

// Pre-encode JSON for JS injection — use HEX flags to prevent XSS from CSV content
$json_flags     = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$kba_data_json  = json_encode($kba_data,   $json_flags);
$site_data_json = json_encode($site_data,  $json_flags);
$top20_json     = json_encode($top20_sites, $json_flags);
?>

<div class="page-header">
    <h1 class="page-title">Statistical Reports & Data Visualization</h1>
    <p class="page-subtitle">Comprehensive analysis and export capabilities</p>
</div>

<!-- Export Buttons -->
<div class="card">
    <div class="card-body">
        <h3>Export Data</h3>
        <div style="display: flex; gap: 15px; margin-top: 15px;">
            <button class="btn btn-primary" onclick="exportGeoJSON()">Download GeoJSON</button>
            <button class="btn btn-danger" onclick="exportPDF()">Download PDF Report</button>
            <button class="btn btn-secondary" onclick="exportCSV()">Export CSV Data</button>
        </div>
    </div>
</div>

<!-- Feature Correlation Matrix -->
<div class="card">
    <h2 class="card-header">Environmental Feature Correlation Matrix <small class="data-source-badge data-source-hardcoded">⚠ Hardcoded</small></h2>
    <div class="card-body">
        <div style="max-width: 800px; margin: 0 auto;">
            <canvas id="correlationChart"></canvas>
        </div>
        <div style="display: flex; gap: 16px; flex-wrap: wrap; margin-top: 12px; font-size: 0.85em;">
            <span><span style="display:inline-block;width:12px;height:12px;background:#28a745;border-radius:2px;vertical-align:middle;"></span> Strong positive (&gt; 0.5)</span>
            <span><span style="display:inline-block;width:12px;height:12px;background:#97bc62;border-radius:2px;vertical-align:middle;"></span> Positive (0 – 0.5)</span>
            <span><span style="display:inline-block;width:12px;height:12px;background:#ffc107;border-radius:2px;vertical-align:middle;"></span> Negative (−0.5 – 0)</span>
            <span><span style="display:inline-block;width:12px;height:12px;background:#dc3545;border-radius:2px;vertical-align:middle;"></span> Strong negative (&lt; −0.5)</span>
        </div>
        <p style="margin-top: 12px; color: #666;">
            <strong>Interpretation:</strong> The correlation matrix shows relationships between environmental 
            factors and bird species richness. Darker colors indicate stronger correlations. Light intensity 
            shows strong negative correlation (-0.72) with species count, while NDVI shows positive correlation (0.68).
        </p>
    </div>
</div>

<!-- KBA/PA Performance Audit -->
<div class="card">
    <h2 class="card-header">KBA/PA Performance Audit <small class="data-source-badge data-source-sample">📄 Sample JSON</small></h2>
    <div class="card-body">
        <table>
            <thead>
                <tr>
                    <th>Rank</th>
                    <th>Area Name</th>
                    <th>Type</th>
                    <th>Light Exposure</th>
                    <th>Species Count</th>
                    <th>Sensitive Species %</th>
                    <th>Effectiveness Score</th>
                    <th>Grade</th>
                </tr>
            </thead>
            <tbody>
                <?php
                // Sort KBA data by effectiveness score
                usort($kba_data, function($a, $b) {
                    return $b['effectiveness_score'] - $a['effectiveness_score'];
                });
                
                $rank = 1;
                foreach ($kba_data as $area):
                    $grade = $area['effectiveness_score'] >= 80 ? 'A' :
                             ($area['effectiveness_score'] >= 70 ? 'B' :
                             ($area['effectiveness_score'] >= 60 ? 'C' : 'D'));
                    $gradeColor = $grade === 'A' ? 'badge-success' :
                                  ($grade === 'B' ? 'badge-info' :
                                  ($grade === 'C' ? 'badge-warning' : 'badge-danger'));
                ?>
                <tr>
                    <td><strong><?php echo $rank++; ?></strong></td>
                    <td><?php echo htmlspecialchars($area['name']); ?></td>
                    <td><span class="badge badge-info"><?php echo $area['type']; ?></span></td>
                    <td><?php echo $area['light_exposure']; ?></td>
                    <td><?php echo $area['species_count']; ?></td>
                    <td><?php echo $area['sensitive_species_percent']; ?>%</td>
                    <td>
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: <?php echo $area['effectiveness_score']; ?>%"></div>
                        </div>
                        <?php echo $area['effectiveness_score']; ?>%
                    </td>
                    <td><span class="badge <?php echo $gradeColor; ?>"><?php echo $grade; ?></span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <div style="margin-top: 20px; padding: 15px; background: var(--bg-card-alt); border: 1px solid var(--border-color); border-radius: 8px;">
            <h4 style="margin-bottom: 12px;">Audit Criteria:</h4>
            <div style="display: grid; grid-template-columns: max-content 1fr; gap: 8px 16px; align-items: center;">
                <span class="badge badge-success" style="justify-self: start;">Grade A (80–100)</span>
                <span>Excellent protection, low light exposure, high species diversity</span>
                <span class="badge badge-info" style="justify-self: start;">Grade B (70–79)</span>
                <span>Good protection, moderate effectiveness</span>
                <span class="badge badge-warning" style="justify-self: start;">Grade C (60–69)</span>
                <span>Fair protection, needs improvement</span>
                <span class="badge badge-danger" style="justify-self: start;">Grade D (&lt;60)</span>
                <span>Poor protection, urgent intervention required</span>
            </div>
        </div>
    </div>
</div>

<!-- Species Distribution Analysis -->
<div class="grid-2">
    <div class="card">
        <h2 class="card-header">Species Distribution by Area <small class="data-source-badge data-source-sample">📄 Sample JSON</small></h2>
        <div class="card-body">
            <canvas id="areaSpeciesChart"></canvas>
        </div>
    </div>
    
    <div class="card">
        <h2 class="card-header">Light Exposure vs Species Count <small class="data-source-badge data-source-sample">📄 Sample JSON</small></h2>
        <div class="card-body">
            <canvas id="scatterChart"></canvas>
        </div>
    </div>
</div>

<!-- Temporal Trends -->
<div class="card">
    <h2 class="card-header">Historical Trends (2014–2024) <small class="data-source-badge data-source-hardcoded">⚠ Hardcoded</small></h2>
    <div class="card-body">
        <canvas id="trendsChart"></canvas>
        <p style="margin-top: 15px; color: #666;">
            <strong>Note:</strong> Dataset coverage spans 2014–2024. A gradual improvement in species richness is 
            observed from 2020 onwards, coinciding with the implementation of light pollution control measures.
        </p>
    </div>
</div>

<!-- Distribution of Light Tolerance + Migrants/Residents -->
<div class="card">
    <h2 class="card-header">Distribution of Light Tolerance &amp; Migration Status (2014) <small class="data-source-badge data-source-csv">📊 CSV Dataset</small></h2>
    <div class="card-body">
        <div class="grid-2" style="gap: 30px;">
            <div>
                <h4 style="text-align:center; margin-bottom: 10px;">Light Tolerance</h4>
                <canvas id="toleranceChart"></canvas>
                <p style="margin-top: 10px; color: #666; font-size: 0.9em; text-align:center;">
                    Tolerant: <strong><?php echo $total_tolerant; ?></strong> &nbsp;|&nbsp; Sensitive: <strong><?php echo $total_sensitive; ?></strong>
                </p>
            </div>
            <div>
                <h4 style="text-align:center; margin-bottom: 10px;">Migration Status</h4>
                <canvas id="migrationChart"></canvas>
                <p style="margin-top: 10px; color: #666; font-size: 0.9em; text-align:center;">
                    Residents: <strong><?php echo $total_resident; ?></strong> &nbsp;|&nbsp; Migrants: <strong><?php echo $total_migrant; ?></strong>
                </p>
            </div>
        </div>
    </div>
</div>

<!-- Light Pollution vs Bird Richness -->
<div class="card">
    <h2 class="card-header">Light Pollution vs Bird Richness (2014) <small class="data-source-badge data-source-csv">📊 CSV Dataset</small></h2>
    <div class="card-body">
        <canvas id="lightRichnessChart"></canvas>
        <p style="margin-top: 15px; color: #666;">
            <strong>Interpretation:</strong> Each point represents a named observation site. 
            Sites with lower light pollution (VIIRS radiance) tend to support higher bird species richness, 
            consistent with the negative impact of artificial light at night on biodiversity.
        </p>
    </div>
</div>

<!-- Per Site Bird Richness -->
<div class="card">
    <h2 class="card-header">Per Site Bird Richness — Top 20 Sites (2014) <small class="data-source-badge data-source-csv">📊 CSV Dataset</small></h2>
    <div class="card-body">
        <canvas id="siteRichnessChart"></canvas>
        <div style="display: flex; gap: 16px; flex-wrap: wrap; margin-top: 12px; font-size: 0.85em;">
            <span><span style="display:inline-block;width:12px;height:12px;background:#2c5f2d;border-radius:2px;vertical-align:middle;"></span> Low light (&lt;15 nW/cm²/sr)</span>
            <span><span style="display:inline-block;width:12px;height:12px;background:#97bc62;border-radius:2px;vertical-align:middle;"></span> Moderate light (15–35 nW/cm²/sr)</span>
            <span><span style="display:inline-block;width:12px;height:12px;background:#ffc107;border-radius:2px;vertical-align:middle;"></span> High light (&gt;35 nW/cm²/sr)</span>
        </div>
        <p style="margin-top: 12px; color: #666;">
            <strong>Note:</strong> Average unique species count per observation site based on 2014 field data. 
            Green areas such as La Mesa Eco Park and wetland parks show the highest bird species richness.
        </p>
    </div>
</div>

<?php
// Inline data block — PHP values embedded before the nowdoc chart scripts
echo '<script>';
echo 'var _kbaData = '       . $kba_data_json   . ';';
echo 'var _siteData = '      . $site_data_json  . ';';
echo 'var _top20 = '         . $top20_json       . ';';
echo 'var _totalTolerant = ' . $total_tolerant   . ';';
echo 'var _totalSensitive = '. $total_sensitive  . ';';
echo 'var _totalResident = ' . $total_resident   . ';';
echo 'var _totalMigrant = '  . $total_migrant    . ';';
echo '</script>';

$extra_scripts = <<<'EOD'
<script>
// Correlation Matrix Heatmap (using bar chart as approximation)
const correlationData = {
    'Light vs Richness': -0.72,
    'NDVI vs Richness': 0.68,
    'Temp vs Richness': -0.45,
    'Elevation vs Richness': 0.23,
    'Light vs NDVI': -0.55,
    'Temp vs Light': 0.38
};

const ctx1 = document.getElementById('correlationChart').getContext('2d');
new Chart(ctx1, {
    type: 'bar',
    data: {
        labels: Object.keys(correlationData),
        datasets: [{
            label: 'Correlation Coefficient',
            data: Object.values(correlationData),
            backgroundColor: function(context) {
                const value = context.parsed.y;
                if (value > 0.5) return '#28a745';
                if (value > 0) return '#97bc62';
                if (value > -0.5) return '#ffc107';
                return '#dc3545';
            }
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        scales: {
            y: {
                beginAtZero: false,
                min: -1,
                max: 1,
                title: {
                    display: true,
                    text: 'Correlation Coefficient'
                }
            }
        }
    }
});

// Species by Area Chart
const kbaData = _kbaData;

const ctx2 = document.getElementById('areaSpeciesChart').getContext('2d');
new Chart(ctx2, {
    type: 'bar',
    data: {
        labels: kbaData.map(area => area.name.substring(0, 20)),
        datasets: [{
            label: 'Total Species',
            data: kbaData.map(area => area.species_count),
            backgroundColor: '#2c5f2d'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        indexAxis: 'y',
        plugins: {
            legend: { display: true, position: 'top' }
        },
        scales: {
            x: {
                beginAtZero: true
            }
        }
    }
});

// Scatter plot: Light vs Species
const ctx3 = document.getElementById('scatterChart').getContext('2d');
new Chart(ctx3, {
    type: 'scatter',
    data: {
        datasets: [{
            label: 'KBA/PA Areas',
            data: kbaData.map(area => ({
                x: area.light_exposure,
                y: area.species_count
            })),
            backgroundColor: '#2c5f2d'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
            legend: { display: true, position: 'top' }
        },
        scales: {
            x: {
                title: {
                    display: true,
                    text: 'Light Exposure'
                }
            },
            y: {
                title: {
                    display: true,
                    text: 'Species Count'
                }
            }
        }
    }
});

// Historical Trends
const ctx4 = document.getElementById('trendsChart').getContext('2d');
new Chart(ctx4, {
    type: 'line',
    data: {
        labels: ['2014', '2015', '2016', '2017', '2018', '2019', '2020', '2021', '2022', '2023', '2024'],
        datasets: [
            {
                label: 'Average Species Richness',
                data: [78, 79, 81, 80, 82, 83, 85, 83, 84, 88, 90],
                borderColor: '#2c5f2d',
                backgroundColor: 'rgba(44, 95, 45, 0.1)',
                fill: true
            },
            {
                label: 'Light Pollution Index',
                data: [68, 67, 66, 65, 64, 63, 64, 63, 58, 52, 48],
                borderColor: '#dc3545',
                backgroundColor: 'rgba(220, 53, 69, 0.1)',
                fill: true
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
            legend: { display: true, position: 'top' }
        },
        scales: {
            y: {
                beginAtZero: false
            }
        }
    }
});

// Export functions
function exportGeoJSON() {
    // In production, this would call api/export_geojson.php
    window.location.href = 'api/export_geojson.php';
}

function exportPDF() {
    // In production, this would call api/export_pdf.php
    alert('Generating PDF report... This would call api/export_pdf.php to create a comprehensive PDF with all charts and statistics.');
}

function exportCSV() {
    // Simple CSV export for KBA data
    let csv = 'Area Name,Type,Species Count,Light Exposure,Sensitive Species %,Effectiveness Score\n';
    kbaData.forEach(area => {
        csv += `"${area.name}",${area.type},${area.species_count},${area.light_exposure},${area.sensitive_species_percent},${area.effectiveness_score}\n`;
    });
    
    const blob = new Blob([csv], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'avilight_kba_report.csv';
    a.click();
    window.URL.revokeObjectURL(url);
}

// ── NEW CHARTS from final_avilight_2014_merged.csv ─────────────────────────

const siteData = _siteData;
const top20    = _top20;

// Light Tolerance Doughnut
const ctx5 = document.getElementById('toleranceChart').getContext('2d');
new Chart(ctx5, {
    type: 'doughnut',
    data: {
        labels: ['Tolerant', 'Sensitive'],
        datasets: [{
            data: [_totalTolerant, _totalSensitive],
            backgroundColor: ['#2c5f2d', '#dc3545'],
            borderWidth: 2
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: { position: 'bottom' },
            tooltip: {
                callbacks: {
                    label: function(ctx) {
                        const total = ctx.dataset.data.reduce((a, b) => a + b, 0);
                        const pct = ((ctx.parsed / total) * 100).toFixed(1);
                        return `${ctx.label}: ${ctx.parsed} (${pct}%)`;
                    }
                }
            }
        }
    }
});

// Migration Status Doughnut
const ctx6 = document.getElementById('migrationChart').getContext('2d');
new Chart(ctx6, {
    type: 'doughnut',
    data: {
        labels: ['Residents', 'Migrants'],
        datasets: [{
            data: [_totalResident, _totalMigrant],
            backgroundColor: ['#3a86ff', '#fb8500'],
            borderWidth: 2
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: { position: 'bottom' },
            tooltip: {
                callbacks: {
                    label: function(ctx) {
                        const total = ctx.dataset.data.reduce((a, b) => a + b, 0);
                        const pct = ((ctx.parsed / total) * 100).toFixed(1);
                        return `${ctx.label}: ${ctx.parsed} (${pct}%)`;
                    }
                }
            }
        }
    }
});

// Light Pollution vs Bird Richness Scatter
const lightRichnessData = siteData
    .filter(s => s.richness > 0)
    .map(s => ({ x: s.light, y: s.richness, label: s.name }));

const ctx7 = document.getElementById('lightRichnessChart').getContext('2d');
new Chart(ctx7, {
    type: 'scatter',
    data: {
        datasets: [{
            label: 'Observation Sites (2014)',
            data: lightRichnessData,
            backgroundColor: 'rgba(44, 95, 45, 0.7)',
            pointRadius: 6,
            pointHoverRadius: 8
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: { display: true, position: 'top' },
            tooltip: {
                callbacks: {
                    label: function(ctx) {
                        const pt = ctx.raw;
                        return `${pt.label} — Light: ${pt.x}, Richness: ${pt.y}`;
                    }
                }
            }
        },
        scales: {
            x: {
                title: { display: true, text: 'Avg Light Pollution (VIIRS radiance, nW/cm²/sr)' }
            },
            y: {
                title: { display: true, text: 'Avg Unique Species Count' },
                beginAtZero: true
            }
        }
    }
});

// Per Site Bird Richness (Top 20)
const ctx8 = document.getElementById('siteRichnessChart').getContext('2d');
new Chart(ctx8, {
    type: 'bar',
    data: {
        labels: top20.map(s => s.name.length > 30 ? s.name.substring(0, 28) + '…' : s.name),
        datasets: [{
            label: 'Avg Unique Species Count',
            data: top20.map(s => s.richness),
            backgroundColor: top20.map(s =>
                s.light < 15 ? '#2c5f2d' :
                s.light < 35 ? '#97bc62' :
                               '#ffc107'
            ),
            borderRadius: 4
        }]
    },
    options: {
        responsive: true,
        indexAxis: 'y',
        plugins: {
            legend: { display: false },
            tooltip: {
                callbacks: {
                    label: function(ctx) {
                        const s = top20[ctx.dataIndex];
                        return `Richness: ${ctx.parsed.x}  |  Light: ${s.light} nW/cm²/sr`;
                    }
                }
            }
        },
        scales: {
            x: {
                beginAtZero: true,
                title: { display: true, text: 'Avg Unique Species Count' }
            }
        }
    }
});
</script>
EOD;

require_once 'includes/footer.php';
?>
