<?php
require_once __DIR__ . '/app/layout.php';
$pdo = db();
$sql = file_get_contents(__DIR__ . '/sql/init.sql');

try {
  $pdo->exec($sql);
  render_header('Installed');
  echo "<div class='card'><h1>✅ Installed</h1>
  <div class='muted'>SQLite database created at <code>".h(SQLITE_PATH)."</code></div>
  <div class='muted' style='margin-top:10px'>Now delete <code>install.php</code> for security.</div>
  <a class='btn' href='/register.php'>Create your first group</a>
  </div>";
  render_footer();
} catch (Exception $e) {
  render_header('Install Error');
  echo "<div class='card'><h1>Install Error</h1><div class='muted'>".h($e->getMessage())."</div></div>";
  render_footer();
}
