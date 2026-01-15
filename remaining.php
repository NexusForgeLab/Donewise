<?php
require_once __DIR__ . '/app/layout.php';
$user = require_login();
$pdo  = db();

// --- 1. FETCH METADATA ---
// Fetch Tags
$st = $pdo->prepare("SELECT * FROM tags WHERE group_id=? ORDER BY sort_order ASC, name ASC");
$st->execute([$user['group_id']]);
$tags = $st->fetchAll();

// Fetch Users (For Filters)
$st = $pdo->prepare("SELECT id, username, display_name FROM users WHERE group_id=? ORDER BY display_name ASC");
$st->execute([$user['group_id']]);
$groupUsers = $st->fetchAll();

// Initialize Sections
$sections = [];
foreach ($tags as $tag) { $sections[$tag['id']] = ['meta' => $tag, 'tasks' => []]; }
$sections['untagged'] = ['meta' => ['id'=>'untagged', 'name'=>'Untagged', 'color'=>'#777'], 'tasks' => []];

// --- 2. BUILD QUERY ---
$filterTag = $_GET['tag'] ?? 'all';
$filterUser = $_GET['user'] ?? 'all';

$sql = "
  SELECT
    t.*,
    COALESCE(d.day_date, 'No Date') as day_date,
    au.display_name AS assigned_name,
    (SELECT COUNT(*) FROM comments c WHERE c.task_id = t.id) AS comment_count,
    (SELECT filepath FROM attachments WHERE task_id = t.id AND file_type IN ('jpg','jpeg','png','gif','webp') ORDER BY created_at ASC LIMIT 1) as task_image,
    GROUP_CONCAT(tg.id) as tag_ids
  FROM tasks t
  LEFT JOIN days d ON d.id = t.day_id 
  LEFT JOIN users au ON au.id = t.assigned_to
  LEFT JOIN task_tags tt ON tt.task_id = t.id
  LEFT JOIN tags tg ON tg.id = tt.tag_id
  WHERE t.group_id = ? AND t.is_done = 0
";

$params = [$user['group_id']];

if (is_numeric($filterUser)) {
    $sql .= " AND t.assigned_to = ?";
    $params[] = $filterUser;
}

$sql .= " GROUP BY t.id ORDER BY t.sort_order ASC, d.day_date ASC, t.id DESC";

$st = $pdo->prepare($sql);
$st->execute($params);
$allTasks = $st->fetchAll();

// --- STATS CALCULATION (Pending) ---
$statsUserId = is_numeric($filterUser) ? $filterUser : $user['id'];
$statsName = ($statsUserId == $user['id']) ? "Your" : "User";
foreach ($groupUsers as $gu) { if($gu['id'] == $statsUserId) $statsName = $gu['display_name']; }

// Count pending tasks for this user (globally)
$stStat = $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE group_id=? AND assigned_to=? AND is_done=0");
$stStat->execute([$user['group_id'], $statsUserId]);
$pendingCount = $stStat->fetchColumn();

// Distribute
foreach ($allTasks as $t) {
    // PHP Filter for tags to allow mixed filtering
    if (is_numeric($filterTag)) {
        $tTags = explode(',', $t['tag_ids'] ?? '');
        if (!in_array($filterTag, $tTags)) continue;
    } elseif ($filterTag === 'untagged' && !empty($t['tag_ids'])) {
        continue;
    }

    $assigned = false;
    if ($t['tag_ids']) {
        $taskTagIds = explode(',', $t['tag_ids']);
        foreach ($sections as $tagId => $sec) {
            if ($tagId === 'untagged') continue; 
            if (in_array($tagId, $taskTagIds)) {
                $sections[$tagId]['tasks'][] = $t;
                $assigned = true;
                break;
            }
        }
    }
    if (!$assigned) { $sections['untagged']['tasks'][] = $t; }
}

render_header('Remaining', $user);
?>

