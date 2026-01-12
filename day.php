<?php
require_once __DIR__ . '/app/layout.php';
require_once __DIR__ . '/app/tag_logic.php';
$user = require_login();
$pdo  = db();

$date = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { $date = date('Y-m-d'); }

$filterTag = $_GET['tag'] ?? 'all';

// Ensure day exists
$st = $pdo->prepare("INSERT OR IGNORE INTO days(group_id, day_date) VALUES(?, ?)");
$st->execute([$user['group_id'], $date]);
$st = $pdo->prepare("SELECT id FROM days WHERE group_id=? AND day_date=?");
$st->execute([$user['group_id'], $date]);
$dayId = (int)$st->fetchColumn();

// --- HANDLE ADD TASK (TEXT + IMAGE) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['text']) || isset($_FILES['task_image'])) && !isset($_POST['edit_mode'])) {
  csrf_check();
  $text = trim($_POST['text'] ?? '');
  
  // Handle File Upload Check
  $hasFile = isset($_FILES['task_image']) && $_FILES['task_image']['error'] === UPLOAD_ERR_OK;
  
  // If text is empty but we have a file, give it a default name
  if ($text === '' && $hasFile) {
      $text = "Image Attachment";
  }

  if ($text !== '') {
    $norm = normalize_text($text);

    // 1. Create Task (Basic logic kept, but removed strict duplicate merge for file uploads to simplify)
    $st = $pdo->prepare("INSERT INTO tasks(group_id, day_id, text, text_norm, created_by) VALUES(?,?,?,?,?)");
    $st->execute([$user['group_id'], $dayId, $text, $norm, $user['id']]);
    $taskId = (int)$pdo->lastInsertId();

    $cleanText = parse_and_save_tags($pdo, (int)$user['group_id'], $taskId, $text);
    
    // Update History
    $pdo->prepare("INSERT INTO item_history(group_id, text, text_norm, use_count, last_used_at) VALUES(?,?,?,?,datetime('now')) ON CONFLICT(group_id, text_norm) DO UPDATE SET use_count=use_count+1, last_used_at=datetime('now')")->execute([$user['group_id'], $cleanText, $norm, 1]);
    
    // 2. Handle Image Upload
    if ($hasFile) {
        $f = $_FILES['task_image'];
        $allowed = ['jpg','jpeg','png','gif','webp'];
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        
        if (in_array($ext, $allowed)) {
            $uploadDir = 'uploads';
            if (!is_dir(__DIR__ . "/$uploadDir")) {
                mkdir(__DIR__ . "/$uploadDir", 0775, true);
            }
            // Generate unique name: taskID_timestamp_random
            $newName = "{$taskId}_" . time() . "_" . bin2hex(random_bytes(4)) . ".$ext";
            $destPath = "$uploadDir/$newName";
            
            if (move_uploaded_file($f['tmp_name'], __DIR__ . "/$destPath")) {
                $st = $pdo->prepare("INSERT INTO attachments(group_id, task_id, user_id, filename, filepath, file_type) VALUES(?,?,?,?,?,?)");
                $st->execute([$user['group_id'], $taskId, $user['id'], $f['name'], $destPath, $ext]);
            }
        }
    }

    if(function_exists('send_group_notification')) {
        $msg = $hasFile ? "added task with image: $cleanText" : "added: $cleanText";
        send_group_notification($pdo, $user['group_id'], $user['id'], $user['display_name'], 'task_add', $msg, $taskId);
    }
    
    header('Location: /day.php?date=' . $date);
    exit;
  }
}

// --- FETCH DATA ---
$st = $pdo->prepare("SELECT * FROM tags WHERE group_id=? ORDER BY name ASC");
$st->execute([$user['group_id']]);
$allTags = $st->fetchAll();

// Added subquery to fetch the first image attachment
$sql = "
  SELECT 
    t.*, 
    u.display_name AS created_name, 
    du.display_name AS done_name,
    (SELECT COUNT(*) FROM comments c WHERE c.task_id = t.id) AS comment_count,
    (SELECT filepath FROM attachments WHERE task_id = t.id AND file_type IN ('jpg','jpeg','png','gif','webp') ORDER BY created_at ASC LIMIT 1) as task_image,
    GROUP_CONCAT(tg.id || ':' || tg.name || ':' || tg.color) as tag_info
  FROM tasks t
  JOIN users u ON u.id = t.created_by
  LEFT JOIN users du ON du.id = t.done_by
  LEFT JOIN task_tags tt ON tt.task_id = t.id
  LEFT JOIN tags tg ON tg.id = tt.tag_id
  WHERE t.day_id = ?
