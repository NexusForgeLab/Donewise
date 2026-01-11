<?php
require_once __DIR__ . '/app/db.php';
$pdo = db();
echo "Adding performance index...<br>";
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_notif_stream ON notifications(user_id, id);");
echo "Done.";
?>
