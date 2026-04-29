<?php
/**
 * api/covariate_status.php
 *
 * Returns the last-fetch timestamp and sync status for each of the 4
 * environmental covariate tables, relative to the latest period covered
 * by raw_bird_observation.
 *
 * Status rules (evaluated in order):
 *  1. raw_bird_observation is empty          → "No Observations"
 *  2. Covariate table is empty               → "No Data"
 *  3. obs_year  > cov_year                   → "Fetch Required"
 *  4. obs_year == cov_year, obs_month > cov_month → "Fetch Required"
 *  5. obs_year == cov_year, obs_month == cov_month → "Up to Date"
 *  6. cov_year  > obs_year                   → "Ahead of Observations"
 *  7. obs_year == cov_year, cov_month > obs_month  → "Ahead of Observations"
 *
 * Response JSON:
 * {
 *   "obs_period": { "year": 2024, "month": 11 },
 *   "covariates": {
 *     "viirs":     { "year": 2024, "month": 10, "ingested_at": "2026-01-28 14:30:00", "status": "Fetch Required" },
 *     "ndvi":      { ... },
 *     "land_temp": { ... },
 *     "precip":    { ... }
 *   }
 * }
 */

ob_start();
ini_set('display_errors', '0');

set_exception_handler(function (Throwable $e) {
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
});

header('Content-Type: application/json');
require_once __DIR__ . '/../includes/auth.php';

if (!is_logged_in()) {
    ob_end_clean();
    echo json_encode(['success' => false, 'error' => 'Session expired.']);
    exit;
}
api_assert_active();

require_once __DIR__ . '/../includes/db.php';

// ── helpers ────────────────────────────────────────────────────────────────────

/**
 * Get the latest year + month from a monthly covariate table, and the most
 * recent ingested_at. Returns null if the table is empty.
 */
function latest_period(PDO $pdo, string $table): ?array {
    $stmt = $pdo->query(
        "SELECT year, MAX(month) AS month, MAX(ingested_at) AS ingested_at
         FROM `{$table}`
         GROUP BY year
         ORDER BY year DESC
         LIMIT 1"
    );
    $row = $stmt->fetch();
    if (!$row || $row['year'] === null) {
        return null;
    }
    return [
        'year'        => (int) $row['year'],
        'month'       => (int) $row['month'],
        'ingested_at' => $row['ingested_at'],
    ];
}

/**
 * Get the latest year from an annual covariate table (e.g. land_cover).
 * Returns null if the table is empty.
 */
function latest_year_only(PDO $pdo, string $table): ?array {
    $stmt = $pdo->query(
        "SELECT MAX(year) AS year, MAX(ingested_at) AS ingested_at
         FROM `{$table}`"
    );
    $row = $stmt->fetch();
    if (!$row || $row['year'] === null) {
        return null;
    }
    return [
        'year'        => (int) $row['year'],
        'month'       => null,
        'ingested_at' => $row['ingested_at'],
    ];
}

/**
 * Determine sync status by comparing the observation period to a covariate period.
 */
function sync_status(?array $obs, ?array $cov): string {
    if ($obs === null)  return 'No Observations';
    if ($cov === null)  return 'No Data';

    $obs_ym = $obs['year'] * 100 + $obs['month'];
    $cov_ym = $cov['year'] * 100 + $cov['month'];

    if ($obs_ym > $cov_ym) return 'Fetch Required';
    if ($obs_ym === $cov_ym) return 'Up to Date';
    return 'Ahead of Observations';
}

/**
 * Sync status for annual covariates — compares years only.
 */
function sync_status_annual(?array $obs, ?array $cov): string {
    if ($obs === null) return 'No Observations';
    if ($cov === null) return 'No Data';
    if ($obs['year'] > $cov['year']) return 'Fetch Required';
    if ($obs['year'] === $cov['year']) return 'Up to Date';
    return 'Ahead of Observations';
}

// ── query ──────────────────────────────────────────────────────────────────────

try {
    $pdo = get_mysql_db();

    // Latest period in bird observations
    $obs_stmt = $pdo->query(
        "SELECT year, MAX(month) AS month
         FROM raw_bird_observation
         GROUP BY year
         ORDER BY year DESC
         LIMIT 1"
    );
    $obs_row = $obs_stmt->fetch();
    $obs = ($obs_row && $obs_row['year'] !== null)
        ? ['year' => (int) $obs_row['year'], 'month' => (int) $obs_row['month']]
        : null;

    // Latest period + ingested_at for each monthly covariate
    $covariates = [];
    foreach (['viirs', 'ndvi', 'land_temp', 'precip'] as $table) {
        $period = latest_period($pdo, $table);
        $covariates[$table] = [
            'year'        => $period['year']        ?? null,
            'month'       => $period['month']       ?? null,
            'ingested_at' => $period['ingested_at'] ?? null,
            'status'      => sync_status($obs, $period),
        ];
    }

    // Land cover — annual covariate, year-only comparison
    $lc_period = latest_year_only($pdo, 'land_cover');
    $covariates['land_cover'] = [
        'year'        => $lc_period['year']        ?? null,
        'month'       => null,
        'ingested_at' => $lc_period['ingested_at'] ?? null,
        'status'      => sync_status_annual($obs, $lc_period),
    ];

    ob_end_clean();
    echo json_encode([
        'success'    => true,
        'obs_period' => $obs,
        'covariates' => $covariates,
    ]);

} catch (PDOException $e) {
    ob_end_clean();
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
