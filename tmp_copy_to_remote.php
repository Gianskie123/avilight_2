<?php
// Copy tables from local `avilight` to remote `defaultdb` (credentials in .env)
function load_env($path) {
    $out = [];
    if (!is_readable($path)) return $out;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
        if (strpos(trim($l),'#')===0) continue;
        if (!preg_match('/^\s*([^=]+)=(.*)$/', $l, $m)) continue;
        $k = trim($m[1]); $v = trim($m[2]); $v = trim($v, "\"'"); $out[$k]=$v;
    }
    return $out;
}
$env = load_env(__DIR__ . '/.env');
$rhost = $env['DB_HOST'] ?? '127.0.0.1';
$rport = $env['DB_PORT'] ?? '3306';
$rdb   = $env['DB_NAME'] ?? 'defaultdb';
$ruser = $env['DB_USER'] ?? 'root';
$rpass = $env['DB_PASS'] ?? '';

$localDsn = 'mysql:host=127.0.0.1;port=3306;dbname=avilight;charset=utf8mb4';
$remoteDsn = "mysql:host={$rhost};port={$rport};dbname={$rdb};charset=utf8mb4";

try {
    $local = new PDO($localDsn, 'root', '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $remote = new PDO($remoteDsn, $ruser, $rpass, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    // Increase remote session lock wait timeout to reduce risk of transient lock failures
    try {
        $remote->exec("SET SESSION innodb_lock_wait_timeout = 300");
    } catch (Throwable $e) {
        // Non-fatal if the remote server doesn't permit session variable changes
    }
} catch (Throwable $e) {
    echo "Connection error: " . $e->getMessage() . "\n";
    exit(1);
}

function copy_table($local, $remote, $selectSql, $insertSql, $paramsMap=null, $batch=500) {
    $stmt = $local->query($selectSql);
    $rows = $stmt->fetchAll();
    $total = count($rows);
    echo "Found $total rows to copy.\n";
    $ins = $remote->prepare($insertSql);
    $i = 0;
    $batchRows = [];
    foreach ($rows as $r) {
        $bind = [];
        if ($paramsMap === null) $bind = $r; else {
            foreach ($paramsMap as $k=>$src) $bind[$k] = $r[$src] ?? null;
        }
        $batchRows[] = $bind;
        if (count($batchRows) >= $batch) {
            try {
                $remote->beginTransaction();
                foreach ($batchRows as $br) {
                    $ins->execute($br);
                    $i++;
                }
                $remote->commit();
            } catch (Throwable $e) {
                if ($remote->inTransaction()) $remote->rollBack();
                throw $e;
            }
            echo "  copied $i / $total\n";
            $batchRows = [];
        }
    }
    // final partial batch
    if (count($batchRows) > 0) {
        try {
            $remote->beginTransaction();
            foreach ($batchRows as $br) {
                $ins->execute($br);
                $i++;
            }
            $remote->commit();
        } catch (Throwable $e) {
            if ($remote->inTransaction()) $remote->rollBack();
            throw $e;
        }
    }
    echo "Copied $i rows.\n";
}

// 1) ecological_yearly_summary
echo "Copying ecological_yearly_summary...\n";
$select = 'SELECT area, year, bird_richness, viirs_avg, ndvi_avg, lst_avg, precipitation_total, updated_at FROM ecological_yearly_summary';
$insert = 'REPLACE INTO ecological_yearly_summary (area, year, bird_richness, viirs_avg, ndvi_avg, lst_avg, precipitation_total, updated_at) VALUES (:area, :year, :bird_richness, :viirs_avg, :ndvi_avg, :lst_avg, :precipitation_total, :updated_at)';
copy_table($local, $remote, $select, $insert);

// 2) metro_yearly_richness
echo "Copying metro_yearly_richness...\n";
$select = 'SELECT year, bird_richness, viirs_avg, ndvi_avg, lst_avg, precipitation_total, updated_at FROM metro_yearly_richness';
$insert = 'REPLACE INTO metro_yearly_richness (year, bird_richness, viirs_avg, ndvi_avg, lst_avg, precipitation_total, updated_at) VALUES (:year, :bird_richness, :viirs_avg, :ndvi_avg, :lst_avg, :precipitation_total, :updated_at)';
copy_table($local, $remote, $select, $insert);

// 3) ecological_monthly_summary
echo "Copying ecological_monthly_summary...\n";
$select = 'SELECT area, year, month, ndvi_avg, viirs_avg, lst_avg, lst_day_avg, lst_night_avg, precipitation_total, cell_count, refreshed_at FROM ecological_monthly_summary';
$insert = 'REPLACE INTO ecological_monthly_summary (area, year, month, ndvi_avg, viirs_avg, lst_avg, lst_day_avg, lst_night_avg, precipitation_total, cell_count, refreshed_at) VALUES (:area, :year, :month, :ndvi_avg, :viirs_avg, :lst_avg, :lst_day_avg, :lst_night_avg, :precipitation_total, :cell_count, :refreshed_at)';
copy_table($local, $remote, $select, $insert, null, 1000);

// Report remote summary
echo "\nRemote summary after copy:\n";
$info = $remote->query('SELECT COUNT(*) AS cnt FROM ecological_yearly_summary')->fetch();
echo "ecological_yearly_summary rows=" . $info['cnt'] . "\n";
$info = $remote->query('SELECT MAX(updated_at) AS last_update FROM ecological_yearly_summary')->fetch();
echo "last_update=" . ($info['last_update'] ?? '(none)') . "\n";
$rows = $remote->query('SELECT year, ROUND(AVG(precipitation_total),2) AS avg_precip FROM ecological_yearly_summary GROUP BY year ORDER BY year')->fetchAll();
foreach ($rows as $r) echo "{$r['year']} {$r['avg_precip']}\n";

echo "\nDone.\n";
