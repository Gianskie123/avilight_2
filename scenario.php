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
        <!-- City Selector -->
        <div class="slider-container">
            <div class="slider-label">
                <span>Target City:</span>
            </div>
            <select id="citySelect" class="form-control" style="max-width: 420px; margin-top: 8px;">
                <option value="Caloocan">Caloocan</option>
                <option value="Las Piñas">Las Piñas</option>
                <option value="Makati">Makati</option>
                <option value="Malabon">Malabon</option>
                <option value="Mandaluyong">Mandaluyong</option>
                <option value="Manila">Manila</option>
                <option value="Marikina">Marikina</option>
                <option value="Muntinlupa">Muntinlupa</option>
                <option value="Navotas">Navotas</option>
                <option value="Parañaque">Parañaque</option>
                <option value="Pasay">Pasay</option>
                <option value="Pasig">Pasig</option>
                <option value="Quezon City">Quezon City</option>
                <option value="San Juan">San Juan</option>
                <option value="Taguig">Taguig</option>
                <option value="Valenzuela">Valenzuela</option>
                <option value="Pateros">Pateros</option>
            </select>
            <p style="color: #666; font-size: 0.9rem;">
                Baseline environmental inputs are computed from this city using aligned historical years.
            </p>
        </div>

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

        <!-- Month Slider -->
        <div class="slider-container">
            <div class="slider-label">
                <span>Scenario Month:</span>
                <span id="monthValue">Jan</span>
            </div>
            <input type="range" min="1" max="12" value="1" step="1" class="slider" id="monthSlider">
            <p style="color: #666; font-size: 0.9rem;">
                Select month for seasonal baseline alignment and prediction.
            </p>
        </div>
        
        <!-- Run Scenario Button -->
        <button class="btn btn-primary" style="margin-top: 20px; padding: 15px 40px; font-size: 1.1rem;" onclick="runScenario()">
            🔄 Run Scenario Analysis
        </button>
    </div>
</div>

<!-- Diagnostics -->
<div class="card">
    <h2 class="card-header">Diagnostics</h2>
    <div class="card-body">
        <p style="color:#666; font-size:0.9rem;">Quick checks for the Scenario API and database connection.</p>
        <div style="display:flex; flex-wrap:wrap; gap:10px; align-items:center;">
            <button class="btn btn-secondary" type="button" onclick="runScenarioDiagnostics('db')">Test DB</button>
            <button class="btn btn-secondary" type="button" onclick="runScenarioDiagnostics('baseline')">Test Baseline (Prewarm)</button>
            <button class="btn btn-secondary" type="button" onclick="runScenarioDiagnostics('monthly')">Test Monthly Averages</button>
            <button class="btn btn-secondary" type="button" onclick="runScenarioDiagnostics('scenario')">Test Scenario API</button>
            <span id="scenarioDiagStatus" style="font-size:0.85rem; color:var(--text-secondary);"></span>
        </div>
        <pre id="scenarioDiagOutput" style="margin-top:12px; white-space:pre-wrap; background:var(--bg-card-alt); border:1px solid var(--border-color); border-radius:8px; padding:10px; font-size:0.82rem; color:var(--text-secondary); max-height:220px; overflow:auto;"></pre>
    </div>
</div>

<!-- Results Section -->
<div id="resultsSection" style="display: none;">
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-label">Tolerant</div>
            <div class="stat-value" id="tolerantOut">0</div>
            <div class="stat-description">Model output</div>
        </div>
        
        <div class="stat-card info">
            <div class="stat-label">Sensitive</div>
            <div class="stat-value" id="sensitiveOut">0</div>
            <div class="stat-description">Model output</div>
        </div>
        
        <div class="stat-card warning">
            <div class="stat-label">Resident</div>
            <div class="stat-value" id="residentOut">0</div>
            <div class="stat-description">Model output</div>
        </div>
        
        <div class="stat-card">
            <div class="stat-label">Migrant</div>
            <div class="stat-value" id="migrantOut">0</div>
            <div class="stat-description">Model output</div>
        </div>
    </div>

    <div class="card">
        <h2 class="card-header">Model SHAP Feature Importance</h2>
        <div class="card-body">
            <div style="display:flex; align-items:center; gap:8px; margin-bottom:8px;">
                <label for="shapOutputSelect" style="margin:0; font-weight:600;">Output:</label>
                <select id="shapOutputSelect" class="form-control" style="max-width:220px;">
                    <option value="all">All Outputs (Average)</option>
                    <option value="sensitive">Sensitive</option>
                    <option value="tolerant">Tolerant</option>
                    <option value="resident">Resident</option>
                    <option value="migrant">Migrant</option>
                </select>
            </div>
            <canvas id="shapChart"></canvas>
            <p style="margin-top: 10px; color: #666;">
                SHAP values use local sensitivity attribution for this scenario run.
            </p>
        </div>
    </div>
    
    <!-- Recovery Map Visualization -->
    <div class="card">
        <h2 class="card-header">Historical Baseline Inputs Used</h2>
        <div class="card-body" id="historicalInputsBox">
            <!-- Populated by JavaScript -->
        </div>
    </div>

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

