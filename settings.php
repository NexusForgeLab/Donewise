<?php
require_once __DIR__ . '/app/layout.php';
require_once __DIR__ . '/app/tag_logic.php'; 
$user = require_login();
$pdo = db();

// Fetch Group Details
$st = $pdo->prepare("SELECT * FROM groups WHERE id=?");
$st->execute([$user['group_id']]);
$group = $st->fetch();

if (!$group) {
    header('Location: /logout.php');
    exit;
}

$isCreator = ((int)$group['created_by'] === (int)$user['id']);
$msg = '';
$err = '';

// --- HANDLE SUBMISSIONS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    // 1. Rename Group
    if (isset($_POST['rename_group']) && $isCreator) {
        $newName = trim($_POST['group_name']);
        if ($newName !== '') {
            $pdo->prepare("UPDATE groups SET name=? WHERE id=?")->execute([$newName, $user['group_id']]);
            $group['name'] = $newName; 
            $msg = "Group name updated.";
        }
    }

    // 2. Delete Group
    if (isset($_POST['delete_group']) && $isCreator) {
        if (trim($_POST['confirm_name']) === $group['name']) {
            $pdo->prepare("DELETE FROM groups WHERE id=?")->execute([$user['group_id']]);
            header('Location: /logout.php'); exit;
        } else {
            $err = "Group name did not match.";
        }
    }

    // 3. Kick User
    if (isset($_POST['kick_id']) && $isCreator) {
        $kickId = (int)$_POST['kick_id'];
        if ($kickId === (int)$user['id']) { $err = "Cannot kick yourself."; } 
        else {
            $pdo->prepare("DELETE FROM users WHERE id=? AND group_id=?")->execute([$kickId, $user['group_id']]);
            $msg = "User removed.";
        }
    }

    // 4. ADD TAG
    if (isset($_POST['new_tag_name']) && $isCreator) {
        $rawTag = trim($_POST['new_tag_name']);
        $cleanTag = mb_strtolower(preg_replace('/\s+/', '', ltrim($rawTag, '#')));

        if ($cleanTag !== '') {
            $st = $pdo->prepare("SELECT id FROM tags WHERE group_id=? AND name=?");
            $st->execute([$user['group_id'], $cleanTag]);
            if ($st->fetch()) {
                $err = "Tag #$cleanTag already exists.";
            } else {
                $hash = crc32($cleanTag);
                $color = get_tag_color($hash);
                $st = $pdo->prepare("INSERT INTO tags (group_id, name, color) VALUES (?, ?, ?)");
                $st->execute([$user['group_id'], $cleanTag, $color]);
                $msg = "Tag #$cleanTag created.";
            }
        }
    }

    // 5. DELETE TAG
    if (isset($_POST['delete_tag_id'])) {
        $tagId = (int)$_POST['delete_tag_id'];
        $pdo->prepare("DELETE FROM tags WHERE id=? AND group_id=?")->execute([$tagId, $user['group_id']]);
        $msg = "Tag deleted.";
    }

    // 6. LEAVE GROUP (New Logic)
    if (isset($_POST['leave_group'])) {
        // Count remaining members
        $st = $pdo->prepare("SELECT COUNT(*) FROM users WHERE group_id=?");
        $st->execute([$user['group_id']]);
        $count = (int)$st->fetchColumn();

        if ($count <= 1) {
            // Last person leaving: Delete the group
            $pdo->prepare("DELETE FROM groups WHERE id=?")->execute([$user['group_id']]);
        } else {
            // If Owner is leaving, transfer ownership
            if ($isCreator) {
                // Pick a random new owner
                $st = $pdo->prepare("SELECT id, display_name FROM users WHERE group_id=? AND id!=? ORDER BY RANDOM() LIMIT 1");
                $st->execute([$user['group_id'], $user['id']]);
                $newOwnerData = $st->fetch();

                if ($newOwnerData) {
                    $pdo->prepare("UPDATE groups SET created_by=? WHERE id=?")->execute([$newOwnerData['id'], $user['group_id']]);
                    
                    if(function_exists('send_group_notification')) {
                         send_group_notification($pdo, $user['group_id'], $user['id'], $user['display_name'], 'general', "left. Owner is now: " . $newOwnerData['display_name']);
                    }
                }
            } else {
                if(function_exists('send_group_notification')) {
                     send_group_notification($pdo, $user['group_id'], $user['id'], $user['display_name'], 'general', "left the group.");
                }
            }

            // Delete the user
            $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$user['id']]);
        }

        // Logout
        header('Location: /logout.php');
        exit;
    }
}

// Fetch Members
$members = $pdo->prepare("SELECT * FROM users WHERE group_id=? ORDER BY display_name ASC");
$members->execute([$user['group_id']]);
$members = $members->fetchAll();

// Fetch Tags
$tags = $pdo->prepare("SELECT * FROM tags WHERE group_id=? ORDER BY name ASC");
$tags->execute([$user['group_id']]);
$allTags = $tags->fetchAll();

$joinLink = APP_URL . "/join.php?token=" . $group['join_token'];

render_header('Group Settings', $user);
?>

