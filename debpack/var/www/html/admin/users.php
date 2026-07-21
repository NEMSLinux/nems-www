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
<div class="container" style="margin-top: 100px; padding-bottom: 100px;">
  <h2><b>NEMS</b> User Manager</h2>
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
    // create a secure temp file
    $tmpFile = tempnam(sys_get_temp_dir(), 'nems-pass-');
    if ($tmpFile === false) {
        return [1, '', 'Unable to create temp file'];
    }

    // lock it down and write password
    chmod($tmpFile, 0600);
    $fh = fopen($tmpFile, 'w');
    if (!$fh) {
        @unlink($tmpFile);
        return [1, '', 'Unable to open temp file'];
    }
    fwrite($fh, $password . "\n");
    fclose($fh);

    // build final arg list including actor
    $args = array_merge(
        $baseArgs,
        ['--password-file', $tmpFile, '--actor', $actorUser]
    );

    // run it
    [$code,$out,$err] = run_userctl($args, null);

    // cleanup ASAP
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
    string $superadminUser,   // the immutable boot account from nems-init
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

    // 1. Self rules
    // - You cannot delete yourself or change your own role.
    // - You CAN change your own password.
    if ($isSelf) {
        if ($action === 'setpass') {
            // allowed
        } else {
            return false;
        }
    }

    // 2. Boot account protection
    // The "main" superadmin from nems-init cannot be:
    // - deleted
    // - demoted
    // Basically: hands off, except password changes.
    if ($isBootAccount) {
        if ($action === 'delete' || $action === 'setrole') {
            return false;
        }
        // setpass on boot account:
        // only allow if actor is that same account, or actor is a superadmin
        if ($action === 'setpass' && !$actorIsSuper && !$isSelf) {
            return false;
        }
    }

    // 3. Role creation / promotion rules
    // You cannot create or assign a role higher than yourself.
    // So:
    // - admin cannot create/promote superadmin
    // - operator cannot promote to admin, etc.
    if (($action === 'create' || $action === 'setrole') && $newRoleLevel !== null) {
        if ($newRoleLevel > $actorLevel) {
            return false;
        }
    }

    // 4. General "who outranks who" rules
    // Below superadmin: you may not touch accounts of equal-or-higher level.
    // Example:
    // - admin cannot delete/downgrade admin
    // - admin cannot act on superadmin
    // - operator cannot act on operator/admin/superadmin
    //
    // BUT: superadmin is special (next section).
    if (!$actorIsSuper) {
        // not superadmin
        // deny if target outranks or matches privilege
        if ($targetLevel >= $actorLevel) {
            // exception: self password already handled above, so here it's always a "no"
            return false;
        }
    }

    // 5. Superadmin special case:
    // superadmin CAN act on other superadmins, with 2 exceptions already handled:
    // - can't act on self (handled in #1)
    // - can't act on boot account for delete/setrole (handled in #2)
    //
    // So if actor is superadmin, we allow acting on another superadmin.
    // No extra block here.

    // 6. Final action-specific sanity:
    // Deleting someone of lower privilege? ok.
    // Changing role to lower/equal than you? ok (already guarded).
    // Changing password for someone else? Generally okay if you got here.
    // Nothing else to block.

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

    // use correct newRole param
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

    // Validate action
    $allowed_actions = ['create','delete','setrole','setpass'];
    if (!in_array($action, $allowed_actions, true)) { $err='Bad action'; }

    // Validate username
    if (!$err && !preg_match('/^[a-z_][a-z0-9_-]*[$]?$/i', $username)) { $err='Invalid username.'; }

    // Validate role if provided
    if (!$err && in_array($action, ['create','setrole'], true)) {
        if ($role === '' || !in_array($role, $roles, true)) { $err='Invalid role.'; }
    }

    // Validate password if needed
    if (!$err && in_array($action, ['create','setpass'], true)) {
        if (!is_string($password) || strlen($password) < 12 || strlen($password) > 128 || preg_match('/[[:cntrl:]]/', $password)) {
            $err = 'Invalid password (12–128 chars, no control characters).';
        }
    }

    // Target info
    $targetInfo  = !$err ? get_user_info_json($username) : [];
    $targetRole  = $targetInfo['role'] ?? ($action==='create' ? $role : 'viewer');

    // Privilege rules
    if (!$err) {
        if (!can_act_on($sessionUser, $myRole, $username, $targetRole, $superadminUser, $prec, $action)) {
            $err = 'Insufficient privilege or disallowed target.';
        }
    }

    // Execute
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
  <div class="alert alert-danger mt-3"><?= h($err) ?></div>
<?php elseif($msg): ?>
  <div class="alert alert-success mt-3"><?= nl2br(h($msg)) ?></div>
<?php endif; ?>

