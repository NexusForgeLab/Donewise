<?php
require_once __DIR__ . '/app/db.php';
$pdo = db();
echo "<h3>Updating Database (v9)...</h3>";

// Add assigned_to column
$cols = $pdo->query("PRAGMA table_info(tasks)")->fetchAll(PDO::FETCH_COLUMN, 1);
if (!in_array('assigned_to', $cols)) {
    $pdo->exec("ALTER TABLE tasks ADD COLUMN assigned_to INTEGER DEFAULT NULL REFERENCES users(id) ON DELETE SET NULL");
    echo "✅ Added 'assigned_to' column.<br>";
} else {
    echo "☑️ 'assigned_to' already exists.<br>";
}

echo "<br><b>Update Complete.</b> <a href='day.php'>Go to Day View</a>";
?>
