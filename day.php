<?php
require_once __DIR__ . '/app/layout.php';
require_once __DIR__ . '/app/tag_logic.php';
$user = require_login();
$pdo  = db();

// --- 1. SETUP & INPUT HANDLING ---
$date = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { $date = date('Y-m-d'); }

// Ensure day exists
$st = $pdo->prepare("INSERT OR IGNORE INTO days(group_id, day_date) VALUES(?, ?)");
$st->execute([$user['group_id'], $date]);
$st = $pdo->prepare("SELECT id FROM days WHERE group_id=? AND day_date=?");
$st->execute([$user['group_id'], $date]);
$dayId = (int)$st->fetchColumn();

// --- HELPER: Parse Assignments ---
function get_assignee_from_text($pdo, $groupId, $text) {
    if (preg_match('/@([a-zA-Z0-9_]+)/', $text, $matches)) {
        $username = $matches[1];
        $st = $pdo->prepare("SELECT id FROM users WHERE group_id=? AND username=?");
        $st->execute([$groupId, $username]);
        return $st->fetchColumn() ?: null;
    }
    return null;
}

// HANDLE ADD TASK
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['text']) || isset($_FILES['task_image'])) && !isset($_POST['edit_mode'])) {
  csrf_check();
  $text = trim($_POST['text'] ?? '');
  $hasFile = isset($_FILES['task_image']) && $_FILES['task_image']['error'] === UPLOAD_ERR_OK;
  if ($text === '' && $hasFile) $text = "Image Attachment";

  if ($text !== '') {
    $norm = normalize_text($text);
    $assignToId = get_assignee_from_text($pdo, $user['group_id'], $text);

    $st = $pdo->prepare("INSERT INTO tasks(group_id, day_id, text, text_norm, created_by, assigned_to) VALUES(?,?,?,?,?,?)");
    $st->execute([$user['group_id'], $dayId, $text, $norm, $user['id'], $assignToId]);
    $taskId = (int)$pdo->lastInsertId();
    
    $cleanText = parse_and_save_tags($pdo, (int)$user['group_id'], $taskId, $text);
    
    // Update History
    $pdo->prepare("INSERT INTO item_history(group_id, text, text_norm, use_count, last_used_at) VALUES(?,?,?,?,datetime('now')) ON CONFLICT(group_id, text_norm) DO UPDATE SET use_count=use_count+1, last_used_at=datetime('now')")->execute([$user['group_id'], $cleanText, $norm, 1]);

    if ($hasFile) {
        $f = $_FILES['task_image'];
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg','jpeg','png','gif','webp'])) {
            $dest = "uploads/{$taskId}_" . time() . "_" . bin2hex(random_bytes(4)) . ".$ext";
            if (move_uploaded_file($f['tmp_name'], __DIR__ . "/$dest")) {
                $pdo->prepare("INSERT INTO attachments(group_id, task_id, user_id, filename, filepath, file_type) VALUES(?,?,?,?,?,?)")->execute([$user['group_id'], $taskId, $user['id'], $f['name'], $dest, $ext]);
            }
        }
    }

    if(function_exists('send_group_notification')) {
        $msg = $hasFile ? "added task with image: $cleanText" : "added: $cleanText";
        send_group_notification($pdo, $user['group_id'], $user['id'], $user['display_name'], 'task_add', $msg, $taskId);
        if ($assignToId && $assignToId != $user['id']) {
            send_group_notification($pdo, $user['group_id'], $user['id'], $user['display_name'], 'assign', "assigned you: $cleanText", $taskId);
        }
    }
    
    header('Location: day.php?date=' . $date); exit;
  }
}

// --- 2. FETCH DATA & FILTERS ---
$filterTag = $_GET['tag'] ?? 'all';
$filterUser = $_GET['user'] ?? 'all';

// Fetch Tags
$st = $pdo->prepare("SELECT * FROM tags WHERE group_id=? ORDER BY sort_order ASC, name ASC");
$st->execute([$user['group_id']]);
$tags = $st->fetchAll();

