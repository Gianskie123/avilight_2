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

// Load sample cells data
$cells_data = json_decode(file_get_contents(__DIR__ . '/../data/sample_cells.json'), true);

// Find the requested cell
$cell = null;
foreach ($cells_data as $c) {
    if ($c['cell_id'] === $cell_id) {
        $cell = $c;
        break;
    }
}

if ($cell) {
    echo json_encode([
        'success' => true,
        'data' => $cell
    ]);
} else {
    echo json_encode([
        'success' => false,
        'error' => 'Cell not found',
        'message' => 'Try cell_2937 or cell_120.9000_14.3000'
    ]);
}
?>
