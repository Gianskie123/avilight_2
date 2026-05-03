<?php
// api/refresh_spatial_map.php
// Admin endpoint: rebuilds observation_city_map and city_grid_map
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

// Allow CLI invocation for testing
$isCli = (php_sapi_name() === 'cli');
if (!$isCli) {
    require_admin();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'POST required']);
        exit;
    }
}

@set_time_limit(0);

$metro_manila_cities = [
    'Caloocan', 'Las Piñas', 'Makati', 'Malabon', 'Mandaluyong',
    'Manila', 'Marikina', 'Muntinlupa', 'Navotas', 'Parañaque',
    'Pasay', 'Pasig', 'Pateros', 'Quezon City', 'San Juan',
    'Taguig', 'Valenzuela',
];

function _normalizeCityKey(string $city): string {
    $normalized = mb_strtolower(trim($city), 'UTF-8');
    $normalized = strtr($normalized, [
        'á' => 'a','à' => 'a','ä' => 'a','â' => 'a','ã' => 'a','å' => 'a',
        'é' => 'e','è' => 'e','ë' => 'e','ê' => 'e',
        'í' => 'i','ì' => 'i','ï' => 'i','î' => 'i',
        'ó' => 'o','ò' => 'o','ö' => 'o','ô' => 'o','õ' => 'o',
        'ú' => 'u','ù' => 'u','ü' => 'u','û' => 'u',
        'ñ' => 'n','ç' => 'c',
    ]);
    return (string) preg_replace('/\s+/', ' ', $normalized);
}

function _flattenCityPolygons(array $geometry): array {
    $type = $geometry['type'] ?? '';
    $coords = $geometry['coordinates'] ?? [];
    if ($type === 'Polygon') return [$coords];
    if ($type === 'MultiPolygon') return $coords;
    return [];
}

function _ringContainsPoint(array $ring, float $x, float $y): bool {
    $inside = false; $n = count($ring);
    if ($n < 3) return false;
    for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
        $xi = (float) ($ring[$i][0] ?? 0);
        $yi = (float) ($ring[$i][1] ?? 0);
        $xj = (float) ($ring[$j][0] ?? 0);
        $yj = (float) ($ring[$j][1] ?? 0);
        $intersect = (($yi > $y) !== ($yj > $y))
            && ($x < (($xj - $xi) * ($y - $yi) / (($yj - $yi) ?: 1e-12) + $xi));
        if ($intersect) $inside = !$inside;
    }
    return $inside;
}

function _polygonContainsPoint(array $polygon, float $x, float $y): bool {
    if (empty($polygon) || ! _ringContainsPoint($polygon[0], $x, $y)) return false;
    foreach (array_slice($polygon, 1) as $hole) {
        if (_ringContainsPoint($hole, $x, $y)) return false;
    }
    return true;
}

function _loadCityPolygons(array $cities): array {
    $path = __DIR__ . '/../MM_Cities_WGS84.geojson';
    if (!is_readable($path)) return [];
    $geo = json_decode((string) file_get_contents($path), true);
    if (!is_array($geo) || !isset($geo['features'])) return [];
    $cityByKey = [];
    foreach ($cities as $c) $cityByKey[_normalizeCityKey($c)] = $c;
    $index = [];
    foreach ($geo['features'] as $feature) {
        $name = (string) ($feature['properties']['city_name'] ?? '');
        $key = _normalizeCityKey($name);
        if (!isset($cityByKey[$key])) continue;
        $polys = _flattenCityPolygons($feature['geometry'] ?? []);
        if (!empty($polys)) $index[$cityByKey[$key]] = $polys;
    }
    return $index;
}

