<?php
/**
 * api/get_historical_data.php
 *
 * Returns observation data from aggregated_bird_observation filtered by year/month.
 * Optionally joins final_master_grid to filter by or return environmental covariate values.
 *
 * Query params:
 *   year              (required) – 4-digit year
 *   month             (optional) – 1–12; omit for all months
 *   env_type          (optional) – land_cover | ndvi | viirs | precip | land_temp
 *   land_cover_types  (optional) – comma-separated land cover labels (when env_type=land_cover)
 *   temp_period       (optional) – day | night (when env_type=land_temp)
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$year        = isset($_GET['year'])  ? (int) $_GET['year']  : 0;
$month       = isset($_GET['month']) ? (int) $_GET['month'] : 0;
$env_type    = trim((string) ($_GET['env_type'] ?? ''));
$temp_period = in_array($_GET['temp_period'] ?? '', ['day', 'night']) ? $_GET['temp_period'] : 'day';

// Land cover label → MODIS numeric code map
$lc_label_map = [
    'Urban & Built-up'        => 13,
    'Water Bodies'            => 17,
    'Forest'                  => 4,
    'Croplands'               => 12,
    'Grasslands'              => 10,
    'Wetlands'                => 11,
    'Woody Savannas'          => 8,
    'Cropland Mosaics'        => 14,
    'Barren'                  => 16,
];

$land_cover_codes = [];
if ($env_type === 'land_cover' && !empty($_GET['land_cover_types'])) {
    foreach (explode(',', (string) $_GET['land_cover_types']) as $label) {
        $label = trim($label);
        if (isset($lc_label_map[$label])) {
            $land_cover_codes[] = $lc_label_map[$label];
        }
    }
}

if ($year < 2000 || $year > 2100) {
    echo json_encode(['error' => 'Invalid year']);
    exit;
}

try {
    $pdo = get_mysql_db();

    // Metro Manila bounding box
    $lat_min = 14.35; $lat_max = 14.82;
    $lng_min = 120.90; $lng_max = 121.22;

    // Determine which env column to return
    $env_col = null;
    switch ($env_type) {
        case 'ndvi':       $env_col = 'fmg.ndvi';          break;
        case 'viirs':      $env_col = 'fmg.viirs_avg_rad'; break;
        case 'precip':     $env_col = 'fmg.monthly_precip_mm'; break;
        case 'land_temp':  $env_col = $temp_period === 'night' ? 'fmg.lst_night' : 'fmg.lst_day'; break;
        case 'land_cover': $env_col = 'fmg.land_cover';    break;
    }

    $select_env = $env_col ? ", {$env_col} AS env_value" : ", NULL AS env_value";

    // Build JOIN and WHERE clauses
    $join  = '';
    $where = ['abo.year = :yr',
              'abo.latitude  BETWEEN :lat_min AND :lat_max',
              'abo.longitude BETWEEN :lng_min AND :lng_max',
              'abo.latitude  != 0', 'abo.longitude != 0',
              "abo.site_name != ''"];
    $params = [
        ':yr'      => $year,
        ':lat_min' => $lat_min, ':lat_max' => $lat_max,
        ':lng_min' => $lng_min, ':lng_max' => $lng_max,
    ];

    if ($month >= 1 && $month <= 12) {
        $where[]          = 'abo.month = :mo';
        $params[':mo']    = $month;
    }

    // Join final_master_grid when an env type is requested
    if ($env_type) {
        $join = 'LEFT JOIN final_master_grid fmg
                    ON fmg.lat = abo.grid_lat AND fmg.lon = abo.grid_lon
                   AND fmg.year = abo.year AND fmg.month = abo.month';

        // Filter observations to only those in matching land cover cells
        if ($env_type === 'land_cover' && !empty($land_cover_codes)) {
            $in_placeholders = implode(',', array_fill(0, count($land_cover_codes), '?'));
            $where[] = "fmg.land_cover IN ({$in_placeholders})";
            // PDO named params can't mix with positional, so append values to a separate array
            $lc_params = $land_cover_codes;
        }
    }

    $where_sql = implode(' AND ', $where);
    $sql = "SELECT abo.species_list, abo.site_name,
                   abo.latitude, abo.longitude, abo.month, abo.year,
                   abo.unique_species_count AS total_unique,
                   abo.total_tolerant, abo.total_sensitive,
                   abo.total_resident, abo.total_migratory AS total_migrant,
                   abo.bird_count AS total_count
                   {$select_env}
            FROM aggregated_bird_observation abo
            {$join}
            WHERE {$where_sql}
            ORDER BY abo.unique_species_count DESC";

    // Mix named + positional not allowed — use positional for all
    $pos_params = array_values($params);
    $sql_positional = str_replace(
        [':yr', ':lat_min', ':lat_max', ':lng_min', ':lng_max', ':mo'],
        ['?',   '?',        '?',        '?',        '?',        '?'],
        $sql
    );
    if (!empty($lc_params ?? [])) {
        $pos_params = array_merge($pos_params, $lc_params);
    }

    $stmt = $pdo->prepare($sql_positional);
    $stmt->execute($pos_params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $rows, 'env_type' => $env_type ?: null]);

} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
