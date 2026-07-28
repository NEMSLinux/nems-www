<?php
  include('/var/www/html/inc/functions.php');
  if (!initialized()) { include('../init.php'); exit(); }

  include('/var/www/html/inc/auth.php');
  nems_secure_session_start();
  nems_require_login(); // redirect to login form if not logged in
  nems_require_admin(); // requires login + role in ['admin','superadmin']

  include('/var/www/html/inc/header.php');
  $platform = ver('platform');
?>

<style>
  /* Scoped Bootstrap 3 Dark Theme Overrides for NEMS */
  .nems-user-manager { color: #e0e0e0; }
  .nems-user-manager .page-header { border-bottom-color: #333; margin-top: 0; }
  .nems-user-manager .page-header h2 { color: #ffffff; }
  .nems-user-manager .page-header small { color: #a0a0a0; }

  /* Panels */
  .nems-user-manager .panel-default {
    background-color: #242526;
    border-color: #3a3b3c;
  }
  .nems-user-manager .panel-default > .panel-heading {
    background-color: #2e3033;
    border-color: #3a3b3c;
    color: #ffffff;
  }
  .nems-user-manager .panel-primary {
    background-color: #242526;
    border-color: #205081;
  }
  .nems-user-manager .panel-primary > .panel-heading {
    background-color: #286090;
    border-color: #205081;
    color: #ffffff;
  }
  .nems-user-manager .panel-body {
    background-color: #242526;
    color: #e0e0e0;
  }

  /* Tables */
  .nems-user-manager .table { color: #e0e0e0; }
  .nems-user-manager .table > thead > tr > th {
    border-bottom-color: #3a3b3c;
    color: #ffffff;
  }
  .nems-user-manager .table > tbody > tr > td {
    border-top-color: #3a3b3c;
    vertical-align: middle;
  }
  .nems-user-manager .table-striped > tbody > tr:nth-of-type(odd) {
    background-color: #1c1d1e;
  }
  .nems-user-manager .table-hover > tbody > tr:hover {
    background-color: #2e3033;
  }

  /* Form Controls */
  .nems-user-manager .form-control {
    background-color: #141414;
    border-color: #3a3b3c;
    color: #ffffff;
  }
  .nems-user-manager .form-control:focus {
    background-color: #141414;
    border-color: #337ab7;
    color: #ffffff;
  }
  .nems-user-manager .form-control::placeholder {
    color: #777777;
  }

  /* Expandable Detail Rows */
  .nems-user-manager .detail-row-bg {
    background-color: #141414 !important;
    border-top: 1px solid #3a3b3c;
    border-bottom: 1px solid #3a3b3c;
  }

  /* Text & Labels */
  .nems-user-manager .text-muted { color: #909090 !important; }
  .nems-user-manager .label-default { background-color: #444444; }
</style>

<div class="container nems-user-manager" style="margin-top: 100px; padding-bottom: 60px;">

  <div class="page-header">
    <h2><strong>NEMS</strong> <small>User Manager</small></h2>
  </div>

<?php

function run_userctl(array $args, ?string $password = null): array {
    $cmd = ['/usr/bin/sudo','/usr/local/sbin/nems-userctl', ...$args];
    $spec = [0=>["pipe","w"],1=>["pipe","w"],2=>["pipe","w"]];
    $proc = proc_open($cmd, $spec, $pipes, null, null);
    if (!is_resource($proc)) return [1,'','Failed to start process'];
    if ($password !== null) { fwrite($pipes[0], $password . "\n"); }
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
    $code = proc_close($proc);
    return [$code, trim($out), trim($err)];
}

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES); }

function get_roles(): array {
    [$c,$o] = run_userctl(['list-roles'], null);
    $r=[]; if($c===0){ foreach(explode("\n",trim($o)) as $l){ if(preg_match('/^([a-z]+)/i',$l,$m)) $r[]=$m[1]; } }
    sort($r);
    return $r;
}
function get_user_info_json(string $u): array {
    [$c,$o] = run_userctl(['info','--user',$u,'--json']);
    return ($c===0 && ($j=json_decode($o,true))) ? $j : [];
}
function get_user_list(): array {
    [$c,$o] = run_userctl(['list-users'], null);
    if ($c!==0) return [];
    $j = json_decode($o,true);
    return is_array($j) ? $j : [];
}

function run_userctl_with_password_file(array $baseArgs, string $password, string $actorUser): array {
    $tmpFile = tempnam(sys_get_temp_dir(), 'nems-pass-');
    if ($tmpFile === false) {
        return [1, '', 'Unable to create temp file'];
    }

    chmod($tmpFile, 0600);
    $fh = fopen($tmpFile, 'w');
    if (!$fh) {
        @unlink($tmpFile);
        return [1, '', 'Unable to open temp file'];
    }
    fwrite($fh, $password . "\n");
    fclose($fh);

    $args = array_merge(
        $baseArgs,
        ['--password-file', $tmpFile, '--actor', $actorUser]
    );

    [$code,$out,$err] = run_userctl($args, null);
    @unlink($tmpFile);

    return [$code,$out,$err];
}

// CSRF
if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(32)); }
function csrf_check(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $ok = hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '');
        if (!$ok) { http_response_code(403); exit('Invalid CSRF token'); }
    }
}

$sessionUser = $_SESSION['user'] ?? '';
if ($sessionUser === '') { http_response_code(403); exit('Not logged in'); }

$roles = get_roles();
$prec  = ['viewer'=>1,'reporter'=>2,'operator'=>3,'admin'=>4,'superadmin'=>5];

// Sort $roles based on the values in $prec
usort($roles, function($a, $b) use ($prec) {
    return ($prec[$a] ?? 0) <=> ($prec[$b] ?? 0);
});

$me     = get_user_info_json($sessionUser);
$myRole = $me['role'] ?? 'viewer';
if (!in_array($myRole, ['admin','superadmin'], true)) {
    http_response_code(403); exit('Admins only.');
}
$superadminUser = trim(@file_get_contents('/usr/local/share/nems/superadmin_user')) ?: '';

function can_act_on(
    string $actor,
    string $actorRole,
    string $target,
    string $targetRole,
    string $superadminUser,
    array $prec,
    string $action,
    ?string $newRole = null
): bool {
    $actorLevel      = $prec[$actorRole]  ?? 0;
    $targetLevel     = $prec[$targetRole] ?? 0;
    $newRoleLevel    = ($newRole !== null && isset($prec[$newRole])) ? $prec[$newRole] : null;
    $isSelf          = ($actor === $target);
    $isBootAccount   = ($target === $superadminUser);
    $actorIsSuper    = ($actorRole === 'superadmin');
    $targetIsSuper   = ($targetRole === 'superadmin');

    if ($isSelf) {
        if ($action !== 'setpass') {
            return false;
        }
    }

    if ($isBootAccount) {
        if ($action === 'delete' || $action === 'setrole') {
            return false;
        }
        if ($action === 'setpass' && !$actorIsSuper && !$isSelf) {
            return false;
        }
    }

    if (($action === 'create' || $action === 'setrole') && $newRoleLevel !== null) {
        if ($newRoleLevel > $actorLevel) {
            return false;
        }
    }

    if (!$actorIsSuper) {
        if ($targetLevel >= $actorLevel) {
            return false;
        }
    }

    return true;
}

$msg=''; $err='';

csrf_check();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action   = $_POST['action']   ?? '';
    $username = trim($_POST['username'] ?? '');
    $role     = trim($_POST['role'] ?? '');
    $password = $_POST['password'] ?? null;

    $targetInfo  = get_user_info_json($username);
    $targetRole  = $targetInfo['role'] ?? ($action==='create' ? $role : 'viewer');

    $newRoleForCheck = null;
    if ($action === 'create' || $action === 'setrole') {
      $newRoleForCheck = $role;
    }

    if (!can_act_on(
        $sessionUser,
        $myRole,
        $username,
        $targetRole,
        $superadminUser,
        $prec,
        $action,
        $newRoleForCheck
    )) {
      $err = 'Insufficient privilege or disallowed target.';
    }

    $allowed_actions = ['create','delete','setrole','setpass'];
    if (!in_array($action, $allowed_actions, true)) { $err='Bad action'; }

    if (!$err && !preg_match('/^[a-z_][a-z0-9_-]*[$]?$/i', $username)) { $err='Invalid username.'; }

    if (!$err && in_array($action, ['create','setrole'], true)) {
        if ($role === '' || !in_array($role, $roles, true)) { $err='Invalid role.'; }
    }

    if (!$err && in_array($action, ['create','setpass'], true)) {
        if (!is_string($password) || strlen($password) < 12 || strlen($password) > 128 || preg_match('/[[:cntrl:]]/', $password)) {
            $err = 'Invalid password (12–128 chars, no control characters).';
        }
    }

    $targetInfo  = !$err ? get_user_info_json($username) : [];
    $targetRole  = $targetInfo['role'] ?? ($action==='create' ? $role : 'viewer');

    if (!$err) {
        if (!can_act_on($sessionUser, $myRole, $username, $targetRole, $superadminUser, $prec, $action)) {
            $err = 'Insufficient privilege or disallowed target.';
        }
    }

    if (!$err) {
        if ($action === 'create') {
          [$c,$o,$e] = run_userctl_with_password_file(
            ['create','--user',$username,'--role',$role],
            $password,
            $sessionUser
          );
        } elseif ($action === 'delete') {
            [$c,$o,$e] = run_userctl(['delete','--user',$username,'--actor',$sessionUser], null);
        } elseif ($action === 'setrole') {
            [$c,$o,$e] = run_userctl(['set-role','--user',$username,'--role',$role,'--actor',$sessionUser], null);
        } elseif ($action === 'setpass') {
            if (!is_string($password) || strlen($password) < 12 || strlen($password) > 128 || preg_match('/[[:cntrl:]]/', $password)) {
                $err = 'Invalid password (12–128 chars, no control characters).';
            } else {
                [$c,$o,$e] = run_userctl_with_password_file(
                    ['set-pass','--user',$username],
                    $password,
                    $sessionUser
                );
                $msg = $o;
                if ($c !== 0) { $err = $e ?: 'Password change failed.'; }
            }
        }
        if ($c !== 0) { $err = $e ?: 'Operation failed.'; } else { $msg = $o ?: 'OK.'; }
    }
}

