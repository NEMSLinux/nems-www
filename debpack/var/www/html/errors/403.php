<?php
  include('/var/www/html/inc/functions.php');
  if (!initialized()) { include('../init.php'); exit(); }

  include('/var/www/html/inc/auth.php');
  nems_secure_session_start();
  nems_require_login(); // redirect to login form if not logged in

  include('/var/www/html/inc/header.php');
?>
<div class="container" style="margin-top: 100px; padding-bottom: 100px;">
  <h2><b>Access Denied</b></h2>
  <p>User <em><?= $_SESSION['user']; ?></em> does not have sufficient access.</p>
</div>
<?php
  include('/var/www/html/inc/footer.php');
?>
