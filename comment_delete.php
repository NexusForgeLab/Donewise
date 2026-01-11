<?php
// Use config/auth instead of layout.php to prevent "Headers already sent" errors
require_once __DIR__ . '/app/config.php';
require_once __DIR__ . '/app/auth.php';

$user = require_login();
csrf_check();
$pdo = db();

$comment_id = (int)($_POST['comment_id'] ?? 0);

if ($comment_id > 0) {
    // 1. Verify ownership and get task_id for redirect
    // We check user_id to ensure users can only delete their own comments
    $st = $pdo->prepare("SELECT task_id FROM comments WHERE id = ? AND user_id = ? LIMIT 1");
    $st->execute([$comment_id, $user['id']]);
    $task_id = $st->fetchColumn();

    if ($task_id) {
        // 2. Delete the comment
        $pdo->prepare("DELETE FROM comments WHERE id = ?")->execute([$comment_id]);
        
        // 3. Redirect to task
        header("Location: /task_details.php?id=" . $task_id);
        exit;
    }
}

// Fallback if ID invalid or permission denied
header("Location: /");
exit;