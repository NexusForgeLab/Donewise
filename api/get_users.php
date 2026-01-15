<?php
require_once __DIR__ . '/../app/auth.php';
$user = require_login();
$pdo = db();

header('Content-Type: application/json');

// Updated query to include 'id'
$st = $pdo->prepare("SELECT id, username, display_name FROM users WHERE group_id=? ORDER BY display_name ASC");
$st->execute([$user['group_id']]);
$users = $st->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($users);
?>