function _mapPointToCity(float $lat, float $lon, array $cityPolygons, array $orderedCities): ?string {
    foreach ($orderedCities as $city) {
        $polys = $cityPolygons[$city] ?? [];
        foreach ($polys as $poly) {
            if (_polygonContainsPoint($poly, $lon, $lat)) return $city;
        }
    }
    // fallback: nearest vertex within ~0.005°
    $maxDistSq = 0.005 * 0.005; $nearest = null; $nd = INF;
    foreach ($orderedCities as $city) {
        foreach (($cityPolygons[$city] ?? []) as $poly) {
            $outer = $poly[0] ?? [];
            foreach ($outer as $coord) {
                $dx = $lon - (float) ($coord[0] ?? 0);
                $dy = $lat - (float) ($coord[1] ?? 0);
                $d = $dx * $dx + $dy * $dy;
                if ($d < $nd) { $nd = $d; $nearest = $city; }
            }
        }
    }
    return ($nd <= $maxDistSq) ? $nearest : null;
}

function _refreshSpatialMaps(PDO $pdo, array $cities): array {
    // counts
    $sourceObsCount = (int) $pdo->query('SELECT COUNT(*) FROM raw_bird_observation WHERE year IS NOT NULL AND species_id IS NOT NULL')->fetchColumn();
    $sourceGridCount = (int) $pdo->query('SELECT COUNT(*) FROM (SELECT latitude AS lat, longitude AS lon FROM viirs UNION SELECT latitude AS lat, longitude AS lon FROM ndvi UNION SELECT latitude AS lat, longitude AS lon FROM land_temp UNION SELECT latitude AS lat, longitude AS lon FROM precip) g')->fetchColumn();

    $cityPolygons = _loadCityPolygons($cities);
    if (empty($cityPolygons)) {
        throw new RuntimeException('City boundary GeoJSON not found or unreadable at MM_Cities_WGS84.geojson.');
    }

    $pdo->beginTransaction();
    try {
        $pdo->exec('DELETE FROM observation_city_map');
        $pdo->exec('DELETE FROM city_grid_map');

        $insertObs = $pdo->prepare('INSERT INTO observation_city_map (rbo_id, area) VALUES (:id, :area)');
        $obsStmt = $pdo->query('SELECT id, latitude, longitude FROM raw_bird_observation WHERE year IS NOT NULL AND species_id IS NOT NULL');
        $mappedObs = 0;
        while ($row = $obsStmt->fetch(PDO::FETCH_ASSOC)) {
            $area = _mapPointToCity((float)$row['latitude'], (float)$row['longitude'], $cityPolygons, $cities);
            if ($area === null) continue;
            $insertObs->execute([':id' => (int)$row['id'], ':area' => $area]);
            $mappedObs++;
        }

        $insertGrid = $pdo->prepare('INSERT INTO city_grid_map (lat, lon, area) VALUES (:lat, :lon, :area)');
        $gridStmt = $pdo->query('SELECT DISTINCT latitude AS lat, longitude AS lon FROM viirs UNION SELECT DISTINCT latitude AS lat, longitude AS lon FROM ndvi UNION SELECT DISTINCT latitude AS lat, longitude AS lon FROM land_temp UNION SELECT DISTINCT latitude AS lat, longitude AS lon FROM precip');
        $mappedGrid = 0;
        while ($row = $gridStmt->fetch(PDO::FETCH_ASSOC)) {
            $area = _mapPointToCity((float)$row['lat'], (float)$row['lon'], $cityPolygons, $cities);
            if ($area === null) continue;
            $insertGrid->execute([':lat' => $row['lat'], ':lon' => $row['lon'], ':area' => $area]);
            $mappedGrid++;
        }

        $metaUpsert = $pdo->prepare('INSERT INTO spatial_mapping_meta (meta_key, meta_value) VALUES (:k, :v) ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)');
        $metaUpsert->execute([':k' => 'source_obs_count', ':v' => (string)$sourceObsCount]);
        $metaUpsert->execute([':k' => 'source_grid_count', ':v' => (string)$sourceGridCount]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    return ['source_obs_count' => $sourceObsCount, 'mapped_obs_count' => $mappedObs, 'source_grid_count' => $sourceGridCount, 'mapped_grid_count' => $mappedGrid];
}

try {
    $pdo = get_mysql_db();
    $stats = _refreshSpatialMaps($pdo, $metro_manila_cities);
    echo json_encode(['success' => true, 'spatial_mapping' => $stats], JSON_PRETTY_PRINT);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
}
