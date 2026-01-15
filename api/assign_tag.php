<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/tag_logic.php'; 
$user = require_login();
$pdo = db();

$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['task_id']) || !isset($data['tag_id'])) {
    http_response_code(400); exit;
}

$taskId = (int)$data['task_id'];
$targetTagId = $data['tag_id']; // Can be 'untagged' or an integer ID

// 1. Fetch Task
$st = $pdo->prepare("SELECT id, text, group_id FROM tasks WHERE id=? AND group_id=?");
$st->execute([$taskId, $user['group_id']]);
$task = $st->fetch();
if (!$task) { http_response_code(403); exit; }

// 2. Determine Target Tag Name
$targetTagName = null;
if ($targetTagId !== 'untagged') {
    $st = $pdo->prepare("SELECT name FROM tags WHERE id=? AND group_id=?");
    $st->execute([$targetTagId, $user['group_id']]);
    $targetTagName = $st->fetchColumn();
    if (!$targetTagName) { http_response_code(400); exit; } 
}

// 3. Parse Existing Tags & Clean Text
$text = $task['text'];
preg_match_all('/#(\w+)/u', $text, $matches);
$existingTags = $matches[1] ?? [];
$cleanText = trim(preg_replace('/#(\w+)/u', '', $text)); // Text without tags

// 4. Build New Tag List (Target First)
$newTagsList = [];

// A. Add Target Tag First (if not untagged)
if ($targetTagName) {
    $newTagsList[] = $targetTagName;
}

// B. Add remaining existing tags (preserve order, avoid duplicates)
foreach ($existingTags as $t) {
    if ($targetTagName && strcasecmp($t, $targetTagName) === 0) continue;
    $newTagsList[] = $t;
}

// 5. Construct New Text string
// Format: "Buy Milk #Section #OldTag1 #OldTag2"
$tagString = '';
foreach ($newTagsList as $t) {
    $tagString .= ' #' . $t;
}
$newText = $cleanText . $tagString;

// 6. Update Database (Text & Relations)
$pdo->beginTransaction();

// Update Text
$pdo->prepare("UPDATE tasks SET text=?, text_norm=? WHERE id=?")
    ->execute([$newText, normalize_text($newText), $taskId]);

// Sync Task Tags (Delete old links, re-insert new ones)
$pdo->prepare("DELETE FROM task_tags WHERE task_id=?")->execute([$taskId]);

if (!empty($newTagsList)) {
    // We need to re-link tags. We use helper logic or manual insert.
    // Manual insert ensures we handle the IDs correctly.
    $stmtCheck = $pdo->prepare("SELECT id FROM tags WHERE group_id=? AND name=?");
    $stmtIns   = $pdo->prepare("INSERT INTO tags (group_id, name, color) VALUES (?, ?, ?)");
    $stmtLink  = $pdo->prepare("INSERT INTO task_tags (task_id, tag_id) VALUES (?, ?)");

    foreach ($newTagsList as $tagName) {
        $tagName = mb_strtolower($tagName);
        $stmtCheck->execute([$user['group_id'], $tagName]);
        $tid = $stmtCheck->fetchColumn();
        
        // Auto-create tag if missing (edge case)
        if (!$tid) {
            $color = function_exists('get_tag_color') ? get_tag_color(crc32($tagName)) : '#777';
            $stmtIns->execute([$user['group_id'], $tagName, $color]);
            $tid = $pdo->lastInsertId();
        }
        $stmtLink->execute([$taskId, $tid]);
    }
}

$pdo->commit();
echo json_encode(['status'=>'ok']);
?>