<?php
// Load system functions if accessed standalone
if (!isset($functions_loaded) || $functions_loaded != 1) {
    require_once('/var/www/html/inc/functions.php');
}

// Paths for execution tracking
$status_file = '/var/www/html/userfiles/nems-init.status';
$log_file    = '/var/www/html/userfiles/nems-init.log';

// Helper to determine real process state
function check_init_status($status_file, $log_file) {
    if (!file_exists($status_file)) {
        return 'idle';
    }

    $raw = trim(file_get_contents($status_file));

    if ($raw === '0') {
        return 'complete';
    }

    if ($raw !== 'RUNNING' && $raw !== '') {
        return 'error';
    }

    // Inspect log if file still says RUNNING
    $log = file_exists($log_file) ? file_get_contents($log_file) : '';
    if (strpos($log, 'NEMS Admin is initialized') !== false || strpos($log, 'OK: created/updated user') !== false) {
        file_put_contents($status_file, '0');
        return 'complete';
    }

    return 'running';
}

// Status API Endpoint for AJAX Polling
if (isset($_GET['action']) && $_GET['action'] === 'get_status') {
    header('Content-Type: application/json');

    $status = check_init_status($status_file, $log_file);
    $log    = file_exists($log_file) ? file_get_contents($log_file) : '';

    echo json_encode([
        'status' => $status,
        'log'    => $log
    ]);
    exit();
}

$is_initialized = initialized();
$current_status = check_init_status($status_file, $log_file);

// Handle status cleanup on fresh GET requests
$in_progress = false;
if ($current_status === 'running') {
    $in_progress = true;
} elseif ($current_status === 'complete' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    @unlink($status_file);
    @unlink($log_file);
}

// Post-Init Security Guard (Require Superadmin if system is initialized and NOT actively running)
if ($is_initialized && !$in_progress) {
    require_once('/var/www/html/inc/auth.php');
    nems_secure_session_start();

    if (!isset($_SESSION['role']) || strtolower($_SESSION['role']) !== 'superadmin') {
        header('Location: /errors/403.php');
        exit();
    }
}

// Fetch Timezones and System Details
$ip = trim(shell_exec('/usr/local/bin/nems-info ip'));
$platform = ver('platform');
require_once('inc/bgcolor.php');

$timezones = explode("\n", trim(shell_exec('timedatectl list-timezones')));
$current_tz = trim(shell_exec('date +%Z'));

