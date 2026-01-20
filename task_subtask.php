<?php
require_once __DIR__ . '/app/config.php';
require_once __DIR__ . '/app/auth.php';

$user = require_login();
$pdo = db();

$action = $_POST['action'] ?? '';
$taskId = (int)($_POST['task_id'] ?? 0);

// Verify Task Ownership
$t = $pdo->prepare("SELECT id FROM tasks WHERE id=? AND group_id=?");
$t->execute([$taskId, $user['group_id']]);
if (!$t->fetch()) exit;

if ($action === 'add') {
    $text = trim($_POST['text'] ?? '');
    if ($text) {
        $stmt = $pdo->prepare("INSERT INTO subtasks(task_id, text) VALUES(?,?)");
        $stmt->execute([$taskId, $text]);
    }
} elseif ($action === 'toggle') {
    $subId = (int)$_POST['sub_id'];
    $val = (int)$_POST['is_done'];
    $pdo->prepare("UPDATE subtasks SET is_done=? WHERE id=? AND task_id=?")->execute([$val, $subId, $taskId]);
}

// Return updated list HTML (Simplifies frontend)
$subs = $pdo->prepare("SELECT * FROM subtasks WHERE task_id=?");
$subs->execute([$taskId]);
foreach ($subs->fetchAll() as $s) {
    $checked = $s['is_done'] ? 'checked' : '';
    $style = $s['is_done'] ? 'text-decoration:line-through;color:#888' : '';
    echo "<div style='margin-bottom:8px; display:flex; align-items:center;'>
            <input type='checkbox' $checked onchange='toggleSub({$s['id']}, this.checked)' style='width:auto; margin-right:10px;'>
            <span style='$style'>" . htmlspecialchars($s['text']) . "</span>
          </div>";
}
?>
