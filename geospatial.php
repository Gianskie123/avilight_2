<?php
$page_title = 'Geospatial Forecasting';
require_once 'includes/header.php';

// Load real observation data from DB (most recent visit per site, top 200 richest)
require_once 'includes/db.php';
$pdo = get_db();
$obs_rows = get_analytics_latest_sites($pdo, 200, 86400);

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
        <div id="geoControlBar" style="display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:8px; padding:10px 14px; border-bottom:1px solid var(--border-color); background:var(--bg-card-alt);">
            <div style="font-size:0.92rem; font-weight:600; color:var(--text-primary);">Metro Manila Environmental Map</div>
            <div style="font-size:0.78rem; color:var(--text-muted);">Analytics controls are simplified to map + prediction panels.</div>
        </div>

        <div id="lcDropdown" style="display:none;">
            <input type="checkbox" class="lc-filter" value="13" checked>
            <input type="checkbox" class="lc-filter" value="17" checked>
            <input type="checkbox" class="lc-filter" value="2" checked>
            <input type="checkbox" class="lc-filter" value="12" checked>
            <input type="checkbox" class="lc-filter" value="10" checked>
            <input type="checkbox" class="lc-filter" value="11" checked>
            <input type="checkbox" class="lc-filter" value="9" checked>
            <input type="checkbox" class="lc-filter" value="8" checked>
            <input type="checkbox" class="lc-filter" value="14" checked>
            <input type="checkbox" class="lc-filter" value="16" checked>
        </div>
        <!-- ── End Control Bar ── -->

        <div class="map-container" style="border-radius:0 0 8px 8px; overflow:hidden;">
            <div id="map"></div>
            <div id="loading" style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); display: none;">
                <div class="loading"></div>
                <p>Loading map data…</p>
            </div>

            <!-- Prediction Heatmap Legend -->
            <div class="legend" id="legendPrediction">
                <strong>Predicted Species Richness</strong>
                <div style="display: flex; align-items: center; margin-top: 8px;">
                    <span class="legend-label" style="margin-right: 6px;">Low</span>
                    <div style="flex: 1; height: 14px; border-radius: 6px; background: linear-gradient(to right, #1f2a7d, #1f4fbf, #2e7de0, #66c2ff, #f2b628);"></div>
                    <span class="legend-label" style="margin-left: 6px;">High</span>
                </div>
                <div style="display: flex; justify-content: space-between; margin-top: 4px;">
                    <span class="legend-label">0</span>
                    <span class="legend-label">12</span>
                    <span class="legend-label">25</span>
                    <span class="legend-label">37</span>
                    <span class="legend-label">50</span>
                </div>
                <div class="legend-label" style="margin-top:8px; color:var(--text-secondary);">Gray = no prediction yet</div>
            </div>

            <!-- Hint label -->
            <div id="mapHint" style="position:absolute; bottom:12px; left:50%; transform:translateX(-50%); background:var(--bg-overlay); color:var(--text-primary); border:1px solid var(--border-color); font-size:0.78rem; padding:4px 12px; border-radius:20px; z-index:900; pointer-events:none;">
                Hover a city area to preview predictions
            </div>
        </div>
    </div>
</div>

<div class="card" id="analyticsScenarioSection">
    <div class="card-body" style="padding:16px;">
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:18px; align-items:start;">
            <div id="bauLeftPanel">
                <div style="font-size:1.02rem; font-weight:700; margin-bottom:4px;">Business as Usual (BAU)</div>
                <div style="font-size:0.84rem; color:var(--text-secondary); margin-bottom:10px;">Inputs are locked to historical trend averages derived from nighttime radiance and environmental records. No manual adjustment.</div>

                <div class="form-group" style="margin-bottom:8px;">
                    <label class="form-label">City / Municipality</label>
                    <select id="bauCitySelect" class="form-control"></select>
                </div>

                <div style="display:flex; justify-content:space-between; align-items:center; padding:8px 10px; border:1px solid var(--border-color); border-radius:6px; margin-bottom:10px; background:var(--bg-card-alt);">
                    <span id="bauLandcoverName" style="font-weight:600;">Urban &amp; Built-up</span>
                    <span id="bauLandcoverShare" style="font-size:0.8rem; color:var(--text-secondary);">0% cover</span>
                </div>

                <div style="font-size:0.86rem; font-weight:600; margin-bottom:8px; color:var(--text-secondary);">Historical Average Inputs (locked)</div>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-bottom:10px;">
                    <div class="card" style="margin:0; padding:10px; border:1px solid rgba(245,158,11,.35); background:rgba(245,158,11,.06);">
                        <div style="font-size:0.76rem; color:var(--text-secondary);">Nighttime Radiance (ALAN)</div>
                        <div id="bauAlanVal" style="font-size:1.28rem; font-weight:700; line-height:1.15;">48 nW/cm²/sr</div>
                        <div id="bauAlanNote" style="font-size:0.7rem; color:var(--text-secondary); margin-top:3px;">2023 baseline: 59 nW · -1.2 nW/yr avg</div>
                    </div>
                    <div class="card" style="margin:0; padding:10px; border:1px solid rgba(34,197,94,.35); background:rgba(34,197,94,.06);">
                        <div style="font-size:0.76rem; color:var(--text-secondary);">NDVI (Vegetation Cover)</div>
                        <div id="bauNdviVal" style="font-size:1.28rem; font-weight:700; line-height:1.15;">56%</div>
                        <div id="bauNdviNote" style="font-size:0.7rem; color:var(--text-secondary); margin-top:3px;">2023 baseline: 52% · +0.5%/yr avg</div>
                    </div>
                    <div class="card" style="margin:0; padding:10px; border:1px solid rgba(244,63,94,.35); background:rgba(244,63,94,.06);">
                        <div style="font-size:0.76rem; color:var(--text-secondary);">Land Surface Temp</div>
                        <div id="bauTempVal" style="font-size:1.28rem; font-weight:700; line-height:1.15;">31°C</div>
                        <div id="bauTempNote" style="font-size:0.7rem; color:var(--text-secondary); margin-top:3px;">2023 baseline: 31.7°C · +0.1°C/yr avg</div>
                    </div>
                    <div class="card" style="margin:0; padding:10px; border:1px solid rgba(59,130,246,.35); background:rgba(59,130,246,.06);">
                        <div style="font-size:0.76rem; color:var(--text-secondary);">Mean Precipitation</div>
                        <div id="bauPrecipVal" style="font-size:1.28rem; font-weight:700; line-height:1.15;">150 mm</div>
                        <div id="bauPrecipNote" style="font-size:0.7rem; color:var(--text-secondary); margin-top:3px;">2023 baseline: 143 mm · -0.8 mm/yr avg</div>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom:10px;">
                    <label class="form-label">Month <span id="bauMonthBadge" style="margin-left:8px; font-size:0.76rem; color:var(--text-secondary);">January</span></label>
                    <input type="range" id="bauMonthSlider" class="slider" min="1" max="12" value="1" step="1">
                    <div style="font-size:0.72rem; color:var(--text-secondary); margin-top:4px;">Mitigation scenario will use the same month.</div>
                </div>

                <button class="btn btn-primary" style="width:100%;" id="runBauBtn">Run BAU Prediction</button>
            </div>

            <div id="bauRightPanel" style="overflow-y:auto; padding-right:4px;">
                <div id="bauResultHeading" style="font-size:1.02rem; font-weight:700; margin-bottom:4px;">BAU Prediction Result</div>
                <div style="font-size:0.84rem; color:var(--text-secondary); margin-bottom:10px;">Projected species richness if current environmental trends continue unchanged.</div>

                <div id="bauResultEmpty" class="card" style="margin:0; padding:20px; text-align:center; color:var(--text-muted);">Select a city and run the BAU prediction<br>Historical inputs will be used automatically.</div>
                <div id="bauResultContent" style="display:none;">
                    <div class="card" style="margin:0 0 10px 0; padding:12px;">
                        <div id="bauResultTitle" style="font-size:0.82rem; color:var(--text-secondary);">BAU TOTAL PREDICTED</div>
                        <div id="bauTotalPred" style="font-size:2.2rem; font-weight:800; line-height:1; text-align:right;">0</div>
                        <div id="bauInputUsed" style="margin-top:8px; font-size:0.78rem; color:var(--text-secondary);"></div>
                    </div>

                    <div class="card" style="margin:0 0 10px 0; padding:12px;">
                        <div style="display:grid; gap:8px;">
                            <div><div style="display:flex; justify-content:space-between;"><span>Light Sensitive</span><strong id="bauSensitiveVal">0</strong></div><div class="geo-bar-track"><div id="bauSensitiveBar" class="geo-bar-fill geo-bar-red" style="width:0%;"></div></div></div>
                            <div><div style="display:flex; justify-content:space-between;"><span>Light Tolerant</span><strong id="bauTolerantVal">0</strong></div><div class="geo-bar-track"><div id="bauTolerantBar" class="geo-bar-fill geo-bar-blue" style="width:0%;"></div></div></div>
                            <div><div style="display:flex; justify-content:space-between;"><span>Resident</span><strong id="bauResidentVal">0</strong></div><div class="geo-bar-track"><div id="bauResidentBar" class="geo-bar-fill geo-bar-green" style="width:0%;"></div></div></div>
                            <div><div style="display:flex; justify-content:space-between;"><span>Migratory</span><strong id="bauMigratoryVal">0</strong></div><div class="geo-bar-track"><div id="bauMigratoryBar" class="geo-bar-fill geo-bar-yellow" style="width:0%;"></div></div></div>
                        </div>
                    </div>

                    <div class="card" style="margin:0; padding:12px;">
                        <div id="bauShapTitle" style="font-weight:700; margin-bottom:2px;">Feature Importance (SHAP) — —</div>
                        <div id="bauShapSubtitle" style="font-size:0.78rem; color:var(--text-secondary); margin-bottom:8px;">Local SHAP values for — · —</div>
                        <div style="margin-bottom:8px; display:flex; align-items:center; gap:8px;">
                            <label for="bauShapOutputSelect" style="font-size:0.78rem; color:var(--text-secondary);">Output:</label>
                            <select id="bauShapOutputSelect" class="form-control" style="max-width:200px;">
                                <option value="all">All Outputs (Average)</option>
                                <option value="sensitive">Sensitive</option>
                                <option value="tolerant">Tolerant</option>
                                <option value="resident">Resident</option>
                                <option value="migrant">Migrant</option>
                            </select>
                        </div>
                        <canvas id="bauShapCanvas" height="170"></canvas>
                        <div id="bauShapText" style="margin-top:8px; font-size:0.83rem; color:var(--text-secondary);"></div>
                        <div style="margin-top:10px; font-size:0.82rem; font-weight:700;">Output Waterfall (Baseline to Predicted)</div>
                        <canvas id="bauWaterfallCanvas" height="150"></canvas>
                        <div id="bauWaterfallText" style="margin-top:6px; font-size:0.76rem; color:var(--text-secondary);"></div>
                        <div id="bauAfterRunNote" style="margin-top:8px; font-size:0.78rem; color:var(--accent-green);">✅ BAU baseline locked. Now configure the <em>Mitigation Scenario</em> below.</div>
                    </div>
                </div>
            </div>
        </div>

        <div id="mitigationScenarioSection" style="margin-top:16px; border-top:1px solid var(--border-color); padding-top:14px; display:none; grid-template-columns:1fr 1fr; gap:18px; align-items:start;">
            <div id="mitLeftPanel">
                <div style="font-size:1.02rem; font-weight:700; margin-bottom:4px;">Mitigation Scenario</div>
                <div style="font-size:0.84rem; color:var(--text-secondary); margin-bottom:10px;">Adjust each slider relative to the historical baseline. Centre = no change. Move left to worsen, right to improve conditions.</div>

                <div class="form-group" style="margin-bottom:8px;">
                    <label class="form-label">Nighttime Radiance (ALAN) <span id="mitAlanBaseline" style="color:var(--text-secondary); font-weight:400;">Baseline: 48 nW</span> <span id="mitAlanBadge" style="float:right; color:var(--text-secondary);">48 nW</span></label>
                    <input type="range" id="mitAlanSlider" class="slider" min="-30" max="30" value="0" step="1">
                    <div style="display:flex; justify-content:space-between; font-size:0.7rem; margin-top:2px;"><span style="color:var(--accent-green);">← Reduce pollution</span><span style="color:var(--accent-red);">More pollution →</span></div>
                </div>
                <div class="form-group" style="margin-bottom:8px;">
                    <label class="form-label">NDVI (Vegetation Cover) <span id="mitNdviBaseline" style="color:var(--text-secondary); font-weight:400;">Baseline: 56%</span> <span id="mitNdviBadge" style="float:right; color:var(--text-secondary);">56%</span></label>
                    <input type="range" id="mitNdviSlider" class="slider" min="-30" max="30" value="0" step="1">
                    <div style="display:flex; justify-content:space-between; font-size:0.7rem; margin-top:2px;"><span style="color:var(--accent-red);">← Less vegetation</span><span style="color:var(--accent-green);">More vegetation →</span></div>
                </div>
                <div class="form-group" style="margin-bottom:8px;">
                    <label class="form-label">Land Surface Temperature <span id="mitTempBaseline" style="color:var(--text-secondary); font-weight:400;">Baseline: 31°C</span> <span id="mitTempBadge" style="float:right; color:var(--text-secondary);">31.0°C</span></label>
                    <input type="range" id="mitTempSlider" class="slider" min="-20" max="20" value="0" step="1">
                    <div style="display:flex; justify-content:space-between; font-size:0.7rem; margin-top:2px;"><span style="color:var(--accent-green);">← Cooler (urban greening)</span><span style="color:var(--accent-red);">Warmer →</span></div>
                </div>
                <div class="form-group" style="margin-bottom:10px;">
                    <label class="form-label">Precipitation <span id="mitPrecipBaseline" style="color:var(--text-secondary); font-weight:400;">Baseline: 150 mm</span> <span id="mitPrecipBadge" style="float:right; color:var(--text-secondary);">150 mm</span></label>
                    <input type="range" id="mitPrecipSlider" class="slider" min="-30" max="30" value="0" step="1">
                    <div style="display:flex; justify-content:space-between; font-size:0.7rem; margin-top:2px;"><span style="color:var(--accent-red);">← Drier conditions</span><span style="color:var(--accent-blue);">More rainfall →</span></div>
                </div>

                <div class="card" style="margin:0 0 10px 0; padding:10px; border:1px solid var(--border-color); background:var(--bg-card-alt);">
                    <div style="font-size:0.9rem; font-weight:700; margin-bottom:4px;">Counterfactual Goal Finder</div>
                    <div style="font-size:0.75rem; color:var(--text-secondary); margin-bottom:8px;">Set a target gain and get an attribution-weighted mitigation slider plan.</div>
                    <div style="display:flex; align-items:center; gap:8px; margin-bottom:8px;">
                        <label for="goalGainInput" style="font-size:0.76rem; color:var(--text-secondary);">Target gain (species)</label>
                        <input id="goalGainInput" type="number" class="form-control" min="1" max="50" step="1" value="3" style="max-width:96px;">
                    </div>
                    <div style="display:flex; gap:8px; margin-bottom:8px;">
                        <button class="btn btn-primary" id="goalSuggestBtn" style="flex:1;">Suggest Plan</button>
                        <button class="btn" id="goalApplyBtn" style="flex:1; background:var(--bg-elevated); border:1px solid var(--border-color); color:var(--text-primary);">Apply Sliders</button>
                    </div>
                    <div id="goalFinderText" style="font-size:0.76rem; color:var(--text-secondary);">Run BAU first to unlock recommendation.</div>
                </div>

                <button class="btn btn-success" style="width:100%;" id="runMitigationBtn">Run Mitigation Prediction</button>
            </div>

            <div id="mitRightPanel" style="overflow-y:auto; padding-right:4px;">
                <div style="font-size:1.02rem; font-weight:700; margin-bottom:4px;">Scenario Comparison</div>
                <div style="font-size:0.84rem; color:var(--text-secondary); margin-bottom:10px;">Side-by-side delta between BAU and Mitigation outcomes.</div>
                <div id="cmpEmpty" class="card" style="margin:0; padding:20px; text-align:center; color:var(--text-muted);">Adjust the mitigation sliders<br>then click Run Mitigation Prediction to see the difference.</div>
                <div id="cmpContent" class="card" style="margin:0; padding:12px; display:none;">
                    <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:8px; text-align:center; margin-bottom:10px;">
                        <div><div style="font-size:0.75rem; color:var(--text-secondary);">BAU</div><div id="cmpBauTotal" style="font-size:2rem; font-weight:800;">0</div><div style="font-size:0.78rem; color:var(--text-secondary);">species</div></div>
                        <div><div style="font-size:0.75rem; color:var(--text-secondary);">Δ Gain</div><div id="cmpDelta" style="font-size:2rem; font-weight:800; color:var(--accent-green);">+0</div><div id="cmpDeltaPct" style="font-size:0.78rem; color:var(--text-secondary);">0%</div></div>
                        <div><div style="font-size:0.75rem; color:var(--text-secondary);">MITIGATION</div><div id="cmpMitTotal" style="font-size:2rem; font-weight:800; color:var(--accent-green);">0</div><div style="font-size:0.78rem; color:var(--text-secondary);">species</div></div>
                    </div>
                    <table style="width:100%;">
                        <thead>
                            <tr><th>Category</th><th>BAU</th><th>Mitigation</th><th>Change</th></tr>
                        </thead>
                        <tbody id="cmpRows"></tbody>
                    </table>
                    <div id="cmpSummary" style="margin-top:10px; font-size:0.84rem; color:var(--text-secondary);"></div>
                    <div id="cmpInputSummary" style="margin-top:8px; font-size:0.78rem; color:var(--text-secondary);"></div>
                    <div style="margin-top:10px; font-size:0.82rem; font-weight:700;">Class Contribution Stacked Bars</div>
                    <canvas id="cmpStackedCanvas" height="150"></canvas>
                    <div style="margin-top:10px; font-size:0.78rem; color:var(--text-secondary); font-style:italic;">Predictions are generated from the connected ML backend using database-derived baselines.</div>
                </div>

                <div class="card" style="margin:10px 0 0 0; padding:12px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; gap:8px;">
                        <div style="font-size:0.9rem; font-weight:700;">Monthly Driver Heatmap</div>
                        <button class="btn btn-primary" id="loadHeatmapBtn" style="padding:6px 10px; font-size:0.76rem;">Load 12-Month Heatmap</button>
                    </div>
                    <div id="heatmapMeta" style="margin-top:6px; font-size:0.76rem; color:var(--text-secondary);">Select city and run BAU first, then load monthly SHAP driver patterns.</div>
                    <div id="monthlyHeatmapTable" style="margin-top:8px; overflow:auto;"></div>
                    <div id="monthlyHeatmapLegend" style="margin-top:8px; border:1px solid var(--border-color); border-radius:8px; padding:8px 10px; background:rgba(2,12,42,0.45);">
                        <div style="font-size:0.74rem; color:var(--text-secondary); margin-bottom:6px;">Heatmap legend: color intensity indicates local SHAP contribution strength for each monthly driver.</div>
                        <div style="display:flex; align-items:center; gap:6px;">
                            <span style="font-size:0.72rem; color:var(--text-muted);">Lower impact</span>
                            <div style="flex:1; height:10px; border-radius:999px; background:linear-gradient(90deg,#f1f5f9 0%, #cbd5e1 25%, #93c5fd 50%, #60a5fa 75%, #1d4ed8 100%);"></div>
                            <span style="font-size:0.72rem; color:var(--text-muted);">Higher impact</span>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<!-- Richness Prediction Interface (shown when Richness mode is active) -->
