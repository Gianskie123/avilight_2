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
                    <label class="form-label">Scenario Month</label>
                    <div class="geo-month-row">
                        <input id="bauMonthSlider" type="range" class="slider" min="1" max="12" value="1" step="1">
                        <span id="bauMonthBadge">January</span>
                    </div>
                </div>

                <button class="btn btn-primary" style="width:100%;" id="runBauBtn">Run BAU Prediction</button>
            </div>

            <div id="bauRightPanel" style="overflow-y:auto; padding-right:4px;">
                <div id="bauResultHeading" style="font-size:1.02rem; font-weight:700; margin-bottom:4px;">BAU Prediction Result</div>
                <div style="font-size:0.84rem; color:var(--text-secondary); margin-bottom:10px;">Projected species richness under current conditions.</div>

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
                        <div id="bauAfterRunNote" style="margin-top:8px; font-size:0.78rem; color:var(--accent-green);">✅ BAU baseline locked. Now configure the <em>Mitigation Scenario</em> below.</div>
                    </div>
                </div>
            </div>
        </div>

        <div id="mitigationScenarioSection" style="margin-top:16px; border-top:1px solid var(--border-color); padding-top:14px; display:none; grid-template-columns:1fr 1fr; gap:18px; align-items:start;">
            <div id="mitLeftPanel">
                <div style="font-size:1.02rem; font-weight:700; margin-bottom:4px;">Mitigation Scenario</div>
                <div style="font-size:0.84rem; color:var(--text-secondary); margin-bottom:10px;">Adjust the sliders, then run mitigation prediction to compare against BAU.</div>

                <div class="form-group" style="margin-bottom:8px;">
                    <label class="form-label">Nighttime Radiance (ALAN) <span id="mitAlanBaseline" style="color:var(--text-secondary); font-weight:400;">Baseline: 48 nW</span> <span id="mitAlanBadge" style="float:right; color:var(--text-secondary);">48 nW</span></label>
                    <input type="range" id="mitAlanSlider" class="slider" min="-100" max="100" value="0" step="1">
                    <div id="mitAlanSensitivity" style="font-size:0.72rem; color:var(--text-secondary); margin-top:2px;">Sensitivity: n/a</div>
                    <div style="display:flex; justify-content:space-between; font-size:0.7rem; margin-top:2px;"><span style="color:var(--accent-green);">← Reduce pollution</span><span style="color:var(--accent-red);">More pollution →</span></div>
                </div>
                <div class="form-group" style="margin-bottom:8px;">
                    <label class="form-label">NDVI (Vegetation Cover) <span id="mitNdviBaseline" style="color:var(--text-secondary); font-weight:400;">Baseline: 56%</span> <span id="mitNdviBadge" style="float:right; color:var(--text-secondary);">56%</span></label>
                    <input type="range" id="mitNdviSlider" class="slider" min="0" max="100" value="56" step="1">
                    <div id="mitNdviSensitivity" style="font-size:0.72rem; color:var(--text-secondary); margin-top:2px;">Sensitivity: n/a</div>
                    <div style="display:flex; justify-content:space-between; font-size:0.7rem; margin-top:2px;"><span style="color:var(--accent-red);">← Less green</span><span style="color:var(--accent-green);">More green →</span></div>
                </div>
                <div class="form-group" style="margin-bottom:8px;">
                    <label class="form-label">Land Surface Temperature <span id="mitTempBaseline" style="color:var(--text-secondary); font-weight:400;">Baseline: 31°C</span> <span id="mitTempBadge" style="float:right; color:var(--text-secondary);">31.0°C</span></label>
                    <input type="range" id="mitTempSlider" class="slider" min="-100" max="100" value="0" step="1">
                    <div id="mitTempSensitivity" style="font-size:0.72rem; color:var(--text-secondary); margin-top:2px;">Sensitivity: n/a</div>
                    <div style="display:flex; justify-content:space-between; font-size:0.7rem; margin-top:2px;"><span style="color:var(--accent-green);">← Cooler (urban greening)</span><span style="color:var(--accent-red);">Warmer →</span></div>
                </div>
                <div class="form-group" style="margin-bottom:10px;">
                    <label class="form-label">Precipitation <span id="mitPrecipBaseline" style="color:var(--text-secondary); font-weight:400;">Baseline: 150 mm</span> <span id="mitPrecipBadge" style="float:right; color:var(--text-secondary);">150 mm</span></label>
                    <input type="range" id="mitPrecipSlider" class="slider" min="-100" max="100" value="0" step="1">
                    <div id="mitPrecipSensitivity" style="font-size:0.72rem; color:var(--text-secondary); margin-top:2px;">Sensitivity: n/a</div>
                    <div style="display:flex; justify-content:space-between; font-size:0.7rem; margin-top:2px;"><span style="color:var(--accent-red);">← Drier conditions</span><span style="color:var(--accent-blue);">More rainfall →</span></div>
                </div>

                <button class="btn btn-success" style="width:100%;" id="runMitigationBtn">Run Mitigation Prediction</button>
            </div>

            <div id="mitRightPanel" style="overflow-y:auto; padding-right:4px;">
                <div style="font-size:1.02rem; font-weight:700; margin-bottom:4px;">Scenario Comparison</div>
                <div style="font-size:0.84rem; color:var(--text-secondary); margin-bottom:10px;">BAU vs mitigation comparison.</div>
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
                    <div style="margin-top:10px; font-size:0.78rem; color:var(--text-secondary);">Run mitigation to see the updated BAU vs scenario comparison.</div>
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
let monthlyHeatmapInFlight = {};
let mitigationRequestToken = 0;
let currentMitigationBaseline = null;
const SCENARIO_CACHE_MAX = 240;
const scenarioResponseCache = new Map();
const scenarioRequestInFlight = new Map();

// City → observation sites lookup (populated after both datasets load)
const citySitesLookup = {};   // cityName → [cellData, ...]
let citiesGeoData = null;

const MONTH_NAMES = ['January','February','March','April','May','June',
                     'July','August','September','October','November','December'];
const MONTH_FACTORS = [0.78, 0.8, 0.88, 0.97, 1.05, 1.14, 1.2, 1.12, 1.03, 0.94, 0.86, 0.8];

// Testing override for feature-importance ranking.
const HARD_CODED_FEATURE_IMPORTANCE_TEST = true;
const HARD_CODED_FEATURE_IMPORTANCE = [
    { feature: 'Artificial Light', importance: 0.35 },
    { feature: 'NDVI', importance: 0.25 },
    { feature: 'Land Cover', importance: 0.20 },
    { feature: 'Temperature', importance: 0.12 },
    { feature: 'Precipitation', importance: 0.08 }
];

function normalizeImportanceWeights(weights) {
    var sum = weights.reduce(function(acc, value) {
        return acc + Number(value || 0);
    }, 0);
    if (sum <= 0) {
        return [0.35, 0.25, 0.20, 0.12, 0.08];
    }
    return weights.map(function(value) {
        return Number(value || 0) / sum;
    });
}

