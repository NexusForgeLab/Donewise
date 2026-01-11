<?php
require_once __DIR__ . '/../app/auth.php';
$user = require_login();
$pdo = db();

$data = json_decode(file_get_contents('php://input'), true);
if (!isset($data['ids']) || !is_array($data['ids'])) {
    http_response_code(400); exit;
}

$pdo->beginTransaction();
$st = $pdo->prepare("UPDATE tasks SET sort_order=? WHERE id=? AND group_id=?");
foreach ($data['ids'] as $index => $id) {
    $st->execute([$index, (int)$id, $user['group_id']]);
}
$pdo->commit();

// NEW: Notification for Reorder
send_group_notification(
    $pdo, 
    $user['group_id'], 
    $user['id'], 
    $user['display_name'], 
    'reorder', 
    "modified the list order."
);

echo json_encode(['status'=>'ok']);