<div id="geoRichnessInterface" class="card" style="display:none;">
    <div class="geo-prediction-layout">
        <div class="geo-prediction-form">
            <div class="geo-prediction-title">Prediction Covariates</div>
            <div class="geo-prediction-subtitle">Select a city, then adjust environmental variables to estimate predicted species richness.</div>

            <div class="form-group geo-city-group">
                <label class="form-label">City / Municipality</label>
                <select id="predCitySelect" class="form-control"></select>
            </div>

            <div class="geo-auto-landtype">
                <span><strong id="predLandTypeName">—</strong></span>
                <span id="predLandTypeCoverage">0% cover</span>
            </div>

            <div class="geo-covariates-grid">
                <div class="form-group geo-covariate-item">
                    <label class="form-label">Land Temp (°C)</label>
                    <input id="predTempInput" type="number" class="form-control" min="10" max="45" step="0.1">
                </div>
                <div class="form-group geo-covariate-item">
                    <label class="form-label">ALAN (nW/cm²/sr)</label>
                    <input id="predAlanInput" type="number" class="form-control" min="0" max="100" step="0.1">
                </div>
                <div class="form-group geo-covariate-item">
                    <label class="form-label">Precipitation (mm)</label>
                    <input id="predPrecipInput" type="number" class="form-control" min="0" max="500" step="1">
                </div>
                <div class="form-group geo-covariate-item">
                    <label class="form-label">NDVI (%)</label>
                    <input id="predNdviInput" type="number" class="form-control" min="0" max="100" step="1">
                </div>
            </div>

            <div class="form-group geo-month-group">
                <label class="form-label">Month</label>
                <div class="geo-month-row">
                    <input id="predMonthSlider" type="range" class="slider" min="1" max="12" value="1" step="1">
                    <span id="predMonthBadge">January</span>
                </div>
            </div>
        </div>

        <div class="geo-prediction-output">
            <div class="geo-output-total">
                <div>
                    <small>TOTAL PREDICTED SPECIES</small>
                    <div id="predTotalContext" class="geo-output-context">—</div>
                </div>
                <div id="predTotalValue" class="geo-output-value">0</div>
            </div>

            <div class="geo-output-card">
                <div class="geo-output-row">
                    <div><strong>Light Sensitive</strong></div>
                    <div id="predSensitiveValue" class="geo-row-value">0</div>
                </div>
                <div class="geo-bar-track"><div id="predSensitiveBar" class="geo-bar-fill geo-bar-red" style="width:0%;"></div></div>

                <div class="geo-output-row">
                    <div><strong>Light Tolerant</strong></div>
                    <div id="predTolerantValue" class="geo-row-value">0</div>
                </div>
                <div class="geo-bar-track"><div id="predTolerantBar" class="geo-bar-fill geo-bar-blue" style="width:0%;"></div></div>

                <div class="geo-output-row">
                    <div><strong>Resident</strong></div>
                    <div id="predResidentValue" class="geo-row-value">0</div>
                </div>
                <div class="geo-bar-track"><div id="predResidentBar" class="geo-bar-fill geo-bar-green" style="width:0%;"></div></div>

                <div class="geo-output-row">
                    <div><strong>Migratory</strong></div>
                    <div id="predMigratoryValue" class="geo-row-value">0</div>
                </div>
                <div class="geo-bar-track"><div id="predMigratoryBar" class="geo-bar-fill geo-bar-yellow" style="width:0%;"></div></div>
            </div>

            <div class="geo-output-card">
                <div class="geo-output-shap-title"><strong>Feature Importance (SHAP)</strong> — <span id="predShapCity">—</span></div>
                <div style="margin-bottom:8px; display:flex; align-items:center; gap:8px;">
                    <label for="predShapOutputSelect" style="font-size:0.78rem; color:var(--text-secondary);">Output:</label>
                    <select id="predShapOutputSelect" class="form-control" style="max-width:200px;">
                        <option value="all">All Outputs (Average)</option>
                        <option value="sensitive">Sensitive</option>
                        <option value="tolerant">Tolerant</option>
                        <option value="resident">Resident</option>
                        <option value="migrant">Migrant</option>
                    </select>
                </div>

                <div class="geo-output-row">
                    <div><strong>Light Intensity</strong></div>
                    <div id="predShapLightVal" class="geo-row-value">0.00</div>
                </div>
                <div class="geo-bar-track"><div id="predShapLightBar" class="geo-bar-fill geo-bar-red" style="width:0%;"></div></div>

                <div class="geo-output-row">
                    <div><strong>NDVI</strong></div>
                    <div id="predShapNdviVal" class="geo-row-value">0.00</div>
                </div>
                <div class="geo-bar-track"><div id="predShapNdviBar" class="geo-bar-fill geo-bar-blue" style="width:0%;"></div></div>

                <div class="geo-output-row">
                    <div><strong>Temperature</strong></div>
                    <div id="predShapTempVal" class="geo-row-value">0.00</div>
                </div>
                <div class="geo-bar-track"><div id="predShapTempBar" class="geo-bar-fill geo-bar-yellow" style="width:0%;"></div></div>

                <div class="geo-output-row">
                    <div><strong>Elevation</strong></div>
                    <div id="predShapElevVal" class="geo-row-value">0.00</div>
                </div>
                <div class="geo-bar-track"><div id="predShapElevBar" class="geo-bar-fill geo-bar-purple" style="width:0%;"></div></div>

                <div class="geo-output-row">
                    <div><strong>Distance to Water</strong></div>
                    <div id="predShapWaterVal" class="geo-row-value">0.00</div>
                </div>
                <div class="geo-bar-track"><div id="predShapWaterBar" class="geo-bar-fill geo-bar-teal" style="width:0%;"></div></div>

                <p id="predDriverText" class="geo-driver-text"></p>
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
        <div id="obsBreakdown" style="display:none; background:var(--bg-card-alt); border-radius:6px; padding:8px 10px; margin-bottom:8px;"></div>
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
<div id="geoDefaultInsights" class="grid-2" style="display:none;">
    <div class="card">
        <h2 class="card-header">Global Feature Importance (SHAP)</h2>
        <div class="card-body">
            <canvas id="globalShapChart"></canvas>
            <p style="margin-top: 15px; color: var(--text-secondary);">
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
    minZoom: 9,
    preferCanvas: true
});

