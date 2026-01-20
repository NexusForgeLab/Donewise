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

// --- HANDLE SUBTASKS (AJAX) ---
if (isset($_POST['subtask_action'])) {
    // Clean return for AJAX
    while (ob_get_level()) ob_end_clean(); 
    
    $action = $_POST['subtask_action'];
    
    if ($action === 'add') {
        $text = trim($_POST['text'] ?? '');
        if ($text) {
            // 1. Insert Subtask
            $pdo->prepare("INSERT INTO subtasks(task_id, text) VALUES(?,?)")->execute([$task_id, $text]);
            
            // 2. NEW: Handle Mentions in Subtasks
            if (preg_match('/@([a-zA-Z0-9_]+)/', $text, $matches)) {
                $mentionedName = $matches[1];
                // Find User ID
                $uSt = $pdo->prepare("SELECT id FROM users WHERE group_id=? AND username=?");
                $uSt->execute([$user['group_id'], $mentionedName]);
                $targetId = $uSt->fetchColumn();

                if ($targetId && $targetId != $user['id'] && function_exists('send_group_notification')) {
                    send_group_notification(
                        $pdo, 
                        $user['group_id'], 
                        $user['id'], 
                        $user['display_name'], 
                        'mention', 
                        "mentioned you in a checklist item on: " . $t['text'], 
                        $task_id
                    );
                }
            }
        }
    } elseif ($action === 'toggle') {
        $subId = (int)$_POST['sub_id'];
        $val = (int)$_POST['is_done'];
        $pdo->prepare("UPDATE subtasks SET is_done=? WHERE id=? AND task_id=?")->execute([$val, $subId, $task_id]);
    } elseif ($action === 'delete') {
        $subId = (int)$_POST['sub_id'];
        $pdo->prepare("DELETE FROM subtasks WHERE id=? AND task_id=?")->execute([$subId, $task_id]);
    }

    // Render List
    $subs = $pdo->prepare("SELECT * FROM subtasks WHERE task_id=?");
    $subs->execute([$task_id]);
    foreach ($subs->fetchAll() as $s) {
        $checked = $s['is_done'] ? 'checked' : '';
        $style = $s['is_done'] ? 'text-decoration:line-through;color:#888' : '';
        
        // NEW: Apply linkify to make @mentions clickable and formatted
        $displayText = linkify(h($s['text']));

        echo "<div style='margin-bottom:8px; display:flex; align-items:center; justify-content:space-between; padding:4px 0; border-bottom:1px solid #eee;'>
                <div style='display:flex; align-items:center; flex:1;'>
                    <input type='checkbox' $checked onchange='toggleSub({$s['id']}, this.checked)' style='width:auto; margin-right:10px; height:18px; width:18px;'>
                    <span style='$style; font-size:1rem; line-height:1.4;'>$displayText</span>
                </div>
                <button onclick='deleteSub({$s['id']})' style='border:none; background:none; color:#ccc; cursor:pointer; font-size:1.2rem; padding:0 8px;'>&times;</button>
              </div>";
    }
    exit;
}

// --- HANDLE POST (Unified Upload + Comment) ---
$uploadErr = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['subtask_action'])) {
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
        
        // --- Process Mentions in Comments ---
        process_mentions($pdo, $user['group_id'], $user['id'], $user['display_name'], $msg, 'comment', $task_id);

        $action = $parent_id ? "replied on:" : "commented on:";
        if (function_exists('send_group_notification')) {
            send_group_notification($pdo, $user['group_id'], $user['id'], $user['display_name'], 'comment', "$action " . $t['text'], $task_id);
        }
    }

    if (($fileAttached || $msg !== '') && !$uploadErr) {
        header("Location: task_details.php?id=$task_id");
        exit;
    }
}

