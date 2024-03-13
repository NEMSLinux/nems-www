<?php

// This is being executed via the NEMS SST GUI.
if (isset($_POST['SST'])) {

  if (isset($_POST['confirm']) && $_POST['confirm'] == true) {
    echo 'Rebooting...';
    shell_exec('sudo /sbin/reboot');
    exit();
  } else {
    exit('Not authorized.');
  }

} else {
  exit('Not authorized.');
}
