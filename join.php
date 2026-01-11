<?php
require_once __DIR__ . '/app/layout.php';
$pdo = db();

$token = trim($_GET['token'] ?? '');
if ($token === '') { header('Location:/login.php'); exit; }

$st = $pdo->prepare("SELECT * FROM groups WHERE join_token=?");
$st->execute([$token]);
$g = $st->fetch();
if(!$g){ render_header('Join'); echo "<div class='card'>Invalid join link.</div>"; render_footer(); exit; }

$err='';
if($_SERVER['REQUEST_METHOD']==='POST'){
  csrf_check();
  $username = trim($_POST['username'] ?? '');
  $display = trim($_POST['display_name'] ?? '');
  $pass = $_POST['password'] ?? '';
  if($username===''||$display===''||strlen($pass)<4){
    $err='Fill all fields (password min 4 chars).';
  }else{
    try{
      $hash = password_hash($pass, PASSWORD_DEFAULT);
      $st = $pdo->prepare("INSERT INTO users(group_id, username, pass_hash, display_name) VALUES(?,?,?,?)");
      $st->execute([(int)$g['id'],$username,$hash,$display]);
      $uid=(int)$pdo->lastInsertId();
      $_SESSION['user']=['id'=>$uid,'group_id'=>(int)$g['id'],'username'=>$username,'display_name'=>$display];
      header('Location:/');
      exit;
    }catch(Exception $e){
      $err='Username already exists in this group.';
    }
  }
}

render_header('Join Group');
?>
<div class="card">
  <h1>Join: <?php echo h($g['name']); ?></h1>
  <?php if($err): ?><div class="card"><?php echo h($err); ?></div><?php endif; ?>
  <form method="post">
    <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>"/>
    <div class="row">
      <div style="flex:1;min-width:220px">
        <div class="muted">Your name</div>
        <input name="display_name" required/>
      </div>
      <div style="flex:1;min-width:220px">
        <div class="muted">Username</div>
        <input name="username" required/>
      </div>
    </div>
    <div style="margin-top:10px">
      <div class="muted">Password</div>
      <input name="password" type="password" required/>
    </div>
    <div style="margin-top:12px">
      <button class="btn" type="submit">Join & Login</button>
    </div>
  </form>
</div>
<?php render_footer(); ?>
