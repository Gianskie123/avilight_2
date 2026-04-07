<?php
$page_title = 'Statistical Reports';

$selected_area = 'All Areas';
$start_year = (int) ($_GET['start_year'] ?? 2014);
$end_year = (int) ($_GET['end_year'] ?? 2024);
$snapshot_year = (int) ($_GET['snapshot_year'] ?? 2024);
$snapshot_month = (int) ($_GET['snapshot_month'] ?? 1);

$available_areas = [
    'Caloocan',
    'Las Piñas',
    'Makati',
    'Malabon',
    'Mandaluyong',
    'Manila',
    'Marikina',
    'Muntinlupa',
    'Navotas',
    'Parañaque',
    'Pasay',
    'Pasig',
    'Pateros',
    'Quezon City',
    'San Juan',
    'Taguig',
    'Valenzuela',
];

$year_min = 2014;
$year_max = 2024;

function loadJsonAssocFile(string $path): array {
    if (!is_readable($path)) {
        return [];
    }
    $decoded = json_decode((string) file_get_contents($path), true);
    return is_array($decoded) ? $decoded : [];
}

function buildRuleBasedRecommendations(
    array $kbaAudit,
    array $trendContext,
    array $diagnosticsContext,
    array $featureContext
): array {
    $recommendations = [];

    $addRecommendation = static function (array $item) use (&$recommendations): void {
        $recommendations[] = array_merge([
            'source' => 'Reports',
            'source_key' => 'reports',
            'evidence' => [],
        ], $item);
    };

    if (!empty($kbaAudit)) {
        $criticalCount = 0;
        $atRiskCount = 0;
        $highLightCount = 0;
        $lowNdviCount = 0;
        $highLstCount = 0;

        foreach ($kbaAudit as $row) {
            $status = (string) ($row['status'] ?? '');
            if ($status === 'Critical') {
                $criticalCount++;
            }
            if ($status === 'Critical' || $status === 'At Risk') {
                $atRiskCount++;
            }
            if ((float) ($row['light_exposure'] ?? 0) > 40.0) {
                $highLightCount++;
            }
            if ((float) ($row['mean_ndvi'] ?? 0) < 0.22) {
                $lowNdviCount++;
            }
            if ((float) ($row['max_lst'] ?? 0) > 35.0) {
                $highLstCount++;
            }
        }

        $weakSites = array_slice($kbaAudit, 0, min(3, count($kbaAudit)));
        $weakSiteNames = array_map(static function (array $site): string {
            return (string) ($site['name'] ?? 'Unknown Site');
        }, $weakSites);
        $worst = $kbaAudit[0] ?? [];
        $worstScore = (float) ($worst['effectiveness_score'] ?? 0.0);
        $worstName = (string) ($worst['name'] ?? 'lowest-ranked site');

        $message = sprintf(
            'Reports tab audit indicates %d Critical and %d At Risk sites. Lowest score is %.1f at %s. A first intervention queue may focus on %s, with shielded low-CCT lighting considered where VIIRS > 40 nW and canopy/cooling interventions suggested where NDVI < 0.22 or LST > 35°C (current counts: light=%d, NDVI=%d, LST=%d).',
            $criticalCount,
            $atRiskCount,
            $worstScore,
            $worstName,
            implode(', ', $weakSiteNames),
            $highLightCount,
            $lowNdviCount,
            $highLstCount
        );

        $addRecommendation([
            'title' => 'Reports Tab: Site-Level Decision Path',
            'class' => $criticalCount > 0 ? 'critical' : '',
            'message' => $message . ' These sites may serve as the first decision-support queue for review and scheduling.',
            'source' => 'Reports tab + KBA/PA audit table',
            'source_key' => 'reports',
            'evidence' => [
                'Weakest sites: ' . implode(', ', $weakSiteNames),
                'Critical sites: ' . $criticalCount,
                'At Risk or worse: ' . $atRiskCount,
                'High VIIRS > 40 nW: ' . $highLightCount,
                'Low NDVI < 0.22: ' . $lowNdviCount,
                'High LST > 35°C: ' . $highLstCount,
            ],
        ]);

        if ($highLightCount > 0 || $criticalCount > 0) {
            $addRecommendation([
                'title' => 'Night-Light Hotspot Review',
                'class' => 'critical',
                'message' => sprintf(
                    'A targeted night-light review may be appropriate for %d KBA/PA sites with VIIRS above 40 nW. Shielding, dimming, or fixture replacement may be considered, with follow-up review in the next monthly cycle.',
                    max($highLightCount, $criticalCount)
                ),
                'source' => 'Reports tab + KBA/PA audit table',
                'source_key' => 'reports',
                'evidence' => [
                    'High VIIRS sites: ' . $highLightCount,
                    'Critical sites: ' . $criticalCount,
                    'Threshold used: VIIRS > 40 nW',
                ],
            ]);
        }

        if ($lowNdviCount > 0 || $highLstCount > 0) {
            $addRecommendation([
                'title' => 'Habitat Recovery and Heat-Buffer Opportunity',
                'class' => '',
                'message' => 'Canopy planting, understory recovery, and heat-buffer surfaces may be suitable in sites with low NDVI or elevated LST. These actions may help improve habitat quality and reduce thermal stress in urban protected areas.',
                'source' => 'Reports tab + KBA/PA audit table',
                'source_key' => 'reports',
                'evidence' => [
                    'Low NDVI sites: ' . $lowNdviCount,
                    'High LST sites: ' . $highLstCount,
                    'NDVI threshold used: < 0.22',
                    'LST threshold used: > 35°C',
                ],
            ]);
        }
    }

    if (!empty($trendContext)) {
        $ys = (int) ($trendContext['year_start'] ?? 0);
        $ye = (int) ($trendContext['year_end'] ?? 0);
        $rs = (float) ($trendContext['richness_start'] ?? 0);
        $re = (float) ($trendContext['richness_end'] ?? 0);
        $vs = (float) ($trendContext['viirs_start'] ?? 0);
        $ve = (float) ($trendContext['viirs_end'] ?? 0);
        $ns = (float) ($trendContext['ndvi_start'] ?? 0);
        $ne = (float) ($trendContext['ndvi_end'] ?? 0);

        $richDelta = $re - $rs;
        $viirsDelta = $ve - $vs;
        $ndviDelta = $ne - $ns;

        $trendClass = 'info';
        if ($richDelta < 0 && $viirsDelta > 0) {
            $trendClass = 'critical';
        }

        $addRecommendation([
            'title' => 'Dashboard Tab: Multi-Year Ecological Trend Action',
            'class' => $trendClass,
            'message' => sprintf(
                'Dashboard trend window (%d-%d) shows richness %.1f → %.1f (%+.1f), VIIRS %.1f → %.1f (%+.1f), and NDVI %.3f → %.3f (%+.3f). Quarterly patrols, restoration funding, and compliance review may be aligned to districts where richness is flattening while light is rising.',
                $ys,
                $ye,
                $rs,
                $re,
                $richDelta,
                $vs,
                $ve,
                $viirsDelta,
                $ns,
                $ne,
                $ndviDelta
            ),
            'source' => 'Dashboard tab trend summary',
            'source_key' => 'dashboard',
            'evidence' => [
                'Window: ' . $ys . '–' . $ye,
                'Richness change: ' . number_format($richDelta, 1),
                'VIIRS change: ' . number_format($viirsDelta, 1),
                'NDVI change: ' . number_format($ndviDelta, 3),
            ],
        ]);

        if ($richDelta < 0 || $viirsDelta > 0) {
            $addRecommendation([
                'title' => 'Seasonal Patrol and Monitoring Calendar',
                'class' => $trendClass === 'critical' ? 'critical' : 'info',
                'message' => 'The dashboard trend direction may help guide seasonal field patrols and remote sensing reviews. If richness is falling and VIIRS is increasing, inspection frequency may be tightened and outcomes checked after each quarter.',
                'source' => 'Dashboard tab trend summary',
                'source_key' => 'dashboard',
                'evidence' => [
                    'Trend richness delta: ' . number_format($richDelta, 1),
                    'Trend VIIRS delta: ' . number_format($viirsDelta, 1),
                    'Trend NDVI delta: ' . number_format($ndviDelta, 3),
                ],
            ]);
        }
    }

    if (!empty($diagnosticsContext)) {
        $r2 = (float) ($diagnosticsContext['r2'] ?? 0);
        $rmse = (float) ($diagnosticsContext['rmse'] ?? 0);
        $mae = (float) ($diagnosticsContext['mae'] ?? 0);

        $diagClass = 'info';
        if ($r2 < 0.65 || $rmse > 9) {
            $diagClass = 'critical';
        } elseif ($r2 < 0.80) {
            $diagClass = '';
        }

        $addRecommendation([
            'title' => 'Analytics/Diagnostics: Model Reliability Guardrails',
            'class' => $diagClass,
            'message' => sprintf(
                'Model diagnostics currently report R²=%.4f, RMSE=%.4f, MAE=%.4f. Model outputs may be treated as triage support, with field validation suggested before high-cost interventions; retraining or recalibration may be considered when R² drops below 0.65 or RMSE rises above 9.',
                $r2,
                $rmse,
                $mae
            ),
            'source' => 'Analytics tab diagnostics',
            'source_key' => 'analytics',
            'evidence' => [
                'R²: ' . number_format($r2, 4),
                'RMSE: ' . number_format($rmse, 4),
                'MAE: ' . number_format($mae, 4),
            ],
        ]);
    }

    if (!empty($featureContext)) {
        $topLabel = (string) ($featureContext['top_label'] ?? 'Unknown Feature');
        $topValue = (float) ($featureContext['top_value'] ?? 0);

        $addRecommendation([
            'title' => 'Analytics Tab: Driver-Focused Monitoring',
            'class' => 'info',
            'message' => sprintf(
                'Feature-importance analysis identifies %s as the strongest driver (%.1f%% contribution). Monitoring resources may be allocated to this covariate in hotspot sites, and trigger thresholds in Settings may be updated when monthly drift exceeds baseline.',
                $topLabel,
                $topValue * 100.0
            ),
            'source' => 'Analytics tab feature importance',
            'source_key' => 'analytics',
            'evidence' => [
                'Top feature: ' . $topLabel,
                'Contribution: ' . number_format($topValue * 100.0, 1) . '%',
            ],
        ]);
    }

    if (empty($recommendations)) {
        $addRecommendation([
            'title' => 'Data Availability Check',
            'class' => 'info',
            'message' => 'Recommendation engine is active, but insufficient source data was detected from reports, dashboard, or analytics diagnostics. Refreshing report caches and rerunning the KBA/PA audit rebuild may help generate stronger decision-support actions.',
            'source' => 'Recommendation engine fallback',
            'source_key' => 'reports',
            'evidence' => [
                'KBA audit rows missing',
                'Trend context unavailable',
                'Diagnostics context unavailable',
                'Feature-importance context unavailable',
            ],
        ]);
    }

    return $recommendations;
}