// Refresh list after any change
$users = get_user_list();
?>

<!-- Alerts -->
<?php if($err): ?>
  <div class="alert alert-danger alert-dismissible" role="alert">
    <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>
    <?= h($err) ?>
  </div>
<?php elseif($msg): ?>
  <div class="alert alert-success alert-dismissible" role="alert">
    <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>
    <?= nl2br(h($msg)) ?>
  </div>
<?php endif; ?>

<!-- User List Panel -->
<div class="panel panel-default">
  <div class="panel-heading">
    <h3 class="panel-title"><strong>Users</strong></h3>
  </div>
  <div class="table-responsive">
    <table class="table table-striped table-hover" style="margin-bottom: 0;">
      <thead>
        <tr>
          <th style="width:25%">Username</th>
          <th style="width:15%">Role</th>
          <th style="width:20%">Created</th>
          <th style="width:20%">Updated</th>
          <th class="text-right" style="width:20%">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($users as $u):
          $uname = $u['username'] ?? '';
          $urole = $u['role'] ?? 'viewer';
          $canDelete = can_act_on($sessionUser,$myRole,$uname,$urole,$superadminUser,$prec,'delete');
          $canRole   = can_act_on($sessionUser,$myRole,$uname,$urole,$superadminUser,$prec,'setrole');
          $canPass   = can_act_on($sessionUser,$myRole,$uname,$urole,$superadminUser,$prec,'setpass');
        ?>
        <tr>
          <td>
            <strong><?= h($uname) ?></strong>
            <?php if ($uname===$sessionUser): ?> <span class="label label-info">you</span><?php endif; ?>
            <?php if ($uname===$superadminUser): ?> <span class="label label-primary">superadmin</span><?php endif; ?>
          </td>
          <td><span class="label label-default"><?= h($urole) ?></span></td>
          <td class="text-muted"><?= isset($u['created_at']) && $u['created_at'] ? date('Y-m-d H:i', (int)$u['created_at']) : '—' ?></td>
          <td class="text-muted"><?= isset($u['updated_at']) && $u['updated_at'] ? date('Y-m-d H:i', (int)$u['updated_at']) : '—' ?></td>
          <td class="text-right">
            <button class="btn btn-xs btn-warning"
                    data-toggle="collapse"
                    data-target="#role-<?= h($uname) ?>"
                    <?= $canRole ? '' : 'disabled' ?>>
              Change Role
            </button>
            <button class="btn btn-xs btn-info"
                    data-toggle="collapse"
                    data-target="#pass-<?= h($uname) ?>"
                    <?= $canPass ? '' : 'disabled' ?>>
              Password
            </button>
            <form method="post" onsubmit="return confirm('Delete <?= h($uname) ?> permanently?');" style="display:inline-block; margin-left:2px;">
              <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="username" value="<?= h($uname) ?>">
              <button class="btn btn-xs btn-danger" <?= $canDelete ? '' : 'disabled' ?>>Delete</button>
            </form>
          </td>
        </tr>

        <!-- Change Role row -->
        <tr class="collapse" id="role-<?= h($uname) ?>">
          <td colspan="5" class="detail-row-bg" style="padding: 15px;">
            <form method="post" class="form-inline">
              <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
              <input type="hidden" name="action" value="setrole">
              <input type="hidden" name="username" value="<?= h($uname) ?>">
              <div class="form-group" style="margin-right: 10px;">
                <label for="role-select-<?= h($uname) ?>" style="margin-right: 5px;">New Role:</label>
                <select id="role-select-<?= h($uname) ?>" name="role" class="form-control input-sm" <?= $canRole ? '' : 'disabled' ?>>
                  <?php foreach($roles as $r): ?>
                    <option value="<?= h($r) ?>" <?= $r===$urole?'selected':'' ?>><?= h($r) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <button class="btn btn-sm btn-warning" <?= $canRole ? '' : 'disabled' ?>>Apply</button>
            </form>
          </td>
        </tr>

        <!-- Change Password row -->
        <tr class="collapse" id="pass-<?= h($uname) ?>">
          <td colspan="5" class="detail-row-bg" style="padding: 15px;">
            <form method="post" class="form-inline">
              <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
              <input type="hidden" name="action" value="setpass">
              <input type="hidden" name="username" value="<?= h($uname) ?>">
              <div class="form-group" style="margin-right: 10px;">
                <label for="pass-input-<?= h($uname) ?>" style="margin-right: 5px;">New Password:</label>
                <input id="pass-input-<?= h($uname) ?>" name="password" type="password" class="form-control input-sm" minlength="12" maxlength="128" required style="width: 250px;">
              </div>
              <button class="btn btn-sm btn-primary">Change Password</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>

        <?php if (empty($users)): ?>
          <tr><td colspan="5" class="text-center text-muted" style="padding: 20px;">No users found.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Create User Panel -->
