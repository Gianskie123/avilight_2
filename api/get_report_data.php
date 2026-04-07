<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/backend_config.php';

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Unauthorized',
    ]);
    exit;
}

$selected_area = trim((string) ($_GET['selected_area'] ?? 'All Areas'));
$start_year = (int) ($_GET['start_year'] ?? 2014);
$end_year = (int) ($_GET['end_year'] ?? 2024);
$snapshot_year = (int) ($_GET['snapshot_year'] ?? 2024);
$snapshot_month = (int) ($_GET['snapshot_month'] ?? 12);
$include_normalized = (string) ($_GET['include_normalized'] ?? '0') === '1';
$scope = trim((string) ($_GET['scope'] ?? 'trend'));
$include_diagnostics = ((string) ($_GET['include_diagnostics'] ?? '0') === '1') || ($scope === 'diagnostics');

$metro_manila_cities = [
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

$units = [
    'bird_richness' => 'species',
    'viirs' => 'nW/cm²/sr',
    'ndvi' => 'index',
    'lst' => '°C',
    'precipitation' => 'mm',
];

$cacheTtlSeconds = $include_diagnostics ? 900 : 120;
$cacheDir = __DIR__ . '/../data/cache/reports';

/**
 * Build inline SQL table for Metro Manila city names.
 */
function buildCityInlineSql(array $cities): string {
    $parts = [];
    foreach ($cities as $city) {
        $safe = str_replace("'", "''", $city);
        $parts[] = "SELECT '{$safe}' AS area";
    }
    return implode(' UNION ALL ', $parts);
}

function normalizeCityKey(string $city): string {
    $normalized = mb_strtolower(trim($city), 'UTF-8');
    $normalized = strtr($normalized, [
        'á' => 'a',
        'à' => 'a',
        'ä' => 'a',
        'â' => 'a',
        'ã' => 'a',
        'å' => 'a',
        'é' => 'e',
        'è' => 'e',
        'ë' => 'e',
        'ê' => 'e',
        'í' => 'i',
        'ì' => 'i',
        'ï' => 'i',
        'î' => 'i',
        'ó' => 'o',
        'ò' => 'o',
        'ö' => 'o',
        'ô' => 'o',
        'õ' => 'o',
        'ú' => 'u',
        'ù' => 'u',
        'ü' => 'u',
        'û' => 'u',
        'ñ' => 'n',
        'ç' => 'c',
    ]);
    $normalized = preg_replace('/\s+/', ' ', (string) $normalized);
    return (string) $normalized;
}

function flattenCityPolygons(array $geometry): array {
    $type = $geometry['type'] ?? '';
    $coords = $geometry['coordinates'] ?? [];
    if ($type === 'Polygon') {
        return [$coords];
    }
    if ($type === 'MultiPolygon') {
        return $coords;
    }
    return [];
}

function ringContainsPoint(array $ring, float $x, float $y): bool {
    $inside = false;
    $n = count($ring);
    if ($n < 3) {
        return false;
    }
    for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
        $xi = (float) ($ring[$i][0] ?? 0);
        $yi = (float) ($ring[$i][1] ?? 0);
        $xj = (float) ($ring[$j][0] ?? 0);
        $yj = (float) ($ring[$j][1] ?? 0);
        $intersect = (($yi > $y) !== ($yj > $y))
            && ($x < (($xj - $xi) * ($y - $yi) / (($yj - $yi) ?: 1e-12) + $xi));
        if ($intersect) {
            $inside = !$inside;
        }
    }
    return $inside;
}

function polygonContainsPoint(array $polygon, float $x, float $y): bool {
    if (empty($polygon)) {
        return false;
    }
    if (!ringContainsPoint($polygon[0], $x, $y)) {
        return false;
    }
    $holes = array_slice($polygon, 1);
    foreach ($holes as $hole) {
        if (ringContainsPoint($hole, $x, $y)) {
            return false;
        }
    }
    return true;
}

function loadCityPolygons(array $cities): array {
    $path = __DIR__ . '/../MM_Cities_WGS84.geojson';
    if (!is_readable($path)) {
        return [];
    }

    $geo = json_decode((string) file_get_contents($path), true);
    if (!is_array($geo) || !isset($geo['features']) || !is_array($geo['features'])) {
        return [];
    }

    $cityByKey = [];
    foreach ($cities as $city) {
        $cityByKey[normalizeCityKey($city)] = $city;
    }

    $index = [];
    foreach ($geo['features'] as $feature) {
        $nameRaw = (string) (($feature['properties']['city_name'] ?? ''));
        $key = normalizeCityKey($nameRaw);
        if (!isset($cityByKey[$key])) {
            continue;
        }
        $canonicalCity = $cityByKey[$key];
        $polys = flattenCityPolygons($feature['geometry'] ?? []);
        if (!empty($polys)) {
            $index[$canonicalCity] = $polys;
        }
    }

    return $index;
}

function mapPointToCity(float $lat, float $lon, array $cityPolygons, array $orderedCities): ?string {
    foreach ($orderedCities as $city) {
        $polys = $cityPolygons[$city] ?? [];
        foreach ($polys as $poly) {
            if (polygonContainsPoint($poly, $lon, $lat)) {
                return $city;
            }
        }
    }
    return null;
}

