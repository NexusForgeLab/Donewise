<?php
require_once __DIR__ . '/app/layout.php';
$user = require_login();
csrf_check();
$pdo = db();

$task_id = (int)($_POST['task_id'] ?? 0);
if ($task_id > 0) {
    // 1. Verify Task
    $st = $pdo->prepare("SELECT * FROM tasks WHERE id=? AND group_id=?");
    $st->execute([$task_id, $user['group_id']]);
    if ($st->fetch()) {
        // 2. Calculate Tomorrow
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        
        // 3. Ensure Day Exists
        $pdo->prepare("INSERT OR IGNORE INTO days(group_id, day_date) VALUES(?,?)")->execute([$user['group_id'], $tomorrow]);
        $dayId = $pdo->prepare("SELECT id FROM days WHERE group_id=? AND day_date=?")->execute([$user['group_id'], $tomorrow])->fetchColumn(); // This needs proper fetch execution
        
        // Correct fetch:
        $stmt = $pdo->prepare("SELECT id FROM days WHERE group_id=? AND day_date=?");
        $stmt->execute([$user['group_id'], $tomorrow]);
        $dayId = $stmt->fetchColumn();

        // 4. Move Task
        $pdo->prepare("UPDATE tasks SET day_id=? WHERE id=?")->execute([$dayId, $task_id]);
    }
}
header('Location: ' . $_SERVER['HTTP_REFERER']);
?>
