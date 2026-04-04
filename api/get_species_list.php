<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/db.php';

try {
    $pdo  = get_mysql_db();
    $rows = $pdo->query(
        'SELECT species_id AS id, species_name AS name
         FROM species_masterlist
         ORDER BY species_name'
    )->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'species' => $rows]);
} catch (PDOException $ex) {
    echo json_encode(['success' => false, 'error' => $ex->getMessage()]);
}
