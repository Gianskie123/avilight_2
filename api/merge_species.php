<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$source_id = (int)($_POST['source_id'] ?? 0);
$target_id = (int)($_POST['target_id'] ?? 0);

if ($source_id <= 0 || $target_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid species IDs']);
    exit;
}
if ($source_id === $target_id) {
    echo json_encode(['success' => false, 'error' => 'Source and target must be different species']);
    exit;
}

try {
    $pdo = get_mysql_db();
    $pdo->beginTransaction();

    // Verify both species exist
    $check = $pdo->prepare('SELECT COUNT(*) FROM species_masterlist WHERE species_id IN (?, ?)');
    $check->execute([$source_id, $target_id]);
    if ((int)$check->fetchColumn() < 2) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'One or both species not found']);
        exit;
    }

    // 1. Reassign all observations from source → target
    $upd = $pdo->prepare(
        'UPDATE raw_bird_observation SET species_id = ? WHERE species_id = ?'
    );
    $upd->execute([$target_id, $source_id]);
    $reassigned = $upd->rowCount();

    // 2. Fetch source image path before deleting
    $img_row = $pdo->prepare('SELECT image_path FROM species_masterlist WHERE species_id = ?');
    $img_row->execute([$source_id]);
    $image_path = $img_row->fetchColumn();

    // 3. Delete the source species
    $del = $pdo->prepare('DELETE FROM species_masterlist WHERE species_id = ?');
    $del->execute([$source_id]);

    $pdo->commit();

    // 4. Remove the source image file (outside transaction — non-critical)
    if ($image_path) {
        $abs         = realpath(__DIR__ . '/../' . $image_path);
        $allowed_dir = realpath(__DIR__ . '/../assets/species_images');
        if ($abs && $allowed_dir && str_starts_with($abs, $allowed_dir)) {
            @unlink($abs);
        }
    }

    echo json_encode([
        'success'     => true,
        'reassigned'  => $reassigned,
    ]);

} catch (PDOException $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'error' => $ex->getMessage()]);
}
