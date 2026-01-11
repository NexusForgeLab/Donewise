<?php
require_once __DIR__ . '/app/layout.php';
$user = current_user();
if (!$user) { header('Location: /login.php'); exit; }
header('Location: /day.php?date=' . date('Y-m-d'));
