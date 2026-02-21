<?php
// API endpoint to run scenario simulations
header('Content-Type: application/json');

// Get POST parameters
$input = json_decode(file_get_contents('php://input'), true);

$light_reduction = isset($input['light_reduction']) ? floatval($input['light_reduction']) : 0;
$ndvi_increase = isset($input['ndvi_increase']) ? floatval($input['ndvi_increase']) : 0;
$temp_change = isset($input['temp_change']) ? floatval($input['temp_change']) : 0;

// In production, this would:
// 1. Load the ML model
// 2. Adjust feature values based on scenario parameters
// 3. Generate new predictions
// 4. Return difference map

// For now, simulate calculation
$light_impact = $light_reduction * 0.3;
$ndvi_impact = $ndvi_increase * 0.5;
$temp_impact = $temp_change * -2;
$total_impact = $light_impact + $ndvi_impact + $temp_impact;

$species_gain = round($total_impact * 0.3);
$sensitive_gain = round($species_gain * 1.5);
$confidence = max(55, min(95, 95 - (abs($light_reduction/50) + abs($ndvi_increase/20) + abs($temp_change/2)) * 15));

// Sample affected areas
$kba_data = json_decode(file_get_contents(__DIR__ . '/../data/sample_kba.json'), true);
$affected_areas = [];

foreach ($kba_data as $area) {
    $predicted = round($area['species_count'] * (1 + $total_impact/100));
    $affected_areas[] = [
        'name' => $area['name'],
        'current' => $area['species_count'],
        'predicted' => $predicted,
        'change' => $predicted - $area['species_count']
    ];
}

echo json_encode([
    'success' => true,
    'parameters' => [
        'light_reduction' => $light_reduction,
        'ndvi_increase' => $ndvi_increase,
        'temp_change' => $temp_change
    ],
    'results' => [
        'total_impact' => $total_impact,
        'species_gain' => $species_gain,
        'sensitive_gain' => $sensitive_gain,
        'confidence' => $confidence
    ],
    'affected_areas' => $affected_areas,
    'message' => 'Scenario simulation complete. In production, this would use the actual ML model.'
]);
?>
