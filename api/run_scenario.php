<?php
/**
 * api/run_scenario.php
 * 
 * Receives scenario parameters from the frontend and calls the Python ML backend
 * to generate predictions using XGBoost, ConvLSTM, and Meta-Learner models.
 */

require_once __DIR__ . '/../includes/backend_config.php';
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json');

function flatten_city_polygons(array $geometry): array {
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

function ring_contains_point(array $ring, float $x, float $y): bool {
    $inside = false;
    $n = count($ring);
    if ($n < 3) {
        return false;
    }
    for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
        $xi = (float)$ring[$i][0];
        $yi = (float)$ring[$i][1];
        $xj = (float)$ring[$j][0];
        $yj = (float)$ring[$j][1];
        $intersect = (($yi > $y) !== ($yj > $y))
            && ($x < (($xj - $xi) * ($y - $yi) / (($yj - $yi) ?: 1e-12) + $xi));
        if ($intersect) {
            $inside = !$inside;
        }
    }
    return $inside;
}

function polygon_contains_point(array $polygon, float $x, float $y): bool {
    if (empty($polygon)) {
        return false;
    }
    if (!ring_contains_point($polygon[0], $x, $y)) {
        return false;
    }
    $holes = array_slice($polygon, 1);
    foreach ($holes as $hole) {
        if (ring_contains_point($hole, $x, $y)) {
            return false;
        }
    }
    return true;
}

function city_geometry(string $city): ?array {
    static $cityIndex = null;
    if ($cityIndex === null) {
        $path = __DIR__ . '/../MM_Cities_WGS84.geojson';
        if (!is_readable($path)) {
            return null;
        }
        $geo = json_decode(file_get_contents($path), true);
        $cityIndex = [];
        foreach (($geo['features'] ?? []) as $feature) {
            $name = strtolower(trim((string)($feature['properties']['city_name'] ?? '')));
            if ($name === '') {
                continue;
            }
            $cityIndex[$name] = flatten_city_polygons($feature['geometry'] ?? []);
        }
    }
    $key = strtolower(trim($city));
    return $cityIndex[$key] ?? null;
}

function bau_city_cache_key(string $city): string {
    $key = strtolower(trim($city));
    return $key === '' ? '__all__' : $key;
}

function city_cell_ids(PDO $pdo, ?string $city): array {
    $city = trim((string)$city);

    if ($city === '') {
        // Metro Manila aggregate: all cells assigned to any city polygon.
        $rows = $pdo->query('SELECT DISTINCT cell_id FROM city_cells')->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($rows)) return array_values($rows);
        // Fallback: city_cells not yet populated.
        $rows = $pdo->query('SELECT DISTINCT cell_id FROM final_master_grid')->fetchAll(PDO::FETCH_COLUMN);
        return array_values(array_filter($rows));
    }

    // Fast path: precomputed city→cell mapping.
    $cityKey = mb_strtolower($city, 'UTF-8');
    $stmt = $pdo->prepare('SELECT cell_id FROM city_cells WHERE city_key = :k');
    $stmt->execute([':k' => $cityKey]);
    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (!empty($rows)) return array_values($rows);

    // Fallback: PHP point-in-polygon (city_cells not yet populated for this city).
    $polygons = city_geometry($city);
    if (!$polygons) return [];

    $rows = $pdo->query('SELECT cell_id, MAX(lat) AS lat, MAX(lon) AS lon FROM final_master_grid GROUP BY cell_id')->fetchAll(PDO::FETCH_ASSOC);
    $ids = [];
    foreach ($rows as $row) {
        $x = (float)$row['lon'];
        $y = (float)$row['lat'];
        foreach ($polygons as $poly) {
            if (polygon_contains_point($poly, $x, $y)) {
                $ids[] = $row['cell_id'];
                break;
            }
        }
    }
    return array_values(array_unique($ids));
}

function yearly_means(PDO $pdo, string $table, string $column, array $cellIds, int $month, bool $ignoreZero = false): array {
    if (empty($cellIds)) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($cellIds), '?'));
    $zeroFilter = $ignoreZero ? " AND {$column} <> 0" : '';
    $sql = "SELECT year, AVG({$column}) AS v FROM {$table} WHERE cell_id IN ({$placeholders}) AND month = ? AND {$column} IS NOT NULL{$zeroFilter} GROUP BY year ORDER BY year";
    $stmt = $pdo->prepare($sql);
    $params = $cellIds;
    $params[] = $month;
    $stmt->execute($params);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[(int)$row['year']] = (float)$row['v'];
    }
    return $out;
}

function average_yearly_change(array $series, array $years): float {
    sort($years);
    $diffs = [];
    for ($i = 1; $i < count($years); $i++) {
        $y0 = $years[$i - 1];
        $y1 = $years[$i];
        if (isset($series[$y0], $series[$y1])) {
            $diffs[] = $series[$y1] - $series[$y0];
        }
    }
    if (empty($diffs)) {
        return 0.0;
    }
    return array_sum($diffs) / count($diffs);
}