// Load KBA/PA audit data from MySQL audit table.
// Rows are sorted ascending by effectiveness_score so the weakest sites appear first (highest priority).
require_once __DIR__ . '/includes/db.php';
$kba_audit_data = [];
$trend_context = [];
$diagnostics_context = [];
$feature_context = [];
try {
    $mysql_reports = get_mysql_db();
    $yearRangeRow = $mysql_reports->query(
        'SELECT MIN(year) AS min_year, MAX(year) AS max_year FROM ecological_yearly_summary'
    )->fetch(PDO::FETCH_ASSOC) ?: [];
    $year_min = (int) ($yearRangeRow['min_year'] ?? $year_min);
    $year_max = (int) ($yearRangeRow['max_year'] ?? $year_max);

    $tableExistsStmt = $mysql_reports->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'kba_pa_audit_live'");
    $tableExistsStmt->execute();
    $tableExists = (int) $tableExistsStmt->fetchColumn() > 0;

    if ($tableExists) {
        $kbaRowsStmt = $mysql_reports->query("SELECT
                area_name AS name,
                area_type AS type,
                source_layer,
                light_exposure,
                mean_ndvi,
                max_lst,
                precipitation_total,
                species_count,
                sensitive_species_count,
                sensitive_species_percent,
                effectiveness_score,
                status,
                grid_cell_count,
                snapshot_year,
                snapshot_month,
                updated_at
            FROM kba_pa_audit_live
            ORDER BY effectiveness_score ASC, area_name ASC");
        $kba_audit_data = $kbaRowsStmt ? ($kbaRowsStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }

    if (empty($kba_audit_data)) {
        $kbaPath = __DIR__ . '/data/sample_kba.json';
        if (is_readable($kbaPath)) {
            $decoded = json_decode((string) file_get_contents($kbaPath), true);
            if (is_array($decoded)) {
                $kba_audit_data = array_values(array_filter($decoded, static function ($row) {
                    return is_array($row) && in_array((string) ($row['type'] ?? ''), ['KBA', 'PA'], true);
                }));
                usort($kba_audit_data, static function (array $left, array $right): int {
                    return ((float) ($left['effectiveness_score'] ?? 0)) <=> ((float) ($right['effectiveness_score'] ?? 0));
                });
            }
        }
    }

    $trendRowsStmt = $mysql_reports->query("SELECT year, bird_richness, viirs_avg, ndvi_avg, lst_avg, precipitation_total
        FROM ecological_yearly_summary
        WHERE area = 'All Areas'
        ORDER BY year ASC");
    $trendRows = $trendRowsStmt ? ($trendRowsStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

    if (empty($trendRows)) {
        $bestArea = (string) ($mysql_reports->query("SELECT area FROM ecological_yearly_summary GROUP BY area ORDER BY COUNT(*) DESC, area ASC LIMIT 1")->fetchColumn() ?: '');
        if ($bestArea !== '') {
            $fallbackTrendStmt = $mysql_reports->prepare("SELECT year, bird_richness, viirs_avg, ndvi_avg, lst_avg, precipitation_total
                FROM ecological_yearly_summary
                WHERE area = :area
                ORDER BY year ASC");
            $fallbackTrendStmt->execute([':area' => $bestArea]);
            $trendRows = $fallbackTrendStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
    }

    if (!empty($trendRows)) {
        $firstTrend = $trendRows[0];
        $lastTrend = $trendRows[count($trendRows) - 1];
        $trend_context = [
            'year_start' => (int) ($firstTrend['year'] ?? 0),
            'year_end' => (int) ($lastTrend['year'] ?? 0),
            'richness_start' => (float) ($firstTrend['bird_richness'] ?? 0),
            'richness_end' => (float) ($lastTrend['bird_richness'] ?? 0),
            'viirs_start' => (float) ($firstTrend['viirs_avg'] ?? 0),
            'viirs_end' => (float) ($lastTrend['viirs_avg'] ?? 0),
            'ndvi_start' => (float) ($firstTrend['ndvi_avg'] ?? 0),
            'ndvi_end' => (float) ($lastTrend['ndvi_avg'] ?? 0),
        ];
    }

    $modelMetrics = loadJsonAssocFile(__DIR__ . '/api_models/model_metrics.json');
    $diagAverage = $modelMetrics['ensembleMetrics']['ensemble_average'] ?? [];
    if (is_array($diagAverage) && isset($diagAverage['r2'], $diagAverage['rmse'], $diagAverage['mae'])) {
        $diagnostics_context = [
            'r2' => (float) $diagAverage['r2'],
            'rmse' => (float) $diagAverage['rmse'],
            'mae' => (float) $diagAverage['mae'],
        ];
    }

    $diagPrecomputed = loadJsonAssocFile(__DIR__ . '/api_models/diagnostics_precomputed.json');
    $allFeatures = $diagPrecomputed['xgboostFeatureImportance']['all'] ?? [];
    $labels = is_array($allFeatures['labels'] ?? null) ? $allFeatures['labels'] : [];
    $values = is_array($allFeatures['values'] ?? null) ? $allFeatures['values'] : [];
    if (!empty($labels) && !empty($values)) {
        $topIndex = null;
        $topValue = -1.0;
        foreach ($values as $idx => $value) {
            $numeric = (float) $value;
            if ($numeric > $topValue) {
                $topValue = $numeric;
                $topIndex = $idx;
            }
        }
        if ($topIndex !== null && isset($labels[$topIndex])) {
            $feature_context = [
                'top_label' => (string) $labels[$topIndex],
                'top_value' => (float) $topValue,
            ];
        }
    }
} catch (Throwable $e) {
    $kba_audit_data = [];
}

$kba_recommendations = buildRuleBasedRecommendations(
    $kba_audit_data,
    $trend_context,
    $diagnostics_context,
    $feature_context
);

if (!in_array($selected_area, array_merge(['All Areas'], $available_areas), true)) {
    $selected_area = 'All Areas';
}

$start_year = max($year_min, min($year_max, $start_year));
$end_year = max($year_min, min($year_max, $end_year));
if ($start_year > $end_year) {
    $end_year = $start_year;
}
$snapshot_year = max($year_min, min($year_max, $snapshot_year));
$snapshot_month = max(1, min(12, $snapshot_month));

$extra_head = <<<'HTML'
<style>
.reports-header-row {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 16px;
    margin-bottom: 20px;
}

.reports-export-bar {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    align-items: center;
}

.reports-shell {
    display: grid;
    gap: 18px;
}

.reports-tabs {
    display: flex;
    width: 100%;
    gap: 8px;
    background: var(--bg-card-alt);
    border: 1px solid var(--border-color);
    border-radius: 10px;
    padding: 6px;
    margin-bottom: 18px;
}

.reports-tab-btn {
    flex: 1 1 0;
    border: 1px solid transparent;
    background: transparent;
    color: var(--text-secondary);
    font-weight: 600;
    font-size: 1rem;
    justify-content: center;
}

.reports-tab-btn:hover {
    background: var(--bg-input);
    color: var(--text-primary);
}

.reports-tab-btn.is-active {
    background: var(--bg-card);
    border-color: var(--border-color);
    color: var(--accent-blue);
    box-shadow: var(--shadow);
}

.reports-tab-panel {
    display: none;
    min-width: 0;
}

.reports-tab-panel.is-active {
    display: block;
}

.global-filter-card,
.section-card,
.diagnostic-card {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    box-shadow: var(--shadow);
}

.global-filter-card {
    padding: 16px;
    margin-bottom: 18px;
}

.section-card,
.diagnostic-card {
    padding: 16px;
    min-width: 0;
}

.section-card {
    background: var(--bg-card-alt);
}

.section-divider {
    height: 1px;
    border: 0;
    background: var(--border-color);
    margin: 12px 0 16px;
}

.section-subtitle {
    color: var(--text-secondary);
    font-size: 0.92rem;
    margin-bottom: 12px;
}

.chart-guidance {
    margin-bottom: 10px;
    padding: 10px 12px;
    border: 1px solid var(--border-color);
    border-radius: 8px;
    background: var(--bg-card);
    color: var(--text-secondary);
    font-size: 0.86rem;
    line-height: 1.45;
}

.chart-guidance strong {
    color: var(--text-primary);
}
.chart-filter-note {
    margin-bottom: 10px;
    padding: 7px 10px;
    border: 1px dashed var(--border-color);
    border-radius: 8px;
    background: var(--bg-card);
    color: var(--text-secondary);
    font-size: 0.82rem;
    line-height: 1.4;
}

.trend-guidance-equal {
    min-height: 152px;
}

.snapshot-doughnut-layout {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 2fr);
    gap: 14px;
    align-items: stretch;
}

.snapshot-doughnut-layout .chart-guidance {
    margin-bottom: 0;
}

.snapshot-doughnut-wrap {
    min-height: 240px;
}

.filter-grid {
    display: grid;
    gap: 14px;
}

.filter-grid.global {
    grid-template-columns: 1fr;
    width: 100%;
}

.filter-grid.global .form-group {
    width: 100%;
    max-width: none;
}

.filter-grid.global .report-select {
    width: 100%;
    max-width: none;
}

.filter-grid.snapshot,
.filter-grid.trend {
    grid-template-columns: repeat(2, minmax(180px, 1fr));
}

.filter-actions {
    display: flex;
    justify-content: flex-end;
    margin-top: 12px;
}

.report-select {
    width: 100%;
    background: var(--bg-input);
    color: var(--text-primary);
    border: 1px solid var(--border-color);
    border-radius: 8px;
    padding: 10px 12px;
    font-size: 0.95rem;
}

.report-panel-stack {
    display: grid;
    gap: 22px;
    min-width: 0;
}

.chart-wrap {
    min-height: 300px;
    position: relative;
}

.chart-empty-note {
    position: absolute;
    inset: 14px;
    display: none;
    align-items: center;
    justify-content: center;
    text-align: center;
    padding: 10px 12px;
    border: 1px dashed var(--border-color);
    border-radius: 8px;
    background: color-mix(in srgb, var(--bg-card) 88%, transparent);
    color: var(--text-secondary);
    font-size: 0.88rem;
    line-height: 1.4;
    z-index: 1;
    pointer-events: none;
}

.chart-wrap.tall {
    min-height: 360px;
}

.chart-loading-overlay {
    position: absolute;
    inset: 0;
    display: none;
    align-items: center;
    justify-content: center;
    gap: 10px;
    background: rgba(0, 0, 0, 0.18);
    color: #fff;
    font-weight: 600;
    border-radius: 8px;
    z-index: 2;
}

[data-theme="dark"] .chart-loading-overlay {
    background: rgba(0, 0, 0, 0.45);
}

.chart-wrap.is-loading {
    opacity: 0.55;
    transition: opacity 0.2s ease;
}

.chart-wrap.is-loading .chart-loading-overlay {
    display: flex;
}

.loading-spinner {
    width: 16px;
    height: 16px;
    border: 2px solid rgba(255, 255, 255, 0.45);
    border-top-color: #fff;
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

.diagnostics-chart-wrap {
    min-height: 360px;
    position: relative;
}

.is-loading-overlay {
    position: absolute;
    inset: 0;
    display: none;
    align-items: center;
    justify-content: center;
    gap: 10px;
    background: rgba(0, 0, 0, 0.18);
    color: #fff;
    font-weight: 600;
    border-radius: 8px;
    z-index: 2;
}

[data-theme="dark"] .is-loading-overlay {
    background: rgba(0, 0, 0, 0.45);
}

.diagnostics-chart-wrap.is-loading {
    opacity: 0.55;
    transition: opacity 0.2s ease;
}

.diagnostics-chart-wrap.is-loading .is-loading-overlay {
    display: flex;
}

.section-grid-2 {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 20px;
}

.trends-grid {
    grid-template-columns: minmax(0, 2fr) minmax(0, 1fr);
}

.trend-card-correlation {
    order: 2;
}

.trend-card-historical {
    order: 1;
}

.section-grid-3 {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 20px;
}

.section-grid-4 {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 20px;
}

.kpi-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(180px, 1fr));
    gap: 20px;
}

.kpi-card {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-left: 4px solid var(--accent-blue);
    border-radius: 10px;
    box-shadow: var(--shadow);
    padding: 18px;
}

.kpi-label {
    color: var(--text-secondary);
    font-size: 0.85rem;
    margin-bottom: 8px;
    text-transform: uppercase;
    letter-spacing: 0.4px;
}

.kpi-value {
    color: var(--text-primary);
    font-size: 2rem;
    font-weight: 700;
    line-height: 1.1;
}

.kpi-meta {
    color: var(--text-muted);
    font-size: 0.82rem;
    margin-top: 8px;
}

.recommendation-stack {
    display: grid;
    gap: 12px;
    margin-bottom: 18px;
}

.report-alert {
    border: 1px solid var(--border-color);
    border-left: 4px solid var(--accent-yellow);
    background: var(--bg-card-alt);
    border-radius: 10px;
    padding: 14px 16px 12px;
}

.report-alert strong {
    display: block;
    margin-bottom: 8px;
    color: var(--text-primary);
    font-size: 0.98rem;
    line-height: 1.35;
}

.report-alert p {
    margin: 0;
    color: var(--text-secondary);
    font-size: 0.92rem;
    line-height: 1.5;
}

.recommendation-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin: 0 0 10px;
}

.recommendation-chip {
    display: inline-flex;
    align-items: center;
    border: 1px solid var(--border-color);
    border-radius: 999px;
    padding: 3px 8px;
    font-size: 0.76rem;
    font-weight: 600;
    color: var(--text-secondary);
    background: color-mix(in srgb, var(--bg-card) 84%, transparent);
}

.recommendation-chip.source-reports {
    color: var(--accent-blue);
    border-color: color-mix(in srgb, var(--accent-blue) 38%, var(--border-color));
    background: color-mix(in srgb, var(--accent-blue) 12%, var(--bg-card));
}

.recommendation-chip.source-dashboard {
    color: #22c55e;
    border-color: color-mix(in srgb, #22c55e 38%, var(--border-color));
    background: color-mix(in srgb, #22c55e 12%, var(--bg-card));
}

.recommendation-chip.source-analytics {
    color: var(--accent-yellow);
    border-color: color-mix(in srgb, var(--accent-yellow) 42%, var(--border-color));
    background: color-mix(in srgb, var(--accent-yellow) 12%, var(--bg-card));
}

.recommendation-body {
    margin-bottom: 10px;
}

.recommendation-footer {
    margin-top: 10px;
    padding-top: 8px;
    border-top: 1px solid var(--border-color);
    color: var(--text-muted);
    font-size: 0.8rem;
    line-height: 1.45;
}

.recommendation-footer strong {
    display: inline;
    margin: 0;
    color: var(--text-secondary);
    font-size: 0.8rem;
}

.recommendation-evidence-list {
    margin: 4px 0 0;
    padding-left: 18px;
}

.recommendation-evidence-list li {
    margin: 2px 0;
}

.report-alert.critical {
    border-left-color: var(--accent-red);
}

.report-alert.info {
    border-left-color: var(--accent-blue);
}

.report-alert.source-reports {
    border-left-color: var(--accent-blue);
}

.report-alert.source-dashboard {
    border-left-color: #22c55e;
}

.report-alert.source-analytics {
    border-left-color: var(--accent-yellow);
}

.table-wrap {
    overflow-x: auto;
    max-width: 100%;
}

.kba-audit-table-wrap {
    max-width: 100%;
    overflow-x: auto;
    overflow-y: visible;
    -webkit-overflow-scrolling: touch;
}

.kba-audit-table {
    width: max-content;
    min-width: 1850px;
}

.pillar-tooltip {
    display: inline-block;
    position: relative;
    margin-left: 6px;
    vertical-align: middle;
}

.pillar-tooltip-btn {
    cursor: help;
    border: 0;
    line-height: 1;
}

.pillar-tooltip-btn:focus-visible {
    outline: 2px solid var(--accent-blue);
    outline-offset: 2px;
}

.pillar-tooltip-panel {
    position: absolute;
    right: 0;
    top: calc(100% + 8px);
    width: 290px;
    max-width: calc(100vw - 24px);
    max-height: min(62vh, 420px);
    overflow: auto;
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 10px;
    box-shadow: var(--shadow);
    padding: 10px;
    z-index: 30;
    display: none;
}

.pillar-tooltip:hover .pillar-tooltip-panel,
.pillar-tooltip:focus-within .pillar-tooltip-panel {
    display: block;
}

.pillar-tooltip.is-flipped .pillar-tooltip-panel {
    top: auto;
    bottom: calc(100% + 8px);
}

.pillar-tooltip-title {
    margin: 0 0 8px;
    color: var(--text-primary);
    font-size: 0.82rem;
    font-weight: 700;
}

.pillar-tooltip-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.78rem;
}

.pillar-tooltip-table th,
.pillar-tooltip-table td {
    padding: 4px 2px;
    border-bottom: 1px solid var(--border-color);
    text-align: left;
    color: var(--text-secondary);
}

.pillar-tooltip-table th {
    color: var(--text-primary);
    font-weight: 600;
}

.pillar-tooltip-table td:last-child,
.pillar-tooltip-table th:last-child {
    text-align: right;
}

.pillar-tooltip-table tr:last-child td {
    border-bottom: 0;
    color: var(--text-primary);
    font-weight: 700;
}

.diagnostic-filter-btn {
    padding: 6px 12px;
    font-size: 0.85rem;
    border: 1px solid var(--border-color);
    background: var(--bg-input);
    color: var(--text-secondary);
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.2s ease;
}

.diagnostic-filter-btn:hover {
    background: var(--bg-card);
    color: var(--text-primary);
}

.diagnostic-filter-btn.is-active {
    background: var(--accent-blue);
    color: #fff;
    border-color: var(--accent-blue);
    font-weight: 600;
}

.export-loading-overlay {
    position: fixed;
    inset: 0;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 14px;
    background: color-mix(in srgb, var(--bg-body) 42%, #000 58%);
    backdrop-filter: blur(2px);
    z-index: 1200;
}

.export-loading-overlay.is-active {
    display: flex;
}

.export-loading-card {
    width: min(500px, calc(100vw - 28px));
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-top: 3px solid var(--accent-blue);
    border-radius: 12px;
    box-shadow: var(--shadow);
    padding: 18px;
}

.export-loading-title {
    margin: 0;
    color: var(--text-primary);
    font-size: 1.02rem;
    font-weight: 700;
}

.export-loading-text {
    margin: 0 0 12px;
    color: var(--text-secondary);
    font-size: 0.92rem;
    line-height: 1.45;
}

.export-loading-row {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 8px;
}

.export-loading-meta {
    margin: 0 0 14px;
    color: var(--text-muted);
    font-size: 0.82rem;
}

.export-loading-actions {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
}

.export-loading-cancel {
    min-width: 112px;
}

@media (max-width: 980px) {
    .reports-header-row {
        flex-direction: column;
        align-items: stretch;
    }

    .reports-export-bar {
        justify-content: flex-end;
    }

    .filter-grid.global,
    .filter-grid.snapshot,
    .filter-grid.trend,
    .kpi-grid,
    .section-grid-2,
    .section-grid-3,
    .section-grid-4 {
        grid-template-columns: 1fr;
    }

    .snapshot-doughnut-layout {
        grid-template-columns: 1fr;
    }

    .chart-wrap,
    .chart-wrap.tall {
        min-height: 260px;
    }
}
</style>
HTML;

require_once 'includes/header.php';
?>

<div class="reports-header-row">
    <div class="page-header" style="margin: 0;">
        <h1 class="page-title">Statistical Reports Dashboard</h1>
        <p class="page-subtitle">Dual-view reporting for ecological impact and machine learning diagnostics</p>
    </div>
    <div class="reports-export-bar">
        <button class="btn btn-danger" type="button" onclick="exportPDF()">Export PDF</button>
        <button class="btn btn-secondary" type="button" onclick="exportCSV()">Export CSV</button>
    </div>
</div>

<div id="exportLoadingOverlay" class="export-loading-overlay" aria-hidden="true">
    <div class="export-loading-card" role="dialog" aria-modal="true" aria-labelledby="exportLoadingTitle" aria-describedby="exportLoadingText">
        <div class="export-loading-row">
            <span class="loading-spinner" aria-hidden="true"></span>
            <h3 id="exportLoadingTitle" class="export-loading-title">Generating PDF Report</h3>
        </div>
        <p id="exportLoadingText" class="export-loading-text">Please wait while the PDF is being generated. This can take a few moments for large reports.</p>
        <p id="exportLoadingMeta" class="export-loading-meta">You can cancel now if you want to adjust filters first.</p>
        <div class="export-loading-actions">
            <button id="cancelPdfExportBtn" class="btn btn-secondary export-loading-cancel" type="button">Cancel</button>
        </div>
    </div>
</div>

<div class="reports-shell">
    <div class="card">
        <div class="reports-tabs" role="tablist" aria-label="Reports view toggle">
            <button class="btn btn-sm reports-tab-btn is-active" type="button" role="tab" aria-selected="true" aria-controls="tab-ecological" id="tab-btn-ecological" data-target="tab-ecological">Ecological Impact</button>
            <button class="btn btn-sm reports-tab-btn" type="button" role="tab" aria-selected="false" aria-controls="tab-diagnostics" id="tab-btn-diagnostics" data-target="tab-diagnostics">Model Diagnostics</button>
        </div>

        <section class="reports-tab-panel is-active" id="tab-ecological" role="tabpanel" aria-labelledby="tab-btn-ecological">
            <div class="global-filter-card">
                <div class="filter-grid global">
                    <div class="form-group" style="margin: 0;">
                        <label class="form-label" for="globalAreaFilter">Select Area</label>
                        <select id="globalAreaFilter" class="report-select">
                            <option value="All Areas" <?php echo $selected_area === 'All Areas' ? 'selected' : ''; ?>>All Areas</option>
                            <?php foreach ($available_areas as $area_name): ?>
                                <option value="<?php echo htmlspecialchars((string) $area_name); ?>" <?php echo $selected_area === (string) $area_name ? 'selected' : ''; ?>><?php echo htmlspecialchars((string) $area_name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <div class="report-panel-stack">
                <section class="section-card">
                    <h2 class="card-header">Long-Term Ecological Impact Trends</h2>
                    <div class="section-subtitle">Start/End Year filters update this chart, and legend items are clickable for focused comparisons.</div>
                    <hr class="section-divider">

                    <div class="global-filter-card" style="margin-bottom: 16px; background: var(--bg-card-alt);">
                        <div class="filter-grid trend">
                            <div class="form-group" style="margin: 0;">
                                <label class="form-label" for="trendStartYear">Start Year</label>
                                <select id="trendStartYear" class="report-select">
                                    <?php for ($year = $year_min; $year <= $year_max; $year++): ?>
                                        <option value="<?php echo $year; ?>" <?php echo $year === $start_year ? 'selected' : ''; ?>><?php echo $year; ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="form-group" style="margin: 0;">
                                <label class="form-label" for="trendEndYear">End Year</label>
                                <select id="trendEndYear" class="report-select">
                                    <?php for ($year = $year_min; $year <= $year_max; $year++): ?>
                                        <option value="<?php echo $year; ?>" <?php echo $year === $end_year ? 'selected' : ''; ?>><?php echo $year; ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                        </div>
                        <div class="filter-actions">
                            <button id="applyTrendFiltersBtn" class="btn btn-primary" type="button">Apply Filters</button>
                        </div>
                    </div>

                    <div class="section-grid-2 trends-grid">
                        <div class="card trend-card-historical">
                            <h2 class="card-header">Historical Trends</h2>
                            <div class="card-body">
                                <div class="chart-guidance trend-guidance-equal">
                                    <div style="margin-top:8px; display:grid; gap:6px;">
                                        <div><strong>Click legend items to isolate specific comparisons.</strong></div>
                                        <div><strong>- Bird Richness (count):</strong> The total count of unique species observed.</div>
                                        <div><strong>- ALAN (nW/cm²/sr):</strong> The average nighttime artificial light pollution.</div>
                                        <div><strong>- NDVI (0-1):</strong> The average healthy green canopy index.</div>
                                        <div><strong>- LST (°C):</strong> The absolute peak (maximum) daytime land surface temperature.</div>
                                        <div><strong>- Precipitation:</strong> The average daily rainfall rate.</div>
                                    </div>
                                </div>
                                <div class="chart-filter-note" id="historicalFilterNote"></div>
                                <div class="chart-wrap trend-chart-wrap">
                                    <div class="chart-empty-note" id="historicalEmptyNote">No existing data for the filters applied.</div>
                                    <div class="chart-loading-overlay"><span class="loading-spinner"></span><span>Loading...</span></div>
                                    <canvas id="historicalTrendsChart"></canvas>
                                </div>
                            </div>
                        </div>
                        <div class="card trend-card-correlation">
                            <h2 class="card-header">Correlation Matrix</h2>
                            <div class="card-body">
                                <div class="chart-guidance trend-guidance-equal">
                                    <div style="margin-top:8px; margin-bottom: 55px; display:grid; gap:6px;">
                                        <div><strong>Hover over the matrix to get specific correlation coefficients.</strong></div>
                                        <div><strong>- Values near +1:</strong> Strong positive linear relationship.</div>
                                        <div><strong>- Values near -1:</strong> Strong negative linear relationship.</div>
                                        <div><strong>- Values near 0:</strong> Little to no linear relationship.</div>
                                    </div>
                                </div>
                                <div class="chart-filter-note" id="correlationFilterNote"></div>
                                <div class="chart-wrap trend-chart-wrap">
                                    <div class="chart-empty-note" id="correlationEmptyNote">No existing data for the filters applied.</div>
                                    <div class="chart-loading-overlay"><span class="loading-spinner"></span><span>Loading...</span></div>
                                    <canvas id="correlationMatrixChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="section-card">
                    <h2 class="card-header">Immediate Site Assessment & Vulnerability</h2>
                    <div class="section-subtitle">Year and Month control only the charts in this section.</div>
                    <hr class="section-divider">

                    <div class="global-filter-card" style="margin-bottom: 16px; background: var(--bg-card-alt);">
                        <div class="filter-grid snapshot">
                            <div class="form-group" style="margin: 0;">
                                <label class="form-label" for="snapshotYear">Year</label>
                                <select id="snapshotYear" class="report-select">
                                    <?php for ($year = $year_min; $year <= $year_max; $year++): ?>
                                        <option value="<?php echo $year; ?>" <?php echo $year === $snapshot_year ? 'selected' : ''; ?>><?php echo $year; ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="form-group" style="margin: 0;">
                                <label class="form-label" for="snapshotMonth">Month</label>
                                <select id="snapshotMonth" class="report-select">
                                    <option value="1" <?php echo $snapshot_month === 1 ? 'selected' : ''; ?>>January</option>
                                    <option value="2" <?php echo $snapshot_month === 2 ? 'selected' : ''; ?>>February</option>
                                    <option value="3" <?php echo $snapshot_month === 3 ? 'selected' : ''; ?>>March</option>
                                    <option value="4" <?php echo $snapshot_month === 4 ? 'selected' : ''; ?>>April</option>
                                    <option value="5" <?php echo $snapshot_month === 5 ? 'selected' : ''; ?>>May</option>
                                    <option value="6" <?php echo $snapshot_month === 6 ? 'selected' : ''; ?>>June</option>
                                    <option value="7" <?php echo $snapshot_month === 7 ? 'selected' : ''; ?>>July</option>
                                    <option value="8" <?php echo $snapshot_month === 8 ? 'selected' : ''; ?>>August</option>
                                    <option value="9" <?php echo $snapshot_month === 9 ? 'selected' : ''; ?>>September</option>
                                    <option value="10" <?php echo $snapshot_month === 10 ? 'selected' : ''; ?>>October</option>
                                    <option value="11" <?php echo $snapshot_month === 11 ? 'selected' : ''; ?>>November</option>
                                    <option value="12" <?php echo $snapshot_month === 12 ? 'selected' : ''; ?>>December</option>
                                </select>
                            </div>
                        </div>
                        <div class="filter-actions">
                            <button id="applySnapshotFiltersBtn" class="btn btn-primary" type="button">Apply Filters</button>
                        </div>
                    </div>

                    <div class="section-grid-2">
                        <div class="card">
                            <h2 class="card-header">Migratory Status Distribution</h2>
                            <div class="card-body">
                                <div class="snapshot-doughnut-layout">
                                    <div>
                                        <div class="chart-guidance">
                                            <div style="display:grid; margin-top:8px; margin-bottom: 55px; gap:6px;">
                                                <div><strong>Hover over the segments to view details of migration status. Click legend items to isolate specific comparisons.</strong></div>
                                                <div><strong>- Migratory:</strong> Species classified as migratory.</div>
                                                <div><strong>- Resident:</strong> Species classified as resident.</div>
                                                <div><strong>- Unclassified:</strong> Species with missing or non-standard status labels.</div>
                                            </div>
                                        </div>
                                        <div class="chart-filter-note" id="migrationFilterNote"></div>
                                    </div>
                                    <div class="chart-wrap trend-chart-wrap snapshot-chart-wrap snapshot-doughnut-wrap">
                                        <div class="chart-empty-note" id="migrationEmptyNote">No existing data for the filters applied.</div>
                                        <div class="chart-loading-overlay"><span class="loading-spinner"></span><span>Loading...</span></div>
                                        <canvas id="migrationStatusChart"></canvas>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="card">
                            <h2 class="card-header">Light Tolerance Distribution</h2>
                            <div class="card-body">
                                <div class="snapshot-doughnut-layout">
                                    <div>
                                        <div class="chart-guidance">
                                            <div style="display:grid; margin-top:8px; margin-bottom: 55px;gap:6px;">
                                                <div><strong>Hover over the segments to view details of light tolerance. Click legend items to isolate specific comparisons.</strong></div>
                                                <div><strong>- Sensitive:</strong> Species vulnerable to artificial light.</div>
                                                <div><strong>- Tolerant:</strong> Species more adaptive to artificial light.</div>
                                                <div><strong>- Unclassified:</strong> Species with missing or non-standard tolerance labels.</div>
                                            </div>
                                        </div>
                                        <div class="chart-filter-note" id="lightToleranceFilterNote"></div>
                                    </div>
                                    <div class="chart-wrap trend-chart-wrap snapshot-chart-wrap snapshot-doughnut-wrap">
                                        <div class="chart-empty-note" id="lightToleranceEmptyNote">No existing data for the filters applied.</div>
                                        <div class="chart-loading-overlay"><span class="loading-spinner"></span><span>Loading...</span></div>
                                        <canvas id="lightToleranceChart"></canvas>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="section-grid-4">
                        <div class="card">
                            <h2 class="card-header">ALAN vs Richness</h2>
                            <div class="card-body">
                                <div class="chart-guidance">
                                    <strong>Hover over the points to view details of light exposure and species richness.</strong>
                                </div>
                                <div class="chart-filter-note" id="lightRichnessFilterNote"></div>
                                <div class="chart-wrap trend-chart-wrap snapshot-chart-wrap">
                                    <div class="chart-empty-note" id="lightRichnessEmptyNote">No existing data for the filters applied.</div>
                                    <div class="chart-loading-overlay"><span class="loading-spinner"></span><span>Loading...</span></div>
                                    <canvas id="lightRichnessScatterChart"></canvas>
                                </div>
                            </div>
                        </div>
                        <div class="card">
                            <h2 class="card-header">NDVI vs Richness</h2>
                            <div class="card-body">
                                <div class="chart-guidance">
                                    <strong>Hover over the points to view details of NDVI and species richness.</strong>
                                </div>
                                <div class="chart-filter-note" id="ndviRichnessFilterNote"></div>
                                <div class="chart-wrap trend-chart-wrap snapshot-chart-wrap">
                                    <div class="chart-empty-note" id="ndviRichnessEmptyNote">No existing data for the filters applied.</div>
                                    <div class="chart-loading-overlay"><span class="loading-spinner"></span><span>Loading...</span></div>
                                    <canvas id="ndviRichnessScatterChart"></canvas>
                                </div>
                            </div>
                        </div>
                        <div class="card">
                            <h2 class="card-header">LST vs Richness</h2>
                            <div class="card-body">
                                <div class="chart-guidance">
                                    <strong>Hover over the points to view details of LST and species richness.</strong>
                                </div>
                                <div class="chart-filter-note" id="lstRichnessFilterNote"></div>
                                <div class="chart-wrap trend-chart-wrap snapshot-chart-wrap">
                                    <div class="chart-empty-note" id="lstRichnessEmptyNote">No existing data for the filters applied.</div>
                                    <div class="chart-loading-overlay"><span class="loading-spinner"></span><span>Loading...</span></div>
                                    <canvas id="lstRichnessScatterChart"></canvas>
                                </div>
                            </div>
                        </div>
                        <div class="card">
                            <h2 class="card-header">Precipitation vs Richness</h2>
                            <div class="card-body">
                                <div class="chart-guidance">
                                    <strong>Hover over the points to view details of precipitation and species richness.</strong>
                                </div>
                                <div class="chart-filter-note" id="precipitationRichnessFilterNote"></div>
                                <div class="chart-wrap trend-chart-wrap snapshot-chart-wrap">
                                    <div class="chart-empty-note" id="precipitationRichnessEmptyNote">No existing data for the filters applied.</div>
                                    <div class="chart-loading-overlay"><span class="loading-spinner"></span><span>Loading...</span></div>
                                    <canvas id="precipitationRichnessScatterChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>

                </section>

                <section class="section-card">
                    <h2 class="card-header">Conservation Priorities & Recommendations</h2>
                    <div class="section-subtitle">Prioritized sites, management guidance, and KBA/PA audit outputs for targeted conservation actions.</div>
                    <hr class="section-divider">

                    <div class="card">
                        <h2 class="card-header">Top 10 Sites by Richness</h2>
                        <div class="card-body">
                            <div class="chart-guidance">
                                <strong>Hover over the bars to view details of each site.</strong>
                            </div>
                            <div class="chart-filter-note" id="topSitesFilterNote"></div>
                            <div class="chart-wrap trend-chart-wrap snapshot-chart-wrap">
                                <div class="chart-empty-note" id="topSitesEmptyNote">No existing data for the filters applied.</div>
                                <div class="chart-loading-overlay"><span class="loading-spinner"></span><span>Loading...</span></div>
                                <canvas id="topSitesRichnessChart"></canvas>
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <h2 class="card-header">Key Biodiversity Area &amp; Protected Areas Audit Table</h2>
                        <div class="card-body">
                            <div class="chart-guidance" style="margin-bottom:12px;">
                                <strong>Applied Effectiveness Weights:</strong>
                                Richness 15%, Density 15%, Sensitive Ratio 15%, NDVI 15%, ALAN 15%, LST 15%, Precipitation 10%.
                                <br><small>Note: These weights can be adjusted in Settings as calibration needs evolve. Displays: Latest Year</small>
                            </div>

                            <div class="table-wrap kba-audit-table-wrap">
                                <table class="kba-audit-table">
                                    <thead>
                                        <tr>
                                            <th>Rank</th>
                                            <th>Area Name</th>
                                            <th>Type</th>
                                            <th>Grid Cells</th>
                                            <th>Species Count</th>
                                            <th>Density<br><small>(species/cell)</small></th>
                                            <th>Avg VIIRS<br><small>(nW)</small></th>
                                            <th>Mean NDVI</th>
                                            <th>Max LST<br><small>(°C)</small></th>
                                            <th>Total Precip<br><small>(mm)</small></th>
                                            <th>Sensitive Count</th>
                                            <th>Sensitive Ratio</th>
                                            <th>Score Richness</th>
                                            <th>Score Density</th>
                                            <th>Score Sensitive</th>
                                            <th>Score ALAN</th>
                                            <th>Score NDVI</th>
                                            <th>Score LST</th>
                                            <th>Score Precip</th>
                                            <th>Effectiveness Score</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($kba_audit_data)): ?>
                                            <?php foreach ($kba_audit_data as $index => $area): ?>
                                                <?php
                                                $gridCells = max(1, (int) ($area['grid_cell_count'] ?? 0));
                                                $speciesCount = (int) ($area['species_count'] ?? 0);
                                                $sensitiveCount = (int) ($area['sensitive_species_count'] ?? 0);

                                                $lightExposure = (float) ($area['light_exposure'] ?? 0);
                                                $lightClass = $lightExposure > 40 ? 'danger' : ($lightExposure > 30 ? 'warning' : 'success');
                                                $meanNdvi = (float) ($area['mean_ndvi'] ?? 0);
                                                $maxLst = (float) ($area['max_lst'] ?? 0);
                                                $precipTotal = (float) ($area['precipitation_total'] ?? 0);

                                                $density = $speciesCount / $gridCells;
                                                $scoreRichness = min(1.0, $speciesCount / 50.0);
                                                $scoreDensity = min(1.0, $density / 3.0);
                                                $scoreAlan = max(0.0, 1.0 - ($lightExposure / 60.0));
                                                $scoreSensitive = $sensitiveCount / max(1, $speciesCount);
                                                $scoreNdvi = min(1.0, $meanNdvi / 0.5);
                                                $scoreLst = max(0.0, 1.0 - ($maxLst / 45.0));
                                                $scorePrecip = min(1.0, $precipTotal / 300.0);

                                                $contribRichness = $scoreRichness * 0.15 * 100.0;
                                                $contribDensity = $scoreDensity * 0.15 * 100.0;
                                                $contribSensitive = $scoreSensitive * 0.15 * 100.0;
                                                $contribNdvi = $scoreNdvi * 0.15 * 100.0;
                                                $contribAlan = $scoreAlan * 0.15 * 100.0;
                                                $contribLst = $scoreLst * 0.15 * 100.0;
                                                $contribPrecip = $scorePrecip * 0.10 * 100.0;
                                                $contribTotal = $contribRichness + $contribDensity + $contribSensitive + $contribNdvi + $contribAlan + $contribLst + $contribPrecip;

                                                $effectiveness = (float) ($area['effectiveness_score'] ?? 0);
                                                $effectivenessClass = $effectiveness >= 75 ? 'success' : ($effectiveness >= 40 ? 'warning' : 'danger');
                                                $statusText = (string) ($area['status'] ?? 'Unknown');
                                                $statusClass = 'warning';
                                                if ($statusText === 'Good') {
                                                    $statusClass = 'success';
                                                } elseif ($statusText === 'Critical') {
                                                    $statusClass = 'danger';
                                                } elseif ($statusText === 'At Risk') {
                                                    $statusClass = 'warning';
                                                } elseif ($statusText === 'Moderate') {
                                                    $statusClass = 'info';
                                                }
                                                ?>
                                                <tr>
                                                    <td><?php echo $index + 1; ?></td>
                                                    <td><?php echo htmlspecialchars((string) ($area['name'] ?? '')); ?></td>
                                                    <td>
                                                        <span class="badge badge-<?php echo htmlspecialchars((string) (($area['type'] ?? '') === 'KBA' ? 'info' : 'success')); ?>">
                                                            <?php echo htmlspecialchars((string) ($area['type'] ?? '')); ?>
                                                        </span>
                                                    </td>
                                                    <td><?php echo (int) ($area['grid_cell_count'] ?? 0); ?></td>
                                                    <td><?php echo $speciesCount; ?></td>
                                                    <td><?php echo number_format($density, 2); ?></td>
                                                    <td>
                                                        <span class="badge badge-<?php echo $lightClass; ?>"><?php echo number_format($lightExposure, 1); ?></span>
                                                    </td>
                                                    <td><?php echo number_format($meanNdvi, 3); ?></td>
                                                    <td><?php echo number_format($maxLst, 2); ?></td>
                                                    <td><?php echo number_format($precipTotal, 1); ?></td>
                                                    <td><?php echo $sensitiveCount; ?></td>
                                                    <td><?php echo number_format($scoreSensitive, 2); ?></td>
                                                    <td><?php echo number_format($scoreRichness, 2); ?></td>
                                                    <td><?php echo number_format($scoreDensity, 2); ?></td>
                                                    <td><?php echo number_format($scoreSensitive, 2); ?></td>
                                                    <td><?php echo number_format($scoreAlan, 2); ?></td>
                                                    <td><?php echo number_format($scoreNdvi, 2); ?></td>
                                                    <td><?php echo number_format($scoreLst, 2); ?></td>
                                                    <td><?php echo number_format($scorePrecip, 2); ?></td>
                                                    <td>
                                                        <span class="badge badge-<?php echo $effectivenessClass; ?>">
                                                            <?php echo number_format($effectiveness, 1); ?>/100
                                                        </span>
                                                        <span class="pillar-tooltip">
                                                            <button type="button" class="badge badge-info pillar-tooltip-btn" aria-label="Show pillar score breakdown">
                                                                details
                                                            </button>
                                                            <div class="pillar-tooltip-panel" role="tooltip">
                                                                <p class="pillar-tooltip-title">Pillar Score Breakdown (points)</p>
                                                                <table class="pillar-tooltip-table">
                                                                    <tbody>
                                                                        <tr><th>Richness (15%)</th><td><?php echo number_format($contribRichness, 1); ?></td></tr>
                                                                        <tr><th>Density (15%)</th><td><?php echo number_format($contribDensity, 1); ?></td></tr>
                                                                        <tr><th>Sensitive (15%)</th><td><?php echo number_format($contribSensitive, 1); ?></td></tr>
                                                                        <tr><th>NDVI (15%)</th><td><?php echo number_format($contribNdvi, 1); ?></td></tr>
                                                                        <tr><th>ALAN (15%)</th><td><?php echo number_format($contribAlan, 1); ?></td></tr>
                                                                        <tr><th>LST (15%)</th><td><?php echo number_format($contribLst, 1); ?></td></tr>
                                                                        <tr><th>Precip (10%)</th><td><?php echo number_format($contribPrecip, 1); ?></td></tr>
                                                                        <tr><th>Total</th><td><?php echo number_format($contribTotal, 1); ?></td></tr>
                                                                    </tbody>
                                                                </table>
                                                            </div>
                                                        </span>
                                                    </td>
                                                    <td><span class="badge badge-<?php echo $statusClass; ?>"><?php echo htmlspecialchars($statusText); ?></span></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="21" style="text-align:center;color:var(--text-secondary);">KBA/PA audit data is unavailable.</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>

                        </div>
                    </div>

                    <div class="card">
                        <h2 class="card-header">Systems Recommendations</h2>
                        <div class="section-subtitle" style="margin: 10px 16px 0;"><strong>Note:</strong> These are recommendations derived from the current results and are not intended as strict compliance directives.</div>
                        <div class="card-body">
                            <div class="recommendation-stack" style="margin-bottom: 0;">
                                <?php foreach ($kba_recommendations as $recommendation): ?>
                                    <?php
                                    $sourceKey = (string) ($recommendation['source_key'] ?? 'reports');
                                    $sourceClass = 'source-reports';
                                    if ($sourceKey === 'dashboard') {
                                        $sourceClass = 'source-dashboard';
                                    } elseif ($sourceKey === 'analytics') {
                                        $sourceClass = 'source-analytics';
                                    }
                                    ?>
                                    <div class="report-alert <?php echo htmlspecialchars($recommendation['class']); ?> <?php echo htmlspecialchars($sourceClass); ?>">
                                        <strong><?php echo htmlspecialchars($recommendation['title']); ?></strong>
                                        <div class="recommendation-meta">
                                            <span class="recommendation-chip <?php echo htmlspecialchars($sourceClass); ?>"><?php echo htmlspecialchars((string) ($recommendation['source'] ?? 'Reports')); ?></span>
                                        </div>
                                        <div class="recommendation-body">
                                            <p><?php echo htmlspecialchars($recommendation['message']); ?></p>
                                        </div>
                                        <div class="recommendation-footer">
                                            <strong>Evidence:</strong>
                                            <?php $evidenceItems = array_values(array_filter((array) ($recommendation['evidence'] ?? []))); ?>
                                            <?php if (!empty($evidenceItems)): ?>
                                                <ul class="recommendation-evidence-list">
                                                    <?php foreach ($evidenceItems as $evidenceItem): ?>
                                                        <li><?php echo htmlspecialchars((string) $evidenceItem); ?></li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            <?php else: ?>
                                                <span>Derived from current report, dashboard, and analytics state.</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </section>
            </div>
        </section>

        <section class="reports-tab-panel" id="tab-diagnostics" role="tabpanel" aria-labelledby="tab-btn-diagnostics">
            <div class="report-panel-stack">
                <div class="kpi-grid">
                    <div class="kpi-card">
                        <div class="kpi-label">RMSE</div>
                        <div class="kpi-value" id="rmseValue">0.00</div>
                        <div class="kpi-meta">Root Mean Squared Error</div>
                    </div>
                    <div class="kpi-card">
                        <div class="kpi-label">MAE</div>
                        <div class="kpi-value" id="maeValue">0.00</div>
                        <div class="kpi-meta">Mean Absolute Error</div>
                    </div>
                    <div class="kpi-card">
                        <div class="kpi-label">R²</div>
                        <div class="kpi-value" id="r2Value">0.00</div>
                        <div class="kpi-meta">Coefficient of Determination</div>
                    </div>
                </div>

                <div class="diagnostic-card">
                    <h2 class="card-header">XGBoost: Feature Importance</h2>
                    <div class="card-body">
                        <div style="margin-bottom: 16px; display: flex; gap: 8px; flex-wrap: wrap;">
                            <button class="btn btn-sm diagnostic-filter-btn xgboost-filter-btn is-active" type="button" data-filter="all">All Species</button>
                            <button class="btn btn-sm diagnostic-filter-btn xgboost-filter-btn" type="button" data-filter="light_sensitive">Light Sensitive</button>
                            <button class="btn btn-sm diagnostic-filter-btn xgboost-filter-btn" type="button" data-filter="light_tolerant">Light Tolerant</button>
                            <button class="btn btn-sm diagnostic-filter-btn xgboost-filter-btn" type="button" data-filter="migratory">Migratory</button>
                            <button class="btn btn-sm diagnostic-filter-btn xgboost-filter-btn" type="button" data-filter="resident">Resident</button>
                        </div>
                        <div class="diagnostics-chart-wrap is-loading">
                            <div class="is-loading-overlay"></div>
                            <div class="chart-empty-note" id="xgboostEmptyNote">No existing data for the filters applied.</div>
                            <canvas id="xgboostFeatureImportanceChart"></canvas>
                        </div>
                    </div>
                </div>

                <div class="diagnostic-card">
                    <h2 class="card-header">ConvLSTM: Actual vs. Predicted</h2>
                    <div class="card-body">
                        <div style="margin-bottom: 16px; display: flex; gap: 8px; flex-wrap: wrap;">
                            <button class="btn btn-sm diagnostic-filter-btn convlstm-filter-btn is-active" type="button" data-filter="all">All Species</button>
                            <button class="btn btn-sm diagnostic-filter-btn convlstm-filter-btn" type="button" data-filter="light_sensitive">Light Sensitive</button>
                            <button class="btn btn-sm diagnostic-filter-btn convlstm-filter-btn" type="button" data-filter="light_tolerant">Light Tolerant</button>
                            <button class="btn btn-sm diagnostic-filter-btn convlstm-filter-btn" type="button" data-filter="migratory">Migratory</button>
                            <button class="btn btn-sm diagnostic-filter-btn convlstm-filter-btn" type="button" data-filter="resident">Resident</button>
                        </div>
                        <div class="chart-guidance" style="margin-bottom: 10px;">
                            <strong>Note:</strong> Because ConvLSTM used a time-series split, predicted richness covers only 2023 and 2024.
                        </div>
                        <div class="diagnostics-chart-wrap is-loading">
                            <div class="is-loading-overlay"></div>
                            <div class="chart-empty-note" id="convlstmEmptyNote">No existing data for the filters applied.</div>
                            <canvas id="convlstmActualPredictedChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </div>
</div>

<?php
$extra_scripts = <<<'HTML'
<script>
(function () {
    var tabButtons = document.querySelectorAll('.reports-tab-btn');
    var panels = document.querySelectorAll('.reports-tab-panel');

    tabButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            var target = button.getAttribute('data-target');
            if (!target) {
                return;
            }

            tabButtons.forEach(function (btn) {
                btn.classList.remove('is-active');
                btn.setAttribute('aria-selected', 'false');
            });

            panels.forEach(function (panel) {
                panel.classList.remove('is-active');
            });

            button.classList.add('is-active');
            button.setAttribute('aria-selected', 'true');

            var nextPanel = document.getElementById(target);
            if (nextPanel) {
                nextPanel.classList.add('is-active');
            }

            if (target === 'tab-diagnostics' && !diagnosticsLoaded) {
                fetchReportData('diagnostics', { includeDiagnostics: true });
            }
        });
    });
})();

(function () {
    function positionPillarTooltip(tooltipEl) {
        if (!tooltipEl) {
            return;
        }
        var triggerEl = tooltipEl.querySelector('.pillar-tooltip-btn');
        var panelEl = tooltipEl.querySelector('.pillar-tooltip-panel');
        if (!triggerEl || !panelEl) {
            return;
        }

        var prevDisplay = panelEl.style.display;
        var prevVisibility = panelEl.style.visibility;

        panelEl.style.visibility = 'hidden';
        panelEl.style.display = 'block';
        tooltipEl.classList.remove('is-flipped');
        panelEl.style.left = '';
        panelEl.style.right = '0';

        var viewportPad = 8;
        var viewportWidth = window.innerWidth || document.documentElement.clientWidth;
        var viewportHeight = window.innerHeight || document.documentElement.clientHeight;
        var triggerRect = triggerEl.getBoundingClientRect();
        var panelRect = panelEl.getBoundingClientRect();

        var desiredLeft = triggerRect.right - panelRect.width;
        if (desiredLeft < viewportPad) {
            desiredLeft = viewportPad;
        }
        if (desiredLeft + panelRect.width > viewportWidth - viewportPad) {
            desiredLeft = Math.max(viewportPad, viewportWidth - viewportPad - panelRect.width);
        }

        var leftWithinTooltip = desiredLeft - tooltipEl.getBoundingClientRect().left;
        panelEl.style.left = leftWithinTooltip + 'px';
        panelEl.style.right = 'auto';

        var panelHeight = panelRect.height;
        var spaceBelow = viewportHeight - triggerRect.bottom - viewportPad;
        var spaceAbove = triggerRect.top - viewportPad;
        var shouldFlip = spaceBelow < panelHeight && spaceAbove > spaceBelow;
        tooltipEl.classList.toggle('is-flipped', shouldFlip);

        panelEl.style.display = prevDisplay;
        panelEl.style.visibility = prevVisibility;
    }

    var tooltipNodes = document.querySelectorAll('.pillar-tooltip');
    tooltipNodes.forEach(function (tooltipEl) {
        tooltipEl.addEventListener('mouseenter', function () {
            positionPillarTooltip(tooltipEl);
        });
        tooltipEl.addEventListener('focusin', function () {
            positionPillarTooltip(tooltipEl);
        });
    });

    window.addEventListener('resize', function () {
        tooltipNodes.forEach(function (tooltipEl) {
            if (tooltipEl.matches(':hover') || tooltipEl.contains(document.activeElement)) {
                positionPillarTooltip(tooltipEl);
            }
        });
    });
})();

function setFilterNote(noteId, text) {
    var el = document.getElementById(noteId);
    if (!el) {
        return;
    }
    el.textContent = text;
}

function setChartEmptyMessage(messageId, shouldShow, messageText) {
    var el = document.getElementById(messageId);
    if (!el) {
        return;
    }
    el.textContent = messageText || 'No existing data for the filters applied.';
    el.style.display = shouldShow ? 'flex' : 'none';
}

function formatSnapshotFiltersText(filters) {
    return 'Filters: Area=' + filters.selected_area + ' | Snapshot=' + filters.snapshot_year + '-' + String(filters.snapshot_month).padStart(2, '0');
}

function formatTrendFiltersText(filters) {
    return 'Filters: Area=' + filters.selected_area + ' | Years=' + filters.start_year + '-' + filters.end_year;
}

function formatFilterLabel(filterName) {
    if (filterName === 'all') {
        return 'All Species';
    }
    return String(filterName || '')
        .replace(/_/g, ' ')
        .replace(/\b\w/g, function (ch) { return ch.toUpperCase(); });
}

var charts = {
    historical: null,
    correlation: null,
    migrationStatus: null,
    lightTolerance: null,
    lightRichnessScatter: null,
    ndviRichnessScatter: null,
    lstRichnessScatter: null,
    precipitationRichnessScatter: null,
    topSitesRichness: null,
    xgboostFeatureImportance: null,
    convlstmActualPredicted: null
};

var xgboostFeatureImportanceDataByFilter = {};
var xgboostCurrentFilter = 'all';

var convlstmPredictionsDataByFilter = {};
var convlstmCurrentFilter = 'all';
var diagnosticsLoaded = false;
var lastFilterKey = '';
var scopeRequestSeq = {
    trend: 0,
    snapshot: 0,
    diagnostics: 0
};

var PRECIP_SCALE_POWER = 2;
var PRECIP_SCALE_FACTOR = Math.pow(10, PRECIP_SCALE_POWER);
var XGBOOST_COLOR_FALLBACKS = [
    '#e11d48',
    '#06b6d4',
    '#8b5cf6',
    '#f59e0b',
    '#14b8a6',
    '#f472b6',
    '#22c55e',
    '#3b82f6',
    '#a855f7',
    '#84cc16'
];
var XGBOOST_COLOR_MAP = {
    historical_species: '#1d4ed8',
    historicalspecies: '#1d4ed8',
    richnes: '#1d4ed8',
    richness: '#1d4ed8',
    bird_richness: '#1d4ed8',
    species_richness: '#1d4ed8',
    bird_species_richness: '#1d4ed8',
    artificial_light: '#ef4444',
    alan: '#ef4444',
    viirs: '#ef4444',
    ndvi: '#22c55e',
    lst: '#f97316',
    temperature: '#f97316',
    land_surface_temperature: '#f97316',
    precipitation: '#06b6d4',
    precip: '#06b6d4',
    rainfall: '#06b6d4',
    seasonality: '#ec4899',
    seasonal: '#ec4899',
    land_cover: '#eab308',
    landcover: '#eab308',
    land_use: '#eab308'
};

function normalizeFeatureKey(label) {
    return String(label || '').toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '');
}

