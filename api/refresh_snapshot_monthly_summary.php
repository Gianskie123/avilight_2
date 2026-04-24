<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/db.php';

function snapshotEnsureTables(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS snapshot_monthly_area_summary (
        area VARCHAR(100) NOT NULL,
        year INT NOT NULL,
        month TINYINT NOT NULL,
        bird_richness INT NOT NULL DEFAULT 0,
        migratory_species_count INT NOT NULL DEFAULT 0,
        resident_species_count INT NOT NULL DEFAULT 0,
        migratory_unclassified_species_count INT NOT NULL DEFAULT 0,
        light_sensitive_species_count INT NOT NULL DEFAULT 0,
        light_tolerant_species_count INT NOT NULL DEFAULT 0,
        light_unclassified_species_count INT NOT NULL DEFAULT 0,
        viirs_avg DOUBLE NULL,
        ndvi_avg DOUBLE NULL,
        lst_avg DOUBLE NULL,
        precipitation_avg DOUBLE NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (area, year, month),
        KEY idx_sms_year_month_area (year, month, area)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS snapshot_monthly_site_summary (
        area VARCHAR(100) NOT NULL,
        site_name VARCHAR(255) NOT NULL,
        year INT NOT NULL,
        month TINYINT NOT NULL,
        bird_richness INT NOT NULL DEFAULT 0,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (area, site_name, year, month),
        KEY idx_smss_year_month_area (year, month, area),
        KEY idx_smss_area_year_month (area, year, month)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function snapshotRefresh(PDO $pdo): array {
    snapshotEnsureTables($pdo);

    $started = microtime(true);
    $pdo->beginTransaction();
    try {
        $pdo->exec('DELETE FROM snapshot_monthly_area_summary');
        $pdo->exec('DELETE FROM snapshot_monthly_site_summary');

        $areaSql = "INSERT INTO snapshot_monthly_area_summary (
                area,
                year,
                month,
                bird_richness,
                migratory_species_count,
                resident_species_count,
                migratory_unclassified_species_count,
                light_sensitive_species_count,
                light_tolerant_species_count,
                light_unclassified_species_count,
                viirs_avg,
                ndvi_avg,
                lst_avg,
                precipitation_avg
            )
            SELECT
                s.area,
                s.year,
                s.month,
                s.bird_richness,
                s.migratory_species_count,
                s.resident_species_count,
                s.migratory_unclassified_species_count,
                s.light_sensitive_species_count,
                s.light_tolerant_species_count,
                s.light_unclassified_species_count,
                e.viirs_avg,
                e.ndvi_avg,
                e.lst_avg,
                e.precipitation_avg
            FROM (
                SELECT
                    m.area,
                    r.year,
                    r.month,
                    COUNT(DISTINCT r.species_id) AS bird_richness,
                    COUNT(DISTINCT CASE WHEN LOWER(TRIM(sm.migratory_status)) = 'migratory' THEN r.species_id END) AS migratory_species_count,
                    COUNT(DISTINCT CASE WHEN LOWER(TRIM(sm.migratory_status)) = 'resident' THEN r.species_id END) AS resident_species_count,
                    COUNT(DISTINCT CASE WHEN sm.migratory_status IS NULL OR LOWER(TRIM(sm.migratory_status)) NOT IN ('migratory', 'resident') THEN r.species_id END) AS migratory_unclassified_species_count,
                    COUNT(DISTINCT CASE WHEN LOWER(TRIM(sm.light_tolerance)) = 'sensitive' THEN r.species_id END) AS light_sensitive_species_count,
                    COUNT(DISTINCT CASE WHEN LOWER(TRIM(sm.light_tolerance)) = 'tolerant' THEN r.species_id END) AS light_tolerant_species_count,
                    COUNT(DISTINCT CASE WHEN sm.light_tolerance IS NULL OR LOWER(TRIM(sm.light_tolerance)) NOT IN ('sensitive', 'tolerant') THEN r.species_id END) AS light_unclassified_species_count
                FROM raw_bird_observation r
                JOIN observation_city_map m ON m.rbo_id = r.id
                LEFT JOIN species_masterlist sm ON sm.species_id = r.species_id
                WHERE r.year IS NOT NULL
                  AND r.month BETWEEN 1 AND 12
                  AND r.species_id IS NOT NULL
                GROUP BY m.area, r.year, r.month
            ) s
            LEFT JOIN (
                SELECT
                    area,
                    year,
                    month,
                    AVG(viirs_avg) AS viirs_avg,
                    AVG(ndvi_avg) AS ndvi_avg,
                    AVG(lst_avg) AS lst_avg,
                    AVG(precipitation_avg) AS precipitation_avg
                FROM (
                    SELECT c.area, v.year, v.month, AVG(NULLIF(v.viirs_avg_rad, 0)) AS viirs_avg, NULL AS ndvi_avg, NULL AS lst_avg, NULL AS precipitation_avg
                    FROM city_grid_map c
                    JOIN viirs v ON v.latitude = c.lat AND v.longitude = c.lon
                    GROUP BY c.area, v.year, v.month

                    UNION ALL

                    SELECT c.area, n.year, n.month, NULL AS viirs_avg,
                           AVG(CASE WHEN n.ndvi = 0 THEN NULL WHEN ABS(n.ndvi) > 1 THEN n.ndvi / 10000 ELSE n.ndvi END) AS ndvi_avg,
                           NULL AS lst_avg, NULL AS precipitation_avg
                    FROM city_grid_map c
                    JOIN ndvi n ON n.latitude = c.lat AND n.longitude = c.lon
                    GROUP BY c.area, n.year, n.month

                    UNION ALL

                    SELECT c.area, lt.year, lt.month, NULL AS viirs_avg, NULL AS ndvi_avg,
                           AVG(CASE WHEN lt.lst_day > 100 THEN (lt.lst_day * 0.02) - 273.15 WHEN lt.lst_day > 0 THEN lt.lst_day ELSE NULL END) AS lst_avg,
                           NULL AS precipitation_avg
                    FROM city_grid_map c
                    JOIN land_temp lt ON lt.latitude = c.lat AND lt.longitude = c.lon
                    GROUP BY c.area, lt.year, lt.month

                    UNION ALL

                    SELECT c.area, p.year, p.month, NULL AS viirs_avg, NULL AS ndvi_avg, NULL AS lst_avg,
                           AVG(CASE WHEN p.precip_mm >= 0 THEN p.precip_mm ELSE NULL END) AS precipitation_avg
                    FROM city_grid_map c
                    JOIN precip p ON p.latitude = c.lat AND p.longitude = c.lon
                    GROUP BY c.area, p.year, p.month
                ) env_union
                GROUP BY area, year, month
            ) e ON e.area = s.area AND e.year = s.year AND e.month = s.month";

        $pdo->exec($areaSql);

        $siteSql = "INSERT INTO snapshot_monthly_site_summary (
                area,
                site_name,
                year,
                month,
                bird_richness
            )
            SELECT
                m.area,
                COALESCE(NULLIF(TRIM(r.site_name), ''), 'Unknown Site') AS site_name,
                r.year,
                r.month,
                COUNT(DISTINCT r.species_id) AS bird_richness
            FROM raw_bird_observation r
            JOIN observation_city_map m ON m.rbo_id = r.id
            WHERE r.year IS NOT NULL
              AND r.month BETWEEN 1 AND 12
              AND r.species_id IS NOT NULL
            GROUP BY m.area, COALESCE(NULLIF(TRIM(r.site_name), ''), 'Unknown Site'), r.year, r.month";

        $pdo->exec($siteSql);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $areaRows = (int) $pdo->query('SELECT COUNT(*) FROM snapshot_monthly_area_summary')->fetchColumn();
    $siteRows = (int) $pdo->query('SELECT COUNT(*) FROM snapshot_monthly_site_summary')->fetchColumn();

    return [
        'success' => true,
        'message' => 'Snapshot monthly summary refreshed.',
        'area_rows' => $areaRows,
        'site_rows' => $siteRows,
        'elapsed_s' => round(microtime(true) - $started, 2),
        'refreshed_at' => gmdate('Y-m-d H:i:s'),
    ];
}

try {
    $pdo = get_mysql_db();
    $result = snapshotRefresh($pdo);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
