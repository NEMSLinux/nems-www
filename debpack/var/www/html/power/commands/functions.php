<?php
function waitForNEMS() {
  $updating = intval(trim(shell_exec('/usr/local/bin/nems-info quickfix')));
  if ($updating == 1) {
    $iteration = 0;
//    echo 'Waiting for NEMS Linux to finish updating. Please wait...<br />';
    echo 'Waiting for NEMS background tasks to finish. Please Wait.<br />'; // should match ../index.php ajax message as this replaces it on complete
//    flush();
    while ($updating == 1) {
      sleep(5);
/*
      if ($iteration++ == 5) {
        $fortune = shell_exec('/usr/local/share/nems-fortune/fortune.sh');
        echo '<b>ChatGPT says:</b> <i>' . $fortune . '</i><br />';
        flush();
        $iteration = 0;
      }
*/
      $updating = intval(trim(shell_exec('/usr/local/bin/nems-info quickfix')));
    }
    echo 'NEMS Linux is ready to proceed.<br />';
    flush();
  }
}
