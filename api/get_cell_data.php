<?php
// API endpoint to get cell data for map interactions
header('Content-Type: application/json');

// In production, this would query database
// For now, return sample data from JSON files

$cell_id = isset($_GET['cell_id']) ? $_GET['cell_id'] : null;

if (!$cell_id) {
    echo json_encode(['error' => 'Cell ID required']);
    exit;
}

// Load sample cells data and build an indexed lookup for O(1) access
$cells_data = json_decode(file_get_contents(__DIR__ . '/../data/sample_cells.json'), true);
$cells_index = array_column($cells_data, null, 'cell_id');

// Find the requested cell via indexed lookup instead of linear scan
$cell = isset($cells_index[$cell_id]) ? $cells_index[$cell_id] : null;

if ($cell) {
    echo json_encode([
        'success' => true,
        'data' => $cell
    ]);
} else {
    echo json_encode([
        'success' => false,
        'error' => 'Area not found',
        'message' => 'Try cell_2937 or cell_120.9000_14.3000'
    ]);
}
?>
