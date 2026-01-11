<?php
require_once __DIR__ . '/../app/auth.php';
header('Content-Type: application/json');
$user = current_user();
if(!$user){ echo json_encode(['unread'=>0]); exit; }
$pdo = db();
$st = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND read_at IS NULL");
$st->execute([$user['id']]);
echo json_encode(['unread'=>(int)$st->fetchColumn()]);