map.fitBounds(MM_BOUNDS, { padding: [8, 8] });
map.setZoom(10);

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
let selectedCityBoundaryLayer = null;
let bauShapChartInstance = null;
let bauWaterfallChartInstance = null;
let cmpStackedChartInstance = null;
let lastBauPrediction = null;
let lastMitigationResult = null;
let hasCompletedBauRun = false;
let hasCompletedMitigationRun = false;
let cityPredictionValues = {};
let cityPredictionDetails = {};
let stackedBauPredictions = {};
let baselineRequestVersion = 0;
let lastGoalPlan = null;
let monthlyHeatmapCache = {};

// City → observation sites lookup (populated after both datasets load)
const citySitesLookup = {};   // cityName → [cellData, ...]
let citiesGeoData = null;

const MONTH_NAMES = ['January','February','March','April','May','June',
                     'July','August','September','October','November','December'];
const MONTH_FACTORS = [0.78, 0.8, 0.88, 0.97, 1.05, 1.14, 1.2, 1.12, 1.03, 0.94, 0.86, 0.8];

const LANDCOVER_COVARIATES = {
    2:  { temp: 27, alan: 18, precip: 210, ndvi: 76 }, // Forest
    8:  { temp: 29, alan: 24, precip: 185, ndvi: 58 }, // Woody Savannas
    9:  { temp: 30, alan: 27, precip: 170, ndvi: 52 }, // Savannas
    10: { temp: 30, alan: 31, precip: 165, ndvi: 47 }, // Grasslands
    11: { temp: 28, alan: 22, precip: 240, ndvi: 64 }, // Wetlands
    12: { temp: 31, alan: 36, precip: 155, ndvi: 43 }, // Croplands
    13: { temp: 31, alan: 55, precip: 150, ndvi: 41 }, // Urban
    14: { temp: 30, alan: 34, precip: 160, ndvi: 45 }, // Crop mosaics
    16: { temp: 33, alan: 38, precip: 120, ndvi: 25 }, // Barren
    17: { temp: 29, alan: 20, precip: 245, ndvi: 49 }  // Water bodies
};

function clamp(num, min, max) {
    return Math.max(min, Math.min(max, num));
}

async function requestScenario(payload) {
    const res = await fetch('api/run_scenario.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    });

    const data = await res.json();
    if (!res.ok || !data.success) {
        throw new Error((data && data.error) ? data.error : 'Scenario API request failed');
    }
    return data;
}

function normalizeShapChart(shapChart) {
    const rows = Array.isArray(shapChart) ? shapChart.slice() : [];
    rows.sort(function(a, b) {
        return (Number(b.importance) || 0) - (Number(a.importance) || 0);
    });
    return rows;
}

function buildShapMap(shapChart) {
    const out = {};
    normalizeShapChart(shapChart).forEach(function(item) {
        out[String(item.feature || '')] = Number(item.importance) || 0;
    });
    return out;
}

function selectedShapOutput(selectId) {
    var el = document.getElementById(selectId);
    var val = el ? String(el.value || 'all').toLowerCase() : 'all';
    if (['all', 'sensitive', 'tolerant', 'resident', 'migrant'].indexOf(val) === -1) {
        return 'all';
    }
    return val;
}

function shapShareMap(rows) {
    var sorted = normalizeShapChart(rows || []);
    var total = sorted.reduce(function(sum, item) { return sum + (Number(item.importance) || 0); }, 0);
    var out = {};
    sorted.forEach(function(item) {
        var key = String(item.feature || '');
        var raw = Number(item.importance) || 0;
        out[key] = total > 0 ? (raw / total) : 0;
    });
    return out;
}

function estimateGoalPlan(targetGain) {
    if (!lastBauPrediction || !lastBauPrediction.result) {
        return null;
    }

    var bau = lastBauPrediction.result;
    var shares = shapShareMap(bau.shapChart || []);
    var total = Math.max(1, Number(bau.total || 1));
    var gain = Number(targetGain || 0);
    if (!isFinite(gain) || gain === 0) {
        return null;
    }

    var direction = gain > 0 ? 1 : -1;
    var gainAbs = Math.abs(gain);
    var effort = clamp(gainAbs / Math.max(1, total * 0.35), 0.05, 1.2);

    var alanW = Number(shares['Artificial Light'] || 0.25);
    var ndviW = Number(shares['NDVI'] || 0.25);
    var tempW = Number(shares['Temperature'] || 0.25);
    var precipW = Number(shares['Precipitation'] || 0.10);

    var alanSlider = Math.round(clamp(effort * 30 * (0.50 + alanW), 0, 30)) * direction;
    var ndviSlider = Math.round(clamp(effort * 30 * (0.45 + ndviW), 0, 30)) * direction;
    var tempSlider = -Math.round(clamp(effort * 20 * (0.40 + tempW), 0, 20)) * direction;
    var precipSlider = Math.round(clamp(effort * 30 * (0.20 + precipW), 0, 30)) * direction;

    return {
        targetGain: gain,
        effort: effort,
        alan: alanSlider,
        ndvi: ndviSlider,
        temp: tempSlider,
        precip: precipSlider,
        shares: {
            alan: alanW,
            ndvi: ndviW,
            temp: tempW,
            precip: precipW
        }
    };
}

function scaledPlan(plan, factor) {
    if (!plan) return null;
    return {
        targetGain: plan.targetGain,
        effort: plan.effort,
        shares: plan.shares,
        alan: Math.round(clamp(plan.alan * factor, -30, 30)),
        ndvi: Math.round(clamp(plan.ndvi * factor, -30, 30)),
        temp: Math.round(clamp(plan.temp * factor, -20, 20)),
        precip: Math.round(clamp(plan.precip * factor, -30, 30))
    };
}

async function evaluateGoalPlan(plan) {
    if (!plan || !lastBauPrediction || !lastBauPrediction.result) {
        return null;
    }

    var cityName = lastBauPrediction.cityName;
    var month = lastBauPrediction.month;
    var bauTotal = Number(lastBauPrediction.result.total || 0);

    var apiData = await requestScenario({
        city: cityName,
        month: month,
        light_reduction: -Number(plan.alan || 0),
        ndvi_increase: Number(plan.ndvi || 0),
        temp_change: Number(plan.temp || 0) / 10,
        precip_change: Number(plan.precip || 0),
        attribution_mode: 'sensitivity'
    });

    var total = Number((apiData.results || {}).total || 0);
    var achievedGain = total - bauTotal;
    var target = Number(plan.targetGain || 0);
    var absError = Math.abs(achievedGain - target);

    return {
        plan: plan,
        achievedGain: achievedGain,
        predictedTotal: total,
        absError: absError
    };
}

async function optimizeGoalPlan(targetGain) {
    var seed = estimateGoalPlan(targetGain);
    if (!seed) return null;

    var candidates = [
        seed,
        scaledPlan(seed, 0.55),
        scaledPlan(seed, 0.85),
        scaledPlan(seed, 1.15),
        scaledPlan(seed, 1.45),
        {
            targetGain: seed.targetGain,
            effort: seed.effort,
            shares: seed.shares,
            alan: Math.round(clamp(seed.alan * 1.25, -30, 30)),
            ndvi: Math.round(clamp(seed.ndvi * 1.10, -30, 30)),
            temp: Math.round(clamp(seed.temp * 1.15, -20, 20)),
            precip: Math.round(clamp(seed.precip * 0.85, -30, 30))
        }
    ].filter(function(c) { return !!c; });

    var best = null;
    for (var i = 0; i < candidates.length; i++) {
        var evaluated = await evaluateGoalPlan(candidates[i]);
        if (!evaluated) continue;
        if (!best || evaluated.absError < best.absError || (evaluated.absError === best.absError && evaluated.predictedTotal > best.predictedTotal)) {
            best = evaluated;
        }
        // Early stop if exact integer target hit.
        if (Math.round(evaluated.achievedGain) === Math.round(targetGain)) {
            best = evaluated;
            break;
        }
    }

    if (!best) return null;
    var out = Object.assign({}, best.plan);
    out.achievedGain = best.achievedGain;
    out.predictedTotal = best.predictedTotal;
    out.absError = best.absError;
    return out;
}

function renderGoalPlan(plan) {
    var el = document.getElementById('goalFinderText');
    if (!el) return;
    if (!plan) {
        el.textContent = 'Run BAU first to unlock recommendation.';
        return;
    }

    var targetText = (plan.targetGain >= 0 ? '+' : '') + plan.targetGain;
    var effortPct = Math.round(plan.effort * 100);
    var alanTxt = (plan.alan >= 0 ? '+' : '') + plan.alan + '%';
    var ndviTxt = (plan.ndvi >= 0 ? '+' : '') + plan.ndvi + '%';
    var tempTxt = (plan.temp >= 0 ? '+' : '') + (plan.temp / 10).toFixed(1) + '°C';
    var precipTxt = (plan.precip >= 0 ? '+' : '') + plan.precip + '%';
    var achievedText = typeof plan.achievedGain === 'number'
        ? ((plan.achievedGain >= 0 ? '+' : '') + plan.achievedGain.toFixed(0))
        : 'n/a';
    var absError = typeof plan.absError === 'number' ? plan.absError : null;
    var closestText = absError !== null
        ? ('Closest achievable in search: ' + achievedText + ' species (off target by ' + absError.toFixed(0) + ').')
        : 'Closest achievable in search: n/a.';

    el.textContent =
        'Target ' + targetText + ' species. Suggested sliders: ALAN ' + alanTxt +
        ', NDVI ' + ndviTxt + ', Temp ' + tempTxt + ', Precip ' + precipTxt +
        '. Estimated gain from model search: ' + achievedText + ' species. ' +
        closestText + ' Effort baseline: ' + effortPct + '%. Apply then run Mitigation to confirm.';
}

function applyGoalPlan(plan) {
    if (!plan) return;
    var alan = document.getElementById('mitAlanSlider');
    var ndvi = document.getElementById('mitNdviSlider');
    var temp = document.getElementById('mitTempSlider');
    var precip = document.getElementById('mitPrecipSlider');
    if (!alan || !ndvi || !temp || !precip) return;

    alan.value = String(clamp(plan.alan, -30, 30));
    ndvi.value = String(clamp(plan.ndvi, -30, 30));
    temp.value = String(clamp(plan.temp, -20, 20));
    precip.value = String(clamp(plan.precip, -30, 30));
    updateMitigationSliderBadges();
}

function destroyChartSafe(instanceRefName) {
    var ref = null;
    if (instanceRefName === 'bauWaterfall') ref = bauWaterfallChartInstance;
    if (instanceRefName === 'cmpStacked') ref = cmpStackedChartInstance;
    if (!ref) return;
    ref.destroy();
    if (instanceRefName === 'bauWaterfall') bauWaterfallChartInstance = null;
    if (instanceRefName === 'cmpStacked') cmpStackedChartInstance = null;
}

function renderBauWaterfall(result) {
    var canvas = document.getElementById('bauWaterfallCanvas');
    var note = document.getElementById('bauWaterfallText');
    if (!canvas) return;

    destroyChartSafe('bauWaterfall');

    var ctx = canvas.getContext('2d');
    if (!ctx) return;

    var outputKey = selectedShapOutput('bauShapOutputSelect');
    var baselineMap = result.baselineByOutput || {};
    var baseline = Number(baselineMap[outputKey] || baselineMap.total || result.baselineTotal || 0);

    var predictedMap = {
        all: Number(result.total || 0),
        tolerant: Number(result.lightTolerant || 0),
        sensitive: Number(result.lightSensitive || 0),
        resident: Number(result.resident || 0),
        migrant: Number(result.migratory || 0)
    };
    var predicted = Number(predictedMap[outputKey] || result.total || 0);
    var delta = predicted - baseline;
    var shapRows = outputKey === 'all'
        ? normalizeShapChart(result.shapChart || [])
        : normalizeShapChart((result.shapByOutput && result.shapByOutput[outputKey]) ? result.shapByOutput[outputKey] : []);
    var topRows = shapRows.slice(0, 4);
    var shapSum = topRows.reduce(function(sum, row) { return sum + (Number(row.importance) || 0); }, 0);
    if (shapSum <= 0) {
        topRows = [
            { feature: 'Artificial Light', importance: 1 },
            { feature: 'NDVI', importance: 1 },
            { feature: 'Temperature', importance: 1 },
            { feature: 'Precipitation', importance: 1 }
        ];
        shapSum = 4;
    }

    var contribs = topRows.map(function(row) {
        var share = (Number(row.importance) || 0) / shapSum;
        return {
            feature: row.feature,
            value: delta * share
        };
    });

    var labels = ['Baseline'].concat(contribs.map(function(c) { return c.feature; })).concat(['Predicted']);
    var transparent = 'rgba(0,0,0,0)';
    var baseData = [];
    var deltaData = [];

    baseData.push(0);
    deltaData.push(baseline);
    var running = baseline;

    contribs.forEach(function(c) {
        var next = running + c.value;
        baseData.push(Math.min(running, next));
        deltaData.push(Math.abs(c.value));
        running = next;
    });

    baseData.push(0);
    deltaData.push(predicted);

    var colors = [
        'rgba(59,130,246,0.75)'
    ].concat(contribs.map(function(c) {
        return c.value >= 0 ? 'rgba(34,197,94,0.78)' : 'rgba(239,68,68,0.78)';
    })).concat(['rgba(168,85,247,0.80)']);

    bauWaterfallChartInstance = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'offset',
                    data: baseData,
                    backgroundColor: transparent,
                    borderWidth: 0,
                    stack: 'waterfall'
                },
                {
                    label: 'value',
                    data: deltaData,
                    backgroundColor: colors,
                    borderWidth: 0,
                    stack: 'waterfall'
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            var idx = context.dataIndex;
                            if (idx === 0) return 'Baseline total: ' + baseline.toFixed(0);
                            if (idx === labels.length - 1) return 'Predicted total: ' + predicted.toFixed(0);
                            var c = contribs[idx - 1];
                            var sign = c.value >= 0 ? '+' : '';
                            return c.feature + ': ' + sign + c.value.toFixed(2) + ' (allocated from total delta)';
                        }
                    }
                }
            },
            scales: {
                x: { stacked: true, ticks: { color: '#9ca3af', font: { size: 10 } }, grid: { display: false } },
                y: { stacked: true, beginAtZero: true, ticks: { color: '#9ca3af', font: { size: 10 } }, grid: { color: 'rgba(255,255,255,0.06)' } }
            }
        }
    });

    if (note) {
        note.textContent =
            'Heuristic waterfall (' + outputKey + '): baseline-to-predicted delta is proportionally allocated to top SHAP drivers for interpretability.';
    }
}

