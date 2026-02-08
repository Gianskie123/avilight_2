<?php
$page_title = 'Statistical Reports';
require_once 'includes/header.php';

// Load sample data
$kba_data = json_decode(file_get_contents('data/sample_kba.json'), true);
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
            <button class="btn btn-primary" onclick="exportGeoJSON()">📥 Download GeoJSON</button>
            <button class="btn btn-danger" onclick="exportPDF()">📄 Download PDF Report</button>
            <button class="btn btn-secondary" onclick="exportCSV()">📊 Export CSV Data</button>
        </div>
    </div>
</div>

<!-- Feature Correlation Matrix -->
<div class="card">
    <h2 class="card-header">Environmental Feature Correlation Matrix</h2>
    <div class="card-body">
        <div style="max-width: 800px; margin: 0 auto;">
            <canvas id="correlationChart"></canvas>
        </div>
        <p style="margin-top: 20px; color: #666;">
            <strong>Interpretation:</strong> The correlation matrix shows relationships between environmental 
            factors and bird species richness. Darker colors indicate stronger correlations. Light intensity 
            shows strong negative correlation (-0.72) with species count, while NDVI shows positive correlation (0.68).
        </p>
    </div>
</div>

<!-- KBA/PA Performance Audit -->
<div class="card">
    <h2 class="card-header">KBA/PA Performance Audit</h2>
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
        
        <div style="margin-top: 20px; padding: 15px; background: #f8f9fa; border-radius: 8px;">
            <h4>Audit Criteria:</h4>
            <ul>
                <li><strong>Grade A (80-100):</strong> Excellent protection, low light exposure, high species diversity</li>
                <li><strong>Grade B (70-79):</strong> Good protection, moderate effectiveness</li>
                <li><strong>Grade C (60-69):</strong> Fair protection, needs improvement</li>
                <li><strong>Grade D (&lt;60):</strong> Poor protection, urgent intervention required</li>
            </ul>
        </div>
    </div>
</div>

<!-- Species Distribution Analysis -->
<div class="grid-2">
    <div class="card">
        <h2 class="card-header">Species Distribution by Area</h2>
        <div class="card-body">
            <canvas id="areaSpeciesChart"></canvas>
        </div>
    </div>
    
    <div class="card">
        <h2 class="card-header">Light Exposure vs Species Count</h2>
        <div class="card-body">
            <canvas id="scatterChart"></canvas>
        </div>
    </div>
</div>

<!-- Temporal Trends -->
<div class="card">
    <h2 class="card-header">Historical Trends (2020-2026)</h2>
    <div class="card-body">
        <canvas id="trendsChart"></canvas>
        <p style="margin-top: 15px; color: #666;">
            <strong>Note:</strong> Historical data shows gradual improvement in species richness 
            following implementation of light pollution control measures in 2023.
        </p>
    </div>
</div>

<?php
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
const kbaData = <?php echo json_encode($kba_data); ?>;

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
        labels: ['2020', '2021', '2022', '2023', '2024', '2025', '2026'],
        datasets: [
            {
                label: 'Average Species Richness',
                data: [85, 83, 82, 84, 88, 92, 95],
                borderColor: '#2c5f2d',
                backgroundColor: 'rgba(44, 95, 45, 0.1)',
                fill: true
            },
            {
                label: 'Light Pollution Index',
                data: [65, 64, 63, 58, 52, 48, 45],
                borderColor: '#dc3545',
                backgroundColor: 'rgba(220, 53, 69, 0.1)',
                fill: true
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
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
</script>
EOD;

require_once 'includes/footer.php';
?>