// Map Tag Names to Colors/IDs for fast lookup
$tagMap = [];
foreach ($tags as $t) {
    $tagMap[mb_strtolower($t['name'])] = $t;
}

// Fetch Users (For Filters)
$st = $pdo->prepare("SELECT id, username, display_name FROM users WHERE group_id=? ORDER BY display_name ASC");
$st->execute([$user['group_id']]);
$groupUsers = $st->fetchAll();

// Initialize Sections
$sections = [];
foreach ($tags as $tag) { $sections[$tag['id']] = ['meta' => $tag, 'tasks' => []]; }
$sections['untagged'] = ['meta' => ['id'=>'untagged', 'name'=>'Untagged', 'color'=>'#777'], 'tasks' => []];

// Build Query
$sql = "
  SELECT 
    t.*, 
    u.display_name AS created_name, 
    du.display_name AS done_name,
    au.display_name AS assigned_name, 
    au.username AS assigned_username,
    (SELECT COUNT(*) FROM comments c WHERE c.task_id = t.id) AS comment_count,
    (SELECT filepath FROM attachments WHERE task_id = t.id AND file_type IN ('jpg','jpeg','png','gif','webp') ORDER BY created_at ASC LIMIT 1) as task_image,
    GROUP_CONCAT(tg.id) as tag_ids
  FROM tasks t
  JOIN users u ON u.id = t.created_by
  LEFT JOIN users du ON du.id = t.done_by
  LEFT JOIN users au ON au.id = t.assigned_to
  LEFT JOIN task_tags tt ON tt.task_id = t.id
  LEFT JOIN tags tg ON tg.id = tt.tag_id
  WHERE t.day_id = ?
";
$params = [$dayId];

// Apply User Filter
if (is_numeric($filterUser)) {
    $sql .= " AND t.assigned_to = ?";
    $params[] = $filterUser;
}

$sql .= " GROUP BY t.id ORDER BY t.is_done ASC, t.sort_order ASC, t.id DESC";

$st = $pdo->prepare($sql);
$st->execute($params);
$allTasks = $st->fetchAll();

// --- STATS CALCULATION ---
$statsUserId = is_numeric($filterUser) ? $filterUser : $user['id'];
$statsName = ($statsUserId == $user['id']) ? "Your" : "User";
foreach ($groupUsers as $gu) { if($gu['id'] == $statsUserId) $statsName = $gu['display_name']; }

$stStat = $pdo->prepare("SELECT COUNT(*) as total, SUM(CASE WHEN is_done=1 THEN 1 ELSE 0 END) as done FROM tasks WHERE day_id=? AND assigned_to=?");
$stStat->execute([$dayId, $statsUserId]);
$userStats = $stStat->fetch();
$progTotal = $userStats['total'] ?: 0;
$progDone = $userStats['done'] ?: 0;
$progPercent = ($progTotal > 0) ? round(($progDone / $progTotal) * 100) : 0;


// Distribute Tasks to Sections
foreach ($allTasks as $t) {
    // Check Filter Tag
    if (is_numeric($filterTag)) {
        $tTags = explode(',', $t['tag_ids'] ?? '');
        if (!in_array($filterTag, $tTags)) continue;
    } elseif ($filterTag === 'untagged' && !empty($t['tag_ids'])) {
        continue;
    }

    // --- NEW LOGIC: Determine Section by Text Order ---
    // 1. Parse tags from text to get order
    preg_match_all('/#(\w+)/u', $t['text'], $matches);
    $tagsInOrder = $matches[1] ?? [];
    
    $targetSectionId = 'untagged';
    $isUrgent = false;

    // 2. Priority 1: Check for Urgent anywhere
    foreach ($tagsInOrder as $tagName) {
        if (strcasecmp($tagName, 'urgent') === 0) {
            $isUrgent = true;
            break;
        }
    }

    if ($isUrgent) {
        // Find Urgent ID
        if (isset($tagMap['urgent'])) {
            $targetSectionId = $tagMap['urgent']['id'];
        }
    } else {
        // 3. Priority 2: Use First Tag
        if (!empty($tagsInOrder)) {
            $firstTagName = mb_strtolower($tagsInOrder[0]);
            if (isset($tagMap[$firstTagName])) {
                $targetSectionId = $tagMap[$firstTagName]['id'];
            }
        }
    }

    // Assign
    if (isset($sections[$targetSectionId])) {
        $sections[$targetSectionId]['tasks'][] = $t;
    } else {
        $sections['untagged']['tasks'][] = $t;
    }
}