function renderClassStackedChart(bau, mit) {
    var canvas = document.getElementById('cmpStackedCanvas');
    if (!canvas || !bau || !mit) return;
    destroyChartSafe('cmpStacked');

    var ctx = canvas.getContext('2d');
    if (!ctx) return;

    cmpStackedChartInstance = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: ['BAU', 'Mitigation'],
            datasets: [
                { label: 'Sensitive', data: [bau.lightSensitive || 0, mit.lightSensitive || 0], backgroundColor: 'rgba(239,68,68,0.78)' },
                { label: 'Tolerant', data: [bau.lightTolerant || 0, mit.lightTolerant || 0], backgroundColor: 'rgba(59,130,246,0.78)' },
                { label: 'Resident', data: [bau.resident || 0, mit.resident || 0], backgroundColor: 'rgba(34,197,94,0.78)' },
                { label: 'Migratory', data: [bau.migratory || 0, mit.migratory || 0], backgroundColor: 'rgba(245,158,11,0.82)' }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: { legend: { labels: { color: '#9ca3af', font: { size: 10 } } } },
            scales: {
                x: { stacked: true, ticks: { color: '#9ca3af', font: { size: 10 } }, grid: { display: false } },
                y: { stacked: true, beginAtZero: true, ticks: { color: '#9ca3af', font: { size: 10 } }, grid: { color: 'rgba(255,255,255,0.06)' } }
            }
        }
    });
}

function heatColor(value, maxValue) {
    var ratio = maxValue > 0 ? clamp(value / maxValue, 0, 1) : 0;
    var r = Math.round(28 + (220 - 28) * ratio);
    var g = Math.round(45 + (170 - 45) * ratio);
    var b = Math.round(95 + (60 - 95) * ratio);
    return 'rgb(' + r + ',' + g + ',' + b + ')';
}

function renderMonthlyHeatmap(cityName, outputKey, matrix) {
    var root = document.getElementById('monthlyHeatmapTable');
    var meta = document.getElementById('heatmapMeta');
    if (!root || !matrix) return;

    var features = ['Artificial Light', 'NDVI', 'Temperature', 'Precipitation', 'Seasonality', 'Land Cover', 'Historical Species'];
    var months = MONTH_NAMES;

    var maxValue = 0;
    features.forEach(function(f) {
        for (var m = 1; m <= 12; m++) {
            maxValue = Math.max(maxValue, Number((matrix[m] && matrix[m][f]) || 0));
        }
    });

    var html = '<table style="width:100%; border-collapse:collapse; font-size:0.72rem;">';
    html += '<thead><tr><th style="text-align:left; padding:6px; border-bottom:1px solid var(--border-color);">Feature</th>';
    for (var i = 0; i < months.length; i++) {
        html += '<th style="padding:6px; border-bottom:1px solid var(--border-color);">' + months[i].slice(0,3) + '</th>';
    }
    html += '</tr></thead><tbody>';

    features.forEach(function(f) {
        html += '<tr>';
        html += '<td style="padding:6px; border-bottom:1px solid rgba(255,255,255,0.06); color:var(--text-secondary);">' + f + '</td>';
        for (var m = 1; m <= 12; m++) {
            var val = Number((matrix[m] && matrix[m][f]) || 0);
            var bg = heatColor(val, maxValue);
            html += '<td title="' + f + ' / ' + months[m - 1] + ': ' + val.toFixed(4) + '" style="padding:6px; text-align:center; background:' + bg + '; color:#fff; border-bottom:1px solid rgba(255,255,255,0.06);">' + val.toFixed(2) + '</td>';
        }
        html += '</tr>';
    });

    html += '</tbody></table>';
    root.innerHTML = html;
    if (meta) {
        meta.textContent = 'Monthly SHAP heatmap for ' + cityName + ' (' + outputKey + '). Darker cells = stronger local contribution.';
    }
}

async function loadMonthlyDriverHeatmap() {
    if (!lastBauPrediction || !lastBauPrediction.cityName) {
        var meta = document.getElementById('heatmapMeta');
        if (meta) meta.textContent = 'Run BAU first to determine city baseline context.';
        return;
    }

    var cityName = lastBauPrediction.cityName;
    var outputKey = selectedShapOutput('bauShapOutputSelect');
    var cacheKey = cityName + '|' + outputKey;
    if (monthlyHeatmapCache[cacheKey]) {
        renderMonthlyHeatmap(cityName, outputKey, monthlyHeatmapCache[cacheKey]);
        return;
    }

    var meta = document.getElementById('heatmapMeta');
    if (meta) meta.textContent = 'Loading monthly SHAP rows (12 API calls)...';

    var matrix = {};
    for (var m = 1; m <= 12; m++) {
        var apiData = await requestScenario({
            city: cityName,
            month: m,
            light_reduction: 0,
            ndvi_increase: 0,
            temp_change: 0,
            precip_change: 0,
            shap_output: outputKey,
            attribution_mode: 'sensitivity'
        });
        var rows = resolveShapRowsFromApi(apiData, outputKey);
        var map = buildShapMap(rows);
        matrix[m] = {
            'Artificial Light': Number(map['Artificial Light'] || 0),
            'NDVI': Number(map['NDVI'] || 0),
            'Temperature': Number(map['Temperature'] || 0),
            'Precipitation': Number(map['Precipitation'] || 0),
            'Seasonality': Number(map['Seasonality'] || 0),
            'Land Cover': Number(map['Land Cover'] || 0),
            'Historical Species': Number(map['Historical Species'] || 0)
        };
    }

    monthlyHeatmapCache[cacheKey] = matrix;
    renderMonthlyHeatmap(cityName, outputKey, matrix);
}


function resolveShapRowsFromApi(data, outputKey) {
    var key = outputKey || 'all';
    if (key === 'all') {
        return normalizeShapChart(data && data.shap_chart ? data.shap_chart : []);
    }
    var byOutput = data && data.shap_by_output ? data.shap_by_output : {};
    return normalizeShapChart(Array.isArray(byOutput[key]) ? byOutput[key] : []);
}

function landCoverDummiesForCode(code) {
    const c = Number(code);
    if ([1, 2, 3, 4, 5, 8, 9, 10].includes(c)) return [0, 1, 0, 0, 0];
    if ([0, 11, 17].includes(c)) return [0, 0, 1, 0, 0];
    if ([12, 14].includes(c)) return [0, 0, 0, 1, 0];
    if ([15, 16].includes(c)) return [0, 0, 0, 0, 1];
    return [1, 0, 0, 0, 0];
}

function setBauBaselineLoading(cityName, month) {
    document.getElementById('bauLandcoverName').textContent = cityName || 'Loading...';
    document.getElementById('bauLandcoverShare').textContent = 'loading';
    document.getElementById('bauAlanVal').textContent = '...';
    document.getElementById('bauNdviVal').textContent = '...';
    document.getElementById('bauTempVal').textContent = '...';
    document.getElementById('bauPrecipVal').textContent = '...';
    document.getElementById('bauAlanNote').textContent = 'Loading month-specific baseline...';
    document.getElementById('bauNdviNote').textContent = 'Loading month-specific baseline...';
    document.getElementById('bauTempNote').textContent = 'Loading month-specific baseline...';
    document.getElementById('bauPrecipNote').textContent = 'Loading month-specific baseline...';
    document.getElementById('bauMonthBadge').textContent = MONTH_NAMES[(month || 1) - 1];
}

function getAvpBarAnimation(stepMs) {
    var step = stepMs || 80;
    return {
        duration: 860,
        easing: 'easeOutCubic',
        delay: function(context) {
            if (context.type !== 'data') return 0;
            return (context.dataIndex || 0) * step + (context.datasetIndex || 0) * 24;
        }
    };
}

function applyAvpStaggerReveal(selector, initialDelayMs, stepMs) {
    var nodes = document.querySelectorAll(selector);
    var baseDelay = initialDelayMs || 100;
    var step = stepMs || 55;

    nodes.forEach(function(node, index) {
        node.style.opacity = '0';
        node.style.transform = 'translateY(12px)';
        node.style.animation = 'avpPageEnter 0.52s cubic-bezier(0.22, 1, 0.36, 1) forwards';
        node.style.animationDelay = (baseDelay + (index * step)) + 'ms';
    });
}

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

function getCityFeatureByName(cityName) {
    if (!citiesGeoData || !citiesGeoData.features) return null;
    for (var i = 0; i < citiesGeoData.features.length; i++) {
        if (citiesGeoData.features[i].properties.city_name === cityName) {
            return citiesGeoData.features[i];
        }
    }
    return null;
}

function focusMapOnCity(cityName) {
    if (!cityName) return;
    var feature = getCityFeatureByName(cityName);
    if (!feature) return;

    var bounds = L.geoJSON(feature).getBounds();
    if (!bounds || !bounds.isValid()) return;

    map.flyToBounds(bounds, {
        padding: [18, 18],
        maxZoom: 13,
        duration: 2.4,
        easeLinearity: 0.2
    });
    highlightSelectedCityBoundary(cityName);
}

function highlightSelectedCityBoundary(cityName) {
    if (selectedCityBoundaryLayer) {
        map.removeLayer(selectedCityBoundaryLayer);
        selectedCityBoundaryLayer = null;
    }
    if (!cityName) return;

    var feature = getCityFeatureByName(cityName);
    if (!feature) return;

    selectedCityBoundaryLayer = L.geoJSON(feature, {
        interactive: false,
        style: {
            fillOpacity: 0,
            color: '#ef4444',
            weight: 5,
            opacity: 1,
            dashArray: '8 4'
        }
    }).addTo(map);

    selectedCityBoundaryLayer.bringToFront();
}

function getDominantLandCoverForCity(cityFeature) {
    var lcCounts = {};
    if (!cityFeature || !geojsonData || !geojsonData.features) {
        return { code: 13, count: 0, total: 0 };
    }

    var total = 0;
    geojsonData.features.forEach(function(f) {
        if (pointInPolygon(f.properties.latitude, f.properties.longitude, cityFeature.geometry)) {
            var lc = f.properties.land_cover;
            lcCounts[lc] = (lcCounts[lc] || 0) + 1;
            total++;
        }
    });

    var domLC = 13;
    var domCount = 0;
    Object.keys(lcCounts).forEach(function(lcKey) {
        if (lcCounts[lcKey] > domCount) {
            domCount = lcCounts[lcKey];
            domLC = parseInt(lcKey);
        }
    });

    return { code: domLC, count: domCount, total: total };
}

