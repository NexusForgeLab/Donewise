<?php
require_once __DIR__ . '/app/layout.php';
$user = require_login();
$pdo = db();

$task_id = (int)($_GET['id'] ?? 0);
if ($task_id <= 0) { header('Location: /'); exit; }

// Fetch Task
$st = $pdo->prepare("SELECT * FROM tasks WHERE id=? AND group_id=?");
$st->execute([$task_id, $user['group_id']]);
$t = $st->fetch();
if (!$t) { header('Location: /'); exit; }

// --- HANDLE POST (Unified Upload + Comment) ---
$uploadErr = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    
    // 1. Handle File Upload
    $fileAttached = false;
    if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
        $f = $_FILES['attachment'];
        $allowed = ['jpg','jpeg','png','gif','pdf','doc','docx','txt','xls','xlsx','json','cs'];
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        
        if (in_array($ext, $allowed)) {
            $uploadDir = 'uploads';
            if (!is_dir(__DIR__ . "/$uploadDir")) {
                mkdir(__DIR__ . "/$uploadDir", 0775, true);
            }
            $newName = "{$task_id}_" . time() . "_" . bin2hex(random_bytes(4)) . ".$ext";
            $destPath = "$uploadDir/$newName";
            
            if (move_uploaded_file($f['tmp_name'], __DIR__ . "/$destPath")) {
                $st = $pdo->prepare("INSERT INTO attachments(group_id, task_id, user_id, filename, filepath, file_type) VALUES(?,?,?,?,?,?)");
                $st->execute([$user['group_id'], $task_id, $user['id'], $f['name'], $destPath, $ext]);

                if (function_exists('send_group_notification')) {
                    send_group_notification($pdo, $user['group_id'], $user['id'], $user['display_name'], 'attachment', "added file: " . $f['name'], $task_id);
                }
                $fileAttached = true;
            } else {
                $uploadErr = "Failed to save file. Check permissions.";
            }
        } else {
            $uploadErr = "Invalid file type.";
        }
    }

    // 2. Handle Comment
    $msg = trim($_POST['message'] ?? '');
    $parent_id = (int)($_POST['parent_id'] ?? 0);
    if ($parent_id === 0) $parent_id = null;

    if ($msg !== '') {
        $st = $pdo->prepare("INSERT INTO comments(group_id, task_id, user_id, parent_id, message) VALUES(?,?,?,?,?)");
        $st->execute([$user['group_id'], $task_id, $user['id'], $parent_id, $msg]);

        $action = $parent_id ? "replied on:" : "commented on:";
        if (function_exists('send_group_notification')) {
            send_group_notification($pdo, $user['group_id'], $user['id'], $user['display_name'], 'comment', "$action " . $t['text'], $task_id);
        }
    }

    if (($fileAttached || $msg !== '') && !$uploadErr) {
        header("Location: /task_details.php?id=$task_id");
        exit;
    }
}

// --- AJAX COMMENT LOADER ---
if (isset($_GET['ajax'])) {
    // PERFORMANCE FIX: Close session early so other requests aren't blocked
    session_write_close();

    $st = $pdo->prepare("SELECT c.*, u.display_name FROM comments c JOIN users u ON u.id = c.user_id WHERE c.task_id=? ORDER BY c.created_at ASC");
    $st->execute([$task_id]);
    $all_comments = $st->fetchAll();

    $tree = []; $map = [];
    foreach ($all_comments as $c) { $c['children'] = []; $map[$c['id']] = $c; }
    foreach ($map as $id => &$c) {
        if ($c['parent_id'] && isset($map[$c['parent_id']])) {
            $map[$c['parent_id']]['children'][] = &$c;
        } else {
            $tree[] = &$c;
        }
    }
    
    // BUG FIX: Swapped $current_user_id and $depth to fix Deprecated warning
    function render_comments_ajax($nodes, $current_user_id, $depth=0) {
        foreach($nodes as $c) {
            $margin = min($depth * 24, 48);
            $isMine = ($c['user_id'] === $current_user_id);
            echo "<div class='comment' style='margin-left:{$margin}px' id='c{$c['id']}'>";
            echo "<div class='comment-meta'><span class='author'>" . h($c['display_name']) . "</span><span class='date'>" . format_time($c['created_at']) . "</span></div>";
            echo "<div class='comment-body'>" . nl2br(h($c['message'])) . "</div>";
            echo "<div class='comment-actions'><button class='btn-link' onclick=\"replyTo({$c['id']}, '".h($c['display_name'])."')\">Reply</button>";
            if ($isMine) {
                echo "<span class='sep'>&#8226;</span><form method='post' action='/comment_delete.php' style='display:inline' onsubmit='return confirm(\"Delete comment?\")'><input type='hidden' name='csrf' value='".h(csrf_token())."'/><input type='hidden' name='comment_id' value='{$c['id']}'/><button class='btn-link delete-link'>Delete</button></form>";
            }
            echo "</div></div>";
            // Recursion updated to match new signature
            if (!empty($c['children'])) render_comments_ajax($c['children'], $current_user_id, $depth + 1);
        }
    }
    
    if(empty($tree)) echo "<div class='muted'>No comments yet.</div>";
    else render_comments_ajax($tree, $user['id'], 0);
    exit;
}