document.getElementById('monthSlider').addEventListener('input', function() {
    const monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    const month = parseInt(this.value, 10);
    document.getElementById('monthValue').textContent = monthNames[month - 1];
});

// KBA data
const kbaData = <?php echo json_encode($kba_data); ?>;
let latestScenarioData = null;

function currentShapOutput() {
    const el = document.getElementById('shapOutputSelect');
    return el ? el.value : 'all';
}

function setScenarioDiag(status, details) {
    const statusEl = document.getElementById('scenarioDiagStatus');
    const outputEl = document.getElementById('scenarioDiagOutput');
    if (statusEl) statusEl.textContent = status || '';
    if (outputEl) outputEl.textContent = details || '';
}

async function runScenarioDiagnostics(kind) {
    setScenarioDiag('Running diagnostics...', '');
    try {
        if (kind === 'db') {
            const res = await fetch('health_db.php');
            const text = await res.text();
            setScenarioDiag('DB check complete (HTTP ' + res.status + ').', text.trim());
            return;
        }

        if (kind === 'monthly') {
            const qs = new URLSearchParams({
                scope: 'trend',
                selected_area: 'All Areas',
                start_year: '2024',
                end_year: '2025',
                snapshot_year: '2025',
                snapshot_month: '12',
                include_diagnostics: '1'
            });
            const t0 = performance.now();
            const res = await fetch('api/get_report_data.php?' + qs.toString());
            const text = await res.text();
            const ms = Math.round(performance.now() - t0);
            setScenarioDiag('Monthly averages check complete (HTTP ' + res.status + ', ' + ms + ' ms).', text.trim());
            return;
        }

        if (kind === 'baseline') {
            const city = document.getElementById('citySelect').value;
            const payload = {
                light_reduction: 0,
                ndvi_increase: 0,
                temp_change: 0,
                precip_change: 0,
                month: 1,
                city: city,
                prewarm_only: true
            };
            const res = await fetch('api/run_scenario.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const text = await res.text();
            setScenarioDiag('Baseline check complete (HTTP ' + res.status + ').', text.trim());
            return;
        }

        const city = document.getElementById('citySelect').value;
        const payload = {
            light_reduction: 0,
            ndvi_increase: 0,
            temp_change: 0,
            precip_change: 0,
            month: 1,
            city: city,
            shap_output: 'all',
            attribution_mode: 'sensitivity',
            meta_only: true
        };

        const res = await fetch('api/run_scenario.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const text = await res.text();
        setScenarioDiag('Scenario API check complete (HTTP ' + res.status + ').', text.trim());
    } catch (err) {
        setScenarioDiag('Diagnostics failed.', String(err || 'Unknown error'));
    }
}

// Run scenario analysis
async function runScenario() {
    // Get slider values
    const lightReduction = parseInt(document.getElementById('lightSlider').value);
    const ndviIncrease = parseInt(document.getElementById('ndviSlider').value);
    const tempChange = parseFloat(document.getElementById('tempSlider').value);
    const month = parseInt(document.getElementById('monthSlider').value, 10);
    const city = document.getElementById('citySelect').value;
    
    // Show loading state
    const btn = event.target;
    const originalText = btn.textContent;
    btn.textContent = '⏳ Analyzing...';
    btn.disabled = true;
    
    try {
        const response = await fetch('api/run_scenario.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                light_reduction: lightReduction,
                ndvi_increase: ndviIncrease,
                temp_change: tempChange,
                precip_change: 0,
                month: month,
                city: city,
                shap_output: currentShapOutput(),
                attribution_mode: 'sensitivity'
            })
        });
        
        if (!response.ok) {
            throw new Error(`API returned ${response.status}`);
        }
        
        const data = await response.json();
        
        if (!data.success) {
            throw new Error(data.error || 'Scenario analysis failed');
        }
        
        // Extract results from ML backend
        const results = data.results;
        const affectedAreas = data.affected_areas || [];
        const richness_change = results.richness_change_pct || 0;

        // Direct model outputs (requested 4 classes)
        document.getElementById('tolerantOut').textContent = results.tolerant ?? 0;
        document.getElementById('sensitiveOut').textContent = results.sensitive ?? 0;
        document.getElementById('residentOut').textContent = results.resident ?? 0;
        document.getElementById('migrantOut').textContent = results.migrant ?? 0;
        
        // Show results section
        document.getElementById('resultsSection').style.display = 'block';
        latestScenarioData = data;
        
        // Update visualizations
        updateRecoveryChart(richness_change, results);
        updateShapChart(data);
        updateAffectedAreas(affectedAreas);
        updateHistoricalInputs(data.historical_inputs, data.parameters);
        generateRecommendations(lightReduction, ndviIncrease, tempChange, richness_change);
        
        // Scroll to results
        document.getElementById('resultsSection').scrollIntoView({ behavior: 'smooth' });
        
    } catch (error) {
        console.error('Scenario analysis error:', error);
        
        // Check if Python backend is running
        if (error.message.includes('Failed to connect')) {
            alert('❌ Error: Python ML backend is not running.\n\n' +
                  'Start the backend with:\n' +
                  'uvicorn model:app --reload --port 5000');
        } else {
            alert('❌ Scenario analysis failed: ' + error.message);
        }
    } finally {
        btn.textContent = originalText;
        btn.disabled = false;
    }
}