function populatePredictionCityDropdown() {
    var select = document.getElementById('predCitySelect');
    if (!select || !citiesGeoData || !citiesGeoData.features) return;

    var names = citiesGeoData.features.map(function(f) { return f.properties.city_name; }).sort();
    select.innerHTML = '';
    names.forEach(function(name) {
        var opt = document.createElement('option');
        opt.value = name;
        opt.textContent = name;
        select.appendChild(opt);
    });

    if (names.length > 0) {
        select.value = names[0];
        syncPredictionFormForCity(names[0], true);
    }
}

function syncPredictionFormForCity(cityName, applyDefaults) {
    var feature = getCityFeatureByName(cityName);
    var dominant = getDominantLandCoverForCity(feature);
    var defaults = LANDCOVER_COVARIATES[dominant.code] || LANDCOVER_COVARIATES[13];
    var coveragePct = dominant.total > 0 ? Math.round((dominant.count / dominant.total) * 100) : 0;
    var citySeed = Math.abs(hashCode(cityName || 'city'));

    // City-specific baseline tweak so changing cities produces visibly different demo values
    var tempDefault = clamp(defaults.temp + (((citySeed % 7) - 3) * 0.35), 10, 45);
    var alanDefault = clamp(defaults.alan + ((citySeed % 21) - 10), 0, 100);
    var precipDefault = clamp(defaults.precip + ((citySeed % 81) - 40), 0, 500);
    var ndviDefault = clamp(defaults.ndvi + ((citySeed % 15) - 7), 0, 100);

    var tempInput = document.getElementById('predTempInput');
    var alanInput = document.getElementById('predAlanInput');
    var precipInput = document.getElementById('predPrecipInput');
    var ndviInput = document.getElementById('predNdviInput');

    document.getElementById('predLandTypeName').textContent = getLandCoverName(dominant.code);
    document.getElementById('predLandTypeCoverage').textContent = coveragePct + '% cover';

    var shouldApplyDefaults = !!applyDefaults;
    if (!shouldApplyDefaults) {
        shouldApplyDefaults =
            tempInput.value === '' ||
            alanInput.value === '' ||
            precipInput.value === '' ||
            ndviInput.value === '';
    }

    if (shouldApplyDefaults) {
        tempInput.value = tempDefault.toFixed(1);
        alanInput.value = alanDefault.toFixed(0);
        precipInput.value = precipDefault.toFixed(0);
        ndviInput.value = ndviDefault.toFixed(0);
    }

    document.getElementById('predShapCity').textContent = cityName;
}

function setBarWidth(id, pct) {
    var el = document.getElementById(id);
    if (el) el.style.width = clamp(pct, 0, 100).toFixed(0) + '%';
}

var bauBarAnimationTimers = [];

function clearBauBarAnimationTimers() {
    while (bauBarAnimationTimers.length) {
        clearTimeout(bauBarAnimationTimers.pop());
    }
}

function animateBauResultBars(result) {
    clearBauBarAnimationTimers();

    var total = Math.max(1, result.total || 1);
    var reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var items = [
        { id: 'bauSensitiveBar', value: result.lightSensitive },
        { id: 'bauTolerantBar', value: result.lightTolerant },
        { id: 'bauResidentBar', value: result.resident },
        { id: 'bauMigratoryBar', value: result.migratory }
    ];

    items.forEach(function(item, index) {
        var el = document.getElementById(item.id);
        if (!el) return;

        var target = clamp((item.value / total) * 100, 0, 100).toFixed(0) + '%';
        if (reducedMotion) {
            el.style.width = target;
            return;
        }

        el.style.transition = 'none';
        el.style.width = '0%';
        el.offsetWidth;
        el.style.transition = 'width 640ms cubic-bezier(0.22, 1, 0.36, 1)';

        var timer = setTimeout(function() {
            el.style.width = target;
        }, 70 + (index * 120));
        bauBarAnimationTimers.push(timer);
    });
}

var valueAnimHandles = {};
function animateValue(id, target, decimals, durationMs) {
    var el = document.getElementById(id);
    if (!el) return;

    var duration = durationMs || 380;
    var precision = decimals || 0;
    var reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    if (valueAnimHandles[id]) {
        cancelAnimationFrame(valueAnimHandles[id]);
        valueAnimHandles[id] = null;
    }

    var currentRaw = el.getAttribute('data-current');
    var startVal = currentRaw !== null ? parseFloat(currentRaw) : parseFloat(el.textContent);
    if (!isFinite(startVal)) startVal = 0;
    var endVal = isFinite(target) ? target : 0;

    if (reducedMotion || Math.abs(endVal - startVal) < 0.01) {
        el.textContent = endVal.toFixed(precision);
        el.setAttribute('data-current', String(endVal));
        return;
    }

    var startTs = null;
    function easeOutCubic(t) {
        return 1 - Math.pow(1 - t, 3);
    }

    function step(ts) {
        if (!startTs) startTs = ts;
        var progress = clamp((ts - startTs) / duration, 0, 1);
        var eased = easeOutCubic(progress);
        var val = startVal + (endVal - startVal) * eased;
        el.textContent = val.toFixed(precision);
        el.setAttribute('data-current', String(val));

        if (progress < 1) {
            valueAnimHandles[id] = requestAnimationFrame(step);
        } else {
            el.textContent = endVal.toFixed(precision);
            el.setAttribute('data-current', String(endVal));
            valueAnimHandles[id] = null;
        }
    }

    valueAnimHandles[id] = requestAnimationFrame(step);
}

async function runRichnessPrediction() {
    var cityName = document.getElementById('predCitySelect').value;
    var cityFeature = getCityFeatureByName(cityName);
    var dom = getDominantLandCoverForCity(cityFeature);

    var temp = parseFloat(document.getElementById('predTempInput').value) || 31;
    var alan = parseFloat(document.getElementById('predAlanInput').value) || 55;
    var precip = parseFloat(document.getElementById('predPrecipInput').value) || 150;
    var ndviPct = parseFloat(document.getElementById('predNdviInput').value) || 41;
    var month = parseInt(document.getElementById('predMonthSlider').value, 10) || 1;

    temp = clamp(temp, 10, 45);
    alan = clamp(alan, 0, 2000);
    precip = clamp(precip, 0, 5000);
    ndviPct = clamp(ndviPct, 0, 100);

    try {
        var dummies = landCoverDummiesForCode(dom.code);
        var shapOutput = selectedShapOutput('predShapOutputSelect');
        var data = await requestScenario({
            manual_mode: true,
            city: cityName,
            month: month,
            light_reduction: 0,
            ndvi_increase: 0,
            temp_change: 0,
            precip_change: 0,
            shap_output: shapOutput,
            attribution_mode: 'sensitivity',
            base_ndvi: ndviPct / 100,
            base_viirs: alan,
            base_lst: temp,
            base_precip: precip,
            lc_dummy_1: dummies[0],
            lc_dummy_2: dummies[1],
            lc_dummy_3: dummies[2],
            lc_dummy_4: dummies[3],
            lc_dummy_5: dummies[4]
        });

        var outputs = data.model_outputs || {};
        var total = Number((data.results || {}).total || 0);
        var sensitive = Number(outputs.sensitive || 0);
        var tolerant = Number(outputs.tolerant || 0);
        var resident = Number(outputs.resident || 0);
        var migratory = Number(outputs.migrant || 0);

        animateValue('predTotalValue', total, 0, 360);
        document.getElementById('predTotalContext').textContent = getLandCoverName(dom.code) + ' · ALAN ' + alan.toFixed(1) + ' nW · ' + MONTH_NAMES[month - 1];

        animateValue('predSensitiveValue', sensitive, 0, 320);
        animateValue('predTolerantValue', tolerant, 0, 320);
        animateValue('predResidentValue', resident, 0, 320);
        animateValue('predMigratoryValue', migratory, 0, 320);

        var totalSafe = Math.max(1, total);
        setBarWidth('predSensitiveBar', (sensitive / totalSafe) * 100);
        setBarWidth('predTolerantBar', (tolerant / totalSafe) * 100);
        setBarWidth('predResidentBar', (resident / totalSafe) * 100);
        setBarWidth('predMigratoryBar', (migratory / totalSafe) * 100);

        var shapRows = resolveShapRowsFromApi(data, shapOutput);
        var shapMap = buildShapMap(shapRows);
        var shapLight = Number(shapMap['Artificial Light'] || 0);
        var shapNdvi = Number(shapMap['NDVI'] || 0);
        var shapTemp = Number(shapMap['Temperature'] || 0);
        var shapElev = Number(shapMap['Land Cover'] || 0);
        var shapWater = Number(shapMap['Precipitation'] || 0);
        var shapTotal = Math.max(1e-6, shapLight + shapNdvi + shapTemp + shapElev + shapWater);

        var shapLightPct = (shapLight / shapTotal) * 100;
        var shapNdviPct = (shapNdvi / shapTotal) * 100;
        var shapTempPct = (shapTemp / shapTotal) * 100;
        var shapElevPct = (shapElev / shapTotal) * 100;
        var shapWaterPct = (shapWater / shapTotal) * 100;

        document.getElementById('predShapLightVal').textContent = shapLightPct.toFixed(1) + '%';
        document.getElementById('predShapNdviVal').textContent = shapNdviPct.toFixed(1) + '%';
        document.getElementById('predShapTempVal').textContent = shapTempPct.toFixed(1) + '%';
        document.getElementById('predShapElevVal').textContent = shapElevPct.toFixed(1) + '%';
        document.getElementById('predShapWaterVal').textContent = shapWaterPct.toFixed(1) + '%';

        setBarWidth('predShapLightBar', shapLightPct);
        setBarWidth('predShapNdviBar', shapNdviPct);
        setBarWidth('predShapTempBar', shapTempPct);
        setBarWidth('predShapElevBar', shapElevPct);
        setBarWidth('predShapWaterBar', shapWaterPct);

        var ranked = shapRows;
        var top = ranked[0] || { feature: 'n/a', importance: 0 };
        var topPct = shapTotal > 0 ? ((Number(top.importance || 0) / shapTotal) * 100) : 0;
        document.getElementById('predDriverText').textContent =
            'Key driver (' + shapOutput + ', local grouped SHAP): ' + top.feature + ' (' + topPct.toFixed(1) + '% share) for ' + cityName + ' in ' + MONTH_NAMES[month - 1] + '.';
    } catch (err) {
        console.error('Richness prediction failed:', err);
        document.getElementById('predDriverText').textContent = 'Prediction unavailable: ' + err.message;
    }
}

async function getBaselineForCity(cityName, month) {
    var requestVersion = ++baselineRequestVersion;
    var response = await requestScenario({
        city: cityName,
        month: month,
        light_reduction: 0,
        ndvi_increase: 0,
        temp_change: 0,
        precip_change: 0,
        attribution_mode: 'sensitivity'
    });

    if (requestVersion !== baselineRequestVersion) {
        return null;
    }

    var hist = response.historical_inputs || null;
    var modelInputs = hist && hist.model_inputs_used ? hist.model_inputs_used : {};
    var lc = hist && hist.dominant_land_cover ? hist.dominant_land_cover : {};

    var feature = getCityFeatureByName(cityName);
    var dominant = getDominantLandCoverForCity(feature);

    return {
        cityName: cityName,
        month: month,
        dominantName: (lc && lc.label) ? lc.label : getLandCoverName(dominant.code),
        coveragePct: dominant.total > 0 ? Math.round((dominant.count / dominant.total) * 100) : 0,
        alan: Number(modelInputs.base_viirs || 0),
        ndvi: Number(modelInputs.base_ndvi || 0) * 100,
        temp: Number(modelInputs.base_lst || 0),
        precip: Number(modelInputs.base_precip || 0),
        ndviStats: hist ? hist.ndvi : null,
        viirsStats: hist ? hist.viirs : null,
        lstStats: hist ? (hist.lst_combined || hist.lst_day) : null,
        lstDayStats: hist ? hist.lst_day : null,
        lstNightStats: hist ? hist.lst_night : null,
        precipStats: hist ? hist.precip_mm : null,
        response: response
    };
}

function formatTrendNote(stats, unit, multiplier, decimals) {
    if (!stats) return 'No aligned historical record found.';
    var m = Number(multiplier || 1);
    var d = Number(decimals || 1);
    var baseYear = Number(stats.baseline_year || 0);
    var baseRaw = Number(stats.base_raw || 0) * m;
    var trend = Number(stats.avg_yearly_change || 0) * m;
    var sign = trend >= 0 ? '+' : '';
    return baseYear + ' baseline: ' + baseRaw.toFixed(d) + ' ' + unit + ' · ' + sign + trend.toFixed(d) + ' ' + unit + '/yr';
}

