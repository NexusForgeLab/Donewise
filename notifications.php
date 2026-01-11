<?php
require_once __DIR__ . '/app/layout.php';
$user = require_login();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $pdo->prepare("UPDATE notifications SET read_at=datetime('now') WHERE user_id=? AND read_at IS NULL")->execute([$user['id']]);
  header('Location: /notifications.php');
  exit;
}


// Helper (Icon Mapper)
function get_icon(string $type): string {
    return match($type) {
        'task_add' => '🛒', 'task_done' => '✅', 'task_undo' => '↩️',
        'task_delete' => '🗑️', 'comment' => '💬', 'reorder' => '🔃',
        default => '📢',
    };
}

// --- AJAX HANDLER ---
if (isset($_GET['ajax'])) {
    // PERFORMANCE FIX: Release session lock immediately
    session_write_close();

    $st = $pdo->prepare("SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 100");
    $st->execute([$user['id']]);
    $rows = $st->fetchAll();
    
    if (!count($rows)) { echo "<div class='muted' style='text-align:center; padding:20px;'>No notifications yet.</div>"; exit; }
    
    echo "<div class='list'>";
    foreach ($rows as $n) {
        $isRead = !empty($n['read_at']);
        $icon = get_icon($n['type'] ?? '');
        $opacity = $isRead ? '0.6' : '1';
        $bg = $isRead ? 'transparent' : '#fff8f8';
        $border = $isRead ? '1px solid #eee' : '1px solid var(--accent)';
        
        echo "<div style='display:flex; gap:12px; padding:12px; margin-bottom:8px; background:$bg; border-radius:8px; border:$border; align-items:flex-start;'>";
        echo "<div style='font-size:1.5rem; line-height:1; opacity:$opacity;'>$icon</div>";
        echo "<div style='flex:1; opacity:$opacity;'>";
        echo "<div style='font-size:1rem; line-height:1.4;'>" . h($n['message']) . "</div>";
        echo "<div class='muted' style='font-size:0.85rem; margin-top:4px;'>" . format_time($n['created_at']);
        if(!$isRead) echo "<span style='color:var(--accent); font-weight:bold; margin-left:6px;'>&#8226; New</span>";
        echo "</div></div>";
        if (!empty($n['task_id'])) {
            echo "<a href='/task_details.php?id=".(int)$n['task_id']."' class='btn-link' style='font-size:1.2rem; text-decoration:none;'>&rsaquo;</a>";
        }
        echo "</div>";
    }
    echo "</div>";
    exit;
}
// --------------------

render_header('Notifications', $user);
?>

<div class="card">
  <div style="display:flex; justify-content:space-between; align-items:center">
    <h1>Notifications</h1>
    <form method="post">
      <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>"/>
      <button class="btn" type="submit">Mark all read</button>
    </form>
  </div>

  <div id="notif-setup" style="display:none; margin:15px 0; background:#e3f2fd; padding:15px; border:1px solid #2196f3; border-radius:8px;">
    <h3 style="margin-top:0; color:#0d47a1; display:flex; align-items:center; gap:8px;">?? Enable Alerts?</h3>
    <div class="muted" style="margin-bottom:10px">Get push notifications on this device.</div>
    <button class="btn" onclick="enableNotifications()" style="background:#2196f3; color:white; border:none;">Enable</button>
  </div>
</div>

<div class="card" id="notifList">
  <div class="muted">Loading...</div>
</div>

<script>
// --- AUTO UPDATE NOTIFICATIONS ---
const notifListEl = document.getElementById('notifList');
let currentNotifHtml = '';

async function loadNotifs() {
    try {
        const res = await fetch('/notifications.php?ajax=1');
        const html = await res.text();
        if (html !== currentNotifHtml) {
            notifListEl.innerHTML = html;
            currentNotifHtml = html;
        }
    } catch(e) {}
}
loadNotifs();
setInterval(loadNotifs, 4000);

// Enable Button Check
document.addEventListener('DOMContentLoaded', () => {
  if ("Notification" in window && Notification.permission === 'default') {
    const box = document.getElementById('notif-setup');
    if (box) box.style.display = 'block';
  }
});
</script>

<?php render_footer(); ?>