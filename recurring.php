<?php
require_once __DIR__ . '/app/layout.php';
$user = require_login();
$pdo = db();

// Handle Create
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {
    csrf_check();
    $text = trim($_POST['text'] ?? '');
    $type = $_POST['type'] ?? 'days'; // 'days' or 'weekly'
    
    if ($text) {
        $next = date('Y-m-d'); // Default next run
        $interval = null;
        $dow = null;

        if ($type === 'days') {
            $interval = (int)$_POST['interval_val'];
            if ($interval < 1) $interval = 1;
            // First run: Today + interval? Or Today? Let's say Today.
            // If we want it to start in X days:
            $next = date('Y-m-d', strtotime("+ $interval days"));
        } else {
            $dow = (int)$_POST['day_of_week']; // 0-6
            // Calculate next occurrence
            $map = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
            $target = $map[$dow];
            $next = date('Y-m-d', strtotime("next $target"));
        }

        $st = $pdo->prepare("INSERT INTO recurring_tasks(group_id, text, frequency_type, interval_val, day_of_week, next_date) VALUES(?,?,?,?,?,?)");
        $st->execute([$user['group_id'], $text, $type, $interval, $dow, $next]);
    }
    header('Location: /recurring.php');
    exit;
}

// Handle Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    csrf_check();
    $id = (int)$_POST['id'];
    $pdo->prepare("DELETE FROM recurring_tasks WHERE id=? AND group_id=?")->execute([$id, $user['group_id']]);
    header('Location: /recurring.php');
    exit;
}

$rows = $pdo->prepare("SELECT * FROM recurring_tasks WHERE group_id=? ORDER BY text ASC");
$rows->execute([$user['group_id']]);
$list = $rows->fetchAll();

render_header('Recurring Items', $user);
?>

<div class="card">
    <h1>Recurring Items</h1>
    <div class="muted">Items here will automatically appear on your list when due.</div>
</div>

<div class="card">
    <h2>Add New Rule</h2>
    <form method="post">
        <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>"/>
        <input type="hidden" name="action" value="create"/>
        
        <div style="margin-bottom:12px">
            <div class="muted">Item Name</div>
            <input name="text" placeholder="e.g. Milk" required />
        </div>

        <div class="row" style="align-items: flex-start">
            <div style="flex:1">
                <div class="muted">Frequency Type</div>
                <select id="freqType" name="type" onchange="toggleFreq()" style="width:100%; padding:10px; border:2px solid #ddd; border-radius:6px; background:white;">
                    <option value="days">Every X Days</option>
                    <option value="weekly">Day of Week</option>
                </select>
            </div>
            
            <div style="flex:1" id="blockDays">
                <div class="muted">Interval (Days)</div>
                <input type="number" name="interval_val" value="3" min="1" />
            </div>

            <div style="flex:1; display:none" id="blockWeek">
                <div class="muted">Day</div>
                <select name="day_of_week" style="width:100%; padding:10px; border:2px solid #ddd; border-radius:6px; background:white;">
                    <option value="1">Monday</option>
                    <option value="2">Tuesday</option>
                    <option value="3">Wednesday</option>
                    <option value="4">Thursday</option>
                    <option value="5">Friday</option>
                    <option value="6">Saturday</option>
                    <option value="0">Sunday</option>
                </select>
            </div>
        </div>

        <button class="btn" type="submit" style="margin-top:16px">Save Rule</button>
    </form>
</div>

<div class="card">
    <h2>Active Rules</h2>
    <?php if(!$list): ?><div class="muted">No recurring items yet.</div><?php endif; ?>

    <?php foreach($list as $r): ?>
        <div class="task">
            <div>
                <div class="text"><?php echo h($r['text']); ?></div>
                <div class="muted">
                    <?php 
                    if($r['frequency_type'] === 'days') echo "Every " . $r['interval_val'] . " days";
                    else {
                        $d = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
                        echo "Every " . $d[$r['day_of_week']];
                    }
                    ?>
                    • Next: <?php echo h($r['next_date']); ?>
                </div>
            </div>
            <form method="post">
                <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>"/>
                <input type="hidden" name="action" value="delete"/>
                <input type="hidden" name="id" value="<?php echo $r['id']; ?>"/>
                <button class="btn" style="padding:6px 12px; font-size:0.8rem">Remove</button>
            </form>
        </div>
    <?php endforeach; ?>
</div>

<script>
function toggleFreq() {
    const val = document.getElementById('freqType').value;
    document.getElementById('blockDays').style.display = (val==='days') ? 'block' : 'none';
    document.getElementById('blockWeek').style.display = (val==='weekly') ? 'block' : 'none';
}
</script>

<?php render_footer(); ?>
