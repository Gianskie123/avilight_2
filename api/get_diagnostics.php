<?php
/**
 * api/get_diagnostics.php
 *
 * Provides diagnostic checks for database schema and configuration.
 * Used by dashboard and analytics tabs to troubleshoot issues.
 *
 * Query params:
 *   check - 'sqlmode' | 'tables'
 */
header('Content-Type: text/plain; charset=UTF-8');

require_once __DIR__ . '/../includes/db.php';

$check = isset($_GET['check']) ? (string)$_GET['check'] : '';

try {
    $pdo = get_mysql_db();

    if ($check === 'sqlmode') {
        // Check MySQL SQL_MODE
        $mode = $pdo->query('SELECT @@sql_mode AS mode')->fetchColumn();
        $isAnsiQuotes = stripos((string)$mode, 'ANSI_QUOTES') !== false;
        
        echo "SQL_MODE: " . ($mode ? (string)$mode : 'NOT SET') . "\n";
        echo "ANSI_QUOTES enabled: " . ($isAnsiQuotes ? 'YES (may cause issues)' : 'NO (OK)') . "\n";
        
        if ($isAnsiQuotes) {
            echo "\nWARNING: ANSI_QUOTES mode is ON.\n";
            echo "This treats double-quoted strings as identifiers, not literals.\n";
            echo "Ensure all SQL uses single quotes for string literals.\n";
        }
        exit;
    }

    if ($check === 'tables') {
        // Check critical tables
        $requiredTables = [
            'aggregated_bird_observation',
            'raw_bird_observation',
            'ecological_yearly_summary',
            'final_master_grid',
            'viirs',
            'ndvi',
            'land_temp',
            'precip',
            'species_masterlist',
        ];

        $existingTables = $pdo->query(
            'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'
        )->fetchAll(PDO::FETCH_COLUMN);

        $existingSet = array_flip($existingTables);

        echo "Critical Table Status:\n";
        echo "=======================\n";

        $missing = [];
        foreach ($requiredTables as $table) {
            $exists = isset($existingSet[$table]) ? 'EXISTS' : 'MISSING';
            echo sprintf("%-35s %s\n", $table, $exists);
            if ($exists === 'MISSING') {
                $missing[] = $table;
            }
        }

        if (!empty($missing)) {
            echo "\nMISSING TABLES:\n";
            foreach ($missing as $t) {
                echo "  - $t\n";
            }
            echo "\nThese tables are required for the dashboard and analytics to function.\n";
            echo "Run data initialization/ETL scripts to populate them.\n";
        } else {
            echo "\n✓ All critical tables exist.\n";
        }
        exit;
    }

    echo "No check specified. Use ?check=sqlmode or ?check=tables\n";

} catch (Throwable $e) {
    http_response_code(500);
    echo "ERROR: " . $e->getMessage() . "\n";
}
?>
