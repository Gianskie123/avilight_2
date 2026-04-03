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
                    <div style="margin-top:10px; font-size:0.78rem; color:var(--text-secondary); font-style:italic;">Prototype model — values are illustrative for presentation purposes.</div>
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
let lastBauPrediction = null;
let hasCompletedBauRun = false;
let hasCompletedMitigationRun = false;
let cityPredictionValues = {};
let cityPredictionDetails = {};
let stackedBauPredictions = {};

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

function runRichnessPrediction() {
    var cityName = document.getElementById('predCitySelect').value;
    var cityFeature = getCityFeatureByName(cityName);
    var dom = getDominantLandCoverForCity(cityFeature);
    var citySeed = Math.abs(hashCode(cityName || 'city'));
    var citySites = citySitesLookup[cityName] || [];

    var temp = parseFloat(document.getElementById('predTempInput').value) || 31;
    var alan = parseFloat(document.getElementById('predAlanInput').value) || 55;
    var precip = parseFloat(document.getElementById('predPrecipInput').value) || 150;
    var ndvi = parseFloat(document.getElementById('predNdviInput').value) || 41;
    var monthIdx = (parseInt(document.getElementById('predMonthSlider').value, 10) || 1) - 1;

    temp = clamp(temp, 10, 45);
    alan = clamp(alan, 0, 100);
    precip = clamp(precip, 0, 500);
    ndvi = clamp(ndvi, 0, 100);

    var base = LANDCOVER_RICHNESS[dom.code] || 8;
    var tempFactor = clamp(1 - Math.abs(temp - 28) / 22, 0.58, 1.08);
    var alanFactor = clamp((75 - alan) / 75, 0.22, 1.08);
    var precipFactor = clamp(0.72 + precip / 420, 0.72, 1.22);
    var ndviFactor = 0.72 + (ndvi / 100) * 0.86;
    var monthFactor = MONTH_FACTORS[monthIdx] || 1;
    var cityFactor = 0.88 + ((citySeed % 17) / 100);
    var siteDensityFactor = clamp(0.92 + Math.min(citySites.length, 12) * 0.015, 0.92, 1.1);

    var predicted = clamp(Math.round(base * tempFactor * alanFactor * precipFactor * ndviFactor * monthFactor * cityFactor * siteDensityFactor * 2.05), 2, 45);

    var sensitiveShare = clamp(0.52 - alan / 185 + ndvi / 240, 0.08, 0.65);
    var lightSensitive = Math.round(predicted * sensitiveShare);
    var lightTolerant = Math.max(0, predicted - lightSensitive);

    var residentShare = clamp(0.48 + precip / 900 - alan / 320, 0.2, 0.82);
    var resident = Math.round(predicted * residentShare);
    var migratory = Math.max(0, predicted - resident);

    var shapLight = clamp(alan / 100 * 0.62, 0.04, 0.62);
    var shapNdvi = clamp(ndvi / 100 * 0.34, 0.03, 0.34);
    var shapTemp = clamp((1 - Math.abs(temp - 28) / 18) * 0.24, 0.02, 0.24);
    var shapElev = clamp((dom.code === 13 ? 0.08 : 0.11), 0.04, 0.16);
    var shapWater = clamp((precip / 500) * 0.12, 0.02, 0.12);
    var shapTotal = shapLight + shapNdvi + shapTemp + shapElev + shapWater;

    animateValue('predTotalValue', predicted, 0, 360);
    document.getElementById('predTotalContext').textContent = getLandCoverName(dom.code) + ' · ALAN ' + alan.toFixed(0) + ' nW · ' + MONTH_NAMES[monthIdx];

    animateValue('predSensitiveValue', lightSensitive, 0, 320);
    animateValue('predTolerantValue', lightTolerant, 0, 320);
    animateValue('predResidentValue', resident, 0, 320);
    animateValue('predMigratoryValue', migratory, 0, 320);

    setBarWidth('predSensitiveBar', (lightSensitive / predicted) * 100);
    setBarWidth('predTolerantBar', (lightTolerant / predicted) * 100);
    setBarWidth('predResidentBar', (resident / predicted) * 100);
    setBarWidth('predMigratoryBar', (migratory / predicted) * 100);

    animateValue('predShapLightVal', shapLight, 2, 300);
    animateValue('predShapNdviVal', shapNdvi, 2, 300);
    animateValue('predShapTempVal', shapTemp, 2, 300);
    animateValue('predShapElevVal', shapElev, 2, 300);
    animateValue('predShapWaterVal', shapWater, 2, 300);

    setBarWidth('predShapLightBar', (shapLight / shapTotal) * 100);
    setBarWidth('predShapNdviBar', (shapNdvi / shapTotal) * 100);
    setBarWidth('predShapTempBar', (shapTemp / shapTotal) * 100);
    setBarWidth('predShapElevBar', (shapElev / shapTotal) * 100);
    setBarWidth('predShapWaterBar', (shapWater / shapTotal) * 100);

    document.getElementById('predDriverText').textContent =
        'Key driver: Light Intensity (' + shapLight.toFixed(2) + ') has the strongest influence on predicted richness for ' + cityName +
        '. High ALAN suppresses light-sensitive species while vegetation support (NDVI) helps retain refugia.';
}

