<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

if (!is_logged_in()) {
    echo json_encode(['success' => false, 'locations' => []]);
    exit;
}

$species_id = filter_input(INPUT_GET, 'species_id', FILTER_VALIDATE_INT);
if (!$species_id) {
    echo json_encode(['success' => false, 'locations' => []]);
    exit;
}

try {
    $pdo  = get_mysql_db();
    $stmt = $pdo->prepare(
        "SELECT site_name, latitude, longitude, COUNT(*) AS sighting_count
           FROM raw_bird_observation
          WHERE species_id = :sid
            AND site_name IS NOT NULL AND site_name != ''
          GROUP BY site_name, latitude, longitude
          ORDER BY sighting_count DESC
          LIMIT 10"
    );
    $stmt->execute([':sid' => $species_id]);
    $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'locations' => $locations]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'locations' => [], 'error' => $e->getMessage()]);
}
