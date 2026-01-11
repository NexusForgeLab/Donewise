<?php
require_once __DIR__ . '/app/db.php';
$pdo = db();

echo "<h3>Updating Database...</h3>";

// 1. Add created_by to groups
try {
    $pdo->exec("ALTER TABLE groups ADD COLUMN created_by INTEGER DEFAULT 0");
    echo "✅ Added 'created_by' to groups.<br>";
    
    // Auto-fix: Set creator to the first user found in that group
    $groups = $pdo->query("SELECT id FROM groups")->fetchAll();
    foreach ($groups as $g) {
        $firstUser = $pdo->query("SELECT id FROM users WHERE group_id={$g['id']} ORDER BY id ASC LIMIT 1")->fetchColumn();
        if ($firstUser) {
            $pdo->exec("UPDATE groups SET created_by=$firstUser WHERE id={$g['id']}");
        }
    }
    echo "✅ assigned owners to existing groups.<br>";
} catch (Exception $e) { echo "ℹ️ 'created_by' already exists.<br>"; }

echo "<br><b>Done! Delete this file.</b>";
?>
