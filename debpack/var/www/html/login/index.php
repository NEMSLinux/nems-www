<?php
declare(strict_types=1);
include('/var/www/html/inc/functions.php');
if (!initialized()) { include('./init.php'); exit(); }

include('/var/www/html/inc/auth.php');
nems_secure_session_start();

if (!empty($_SESSION['user']) && !empty($_SESSION['role'])) {
    header('Location: /admin/users.php'); exit;
}

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $u = trim($_POST['username'] ?? '');
    $p = $_POST['password'] ?? '';
    $csrf = $_POST['csrf'] ?? '';

    if (!hash_equals($_SESSION['csrf'] ?? '', $csrf)) {
        $err = 'Invalid request. Please try again.';
    } elseif (!preg_match('/^[a-z_][a-z0-9_-]*[$]?$/i', $u)) {
        $err = 'Invalid username.';
    } else {
        // Throttle brute force (per session)
        $_SESSION['__fail'] = (int)($_SESSION['__fail'] ?? 0);
        if ($_SESSION['__fail'] > 0) {
            $delay = min(5, $_SESSION['__fail']); // cap at 5s
            sleep($delay);
        }

        if (nems_verify_htpasswd($u, $p)) {
            $role = nems_get_role($u);
            if ($role === null) {
                $err = 'Account is disabled or misconfigured.';
            } else {
                // Success: reset fail counter, set session, rotate ID
                $_SESSION['__fail'] = 0;
                session_regenerate_id(true);
                $_SESSION['user'] = $u;
                $_SESSION['role'] = $role;
                $_SESSION['__last'] = time();
                header('Location: /admin/users.php'); exit;
            }
        } else {
            $_SESSION['__fail']++;
            $err = 'Invalid username or password.';
        }
    }
}

include('/var/www/html/inc/header.php');
?>
<div class="container" style="margin-top: 100px; padding-bottom: 100px;">
  <h2><b>NEMS</b> User Login</h2>
  <div class="card">

    <div class="card-body">
      <?php if ($err): ?>
        <div class="alert alert-danger"><?= nems_h($err) ?></div>
      <?php endif; ?>
      <form method="post" autocomplete="off" style="max-width: 260px;">
        <input type="hidden" name="csrf" value="<?= nems_h($_SESSION['csrf']) ?>">
        <div class="mb-3" style="margin-bottom: 1em;">
          <label style="color: #ccc;" class="form-label">Username</label>
          <input name="username" class="form-control" required autofocus
                 pattern="[A-Za-z_][A-Za-z0-9_-]*\$?" maxlength="32">
        </div>
        <div class="mb-3" style="margin-bottom: 1em;">
          <label style="color: #ccc;class="form-label">Password</label>
          <input name="password" type="password" class="form-control" required>
        </div>
        <button class="btn btn-primary w-100">Login</button>
      </form>
    </div>

  </div>
</div>
<?php include('/var/www/html/inc/footer.php'); ?>
