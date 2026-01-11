<?php
require_once __DIR__ . '/app/auth.php';

$target_user_id = (int)($_GET['uid'] ?? 0);
$identities = $_SESSION['my_identities'] ?? [];

$found = false;
foreach ($identities as $id) {
    if ((int)$id['id'] === $target_user_id) {
        $_SESSION['user'] = $id;
        $found = true;
        break;
    }
}

// FIX: Close session before redirecting to prevent "headers already sent" next page
session_write_close();

header('Location: /');
exit;