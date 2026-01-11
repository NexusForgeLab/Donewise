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

if ((int)$t['is_done'] === 0) {
  $pdo->prepare("UPDATE tasks SET is_done=1, done_at=datetime('now'), done_by=? WHERE id=?")
      ->execute([$user['id'], $task_id]);

  // NEW: Use Helper
  send_group_notification(
      $pdo, 
      $user['group_id'], 
      $user['id'], 
      $user['display_name'], 
      'task_done', 
      "completed: " . $t['text'], 
      $task_id
  );

  // Smart Recurring Logic (Keep existing)
  $st_rules = $pdo->prepare("SELECT * FROM recurring_tasks WHERE group_id=?");
  $st_rules->execute([$user['group_id']]);
  $rules = $st_rules->fetchAll();

  foreach ($rules as $r) {
    if (normalize_text($r['text']) === $t['text_norm']) {
      $next = date('Y-m-d'); 
      if ($r['frequency_type'] === 'days') {
        $days = (int)$r['interval_val'];
        $next = date('Y-m-d', strtotime("+ $days days"));
      } 
      elseif ($r['frequency_type'] === 'weekly') {
        $map = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
        $dow = $map[$r['day_of_week']] ?? 'Monday';
        $next = date('Y-m-d', strtotime("next $dow"));
      }
      $pdo->prepare("UPDATE recurring_tasks SET next_date=? WHERE id=?")
          ->execute([$next, $r['id']]);
    }
  }
}

$day = $pdo->query("SELECT d.day_date FROM tasks t JOIN days d ON d.id=t.day_id WHERE t.id=".(int)$task_id)->fetchColumn();
header('Location: /day.php?date=' . $day);