$error = '';

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'nems_init') {
    $username         = trim($_POST['username'] ?? '');
    $password         = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';
    $email            = trim($_POST['email'] ?? '');
    $timezone         = trim($_POST['timezone'] ?? '');
    $force            = isset($_POST['force']) && $_POST['force'] === '1';

    $badnames = ['nemsadmin', 'nagios', 'nems', 'root', 'user', 'config', 'pi', 'admin', 'robbie', 'nagiosadmin', 'www-data'];

    if (empty($username) || !preg_match('/^[a-z_][a-z0-9_-]{2,15}$/', $username)) {
        $error = 'Username must be 3-16 lowercase alphanumeric characters starting with a letter.';
    } elseif (in_array($username, $badnames)) {
        $error = "Username '{$username}' is reserved or prohibited.";
    } elseif (strlen($password) < 12) {
        $error = 'Superadmin password MUST be at least 12 characters long.';
    } elseif ($password !== $password_confirm) {
        $error = 'Passwords do not match.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid notification email address.';
    } elseif ($is_initialized && !$force) {
        $error = 'System is already initialized. You must check "Force Re-initialization" to proceed.';
    } else {
        // Apply Selected System Timezone
        if (!empty($timezone) && in_array($timezone, $timezones)) {
            shell_exec('sudo /usr/bin/timedatectl set-timezone ' . escapeshellarg($timezone));
        }

        // Clear stale files and initialize tracking markers
        @unlink($status_file);
        @unlink($log_file);
        file_put_contents($status_file, "RUNNING");
        file_put_contents($log_file, "Starting NEMS Linux initialization...\n");

        // Execute via systemd-run and capture exit status code
        $inner_cmd = sprintf(
            '/usr/local/bin/nems-init --headless -u %s -p %s -e %s %s > %s 2>&1; echo $? > %s',
            escapeshellarg($username),
            escapeshellarg($password),
            escapeshellarg($email),
            $force ? '-f' : '',
            escapeshellarg($log_file),
            escapeshellarg($status_file)
        );

        $cmd = sprintf(
            'sudo /usr/bin/systemd-run --service-type=exec /bin/bash -c %s',
            escapeshellarg($inner_cmd)
        );

        exec($cmd);

        // Nuke active session so old superadmin credentials are completely discarded
        if (session_status() === PHP_SESSION_ACTIVE || isset($_SESSION)) {
            $_SESSION = array();
            if (ini_get("session.use_cookies")) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000,
                    $params["path"], $params["domain"],
                    $params["secure"], $params["httponly"]
                );
            }
            @session_destroy();
        }

        // Switch UI to active progress state
        $in_progress = true;
    }
}
?><!DOCTYPE html>
<!--[if IE 8]> <html lang="en" class="ie8"> <![endif]-->
<!--[if IE 9]> <html lang="en" class="ie9"> <![endif]-->
<!--[if !IE]><!--> <html lang="en"> <!--<![endif]-->
<head>
    <title>NEMS Linux: First-Run Initialization</title>

    <!-- Meta -->
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="author" content="Robbie Ferguson - https://baldnerd.com/">
    <meta name="robots" content="noindex">

    <!-- Favicon -->
    <link rel="shortcut icon" href="/favicon.ico">
    <meta name="theme-color" content="#<?= $bgcolor ?>">

    <!-- Web Fonts -->
    <link rel='stylesheet' type='text/css' href='//fonts.googleapis.com/css?family=Open+Sans:400,300,600&amp;subset=cyrillic,latin'>

    <!-- CSS Global Compulsory -->
    <link rel="stylesheet" href="https://cdn.zecheriah.com/site-assets/1.9.6/One-Pages/Classic/assets/plugins/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.zecheriah.com/site-assets/1.9.6/One-Pages/Classic/assets/css/one.style.css">

    <!-- CSS Footer -->
    <link rel="stylesheet" href="https://cdn.zecheriah.com/site-assets/1.9.6/One-Pages/Classic/assets/css/footers/footer-v7.css">

    <!-- CSS Implementing Plugins -->
    <link rel="stylesheet" href="/css/animate.min.css">
    <link rel="stylesheet" href="https://cdn.zecheriah.com/site-assets/1.9.6/assets/plugins/line-icons/line-icons.css">
    <link rel="stylesheet" href="/css/font-awesome.min.css">
    <link rel="stylesheet" href="https://cdn.zecheriah.com/site-assets/1.9.6/One-Pages/Classic/assets/plugins/pace/pace-flash.css">
    <link rel="stylesheet" href="https://cdn.zecheriah.com/site-assets/1.9.6/assets/plugins/sky-forms-pro/skyforms/css/sky-forms.css">
    <link rel="stylesheet" href="https://cdn.zecheriah.com/site-assets/1.9.6/assets/plugins/sky-forms-pro/skyforms/custom/custom-sky-forms.css">
    <!--[if lt IE 9]><link rel="stylesheet" href="https://cdn.zecheriah.com/site-assets/1.9.6/assets/plugins/sky-forms-pro/skyforms/css/sky-forms-ie8.css"><![endif]-->
    <link rel="stylesheet" href="https://cdn.zecheriah.com/site-assets/1.9.6/assets/css/pages/page_error4_404.css">

    <!-- CSS Theme -->
    <link rel="stylesheet" href="https://cdn.zecheriah.com/site-assets/1.9.6/assets/css/headers/header-v6.css">
    <link rel="stylesheet" href="https://cdn.zecheriah.com/site-assets/1.9.6/One-Pages/Classic/assets/css/theme-skins/one.dark.css">

    <!-- Fullcalendar -->
    <link rel="stylesheet" href="/css/fullcalendar.min.css">

    <!-- CSS Customization -->
    <link rel="stylesheet" href="/css/custom.css">

    <!-- JS Global Compulsory -->
    <script src="/js/jquery.min.js"></script>
    <script src="/js/moment.min.js"></script>

    <script language="javascript" type="text/javascript">
      function resizeIframe(obj) {
        obj.style.height = obj.contentWindow.document.body.scrollHeight + 'px';
      }
    </script>

    <style>
        .init-box {
            background: rgba(20, 20, 20, 0.85);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 8px;
            padding: 35px 45px;
            margin-top: 20px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5);
        }
        .dark-input {
            background: #181818 !important;
            color: #fff !important;
            border: 1px solid #383838 !important;
            height: 42px;
            font-size: 14px;
        }
        .dark-input:focus {
            border-color: #a00 !important;
            box-shadow: 0 0 5px rgba(160, 0, 0, 0.5);
        }
        .help-block-custom {
            color: #aaa;
            font-size: 0.85em;
            margin-top: 5px;
        }
        .superadmin-info-box {
            background: rgba(30, 30, 30, 0.9);
            border-left: 4px solid #d9534f;
            padding: 15px 20px;
            margin-bottom: 25px;
            border-radius: 0 4px 4px 0;
        }
        .superadmin-info-box h5 {
            color: #d9534f;
            font-weight: bold;
            margin-top: 0;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .superadmin-info-box p {
            color: #ccc;
            font-size: 0.92em;
            line-height: 1.5;
            margin-bottom: 8px;
        }
        .superadmin-info-box p:last-child {
            margin-bottom: 0;
        }
        pre.log-output {
            background: #111;
            color: #0f0;
            padding: 15px;
            border-radius: 4px;
            height: 280px;
            overflow-y: auto;
            text-align: left;
            font-family: monospace;
            border: 1px solid #333;
        }
    </style>
</head>

<body>

<div class="navbar" style="display:none;"></div>

<div class="container content valign__middle" style="padding-top: 20px; padding-bottom: 40px;">
    <div class="row">
        <div class="col-md-10 col-md-offset-1 col-lg-8 col-lg-offset-2">
            
            <!-- Header Section -->
            <div class="text-center" style="margin-bottom: 15px; position: relative;">
                <img src="/img/nems_logo.png" class="img-responsive" style="margin: 0 auto; max-height: 80px;" />
                <span style="color: #aaa; font-size: 1.2em; display: block; margin-top: 5px;">For <?php echo $platform->name; ?></span>
                
                <a href="https://docs.nemslinux.com/en/latest/gettingstarted/initialization.html" target="_blank" class="btn btn-xs btn-default pull-right" style="position: absolute; right: 0; top: 10px; background: rgba(255,255,255,0.1); border-color: rgba(255,255,255,0.2); color: #ccc;">
                    <i class="fa fa-book"></i> Documentation
                </a>
            </div>

            <!-- Main Card -->
            <div class="init-box">
                <?php if ($in_progress): ?>

                    <!-- Active Progress Screen -->
                    <div class="text-center" style="padding: 10px 0;">
                        <h3 class="color-light" style="font-weight: bold; margin-top: 0;">
                            <i class="fa fa-circle-o-notch fa-spin text-danger" id="status-spinner"></i>
                            <span id="status-title">Initializing NEMS Linux...</span>
                        </h3>
                        <p class="color-light" id="status-subtext" style="font-size: 1.05em; margin-bottom: 20px;">
                            Please wait while your system is being provisioned. Do not close or refresh this page.
                        </p>
                    </div>

                    <div style="margin-top: 10px;">
                        <h5 class="color-light"><i class="fa fa-terminal"></i> Live Execution Log</h5>
                        <pre class="log-output" id="log-window">Starting initialization...</pre>
                    </div>

                <?php else: ?>

                    <!-- Setup Form -->
                    <h3 class="color-light text-center" style="font-weight: bold; margin-top: 0; margin-bottom: 25px;">NEMS Linux System Initialization</h3>

                    <?php if ($is_initialized): ?>
                        <div class="alert alert-warning text-center" style="margin-bottom: 25px;">
                            <strong>WARNING:</strong> This NEMS Linux server is already initialized. Re-initializing will wipe your current configuration database!
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($error)): ?>
                        <div class="alert alert-danger text-center" style="margin-bottom: 25px;"><?php echo htmlspecialchars($error); ?></div>
                    <?php endif; ?>

                    <!-- Superadmin Guidance Callout -->
                    <div class="superadmin-info-box">
                        <h5><i class="fa fa-shield"></i> Master Superadmin Account</h5>
                        <p><strong>IMPORTANT:</strong> This creates the system's root "god mode" account, granting unrestricted administrative control over NEMS Linux and all underlying monitoring services.</p>
                        <p><strong>RECOMMENDED PRACTICE:</strong> Use a role-based username rather than a personal name. Do not tie this master account to an individual or use it for daily tasks. After initialization, log in with this account to create sub-accounts for staff or family members.</p>
                    </div>

                    <form method="POST" action="">
                        <input type="hidden" name="action" value="nems_init">

                        <div class="form-group" style="margin-bottom: 20px;">
                            <label class="color-light" style="font-size: 1.05em;">Superadmin Username</label>
                            <input type="text" name="username" class="form-control dark-input" value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" placeholder="e.g., sysadmin" required>
                            <div class="help-block-custom">3-16 lowercase alphanumeric characters starting with a letter. Must be a role-based name.</div>
                        </div>

                        <div class="row" style="margin-bottom: 10px;">
                            <div class="col-sm-6 form-group">
                                <label class="color-light" style="font-size: 1.05em;">Superadmin Password</label>
                                <input type="password" name="password" class="form-control dark-input" required>
                                <div class="help-block-custom">Minimum 12 characters required.</div>
                            </div>
                            <div class="col-sm-6 form-group">
                                <label class="color-light" style="font-size: 1.05em;">Confirm Password</label>
                                <input type="password" name="password_confirm" class="form-control dark-input" required>
                            </div>
                        </div>

                        <div class="form-group" style="margin-bottom: 20px;">
                            <label class="color-light" style="font-size: 1.05em;">Notification Email Address</label>
                            <input type="email" name="email" class="form-control dark-input" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" placeholder="admin@domain.com" required>
                            <div class="help-block-custom">System alerts and monitoring notifications will be routed here.</div>
                        </div>

                        <div class="form-group" style="margin-bottom: 25px;">
                            <label class="color-light" style="font-size: 1.05em;">System Timezone</label>
                            <select name="timezone" class="form-control dark-input">
                                <?php foreach ($timezones as $tz): ?>
                                    <option value="<?php echo htmlspecialchars($tz); ?>" <?php echo ($tz === 'America/New_York' || $tz === $current_tz) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($tz); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <?php if ($is_initialized): ?>
                            <div class="checkbox form-group" style="margin-bottom: 25px; padding-left: 5px;">
                                <label class="color-light text-danger" style="font-weight: bold; font-size: 0.95em;">
                                    <input type="checkbox" name="force" value="1"> I understand this will erase my current configuration and re-initialize NEMS.
                                </label>
                            </div>
                        <?php endif; ?>

                        <div style="margin-top: 30px;">
                            <button type="submit" class="btn-u btn-u-lg btn-u-red btn-u-block rounded-4x" style="height: 50px; font-size: 1.1em; font-weight: bold; letter-spacing: 0.5px;">
                                <i class="fa fa-rocket"></i> INITIALIZE NEMS LINUX
                            </button>
                        </div>
                    </form>

                <?php endif; ?>
            </div>

            <?php
              if (file_exists('/var/www/html/vendor/logo.png')) {
                echo '<div class="text-center img-center"><p style="font-size: 0.5em; color:#aaa; margin: 30px 0 0px 0; padding: 0 !important;">Distributed By:</p>';
                if (file_exists('/var/www/html/vendor/url.txt')) {
                  $vendorurl = trim(file_get_contents('/var/www/html/vendor/url.txt'));
                  echo '<a style="margin: 0; padding: 0 0 20px 0;" href="' . $vendorurl . '" target="_blank"><img src="/vendor/logo.png" class="img-responsive" style="max-height:60px;" /></a>';
                } else {
                  echo '<img src="/vendor/logo.png" class="img-responsive" style="max-height:60px;" />';
                }
                echo '</div>';
              }
            ?>

        </div>
    </div>
