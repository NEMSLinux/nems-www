<?php
  include('/var/www/html/inc/functions.php');
  if (!initialized()) {
    include('../init.php');
    exit();
  }
  include('/var/www/html/inc/header.php');
  $platform = ver('platform');

  // Define period configurations and target image files
  $periods = [
    'day'   => ['label' => 'Day',   'title' => 'One Day Overview',   'code' => 'd', 'file' => './img/system1z.1day.png'],
    'week'  => ['label' => 'Week',  'title' => 'One Week Overview',  'code' => 'w', 'file' => './img/system1z.1week.png'],
    'month' => ['label' => 'Month', 'title' => 'One Month Overview', 'code' => 'm', 'file' => './img/system1z.1month.png'],
    'year'  => ['label' => 'Year',  'title' => 'One Year Overview',  'code' => 'y', 'file' => './img/system1z.1year.png'],
  ];

  // Filter periods to only those with existing files
  $available_periods = [];
  foreach ($periods as $id => $info) {
    if (file_exists($info['file'])) {
      $available_periods[$id] = $info;
    }
  }
?>

<div class="container" style="margin-top: 100px; padding-bottom: 100px;">
  <p><img src="./logo.png" /></p>

<?php if (!empty($available_periods)): ?>
  <div class="tab-v1">
    <ul class="nav nav-tabs">
      <?php
        $is_first = true;
        foreach ($available_periods as $id => $info):
      ?>
        <li class="<?= $is_first ? 'active' : '' ?>"><a href="#<?= $id ?>" data-toggle="tab"><?= $info['label'] ?></a></li>
      <?php
        $is_first = false;
        endforeach;
      ?>
    </ul>

    <div class="tab-content">
      <?php
        $is_first = true;
        foreach ($available_periods as $id => $info):
      ?>
        <div class="tab-pane fade in <?= $is_first ? 'active' : '' ?>" id="<?= $id ?>">
          <div class="row">
            <div class="col-md-12">
              <h4>NEMS Linux &ndash; <b><?= $info['title'] ?></b></h4>
              <p>Updated <?= date("F d Y H:i:s", filemtime($info['file'])) ?></p>
              <?php
                $images = loadMonitorix($info['code']);
                if (is_array($images) && count($images) > 0) {
                  foreach ($images as $image) {
                    echo PHP_EOL . '                                <div class="row"><div class="text-center col-md-12 col-xs-12"><img src="./img/' . $image . '" style="margin: 10px auto;" class="img-responsive" /></div></div>';
                  }
                }
              ?>
            </div>
          </div>
        </div>
      <?php
        $is_first = false;
        endforeach;
      ?>
    </div>
  </div>
<?php else: ?>
  <div class="row">
    <div class="col-md-12">
      <p>When you first boot your NEMS Server, all Monitorix data and graphs are reset. It takes some time for Monitorix to be ready.</p>
      <p>Please check back soon.</p>
    </div>
  </div>
<?php endif; ?>

<p><span style="color:#444;">Powered By</span> <a href="http://www.monitorix.org/" target="_blank">Monitorix</a> by Jordi Sanfeliu</p>

</div>
<?php
  include('/var/www/html/inc/footer.php');
?>
