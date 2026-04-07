<?php
require __DIR__ . '/includes/db.php';
$pdo = get_mysql_db();
$year = 2024;
$month = 12;

$base = $pdo->prepare("SELECT COUNT(*) AS c FROM raw_bird_observation r JOIN observation_city_map m ON m.observation_id=r.observation_id WHERE r.year=:y AND r.month=:m AND r.species_id IS NOT NULL");
$base->execute([':y'=>$year, ':m'=>$month]);
$baseCount = (int)$base->fetch(PDO::FETCH_ASSOC)['c'];

$withEnv = $pdo->prepare("SELECT COUNT(*) AS c FROM raw_bird_observation r JOIN observation_city_map m ON m.observation_id=r.observation_id LEFT JOIN viirs v ON v.year=r.year AND v.month=r.month AND ABS(v.latitude-r.latitude)<0.000001 AND ABS(v.longitude-r.longitude)<0.000001 LEFT JOIN ndvi n ON n.year=r.year AND n.month=r.month AND ABS(n.latitude-r.latitude)<0.000001 AND ABS(n.longitude-r.longitude)<0.000001 LEFT JOIN land_temp lt ON lt.year=r.year AND lt.month=r.month AND ABS(lt.latitude-r.latitude)<0.000001 AND ABS(lt.longitude-r.longitude)<0.000001 LEFT JOIN precip p ON p.year=r.year AND p.month=r.month AND ABS(p.latitude-r.latitude)<0.000001 AND ABS(p.longitude-r.longitude)<0.000001 WHERE r.year=:y AND r.month=:m AND r.species_id IS NOT NULL AND (v.viirs_avg_rad IS NOT NULL OR n.ndvi IS NOT NULL OR lt.lst_day IS NOT NULL OR p.precip_mm IS NOT NULL)");
$withEnv->execute([':y'=>$year, ':m'=>$month]);
$withEnvCount = (int)$withEnv->fetch(PDO::FETCH_ASSOC)['c'];

$byJoin = $pdo->prepare("SELECT
SUM(CASE WHEN v.viirs_avg_rad IS NOT NULL THEN 1 ELSE 0 END) AS viirs_hits,
SUM(CASE WHEN n.ndvi IS NOT NULL THEN 1 ELSE 0 END) AS ndvi_hits,
SUM(CASE WHEN lt.lst_day IS NOT NULL THEN 1 ELSE 0 END) AS lst_hits,
SUM(CASE WHEN p.precip_mm IS NOT NULL THEN 1 ELSE 0 END) AS precip_hits
FROM raw_bird_observation r
JOIN observation_city_map m ON m.observation_id=r.observation_id
LEFT JOIN viirs v ON v.year=r.year AND v.month=r.month AND ABS(v.latitude-r.latitude)<0.000001 AND ABS(v.longitude-r.longitude)<0.000001
LEFT JOIN ndvi n ON n.year=r.year AND n.month=r.month AND ABS(n.latitude-r.latitude)<0.000001 AND ABS(n.longitude-r.longitude)<0.000001
LEFT JOIN land_temp lt ON lt.year=r.year AND lt.month=r.month AND ABS(lt.latitude-r.latitude)<0.000001 AND ABS(lt.longitude-r.longitude)<0.000001
LEFT JOIN precip p ON p.year=r.year AND p.month=r.month AND ABS(p.latitude-r.latitude)<0.000001 AND ABS(p.longitude-r.longitude)<0.000001
WHERE r.year=:y AND r.month=:m AND r.species_id IS NOT NULL");
$byJoin->execute([':y'=>$year, ':m'=>$month]);
$join = $byJoin->fetch(PDO::FETCH_ASSOC) ?: [];

echo json_encode([
  'year'=>$year,
  'month'=>$month,
  'base_observation_rows'=>$baseCount,
  'rows_with_any_env_match'=>$withEnvCount,
  'join_hits'=>$join,
], JSON_PRETTY_PRINT), PHP_EOL;
?>