function baseline_with_trend(array $series, array $commonYears, int $latestCommonYear): array {
    $baselineYear = $latestCommonYear;
    $trend = average_yearly_change($series, $commonYears);

    $baseRaw = null;
    if (isset($series[$baselineYear])) {
        $baseRaw = (float)$series[$baselineYear];
    } else {
        $fallbackYears = array_values(array_filter($commonYears, fn($y) => $y <= $latestCommonYear));
        rsort($fallbackYears);
        foreach ($fallbackYears as $y) {
            if (isset($series[$y])) {
                $baseRaw = (float)$series[$y];
                break;
            }
        }
    }

    if ($baseRaw === null) {
        $baseRaw = 0.0;
    }

    return [
        'baseline_year' => $baselineYear,
        'base_raw' => $baseRaw,
        'avg_yearly_change' => $trend,
        'adjusted_baseline' => $baseRaw + $trend,
    ];
}

function dominant_land_cover(PDO $pdo, array $cellIds, int $referenceYear): array {
    if (empty($cellIds)) {
        return [
            'year' => null,
            'code' => null,
            'label' => 'Unknown',
            'dummies' => [1, 0, 0, 0, 0],
            'group_shares' => ['urban' => 1.0, 'vegetation' => 0.0, 'water' => 0.0, 'cropland' => 0.0, 'barren' => 0.0],
        ];
    }
    $in = implode(',', array_fill(0, count($cellIds), '?'));

    $yearSql = "SELECT MAX(year) AS y FROM land_cover WHERE cell_id IN ({$in}) AND year <= ?";
    $stmt = $pdo->prepare($yearSql);
    $params = $cellIds;
    $params[] = $referenceYear;
    $stmt->execute($params);
    $landYear = (int)($stmt->fetchColumn() ?: 0);

    if ($landYear === 0) {
        $stmt = $pdo->prepare("SELECT MAX(year) AS y FROM land_cover WHERE cell_id IN ({$in})");
        $stmt->execute($cellIds);
        $landYear = (int)($stmt->fetchColumn() ?: 0);
    }

    if ($landYear === 0) {
        return [
            'year' => null,
            'code' => null,
            'label' => 'Unknown',
            'dummies' => [1, 0, 0, 0, 0],
            'group_shares' => ['urban' => 1.0, 'vegetation' => 0.0, 'water' => 0.0, 'cropland' => 0.0, 'barren' => 0.0],
        ];
    }

    $modeSql = "SELECT land_cover, COUNT(*) AS c FROM land_cover WHERE cell_id IN ({$in}) AND year = ? GROUP BY land_cover ORDER BY c DESC";
    $stmt = $pdo->prepare($modeSql);
    $params = $cellIds;
    $params[] = $landYear;
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $code = isset($rows[0]['land_cover']) ? (int)$rows[0]['land_cover'] : null;

    // MODIS IGBP grouped into the 5 dummies expected by the model.
    $group_for_code = function (?int $lcCode): string {
        if ($lcCode === null) {
            return 'urban';
        }
        if (in_array($lcCode, [1, 2, 3, 4, 5, 8, 9, 10], true)) {
            return 'vegetation';
        }
        if (in_array($lcCode, [0, 11, 17], true)) {
            return 'water';
        }
        if (in_array($lcCode, [12, 14], true)) {
            return 'cropland';
        }
        if (in_array($lcCode, [15, 16], true)) {
            return 'barren';
        }
        return 'urban';
    };

    $labels = [
        'urban' => 'Urban/Built-up',
        'vegetation' => 'Vegetated',
        'water' => 'Water/Wetlands',
        'cropland' => 'Cropland/Mosaic',
        'barren' => 'Barren/Sparse',
    ];

    $shares = ['urban' => 0.0, 'vegetation' => 0.0, 'water' => 0.0, 'cropland' => 0.0, 'barren' => 0.0];
    $total = 0.0;
    foreach ($rows as $r) {
        $lcCode = isset($r['land_cover']) ? (int)$r['land_cover'] : null;
        $cnt = isset($r['c']) ? (float)$r['c'] : 0.0;
        $grp = $group_for_code($lcCode);
        $shares[$grp] += $cnt;
        $total += $cnt;
    }

    if ($total > 0) {
        foreach (array_keys($shares) as $k) {
            $shares[$k] = $shares[$k] / $total;
        }
    } else {
        $shares['urban'] = 1.0;
    }

    $dominantGroup = 'urban';
    $maxShare = -1.0;
    foreach ($shares as $k => $v) {
        if ($v > $maxShare) {
            $maxShare = $v;
            $dominantGroup = $k;
        }
    }

    $dummies = [
        $dominantGroup === 'urban'      ? 1.0 : 0.0,
        $dominantGroup === 'vegetation' ? 1.0 : 0.0,
        $dominantGroup === 'water'      ? 1.0 : 0.0,
        $dominantGroup === 'cropland'   ? 1.0 : 0.0,
        $dominantGroup === 'barren'     ? 1.0 : 0.0,
    ];

    return [
        'year' => $landYear,
        'code' => $code,
        'label' => $labels[$dominantGroup],
        'dummies' => $dummies,
        'group_shares' => $shares,
    ];
}

