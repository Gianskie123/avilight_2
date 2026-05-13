<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/db.php';

$year = isset($_GET['year']) ? (int) $_GET['year'] : 0;
$month = isset($_GET['month']) ? (int) $_GET['month'] : 0;
$area = trim((string) ($_GET['area'] ?? 'All Areas'));
$filter_migratory = strtolower(trim((string) ($_GET['filter_migratory'] ?? '')));
$filter_light = strtolower(trim((string) ($_GET['filter_light'] ?? '')));
$bird = strtolower(trim((string) ($_GET['bird'] ?? '')));
$species_query = strtolower(trim((string) ($_GET['species_query'] ?? '')));
$species_page = max(1, (int) ($_GET['species_page'] ?? 1));
$species_per_page = (int) ($_GET['species_per_page'] ?? 5);
$species_per_page = min(50, max(1, $species_per_page));
$species_sort = trim((string) ($_GET['species_sort'] ?? 'name'));

if ($year < 2000 || $year > 2100) {
    echo json_encode(['success' => false, 'error' => 'Invalid year']);
    exit;
}

try {
    $pdo = get_mysql_db();

    $where = ['p.year = :year'];
    $params = [':year' => $year];

    if ($month >= 1 && $month <= 12) {
        $where[] = 'p.month = :month';
        $params[':month'] = $month;
    }

    if ($area !== '' && strtolower($area) !== 'all areas') {
        $where[] = 'p.area = :area';
        $params[':area'] = $area;
    }

    if ($filter_migratory !== '') {
        $where[] = 'p.migratory_status = :migratory_status';
        $params[':migratory_status'] = $filter_migratory;
    }

    if ($filter_light !== '') {
        $where[] = 'p.light_tolerance = :light_tolerance';
        $params[':light_tolerance'] = $filter_light;
    }

    if ($bird !== '') {
        $where[] = 'LOWER(TRIM(sm.species_name)) = :bird';
        $params[':bird'] = $bird;
    }

    $baseWhereSql = implode(' AND ', $where);
    $listWhere = $baseWhereSql;
    $listParams = $params;

    if ($species_query !== '') {
        $listWhere .= ' AND LOWER(TRIM(sm.species_name)) LIKE :species_query';
        $listParams[':species_query'] = '%' . $species_query . '%';
    }

    $migrationSql = "SELECT p.migratory_status AS category, COUNT(DISTINCT p.species_id) AS species_count
        FROM snapshot_species_presence p
        JOIN species_masterlist sm ON sm.species_id = p.species_id
        WHERE {$baseWhereSql}
        GROUP BY p.migratory_status";

    $lightSql = "SELECT p.light_tolerance AS category, COUNT(DISTINCT p.species_id) AS species_count
        FROM snapshot_species_presence p
        JOIN species_masterlist sm ON sm.species_id = p.species_id
        WHERE {$baseWhereSql}
        GROUP BY p.light_tolerance";

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
        'migratory' => 0,
        'resident' => 0,
    ];
    foreach ($migrationRows as $row) {
        $cat = (string) ($row['category'] ?? '');
        $count = (int) ($row['species_count'] ?? 0);
        if ($cat === 'migratory') {
            $migrationMap['migratory'] += $count;
        } elseif ($cat === 'resident') {
            $migrationMap['resident'] += $count;
        }
    }

    $lightMap = [
        'tolerant' => 0,
        'sensitive' => 0,
    ];
    foreach ($lightRows as $row) {
        $cat = (string) ($row['category'] ?? '');
        $count = (int) ($row['species_count'] ?? 0);
        if ($cat === 'tolerant') {
            $lightMap['tolerant'] += $count;
        } elseif ($cat === 'sensitive') {
            $lightMap['sensitive'] += $count;
        }
    }

    if ($filter_migratory === 'migratory') {
        $migrationMap['resident'] = 0;
    } elseif ($filter_migratory === 'resident') {
        $migrationMap['migratory'] = 0;
    }

    if ($filter_light === 'tolerant') {
        $lightMap['sensitive'] = 0;
    } elseif ($filter_light === 'sensitive') {
        $lightMap['tolerant'] = 0;
    }

    $totalCountSql = "SELECT COUNT(DISTINCT p.species_id)
        FROM snapshot_species_presence p
        JOIN species_masterlist sm ON sm.species_id = p.species_id
        WHERE {$listWhere}";

    $countStmt = $pdo->prepare($totalCountSql);
    foreach ($listParams as $k => $v) {
        $countStmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $countStmt->execute();
    $totalCount = (int) ($countStmt->fetchColumn() ?: 0);

    $offset = ($species_page - 1) * $species_per_page;
    $orderSql = 'ORDER BY sm.species_name ASC';
    if ($species_sort === 'name_desc') {
        $orderSql = 'ORDER BY sm.species_name DESC';
    }

    $listSql = "SELECT DISTINCT
            p.species_id,
            sm.species_name AS common_name,
            COALESCE(NULLIF(TRIM(sm.migratory_status), ''), p.migratory_status) AS migratory_status,
            COALESCE(NULLIF(TRIM(sm.light_tolerance), ''), p.light_tolerance) AS light_tolerance
        FROM snapshot_species_presence p
        JOIN species_masterlist sm ON sm.species_id = p.species_id
        WHERE {$listWhere}
        {$orderSql}
        LIMIT :limit OFFSET :offset";

    $listStmt = $pdo->prepare($listSql);
    foreach ($listParams as $k => $v) {
        $listStmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $listStmt->bindValue(':limit', $species_per_page, PDO::PARAM_INT);
    $listStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $listStmt->execute();
    $rows = $listStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    echo json_encode([
        'success' => true,
        'area' => $area === '' ? 'All Areas' : $area,
        'year' => $year,
        'month' => $month,
        'counts' => [
            'resident' => $migrationMap['resident'],
            'migratory' => $migrationMap['migratory'],
            'tolerant' => $lightMap['tolerant'],
            'sensitive' => $lightMap['sensitive'],
            'total_species' => max(array_sum($migrationMap), array_sum($lightMap)),
        ],
        'species' => [
            'rows' => $rows,
            'page' => $species_page,
            'per_page' => $species_per_page,
            'total' => $totalCount,
        ],
    ]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
