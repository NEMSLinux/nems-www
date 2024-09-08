<?php
  require_once('bgcolor.php');
?><!DOCTYPE html>
<!--[if IE 8]> <html lang="en" class="ie8"> <![endif]-->
<!--[if IE 9]> <html lang="en" class="ie9"> <![endif]-->
<!--[if !IE]><!--> <html lang="en"> <!--<![endif]-->
<head>
  <?php
    $nemsalias = trim(shell_exec('/usr/local/bin/nems-info alias'));
    echo '  <title>';
    echo $whitelabel->name;
    if (isset($nemsalias) && strtoupper($nemsalias) != 'NEMS') {
      echo ': ' . $nemsalias;
    } else {
      echo ' Server';
    }
    echo '</title>';
  ?>
	<!-- Meta -->
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta name="author" content="Robbie Ferguson - https://baldnerd.com/">
        <meta name="robots" content="noindex">

	<!-- Favicon -->
	<?php
		if ($whitelabel->enabled == 1) {
		        echo '<link rel="shortcut icon" href="/img/whitelabel.php?f=favicon">';
		} else {
		        echo '<link rel="shortcut icon" href="/favicon.ico">';
		}
	?>

        <!-- Nav bar color -->
        <meta name="theme-color" content="#<?= $bgcolorDark ?>">

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
	<link rel="stylesheet" href="/css/font-awesome.min.css?ipadsucks=1">
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
	<link rel="stylesheet" href="/css/custom.css?v=1.00">

        <!-- JS Global Compulsory -->
        <script src="/js/jquery.min.js"></script>
        <script src="/js/moment.min.js"></script>

        <script language="javascript" type="text/javascript">
          function resizeIframe(obj) {
            obj.style.height = obj.contentWindow.document.body.scrollHeight + 'px';
          }
        </script>
        <style>
          .navbar, .top-nav-collapse {
            background: rgba(<?= $bgcolorDarkRGB[0] ?>,<?= $bgcolorDarkRGB[1] ?>,<?= $bgcolorDarkRGB[2] ?>,90%) !important;
          }
        </style>
</head>

<!--
The #page-top ID is part of the scrolling feature.
The data-spy and data-target are part of the built-in Bootstrap scrollspy function.
-->
<body id="body" data-spy="scroll" data-target=".one-page-header" class="demo-lightbox-gallery dark">

	<!--=== Header ===-->
	<nav class="one-page-header one-page-header-style-2 navbar navbar-default navbar-fixed-top" role="navigation">
		<div class="container">
			<div class="menu-container page-scroll">
				<button type="button" class="navbar-toggle" data-toggle="collapse" data-target=".navbar-ex1-collapse">
					<span class="sr-only">Toggle navigation</span>
					<span class="icon-bar"></span>
					<span class="icon-bar"></span>
					<span class="icon-bar"></span>
				</button>

				<a class="navbar-brand" href="/">
					<?php
						if ($whitelabel->enabled == 1) {
							echo '<img src="/img/whitelabel.php?f=logo" id="logo-header" alt="' . $whitelabel->name . '" />';
						} else {
		                                        echo '<img src="/img/nems_logo.png" id="logo-header" alt="NEMS Linux" />';
						}
					?>
				</a>
			</div>

			<!-- Collect the nav links, forms, and other content for toggling -->
			<div class="collapse navbar-collapse navbar-ex1-collapse">
				<div class="menu-container">
					<ul class="nav navbar-nav">

						<li class="dropdown">
							<a href="javascript:void(0);" class="dropdown-toggle" data-toggle="dropdown">
							  Configuration
							</a>
							<ul class="dropdown-menu">
							  <?php if (ver('nems') >= 1.3) echo '<li><a href="https://' . $self->host . '/info/">Server Overview</a></li>'; ?>
							  <?php if (ver('nems') >= 1.3) echo '<li><a href="https://' . $self->host . '/config/">System Settings Tool</a></li>'; ?>
							  <li><a href="/nconf/" target="_blank">Configurator</a></li>
							</ul>
						</li>

						<li class="dropdown">
							<a href="javascript:void(0);" class="dropdown-toggle" data-toggle="dropdown">
							  Reporting
							</a>
							<ul class="dropdown-menu">

                                                          <li><h4 style="padding: 0 0 4px 4px; margin-bottom: 0;">Modern</h4></li>
							  <?php if (ver('nems') >= 1.4) echo '<li><a href="/adagios/" target="_blank">Adagios</a></li>'; ?>
							  <?php if (ver('nems') >= 1.4) echo '<li><a href="/mobile/" target="_blank">Mobile UI</a></li>'; ?>
							  <?php if (ver('nems') >= 1.4) echo '<li><a href="/tv/" target="_blank">TV Dashboard</a></li>'; ?>
							  <?php if (ver('nems') >= 1.6 || file_exists('/var/www/nagiostv')) echo '<li><a href="/nagiostv/" target="_blank">Tactical Overview</a></li>'; ?>
							  <?php
  $cloudauth = intval(shell_exec('/usr/local/bin/nems-info cloudauthcache'));
  if ($cloudauth == 1) echo '<li><a href="/cloud/" target="_blank">NEMS Cloud Services Dashboard</a></li>';