function updateBauInputsPanel(base) {
    if (!base) return;
    document.getElementById('bauLandcoverName').textContent = base.dominantName;
    document.getElementById('bauLandcoverShare').textContent = base.coveragePct + '% cover';
    document.getElementById('bauAlanVal').textContent = Math.round(base.alan) + ' nW/cm²/sr';
    document.getElementById('bauNdviVal').textContent = Math.round(base.ndvi) + '%';
    document.getElementById('bauTempVal').textContent = base.temp.toFixed(1) + '°C';
    document.getElementById('bauPrecipVal').textContent = Math.round(base.precip) + ' mm';
    document.getElementById('bauAlanNote').textContent = formatTrendNote(base.viirsStats, 'nW', 1, 2);
    document.getElementById('bauNdviNote').textContent = formatTrendNote(base.ndviStats, '%', 100, 2);
    var lstCombinedNote = formatTrendNote(base.lstStats, '°C', 1, 2);
    var lstDayNote = base.lstDayStats ? ('Day: ' + Number(base.lstDayStats.adjusted_baseline || 0).toFixed(2) + '°C') : '';
    var lstNightNote = base.lstNightStats ? ('Night: ' + Number(base.lstNightStats.adjusted_baseline || 0).toFixed(2) + '°C') : '';
    document.getElementById('bauTempNote').textContent = lstCombinedNote + (lstDayNote && lstNightNote ? (' · ' + lstDayNote + ' · ' + lstNightNote) : '');
    document.getElementById('bauPrecipNote').textContent = formatTrendNote(base.precipStats, 'mm', 1, 2);
    document.getElementById('mitAlanBaseline').textContent = 'Baseline: ' + Math.round(base.alan) + ' nW';
    document.getElementById('mitNdviBaseline').textContent = 'Baseline: ' + Math.round(base.ndvi) + '%';
    document.getElementById('mitTempBaseline').textContent = 'Baseline: ' + base.temp.toFixed(1) + '°C';
    document.getElementById('mitPrecipBaseline').textContent = 'Baseline: ' + Math.round(base.precip) + ' mm';

    if (lastBauPrediction && lastBauPrediction.cityName === base.cityName && lastBauPrediction.month === base.month) {
        lastBauPrediction.baseline = base;
    }
    updateMitigationSliderBadges();
}

function resetBauScenarioState() {
    lastBauPrediction = null;
    hasCompletedBauRun = false;
    hasCompletedMitigationRun = false;
    cityPredictionValues = {};
    cityPredictionDetails = {};
    lastGoalPlan = null;
    lastMitigationResult = null;

    var bauResultHeading = document.getElementById('bauResultHeading');
    var bauResultEmpty = document.getElementById('bauResultEmpty');
    var bauResultContent = document.getElementById('bauResultContent');
    if (bauResultHeading) bauResultHeading.textContent = 'BAU Prediction Result';
    if (bauResultEmpty) {
        bauResultEmpty.style.display = 'block';
        bauResultEmpty.innerHTML = 'Select a city and run the BAU prediction<br>Historical inputs will be used automatically.';
    }
    if (bauResultContent) bauResultContent.style.display = 'none';

    var mitigationSection = document.getElementById('mitigationScenarioSection');
    if (mitigationSection) mitigationSection.style.display = 'none';
    var cmpEmpty = document.getElementById('cmpEmpty');
    var cmpContent = document.getElementById('cmpContent');
    if (cmpEmpty) cmpEmpty.style.display = 'block';
    if (cmpContent) cmpContent.style.display = 'none';
    var bauInputUsed = document.getElementById('bauInputUsed');
    if (bauInputUsed) bauInputUsed.textContent = '';
    var cmpInputSummary = document.getElementById('cmpInputSummary');
    if (cmpInputSummary) cmpInputSummary.textContent = '';
    var monthlyHeatmapTable = document.getElementById('monthlyHeatmapTable');
    if (monthlyHeatmapTable) monthlyHeatmapTable.innerHTML = '';
    var heatmapMeta = document.getElementById('heatmapMeta');
    if (heatmapMeta) heatmapMeta.textContent = 'Select city and run BAU first, then load monthly SHAP driver patterns.';
    destroyChartSafe('bauWaterfall');
    destroyChartSafe('cmpStacked');
    renderGoalPlan(null);
    refreshCityLayerStyles();
    syncMitigationPanelHeight();
}

function updateBauResultUI(cityName, result) {
    document.getElementById('bauResultEmpty').style.display = 'none';
    document.getElementById('bauResultContent').style.display = 'block';
    document.getElementById('bauResultHeading').textContent = 'BAU Prediction Result — ' + cityName + ' · ' + result.monthName;
    document.getElementById('bauResultTitle').textContent = 'BAU TOTAL PREDICTED — ' + cityName + ' · ' + result.monthName;
    animateValue('bauTotalPred', result.total, 0, 360);
    document.getElementById('bauShapTitle').textContent = 'Feature Importance (SHAP) — ' + cityName;
    document.getElementById('bauShapSubtitle').textContent = 'Local SHAP values for ' + cityName + ' · ' + document.getElementById('bauLandcoverName').textContent;

    document.getElementById('bauSensitiveVal').textContent = result.lightSensitive;
    document.getElementById('bauTolerantVal').textContent = result.lightTolerant;
    document.getElementById('bauResidentVal').textContent = result.resident;
    document.getElementById('bauMigratoryVal').textContent = result.migratory;

    var bauInputs = result.inputValues && result.inputValues.scenario ? result.inputValues.scenario : null;
    var inputUsedText = '';
    if (bauInputs) {
        inputUsedText =
            'Inputs used: NDVI ' + (Number(bauInputs.ndvi || 0) * 100).toFixed(2) + '% · ' +
            'ALAN ' + Number(bauInputs.viirs || 0).toFixed(2) + ' nW · ' +
            'LST ' + Number(bauInputs.lst || 0).toFixed(2) + '°C · ' +
            'Precip ' + Number(bauInputs.precip || 0).toFixed(2) + ' mm · ' +
            'Month ' + result.monthName;
    }
    document.getElementById('bauInputUsed').textContent = inputUsedText;

    animateBauResultBars(result);

    var oldCanvas = document.getElementById('bauShapCanvas');
    if (!oldCanvas || !oldCanvas.parentNode) return;

    if (bauShapChartInstance) {
        bauShapChartInstance.destroy();
        bauShapChartInstance = null;
    }

    var newCanvas = oldCanvas.cloneNode(false);
    newCanvas.id = 'bauShapCanvas';
    newCanvas.height = 170;
    oldCanvas.parentNode.replaceChild(newCanvas, oldCanvas);

    var shapCtx = newCanvas.getContext('2d');
    if (!shapCtx) return;

    var shapOutput = selectedShapOutput('bauShapOutputSelect');
    var sortedShap = shapOutput === 'all'
        ? normalizeShapChart(result.shapChart || [])
        : normalizeShapChart((result.shapByOutput && result.shapByOutput[shapOutput]) ? result.shapByOutput[shapOutput] : []);
    var shapLabels = sortedShap.map(function(item) { return item.feature; });
    var shapRawValues = sortedShap.map(function(item) { return Number(item.importance) || 0; });
    var rawTotal = shapRawValues.reduce(function(sum, v) { return sum + v; }, 0);
    var shapValues = rawTotal > 0
        ? shapRawValues.map(function(v) { return (v / rawTotal) * 100; })
        : shapRawValues.map(function() { return 0; });
    if (!shapLabels.length) {
        shapLabels = ['No SHAP data'];
        shapValues = [0];
        shapRawValues = [0];
    }
    var shapAxisMax = 100;

    bauShapChartInstance = new Chart(shapCtx, {
        type: 'bar',
        data: {
            labels: shapLabels,
            datasets: [{
                data: shapValues,
                rawValues: shapRawValues,
                backgroundColor: ['#ef4444', '#22c55e', '#f59e0b', '#8b5cf6', '#06b6d4', '#06b6d4', '#94a3b8']
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            animation: getAvpBarAnimation(90),
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            var pct = Number(context.parsed.y || 0).toFixed(2) + '%';
                            var raw = Number(context.dataset.rawValues?.[context.dataIndex] || 0).toFixed(6);
                            return 'Share: ' + pct + ' (raw: ' + raw + ')';
                        }
                    }
                }
            },
            scales: {
                y: { beginAtZero: true, max: shapAxisMax, ticks: { color: '#9ca3af', font: { size: 10 } }, grid: { color: 'rgba(255,255,255,0.06)' } },
                x: { ticks: { color: '#9ca3af', font: { size: 10 } }, grid: { display: false } }
            }
        }
    });

    var topA = sortedShap[0] || { feature: 'n/a', importance: 0 };
    var topB = sortedShap[1] || { feature: 'n/a', importance: 0 };
    var topAPct = rawTotal > 0 ? (Number(topA.importance || 0) / rawTotal) * 100 : 0;
    var topBPct = rawTotal > 0 ? (Number(topB.importance || 0) / rawTotal) * 100 : 0;
    document.getElementById('bauShapText').textContent =
        'Interpretation (' + shapOutput + ', local grouped SHAP): Top drivers in ' + cityName + ' are ' + topA.feature + ' (' + topAPct.toFixed(1) + '%) and ' + topB.feature + ' (' + topBPct.toFixed(1) + '%).';

    syncBauResultPanelHeight();
}

function syncBauResultPanelHeight() {
    var left = document.getElementById('bauLeftPanel');
    var right = document.getElementById('bauRightPanel');
    if (!left || !right) return;

    var targetHeight = left.offsetHeight;
    if (!targetHeight || targetHeight < 100) {
        right.style.height = '';
        right.style.maxHeight = '';
        return;
    }

    right.style.height = targetHeight + 'px';
    right.style.maxHeight = targetHeight + 'px';
}

function syncMitigationPanelHeight() {
    var section = document.getElementById('mitigationScenarioSection');
    var left = document.getElementById('mitLeftPanel');
    var right = document.getElementById('mitRightPanel');
    if (!section || !left || !right) return;

    if (section.style.display === 'none') {
        right.style.height = '';
        right.style.maxHeight = '';
        return;
    }

    var targetHeight = left.offsetHeight;
    if (!targetHeight || targetHeight < 100) {
        right.style.height = '';
        right.style.maxHeight = '';
        return;
    }

    right.style.height = targetHeight + 'px';
    right.style.maxHeight = targetHeight + 'px';
}

async function runBauPrediction() {
    var cityName = document.getElementById('bauCitySelect').value;
    if (!cityName) return;

    var month = parseInt(document.getElementById('bauMonthSlider').value, 10) || 1;
    document.getElementById('bauMonthBadge').textContent = MONTH_NAMES[month - 1];

    var runBtn = document.getElementById('runBauBtn');
    if (runBtn) {
        runBtn.disabled = true;
        runBtn.textContent = 'Running BAU...';
    }

    try {
        var selectedOutput = selectedShapOutput('bauShapOutputSelect');
        var base = await getBaselineForCity(cityName, month);
        if (!base) {
            if (runBtn) {
                runBtn.disabled = false;
                runBtn.textContent = 'Run BAU Prediction';
            }
            return;
        }
        updateBauInputsPanel(base);
        updateMitigationSliderBadges();

        var apiData = await requestScenario({
            city: cityName,
            month: month,
            light_reduction: 0,
            ndvi_increase: 0,
            temp_change: 0,
            precip_change: 0,
            shap_output: selectedOutput,
            attribution_mode: 'sensitivity'
        });
        var outputs = apiData.model_outputs || {};
        var result = {
            total: Number((apiData.results || {}).total || 0),
            baselineTotal: Number((apiData.results || {}).baseline_total || 0),
            baselineByOutput: (apiData.results && apiData.results.baseline_by_output) ? apiData.results.baseline_by_output : {},
            lightSensitive: Number(outputs.sensitive || 0),
            lightTolerant: Number(outputs.tolerant || 0),
            resident: Number(outputs.resident || 0),
            migratory: Number(outputs.migrant || 0),
            monthName: MONTH_NAMES[month - 1],
            shapChart: normalizeShapChart(apiData.shap_chart || []),
            shapByOutput: apiData.shap_by_output || {},
            inputValues: apiData.input_values || {}
        };

        stackedBauPredictions[cityName] = {
            total: result.total,
            lightSensitive: result.lightSensitive,
            lightTolerant: result.lightTolerant,
            resident: result.resident,
            migratory: result.migratory,
            monthName: result.monthName,
            dominantName: base.dominantName
        };

        lastBauPrediction = { cityName: cityName, month: month, baseline: base, result: result };
        updateBauResultUI(cityName, result);
        renderBauWaterfall(result);
        renderGlobalShapChart(result.shapChart || []);
    } catch (err) {
        console.error('BAU prediction failed:', err);
        var bauResultEmpty = document.getElementById('bauResultEmpty');
        var bauResultContent = document.getElementById('bauResultContent');
        if (bauResultContent) bauResultContent.style.display = 'none';
        if (bauResultEmpty) {
            bauResultEmpty.style.display = 'block';
            bauResultEmpty.innerHTML = 'Failed to run BAU prediction.<br>' + err.message;
        }
        if (runBtn) {
            runBtn.disabled = false;
            runBtn.textContent = 'Run BAU Prediction';
        }
        return;
    }

    if (runBtn) {
        runBtn.disabled = false;
        runBtn.textContent = 'Run BAU Prediction';
    }

    hasCompletedBauRun = true;
    hasCompletedMitigationRun = false;
    cityPredictionValues = {};
    cityPredictionDetails = {};
    var mitigationSection = document.getElementById('mitigationScenarioSection');
    if (mitigationSection) mitigationSection.style.display = 'grid';
    var cmpEmpty = document.getElementById('cmpEmpty');
    var cmpContent = document.getElementById('cmpContent');
    if (cmpEmpty) cmpEmpty.style.display = 'block';
    if (cmpContent) cmpContent.style.display = 'none';
    refreshCityLayerStyles();
    syncMitigationPanelHeight();
}