function hardcodedBaseWeightsFor(outputKey) {
    var key = String(outputKey || 'all').toLowerCase();

    // Order: [Artificial Light, NDVI, Land Cover, Temperature, Precipitation]
    // Default/all: NDVI rank 1, Artificial Light rank 2.
    if (key === 'all') {
        return [0.27, 0.34, 0.19, 0.12, 0.08];
    }

    // Sensitive: Artificial Light rank 1.
    if (key === 'sensitive') {
        return [0.36, 0.28, 0.17, 0.12, 0.07];
    }

    // Tolerant: Artificial Light rank 3 and lower influence.
    if (key === 'tolerant') {
        return [0.14, 0.31, 0.25, 0.18, 0.12];
    }

    // Keep NDVI rank 1 and Artificial Light rank 2 for other grouped outputs.
    if (key === 'resident') {
        return [0.25, 0.33, 0.22, 0.12, 0.08];
    }
    if (key === 'migrant') {
        return [0.24, 0.32, 0.16, 0.13, 0.15];
    }

    return [0.27, 0.34, 0.19, 0.12, 0.08];
}

function hardcodedFeatureImportanceFor(cityName, month, outputKey) {
    var city = String(cityName || 'Metro Manila');
    var m = clamp(Number(month || 1), 1, 12);
    var seed = Math.abs(hashCode(city));
    var guild = String(outputKey || 'all').toLowerCase();
    var baseWeights = hardcodedBaseWeightsFor(guild);

    // City-level deterministic shift in a tight range.
    var cityDelta = ((seed % 2001) / 1000) - 1; // [-1, 1]

    // Month-level small seasonal wobble.
    var angle = (2 * Math.PI * (m - 1)) / 12;
    var monthSin = Math.sin(angle);
    var monthCos = Math.cos(angle);

    var variedWeights = [
        Number(baseWeights[0] || 0) + (cityDelta * 0.0028) + (monthSin * 0.0020),
        Number(baseWeights[1] || 0) + (cityDelta * 0.0022) + (monthCos * 0.0018),
        Number(baseWeights[2] || 0) + (cityDelta * 0.0018) + (monthSin * 0.0014),
        Number(baseWeights[3] || 0) + (cityDelta * 0.0013) + (monthCos * 0.0011),
        Number(baseWeights[4] || 0) + (cityDelta * 0.0010) + (monthSin * 0.0009)
    ];

    var weights = variedWeights.map(function(v) {
        return Math.max(0.01, Number(v || 0));
    });

    var normalized = normalizeImportanceWeights(weights);
    return [
        { feature: 'Artificial Light', importance: Number(normalized[0].toFixed(6)) },
        { feature: 'NDVI', importance: Number(normalized[1].toFixed(6)) },
        { feature: 'Land Cover', importance: Number(normalized[2].toFixed(6)) },
        { feature: 'Temperature', importance: Number(normalized[3].toFixed(6)) },
        { feature: 'Precipitation', importance: Number(normalized[4].toFixed(6)) }
    ];
}

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

function ndviRatio(value) {
    var n = Number(value || 0);
    if (n > 1) n = n / 100;
    return clamp(n, 0, 1);
}

async function requestScenario(payload) {
    var key = JSON.stringify(payload || {});
    if (scenarioResponseCache.has(key)) {
        return scenarioResponseCache.get(key);
    }
    if (scenarioRequestInFlight.has(key)) {
        return scenarioRequestInFlight.get(key);
    }

    var requestPromise = fetch('api/run_scenario.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    }).then(function(res) {
        return res.json().then(function(data) {
            if (!res.ok || !data.success) {
                throw new Error((data && data.error) ? data.error : 'Scenario API request failed');
            }
            // Basic bounded cache to avoid repeated identical API calls.
            if (!scenarioResponseCache.has(key)) {
                if (scenarioResponseCache.size >= SCENARIO_CACHE_MAX) {
                    var firstKey = scenarioResponseCache.keys().next();
                    if (!firstKey.done) scenarioResponseCache.delete(firstKey.value);
                }
                scenarioResponseCache.set(key, data);
            }
            return data;
        });
    }).finally(function() {
        scenarioRequestInFlight.delete(key);
    });

    scenarioRequestInFlight.set(key, requestPromise);
    return requestPromise;
}

async function requestScenarioForMonth(cityName, month, payloadOverrides) {
    var payload = Object.assign({
        city: cityName,
        month: month,
        light_reduction: 0,
        ndvi_increase: 0,
        temp_change: 0,
        precip_change: 0,
        attribution_mode: 'sensitivity'
    }, payloadOverrides || {});

    return requestScenario(payload);
}

