<?php
  error_reporting(E_ALL ^ E_NOTICE);

  include('/var/www/html/inc/functions.php');
  if (!initialized()) {
    include('../init.php');
    exit();
  }
  if (ver('nems') < 1.7) {
    exit('Requires NEMS 1.7+');
  }
  include('/var/www/html/inc/header.php');

  $platform = ver('platform');

?>

<div class="container" style="margin-top: 100px; padding-bottom: 100px;">
  <h2><b>NEMS Server</b> Power Controller</h2>
  <p>Safely reboot or power off your NEMS Server.</p>
  <form method="post" id="power" class="sky-form" style="border:none;">

    <section>
      <label class="label" style="color: #eee">Confirm this action</label>
      <div class="inline-group">
        <label class="checkbox light" style="color: #999"><input name="confirm" type="checkbox" id="confirmed"><i></i>Confirmed</label>
      </div>
    </section>

              <?php
                if (ver('nems') >= 1.7) {
                  echo '<a id="power-reboot" class="btn-u btn-u-orange"><i class="fa fa-refresh"></i> Reboot</a>';
                  echo '<div id="power-reboot-modal" class="modal fade" role="dialog"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button><h4 class="modal-title">Rebooting your NEMS Server...</h4></div><div class="modal-body"></div><div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">Close</button></div></div></div></div>';
                  echo "
                       <script>
                         $(document).ready(function(){
                             $('#power-reboot').click(function(e){
                             if ($('input#confirmed').prop('checked') === false) {
                               popup('Not rebooting: You did not confirm.');
                               return false;
                             }
                             $('#power-reboot-modal .modal-body').html('Waiting for NEMS background tasks to finish. Please Wait <i class=\"fa fa-spinner fa-pulse fa-fw\"></i><br /><br /><b>Note:</b> Even if you close this window, the reboot will occur in a moment.');
                             $('#power-reboot-modal').modal('show');
                             $.ajax({
                               url: './commands/reboot.php',
                               type: 'post',
                               data: {
                                 'SST':1,
                                 'confirm':$('input#confirmed').prop('checked')
                               },
                               success: function(response){
                                 $('#power-reboot-modal .modal-body').html(response);
                               }
                             });
                           });
                         });
                       </script>";


                  echo '<a id="power-halt" class="btn-u btn-u-red"><i class="fa fa-power-off"></i> Shutdown</a>';
                  echo '<div id="power-halt-modal" class="modal fade" role="dialog"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button><h4 class="modal-title">Powering off your NEMS Server...</h4></div><div class="modal-body"></div><div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">Close</button></div></div></div></div>';
                  echo "
                       <script>
                         $(document).ready(function(){
                           $('#power-halt').click(function(){
                             if ($('input#confirmed').prop('checked') === false) {
                               popup('Not shutting down: You did not confirm.');
                               return false;
                             }
                             $('#power-halt-modal .modal-body').html('Waiting for NEMS background tasks to finish. Please Wait <i class=\"fa fa-spinner fa-pulse fa-fw\"></i><br /><br /><b>Note:</b> Even if you close this window, the shutdown will occur in a moment.');
                             $('#power-halt-modal').modal('show');
                             $.ajax({
                               url: './commands/halt.php',
                               type: 'post',
                               data: {
                                 'SST':1,
                                 'confirm':$('input#confirmed').prop('checked')
                               },
                               success: function(response){
                                 $('#power-halt-modal .modal-body').html(response);
                               }
                             });
                           });
                         });
                       </script>";
                }
              ?>

</div>
</div>

</form>

</div>

<div id="dialog-overlay"></div>
<div id="dialog-box">
	<div class="dialog-content">
		<div id="dialog-message"></div>
		<a href="#" class="button">Close</a>
	</div>
</div>

<style>
#dialog-overlay {

	/* set it to fill the whil screen */
	width:100%; 
	height:100%;
	
	/* transparency for different browsers */
	filter:alpha(opacity=50); 
	-moz-opacity:0.5; 
	-khtml-opacity: 0.5; 
	opacity: 0.5; 
	background:#000; 

	/* make sure it appear behind the dialog box but above everything else */
	position:absolute; 
	top:0; left:0; 
	z-index:3000; 

	/* hide it by default */
	display:none;
}


#dialog-box {
	
	/* css3 drop shadow */
	-webkit-box-shadow: 0px 0px 10px rgba(0, 0, 0, 0.5);
	-moz-box-shadow: 0px 0px 10px rgba(0, 0, 0, 0.5);
	
	/* css3 border radius */
	-moz-border-radius: 5px;
    -webkit-border-radius: 5px;
	
	background:#eee;
	/* styling of the dialog box, i have a fixed dimension for this demo */ 
	width:328px; 
	
	/* make sure it has the highest z-index */
	position:absolute; 
	z-index:5000; 

	/* hide it by default */
	display:none;
}

#dialog-box .dialog-content {
	/* style the content */
	text-align:left; 
	padding:10px; 
	margin:13px;
	color:#666; 
	font-family:arial;
	font-size:11px; 
}

a.button {
	/* styles for button */
	margin:10px auto 0 auto;
	text-align:center;
	display: block;
	width:50px;
	padding: 5px 10px 6px;
	color: #fff;
	text-decoration: none;
	font-weight: bold;
	line-height: 1;
	
	/* button color */
	background-color: #e33100;
	
	/* css3 implementation :) */
	/* rounded corner */
	-moz-border-radius: 5px;
	-webkit-border-radius: 5px;
	
	/* drop shadow */
	-moz-box-shadow: 0 1px 3px rgba(0,0,0,0.5);
	-webkit-box-shadow: 0 1px 3px rgba(0,0,0,0.5);
	
	/* text shaow */
	text-shadow: 0 -1px 1px rgba(0,0,0,0.25);
	border-bottom: 1px solid rgba(0,0,0,0.25);
	position: relative;
	cursor: pointer;
	
}

a.button:hover {
	background-color: #c33100;	
}

/* extra styling */
#dialog-box .dialog-content p {
	font-weight:700; margin:0;
}

#dialog-box .dialog-content ul {
	margin:10px 0 10px 20px; 
	padding:0; 
	height:50px;
}
</style>

<script>
$(document).ready(function () {

	// if user clicked on button, the overlay layer or the dialogbox, close the dialog	
	$('a.btn-ok, #dialog-overlay, #dialog-box').click(function () {		
		$('#dialog-overlay, #dialog-box').hide();		
		return false;
	});
	
	// if user resize the window, call the same function again
	// to make sure the overlay fills the screen and dialogbox aligned to center	
	$(window).resize(function () {
		
		//only do it if the dialog box is not hidden
		if (!$('#dialog-box').is(':hidden')) popup();		
	});	
	
	
});

//Popup dialog
function popup(message) {
		
	// get the screen height and width  
	var maskHeight = $(document).height();  
	var maskWidth = $(window).width();
	
	// calculate the values for center alignment
	var dialogTop =  (maskHeight/3) - ($('#dialog-box').height());  
	var dialogLeft = (maskWidth/2) - ($('#dialog-box').width()/2); 
	
	// assign values to the overlay and dialog box
	$('#dialog-overlay').css({height:maskHeight, width:maskWidth}).show();
	$('#dialog-box').css({top:dialogTop, left:dialogLeft}).show();
	
	// display the message
	$('#dialog-message').html(message);
			
}
</script>
<?php
  include('/var/www/html/inc/footer.php');
?>
