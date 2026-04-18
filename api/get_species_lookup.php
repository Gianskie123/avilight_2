<?php
/**
 * api/get_species_lookup.php
 *
 * Returns the full species masterlist from the MySQL database as a JSON
 * lookup map keyed by (lower-cased) species name.
 *
 * Response shape:
 *   {
 *     "success": true,
 *     "lookup": {
 *       "barn swallow": { "tolerance": "Tolerant", "migration": "Migratory" },
 *       ...
 *     }
 *   }
 */
header('Content-Type: application/json');
header('Cache-Control: public, max-age=3600');

require_once __DIR__ . '/../includes/db.php';

try {
    $pdo  = get_mysql_db();
    $rows = $pdo->query(
        'SELECT species_name, light_tolerance, migratory_status FROM species_masterlist ORDER BY species_name'
    )->fetchAll(PDO::FETCH_ASSOC);

    $lookup = [];
    foreach ($rows as $row) {
        $key = strtolower(trim($row['species_name']));
        $tol = $row['light_tolerance'] ?? '';
        $mig = $row['migratory_status'] ?? '';
        // Normalise to Title Case for display
        $lookup[$key] = [
            'tolerance' => $tol ? ucfirst(strtolower($tol)) : null,
            'migration' => $mig ? ucfirst(strtolower($mig)) : null,
        ];
    }

    echo json_encode(['success' => true, 'lookup' => $lookup]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage(), 'lookup' => []]);
}