</div>

<!-- Original Javascript Inclusions & Theme Engine -->
<script src="https://cdn.zecheriah.com/site-assets/1.9.6/One-Pages/Classic/assets/plugins/jquery/jquery-migrate.min.js"></script>
<script src="https://cdn.zecheriah.com/site-assets/1.9.6/One-Pages/Classic/assets/plugins/bootstrap/js/bootstrap.min.js"></script>
<script src="https://cdn.zecheriah.com/site-assets/1.9.6/One-Pages/Classic/assets/plugins/smoothScroll.js"></script>
<script src="https://cdn.zecheriah.com/site-assets/1.9.6/One-Pages/Classic/assets/plugins/jquery.easing.min.js"></script>
<script src="https://cdn.zecheriah.com/site-assets/1.9.6/One-Pages/Classic/assets/plugins/pace/pace.min.js"></script>
<script src="https://cdn.zecheriah.com/site-assets/1.9.6/One-Pages/Classic/assets/plugins/jquery.parallax.js"></script>
<script src="https://cdn.zecheriah.com/site-assets/1.9.6/One-Pages/Classic/assets/plugins/owl-carousel/owl.carousel.js"></script>
<script src="https://cdn.zecheriah.com/site-assets/1.9.6/One-Pages/Classic/assets/plugins/sky-forms-pro/skyforms/js/jquery.form.min.js"></script>
<script src="https://cdn.zecheriah.com/site-assets/1.9.6/One-Pages/Classic/assets/plugins/sky-forms-pro/skyforms/js/jquery.validate.min.js"></script>
<script src="https://cdn.zecheriah.com/site-assets/1.9.6/One-Pages/Classic/assets/plugins/cube-portfolio/cubeportfolio/js/jquery.cubeportfolio.min.js"></script>
<script src="https://cdn.zecheriah.com/site-assets/1.9.6/One-Pages/Classic/assets/plugins/modernizr.js"></script>
<script src="https://cdn.zecheriah.com/site-assets/1.9.6/One-Pages/Classic/assets/plugins/login-signup-modal-window/js/main.js"></script>
<script src="/js/jquery.backstretch.min.js"></script>