/**
 * Fast land cover lookup using city_land_cover_summary (precomputed proportional shares).
 * Falls back to dominant_land_cover() with PHP point-in-polygon if the summary table
 * is not yet populated.
 */
function dominant_land_cover_by_city(PDO $pdo, string $city, int $referenceYear): array {
    $labels = [
        'urban'      => 'Urban/Built-up',
        'vegetation' => 'Vegetated',
        'water'      => 'Water/Wetlands',
        'cropland'   => 'Cropland/Mosaic',
        'barren'     => 'Barren/Sparse',
    ];

    $emptyFallback = function () use ($pdo, $city, $referenceYear): array {
        return dominant_land_cover($pdo, city_cell_ids($pdo, $city), $referenceYear);
    };

    if ($city === '') {
        $stmt = $pdo->prepare('SELECT
                AVG(urban)      AS urban,
                AVG(vegetation) AS vegetation,
                AVG(water)      AS water,
                AVG(cropland)   AS cropland,
                AVG(barren)     AS barren,
                MAX(ref_year)   AS ref_year
            FROM city_land_cover_summary
            WHERE ref_year <= :year');
        $stmt->execute([':year' => $referenceYear]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || !$row['ref_year']) return $emptyFallback();
    } else {
        $cityKey = mb_strtolower($city, 'UTF-8');
        $stmt = $pdo->prepare('SELECT ref_year, urban, vegetation, water, cropland, barren
            FROM city_land_cover_summary
            WHERE city_key = :k AND ref_year <= :year
            ORDER BY ref_year DESC LIMIT 1');
        $stmt->execute([':k' => $cityKey, ':year' => $referenceYear]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $stmt = $pdo->prepare('SELECT ref_year, urban, vegetation, water, cropland, barren
                FROM city_land_cover_summary
                WHERE city_key = :k
                ORDER BY ref_year DESC LIMIT 1');
            $stmt->execute([':k' => $cityKey]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        if (!$row) return $emptyFallback();
    }

    $shares = [
        'urban'      => (float)$row['urban'],
        'vegetation' => (float)$row['vegetation'],
        'water'      => (float)$row['water'],
        'cropland'   => (float)$row['cropland'],
        'barren'     => (float)$row['barren'],
    ];

    $dominantGroup = (string)array_search(max($shares), $shares, true);

    return [
        'year'         => (int)$row['ref_year'],
        'code'         => null,
        'label'        => $labels[$dominantGroup] ?? 'Urban/Built-up',
        'dummies'      => [
            $dominantGroup === 'urban'      ? 1.0 : 0.0,
            $dominantGroup === 'vegetation' ? 1.0 : 0.0,
            $dominantGroup === 'water'      ? 1.0 : 0.0,
            $dominantGroup === 'cropland'   ? 1.0 : 0.0,
            $dominantGroup === 'barren'     ? 1.0 : 0.0,
        ],
        'group_shares' => $shares,
    ];
}

function compute_bau_baseline_from_live_data(PDO $pdo, string $city, int $month): array {
    $cell_ids = city_cell_ids($pdo, $city);

    if (empty($cell_ids) && $city !== '') {
        throw new Exception("No grid cells found for city: {$city}");
    }

    if (empty($cell_ids)) {
        throw new Exception('No grid cells available for BAU baseline computation.');
    }

    // Primary: ecological_monthly_summary — month-specific trend per city.
    // Fallback: ecological_yearly_summary — annual averages, used when monthly
    //   data has fewer than 2 years (e.g. table not yet populated).
    $sourceUsed = 'monthly';

    if ($city === '') {
        $stmt = $pdo->prepare('SELECT year,
                AVG(ndvi_avg)            AS ndvi_avg,
                AVG(viirs_avg)           AS viirs_avg,
                AVG(lst_day_avg)         AS lst_day_avg,
                AVG(lst_night_avg)       AS lst_night_avg,
                AVG(precipitation_total) AS precipitation_total
            FROM ecological_monthly_summary
            WHERE month = :month AND year BETWEEN 2014 AND 2025
            GROUP BY year
            ORDER BY year ASC');
        $stmt->execute([':month' => $month]);
    } else {
        $stmt = $pdo->prepare('SELECT year, ndvi_avg, viirs_avg, lst_day_avg, lst_night_avg, precipitation_total
            FROM ecological_monthly_summary
            WHERE area = :area AND month = :month AND year BETWEEN 2014 AND 2025
            ORDER BY year ASC');
        $stmt->execute([':area' => $city, ':month' => $month]);
    }
    $envRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if (count($envRows) < 2) {
        $sourceUsed = 'yearly_fallback';
        if ($city === '') {
            $fbStmt = $pdo->prepare('SELECT year,
                    AVG(ndvi_avg)            AS ndvi_avg,
                    AVG(viirs_avg)           AS viirs_avg,
                    AVG(lst_avg)             AS lst_avg,
                    AVG(precipitation_total) AS precipitation_total
                FROM ecological_yearly_summary
                WHERE year BETWEEN 2014 AND 2025
                GROUP BY year
                ORDER BY year ASC');
            $fbStmt->execute();
        } else {
            $fbStmt = $pdo->prepare('SELECT year, ndvi_avg, viirs_avg, lst_avg, precipitation_total
                FROM ecological_yearly_summary
                WHERE area = :area AND year BETWEEN 2014 AND 2025
                ORDER BY year ASC');
            $fbStmt->execute([':area' => $city]);
        }
        $envRows = $fbStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $series = [
        'ndvi'      => [],
        'viirs'     => [],
        'lst_day'   => [],
        'lst_night' => [],
        'precip'    => [],
    ];

    foreach ($envRows as $row) {
        $year  = (int)($row['year'] ?? 0);
        if ($year < 2014 || $year > 2025) continue;

        $ndvi  = isset($row['ndvi_avg'])            ? (float)$row['ndvi_avg']            : null;
        $viirs = isset($row['viirs_avg'])            ? (float)$row['viirs_avg']           : null;
        $precip = isset($row['precipitation_total']) ? (float)$row['precipitation_total'] : null;

        // Prefer separate day/night columns (monthly summary); fall back to lst_avg (yearly fallback)
        $lstDay   = array_key_exists('lst_day_avg', $row) && $row['lst_day_avg'] !== null
                        ? (float)$row['lst_day_avg'] : null;
        $lstNight = array_key_exists('lst_night_avg', $row) && $row['lst_night_avg'] !== null
                        ? (float)$row['lst_night_avg'] : null;
        if ($lstDay === null && isset($row['lst_avg']) && $row['lst_avg'] !== null) {
            $lstDay = (float)$row['lst_avg'];
        }

        if ($ndvi === null || $viirs === null || $lstDay === null || $precip === null) continue;

        $series['ndvi'][$year]      = $ndvi;
        $series['viirs'][$year]     = $viirs;
        $series['lst_day'][$year]   = $lstDay;
        $series['lst_night'][$year] = $lstNight ?? $lstDay; // night falls back to day when unavailable
        $series['precip'][$year]    = $precip;
    }

    $yearSets    = array_map(fn($s) => array_keys($s), $series);
    $commonYears = array_values(array_intersect(
        $yearSets['ndvi'],
        $yearSets['viirs'],
        $yearSets['lst_day'],
        $yearSets['lst_night'],
        $yearSets['precip']
    ));
    sort($commonYears);

    if (count($commonYears) < 2) {
        throw new Exception('Not enough aligned historical years where all environmental covariates have data. Need at least 2 years.');
    }

    $latestCommonYear = (int)max($commonYears);

    $lstCombined = [];
    foreach ($commonYears as $y) {
        $d = (float)($series['lst_day'][$y]   ?? 0.0);
        $n = (float)($series['lst_night'][$y] ?? $d);
        $lstCombined[$y] = ($d + $n) / 2.0;
    }

    $ndviStats     = baseline_with_trend($series['ndvi'],      $commonYears, $latestCommonYear);
    $viirsStats    = baseline_with_trend($series['viirs'],     $commonYears, $latestCommonYear);
    $lstDayStats   = baseline_with_trend($series['lst_day'],   $commonYears, $latestCommonYear);
    $lstNightStats = baseline_with_trend($series['lst_night'], $commonYears, $latestCommonYear);
    $lstStats      = baseline_with_trend($lstCombined,         $commonYears, $latestCommonYear);
    $precipStats   = baseline_with_trend($series['precip'],    $commonYears, $latestCommonYear);

    $landCover = dominant_land_cover_by_city($pdo, $city, $latestCommonYear);

    $base_ndvi   = max(0.0, min(1.0, $ndviStats['adjusted_baseline']));
    $base_viirs  = max(0.0, $viirsStats['adjusted_baseline']);
    $base_lst    = $lstStats['adjusted_baseline'];
    $base_precip = max(0.0, $precipStats['adjusted_baseline']);
    $lc_dummies  = $landCover['dummies'];

    $formulaSource = $sourceUsed === 'monthly'
        ? "ecological_monthly_summary (month {$month}-specific)"
        : 'ecological_yearly_summary (annual fallback — monthly data insufficient)';

    $historical_inputs = [
        'city'                    => $city === '' ? 'Metro Manila (All Cities)' : $city,
        'month'                   => $month,
        'cell_count'              => count($cell_ids),
        'latest_common_year'      => $latestCommonYear,
        'baseline_reference_year' => $latestCommonYear,
        'common_years'            => $commonYears,
        'year_span'               => count($commonYears) >= 2 ? [min($commonYears), max($commonYears)] : null,
        'baseline_source'         => $sourceUsed,
        'ndvi'                    => $ndviStats,
        'viirs'                   => $viirsStats,
        'lst_day'                 => $lstDayStats,
        'lst_night'               => $lstNightStats,
        'lst_combined'            => $lstStats,
        'precip_mm'               => $precipStats,
        'dominant_land_cover'     => $landCover,
        'model_inputs_used'       => [
            'base_ndvi'           => $base_ndvi,
            'base_viirs'          => $base_viirs,
            'base_lst'            => $base_lst,
            'base_lst_day'        => $lstDayStats['adjusted_baseline'],
            'base_lst_night'      => $lstNightStats['adjusted_baseline'],
            'base_precip'         => $base_precip,
            'land_cover_dummies'  => $lc_dummies,
        ],
        'formula_note'   => "Baseline = value(latest aligned year) + avg(yearly change) from {$formulaSource}.",
        'baseline_scope' => $sourceUsed === 'monthly' ? 'monthly_specific' : 'yearly_fallback',
    ];

    return [
        'base_ndvi'         => $base_ndvi,
        'base_viirs'        => $base_viirs,
        'base_lst'          => $base_lst,
        'base_precip'       => $base_precip,
        'lc_dummies'        => $lc_dummies,
        'historical_inputs' => $historical_inputs,
    ];
}

function polygons_contain_point(array $polygons, float $x, float $y): bool {
    foreach ($polygons as $poly) {
        if (polygon_contains_point($poly, $x, $y)) {
            return true;
        }
    }
    return false;
}

function latest_observation_rows(PDO $pdo): array {
    // Production table is aggregated_bird_observation (MariaDB).
    // Column mapping from legacy SQLite 'observations':
    //   total_unique  → unique_species_count
    //   total_migrant → total_migratory
    $sql = "
        SELECT o1.site_name, o1.latitude, o1.longitude,
               o1.unique_species_count AS total_unique,
               o1.total_tolerant, o1.total_sensitive,
               o1.total_resident, o1.total_migratory AS total_migrant
        FROM aggregated_bird_observation o1
        INNER JOIN (
            SELECT site_name, MAX(year * 100 + month) AS max_ym
            FROM aggregated_bird_observation
            WHERE site_name != '' AND latitude != 0 AND longitude != 0
            GROUP BY site_name
        ) latest
            ON o1.site_name = latest.site_name
           AND (o1.year * 100 + o1.month) = latest.max_ym
        WHERE o1.site_name != '' AND o1.latitude != 0 AND o1.longitude != 0
        ORDER BY o1.unique_species_count DESC
        LIMIT 250
    ";
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

function build_affected_areas_from_rows(array $rows, ?array $cityPolygons, float $richnessChangePct): array {
    $areas = [];

    foreach ($rows as $row) {
        $lat = (float)$row['latitude'];
        $lon = (float)$row['longitude'];
        if ($cityPolygons && !polygons_contain_point($cityPolygons, $lon, $lat)) {
            continue;
        }

        $baselineSpecies = max(0, (int)$row['total_unique']);
        $predictedSpecies = max(0, (int)round($baselineSpecies * (1 + ($richnessChangePct / 100.0))));
        $change = $predictedSpecies - $baselineSpecies;
        $absChange = abs($change);

        $areas[] = [
            'name' => (string)$row['site_name'],
            'current' => $baselineSpecies,
            'predicted' => $predictedSpecies,
            'change' => $change,
            'impact_level' => $absChange >= 8 ? 'High' : ($absChange >= 4 ? 'Medium' : 'Low'),
        ];

        if (count($areas) >= 15) {
            break;
        }
    }

    return $areas;
}

function average_city_baseline_total_from_rows(array $rows, ?array $cityPolygons): float {
    $vals = [];
    foreach ($rows as $row) {
        $lat = (float)$row['latitude'];
        $lon = (float)$row['longitude'];
        if ($cityPolygons && !polygons_contain_point($cityPolygons, $lon, $lat)) {
            continue;
        }
        $vals[] = max(0, (float)$row['total_unique']);
    }

    if (empty($vals)) {
        return 0.0;
    }
    return array_sum($vals) / count($vals);
}

function average_city_baseline_outputs_from_rows(array $rows, ?array $cityPolygons): array {
    $totals = [
        'tolerant' => [],
        'sensitive' => [],
        'resident' => [],
        'migrant' => [],
        'total' => [],
    ];

    foreach ($rows as $row) {
        $lat = (float)$row['latitude'];
        $lon = (float)$row['longitude'];
        if ($cityPolygons && !polygons_contain_point($cityPolygons, $lon, $lat)) {
            continue;
        }

        $totals['tolerant'][] = max(0, (float)($row['total_tolerant'] ?? 0));
        $totals['sensitive'][] = max(0, (float)($row['total_sensitive'] ?? 0));
        $totals['resident'][] = max(0, (float)($row['total_resident'] ?? 0));
        $totals['migrant'][] = max(0, (float)($row['total_migrant'] ?? 0));
        $totals['total'][] = max(0, (float)($row['total_unique'] ?? 0));
    }

    $avg = [];
    foreach ($totals as $k => $vals) {
        $avg[$k] = empty($vals) ? 0.0 : (array_sum($vals) / count($vals));
    }
    return $avg;
}

function table_has_column(PDO $pdo, string $table, string $column): bool {
    $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'mysql') {
        $stmt = $pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1');
        $stmt->execute([$table, $column]);
        return (bool)$stmt->fetchColumn();
    }
    if ($driver === 'sqlite') {
        $stmt = $pdo->prepare("PRAGMA table_info('" . str_replace("'", "''", $table) . "')");
        $stmt->execute();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (($row['name'] ?? '') === $column) {
                return true;
            }
        }
    }
    return false;
}

function enforce_pair_balance(float $tolerant, float $sensitive, float $resident, float $migrant): array {
    $tolerant = max(0.0, $tolerant);
    $sensitive = max(0.0, $sensitive);
    $resident = max(0.0, $resident);
    $migrant = max(0.0, $migrant);

    $pairA = $tolerant + $sensitive;
    $pairB = $resident + $migrant;
    $consensus = ($pairA + $pairB) / 2.0;

    $eps = 1e-9;

    $adjTolerant = $consensus * ($tolerant / max($pairA, $eps));
    $adjSensitive = $consensus * ($sensitive / max($pairA, $eps));
    $adjResident = $consensus * ($resident / max($pairB, $eps));
    $adjMigrant = $consensus * ($migrant / max($pairB, $eps));

    return [
        'tolerant' => $adjTolerant,
        'sensitive' => $adjSensitive,
        'resident' => $adjResident,
        'migrant' => $adjMigrant,
        'total' => $consensus,
    ];
}

try {
    // Get POST parameters
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception("No input data received");
    }
    
    // Extract parameters from request
    $light_reduction = isset($input['light_reduction']) ? floatval($input['light_reduction']) : 0;
    $ndvi_increase = isset($input['ndvi_increase']) ? floatval($input['ndvi_increase']) : 0;
    $temp_change = isset($input['temp_change']) ? floatval($input['temp_change']) : 0;
    $precip_change = isset($input['precip_change']) ? floatval($input['precip_change']) : 0;
    $month = isset($input['month']) ? intval($input['month']) : 1;
    if ($month < 1 || $month > 12) {
        throw new Exception('Month must be between 1 and 12.');
    }
    $city = isset($input['city']) ? trim((string)$input['city']) : '';
    $manual_mode = !empty($input['manual_mode']);
    // Force full-stack inference path (XGBoost + ConvLSTM + meta-learner).
    $meta_only = false;
    $shap_output = isset($input['shap_output']) ? strtolower(trim((string)$input['shap_output'])) : 'all';
    if (!in_array($shap_output, ['all', 'sensitive', 'tolerant', 'resident', 'migrant'], true)) {
        $shap_output = 'all';
    }
    $attribution_mode = isset($input['attribution_mode']) ? strtolower(trim((string)$input['attribution_mode'])) : 'sensitivity';
    if (!in_array($attribution_mode, ['tree_path', 'sensitivity'], true)) {
        $attribution_mode = 'sensitivity';
    }
    $prewarm_only = !empty($input['prewarm_only']);
    
    // Optional: Cell ID for fetching actual baseline values from database
    $cell_id = isset($input['cell_id']) ? $input['cell_id'] : null;
    
    // Default fallbacks if DB has incomplete records.
    $base_ndvi = isset($input['base_ndvi']) ? floatval($input['base_ndvi']) : 0.45;
    $base_viirs = isset($input['base_viirs']) ? floatval($input['base_viirs']) : 25.0;
    $base_lst = isset($input['base_lst']) ? floatval($input['base_lst']) : 28.0;
    $base_precip = isset($input['base_precip']) ? floatval($input['base_precip']) : 150.0;
    $lc_dummies = [
        isset($input['lc_dummy_1']) ? floatval($input['lc_dummy_1']) : 1.0,
        isset($input['lc_dummy_2']) ? floatval($input['lc_dummy_2']) : 0.0,
        isset($input['lc_dummy_3']) ? floatval($input['lc_dummy_3']) : 0.0,
        isset($input['lc_dummy_4']) ? floatval($input['lc_dummy_4']) : 0.0,
        isset($input['lc_dummy_5']) ? floatval($input['lc_dummy_5']) : 0.0,
    ];

    // City-driven DB baseline extraction with year alignment + trend adjustment.
    $historical_inputs = null;
    $pdo = get_mysql_db();
    $city_polygons = $city !== '' ? city_geometry($city) : null;
    if (!$manual_mode) {
        $cache_key = bau_city_cache_key($city);
        $cache_row = get_mysql_bau_baseline_cache($pdo, $cache_key, $month, 2592000);

        if ($cache_row !== null) {
            $base_ndvi = (float)$cache_row['base_ndvi'];
            $base_viirs = (float)$cache_row['base_viirs'];
            $base_lst = (float)$cache_row['base_lst'];
            $base_precip = (float)$cache_row['base_precip'];
            $lc_dummies = [
                (float)$cache_row['lc_dummy_1'],
                (float)$cache_row['lc_dummy_2'],
                (float)$cache_row['lc_dummy_3'],
                (float)$cache_row['lc_dummy_4'],
                (float)$cache_row['lc_dummy_5'],
            ];
            $historical_inputs = json_decode((string)($cache_row['historical_inputs_json'] ?? ''), true);
            if (!is_array($historical_inputs)) {
                $historical_inputs = [
                    'city' => $city === '' ? 'Metro Manila (All Cities)' : $city,
                    'month' => $month,
                ];
            }
            $historical_inputs['cache'] = [
                'hit' => true,
                'city_key' => $cache_key,
                'refreshed_at' => $cache_row['refreshed_at'] ?? null,
            ];
        } else {
            $live = compute_bau_baseline_from_live_data($pdo, $city, $month);
            $base_ndvi = (float)$live['base_ndvi'];
            $base_viirs = (float)$live['base_viirs'];
            $base_lst = (float)$live['base_lst'];
            $base_precip = (float)$live['base_precip'];
            $lc_dummies = $live['lc_dummies'];
            $historical_inputs = $live['historical_inputs'];

            upsert_mysql_bau_baseline_cache($pdo, [
                'city_key' => $cache_key,
                'city_label' => $city === '' ? 'Metro Manila (All Cities)' : $city,
                'month' => $month,
                'base_ndvi' => $base_ndvi,
                'base_viirs' => $base_viirs,
                'base_lst' => $base_lst,
                'base_precip' => $base_precip,
                'lc_dummy_1' => (float)$lc_dummies[0],
                'lc_dummy_2' => (float)$lc_dummies[1],
                'lc_dummy_3' => (float)$lc_dummies[2],
                'lc_dummy_4' => (float)$lc_dummies[3],
                'lc_dummy_5' => (float)$lc_dummies[4],
                'cell_count' => (int)($historical_inputs['cell_count'] ?? 0),
                'latest_common_year' => isset($historical_inputs['latest_common_year']) ? (int)$historical_inputs['latest_common_year'] : null,
                'historical_inputs_json' => json_encode($historical_inputs, JSON_UNESCAPED_SLASHES),
            ]);

            $historical_inputs['cache'] = [
                'hit' => false,
                'city_key' => $cache_key,
                'refreshed_at' => gmdate('Y-m-d H:i:s'),
            ];
        }
    } elseif ($manual_mode && $city !== '') {
        $city_cells_for_landcover = city_cell_ids($pdo, $city);
        if (!empty($city_cells_for_landcover)) {
            $landCover = dominant_land_cover($pdo, $city_cells_for_landcover, (int)date('Y'));
            $lc_dummies = $landCover['dummies'];
        }
    }

    if ($prewarm_only) {
        echo json_encode([
            'success' => true,
            'prewarm_only' => true,
            'city' => $city,
            'month' => $month,
            'historical_inputs' => $historical_inputs,
        ]);
        exit;
    }
    
    // Prepare request payload for Python backend
    $python_payload = [
        'light_reduction_pct' => $light_reduction,
        'ndvi_increase_pct' => $ndvi_increase,
        'temp_change_c' => $temp_change,
        'precip_change_pct' => $precip_change,
        'month' => $month,
        'meta_only' => $meta_only,
        'shap_output' => $shap_output,
        'attribution_mode' => $attribution_mode,
        'cell_id' => $cell_id,
        'base_ndvi' => $base_ndvi,
        'base_viirs' => $base_viirs,
        'base_lst' => $base_lst,
        'base_precip' => $base_precip,
        'lc_dummy_1' => $lc_dummies[0],
        'lc_dummy_2' => $lc_dummies[1],
        'lc_dummy_3' => $lc_dummies[2],
        'lc_dummy_4' => $lc_dummies[3],
        'lc_dummy_5' => $lc_dummies[4],
    ];
    
    // Call Python FastAPI backend (retry up to 3x on empty-reply — handles
    // Railway cold-start where TensorFlow takes longer than the sleep in start.sh)
    $python_url = PYTHON_BACKEND_URL . '/predict';
    $python_payload_json = json_encode($python_payload);

    $response   = false;
    $http_code  = 0;
    $curl_error = '';
    $max_attempts = 3;

    for ($attempt = 1; $attempt <= $max_attempts; $attempt++) {
        $ch = curl_init($python_url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 45,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_POSTFIELDS     => $python_payload_json,
        ]);
        $response  = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        // Only retry on transient empty-reply (CURLE_GOT_NOTHING); bail immediately on real errors
        if (!$curl_error || $attempt === $max_attempts) break;
        if (strpos($curl_error, 'Empty reply') === false && strpos($curl_error, 'recv failure') === false) break;
        sleep($attempt * 3); // 3s, 6s back-off between retries
    }

    // Handle connection errors
    if ($curl_error) {
        throw new Exception("Failed to connect to Python backend: " . $curl_error .
                          "\nMake sure the FastAPI server is running on " . PYTHON_BACKEND_URL);
    }
    
    if ($http_code !== 200) {
        throw new Exception("Python backend returned HTTP $http_code: $response");
    }
    
    // Decode and validate response
    $ml_results = json_decode($response, true);
    
    if (!$ml_results || !isset($ml_results['success'])) {
        throw new Exception("Invalid response from Python backend");
    }
    
    // Extract predictions
    $predictions = $ml_results['predictions'] ?? [];
    $predictions_cont = $ml_results['predictions_continuous'] ?? [];
    $out_tolerant = isset($predictions_cont['tolerant']) ? (float)$predictions_cont['tolerant'] : (float)($predictions['tolerant'] ?? 0);
    $out_sensitive = isset($predictions_cont['sensitive']) ? (float)$predictions_cont['sensitive'] : (float)($predictions['sensitive'] ?? 0);
    $out_resident = isset($predictions_cont['resident']) ? (float)$predictions_cont['resident'] : (float)($predictions['resident'] ?? 0);
    $out_migrant = isset($predictions_cont['migrant']) ? (float)$predictions_cont['migrant'] : (float)($predictions['migrant'] ?? 0);

    // Safety net: enforce strict pair consistency so gains stay aligned across pairs.
    $balanced = enforce_pair_balance($out_tolerant, $out_sensitive, $out_resident, $out_migrant);
    $out_tolerant = (float)$balanced['tolerant'];
    $out_sensitive = (float)$balanced['sensitive'];
    $out_resident = (float)$balanced['resident'];
    $out_migrant = (float)$balanced['migrant'];
    $out_total = (float)$balanced['total'];
    $shap_chart = $ml_results['shap_chart'] ?? [];
    usort($shap_chart, fn($a, $b) => (($b['importance'] ?? 0) <=> ($a['importance'] ?? 0)));
    $shap_by_output = $ml_results['shap_by_output'] ?? [];
    foreach (['sensitive', 'tolerant', 'resident', 'migrant'] as $k) {
        if (!isset($shap_by_output[$k]) || !is_array($shap_by_output[$k])) {
            $shap_by_output[$k] = [];
            continue;
        }
        usort($shap_by_output[$k], fn($a, $b) => (($b['importance'] ?? 0) <=> ($a['importance'] ?? 0)));
    }

    // Calculate impact metrics using observation-derived city baseline (not hardcoded).
    $total_richness = $out_total;
    $obs_rows = latest_observation_rows($pdo);
    $baseline_total = average_city_baseline_total_from_rows($obs_rows, $city_polygons);
    $baseline_by_output = average_city_baseline_outputs_from_rows($obs_rows, $city_polygons);
    $richness_change_pct = 0;
    if ($baseline_total > 0) {
        $richness_change_pct = (($total_richness - $baseline_total) / $baseline_total) * 100;
    }

    // Build affected areas from live observations.
    $affected_areas = build_affected_areas_from_rows($obs_rows, $city_polygons, $richness_change_pct);
    
    // Build final response
    $response_data = [
        'success' => true,
        'parameters' => [
            'light_reduction' => $light_reduction,
            'ndvi_increase' => $ndvi_increase,
            'temp_change' => $temp_change,
            'precip_change' => $precip_change,
            'month' => $month,
            'city' => $city,
            'manual_mode' => $manual_mode,
            'meta_only' => false,
            'shap_output' => $shap_output,
            'attribution_mode' => $attribution_mode,
        ],
        'results' => [
            'tolerant' => round($out_tolerant, 2),
            'sensitive' => round($out_sensitive, 2),
            'resident' => round($out_resident, 2),
            'migrant' => round($out_migrant, 2),
            'total' => round($out_total, 2),
            'richness_change_pct' => round($richness_change_pct, 2),
            'baseline_total' => round($baseline_total, 2),
            'baseline_by_output' => [
                'tolerant' => round((float)($baseline_by_output['tolerant'] ?? 0), 2),
                'sensitive' => round((float)($baseline_by_output['sensitive'] ?? 0), 2),
                'resident' => round((float)($baseline_by_output['resident'] ?? 0), 2),
                'migrant' => round((float)($baseline_by_output['migrant'] ?? 0), 2),
                'all' => round((float)($baseline_by_output['total'] ?? $baseline_total), 2),
                'total' => round((float)($baseline_by_output['total'] ?? $baseline_total), 2),
            ],
        ],
        'affected_areas' => $affected_areas,
        'parameters_applied' => $ml_results['parameters_applied'] ?? [],
        'environmental_values' => $ml_results['environmental_values'] ?? [],
        'input_values' => $ml_results['input_values'] ?? [],
        'historical_inputs' => $historical_inputs,
        'shap_source' => $ml_results['shap_source'] ?? null,
        'shap_warning' => $ml_results['shap_warning'] ?? null,
        'attribution_mode' => $ml_results['attribution_mode'] ?? $attribution_mode,
        'model_outputs' => [
            'sensitive' => round($out_sensitive, 2),
            'tolerant' => round($out_tolerant, 2),
            'migrant' => round($out_migrant, 2),
            'resident' => round($out_resident, 2),
        ],
        'shap_chart' => $shap_chart,
        'shap_by_output' => $shap_by_output,
    ];
    
    echo json_encode($response_data);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
