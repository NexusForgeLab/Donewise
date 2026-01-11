<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

function current_user(): ?array {
  return $_SESSION['user'] ?? null;
}

function require_login(): array {
  $u = current_user();
  if (!$u) { header('Location: /login.php'); exit; }
  return $u;
}

function csrf_token(): string {
  if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
  return $_SESSION['csrf'];
}

function csrf_check(): void {
  $t = $_POST['csrf'] ?? '';
  if (!$t || !hash_equals($_SESSION['csrf'] ?? '', $t)) {
    http_response_code(400);
    echo "Bad CSRF token";
    exit;
  }
}

// Updated: Strips hashtags so "Milk #urgent" matches "Milk"
function normalize_text(string $s): string {
  $s = mb_strtolower($s);
  // Remove hashtags (e.g. #urgent #food)
  $s = preg_replace('/#\w+/u', '', $s);
  // Remove punctuation/symbols (optional, keeps it cleaner)
  // $s = preg_replace('/[^\w\s]/u', '', $s); 
  // Normalize whitespace
  $s = trim(preg_replace('/\s+/', ' ', $s));
  return $s;
}

function send_group_notification(PDO $pdo, int $group_id, int $sender_id, string $sender_name, string $type, string $short_msg, ?int $task_id = null): void {
    $stmt = $pdo->prepare("SELECT name FROM groups WHERE id = ?");
    $stmt->execute([$group_id]);
    $groupName = $stmt->fetchColumn();

    $fullMessage = "[{$groupName}] {$sender_name} {$short_msg}";

    $stmt = $pdo->prepare("SELECT id FROM users WHERE group_id = ? AND id <> ?");
    $stmt->execute([$group_id, $sender_id]);
    $recipients = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $ins = $pdo->prepare("INSERT INTO notifications(group_id, user_id, type, message, task_id) VALUES(?,?,?,?,?)");
    foreach ($recipients as $uid) {
        $ins->execute([$group_id, $uid, $type, $fullMessage, $task_id]);
    }
}