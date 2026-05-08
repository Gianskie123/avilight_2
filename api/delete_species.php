<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$species_id = (int)($_POST['species_id'] ?? 0);
if ($species_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid species ID']);
    exit;
}

try {
    $pdo = get_mysql_db();

    // Fetch image path before deleting so we can remove the file
    $row = $pdo->prepare('SELECT image_path FROM species_masterlist WHERE species_id = ?');
    $row->execute([$species_id]);
    $image_path = $row->fetchColumn();

    // Delete from DB
    $stmt = $pdo->prepare('DELETE FROM species_masterlist WHERE species_id = ?');
    $stmt->execute([$species_id]);

    if ($stmt->rowCount() === 0) {
        echo json_encode(['success' => false, 'error' => 'Species not found']);
        exit;
    }

    // Remove associated image file if it exists and is inside our assets folder
    if ($image_path) {
        $clean_path = strtok((string)$image_path, '?');
        $abs = realpath(__DIR__ . '/../' . $clean_path);
        $allowed_dir = realpath(__DIR__ . '/../assets/species_images');
        if ($abs && $allowed_dir && str_starts_with($abs, $allowed_dir)) {
            @unlink($abs);
        }
    }

    echo json_encode(['success' => true]);
} catch (PDOException $ex) {
    echo json_encode(['success' => false, 'error' => $ex->getMessage()]);
}
