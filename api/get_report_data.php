<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/backend_config.php';

if (false && !is_logged_in()) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Unauthorized',
    ]);
    exit;
}

$selected_area = trim((string) ($_GET['selected_area'] ?? 'All Areas'));
$start_year = (int) ($_GET['start_year'] ?? 2014);
$end_year = (int) ($_GET['end_year'] ?? 2025);
$snapshot_start_year = (int) ($_GET['snapshot_start_year'] ?? ($_GET['snapshot_year'] ?? 2025));
$snapshot_start_month = (int) ($_GET['snapshot_start_month'] ?? ($_GET['snapshot_month'] ?? 12));
$snapshot_end_year = (int) ($_GET['snapshot_end_year'] ?? ($_GET['snapshot_year'] ?? 2025));
$snapshot_end_month = (int) ($_GET['snapshot_end_month'] ?? ($_GET['snapshot_month'] ?? 12));
$snapshot_year = $snapshot_end_year;
$snapshot_month = $snapshot_end_month;
$include_normalized = (string) ($_GET['include_normalized'] ?? '0') === '1';
$scope = trim((string) ($_GET['scope'] ?? 'trend'));
$include_diagnostics = ((string) ($_GET['include_diagnostics'] ?? '0') === '1') || ($scope === 'diagnostics');
$force_refresh = ((string) ($_GET['force_refresh'] ?? '0') === '1');

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

$cacheTtlSeconds = 30 * 24 * 60 * 60;
$cacheDir = __DIR__ . '/../data/cache/reports';
$reportCacheSchemaVersion = 'v10';

function safeReportYearBounds(PDO $mysql): array {
    $yearRow = $mysql->query('SELECT MIN(year) AS min_year, MAX(year) AS max_year FROM ecological_yearly_summary')->fetch(PDO::FETCH_ASSOC) ?: [];
    $yearMin = (int) ($yearRow['min_year'] ?? 2014);
    $yearMax = (int) ($yearRow['max_year'] ?? 2025);
    if ($yearMin <= 0 || $yearMax <= 0 || $yearMin > $yearMax) {
        return [2014, 2025];
    }
    return [$yearMin, $yearMax];
}

/**
 * Build inline SQL table for Metro Manila city names.
 */

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

function buildCityInlineSql(array $cities): string {
    $parts = [];
    foreach ($cities as $city) {
        $city = trim((string) $city);
        if ($city === '') {
            continue;
        }
        $parts[] = "SELECT '" . str_replace("'", "''", $city) . "' AS area";
    }

    if (empty($parts)) {
        return "SELECT 'All Areas' AS area WHERE 1 = 0";
    }

    return implode(" UNION ALL ", $parts);
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

    // Fallback: snap coastal/reclaimed-land points that missed all polygons to the
    // nearest city boundary vertex, but only within ~500 m (≈0.005°) so points
    // genuinely outside Metro Manila are still excluded.
    $maxDistSq   = 0.005 * 0.005;
    $nearestCity = null;
    $nearestDist = PHP_FLOAT_MAX;
    foreach ($orderedCities as $city) {
        foreach (($cityPolygons[$city] ?? []) as $poly) {
            $outerRing = $poly[0] ?? [];
            foreach ($outerRing as $coord) {
                $dx = $lon - (float) ($coord[0] ?? 0);
                $dy = $lat - (float) ($coord[1] ?? 0);
                $d  = $dx * $dx + $dy * $dy;
                if ($d < $nearestDist) {
                    $nearestDist = $d;
                    $nearestCity = $city;
                }
            }
        }
    }
    return ($nearestDist <= $maxDistSq) ? $nearestCity : null;
}

