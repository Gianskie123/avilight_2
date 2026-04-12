<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fetch_bmb_announcements.php';

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$limit = max(1, min(20, (int) ($_GET['limit'] ?? 5)));
$items = fetch_bmb_announcements($limit);

echo json_encode(['success' => true, 'items' => $items]);