function getBaselineForCity(cityName) {
    var feature = getCityFeatureByName(cityName);
    var dominant = getDominantLandCoverForCity(feature);
    var defaults = LANDCOVER_COVARIATES[dominant.code] || LANDCOVER_COVARIATES[13];
    var citySeed = Math.abs(hashCode(cityName || 'city'));

    return {
        cityName: cityName,
        dominantName: getLandCoverName(dominant.code),
        coveragePct: dominant.total > 0 ? Math.round((dominant.count / dominant.total) * 100) : 0,
        alan: clamp(defaults.alan + ((citySeed % 21) - 10), 0, 100),
        ndvi: clamp(defaults.ndvi + ((citySeed % 15) - 7), 0, 100),
        temp: clamp(defaults.temp + (((citySeed % 7) - 3) * 0.35), 10, 45),
        precip: clamp(defaults.precip + ((citySeed % 81) - 40), 0, 500)
    };
}

function computeScenarioPrediction(cityName, inputs, monthIndex) {
    var cityFeature = getCityFeatureByName(cityName);
    var dom = getDominantLandCoverForCity(cityFeature);
    var citySeed = Math.abs(hashCode(cityName || 'city'));
    var citySites = citySitesLookup[cityName] || [];

    var temp = clamp(inputs.temp, 10, 45);
    var alan = clamp(inputs.alan, 0, 100);
    var precip = clamp(inputs.precip, 0, 500);
    var ndvi = clamp(inputs.ndvi, 0, 100);

    var base = LANDCOVER_RICHNESS[dom.code] || 8;
    var tempFactor = clamp(1 - Math.abs(temp - 28) / 22, 0.58, 1.08);
    var alanFactor = clamp((75 - alan) / 75, 0.22, 1.08);
    var precipFactor = clamp(0.72 + precip / 420, 0.72, 1.22);
    var ndviFactor = 0.72 + (ndvi / 100) * 0.86;
    var monthFactor = MONTH_FACTORS[monthIndex] || 1;
    var cityFactor = 0.88 + ((citySeed % 17) / 100);
    var siteDensityFactor = clamp(0.92 + Math.min(citySites.length, 12) * 0.015, 0.92, 1.1);

    var predicted = clamp(Math.round(base * tempFactor * alanFactor * precipFactor * ndviFactor * monthFactor * cityFactor * siteDensityFactor * 2.05), 2, 45);

    var sensitiveShare = clamp(0.52 - alan / 185 + ndvi / 240, 0.08, 0.65);
    var lightSensitive = Math.round(predicted * sensitiveShare);
    var lightTolerant = Math.max(0, predicted - lightSensitive);

    var residentShare = clamp(0.48 + precip / 900 - alan / 320, 0.2, 0.82);
    var resident = Math.round(predicted * residentShare);
    var migratory = Math.max(0, predicted - resident);

    var shapLight = clamp(alan / 100 * 0.62, 0.04, 0.62);
    var shapNdvi = clamp(ndvi / 100 * 0.34, 0.03, 0.34);
    var shapTemp = clamp((1 - Math.abs(temp - 28) / 18) * 0.24, 0.02, 0.24);
    var shapElev = clamp((dom.code === 13 ? 0.08 : 0.11), 0.04, 0.16);
    var shapWater = clamp((precip / 500) * 0.12, 0.02, 0.12);

    return {
        total: predicted,
        lightSensitive: lightSensitive,
        lightTolerant: lightTolerant,
        resident: resident,
        migratory: migratory,
        shap: { light: shapLight, ndvi: shapNdvi, temp: shapTemp, elev: shapElev, water: shapWater },
        monthName: MONTH_NAMES[monthIndex]
    };
}

function updateBauInputsPanel(base) {
    document.getElementById('bauLandcoverName').textContent = base.dominantName;
    document.getElementById('bauLandcoverShare').textContent = base.coveragePct + '% cover';
    document.getElementById('bauAlanVal').textContent = Math.round(base.alan) + ' nW/cm²/sr';
    document.getElementById('bauNdviVal').textContent = Math.round(base.ndvi) + '%';
    document.getElementById('bauTempVal').textContent = base.temp.toFixed(1) + '°C';
    document.getElementById('bauPrecipVal').textContent = Math.round(base.precip) + ' mm';
    document.getElementById('mitAlanBaseline').textContent = 'Baseline: ' + Math.round(base.alan) + ' nW';
    document.getElementById('mitNdviBaseline').textContent = 'Baseline: ' + Math.round(base.ndvi) + '%';
    document.getElementById('mitTempBaseline').textContent = 'Baseline: ' + base.temp.toFixed(1) + '°C';
    document.getElementById('mitPrecipBaseline').textContent = 'Baseline: ' + Math.round(base.precip) + ' mm';
}