// --- AJAX COMMENT LOADER ---
if (isset($_GET['ajax'])) {
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
    
    function render_comments_ajax($nodes, $current_user_id, $depth=0) {
        foreach($nodes as $c) {
            $margin = min($depth * 24, 48);
            $isMine = ($c['user_id'] === $current_user_id);
            echo "<div class='comment' style='margin-left:{$margin}px' id='c{$c['id']}'>";
            echo "<div class='comment-meta'><span class='author'>" . h($c['display_name']) . "</span><span class='date'>" . format_time($c['created_at']) . "</span></div>";
            
            // Apply Linkify (URLs + Mentions)
            echo "<div class='comment-body'>" . nl2br(linkify(h($c['message']))) . "</div>";
            
            echo "<div class='comment-actions'><button class='btn-link' onclick=\"replyTo({$c['id']}, '".h($c['display_name'])."')\">Reply</button>";
            if ($isMine) {
                echo "<span class='sep'>&#8226;</span><form method='post' action='comment_delete.php' style='display:inline' onsubmit='return confirm(\"Delete comment?\")'><input type='hidden' name='csrf' value='".h(csrf_token())."'/><input type='hidden' name='comment_id' value='{$c['id']}'/><button class='btn-link delete-link'>Delete</button></form>";
            }
            echo "</div></div>";
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
  <h1><?php echo linkify(h($t['text'])); ?></h1>
  <div class="muted">
    Created: <?php echo format_time($t['created_at']); ?>
    <?php if($t['is_done']): ?><span class="badge" style="background:var(--muted)">Completed</span><?php endif; ?>
  </div>
</div>

<?php if($uploadErr): ?><div class="card" style="border-color:red; color:red;"><?php echo h($uploadErr); ?></div><?php endif; ?>

<div class="card">
  <h2>Checklist</h2>
  <div id="subtaskList" style="margin-bottom:15px;">
    </div>
  <form onsubmit="event.preventDefault(); addSub();" style="display:flex; gap:10px; position:relative;">
    <input id="newSub" placeholder="Add subtask... (Type @ to mention)" required autocomplete="off" style="width:100%;">
    <div class="suggest-list"></div>
    <button class="btn" style="width:auto; padding:0 20px;">+</button>
  </form>
</div>

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
          <a href="<?php echo h($att['filepath']); ?>" target="_blank" download="<?php echo h($att['filename']); ?>">
            <?php if($isImg): ?>
                <img src="<?php echo h($att['filepath']); ?>" class="attachment-preview" loading="lazy" />
            <?php else: ?>
                <div class="attachment-icon"><?php echo strtoupper(h($att['file_type'])); ?></div>
            <?php endif; ?>
          </a>
          
          <div class="attachment-meta" title="<?php echo h($att['filename']); ?>">
            <?php echo h($att['filename']); ?>
          </div>

          <?php if($canDel): ?>
            <form method="post" action="attachment_delete.php" onsubmit="return confirm('Delete file?');">
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
  
  <form method="post" enctype="multipart/form-data" class="suggest">
    <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>"/>
    <input type="hidden" name="parent_id" id="parentInput" value=""/>
    
    <div class="row" style="align-items:flex-start;">
      <div style="flex:1; position:relative;">
        <textarea name="message" placeholder="Write a comment... (Type @ to mention)" rows="2" style="min-height:50px; resize:none;" id="msgInput"></textarea>
        <div class="suggest-list"></div>
        
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
        const res = await fetch('task_details.php?id=<?php echo $task_id; ?>&ajax=1');
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

function loadSubs() {
    const fd = new FormData();
    fd.append('subtask_action', 'list');
    fetch('task_details.php?id=<?php echo $task_id; ?>', { method:'POST', body:fd })
    .then(r => r.text()).then(h => document.getElementById('subtaskList').innerHTML = h);
}
function addSub() {
    const txt = document.getElementById('newSub');
    if(!txt.value.trim()) return;
    const fd = new FormData();
    fd.append('subtask_action', 'add');
    fd.append('text', txt.value);
    fetch('task_details.php?id=<?php echo $task_id; ?>', { method:'POST', body:fd }).then(() => {
        txt.value = ''; loadSubs();
    });
}
function toggleSub(id, done) {
    const fd = new FormData();
    fd.append('subtask_action', 'toggle');
    fd.append('sub_id', id);
    fd.append('is_done', done ? 1 : 0);
    fetch('task_details.php?id=<?php echo $task_id; ?>', { method:'POST', body:fd }).then(loadSubs);
}
function deleteSub(id) {
    if(!confirm('Delete item?')) return;
    const fd = new FormData();
    fd.append('subtask_action', 'delete');
    fd.append('sub_id', id);
    fetch('task_details.php?id=<?php echo $task_id; ?>', { method:'POST', body:fd }).then(loadSubs);
}
loadSubs();

// ENABLE AUTOCOMPLETE FOR COMMENTS & CHECKLIST
window.addEventListener('load', function(){
    if(typeof attachSuggest === 'function') {
        attachSuggest('msgInput');
        attachSuggest('newSub'); // NEW: Attach to subtask input
    }
});
</script>

<?php render_footer(); ?>