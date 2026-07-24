<?php
  include('/var/www/html/inc/functions.php');
  if (!initialized()) { include('../init.php'); exit(); }

  include('/var/www/html/inc/auth.php');
  nems_secure_session_start();
  nems_require_login(); // redirect to login form if not logged in

  include('/var/www/html/inc/header.php');
  $platform = ver('platform');
?>
<div class="container" style="margin-top: 100px; padding-bottom: 100px;">
  <h2><b>Welcome, <?= $_SESSION['user']; ?>.</b></h2>
  <p>You are logged in.</p>
</div>
<?php
  include('/var/www/html/inc/footer.php');
?>
