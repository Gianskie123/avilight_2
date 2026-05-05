<?php
$require = require_once __DIR__ . '/includes/db.php';
$pdo = get_mysql_db();
$area = $argv[1] ?? 'Quezon City';
$stmt = $pdo->prepare('SELECT r.id, r.site_name, r.latitude, r.longitude, m.area FROM raw_bird_observation r JOIN observation_city_map m ON m.rbo_id = r.id WHERE m.area = :area LIMIT 200');
$stmt->execute([':area' => $area]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    echo implode('\t', [($r['id'] ?? ''), ($r['site_name'] ?? ''), ($r['latitude'] ?? ''), ($r['longitude'] ?? ''), ($r['area'] ?? '')]) . "\n";
}
echo "-- total: " . count($rows) . "\n";
