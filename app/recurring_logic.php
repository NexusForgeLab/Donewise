<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

function check_recurring_tasks(): void {
  $user = current_user();
  if (!$user) return;

  $pdo = db();
  $today = date('Y-m-d');

  // 1. Find tasks that are due today (or overdue)
  $st = $pdo->prepare("SELECT * FROM recurring_tasks WHERE group_id=? AND next_date <= ?");
  $st->execute([$user['group_id'], $today]);
  $tasks = $st->fetchAll();

  if (empty($tasks)) return;

  // 2. Process each task
  foreach ($tasks as $r) {
    // Ensure 'Today' exists in days table
    $pdo->prepare("INSERT OR IGNORE INTO days(group_id, day_date) VALUES(?,?)")->execute([$user['group_id'], $today]);
    $dayId = $pdo->query("SELECT id FROM days WHERE group_id={$user['group_id']} AND day_date='$today'")->fetchColumn();

    // Add to Task List (if not duplicate text on same day?)
    // We'll just add it. The user can see it's from recurring.
    $norm = normalize_text($r['text']);
    $pdo->prepare("INSERT INTO tasks(group_id, day_id, text, text_norm, created_by) VALUES(?,?,?,?,?)")
        ->execute([$user['group_id'], $dayId, $r['text'], $norm, $user['id']]);

    // 3. Calculate Next Date
    $next = $today; // Start calculation from today
    
    if ($r['frequency_type'] === 'days') {
        // E.g. Today + 3 days
        $days = (int)$r['interval_val'];
        $next = date('Y-m-d', strtotime($today . " + $days days"));
    } 
    elseif ($r['frequency_type'] === 'weekly') {
        // E.g. Next Monday
        $map = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
        $target = $map[$r['day_of_week']] ?? 'Monday';
        // 'next Monday' from today
        $next = date('Y-m-d', strtotime("next $target"));
    }

    // 4. Update the recurring rule
    $pdo->prepare("UPDATE recurring_tasks SET next_date=? WHERE id=?")->execute([$next, $r['id']]);
  }
}