<!-- JS Page Level -->
<script src="https://cdn.zecheriah.com/site-assets/1.9.6/One-Pages/Classic/assets/js/one.app.js"></script>
<script src="https://cdn.zecheriah.com/site-assets/1.9.6/One-Pages/Classic/assets/js/forms/login.js"></script>
<script src="https://cdn.zecheriah.com/site-assets/1.9.6/One-Pages/Classic/assets/js/forms/contact.js"></script>
<script src="https://cdn.zecheriah.com/site-assets/1.9.6/One-Pages/Classic/assets/js/plugins/pace-loader.js"></script>
<script src="https://cdn.zecheriah.com/site-assets/1.9.6/One-Pages/Classic/assets/js/plugins/owl-carousel.js"></script>
<script src="https://cdn.zecheriah.com/site-assets/1.9.6/One-Pages/Classic/assets/js/plugins/cube-portfolio/cube-portfolio-lightbox.js"></script>

<script>
    jQuery(document).ready(function() {
        App.init();
        LoginForm.initLoginForm();
        ContactForm.initContactForm();
        OwlCarousel.initOwlCarousel();
    });
</script>

<?php
  $backgroundElem = 'body';
  require_once('/var/www/html/inc/wallpaper.php');
?>

<!--[if lt IE 9]>
    <script src="https://cdn.zecheriah.com/site-assets/1.9.6/One-Pages/Classic/assets/plugins/respond.js"></script>
    <script src="https://cdn.zecheriah.com/site-assets/1.9.6/One-Pages/Classic/assets/plugins/html5shiv.js"></script>
    <script src="https://cdn.zecheriah.com/site-assets/1.9.6/One-Pages/Classic/assets/js/plugins/placeholder-IE-fixes.js"></script>
    <script src="https://cdn.zecheriah.com/site-assets/1.9.6/One-Pages/Classic/assets/plugins/sky-forms-pro/skyforms/js/sky-forms-ie8.js"></script>
