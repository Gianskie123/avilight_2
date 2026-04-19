<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

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

    // Fetch name and image path before deleting
    $row = $pdo->prepare('SELECT species_name, image_path FROM species_masterlist WHERE species_id = ?');
    $row->execute([$species_id]);
    $species = $row->fetch(PDO::FETCH_ASSOC);

    // Delete from DB
    $stmt = $pdo->prepare('DELETE FROM species_masterlist WHERE species_id = ?');
    $stmt->execute([$species_id]);

    if ($stmt->rowCount() === 0) {
        echo json_encode(['success' => false, 'error' => 'Species not found']);
        exit;
    }

    // Remove associated image file if it exists and is inside our assets folder
    if (!empty($species['image_path'])) {
        $abs = realpath(__DIR__ . '/../' . $species['image_path']);
        $allowed_dir = realpath(__DIR__ . '/../assets/species_images');
        if ($abs && $allowed_dir && str_starts_with($abs, $allowed_dir)) {
            @unlink($abs);
        }
    }

    // Log the deletion to access_log
    try {
        _ensure_access_log_table($pdo);
        $species_label = $species['species_name'] ?? "ID {$species_id}";
        $pdo->prepare(
            'INSERT INTO access_log (user_id, email, action, ip_address) VALUES (:uid, :email, :act, :ip)'
        )->execute([
            ':uid'   => $_SESSION['user_id']    ?? null,
            ':email' => $_SESSION['user_email'] ?? '',
            ':act'   => "Deleted species: {$species_label} (species_id={$species_id})",
            ':ip'    => $_SERVER['REMOTE_ADDR'] ?? '',
        ]);
    } catch (Throwable $_) { /* non-fatal — deletion already succeeded */ }

    echo json_encode(['success' => true]);
} catch (PDOException $ex) {
    echo json_encode(['success' => false, 'error' => $ex->getMessage()]);
}
