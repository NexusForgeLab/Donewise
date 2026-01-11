<?php
declare(strict_types=1);

date_default_timezone_set(getenv('PHP_TZ') ?: 'Asia/Kolkata');

function env(string $k, ?string $default=null): string {
  $v = getenv($k);
  return ($v === false || $v === '') ? ($default ?? '') : $v;
}

define('APP_URL', rtrim(env('APP_URL','http://localhost:8088'), '/'));
define('SQLITE_PATH', env('SQLITE_PATH', __DIR__ . '/../data/app.db'));

// Fix: Only start session if one isn't already active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}