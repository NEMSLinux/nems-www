<?php
require_once('functions.php');

// This is being executed via the NEMS SST GUI.
if (isset($_POST['SST'])) {
  if (isset($_POST['confirm']) && $_POST['confirm'] == true) {
    waitForNEMS();
    echo 'Rebooting...';
    flush();
    shell_exec('sudo /sbin/reboot');
    exit();
  } else {
    exit('Not authorized.');
  }
} else {
  exit('Not authorized.');
}
flush();