// Fetch Attachments
$st = $pdo->prepare("SELECT * FROM attachments WHERE task_id=? ORDER BY created_at DESC");
$st->execute([$task_id]);
$attachments = $st->fetchAll();

render_header('Task Details', $user);
?>

<div class="card">
  <div style="display:flex;justify-content:space-between;margin-bottom:10px">
    <a href="javascript:history.back()" class="btn">&#8592; Back</a>
  </div>
  <h1><?php echo h($t['text']); ?></h1>
  <div class="muted">
    Created: <?php echo format_time($t['created_at']); ?>
    <?php if($t['is_done']): ?><span class="badge" style="background:var(--muted)">Completed</span><?php endif; ?>
  </div>
</div>

<?php if($uploadErr): ?><div class="card" style="border-color:red; color:red;"><?php echo h($uploadErr); ?></div><?php endif; ?>

<div class="card">
  <h2>Attachments</h2>
  <?php if(count($attachments)): ?>
    <div class="attachment-grid">
      <?php foreach($attachments as $att): ?>
        <?php 
            $isImg = in_array($att['file_type'], ['jpg','jpeg','png','gif']); 
            $canDel = ($att['user_id'] === $user['id']);
        ?>
        <div class="attachment-item">
          <a href="/<?php echo h($att['filepath']); ?>" target="_blank" download="<?php echo h($att['filename']); ?>">
            <?php if($isImg): ?>
                <img src="/<?php echo h($att['filepath']); ?>" class="attachment-preview" loading="lazy" />
            <?php else: ?>
                <div class="attachment-icon"><?php echo strtoupper(h($att['file_type'])); ?></div>
            <?php endif; ?>
          </a>
          
          <div class="attachment-meta" title="<?php echo h($att['filename']); ?>">
            <?php echo h($att['filename']); ?>
          </div>

          <?php if($canDel): ?>
            <form method="post" action="/attachment_delete.php" onsubmit="return confirm('Delete file?');">
                <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>"/>
                <input type="hidden" name="attachment_id" value="<?php echo $att['id']; ?>"/>
                <button class="btn-del-file" title="Delete">&times;</button>
            </form>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <div class="muted">No files attached.</div>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Discussion</h2>
  <div id="commentList" class="comment-list">
    <div class="muted">Loading comments...</div>
  </div>
</div>

<div class="card" id="replyFormCard" style="position:sticky; bottom:20px; box-shadow:0 -5px 20px rgba(0,0,0,0.1); border-color:var(--accent-blue);">
  <div id="replyingToBanner" style="display:none; background:#fff; padding:8px; border:2px dashed var(--accent); margin-bottom:10px; font-size:0.9rem;">
    Replying to <b id="replyingToName"></b> 
    <button type="button" style="background:none;border:none;color:var(--accent);cursor:pointer;margin-left:10px;text-decoration:underline" onclick="cancelReply()">Cancel</button>
  </div>
  
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>"/>
    <input type="hidden" name="parent_id" id="parentInput" value=""/>
    
    <div class="row" style="align-items:flex-start;">
      <div style="flex:1;">
        <textarea name="message" placeholder="Write a comment..." rows="2" style="min-height:50px; resize:none;" id="msgInput"></textarea>
        
        <div style="margin-top:8px;">
            <label class="btn-link" style="cursor:pointer; font-size:0.9rem; display:inline-flex; align-items:center; gap:5px;">
                &#128206; Attach File 
                <input type="file" name="attachment" style="display:none;" onchange="document.getElementById('fileNameDisplay').textContent = this.files[0].name;">
            </label>
            <span id="fileNameDisplay" class="muted" style="margin-left:10px; font-size:0.8rem;"></span>
        </div>
      </div>
      <div>
        <button class="btn" type="submit" style="height:100%;">Post</button>
      </div>
    </div>
  </form>
</div>

<script>
const commentListEl = document.getElementById('commentList');
let currentCommentHtml = '';

async function loadComments() {
    try {
        const res = await fetch('/task_details.php?id=<?php echo $task_id; ?>&ajax=1');
        const html = await res.text();
        if (html !== currentCommentHtml) {
            commentListEl.innerHTML = html;
            currentCommentHtml = html;
        }
    } catch(e) {}
}
loadComments();
setInterval(loadComments, 4000);

function replyTo(id, name) {
  document.getElementById('parentInput').value = id;
  document.getElementById('replyingToName').textContent = name;
  document.getElementById('replyingToBanner').style.display = 'block';
  document.getElementById('msgInput').focus();
  document.getElementById('replyFormCard').scrollIntoView({behavior:'smooth'});
}
function cancelReply() {
  document.getElementById('parentInput').value = '';
  document.getElementById('replyingToBanner').style.display = 'none';
}
</script>

<?php render_footer(); ?>