function colorWithAlpha(hex, alpha) {
    var match = String(hex || '').replace('#', '');
    if (match.length !== 6) {
        return 'rgba(59,130,246,' + alpha + ')';
    }
    var r = parseInt(match.slice(0, 2), 16);
    var g = parseInt(match.slice(2, 4), 16);
    var b = parseInt(match.slice(4, 6), 16);
    return 'rgba(' + r + ',' + g + ',' + b + ',' + alpha + ')';
}

function getXGBoostBarColors(labels) {
    var list = Array.isArray(labels) ? labels : [];
    return list.map(function (label, index) {
        var key = normalizeFeatureKey(label);
        var base = XGBOOST_COLOR_MAP[key] || XGBOOST_COLOR_FALLBACKS[index % XGBOOST_COLOR_FALLBACKS.length];
        return {
            background: colorWithAlpha(base, 0.82),
            border: base
        };
    });
}

function createChartSafe(canvasId, config) {
    if (typeof Chart === 'undefined') {
        return null;
    }
    var canvas = document.getElementById(canvasId);
    if (!canvas) {
        return null;
    }
    var ctx = canvas.getContext('2d');
    if (!ctx) {
        return null;
    }
    return new Chart(ctx, config);
}

function initTrendCharts() {
    charts.historical = createChartSafe('historicalTrendsChart', {
        type: 'line',
        data: {
            labels: [],
            datasets: [
                {
                    label: 'Bird Richness',
                    unit: 'species',
                    data: [],
                    yAxisID: 'yRichness',
                    borderColor: '#2563eb',
                    backgroundColor: 'rgba(37,99,235,0.12)',
                    borderWidth: 3,
                    pointRadius: 3,
                    pointHoverRadius: 5,
                    tension: 0.3,
                    fill: false
                },
                {
                    label: 'ALAN',
                    unit: 'nW/cm²/sr',
                    data: [],
                    yAxisID: 'yEnv',
                    borderColor: '#dc2626',
                    backgroundColor: 'rgba(220,38,38,0.12)',
                    borderWidth: 2,
                    borderDash: [5, 4],
                    tension: 0.3,
                    fill: false
                },
                {
                    label: 'NDVI',
                    unit: 'index',
                    data: [],
                    yAxisID: 'yEnv',
                    borderColor: '#16a34a',
                    backgroundColor: 'rgba(22,163,74,0.12)',
                    borderWidth: 2,
                    borderDash: [5, 4],
                    tension: 0.3,
                    fill: false
                },
                {
                    label: 'LST',
                    unit: '°C',
                    data: [],
                    yAxisID: 'yEnv',
                    borderColor: '#d97706',
                    backgroundColor: 'rgba(217,119,6,0.12)',
                    borderWidth: 2,
                    borderDash: [5, 4],
                    tension: 0.3,
                    fill: false
                },
                {
                    label: 'Precipitation',
                    unit: 'mm',
                    scaleFactor: PRECIP_SCALE_FACTOR,
                    data: [],
                    yAxisID: 'yEnv',
                    borderColor: '#0891b2',
                    backgroundColor: 'rgba(8,145,178,0.12)',
                    borderWidth: 2,
                    tension: 0.3,
                    fill: false
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: {
                    position: 'top',
                    labels: {
                        usePointStyle: true,
                        boxWidth: 12
                    }
                },
                tooltip: {
                    callbacks: {
                        label: function (context) {
                            var ds = context.dataset || {};
                            var value = Number(context.parsed.y || 0);
                            var unit = ds.unit || '';
                            var rawUnit = ds.rawUnit || unit;
                            var scaleFactor = Number(ds.scaleFactor || 1);
                            var precision = unit === 'species' ? 0 : 2;
                            if (unit === 'mm') {
                                precision = 1;
                            }
                            if (unit === 'x10^2 mm') {
                                precision = 2;
                            }
                            if (unit === 'index') {
                                precision = 3;
                            }
                            if (scaleFactor > 1) {
                                var rawValue = value * scaleFactor;
                                return ds.label + ': ' + rawValue.toFixed(1) + (rawUnit ? (' ' + rawUnit) : '');
                            }
                            return ds.label + ': ' + value.toFixed(precision) + (unit ? (' ' + unit) : '');
                        }
                    }
                }
            },
            scales: {
                x: {
                    title: { display: true, text: 'Year' }
                },
                yRichness: {
                    type: 'linear',
                    position: 'left',
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: 'Bird Richness (species count)'
                    },
                    grid: {
                        color: 'rgba(37,99,235,0.12)'
                    }
                },
                yEnv: {
                    type: 'linear',
                    position: 'right',
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: 'Environmental Indicators'
                    },
                    grid: {
                        drawOnChartArea: false
                    }
                }
            }
        }
    });

    charts.correlation = createChartSafe('correlationMatrixChart', {
        type: 'bar',
        data: {
            labels: ['ALAN vs Richness', 'NDVI vs Richness', 'LST vs Richness', 'Precip vs Richness'],
            datasets: [{ label: 'Pearson Correlation (r)', data: [0, 0, 0, 0], borderWidth: 1, borderRadius: 6 }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            indexAxis: 'y',
            plugins: { legend: { display: false } },
            scales: {
                x: { min: -1, max: 1, title: { display: true, text: 'Correlation Coefficient (r)' } }
            }
        }
    });

    charts.migrationStatus = createChartSafe('migrationStatusChart', {
        type: 'doughnut',
        data: {
            labels: ['Migratory', 'Resident', 'Unclassified'],
            datasets: [{
                data: [0, 0, 0],
                backgroundColor: ['#0891b2', '#16a34a', '#6b7280'],
                borderColor: ['#0e7490', '#15803d', '#4b5563'],
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom' }
            }
        }
    });

    charts.lightTolerance = createChartSafe('lightToleranceChart', {
        type: 'doughnut',
        data: {
            labels: ['Sensitive', 'Tolerant', 'Unclassified'],
            datasets: [{
                data: [0, 0, 0],
                backgroundColor: ['#dc2626', '#2563eb', '#6b7280'],
                borderColor: ['#b91c1c', '#1d4ed8', '#4b5563'],
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom' }
            }
        }
    });

    var createSnapshotScatterChart = function (canvasId, label, color, xAxisTitle) {
        return createChartSafe(canvasId, {
            type: 'scatter',
            data: {
                datasets: [{
                    label: label,
                    data: [],
                    backgroundColor: color,
                    borderColor: color,
                    pointRadius: 4,
                    pointHoverRadius: 6,
                    pointBorderWidth: 1,
                    showLine: false
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            title: function (items) {
                                if (!items || !items.length) {
                                    return '';
                                }
                                var raw = items[0].raw || {};
                                return raw.site_name || raw.area || 'Site';
                            },
                            label: function (context) {
                                var x = Number(context.parsed.x || 0);
                                var y = Number(context.parsed.y || 0);
                                var raw = context.raw || {};
                                var area = raw.area ? (' | Area: ' + raw.area) : '';
                                return 'X: ' + x.toFixed(3) + ' | Richness: ' + y.toFixed(0) + area;
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        title: {
                            display: true,
                            text: xAxisTitle
                        }
                    },
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Bird Richness (species)'
                        }
                    }
                }
            }
        });
    };

    charts.lightRichnessScatter = createSnapshotScatterChart('lightRichnessScatterChart', 'Light vs Richness', 'rgba(220,38,38,0.85)', 'ALAN (nW/cm²/sr)');
    charts.ndviRichnessScatter = createSnapshotScatterChart('ndviRichnessScatterChart', 'NDVI vs Richness', 'rgba(22,163,74,0.85)', 'NDVI (index)');
    charts.lstRichnessScatter = createSnapshotScatterChart('lstRichnessScatterChart', 'LST vs Richness', 'rgba(217,119,6,0.85)', 'LST (°C)');
    charts.precipitationRichnessScatter = createSnapshotScatterChart('precipitationRichnessScatterChart', 'Precipitation vs Richness', 'rgba(8,145,178,0.85)', 'Precipitation (mm)');

    charts.topSitesRichness = createChartSafe('topSitesRichnessChart', {
        type: 'bar',
        data: {
            labels: [],
            datasets: [{
                label: 'Bird Richness',
                data: [],
                backgroundColor: 'rgba(37,99,235,0.78)',
                borderColor: 'rgba(37,99,235,1)',
                borderWidth: 1,
                borderRadius: 6,
                barThickness: 18,
                maxBarThickness: 22
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            indexAxis: 'y',
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        title: function (items) {
                            if (!items || !items.length) {
                                return '';
                            }
                            var rawLabel = items[0].label || '';
                            return rawLabel;
                        },
                        label: function (context) {
                            return 'Bird richness: ' + Number(context.parsed.x || 0).toFixed(0);
                        }
                    }
                }
            },
            scales: {
                x: {
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: 'Bird Richness (species)'
                    }
                },
                y: {
                    ticks: {
                        autoSkip: false
                    }
                }
            }
        }
    });

    charts.xgboostFeatureImportance = createChartSafe('xgboostFeatureImportanceChart', {
        type: 'bar',
        data: {
            labels: [],
            datasets: [{
                label: 'Importance Score',
                data: [],
                backgroundColor: [],
                borderColor: [],
                borderWidth: 1,
                borderRadius: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            indexAxis: 'y',
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function (context) {
                            return 'Importance: ' + Number(context.parsed.x || 0).toFixed(4);
                        }
                    }
                }
            },
            scales: {
                x: {
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: 'Importance Score'
                    }
                },
                y: {
                    ticks: {
                        autoSkip: false
                    }
                }
            }
        }
    });

    charts.convlstmActualPredicted = createChartSafe('convlstmActualPredictedChart', {
        type: 'line',
        data: {
            labels: [],
            datasets: [
                {
                    label: 'Actual Richness',
                    data: [],
                    borderColor: '#2563eb',
                    backgroundColor: 'rgba(37,99,235,0.12)',
                    borderWidth: 2.5,
                    pointRadius: 4,
                    pointHoverRadius: 6,
                    pointBackgroundColor: '#2563eb',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 1,
                    tension: 0.3,
                    fill: false
                },
                {
                    label: 'Predicted Richness',
                    data: [],
                    borderColor: '#dc2626',
                    backgroundColor: 'rgba(220,38,38,0.12)',
                    borderWidth: 2.5,
                    borderDash: [5, 5],
                    pointRadius: 4,
                    pointHoverRadius: 6,
                    pointBackgroundColor: '#dc2626',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 1,
                    tension: 0.3,
                    fill: false
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: true,
                    position: 'top',
                    labels: {
                        usePointStyle: true,
                        boxWidth: 8
                    }
                },
                tooltip: {
                    callbacks: {
                        label: function (context) {
                            return context.dataset.label + ': ' + Number(context.parsed.y || 0).toFixed(2);
                        }
                    }
                }
            },
            scales: {
                y: {
                    title: {
                        display: true,
                        text: 'Species Richness (count)'
                    },
                    beginAtZero: true
                },
                x: {
                    title: {
                        display: true,
                        text: 'Year'
                    }
                }
            }
        }
    });
}

