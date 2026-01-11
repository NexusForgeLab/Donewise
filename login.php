<?php
require_once __DIR__ . '/app/layout.php';
$pdo = db();

$err='';
if($_SERVER['REQUEST_METHOD']==='POST'){
  csrf_check();
  $username = trim($_POST['username'] ?? '');
  $pass = $_POST['password'] ?? '';

  if($username===''||$pass===''){ 
    $err='Fill all fields.'; 
  } else {
    // 1. Find ALL user records with this username (across all groups)
    $st = $pdo->prepare("
        SELECT u.*, g.name AS group_name 
        FROM users u 
        JOIN groups g ON g.id=u.group_id 
        WHERE u.username=?
    ");
    $st->execute([$username]);
    $potential_users = $st->fetchAll();

    $valid_accounts = [];

    // 2. Verify password for each account
    foreach ($potential_users as $u) {
        if (password_verify($pass, $u['pass_hash'])) {
            $valid_accounts[] = [
                'id' => (int)$u['id'],
                'group_id' => (int)$u['group_id'],
                'username' => $u['username'],
                'display_name' => $u['display_name'],
                'group_name' => $u['group_name']
            ];
        }
    }

    if (empty($valid_accounts)) {
        $err='Invalid username or password.';
    } else {
        // 3. Log in using the first account found
        $_SESSION['user'] = $valid_accounts[0];
        
        // 4. Store ALL valid accounts in session so we can switch later
        $_SESSION['my_identities'] = $valid_accounts;

        // FIX: Write session data and close lock before redirecting
        session_write_close();

        header('Location:/');
        exit;
    }
  }
}

render_header('Login');
?>
<div class="card">
  <h1>Login</h1>
  <?php if($err): ?><div class="card"><?php echo h($err); ?></div><?php endif; ?>
  
  <form method="post">
    <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>"/>
    
    <div style="margin-bottom:10px">
      <div class="muted">Username</div>
      <input name="username" required autocomplete="username" placeholder="Enter username" autofocus/>
    </div>

    <div style="margin-bottom:12px">
      <div class="muted">Password</div>
      <input name="password" type="password" required/>
    </div>

    <div>
      <button class="btn" type="submit">Login</button>
      <a class="btn" href="/register.php">Create new group</a>
    </div>
  </form>
</div>
<?php render_footer(); ?>