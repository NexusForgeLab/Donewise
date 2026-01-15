<?php
require_once __DIR__ . '/app/db.php';
$pdo = db();
echo "<h3>Updating Database (v8)...</h3>";

// 1. Add sort_order column to tags if missing
$cols = $pdo->query("PRAGMA table_info(tags)")->fetchAll(PDO::FETCH_COLUMN, 1);
if (!in_array('sort_order', $cols)) {
    $pdo->exec("ALTER TABLE tags ADD COLUMN sort_order INTEGER DEFAULT 0");
    echo "✅ Added 'sort_order' to tags table.<br>";
} else {
    echo "☑️ 'sort_order' already exists.<br>";
}

// 2. Ensure 'Urgent' is at the top (negative sort order)
// We use -100 to ensure it stays above defaults (0)
$st = $pdo->prepare("UPDATE tags SET sort_order = -100 WHERE LOWER(name) = 'urgent'");
$st->execute();
if ($st->rowCount() > 0) {
    echo "✅ 'Urgent' tag prioritized.<br>";
} else {
    echo "ℹ️ 'Urgent' tag not found (create it to see it on top).<br>";
}

echo "<br><b>Update Complete.</b> <a href='day.php'>Go to Day View</a>";
?>