<?php if($msg): ?><div class="card" style="background:#e8f5e9; border-color:green; padding:15px;"><?php echo h($msg); ?></div><?php endif; ?>
<?php if($err): ?><div class="card" style="background:#ffebee; border-color:red; padding:15px;"><?php echo h($err); ?></div><?php endif; ?>

<div class="card">
  <?php if($isCreator): ?>
    <form method="post" style="margin-bottom:20px;">
        <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>"/>
        <div class="muted">Group Name</div>
        <div class="row">
            <div style="flex:1;">
                <input name="group_name" value="<?php echo h($group['name']); ?>" required />
            </div>
            <div>
                <button class="btn" type="submit" name="rename_group" style="min-width:100px;">Save Name</button>
            </div>
        </div>
    </form>
  <?php else: ?>
    <h1><?php echo h($group['name']); ?></h1>
  <?php endif; ?>

  <div style="margin-top:20px;">
    <div class="muted">Invite Link:</div>
    <div style="display:flex; gap:8px;">
        <input value="<?php echo h($joinLink); ?>" readonly style="background:#f9f9f9; color:#555;"/>
        <button class="btn" onclick="navigator.clipboard.writeText('<?php echo h($joinLink); ?>');alert('Copied!')">Copy</button>
    </div>
  </div>
</div>

<div class="card">
  <h2>Manage Tags</h2>
  <?php if($isCreator): ?>
    <form method="post" style="margin-bottom:20px; padding-bottom:20px; border-bottom:1px dashed #eee;">
        <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>"/>
        <div class="muted">Create New Tag</div>
        <div class="row" style="align-items:center;">
            <div style="flex:1;">
                <input name="new_tag_name" placeholder="e.g. Urgent" required />
            </div>
            <div>
                <button class="btn" type="submit" style="background:var(--card-bg);">Add Tag</button>
            </div>
        </div>
    </form>
  <?php endif; ?>
  
  <?php if(empty($allTags)): ?>
    <div class="muted">No tags created yet.</div>
  <?php else: ?>
    <div class="tags-container">
        <?php foreach($allTags as $tag): ?>
            <form method="post" style="display:inline" onsubmit="return confirm('Delete tag #<?php echo h($tag['name']); ?>?');">
                <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>"/>
                <input type="hidden" name="delete_tag_id" value="<?php echo $tag['id']; ?>"/>
                <button class="tag-pill" title="Delete Tag"
                        style="border:1px solid <?php echo $tag['color']; ?>; color:<?php echo $tag['color']; ?>; background:white; cursor:pointer; padding:4px 10px; margin-bottom:6px;">
                    #<?php echo h($tag['name']); ?> <span style="color:var(--muted); margin-left:4px; font-weight:bold;">&times;</span>
                </button>
            </form>
        <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Members (<?php echo count($members); ?>)</h2>
  <div class="list">
    <?php foreach($members as $m): ?>
      <?php $isMe = ($m['id'] === $user['id']); $isOwner = ($m['id'] === $group['created_by']); ?>
      <div style="display:flex; justify-content:space-between; align-items:center; padding:12px 0; border-bottom:1px solid #eee;">
        <div>
            <div style="font-weight:bold; font-size:1.1rem;"><?php echo h($m['display_name']); ?><?php if($isMe) echo " <span class='badge'>You</span>"; ?></div>
            <div class="muted" style="font-size:0.85rem;">@<?php echo h($m['username']); ?></div>
        </div>
        <div>
            <?php if($isOwner): ?>
                <span class="pill" style="background:#fff9c4; border-color:#fbc02d; color:#f57f17;">Owner</span>
            <?php elseif($isCreator): ?>
                <form method="post" onsubmit="return confirm('Remove User?');" style="margin:0;">
                    <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>"/>
                    <input type="hidden" name="kick_id" value="<?php echo $m['id']; ?>"/>
                    <button class="btn-link delete-link" style="font-size:0.9rem;">Remove</button>
                </form>
            <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<div class="card" style="border-color:#ff3b30; background:#fff5f5;">
    <h2 style="color:#c62828;">Danger Zone</h2>
    
    <button class="btn" style="background:#fff; border-color:#ff3b30; color:#ff3b30; margin-right:10px;" onclick="if(confirm('Are you sure you want to leave this group?')) document.getElementById('leaveForm').submit();">Leave Group</button>
    <form method="post" id="leaveForm" style="display:none">
        <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>"/>
        <input type="hidden" name="leave_group" value="1"/>
    </form>

    <?php if($isCreator): ?>
        <button class="btn" style="background:#ff3b30; color:white; border:none;" onclick="document.getElementById('deleteArea').style.display='block'; this.style.display='none'">Delete Group</button>
        <div id="deleteArea" style="display:none; margin-top:15px;">
            <form method="post">
                <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>"/>
                <div class="muted">Type <b><?php echo h($group['name']); ?></b> to confirm:</div>
                <div class="row">
                    <div style="flex:1;"><input name="confirm_name" required style="border-color:#ff3b30;" /></div>
                    <div><button class="btn" type="submit" name="delete_group" style="background:#ff3b30; color:white; border:none;">Confirm</button></div>
                </div>
            </form>
        </div>
    <?php endif; ?>
</div>

<?php render_footer(); ?>