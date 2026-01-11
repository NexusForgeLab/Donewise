<?php
require_once __DIR__ . '/app/layout.php';
$user = require_login();
csrf_check();
$pdo = db();

$task_id = (int)($_POST['task_id'] ?? 0);
if ($task_id <= 0) { header('Location:/'); exit; }

$st = $pdo->prepare("SELECT * FROM tasks WHERE id=? AND group_id=?");
$st->execute([$task_id, $user['group_id']]);
$t = $st->fetch();
if(!$t){ header('Location:/'); exit; }

if ((int)$t['is_done'] === 1) {
  $pdo->prepare("UPDATE tasks SET is_done=0, done_at=NULL, done_by=NULL WHERE id=?")
      ->execute([$task_id]);

  // NEW: Use Helper
  send_group_notification(
      $pdo, 
      $user['group_id'], 
      $user['id'], 
      $user['display_name'], 
      'task_undo', 
      "undid completion: " . $t['text'], 
      $task_id
  );
}

$day = $pdo->query("SELECT d.day_date FROM tasks t JOIN days d ON d.id=t.day_id WHERE t.id=".(int)$task_id)->fetchColumn();
header('Location: /day.php?date=' . $day);