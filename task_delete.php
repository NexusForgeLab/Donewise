<?php
require_once __DIR__ . '/app/layout.php';
$user = require_login();
csrf_check();
$pdo = db();

$task_id = (int)($_POST['task_id'] ?? 0);

if ($task_id > 0) {
    // 1. Get task details (Date & Text)
    $st = $pdo->prepare("SELECT d.day_date, t.text FROM tasks t JOIN days d ON d.id=t.day_id WHERE t.id=? AND t.group_id=?");
    $st->execute([$task_id, $user['group_id']]);
    $row = $st->fetch();

    if ($row) {
        // NEW: Notification before delete
        send_group_notification(
            $pdo, 
            $user['group_id'], 
            $user['id'], 
            $user['display_name'], 
            'task_delete', 
            "deleted: " . $row['text'], 
            null // ID will be invalid soon, so null
        );

        // 2. Delete the task
        $st = $pdo->prepare("DELETE FROM tasks WHERE id=? AND group_id=?");
        $st->execute([$task_id, $user['group_id']]);
        
        header("Location: /day.php?date=" . $row['day_date']);
        exit;
    }
}

header("Location: /");