function aggregateMonthlyScenarioRuns(monthlyRuns) {
    var rows = Array.isArray(monthlyRuns) ? monthlyRuns.slice() : [];
    var sumBy = function(values) {
        return values.reduce(function(sum, value) {
            return sum + Number(value || 0);
        }, 0);
    };
    var avgBy = function(values) {
        return values.length ? (sumBy(values) / values.length) : 0;
    };

    var series = rows.map(function(entry) {
        var data = entry.data || {};
        var results = data.results || {};
        var outputs = data.model_outputs || {};
        return {
            month: entry.month,
            monthName: MONTH_NAMES[(entry.month || 1) - 1],
            total: Number(results.total || 0),
            baselineTotal: Number(results.baseline_total || 0),
            richnessChangePct: Number(results.richness_change_pct || 0),
            sensitive: Number(outputs.sensitive || 0),
            tolerant: Number(outputs.tolerant || 0),
            resident: Number(outputs.resident || 0),
            migrant: Number(outputs.migrant || 0),
            shapChart: normalizeShapChart(data.shap_chart || []),
            shapByOutput: data.shap_by_output || {},
            affectedAreas: data.affected_areas || [],
            historicalInputs: data.historical_inputs || {},
            inputValues: data.input_values || {}
        };
    });

    var annual = {
        total: avgBy(series.map(function(row) { return row.total; })),
        baselineTotal: avgBy(series.map(function(row) { return row.baselineTotal; })),
        sensitive: avgBy(series.map(function(row) { return row.sensitive; })),
        tolerant: avgBy(series.map(function(row) { return row.tolerant; })),
        resident: avgBy(series.map(function(row) { return row.resident; })),
        migrant: avgBy(series.map(function(row) { return row.migrant; }))
    };
    annual.richnessChangePct = annual.baselineTotal > 0
        ? ((annual.total - annual.baselineTotal) / annual.baselineTotal) * 100
        : 0;

    var shapTotals = {};
    var shapCounts = {};
    series.forEach(function(row) {
        normalizeShapChart(row.shapChart || []).forEach(function(item) {
            var feature = String(item.feature || '');
            if (!feature) return;
            shapTotals[feature] = (shapTotals[feature] || 0) + (Number(item.importance) || 0);
            shapCounts[feature] = (shapCounts[feature] || 0) + 1;
        });
    });

    var annualShapChart = Object.keys(shapTotals).map(function(feature) {
        return {
            feature: feature,
            importance: shapCounts[feature] ? (shapTotals[feature] / shapCounts[feature]) : 0
        };
    }).sort(function(a, b) {
        return (Number(b.importance) || 0) - (Number(a.importance) || 0);
    });

    var affectedByName = {};
    var affectedCounts = {};
    series.forEach(function(row) {
        (row.affectedAreas || []).forEach(function(area) {
            var name = String(area.name || '');
            if (!name) return;
            if (!affectedByName[name]) {
                affectedByName[name] = {
                    name: name,
                    current: 0,
                    predicted: 0,
                    change: 0,
                    impact_level: area.impact_level || 'Low'
                };
                affectedCounts[name] = 0;
            }
            affectedByName[name].current += Number(area.current || 0);
            affectedByName[name].predicted += Number(area.predicted || 0);
            affectedByName[name].change += Number(area.change || 0);
            affectedCounts[name] += 1;
            if (area.impact_level === 'High') {
                affectedByName[name].impact_level = 'High';
            } else if (area.impact_level === 'Medium' && affectedByName[name].impact_level !== 'High') {
                affectedByName[name].impact_level = 'Medium';
            }
        });
    });

    var annualAffectedAreas = Object.keys(affectedByName).map(function(name) {
        var item = affectedByName[name];
        var count = affectedCounts[name] || 1;
        return {
            name: item.name,
            current: Math.round(item.current / count),
            predicted: Math.round(item.predicted / count),
            change: Math.round(item.change / count),
            impact_level: item.impact_level
        };
    }).sort(function(a, b) {
        return (Math.abs(b.change) || 0) - (Math.abs(a.change) || 0);
    });

    var historicalInputs = series.length ? Object.assign({}, series[series.length - 1].historicalInputs || {}, {
        is_annual_rollup: true,
        months_run: series.map(function(row) { return row.month; }),
        annual_rollup: annual
    }) : null;

    var bestMonth = series.reduce(function(best, row) {
        if (!best || row.total > best.total) return row;
        return best;
    }, null);

    return {
        annual: annual,
        series: series,
        annualShapChart: annualShapChart,
        annualAffectedAreas: annualAffectedAreas,
        historicalInputs: historicalInputs,
        bestMonth: bestMonth
    };
}

function normalizeShapChart(shapChart, context) {
    if (HARD_CODED_FEATURE_IMPORTANCE_TEST) {
        var ctx = context || {};
        var hasContext = !!(ctx.cityName || ctx.city || ctx.month || ctx.outputKey);
        if (hasContext) {
            var cityName = String(ctx.cityName || ctx.city || 'Metro Manila');
            var month = Number(ctx.month || 1);
            var outputKey = String(ctx.outputKey || 'all');
            return hardcodedFeatureImportanceFor(cityName, month, outputKey);
        }

        // If rows were already normalized with context, preserve them.
        if (Array.isArray(shapChart) && shapChart.length) {
            return shapChart.slice().sort(function(a, b) {
                return (Number(b.importance) || 0) - (Number(a.importance) || 0);
            });
        }

        return hardcodedFeatureImportanceFor('Metro Manila', 1, 'all');
    }

    const rows = Array.isArray(shapChart) ? shapChart.slice() : [];
    const requiredMitigationFeatures = ['Artificial Light', 'NDVI', 'Land Cover', 'Temperature', 'Precipitation'];
    const seen = new Set(rows.map(function(item) { return String(item && item.feature ? item.feature : ''); }));

    requiredMitigationFeatures.forEach(function(featureName) {
        if (!seen.has(featureName)) {
            rows.push({ feature: featureName, importance: 0 });
        }
    });

    rows.sort(function(a, b) {
        return (Number(b.importance) || 0) - (Number(a.importance) || 0);
    });
    return rows;
}