<div class="panel panel-primary" style="margin-top: 30px;">
  <div class="panel-heading">
    <h3 class="panel-title"><strong>Create User</strong></h3>
  </div>
  <div class="panel-body">
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
      <input type="hidden" name="action" value="create">
      <div class="row">
        <div class="col-sm-3">
          <div class="form-group">
            <label for="create-username">Username</label>
            <input id="create-username" name="username" class="form-control" required pattern="[A-Za-z_][A-Za-z0-9_-]*\$?" maxlength="32" placeholder="Username">
          </div>
        </div>
        <div class="col-sm-4">
          <div class="form-group">
            <label for="create-password">Password</label>
            <input id="create-password" name="password" type="password" class="form-control" minlength="12" maxlength="128" required placeholder="Min 12 characters">
          </div>
        </div>
        <div class="col-sm-3">
          <div class="form-group">
            <label for="create-role">Role</label>
            <select id="create-role" name="role" class="form-control" required>
              <option value=""></option>
              <?php foreach($roles as $r): ?>
                <option value="<?= h($r) ?>"><?= h($r) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="col-sm-2">
          <div class="form-group">
            <label class="hidden-xs">&nbsp;</label>
            <button type="submit" class="btn btn-success btn-block">Create User</button>
          </div>
        </div>
      </div>
    </form>
  </div>
</div>

</div>
<?php
  include('/var/www/html/inc/footer.php');
?>