function updateSnapshotCharts(payload) {
    var snap = payload && payload.snapshotDistributions ? payload.snapshotDistributions : {};
    var scatter = payload && payload.snapshotScatterData ? payload.snapshotScatterData : {};
    var topSites = payload && payload.topSitesRichnessData ? payload.topSitesRichnessData : {};
    var xgboostData = payload && payload.xgboostFeatureImportance ? payload.xgboostFeatureImportance : {};
    var migration = snap && snap.migration_status ? snap.migration_status : {};
    var light = snap && snap.light_tolerance ? snap.light_tolerance : {};
    var filters = getFilterValues();
    var snapshotFilterText = formatSnapshotFiltersText(filters);

    setFilterNote('lightRichnessFilterNote', snapshotFilterText);
    setFilterNote('ndviRichnessFilterNote', snapshotFilterText);
    setFilterNote('lstRichnessFilterNote', snapshotFilterText);
    setFilterNote('precipitationRichnessFilterNote', snapshotFilterText);
    setFilterNote('migrationFilterNote', snapshotFilterText);
    setFilterNote('lightToleranceFilterNote', snapshotFilterText);

    var topSitesYear = topSites && topSites.snapshot_year ? topSites.snapshot_year : filters.snapshot_year;
    var topSitesMonth = topSites && topSites.snapshot_month ? topSites.snapshot_month : filters.snapshot_month;
    setFilterNote('topSitesFilterNote', 'Displays: All Areas | Latest Month Snapshot, Last Updated: ' + topSitesYear + '-' + String(topSitesMonth).padStart(2, '0'));

    if (charts.migrationStatus) {
        charts.migrationStatus.data.labels = Array.isArray(migration.labels) ? migration.labels : ['Migratory', 'Resident', 'Unclassified'];
        charts.migrationStatus.data.datasets[0].data = Array.isArray(migration.data) ? migration.data : [0, 0, 0];
        charts.migrationStatus.update();
        var migrationValues = charts.migrationStatus.data.datasets[0].data || [];
        var migrationTotal = migrationValues.reduce(function (sum, v) { return sum + Number(v || 0); }, 0);
        setChartEmptyMessage('migrationEmptyNote', migrationTotal === 0);
    }

    if (charts.lightTolerance) {
        charts.lightTolerance.data.labels = Array.isArray(light.labels) ? light.labels : ['Sensitive', 'Tolerant', 'Unclassified'];
        charts.lightTolerance.data.datasets[0].data = Array.isArray(light.data) ? light.data : [0, 0, 0];
        charts.lightTolerance.update();
        var lightValues = charts.lightTolerance.data.datasets[0].data || [];
        var lightTotal = lightValues.reduce(function (sum, v) { return sum + Number(v || 0); }, 0);
        setChartEmptyMessage('lightToleranceEmptyNote', lightTotal === 0);
    }

    if (charts.lightRichnessScatter) {
        var lightPoints = scatter && scatter.light_richness && Array.isArray(scatter.light_richness.points)
            ? scatter.light_richness.points
            : [];
        charts.lightRichnessScatter.data.datasets[0].data = lightPoints;
        charts.lightRichnessScatter.update();
        setChartEmptyMessage('lightRichnessEmptyNote', lightPoints.length === 0);
    }

    if (charts.ndviRichnessScatter) {
        var ndviPoints = scatter && scatter.ndvi_richness && Array.isArray(scatter.ndvi_richness.points)
            ? scatter.ndvi_richness.points
            : [];
        charts.ndviRichnessScatter.data.datasets[0].data = ndviPoints;
        charts.ndviRichnessScatter.update();
        setChartEmptyMessage('ndviRichnessEmptyNote', ndviPoints.length === 0);
    }

    if (charts.lstRichnessScatter) {
        var lstPoints = scatter && scatter.lst_richness && Array.isArray(scatter.lst_richness.points)
            ? scatter.lst_richness.points
            : [];
        charts.lstRichnessScatter.data.datasets[0].data = lstPoints;
        charts.lstRichnessScatter.update();
        setChartEmptyMessage('lstRichnessEmptyNote', lstPoints.length === 0);
    }

    if (charts.precipitationRichnessScatter) {
        var precipPoints = scatter && scatter.precipitation_richness && Array.isArray(scatter.precipitation_richness.points)
            ? scatter.precipitation_richness.points
            : [];
        charts.precipitationRichnessScatter.data.datasets[0].data = precipPoints;
        charts.precipitationRichnessScatter.update();
        setChartEmptyMessage('precipitationRichnessEmptyNote', precipPoints.length === 0);
    }

    if (charts.topSitesRichness) {
        var topLabels = Array.isArray(topSites.labels) ? topSites.labels : [];
        var topValues = Array.isArray(topSites.data) ? topSites.data : [];
        charts.topSitesRichness.data.labels = topLabels;
        charts.topSitesRichness.data.datasets[0].data = topValues;
        charts.topSitesRichness.update();
        setChartEmptyMessage('topSitesEmptyNote', topValues.length === 0);
    }

    if (Object.keys(xgboostData).length > 0) {
        xgboostFeatureImportanceDataByFilter = xgboostData;
        switchXGBoostFilter(xgboostCurrentFilter);
    }

    var convlstm = payload && payload.convlstmPredictions ? payload.convlstmPredictions : {};
    if (Object.keys(convlstm).length > 0) {
        convlstmPredictionsDataByFilter = convlstm;
        switchConvLSTMFilter(convlstmCurrentFilter);
    }

    // Populate KPI cards with ensemble metrics
    if (payload && payload.ensembleMetrics && payload.ensembleMetrics.ensemble_average) {
        var metrics = payload.ensembleMetrics.ensemble_average;
        document.getElementById('rmseValue').textContent = (metrics.rmse || 0).toFixed(4);
        document.getElementById('maeValue').textContent = (metrics.mae || 0).toFixed(4);
        document.getElementById('r2Value').textContent = (metrics.r2 || 0).toFixed(4);
    }

}