render_header("Donewise - $date", $user);
?>

<div class="card" style="padding-bottom:10px;">
  <div style="display:flex; justify-content:space-between; align-items:center;">
    <h1><?php echo h($date); ?></h1>
    <a href="day.php?date=<?php echo date('Y-m-d'); ?>" class="btn" style="font-size:0.8rem">Today</a>
  </div>

  <div style="background:#f4f4f4; border-radius:8px; padding:10px; margin-top:10px; display:flex; align-items:center; gap:12px;">
    <div style="font-weight:bold; font-size:0.9rem; color:#555; white-space:nowrap;">
        <?php echo h($statsName); ?>'s Progress: <?php echo "$progDone / $progTotal"; ?>
    </div>
    <div style="flex:1; background:#ddd; height:8px; border-radius:4px; overflow:hidden;">
        <div style="width:<?php echo $progPercent; ?>%; background:var(--accent-blue); height:100%; transition:width 0.3s;"></div>
    </div>
    <div style="font-size:0.8rem; color:#777;"><?php echo $progPercent; ?>%</div>
  </div>

  <div class="filter-bar" style="margin-top:15px;">
    <a href="?date=<?php echo $date; ?>&user=<?php echo $filterUser; ?>&tag=all" class="filter-chip <?php echo ($filterTag=='all')?'active':''; ?>">All Tags</a>
    <?php foreach($tags as $tag): ?>
        <a href="?date=<?php echo $date; ?>&user=<?php echo $filterUser; ?>&tag=<?php echo $tag['id']; ?>" class="filter-chip <?php echo ($filterTag==$tag['id'])?'active':''; ?>">#<?php echo h($tag['name']); ?></a>
    <?php endforeach; ?>
    
    <div style="width:1px; background:#ccc; margin:0 8px;"></div>
    
    <a href="?date=<?php echo $date; ?>&tag=<?php echo $filterTag; ?>&user=all" class="filter-chip <?php echo ($filterUser=='all')?'active':''; ?>">All Users</a>
    <?php foreach($groupUsers as $gu): ?>
        <a href="?date=<?php echo $date; ?>&tag=<?php echo $filterTag; ?>&user=<?php echo $gu['id']; ?>" class="filter-chip <?php echo ($filterUser==$gu['id'])?'active':''; ?>" style="--tag-color:var(--accent-blue);">
           @<?php echo h($gu['username']); ?>
        </a>
    <?php endforeach; ?>
  </div>

  <form method="post" class="suggest" style="margin-top:16px;" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>"/>
    <div class="row" style="align-items:center">
      <div style="flex:1;min-width:200px; display:flex;">
        <label class="upload-btn" title="Attach Image" id="uploadLbl">📷
            <input type="file" name="task_image" accept="image/*" style="display:none" onchange="document.getElementById('uploadLbl').classList.add('has-file');">
        </label>
        <div style="flex:1; position:relative;">
            <input id="taskInput" name="text" placeholder="e.g., Review report @<?php echo h($user['username']); ?> #urgent" autocomplete="off" />
            <div class="suggest-list"></div>
        </div>
      </div>
      <button class="btn" type="submit">Add</button>
      <div style="display:flex; gap:5px;">
        <a class="btn" href="day.php?date=<?php echo date('Y-m-d', strtotime($date . ' -1 day')); ?>">&#8592;</a>
        <a class="btn" href="day.php?date=<?php echo date('Y-m-d', strtotime($date . ' +1 day')); ?>">&#8594;</a>
      </div>
    </div>
  </form>
</div>

