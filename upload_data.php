<?php
/**
 * api/upload_data.php
 *
 * Bird observation data ingestion pipeline for eBird-format CSV/XLSX exports.
 * Accepts files up to 150 MB. All responses are JSON.
 *
 * Pipeline steps:
 *  1.  File-type guard        – Accepts CSV or XLSX only; rejects everything else.
 *  2.  File-size guard        – Rejects files larger than 150 MB.
 *  3.  Streaming parse        – CSV and XLSX are read via generators (one row at
 *                               a time) so large files never exhaust memory.
 *  4.  Duplicate-column check – Rejects the upload if any header appears more
 *                               than once (case-insensitive).
 *  5.  Required-column check  – Validates that all 8 eBird columns are present:
 *                               GLOBAL UNIQUE IDENTIFIER, LOCALITY, LATITUDE,
 *                               LONGITUDE, OBSERVATION DATE, COMMON NAME,
 *                               SCIENTIFIC NAME, OBSERVATION COUNT.
 *                               Columns are renamed on ingest:
 *                                 GLOBAL UNIQUE IDENTIFIER → observation_id
 *                                 LOCALITY                 → site_name
 *                                 OBSERVATION COUNT        → bird_count
 *  6.  Row cleaning           – Drops rows where:
 *                                 • observation_id or COMMON NAME is empty
 *                                 • COMMON NAME contains " sp." or "/" (uncertain)
 *                                 • OBSERVATION DATE cannot be parsed
 *                                 • Coordinates fall outside the Metro Manila
 *                                   bounding box (lat 14.3–14.8, lon 120.9–121.15)
 *                               Extracts month and year from OBSERVATION DATE.
 *  7.  No-content guard       – Rejects the upload if all rows were cleaned out.
 *  8.  In-file duplicate guard– Rejects the upload if the same GLOBAL UNIQUE
 *                               IDENTIFIER appears more than once in the file.
 *  9.  Species mapping        – Maps COMMON NAME → species_id via species_masterlist.
 *                               Unrecognised species are auto-inserted with blank
 *                               light_tolerance / migratory_status for later review.
 *  10. Batch insert           – Inserts cleaned rows into raw_bird_observation in
 *                               batches of 500 (one multi-row INSERT per batch).
 *                               Plain INSERT (no IGNORE) — a duplicate observation_id
 *                               already in the database aborts the entire upload and
 *                               rolls back the transaction.
 */

// Buffer ALL output so nothing (warnings, notices, include newlines) can
// leak into the response before our JSON. Every exit point calls ob_end_clean().
ob_start();

ini_set('display_errors', '0');
ini_set('memory_limit', '512M');
error_reporting(E_ALL);

set_exception_handler(function (Throwable $e) {
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Unhandled error: ' . $e->getMessage()]);
    exit;
});

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Fatal error: ' . $err['message'] . ' in ' . $err['file'] . ' on line ' . $err['line']]);
    }
});

header('Content-Type: application/json');
require_once __DIR__ . '/../includes/auth.php';

// Return JSON instead of redirecting — fetch() would silently follow a
// Location: redirect and hand back the login page HTML, breaking JSON parsing.
if (!is_logged_in()) {
    echo json_encode(['success' => false, 'error' => 'Session expired. Please log in again.']);
    exit;
}

require_once __DIR__ . '/../includes/db.php';

// ── helpers ────────────────────────────────────────────────────────────────────