function updateMitigationSliderBadges() {
    var base = (lastBauPrediction && lastBauPrediction.baseline) ? lastBauPrediction.baseline : null;
    if (!base) {
        return;
    }
    var alanDeltaPct = (parseInt(document.getElementById('mitAlanSlider').value, 10) || 0) / 100;
    var ndviDeltaPct = (parseInt(document.getElementById('mitNdviSlider').value, 10) || 0) / 100;
    var tempDelta = (parseInt(document.getElementById('mitTempSlider').value, 10) || 0) / 10;
    var precipDeltaPct = (parseInt(document.getElementById('mitPrecipSlider').value, 10) || 0) / 100;

    var adjustedAlan = clamp(base.alan * (1 + alanDeltaPct), 0, 100);
    var adjustedNdvi = clamp(base.ndvi * (1 + ndviDeltaPct), 0, 100);
    var adjustedTemp = clamp(base.temp + tempDelta, 10, 45);
    var adjustedPrecip = clamp(base.precip * (1 + precipDeltaPct), 0, 500);

    document.getElementById('mitAlanBadge').textContent = Math.round(adjustedAlan) + ' nW';
    document.getElementById('mitNdviBadge').textContent = Math.round(adjustedNdvi) + '%';
    document.getElementById('mitTempBadge').textContent = adjustedTemp.toFixed(1) + '°C';
    document.getElementById('mitPrecipBadge').textContent = Math.round(adjustedPrecip) + ' mm';
}

function updateScenarioComparison(mitigationResult) {
    var bau = lastBauPrediction ? lastBauPrediction.result : { total: 0, lightSensitive: 0, lightTolerant: 0, resident: 0, migratory: 0 };
    var mit = mitigationResult || bau;

    var delta = mit.total - bau.total;
    var pct = bau.total > 0 ? (delta / bau.total) * 100 : 0;

    document.getElementById('cmpBauTotal').textContent = bau.total;
    document.getElementById('cmpMitTotal').textContent = mit.total;
    document.getElementById('cmpDelta').textContent = (delta >= 0 ? '+' : '') + delta;
    document.getElementById('cmpDeltaPct').textContent = (pct >= 0 ? '+' : '') + pct.toFixed(1) + '%';

    var categories = [
        { name: 'Light Sensitive', b: bau.lightSensitive, m: mit.lightSensitive },
        { name: 'Light Tolerant',  b: bau.lightTolerant,  m: mit.lightTolerant },
        { name: 'Resident',        b: bau.resident,      m: mit.resident },
        { name: 'Migratory',       b: bau.migratory,     m: mit.migratory }
    ];

    document.getElementById('cmpRows').innerHTML = categories.map(function(item) {
        var d = item.m - item.b;
        return '<tr>' +
            '<td>' + item.name + '</td>' +
            '<td>' + item.b + '</td>' +
            '<td>' + item.m + '</td>' +
            '<td style="font-weight:700; color:' + (d >= 0 ? 'var(--accent-green)' : 'var(--accent-red)') + ';">' + (d >= 0 ? '+' : '') + d + '</td>' +
        '</tr>';
    }).join('');

    var lightDelta = mit.lightSensitive - bau.lightSensitive;
    var pctRounded = Math.round(pct * 10) / 10;
    var pctText = (pctRounded >= 0 ? '+' : '') + (Number.isInteger(pctRounded) ? pctRounded.toFixed(0) : pctRounded.toFixed(1)) + '%';

    document.getElementById('cmpSummary').textContent =
        '🧾 Summary: The mitigation scenario projects a ' + (delta >= 0 ? 'gain' : 'loss') + ' of ' + Math.abs(delta) + ' species (' + pctText + ') over BAU. Light-sensitive species ' + (lightDelta >= 0 ? 'increase' : 'decrease') + ' by ' + Math.abs(lightDelta) + ', indicating that vegetation improvements partially offset light pollution effects.';

    var cmpInputSummary = document.getElementById('cmpInputSummary');
    var bauInputs = bau.inputValues && bau.inputValues.scenario ? bau.inputValues.scenario : null;
    var mitInputs = mit.inputValues && mit.inputValues.scenario ? mit.inputValues.scenario : null;
    if (!cmpInputSummary) return;
    if (!bauInputs || !mitInputs) {
        cmpInputSummary.textContent = '';
        return;
    }

    var ndviDelta = (Number(mitInputs.ndvi || 0) - Number(bauInputs.ndvi || 0)) * 100;
    var viirsDelta = Number(mitInputs.viirs || 0) - Number(bauInputs.viirs || 0);
    var lstDelta = Number(mitInputs.lst || 0) - Number(bauInputs.lst || 0);
    var precipDelta = Number(mitInputs.precip || 0) - Number(bauInputs.precip || 0);

    cmpInputSummary.textContent =
        'Input deltas vs BAU: NDVI ' + (ndviDelta >= 0 ? '+' : '') + ndviDelta.toFixed(2) + '%, ' +
        'ALAN ' + (viirsDelta >= 0 ? '+' : '') + viirsDelta.toFixed(2) + ' nW, ' +
        'LST ' + (lstDelta >= 0 ? '+' : '') + lstDelta.toFixed(2) + '°C, ' +
        'Precip ' + (precipDelta >= 0 ? '+' : '') + precipDelta.toFixed(2) + ' mm.';

    renderClassStackedChart(bau, mit);
}

async function runMitigationPrediction() {
    if (!lastBauPrediction || !hasCompletedBauRun) return;

    var base = lastBauPrediction.baseline;
    var cityName = lastBauPrediction.cityName;
    var month = lastBauPrediction.month;

    var alanDeltaPct = parseInt(document.getElementById('mitAlanSlider').value, 10) / 100;
    var ndviDeltaPct = parseInt(document.getElementById('mitNdviSlider').value, 10) / 100;
    var tempDelta = parseInt(document.getElementById('mitTempSlider').value, 10) / 10;
    var precipDeltaPct = parseInt(document.getElementById('mitPrecipSlider').value, 10) / 100;

    var runBtn = document.getElementById('runMitigationBtn');
    if (runBtn) {
        runBtn.disabled = true;
        runBtn.textContent = 'Running Mitigation...';
    }

    var mitResult;
    try {
        var apiData = await requestScenario({
            city: cityName,
            month: month,
            light_reduction: -(alanDeltaPct * 100),
            ndvi_increase: ndviDeltaPct * 100,
            temp_change: tempDelta,
            precip_change: precipDeltaPct * 100,
            attribution_mode: 'sensitivity'
        });
        var outputs = apiData.model_outputs || {};
        mitResult = {
            total: Number((apiData.results || {}).total || 0),
            lightSensitive: Number(outputs.sensitive || 0),
            lightTolerant: Number(outputs.tolerant || 0),
            resident: Number(outputs.resident || 0),
            migratory: Number(outputs.migrant || 0),
            monthName: MONTH_NAMES[month - 1],
            shapChart: normalizeShapChart(apiData.shap_chart || []),
            inputValues: apiData.input_values || {}
        };
    } catch (err) {
        console.error('Mitigation prediction failed:', err);
        if (runBtn) {
            runBtn.disabled = false;
            runBtn.textContent = 'Run Mitigation Prediction';
        }
        return;
    }

    if (runBtn) {
        runBtn.disabled = false;
        runBtn.textContent = 'Run Mitigation Prediction';
    }

    hasCompletedMitigationRun = true;
    lastMitigationResult = mitResult;
    var cmpEmpty = document.getElementById('cmpEmpty');
    var cmpContent = document.getElementById('cmpContent');
    if (cmpEmpty) cmpEmpty.style.display = 'none';
    if (cmpContent) cmpContent.style.display = 'block';
    updateScenarioComparison(mitResult);
    refreshCityLayerStyles();
    syncMitigationPanelHeight();
}

function initAnalyticsScenarioUI() {
    var citySelect = document.getElementById('bauCitySelect');
    if (!citySelect || !citiesGeoData || !citiesGeoData.features) return;

    var names = citiesGeoData.features.map(function(f) { return f.properties.city_name; }).sort();
    citySelect.innerHTML = '';
    names.forEach(function(name) {
        var opt = document.createElement('option');
        opt.value = name;
        opt.textContent = name;
        citySelect.appendChild(opt);
    });

    citySelect.addEventListener('change', async function() {
        resetBauScenarioState();
        focusMapOnCity(this.value);
        try {
            var month = parseInt(document.getElementById('bauMonthSlider').value, 10) || 1;
            setBauBaselineLoading(this.value, month);
            var base = await getBaselineForCity(this.value, month);
            if (!base) return;
            updateBauInputsPanel(base);
        } catch (err) {
            console.error('Failed to refresh city baseline:', err);
        }
    });

    document.getElementById('bauMonthSlider').addEventListener('input', async function() {
        document.getElementById('bauMonthBadge').textContent = MONTH_NAMES[(parseInt(this.value, 10) || 1) - 1];
        resetBauScenarioState();
        var cityName = citySelect.value;
        if (!cityName) return;
        try {
            var month = parseInt(this.value, 10) || 1;
            setBauBaselineLoading(cityName, month);
            var base = await getBaselineForCity(cityName, month);
            if (!base) return;
            updateBauInputsPanel(base);
        } catch (err) {
            console.error('Failed to refresh month baseline:', err);
        }
    });

    document.getElementById('runBauBtn').addEventListener('click', runBauPrediction);
    document.getElementById('runMitigationBtn').addEventListener('click', runMitigationPrediction);
    document.getElementById('loadHeatmapBtn').addEventListener('click', async function() {
        var btn = document.getElementById('loadHeatmapBtn');
        if (btn) {
            btn.disabled = true;
            btn.textContent = 'Loading...';
        }
        try {
            await loadMonthlyDriverHeatmap();
        } catch (err) {
            console.error('Heatmap load failed:', err);
            var meta = document.getElementById('heatmapMeta');
            if (meta) meta.textContent = 'Heatmap load failed: ' + err.message;
        } finally {
            if (btn) {
                btn.disabled = false;
                btn.textContent = 'Load 12-Month Heatmap';
            }
        }
    });

    document.getElementById('goalSuggestBtn').addEventListener('click', async function() {
        if (!lastBauPrediction || !lastBauPrediction.result) {
            renderGoalPlan(null);
            return;
        }
        var suggestBtn = document.getElementById('goalSuggestBtn');
        var applyBtn = document.getElementById('goalApplyBtn');
        var hint = document.getElementById('goalFinderText');
        if (suggestBtn) {
            suggestBtn.disabled = true;
            suggestBtn.textContent = 'Finding...';
        }
        if (applyBtn) applyBtn.disabled = true;
        if (hint) hint.textContent = 'Searching for a closer plan to your target using live model calls...';

        var targetEl = document.getElementById('goalGainInput');
        var target = parseInt(targetEl ? targetEl.value : '3', 10);
        if (!isFinite(target) || target === 0) target = 3;
        target = clamp(target, -50, 50);
        try {
            lastGoalPlan = await optimizeGoalPlan(target);
            renderGoalPlan(lastGoalPlan);
        } catch (err) {
            console.error('Goal finder optimization failed:', err);
            if (hint) hint.textContent = 'Goal search failed: ' + err.message;
        } finally {
            if (suggestBtn) {
                suggestBtn.disabled = false;
                suggestBtn.textContent = 'Suggest Plan';
            }
            if (applyBtn) applyBtn.disabled = false;
        }
    });

    document.getElementById('goalApplyBtn').addEventListener('click', async function() {
        if (!lastGoalPlan) {
            var targetEl = document.getElementById('goalGainInput');
            var target = parseInt(targetEl ? targetEl.value : '3', 10);
            if (!isFinite(target) || target === 0) target = 3;
            target = clamp(target, -50, 50);
            lastGoalPlan = await optimizeGoalPlan(target);
        }
        applyGoalPlan(lastGoalPlan);
        renderGoalPlan(lastGoalPlan);
    });

    ['mitAlanSlider', 'mitNdviSlider', 'mitTempSlider', 'mitPrecipSlider'].forEach(function(id) {
        document.getElementById(id).addEventListener('input', updateMitigationSliderBadges);
    });

    document.getElementById('bauShapOutputSelect').addEventListener('change', function() {
        if (lastBauPrediction && lastBauPrediction.result) {
            updateBauResultUI(lastBauPrediction.cityName, lastBauPrediction.result);
            renderBauWaterfall(lastBauPrediction.result);
        }
    });

    resetBauScenarioState();
    syncBauResultPanelHeight();
    syncMitigationPanelHeight();

    if (names.length) {
        citySelect.value = names[0];
        var firstMonth = parseInt(document.getElementById('bauMonthSlider').value, 10) || 1;
        setBauBaselineLoading(names[0], firstMonth);
        getBaselineForCity(names[0], firstMonth)
            .then(function(base) { if (base) updateBauInputsPanel(base); })
            .catch(function(err) { console.error('Initial baseline load failed:', err); });
        highlightSelectedCityBoundary(names[0]);
        focusMapOnCity(names[0]);
    }
}