?>

                                                          <li><h4 style="padding: 0 0 4px 4px; margin: 0;">Legacy</h4></li>
							  <?php
							    if (ver('nems') >= 1.4) {
							      echo '<li><a href="/nagios/" target="_blank">Nagios Core</a></li>';
                                                            } else {
							      echo '<li><a href="/nagios3/" target="_blank">Nagios Core</a></li>';
							    }
							  ?>
							  <li><a href="/nagvis/" target="_blank">NagVis</a></li>
							  <?php if (ver('nems') < 1.4) echo '<li><a href="/check_mk/" target="_blank">Check_MK Multisite</a></li>'; ?>
							  <?php if (ver('nems') >= 1.5) echo '<li><a href="/nagios/cgi-bin/show.cgi" target="_blank">Nagios Graphs</a></li>'; ?>
							</ul>
						</li>

						<li class="dropdown">
							<a href="javascript:void(0);" class="dropdown-toggle" data-toggle="dropdown">
							  System
							</a>

							<ul class="dropdown-menu">
 							  <?php if (checkConfEnabled('monitorix')) { echo '<li><a href="/monitorix/">Monitorix</a></li>'; } ?>
							  <?php if (ver('nems') >= 1.4 && ver('platform')->num != 21) echo '<li><a href="https://' . $self->host . ':9090" target="_blank">Cockpit</a></li>'; ?>
							  <?php if (ver('nems') >= 1.7) echo '<li><a href="/power">Power Controller</a></li>'; ?>
							  <?php if ((ver('platform')->num < 10 || (ver('platform')->num >= 150 && ver('platform')->num <= 199)) && checkConfEnabled('rpi-monitor')) { echo '<li><a href="http://' . $self->host . ':8888" target="_blank">RPi-Monitor</a></li>'; } ?>
							  <?php if (ver('nems') >= 1.3) echo '<li><a href="https://' . $self->host . ':2812" target="_blank"><em>monit</em> Service Monitor</a></li>'; ?>
							  <?php if (file_exists('/usr/lib/systemd/system/glancesweb.service')) echo '<li><a href="http://' . $self->host . ':61208" target="_blank">Glances</a></li>'; ?>
							</ul>
						</li>

						<li class="dropdown">
							<a href="/backup/nems-migrator/">Migrator</a>
						</li>

						<li class="dropdown" <?php if ($whitelabel->enabled == 1) echo 'style="display:none;"'; ?>>
							<a href="javascript:void(0);" class="dropdown-toggle" data-toggle="dropdown">
								Support Us
							</a>
							<ul class="dropdown-menu">
                						<li><a href="https://patreon.com/nems" target="_blank"><i class="fa fa-fw fa-user"></i> Become a Patron</a></li>
								<li><a href="https://shop.nemslinux.com/" target="_blank"><i class="fa fa-fw fa-shopping-cart"></i> NEMS Merch</a></li>
								<li><a href="https://category5.tv/shop/category/sbc" target="_blank"><i class="fa fa-fw fa-desktop"></i> Buy SBCs and Accessories</a></li>
               							<li><a href="https://donate.category5.tv/" target="_blank"><i class="fa fa-fw fa-credit-card"></i> Donate</a></li>
            						</ul>
						</li>

						<li class="dropdown" <?php if ($whitelabel->enabled == 1) echo 'style="display:none;"'; ?>>
							<a href="javascript:void(0);" class="dropdown-toggle" data-toggle="dropdown">
								Get Help
							</a>
							<ul class="dropdown-menu">
						                <li><a href="https://docs.nemslinux.com/" target="_blank">NEMS Documentation</a></li>
								<li><a href="https://www.patreon.com/bePatron?c=1348071&rid=2163018" target="_blank">Priority Support</a></li>
								<li><a href="https://discord.gg/e9xT9mh" target="_blank">Discord Server</a></li>
                						<li><a href="https://nemslinux.com/" target="_blank">Official NEMS Linux Web Site</a></li>
					                </ul>
						</li>

					</ul>
				</div>
			</div>
			<!-- /.navbar-collapse -->
		</div>
		<!-- /.container -->
	</nav>
	<!--=== End Header ===-->

