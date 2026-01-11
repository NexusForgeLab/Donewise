<?php
require_once __DIR__ . '/app/db.php';
$pdo = db();

echo "<h3>Updating Database (v5 - Tags)...</h3>";

try {
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS tags (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        group_id INTEGER NOT NULL,
        name TEXT NOT NULL,
        color TEXT NOT NULL,
        UNIQUE(group_id, name),
        FOREIGN KEY (group_id) REFERENCES groups(id) ON DELETE CASCADE
      )
    ");
    echo "✅ Created 'tags' table.<br>";

    $pdo->exec("
      CREATE TABLE IF NOT EXISTS task_tags (
        task_id INTEGER NOT NULL,
        tag_id INTEGER NOT NULL,
        PRIMARY KEY(task_id, tag_id),
        FOREIGN KEY(task_id) REFERENCES tasks(id) ON DELETE CASCADE,
        FOREIGN KEY(tag_id) REFERENCES tags(id) ON DELETE CASCADE
      )
    ");
    echo "✅ Created 'task_tags' table.<br>";

} catch (Exception $e) { echo "Error: " . $e->getMessage(); }

echo "<br><b>Done! Delete this file.</b>";
?>
