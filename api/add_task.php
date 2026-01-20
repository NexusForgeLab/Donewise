<?php
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/tag_logic.php';

header('Content-Type: application/json');

// --- 1. AUTHENTICATION ---
$headers = getallheaders();
$auth = $headers['Authorization'] ?? '';

// Support for "Bearer <token>"
if (!preg_match('/Bearer\s(\w+)/', $auth, $matches)) {
    http_response_code(401); 
    echo json_encode(['error'=>'Missing or invalid Authorization header']); 
    exit;
}
$token = $matches[1];

$pdo = db();
$st = $pdo->prepare("SELECT * FROM users WHERE api_token = ?");
$st->execute([$token]);
$user = $st->fetch();

if (!$user) {
    http_response_code(403); 
    echo json_encode(['error'=>'Invalid token']); 
    exit;
}

// --- 2. INPUT PARSING ---
$input = json_decode(file_get_contents('php://input'), true);
$rawText = trim($input['text'] ?? '');

if (!$rawText) {
    http_response_code(400); 
    echo json_encode(['error'=>'No text provided']); 
    exit;
}

// --- 3. SMART DATE LOGIC ---
$targetDate = date('Y-m-d'); // Default to Today
$cleanText = $rawText;

// Regex patterns for common voice commands (case insensitive)
$patterns = [
    '/\b(tomorrow)\b/i'       => '+1 day',
    '/\b(tonight)\b/i'        => 'today',
    '/\b(next week)\b/i'      => '+1 week',
    '/\b(next monday)\b/i'    => 'next monday',
    '/\b(next tuesday)\b/i'   => 'next tuesday',
    '/\b(next wednesday)\b/i' => 'next wednesday',
    '/\b(next thursday)\b/i'  => 'next thursday',
    '/\b(next friday)\b/i'    => 'next friday',
    '/\b(next saturday)\b/i'  => 'next saturday',
    '/\b(next sunday)\b/i'    => 'next sunday',
];

// Check "In X Days"
if (preg_match('/(in\s+(\d+)\s+days?)/i', $rawText, $matches)) {
    $days = (int)$matches[2];
    $targetDate = date('Y-m-d', strtotime("+$days days"));
    $cleanText = str_replace($matches[1], '', $cleanText); 
} else {
    // Check keyword patterns
    foreach ($patterns as $pattern => $modifier) {
        if (preg_match($pattern, $rawText)) {
            $targetDate = date('Y-m-d', strtotime($modifier));
            $cleanText = preg_replace($pattern, '', $cleanText);
            break; // Stop at first match
        }
    }
}

// Cleanup whitespace left by removals
$cleanText = trim(preg_replace('/\s+/', ' ', $cleanText));

// --- 4. INSERT TASK ---
// Ensure the "Day" exists in DB
$pdo->prepare("INSERT OR IGNORE INTO days(group_id, day_date) VALUES(?,?)")->execute([$user['group_id'], $targetDate]);
$st = $pdo->prepare("SELECT id FROM days WHERE group_id=? AND day_date=?");
$st->execute([$user['group_id'], $targetDate]);
$dayId = $st->fetchColumn();

// Normalized text for sorting/history
$norm = mb_strtolower(trim(preg_replace('/#\w+/u', '', $cleanText)));

$st = $pdo->prepare("INSERT INTO tasks(group_id, day_id, text, text_norm, created_by) VALUES(?,?,?,?,?)");
$st->execute([$user['group_id'], $dayId, $cleanText, $norm, $user['id']]);
$taskId = $pdo->lastInsertId();

// Handle Tags & History
parse_and_save_tags($pdo, $user['group_id'], $taskId, $cleanText);

// --- 5. RESPONSE ---
echo json_encode([
    'status' => 'ok',
    'id' => $taskId,
    'scheduled_date' => $targetDate,
    'original_text' => $rawText,
    'saved_text' => $cleanText
]);
?>