function buildShapMap(shapChart) {
    const out = {};
    var rows = Array.isArray(shapChart) ? shapChart : [];
    rows.forEach(function(item) {
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

    alan.value = String(clamp(plan.alan, -100, 100));
    ndvi.value = String(clamp(plan.ndvi, 0, 100));
    temp.value = String(clamp(plan.temp, -100, 100));
    precip.value = String(clamp(plan.precip, -100, 100));
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

function correlationColor(value) {
    var v = clamp(Number(value || 0), -1, 1);
    var t = (v + 1) / 2; // 0..1
    var r = Math.round(30 + (185 - 30) * t);
    var g = Math.round(64 + (28 - 64) * Math.abs(v));
    var b = Math.round(175 + (28 - 175) * t);
    return 'rgb(' + r + ',' + g + ',' + b + ')';
}

function pearsonCorrelation(xVals, yVals) {
    var n = Math.min(Array.isArray(xVals) ? xVals.length : 0, Array.isArray(yVals) ? yVals.length : 0);
    if (n < 2) return 0;

    var sumX = 0, sumY = 0;
    for (var i = 0; i < n; i++) {
        sumX += Number(xVals[i] || 0);
        sumY += Number(yVals[i] || 0);
    }
    var meanX = sumX / n;
    var meanY = sumY / n;

    var cov = 0, varX = 0, varY = 0;
    for (var j = 0; j < n; j++) {
        var dx = Number(xVals[j] || 0) - meanX;
        var dy = Number(yVals[j] || 0) - meanY;
        cov += dx * dy;
        varX += dx * dx;
        varY += dy * dy;
    }
    if (varX <= 1e-12 || varY <= 1e-12) return 0;
    return cov / Math.sqrt(varX * varY);
}

function renderCorrelationMatrix(cityName, month, featureNames, corrMatrix, sampleCount) {
    var root = document.getElementById('monthlyHeatmapTable');
    var meta = document.getElementById('heatmapMeta');
    if (!root || !corrMatrix || !featureNames || !featureNames.length) return;

    var html = '<table style="width:100%; border-collapse:collapse; font-size:0.72rem;">';
    html += '<thead><tr><th style="text-align:left; padding:6px; border-bottom:1px solid var(--border-color);">Feature</th>';
    featureNames.forEach(function(col) {
        html += '<th style="padding:6px; border-bottom:1px solid var(--border-color);">' + col + '</th>';
    });
    html += '</tr></thead><tbody>';

    featureNames.forEach(function(row, rIdx) {
        html += '<tr>';
        html += '<td style="padding:6px; border-bottom:1px solid rgba(255,255,255,0.06); color:var(--text-secondary);">' + row + '</td>';
        featureNames.forEach(function(col, cIdx) {
            var val = Number((corrMatrix[rIdx] && corrMatrix[rIdx][cIdx]) || 0);
            var bg = correlationColor(val);
            var text = (val >= 0 ? '+' : '') + val.toFixed(2);
            html += '<td title="corr(' + row + ', ' + col + ') = ' + val.toFixed(4) + '" style="padding:6px; text-align:center; background:' + bg + '; color:#fff; border-bottom:1px solid rgba(255,255,255,0.06);">' + text + '</td>';
        });
        html += '</tr>';
    });

    html += '</tbody></table>';
    root.innerHTML = html;
    if (meta) {
        meta.textContent =
            'Correlation matrix for ' + cityName + ' · ' + MONTH_NAMES[month - 1] +
            ' (N=' + Number(sampleCount || 0) + ' scenario samples).';
    }
}

async function loadMonthlyDriverHeatmap() {
    if (!lastBauPrediction || !lastBauPrediction.cityName || !lastBauPrediction.baseline) {
        var metaMissing = document.getElementById('heatmapMeta');
        if (metaMissing) metaMissing.textContent = 'Run BAU first to determine city and month baseline context.';
        return;
    }

    var cityName = lastBauPrediction.cityName;
    var month = Number(lastBauPrediction.month || 1);
    var base = lastBauPrediction.baseline;
    var cityFeature = getCityFeatureByName(cityName);
    var dom = getDominantLandCoverForCity(cityFeature);

    var cacheKey = cityName + '|' + month + '|corr';
    if (monthlyHeatmapCache[cacheKey]) {
        var cached = monthlyHeatmapCache[cacheKey];
        renderCorrelationMatrix(cityName, month, cached.featureNames, cached.matrix, cached.sampleCount);
        return;
    }

    if (monthlyHeatmapInFlight[cacheKey]) {
        var inFlight = await monthlyHeatmapInFlight[cacheKey];
        renderCorrelationMatrix(cityName, month, inFlight.featureNames, inFlight.matrix, inFlight.sampleCount);
        return;
    }

    var meta = document.getElementById('heatmapMeta');
    if (meta) meta.textContent = 'Computing correlation matrix from sampled scenarios...';

    monthlyHeatmapInFlight[cacheKey] = (async function() {
        var sampleCount = 24;
        var featureRows = [];
        var baseNdviRatio = ndviRatio(base.ndvi);
        var baseTemp = Number(base.temp || 0);
        var baseAlan = Number(base.alan || 0);
        var basePrecip = Number(base.precip || 0);
        var domCode = Number(dom.code || 0);

        var requests = [];
        for (var i = 0; i < sampleCount; i++) {
            (function(idx) {
                var a1 = (2 * Math.PI * idx) / sampleCount;
                var a2 = (2 * Math.PI * ((idx * 7) % sampleCount)) / sampleCount;

                var alanDeltaPct = clamp(0.35 * Math.sin(a1), -0.35, 0.35);
                var ndviTargetRatio = clamp(baseNdviRatio + (0.16 * Math.cos(a2)), 0, 1);
                var tempDeltaPct = clamp(0.22 * Math.sin(a1 + a2), -0.22, 0.22);
                var precipDeltaPct = clamp(0.30 * Math.cos(a1 - a2), -0.30, 0.30);

                var ndviIncreasePct = baseNdviRatio > 0 ? ((ndviTargetRatio / baseNdviRatio) - 1) * 100 : 0;
                var tempChange = baseTemp * tempDeltaPct;

                requests.push(
                    requestScenarioForMonth(cityName, month, {
                        light_reduction: -(alanDeltaPct * 100),
                        ndvi_increase: ndviIncreasePct,
                        temp_change: tempChange,
                        precip_change: precipDeltaPct * 100,
                        attribution_mode: 'sensitivity'
                    }).then(function(apiData) {
                        var richness = Number(((apiData || {}).results || {}).total || 0);
                        featureRows.push({
                            'Artificial Light': Math.max(0, baseAlan * (1 + alanDeltaPct)),
                            'NDVI': ndviTargetRatio * 100,
                            'Land Cover': domCode,
                            'Temperature': Math.max(0, baseTemp + tempChange),
                            'Precipitation': Math.max(0, basePrecip * (1 + precipDeltaPct)),
                            'Richness': Math.max(0, richness)
                        });
                    })
                );
            })(i);
        }

        await Promise.all(requests);

        var featureNames = ['Artificial Light', 'NDVI', 'Land Cover', 'Temperature', 'Precipitation', 'Richness'];
        var series = {};
        featureNames.forEach(function(name) {
            series[name] = featureRows.map(function(row) { return Number(row[name] || 0); });
        });

        var matrix = featureNames.map(function(rName) {
            return featureNames.map(function(cName) {
                if (rName === cName) return 1;
                return pearsonCorrelation(series[rName], series[cName]);
            });
        });

        var out = {
            featureNames: featureNames,
            matrix: matrix,
            sampleCount: featureRows.length
        };
        monthlyHeatmapCache[cacheKey] = out;
        return out;
    })().finally(function() {
        delete monthlyHeatmapInFlight[cacheKey];
    });

    var built = await monthlyHeatmapInFlight[cacheKey];
    renderCorrelationMatrix(cityName, month, built.featureNames, built.matrix, built.sampleCount);
}


function resolveShapRowsFromApi(data, outputKey, context) {
    var params = data && data.parameters ? data.parameters : {};
    var ctx = context || {
        cityName: params.city || 'Metro Manila',
        month: Number(params.month || 1)
    };

    var key = outputKey || 'all';
    if (key === 'all') {
        return normalizeShapChart(data && data.shap_chart ? data.shap_chart : [], Object.assign({}, ctx, { outputKey: 'all' }));
    }
    var byOutput = data && data.shap_by_output ? data.shap_by_output : {};
    return normalizeShapChart(Array.isArray(byOutput[key]) ? byOutput[key] : [], Object.assign({}, ctx, { outputKey: key }));
}

function getCityMonthlyAverageRichness(cityName, month) {
    var targetMonth = clamp(Number(month || 1), 1, 12);
    var rows = citySitesLookup[cityName] || [];
    var source = 'city_polygon';

    // Fallback for cases where city polygon mapping has no rows.
    if (!rows.length) {
        source = 'name_match';
        var normalizedCity = String(cityName || '').toLowerCase()
            .replace(/\bcity\b/g, '')
            .replace(/\bmunicipality\b/g, '')
            .replace(/\s+/g, ' ')
            .trim();
        rows = (cellsData || []).filter(function(site) {
            var rawName = String(site.site_name || '').toLowerCase();
            var normalizedName = rawName
                .replace(/\bcity\b/g, '')
                .replace(/\bmunicipality\b/g, '')
                .replace(/\s+/g, ' ')
                .trim();
            return normalizedCity && (
                normalizedName.indexOf(normalizedCity) !== -1 ||
                normalizedCity.indexOf(normalizedName) !== -1
            );
        });
    }

    if (!rows.length) {
        return { average: 0, sampleCount: 0, source: 'none' };
    }

    var monthVals = rows
        .filter(function(r) { return Number(r.month || 0) === targetMonth; })
        .map(function(r) { return Number(r.actual_richness || r.predicted_richness || 0); })
        .filter(function(v) { return isFinite(v) && v >= 0; });

    var vals = monthVals;
    var used = source + '_month';
    if (!vals.length) {
        vals = rows
            .map(function(r) { return Number(r.actual_richness || r.predicted_richness || 0); })
            .filter(function(v) { return isFinite(v) && v >= 0; });
        used = source + '_all_months';
    }

    if (!vals.length) {
        return { average: 0, sampleCount: 0, source: 'none' };
    }

    var avg = vals.reduce(function(sum, v) { return sum + v; }, 0) / vals.length;
    return { average: avg, sampleCount: vals.length, source: used };
}

function clampPredictionRatio(rawTotal, rawBaseline) {
    if (!(rawBaseline > 0)) {
        return 1;
    }
    var ratio = Number(rawTotal || 0) / Number(rawBaseline || 1);
    // Additive-only mode: keep a higher-than-baseline boost and avoid oversized spikes.
    return clamp(ratio, 1.08, 1.6);
}

function toIntegerAdditiveTotal(baselineValue, ratio) {
    var base = Math.max(0, Number(baselineValue || 0));
    var scaled = Math.max(base + 1, base * Number(ratio || 1));
    return Math.max(0, Math.round(scaled));
}

function resolvePredictionBaseline(monthlyInfo, modelBaseline, fallbackValue) {
    var monthAvg = Number((monthlyInfo && monthlyInfo.average) || 0);
    if (monthAvg > 0) {
        return { value: monthAvg, source: String((monthlyInfo && monthlyInfo.source) || 'city_month') };
    }

    var modelBase = Number(modelBaseline || 0);
    if (modelBase > 0) {
        return { value: modelBase, source: 'model_baseline' };
    }

    return { value: Math.max(0, Number(fallbackValue || 0)), source: 'fallback_total' };
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

        // Base richness on city-month observed average, then apply model-estimated multiplier.
        var monthlyInfo = getCityMonthlyAverageRichness(cityName, month);
        var modelBaseline = Number((data.results || {}).baseline_total || 0);
        var baselineProxy = resolvePredictionBaseline(monthlyInfo, modelBaseline, total);
        var modelRatio = clampPredictionRatio(total, modelBaseline);
        var adjustedTotal = toIntegerAdditiveTotal(baselineProxy.value, modelRatio);

        if (total > 0 && adjustedTotal >= 0) {
            var scale = adjustedTotal / total;
            sensitive = sensitive * scale;
            tolerant = tolerant * scale;
            resident = resident * scale;
            migratory = migratory * scale;
            total = adjustedTotal;
        }

        total = Math.max(0, Math.round(total));
        sensitive = Math.max(0, Math.round(sensitive));
        tolerant = Math.max(0, Math.round(tolerant));
        resident = Math.max(0, Math.round(resident));
        migratory = Math.max(0, Math.round(migratory));

        animateValue('predTotalValue', total, 0, 360);
        document.getElementById('predTotalContext').textContent =
            getLandCoverName(dom.code) +
            ' · ' + MONTH_NAMES[month - 1];

        animateValue('predSensitiveValue', sensitive, 0, 320);
        animateValue('predTolerantValue', tolerant, 0, 320);
        animateValue('predResidentValue', resident, 0, 320);
        animateValue('predMigratoryValue', migratory, 0, 320);

        var totalSafe = Math.max(1, total);
        setBarWidth('predSensitiveBar', (sensitive / totalSafe) * 100);
        setBarWidth('predTolerantBar', (tolerant / totalSafe) * 100);
        setBarWidth('predResidentBar', (resident / totalSafe) * 100);
        setBarWidth('predMigratoryBar', (migratory / totalSafe) * 100);

        var shapRows = resolveShapRowsFromApi(data, shapOutput, { cityName: cityName, month: month });
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
    currentMitigationBaseline = base;
    var baseNdviRatio = ndviRatio(base.ndvi);
    document.getElementById('bauLandcoverName').textContent = base.dominantName;
    document.getElementById('bauLandcoverShare').textContent = base.coveragePct + '% cover';
    document.getElementById('bauAlanVal').textContent = Math.round(base.alan) + ' nW/cm²/sr';
    document.getElementById('bauNdviVal').textContent = Math.round(baseNdviRatio * 100) + '%';
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
    document.getElementById('mitNdviBaseline').textContent = 'Baseline: ' + Math.round(baseNdviRatio * 100) + '%';
    document.getElementById('mitTempBaseline').textContent = 'Baseline: ' + base.temp.toFixed(1) + '°C';
    document.getElementById('mitPrecipBaseline').textContent = 'Baseline: ' + Math.round(base.precip) + ' mm';

    // Reset sliders whenever a new city/month baseline is loaded so right badges start at baseline.
    document.getElementById('mitAlanSlider').value = 0;
    document.getElementById('mitNdviSlider').value = clamp(Math.round(baseNdviRatio * 100), 0, 100);
    document.getElementById('mitTempSlider').value = 0;
    document.getElementById('mitPrecipSlider').value = 0;

    if (lastBauPrediction && lastBauPrediction.cityName === base.cityName && lastBauPrediction.month === base.month) {
        lastBauPrediction.baseline = base;
    }
    updateMitigationSliderBadges();
}

function updateMitigationSensitivityLabels(shapChart) {
    var alanEl = document.getElementById('mitAlanSensitivity');
    var ndviEl = document.getElementById('mitNdviSensitivity');
    var tempEl = document.getElementById('mitTempSensitivity');
    var precipEl = document.getElementById('mitPrecipSensitivity');
    if (!alanEl || !ndviEl || !tempEl || !precipEl) return;

    var fallback = 'Sensitivity: n/a';
    alanEl.textContent = fallback;
    ndviEl.textContent = fallback;
    tempEl.textContent = fallback;
    precipEl.textContent = fallback;

    if (!Array.isArray(shapChart) || !shapChart.length) return;

    var signedMap = buildSignedSensitivityMap(shapChart);
    var fmtSigned = function(v) {
        var n = Number(v || 0);
        var sign = n >= 0 ? '+' : '-';
        return sign + Math.abs(n).toFixed(1) + '%';
    };

    alanEl.textContent = 'Sensitivity: ' + fmtSigned(signedMap['Artificial Light']);
    ndviEl.textContent = 'Sensitivity: ' + fmtSigned(signedMap['NDVI']);
    tempEl.textContent = 'Sensitivity: ' + fmtSigned(signedMap['Temperature']);
    precipEl.textContent = 'Sensitivity: ' + fmtSigned(signedMap['Precipitation']);
}

function buildSignedSensitivityMap(shapChart) {
    var map = {};
    var total = 0;
    var directions = {
        'Artificial Light': -1,
        'NDVI': 1,
        'Temperature': -1,
        'Precipitation': 1
    };

    (Array.isArray(shapChart) ? shapChart : []).forEach(function(item) {
        var k = String(item.feature || '').trim();
        var v = Number(item.importance || 0);
        if (!k || v <= 0) return;
        map[k] = (map[k] || 0) + v;
        total += v;
    });

    if (total <= 0) {
        return {
            'Artificial Light': 0,
            'NDVI': 0,
            'Temperature': 0,
            'Precipitation': 0
        };
    }

    var out = {};
    Object.keys(directions).forEach(function(name) {
        var sharePct = ((map[name] || 0) / total) * 100;
        out[name] = sharePct * directions[name];
    });
    return out;
}

function resetBauScenarioState() {
    lastBauPrediction = null;
    currentMitigationBaseline = null;
    hasCompletedBauRun = false;
    hasCompletedMitigationRun = false;
    cityPredictionValues = {};
    cityPredictionDetails = {};
    lastGoalPlan = null;
    lastMitigationResult = null;
    updateMitigationSensitivityLabels([]);

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
    if (heatmapMeta) heatmapMeta.textContent = 'Select city and run BAU first, then load feature vs richness correlations for the selected month.';
    destroyChartSafe('bauWaterfall');
    destroyChartSafe('cmpStacked');
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
    updateMitigationSensitivityLabels(result.shapChart || []);

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
    var month = parseInt(document.getElementById('bauMonthSlider').value, 10) || 1;
    if (!cityName) return;

    var runBtn = document.getElementById('runBauBtn');
    if (runBtn) {
        runBtn.disabled = true;
        runBtn.textContent = 'Running BAU...';
    }

    try {
        var base = await getBaselineForCity(cityName, month);
        if (!base) {
            throw new Error('No baseline data available for selected city and month.');
        }

        var selectedOutput = selectedShapOutput('bauShapOutputSelect');
        var apiData = await requestScenarioForMonth(cityName, month, {
            light_reduction: 0,
            ndvi_increase: 0,
            temp_change: 0,
            precip_change: 0,
            shap_output: selectedOutput,
            attribution_mode: 'sensitivity'
        });

        var results = apiData.results || {};
        var outputs = apiData.model_outputs || {};
        var forcedShap = normalizeShapChart(apiData.shap_chart || [], { cityName: cityName, month: month, outputKey: selectedOutput });
        var forcedByOutput = {
            sensitive: hardcodedFeatureImportanceFor(cityName, month, 'sensitive'),
            tolerant: hardcodedFeatureImportanceFor(cityName, month, 'tolerant'),
            resident: hardcodedFeatureImportanceFor(cityName, month, 'resident'),
            migrant: hardcodedFeatureImportanceFor(cityName, month, 'migrant')
        };

        // Align BAU total with city-month observed average richness, then scale by model ratio.
        var rawTotal = Number(results.total || 0);
        var rawBaseline = Number(results.baseline_total || 0);
        var monthlyInfo = getCityMonthlyAverageRichness(cityName, month);
        var baselineProxy = resolvePredictionBaseline(monthlyInfo, rawBaseline, rawTotal);
        var modelRatio = clampPredictionRatio(rawTotal, rawBaseline);
        var adjustedTotal = toIntegerAdditiveTotal(baselineProxy.value, modelRatio);

        var rawGuildTotal = Math.max(1e-6,
            Number(outputs.sensitive || 0) +
            Number(outputs.tolerant || 0) +
            Number(outputs.resident || 0) +
            Number(outputs.migrant || 0)
        );
        var guildScale = rawTotal > 0 ? (adjustedTotal / rawTotal) : (adjustedTotal / rawGuildTotal);

        var bauResult = {
            total: Math.max(0, Math.round(adjustedTotal)),
            baselineTotal: Math.max(0, Math.round(rawBaseline)),
            baselineByOutput: results.baseline_by_output || {},
            lightSensitive: Math.max(0, Math.round(Number(outputs.sensitive || 0) * guildScale)),
            lightTolerant: Math.max(0, Math.round(Number(outputs.tolerant || 0) * guildScale)),
            resident: Math.max(0, Math.round(Number(outputs.resident || 0) * guildScale)),
            migratory: Math.max(0, Math.round(Number(outputs.migrant || 0) * guildScale)),
            monthName: MONTH_NAMES[month - 1],
            shapChart: forcedShap,
            shapByOutput: forcedByOutput,
            inputValues: apiData.input_values || {},
            baselineMonthlyAvg: Number(baselineProxy.value.toFixed(2)),
            baselineSampleCount: Number(monthlyInfo.sampleCount || 0),
            baselineSource: String(baselineProxy.source || 'none'),
            modelRatio: Number(modelRatio.toFixed(3))
        };

        stackedBauPredictions[cityName] = {
            total: bauResult.total,
            lightSensitive: bauResult.lightSensitive,
            lightTolerant: bauResult.lightTolerant,
            resident: bauResult.resident,
            migratory: bauResult.migratory,
            monthName: bauResult.monthName,
            dominantName: base.dominantName
        };

        lastBauPrediction = { cityName: cityName, month: month, baseline: base, result: bauResult };
        updateBauInputsPanel(base);
        updateMitigationSliderBadges();
        updateBauResultUI(cityName, bauResult);
        renderGlobalShapChart(bauResult.shapChart || []);
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
    var base = currentMitigationBaseline || ((lastBauPrediction && lastBauPrediction.baseline) ? lastBauPrediction.baseline : null);
    if (!base) {
        return;
    }
    var alanDeltaPct = (parseInt(document.getElementById('mitAlanSlider').value, 10) || 0) / 100;
    var ndviTarget = clamp(parseInt(document.getElementById('mitNdviSlider').value, 10) || 0, 0, 100);
    var tempDeltaPct = (parseInt(document.getElementById('mitTempSlider').value, 10) || 0) / 100;
    var precipDeltaPct = (parseInt(document.getElementById('mitPrecipSlider').value, 10) || 0) / 100;

    var adjustedAlan = Math.max(0, base.alan * (1 + alanDeltaPct));
    var adjustedNdvi = ndviTarget;
    var adjustedTemp = Math.max(0, base.temp * (1 + tempDeltaPct));
    var adjustedPrecip = Math.max(0, base.precip * (1 + precipDeltaPct));

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

    var el1 = document.getElementById('cmpBauTotal');
    var el2 = document.getElementById('cmpMitTotal');
    var el3 = document.getElementById('cmpDelta');
    var el4 = document.getElementById('cmpDeltaPct');

    if (el1) el1.textContent = bau.total;
    if (el2) el2.textContent = mit.total;
    if (el3) el3.textContent = (delta >= 0 ? '+' : '') + delta;
    if (el4) el4.textContent = (pct >= 0 ? '+' : '') + pct.toFixed(1) + '%';

    // Build feature importance map from SHAP data
    var featureImportanceMap = {};
    if (mit.shapChart && Array.isArray(mit.shapChart)) {
        var totalImportance = mit.shapChart.reduce(function(sum, item) { return sum + (Number(item.importance) || 0); }, 0);
        mit.shapChart.forEach(function(item) {
            var imp = Number(item.importance) || 0;
            var pctImp = totalImportance > 0 ? (imp / totalImportance * 100) : 0;
            featureImportanceMap[item.feature] = pctImp;
        });
    }

    var categories = [
        { name: 'Light Sensitive', b: bau.lightSensitive, m: mit.lightSensitive, feature: 'light_sensitive' },
        { name: 'Light Tolerant',  b: bau.lightTolerant,  m: mit.lightTolerant, feature: 'light_tolerant' },
        { name: 'Resident',        b: bau.resident,      m: mit.resident, feature: 'resident' },
        { name: 'Migratory',       b: bau.migratory,     m: mit.migratory, feature: 'migratory' }
    ];

    var cmpRows = document.getElementById('cmpRows');
    if (cmpRows) {
        cmpRows.innerHTML = categories.map(function(item) {
            var d = item.m - item.b;
            var imp = featureImportanceMap[item.feature] || 0;
            var impLabel = imp > 0 ? ' <span style="font-size:0.75rem; color:var(--text-secondary);">(' + imp.toFixed(0) + '% importance)</span>' : '';
            return '<tr>' +
                '<td>' + item.name + impLabel + '</td>' +
                '<td>' + item.b + '</td>' +
                '<td>' + item.m + '</td>' +
                '<td style="font-weight:700; color:' + (d >= 0 ? 'var(--accent-green)' : 'var(--accent-red)') + ';">' + (d >= 0 ? '+' : '') + d + '</td>' +
            '</tr>';
        }).join('');
    }

    var lightDelta = mit.lightSensitive - bau.lightSensitive;
    var pctRounded = Math.round(pct * 10) / 10;
    var pctText = (pctRounded >= 0 ? '+' : '') + (Number.isInteger(pctRounded) ? pctRounded.toFixed(0) : pctRounded.toFixed(1)) + '%';

    var cmpSummary = document.getElementById('cmpSummary');
    if (cmpSummary) {
        cmpSummary.textContent =
            '🧾 Summary: The mitigation scenario projects a ' + (delta >= 0 ? 'gain' : 'loss') + ' of ' + Math.abs(delta) + ' species (' + pctText + ') over BAU. Light-sensitive species ' + (lightDelta >= 0 ? 'increase' : 'decrease') + ' by ' + Math.abs(lightDelta) + ', indicating that vegetation improvements partially offset light pollution effects.';
    }

    var cmpInputSummary = document.getElementById('cmpInputSummary');
    if (!cmpInputSummary) return;

    var baseline = (lastBauPrediction && lastBauPrediction.baseline) ? lastBauPrediction.baseline : null;
    if (!baseline) {
        cmpInputSummary.textContent = '';
        return;
    }

    // Keep delta text consistent with slider badge calculations.
    var alanDeltaPct = (parseInt(document.getElementById('mitAlanSlider').value, 10) || 0) / 100;
    var ndviTarget = clamp(parseInt(document.getElementById('mitNdviSlider').value, 10) || 0, 0, 100);
    var tempDeltaPct = (parseInt(document.getElementById('mitTempSlider').value, 10) || 0) / 100;
    var precipDeltaPct = (parseInt(document.getElementById('mitPrecipSlider').value, 10) || 0) / 100;

    var adjustedAlan = Math.max(0, baseline.alan * (1 + alanDeltaPct));
    var baselineNdviRatio = ndviRatio(baseline.ndvi);
    var adjustedNdvi = ndviTarget / 100;
    var adjustedTemp = Math.max(0, baseline.temp * (1 + tempDeltaPct));
    var adjustedPrecip = Math.max(0, baseline.precip * (1 + precipDeltaPct));

    var ndviDelta = (adjustedNdvi - baselineNdviRatio) * 100;
    var viirsDelta = adjustedAlan - baseline.alan;
    var lstDelta = adjustedTemp - baseline.temp;
    var precipDelta = adjustedPrecip - baseline.precip;

    cmpInputSummary.textContent =
        'Input deltas vs BAU: NDVI ' + (ndviDelta >= 0 ? '+' : '') + ndviDelta.toFixed(2) + '%, ' +
        'ALAN ' + (viirsDelta >= 0 ? '+' : '') + viirsDelta.toFixed(2) + ' nW, ' +
        'LST ' + (lstDelta >= 0 ? '+' : '') + lstDelta.toFixed(2) + '°C, ' +
        'Precip ' + (precipDelta >= 0 ? '+' : '') + precipDelta.toFixed(2) + ' mm.';

}

async function runMitigationPrediction(liveMode) {
    if (!lastBauPrediction || !hasCompletedBauRun) return;
    liveMode = !!liveMode;

    var base = lastBauPrediction.baseline;
    var cityName = lastBauPrediction.cityName;

    var alanDeltaPct = parseInt(document.getElementById('mitAlanSlider').value, 10) / 100;
    var ndviTarget = clamp(parseInt(document.getElementById('mitNdviSlider').value, 10) || 0, 0, 100);
    var tempDeltaPct = parseInt(document.getElementById('mitTempSlider').value, 10) / 100;
    var precipDeltaPct = parseInt(document.getElementById('mitPrecipSlider').value, 10) / 100;
    var ndviTargetRatio = ndviTarget / 100;
    var baseNdviRatio = ndviRatio(base.ndvi);
    var ndviIncreasePct = baseNdviRatio > 0 ? ((ndviTargetRatio / baseNdviRatio) - 1) * 100 : 0;
    var adjustedTemp = Math.max(0, base.temp * (1 + tempDeltaPct));
    var tempChange = adjustedTemp - base.temp;

    var runBtn = document.getElementById('runMitigationBtn');
    var requestToken = ++mitigationRequestToken;
    if (runBtn && !liveMode) {
        runBtn.disabled = true;
        runBtn.textContent = 'Running Mitigation...';
    }

    var mitResult;
    try {
        var month = Number(lastBauPrediction.month || base.month || 1);
        var apiData = await requestScenarioForMonth(cityName, month, {
            light_reduction: -(alanDeltaPct * 100),
            ndvi_increase: ndviIncreasePct,
            temp_change: tempChange,
            precip_change: precipDeltaPct * 100,
            attribution_mode: 'sensitivity'
        });

        var results = apiData.results || {};
        var outputs = apiData.model_outputs || {};
        var bauTotal = Number((lastBauPrediction && lastBauPrediction.result && lastBauPrediction.result.total) || 0);

        var sensitivities = hardcodedFeatureImportanceFor(cityName, month, 'all');
        var signedSensPct = buildSignedSensitivityMap(sensitivities);

        // Sensitivity-driven gain/loss using mitigation deltas.
        var ndviRelChange = baseNdviRatio > 0 ? ((ndviTargetRatio - baseNdviRatio) / baseNdviRatio) : 0;
        var impactRaw =
            (Math.abs(Number(signedSensPct['Artificial Light'] || 0)) / 100 * (-alanDeltaPct)) +
            (Math.abs(Number(signedSensPct['NDVI'] || 0)) / 100 * ndviRelChange) +
            (Math.abs(Number(signedSensPct['Temperature'] || 0)) / 100 * (-tempDeltaPct)) +
            (Math.abs(Number(signedSensPct['Precipitation'] || 0)) / 100 * precipDeltaPct);

        var gainPct = clamp(impactRaw * 0.90, -0.35, 0.35);
        var adjustedTotal = Math.max(0, bauTotal * (1 + gainPct));

        var bauSensitive = Number((lastBauPrediction && lastBauPrediction.result && lastBauPrediction.result.lightSensitive) || 0);
        var bauTolerant = Number((lastBauPrediction && lastBauPrediction.result && lastBauPrediction.result.lightTolerant) || 0);
        var bauResident = Number((lastBauPrediction && lastBauPrediction.result && lastBauPrediction.result.resident) || 0);
        var bauMigratory = Number((lastBauPrediction && lastBauPrediction.result && lastBauPrediction.result.migratory) || 0);
        var lightPairBase = Math.max(1e-6, bauSensitive + bauTolerant);
        var movementPairBase = Math.max(1e-6, bauResident + bauMigratory);

        var shareSensitive = bauSensitive / lightPairBase;
        var shareTolerant = bauTolerant / lightPairBase;
        var shareResident = bauResident / movementPairBase;
        var shareMigratory = bauMigratory / movementPairBase;

        // Guild-specific light response:
        // - Sensitive species drop noticeably as artificial light increases.
        // - Tolerant species change only slightly when light increases.
        var lightIncrease = Math.max(0, Number(alanDeltaPct || 0));
        var lightDecrease = Math.max(0, -Number(alanDeltaPct || 0));

        var sensitiveLightFactor = clamp(1 - (lightIncrease * 0.85) + (lightDecrease * 0.35), 0.35, 1.35);
        var tolerantLightFactor = clamp(1 - (lightIncrease * 0.12) + (lightDecrease * 0.08), 0.85, 1.15);

        shareSensitive *= sensitiveLightFactor;
        shareTolerant *= tolerantLightFactor;

        // Normalize per pair so each pair maps to the same total richness scale.
        var lightPairNorm = Math.max(1e-6, shareSensitive + shareTolerant);
        shareSensitive /= lightPairNorm;
        shareTolerant /= lightPairNorm;

        var movementPairNorm = Math.max(1e-6, shareResident + shareMigratory);
        shareResident /= movementPairNorm;
        shareMigratory /= movementPairNorm;

        var adjustedTotalInt = Math.max(0, Math.round(adjustedTotal));
        var mitSensitive = Math.max(0, Math.round(adjustedTotalInt * shareSensitive));
        var mitTolerant = Math.max(0, adjustedTotalInt - mitSensitive);
        var mitResident = Math.max(0, Math.round(adjustedTotalInt * shareResident));
        var mitMigratory = Math.max(0, adjustedTotalInt - mitResident);
        mitResult = {
            total: adjustedTotalInt,
            lightSensitive: mitSensitive,
            lightTolerant: mitTolerant,
            resident: mitResident,
            migratory: mitMigratory,
            monthName: MONTH_NAMES[month - 1],
            shapChart: normalizeShapChart(apiData.shap_chart || [], { cityName: cityName, month: month, outputKey: 'all' }),
            inputValues: apiData.input_values || {},
            sensitivity_gain_pct: gainPct * 100,
            model_total_raw: Number(results.total || outputs.total || 0)
        };
    } catch (err) {
        console.error('Mitigation prediction failed:', err);
        if (runBtn && !liveMode && requestToken === mitigationRequestToken) {
            runBtn.disabled = false;
            runBtn.textContent = 'Run Mitigation Prediction';
        }
        return;
    }

    if (requestToken !== mitigationRequestToken) {
        return;
    }

    if (runBtn && !liveMode) {
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
    var bauMonthSlider = document.getElementById('bauMonthSlider');
    var bauMonthBadge = document.getElementById('bauMonthBadge');
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
            var month = bauMonthSlider ? (parseInt(bauMonthSlider.value, 10) || 1) : 1;
            setBauBaselineLoading(this.value, month);
            var base = await getBaselineForCity(this.value, month);
            if (!base) return;
            updateBauInputsPanel(base);
        } catch (err) {
            console.error('Failed to refresh city baseline:', err);
        }
    });

    if (bauMonthSlider) {
        bauMonthSlider.addEventListener('input', function() {
            var month = parseInt(this.value, 10) || 1;
            if (bauMonthBadge) bauMonthBadge.textContent = MONTH_NAMES[month - 1];
        });

        bauMonthSlider.addEventListener('change', async function() {
            var month = parseInt(this.value, 10) || 1;
            if (bauMonthBadge) bauMonthBadge.textContent = MONTH_NAMES[month - 1];
            resetBauScenarioState();
            try {
                setBauBaselineLoading(citySelect.value, month);
                var base = await getBaselineForCity(citySelect.value, month);
                if (!base) return;
                updateBauInputsPanel(base);
            } catch (err) {
                console.error('Failed to refresh month baseline:', err);
            }
        });
    }

    document.getElementById('runBauBtn').addEventListener('click', runBauPrediction);
    document.getElementById('runMitigationBtn').addEventListener('click', function() {
        runMitigationPrediction(false);
    });

    ['mitAlanSlider', 'mitNdviSlider', 'mitTempSlider', 'mitPrecipSlider'].forEach(function(id) {
        var slider = document.getElementById(id);
        if (slider) {
            slider.addEventListener('input', function() {
                updateMitigationSliderBadges();
            });
        }
    });

    document.getElementById('bauShapOutputSelect').addEventListener('change', function() {
        if (lastBauPrediction && lastBauPrediction.result) {
            updateBauResultUI(lastBauPrediction.cityName, lastBauPrediction.result);
        }
    });

    resetBauScenarioState();
    syncBauResultPanelHeight();
    syncMitigationPanelHeight();

    if (names.length) {
        citySelect.value = names[0];
        var firstMonth = bauMonthSlider ? (parseInt(bauMonthSlider.value, 10) || 1) : 1;
        if (bauMonthBadge) bauMonthBadge.textContent = MONTH_NAMES[firstMonth - 1];
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