function ensureSpatialMapTables(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS observation_city_map (
        rbo_id BIGINT NOT NULL,
        area VARCHAR(100) NOT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (rbo_id),
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

            $insertObs = $pdo->prepare('INSERT INTO observation_city_map (rbo_id, area) VALUES (:id, :area)');
            $obsStmt = $pdo->query('SELECT id, latitude, longitude FROM raw_bird_observation WHERE year IS NOT NULL AND species_id IS NOT NULL');
            while ($row = $obsStmt->fetch(PDO::FETCH_ASSOC)) {
                $lat = (float) ($row['latitude'] ?? 0);
                $lon = (float) ($row['longitude'] ?? 0);
                $area = mapPointToCity($lat, $lon, $cityPolygons, $cities);
                if ($area === null) {
                    continue;
                }
                $insertObs->execute([
                    ':id' => (int) $row['id'],
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

    $pdo->exec("CREATE TABLE IF NOT EXISTS metro_yearly_richness (
        year                INT    NOT NULL,
        bird_richness       INT    NULL,
        viirs_avg           DOUBLE NULL,
        ndvi_avg            DOUBLE NULL,
        lst_avg             DOUBLE NULL,
        precipitation_total DOUBLE NULL,
        updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (year)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/**
 * Lightweight audit log for data quality checks.
 */
function logDataQuality(array $payload): void {
    $line = '[ecological-report-validation] ' . json_encode($payload, JSON_UNESCAPED_UNICODE);
    error_log($line ?: '[ecological-report-validation] failed-to-encode-log');
}

function ensureIndexExists(PDO $pdo, string $tableName, string $indexName, string $createSql): void {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.statistics
        WHERE table_schema = DATABASE()
          AND table_name = :table_name
          AND index_name = :index_name");
    $stmt->bindValue(':table_name', $tableName, PDO::PARAM_STR);
    $stmt->bindValue(':index_name', $indexName, PDO::PARAM_STR);
    $stmt->execute();

    if ((int) $stmt->fetchColumn() === 0) {
        $pdo->exec($createSql);
    }
}

function ensureReportQueryIndexes(PDO $pdo): void {
    ensureIndexExists(
        $pdo,
        'observation_city_map',
        'idx_ocm_area_rbo',
        'CREATE INDEX idx_ocm_area_rbo ON observation_city_map(area, rbo_id)'
    );

    ensureIndexExists(
        $pdo,
        'city_grid_map',
        'idx_cgm_area_lat_lon',
        'CREATE INDEX idx_cgm_area_lat_lon ON city_grid_map(area, lat, lon)'
    );

    ensureIndexExists(
        $pdo,
        'ecological_yearly_summary',
        'idx_summary_year',
        'CREATE INDEX idx_summary_year ON ecological_yearly_summary(year)'
    );

    ensureIndexExists(
        $pdo,
        'raw_bird_observation',
        'idx_rbo_year_month_species_id',
        'CREATE INDEX idx_rbo_year_month_species_id ON raw_bird_observation(year, month, species_id, id)'
    );

    ensureIndexExists(
        $pdo,
        'raw_bird_observation',
        'idx_rbo_year_month_site_species',
        'CREATE INDEX idx_rbo_year_month_site_species ON raw_bird_observation(year, month, site_name, species_id)'
    );

    ensureIndexExists(
        $pdo,
        'viirs',
        'idx_viirs_year_month_lat_lon',
        'CREATE INDEX idx_viirs_year_month_lat_lon ON viirs(year, month, latitude, longitude)'
    );

    ensureIndexExists(
        $pdo,
        'ndvi',
        'idx_ndvi_year_month_lat_lon',
        'CREATE INDEX idx_ndvi_year_month_lat_lon ON ndvi(year, month, latitude, longitude)'
    );

    ensureIndexExists(
        $pdo,
        'land_temp',
        'idx_land_temp_year_month_lat_lon',
        'CREATE INDEX idx_land_temp_year_month_lat_lon ON land_temp(year, month, latitude, longitude)'
    );

    ensureIndexExists(
        $pdo,
        'precip',
        'idx_precip_year_month_lat_lon',
        'CREATE INDEX idx_precip_year_month_lat_lon ON precip(year, month, latitude, longitude)'
    );
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

function buildSnapshotRangeSql(string $alias = 'r'): string {
    // Use each placeholder exactly once to avoid HY093 with native prepared statements.
    return "((({$alias}.year * 12) + {$alias}.month) BETWEEN ((:snapshot_start_year * 12) + :snapshot_start_month) AND ((:snapshot_end_year * 12) + :snapshot_end_month))";
}

function snapshotSummaryHasRows(PDO $pdo, string $selectedArea, int $snapshotStartYear, int $snapshotStartMonth, int $snapshotEndYear, int $snapshotEndMonth): bool {
    try {
        $sql = "SELECT 1
            FROM snapshot_monthly_area_summary s
            WHERE " . buildSnapshotRangeSql('s') . "
                            AND (:selected_area_all = 'All Areas' OR s.area = :selected_area_value)
            LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':snapshot_start_year', $snapshotStartYear, PDO::PARAM_INT);
        $stmt->bindValue(':snapshot_start_month', $snapshotStartMonth, PDO::PARAM_INT);
        $stmt->bindValue(':snapshot_end_year', $snapshotEndYear, PDO::PARAM_INT);
        $stmt->bindValue(':snapshot_end_month', $snapshotEndMonth, PDO::PARAM_INT);
                $stmt->bindValue(':selected_area_all', $selectedArea, PDO::PARAM_STR);
                $stmt->bindValue(':selected_area_value', $selectedArea, PDO::PARAM_STR);
        $stmt->execute();
        return (bool) $stmt->fetchColumn();
    } catch (Throwable) {
        return false;
    }
}

const SNAPSHOT_CACHE_VERSION = 'v2';

/**
 * Ensure snapshot_species_presence exists and is populated.
 * One row per (area, year, month, species_id) — pre-joined with species_masterlist.
 * COUNT(DISTINCT species_id) over any date range is then fast and correct.
 */
function ensureSnapshotSpeciesPresenceTable(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS snapshot_species_presence (
        area             VARCHAR(100) NOT NULL,
        year             SMALLINT     NOT NULL,
        month            TINYINT      NOT NULL,
        species_id       INT          NOT NULL,
        migratory_status VARCHAR(50)  NOT NULL DEFAULT '',
        light_tolerance  VARCHAR(50)  NOT NULL DEFAULT '',
        PRIMARY KEY (area, year, month, species_id),
        INDEX idx_ssp_year_month_species (year, month, species_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $empty = !(bool) $pdo->query('SELECT 1 FROM snapshot_species_presence LIMIT 1')->fetchColumn();
    if (!$empty) {
        return;
    }

    $pdo->exec("INSERT INTO snapshot_species_presence
            (area, year, month, species_id, migratory_status, light_tolerance)
        SELECT
            m.area,
            r.year,
            r.month,
            r.species_id,
            LOWER(TRIM(COALESCE(NULLIF(TRIM(sm.migratory_status), ''), 'unclassified'))),
            LOWER(TRIM(COALESCE(NULLIF(TRIM(sm.light_tolerance), ''), 'unclassified')))
        FROM raw_bird_observation r
        JOIN city_grid_map        m  ON m.lat = r.grid_lat AND m.lon = r.grid_lon
        LEFT JOIN species_masterlist sm ON sm.species_id = r.species_id
        WHERE r.year IS NOT NULL AND r.species_id IS NOT NULL AND m.area IS NOT NULL
        GROUP BY m.area, r.year, r.month, r.species_id
        ON DUPLICATE KEY UPDATE
            migratory_status = VALUES(migratory_status),
            light_tolerance  = VALUES(light_tolerance)");
}

/**
 * Rebuild snapshot_species_presence from scratch (call after new observation data is imported).
 */
function rebuildSnapshotSpeciesPresenceTable(PDO $pdo): void {
    $pdo->exec('TRUNCATE TABLE snapshot_species_presence');
    $pdo->exec("INSERT INTO snapshot_species_presence
            (area, year, month, species_id, migratory_status, light_tolerance)
        SELECT
            m.area,
            r.year,
            r.month,
            r.species_id,
            LOWER(TRIM(COALESCE(NULLIF(TRIM(sm.migratory_status), ''), 'unclassified'))),
            LOWER(TRIM(COALESCE(NULLIF(TRIM(sm.light_tolerance), ''), 'unclassified')))
        FROM raw_bird_observation r
        JOIN city_grid_map        m  ON m.lat = r.grid_lat AND m.lon = r.grid_lon
        LEFT JOIN species_masterlist sm ON sm.species_id = r.species_id
        WHERE r.year IS NOT NULL AND r.species_id IS NOT NULL AND m.area IS NOT NULL
        GROUP BY m.area, r.year, r.month, r.species_id
        ON DUPLICATE KEY UPDATE
            migratory_status = VALUES(migratory_status),
            light_tolerance  = VALUES(light_tolerance)");
}

/**
 * Ensure snapshot_cache table exists with formula_version column.
 */
function ensureSnapshotCacheTable(PDO $pdo): void {
    try {
        $pdo->query('SELECT 1 FROM snapshot_cache LIMIT 1');
        // Table exists — ensure formula_version column is present (MySQL-compatible check)
        try {
            $has = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'snapshot_cache' AND COLUMN_NAME = 'formula_version'")->fetchColumn();
            if (!$has) {
                $pdo->exec("ALTER TABLE snapshot_cache ADD COLUMN formula_version VARCHAR(20) NOT NULL DEFAULT 'v1'");
            }
        } catch (Throwable) {}
    } catch (Throwable) {
        $pdo->exec("CREATE TABLE snapshot_cache (
            id INT AUTO_INCREMENT PRIMARY KEY,
            area VARCHAR(100) NOT NULL,
            year_start INT NOT NULL,
            month_start INT NOT NULL,
            year_end INT NOT NULL,
            month_end INT NOT NULL,
            migration_migratory INT DEFAULT 0,
            migration_resident INT DEFAULT 0,
            migration_unclassified INT DEFAULT 0,
            light_sensitive INT DEFAULT 0,
            light_tolerant INT DEFAULT 0,
            light_unclassified INT DEFAULT 0,
            richness_count INT DEFAULT 0,
            formula_version VARCHAR(20) NOT NULL DEFAULT 'v1',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_area_dates (area, year_start, month_start, year_end, month_end)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}

/**
 * Query or compute snapshot aggregations with lazy caching.
 */
function getOrComputeSnapshotMetrics(
    PDO $pdo,
    string $selectedArea,
    int $snapshotStartYear,
    int $snapshotStartMonth,
    int $snapshotEndYear,
    int $snapshotEndMonth,
    array $cities
): array {
    ensureSnapshotCacheTable($pdo);

    $cacheStmt = $pdo->prepare('SELECT migration_migratory, migration_resident, migration_unclassified, light_sensitive, light_tolerant, light_unclassified, richness_count FROM snapshot_cache WHERE area = ? AND year_start = ? AND month_start = ? AND year_end = ? AND month_end = ? AND formula_version = ?');
    $cacheStmt->execute([$selectedArea, $snapshotStartYear, $snapshotStartMonth, $snapshotEndYear, $snapshotEndMonth, SNAPSHOT_CACHE_VERSION]);
    $cacheRow = $cacheStmt->fetch(PDO::FETCH_ASSOC);

    if ($cacheRow) {
        return [
            'migration' => [
                'migratory' => (int)$cacheRow['migration_migratory'],
                'resident' => (int)$cacheRow['migration_resident'],
                'unclassified' => (int)$cacheRow['migration_unclassified'],
            ],
            'light' => [
                'sensitive' => (int)$cacheRow['light_sensitive'],
                'tolerant' => (int)$cacheRow['light_tolerant'],
                'unclassified' => (int)$cacheRow['light_unclassified'],
            ],
            'richness' => (int)$cacheRow['richness_count'],
            'from_cache' => true,
        ];
    }

    $distributions = fetchSnapshotSpeciesDistributions(
        $pdo,
        $selectedArea,
        $snapshotStartYear,
        $snapshotStartMonth,
        $snapshotEndYear,
        $snapshotEndMonth
    );

    $migrationData = $distributions['migration_status']['data'] ?? [];
    $lightData = $distributions['light_tolerance']['data'] ?? [];

    cacheSnapshotMetrics(
        $pdo,
        $selectedArea,
        $snapshotStartYear,
        $snapshotStartMonth,
        $snapshotEndYear,
        $snapshotEndMonth,
        [
            'migratory' => (int) ($migrationData[0] ?? 0),
            'resident' => (int) ($migrationData[1] ?? 0),
            'unclassified' => (int) ($migrationData[2] ?? 0),
        ],
        [
            'sensitive' => (int) ($lightData[0] ?? 0),
            'tolerant' => (int) ($lightData[1] ?? 0),
            'unclassified' => (int) ($lightData[2] ?? 0),
        ],
        (int) ($distributions['migration_status']['total_species'] ?? 0)
    );

    return [
        'migration' => [
            'migratory' => (int) ($migrationData[0] ?? 0),
            'resident' => (int) ($migrationData[1] ?? 0),
            'unclassified' => (int) ($migrationData[2] ?? 0),
        ],
        'light' => [
            'sensitive' => (int) ($lightData[0] ?? 0),
            'tolerant' => (int) ($lightData[1] ?? 0),
            'unclassified' => (int) ($lightData[2] ?? 0),
        ],
        'richness' => (int) ($distributions['migration_status']['total_species'] ?? 0),
        'from_cache' => false,
        'cache_miss' => true,
    ];
}

/**
 * Store computed snapshot metrics in cache.
 */
function cacheSnapshotMetrics(
    PDO $pdo,
    string $selectedArea,
    int $snapshotStartYear,
    int $snapshotStartMonth,
    int $snapshotEndYear,
    int $snapshotEndMonth,
    array $migrationData,
    array $lightData,
    int $richness
): void {
    try {
        ensureSnapshotCacheTable($pdo);
        $stmt = $pdo->prepare('INSERT INTO snapshot_cache (area, year_start, month_start, year_end, month_end, migration_migratory, migration_resident, migration_unclassified, light_sensitive, light_tolerant, light_unclassified, richness_count, formula_version) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE migration_migratory = VALUES(migration_migratory), migration_resident = VALUES(migration_resident), migration_unclassified = VALUES(migration_unclassified), light_sensitive = VALUES(light_sensitive), light_tolerant = VALUES(light_tolerant), light_unclassified = VALUES(light_unclassified), richness_count = VALUES(richness_count), formula_version = VALUES(formula_version), updated_at = CURRENT_TIMESTAMP');
        $stmt->execute([
            $selectedArea, $snapshotStartYear, $snapshotStartMonth, $snapshotEndYear, $snapshotEndMonth,
            (int)($migrationData['migratory'] ?? 0),
            (int)($migrationData['resident'] ?? 0),
            (int)($migrationData['unclassified'] ?? 0),
            (int)($lightData['sensitive'] ?? 0),
            (int)($lightData['tolerant'] ?? 0),
            (int)($lightData['unclassified'] ?? 0),
            $richness,
            SNAPSHOT_CACHE_VERSION,
        ]);
    } catch (Throwable) {
        // Silently fail if caching fails - doesn't block main response
    }
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

function fetchSnapshotSpeciesDistributions(PDO $pdo, string $selectedArea, int $snapshotStartYear, int $snapshotStartMonth, int $snapshotEndYear, int $snapshotEndMonth): array {
    $rangeSql = buildSnapshotRangeSql('r');
    $params   = [
        ':snapshot_start_year'  => $snapshotStartYear,
        ':snapshot_start_month' => $snapshotStartMonth,
        ':snapshot_end_year'    => $snapshotEndYear,
        ':snapshot_end_month'   => $snapshotEndMonth,
    ];

    if ($selectedArea === 'All Areas') {
        // Metro Manila: query raw observations directly (no city-grid constraint) so the
        // count matches the Historical Trend which also uses all rows in raw_bird_observation.
        $migrationSql = "SELECT
                LOWER(TRIM(COALESCE(NULLIF(TRIM(sm.migratory_status), ''), 'unclassified'))) AS category,
                COUNT(DISTINCT r.species_id) AS species_count
            FROM raw_bird_observation r
            LEFT JOIN species_masterlist sm ON sm.species_id = r.species_id
            WHERE r.year IS NOT NULL AND r.species_id IS NOT NULL
              AND {$rangeSql}
            GROUP BY category";

        $lightSql = "SELECT
                LOWER(TRIM(COALESCE(NULLIF(TRIM(sm.light_tolerance), ''), 'unclassified'))) AS category,
                COUNT(DISTINCT r.species_id) AS species_count
            FROM raw_bird_observation r
            LEFT JOIN species_masterlist sm ON sm.species_id = r.species_id
            WHERE r.year IS NOT NULL AND r.species_id IS NOT NULL
              AND {$rangeSql}
            GROUP BY category";
    } else {
        // City level: use snapshot_species_presence (city_grid_map based, fast pre-aggregated).
        ensureSnapshotSpeciesPresenceTable($pdo);
        $params[':area'] = $selectedArea;
        $rangeClauseP    = buildSnapshotRangeSql('p');

        $migrationSql = "SELECT migratory_status AS category, COUNT(DISTINCT species_id) AS species_count
            FROM snapshot_species_presence p
            WHERE {$rangeClauseP} AND area = :area
            GROUP BY migratory_status";

        $lightSql = "SELECT light_tolerance AS category, COUNT(DISTINCT species_id) AS species_count
            FROM snapshot_species_presence p
            WHERE {$rangeClauseP} AND area = :area
            GROUP BY light_tolerance";
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
        'migratory'    => 0,
        'resident'     => 0,
        'unclassified' => 0,
    ];
    foreach ($migrationRows as $row) {
        $cat   = (string) ($row['category']     ?? '');
        $count = (int)    ($row['species_count'] ?? 0);
        if ($cat === 'migratory') {
            $migrationMap['migratory'] += $count;
        } elseif ($cat === 'resident') {
            $migrationMap['resident'] += $count;
        } else {
            $migrationMap['unclassified'] += $count;
        }
    }

    $lightMap = [
        'sensitive'    => 0,
        'tolerant'     => 0,
        'unclassified' => 0,
    ];
    foreach ($lightRows as $row) {
        $cat   = (string) ($row['category']     ?? '');
        $count = (int)    ($row['species_count'] ?? 0);
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

function fetchSnapshotScatterData(PDO $pdo, array $cities, string $selectedArea, int $snapshotStartYear, int $snapshotStartMonth, int $snapshotEndYear, int $snapshotEndMonth): array {
    if (snapshotSummaryHasRows($pdo, $selectedArea, $snapshotStartYear, $snapshotStartMonth, $snapshotEndYear, $snapshotEndMonth)) {
        // Richness: COUNT DISTINCT species per area, matching Historical Trends computation
        $richnessSql = "SELECT m.area, COUNT(DISTINCT r.species_id) AS bird_richness
            FROM raw_bird_observation r
            JOIN city_grid_map m ON m.lat = r.grid_lat AND m.lon = r.grid_lon
            WHERE r.year IS NOT NULL AND r.species_id IS NOT NULL
              AND " . buildSnapshotRangeSql('r') . "
              AND (:selected_area_all = 'All Areas' OR m.area = :selected_area_value)
            GROUP BY m.area
            HAVING COUNT(DISTINCT r.species_id) > 0";

        $richnessStmt = $pdo->prepare($richnessSql);
        $richnessStmt->bindValue(':snapshot_start_year', $snapshotStartYear, PDO::PARAM_INT);
        $richnessStmt->bindValue(':snapshot_start_month', $snapshotStartMonth, PDO::PARAM_INT);
        $richnessStmt->bindValue(':snapshot_end_year', $snapshotEndYear, PDO::PARAM_INT);
        $richnessStmt->bindValue(':snapshot_end_month', $snapshotEndMonth, PDO::PARAM_INT);
        $richnessStmt->bindValue(':selected_area_all', $selectedArea, PDO::PARAM_STR);
        $richnessStmt->bindValue(':selected_area_value', $selectedArea, PDO::PARAM_STR);
        $richnessStmt->execute();
        $richnessByArea = [];
        foreach ($richnessStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $rr) {
            $a = trim((string) ($rr['area'] ?? ''));
            if ($a !== '') {
                $richnessByArea[$a] = (float) $rr['bird_richness'];
            }
        }

        $sql = "SELECT
                c.area,
                AVG(NULLIF(f.viirs_avg_rad, 0)) AS viirs,
                AVG(
                    CASE
                        WHEN f.ndvi = 0 THEN NULL
                        WHEN ABS(f.ndvi) > 1 THEN f.ndvi / 10000
                        ELSE f.ndvi
                    END
                ) AS ndvi,
                COALESCE(
                    (AVG(CASE WHEN f.lst_day > 100 THEN (f.lst_day * 0.02) - 273.15
                              WHEN f.lst_day > 0 THEN f.lst_day
                              ELSE NULL END) +
                     AVG(CASE WHEN f.lst_night > 100 THEN (f.lst_night * 0.02) - 273.15
                              WHEN f.lst_night > 0 THEN f.lst_night
                              ELSE NULL END)) / 2.0,
                    AVG(CASE WHEN f.lst_day > 100 THEN (f.lst_day * 0.02) - 273.15
                             WHEN f.lst_day > 0 THEN f.lst_day
                             ELSE NULL END)
                ) AS lst
            FROM final_master_grid f
            JOIN city_grid_map c
              ON ROUND(c.lat, 6) = ROUND(f.lat, 6)
             AND ROUND(c.lon, 6) = ROUND(f.lon, 6)
            WHERE " . buildSnapshotRangeSql('f') . "
              AND (:selected_area_all2 = 'All Areas' OR c.area = :selected_area_value2)
            GROUP BY c.area
            ORDER BY c.area ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':snapshot_start_year', $snapshotStartYear, PDO::PARAM_INT);
        $stmt->bindValue(':snapshot_start_month', $snapshotStartMonth, PDO::PARAM_INT);
        $stmt->bindValue(':snapshot_end_year', $snapshotEndYear, PDO::PARAM_INT);
        $stmt->bindValue(':snapshot_end_month', $snapshotEndMonth, PDO::PARAM_INT);
        $stmt->bindValue(':selected_area_all2', $selectedArea, PDO::PARAM_STR);
        $stmt->bindValue(':selected_area_value2', $selectedArea, PDO::PARAM_STR);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Precipitation: sum per cell first, then average across cells — matches ecological_yearly_summary pipeline.
        // Uses separate named params to avoid PDO HY093 (each placeholder used exactly once per statement).
        $precipSql = "SELECT c.area, AVG(cs.annual_precip) AS precipitation
            FROM (
                SELECT f2.lat, f2.lon,
                    SUM(CASE WHEN f2.monthly_precip_mm >= 0 THEN f2.monthly_precip_mm ELSE NULL END) AS annual_precip
                FROM final_master_grid f2
                WHERE (((f2.year * 12) + f2.month) BETWEEN ((:p1_start_year * 12) + :p1_start_month) AND ((:p1_end_year * 12) + :p1_end_month))
                GROUP BY f2.lat, f2.lon
            ) cs
            JOIN city_grid_map c
              ON ROUND(c.lat, 6) = ROUND(cs.lat, 6)
             AND ROUND(c.lon, 6) = ROUND(cs.lon, 6)
            WHERE (:p1_area_all = 'All Areas' OR c.area = :p1_area_value)
            GROUP BY c.area";
        $precipStmt = $pdo->prepare($precipSql);
        $precipStmt->bindValue(':p1_start_year', $snapshotStartYear, PDO::PARAM_INT);
        $precipStmt->bindValue(':p1_start_month', $snapshotStartMonth, PDO::PARAM_INT);
        $precipStmt->bindValue(':p1_end_year', $snapshotEndYear, PDO::PARAM_INT);
        $precipStmt->bindValue(':p1_end_month', $snapshotEndMonth, PDO::PARAM_INT);
        $precipStmt->bindValue(':p1_area_all', $selectedArea, PDO::PARAM_STR);
        $precipStmt->bindValue(':p1_area_value', $selectedArea, PDO::PARAM_STR);
        $precipStmt->execute();
        $precipByArea = [];
        foreach ($precipStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $pr) {
            $a = trim((string) ($pr['area'] ?? ''));
            if ($a !== '') {
                $precipByArea[$a] = $pr['precipitation'] !== null ? (float) $pr['precipitation'] : null;
            }
        }
        foreach ($rows as &$row) {
            $a = trim((string) ($row['area'] ?? ''));
            $row['precipitation'] = $precipByArea[$a] ?? null;
        }
        unset($row);

        $points = [
            'light_richness' => [],
            'ndvi_richness' => [],
            'lst_richness' => [],
            'precipitation_richness' => [],
        ];

        foreach ($rows as $row) {
            $area = trim((string) ($row['area'] ?? ''));
            if ($area === '') {
                continue;
            }
            $richness = $richnessByArea[$area] ?? 0.0;
            if ($richness <= 0.0) {
                continue;
            }
            if ($row['viirs'] !== null) {
                $points['light_richness'][] = ['x' => round((float) $row['viirs'], 4), 'y' => round($richness, 2), 'area' => $area, 'site_name' => $area];
            }
            if ($row['ndvi'] !== null) {
                $points['ndvi_richness'][] = ['x' => round((float) $row['ndvi'], 4), 'y' => round($richness, 2), 'area' => $area, 'site_name' => $area];
            }
            if ($row['lst'] !== null) {
                $points['lst_richness'][] = ['x' => round((float) $row['lst'], 4), 'y' => round($richness, 2), 'area' => $area, 'site_name' => $area];
            }
            if ($row['precipitation'] !== null) {
                $points['precipitation_richness'][] = ['x' => round((float) $row['precipitation'], 4), 'y' => round($richness, 2), 'area' => $area, 'site_name' => $area];
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

    $areaListSql = "'" . implode("','", array_map(static fn($c) => str_replace("'", "''", $c), $cities)) . "'";
    $snapshotRangeSql = buildSnapshotRangeSql('r');
    $richnessSql = "SELECT m.area, COUNT(DISTINCT r.species_id) AS bird_richness
        FROM raw_bird_observation r
        JOIN city_grid_map m ON m.lat = r.grid_lat AND m.lon = r.grid_lon
        WHERE r.year IS NOT NULL AND r.species_id IS NOT NULL
          AND {$snapshotRangeSql}
          AND m.area IN ({$areaListSql})";

    $params = [
                ':snapshot_start_year' => $snapshotStartYear,
                ':snapshot_start_month' => $snapshotStartMonth,
                ':snapshot_end_year' => $snapshotEndYear,
                ':snapshot_end_month' => $snapshotEndMonth,
    ];

    if ($selectedArea !== 'All Areas') {
        $richnessSql .= "\n          AND m.area = :selected_area";
        $params[':selected_area'] = $selectedArea;
    }

    $richnessSql .= "\n        GROUP BY m.area
        HAVING COUNT(DISTINCT r.species_id) > 0
        ORDER BY m.area ASC";

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
            COALESCE(
                (AVG(CASE WHEN lt.lst_day > 100 THEN (lt.lst_day * 0.02) - 273.15
                          WHEN lt.lst_day > 0 THEN lt.lst_day
                          ELSE NULL END) +
                 AVG(CASE WHEN lt.lst_night > 100 THEN (lt.lst_night * 0.02) - 273.15
                          WHEN lt.lst_night > 0 THEN lt.lst_night
                          ELSE NULL END)) / 2.0,
                AVG(CASE WHEN lt.lst_day > 100 THEN (lt.lst_day * 0.02) - 273.15
                         WHEN lt.lst_day > 0 THEN lt.lst_day
                         ELSE NULL END)
            ) AS lst,
            AVG(p.annual_precip) AS precipitation
        FROM city_grid_map cells
        LEFT JOIN viirs v
                            ON (v.year * 100 + v.month) BETWEEN :viirs_start_ym AND :viirs_end_ym
           AND v.latitude = cells.lat
           AND v.longitude = cells.lon
        LEFT JOIN ndvi n
                            ON (n.year * 100 + n.month) BETWEEN :ndvi_start_ym AND :ndvi_end_ym
           AND n.latitude = cells.lat
           AND n.longitude = cells.lon
        LEFT JOIN land_temp lt
                            ON (lt.year * 100 + lt.month) BETWEEN :lst_start_ym AND :lst_end_ym
           AND lt.latitude = cells.lat
           AND lt.longitude = cells.lon
        LEFT JOIN (
            SELECT p2.latitude, p2.longitude,
                SUM(CASE WHEN p2.precip_mm >= 0 THEN p2.precip_mm ELSE NULL END) AS annual_precip
            FROM precip p2
            WHERE (p2.year * 100 + p2.month) BETWEEN :precip_start_ym AND :precip_end_ym
            GROUP BY p2.latitude, p2.longitude
        ) p ON p.latitude = cells.lat
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
    $startYm = ($snapshotStartYear * 100) + $snapshotStartMonth;
    $endYm = ($snapshotEndYear * 100) + $snapshotEndMonth;
    $envStmt->bindValue(':viirs_start_ym', $startYm, PDO::PARAM_INT);
    $envStmt->bindValue(':viirs_end_ym', $endYm, PDO::PARAM_INT);
    $envStmt->bindValue(':ndvi_start_ym', $startYm, PDO::PARAM_INT);
    $envStmt->bindValue(':ndvi_end_ym', $endYm, PDO::PARAM_INT);
    $envStmt->bindValue(':lst_start_ym', $startYm, PDO::PARAM_INT);
    $envStmt->bindValue(':lst_end_ym', $endYm, PDO::PARAM_INT);
    $envStmt->bindValue(':precip_start_ym', $startYm, PDO::PARAM_INT);
    $envStmt->bindValue(':precip_end_ym', $endYm, PDO::PARAM_INT);
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

    $monthlyMediansByArea = fetchAreaMonthlyEnvMedians(
        $pdo,
        $cities,
        $selectedArea,
        $snapshotStartYear,
        $snapshotStartMonth,
        $snapshotEndYear,
        $snapshotEndMonth
    );
    $yearlyEnvFallbackByArea = fetchAreaYearlyEnvAverages(
        $pdo,
        $cities,
        $selectedArea,
        $snapshotStartYear,
        $snapshotEndYear
    );

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
        $yearlyFallback = $yearlyEnvFallbackByArea[$area] ?? [];
        if ($env['viirs'] === null && isset($med['viirs'])) {
            $env['viirs'] = (float) $med['viirs'];
        }
        if ($env['viirs'] === null && isset($yearlyFallback['viirs'])) {
            $env['viirs'] = (float) $yearlyFallback['viirs'];
        }
        if ($env['ndvi'] === null && isset($med['ndvi'])) {
            $env['ndvi'] = (float) $med['ndvi'];
        }
        if ($env['ndvi'] === null && isset($yearlyFallback['ndvi'])) {
            $env['ndvi'] = (float) $yearlyFallback['ndvi'];
        }
        if ($env['lst'] === null && isset($med['lst'])) {
            $env['lst'] = (float) $med['lst'];
        }
        if ($env['lst'] === null && isset($yearlyFallback['lst'])) {
            $env['lst'] = (float) $yearlyFallback['lst'];
        }
        if ($env['precipitation'] === null && isset($med['precipitation'])) {
            $env['precipitation'] = (float) $med['precipitation'];
        }
        if ($env['precipitation'] === null && isset($yearlyFallback['precipitation'])) {
            $env['precipitation'] = (float) $yearlyFallback['precipitation'];
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

function fetchAreaMonthlyEnvMedians(PDO $pdo, array $cities, string $selectedArea, int $startYear, int $startMonth, int $endYear, int $endMonth): array {
    $areaListSql = "'" . implode("','", array_map(static fn($c) => str_replace("'", "''", $c), $cities)) . "'";
    $filters = '';
    if ($selectedArea !== 'All Areas') {
        $filters = "\n          AND cells.area = :selected_area";
    }

    $queries = [
        'viirs' => "SELECT cells.area AS area, v.year AS yr, v.month AS mo, AVG(NULLIF(v.viirs_avg_rad, 0)) AS value
            FROM city_grid_map cells
            JOIN viirs v
              ON v.latitude = cells.lat
             AND v.longitude = cells.lon
             AND (v.year * 100 + v.month) BETWEEN :start_ym AND :end_ym
            WHERE cells.area IN ({$areaListSql}){$filters}
            GROUP BY cells.area, v.year, v.month",
        'ndvi' => "SELECT cells.area AS area, n.year AS yr, n.month AS mo, AVG(
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
             AND (n.year * 100 + n.month) BETWEEN :start_ym AND :end_ym
            WHERE cells.area IN ({$areaListSql}){$filters}
            GROUP BY cells.area, n.year, n.month",
        'lst' => "SELECT cells.area AS area, lt.year AS yr, lt.month AS mo, AVG(
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
                         AND (lt.year * 100 + lt.month) BETWEEN :start_ym AND :end_ym
            WHERE cells.area IN ({$areaListSql}){$filters}
                        GROUP BY cells.area, lt.year, lt.month",
                'precipitation' => "SELECT cells.area AS area, p.year AS yr, p.month AS mo, AVG(CASE WHEN p.precip_mm >= 0 THEN p.precip_mm ELSE NULL END) AS value
            FROM city_grid_map cells
            JOIN precip p
              ON p.latitude = cells.lat
             AND p.longitude = cells.lon
                         AND (p.year * 100 + p.month) BETWEEN :start_ym AND :end_ym
            WHERE cells.area IN ({$areaListSql}){$filters}
                        GROUP BY cells.area, p.year, p.month",
    ];

    $values = [];
    foreach ($queries as $key => $sql) {
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':start_ym', ($startYear * 100) + $startMonth, PDO::PARAM_INT);
        $stmt->bindValue(':end_ym', ($endYear * 100) + $endMonth, PDO::PARAM_INT);
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

function fetchAreaYearlyEnvAverages(PDO $pdo, array $cities, string $selectedArea, int $startYear, int $endYear): array {
    $areaListSql = "'" . implode("','", array_map(static fn($c) => str_replace("'", "''", $c), $cities)) . "'";
    $filters = '';
    if ($selectedArea !== 'All Areas') {
        $filters = "\n          AND area = :selected_area";
    }

    $sql = "SELECT
            area,
            AVG(viirs_avg) AS viirs,
            AVG(ndvi_avg) AS ndvi,
            MAX(lst_avg) AS lst,
            AVG(precipitation_total) AS precipitation
        FROM ecological_yearly_summary
        WHERE year BETWEEN :start_year AND :end_year
          AND area IN ({$areaListSql}){$filters}
        GROUP BY area";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':start_year', $startYear, PDO::PARAM_INT);
    $stmt->bindValue(':end_year', $endYear, PDO::PARAM_INT);
    if ($selectedArea !== 'All Areas') {
        $stmt->bindValue(':selected_area', $selectedArea, PDO::PARAM_STR);
    }
    $stmt->execute();

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $values = [];
    foreach ($rows as $row) {
        $area = trim((string) ($row['area'] ?? ''));
        if ($area === '') {
            continue;
        }
        $values[$area] = [
            'viirs' => isset($row['viirs']) ? (float) $row['viirs'] : null,
            'ndvi' => isset($row['ndvi']) ? (float) $row['ndvi'] : null,
            'lst' => isset($row['lst']) ? (float) $row['lst'] : null,
            'precipitation' => isset($row['precipitation']) ? (float) $row['precipitation'] : null,
        ];
    }

    return $values;
}

function fetchHistoricalYearlyPrecipitationSeries(PDO $pdo, array $cities, string $selectedArea, int $startYear, int $endYear): array {
    $areaListSql = "'" . implode("','", array_map(static fn($c) => str_replace("'", "''", $c), $cities)) . "'";

    if ($selectedArea === 'All Areas') {
        $sql = "SELECT prec.year AS year, AVG(prec.area_annual_precip) AS precipitation
            FROM (
                SELECT cells.area AS area, cell_precip.year AS year, AVG(cell_precip.annual_precip) AS area_annual_precip
                FROM (
                    SELECT g.lat, g.lon, g.year,
                        SUM(CASE WHEN g.monthly_precip_mm >= 0 THEN g.monthly_precip_mm ELSE NULL END) AS annual_precip
                    FROM final_master_grid g
                    WHERE g.year BETWEEN :start_year AND :end_year
                    GROUP BY g.lat, g.lon, g.year
                ) cell_precip
                JOIN city_grid_map cells
                  ON ROUND(cells.lat, 6) = ROUND(cell_precip.lat, 6)
                 AND ROUND(cells.lon, 6) = ROUND(cell_precip.lon, 6)
                WHERE cells.area IN ({$areaListSql})
                GROUP BY cells.area, cell_precip.year
            ) prec
            GROUP BY prec.year
            ORDER BY prec.year ASC";
    } else {
        $sql = "SELECT prec.year AS year, prec.area_annual_precip AS precipitation
            FROM (
                SELECT cells.area AS area, cell_precip.year AS year, AVG(cell_precip.annual_precip) AS area_annual_precip
                FROM (
                    SELECT g.lat, g.lon, g.year,
                        SUM(CASE WHEN g.monthly_precip_mm >= 0 THEN g.monthly_precip_mm ELSE NULL END) AS annual_precip
                    FROM final_master_grid g
                    WHERE g.year BETWEEN :start_year AND :end_year
                    GROUP BY g.lat, g.lon, g.year
                ) cell_precip
                JOIN city_grid_map cells
                  ON ROUND(cells.lat, 6) = ROUND(cell_precip.lat, 6)
                 AND ROUND(cells.lon, 6) = ROUND(cell_precip.lon, 6)
                WHERE cells.area = :selected_area
                GROUP BY cells.area, cell_precip.year
            ) prec
            ORDER BY prec.year ASC";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':start_year', $startYear, PDO::PARAM_INT);
    $stmt->bindValue(':end_year', $endYear, PDO::PARAM_INT);
    if ($selectedArea !== 'All Areas') {
        $stmt->bindValue(':selected_area', $selectedArea, PDO::PARAM_STR);
    }
    $stmt->execute();

    $precipitationByYear = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $year = (int) ($row['year'] ?? 0);
        if ($year <= 0) {
            continue;
        }
        $precipitationByYear[$year] = isset($row['precipitation']) ? (float) $row['precipitation'] : null;
    }

    return $precipitationByYear;
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

function fetchTopSitesRichnessData(PDO $pdo, array $cities, string $selectedArea, int $snapshotStartYear, int $snapshotStartMonth, int $snapshotEndYear, int $snapshotEndMonth, int $limit = 10): array {
    if (snapshotSummaryHasRows($pdo, $selectedArea, $snapshotStartYear, $snapshotStartMonth, $snapshotEndYear, $snapshotEndMonth)) {
        $selectArea  = ($selectedArea === 'All Areas') ? "'All Areas' AS area" : 'm.area AS area';
        $groupByArea = ($selectedArea === 'All Areas') ? '' : 'm.area,';
        $sql = "SELECT
                                COALESCE(NULLIF(TRIM(r.site_name), ''), 'Unknown Site') AS site_name,
                                {$selectArea},
                                COUNT(DISTINCT r.species_id) AS bird_richness
                        FROM raw_bird_observation r
                        JOIN city_grid_map m ON m.lat = r.grid_lat AND m.lon = r.grid_lon
                        WHERE r.year IS NOT NULL AND r.species_id IS NOT NULL
                            AND " . buildSnapshotRangeSql('r') . "
                            AND (:selected_area_all = 'All Areas' OR m.area = :selected_area_value)
                        GROUP BY {$groupByArea} COALESCE(NULLIF(TRIM(r.site_name), ''), 'Unknown Site')
                        HAVING COUNT(DISTINCT r.species_id) > 0
            ORDER BY bird_richness DESC, site_name ASC
            LIMIT :lim";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':snapshot_start_year', $snapshotStartYear, PDO::PARAM_INT);
        $stmt->bindValue(':snapshot_start_month', $snapshotStartMonth, PDO::PARAM_INT);
        $stmt->bindValue(':snapshot_end_year', $snapshotEndYear, PDO::PARAM_INT);
        $stmt->bindValue(':snapshot_end_month', $snapshotEndMonth, PDO::PARAM_INT);
        $stmt->bindValue(':selected_area_all', $selectedArea, PDO::PARAM_STR);
        $stmt->bindValue(':selected_area_value', $selectedArea, PDO::PARAM_STR);
        $stmt->bindValue(':lim', max(1, (int) $limit), PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Ensure returned rows actually belong to the requested area when scoped.
        if ($selectedArea !== 'All Areas' && !empty($rows)) {
            $rows = array_values(array_filter($rows, static function ($r) use ($selectedArea) {
                $area = trim((string) ($r['area'] ?? ''));
                return $area === $selectedArea;
            }));
        }

        $labels = [];
        $values = [];
        $details = [];
        $rank = 1;
        foreach ($rows as $row) {
            $siteName = trim((string) ($row['site_name'] ?? ''));
            $area = trim((string) ($row['area'] ?? ''));
            if ($siteName === '' || $area === '') {
                continue;
            }
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
            'total_sites' => count($details),
            'selected_area' => $selectedArea,
            'snapshot_start_year' => $snapshotStartYear,
            'snapshot_start_month' => $snapshotStartMonth,
            'snapshot_end_year' => $snapshotEndYear,
            'snapshot_end_month' => $snapshotEndMonth,
            'snapshot_year' => $snapshotEndYear,
            'snapshot_month' => $snapshotEndMonth,
        ];
    }

    $areaClause = '';
    if ($selectedArea !== 'All Areas') {
        $areaClause = ' AND m.area = :selected_area';
    }
    $snapshotRangeSql = buildSnapshotRangeSql('r');

    $selectArea2  = ($selectedArea === 'All Areas') ? "'All Areas' AS area" : 'm.area AS area';
    $groupByArea2 = ($selectedArea === 'All Areas') ? '' : 'm.area,';
    $sql = "SELECT STRAIGHT_JOIN
                        COALESCE(NULLIF(TRIM(r.site_name), ''), 'Unknown Site') AS site_name,
            {$selectArea2},
                        COUNT(DISTINCT r.species_id) AS bird_richness
                FROM raw_bird_observation r
                JOIN city_grid_map m ON m.lat = r.grid_lat AND m.lon = r.grid_lon
        WHERE r.year IS NOT NULL AND r.species_id IS NOT NULL
          AND {$snapshotRangeSql}
          {$areaClause}
                GROUP BY {$groupByArea2} COALESCE(NULLIF(TRIM(r.site_name), ''), 'Unknown Site')
        HAVING COUNT(DISTINCT r.species_id) > 0
        ORDER BY bird_richness DESC, site_name ASC
        LIMIT :lim";

    $params = [
        ':snapshot_start_year' => $snapshotStartYear,
        ':snapshot_start_month' => $snapshotStartMonth,
        ':snapshot_end_year' => $snapshotEndYear,
        ':snapshot_end_month' => $snapshotEndMonth,
    ];
    if ($selectedArea !== 'All Areas') {
        $params[':selected_area'] = $selectedArea;
    }

    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->bindValue(':lim', max(1, (int) $limit), PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Ensure returned rows actually belong to the requested area when scoped.
    if ($selectedArea !== 'All Areas' && !empty($rows)) {
        $rows = array_values(array_filter($rows, static function ($r) use ($selectedArea) {
            $area = trim((string) ($r['area'] ?? ''));
            return $area === $selectedArea;
        }));
    }

    $labels = [];
    $values = [];
    $details = [];
    $rank = 1;
    foreach ($rows as $row) {
        $siteName = trim((string) ($row['site_name'] ?? ''));
        $area = trim((string) ($row['area'] ?? ''));
        if ($siteName === '' || $area === '') {
            continue;
        }
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
        'total_sites' => count($details),
        'selected_area' => $selectedArea,
        'snapshot_start_year' => $snapshotStartYear,
        'snapshot_start_month' => $snapshotStartMonth,
        'snapshot_end_year' => $snapshotEndYear,
        'snapshot_end_month' => $snapshotEndMonth,
        'snapshot_year' => $snapshotEndYear,
        'snapshot_month' => $snapshotEndMonth,
    ];
}

function fetchDiagnosticsYearlySeries(PDO $pdo, string $selectedArea, int $startYear, int $endYear): array {
    $envByYear = [];
    $classByYear = [];
    $richnessByYear = [];

    if ($selectedArea === 'All Areas') {
        $richnessStmt = $pdo->prepare("SELECT
                r.year,
                COUNT(DISTINCT r.species_id) AS bird_richness
            FROM raw_bird_observation r
            JOIN observation_city_map m
                ON m.rbo_id = r.id
            WHERE r.year BETWEEN :start_year AND :end_year
              AND r.species_id IS NOT NULL
            GROUP BY r.year
            ORDER BY r.year ASC");
        $richnessStmt->bindValue(':start_year', $startYear, PDO::PARAM_INT);
        $richnessStmt->bindValue(':end_year', $endYear, PDO::PARAM_INT);
        $richnessStmt->execute();

        foreach (($richnessStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
            $year = (int) ($row['year'] ?? 0);
            if ($year > 0) {
                $richnessByYear[$year] = isset($row['bird_richness']) ? (float) $row['bird_richness'] : null;
            }
        }

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
                ON m.rbo_id = r.id
            WHERE r.year BETWEEN :start_year AND :end_year
              AND r.species_id IS NOT NULL
            GROUP BY r.year
            ORDER BY r.year ASC");
        $classStmt->bindValue(':start_year', $startYear, PDO::PARAM_INT);
        $classStmt->bindValue(':end_year', $endYear, PDO::PARAM_INT);
        $classStmt->execute();
    } else {
        $richnessStmt = $pdo->prepare("SELECT
                r.year,
                COUNT(DISTINCT r.species_id) AS bird_richness
            FROM raw_bird_observation r
            JOIN observation_city_map m
                ON m.rbo_id = r.id
            WHERE m.area = :area
              AND r.year BETWEEN :start_year AND :end_year
              AND r.species_id IS NOT NULL
            GROUP BY r.year
            ORDER BY r.year ASC");
        $richnessStmt->bindValue(':area', $selectedArea, PDO::PARAM_STR);
        $richnessStmt->bindValue(':start_year', $startYear, PDO::PARAM_INT);
        $richnessStmt->bindValue(':end_year', $endYear, PDO::PARAM_INT);
        $richnessStmt->execute();

        foreach (($richnessStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
            $year = (int) ($row['year'] ?? 0);
            if ($year > 0) {
                $richnessByYear[$year] = isset($row['bird_richness']) ? (float) $row['bird_richness'] : null;
            }
        }

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
                ON m.rbo_id = r.id
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
            'bird_richness' => isset($richnessByYear[$y]) ? (float) $richnessByYear[$y] : null,
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
function resolve_model_bundle_path(string $filePath): ?string {
    $filePath = trim($filePath);
    if ($filePath === '') {
        return null;
    }

    $candidates = [];
    $projectRoot = realpath(__DIR__ . '/..');
    $apiModelsRoot = realpath(__DIR__ . '/../api_models');

    if (preg_match('/^[A-Za-z]:[\\\\\/]/', $filePath) || str_starts_with($filePath, '\\') || str_starts_with($filePath, '/')) {
        $candidates[] = $filePath;
    } else {
        if ($projectRoot !== false) {
            $candidates[] = $projectRoot . DIRECTORY_SEPARATOR . ltrim($filePath, '/\\');
        }
        if ($apiModelsRoot !== false) {
            $candidates[] = $apiModelsRoot . DIRECTORY_SEPARATOR . ltrim($filePath, '/\\');
        }
    }

    foreach ($candidates as $candidate) {
        $resolved = realpath($candidate);
        if ($resolved !== false) {
            return $resolved;
        }
    }

    return null;
}

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
        'trainingMetrics' => (isset($raw['trainingMetrics']) && is_array($raw['trainingMetrics']))
            ? $raw['trainingMetrics']
            : [],
        'testingMetrics' => (isset($raw['testingMetrics']) && is_array($raw['testingMetrics']))
            ? $raw['testingMetrics']
            : [],
        'metricsSource' => (string) ($raw['metricsSource'] ?? ''),
    ];
}

function buildDiagnosticsConvLstmSeries(array $seriesRows, array $sourcePredictions, int $startYear = 2014, int $endYear = 2025): array {
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
            if (array_key_exists($index, $seriesActual) && $seriesActual[$index] !== null && $seriesActual[$index] !== '') {
                $actualByYear[$filter][$year] = (float) $seriesActual[$index];
            } else {
                $actualByYear[$filter][$year] = null;
            }

            if (array_key_exists($index, $seriesPredicted) && $seriesPredicted[$index] !== null && $seriesPredicted[$index] !== '') {
                $predictedByYear[$filter][$year] = (float) $seriesPredicted[$index];
            } else {
                $predictedByYear[$filter][$year] = null;
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
            $birdRichness = (float) ($row['bird_richness'] ?? 0.0);
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
                    $actualValue = $birdRichness > 0 ? $birdRichness : (($tolerant + $sensitive) + ($resident + $migrant)) / 2.0;
                    break;
            }

            if (isset($actualByYear[$filter]) && array_key_exists($year, $actualByYear[$filter])) {
                $sourceActual = $actualByYear[$filter][$year];
                // If source artifact has null actual (common for latest year), use computed yearly actual fallback.
                $actualOut[] = ($sourceActual === null)
                    ? round((float) $actualValue, 4)
                    : round((float) $sourceActual, 4);
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

        $activeDir = resolve_model_bundle_path((string) $active['file_path']);
        if ($activeDir === null || !$hasRequired($activeDir)) {
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
    $diagEndYear = 2025;
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

    [$yearMin, $yearMax] = safeReportYearBounds($mysql);
    if ($yearMin <= 0 || $yearMax <= 0 || $yearMin > $yearMax) {
        $yearMin = 2014;
        $yearMax = 2025;
    }

    $start_year = max($yearMin, min($yearMax, $start_year));
    $end_year = max($yearMin, min($yearMax, $end_year));
    if ($start_year > $end_year) {
        $end_year = $start_year;
    }
    $snapshot_start_year = max($yearMin, min($yearMax, $snapshot_start_year));
    $snapshot_end_year = max($yearMin, min($yearMax, $snapshot_end_year));
    $snapshot_start_month = max(1, min(12, $snapshot_start_month));
    $snapshot_end_month = max(1, min(12, $snapshot_end_month));

    $snapshotStartYm = ($snapshot_start_year * 100) + $snapshot_start_month;
    $snapshotEndYm = ($snapshot_end_year * 100) + $snapshot_end_month;
    if ($snapshotStartYm > $snapshotEndYm) {
        $tmpYear = $snapshot_start_year;
        $tmpMonth = $snapshot_start_month;
        $snapshot_start_year = $snapshot_end_year;
        $snapshot_start_month = $snapshot_end_month;
        $snapshot_end_year = $tmpYear;
        $snapshot_end_month = $tmpMonth;
    }

    $snapshot_year = $snapshot_end_year;
    $snapshot_month = $snapshot_end_month;

    if (!in_array($selected_area, array_merge(['All Areas'], $metro_manila_cities), true)) {
        $selected_area = 'All Areas';
    }

    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0775, true);
    }

    // Include spatial mapping counts in cache key so cached report payloads
    // are invalidated when the observation_city_map / city_grid_map changes.
    try {
        $mappedObsCount = (int) $mysql->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = "observation_city_map"')->fetchColumn();
        if ($mappedObsCount) {
            $mappedObsCount = (int) $mysql->query('SELECT COUNT(*) FROM observation_city_map')->fetchColumn();
        } else {
            $mappedObsCount = 0;
        }
        $mappedGridCount = (int) $mysql->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = "city_grid_map"')->fetchColumn();
        if ($mappedGridCount) {
            $mappedGridCount = (int) $mysql->query('SELECT COUNT(*) FROM city_grid_map')->fetchColumn();
        } else {
            $mappedGridCount = 0;
        }
    } catch (Throwable $e) {
        $mappedObsCount = 0;
        $mappedGridCount = 0;
    }

    $cacheKey = 'reports:' . $reportCacheSchemaVersion . ':' . $selected_area . ':' . $start_year . ':' . $end_year . ':' . $snapshot_start_year . ':' . $snapshot_start_month . ':' . $snapshot_end_year . ':' . $snapshot_end_month . ':mappedObs=' . $mappedObsCount . ':mappedGrid=' . $mappedGridCount . ':diag=' . ($include_diagnostics ? '1' : '0');
    $cacheFile = $cacheDir . '/' . sha1($cacheKey) . '.json';

    $cached = $force_refresh ? null : cacheGet($cacheFile, $cacheTtlSeconds);
    if (is_array($cached)) {
        echo json_encode($cached, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        exit;
    }

    // If caller requested a forced refresh, rebuild spatial mapping tables
    // so top-sites and snapshot queries use the freshest city mapping.
    $spatialMapStats = ['skipped' => true];
    if ($force_refresh) {
        try {
            $spatialMapStats = refreshSpatialMaps($mysql, $metro_manila_cities, 0);
        } catch (Throwable $smEx) {
            // keep 'skipped' flag but record error in meta later
            $spatialMapStats = ['skipped' => true, 'error' => $smEx->getMessage()];
        }
    }

    if ($scope === 'snapshot') {
        $snapshotDistributionsError = '';
        $snapshotScatterError = '';
        $topSitesError = '';
        try {
            $snapshotDistributions = fetchSnapshotSpeciesDistributions(
                $mysql,
                $selected_area,
                $snapshot_start_year,
                $snapshot_start_month,
                $snapshot_end_year,
                $snapshot_end_month
            );
        } catch (Throwable $distEx) {
            $snapshotDistributionsError = $distEx->getMessage();
            $snapshotDistributions = [
                'migration_status' => ['labels' => [], 'data' => [], 'total_species' => 0],
                'light_tolerance' => ['labels' => [], 'data' => [], 'total_species' => 0],
            ];
        }

        try {
            $snapshotScatterData = fetchSnapshotScatterData(
                $mysql,
                $metro_manila_cities,
                $selected_area,
                $snapshot_start_year,
                $snapshot_start_month,
                $snapshot_end_year,
                $snapshot_end_month
            );
        } catch (Throwable $scatterEx) {
            $snapshotScatterData = emptySnapshotScatterData();
            $snapshotScatterError = $scatterEx->getMessage();
        }

        try {
            $topSitesRichnessData = fetchTopSitesRichnessData(
                $mysql,
                $metro_manila_cities,
                $selected_area,
                $snapshot_start_year,
                $snapshot_start_month,
                $snapshot_end_year,
                $snapshot_end_month,
                10
            );
        } catch (Throwable $topSitesEx) {
            $topSitesError = $topSitesEx->getMessage();
            $topSitesRichnessData = ['labels' => [], 'data' => [], 'rows' => []];
        }

        $payload = [
            'success' => true,
            'area' => $selected_area,
            'start_year' => $start_year,
            'end_year' => $end_year,
            'data' => [],
            'units' => $units,
            'normalized' => null,
            'meta' => [
                'available_areas' => $metro_manila_cities,
                'year_min' => $yearMin,
                'year_max' => $yearMax,
                'cache_key' => $cacheKey,
                'spatial_mapping' => $spatialMapStats,
                'diagnostics_included' => $include_diagnostics,
                'scope' => $scope,
                'diagnostics_source' => 'not_requested',
                'diagnostics_error' => '',
                'snapshot_scatter_error' => $snapshotScatterError,
                'snapshot_distributions_error' => $snapshotDistributionsError,
                'top_sites_error' => $topSitesError,
                'diagnostics_model_dir_source' => (string) ($resolvedModelDirInfo['source'] ?? 'unknown'),
                'diagnostics_model_dir_note' => (string) ($resolvedModelDirInfo['note'] ?? ''),
                'diagnostics_metrics_source' => '',
            ],
            'validation' => [
                'ndvi_out_of_range' => 0,
                'lst_out_of_range' => 0,
                'precip_low' => 0,
                'precip_high' => 0,
                'missing_years' => [],
                'null_counts' => [],
                'scaling_corrections_applied' => [
                    'ndvi_divided_by_10000_when_abs_gt_1' => true,
                    'lst_modis_scale_0_02_then_kelvin_to_celsius' => true,
                    'precipitation_monthly_avg_mm_spatial_avg_across_cells' => true,
                ],
            ],
            'filters' => [
                'selected_area' => $selected_area,
                'start_year' => $start_year,
                'end_year' => $end_year,
                'snapshot_start_year' => $snapshot_start_year,
                'snapshot_start_month' => $snapshot_start_month,
                'snapshot_end_year' => $snapshot_end_year,
                'snapshot_end_month' => $snapshot_end_month,
                'snapshot_year' => $snapshot_year,
                'snapshot_month' => $snapshot_month,
            ],
            'trendHistoricalData' => ['labels' => [], 'richness' => [], 'viirs' => [], 'ndvi' => [], 'lst' => [], 'precip' => []],
            'trendCorrelationData' => ['richness_viirs' => 0.0, 'richness_ndvi' => 0.0, 'richness_lst' => 0.0, 'richness_precip' => 0.0],
            'snapshotDistributions' => $snapshotDistributions,
            'snapshotScatterData' => $snapshotScatterData,
            'topSitesRichnessData' => $topSitesRichnessData,
            'xgboostFeatureImportance' => [],
            'convlstmPredictions' => [],
            'ensembleMetrics' => ['ensemble_average' => ['rmse' => 0.0, 'mae' => 0.0, 'r2' => 0.0], 'by_class' => []],
        ];

        cachePut($cacheFile, $payload);
        echo json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        exit;
    }

    ensureSummaryTable($mysql);
    ensureReportQueryIndexes($mysql);
    ensureSpatialMapTables($mysql);
    $mappedObsCount = (int) $mysql->query('SELECT COUNT(*) FROM observation_city_map')->fetchColumn();
    $mappedGridCount = (int) $mysql->query('SELECT COUNT(*) FROM city_grid_map')->fetchColumn();
    if ($mappedObsCount === 0 || $mappedGridCount === 0) {
        $spatialMapStats = refreshSpatialMaps($mysql, $metro_manila_cities, 86400);
    } else {
        $spatialMapStats = [
            'source_obs_count' => 0,
            'mapped_obs_count' => $mappedObsCount,
            'source_grid_count' => 0,
            'mapped_grid_count' => $mappedGridCount,
            'skipped' => true,
            'elapsed_s' => 0.0,
        ];
    }

    // ecological_yearly_summary is now refreshed manually only via the admin/CLI pipeline.
    // Report reads should never mutate it, even when the table is stale.
    $refreshChecks = [
        'ndvi_out_of_range' => 0,
        'lst_out_of_range' => 0,
        'precip_low' => 0,
        'precip_high' => 0,
    ];

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


    $unified = [];
    $nullCounts = [
        'bird_richness' => 0,
        'viirs' => 0,
        'ndvi' => 0,
        'lst' => 0,
        'precipitation' => 0,
    ];
    $missingYears = [];
    $correlation = [
        'richness_viirs' => 0.0,
        'richness_ndvi' => 0.0,
        'richness_lst' => 0.0,
        'richness_precip' => 0.0,
    ];

    if ($scope !== 'snapshot') {
        $areaListSql = "'" . implode("','", array_map(static fn($c) => str_replace("'", "''", $c), $metro_manila_cities)) . "'";
        if ($selected_area === 'All Areas') {
            $trendSql = "SELECT
                    year,
                    bird_richness,
                    viirs_avg           AS viirs,
                    ndvi_avg            AS ndvi,
                    lst_avg             AS lst,
                    precipitation_total AS precipitation
                FROM metro_yearly_richness
                WHERE year BETWEEN :start_year AND :end_year
                ORDER BY year ASC";
            $trendStmt = $mysql->prepare($trendSql);
            if (!$trendStmt) {
                throw new Exception("Failed to prepare all-areas trend query: " . implode(", ", $mysql->errorInfo()));
            }
            $trendStmt->bindValue(':start_year', $start_year, PDO::PARAM_INT);
            $trendStmt->bindValue(':end_year', $end_year, PDO::PARAM_INT);
        } else {
            $trendSql = "SELECT
                    year,
                    bird_richness,
                    viirs_avg AS viirs,
                    ndvi_avg AS ndvi,
                    lst_avg AS lst,
                    precipitation_total AS precipitation
                FROM ecological_yearly_summary
                WHERE area = :area
                  AND year BETWEEN :start_year AND :end_year
                ORDER BY year ASC";
            $trendStmt = $mysql->prepare($trendSql);
            if (!$trendStmt) {
                throw new Exception("Failed to prepare specific-area trend query: " . implode(", ", $mysql->errorInfo()));
            }
            $trendStmt->bindValue(':area', $selected_area, PDO::PARAM_STR);
            $trendStmt->bindValue(':start_year', $start_year, PDO::PARAM_INT);
            $trendStmt->bindValue(':end_year', $end_year, PDO::PARAM_INT);
        }

        try {
            if (!$trendStmt->execute()) {
                throw new Exception("Failed to execute trend query: " . implode(", ", $trendStmt->errorInfo()));
            }
            $seriesRows = $trendStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $precipitationByYear = fetchHistoricalYearlyPrecipitationSeries(
                $mysql,
                $metro_manila_cities,
                $selected_area,
                $start_year,
                $end_year
            );

            foreach ($seriesRows as &$seriesRow) {
                $year = (int) ($seriesRow['year'] ?? 0);
                if ($year > 0 && array_key_exists($year, $precipitationByYear)) {
                    $seriesRow['precipitation'] = $precipitationByYear[$year];
                }
            }
            unset($seriesRow);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Data aggregation failed: ' . $e->getMessage(),
                'debug_filters' => [
                    'selected_area' => $selected_area,
                    'start_year' => $start_year,
                    'end_year' => $end_year,
                    'snapshot_start_year' => $snapshot_start_year,
                    'snapshot_start_month' => $snapshot_start_month,
                    'snapshot_end_year' => $snapshot_end_year,
                    'snapshot_end_month' => $snapshot_end_month,
                    'snapshot_year' => $snapshot_year,
                    'snapshot_month' => $snapshot_month,
                ],
            ]);
            exit;
        }

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

        $corrInput = $seriesRows;

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
    }

    $snapshotDistributions = [];
    $snapshotDistributionsError = '';
    $snapshotScatterData = [];
    $snapshotScatterError = '';
    $topSitesRichnessData = [];
    $topSitesError = '';

    // Snapshot queries are expensive (multi-table spatial joins). Skip them entirely
    // for scope=trend: the JS fires a separate scope=snapshot request for this data
    // and only calls updateTrendCharts() on the trend response, which never reads
    // snapshotDistributions, snapshotScatterData, or topSitesRichnessData.
    if ($scope !== 'trend') {
        try {
            $cachedMetrics = getOrComputeSnapshotMetrics(
                $mysql,
                $selected_area,
                $snapshot_start_year,
                $snapshot_start_month,
                $snapshot_end_year,
                $snapshot_end_month,
                $metro_manila_cities
            );

            if ($cachedMetrics['from_cache']) {
                $snapshotDistributions = [
                    'migration_status' => [
                        'labels' => ['Migratory', 'Resident', 'Unclassified'],
                        'data' => [
                            $cachedMetrics['migration']['migratory'],
                            $cachedMetrics['migration']['resident'],
                            $cachedMetrics['migration']['unclassified'],
                        ],
                        'total_species' => array_sum($cachedMetrics['migration']),
                    ],
                    'light_tolerance' => [
                        'labels' => ['Sensitive', 'Tolerant', 'Unclassified'],
                        'data' => [
                            $cachedMetrics['light']['sensitive'],
                            $cachedMetrics['light']['tolerant'],
                            $cachedMetrics['light']['unclassified'],
                        ],
                        'total_species' => array_sum($cachedMetrics['light']),
                    ],
                ];
            } else {
                $distributions = fetchSnapshotSpeciesDistributions(
                    $mysql,
                    $selected_area,
                    $snapshot_start_year,
                    $snapshot_start_month,
                    $snapshot_end_year,
                    $snapshot_end_month
                );
                $snapshotDistributions = $distributions;

                if (isset($distributions['migration_status']['data'], $distributions['light_tolerance']['data'])) {
                    $mig = $distributions['migration_status']['data'];
                    $light = $distributions['light_tolerance']['data'];
                    cacheSnapshotMetrics(
                        $mysql,
                        $selected_area,
                        $snapshot_start_year,
                        $snapshot_start_month,
                        $snapshot_end_year,
                        $snapshot_end_month,
                        ['migratory' => $mig[0] ?? 0, 'resident' => $mig[1] ?? 0, 'unclassified' => $mig[2] ?? 0],
                        ['sensitive' => $light[0] ?? 0, 'tolerant' => $light[1] ?? 0, 'unclassified' => $light[2] ?? 0],
                        $distributions['migration_status']['total_species'] ?? 0
                    );
                }
            }
        } catch (Throwable $distEx) {
            $snapshotDistributionsError = $distEx->getMessage();
            $snapshotDistributions = [
                'migration_status' => ['labels' => [], 'data' => [], 'total_species' => 0],
                'light_tolerance' => ['labels' => [], 'data' => [], 'total_species' => 0],
            ];
        }

        try {
            $snapshotScatterData = fetchSnapshotScatterData(
                $mysql,
                $metro_manila_cities,
                $selected_area,
                $snapshot_start_year,
                $snapshot_start_month,
                $snapshot_end_year,
                $snapshot_end_month
            );
        } catch (Throwable $scatterEx) {
            $snapshotScatterData = emptySnapshotScatterData();
            $snapshotScatterError = $scatterEx->getMessage();
        }

        try {
            $topSitesRichnessData = fetchTopSitesRichnessData(
                $mysql,
                $metro_manila_cities,
                $selected_area,
                $snapshot_start_year,
                $snapshot_start_month,
                $snapshot_end_year,
                $snapshot_end_month,
                10
            );
        } catch (Throwable $topSitesEx) {
            $topSitesError = $topSitesEx->getMessage();
            $topSitesRichnessData = ['labels' => [], 'data' => [], 'rows' => []];
        }
    }


    $trendHistoricalData = [];
    $normalized = null;

    if (!empty($unified) && is_array($unified)) {
        try {
            $trendHistoricalData = [
                'labels' => array_map(static fn($r) => (int) $r['year'], $unified),
                'richness' => array_map(static fn($r) => $r['bird_richness'], $unified),
                'viirs' => array_map(static fn($r) => $r['viirs'], $unified),
                'ndvi' => array_map(static fn($r) => $r['ndvi'], $unified),
                'lst' => array_map(static fn($r) => $r['lst'], $unified),
                'precip' => array_map(static fn($r) => $r['precipitation'], $unified),
            ];
        } catch (Throwable $trendEx) {
            $trendHistoricalData = ['labels' => [], 'richness' => [], 'viirs' => [], 'ndvi' => [], 'lst' => [], 'precip' => []];
        }
    }

    if ($include_normalized) {
        try {
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
        } catch (Throwable $normEx) {
            $normalized = null;
        }
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
            'snapshot_distributions_error' => $snapshotDistributionsError ?? '',
            'top_sites_error' => $topSitesError ?? '',
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
            'snapshot_start_year' => $snapshot_start_year,
            'snapshot_start_month' => $snapshot_start_month,
            'snapshot_end_year' => $snapshot_end_year,
            'snapshot_end_month' => $snapshot_end_month,
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