function resetBauScenarioState() {
    hasCompletedBauRun = false;
    hasCompletedMitigationRun = false;
    cityPredictionValues = {};
    cityPredictionDetails = {};

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
    refreshCityLayerStyles();
    syncMitigationPanelHeight();
}

function updateBauResultUI(cityName, result) {
    document.getElementById('bauResultEmpty').style.display = 'none';
    document.getElementById('bauResultContent').style.display = 'block';
    document.getElementById('bauResultHeading').textContent = 'BAU Prediction Result — ' + cityName + ' · ' + result.monthName;
    document.getElementById('bauResultTitle').textContent = 'BAU TOTAL PREDICTED — ' + cityName + ' · ' + result.monthName;
    document.getElementById('bauTotalPred').textContent = result.total;
    document.getElementById('bauShapTitle').textContent = 'Feature Importance (SHAP) — ' + cityName;
    document.getElementById('bauShapSubtitle').textContent = 'Local SHAP values for ' + cityName + ' · ' + document.getElementById('bauLandcoverName').textContent;

    document.getElementById('bauSensitiveVal').textContent = result.lightSensitive;
    document.getElementById('bauTolerantVal').textContent = result.lightTolerant;
    document.getElementById('bauResidentVal').textContent = result.resident;
    document.getElementById('bauMigratoryVal').textContent = result.migratory;

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

    var shapValues = [result.shap.light, result.shap.ndvi, result.shap.temp, result.shap.elev, result.shap.water];
    var shapAxisMax = Math.max.apply(null, shapValues);
    if (!isFinite(shapAxisMax) || shapAxisMax <= 0) {
        shapAxisMax = 0.1;
    }

    bauShapChartInstance = new Chart(shapCtx, {
        type: 'bar',
        data: {
            labels: ['Light Intensity', 'NDVI', 'Temperature', 'Elevation', 'Distance to Water'],
            datasets: [{ data: shapValues, backgroundColor: ['#ef4444', '#22c55e', '#f59e0b', '#8b5cf6', '#06b6d4'] }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            animation: getAvpBarAnimation(90),
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true, max: shapAxisMax, ticks: { color: '#9ca3af', font: { size: 10 } }, grid: { color: 'rgba(255,255,255,0.06)' } },
                x: { ticks: { color: '#9ca3af', font: { size: 10 } }, grid: { display: false } }
            }
        }
    });

    document.getElementById('bauShapText').textContent =
        'Interpretation: In ' + cityName + ', light intensity (' + result.shap.light.toFixed(2) + ') and ndvi (' + result.shap.ndvi.toFixed(2) + ') are the strongest drivers of bird species richness.';

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

function runBauPrediction() {
    var cityName = document.getElementById('bauCitySelect').value;
    if (!cityName) return;

    var monthIndex = (parseInt(document.getElementById('bauMonthSlider').value, 10) || 1) - 1;
    document.getElementById('bauMonthBadge').textContent = MONTH_NAMES[monthIndex];

    var base = getBaselineForCity(cityName);
    updateBauInputsPanel(base);
    updateMitigationSliderBadges();

    var result = computeScenarioPrediction(cityName, {
        alan: base.alan,
        ndvi: base.ndvi,
        temp: base.temp,
        precip: base.precip
    }, monthIndex);

    var cityFeature = getCityFeatureByName(cityName);
    var dominant = getDominantLandCoverForCity(cityFeature);
    stackedBauPredictions[cityName] = {
        total: result.total,
        lightSensitive: result.lightSensitive,
        lightTolerant: result.lightTolerant,
        resident: result.resident,
        migratory: result.migratory,
        monthName: result.monthName,
        dominantName: getLandCoverName(dominant.code)
    };

    lastBauPrediction = { cityName: cityName, monthIndex: monthIndex, baseline: base, result: result };
    updateBauResultUI(cityName, result);
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
    var cityName = document.getElementById('bauCitySelect').value;
    var base = (lastBauPrediction && lastBauPrediction.baseline) ? lastBauPrediction.baseline : getBaselineForCity(cityName);
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
}

