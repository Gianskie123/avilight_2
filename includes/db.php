<?php
/**
 * db.php
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
