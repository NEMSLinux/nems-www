<?php
  $filerequest = filter_var($_GET['f'], FILTER_SANITIZE_STRING);
  switch ($filerequest) {
    case 'favicon':
        header('Content-type: image/x-icon');
	$file = '/usr/local/share/whitelabel/favicon.ico';
        break;
    case 'logo':
        header('Content-type: image/jpeg');
	$file = '/usr/local/share/whitelabel/logo.jpg';
        break;
    case 'background':
        header('Content-type: image/jpeg');
	$file = '/usr/local/share/whitelabel/background.jpg';
        break;
  }
  if (isset($file)) {
    readfile($file);
  } else {
    die('e');
  }
?>
