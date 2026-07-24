<?php
declare(strict_types=1);

const NEMS_HTPASSWD = '/var/www/htpasswd';

function nems_secure_session_start(): void {
    if (PHP_SESSION_ACTIVE !== session_status()) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => !empty($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
    }
    // Simple hijack guards
    $_SESSION['__ip'] = $_SESSION['__ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? '');
    $_SESSION['__ua'] = $_SESSION['__ua'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? '');
    if ($_SESSION['__ip'] !== ($_SERVER['REMOTE_ADDR'] ?? '') ||
        $_SESSION['__ua'] !== ($_SERVER['HTTP_USER_AGENT'] ?? '')) {
        nems_logout_session();
    }
    // Idle timeout (30 min)
    $now = time();
    if (!empty($_SESSION['__last']) && ($now - (int)$_SESSION['__last'] > 1800)) {
        nems_logout_session();
    }
    $_SESSION['__last'] = $now;

    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
}

function nems_logout_session(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

function nems_h(string $s): string { return htmlspecialchars($s, ENT_QUOTES); }

function nems_run_userctl(array $args, ?string $stdin = null): array {
    // Absolute, non-interactive
    $cmd_argv = array_merge(['/usr/bin/sudo', '-n', '/usr/local/sbin/nems-userctl'], $args);

    // Debug context (one line)
    $dbg = sprintf(
        'euid=%s user=%s cwd=%s disable_functions=%s open_basedir=%s',
        function_exists('posix_geteuid') ? (string)posix_geteuid() : 'n/a',
        function_exists('get_current_user') ? get_current_user() : 'n/a',
        @getcwd() ?: 'n/a',
        (string)ini_get('disable_functions'),
        (string)ini_get('open_basedir')
    );

    // Prefer proc_open
    $desc = [
        0 => ['pipe','w'],  // stdin (we write)
        1 => ['pipe','r'],  // stdout (we read)
        2 => ['pipe','r'],  // stderr (we read)
    ];

    $out = ''; $err = ''; $code = 1;

    // Guard: are these functions disabled?
    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    $proc_allowed = !(in_array('proc_open', $disabled, true) || in_array('proc_close', $disabled, true));

    if ($proc_allowed) {
        $proc = @proc_open($cmd_argv, $desc, $pipes, '/', null);
        if (is_resource($proc)) {
            // stdin
            if (isset($pipes[0]) && is_resource($pipes[0])) {
                if ($stdin !== null) { fwrite($pipes[0], $stdin . "\n"); }
                fclose($pipes[0]);
            }

            // stdout
            if (isset($pipes[1]) && is_resource($pipes[1])) {
                stream_set_blocking($pipes[1], true);
                $out = stream_get_contents($pipes[1]);
                fclose($pipes[1]);
            } else {
                error_log('nems_run_userctl: stdout pipe missing/closed');
            }

            // stderr
            if (isset($pipes[2]) && is_resource($pipes[2])) {
                stream_set_blocking($pipes[2], true);
                $err = stream_get_contents($pipes[2]);
                fclose($pipes[2]);
            } else {
                error_log('nems_run_userctl: stderr pipe missing/closed');
            }

            $code = proc_close($proc);
        } else {
            error_log('nems_run_userctl: proc_open failed; '.$dbg.' cmd='.implode(' ', $cmd_argv));
        }
    } else {
        error_log('nems_run_userctl: proc_open disabled; falling back; '.$dbg.' cmd='.implode(' ', $cmd_argv));
    }

    // If proc_open path failed or returned 1 with no stderr, try fallback once via shell_exec
    if ($code !== 0) {
        error_log('nems_run_userctl: nonzero exit '.$code.'; stdout="'.trim((string)$out).'" stderr="'.trim((string)$err).'"');
    }
    if (($code !== 0 || $out === '') && function_exists('shell_exec')) {
        // Build safe shell command (no user-supplied shell metacharacters in argv we control)
        $parts = array_map('escapeshellarg', $cmd_argv);
        $shell_cmd = implode(' ', $parts).' 2>&1';
        $fallback = @shell_exec($shell_cmd);
        if (is_string($fallback) && $fallback !== '') {
            // We can’t separate stderr here; put it in $out and set code heuristically
            $out = trim($fallback);
            $err = '';
            // Heuristic: if output begins with "sudo:" consider it an error
            $code = (stripos($out, 'sudo:') === 0) ? 1 : 0;
            error_log('nems_run_userctl: shell_exec fallback used; code='.$code.' out="'.substr($out,0,200).'"');
        } else {
            error_log('nems_run_userctl: shell_exec fallback produced no output');
        }
    }

    return [$code, trim((string)$out), trim((string)$err)];
}

function nems_get_role(string $user): ?string {
    [$c,$o,$e] = nems_run_userctl(['info','--user',$user,'--json']);
    if ($c !== 0) return null;
    $j = json_decode($o, true);
    if (!is_array($j)) return null;

    // Only deny if deleted_at exists AND is not null
    if (array_key_exists('deleted_at', $j) && $j['deleted_at'] !== null) {
        return null;
    }
    return $j['role'] ?? null;
}

function nems_verify_htpasswd(string $user, string $pass): bool {
    if (!is_file(NEMS_HTPASSWD)) return false;
    $fh = fopen(NEMS_HTPASSWD, 'r');
    if (!$fh) return false;
    $hash = null;
    while (($line = fgets($fh)) !== false) {
        $line = rtrim($line, "\r\n");
        if ($line === '' || !str_contains($line, ':')) continue;
        [$u, $h] = explode(':', $line, 2);
        if (hash_equals($u, $user)) { $hash = $h; break; }
    }
    fclose($fh);
    if ($hash === null) return false;
    // htpasswd -B uses bcrypt ($2y$...); password_verify supports it.
    return password_verify($pass, $hash);
}

function nems_require_login(): void {
    if (empty($_SESSION['user']) || empty($_SESSION['role'])) {
        $_SESSION['returnAfterLogin'] = $_SERVER['REQUEST_URI'];
        header('Location: /login/');
        exit;
    }
}

function nems_require_admin(): void {
    nems_require_login();
    if (!in_array($_SESSION['role'], ['admin','superadmin'], true)) {
        http_response_code(403); exit('This section requires admin-level access.');
    }
}
