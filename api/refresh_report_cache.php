<?php
/**
 * api/refresh_report_cache.php
 *
 * Dedicated pre-computation endpoint for the Reports tab.
 * Forces a rebuild of:
 *   1. Spatial city maps (observation_city_map, city_grid_map)
 *   2. Ecological yearly summary (ecological_yearly_summary)
 *   3. File-based report JSON caches (data/cache/reports/)
 *
 * Intended to be called:
 *   - Manually from the admin panel after new data is uploaded.
 *   - Via a scheduled task / cron job (e.g., nightly at 02:00).
 *
 * Requires admin authentication. Returns JSON.
 *
 * Usage:
 *   POST /api/refresh_report_cache.php
 *   POST /api/refresh_report_cache.php?force=1   (skip staleness check)
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/auth.php';
require_admin();
require_once __DIR__ . '/../includes/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST required']);
    exit;
}

@set_time_limit(0);

// ── Inline copies of the pipeline functions (mirrors api/get_report_data.php) ──

$metro_manila_cities = [
    'Caloocan', 'Las Piñas', 'Makati', 'Malabon', 'Mandaluyong',
    'Manila', 'Marikina', 'Muntinlupa', 'Navotas', 'Parañaque',
    'Pasay', 'Pasig', 'Pateros', 'Quezon City', 'San Juan',
    'Taguig', 'Valenzuela',
];

function rrc_buildCityInlineSql(array $cities): string {
    $parts = [];
    foreach ($cities as $city) {
        $safe = str_replace("'", "''", $city);
        $parts[] = "SELECT '{$safe}' COLLATE utf8mb4_general_ci AS area";
    }
    return implode(' UNION ALL ', $parts);
}

function rrc_normalizeCityKey(string $city): string {
    $normalized = mb_strtolower(trim($city), 'UTF-8');
    $normalized = strtr($normalized, [
        'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'ã' => 'a',
        'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
        'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'õ' => 'o',
        'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
        'ñ' => 'n', 'ç' => 'c',
    ]);
    return (string) preg_replace('/\s+/', ' ', $normalized);
}

function rrc_flattenCityPolygons(array $geometry): array {
    $type   = $geometry['type'] ?? '';
    $coords = $geometry['coordinates'] ?? [];
    if ($type === 'Polygon') {
        return [$coords];
    }
    if ($type === 'MultiPolygon') {
        return $coords;
    }
    return [];
}

function rrc_ringContainsPoint(array $ring, float $x, float $y): bool {
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

function rrc_polygonContainsPoint(array $polygon, float $x, float $y): bool {
    if (empty($polygon) || !rrc_ringContainsPoint($polygon[0], $x, $y)) {
        return false;
    }
    foreach (array_slice($polygon, 1) as $hole) {
        if (rrc_ringContainsPoint($hole, $x, $y)) {
            return false;
        }
    }
    return true;
}

function rrc_loadCityPolygons(array $cities): array {
    $path = __DIR__ . '/../MM_Cities_WGS84.geojson';
    if (!is_readable($path)) {
        return [];
    }
    $geo = json_decode((string) file_get_contents($path), true);
    if (!is_array($geo) || !isset($geo['features'])) {
        return [];
    }
    $cityByKey = [];
    foreach ($cities as $city) {
        $cityByKey[rrc_normalizeCityKey($city)] = $city;
    }
    $index = [];
    foreach ($geo['features'] as $feature) {
        $key = rrc_normalizeCityKey((string) ($feature['properties']['city_name'] ?? ''));
        if (!isset($cityByKey[$key])) {
            continue;
        }
        $polys = rrc_flattenCityPolygons($feature['geometry'] ?? []);
        if (!empty($polys)) {
            $index[$cityByKey[$key]] = $polys;
        }
    }
    return $index;
}

function rrc_mapPointToCity(float $lat, float $lon, array $cityPolygons, array $orderedCities): ?string {
    foreach ($orderedCities as $city) {
        foreach (($cityPolygons[$city] ?? []) as $poly) {
            if (rrc_polygonContainsPoint($poly, $lon, $lat)) {
                return $city;
            }
        }
    }
    return null;
}

function rrc_isDeadlock(Throwable $e): bool {
    $msg = $e->getMessage();
    return strpos($msg, 'SQLSTATE[40001]') !== false
        || strpos($msg, 'Deadlock found when trying to get lock') !== false
        || strpos($msg, '1213') !== false;
}

function rrc_refreshSpatialMaps(PDO $pdo, array $cities): array {
    $sourceObsCount  = (int) $pdo->query('SELECT COUNT(*) FROM raw_bird_observation WHERE year IS NOT NULL AND species_id IS NOT NULL')->fetchColumn();
    $sourceGridCount = (int) $pdo->query('SELECT COUNT(*) FROM (
        SELECT DISTINCT lat, lon FROM final_master_grid
    ) g')->fetchColumn();

    $cityPolygons = rrc_loadCityPolygons($cities);
    if (empty($cityPolygons)) {
        throw new RuntimeException('City boundary GeoJSON not found or unreadable at MM_Cities_WGS84.geojson.');
    }

    $pdo->beginTransaction();
    try {
        $pdo->exec('DELETE FROM observation_city_map');
        $pdo->exec('DELETE FROM city_grid_map');

        $insertObs = $pdo->prepare('INSERT INTO observation_city_map (rbo_id, area) VALUES (:id, :area)');
        $obsStmt   = $pdo->query('SELECT id, latitude, longitude FROM raw_bird_observation WHERE year IS NOT NULL AND species_id IS NOT NULL');
        $mappedObs = 0;
        while ($row = $obsStmt->fetch(PDO::FETCH_ASSOC)) {
            $area = rrc_mapPointToCity((float) $row['latitude'], (float) $row['longitude'], $cityPolygons, $cities);
            if ($area === null) {
                continue;
            }
            $insertObs->execute([':id' => (int) $row['id'], ':area' => $area]);
            $mappedObs++;
        }

        $insertGrid = $pdo->prepare('INSERT INTO city_grid_map (lat, lon, area) VALUES (:lat, :lon, :area)');
        $gridStmt   = $pdo->query('SELECT DISTINCT lat, lon FROM final_master_grid');
        $mappedGrid = 0;
        while ($row = $gridStmt->fetch(PDO::FETCH_ASSOC)) {
            $area = rrc_mapPointToCity((float) $row['lat'], (float) $row['lon'], $cityPolygons, $cities);
            if ($area === null) {
                continue;
            }
            $insertGrid->execute([':lat' => $row['lat'], ':lon' => $row['lon'], ':area' => $area]);
            $mappedGrid++;
        }

        $metaUpsert = $pdo->prepare('INSERT INTO spatial_mapping_meta (meta_key, meta_value) VALUES (:k, :v) ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)');
        $metaUpsert->execute([':k' => 'source_obs_count',  ':v' => (string) $sourceObsCount]);
        $metaUpsert->execute([':k' => 'source_grid_count', ':v' => (string) $sourceGridCount]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return [
        'source_obs_count'  => $sourceObsCount,
        'mapped_obs_count'  => $mappedObs,
        'source_grid_count' => $sourceGridCount,
        'mapped_grid_count' => $mappedGrid,
    ];
}

function rrc_dropTempTables(PDO $pdo): void {
    foreach ([
        'tmp_rrc_years', 'tmp_rrc_richness',
        'tmp_rrc_env_cell_month', 'tmp_rrc_env_cell_year', 'tmp_rrc_env_area_year',
    ] as $t) {
        $pdo->exec("DROP TEMPORARY TABLE IF EXISTS {$t}");
    }
}

function rrc_refreshSummary(PDO $pdo, array $cities): array {
    $citySql = rrc_buildCityInlineSql($cities);
    rrc_dropTempTables($pdo);

    try {
        // Step 1 – all years present in source data
        $pdo->exec('CREATE TEMPORARY TABLE tmp_rrc_years (
            year INT NOT NULL, PRIMARY KEY (year)
        ) ENGINE=InnoDB');
        $pdo->exec('INSERT INTO tmp_rrc_years (year)
            SELECT DISTINCT year FROM (
                SELECT year FROM final_master_grid
                UNION
                SELECT year FROM raw_bird_observation
            ) yrs WHERE year IS NOT NULL');

        // Step 2 – distinct species_id per city-year (bird richness)
        $pdo->exec('CREATE TEMPORARY TABLE tmp_rrc_richness (
            area VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
            year INT NOT NULL,
            bird_richness INT NULL,
            PRIMARY KEY (area, year)
        ) ENGINE=InnoDB');
        $pdo->exec('INSERT INTO tmp_rrc_richness (area, year, bird_richness)
            SELECT
                m.area,
                r.year,
                COUNT(DISTINCT r.species_id) AS bird_richness
            FROM raw_bird_observation r
            JOIN observation_city_map m ON m.rbo_id = r.id
            JOIN species_masterlist sm ON sm.species_id = r.species_id
            WHERE r.year IS NOT NULL AND r.species_id IS NOT NULL
            GROUP BY m.area, r.year');

        // Step 3 – per-cell per-month environmental averages with scaling corrections:
        //   NDVI:  divide by 10 000 when |ndvi| > 1 (raw MODIS integer encoding)
        //   LST:   MODIS scale 0.02 then subtract 273.15 K→°C when raw > 100
        //   VIIRS: exclude zero-fill sentinel values
        //   Precip: exclude negative fill values
        $pdo->exec('CREATE TEMPORARY TABLE tmp_rrc_env_cell_month (
            area       VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
            year       INT NOT NULL,
            month      INT NOT NULL,
            lat        DECIMAL(10,8) NOT NULL,
            lon        DECIMAL(11,8) NOT NULL,
            viirs_month  DOUBLE NULL,
            ndvi_month   DOUBLE NULL,
            lst_month    DOUBLE NULL,
            precip_month DOUBLE NULL,
            PRIMARY KEY (area, year, month, lat, lon),
            KEY idx_rrc_ecm_ay (area, year)
        ) ENGINE=InnoDB');
        $pdo->exec('INSERT INTO tmp_rrc_env_cell_month
                (area, year, month, lat, lon, viirs_month, ndvi_month, lst_month, precip_month)
            SELECT
                cells.area, g.year, g.month, g.lat, g.lon,
                AVG(NULLIF(g.viirs_avg_rad, 0)),
                AVG(CASE
                    WHEN g.ndvi = 0     THEN NULL
                    WHEN ABS(g.ndvi) > 1 THEN g.ndvi / 10000
                    ELSE g.ndvi
                END),
                MAX(CASE
                    WHEN g.lst_day > 100 THEN (g.lst_day * 0.02) - 273.15
                    WHEN g.lst_day > 0   THEN g.lst_day
                    ELSE NULL
                END),
                AVG(CASE WHEN g.monthly_precip_mm >= 0 THEN g.monthly_precip_mm ELSE NULL END)
                FROM final_master_grid g
                JOIN city_grid_map cells ON ROUND(g.lat, 6) = ROUND(cells.lat, 6) AND ROUND(g.lon, 6) = ROUND(cells.lon, 6)
            WHERE NOT (
                COALESCE(g.viirs_avg_rad, 0) = 0
                AND COALESCE(g.ndvi, 0) = 0
                AND COALESCE(g.lst_day, 0) = 0
                AND COALESCE(g.monthly_precip_mm, 0) = 0
            )
            GROUP BY cells.area, g.year, g.month, g.lat, g.lon');

        // Step 4 – collapse months → cell-year
        //   VIIRS + NDVI: annual mean of monthly means
        //   LST:          peak (MAX) over months — preserves hottest-month signal
        //   Precip:       annual sum of monthly spatial averages (mm/year per cell)
        $pdo->exec('CREATE TEMPORARY TABLE tmp_rrc_env_cell_year (
            area           VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
            year           INT NOT NULL,
            lat            DECIMAL(10,8) NOT NULL,
            lon            DECIMAL(11,8) NOT NULL,
            viirs_cell_yr  DOUBLE NULL,
            ndvi_cell_yr   DOUBLE NULL,
            lst_cell_yr    DOUBLE NULL,
            precip_cell_yr DOUBLE NULL,
            PRIMARY KEY (area, year, lat, lon),
            KEY idx_rrc_ecy_ay (area, year)
        ) ENGINE=InnoDB');
        $pdo->exec('INSERT INTO tmp_rrc_env_cell_year
                (area, year, lat, lon, viirs_cell_yr, ndvi_cell_yr, lst_cell_yr, precip_cell_yr)
            SELECT area, year, lat, lon,
                AVG(viirs_month),
                AVG(ndvi_month),
                MAX(lst_month),
                SUM(precip_month)
            FROM tmp_rrc_env_cell_month
            GROUP BY area, year, lat, lon');

        // Step 5 – spatial average across cells → area-year summary
        //   VIIRS + NDVI + precip: mean across cells (equal-area grid, so simple mean is correct)
        //   LST: MAX across cells (area-level peak heat — ecologically meaningful)
        $pdo->exec('CREATE TEMPORARY TABLE tmp_rrc_env_area_year (
            area                 VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
            year                 INT NOT NULL,
            viirs_avg            DOUBLE NULL,
            ndvi_avg             DOUBLE NULL,
            lst_avg              DOUBLE NULL,
            precipitation_total  DOUBLE NULL,
            PRIMARY KEY (area, year)
        ) ENGINE=InnoDB');
        $pdo->exec("INSERT INTO tmp_rrc_env_area_year (area, year)
            SELECT c.area, y.year
            FROM ({$citySql}) c
            CROSS JOIN tmp_rrc_years y");
        $pdo->exec('UPDATE tmp_rrc_env_area_year e
            JOIN (
                SELECT
                    area,
                    year,
                    AVG(precip_cell_yr) AS precipitation_total
                FROM tmp_rrc_env_cell_year
                GROUP BY area, year
            ) p ON p.area = e.area AND p.year = e.year
            SET e.precipitation_total = p.precipitation_total');
        $pdo->exec('UPDATE tmp_rrc_env_area_year e
            JOIN (
                SELECT
                    cells.area,
                    g.year,
                    AVG(NULLIF(g.viirs_avg_rad, 0)) AS viirs_avg
                FROM final_master_grid g
                JOIN city_grid_map cells
                    ON ROUND(g.lat, 6) = ROUND(cells.lat, 6)
                   AND ROUND(g.lon, 6) = ROUND(cells.lon, 6)
                GROUP BY cells.area, g.year
            ) vsrc ON vsrc.area = e.area AND vsrc.year = e.year
            SET e.viirs_avg = vsrc.viirs_avg');
        $pdo->exec('UPDATE tmp_rrc_env_area_year e
            JOIN (
                SELECT
                    cells.area,
                    g.year,
                    AVG(
                        CASE
                            WHEN g.ndvi = 0 THEN NULL
                            WHEN ABS(g.ndvi) > 1 THEN g.ndvi / 10000
                            ELSE g.ndvi
                        END
                    ) AS ndvi_avg
                FROM final_master_grid g
                JOIN city_grid_map cells
                    ON ROUND(g.lat, 6) = ROUND(cells.lat, 6)
                   AND ROUND(g.lon, 6) = ROUND(cells.lon, 6)
                GROUP BY cells.area, g.year
            ) nsrc ON nsrc.area = e.area AND nsrc.year = e.year
            SET e.ndvi_avg = nsrc.ndvi_avg');
        $pdo->exec('UPDATE tmp_rrc_env_area_year e
            JOIN (
                SELECT
                    cells.area,
                    g.year,
                    MAX(
                        CASE
                            WHEN g.lst_day > 100 THEN (g.lst_day * 0.02) - 273.15
                            WHEN g.lst_day > 0 THEN g.lst_day
                            ELSE NULL
                        END
                    ) AS lst_avg
                FROM final_master_grid g
                JOIN city_grid_map cells
                    ON ROUND(g.lat, 6) = ROUND(cells.lat, 6)
                   AND ROUND(g.lon, 6) = ROUND(cells.lon, 6)
                GROUP BY cells.area, g.year
            ) lsrc ON lsrc.area = e.area AND lsrc.year = e.year
            SET e.lst_avg = lsrc.lst_avg');

        // Step 6 – upsert into permanent summary table (MariaDB-safe)
        $pdo->exec("REPLACE INTO ecological_yearly_summary
                (area, year, bird_richness, viirs_avg, ndvi_avg, lst_avg, precipitation_total, updated_at)
            SELECT
                c.area, y.year,
                r.bird_richness,
                e.viirs_avg, e.ndvi_avg, e.lst_avg, e.precipitation_total,
                CURRENT_TIMESTAMP
            FROM ({$citySql}) c
            CROSS JOIN tmp_rrc_years y
            LEFT JOIN tmp_rrc_richness r       ON r.area = c.area AND r.year = y.year
            LEFT JOIN tmp_rrc_env_area_year e  ON e.area = c.area AND e.year = y.year");

    } finally {
        rrc_dropTempTables($pdo);
    }

    // Post-build range checks
    $checks = $pdo->query("SELECT
            SUM(CASE WHEN ABS(ndvi_avg) > 1 THEN 1 ELSE 0 END) AS ndvi_out_of_range,
            SUM(CASE WHEN lst_avg < 10 OR lst_avg > 60 THEN 1 ELSE 0 END) AS lst_out_of_range,
            SUM(CASE WHEN precipitation_total < 0 AND precipitation_total IS NOT NULL THEN 1 ELSE 0 END) AS precip_negative,
            COUNT(*) AS total_rows
        FROM ecological_yearly_summary")->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'total_rows'        => (int) ($checks['total_rows'] ?? 0),
        'ndvi_out_of_range' => (int) ($checks['ndvi_out_of_range'] ?? 0),
        'lst_out_of_range'  => (int) ($checks['lst_out_of_range'] ?? 0),
        'precip_negative'   => (int) ($checks['precip_negative'] ?? 0),
    ];
}

function rrc_purgeCacheFiles(string $cacheDir): int {
    if (!is_dir($cacheDir)) {
        return 0;
    }
    $purged = 0;
    foreach (glob($cacheDir . '/*.json') ?: [] as $file) {
        if (@unlink($file)) {
            $purged++;
        }
    }
    return $purged;
}

// ── Main execution ─────────────────────────────────────────────────────────────

try {
    $pdo = get_mysql_db();
    $t0  = microtime(true);

    $maxAttempts = 3;
    $attempt = 0;
    $spatialStats = [];
    $summaryStats = [];
    $purgedFiles = 0;
    $lastError = null;
    $t1 = $t0;
    $t2 = $t0;
    $t3 = $t0;

    while ($attempt < $maxAttempts) {
        $attempt++;
        try {
            // 1. Spatial maps
            $spatialStats = rrc_refreshSpatialMaps($pdo, $metro_manila_cities);
            $t1 = microtime(true);

            // 2. Ecological yearly summary
            $summaryStats = rrc_refreshSummary($pdo, $metro_manila_cities);
            $t2 = microtime(true);

            // 3. Purge stale file-cache entries so the next request rebuilds fresh
            $cacheDir = __DIR__ . '/../data/cache/reports';
            $purgedFiles = rrc_purgeCacheFiles($cacheDir);
            $t3 = microtime(true);
            $lastError = null;
            break;
        } catch (Throwable $e) {
            $lastError = $e;
            if (!rrc_isDeadlock($e) || $attempt >= $maxAttempts) {
                throw $e;
            }
            // Brief backoff before retrying deadlocked refresh operations.
            usleep(200000 * $attempt);
        }
    }

    echo json_encode([
        'success'          => true,
        'message'          => 'Ecological report cache refreshed.',
        'elapsed_s'        => round($t3 - $t0, 2),
        'spatial_maps'     => array_merge($spatialStats, ['elapsed_s' => round($t1 - $t0, 2)]),
        'summary_table'    => array_merge($summaryStats, ['elapsed_s' => round($t2 - $t1, 2)]),
        'cache_files_purged' => $purgedFiles,
        'retry_attempts'    => $attempt,
        'deadlock_recovered' => $lastError === null && $attempt > 1,
        'refreshed_at'     => date('Y-m-d H:i:s'),
    ], JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage(),
    ]);
}
