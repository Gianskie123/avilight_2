<?php
/**
 * load_species.php
 *
 * Returns an array of species records read from the SQLite database
 * (populated from data/species_masterlist.csv on first run).
 *
 * Each record:
 *   [
 *     'id'              => int,
 *     'common_name'     => string,
 *     'light_tolerance' => 'Sensitive' | 'Moderate' | 'Tolerant',
 *     'migration_status'=> 'Resident' | 'Migratory' | 'Both',
 *     'image'           => string,   // derived placeholder path
 *   ]
 */

function load_species_from_csv($path = null): array {
    // Use the database; $path kept for backward-compat signature but ignored
    require_once __DIR__ . '/db.php';
    $pdo  = get_db();
    $rows = $pdo->query('SELECT * FROM species ORDER BY common_name')->fetchAll(PDO::FETCH_ASSOC);
    return array_map(function (array $r): array {
        return [
            'id'              => (int) $r['id'],
            'common_name'     => $r['common_name'],
            'light_tolerance' => $r['light_tolerance'],
            'migration_status'=> $r['migration_status'],
            // scientific_name and conservation_status not in the real dataset
            'scientific_name'     => '',
            'conservation_status' => '',
            'image'               => 'assets/images/species/placeholder.jpg',
        ];
    }, $rows);
}
