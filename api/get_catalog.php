<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/db.php';

$pdo = get_mysql_db();

$tolerance_filter = isset($_GET['tolerance']) ? (string)$_GET['tolerance'] : 'all';
$migration_filter = isset($_GET['migration']) ? (string)$_GET['migration'] : 'all';
$search_query     = isset($_GET['search'])    ? trim((string)$_GET['search']) : '';
$page             = max(1, (int)($_GET['page'] ?? 1));
$per_page         = 50;

$where  = [];
$params = [];
$valid_tolerances = ['sensitive', 'tolerant'];
$valid_migrations = ['resident', 'migratory'];

$tol_lower = strtolower($tolerance_filter);
if (in_array($tol_lower, $valid_tolerances, true)) {
    $where[]  = 'light_tolerance = :tol';
    $params[':tol'] = $tol_lower;
}
$mig_lower = strtolower($migration_filter);
if (in_array($mig_lower, $valid_migrations, true)) {
    $where[]  = 'migratory_status = :mig';
    $params[':mig'] = $mig_lower;
}

$order_sql = 'ORDER BY species_name';
$fuzzy_ids = null;

if ($search_query !== '') {
    $words = array_values(array_filter(array_map('trim', preg_split('/\s+/', $search_query))));
    $like_conds   = ['species_name LIKE :phrase'];
    $params[':phrase'] = '%' . $search_query . '%';
    foreach ($words as $i => $word) {
        $key = ':word' . $i;
        $like_conds[] = "species_name LIKE $key";
        $params[$key] = '%' . $word . '%';
    }
    $where[] = '(' . implode(' OR ', $like_conds) . ')';
    $order_sql = 'ORDER BY CASE WHEN species_name LIKE :rank_phrase THEN 0 ELSE 1 END, species_name';
}

$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$count_stmt = $pdo->prepare("SELECT COUNT(*) FROM species_masterlist $where_sql");
$count_stmt->execute($params ?: null);
$like_count = (int)$count_stmt->fetchColumn();

// Levenshtein fallback
if ($search_query !== '' && $like_count === 0) {
    function catalog_fuzzy(PDO $pdo, string $query): array {
        $q     = strtolower(trim($query));
        $words = array_values(array_filter(preg_split('/\s+/', $q), fn($w) => strlen($w) >= 2));
        if (!$words) return [];
        $rows = $pdo->query('SELECT species_id, LOWER(species_name) AS name FROM species_masterlist')->fetchAll(PDO::FETCH_ASSOC);
        $matches = [];
        foreach ($rows as $row) {
            $name_words = array_values(array_filter(preg_split('/[\s\-]+/', $row['name']), fn($w) => strlen($w) >= 2));
            $score = 0;
            if (strpos($row['name'], $q) !== false) { $score = 1000; }
            else {
                foreach ($words as $qw) {
                    $best = PHP_INT_MAX;
                    foreach ($name_words as $nw) { $best = min($best, levenshtein($qw, $nw)); }
                    $max_allowed = max(1, (int)(strlen($qw) / 3));
                    if ($best <= $max_allowed) { $score += (strlen($qw) - $best) * 10; }
                }
            }
            if ($score > 0) { $matches[(int)$row['species_id']] = $score; }
        }
        arsort($matches);
        return $matches;
    }

    $fuzzy_map = catalog_fuzzy($pdo, $search_query);
    if ($fuzzy_map) {
        $fuzzy_ids    = array_keys($fuzzy_map);
        $filter_where = [];
        $filter_params = [];
        if (in_array($tol_lower, $valid_tolerances, true)) { $filter_where[] = 'light_tolerance = :tol'; $filter_params[':tol'] = $tol_lower; }
        if (in_array($mig_lower, $valid_migrations, true)) { $filter_where[] = 'migratory_status = :mig'; $filter_params[':mig'] = $mig_lower; }
        $in_list      = implode(',', array_map('intval', $fuzzy_ids));
        $filter_where[] = "species_id IN ($in_list)";
        $fuzzy_where_sql = $filter_where ? 'WHERE ' . implode(' AND ', $filter_where) : '';
        $cs = $pdo->prepare("SELECT COUNT(*) FROM species_masterlist $fuzzy_where_sql");
        $cs->execute($filter_params ?: null);
        $like_count = (int)$cs->fetchColumn();
        $order_sql  = 'ORDER BY FIELD(species_id, ' . implode(',', $fuzzy_ids) . ')';
        $params     = $filter_params;
        $where_sql  = $fuzzy_where_sql;
    } else {
        $fuzzy_ids = [];
    }
}

$total_filtered = $like_count;
$total_pages    = max(1, (int)ceil($total_filtered / $per_page));
$page           = min($page, $total_pages);
$offset         = ($page - 1) * $per_page;

$rows = [];
if ($total_filtered > 0) {
    $fetch_params = $params;
    if ($search_query !== '' && $fuzzy_ids === null) {
        $fetch_params[':rank_phrase'] = '%' . $search_query . '%';
    }
    $stmt = $pdo->prepare(
        "SELECT species_id AS id, species_name AS common_name, light_tolerance,
                migratory_status AS migration_status, description, image_path
         FROM species_masterlist
         $where_sql $order_sql
         LIMIT $per_page OFFSET $offset"
    );
    $stmt->execute($fetch_params ?: null);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$root = realpath(__DIR__ . '/..');
$rows = array_map(function (array $row) use ($root): array {
    $path = $row['image_path'] ?? '';
    if (!empty($path)) {
        $clean = strtok($path, '?');
        $abs   = $root . '/' . ltrim($clean, '/');
        if (is_file($abs)) {
            $row['image_path'] = $clean . '?v=' . filemtime($abs);
        }
        // WebP sibling
        $webp_clean = preg_replace('/\.(jpg|jpeg|png)$/i', '.webp', $clean);
        $webp_abs   = $root . '/' . ltrim($webp_clean, '/');
        $row['webp_path'] = is_file($webp_abs) ? ($webp_clean . '?v=' . filemtime($webp_abs)) : '';
    } else {
        $row['webp_path'] = '';
    }
    return $row;
}, $rows);

echo json_encode([
    'success'        => true,
    'rows'           => array_values($rows),
    'total_filtered' => $total_filtered,
    'total_pages'    => $total_pages,
    'page'           => $page,
    'per_page'       => $per_page,
]);
