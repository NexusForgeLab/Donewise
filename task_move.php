<?php
require_once __DIR__ . '/app/layout.php';
$user = require_login();
csrf_check();
$pdo = db();

$task_id = (int)($_POST['task_id'] ?? 0);
$new_date = $_POST['new_date'] ?? '';

if ($task_id > 0 && preg_match('/^\d{4}-\d{2}-\d{2}$/', $new_date)) {
    
    // 1. Verify task ownership
    $st = $pdo->prepare("SELECT id FROM tasks WHERE id=? AND group_id=?");
    $st->execute([$task_id, $user['group_id']]);
    if ($st->fetch()) {
        
        // 2. Find/Create target day
        $st = $pdo->prepare("INSERT OR IGNORE INTO days(group_id, day_date) VALUES(?, ?)");
        $st->execute([$user['group_id'], $new_date]);

        $st = $pdo->prepare("SELECT id FROM days WHERE group_id=? AND day_date=?");
        $st->execute([$user['group_id'], $new_date]);
        $newDayId = (int)$st->fetchColumn();

        // 3. Move Task
        $st = $pdo->prepare("UPDATE tasks SET day_id=? WHERE id=?");
        $st->execute([$newDayId, $task_id]);
    }
}

// Redirect back to where we came from (or the new date?)
// Usually staying on current page is less confusing
header("Location: " . $_SERVER['HTTP_REFERER']);
exit;
