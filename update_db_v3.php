<?php
require_once __DIR__ . '/app/db.php';
$pdo = db();

echo "<h3>Updating Database...</h3>";

try {
    // Add 'type' column for icons (add, done, comment, etc.)
    $pdo->exec("ALTER TABLE notifications ADD COLUMN type TEXT DEFAULT 'general'");
    echo "✅ Added 'type' column.<br>";
} catch (Exception $e) { echo "ℹ️ 'type' column already exists.<br>"; }

try {
    // Add 'group_id' column to filter/show group context
    $pdo->exec("ALTER TABLE notifications ADD COLUMN group_id INTEGER DEFAULT 0");
    echo "✅ Added 'group_id' column.<br>";
} catch (Exception $e) { echo "ℹ️ 'group_id' column already exists.<br>"; }

try {
    // Add 'task_id' column to link to the item
    $pdo->exec("ALTER TABLE notifications ADD COLUMN task_id INTEGER DEFAULT 0");
    echo "✅ Added 'task_id' column.<br>";
} catch (Exception $e) { echo "ℹ️ 'task_id' column already exists.<br>"; }

echo "<br><b>Done! Delete this file now.</b>";
?>