<div id="sectionsContainer">
<?php foreach ($sections as $tagId => $sec): ?>
    <?php 
    $isUrgent = (isset($sec['meta']['name']) && strtolower($sec['meta']['name']) === 'urgent');
    // Hide empty sections (except untagged if strictly empty logic desired, or urgent if preferred)
    if (empty($sec['tasks']) && !$isUrgent && $tagId !== 'untagged') continue; 
    if ($tagId === 'untagged' && empty($sec['tasks'])) continue;
    
    $accentColor = $sec['meta']['color'] ?? '#ccc';
    ?>

    <div class="section-wrapper" data-tag-id="<?php echo $tagId; ?>">
        <div class="section-header" style="border-left: 6px solid <?php echo $accentColor; ?>">
            <span><?php echo h(ucfirst($sec['meta']['name'])); ?> <span class="badge" style="background:#fff; border:1px solid #ccc; color:#555"><?php echo count($sec['tasks']); ?></span></span>
            <span style="color:#ccc; font-size:1.2rem;">&#9776;</span>
        </div>

        <div class="section-body" id="list-<?php echo $tagId; ?>">
            <?php foreach ($sec['tasks'] as $t): ?>
                <?php $isDone = ((int)$t['is_done'] === 1); ?>
                <div class="task <?php echo $isDone ? 'done' : ''; ?>" data-id="<?php echo $t['id']; ?>">
                  
                  <div style="display:flex; align-items:flex-start; width:100%">
                      <div class="handle" style="cursor:grab; margin-right:12px; color:#ddd; font-size:1.2rem; padding-top:2px;">&#8942;</div>
                      
                      <?php if(!empty($t['task_image'])): ?>
                        <a href="<?php echo h($t['task_image']); ?>" target="_blank"><img src="<?php echo h($t['task_image']); ?>" class="task-thumb" /></a>
                      <?php endif; ?>

                      <div style="flex-grow:1;">
                        <div class="text">
                            <?php 
                            $displayText = h($t['text']);
                            $displayText = preg_replace('/#(\w+)/u', '', $displayText); 
                            echo linkify(trim($displayText)); 
                            ?>
                            
                            <span class="tags-container" style="margin-left:8px;">
                            <?php 
                            // Extract tags specifically from text for rendering
                            preg_match_all('/#(\w+)/u', $t['text'], $matches);
                            $tagsToRender = array_unique($matches[1] ?? []);
                            
                            foreach($tagsToRender as $tagName) {
                                $lowerName = mb_strtolower($tagName);
                                if (isset($tagMap[$lowerName])) {
                                    $tg = $tagMap[$lowerName];
                                    echo "<span class='tag-pill' style='color:{$tg['color']}; border:1px solid {$tg['color']}; margin-right:4px;'>#".h($tg['name'])."</span>";
                                } else {
                                    // Fallback for tags in text but not in DB yet (edge case)
                                    echo "<span class='tag-pill' style='color:#777; border:1px solid #ccc; margin-right:4px;'>#".h($tagName)."</span>";
                                }
                            }
                            ?>
                            </span>
                        </div>
                        <div class="muted" style="font-size:0.8rem; display:flex; align-items:center; gap:8px;">
                            <span><?php echo h($t['created_name']); ?></span>
                            <?php if ($isDone): ?> <span>• done by <?php echo h($t['done_name']); ?></span><?php endif; ?>
                            
                            <?php if (!empty($t['assigned_name'])): ?>
                                <span class="pill" style="background:#e3f2fd; color:#0d47a1; border-color:#90caf9;">
                                    ➜ <?php echo h($t['assigned_name']); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                      </div>
                  </div>

                  <div style="display:flex; justify-content:flex-end; gap:5px; margin-top:5px; width:100%; padding-left:30px;">
                    <a class="btn" style="padding:6px 10px; font-size:0.8rem" href="task_details.php?id=<?php echo $t['id']; ?>">💬 <?php if($t['comment_count']>0) echo $t['comment_count']; ?></a>
                    <?php if (!$isDone): ?>
                      <form method="post" action="task_done.php" style="display:inline"><input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>"/><input type="hidden" name="task_id" value="<?php echo $t['id']; ?>"/><button class="btn" style="padding:6px 10px; font-size:0.8rem">✔</button></form>
                    <?php else: ?>
                      <form method="post" action="task_undo.php" style="display:inline"><input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>"/><input type="hidden" name="task_id" value="<?php echo $t['id']; ?>"/><button class="btn" style="padding:6px 10px; font-size:0.8rem">↺</button></form>
                    <?php endif; ?>
                    <button class="btn" style="padding:6px 10px; font-size:0.8rem" onclick="openEditModal(<?php echo $t['id']; ?>, '<?php echo addslashes(h($t['text'])); ?>', '<?php echo $date; ?>')">✏</button>
                    <form method="post" action="task_delete.php" style="display:inline" onsubmit="return confirm('Delete?');"><input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>"/><input type="hidden" name="task_id" value="<?php echo $t['id']; ?>"/><button class="btn" style="padding:6px 10px; font-size:0.8rem; color:red;">🗑</button></form>
                  </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endforeach; ?>