function setScopeLoading(scope, isLoading) {
    if (scope === 'snapshot') {
        var selector = '.snapshot-chart-wrap';
        document.querySelectorAll(selector).forEach(function (el) {
            el.classList.toggle('is-loading', !!isLoading);
        });
    } else if (scope === 'trend') {
        var selector = '.trend-chart-wrap';
        document.querySelectorAll(selector).forEach(function (el) {
            el.classList.toggle('is-loading', !!isLoading);
        });
    } else if (scope === 'diagnostics') {
        var selector = '.diagnostics-chart-wrap';
        document.querySelectorAll(selector).forEach(function (el) {
            el.classList.toggle('is-loading', !!isLoading);
        });
    }
}

function getFilterValues() {
    return {
        selected_area: (document.getElementById('globalAreaFilter') || {}).value || 'All Areas',
        start_year: (document.getElementById('trendStartYear') || {}).value || '2014',
        end_year: (document.getElementById('trendEndYear') || {}).value || '2024',
        snapshot_year: (document.getElementById('snapshotYear') || {}).value || '2024',
        snapshot_month: (document.getElementById('snapshotMonth') || {}).value || '1'
    };
}

function buildRequestByScope(scope, filters, includeDiagnostics) {
    var request = {
        scope: scope,
        include_diagnostics: includeDiagnostics ? '1' : '0'
    };

    if (scope === 'trend') {
        request.selected_area = filters.selected_area;
        request.start_year = filters.start_year;
        request.end_year = filters.end_year;
        return request;
    }

    if (scope === 'snapshot') {
        request.selected_area = filters.selected_area;
        request.snapshot_year = filters.snapshot_year;
        request.snapshot_month = filters.snapshot_month;
        return request;
    }

    request.selected_area = filters.selected_area;
    request.start_year = filters.start_year;
    request.end_year = filters.end_year;
    request.snapshot_year = filters.snapshot_year;
    request.snapshot_month = filters.snapshot_month;
    return request;
}

