<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

function db(): PDO {
  static $pdo = null;
  if ($pdo) return $pdo;

  $path = SQLITE_PATH;
  $dir = dirname($path);
  if (!is_dir($dir)) @mkdir($dir, 0775, true);

  $pdo = new PDO('sqlite:' . $path, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
  
  // --- PERFORMANCE OPTIMIZATIONS ---
  // 1. Enforce Foreign Keys
  $pdo->exec("PRAGMA foreign_keys = ON;");
  // 2. WAL Mode: Allows reading and writing simultaneously (Crucial for speed)
  $pdo->exec("PRAGMA journal_mode = WAL;");
  // 3. Synchronous Normal: Safely reduces disk syncs for speed
  $pdo->exec("PRAGMA synchronous = NORMAL;");
  
  return $pdo;
}