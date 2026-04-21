
$_GET = [
    "selected_area" => "All Areas",
    "start_year" => 2014,
    "end_year" => 2025,
    "snapshot_year" => 2025,
    "snapshot_month" => 1,
    "scope" => "trend",
    "include_diagnostics" => "0"
];
function is_logged_in() { return true; }
require "api/get_report_data.php";