function json_error(string $msg): void {
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

/**
 * Generator: yields one CSV row at a time.
 * Never holds the full file in memory.
 */
function parse_csv_gen(string $path): Generator {
    $handle = fopen($path, 'r');
    if (!$handle) return;
    while (($row = fgetcsv($handle)) !== false) {
        yield $row;
    }
    fclose($handle);
}

/**
 * Generator: yields one row at a time from the first sheet of an XLSX file.
 * Uses getStream() + XMLReader so neither the ZIP entry nor the full row list
 * is ever loaded into memory at once.
 */
function parse_xlsx_gen(string $path): Generator {
    if (!class_exists('ZipArchive')) {
        json_error('ZipArchive PHP extension is required to read XLSX files.');
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return;

    // ── shared strings – stream to temp, parse with XMLReader ────────────────
    $shared_strings = [];
    $ss_stream = $zip->getStream('xl/sharedStrings.xml');
    if ($ss_stream !== false) {
        $ss_tmp    = tempnam(sys_get_temp_dir(), 'avilight_ss_');
        $ss_handle = fopen($ss_tmp, 'wb');
        stream_copy_to_stream($ss_stream, $ss_handle);
        fclose($ss_stream);
        fclose($ss_handle);

        $ss_reader    = new XMLReader();
        $ss_reader->open($ss_tmp);
        $current_text = '';
        $in_t         = false;

        while ($ss_reader->read()) {
            $type = $ss_reader->nodeType;
            $name = $ss_reader->localName;
            if ($type === XMLReader::ELEMENT && $name === 'si') {
                $current_text = '';
            } elseif ($type === XMLReader::ELEMENT && $name === 't') {
                $in_t = true;
            } elseif (($type === XMLReader::TEXT || $type === XMLReader::CDATA) && $in_t) {
                $current_text .= $ss_reader->value;
            } elseif ($type === XMLReader::END_ELEMENT && $name === 't') {
                $in_t = false;
            } elseif ($type === XMLReader::END_ELEMENT && $name === 'si') {
                $shared_strings[] = $current_text;
                $current_text     = '';
            }
        }
        $ss_reader->close();
        unlink($ss_tmp);
    }

    // ── sheet1 – stream to temp file, then yield rows via XMLReader ───────────
    $sheet_stream = $zip->getStream('xl/worksheets/sheet1.xml');
    if ($sheet_stream === false) {
        $zip->close();
        return;
    }

    $tmp        = tempnam(sys_get_temp_dir(), 'avilight_xlsx_');
    $tmp_handle = fopen($tmp, 'wb');
    stream_copy_to_stream($sheet_stream, $tmp_handle);
    fclose($sheet_stream);
    fclose($tmp_handle);
    $zip->close();

    $reader = new XMLReader();
    if (!$reader->open($tmp)) {
        unlink($tmp);
        return;
    }

    $cell_data = [];
    $max_col   = 0;
    $cur_col   = 0;
    $cur_type  = '';
    $cur_val   = '';
    $in_v      = false;
    $in_is_t   = false;

    while ($reader->read()) {
        switch ($reader->nodeType) {

            case XMLReader::ELEMENT:
                switch ($reader->localName) {
                    case 'row':
                        $cell_data = [];
                        $max_col   = 0;
                        break;
                    case 'c':
                        $ref      = $reader->getAttribute('r') ?? '';
                        $cur_col  = xlsx_col_to_int(preg_replace('/\d/', '', $ref));
                        $max_col  = max($max_col, $cur_col);
                        $cur_type = $reader->getAttribute('t') ?? '';
                        $cur_val  = '';
                        break;
                    case 'v':
                        $in_v    = true;
                        $cur_val = '';
                        break;
                    case 't':
                        if ($cur_type === 'inlineStr') {
                            $in_is_t = true;
                            $cur_val = '';
                        }
                        break;
                }
                break;

            case XMLReader::TEXT:
            case XMLReader::CDATA:
                if ($in_v || $in_is_t) {
                    $cur_val .= $reader->value;
                }
                break;

            case XMLReader::END_ELEMENT:
                switch ($reader->localName) {
                    case 'v':
                        $in_v = false;
                        $cell_data[$cur_col] = ($cur_type === 's')
                            ? ($shared_strings[(int) $cur_val] ?? '')
                            : $cur_val;
                        break;
                    case 't':
                        if ($in_is_t) {
                            $in_is_t = false;
                            $cell_data[$cur_col] = ($cell_data[$cur_col] ?? '') . $cur_val;
                        }
                        break;
                    case 'row':
                        $row_arr = [];
                        for ($i = 1; $i <= $max_col; $i++) {
                            $row_arr[] = $cell_data[$i] ?? '';
                        }
                        yield $row_arr;   // one row at a time — no accumulation
                        break;
                }
                break;
        }
    }

    $reader->close();
    unlink($tmp);
}

/** Convert an Excel column letter(s) (A, B, … AA, AB …) to a 1-based integer. */
function xlsx_col_to_int(string $col): int {
    $col = strtoupper($col);
    $num = 0;
    for ($i = 0; $i < strlen($col); $i++) {
        $num = $num * 26 + (ord($col[$i]) - 64);
    }
    return $num;
}

/**
 * Convert an Excel serial date (days since 1900-01-00) to a Y-m-d string.
 * Accounts for Excel's infamous 1900 leap-year bug.
 */
function xlsx_serial_to_date(float $serial): string {
    $days = (int) $serial;
    if ($days > 59) {
        $days--; // skip the phantom Feb 29, 1900
    }
    $ts = mktime(0, 0, 0, 1, 1, 1900) + ($days - 1) * 86400;
    return date('Y-m-d', $ts);
}

// ── request guard ─────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('POST required');
}
if (empty($_FILES['file'])) {
    json_error('No file uploaded');
}

$file = $_FILES['file'];

// ── 1. File-type check ────────────────────────────────────────────────────────

$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($ext, ['csv', 'xlsx'], true)) {
    json_error('Invalid file type. Only CSV and XLSX files are accepted.');
}

// ── 2. File-size check (150 MB) ───────────────────────────────────────────────