<div class="card" style="padding-bottom:10px;">
  <h1>Remaining</h1>
  <div class="muted">All unfinished items.</div>

  <div style="background:#e3f2fd; color:#0d47a1; border-radius:8px; padding:10px; margin-top:10px; border:1px solid #90caf9;">
      <b><?php echo h($statsName); ?>'s Backlog:</b> <?php echo $pendingCount; ?> tasks pending.
  </div>

  <div class="filter-bar" style="margin-top:15px;">
    <a href="?user=<?php echo $filterUser; ?>&tag=all" class="filter-chip <?php echo ($filterTag=='all')?'active':''; ?>">All Tags</a>
    <a href="?user=<?php echo $filterUser; ?>&tag=untagged" class="filter-chip <?php echo ($filterTag=='untagged')?'active':''; ?>">Untagged</a>
    <?php foreach($tags as $tag): ?>
        <a href="?user=<?php echo $filterUser; ?>&tag=<?php echo $tag['id']; ?>" class="filter-chip <?php echo ($filterTag==$tag['id'])?'active':''; ?>">#<?php echo h($tag['name']); ?></a>
    <?php endforeach; ?>
    
    <div style="width:1px; background:#ccc; margin:0 8px;"></div>
    
    <a href="?tag=<?php echo $filterTag; ?>&user=all" class="filter-chip <?php echo ($filterUser=='all')?'active':''; ?>">All Users</a>
    <?php foreach($groupUsers as $gu): ?>
        <a href="?tag=<?php echo $filterTag; ?>&user=<?php echo $gu['id']; ?>" class="filter-chip <?php echo ($filterUser==$gu['id'])?'active':''; ?>" style="--tag-color:var(--accent-blue);">
           @<?php echo h($gu['username']); ?>
        </a>
    <?php endforeach; ?>
  </div>
</div>

<div id="sectionsContainer">
<?php foreach ($sections as $tagId => $sec): ?>
    <?php 
    if (empty($sec['tasks']) && $tagId !== 'untagged') continue;
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
                <div class="task" data-id="<?php echo $t['id']; ?>">
                  <div style="display:flex; align-items:flex-start; width:100%">
                      <div class="handle" style="cursor:grab; margin-right:12px; color:#ddd; font-size:1.2rem; padding-top:2px;">&#8942;</div>
                      
                      <?php if(!empty($t['task_image'])): ?>
                        <a href="<?php echo h($t['task_image']); ?>" target="_blank"><img src="<?php echo h($t['task_image']); ?>" class="task-thumb" /></a>
                      <?php endif; ?>

                      <div style="flex-grow:1;">
                        <div class="text">
                            <?php 
                            $cleanText = trim(preg_replace('/#(\w+)/u', '', h($t['text']))); 
                            echo linkify($cleanText ?: h($t['text'])); 
                            ?>
                        </div>
                        
                        <div class="muted">
                          Due: 
                          <?php if($t['day_date'] !== 'No Date'): ?>
                            <a class="pill" href="day.php?date=<?php echo h($t['day_date']); ?>" style="text-decoration:none">
                                <?php echo h($t['day_date']); ?>
                            </a>
                          <?php else: ?>
                             <span class="pill" style="background:#eee">No Date</span>
                          <?php endif; ?>
                          
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
                    
                    <form method="post" action="task_done.php" style="display:inline">
                      <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>"/>
                      <input type="hidden" name="task_id" value="<?php echo (int)$t['id']; ?>"/>
                      <button class="btn" style="padding:6px 10px; font-size:0.8rem">✔</button>
                    </form>
                    
                    <?php $editDate = ($t['day_date'] !== 'No Date') ? $t['day_date'] : date('Y-m-d'); ?>
                    <button class="btn" style="padding:6px 10px; font-size:0.8rem" onclick="openEditModal(<?php echo $t['id']; ?>, '<?php echo addslashes(h($t['text'])); ?>', '<?php echo $editDate; ?>')">✏</button>
                    
                    <form method="post" action="task_delete.php" style="display:inline" onsubmit="return confirm('Delete?');">
                        <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>"/>
                        <input type="hidden" name="task_id" value="<?php echo $t['id']; ?>"/>
                        <button class="btn" style="padding:6px 10px; font-size:0.8rem; color:red;">🗑</button>
                    </form>
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
    if(typeof attachSuggest==='function'){attachSuggest('editText');}

    Sortable.create(document.getElementById('sectionsContainer'), {
        animation: 150, handle: '.section-header',
        onEnd: function() {
            let tagIds = [];
            document.querySelectorAll('.section-wrapper').forEach(div => tagIds.push(div.getAttribute('data-tag-id')));
            fetch('api/reorder_tags.php', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({ids: tagIds}) });
        }
    });

    document.querySelectorAll('.section-body').forEach(el => {
        Sortable.create(el, {
            group: 'tasks', animation: 150, handle: '.handle',
            onEnd: function(evt) {
                let ids = [];
                document.querySelectorAll('.task').forEach(div => ids.push(div.getAttribute('data-id')));
                fetch('api/reorder.php', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({ids: ids}) });
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