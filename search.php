<?php
require_once __DIR__ . '/app/layout.php';
$user = require_login();
$pdo = db();

$q = trim($_GET['q'] ?? '');
$results = [];

if ($q !== '') {
    // Search Tasks and join Days to show when it happened
    $sql = "SELECT t.*, d.day_date 
            FROM tasks t 
            JOIN days d ON d.id = t.day_id 
            WHERE t.group_id = ? AND t.text LIKE ? 
            ORDER BY d.day_date DESC LIMIT 50";
    $st = $pdo->prepare($sql);
    $st->execute([$user['group_id'], '%' . $q . '%']);
    $results = $st->fetchAll();
}

render_header('Search', $user);
?>

<div class="card">
    <h1>Search</h1>
    <form method="get" class="row">
        <div style="flex:1">
            <input name="q" value="<?php echo h($q); ?>" placeholder="Search tasks..." autofocus />
        </div>
        <button class="btn">Search</button>
    </form>
</div>

<?php if($q && empty($results)): ?>
    <div class="card muted">No results found for "<?php echo h($q); ?>"</div>
<?php elseif(!empty($results)): ?>
    <div class="card">
        <?php foreach($results as $r): ?>
            <div class="task">
                <div>
                    <div class="text">
                        <a href="task_details.php?id=<?php echo $r['id']; ?>"><?php echo h($r['text']); ?></a>
                    </div>
                    <div class="muted">
                        <?php echo $r['day_date']; ?> 
                        <?php if($r['is_done']): ?><span class="badge" style="background:#ccc">Done</span><?php endif; ?>
                    </div>
                </div>
                <a class="btn" href="day.php?date=<?php echo $r['day_date']; ?>">Go to Day</a>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php render_footer(); ?>
