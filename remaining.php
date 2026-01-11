<?php
require_once __DIR__ . '/app/layout.php';
$user = require_login();
$pdo  = db();

// 1. Get Filter from URL
$filterTag = $_GET['tag'] ?? 'all';

// 2. Fetch Tags for Filter Bar
$st = $pdo->prepare("SELECT * FROM tags WHERE group_id=? ORDER BY name ASC");
$st->execute([$user['group_id']]);
$allTags = $st->fetchAll();

// 3. Build Query with Filtering
$sql = "
  SELECT
    t.*,
    d.day_date,
    GROUP_CONCAT(tg.id || ':' || tg.name || ':' || tg.color) as tag_info
  FROM tasks t
  JOIN days d ON d.id = t.day_id
  LEFT JOIN task_tags tt ON tt.task_id = t.id
  LEFT JOIN tags tg ON tg.id = tt.tag_id
  WHERE t.group_id = ? AND t.is_done = 0
";

$params = [$user['group_id']];

// Apply Filter Logic
if ($filterTag === 'untagged') {
    $sql .= " AND t.id NOT IN (SELECT task_id FROM task_tags)";
} elseif (is_numeric($filterTag)) {
    $sql .= " AND t.id IN (SELECT task_id FROM task_tags WHERE tag_id = ?)";
    $params[] = $filterTag;
}

$sql .= " GROUP BY t.id ORDER BY d.day_date DESC, t.id DESC";

$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();

render_header('Remaining', $user);
?>

<div class="card" style="padding-bottom:10px;">
  <h1>Remaining</h1>
  <div class="muted">All unfinished items across all days.</div>

  <div class="filter-bar">
    <a href="?tag=all" class="filter-chip <?php echo ($filterTag=='all')?'active':''; ?>">All</a>
    <a href="?tag=untagged" class="filter-chip <?php echo ($filterTag=='untagged')?'active':''; ?>">Untagged</a>
    <?php foreach($allTags as $tag): ?>
        <a href="?tag=<?php echo $tag['id']; ?>" 
           class="filter-chip <?php echo ($filterTag==$tag['id'])?'active':''; ?>"
           style="--tag-color:<?php echo $tag['color']; ?>">
           #<?php echo h($tag['name']); ?>
        </a>
    <?php endforeach; ?>
  </div>
</div>

<div class="card">
  <?php if (!count($rows)): ?>
    <div class="muted">No items found for this filter.</div>
  <?php endif; ?>

  <div id="taskList">
  <?php foreach ($rows as $t): ?>
    <?php 
        // Process Tags
        $myTags = [];
        if ($t['tag_info']) {
            $raw = explode(',', $t['tag_info']);
            foreach($raw as $r) {
                $parts = explode(':', $r);
                if(count($parts)>=3) $myTags[] = ['id'=>$parts[0], 'name'=>$parts[1], 'color'=>$parts[2]];
            }
        }
    ?>

    <div class="task">
      <div style="flex-grow:1;">
        <div class="text">
            <?php 
            // Display clean text (hide hashtags)
            $displayText = preg_replace('/#(\w+)/u', '', h($t['text'])); 
            echo trim($displayText) ?: h($t['text']); 
            ?>
            
            <span class="tags-container">
                <?php if(empty($myTags)): ?>
                    <span class="tag-pill untagged">Untagged</span>
                <?php else: ?>
                    <?php foreach($myTags as $mt): ?>
                        <span class="tag-pill" style="color:<?php echo $mt['color']; ?>; border-color:<?php echo $mt['color']; ?>;">
                            #<?php echo h($mt['name']); ?>
                        </span>
                    <?php endforeach; ?>
                <?php endif; ?>
            </span>
        </div>
        
        <div class="muted">
          Due: 
          <a class="pill" href="/day.php?date=<?php echo h($t['day_date']); ?>" style="text-decoration:none">
            <?php echo h($t['day_date']); ?>
          </a>
        </div>
      </div>

      <div>
        <form method="post" action="/task_done.php" style="display:inline">
          <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>"/>
          <input type="hidden" name="task_id" value="<?php echo (int)$t['id']; ?>"/>
          <button class="btn" type="submit">Mark done</button>
        </form>
      </div>
    </div>
  <?php endforeach; ?>
  </div>
</div>

<?php render_footer(); ?>