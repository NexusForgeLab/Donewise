<?php
require_once __DIR__ . '/app/layout.php';
require_once __DIR__ . '/app/tag_logic.php'; 
$user = require_login();
csrf_check();
$pdo = db();

$taskId = (int)($_POST['task_id'] ?? 0);
$text = trim($_POST['text'] ?? '');
$newDate = $_POST['new_date'] ?? '';

if ($taskId && $text && $newDate) {
    // 1. Verify ownership
    $st = $pdo->prepare("SELECT * FROM tasks WHERE id=? AND group_id=?");
    $st->execute([$taskId, $user['group_id']]);
    $task = $st->fetch();

    if ($task) {
        $pdo->beginTransaction();

        // 2. Process Mentions (Finds user IDs mentioned)
        // returns the first mentioned user ID to be the 'assignee'
        $assigneeId = process_mentions($pdo, $user['group_id'], $user['id'], $user['display_name'], $text, 'edit', $taskId);
        
        // If no one mentioned, keep existing assignee? Or clear?
        // Let's keep existing unless explicitly changed.
        // IF a user IS mentioned, we update assignment.
        $assignSQL = "";
        $params = [$text, $taskId];
        
        if ($assigneeId) {
            $assignSQL = ", assigned_to=?";
            $params = [$text, $assigneeId, $taskId];
        } else {
            // Keep old params
            $params = [$text, $taskId];
        }

        $norm = normalize_text($text);
        
        // 3. Update Text & Assignee
        // We inject the assignSQL dynamically
        if ($assigneeId) {
            $st = $pdo->prepare("UPDATE tasks SET text=?, text_norm=?, assigned_to=? WHERE id=?");
            $st->execute([$text, $norm, $assigneeId, $taskId]);
        } else {
            $st = $pdo->prepare("UPDATE tasks SET text=?, text_norm=? WHERE id=?");
            $st->execute([$text, $norm, $taskId]);
        }

        // 4. Update Tags
        $pdo->prepare("DELETE FROM task_tags WHERE task_id=?")->execute([$taskId]);
        parse_and_save_tags($pdo, $user['group_id'], $taskId, $text);

        // 5. Move Date (If changed)
        $st = $pdo->prepare("INSERT OR IGNORE INTO days(group_id, day_date) VALUES(?, ?)");
        $st->execute([$user['group_id'], $newDate]);
        
        $st = $pdo->prepare("SELECT id FROM days WHERE group_id=? AND day_date=?");
        $st->execute([$user['group_id'], $newDate]);
        $newDayId = (int)$st->fetchColumn();

        if ($newDayId && $newDayId !== (int)$task['day_id']) {
             $pdo->prepare("UPDATE tasks SET day_id=? WHERE id=?")->execute([$newDayId, $taskId]);
        }

        $pdo->commit();
    }
}

header('Location: ' . $_SERVER['HTTP_REFERER']);
exit;
?>