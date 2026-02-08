<?php
$page_title = 'Scenario Modeling';
require_once 'includes/header.php';

// Load sample data
$kba_data = json_decode(file_get_contents('data/sample_kba.json'), true);
?>

<div class="page-header">
    <h1 class="page-title">Scenario Modeling - What-If Analysis</h1>
    <p class="page-subtitle">Simulate policy changes and predict their impact on bird species richness</p>
</div>

<!-- Scenario Controls -->
<div class="card">
    <h2 class="card-header">Policy Simulation Parameters</h2>
    <div class="card-body">
        <!-- Light Reduction Slider -->
        <div class="slider-container">
            <div class="slider-label">
                <span>Light Reduction:</span>
                <span id="lightValue">0%</span>
            </div>
            <input type="range" min="0" max="50" value="0" class="slider" id="lightSlider">
            <p style="color: #666; font-size: 0.9rem;">
                Simulate reduction in artificial light at night (ALAN) through policy interventions
            </p>
        </div>
        
        <!-- NDVI Increase Slider -->
        <div class="slider-container">
            <div class="slider-label">
                <span>NDVI Increase (Vegetation):</span>
                <span id="ndviValue">0%</span>
            </div>
            <input type="range" min="0" max="20" value="0" class="slider" id="ndviSlider">
            <p style="color: #666; font-size: 0.9rem;">
                Simulate urban greening initiatives and tree planting programs
            </p>
        </div>
        
        <!-- Temperature Change Slider -->
        <div class="slider-container">
            <div class="slider-label">
                <span>Temperature Change:</span>
                <span id="tempValue">0°C</span>
            </div>
            <input type="range" min="-2" max="2" value="0" step="0.5" class="slider" id="tempSlider">
            <p style="color: #666; font-size: 0.9rem;">
                Account for climate change scenarios (RCP 4.5 to RCP 8.5)
            </p>
        </div>
        
        <!-- Run Scenario Button -->
        <button class="btn btn-primary" style="margin-top: 20px; padding: 15px 40px; font-size: 1.1rem;" onclick="runScenario()">
            🔄 Run Scenario Analysis
        </button>
    </div>
</div>

<!-- Results Section -->
<div id="resultsSection" style="display: none;">
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-label">Predicted Species Gain</div>
            <div class="stat-value" id="speciesGain">+0</div>
            <div class="stat-description">Additional species expected</div>
        </div>
        
        <div class="stat-card info">
            <div class="stat-label">Overall Richness Change</div>
            <div class="stat-value" id="richnessChange">0%</div>
            <div class="stat-description">Average across Metro Manila</div>
        </div>
        
        <div class="stat-card warning">
            <div class="stat-label">Light-Sensitive Species</div>
            <div class="stat-value" id="sensitiveGain">+0</div>
            <div class="stat-description">Most benefited group</div>
        </div>
        
        <div class="stat-card">
            <div class="stat-label">Confidence Score</div>
            <div class="stat-value" id="confidence">0%</div>
            <div class="stat-description">Model prediction reliability</div>
        </div>
    </div>
    
    <!-- Recovery Map Visualization -->
    <div class="card">
        <h2 class="card-header">Predicted Recovery Heatmap</h2>
        <div class="card-body">
            <canvas id="recoveryChart"></canvas>
            <p style="margin-top: 15px; color: #666;">
                <strong>Interpretation:</strong> Areas shown in green are predicted to experience 
                the greatest increase in species richness under the selected scenario parameters.
            </p>
        </div>
    </div>
    
    <!-- Affected KBA/PA List -->
    <div class="card">
        <h2 class="card-header">Affected Protected Areas</h2>
        <div class="card-body">
            <table>
                <thead>
                    <tr>
                        <th>Area Name</th>
                        <th>Current Species</th>
                        <th>Predicted Species</th>
                        <th>Change</th>
                        <th>Impact Level</th>
                    </tr>
                </thead>
                <tbody id="affectedAreasTable">
                    <!-- Populated by JavaScript -->
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- Policy Recommendations -->
    <div class="card">
        <h2 class="card-header">Policy Recommendations</h2>
        <div class="card-body" id="recommendations">
            <!-- Populated by JavaScript -->
        </div>
    </div>
</div>

<?php
$extra_scripts = <<<'EOD'
<script>
// Update slider values
document.getElementById('lightSlider').addEventListener('input', function() {
    document.getElementById('lightValue').textContent = this.value + '%';
});

document.getElementById('ndviSlider').addEventListener('input', function() {
    document.getElementById('ndviValue').textContent = this.value + '%';
});

document.getElementById('tempSlider').addEventListener('input', function() {
    const val = parseFloat(this.value);
    document.getElementById('tempValue').textContent = (val >= 0 ? '+' : '') + val + '°C';
});

// KBA data
const kbaData = <?php echo json_encode($kba_data); ?>;

