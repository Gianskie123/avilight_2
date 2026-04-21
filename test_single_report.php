<?php
// Test a single report data request to diagnose the warming failures

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION["user_email"] = "cli-cache-warmer.local";

// Set parameters for a simple trend request
$_GET = [
    'scope' => 'trend',
    'selected_area' => 'All Areas',
    'start_year' => 2012,
    'end_year' => 2012,
    'snapshot_year' => 2025,
    'snapshot_month' => 12,
    'include_diagnostics' => '0',
];

// Include and execute get_report_data.php
include __DIR__ . '/api/get_report_data.php';