function updateTrendCharts(payload) {
    var trendData = payload && payload.trendHistoricalData ? payload.trendHistoricalData : {};
    var corr = payload && payload.trendCorrelationData ? payload.trendCorrelationData : {};
    var filters = getFilterValues();
    var trendFilterText = formatTrendFiltersText(filters);
    setFilterNote('historicalFilterNote', trendFilterText);
    setFilterNote('correlationFilterNote', trendFilterText);

    if (charts.historical) {
        charts.historical.data.labels = Array.isArray(trendData.labels) ? trendData.labels : [];
        charts.historical.data.datasets[0].data = Array.isArray(trendData.richness) ? trendData.richness : [];
        charts.historical.data.datasets[1].data = Array.isArray(trendData.viirs) ? trendData.viirs : [];
        charts.historical.data.datasets[2].data = Array.isArray(trendData.ndvi) ? trendData.ndvi : [];
        charts.historical.data.datasets[3].data = Array.isArray(trendData.lst) ? trendData.lst : [];
        charts.historical.data.datasets[4].data = Array.isArray(trendData.precip)
            ? trendData.precip.map(function (v) {
                return v == null ? null : Number(v) / PRECIP_SCALE_FACTOR;
            })
            : [];
        charts.historical.update();
        var histHasData = Array.isArray(trendData.labels) && trendData.labels.length > 0;
        setChartEmptyMessage('historicalEmptyNote', !histHasData);
    }

    var corrValues = [
        Number(corr.richness_viirs || 0),
        Number(corr.richness_ndvi || 0),
        Number(corr.richness_lst || 0),
        Number(corr.richness_precip || 0)
    ];

    if (charts.correlation) {
        charts.correlation.data.datasets[0].data = corrValues;
        charts.correlation.data.datasets[0].backgroundColor = [
            'rgba(220,38,38,0.8)',
            'rgba(22,163,74,0.8)',
            'rgba(217,119,6,0.8)',
            'rgba(8,145,178,0.8)'
        ];
        charts.correlation.data.datasets[0].borderColor = [
            'rgba(220,38,38,1)',
            'rgba(22,163,74,1)',
            'rgba(217,119,6,1)',
            'rgba(8,145,178,1)'
        ];
        charts.correlation.update();
        var corrHasData = Array.isArray(trendData.labels) && trendData.labels.length > 0;
        setChartEmptyMessage('correlationEmptyNote', !corrHasData);
    }
}

