<?php
require_once __DIR__ . '/../app/auth.php';
$user = require_login();
$pdo = db();
header('Content-Type: application/json');

$q = trim($_GET['q'] ?? '');
if ($q === '') { echo json_encode(['items'=>[]]); exit; }

$items = [];

// 1. TAG SEARCH (if starts with #)
if (str_starts_with($q, '#')) {
    $term = substr($q, 1); // remove '#'
    $st = $pdo->prepare("
        SELECT name FROM tags 
        WHERE group_id=? AND name LIKE ?
        ORDER BY name ASC 
        LIMIT 10
    ");
    $st->execute([$user['group_id'], $term . '%']);
    $rows = $st->fetchAll();
    
    // Format as hashtags
    $items = array_map(fn($r) => '#' . $r['name'], $rows);

} else {
// 2. ITEM HISTORY SEARCH (Standard)
    $qnorm = normalize_text($q);
    if ($qnorm !== '') {
        $st = $pdo->prepare("
          SELECT text
          FROM item_history
          WHERE group_id=? AND (text_norm LIKE ? OR text_norm LIKE ?)
          ORDER BY (text_norm LIKE ?) DESC, use_count DESC, last_used_at DESC
          LIMIT 10
        ");
        $starts = $qnorm . '%';
        $contains = '%' . $qnorm . '%';
        $st->execute([$user['group_id'], $starts, $contains, $starts]);
        $items = array_map(fn($r)=>$r['text'], $st->fetchAll());
    }
}

echo json_encode(['items'=>$items]);
?>