</div>

<div id="editModal" class="modal-overlay">
  <div class="modal-content">
    <div class="modal-header">Edit Item</div>
    <form action="task_edit.php" method="post" name="editForm" id="editForm">
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
        <div class="modal-actions" style="justify-content:space-between; gap:10px;">
            <button type="button" class="btn" style="background:transparent; border:none; color:#666;" onclick="closeEditModal()">Cancel</button>
            <button type="button" class="btn" style="background:#e3f2fd; color:#0d47a1;" onclick="moveTaskToday()">Move Today</button>
            <button type="submit" class="btn">Save</button>
        </div>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>
<script>
window.addEventListener('load',function(){
    if(typeof attachSuggest==='function'){attachSuggest('taskInput');attachSuggest('editText');}

    // Sort Sections
    Sortable.create(document.getElementById('sectionsContainer'), {
        animation: 150, handle: '.section-header',
        onEnd: function() {
            let tagIds = [];
            document.querySelectorAll('.section-wrapper').forEach(div => tagIds.push(div.getAttribute('data-tag-id')));
            fetch('api/reorder_tags.php', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({ids: tagIds}) });
        }
    });

    // Sort/Move Tasks
    document.querySelectorAll('.section-body').forEach(el => {
        Sortable.create(el, {
            group: 'tasks', animation: 150, handle: '.handle',
            onEnd: function(evt) {
                // 1. Handle Reorder within list
                let ids = [];
                evt.to.querySelectorAll('.task').forEach(div => ids.push(div.getAttribute('data-id')));
                fetch('api/reorder.php', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({ids: ids}) });

                // 2. Handle Section Change (Tag Reorder)
                if (evt.from !== evt.to) {
                    const newSection = evt.to.closest('.section-wrapper');
                    
                    const newTagId = newSection ? newSection.getAttribute('data-tag-id') : 'untagged';
                    const taskId = evt.item.getAttribute('data-id');

                    // Call API to rewrite text and assign new tag as primary
                    fetch('api/assign_tag.php', { 
                        method: 'POST', 
                        headers: {'Content-Type': 'application/json'}, 
                        body: JSON.stringify({ 
                            task_id: taskId, 
                            tag_id: newTagId
                        }) 
                    }).then(() => {
                        window.location.reload(); 
                    });
                }
            }
        });
    });
});

const modal=document.getElementById('editModal'),editTaskId=document.getElementById('editTaskId'),editText=document.getElementById('editText'),editDate=document.getElementById('editDate');
function openEditModal(id,text,date){editTaskId.value=id;editText.value=text;editDate.value=date;modal.style.display='flex';}
function closeEditModal(){modal.style.display='none';}
function moveTaskToday() {
    const now = new Date();
    const offset = now.getTimezoneOffset();
    const localDate = new Date(now.getTime() - (offset*60*1000));
    editDate.value = localDate.toISOString().split('T')[0];
    document.getElementById('editForm').submit();
}
modal.addEventListener('click',e=>{if(e.target===modal)closeEditModal();});
</script>
<?php render_footer(); ?>