window.addEventListener('resize', syncBauResultPanelHeight);
window.addEventListener('resize', syncMitigationPanelHeight);

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
        { val: 0,  r: 31,  g: 42,  b: 125 },
        { val: 12, r: 31,  g: 79,  b: 191 },
        { val: 25, r: 46,  g: 125, b: 224 },
        { val: 37, r: 102, g: 194, b: 255 },
        { val: 50, r: 242, g: 182, b: 40  }
    ];
    value = Math.max(0, Math.min(50, value));
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
    if (selected.size === 0) {
        [2,8,9,10,11,12,13,14,16,17].forEach(function(code) { selected.add(code); });
    }
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
    return { fillColor: '#9ca3af', weight: 1.5, color: '#64748b', fillOpacity: 0.35, opacity: 0.95 };
}

function cityHoverStyle() {
    return { fillColor: '#d1d5db', weight: 2.3, color: '#38bdf8', fillOpacity: 0.42, opacity: 1 };
}

function getCityPredictionValue(cityName) {
    if (!cityPredictionValues || !Object.prototype.hasOwnProperty.call(cityPredictionValues, cityName)) {
        return null;
    }
    return cityPredictionValues[cityName];
}

function buildCityPredictionDetailsForMap(monthIndex, mitigationDeltas) {
    return {};
}

function buildCityHoverTooltipHtml(cityName, feature) {
    var dominant = getDominantLandCoverForCity(feature);
    var dominantName = getLandCoverName(dominant.code);
    var details = stackedBauPredictions[cityName] || null;
    var canShowPrediction = !!details;

    if (!canShowPrediction) {
        return '<div style="min-width:170px; background:#0b1220; color:#e2e8f0; border:1px solid #334155; border-radius:8px; overflow:hidden;">' +
            '<div style="padding:7px 9px; font-weight:700; font-size:0.88rem;">' + cityName + '</div>' +
            '<div style="padding:0 9px 8px 9px; color:#94a3b8; font-size:0.8rem;"><span style="display:inline-block; width:9px; height:9px; border-radius:50%; background:#ef4444; margin-right:6px; vertical-align:middle;"></span>' + dominantName + '</div>' +
        '</div>';
    }

    return '<div style="min-width:200px; background:#0b1220; color:#e2e8f0; border:1px solid #334155; border-radius:9px; overflow:hidden;">' +
        '<div style="padding:7px 9px; border-bottom:1px solid #1e293b;">' +
            '<div style="font-weight:700; font-size:0.9rem;">' + cityName + '</div>' +
            '<div style="margin-top:2px; color:#94a3b8; font-size:0.78rem;"><span style="display:inline-block; width:9px; height:9px; border-radius:50%; background:#ef4444; margin-right:6px; vertical-align:middle;"></span>' + details.dominantName + '</div>' +
        '</div>' +
        '<div style="padding:7px 9px; border-bottom:1px solid #1e293b;">' +
            '<div style="display:flex; justify-content:space-between; align-items:baseline; margin-bottom:3px;"><span style="font-size:0.74rem; color:#94a3b8;">Total Predicted</span><span style="font-size:1.35rem; line-height:1; font-weight:800; color:#d8b4fe;">' + details.total + ' spp.</span></div>' +
            '<div style="display:flex; justify-content:space-between; color:#fda4af; font-size:0.82rem;"><span><span style="display:inline-block; width:8px; height:8px; border-radius:50%; background:#fb7185; margin-right:6px;"></span>Light Sensitive</span><strong>' + details.lightSensitive + '</strong></div>' +
            '<div style="display:flex; justify-content:space-between; color:#60a5fa; font-size:0.82rem;"><span><span style="display:inline-block; width:8px; height:8px; border-radius:50%; background:#60a5fa; margin-right:6px;"></span>Light Tolerant</span><strong>' + details.lightTolerant + '</strong></div>' +
            '<div style="display:flex; justify-content:space-between; color:#34d399; font-size:0.82rem;"><span><span style="display:inline-block; width:8px; height:8px; border-radius:50%; background:#34d399; margin-right:6px;"></span>Resident</span><strong>' + details.resident + '</strong></div>' +
            '<div style="display:flex; justify-content:space-between; color:#facc15; font-size:0.82rem;"><span><span style="display:inline-block; width:8px; height:8px; border-radius:50%; background:#facc15; margin-right:6px;"></span>Migratory</span><strong>' + details.migratory + '</strong></div>' +
        '</div>' +
        '<div style="padding:6px 9px; color:#94a3b8; font-size:0.76rem; font-style:italic;">' + details.monthName + ' · ' + details.dominantName + '</div>' +
    '</div>';
}

function getComputedCityStyle(feature, isHover) {
    var baseStyle;
    var cityName = feature && feature.properties ? feature.properties.city_name : null;
    var stackedPrediction = cityName ? stackedBauPredictions[cityName] : null;

    if (!stackedPrediction || !cityName) {
        baseStyle = { fillColor: '#9ca3af', weight: 1.5, color: '#64748b', fillOpacity: 0.35, opacity: 0.95 };
    } else {
        var value = stackedPrediction.total;
        if (value === null || typeof value === 'undefined') {
            baseStyle = { fillColor: '#9ca3af', weight: 1.5, color: '#64748b', fillOpacity: 0.35, opacity: 0.95 };
        } else {
            baseStyle = { fillColor: getRichnessColor(value), weight: 1.5, color: '#0f172a', fillOpacity: 0.72, opacity: 0.95 };
        }
    }

    if (!isHover) return baseStyle;

    return {
        fillColor: baseStyle.fillColor,
        weight: 2.3,
        color: '#38bdf8',
        fillOpacity: Math.min(0.85, (baseStyle.fillOpacity || 0.35) + 0.08),
        opacity: 1
    };
}

function refreshCityLayerStyles() {
    if (!cityLayer) return;
    cityLayer.eachLayer(function(layer) {
        layer.setStyle(getComputedCityStyle(layer.feature, false));
    });
    cityLayer.bringToFront();
    if (selectedCityBoundaryLayer) selectedCityBoundaryLayer.bringToFront();
}

// ── Map mode ──────────────────────────────────────────────

function setColorMode(mode) {
    colorMode = mode;
    var btnPred = document.getElementById('btnPredictions');
    var btnLand = document.getElementById('btnLandCover');
    if (btnPred) btnPred.className = mode === 'predictions' ? 'btn btn-primary btn-sm' : 'btn btn-secondary btn-sm';
    if (btnLand) btnLand.className = mode === 'landcover' ? 'btn btn-primary btn-sm' : 'btn btn-secondary btn-sm';
    document.getElementById('legendPrediction').style.display = 'block';

    var richnessUI = document.getElementById('geoRichnessInterface');
    var defaultUI = document.getElementById('geoDefaultInsights');
    if (richnessUI && defaultUI) {
        if (mode === 'predictions') {
            richnessUI.style.display = 'block';
            defaultUI.style.display = 'none';
        } else {
            richnessUI.style.display = 'none';
            defaultUI.style.display = 'grid';
        }
    }

    refreshCityLayerStyles();
}

// ── Land-cover layer ──────────────────────────────────────

function applyLandCoverFilter() {
    if (geojsonLayer) { map.removeLayer(geojsonLayer); geojsonLayer = null; }
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
        style: function(feature) { return getComputedCityStyle(feature, false); },
        onEachFeature: function(feature, layer) {
            var cityName = feature.properties.city_name;

            layer.bindTooltip('', { sticky: true, className: 'map-tooltip', direction: 'auto', offset: L.point(14, 0), opacity: 1 });

            layer.on('mouseover', function() {
                layer.setStyle(getComputedCityStyle(feature, true));
                layer.setTooltipContent(buildCityHoverTooltipHtml(cityName, feature));
                layer.openTooltip();
            });
            layer.on('mouseout',  function() {
                layer.setStyle(getComputedCityStyle(feature, false));
                layer.closeTooltip();
            });
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
        li.style.color = 'var(--text-muted)';
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
            animation: getAvpBarAnimation(72),
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
        '<p style="margin-top:10px;font-size:0.9rem;color:var(--text-secondary);">' +
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

var monthSliderEl = document.getElementById('monthSlider');
if (monthSliderEl) {
    monthSliderEl.addEventListener('input', function() {
        var monthValueEl = document.getElementById('monthValue');
        if (monthValueEl) monthValueEl.textContent = MONTH_NAMES[this.value - 1];
    });
}

document.getElementById('predMonthSlider').addEventListener('input', function() {
    document.getElementById('predMonthBadge').textContent = MONTH_NAMES[this.value - 1];
    schedulePredictionUpdate();
});

document.getElementById('predCitySelect').addEventListener('change', function() {
    syncPredictionFormForCity(this.value, false);
    focusMapOnCity(this.value);
    runRichnessPrediction();
});

document.getElementById('predShapOutputSelect').addEventListener('change', function() {
    runRichnessPrediction();
});

var predictionUpdateTimer = null;
function schedulePredictionUpdate() {
    if (predictionUpdateTimer) clearTimeout(predictionUpdateTimer);
    predictionUpdateTimer = setTimeout(runRichnessPrediction, 120);
}

['predTempInput', 'predAlanInput', 'predPrecipInput', 'predNdviInput'].forEach(function(id) {
    var input = document.getElementById(id);
    if (!input) return;
    input.addEventListener('input', schedulePredictionUpdate);
    input.addEventListener('change', runRichnessPrediction);
});

// ── Species filters ───────────────────────────────────────

function filterSpecies(type) {
    activeLightFilter = type;
    applyLandCoverFilter();
}

function filterMigration(type) {
    activeMigrationFilter = type;
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

    // Initialize BAU + Mitigation scenario panel
    initAnalyticsScenarioUI();

    // Initialize richness prediction interface
    populatePredictionCityDropdown();
    runRichnessPrediction();

    applyAvpStaggerReveal('#geoControlBar, #analyticsScenarioSection, #geoRichnessInterface, #geoDefaultInsights .card', 90, 60);

}).catch(function(err) {
    document.getElementById('loading').style.display = 'none';
    console.error('Error loading map data:', err);
});

// ── Global SHAP chart ─────────────────────────────────────

var globalShapChart = null;

function renderGlobalShapChart(shapRows) {
    var rows = normalizeShapChart(shapRows || []);
    var labels = rows.map(function(item) { return item.feature; });
    var rawValues = rows.map(function(item) { return Number(item.importance) || 0; });
    var rawTotal = rawValues.reduce(function(sum, v) { return sum + v; }, 0);
    var values = rawTotal > 0
        ? rawValues.map(function(v) { return (v / rawTotal) * 100; })
        : rawValues.map(function() { return 0; });
    if (!labels.length) {
        labels = ['No data'];
        values = [0];
        rawValues = [0];
    }

    var ctxGlobal = document.getElementById('globalShapChart').getContext('2d');
    if (globalShapChart) {
        globalShapChart.destroy();
        globalShapChart = null;
    }

    var axisMax = 100;

    globalShapChart = new Chart(ctxGlobal, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Feature Importance',
                data: values,
                rawValues: rawValues,
                backgroundColor: '#2c5f2d'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            animation: getAvpBarAnimation(95),
            plugins: {
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            var pct = Number(context.parsed.y || 0).toFixed(2) + '%';
                            var raw = Number(context.dataset.rawValues?.[context.dataIndex] || 0).toFixed(6);
                            return 'Share: ' + pct + ' (raw: ' + raw + ')';
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    max: axisMax,
                    title: { display: true, text: 'SHAP share (%)' }
                }
            }
        }
    });
}

renderGlobalShapChart([]);
</script>
EOD;

require_once 'includes/footer.php';
?>
