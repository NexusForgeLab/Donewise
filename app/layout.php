<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/recurring_logic.php';

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

// --- NEW HELPER: Make links & mentions clickable ---
function linkify(string $text): string {
  // 1. URLs (http/https)
  $text = preg_replace(
      '~(https?://\S+)~i', 
      '<a href="$1" target="_blank" rel="noopener noreferrer" style="color:var(--accent-blue);text-decoration:underline" onclick="event.stopPropagation()">$1</a>', 
      $text
  );
  
  // 2. Emails
  $text = preg_replace(
      '/([a-zA-Z0-9._-]+@[a-zA-Z0-9._-]+\.[a-zA-Z0-9._-]+)/i', 
      '<a href="mailto:$1" style="color:var(--accent-blue);text-decoration:underline" onclick="event.stopPropagation()">$1</a>', 
      $text
  );

  // 3. Mentions (@username) - Visual Only
  $text = preg_replace(
      '/@([a-zA-Z0-9_]+)/',
      '<span style="color:var(--accent-blue); font-weight:bold;">@$1</span>',
      $text
  );
  
  return $text;
}

// --- NEW HELPER: Process Mentions (Notifications & Assignment) ---
function process_mentions($pdo, $groupId, $actorId, $actorName, $text, $contextType, $contextId) {
    // 1. Find all @mentions
    preg_match_all('/@([a-zA-Z0-9_]+)/', $text, $matches);
    $mentionedUsernames = array_unique($matches[1]);
    
    $assigneeId = null;

    if (!empty($mentionedUsernames)) {
        // Fetch User IDs
        $placeholders = implode(',', array_fill(0, count($mentionedUsernames), '?'));
        $st = $pdo->prepare("SELECT id, username FROM users WHERE group_id=? AND username IN ($placeholders)");
        $st->execute(array_merge([$groupId], $mentionedUsernames));
        $users = $st->fetchAll();

        foreach ($users as $u) {
            // If checking for assignment, pick the FIRST mentioned user
            if ($assigneeId === null) {
                $assigneeId = $u['id'];
            }

            // Send Notification (avoid self-notify)
            if ((int)$u['id'] !== (int)$actorId && function_exists('send_group_notification')) {
                // Customize message based on context
                $msg = "mentioned you in a $contextType";
                if ($contextType === 'task' || $contextType === 'edit') {
                    $msg = "assigned you a task";
                } elseif ($contextType === 'comment') {
                    $msg = "mentioned you in a comment";
                }
                
                send_group_notification($pdo, $groupId, $actorId, $actorName, 'mention', $msg, $contextId, $u['id']);
            }
        }
    }
    
    return $assigneeId; // Returns the ID of the first mentioned user (or null)
}

function format_time(?string $dt): string {
  if (!$dt) return '';
  try {
    $d = new DateTime($dt, new DateTimeZone('UTC'));
    $d->setTimezone(new DateTimeZone(date_default_timezone_get()));
    return $d->format('M j, g:i a');
  } catch (Exception $e) { return $dt; }
}

function render_header(string $title, ?array $user=null): void {
  $user = $user ?? current_user();
  
  if ($user) {
    check_recurring_tasks(); 
  }

  $unread = 0;
  if ($user) {
    $pdo = db();
    $st = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND read_at IS NULL");
    $st->execute([$user['id']]);
    $unread = (int)$st->fetchColumn();
  }
  
  $badgeStyle = ($unread > 0) ? '' : 'display:none';

  echo "<!doctype html><html><head>
  <meta charset='utf-8'/>
  <meta name='viewport' content='width=device-width,initial-scale=1,maximum-scale=1'/>
  <meta name='theme-color' content='#ff3b30'/>
  
  <title>".h($title)."</title>
  
  <link rel='stylesheet' href='assets/style.css'/>
  <link rel='manifest' href='manifest.json'/>
  
  <link rel='icon' type='image/png' href='assets/icon-192.png' />
  <link rel='apple-touch-icon' href='assets/icon-192.png' />
  
  </head><body><div class='wrap'>";

  echo "<div class='topbar'>";
  echo "<div class='brand'><a href='index.php'>Donewise</a></div>";
  
  if ($user) {
    echo "<div class='nav'>";
    
    $identities = $_SESSION['my_identities'] ?? [$user];
    $hasMultiple = count($identities) > 1;
    
    echo "<div class='user-menu'>";
    if ($hasMultiple) {
        echo "<form style='display:inline; margin:0;'>";
        echo "<select onchange=\"location.href='switch_group.php?uid='+this.value\">";
        foreach ($identities as $id) {
            $sel = ($id['id'] === $user['id']) ? 'selected' : '';
            echo "<option value='{$id['id']}' $sel>" . h($id['display_name']) . " @ " . h($id['group_name']) . "</option>";
        }
        echo "</select>";
        echo "</form>";
    } else {
        echo "<span class='user-name'>" . h($user['display_name']) . "</span>";
        echo "<span class='group-name'>(" . h($user['group_name'] ?? 'Group') . ")</span>";
    }
    echo "</div>";

    echo "<a class='btn' href='remaining.php'>Remaining</a>";
    echo "<a class='btn' href='recurring.php'>Recurring</a>";
    echo "<a class='btn' href='notifications.php'>Notifs <span id='unreadBadge' class='badge' style=\"$badgeStyle\">".$unread."</span></a>";
    echo "<a class='btn' href='settings.php'>Group</a>";
    echo "<a class='btn' href='logout.php'>Logout</a>";
    echo "</div>";
  } else {
    echo "<div class='nav'><a class='btn' href='login.php'>Login</a><a class='btn' href='register.php'>Create Group</a></div>";
  }
  echo "</div>";
}

function render_footer(): void {
  echo "</div>
  <script src='assets/app.js'></script>
  <script>
    if ('serviceWorker' in navigator) {
      navigator.serviceWorker.register('sw.js');
    }
  </script>
  </body></html>";
}
?>