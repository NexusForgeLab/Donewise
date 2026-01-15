<?php
require_once __DIR__ . '/app/db.php';
$pdo = db();

echo "<h3>Fixing Headless Groups...</h3>";

// 1. Find groups where the owner (created_by) does not exist in the users table
$sql = "
    SELECT g.id, g.name, g.created_by 
    FROM groups g 
    LEFT JOIN users u ON u.id = g.created_by 
    WHERE u.id IS NULL OR g.created_by = 0
";
$groups = $pdo->query($sql)->fetchAll();

if (empty($groups)) {
    echo "✅ No headless groups found.<br>";
}

foreach ($groups as $g) {
    echo "Found headless group: <b>" . htmlspecialchars($g['name']) . "</b> (ID: {$g['id']})... ";
    
    // Find a new owner (First user in the group)
    $st = $pdo->prepare("SELECT id FROM users WHERE group_id=? ORDER BY id ASC LIMIT 1");
    $st->execute([$g['id']]);
    $newOwner = $st->fetchColumn();
    
    if ($newOwner) {
        $pdo->prepare("UPDATE groups SET created_by=? WHERE id=?")->execute([$newOwner, $g['id']]);
        echo "Assigned new owner ID: $newOwner.<br>";
    } else {
        // No users left in group? Delete it.
        $pdo->prepare("DELETE FROM groups WHERE id=?")->execute([$g['id']]);
        echo "No members found. Group deleted.<br>";
    }
}

echo "<br><a href='/'>Go Home</a>";
?>