function ensureSpatialMapTables(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS observation_city_map (
        observation_id VARCHAR(255) NOT NULL,
        area VARCHAR(100) NOT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (observation_id),
        KEY idx_ocm_area (area)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS city_grid_map (
        lat DECIMAL(10,8) NOT NULL,
        lon DECIMAL(11,8) NOT NULL,
        area VARCHAR(100) NOT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (lat, lon),
        KEY idx_cgm_area (area)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS spatial_mapping_meta (
        meta_key VARCHAR(120) NOT NULL,
        meta_value VARCHAR(255) NOT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (meta_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function refreshSpatialMaps(PDO $pdo, array $cities, int $maxAgeSeconds = 86400): array {
    ensureSpatialMapTables($pdo);

    $sourceObsCount = (int) $pdo->query('SELECT COUNT(*) FROM raw_bird_observation WHERE year IS NOT NULL AND species_id IS NOT NULL')->fetchColumn();
    $sourceGridCount = (int) $pdo->query('SELECT COUNT(*) FROM (
        SELECT latitude AS lat, longitude AS lon FROM viirs
        UNION
        SELECT latitude AS lat, longitude AS lon FROM ndvi
        UNION
        SELECT latitude AS lat, longitude AS lon FROM land_temp
        UNION
        SELECT latitude AS lat, longitude AS lon FROM precip
    ) g')->fetchColumn();

    $metaStmt = $pdo->query("SELECT meta_key, meta_value FROM spatial_mapping_meta WHERE meta_key IN ('source_obs_count', 'source_grid_count')");
    $metaRows = $metaStmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
    $metaObs = isset($metaRows['source_obs_count']) ? (int) $metaRows['source_obs_count'] : -1;
    $metaGrid = isset($metaRows['source_grid_count']) ? (int) $metaRows['source_grid_count'] : -1;

    $mappedObsCount = (int) $pdo->query('SELECT COUNT(*) FROM observation_city_map')->fetchColumn();
    $mappedGridCount = (int) $pdo->query('SELECT COUNT(*) FROM city_grid_map')->fetchColumn();
    $latestMapTime = $pdo->query("SELECT MAX(updated_at) FROM (
        SELECT MAX(updated_at) AS updated_at FROM observation_city_map
        UNION ALL
        SELECT MAX(updated_at) AS updated_at FROM city_grid_map
    ) t")->fetchColumn();
    $latestTs = $latestMapTime ? strtotime((string) $latestMapTime . ' UTC') : false;

    $needsRefresh = ($mappedObsCount === 0 || $mappedGridCount === 0);
    if (!$needsRefresh && ($metaObs !== $sourceObsCount || $metaGrid !== $sourceGridCount)) {
        $needsRefresh = true;
    }
    if (!$needsRefresh && ($latestTs === false || (time() - $latestTs) > $maxAgeSeconds)) {
        $needsRefresh = true;
    }

    if ($needsRefresh) {
        $cityPolygons = loadCityPolygons($cities);
        if (empty($cityPolygons)) {
            throw new RuntimeException('City boundary polygons could not be loaded for spatial mapping.');
        }

        $pdo->beginTransaction();
        try {
            $pdo->exec('DELETE FROM observation_city_map');
            $pdo->exec('DELETE FROM city_grid_map');

            $insertObs = $pdo->prepare('INSERT INTO observation_city_map (observation_id, area) VALUES (:id, :area)');
            $obsStmt = $pdo->query('SELECT observation_id, latitude, longitude FROM raw_bird_observation WHERE year IS NOT NULL AND species_id IS NOT NULL');
            while ($row = $obsStmt->fetch(PDO::FETCH_ASSOC)) {
                $lat = (float) ($row['latitude'] ?? 0);
                $lon = (float) ($row['longitude'] ?? 0);
                $area = mapPointToCity($lat, $lon, $cityPolygons, $cities);
                if ($area === null) {
                    continue;
                }
                $insertObs->execute([
                    ':id' => (string) $row['observation_id'],
                    ':area' => $area,
                ]);
            }

            $insertGrid = $pdo->prepare('INSERT INTO city_grid_map (lat, lon, area) VALUES (:lat, :lon, :area)');
            $gridStmt = $pdo->query('SELECT lat, lon FROM (
                SELECT latitude AS lat, longitude AS lon FROM viirs
                UNION
                SELECT latitude AS lat, longitude AS lon FROM ndvi
                UNION
                SELECT latitude AS lat, longitude AS lon FROM land_temp
                UNION
                SELECT latitude AS lat, longitude AS lon FROM precip
            ) g');
            while ($row = $gridStmt->fetch(PDO::FETCH_ASSOC)) {
                $lat = (float) ($row['lat'] ?? 0);
                $lon = (float) ($row['lon'] ?? 0);
                $area = mapPointToCity($lat, $lon, $cityPolygons, $cities);
                if ($area === null) {
                    continue;
                }
                $insertGrid->execute([
                    ':lat' => $lat,
                    ':lon' => $lon,
                    ':area' => $area,
                ]);
            }

            $metaUpsert = $pdo->prepare('INSERT INTO spatial_mapping_meta (meta_key, meta_value) VALUES (:k, :v) ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)');
            $metaUpsert->execute([':k' => 'source_obs_count', ':v' => (string) $sourceObsCount]);
            $metaUpsert->execute([':k' => 'source_grid_count', ':v' => (string) $sourceGridCount]);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $mappedObsCount = (int) $pdo->query('SELECT COUNT(*) FROM observation_city_map')->fetchColumn();
        $mappedGridCount = (int) $pdo->query('SELECT COUNT(*) FROM city_grid_map')->fetchColumn();
    }

    return [
        'refreshed' => $needsRefresh,
        'source_obs_count' => $sourceObsCount,
        'mapped_obs_count' => $mappedObsCount,
        'source_grid_count' => $sourceGridCount,
        'mapped_grid_count' => $mappedGridCount,
    ];
}

/**
 * Compute Pearson correlation for richness vs one variable.
 */
function pearson(array $row, string $prefix): float {
    $n = (float) ($row['n'] ?? 0);
    if ($n <= 1) {
        return 0.0;
    }

    $sx = (float) ($row['sx'] ?? 0);
    $sx2 = (float) ($row['sx2'] ?? 0);
    $sy = (float) ($row[$prefix . '_sum'] ?? 0);
    $sy2 = (float) ($row[$prefix . '_sum2'] ?? 0);
    $sxy = (float) ($row[$prefix . '_sumxy'] ?? 0);

    $num = ($n * $sxy) - ($sx * $sy);
    $denA = ($n * $sx2) - ($sx * $sx);
    $denB = ($n * $sy2) - ($sy * $sy);
    if ($denA <= 0 || $denB <= 0) {
        return 0.0;
    }

    return round($num / sqrt($denA * $denB), 4);
}

/**
 * Ensure pre-aggregated summary table and index exist.
 */
function ensureSummaryTable(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS ecological_yearly_summary (
        area VARCHAR(100) NOT NULL,
        year INT NOT NULL,
        bird_richness INT NULL,
        viirs_avg DOUBLE NULL,
        ndvi_avg DOUBLE NULL,
        lst_avg DOUBLE NULL,
        precipitation_total DOUBLE NULL,
        data_quality_flags TEXT NULL,
        corrected_fields TEXT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (area, year)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $idxStmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.statistics
        WHERE table_schema = DATABASE()
          AND table_name = 'ecological_yearly_summary'
          AND index_name = 'idx_area_year'");
    $idxStmt->execute();
    $idxExists = (int) $idxStmt->fetchColumn();
    if ($idxExists === 0) {
        $pdo->exec('CREATE INDEX idx_area_year ON ecological_yearly_summary(area, year)');
    }
}

/**
 * Lightweight audit log for data quality checks.
 */
function logDataQuality(array $payload): void {
    $line = '[ecological-report-validation] ' . json_encode($payload, JSON_UNESCAPED_UNICODE);
    error_log($line ?: '[ecological-report-validation] failed-to-encode-log');
}

/**
 * Returns true when summary table should be rebuilt.
 */
function summaryNeedsRefresh(PDO $pdo, int $maxAgeSeconds = 3600): bool {
    $exists = (int) $pdo->query('SELECT COUNT(*) FROM ecological_yearly_summary')->fetchColumn();
    if ($exists === 0) {
        return true;
    }

    $latest = $pdo->query('SELECT MAX(updated_at) FROM ecological_yearly_summary')->fetchColumn();
    if (!$latest) {
        return true;
    }

    $ts = strtotime((string) $latest . ' UTC');
    if ($ts === false) {
        return true;
    }

    return (time() - $ts) > $maxAgeSeconds;
}

function dropTemporarySummaryTables(PDO $pdo): void {
    foreach ([
        'tmp_report_years',
        'tmp_report_richness',
        'tmp_report_env_cell_month',
        'tmp_report_env_cell_year',
        'tmp_report_env_area_year',
    ] as $tableName) {
        $pdo->exec("DROP TEMPORARY TABLE IF EXISTS {$tableName}");
    }
}

/**
 * Build or refresh yearly summary from source tables with scaling corrections.
 */
function refreshSummary(PDO $pdo, array $cities): array {
    $citySql = buildCityInlineSql($cities);

    // Bird richness is the distinct species_id count per city-year.
    // Environmental covariates are rolled up as: cell-month -> cell-year -> area-year.
    // This preserves the LST peak, prevents precipitation duplication, and keeps the plan streamable.
    dropTemporarySummaryTables($pdo);

    try {
        $pdo->exec('CREATE TEMPORARY TABLE tmp_report_years (
            year INT NOT NULL,
            PRIMARY KEY (year)
        ) ENGINE=InnoDB');
        $pdo->exec('INSERT INTO tmp_report_years (year)
            SELECT DISTINCT year
            FROM (
                SELECT year FROM viirs
                UNION
                SELECT year FROM ndvi
                UNION
                SELECT year FROM land_temp
                UNION
                SELECT year FROM precip
                UNION
                SELECT year FROM raw_bird_observation
            ) yrs
            WHERE year IS NOT NULL');

        $pdo->exec('CREATE TEMPORARY TABLE tmp_report_richness (
            area VARCHAR(100) NOT NULL,
            year INT NOT NULL,
            bird_richness INT NULL,
            PRIMARY KEY (area, year)
        ) ENGINE=InnoDB');
        $pdo->exec('INSERT INTO tmp_report_richness (area, year, bird_richness)
            SELECT
                m.area,
                r.year,
                COUNT(DISTINCT r.species_id) AS bird_richness
            FROM raw_bird_observation r
            JOIN observation_city_map m
                ON m.observation_id = r.observation_id
            JOIN species_masterlist sm
                ON sm.species_id = r.species_id
            WHERE r.year IS NOT NULL
              AND r.species_id IS NOT NULL
            GROUP BY m.area, r.year');

        $pdo->exec('CREATE TEMPORARY TABLE tmp_report_env_cell_month (
            area VARCHAR(100) NOT NULL,
            year INT NOT NULL,
            month INT NOT NULL,
            lat DECIMAL(10,8) NOT NULL,
            lon DECIMAL(11,8) NOT NULL,
            viirs_month DOUBLE NULL,
            ndvi_month DOUBLE NULL,
            lst_month DOUBLE NULL,
            precip_month DOUBLE NULL,
            PRIMARY KEY (area, year, month, lat, lon),
            KEY idx_env_cell_month_area_year (area, year),
            KEY idx_env_cell_month_lat_lon (lat, lon)
        ) ENGINE=InnoDB');
        $pdo->exec('INSERT INTO tmp_report_env_cell_month (area, year, month, lat, lon, viirs_month, ndvi_month, lst_month, precip_month)
            SELECT
                cells.area,
                p.year,
                p.month,
                p.latitude,
                p.longitude,
                AVG(NULLIF(v.viirs_avg_rad, 0)) AS viirs_month,
                AVG(
                    CASE
                        WHEN ABS(n.ndvi) > 1 THEN n.ndvi / 10000
                        WHEN n.ndvi = 0 THEN NULL
                        ELSE n.ndvi
                    END
                ) AS ndvi_month,
                MAX(
                    CASE
                        WHEN lt.lst_day > 100 THEN (lt.lst_day * 0.02) - 273.15
                        WHEN lt.lst_day <= 0 THEN NULL
                        ELSE lt.lst_day
                    END
                ) AS lst_month,
                AVG(
                    CASE
                        WHEN p.precip_mm < 0 THEN NULL
                        ELSE p.precip_mm
                    END
                ) AS precip_month
            FROM precip p
            JOIN city_grid_map cells
                ON p.latitude = cells.lat
               AND p.longitude = cells.lon
            LEFT JOIN viirs v
                ON v.year = p.year
               AND v.month = p.month
               AND v.latitude = p.latitude
               AND v.longitude = p.longitude
            LEFT JOIN ndvi n
                ON n.year = p.year
               AND n.month = p.month
               AND n.latitude = p.latitude
               AND n.longitude = p.longitude
            LEFT JOIN land_temp lt
                ON lt.year = p.year
               AND lt.month = p.month
               AND lt.latitude = p.latitude
               AND lt.longitude = p.longitude
            WHERE NOT (
                COALESCE(v.viirs_avg_rad, 0) = 0
                AND COALESCE(n.ndvi, 0) = 0
                AND COALESCE(lt.lst_day, 0) = 0
                AND COALESCE(p.precip_mm, 0) = 0
            )
            GROUP BY cells.area, p.year, p.month, p.latitude, p.longitude');

        $pdo->exec('CREATE TEMPORARY TABLE tmp_report_env_cell_year (
            area VARCHAR(100) NOT NULL,
            year INT NOT NULL,
            lat DECIMAL(10,8) NOT NULL,
            lon DECIMAL(11,8) NOT NULL,
            viirs_cell_year DOUBLE NULL,
            ndvi_cell_year DOUBLE NULL,
            lst_cell_year DOUBLE NULL,
            precip_cell_year DOUBLE NULL,
            PRIMARY KEY (area, year, lat, lon),
            KEY idx_env_cell_year_area_year (area, year)
        ) ENGINE=InnoDB');
        $pdo->exec('INSERT INTO tmp_report_env_cell_year (area, year, lat, lon, viirs_cell_year, ndvi_cell_year, lst_cell_year, precip_cell_year)
            SELECT
                area,
                year,
                lat,
                lon,
                AVG(viirs_month) AS viirs_cell_year,
                AVG(ndvi_month) AS ndvi_cell_year,
                MAX(lst_month) AS lst_cell_year,
                SUM(precip_month) AS precip_cell_year
            FROM tmp_report_env_cell_month
            GROUP BY area, year, lat, lon');

        $pdo->exec('CREATE TEMPORARY TABLE tmp_report_env_area_year (
            area VARCHAR(100) NOT NULL,
            year INT NOT NULL,
            viirs_avg DOUBLE NULL,
            ndvi_avg DOUBLE NULL,
            lst_avg DOUBLE NULL,
            precipitation_total DOUBLE NULL,
            PRIMARY KEY (area, year)
        ) ENGINE=InnoDB');
        $pdo->exec('INSERT INTO tmp_report_env_area_year (area, year, viirs_avg, ndvi_avg, lst_avg, precipitation_total)
            SELECT
                area,
                year,
                AVG(viirs_cell_year) AS viirs_avg,
                AVG(ndvi_cell_year) AS ndvi_avg,
                MAX(lst_cell_year) AS lst_avg,
                AVG(precip_cell_year) AS precipitation_total
            FROM tmp_report_env_cell_year
            GROUP BY area, year');

        $summarySql = "REPLACE INTO ecological_yearly_summary (
                area,
                year,
                bird_richness,
                viirs_avg,
                ndvi_avg,
                lst_avg,
                precipitation_total,
                data_quality_flags,
                corrected_fields,
                updated_at
            )
            SELECT
                c.area,
                y.year,
                r.bird_richness,
                e.viirs_avg,
                e.ndvi_avg,
                e.lst_avg,
                e.precipitation_total,
                NULL,
                NULL,
                CURRENT_TIMESTAMP
            FROM ({$citySql}) c
            CROSS JOIN tmp_report_years y
            LEFT JOIN tmp_report_richness r
                ON r.area = c.area
               AND r.year = y.year
            LEFT JOIN tmp_report_env_area_year e
                ON e.area = c.area
               AND e.year = y.year";

        $pdo->exec($summarySql);
    } finally {
        dropTemporarySummaryTables($pdo);
    }

    $scalingChecks = $pdo->query("SELECT
            SUM(CASE WHEN ABS(ndvi_avg) > 1 THEN 1 ELSE 0 END) AS ndvi_out_of_range,
            SUM(CASE WHEN lst_avg < 10 OR lst_avg > 60 THEN 1 ELSE 0 END) AS lst_out_of_range,
            SUM(CASE WHEN precipitation_total < 100 AND precipitation_total IS NOT NULL THEN 1 ELSE 0 END) AS precip_low,
            SUM(CASE WHEN precipitation_total > 6000 AND precipitation_total IS NOT NULL THEN 1 ELSE 0 END) AS precip_high
        FROM ecological_yearly_summary")->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'ndvi_out_of_range' => (int) ($scalingChecks['ndvi_out_of_range'] ?? 0),
        'lst_out_of_range' => (int) ($scalingChecks['lst_out_of_range'] ?? 0),
        'precip_low' => (int) ($scalingChecks['precip_low'] ?? 0),
        'precip_high' => (int) ($scalingChecks['precip_high'] ?? 0),
    ];
}

/**
 * File-cache response payload for fast repeated reads.
 */
function cacheGet(string $cachePath, int $ttl): ?array {
    if ($ttl <= 0) {
        return null;
    }
    if (!is_file($cachePath)) {
        return null;
    }
    $mtime = filemtime($cachePath);
    if ($mtime === false || (time() - $mtime) > $ttl) {
        return null;
    }

    $raw = file_get_contents($cachePath);
    if ($raw === false) {
        return null;
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return null;
    }
    return $decoded;
}

function cachePut(string $cachePath, array $payload): void {
    $json = json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    if ($json === false) {
        return;
    }
    @file_put_contents($cachePath, $json, LOCK_EX);
}

function fetchSnapshotSpeciesDistributions(PDO $pdo, string $selectedArea, int $snapshotYear, int $snapshotMonth): array {
    $areaClause = '';
    if ($selectedArea !== 'All Areas') {
        $areaClause = ' AND m.area = :area ';
    }

    $migrationSql = "SELECT
            LOWER(TRIM(sm.migratory_status)) AS category,
            COUNT(DISTINCT r.species_id) AS species_count
        FROM raw_bird_observation r
        JOIN species_masterlist sm
            ON sm.species_id = r.species_id
        JOIN observation_city_map m
            ON m.observation_id = r.observation_id
        WHERE r.year = :snapshot_year
          AND r.month = :snapshot_month
          AND r.species_id IS NOT NULL
          {$areaClause}
        GROUP BY category";

    $lightSql = "SELECT
            LOWER(TRIM(sm.light_tolerance)) AS category,
            COUNT(DISTINCT r.species_id) AS species_count
        FROM raw_bird_observation r
        JOIN species_masterlist sm
            ON sm.species_id = r.species_id
        JOIN observation_city_map m
            ON m.observation_id = r.observation_id
        WHERE r.year = :snapshot_year
          AND r.month = :snapshot_month
          AND r.species_id IS NOT NULL
          {$areaClause}
        GROUP BY category";

    $params = [
        ':snapshot_year' => $snapshotYear,
        ':snapshot_month' => $snapshotMonth,
    ];
    if ($selectedArea !== 'All Areas') {
        $params[':area'] = $selectedArea;
    }

    $migStmt = $pdo->prepare($migrationSql);
    foreach ($params as $k => $v) {
        $migStmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $migStmt->execute();
    $migrationRows = $migStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $lightStmt = $pdo->prepare($lightSql);
    foreach ($params as $k => $v) {
        $lightStmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $lightStmt->execute();
    $lightRows = $lightStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $migrationMap = [
        'migratory' => 0,
        'resident' => 0,
        'unclassified' => 0,
    ];
    foreach ($migrationRows as $row) {
        $cat = (string) ($row['category'] ?? '');
        $count = (int) ($row['species_count'] ?? 0);
        if ($cat === 'migratory') {
            $migrationMap['migratory'] += $count;
        } elseif ($cat === 'resident') {
            $migrationMap['resident'] += $count;
        } else {
            $migrationMap['unclassified'] += $count;
        }
    }

    $lightMap = [
        'sensitive' => 0,
        'tolerant' => 0,
        'unclassified' => 0,
    ];
    foreach ($lightRows as $row) {
        $cat = (string) ($row['category'] ?? '');
        $count = (int) ($row['species_count'] ?? 0);
        if ($cat === 'sensitive') {
            $lightMap['sensitive'] += $count;
        } elseif ($cat === 'tolerant') {
            $lightMap['tolerant'] += $count;
        } else {
            $lightMap['unclassified'] += $count;
        }
    }

    return [
        'migration_status' => [
            'labels' => ['Migratory', 'Resident', 'Unclassified'],
            'data' => [
                $migrationMap['migratory'],
                $migrationMap['resident'],
                $migrationMap['unclassified'],
            ],
            'total_species' => array_sum($migrationMap),
        ],
        'light_tolerance' => [
            'labels' => ['Sensitive', 'Tolerant', 'Unclassified'],
            'data' => [
                $lightMap['sensitive'],
                $lightMap['tolerant'],
                $lightMap['unclassified'],
            ],
            'total_species' => array_sum($lightMap),
        ],
    ];
}

function fetchSnapshotScatterData(PDO $pdo, array $cities, string $selectedArea, int $snapshotYear, int $snapshotMonth): array {
    $areaListSql = "'" . implode("','", array_map(static fn($c) => str_replace("'", "''", $c), $cities)) . "'";
    $areaExpr = "COALESCE(NULLIF(TRIM(m.area), ''), NULLIF(TRIM(cg.area), ''))";
    $richnessSql = "SELECT
            {$areaExpr} AS area,
            COUNT(DISTINCT r.species_id) AS bird_richness
        FROM raw_bird_observation r
        JOIN species_masterlist sm
            ON sm.species_id = r.species_id
        LEFT JOIN observation_city_map m
            ON m.observation_id = r.observation_id
        LEFT JOIN city_grid_map cg
            ON ABS(cg.lat - r.latitude) < 0.000001
           AND ABS(cg.lon - r.longitude) < 0.000001
        WHERE r.year = :snapshot_year
          AND r.month = :snapshot_month
          AND r.species_id IS NOT NULL
          AND {$areaExpr} IN ({$areaListSql})";

    $params = [
        ':snapshot_year' => $snapshotYear,
        ':snapshot_month' => $snapshotMonth,
    ];

    if ($selectedArea !== 'All Areas') {
        $richnessSql .= "\n          AND {$areaExpr} = :selected_area";
        $params[':selected_area'] = $selectedArea;
    }

    $richnessSql .= "\n        GROUP BY {$areaExpr}
        HAVING bird_richness > 0
        ORDER BY {$areaExpr} ASC";

    $envSql = "SELECT
            cells.area AS area,
            AVG(NULLIF(v.viirs_avg_rad, 0)) AS viirs,
            AVG(
                CASE
                    WHEN n.ndvi = 0 THEN NULL
                    WHEN ABS(n.ndvi) > 1 THEN n.ndvi / 10000
                    ELSE n.ndvi
                END
            ) AS ndvi,
            AVG(
                CASE
                    WHEN lt.lst_day > 100 THEN (lt.lst_day * 0.02) - 273.15
                    WHEN lt.lst_day > 0 THEN lt.lst_day
                    ELSE NULL
                END
            ) AS lst,
            AVG(CASE WHEN p.precip_mm >= 0 THEN p.precip_mm ELSE NULL END) AS precipitation
        FROM city_grid_map cells
        LEFT JOIN viirs v
                ON v.year = :env_year_v
              AND v.month = :env_month_v
           AND v.latitude = cells.lat
           AND v.longitude = cells.lon
        LEFT JOIN ndvi n
                ON n.year = :env_year_n
              AND n.month = :env_month_n
           AND n.latitude = cells.lat
           AND n.longitude = cells.lon
        LEFT JOIN land_temp lt
                ON lt.year = :env_year_lt
              AND lt.month = :env_month_lt
           AND lt.latitude = cells.lat
           AND lt.longitude = cells.lon
        LEFT JOIN precip p
                ON p.year = :env_year_p
              AND p.month = :env_month_p
           AND p.latitude = cells.lat
           AND p.longitude = cells.lon
        WHERE cells.area IN ({$areaListSql})";

    if ($selectedArea !== 'All Areas') {
        $envSql .= "\n          AND cells.area = :selected_area";
    }

    $envSql .= "\n        GROUP BY cells.area";

    $stmt = $pdo->prepare($richnessSql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->execute();
    $richnessRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $envStmt = $pdo->prepare($envSql);
    $envStmt->bindValue(':env_year_v', $snapshotYear, PDO::PARAM_INT);
    $envStmt->bindValue(':env_month_v', $snapshotMonth, PDO::PARAM_INT);
    $envStmt->bindValue(':env_year_n', $snapshotYear, PDO::PARAM_INT);
    $envStmt->bindValue(':env_month_n', $snapshotMonth, PDO::PARAM_INT);
    $envStmt->bindValue(':env_year_lt', $snapshotYear, PDO::PARAM_INT);
    $envStmt->bindValue(':env_month_lt', $snapshotMonth, PDO::PARAM_INT);
    $envStmt->bindValue(':env_year_p', $snapshotYear, PDO::PARAM_INT);
    $envStmt->bindValue(':env_month_p', $snapshotMonth, PDO::PARAM_INT);
    if ($selectedArea !== 'All Areas') {
        $envStmt->bindValue(':selected_area', $selectedArea, PDO::PARAM_STR);
    }
    $envStmt->execute();
    $envRows = $envStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $envByArea = [];
    foreach ($envRows as $envRow) {
        $area = trim((string) ($envRow['area'] ?? ''));
        if ($area === '') {
            continue;
        }
        $envByArea[$area] = [
            'viirs' => isset($envRow['viirs']) ? (float) $envRow['viirs'] : null,
            'ndvi' => isset($envRow['ndvi']) ? (float) $envRow['ndvi'] : null,
            'lst' => isset($envRow['lst']) ? (float) $envRow['lst'] : null,
            'precipitation' => isset($envRow['precipitation']) ? (float) $envRow['precipitation'] : null,
        ];
    }

    $monthlyMediansByArea = fetchAreaMonthlyEnvMedians($pdo, $cities, $selectedArea, $snapshotMonth);

    $richnessByArea = [];
    foreach ($richnessRows as $row) {
        $area = trim((string) ($row['area'] ?? ''));
        if ($area === '') {
            continue;
        }
        $richnessByArea[$area] = isset($row['bird_richness']) ? (float) $row['bird_richness'] : 0.0;
    }

    $targetAreas = $selectedArea === 'All Areas' ? $cities : [$selectedArea];

    $points = [
        'light_richness' => [],
        'ndvi_richness' => [],
        'lst_richness' => [],
        'precipitation_richness' => [],
    ];
    foreach ($targetAreas as $area) {
        $area = trim((string) $area);
        if ($area === '') {
            continue;
        }

        $richness = isset($richnessByArea[$area]) ? (float) $richnessByArea[$area] : 0.0;

        $env = $envByArea[$area] ?? [
            'viirs' => null,
            'ndvi' => null,
            'lst' => null,
            'precipitation' => null,
        ];
        $med = $monthlyMediansByArea[$area] ?? [];
        if ($env['viirs'] === null && isset($med['viirs'])) {
            $env['viirs'] = (float) $med['viirs'];
        }
        if ($env['ndvi'] === null && isset($med['ndvi'])) {
            $env['ndvi'] = (float) $med['ndvi'];
        }
        if ($env['lst'] === null && isset($med['lst'])) {
            $env['lst'] = (float) $med['lst'];
        }
        if ($env['precipitation'] === null && isset($med['precipitation'])) {
            $env['precipitation'] = (float) $med['precipitation'];
        }

        if ($env['viirs'] !== null) {
            $points['light_richness'][] = [
                'x' => round((float) $env['viirs'], 4),
                'y' => round((float) $richness, 2),
                'area' => $area,
                'site_name' => $area,
            ];
        }
        if ($env['ndvi'] !== null) {
            $points['ndvi_richness'][] = [
                'x' => round((float) $env['ndvi'], 4),
                'y' => round((float) $richness, 2),
                'area' => $area,
                'site_name' => $area,
            ];
        }
        if ($env['lst'] !== null) {
            $points['lst_richness'][] = [
                'x' => round((float) $env['lst'], 4),
                'y' => round((float) $richness, 2),
                'area' => $area,
                'site_name' => $area,
            ];
        }
        if ($env['precipitation'] !== null) {
            $points['precipitation_richness'][] = [
                'x' => round((float) $env['precipitation'], 4),
                'y' => round((float) $richness, 2),
                'area' => $area,
                'site_name' => $area,
            ];
        }
    }

    return [
        'light_richness' => [
            'x_label' => 'ALAN (nW/cm²/sr)',
            'y_label' => 'Bird Richness (species)',
            'points' => $points['light_richness'],
        ],
        'ndvi_richness' => [
            'x_label' => 'NDVI (index)',
            'y_label' => 'Bird Richness (species)',
            'points' => $points['ndvi_richness'],
        ],
        'lst_richness' => [
            'x_label' => 'LST (°C)',
            'y_label' => 'Bird Richness (species)',
            'points' => $points['lst_richness'],
        ],
        'precipitation_richness' => [
            'x_label' => 'Precipitation (mm)',
            'y_label' => 'Bird Richness (species)',
            'points' => $points['precipitation_richness'],
        ],
    ];
}

function medianValue(array $values): ?float {
    if (empty($values)) {
        return null;
    }
    sort($values, SORT_NUMERIC);
    $n = count($values);
    $mid = intdiv($n, 2);
    if (($n % 2) === 1) {
        return (float) $values[$mid];
    }
    return ((float) $values[$mid - 1] + (float) $values[$mid]) / 2.0;
}

function fetchAreaMonthlyEnvMedians(PDO $pdo, array $cities, string $selectedArea, int $month): array {
    $areaListSql = "'" . implode("','", array_map(static fn($c) => str_replace("'", "''", $c), $cities)) . "'";
    $filters = '';
    if ($selectedArea !== 'All Areas') {
        $filters = "\n          AND cells.area = :selected_area";
    }

    $queries = [
        'viirs' => "SELECT cells.area AS area, v.year AS yr, AVG(NULLIF(v.viirs_avg_rad, 0)) AS value
            FROM city_grid_map cells
            JOIN viirs v
              ON v.latitude = cells.lat
             AND v.longitude = cells.lon
             AND v.month = :month
            WHERE cells.area IN ({$areaListSql}){$filters}
            GROUP BY cells.area, v.year",
        'ndvi' => "SELECT cells.area AS area, n.year AS yr, AVG(
                    CASE
                        WHEN n.ndvi = 0 THEN NULL
                        WHEN ABS(n.ndvi) > 1 THEN n.ndvi / 10000
                        ELSE n.ndvi
                    END
                ) AS value
            FROM city_grid_map cells
            JOIN ndvi n
              ON n.latitude = cells.lat
             AND n.longitude = cells.lon
             AND n.month = :month
            WHERE cells.area IN ({$areaListSql}){$filters}
            GROUP BY cells.area, n.year",
        'lst' => "SELECT cells.area AS area, lt.year AS yr, AVG(
                    CASE
                        WHEN lt.lst_day > 100 THEN (lt.lst_day * 0.02) - 273.15
                        WHEN lt.lst_day > 0 THEN lt.lst_day
                        ELSE NULL
                    END
                ) AS value
            FROM city_grid_map cells
            JOIN land_temp lt
              ON lt.latitude = cells.lat
             AND lt.longitude = cells.lon
             AND lt.month = :month
            WHERE cells.area IN ({$areaListSql}){$filters}
            GROUP BY cells.area, lt.year",
        'precipitation' => "SELECT cells.area AS area, p.year AS yr, AVG(CASE WHEN p.precip_mm >= 0 THEN p.precip_mm ELSE NULL END) AS value
            FROM city_grid_map cells
            JOIN precip p
              ON p.latitude = cells.lat
             AND p.longitude = cells.lon
             AND p.month = :month
            WHERE cells.area IN ({$areaListSql}){$filters}
            GROUP BY cells.area, p.year",
    ];

    $values = [];
    foreach ($queries as $key => $sql) {
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':month', $month, PDO::PARAM_INT);
        if ($selectedArea !== 'All Areas') {
            $stmt->bindValue(':selected_area', $selectedArea, PDO::PARAM_STR);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $row) {
            $area = trim((string) ($row['area'] ?? ''));
            if ($area === '' || !isset($row['value']) || $row['value'] === null) {
                continue;
            }
            $values[$area][$key][] = (float) $row['value'];
        }
    }

    $medians = [];
    foreach ($values as $area => $byVar) {
        foreach (['viirs', 'ndvi', 'lst', 'precipitation'] as $k) {
            $m = medianValue($byVar[$k] ?? []);
            if ($m !== null) {
                $medians[$area][$k] = $m;
            }
        }
    }

    return $medians;
}

function emptySnapshotScatterData(): array {
    return [
        'light_richness' => [
            'x_label' => 'ALAN (nW/cm²/sr)',
            'y_label' => 'Bird Richness (species)',
            'points' => [],
        ],
        'ndvi_richness' => [
            'x_label' => 'NDVI (index)',
            'y_label' => 'Bird Richness (species)',
            'points' => [],
        ],
        'lst_richness' => [
            'x_label' => 'LST (°C)',
            'y_label' => 'Bird Richness (species)',
            'points' => [],
        ],
        'precipitation_richness' => [
            'x_label' => 'Precipitation (mm)',
            'y_label' => 'Bird Richness (species)',
            'points' => [],
        ],
    ];
}

function fetchTopSitesRichnessData(PDO $pdo, array $cities, string $selectedArea, int $snapshotYear, int $snapshotMonth, int $limit = 10): array {
    $latestStmt = $pdo->query("SELECT year, month
        FROM raw_bird_observation
        WHERE year IS NOT NULL
          AND month IS NOT NULL
          AND species_id IS NOT NULL
        ORDER BY year DESC, month DESC
        LIMIT 1");
    $latestRow = $latestStmt ? ($latestStmt->fetch(PDO::FETCH_ASSOC) ?: []) : [];
    $snapshotYear = (int) ($latestRow['year'] ?? $snapshotYear);
    $snapshotMonth = (int) ($latestRow['month'] ?? $snapshotMonth);

    $sql = "SELECT
            COALESCE(NULLIF(TRIM(r.site_name), ''), 'Unknown Site') AS site_name,
            COUNT(DISTINCT r.species_id) AS bird_richness,
            AVG(r.latitude) AS site_lat,
            AVG(r.longitude) AS site_lon
        FROM raw_bird_observation r
        JOIN species_masterlist sm
            ON sm.species_id = r.species_id
        WHERE r.year = :snapshot_year
          AND r.month = :snapshot_month
          AND r.species_id IS NOT NULL
        GROUP BY site_name
        HAVING bird_richness > 0
        ORDER BY bird_richness DESC, site_name ASC";

    $params = [
        ':snapshot_year' => $snapshotYear,
        ':snapshot_month' => $snapshotMonth,
    ];

    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $labels = [];
    $values = [];
    $details = [];
    $cityPolygons = loadCityPolygons($cities);
    $resolved = [];

    foreach ($rows as $row) {
        $siteName = trim((string) ($row['site_name'] ?? ''));
        if ($siteName === '') {
            continue;
        }

        $lat = isset($row['site_lat']) ? (float) $row['site_lat'] : null;
        $lon = isset($row['site_lon']) ? (float) $row['site_lon'] : null;
        if ($lat === null || $lon === null || empty($cityPolygons)) {
            continue;
        }

        $area = mapPointToCity($lat, $lon, $cityPolygons, $cities);
        if ($area === null) {
            continue;
        }

        $resolved[] = [
            'site_name' => $siteName,
            'area' => $area,
            'bird_richness' => (int) ($row['bird_richness'] ?? 0),
        ];
    }

    usort($resolved, static function (array $a, array $b): int {
        if ($a['bird_richness'] === $b['bird_richness']) {
            return strcmp((string) $a['site_name'], (string) $b['site_name']);
        }
        return $b['bird_richness'] <=> $a['bird_richness'];
    });

    if ($limit > 0 && count($resolved) > $limit) {
        $resolved = array_slice($resolved, 0, $limit);
    }

    $rank = 1;
    foreach ($resolved as $row) {
        $siteName = (string) $row['site_name'];
        $area = (string) $row['area'];
        $richness = (int) ($row['bird_richness'] ?? 0);
        $labels[] = $siteName;
        $values[] = $richness;
        $details[] = [
            'rank' => $rank,
            'site_name' => $siteName,
            'area' => $area,
            'bird_richness' => $richness,
        ];
        $rank++;
    }

    return [
        'labels' => $labels,
        'data' => $values,
        'rows' => $details,
        'total_sites' => count($resolved),
        'selected_area' => 'All Areas',
        'snapshot_year' => $snapshotYear,
        'snapshot_month' => $snapshotMonth,
    ];
}

function fetchDiagnosticsYearlySeries(PDO $pdo, string $selectedArea, int $startYear, int $endYear): array {
    $envByYear = [];
    $classByYear = [];

    if ($selectedArea === 'All Areas') {
        $envStmt = $pdo->prepare("SELECT
                year,
                AVG(ndvi_avg) AS ndvi,
                AVG(lst_avg) AS lst_day,
                AVG(viirs_avg) AS viirs_avg_rad,
                AVG(precipitation_total) AS monthly_precip_mm
            FROM ecological_yearly_summary
            WHERE year BETWEEN :start_year AND :end_year
            GROUP BY year
            ORDER BY year ASC");
        $envStmt->bindValue(':start_year', $startYear, PDO::PARAM_INT);
        $envStmt->bindValue(':end_year', $endYear, PDO::PARAM_INT);
        $envStmt->execute();

        $classStmt = $pdo->prepare("SELECT
                r.year,
                COUNT(DISTINCT CASE WHEN LOWER(TRIM(sm.light_tolerance)) = 'tolerant' THEN r.species_id END) AS tolerant_count,
                COUNT(DISTINCT CASE WHEN LOWER(TRIM(sm.light_tolerance)) = 'sensitive' THEN r.species_id END) AS sensitive_count,
                COUNT(DISTINCT CASE WHEN LOWER(TRIM(sm.migratory_status)) = 'resident' THEN r.species_id END) AS resident_count,
                COUNT(DISTINCT CASE WHEN LOWER(TRIM(sm.migratory_status)) = 'migratory' THEN r.species_id END) AS migrant_count
            FROM raw_bird_observation r
            JOIN species_masterlist sm
                ON sm.species_id = r.species_id
            JOIN observation_city_map m
                ON m.observation_id = r.observation_id
            WHERE r.year BETWEEN :start_year AND :end_year
              AND r.species_id IS NOT NULL
            GROUP BY r.year
            ORDER BY r.year ASC");
        $classStmt->bindValue(':start_year', $startYear, PDO::PARAM_INT);
        $classStmt->bindValue(':end_year', $endYear, PDO::PARAM_INT);
        $classStmt->execute();
    } else {
        $envStmt = $pdo->prepare("SELECT
                year,
                ndvi_avg AS ndvi,
                lst_avg AS lst_day,
                viirs_avg AS viirs_avg_rad,
                precipitation_total AS monthly_precip_mm
            FROM ecological_yearly_summary
            WHERE area = :area
              AND year BETWEEN :start_year AND :end_year
            ORDER BY year ASC");
        $envStmt->bindValue(':area', $selectedArea, PDO::PARAM_STR);
        $envStmt->bindValue(':start_year', $startYear, PDO::PARAM_INT);
        $envStmt->bindValue(':end_year', $endYear, PDO::PARAM_INT);
        $envStmt->execute();

        $classStmt = $pdo->prepare("SELECT
                r.year,
                COUNT(DISTINCT CASE WHEN LOWER(TRIM(sm.light_tolerance)) = 'tolerant' THEN r.species_id END) AS tolerant_count,
                COUNT(DISTINCT CASE WHEN LOWER(TRIM(sm.light_tolerance)) = 'sensitive' THEN r.species_id END) AS sensitive_count,
                COUNT(DISTINCT CASE WHEN LOWER(TRIM(sm.migratory_status)) = 'resident' THEN r.species_id END) AS resident_count,
                COUNT(DISTINCT CASE WHEN LOWER(TRIM(sm.migratory_status)) = 'migratory' THEN r.species_id END) AS migrant_count
            FROM raw_bird_observation r
            JOIN species_masterlist sm
                ON sm.species_id = r.species_id
            JOIN observation_city_map m
                ON m.observation_id = r.observation_id
            WHERE m.area = :area
              AND r.year BETWEEN :start_year AND :end_year
              AND r.species_id IS NOT NULL
            GROUP BY r.year
            ORDER BY r.year ASC");
        $classStmt->bindValue(':area', $selectedArea, PDO::PARAM_STR);
        $classStmt->bindValue(':start_year', $startYear, PDO::PARAM_INT);
        $classStmt->bindValue(':end_year', $endYear, PDO::PARAM_INT);
        $classStmt->execute();
    }

    foreach (($envStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
        $year = (int) ($row['year'] ?? 0);
        if ($year <= 0) {
            continue;
        }
        $envByYear[$year] = [
            'ndvi' => isset($row['ndvi']) ? (float) $row['ndvi'] : 0.0,
            'lst_day' => isset($row['lst_day']) ? (float) $row['lst_day'] : 0.0,
            'viirs_avg_rad' => isset($row['viirs_avg_rad']) ? (float) $row['viirs_avg_rad'] : 0.0,
            'monthly_precip_mm' => isset($row['monthly_precip_mm']) ? (float) $row['monthly_precip_mm'] : 0.0,
        ];
    }

    foreach (($classStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
        $year = (int) ($row['year'] ?? 0);
        if ($year <= 0) {
            continue;
        }
        $classByYear[$year] = [
            'tolerant' => (float) ($row['tolerant_count'] ?? 0.0),
            'sensitive' => (float) ($row['sensitive_count'] ?? 0.0),
            'resident' => (float) ($row['resident_count'] ?? 0.0),
            'migrant' => (float) ($row['migrant_count'] ?? 0.0),
        ];
    }

    $rows = [];
    for ($y = $startYear; $y <= $endYear; $y++) {
        $env = $envByYear[$y] ?? [
            'ndvi' => 0.0,
            'lst_day' => 0.0,
            'viirs_avg_rad' => 0.0,
            'monthly_precip_mm' => 0.0,
        ];
        $cls = $classByYear[$y] ?? [
            'tolerant' => 0.0,
            'sensitive' => 0.0,
            'resident' => 0.0,
            'migrant' => 0.0,
        ];

        $rows[] = [
            'year' => $y,
            'ndvi' => (float) $env['ndvi'],
            'lst_day' => (float) $env['lst_day'],
            'viirs_avg_rad' => (float) $env['viirs_avg_rad'],
            'monthly_precip_mm' => (float) $env['monthly_precip_mm'],
            'tolerant' => (float) $cls['tolerant'],
            'sensitive' => (float) $cls['sensitive'],
            'resident' => (float) $cls['resident'],
            'migrant' => (float) $cls['migrant'],
        ];
    }

    return $rows;
}

function buildDefaultDiagnosticsPayload(array $years): array {
    $filters = ['all', 'light_sensitive', 'light_tolerant', 'migratory', 'resident'];
    $emptySeries = [];
    foreach ($filters as $filter) {
        $emptySeries[$filter] = [
            'years' => $years,
            'actual' => array_fill(0, count($years), 0.0),
            'predicted' => array_fill(0, count($years), 0.0),
        ];
    }

    $labels = ['Artificial Light', 'NDVI', 'Temperature', 'Precipitation', 'Seasonality', 'Land Cover', 'Historical Species'];
    $emptyImportance = [];
    foreach ($filters as $filter) {
        $emptyImportance[$filter] = [
            'labels' => $labels,
            'values' => array_fill(0, count($labels), 0.0),
        ];
    }

    return [
        'xgboostFeatureImportance' => $emptyImportance,
        'convlstmPredictions' => $emptySeries,
        'ensembleMetrics' => [
            'ensemble_average' => ['rmse' => 0.0, 'mae' => 0.0, 'r2' => 0.0],
            'by_class' => [
                'tolerant' => ['rmse' => 0.0, 'mae' => 0.0, 'r2' => 0.0],
                'sensitive' => ['rmse' => 0.0, 'mae' => 0.0, 'r2' => 0.0],
                'resident' => ['rmse' => 0.0, 'mae' => 0.0, 'r2' => 0.0],
                'migrant' => ['rmse' => 0.0, 'mae' => 0.0, 'r2' => 0.0],
            ],
        ],
    ];
}

function resolveDiagnosticsArtifactPath(string $modelsDir): ?string {
    $candidates = [
        $modelsDir . '/diagnostics_precomputed.json',
        $modelsDir . '/diagnostics_summary.json',
    ];

    $versionsDir = $modelsDir . '/versions';
    if (is_dir($versionsDir)) {
        $entries = array_values(array_filter(scandir($versionsDir) ?: [], static fn($v) => $v !== '.' && $v !== '..'));
        rsort($entries, SORT_STRING);
        foreach ($entries as $entry) {
            $base = $versionsDir . '/' . $entry;
            $candidates[] = $base . '/diagnostics_precomputed.json';
            $candidates[] = $base . '/diagnostics_summary.json';
        }
    }

    foreach ($candidates as $path) {
        if (is_file($path) && is_readable($path)) {
            return $path;
        }
    }
    return null;
}

function normalizeDiagnosticsPayloadShape(array $raw, array $fallback): array {
    if (isset($raw['default']) && is_array($raw['default'])) {
        $raw = $raw['default'];
    }

    return [
        'xgboostFeatureImportance' => (isset($raw['xgboostFeatureImportance']) && is_array($raw['xgboostFeatureImportance']))
            ? $raw['xgboostFeatureImportance']
            : $fallback['xgboostFeatureImportance'],
        'convlstmPredictions' => (isset($raw['convlstmPredictions']) && is_array($raw['convlstmPredictions']))
            ? $raw['convlstmPredictions']
            : $fallback['convlstmPredictions'],
        'ensembleMetrics' => (isset($raw['ensembleMetrics']) && is_array($raw['ensembleMetrics']))
            ? $raw['ensembleMetrics']
            : $fallback['ensembleMetrics'],
        'metricsSource' => (string) ($raw['metricsSource'] ?? ''),
    ];
}

function buildDiagnosticsConvLstmSeries(array $seriesRows, array $sourcePredictions, int $startYear = 2014, int $endYear = 2024): array {
    $years = [];
    for ($year = $startYear; $year <= $endYear; $year++) {
        $years[] = $year;
    }

    $rowsByYear = [];
    foreach ($seriesRows as $row) {
        $year = (int) ($row['year'] ?? 0);
        if ($year >= $startYear && $year <= $endYear) {
            $rowsByYear[$year] = $row;
        }
    }

    $actualByYear = [];
    $predictedByYear = [];
    foreach ($sourcePredictions as $filter => $series) {
        if (!is_array($series)) {
            continue;
        }
        $seriesYears = (isset($series['years']) && is_array($series['years'])) ? $series['years'] : [];
        $seriesActual = (isset($series['actual']) && is_array($series['actual'])) ? $series['actual'] : [];
        $seriesPredicted = (isset($series['predicted']) && is_array($series['predicted'])) ? $series['predicted'] : [];
        foreach ($seriesYears as $index => $yearValue) {
            $year = (int) $yearValue;
            if ($year < $startYear || $year > $endYear) {
                continue;
            }
            if (!isset($actualByYear[$filter])) {
                $actualByYear[$filter] = [];
            }
            if (!isset($predictedByYear[$filter])) {
                $predictedByYear[$filter] = [];
            }
            $actualByYear[$filter][$year] = array_key_exists($index, $seriesActual) ? (float) $seriesActual[$index] : null;

            // ConvLSTM test split is pinned to 2023-2024; keep predicted values scoped to those years.
            if ($year >= 2023 && $year <= 2024) {
                $predictedByYear[$filter][$year] = array_key_exists($index, $seriesPredicted) ? (float) $seriesPredicted[$index] : null;
            }
        }
    }

    $filters = ['all', 'light_sensitive', 'light_tolerant', 'migratory', 'resident'];
    $out = [];
    foreach ($filters as $filter) {
        $actualOut = [];
        $predictedOut = [];
        foreach ($years as $year) {
            $row = $rowsByYear[$year] ?? null;
            $tolerant = (float) ($row['tolerant'] ?? 0.0);
            $sensitive = (float) ($row['sensitive'] ?? 0.0);
            $resident = (float) ($row['resident'] ?? 0.0);
            $migrant = (float) ($row['migrant'] ?? 0.0);

            switch ($filter) {
                case 'light_sensitive':
                    $actualValue = $sensitive;
                    break;
                case 'light_tolerant':
                    $actualValue = $tolerant;
                    break;
                case 'migratory':
                    $actualValue = $migrant;
                    break;
                case 'resident':
                    $actualValue = $resident;
                    break;
                case 'all':
                default:
                    $actualValue = (($tolerant + $sensitive) + ($resident + $migrant)) / 2.0;
                    break;
            }

            if (isset($actualByYear[$filter]) && array_key_exists($year, $actualByYear[$filter])) {
                $actualOut[] = $actualByYear[$filter][$year] === null ? null : round((float) $actualByYear[$filter][$year], 4);
            } else {
                $actualOut[] = round((float) $actualValue, 4);
            }
            $predictedOut[] = (isset($predictedByYear[$filter]) && array_key_exists($year, $predictedByYear[$filter]))
                ? round((float) $predictedByYear[$filter][$year], 4)
                : null;
        }

        $out[$filter] = [
            'years' => $years,
            'actual' => $actualOut,
            'predicted' => $predictedOut,
        ];
    }

    return $out;
}

function normalizeScopeAreaKey(string $area): string {
    $area = strtolower(trim($area));
    $area = preg_replace('/\s+/', ' ', $area);
    return (string) $area;
}

function parseScopeKey(string $key): ?array {
    if (!preg_match('/^(.*):(\d{4})-(\d{4})$/', $key, $m)) {
        return null;
    }

    $area = normalizeScopeAreaKey((string) $m[1]);
    $start = (int) $m[2];
    $end = (int) $m[3];
    if ($start > $end) {
        return null;
    }

    return [
        'raw' => $key,
        'area' => $area,
        'start' => $start,
        'end' => $end,
    ];
}

function resolveBestScopeKey(array $scopeMap, string $area, int $startYear, int $endYear): ?string {
    $areaKey = normalizeScopeAreaKey($area);

    $exact = null;
    $bestContaining = null;
    $bestOverlap = null;
    $bestNearest = null;

    foreach ($scopeMap as $rawKey => $payload) {
        if (!is_string($rawKey) || !is_array($payload)) {
            continue;
        }
        $parsed = parseScopeKey($rawKey);
        if ($parsed === null) {
            continue;
        }
        if ($parsed['area'] !== $areaKey) {
            continue;
        }

        if ($parsed['start'] === $startYear && $parsed['end'] === $endYear) {
            $exact = $parsed['raw'];
            break;
        }

        $contains = ($parsed['start'] <= $startYear && $parsed['end'] >= $endYear);
        $distance = abs($parsed['start'] - $startYear) + abs($parsed['end'] - $endYear);
        $span = $parsed['end'] - $parsed['start'];

        $overlap = max(0, min($parsed['end'], $endYear) - max($parsed['start'], $startYear) + 1);

        if ($contains) {
            if ($bestContaining === null
                || $span < $bestContaining['span']
                || ($span === $bestContaining['span'] && $distance < $bestContaining['distance'])) {
                $bestContaining = [
                    'raw' => $parsed['raw'],
                    'span' => $span,
                    'distance' => $distance,
                ];
            }
            continue;
        }

        if ($overlap > 0) {
            if ($bestOverlap === null
                || $overlap > $bestOverlap['overlap']
                || ($overlap === $bestOverlap['overlap'] && $distance < $bestOverlap['distance'])) {
                $bestOverlap = [
                    'raw' => $parsed['raw'],
                    'overlap' => $overlap,
                    'distance' => $distance,
                ];
            }
            continue;
        }

        if ($bestNearest === null || $distance < $bestNearest['distance']) {
            $bestNearest = [
                'raw' => $parsed['raw'],
                'distance' => $distance,
            ];
        }
    }

    if ($exact !== null) {
        return $exact;
    }
    if ($bestContaining !== null) {
        return $bestContaining['raw'];
    }
    if ($bestOverlap !== null) {
        return $bestOverlap['raw'];
    }
    if ($bestNearest !== null) {
        return $bestNearest['raw'];
    }

    return null;
}

function attachDiagnosticsMeta(array $payload, string $source, string $error = ''): array {
    $payload['_diagnostics_source'] = $source;
    $payload['_diagnostics_error'] = $error;
    return $payload;
}

function runDiagnosticsInferenceScript(array $rows, string $modelsDir): array {
    $scriptPath = __DIR__ . '/diagnostics_inference.py';
    if (!is_file($scriptPath)) {
        return ['success' => false, 'error' => 'Diagnostics inference script not found.'];
    }

    $backendPayload = json_encode([
        'rows' => $rows,
        'model_dir' => $modelsDir,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($backendPayload === false) {
        $backendPayload = '{}';
    }

    $backendUrl = rtrim(PYTHON_BACKEND_URL, '/') . '/diagnostics';
    $backendContext = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => $backendPayload,
            // Diagnostics can be slower than scenario prediction due full historical pass.
            'timeout' => 180,
        ],
    ]);
    $backendRaw = @file_get_contents($backendUrl, false, $backendContext);
    if ($backendRaw !== false) {
        $backendResult = json_decode($backendRaw, true);
        if (is_array($backendResult) && ($backendResult['success'] ?? false)) {
            return ['success' => true, 'payload' => $backendResult];
        }
    }

    $backendError = 'Unknown diagnostics backend error.';
    if (isset($backendResult) && is_array($backendResult)) {
        if (!empty($backendResult['error'])) {
            $backendError = (string) $backendResult['error'];
        } elseif (!empty($backendResult['detail'])) {
            $backendError = (string) $backendResult['detail'];
        }
    } else {
        $lastErr = error_get_last();
        if (is_array($lastErr) && !empty($lastErr['message'])) {
            $backendError = 'Diagnostics backend unreachable: ' . (string) $lastErr['message'];
        }
    }

    $candidates = [
        __DIR__ . '/../.venv/Scripts/python.exe',
        __DIR__ . '/../.venv/bin/python',
        __DIR__ . '/../../.venv/Scripts/python.exe',
        __DIR__ . '/../../.venv/bin/python',
    ];
    if (defined('PYTHON_BIN')) {
        $candidates[] = (string) PYTHON_BIN;
    }
    $candidates[] = 'python';
    $candidates[] = 'python3';

    $pythonBin = '';
    foreach ($candidates as $candidate) {
        if ($candidate === '' || $candidate === null) {
            continue;
        }
        if ($candidate === 'python' || $candidate === 'python3' || is_file($candidate)) {
            $pythonBin = (string) $candidate;
            break;
        }
    }
    if ($pythonBin === '') {
        return ['success' => false, 'error' => 'No usable Python executable found for diagnostics inference.'];
    }

    $payload = json_encode([
        'rows' => $rows,
        'model_dir' => $modelsDir,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($payload === false) {
        $payload = '{}';
    }

    $inputTmp = tempnam(sys_get_temp_dir(), 'diag_in_');
    $outputTmp = tempnam(sys_get_temp_dir(), 'diag_out_');
    if ($inputTmp === false || $outputTmp === false) {
        return ['success' => false, 'error' => 'Failed to allocate diagnostics temporary files.'];
    }

    @file_put_contents($inputTmp, $payload, LOCK_EX);

    $cmd = escapeshellarg($pythonBin)
        . ' ' . escapeshellarg($scriptPath)
        . ' --input-file ' . escapeshellarg($inputTmp)
        . ' --output-file ' . escapeshellarg($outputTmp);

    $logs = [];
    $exitCode = 0;
    $usedExec = false;

    if (function_exists('exec')) {
        $usedExec = true;
        @exec($cmd . ' 2>&1', $logs, $exitCode);
    } else {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($cmd, $descriptorSpec, $pipes, dirname(__DIR__));
        if (!is_resource($process)) {
            @unlink($inputTmp);
            @unlink($outputTmp);
            return ['success' => false, 'error' => 'Failed to start diagnostics inference process.'];
        }
        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }
        $stdout = (isset($pipes[1]) && is_resource($pipes[1])) ? stream_get_contents($pipes[1]) : '';
        if (isset($pipes[1]) && is_resource($pipes[1])) {
            fclose($pipes[1]);
        }
        $stderr = (isset($pipes[2]) && is_resource($pipes[2])) ? stream_get_contents($pipes[2]) : '';
        if (isset($pipes[2]) && is_resource($pipes[2])) {
            fclose($pipes[2]);
        }
        $exitCode = proc_close($process);
        $logsText = trim((string) $stderr . "\n" . (string) $stdout);
        if ($logsText !== '') {
            $logs = preg_split('/\r\n|\r|\n/', $logsText) ?: [];
        }
    }

    $outputJson = @file_get_contents($outputTmp);
    @unlink($inputTmp);
    @unlink($outputTmp);

    if ($exitCode !== 0) {
        $err = trim($backendError . "\n" . implode("\n", $logs));
        if ($err === '') {
            $err = 'Diagnostics inference process exited with code ' . $exitCode . ($usedExec ? ' (exec)' : ' (proc_open)');
        }
        return ['success' => false, 'error' => $err];
    }

    $decoded = json_decode((string) $outputJson, true);
    if (!is_array($decoded)) {
        return ['success' => false, 'error' => 'Invalid diagnostics JSON returned by inference script.'];
    }
    if (!($decoded['success'] ?? false)) {
        return ['success' => false, 'error' => (string) ($decoded['error'] ?? 'Diagnostics inference failed.')];
    }

    return ['success' => true, 'payload' => $decoded];
}

function resolveDiagnosticsModelDir(PDO $pdo, string $defaultModelsDir): array {
    $required = [
        'xgb_tolerant.json',
        'xgb_sensitive.json',
        'xgb_resident.json',
        'xgb_migrant.json',
        'convlstm_classifier.keras',
        'convlstm_regressor.keras',
        'meta_learner.joblib',
    ];

    $hasRequired = static function (string $dir) use ($required): bool {
        if (!is_dir($dir)) {
            return false;
        }
        foreach ($required as $f) {
            if (!is_file($dir . DIRECTORY_SEPARATOR . $f)) {
                return false;
            }
        }
        return true;
    };

    // Default hard fallback: live files under api_models/
    $fallback = [
        'dir' => $defaultModelsDir,
        'source' => 'api_models_fallback',
        'note' => '',
    ];

    if ($hasRequired($defaultModelsDir)) {
        $fallback['note'] = 'Using default api_models directory.';
    } else {
        $fallback['note'] = 'Default api_models directory is missing one or more required model files.';
    }

    // If models table does not exist or query fails, keep fallback.
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'models'");
        $hasModelsTable = $stmt && $stmt->fetchColumn();
        if (!$hasModelsTable) {
            return $fallback;
        }

        $active = $pdo->query("SELECT file_path FROM models WHERE status = 'Active' ORDER BY id DESC LIMIT 1")
            ->fetch(PDO::FETCH_ASSOC);
        if (!$active || empty($active['file_path'])) {
            $fallback['note'] = 'No active model row found in database; using api_models fallback.';
            return $fallback;
        }

        $activeDir = realpath(__DIR__ . '/../' . ltrim((string) $active['file_path'], '/\\'));
        if ($activeDir === false || !$hasRequired($activeDir)) {
            $fallback['note'] = 'Active DB model path is missing required files; using api_models fallback.';
            return $fallback;
        }

        return [
            'dir' => $activeDir,
            'source' => 'db_active_model',
            'note' => 'Using active model bundle from database registry.',
        ];
    } catch (Throwable $e) {
        $fallback['note'] = 'Model registry lookup failed; using api_models fallback.';
        return $fallback;
    }
}

function computeModelDiagnostics(PDO $pdo, string $selectedArea, int $startYear, int $endYear, string $modelsDir): array {
    $diagStartYear = 2014;
    $diagEndYear = 2024;
    $diagArea = 'All Areas';
    $years = [];
    for ($y = $diagStartYear; $y <= $diagEndYear; $y++) {
        $years[] = $y;
    }
    $fallback = buildDefaultDiagnosticsPayload($years);

    $seriesRows = null;
    $seriesError = '';
    $loadSeriesRows = static function () use (&$seriesRows, &$seriesError, $pdo, $diagArea, $diagStartYear, $diagEndYear): array {
        if (is_array($seriesRows)) {
            return $seriesRows;
        }
        try {
            $seriesRows = fetchDiagnosticsYearlySeries($pdo, $diagArea, $diagStartYear, $diagEndYear);
            return $seriesRows;
        } catch (Throwable $e) {
            $seriesError = 'Diagnostics series build failed: ' . $e->getMessage();
            $seriesRows = [];
            for ($y = $diagStartYear; $y <= $diagEndYear; $y++) {
                $seriesRows[] = [
                    'year' => $y,
                    'ndvi' => 0.0,
                    'lst_day' => 0.0,
                    'viirs_avg_rad' => 0.0,
                    'monthly_precip_mm' => 0.0,
                    'tolerant' => 0.0,
                    'sensitive' => 0.0,
                    'resident' => 0.0,
                    'migrant' => 0.0,
                ];
            }
            return $seriesRows;
        }
    };

    $artifactPath = resolveDiagnosticsArtifactPath($modelsDir);
    $artifactError = '';
    if ($artifactPath !== null) {
        $json = file_get_contents($artifactPath);
        if ($json === false || trim($json) === '') {
            $artifactError = 'Diagnostics artifact exists but is empty/unreadable.';
        } else {
            $decoded = json_decode($json, true);
            if (!is_array($decoded)) {
                $artifactError = 'Diagnostics artifact JSON is invalid.';
            } else {
                // Fast path: use top-level diagnostics payload directly when present.
                if (
                    (isset($decoded['xgboostFeatureImportance']) && is_array($decoded['xgboostFeatureImportance']))
                    || (isset($decoded['convlstmPredictions']) && is_array($decoded['convlstmPredictions']))
                    || (isset($decoded['ensembleMetrics']) && is_array($decoded['ensembleMetrics']))
                ) {
                    $normalized = normalizeDiagnosticsPayloadShape($decoded, $fallback);
                    $normalized['convlstmPredictions'] = buildDiagnosticsConvLstmSeries([], (array) ($normalized['convlstmPredictions'] ?? []), $diagStartYear, $diagEndYear);
                    return attachDiagnosticsMeta($normalized, 'precomputed_json_global', '');
                }

                // Optional scoping in artifact: by_scope["<area>:<start>-<end>"] or by_area["<area>"]
                $scopeKey = strtolower(trim($diagArea)) . ':' . $diagStartYear . '-' . $diagEndYear;
                $resolvedScopeKey = null;
                if (isset($decoded['by_scope']) && is_array($decoded['by_scope'])) {
                    $resolvedScopeKey = resolveBestScopeKey($decoded['by_scope'], $diagArea, $diagStartYear, $diagEndYear);
                }
                if ($resolvedScopeKey !== null && isset($decoded['by_scope'][$resolvedScopeKey]) && is_array($decoded['by_scope'][$resolvedScopeKey])) {
                    $normalized = normalizeDiagnosticsPayloadShape($decoded['by_scope'][$resolvedScopeKey], $fallback);
                    $normalized['convlstmPredictions'] = buildDiagnosticsConvLstmSeries([], (array) ($normalized['convlstmPredictions'] ?? []), $diagStartYear, $diagEndYear);
                    return attachDiagnosticsMeta($normalized, 'precomputed_json_scope', $seriesError);
                }

                $areaKey = strtolower(trim($diagArea));

                if (isset($decoded['by_area'][$areaKey]) && is_array($decoded['by_area'][$areaKey])) {
                    $normalized = normalizeDiagnosticsPayloadShape($decoded['by_area'][$areaKey], $fallback);
                    $normalized['convlstmPredictions'] = buildDiagnosticsConvLstmSeries([], (array) ($normalized['convlstmPredictions'] ?? []), $diagStartYear, $diagEndYear);
                    return attachDiagnosticsMeta($normalized, 'precomputed_json_area', $seriesError);
                }
                if ($areaKey !== 'all areas' && isset($decoded['by_area']['all areas']) && is_array($decoded['by_area']['all areas'])) {
                    $normalized = normalizeDiagnosticsPayloadShape($decoded['by_area']['all areas'], $fallback);
                    $normalized['convlstmPredictions'] = buildDiagnosticsConvLstmSeries([], (array) ($normalized['convlstmPredictions'] ?? []), $diagStartYear, $diagEndYear);
                    return attachDiagnosticsMeta($normalized, 'precomputed_json_area', $seriesError);
                }

                $normalized = normalizeDiagnosticsPayloadShape($decoded, $fallback);
                $normalized['convlstmPredictions'] = buildDiagnosticsConvLstmSeries([], (array) ($normalized['convlstmPredictions'] ?? []), $diagStartYear, $diagEndYear);
                return attachDiagnosticsMeta($normalized, 'precomputed_json_global', $seriesError);
            }
        }
    }

    $seriesRowsForLive = $loadSeriesRows();
    $liveResult = runDiagnosticsInferenceScript($seriesRowsForLive, $modelsDir);
    if (($liveResult['success'] ?? false) && isset($liveResult['payload']) && is_array($liveResult['payload'])) {
        $normalizedLive = normalizeDiagnosticsPayloadShape($liveResult['payload'], $fallback);
        $normalizedLive['convlstmPredictions'] = buildDiagnosticsConvLstmSeries($seriesRowsForLive, (array) ($normalizedLive['convlstmPredictions'] ?? []), $diagStartYear, $diagEndYear);
        return attachDiagnosticsMeta($normalizedLive, 'live_model_inference_db', $seriesError);
    }

    $liveError = (string) ($liveResult['error'] ?? 'Unknown diagnostics inference error.');
    if ($artifactError !== '') {
        $liveError = trim($artifactError . "\n" . $liveError);
    }
    if ($seriesError !== '') {
        $liveError = trim($seriesError . "\n" . $liveError);
    }
    return attachDiagnosticsMeta($fallback, 'fallback_default', $liveError);
}


try {
    $mysql = get_mysql_db();

    ensureSummaryTable($mysql);
    $spatialMapStats = refreshSpatialMaps($mysql, $metro_manila_cities, 86400);

    $yearRow = $mysql->query('SELECT MIN(year) AS min_year, MAX(year) AS max_year FROM ecological_yearly_summary')->fetch(PDO::FETCH_ASSOC) ?: [];
    $yearMin = (int) ($yearRow['min_year'] ?? 2014);
    $yearMax = (int) ($yearRow['max_year'] ?? 2024);
    if ($yearMin <= 0 || $yearMax <= 0 || $yearMin > $yearMax) {
        $yearMin = 2014;
        $yearMax = 2024;
    }

    $start_year = max($yearMin, min($yearMax, $start_year));
    $end_year = max($yearMin, min($yearMax, $end_year));
    if ($start_year > $end_year) {
        $end_year = $start_year;
    }
    $snapshot_year = max($yearMin, min($yearMax, $snapshot_year));
    $snapshot_month = max(1, min(12, $snapshot_month));

    if (!in_array($selected_area, array_merge(['All Areas'], $metro_manila_cities), true)) {
        $selected_area = 'All Areas';
    }

    if (summaryNeedsRefresh($mysql, 3600)) {
        $refreshChecks = refreshSummary($mysql, $metro_manila_cities);
    } else {
        $refreshChecks = [
            'ndvi_out_of_range' => 0,
            'lst_out_of_range' => 0,
            'precip_low' => 0,
            'precip_high' => 0,
        ];
    }

    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0775, true);
    }
    $cacheKey = 'reports:' . $selected_area . ':' . $start_year . ':' . $end_year . ':' . $snapshot_year . ':' . $snapshot_month . ':diag=' . ($include_diagnostics ? '1' : '0');
    $cacheFile = $cacheDir . '/' . sha1($cacheKey) . '.json';

    $cached = cacheGet($cacheFile, $cacheTtlSeconds);
    if (is_array($cached)) {
        echo json_encode($cached, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        exit;
    }

    $xgboostFeatureImportanceData = [];
    $convlstmPredictionsData = [];
    $ensembleMetrics = [
        'ensemble_average' => ['rmse' => 0.0, 'mae' => 0.0, 'r2' => 0.0],
        'by_class' => [],
    ];
    $diagnosticsSource = $include_diagnostics ? 'not_computed' : 'not_requested';
    $diagnosticsError = '';
    $diagnosticsMetricsSource = '';
    $resolvedModelDirInfo = resolveDiagnosticsModelDir($mysql, __DIR__ . '/../api_models');
    if ($include_diagnostics) {
        $modelDiagnostics = computeModelDiagnostics(
            $mysql,
            $selected_area,
            $start_year,
            $end_year,
            (string) ($resolvedModelDirInfo['dir'] ?? (__DIR__ . '/../api_models'))
        );
        $xgboostFeatureImportanceData = $modelDiagnostics['xgboostFeatureImportance'] ?? [];
        $convlstmPredictionsData = $modelDiagnostics['convlstmPredictions'] ?? [];
        $ensembleMetrics = $modelDiagnostics['ensembleMetrics'] ?? $ensembleMetrics;
        $diagnosticsSource = (string) ($modelDiagnostics['_diagnostics_source'] ?? 'unknown');
        $diagnosticsError = (string) ($modelDiagnostics['_diagnostics_error'] ?? '');
        $diagnosticsMetricsSource = (string) ($modelDiagnostics['metricsSource'] ?? '');
    }


    $seriesRows = [];
    if ($selected_area === 'All Areas') {
        $areaListSql = "'" . implode("','", array_map(static fn($c) => str_replace("'", "''", $c), $metro_manila_cities)) . "'";

        $envStmt = $mysql->prepare("SELECT
                year,
                AVG(viirs_avg) AS viirs,
                AVG(ndvi_avg) AS ndvi,
                AVG(lst_avg) AS lst,
                AVG(precipitation_total) AS precipitation
            FROM ecological_yearly_summary
            WHERE year BETWEEN :start_year AND :end_year
              AND area IN ({$areaListSql})
            GROUP BY year
            ORDER BY year ASC");
        $envStmt->bindValue(':start_year', $start_year, PDO::PARAM_INT);
        $envStmt->bindValue(':end_year', $end_year, PDO::PARAM_INT);
        $envStmt->execute();
        $envRows = $envStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $richnessStmt = $mysql->prepare("SELECT
                r.year,
                COUNT(DISTINCT r.species_id) AS bird_richness
            FROM raw_bird_observation r
            JOIN species_masterlist sm
                ON sm.species_id = r.species_id
            JOIN observation_city_map m
                ON m.observation_id = r.observation_id
            WHERE r.year BETWEEN :start_year AND :end_year
              AND r.species_id IS NOT NULL
              AND m.area IN ({$areaListSql})
            GROUP BY r.year
            ORDER BY r.year ASC");
        $richnessStmt->bindValue(':start_year', $start_year, PDO::PARAM_INT);
        $richnessStmt->bindValue(':end_year', $end_year, PDO::PARAM_INT);
        $richnessStmt->execute();
        $richnessRows = $richnessStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $richnessByYear = [];
        foreach ($richnessRows as $row) {
            $year = (int) ($row['year'] ?? 0);
            if ($year > 0) {
                $richnessByYear[$year] = isset($row['bird_richness']) ? (float) $row['bird_richness'] : null;
            }
        }

        foreach ($envRows as $row) {
            $year = (int) ($row['year'] ?? 0);
            if ($year <= 0) {
                continue;
            }
            $seriesRows[] = [
                'year' => $year,
                'bird_richness' => $richnessByYear[$year] ?? null,
                'viirs' => isset($row['viirs']) ? (float) $row['viirs'] : null,
                'ndvi' => isset($row['ndvi']) ? (float) $row['ndvi'] : null,
                'lst' => isset($row['lst']) ? (float) $row['lst'] : null,
                'precipitation' => isset($row['precipitation']) ? (float) $row['precipitation'] : null,
            ];
        }
    } else {
        $seriesStmt = $mysql->prepare("SELECT
                year,
                bird_richness,
                viirs_avg AS viirs,
                ndvi_avg AS ndvi,
                lst_avg AS lst,
                precipitation_total AS precipitation
            FROM ecological_yearly_summary
            WHERE area = :area
              AND year BETWEEN :start_year AND :end_year
            ORDER BY year ASC");
        $seriesStmt->bindValue(':area', $selected_area, PDO::PARAM_STR);
        $seriesStmt->bindValue(':start_year', $start_year, PDO::PARAM_INT);
        $seriesStmt->bindValue(':end_year', $end_year, PDO::PARAM_INT);
        $seriesStmt->execute();
        $seriesRows = $seriesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $unified = [];
    $nullCounts = [
        'bird_richness' => 0,
        'viirs' => 0,
        'ndvi' => 0,
        'lst' => 0,
        'precipitation' => 0,
    ];

    $presentYears = [];
    foreach ($seriesRows as $row) {
        $year = (int) ($row['year'] ?? 0);
        if ($year <= 0) {
            continue;
        }
        $normalizedRow = [
            'year' => $year,
            'bird_richness' => isset($row['bird_richness']) ? (float) $row['bird_richness'] : null,
            'viirs' => isset($row['viirs']) ? (float) $row['viirs'] : null,
            'ndvi' => isset($row['ndvi']) ? (float) $row['ndvi'] : null,
            'lst' => isset($row['lst']) ? (float) $row['lst'] : null,
            'precipitation' => isset($row['precipitation']) ? (float) $row['precipitation'] : null,
        ];
        foreach ($nullCounts as $k => $v) {
            if ($normalizedRow[$k] === null) {
                $nullCounts[$k]++;
            }
        }
        $unified[] = $normalizedRow;
        $presentYears[$year] = true;
    }

    $missingYears = [];
    for ($y = $start_year; $y <= $end_year; $y++) {
        if (!isset($presentYears[$y])) {
            $missingYears[] = $y;
        }
    }

    $corrInput = [];
    if ($selected_area === 'All Areas') {
        $corrStmt = $mysql->prepare("SELECT
                bird_richness,
                viirs_avg AS viirs,
                ndvi_avg AS ndvi,
                lst_avg AS lst,
                precipitation_total AS precipitation
            FROM ecological_yearly_summary
            WHERE year BETWEEN :start_year AND :end_year
              AND area IN ('" . implode("','", array_map(static fn($c) => str_replace("'", "''", $c), $metro_manila_cities)) . "')
              AND bird_richness IS NOT NULL");
        $corrStmt->bindValue(':start_year', $start_year, PDO::PARAM_INT);
        $corrStmt->bindValue(':end_year', $end_year, PDO::PARAM_INT);
        $corrStmt->execute();
        $corrInput = $corrStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } else {
        $corrStmt = $mysql->prepare("SELECT
                bird_richness,
                viirs_avg AS viirs,
                ndvi_avg AS ndvi,
                lst_avg AS lst,
                precipitation_total AS precipitation
            FROM ecological_yearly_summary
            WHERE area = :area
              AND year BETWEEN :start_year AND :end_year
              AND bird_richness IS NOT NULL");
        $corrStmt->bindValue(':area', $selected_area, PDO::PARAM_STR);
        $corrStmt->bindValue(':start_year', $start_year, PDO::PARAM_INT);
        $corrStmt->bindValue(':end_year', $end_year, PDO::PARAM_INT);
        $corrStmt->execute();
        $corrInput = $corrStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $buildCorrRow = static function (array $rows, string $key): array {
        $n = 0;
        $sx = 0.0;
        $sx2 = 0.0;
        $sy = 0.0;
        $sy2 = 0.0;
        $sxy = 0.0;
        foreach ($rows as $r) {
            if (!isset($r['bird_richness'], $r[$key]) || $r[$key] === null) {
                continue;
            }
            $x = (float) $r['bird_richness'];
            $y = (float) $r[$key];
            $n++;
            $sx += $x;
            $sx2 += $x * $x;
            $sy += $y;
            $sy2 += $y * $y;
            $sxy += $x * $y;
        }
        return [
            'n' => $n,
            'sx' => $sx,
            'sx2' => $sx2,
            $key . '_sum' => $sy,
            $key . '_sum2' => $sy2,
            $key . '_sumxy' => $sxy,
        ];
    };

    $viirsRow = $buildCorrRow($corrInput, 'viirs');
    $ndviRow = $buildCorrRow($corrInput, 'ndvi');
    $lstRow = $buildCorrRow($corrInput, 'lst');
    $precRow = $buildCorrRow($corrInput, 'precipitation');

    $correlation = [
        'richness_viirs' => pearson($viirsRow, 'viirs'),
        'richness_ndvi' => pearson($ndviRow, 'ndvi'),
        'richness_lst' => pearson($lstRow, 'lst'),
        'richness_precip' => pearson($precRow, 'precipitation'),
    ];

    $snapshotDistributions = fetchSnapshotSpeciesDistributions($mysql, $selected_area, $snapshot_year, $snapshot_month);
    $snapshotScatterError = '';
    try {
        $snapshotScatterData = fetchSnapshotScatterData($mysql, $metro_manila_cities, $selected_area, $snapshot_year, $snapshot_month);
    } catch (Throwable $scatterEx) {
        $snapshotScatterData = emptySnapshotScatterData();
        $snapshotScatterError = $scatterEx->getMessage();
    }
    $topSitesRichnessData = fetchTopSitesRichnessData($mysql, $metro_manila_cities, $selected_area, $snapshot_year, $snapshot_month, 10);


    $trendHistoricalData = [
        'labels' => array_map(static fn($r) => (int) $r['year'], $unified),
        'richness' => array_map(static fn($r) => $r['bird_richness'], $unified),
        'viirs' => array_map(static fn($r) => $r['viirs'], $unified),
        'ndvi' => array_map(static fn($r) => $r['ndvi'], $unified),
        'lst' => array_map(static fn($r) => $r['lst'], $unified),
        'precip' => array_map(static fn($r) => $r['precipitation'], $unified),
    ];

    $normalized = null;
    if ($include_normalized) {
        $keys = ['bird_richness', 'viirs', 'ndvi', 'lst', 'precipitation'];
        $stats = [];
        foreach ($keys as $key) {
            $vals = array_values(array_filter(array_map(static fn($r) => $r[$key], $unified), static fn($v) => $v !== null));
            $n = count($vals);
            if ($n === 0) {
                $stats[$key] = ['mean' => 0.0, 'std' => 0.0];
                continue;
            }
            $mean = array_sum($vals) / $n;
            $var = 0.0;
            foreach ($vals as $v) {
                $var += ($v - $mean) * ($v - $mean);
            }
            $std = $n > 1 ? sqrt($var / ($n - 1)) : 0.0;
            $stats[$key] = ['mean' => $mean, 'std' => $std];
        }

        $normalized = array_map(static function ($r) use ($stats) {
            $out = ['year' => $r['year']];
            foreach (['bird_richness', 'viirs', 'ndvi', 'lst', 'precipitation'] as $key) {
                $val = $r[$key];
                $std = $stats[$key]['std'];
                if ($val === null || $std <= 0) {
                    $out[$key . '_z'] = null;
                } else {
                    $out[$key . '_z'] = round(($val - $stats[$key]['mean']) / $std, 6);
                }
            }
            return $out;
        }, $unified);
    }

    logDataQuality([
        'area' => $selected_area,
        'start_year' => $start_year,
        'end_year' => $end_year,
        'missing_years' => $missingYears,
        'null_counts' => $nullCounts,
        'range_flags' => $refreshChecks,
        'spatial_mapping' => $spatialMapStats,
        'scaling_corrections' => [
            'ndvi_divided_by_10000_when_abs_gt_1' => true,
            'lst_celsius_converted_when_raw_gt_100' => true,
            'precipitation_yearly_sum_from_monthly' => true,
        ],
    ]);

    $payload = [
        'success' => true,
        'area' => $selected_area,
        'start_year' => $start_year,
        'end_year' => $end_year,
        'data' => $unified,
        'units' => $units,
        'normalized' => $normalized,
        'meta' => [
            'available_areas' => $metro_manila_cities,
            'year_min' => $yearMin,
            'year_max' => $yearMax,
            'cache_key' => $cacheKey,
            'spatial_mapping' => $spatialMapStats,
            'diagnostics_included' => $include_diagnostics,
            'scope' => $scope,
            'diagnostics_source' => $diagnosticsSource,
            'diagnostics_error' => $diagnosticsError,
            'snapshot_scatter_error' => $snapshotScatterError,
            'diagnostics_model_dir_source' => (string) ($resolvedModelDirInfo['source'] ?? 'unknown'),
            'diagnostics_model_dir_note' => (string) ($resolvedModelDirInfo['note'] ?? ''),
            'diagnostics_metrics_source' => $diagnosticsMetricsSource,
        ],
        'validation' => [
            'ndvi_out_of_range' => $refreshChecks['ndvi_out_of_range'] ?? 0,
            'lst_out_of_range' => $refreshChecks['lst_out_of_range'] ?? 0,
            'precip_low' => $refreshChecks['precip_low'] ?? 0,
            'precip_high' => $refreshChecks['precip_high'] ?? 0,
            'missing_years' => $missingYears,
            'null_counts' => $nullCounts,
            'scaling_corrections_applied' => [
                'ndvi_divided_by_10000_when_abs_gt_1' => true,
                'lst_modis_scale_0_02_then_kelvin_to_celsius' => true,
                'precipitation_monthly_avg_mm_spatial_avg_across_cells' => true,
            ],
        ],

        // Backward-compatible keys for existing frontend wiring.
        'filters' => [
            'selected_area' => $selected_area,
            'start_year' => $start_year,
            'end_year' => $end_year,
            'snapshot_year' => $snapshot_year,
            'snapshot_month' => $snapshot_month,
        ],
        'trendHistoricalData' => $trendHistoricalData,
        'trendCorrelationData' => $correlation,
        'snapshotDistributions' => $snapshotDistributions,
        'snapshotScatterData' => $snapshotScatterData,
        'topSitesRichnessData' => $topSitesRichnessData,
        'xgboostFeatureImportance' => $xgboostFeatureImportanceData,
        'convlstmPredictions' => $convlstmPredictionsData,
        'ensembleMetrics' => $ensembleMetrics,
    ];

    cachePut($cacheFile, $payload);

    echo json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ]);
}