if ($file['size'] > 150 * 1024 * 1024) {
    json_error('File exceeds the file size limit of 150MB.');
}

// ── 3. Open a streaming row generator ────────────────────────────────────────

$row_gen = ($ext === 'csv')
    ? parse_csv_gen($file['tmp_name'])
    : parse_xlsx_gen($file['tmp_name']);

$row_gen->rewind();

if (!$row_gen->valid()) {
    json_error('File is empty or could not be parsed.');
}

$raw_headers = $row_gen->current();  // first yielded row = headers
$row_gen->next();

// ── 4. Duplicate-column check ─────────────────────────────────────────────────

$upper_headers = array_map(fn($h) => strtoupper(trim($h)), $raw_headers);
$counts        = array_count_values($upper_headers);
$duplicates    = array_keys(array_filter($counts, fn($c) => $c > 1));
if ($duplicates) {
    json_error('Duplicate columns detected: ' . implode(', ', $duplicates));
}

// Build a lookup: UPPER_COLUMN_NAME → zero-based index
$col_index = [];
foreach ($upper_headers as $i => $h) {
    $col_index[$h] = $i;
}

// ── 5. Require the 8 eBird columns ───────────────────────────────────────────

// Maps source column name → internal field name used in processing
$required = [
    'GLOBAL UNIQUE IDENTIFIER' => 'observation_id',
    'LOCALITY'                 => 'site_name',
    'LATITUDE'                 => 'latitude',
    'LONGITUDE'                => 'longitude',
    'OBSERVATION DATE'         => 'observation_date',
    'COMMON NAME'              => 'common_name',
    'SCIENTIFIC NAME'          => 'scientific_name',
    'OBSERVATION COUNT'        => 'bird_count',
];

$missing = [];
foreach (array_keys($required) as $col) {
    if (!array_key_exists($col, $col_index)) {
        $missing[] = $col;
    }
}
if ($missing) {
    json_error('Missing required columns: ' . implode(', ', $missing));
}

// Convenience: index shortcuts for the 8 columns
$idx = [];
foreach ($required as $src => $_alias) {
    $idx[$src] = $col_index[$src];
}

// ── Phase A: Clean rows ───────────────────────────────────────────────────────
// Consume the generator one row at a time — no full row array ever in memory.

$cleaned = [];
$skipped = 0;

while ($row_gen->valid()) {
    $row = $row_gen->current();
    $row_gen->next();
    $obs_id      = trim($row[$idx['GLOBAL UNIQUE IDENTIFIER']] ?? '');
    $site        = trim($row[$idx['LOCALITY']]                 ?? '');
    $lat_raw     = trim($row[$idx['LATITUDE']]                 ?? '');
    $lon_raw     = trim($row[$idx['LONGITUDE']]                ?? '');
    $date_raw    = trim($row[$idx['OBSERVATION DATE']]         ?? '');
    $common_name = trim($row[$idx['COMMON NAME']]              ?? '');
    $count_raw   = trim($row[$idx['OBSERVATION COUNT']]        ?? '');

    // Drop rows missing the primary key or species name
    if ($obs_id === '' || $common_name === '') {
        $skipped++;
        continue;
    }

    // ── 6. Drop uncertain species records ────────────────────────────────────
    // Reject " sp." (with leading space) or "/" anywhere in the name
    if (stripos($common_name, ' sp.') !== false || strpos($common_name, '/') !== false) {
        $skipped++;
        continue;
    }

    // ── Extract month & year from OBSERVATION DATE ────────────────────────────
    // Check for Excel serial numbers FIRST — strtotime() will "successfully"
    // parse a bare integer like 45292 as a relative offset and return a
    // wildly wrong timestamp (e.g. year 4723) instead of false.
    if (is_numeric($date_raw)) {
        $date_raw = xlsx_serial_to_date((float) $date_raw);
    }
    $ts = strtotime($date_raw);
    if ($ts === false) {
        $skipped++;   // unparseable date – drop row
        continue;
    }

    // ── Metro Manila bounding box filter ─────────────────────────────────────
    // Latitude  : 14.3 – 14.8  N
    // Longitude : 120.9 – 121.15 E
    $lat = (float) $lat_raw;
    $lon = (float) $lon_raw;
    if ($lat < 14.3 || $lat > 14.8 || $lon < 120.9 || $lon > 121.15) {
        $skipped++;
        continue;
    }

    $cleaned[] = [
        'obs_id'      => $obs_id,
        'site'        => $site,
        'lat'         => $lat,
        'lon'         => $lon,
        'month'       => (int) date('n', $ts),
        'year'        => (int) date('Y', $ts),
        'common_name' => $common_name,
        'bird_count'  => is_numeric($count_raw) ? (int) $count_raw : null,
    ];
}

