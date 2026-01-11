<?php
require_once __DIR__ . '/../app/db.php';
header('Content-Type: application/json');

$u = trim($_GET['u'] ?? '');
if ($u === '') { echo json_encode([]); exit; }

$pdo = db();
// Find all group names where this username exists
$st = $pdo->prepare("
    SELECT g.name 
    FROM groups g 
    JOIN users u ON u.group_id = g.id 
    WHERE u.username = ? 
    ORDER BY g.name ASC
");
$st->execute([$u]);
$groups = $st->fetchAll(PDO::FETCH_COLUMN);

echo json_encode($groups);