<![endif]-->

<!--[if lt IE 10]>
    <script src="https://cdn.zecheriah.com/site-assets/1.9.6/One-Pages/Classic/assets/plugins/sky-forms-pro/skyforms/js/jquery.placeholder.min.js"></script>
<![endif]-->

<span style="color: #333; position: absolute; right:0; bottom: 0; padding: 8px;">NEMS Linux <?php echo ver('nems'); ?></span>

<?php if ($in_progress): ?>
<script>
  var consecutiveErrors = 0;

  (function pollStatus() {
    $.ajax({
      url: 'init.php?action=get_status',
      type: 'GET',
      dataType: 'json',
      timeout: 3000,
      success: function(data) {
        consecutiveErrors = 0;

        if (data.log) {
          var logWin = $('#log-window');
          logWin.text(data.log);
          logWin.scrollTop(logWin[0].scrollHeight);
        }

        if (data.status === 'complete') {
          $('#status-spinner').removeClass('fa-spin fa-circle-o-notch text-danger').addClass('fa-check-circle text-success');
          $('#status-title').text('Initialization Complete!');
          $('#status-subtext').text('Redirecting to login screen...');
          setTimeout(function() {
            window.location.href = '/';
          }, 2000);
        } else if (data.status === 'error') {
          $('#status-spinner').removeClass('fa-spin fa-circle-o-notch text-danger').addClass('fa-times-circle text-danger');
          $('#status-title').text('Initialization Failed');
          $('#status-subtext').html('An error occurred during setup. Review log below or <a href="/init.php" class="text-danger"><u>click here to retry</u></a>.');
        } else {
          setTimeout(pollStatus, 2000);
        }
      },
      error: function() {
        consecutiveErrors++;

        if (consecutiveErrors >= 5) {
          window.location.href = '/';
        } else {
          setTimeout(pollStatus, 3000);
        }
      }
    });
  })();
</script>
<?php endif; ?>

</body>
</html>
