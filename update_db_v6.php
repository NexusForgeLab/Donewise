<?php
require_once __DIR__ . '/app/db.php';
$pdo = db();

echo "<h3>Updating Database (v6 - Attachments)...</h3>";

try {
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS attachments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        group_id INTEGER NOT NULL,
        task_id INTEGER NOT NULL,
        user_id INTEGER NOT NULL,
        filename TEXT NOT NULL,
        filepath TEXT NOT NULL,
        file_type TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT (datetime('now')),
        FOREIGN KEY (group_id) REFERENCES groups(id) ON DELETE CASCADE,
        FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
      )
    ");
    echo "✅ Created 'attachments' table.<br>";
} catch (Exception $e) { echo "Error: " . $e->getMessage(); }

echo "<br><b>Done! Delete this file.</b>";
?>