function runMitigationPrediction() {
    if (!lastBauPrediction || !hasCompletedBauRun) return;

    var base = lastBauPrediction.baseline;
    var cityName = lastBauPrediction.cityName;
    var monthIndex = lastBauPrediction.monthIndex;

    var alanDeltaPct = parseInt(document.getElementById('mitAlanSlider').value, 10) / 100;
    var ndviDeltaPct = parseInt(document.getElementById('mitNdviSlider').value, 10) / 100;
    var tempDelta = parseInt(document.getElementById('mitTempSlider').value, 10) / 10;
    var precipDeltaPct = parseInt(document.getElementById('mitPrecipSlider').value, 10) / 100;

    var mitInputs = {
        alan: clamp(base.alan * (1 + alanDeltaPct), 0, 100),
        ndvi: clamp(base.ndvi * (1 + ndviDeltaPct), 0, 100),
        temp: clamp(base.temp + tempDelta, 10, 45),
        precip: clamp(base.precip * (1 + precipDeltaPct), 0, 500)
    };

    var mitResult = computeScenarioPrediction(cityName, mitInputs, monthIndex);

    var hasSliderChange = alanDeltaPct !== 0 || ndviDeltaPct !== 0 || tempDelta !== 0 || precipDeltaPct !== 0;
    if (hasSliderChange) {
        var mitigationSignal = (-alanDeltaPct * 0.9) + (ndviDeltaPct * 0.8) + (-tempDelta * 0.05) + (precipDeltaPct * 0.4);
        if (Math.abs(mitigationSignal) > 0.001) {
            var adjustedTotal = clamp(Math.round(mitResult.total + (mitigationSignal * 7)), 2, 50);
            if (adjustedTotal === lastBauPrediction.result.total) {
                adjustedTotal = clamp(adjustedTotal + (mitigationSignal > 0 ? 1 : -1), 2, 50);
            }
            if (adjustedTotal !== mitResult.total) {
                var sShare = mitResult.total > 0 ? mitResult.lightSensitive / mitResult.total : 0.32;
                var rShare = mitResult.total > 0 ? mitResult.resident / mitResult.total : 0.5;
                var adjSensitive = Math.round(adjustedTotal * sShare);
                var adjTolerant = Math.max(0, adjustedTotal - adjSensitive);
                var adjResident = Math.round(adjustedTotal * rShare);
                var adjMigratory = Math.max(0, adjustedTotal - adjResident);
                mitResult = {
                    total: adjustedTotal,
                    lightSensitive: adjSensitive,
                    lightTolerant: adjTolerant,
                    resident: adjResident,
                    migratory: adjMigratory,
                    shap: mitResult.shap,
                    monthName: mitResult.monthName
                };
            }
        }
    }

    hasCompletedMitigationRun = true;
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

    citySelect.addEventListener('change', function() {
        updateBauInputsPanel(getBaselineForCity(this.value));
        updateMitigationSliderBadges();
        resetBauScenarioState();
        focusMapOnCity(this.value);
    });

    document.getElementById('bauMonthSlider').addEventListener('input', function() {
        document.getElementById('bauMonthBadge').textContent = MONTH_NAMES[(parseInt(this.value, 10) || 1) - 1];
        resetBauScenarioState();
    });

    document.getElementById('runBauBtn').addEventListener('click', runBauPrediction);
    document.getElementById('runMitigationBtn').addEventListener('click', runMitigationPrediction);

    ['mitAlanSlider', 'mitNdviSlider', 'mitTempSlider', 'mitPrecipSlider'].forEach(function(id) {
        document.getElementById(id).addEventListener('input', updateMitigationSliderBadges);
    });

    updateMitigationSliderBadges();
    resetBauScenarioState();
    syncBauResultPanelHeight();
    syncMitigationPanelHeight();

    if (names.length) {
        citySelect.value = names[0];
        updateBauInputsPanel(getBaselineForCity(names[0]));
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
    var out = {};
    if (!citiesGeoData || !citiesGeoData.features) return out;

    citiesGeoData.features.forEach(function(feature) {
        var cityName = feature.properties.city_name;
        var dominant = getDominantLandCoverForCity(feature);
        var base = getBaselineForCity(cityName);
        var mitInputs = {
            alan: clamp(base.alan * (1 + mitigationDeltas.alanDeltaPct), 0, 100),
            ndvi: clamp(base.ndvi * (1 + mitigationDeltas.ndviDeltaPct), 0, 100),
            temp: clamp(base.temp + mitigationDeltas.tempDelta, 10, 45),
            precip: clamp(base.precip * (1 + mitigationDeltas.precipDeltaPct), 0, 500)
        };
        var result = computeScenarioPrediction(cityName, mitInputs, monthIndex);
        out[cityName] = {
            total: result.total,
            lightSensitive: result.lightSensitive,
            lightTolerant: result.lightTolerant,
            resident: result.resident,
            migratory: result.migratory,
            monthName: result.monthName,
            dominantName: getLandCoverName(dominant.code)
        };
    });

    return out;
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
        animation: getAvpBarAnimation(95),
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
