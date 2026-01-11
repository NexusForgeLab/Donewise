<?php
// This script runs to push events to the browser
require_once __DIR__ . '/../app/auth.php';

// 1. PERFORMANCE: Close session immediately so we don't block other requests
session_write_close();

// 2. SERVER HEALTH: Don't run forever. Exit after 40s to free up the PHP worker.
// The browser's EventSource will automatically reconnect.
$endTime = time() + 40; 

ini_set('output_buffering', 'off');
ini_set('zlib.output_compression', false);
set_time_limit(120); // Allow enough time for our loop

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');

$user = current_user();
if (!$user) {
    echo "data: {\"error\": \"unauthorized\"}\n\n";
    if (ob_get_level() > 0) ob_flush();
    flush();
    exit;
}

$pdo = db();

// Get the starting point
$stmt = $pdo->prepare("SELECT MAX(id) FROM notifications");
$stmt->execute();
$lastId = (int)$stmt->fetchColumn();

// Send initial heartbeat to establish connection
echo ": connected\n\n";
if (ob_get_level() > 0) ob_flush();
flush();

while (time() < $endTime) {
    // Check for NEW notifications since $lastId
    // OPTIMIZATION: Check specific user ID > lastId
    $stmt = $pdo->prepare("SELECT * FROM notifications WHERE id > ? AND user_id = ? ORDER BY id ASC");
    $stmt->execute([$lastId, $user['id']]);
    $newRows = $stmt->fetchAll();

    if ($newRows) {
        // Get updated unread count (Only count if we have new data)
        $cStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND read_at IS NULL");
        $cStmt->execute([$user['id']]);
        $unreadCount = (int)$cStmt->fetchColumn();

        foreach ($newRows as $r) {
            $lastId = $r['id'];
            $payload = json_encode([
                'type' => 'notification',
                'message' => $r['message'],
                'icon_type' => $r['type'],
                'unread' => $unreadCount
            ]);
            echo "data: {$payload}\n\n";
        }
        
        if (ob_get_level() > 0) ob_flush();
        flush();
    }

    if (connection_aborted()) break;

    // PERFORMANCE: Sleep 3 seconds instead of 1.
    // 1s is too aggressive for a shopping list and hammers the DB.
    sleep(3);
}