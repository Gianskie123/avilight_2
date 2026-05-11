<?php
/**
 * db.php
 *
 * Provides two database connections:
 *   get_db()       – PDO connection to the legacy SQLite database.
 *   get_mysql_db() – PDO connection to the MySQL/MariaDB `avilight` database.
 *
 * Returns a PDO connection to the SQLite database.
 * On first call (when the DB file does not yet exist) both CSV datasets are
 * imported automatically:
 *
 *   data/species_masterlist.csv  → table `species`
 *   data/observations.csv        → table `observations`
 *
 * Field normalisation applied during import:
 *   Light Tolerance : "Neutral" → "Moderate"
 *   Migration Status: "Resident/Migratory", "Resident / Migratory",
 *                     "Migratory / Resident", "Migratoryory" → skipped (uncertain)
 *                     (trailing/extra whitespace stripped)
 *
 * Rows excluded during import:
 *   - Species name contains " sp." (uncertain species-level identification)
 *   - Migration status contains both "resident" and "migratory" (ambiguous)
 */

function get_db(): PDO {
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $db_path  = __DIR__ . '/../data/avilight.sqlite';
    $csv_root = __DIR__ . '/../data/';
    $needs_init = !file_exists($db_path);

    $pdo = new PDO('sqlite:' . $db_path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA journal_mode = WAL;');
    $pdo->exec('PRAGMA synchronous = NORMAL;');

    if ($needs_init) {
        _init_db($pdo, $csv_root);
    }

    return $pdo;
}

/** Ensure analytics cache tables/indexes exist in SQLite. */
function ensure_analytics_cache_tables(PDO $pdo): void {
    $pdo->exec('CREATE TABLE IF NOT EXISTS analytics_latest_sites (
        source_observation_id INTEGER,
        species_list          TEXT,
        site_name             TEXT NOT NULL,
        longitude             REAL,
        latitude              REAL,
        month                 INTEGER,
        year                  INTEGER,
        total_tolerant        INTEGER DEFAULT 0,
        total_sensitive       INTEGER DEFAULT 0,
        total_resident        INTEGER DEFAULT 0,
        total_migrant         INTEGER DEFAULT 0,
        total_unique          INTEGER DEFAULT 0,
        total_count           INTEGER DEFAULT 0,
        refreshed_at          TEXT NOT NULL
    );
    CREATE INDEX IF NOT EXISTS idx_als_site ON analytics_latest_sites(site_name);
    CREATE INDEX IF NOT EXISTS idx_als_unique ON analytics_latest_sites(total_unique);
    CREATE INDEX IF NOT EXISTS idx_als_refreshed ON analytics_latest_sites(refreshed_at);');
}

/** Rebuild latest-per-site analytics cache from observations. */
function refresh_analytics_latest_sites_cache(PDO $pdo): void {
    ensure_analytics_cache_tables($pdo);

    $pdo->beginTransaction();
    try {
        $pdo->exec('DELETE FROM analytics_latest_sites');

        $sql = '
            INSERT INTO analytics_latest_sites (
                source_observation_id, species_list, site_name, longitude, latitude, month, year,
                total_tolerant, total_sensitive, total_resident, total_migrant,
                total_unique, total_count, refreshed_at
            )
            SELECT
                o1.id, o1.species_list, o1.site_name, o1.longitude, o1.latitude, o1.month, o1.year,
                o1.total_tolerant, o1.total_sensitive, o1.total_resident, o1.total_migrant,
                o1.total_unique, o1.total_count, datetime("now")
            FROM observations o1
            INNER JOIN (
                SELECT site_name, MAX(year * 100 + month) AS max_ym
                FROM observations
                WHERE site_name != "" AND latitude != 0 AND longitude != 0
                GROUP BY site_name
            ) latest ON o1.site_name = latest.site_name
                   AND (o1.year * 100 + o1.month) = latest.max_ym
            WHERE o1.site_name != "" AND o1.latitude != 0 AND o1.longitude != 0
        ';
        $pdo->exec($sql);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Return latest-per-site rows from cache, rebuilding when missing or stale.
 */
function get_analytics_latest_sites(PDO $pdo, int $limit = 200, int $maxAgeSeconds = 2592000): array {
    ensure_analytics_cache_tables($pdo);

    $limit = max(1, $limit);
    $maxAgeSeconds = max(60, $maxAgeSeconds);

    $count = (int)$pdo->query('SELECT COUNT(*) FROM analytics_latest_sites')->fetchColumn();
    $refreshedRaw = $pdo->query('SELECT MAX(refreshed_at) FROM analytics_latest_sites')->fetchColumn();

    $stale = true;
    if (!empty($refreshedRaw)) {
        $refreshedTs = strtotime((string)$refreshedRaw . ' UTC');
        if ($refreshedTs !== false) {
            $stale = (time() - $refreshedTs) > $maxAgeSeconds;
        }
    }

    if ($count === 0 || $stale) {
        refresh_analytics_latest_sites_cache($pdo);
    }

    $stmt = $pdo->prepare('SELECT
            source_observation_id AS id,
            species_list,
            site_name,
            longitude,
            latitude,
            month,
            year,
            total_tolerant,
            total_sensitive,
            total_resident,
            total_migrant,
            total_unique,
            total_count
        FROM analytics_latest_sites
        ORDER BY total_unique DESC
        LIMIT :lim');
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Normalise Light Tolerance values from CSV to internal values. */
function _normalise_tolerance(string $raw): string {
    $v = trim($raw);
    return ($v === 'Neutral') ? 'Moderate' : $v;
}

/** Normalise Migratory Status values from CSV to Resident / Migratory. */
function _normalise_migration(string $raw): string {
    $v = strtolower(trim($raw));
    if (strpos($v, 'migratory') !== false) {
        return 'Migratory';
    }
    return 'Resident';
}

/** Return true if a species row should be excluded from the database. */
function _should_skip_species(string $name, string $raw_migration): bool {
    // Drop uncertain species-level records (name contains " sp.")
    if (stripos($name, ' sp.') !== false) {
        return true;
    }
    // Drop ambiguous migration status (contains both "resident" and "migratory")
    $mig = strtolower($raw_migration);
    if (strpos($mig, 'resident') !== false && strpos($mig, 'migratory') !== false) {
        return true;
    }
    return false;
}

/** Create tables and import both CSV files into the SQLite database. */
function _init_db(PDO $pdo, string $csv_root): void {
    // ── species table ──────────────────────────────────────────────────────
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS species (
            id               INTEGER PRIMARY KEY AUTOINCREMENT,
            common_name      TEXT NOT NULL,
            light_tolerance  TEXT NOT NULL,
            migration_status TEXT NOT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_species_tolerance ON species(light_tolerance);
        CREATE INDEX IF NOT EXISTS idx_species_migration ON species(migration_status);
    ');

    $species_csv = $csv_root . 'species_masterlist.csv';
    if (is_readable($species_csv)) {
        $handle = fopen($species_csv, 'r');
        $headers = fgetcsv($handle); // skip header
        $stmt = $pdo->prepare(
            'INSERT INTO species (common_name, light_tolerance, migration_status)
             VALUES (:name, :tol, :mig)'
        );
        $pdo->beginTransaction();
        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) < 3) continue;
            $name = trim($row[0]);
            $mig  = trim($row[2]);
            if (_should_skip_species($name, $mig)) continue;
            $stmt->execute([
                ':name' => $name,
                ':tol'  => _normalise_tolerance($row[1]),
                ':mig'  => _normalise_migration($mig),
            ]);
        }
        $pdo->commit();
        fclose($handle);
    }

    // ── observations table ─────────────────────────────────────────────────
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS observations (
            id                     INTEGER PRIMARY KEY AUTOINCREMENT,
            species_list           TEXT,
            site_name              TEXT,
            longitude              REAL,
            latitude               REAL,
            month                  INTEGER,
            year                   INTEGER,
            total_tolerant         INTEGER DEFAULT 0,
            total_sensitive        INTEGER DEFAULT 0,
            total_resident         INTEGER DEFAULT 0,
            total_migrant          INTEGER DEFAULT 0,
            total_unique           INTEGER DEFAULT 0,
            total_count            INTEGER DEFAULT 0
        );
        CREATE INDEX IF NOT EXISTS idx_obs_year  ON observations(year);
        CREATE INDEX IF NOT EXISTS idx_obs_month ON observations(month);
        CREATE INDEX IF NOT EXISTS idx_obs_site  ON observations(site_name);
        CREATE INDEX IF NOT EXISTS idx_obs_coords ON observations(latitude, longitude);
        CREATE INDEX IF NOT EXISTS idx_obs_year_month_coords ON observations(year, month, latitude, longitude);
    ');

    $obs_csv = $csv_root . 'observations.csv';
    if (is_readable($obs_csv)) {
        $handle = fopen($obs_csv, 'r');
        $headers = fgetcsv($handle); // skip header row
        // Map header names → column positions
        $col = array_flip(array_map('trim', $headers));

        $stmt = $pdo->prepare(
            'INSERT INTO observations
               (species_list, site_name, longitude, latitude, month, year,
                total_tolerant, total_sensitive, total_resident, total_migrant,
                total_unique, total_count)
             VALUES
               (:sl, :sn, :lon, :lat, :mo, :yr, :tol, :sen, :res, :mig, :uniq, :cnt)'
        );
        $pdo->beginTransaction();
        $batch = 0;
        while (($row = fgetcsv($handle)) !== false) {
            $stmt->execute([
                ':sl'   => $row[$col['species_list']] ?? '',
                ':sn'   => trim($row[$col['Site Name']] ?? ''),
                ':lon'  => (float)($row[$col['Longitude']] ?? 0),
                ':lat'  => (float)($row[$col['Latitude']] ?? 0),
                ':mo'   => (int)($row[$col['Month']] ?? 0),
                ':yr'   => (int)($row[$col['Year']] ?? 0),
                ':tol'  => (int)($row[$col['total_tolerant_species']] ?? 0),
                ':sen'  => (int)($row[$col['total_sensitive_species']] ?? 0),
                ':res'  => (int)($row[$col['total_resident_species']] ?? 0),
                ':mig'  => (int)($row[$col['total_migrant_species']] ?? 0),
                ':uniq' => (int)($row[$col['total_unique_species']] ?? 0),
                ':cnt'  => (int)($row[$col['total_bird_count']] ?? 0),
            ]);
            // Commit in batches to avoid huge transactions
            if (++$batch % 500 === 0) {
                $pdo->commit();
                $pdo->beginTransaction();
            }
        }
        $pdo->commit();
        fclose($handle);
    }
}

// ── MySQL / MariaDB connection ─────────────────────────────────────────────────

/**
 * Returns a singleton PDO connection to the MySQL `avilight` database.
 * Credentials match standard XAMPP defaults; adjust if your setup differs.
 */
function get_mysql_db(): PDO {
    static $mysql_pdo = null;
    if ($mysql_pdo !== null) {
        return $mysql_pdo;
    }

    // Read DB credentials from environment (allow Railway / Docker / Laragon overrides)
    $host   = getenv('DB_HOST') ?: getenv('MYSQL_HOST') ?: '127.0.0.1';
    $port   = getenv('DB_PORT') ?: '3306';
    $dbname = getenv('DB_NAME') ?: getenv('MYSQL_DATABASE') ?: 'avilight';
    $user   = getenv('DB_USER') ?: getenv('MYSQL_USER') ?: 'root';
    $pass   = getenv('DB_PASS') ?: getenv('MYSQL_PASSWORD') ?: '';

    $sslCa   = getenv('DB_SSL_CA') ?: getenv('MYSQL_SSL_CA') ?: '';
    $sslCert = getenv('DB_SSL_CERT') ?: getenv('MYSQL_SSL_CERT') ?: '';
    $sslKey  = getenv('DB_SSL_KEY') ?: getenv('MYSQL_SSL_KEY') ?: '';
    $sslVerifyRaw = getenv('DB_SSL_VERIFY') ?: getenv('MYSQL_SSL_VERIFY');
    $sslVerify = null;
    if ($sslVerifyRaw !== false && $sslVerifyRaw !== null && $sslVerifyRaw !== '') {
        $sslVerify = filter_var($sslVerifyRaw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    // Support DATABASE_URL style if provided (e.g. from some platforms)
    $databaseUrl = getenv('DATABASE_URL');
    if ($databaseUrl) {
        $parts = parse_url($databaseUrl);
        if ($parts !== false) {
            $host = $parts['host'] ?? $host;
            $port = $parts['port'] ?? $port;
            $user = $parts['user'] ?? $user;
            $pass = $parts['pass'] ?? $pass;
            if (isset($parts['path'])) {
                $dbname = ltrim($parts['path'], '/') ?: $dbname;
            }
        }
    }

    $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    if ($sslCa !== '') {
        $options[PDO::MYSQL_ATTR_SSL_CA] = $sslCa;
    }
    if ($sslCert !== '') {
        $options[PDO::MYSQL_ATTR_SSL_CERT] = $sslCert;
    }
    if ($sslKey !== '') {
        $options[PDO::MYSQL_ATTR_SSL_KEY] = $sslKey;
    }
    if ($sslVerify !== null) {
        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = $sslVerify;
    }

    $mysql_pdo = new PDO($dsn, $user, $pass, $options);
    $mysql_pdo->exec("SET time_zone = '+08:00'");

    return $mysql_pdo;
}

/** Ensure MySQL BAU baseline cache table/indexes exist. */
function ensure_mysql_bau_baseline_cache_table(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS analytics_bau_baselines (
        city_key              VARCHAR(150) NOT NULL,
        city_label            VARCHAR(150) NOT NULL,
        month                 TINYINT UNSIGNED NOT NULL,
        base_ndvi             DOUBLE NOT NULL,
        base_viirs            DOUBLE NOT NULL,
        base_lst              DOUBLE NOT NULL,
        base_precip           DOUBLE NOT NULL,
        lc_dummy_1            DOUBLE NOT NULL,
        lc_dummy_2            DOUBLE NOT NULL,
        lc_dummy_3            DOUBLE NOT NULL,
        lc_dummy_4            DOUBLE NOT NULL,
        lc_dummy_5            DOUBLE NOT NULL,
        cell_count            INT NOT NULL DEFAULT 0,
        latest_common_year    INT NULL,
        historical_inputs_json LONGTEXT NULL,
        refreshed_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (city_key, month),
        KEY idx_abb_refreshed (refreshed_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** Delete all cached BAU baselines. */
function clear_mysql_bau_baseline_cache(PDO $pdo): void {
    ensure_mysql_bau_baseline_cache_table($pdo);
    $pdo->exec('DELETE FROM analytics_bau_baselines');
}

/** Fetch cached BAU baseline if not stale; returns null when missing/stale. */
function get_mysql_bau_baseline_cache(PDO $pdo, string $cityKey, int $month, int $maxAgeSeconds = 86400): ?array {
    ensure_mysql_bau_baseline_cache_table($pdo);

    $stmt = $pdo->prepare('SELECT
            city_key,
            city_label,
            month,
            base_ndvi,
            base_viirs,
            base_lst,
            base_precip,
            lc_dummy_1,
            lc_dummy_2,
            lc_dummy_3,
            lc_dummy_4,
            lc_dummy_5,
            cell_count,
            latest_common_year,
            historical_inputs_json,
            refreshed_at
        FROM analytics_bau_baselines
        WHERE city_key = :city_key AND month = :month
        LIMIT 1');
    $stmt->execute([
        ':city_key' => $cityKey,
        ':month' => $month,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    $maxAgeSeconds = max(60, $maxAgeSeconds);
    $refTs = strtotime((string)$row['refreshed_at'] . ' UTC');
    if ($refTs !== false && (time() - $refTs) > $maxAgeSeconds) {
        return null;
    }
    return $row;
}

/** Upsert one city-month BAU baseline cache row. */
function upsert_mysql_bau_baseline_cache(PDO $pdo, array $payload): void {
    ensure_mysql_bau_baseline_cache_table($pdo);

    $stmt = $pdo->prepare('INSERT INTO analytics_bau_baselines (
            city_key,
            city_label,
            month,
            base_ndvi,
            base_viirs,
            base_lst,
            base_precip,
            lc_dummy_1,
            lc_dummy_2,
            lc_dummy_3,
            lc_dummy_4,
            lc_dummy_5,
            cell_count,
            latest_common_year,
            historical_inputs_json,
            refreshed_at
        ) VALUES (
            :city_key,
            :city_label,
            :month,
            :base_ndvi,
            :base_viirs,
            :base_lst,
            :base_precip,
            :lc_dummy_1,
            :lc_dummy_2,
            :lc_dummy_3,
            :lc_dummy_4,
            :lc_dummy_5,
            :cell_count,
            :latest_common_year,
            :historical_inputs_json,
            CURRENT_TIMESTAMP
        ) ON DUPLICATE KEY UPDATE
            city_label = VALUES(city_label),
            base_ndvi = VALUES(base_ndvi),
            base_viirs = VALUES(base_viirs),
            base_lst = VALUES(base_lst),
            base_precip = VALUES(base_precip),
            lc_dummy_1 = VALUES(lc_dummy_1),
            lc_dummy_2 = VALUES(lc_dummy_2),
            lc_dummy_3 = VALUES(lc_dummy_3),
            lc_dummy_4 = VALUES(lc_dummy_4),
            lc_dummy_5 = VALUES(lc_dummy_5),
            cell_count = VALUES(cell_count),
            latest_common_year = VALUES(latest_common_year),
            historical_inputs_json = VALUES(historical_inputs_json),
            refreshed_at = CURRENT_TIMESTAMP');

    $stmt->execute([
        ':city_key' => (string)$payload['city_key'],
        ':city_label' => (string)$payload['city_label'],
        ':month' => (int)$payload['month'],
        ':base_ndvi' => (float)$payload['base_ndvi'],
        ':base_viirs' => (float)$payload['base_viirs'],
        ':base_lst' => (float)$payload['base_lst'],
        ':base_precip' => (float)$payload['base_precip'],
        ':lc_dummy_1' => (float)$payload['lc_dummy_1'],
        ':lc_dummy_2' => (float)$payload['lc_dummy_2'],
        ':lc_dummy_3' => (float)$payload['lc_dummy_3'],
        ':lc_dummy_4' => (float)$payload['lc_dummy_4'],
        ':lc_dummy_5' => (float)$payload['lc_dummy_5'],
        ':cell_count' => (int)($payload['cell_count'] ?? 0),
        ':latest_common_year' => isset($payload['latest_common_year']) ? (int)$payload['latest_common_year'] : null,
        ':historical_inputs_json' => $payload['historical_inputs_json'] ?? null,
    ]);
}

/**
 * Refresh ecological_monthly_summary for the given years using city_cells.
 * Called automatically after build_master_grid succeeds.
 * Returns the number of rows upserted.
 */
function refresh_ecological_monthly_summary(PDO $pdo, array $years): int {
    if (empty($years)) return 0;
    $in = implode(',', array_map('intval', $years));
    return (int)$pdo->exec("
        INSERT INTO ecological_monthly_summary
            (area, year, month, ndvi_avg, viirs_avg, lst_avg, precipitation_total, cell_count)
        SELECT
            cc.city_key                                                       AS area,
            fmg.year,
            fmg.month,
            AVG(fmg.ndvi)                                                     AS ndvi_avg,
            AVG(fmg.viirs_avg_rad)                                            AS viirs_avg,
            AVG((fmg.lst_day + COALESCE(fmg.lst_night, fmg.lst_day)) / 2.0) AS lst_avg,
            AVG(fmg.monthly_precip_mm)                                        AS precipitation_total,
            COUNT(DISTINCT fmg.cell_id)                                       AS cell_count
        FROM final_master_grid fmg
        JOIN city_cells cc ON fmg.cell_id = cc.cell_id
        WHERE fmg.year IN ({$in})
          AND fmg.ndvi              IS NOT NULL
          AND fmg.viirs_avg_rad     IS NOT NULL
          AND fmg.lst_day           IS NOT NULL
          AND fmg.monthly_precip_mm IS NOT NULL
        GROUP BY cc.city_key, fmg.year, fmg.month
        ON DUPLICATE KEY UPDATE
            ndvi_avg            = VALUES(ndvi_avg),
            viirs_avg           = VALUES(viirs_avg),
            lst_avg             = VALUES(lst_avg),
            precipitation_total = VALUES(precipitation_total),
            cell_count          = VALUES(cell_count),
            refreshed_at        = CURRENT_TIMESTAMP
    ");
}

/**
 * Refresh city_land_cover_summary for the given years using city_cells.
 * Called automatically after fetch_satellite land_cover ingestion succeeds.
 * Returns the number of rows upserted.
 */
function refresh_city_land_cover_summary(PDO $pdo, array $years): int {
    if (empty($years)) return 0;
    $in = implode(',', array_map('intval', $years));
    return (int)$pdo->exec("
        INSERT INTO city_land_cover_summary
            (city_key, ref_year, urban, vegetation, water, cropland, barren, cell_count)
        SELECT
            city_key,
            ref_year,
            cnt_urban      / total_cnt AS urban,
            cnt_vegetation / total_cnt AS vegetation,
            cnt_water      / total_cnt AS water,
            cnt_cropland   / total_cnt AS cropland,
            cnt_barren     / total_cnt AS barren,
            total_cnt                  AS cell_count
        FROM (
            SELECT
                cc.city_key,
                lc.year AS ref_year,
                SUM(CASE WHEN lc.land_cover IN (1,2,3,4,5,8,9,10) THEN 1 ELSE 0 END)     AS cnt_vegetation,
                SUM(CASE WHEN lc.land_cover IN (0,11,17)           THEN 1 ELSE 0 END)     AS cnt_water,
                SUM(CASE WHEN lc.land_cover IN (12,14)             THEN 1 ELSE 0 END)     AS cnt_cropland,
                SUM(CASE WHEN lc.land_cover IN (15,16)             THEN 1 ELSE 0 END)     AS cnt_barren,
                SUM(CASE WHEN lc.land_cover IS NULL
                           OR lc.land_cover NOT IN (0,1,2,3,4,5,8,9,10,11,12,14,15,16,17)
                         THEN 1 ELSE 0 END)                                                AS cnt_urban,
                COUNT(*) AS total_cnt
            FROM land_cover lc
            JOIN city_cells cc ON lc.cell_id = cc.cell_id
            WHERE lc.year IN ({$in})
            GROUP BY cc.city_key, lc.year
        ) counts
        WHERE total_cnt > 0
        ON DUPLICATE KEY UPDATE
            urban        = VALUES(urban),
            vegetation   = VALUES(vegetation),
            water        = VALUES(water),
            cropland     = VALUES(cropland),
            barren       = VALUES(barren),
            cell_count   = VALUES(cell_count),
            refreshed_at = CURRENT_TIMESTAMP
    ");
}
