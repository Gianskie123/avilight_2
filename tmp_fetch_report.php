<?php
// Temporary helper to call get_report_data.php with query parameters and print JSON
$params = $argv[1] ?? '';
parse_str($params, $_GET);
include __DIR__ . '/api/get_report_data.php';