// ── 7. No-content guard ───────────────────────────────────────────────────────

if (count($cleaned) === 0) {
    json_error('The file contains no valid content to insert. All rows were skipped (uncertain species, missing fields, unparseable dates, or coordinates outside Metro Manila lat 14.3–14.8 / lon 120.9–121.15).');
}

// ── 8. Duplicate observation_id guard (within the file) ───────────────────────

$obs_id_counts = array_count_values(array_column($cleaned, 'obs_id'));
$dup_ids       = array_keys(array_filter($obs_id_counts, fn($c) => $c > 1));
if ($dup_ids) {
    $preview = array_slice($dup_ids, 0, 10);
    $suffix  = count($dup_ids) > 10 ? ' … and ' . (count($dup_ids) - 10) . ' more.' : '.';
    json_error(
        'Duplicate GLOBAL UNIQUE IDENTIFIER values found within the file (' . count($dup_ids) . ' duplicates). '
        . 'Fix the file and re-upload. Duplicates: ' . implode(', ', $preview) . $suffix
    );
}

// ── 9-10. Resolve species & batch-insert observations ────────────────────────

const BATCH_SIZE = 500;   // rows per INSERT statement

/**
 * Execute one multi-row INSERT for the given batch.
 * Builds: INSERT INTO raw_bird_observation … VALUES (?,?,…),(?,?,…),…
 */
function insert_observation_batch(PDO $pdo, array $batch): void {
    $placeholders = implode(', ', array_fill(0, count($batch), '(?, ?, ?, ?, ?, ?, ?, ?)'));
    $sql = "INSERT INTO raw_bird_observation
                (observation_id, site_name, latitude, longitude, species_id, month, year, bird_count)
            VALUES {$placeholders}";

    $values = [];
    foreach ($batch as $r) {
        $values[] = $r['obs_id'];
        $values[] = $r['site'];
        $values[] = $r['lat'];
        $values[] = $r['lon'];
        $values[] = $r['species_id'];
        $values[] = $r['month'];
        $values[] = $r['year'];
        $values[] = $r['bird_count'];
    }

    $pdo->prepare($sql)->execute($values);
}

$inserted      = 0;
$new_species   = 0;
$species_cache = [];

try {
    $pdo = get_mysql_db();

    $stmt_find_species = $pdo->prepare(
        'SELECT species_id FROM species_masterlist WHERE species_name = :name LIMIT 1'
    );
    $stmt_add_species = $pdo->prepare(
        "INSERT INTO species_masterlist (species_name, light_tolerance, migratory_status, description)
         VALUES (:name, '', '', '')"
    );

    $pdo->beginTransaction();

    // ── 9. Resolve species_id for every cleaned row ───────────────────────────
    // Done individually because INSERT needs lastInsertId() for new species.
    foreach ($cleaned as &$r) {
        $name = $r['common_name'];
        if (!isset($species_cache[$name])) {
            $stmt_find_species->execute([':name' => $name]);
            $found = $stmt_find_species->fetchColumn();
            if ($found !== false) {
                $species_cache[$name] = (int) $found;
            } else {
                $stmt_add_species->execute([':name' => $name]);
                $species_cache[$name] = (int) $pdo->lastInsertId();
                $new_species++;
            }
        }
        $r['species_id'] = $species_cache[$name];
    }
    unset($r);

    // ── 10. Batch-insert observations (BATCH_SIZE rows per query) ─────────────
    $batch = [];
    foreach ($cleaned as $r) {
        $batch[] = $r;
        if (count($batch) === BATCH_SIZE) {
            insert_observation_batch($pdo, $batch);
            $inserted += count($batch);
            $batch = [];
        }
    }
    if ($batch) {
        insert_observation_batch($pdo, $batch);   // flush remaining rows
        $inserted += count($batch);
    }

    $pdo->commit();

} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $msg = $e->getMessage();
    if (str_contains($msg, '1062') || str_contains($msg, 'Duplicate entry')) {
        json_error('Upload aborted: one or more observation IDs already exist in the database. No records were saved. Remove the duplicates from your file and re-upload.');
    }
    if (str_contains($msg, 'could not find driver') || str_contains($msg, 'Connection refused') || str_contains($msg, 'Unknown database')) {
        json_error('Database connection failed: ' . $msg . '. Ensure MySQL is running and the avilight database exists.');
    }
    json_error('Database error: ' . $msg);
}

ob_end_clean();
header('Content-Type: application/json');
echo json_encode([
    'success'     => true,
    'message'     => "Upload complete. Inserted: {$inserted} | Skipped (cleaned out): {$skipped} | New species added: {$new_species}.",
    'inserted'    => $inserted,
    'skipped'     => $skipped,
    'new_species' => $new_species,
]);
