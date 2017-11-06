<?php
/*This File is for managing all settings of the Arcavis in the admin area.*/

class WooCommerce_Arcavis_Shop_Admin_Settings{
	
	public function __construct(){
		add_action('admin_menu', array($this,'arcavis_setting'));
		add_action( 'admin_enqueue_scripts', array($this,'arcavis_setting_scripts_admin'));
		
	}

	public function arcavis_setting_scripts_admin(){
		wp_enqueue_style( 'aracavis_style', plugin_dir_url( __FILE__ ).'../css/arcavis.css' );
		wp_enqueue_script('aracavis_script',plugin_dir_url( __FILE__ ).'../js/script.js');
	}

	

	public function arcavis_setting() {
		add_options_page('Arcavis Settings', 'Arcavis Settings', 'manage_options', 'arcavis_settings', array($this,'arcavis_setting_page'));
	}

	public function get_arcavis_settings(){
		$arcavis_settings = get_option( 'arcavis_settings' );
		return unserialize($arcavis_settings);
	}
	
	public function arcavis_setting_page(){
		screen_icon();
		 $setting_option = $this->get_arcavis_settings();
		 ?>
		<script type="text/javascript">
		var website_url = '<?php echo site_url(); ?>';
		</script>

		<div id="arcavis_preloader">
		<div>
			<h3>Synchronization is started, this process take several minutes to complete..</h3>
			<h4>Do not reload the page...</h4>
			<img src="<?php echo site_url('wp-content/plugins/woocommerce-arcavis-shop/includes/images/Spinner.gif'); ?>">  
		</div>	
		</div>
		<div class="arcavis_setting_page">
			<h2>Arcavis Shop Setting</h2>	
			<div class="postbox-container">
			  
		  <?php 
		  if(isset($_POST["arcavis_link"])) {

		  		$arcavis_settings = array('arcavis_link' => rtrim($_POST["arcavis_link"],'/'),
					  'arcavis_username' => $_POST["arcavis_username"],
					  'arcavis_password' => $_POST["arcavis_password"],
					  'arcavis_sync_interval' => $_POST["arcavis_sync_interval"]
					  );
		  		update_option('arcavis_settings',serialize($arcavis_settings),true);
		  		
		  		
		     ?>
		     <div class="notice notice-success is-dismissible">
       		 <p>Setting Saved.</p>
       		
       		 <?php
       		 if(rtrim($_POST["arcavis_link"],'/') != $setting_option['arcavis_link'] || $_POST["arcavis_username"] != $setting_option['arcavis_username']){
       		 	?>
       		 	 <script type="text/javascript">
       		 	 	sync_on_save_api('yes');
       		 	 
       		 	 </script>
       		 	<?php

       		 }
       		 ?>
       		 
   			 </div>
   			 <?php
		  }
		 /* if(isset($_POST['full_re_sync']) && $_POST['full_re_sync'] == 'Full Re-Sync'){
		  		$this->full_re_sync();
		  }*/
		  ?>
		  <?php $arcavis_settings = $this->get_arcavis_settings(); ?>
		  <form method="post" action="">
		 
		  <h3>Enter your Arcavis credentials to connect with Arcavis</h3>
		  <table width="100%">

		  <tr valign="top">
		  <th scope="row"><label for="arcavis_link">Arcavis</label></th>
		  <td><input type="url" id="arcavis_link" name="arcavis_link" required="required" value="<?php echo isset($arcavis_settings['arcavis_link']) ? $arcavis_settings['arcavis_link'] : ''; ?>" /></td>
		  </tr>

		  <tr valign="top">
		  <th scope="row"><label for="arcavis_username">Username</label></th>
		  <td><input type="text" id="arcavis_username" name="arcavis_username" required="required" value="<?php echo isset($arcavis_settings['arcavis_username']) ? $arcavis_settings['arcavis_username'] : ''; ?>" /></td>
		  </tr>

		   <tr valign="top">
		  <th scope="row"><label for="arcavis_password">Password</label></th>
		  <td><input type="password" id="arcavis_password" name="arcavis_password" required="required" value="<?php echo isset($arcavis_settings['arcavis_password']) ? $arcavis_settings['arcavis_password'] : ''; ?>" /></td>
		  </tr>

		   <tr valign="top">
		  <th scope="row"><label for="arcavis_sync_interval">Sync-Interval</label></th>
		  <td><input type="number" id="arcavis_sync_interval" name="arcavis_sync_interval" min="10" max="60" required="required" value="<?php echo ($arcavis_settings['arcavis_sync_interval'] == '' && isset($arcavis_settings['arcavis_sync_interval'])) ? 60 : $arcavis_settings['arcavis_sync_interval']; ?>" /> min</td>
		  </tr>
		  </table>
		  <input name="submit" id="submit" class="button button-primary" value="Save and Start Sync" type="submit" onclick="return confirm('Are you sure you want to Reset shop data, this action may Delete all products of the shop ? ')">
		  <?php  //submit_button(); ?>
		  </form>
		  </div>

		  <div class="postbox-container">
			  <form action="" method="post" >	
			   <h3>Click here to Reset all arcavis data.</h3>		  
			  <input type="button" name="" value="Full Re-Sync" class="button arcavis_danger" id="start_sync" >	
			  </form>
		  </div>

		  
		  </div>
		<?php
	}

	

	
}

?>