async function fetchReportData(scope, options) {
    options = options || {};
    var includeDiagnostics = !!options.includeDiagnostics || scope === 'diagnostics';
    var filters = getFilterValues();
    var currentFilterKey = [filters.selected_area, filters.start_year, filters.end_year, filters.snapshot_year, filters.snapshot_month].join('|');
    if (lastFilterKey && lastFilterKey !== currentFilterKey) {
        diagnosticsLoaded = false;
    }
    lastFilterKey = currentFilterKey;

    if (Number(filters.start_year) > Number(filters.end_year)) {
        filters.end_year = filters.start_year;
        var endEl = document.getElementById('trendEndYear');
        if (endEl) {
            endEl.value = filters.end_year;
        }
    }

    setScopeLoading(scope, true);
    scopeRequestSeq[scope] = (scopeRequestSeq[scope] || 0) + 1;
    var requestSeq = scopeRequestSeq[scope];
    if (includeDiagnostics) {
        setScopeLoading('diagnostics', true);
    }
    try {
        var req = buildRequestByScope(scope, filters, includeDiagnostics);
        var params = new URLSearchParams(req);
        var response = await fetch('api/get_report_data.php?' + params.toString(), {
            method: 'GET',
            headers: { 'Accept': 'application/json' }
        });

        if (!response.ok) {
            var errorText = '';
            try {
                var errorJson = await response.json();
                errorText = (errorJson && (errorJson.error || errorJson.detail)) ? (errorJson.error || errorJson.detail) : '';
            } catch (parseErr) {
                errorText = '';
            }
            throw new Error(errorText ? ('Request failed: ' + response.status + ' - ' + errorText) : ('Request failed: ' + response.status));
        }

        var data = await response.json();
        if (!data.success) {
            throw new Error(data.error || 'Failed to load report data');
        }

        // Ignore stale responses so rapid filter changes do not revert charts.
        if (requestSeq !== scopeRequestSeq[scope]) {
            return;
        }

        if (scope === 'trend') {
            updateTrendCharts(data);
        } else if (scope === 'snapshot') {
            updateSnapshotCharts(data);
        } else if (scope === 'diagnostics') {
            updateSnapshotCharts(data);
        }
        if (data && data.meta && data.meta.diagnostics_source && data.meta.diagnostics_source !== 'live_model_inference_db') {
            console.warn('Diagnostics source is not live model inference:', data.meta.diagnostics_source, data.meta.diagnostics_error || '');
        }
        if (data && data.meta && data.meta.diagnostics_included) {
            diagnosticsLoaded = true;
        }
        if (includeDiagnostics) {
            setScopeLoading('diagnostics', false);
        }

        // Keep URL in sync without full reload.
        var next = new URLSearchParams(filters);
        history.replaceState({}, '', window.location.pathname + '?' + next.toString());
    } catch (err) {
        console.error(err);
        if (requestSeq !== scopeRequestSeq[scope]) {
            return;
        }
        if (scope === 'trend') {
            var trendErrMsg = (err && err.message) ? err.message : 'Unable to load data for the filters applied.';
            setChartEmptyMessage('historicalEmptyNote', true, trendErrMsg);
            setChartEmptyMessage('correlationEmptyNote', true, trendErrMsg);
        }
        if (scope === 'snapshot') {
            var snapshotErrMsg = (err && err.message) ? err.message : 'Unable to load data for the filters applied.';
            setChartEmptyMessage('lightRichnessEmptyNote', true, snapshotErrMsg);
            setChartEmptyMessage('ndviRichnessEmptyNote', true, snapshotErrMsg);
            setChartEmptyMessage('lstRichnessEmptyNote', true, snapshotErrMsg);
            setChartEmptyMessage('precipitationRichnessEmptyNote', true, snapshotErrMsg);
            setChartEmptyMessage('topSitesEmptyNote', true, snapshotErrMsg);
        }
        if (scope === 'diagnostics' || includeDiagnostics) {
            setChartEmptyMessage('xgboostEmptyNote', true, 'Unable to load diagnostics for the filters applied.');
            setChartEmptyMessage('convlstmEmptyNote', true, 'Unable to load diagnostics for the filters applied.');
        }
        if (includeDiagnostics) {
            setScopeLoading('diagnostics', false);
        }
    } finally {
        if (requestSeq === scopeRequestSeq[scope]) {
            setScopeLoading(scope, false);
        }
    }
}

