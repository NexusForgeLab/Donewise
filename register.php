<?php
require_once __DIR__ . '/app/layout.php';
$pdo = db();

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $group = trim($_POST['group_name'] ?? '');
  $username = trim($_POST['username'] ?? '');
  $display = trim($_POST['display_name'] ?? '');
  $pass = $_POST['password'] ?? '';

  if ($group===''||$username===''||$display===''||strlen($pass)<4) {
    $err = 'Fill all fields (password min 4 chars).';
  } else {
    $token = bin2hex(random_bytes(16));
    $pdo->beginTransaction();
    try{
      $st = $pdo->prepare("INSERT INTO groups(name, join_token) VALUES(?,?)");
      $st->execute([$group, $token]);
      $group_id = (int)$pdo->lastInsertId();

      $hash = password_hash($pass, PASSWORD_DEFAULT);
      $st = $pdo->prepare("INSERT INTO users(group_id, username, pass_hash, display_name) VALUES(?,?,?,?)");
      $st->execute([$group_id, $username, $hash, $display]);
      $user_id = (int)$pdo->lastInsertId();

      $pdo->commit();

      $_SESSION['user'] = ['id'=>$user_id,'group_id'=>$group_id,'username'=>$username,'display_name'=>$display];
      header('Location: /');
      exit;
    }catch(Exception $e){
      $pdo->rollBack();
      $err = 'Username already exists in this group or DB error.';
    }
  }
}

render_header('Create Group');
?>
<div class="card">
  <h1>Create a Group</h1>
  <div class="muted">Example groups: Family, Friends, Office.</div>
  <?php if($err): ?><div class="card"><?php echo h($err); ?></div><?php endif; ?>
  <form method="post">
    <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>"/>
    <div class="row">
      <div style="flex:1;min-width:220px">
        <div class="muted">Group name</div>
        <input name="group_name" required/>
      </div>
      <div style="flex:1;min-width:220px">
        <div class="muted">Your name</div>
        <input name="display_name" required/>
      </div>
    </div>
    <div class="row" style="margin-top:10px">
      <div style="flex:1;min-width:220px">
        <div class="muted">Username</div>
        <input name="username" required/>
      </div>
      <div style="flex:1;min-width:220px">
        <div class="muted">Password</div>
        <input name="password" type="password" required/>
      </div>
    </div>
    <div style="margin-top:12px">
      <button class="btn" type="submit">Create Group & Login</button>
      <a class="btn" href="/login.php">I already have a group</a>
    </div>
  </form>
</div>
<?php render_footer(); ?>