";
$params = [$dayId];

if ($filterTag === 'untagged') {
    $sql .= " AND t.id NOT IN (SELECT task_id FROM task_tags)";
} elseif (is_numeric($filterTag)) {
    $sql .= " AND t.id IN (SELECT task_id FROM task_tags WHERE tag_id = ?)";
    $params[] = $filterTag;
}

$sql .= " GROUP BY t.id ORDER BY t.is_done ASC, t.sort_order ASC, t.id DESC";

$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();

render_header("Donewise - $date", $user);
?>

<style>
.btn-ghost { background: transparent; border-color: transparent; color: var(--muted); box-shadow: none; }
.btn-ghost:hover { background: transparent !important; color: var(--accent-blue) !important; border-color: transparent !important; text-decoration: underline; }
.upload-btn { cursor:pointer; display:flex; align-items:center; justify-content:center; font-size:1.2rem; padding:0 12px; border:2px dashed var(--border-color); border-radius:6px; background:white; height:44px; margin-right:8px; transition:0.2s; }
.upload-btn:hover, .upload-btn.has-file { border-color:var(--accent-blue); background:#f0f8ff; color:var(--accent-blue); }
.task-thumb { width:50px; height:50px; object-fit:cover; border-radius:6px; margin-right:12px; border:1px solid #ccc; flex-shrink:0; background:#eee; }
</style>

<div class="card" style="padding-bottom:10px;">
  <div style="display:flex; justify-content:space-between; align-items:center;">
    <h1><?php echo h($date); ?></h1>
    <a href="/day.php?date=<?php echo date('Y-m-d'); ?>" class="btn" style="font-size:0.8rem">Today</a>
  </div>

  <div class="filter-bar">
    <a href="?date=<?php echo $date; ?>&tag=all" class="filter-chip <?php echo ($filterTag=='all')?'active':''; ?>">All</a>
    <a href="?date=<?php echo $date; ?>&tag=untagged" class="filter-chip <?php echo ($filterTag=='untagged')?'active':''; ?>">Untagged</a>
    <?php foreach($allTags as $tag): ?>
        <a href="?date=<?php echo $date; ?>&tag=<?php echo $tag['id']; ?>" class="filter-chip <?php echo ($filterTag==$tag['id'])?'active':''; ?>" style="--tag-color:<?php echo $tag['color']; ?>">#<?php echo h($tag['name']); ?></a>
    <?php endforeach; ?>
  </div>

  <form method="post" class="suggest" style="margin-top:16px;" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>"/>
    <div class="row" style="align-items:center">
      <div style="flex:1;min-width:200px; display:flex;">
        <label class="upload-btn" title="Attach Image" id="uploadLbl">
            📷
            <input type="file" name="task_image" id="taskImgInput" accept="image/*" style="display:none" onchange="document.getElementById('uploadLbl').classList.add('has-file');">
        </label>
        <div style="flex:1; position:relative;">
            <input id="taskInput" name="text" placeholder="e.g., Milk #food" autocomplete="off" />
            <div class="suggest-list"></div>
        </div>
      </div>
      <button class="btn" type="submit">Add</button>
      <div style="display:flex; gap:5px;">
        <a class="btn" href="/day.php?date=<?php echo date('Y-m-d', strtotime($date . ' -1 day')); ?>">&#8592;</a>
        <a class="btn" href="/day.php?date=<?php echo date('Y-m-d', strtotime($date . ' +1 day')); ?>">&#8594;</a>
      </div>
    </div>
  </form>
</div>

<div class="card">
  <h2>Items</h2>
  <?php if (!count($rows)): ?><div class="muted">No items found.</div><?php endif; ?>
  <div id="taskList">
  <?php foreach ($rows as $t): ?>
    <?php 
        $isDone = ((int)$t['is_done'] === 1); 
        $myTags = [];
        if ($t['tag_info']) {
            $raw = explode(',', $t['tag_info']);
            foreach($raw as $r) {
                $parts = explode(':', $r);
                if(count($parts)>=3) $myTags[] = ['id'=>$parts[0], 'name'=>$parts[1], 'color'=>$parts[2]];
            }
        }
    ?>
    <div class="task <?php echo $isDone ? 'done' : ''; ?>" data-id="<?php echo $t['id']; ?>">
      <div style="cursor:grab; margin-right:6px; color:#ccc; font-size:1.2rem;">&#9776;</div>
      
      <?php if(!empty($t['task_image'])): ?>
        <a href="/<?php echo h($t['task_image']); ?>" target="_blank">
            <img src="/<?php echo h($t['task_image']); ?>" class="task-thumb" loading="lazy" alt="Task Image" />
        </a>
      <?php endif; ?>

      <div style="flex-grow:1;">
        <div class="text">
            <?php 
            $displayText = h($t['text']);
            $displayText = preg_replace('/#(\w+)/u', '', $displayText); 
            echo trim($displayText) ?: h($t['text']); 
            ?>
            <span class="tags-container">
                <?php if(empty($myTags)): ?><span class="tag-pill untagged">Untagged</span><?php else: ?>
                    <?php foreach($myTags as $mt): ?>
                        <span class="tag-pill" style="background-color:<?php echo $mt['color']; ?>20; color:<?php echo $mt['color']; ?>; border-color:<?php echo $mt['color']; ?>;">#<?php echo h($mt['name']); ?></span>
                    <?php endforeach; ?>
                <?php endif; ?>
            </span>
        </div>
        <div class="muted"><?php echo h($t['created_name']); ?><?php if ($isDone): ?> &#8226; done by <?php echo h($t['done_name']); ?><?php endif; ?></div>
      </div>
      <div style="display:flex; align-items:center; gap:4px; flex-wrap:wrap">
        <a class="btn" style="padding:10px 14px;" href="/task_details.php?id=<?php echo $t['id']; ?>"><span>&#128172;</span><?php if($t['comment_count']>0) echo " <b>{$t['comment_count']}</b>"; ?></a>
        <?php if (!$isDone): ?>
          <form method="post" action="/task_done.php" style="display:inline"><input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>"/><input type="hidden" name="task_id" value="<?php echo $t['id']; ?>"/><button class="btn" type="submit">Done</button></form>
        <?php else: ?>
          <form method="post" action="/task_undo.php" style="display:inline"><input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>"/><input type="hidden" name="task_id" value="<?php echo $t['id']; ?>"/><button class="btn" type="submit">Undo</button></form>
        <?php endif; ?>
        <button class="btn" style="padding:10px 14px;" onclick="openEditModal(<?php echo $t['id']; ?>, '<?php echo addslashes(h($t['text'])); ?>', '<?php echo $date; ?>')">&#9998;</button>
        <form method="post" action="/task_delete.php" style="display:inline" onsubmit="return confirm('Delete?');"><input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>"/><input type="hidden" name="task_id" value="<?php echo $t['id']; ?>"/><button class="btn" style="padding:10px;" title="Delete">&#128465;</button></form>
      </div>
    </div>
  <?php endforeach; ?>
  </div>
</div>

<div id="editModal" class="modal-overlay">
  <div class="modal-content">
    <div class="modal-header">Edit Item</div>
    <form action="/task_edit.php" method="post">
        <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>"/>
        <input type="hidden" name="task_id" id="editTaskId" />
        <div style="margin-bottom:15px;" class="suggest">
            <div class="muted">Description</div>
            <textarea name="text" id="editText" required style="height:80px;"></textarea>
            <div class="suggest-list"></div>
        </div>
        <div style="margin-bottom:15px;">
            <div class="muted">Date</div>
            <input type="date" name="new_date" id="editDate" required />
        </div>
        <div class="modal-actions">
            <button type="button" class="btn btn-ghost" onclick="closeEditModal()">Cancel</button>
            <button type="submit" class="btn">Save Changes</button>
        </div>
    </form>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>
<script>
window.addEventListener('load',function(){if(typeof attachSuggest==='function'){attachSuggest('taskInput');attachSuggest('editText');}});
const modal=document.getElementById('editModal'),editTaskId=document.getElementById('editTaskId'),editText=document.getElementById('editText'),editDate=document.getElementById('editDate');
function openEditModal(id,text,date){editTaskId.value=id;editText.value=text;editDate.value=date;modal.style.display='flex';}
function closeEditModal(){modal.style.display='none';}
modal.addEventListener('click',e=>{if(e.target===modal)closeEditModal();});
if(document.getElementById('taskList')){Sortable.create(document.getElementById('taskList'),{animation:150,handle:'.task > div:first-child',onEnd:function(){let ids=[];document.querySelectorAll('.task').forEach(div=>ids.push(div.getAttribute('data-id')));fetch('/api/reorder.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({ids:ids})});}});}
</script>
<?php render_footer(); ?>