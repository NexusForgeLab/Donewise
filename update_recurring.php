<?php
require_once __DIR__ . '/app/db.php';
$pdo = db();

$sql = "
CREATE TABLE IF NOT EXISTS recurring_tasks (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  group_id INTEGER NOT NULL,
  text TEXT NOT NULL,
  frequency_type TEXT NOT NULL, -- 'days' or 'weekly'
  interval_val INTEGER NULL,    -- e.g. 3 (for every 3 days)
  day_of_week INTEGER NULL,     -- 0=Sun, 1=Mon... 6=Sat
  next_date TEXT NOT NULL,      -- YYYY-MM-DD
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  FOREIGN KEY (group_id) REFERENCES groups(id) ON DELETE CASCADE
);
";

try {
    $pdo->exec($sql);
    echo "✅ Database updated: 'recurring_tasks' table created.";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
