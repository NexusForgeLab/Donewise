<?php
require_once __DIR__ . '/app/layout.php';
require_once __DIR__ . '/app/tag_logic.php'; // Ensure this exists from previous step
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

        // 2. Update Text
        $norm = normalize_text($text);
        $st = $pdo->prepare("UPDATE tasks SET text=?, text_norm=? WHERE id=?");
        $st->execute([$text, $norm, $taskId]);

        // 3. Update Tags (Clear old -> Parse new)
        $pdo->prepare("DELETE FROM task_tags WHERE task_id=?")->execute([$taskId]);
        parse_and_save_tags($pdo, $user['group_id'], $taskId, $text);

        // 4. Move Date (If changed)
        // Find ID of target date
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

// Return to the previous page (the day view)
header('Location: ' . $_SERVER['HTTP_REFERER']);
exit;