<!-- User List -->
<div class="card nems-card bg-dark mt-4 mb-4">
  <div class="card-header bg-light">
    <span class="nems-section-title">Users</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-dark table-striped table-hover table-nems align-middle mb-0">
        <thead>
          <tr>
            <th style="width:28%">Username</th>
            <th style="width:12%">Role</th>
            <th style="width:20%">Created</th>
            <th style="width:20%">Updated</th>
            <th class="text-end" style="width:20%">Actions</th>
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
              <?php if ($uname===$sessionUser): ?><span class="badge bg-info ms-2">you</span><?php endif; ?>
              <?php if ($uname===$superadminUser): ?><span class="badge bg-dark text-white ms-1">superadmin</span><?php endif; ?>
            </td>
            <td><span class="badge bg-secondary"><?= h($urole) ?></span></td>
            <td class="text-muted"><?= isset($u['created_at']) && $u['created_at'] ? date('Y-m-d H:i', (int)$u['created_at']) : '—' ?></td>
            <td class="text-muted"><?= isset($u['updated_at']) && $u['updated_at'] ? date('Y-m-d H:i', (int)$u['updated_at']) : '—' ?></td>
            <td class="text-end nems-actions">
              <button class="btn btn-sm btn-outline-warning"
                      data-bs-toggle="collapse"
                      data-bs-target="#role-<?= h($uname) ?>"
                      <?= $canRole ? '' : 'disabled' ?>>
                Change Role
              </button>
              <button class="btn btn-sm btn-outline-primary"
                      data-bs-toggle="collapse"
                      data-bs-target="#pass-<?= h($uname) ?>"
                      <?= $canPass ? '' : 'disabled' ?>>
                Password
              </button>
              <form method="post" onsubmit="return confirm('Delete <?= h($uname) ?> permanently?');" class="ms-1">
                <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="username" value="<?= h($uname) ?>">
                <button class="btn btn-sm btn-outline-danger" <?= $canDelete ? '' : 'disabled' ?>>Delete</button>
              </form>
            </td>
          </tr>

          <!-- Change Role row -->
          <tr class="collapse collapse-row" id="role-<?= h($uname) ?>">
            <td colspan="5" class="p-3">
              <form method="post" class="row g-2 align-items-end">
                <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
                <input type="hidden" name="action" value="setrole">
                <input type="hidden" name="username" value="<?= h($uname) ?>">
                <div class="col-md-4">
                  <label class="form-label">New Role</label>
                  <select name="role" class="form-select" <?= $canRole ? '' : 'disabled' ?>>
                    <?php foreach($roles as $r): ?>
                      <option value="<?= h($r) ?>" <?= $r===$urole?'selected':'' ?>><?= h($r) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-2">
                  <button class="btn btn-warning w-100" <?= $canRole ? '' : 'disabled' ?>>Apply</button>
                </div>
              </form>
            </td>
          </tr>

          <!-- Change Password row -->
          <tr class="collapse collapse-row" id="pass-<?= h($uname) ?>">
            <td colspan="5" class="p-3">
              <form method="post" class="row g-2 align-items-end">
                <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
                <input type="hidden" name="action" value="setpass">
                <input type="hidden" name="username" value="<?= h($uname) ?>">
                <div class="col-md-6">
                  <label class="form-label">New Password</label>
                  <input name="password" type="password" class="form-control" minlength="12" maxlength="128" required>
                </div>
                <div class="col-md-2">
                  <button class="btn btn-primary w-100">Change</button>
                </div>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>

          <?php if (empty($users)): ?>
            <tr><td colspan="5" class="text-center text-muted p-4">No users found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Create User -->
<div class="card nems-card bg-dark mb-5">
  <div class="card-header bg-primary text-white">
    <span class="nems-section-title">Create User</span>
  </div>
  <div class="card-body">
    <form method="post" class="row g-3">
      <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
      <input type="hidden" name="action" value="create">
      <div class="col-md-3">
        <label class="form-label">Username</label>
        <input name="username" class="form-control" required pattern="[A-Za-z_][A-Za-z0-9_-]*\$?" maxlength="32">
      </div>
      <div class="col-md-5">
        <label class="form-label">Password</label>
        <input name="password" type="password" class="form-control" minlength="12" maxlength="128" required>
      </div>
      <div class="col-md-2">
        <label class="form-label">Role</label>
        <select name="role" class="form-select" required>
          <option value="">Select</option>
          <?php foreach($roles as $r): ?>
            <option value="<?= h($r) ?>"><?= h($r) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2 d-flex align-items-end">
        <button class="btn btn-success w-100">Create</button>
      </div>
    </form>
  </div>
</div>

<?php if($err || $msg): ?>
  <div class="position-fixed bottom-0 end-0 p-3" style="z-index: 1080">
    <div class="toast align-items-center text-bg-<?= $err ? 'danger' : 'success' ?> border-0 show">
      <div class="d-flex">
        <div class="toast-body"><?= nl2br(h($err ?: $msg)) ?></div>
        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
      </div>
    </div>
  </div>
<?php endif; ?>

</div>
<?php
  include('/var/www/html/inc/footer.php');
?>
