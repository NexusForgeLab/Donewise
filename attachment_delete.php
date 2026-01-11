<?php
// Use config/auth instead of layout.php to prevent "Headers already sent" errors
require_once __DIR__ . '/app/config.php';
require_once __DIR__ . '/app/auth.php';

$user = require_login();
csrf_check();
$pdo = db();

$att_id = (int)($_POST['attachment_id'] ?? 0);

if ($att_id > 0) {
    // 1. Check permissions (Group access)
    // Ensure the attachment belongs to the user's current group
    $st = $pdo->prepare("SELECT * FROM attachments WHERE id = ? AND group_id = ? LIMIT 1");
    $st->execute([$att_id, $user['group_id']]);
    $file = $st->fetch();

    if ($file) {
        // 2. Delete physical file
        // Construct path relative to this script
        $fullPath = __DIR__ . '/' . $file['filepath'];
        
        // Safety check: Ensure file exists and is a file (not a directory)
        if (file_exists($fullPath) && is_file($fullPath)) {
            unlink($fullPath);
        }

        // 3. Delete DB record
        $pdo->prepare("DELETE FROM attachments WHERE id = ?")->execute([$att_id]);
        
        // 4. Redirect
        header("Location: /task_details.php?id=" . $file['task_id']);
        exit;
    }
}

// Fallback
header("Location: /");
exit;