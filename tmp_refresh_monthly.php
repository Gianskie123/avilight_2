<?php
require_once __DIR__ . '/includes/db.php';
$pdo = get_mysql_db();
$years = range(2014, 2025);
$cnt = refresh_ecological_monthly_summary($pdo, $years);
echo "Refreshed ecological_monthly_summary rows: $cnt\n";
