<?php
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json');

$tab = $_GET['tab'] ?? 'no-category';
$tab = in_array($tab, ['no-category', 'edit-details'], true) ? $tab : 'no-category';

$page = max(1, (int) ($_GET['page'] ?? 1));
$per_page = (int) ($_GET['per_page'] ?? 25);
$per_page = max(5, min(100, $per_page));
$q = trim((string) ($_GET['q'] ?? ''));

try {
    $pdo = get_mysql_db();

    $select_cols = "species_id AS id, species_name AS common_name, light_tolerance, migratory_status AS migration_status, description, image_path";

    $where = [];
    $params = [];

    if ($tab === 'no-category') {
        $where[] = "(light_tolerance='' OR light_tolerance IS NULL OR migratory_status='' OR migratory_status IS NULL)";
    }

    if ($q !== '') {
        $where[] = 'species_name LIKE :q';
        $params[':q'] = '%' . $q . '%';
    }

    $where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM species_masterlist $where_sql");
    $count_stmt->execute($params ?: null);
    $total = (int) $count_stmt->fetchColumn();

    $total_pages = max(1, (int) ceil($total / $per_page));
    $page = min($page, $total_pages);
    $offset = ($page - 1) * $per_page;

    if ($tab === 'edit-details') {
        $sql = "SELECT $select_cols,
                       (image_path IS NULL OR image_path='') AS no_image,
                       (description IS NULL OR description='') AS no_desc
                FROM species_masterlist
                $where_sql
                ORDER BY species_name
                LIMIT :limit OFFSET :offset";
    } else {
        $sql = "SELECT $select_cols
                FROM species_masterlist
                $where_sql
                ORDER BY species_name
                LIMIT :limit OFFSET :offset";
    }

    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $root = dirname(__DIR__);
    $rows = array_map(function (array $row) use ($root): array {
        $path = $row['image_path'] ?? '';
        if (!empty($path)) {
            $abs = $root . '/' . ltrim($path, '/');
            if (is_file($abs)) {
                $row['image_path'] = $path . '?v=' . filemtime($abs);
            }
        }
        return $row;
    }, $rows);

    echo json_encode([
        'success' => true,
        'rows' => $rows,
        'total' => $total,
        'page' => $page,
        'per_page' => $per_page,
        'total_pages' => $total_pages,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error: ' . $e->getMessage(),
    ]);
}