function updateShapChart(data) {
    const ctx = document.getElementById('shapChart');
    if (!ctx) {
        return;
    }
    if (window.shapChartInstance) {
        window.shapChartInstance.destroy();
    }

    const selected = currentShapOutput();
    const byOutput = data && data.shap_by_output ? data.shap_by_output : {};
    const rows = selected === 'all'
        ? (data && Array.isArray(data.shap_chart) ? data.shap_chart : [])
        : (Array.isArray(byOutput[selected]) ? byOutput[selected] : []);

    const sorted = rows.slice()
        .sort((a, b) => (Number(b.importance) || 0) - (Number(a.importance) || 0));

    const labels = sorted.map(x => x.feature);
    const rawValues = sorted.map(x => Number(x.importance) || 0);
    const rawTotal = rawValues.reduce((sum, v) => sum + v, 0);
    const values = rawTotal > 0
        ? rawValues.map(v => (v / rawTotal) * 100)
        : rawValues.map(() => 0);

    window.shapChartInstance = new Chart(ctx, {
        type: 'bar',
        data: {
            labels,
            datasets: [{
                label: 'SHAP Share (%) (' + selected + ')',
                data: values,
                backgroundColor: '#4a7c59',
                rawValues: rawValues
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const pct = Number(context.parsed.x || 0).toFixed(2) + '%';
                            const raw = Number(context.dataset.rawValues?.[context.dataIndex] || 0).toFixed(6);
                            return 'Share: ' + pct + ' (raw: ' + raw + ')';
                        }
                    }
                }
            },
            scales: {
                x: {
                    beginAtZero: true,
                    max: 100,
                    title: {
                        display: true,
                        text: 'SHAP share of selected output (%)'
                    }
                }
            }
        }
    });
}

document.getElementById('shapOutputSelect').addEventListener('change', function() {
    if (latestScenarioData) {
        updateShapChart(latestScenarioData);
    }
});

function updateHistoricalInputs(historicalInputs, parameters) {
    const box = document.getElementById('historicalInputsBox');
    if (!historicalInputs) {
        box.innerHTML = '<p style="color:#777;">No historical baseline details returned by API.</p>';
        return;
    }

    const city = historicalInputs.city || (parameters?.city || 'Selected City');
    const land = historicalInputs.dominant_land_cover || {};
    const modelInputs = historicalInputs.model_inputs_used || {};
    const years = (historicalInputs.common_years || []).join(', ');
    const formulaNote = historicalInputs.formula_note || 'Baseline = value(last year) ± average annual change across aligned years';

    box.innerHTML = `
        <p><strong>City Scope:</strong> ${city}</p>
        <p><strong>Aligned Common Years:</strong> ${years}</p>
        <p><strong>Month:</strong> ${historicalInputs.month ?? (parameters?.month ?? 'N/A')}</p>
        <p><strong>Latest Common Year:</strong> ${historicalInputs.latest_common_year} | <strong>Baseline Year Used:</strong> ${historicalInputs.baseline_reference_year}</p>
        <p><strong>Formula:</strong> ${formulaNote}</p>
        <hr>
        <p><strong>Dominant Land Cover:</strong> ${land.label || 'Unknown'} (code: ${land.code ?? 'N/A'}, year: ${land.year ?? 'N/A'})</p>
        <p><strong>Base NDVI:</strong> ${(modelInputs.base_ndvi ?? 0).toFixed(4)} | <strong>Base VIIRS:</strong> ${(modelInputs.base_viirs ?? 0).toFixed(4)}</p>
        <p><strong>Base LST Day:</strong> ${(modelInputs.base_lst ?? 0).toFixed(4)} °C | <strong>Base Precip:</strong> ${(modelInputs.base_precip ?? 0).toFixed(4)} mm</p>
        <p><strong>Cells Included:</strong> ${historicalInputs.cell_count ?? 0}</p>
    `;
}

