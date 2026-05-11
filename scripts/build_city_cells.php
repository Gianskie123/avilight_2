<?php
/**
 * scripts/build_city_cells.php
 *
 * Reads MM_Cities_WGS84.geojson, converts each city boundary to WKT, loads it
 * into city_polygons, then runs a single ST_Contains spatial join against
 * final_master_grid to populate city_cells.
 *
 * Requires MySQL 5.7+ or MariaDB 10.2+ (ST_Contains, ST_GeomFromText).
 * This is a one-time (or post-boundary-change) operation — not part of the
 * regular data-ingestion pipeline.
 *
 * Usage:
 *   php scripts/build_city_cells.php
 *   php scripts/build_city_cells.php --dry-run
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script must be run via CLI.\n");
}

// php-cgi / some minimal builds omit these stream constants even in CLI mode.
if (!defined('STDOUT')) define('STDOUT', fopen('php://stdout', 'w'));
if (!defined('STDERR')) define('STDERR', fopen('php://stderr', 'w'));

require_once __DIR__ . '/../includes/db.php';

$dryRun = in_array('--dry-run', array_slice($argv, 1), true);

// ── Load GeoJSON ───────────────────────────────────────────────────────────────

$geojsonPath = realpath(__DIR__ . '/../MM_Cities_WGS84.geojson');
if ($geojsonPath === false || !is_readable($geojsonPath)) {
    fwrite(STDERR, "GeoJSON not found: MM_Cities_WGS84.geojson\n");
    exit(1);
}

$geo = json_decode(file_get_contents($geojsonPath), true);
if (!is_array($geo) || empty($geo['features'])) {
    fwrite(STDERR, "Invalid or empty GeoJSON file.\n");
    exit(1);
}

fwrite(STDOUT, "GeoJSON loaded: " . count($geo['features']) . " features.\n");

if ($dryRun) {
    fwrite(STDOUT, "[dry-run] No DB writes performed.\n");
    exit(0);
}

// ── WKT conversion ─────────────────────────────────────────────────────────────

/**
 * Convert a GeoJSON geometry (Polygon or MultiPolygon) to WKT.
 * GeoJSON coordinates are [lon, lat], which matches MySQL's POINT(x, y) order.
 */
function geojson_geometry_to_wkt(array $geom): string
{
    $type   = $geom['type']        ?? '';
    $coords = $geom['coordinates'] ?? [];

    $ring = fn(array $r): string =>
        '(' . implode(',', array_map(fn($c) => $c[0] . ' ' . $c[1], $r)) . ')';

    $polygon = fn(array $p): string =>
        '(' . implode(',', array_map($ring, $p)) . ')';

    if ($type === 'Polygon') {
        return 'POLYGON(' . $polygon($coords) . ')';
    }
    if ($type === 'MultiPolygon') {
        return 'MULTIPOLYGON(' . implode(',', array_map($polygon, $coords)) . ')';
    }
    throw new RuntimeException("Unsupported geometry type: {$type}");
}

// ── DB setup ───────────────────────────────────────────────────────────────────

$pdo = get_mysql_db();

// Allow the spatial join query to run without hitting the interactive timeout.
$pdo->exec('SET SESSION wait_timeout = 3600');

// ── Load city polygons ─────────────────────────────────────────────────────────

$pdo->exec('TRUNCATE TABLE city_polygons');

$insertPoly = $pdo->prepare(
    'INSERT INTO city_polygons (city_key, city_label, polygon)
     VALUES (:key, :label, ST_GeomFromText(:wkt))'
);

$loaded  = 0;
$skipped = 0;

foreach ($geo['features'] as $feature) {
    $label = trim((string)($feature['properties']['city_name'] ?? ''));
    if ($label === '') {
        $skipped++;
        continue;
    }

    try {
        $wkt = geojson_geometry_to_wkt((array)($feature['geometry'] ?? []));
    } catch (RuntimeException $e) {
        fwrite(STDERR, "  Skipping {$label}: " . $e->getMessage() . "\n");
        $skipped++;
        continue;
    }

    try {
        $insertPoly->execute([
            ':key'   => mb_strtolower($label, 'UTF-8'),
            ':label' => $label,
            ':wkt'   => $wkt,
        ]);
        fwrite(STDOUT, "  Loaded polygon: {$label}\n");
        $loaded++;
    } catch (PDOException $e) {
        fwrite(STDERR, "  DB error for {$label}: " . $e->getMessage() . "\n");
        $skipped++;
    }
}

fwrite(STDOUT, "\nPolygons loaded: {$loaded}, skipped: {$skipped}\n");

if ($loaded === 0) {
    fwrite(STDERR, "No polygons loaded — aborting.\n");
    exit(1);
}

// ── Spatial join → city_cells ──────────────────────────────────────────────────
//
// For each distinct cell_id in final_master_grid, test which city polygon
// contains that cell's centroid using ST_Contains. One cell can only belong
// to one city (polygons don't overlap in the GeoJSON), so no deduplication needed.

fwrite(STDOUT, "\nRunning spatial join (ST_Contains over all cells × city polygons)...\n");

$pdo->exec('TRUNCATE TABLE city_cells');

$affected = $pdo->exec("
    INSERT INTO city_cells (city_key, cell_id)
    SELECT cp.city_key, cells.cell_id
    FROM city_polygons cp
    JOIN (
        SELECT cell_id, MAX(lat) AS lat, MAX(lon) AS lon
        FROM final_master_grid
        GROUP BY cell_id
    ) cells
      ON ST_Contains(
             cp.polygon,
             ST_GeomFromText(CONCAT('POINT(', cells.lon, ' ', cells.lat, ')'))
         )
");

$total = (int)$pdo->query('SELECT COUNT(DISTINCT city_key) FROM city_cells')->fetchColumn();

fwrite(STDOUT, "Done. Inserted {$affected} cell mappings across {$total} cities.\n");