function switchXGBoostFilter(filterName) {
    xgboostCurrentFilter = filterName;
    var data = xgboostFeatureImportanceDataByFilter[filterName] || {};
    var labels = Array.isArray(data.labels) ? data.labels : [];
    var values = Array.isArray(data.values) ? data.values : [];
    var colors = getXGBoostBarColors(labels);
    var backgroundColors = colors.map(function (entry) { return entry.background; });
    var borderColors = colors.map(function (entry) { return entry.border; });
    
    if (charts.xgboostFeatureImportance) {
        charts.xgboostFeatureImportance.data.labels = labels;
        charts.xgboostFeatureImportance.data.datasets[0].data = values;
        charts.xgboostFeatureImportance.data.datasets[0].backgroundColor = backgroundColors;
        charts.xgboostFeatureImportance.data.datasets[0].borderColor = borderColors;
        charts.xgboostFeatureImportance.update();
    }
    setChartEmptyMessage('xgboostEmptyNote', values.length === 0);
    
    var buttons = document.querySelectorAll('.xgboost-filter-btn');
    buttons.forEach(function (btn) {
        btn.classList.toggle('is-active', btn.getAttribute('data-filter') === filterName);
    });
}

function switchConvLSTMFilter(filterName) {
    convlstmCurrentFilter = filterName;
    var data = convlstmPredictionsDataByFilter[filterName] || {};
    var years = Array.isArray(data.years) ? data.years : [];
    var actual = Array.isArray(data.actual) ? data.actual : [];
    var predicted = Array.isArray(data.predicted) ? data.predicted : [];
    
    if (charts.convlstmActualPredicted) {
        charts.convlstmActualPredicted.data.labels = years;
        charts.convlstmActualPredicted.data.datasets[0].data = actual;
        charts.convlstmActualPredicted.data.datasets[1].data = predicted;
        charts.convlstmActualPredicted.update();
    }
    setChartEmptyMessage('convlstmEmptyNote', years.length === 0);
    
    var buttons = document.querySelectorAll('.convlstm-filter-btn');
    buttons.forEach(function (btn) {
        btn.classList.toggle('is-active', btn.getAttribute('data-filter') === filterName);
    });
}

function wireFilterButtons() {
    var trendBtn = document.getElementById('applyTrendFiltersBtn');
    var snapshotBtn = document.getElementById('applySnapshotFiltersBtn');
    var globalAreaFilter = document.getElementById('globalAreaFilter');

    if (trendBtn) {
        trendBtn.addEventListener('click', function (e) {
            e.preventDefault();
            diagnosticsLoaded = false;
            fetchReportData('trend');
            // Area is global; keep snapshot charts in sync when trend filters are applied.
            fetchReportData('snapshot');
        });
    }

    if (snapshotBtn) {
        snapshotBtn.addEventListener('click', function (e) {
            e.preventDefault();
            diagnosticsLoaded = false;
            fetchReportData('snapshot');
        });
    }

    if (globalAreaFilter) {
        globalAreaFilter.addEventListener('change', function () {
            diagnosticsLoaded = false;
            fetchReportData('trend');
            fetchReportData('snapshot');
        });
    }

    var xgboostFilterBtns = document.querySelectorAll('.xgboost-filter-btn');
    xgboostFilterBtns.forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            var filterName = btn.getAttribute('data-filter');
            switchXGBoostFilter(filterName);
        });
    });

    var convlstmFilterBtns = document.querySelectorAll('.convlstm-filter-btn');
    convlstmFilterBtns.forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            var filterName = btn.getAttribute('data-filter');
            switchConvLSTMFilter(filterName);
        });
    });
}

function exportPDF() {
    exportReport('pdf');
}

function exportCSV() {
    exportReport('csv');
}

function exportReport(format) {
    if (window.exportRequestInFlight) {
        return;
    }

    var isPdf = format === 'pdf';
    var formatUpper = isPdf ? 'PDF' : 'CSV';
    var filters = getFilterValues();
    var params = new URLSearchParams(filters);
    params.set('format', format);

    var overlay = document.getElementById('exportLoadingOverlay');
    var titleEl = document.getElementById('exportLoadingTitle');
    var textEl = document.getElementById('exportLoadingText');
    var metaEl = document.getElementById('exportLoadingMeta');
    var cancelBtn = document.getElementById('cancelPdfExportBtn');
    var controller = new AbortController();
    var cancelled = false;

    window.exportRequestInFlight = true;
    window.exportAbortController = controller;

    if (titleEl) {
        titleEl.textContent = 'Generating ' + formatUpper + ' Report';
    }
    if (textEl) {
        textEl.textContent = 'Please wait while the ' + formatUpper + ' file is being generated. This can take a few moments for large reports.';
    }
    if (metaEl) {
        metaEl.textContent = 'You can cancel now if you want to adjust filters first.';
    }

    if (overlay) {
        overlay.classList.add('is-active');
        overlay.setAttribute('aria-hidden', 'false');
    }
    if (cancelBtn) {
        cancelBtn.disabled = false;
        cancelBtn.textContent = 'Cancel';
    }

    var onCancel = function () {
        cancelled = true;
        if (cancelBtn) {
            cancelBtn.disabled = true;
            cancelBtn.textContent = 'Cancelling...';
        }
        controller.abort();
    };

    if (cancelBtn) {
        cancelBtn.addEventListener('click', onCancel, { once: true });
    }

    fetch('api/export_handler.php?' + params.toString(), {
        method: 'GET',
        headers: { 'Accept': isPdf ? 'application/pdf' : 'text/csv' },
        credentials: 'same-origin',
        signal: controller.signal
    })
        .then(function (response) {
            if (!response.ok) {
                return response.text().then(function (body) {
                    throw new Error(body || (formatUpper + ' export failed with status ' + response.status));
                });
            }

            var disposition = response.headers.get('Content-Disposition') || '';
            var defaultExt = isPdf ? '.pdf' : '.csv';
            var fileName = 'avilight_reports_' + new Date().toISOString().slice(0, 19).replace(/[-:T]/g, '') + defaultExt;
            var match = disposition.match(/filename="?([^";]+)"?/i);
            if (match && match[1]) {
                fileName = match[1];
            }

            return response.blob().then(function (blob) {
                return { blob: blob, fileName: fileName };
            });
        })
        .then(function (result) {
            var url = URL.createObjectURL(result.blob);
            var a = document.createElement('a');
            a.href = url;
            a.download = result.fileName;
            document.body.appendChild(a);
            a.click();
            a.remove();
            window.setTimeout(function () {
                URL.revokeObjectURL(url);
            }, 1000);
        })
        .catch(function (err) {
            console.error(err);
            if (err && err.name === 'AbortError') {
                return;
            }
            window.alert(formatUpper + ' export failed. ' + (err && err.message ? err.message : 'Please try again.'));
        })
        .finally(function () {
            window.exportRequestInFlight = false;
            window.exportAbortController = null;
            if (overlay) {
                overlay.classList.remove('is-active');
                overlay.setAttribute('aria-hidden', 'true');
            }
            if (cancelBtn) {
                cancelBtn.disabled = false;
                cancelBtn.textContent = 'Cancel';
            }
            if (cancelled) {
                console.info(formatUpper + ' generation cancelled by user.');
            }
        });
}

initTrendCharts();
wireFilterButtons();
fetchReportData('trend');
fetchReportData('snapshot');
</script>
HTML;

require_once 'includes/footer.php';
?>