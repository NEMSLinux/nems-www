<?php

// This is being tested via the NEMS SST GUI.
if (isset($_POST['SST'])) {

  if (isset($_POST['confirm']) && intval($_POST['confirm']) == 1) {
    echo 'Shutting Down...';
    shell_exec('/sbin/reboot');
    exit();
  }

  echo 'Rebooting...';
  shell_exec('sudo /sbin/shutdown -h now');
  exit();

}
