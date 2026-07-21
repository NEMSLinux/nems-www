<?php
declare(strict_types=1);
include('/var/www/html/inc/functions.php');
if (!initialized()) { include('./init.php'); exit(); }

include('/var/www/html/inc/auth.php');
nems_secure_session_start();

nems_logout_session();
header('Location: /login/');
