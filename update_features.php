<?php
require_once __DIR__ . '/app/db.php';
$pdo = db();

echo "<h3>Applying Feature Updates...</h3>";

// 1. Create Subtasks Table
try {
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS subtasks (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        task_id INTEGER NOT NULL,
        text TEXT NOT NULL,
        is_done INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL DEFAULT (datetime('now')),
        FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
      )
    ");
    echo "✅ Created 'subtasks' table.<br>";
} catch (Exception $e) { echo "Error creating subtasks: " . $e->getMessage() . "<br>"; }

// 2. Add API Token to Users
try {
    // Check if column exists
    $cols = $pdo->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('api_token', $cols)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN api_token TEXT NULL");
        echo "✅ Added 'api_token' to users table.<br>";
    } else {
        echo "ℹ️ 'api_token' column already exists.<br>";
    }
} catch (Exception $e) { echo "Error updating users: " . $e->getMessage() . "<br>"; }

echo "<br><b>Update Complete.</b> <a href='/'>Go Home</a>";
?>