// Run scenario analysis
function runScenario() {
    // Get slider values
    const lightReduction = parseInt(document.getElementById('lightSlider').value);
    const ndviIncrease = parseInt(document.getElementById('ndviSlider').value);
    const tempChange = parseFloat(document.getElementById('tempSlider').value);
    
    // Calculate impacts (simplified model for demonstration)
    // In production, this would call the API with ML model
    const lightImpact = lightReduction * 0.3; // Each 1% light reduction = 0.3% richness increase
    const ndviImpact = ndviIncrease * 0.5; // Each 1% NDVI increase = 0.5% richness increase
    const tempImpact = tempChange * -2; // Each 1°C increase = -2% richness
    
    const totalImpact = lightImpact + ndviImpact + tempImpact;
    const speciesGain = Math.round(totalImpact * 0.3); // Rough conversion to species count
    const sensitiveGain = Math.round(speciesGain * 1.5); // Sensitive species benefit more
    
    // Calculate confidence based on how extreme the changes are
    const extremeness = Math.abs(lightReduction/50) + Math.abs(ndviIncrease/20) + Math.abs(tempChange/2);
    const confidence = Math.max(55, Math.min(95, 95 - extremeness * 15));
    
    // Update stats
    document.getElementById('speciesGain').textContent = speciesGain >= 0 ? '+' + speciesGain : speciesGain;
    document.getElementById('richnessChange').textContent = (totalImpact >= 0 ? '+' : '') + totalImpact.toFixed(1) + '%';
    document.getElementById('sensitiveGain').textContent = '+' + sensitiveGain;
    document.getElementById('confidence').textContent = confidence.toFixed(0) + '%';
    
    // Show results section
    document.getElementById('resultsSection').style.display = 'block';
    
    // Update recovery chart
    updateRecoveryChart(totalImpact);
    
    // Update affected areas table
    updateAffectedAreas(totalImpact);
    
    // Generate recommendations
    generateRecommendations(lightReduction, ndviIncrease, tempChange, totalImpact);
    
    // Scroll to results
    document.getElementById('resultsSection').scrollIntoView({ behavior: 'smooth' });
}

function updateRecoveryChart(impact) {
    const areas = ['Quezon City', 'Manila', 'Makati', 'Pasig', 'Taguig', 'Parañaque', 'Las Piñas', 'Muntinlupa'];
    const baseValues = [12, 8, 6, 10, 14, 9, 11, 13];
    const recoveryValues = baseValues.map(v => v * (1 + impact/100));
    
    const ctx = document.getElementById('recoveryChart');
    
    // Destroy existing chart if it exists
    if (window.recoveryChartInstance) {
        window.recoveryChartInstance.destroy();
    }
    
    window.recoveryChartInstance = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: areas,
            datasets: [
                {
                    label: 'Current Richness',
                    data: baseValues,
                    backgroundColor: '#97bc62'
                },
                {
                    label: 'Predicted Richness',
                    data: recoveryValues,
                    backgroundColor: '#2c5f2d'
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            scales: {
                y: {
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: 'Species Richness'
                    }
                }
            }
        }
    });
}

function updateAffectedAreas(impact) {
    const tbody = document.getElementById('affectedAreasTable');
    tbody.innerHTML = '';
    
    kbaData.forEach(area => {
        const currentSpecies = area.species_count;
        const predictedSpecies = Math.round(currentSpecies * (1 + impact/100));
        const change = predictedSpecies - currentSpecies;
        const impactLevel = Math.abs(change) > 10 ? 'High' : Math.abs(change) > 5 ? 'Medium' : 'Low';
        
        const row = `
            <tr>
                <td>${area.name}</td>
                <td>${currentSpecies}</td>
                <td>${predictedSpecies}</td>
                <td style="color: ${change >= 0 ? 'green' : 'red'}; font-weight: bold;">
                    ${change >= 0 ? '+' : ''}${change}
                </td>
                <td><span class="badge ${impactLevel === 'High' ? 'badge-danger' : impactLevel === 'Medium' ? 'badge-warning' : 'badge-info'}">${impactLevel}</span></td>
            </tr>
        `;
        tbody.innerHTML += row;
    });
}

function generateRecommendations(light, ndvi, temp, totalImpact) {
    const recDiv = document.getElementById('recommendations');
    let recommendations = '';
    
    if (totalImpact > 5) {
        recommendations += '<div class="alert alert-info"><strong>✓ Positive Scenario:</strong> This scenario predicts significant improvement in bird species richness.</div>';
    } else if (totalImpact < -5) {
        recommendations += '<div class="alert alert-danger"><strong>⚠ Negative Scenario:</strong> This scenario predicts decline in species diversity. Consider alternative approaches.</div>';
    } else {
        recommendations += '<div class="alert alert-warning"><strong>→ Neutral Scenario:</strong> Minimal predicted change. More aggressive interventions may be needed.</div>';
    }
    
    if (light > 20) {
        recommendations += '<p><strong>Light Reduction:</strong> A ' + light + '% reduction is ambitious but highly beneficial. Consider phased implementation starting with critical KBA/PA buffer zones.</p>';
    }
    
    if (ndvi > 10) {
        recommendations += '<p><strong>Urban Greening:</strong> Increasing vegetation by ' + ndvi + '% requires significant urban forestry investment. Prioritize La Mesa Watershed and Marikina Watershed for maximum impact.</p>';
    }
    
    if (temp !== 0) {
        recommendations += '<p><strong>Climate Consideration:</strong> Temperature changes of ' + temp + '°C are beyond policy control but important for long-term planning. Focus on climate adaptation strategies.</p>';
    }
    
    recommendations += '<hr><p><strong>Priority Actions:</strong></p><ul>';
    recommendations += '<li>Implement strict lighting ordinances in protected area buffer zones (500m radius)</li>';
    recommendations += '<li>Launch community-based urban tree planting programs in identified hotspots</li>';
    recommendations += '<li>Monitor migratory bird populations during peak seasons (Sep-Nov)</li>';
    recommendations += '<li>Conduct annual VIIRS-based light pollution audits</li>';
    recommendations += '</ul>';
    
    recDiv.innerHTML = recommendations;
}
</script>
EOD;

require_once 'includes/footer.php';
?>