function updateRecoveryChart(impact, results) {
    const areas = ['Quezon City', 'Manila', 'Makati', 'Pasig', 'Taguig', 'Parañaque', 'Las Piñas', 'Muntinlupa'];
    const baseValues = [12, 8, 6, 10, 14, 9, 11, 13];
    const changePercent = impact / 100;
    const recoveryValues = baseValues.map(v => v * (1 + changePercent));
    
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
                    label: 'Baseline Richness',
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

function updateAffectedAreas(affectedAreas) {
    const tbody = document.getElementById('affectedAreasTable');
    let html = '';
    
    if (affectedAreas && affectedAreas.length > 0) {
        affectedAreas.forEach(area => {
            const change = area.change || 0;
            const impact = area.impact_level || 'Low';
            
            html += `
                <tr>
                    <td>${area.name}</td>
                    <td>${area.current}</td>
                    <td>${area.predicted}</td>
                    <td style="color: ${change >= 0 ? 'green' : 'red'}; font-weight: bold;">
                        ${change >= 0 ? '+' : ''}${change}
                    </td>
                    <td><span class="badge ${impact === 'High' ? 'badge-danger' : impact === 'Medium' ? 'badge-warning' : 'badge-info'}">${impact}</span></td>
                </tr>
            `;
        });
    } else {
        html = '<tr><td colspan="5" style="text-align: center; color: #999;">No data available</td></tr>';
    }
    
    tbody.innerHTML = html;
}

function generateRecommendations(light, ndvi, temp, totalImpact) {
    const recDiv = document.getElementById('recommendations');
    let recommendations = '';
    
    if (totalImpact > 5) {
        recommendations += '<div class="alert alert-info"><strong>✓ Positive Scenario:</strong> ML models predict significant improvement in bird species richness. The combined effect of these interventions appears highly beneficial.</div>';
    } else if (totalImpact < -5) {
        recommendations += '<div class="alert alert-danger"><strong>⚠ Negative Scenario:</strong> Models predict decline in species diversity. Temperature increases and vegetation loss appear detrimental. Consider alternative approaches.</div>';
    } else {
        recommendations += '<div class="alert alert-warning"><strong>→ Neutral Scenario:</strong> Minimal predicted change from baseline. More aggressive or targeted interventions may be needed for significant impact.</div>';
    }
    
    if (light > 20) {
        recommendations += '<p><strong>Light Reduction:</strong> A ' + light + '% reduction is ambitious but highly beneficial per model analysis. Consider phased implementation starting with critical KBA/PA buffer zones (0-500m), then extending to broader zones.</p>';
    }
    
    if (ndvi > 10) {
        recommendations += '<p><strong>Urban Greening:</strong> Increasing vegetation by ' + ndvi + '% requires significant urban forestry investment. Models show strong positive correlation with sensitive species. Prioritize La Mesa Watershed and Marikina Watershed for maximum impact.</p>';
    }
    
    if (temp !== 0) {
        recommendations += '<p><strong>Climate Consideration:</strong> Temperature changes of ' + temp + '°C are largely beyond local policy control but critical for long-term planning. Models account for thermal sensitivities of different species groups. Focus on climate adaptation strategies including establishing thermal refugia in riparian zones.</p>';
    }
    
    recommendations += '<hr><p><strong>Priority Actions (ML-Informed):</strong></p><ul>';
    recommendations += '<li><strong>Immediate (0-6 months):</strong> Implement strict lighting ordinances in protected area buffer zones (500m radius)</li>';
    recommendations += '<li><strong>Short-term (6-18 months):</strong> Launch community-based urban tree planting programs in identified biodiversity hotspots</li>';
    recommendations += '<li><strong>Ongoing:</strong> Monitor migratory bird populations during peak seasons (Sep-Nov) to validate model predictions</li>';
    recommendations += '<li><strong>Annual:</strong> Conduct VIIRS-based light pollution audits and update baseline environmental covariates for model refinement</li>';
    recommendations += '</ul>';
    
    recDiv.innerHTML = recommendations;
}
</script>
EOD;

require_once 'includes/footer.php';
?>
