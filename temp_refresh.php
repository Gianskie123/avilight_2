<?php session_start(); $_SESSION["admin_auth"] = true; $_POST["force"] = 1; require_once __DIR__ . "/api/refresh_report_cache.php";
