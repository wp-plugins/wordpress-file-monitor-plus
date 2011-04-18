<?php
/*
Plugin Name: WordPress File Monitor Plus
Plugin URI: http://l3rady.com/projects/wordpress-file-monitor-plus/
Description: Monitor your website for added/changed/deleted files
Author: Scott Cariss
Version: 1.0
Author URI: http://l3rady.com/
*/

/*  Copyright 2011  Scott Cariss  (email : scott@l3rady.com)

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

// Only load class if it hasn't already been loaded
if (!class_exists('sc_WordPressFileMonitorPlus')) {

	// Wordpress File Monitor Plus Class - All the magic happens here!
	class sc_WordPressFileMonitorPlus {
		var $settings = array(); // Array to hold all plugin settings
		var $oldScanData = array(); // When scan is done this is populated from the old scan data
		var $newScanData = array();  // Holds new scan data when scan is done
		var $scanRan = false;  // Keep track of when a scan is run so we can show a message to say its completed
		var $settingsUpdated = false; // Same as above but this time for settings updated
		var $settingsFailed = false;  // Same as above but this time for settings failed
		var $settingsFailedMessage = ""; // When settings save fails this is populated with reason why.
		var $cronIntervals = array(); // Will store cron intervals


		/**
		 * Created to cater for PHP 4
		 *
		 * Pointless at the moment as there are a few
		 * functions that are in use that are not supported
		 * by PHP 4. Later version of plugin I'll put this right.
		*/
		function sc_WordPressFileMonitorPlus() {
			$this->__construct();
		} // end sc_WordPressFileMonitorPlus function


		function __construct() {
			// Setup cron intervals
			$this->cronIntervals = array( // Out of the box wordpress only supports 3 cron intervals but you are welcome to add your
								    	// own here. However you need to write your own filter to add this new intervals.
									   "hourly" => array("name" => __("Hourly", "wordpress-file-monitor-plus"), "seconds" => 3600), 
									   "twicedaily" => array("name" => __("Twice Daily", "wordpress-file-monitor-plus"), "seconds" => 43200), 
									   "daily" => array("name" => __("Daily", "wordpress-file-monitor-plus"), "seconds" => 86400), 
									   "manual" => array("name" => __("Manual", "wordpress-file-monitor-plus"), "seconds" => 0)
								   );
			// Create Activation and Deactivation Hooks
			register_activation_hook(__FILE__, array(&$this, 'activate'));
			register_deactivation_hook(__FILE__, array(&$this, 'deactive'));
			// Internationalization
			load_plugin_textdomain('wordpress-file-monitor-plus', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/');
			// Load settings
			$this->settings = maybe_unserialize(get_option('sc_wpfmp_settings'));
			// Define the permission to see/read/remove admin alert if not already set in config
			if(!defined('SC_WPFMP_ADMIN_ALERT_PERMISSION')) {define('SC_WPFMP_ADMIN_ALERT_PERMISSION', 'manage_options');}
			// Add Menu to options
			add_action('admin_menu', array(&$this, 'create_admin_pages'));
			// Check for things to do when needed
			add_action('init', array(&$this, 'things_to_do'));
			// Add settings link to plugin in plugin list
			add_filter('plugin_action_links', array(&$this, 'plugin_action_links'), 10, 2);
			// Create a hook for scanning
			add_action('sc_wpfmp_scan', array(&$this, 'scan'));
			// Admin alert show in dashboard
			add_action('admin_notices', array(&$this, 'admin_alert'));
		} // End __construct function


		/**
		 * What to do when plugin is activated
		 *
		 * On plugin activate check if settings exist, if not create some
		 * default values and save to wordpress options database table.
		 * Then run enable cron function to see if a cron should be created.
		*/
		function activate() {
			
			// Check that wordpress is running version 3.1 or greater.
			// This plugin most probably works on lower versions but as this is to aid
			// in your wordpress security then you have already failed if you cannot keep
			// wordpress up to date.
			if ( version_compare( get_bloginfo( 'version' ), '3.1', '<' ) ) {
				deactivate_plugins( basename( __FILE__ ) ); // Deactivate this plugin
			}
			
			// are our settings already set in the db?
			if (!is_array($this->settings) || empty($this->settings)) {
				// Nope lets load in some default values
				$this->settings = array(
					'cron_method' => 'wordpress', // Cron method to be used for scheduling scans
					'file_check_interval' => 'daily', // How often should the cron run
					'notify_by_email' => 1, // Do we want to be notified by email when there is a file change?
					'from_address' => get_option('admin_email'), // Email address the notification comes from
					'notify_address' => get_option('admin_email'), // Email address the notification is sent to
					'site_root' => realpath(ABSPATH), // The file check path to start checking files from
					'exclude_paths' => array(), // What exact directories should we ignore?
					'exclude_files' => array(), // What exact files should we ignore?
					'exclude_paths_wild' => array(), // What directory names should we ignore?
					'exclude_files_wild' => array(), // What file names should we ignore?
					'file_check_use_size' => 1, // Should we log the filesize of files?
					'file_check_use_modified' => 1, // Should we log the modified date of files?
					'file_check_use_md5' => 1, // Should we log the hash of files using md5_file()?
					'display_admin_alert' => 1, // Do we allow the plugin to notify us when there is a change in the admin area?
					'is_admin_alert' => 0, // Is there a live admin alert?
					'security_key' => sha1(microtime(true).mt_rand(10000,90000)) // Generate a random key to be used for Other Cron Method
					// The security key is only shown to the admin and has to be used for triggering a manual scan via an external cron.
					// This is to stop non admin users from being able to trigger the cron and potentially abuse server resources.
				);
				// Save settings to wordpress options db table
				add_option('sc_wpfmp_settings', maybe_serialize($this->settings), NULL, 'no');
			} // End if settings exist
			
			// Does a wordpress cron need enabling?
			$this->enable_cron();
		} // End install function


		/**
		 * What to do when plugin is deactivated
		 *
		 * On plugin deactivation all we need to do
		 * is remove any wordpresscron if there is one.
		*/
		function deactive() {
			// Go disable any wordpress cron we may have setup
			$this->disable_cron();
		} // End deactive function


		/**
		 * Create Admin page links and queue up any needed JS and CSS
		 *
		 * Create link under wordpress admin options to WPFMP settings page.
		 * Make sure WPFMP JavaScript is loaded only on the settings page. Only need it there.
		 * If admin alert is active and there is one then make sure thickbox JS and CSS is
		 * loaded on all admin pages.
		*/
		function create_admin_pages() {
			$page = add_options_page('WordPress File Monitor Plus', 'WordPress File Monitor Plus', 'manage_options', 'wordpress-file-monitor-plus', array(&$this, 'settings_page'));
			// Add my plugin js just to
			add_action("admin_print_scripts-$page", array(&$this, 'create_admin_pages_scripts'));
			
			// Make sure thickbox is loaded on all admin pages if admin alert is active, there is one and user has permission
			if($this->settings['is_admin_alert'] == 1 && $this->settings['display_admin_alert'] == 1 && current_user_can(SC_WPFMP_ADMIN_ALERT_PERMISSION)) {
				add_action("admin_print_scripts", array(&$this, 'create_admin_pages_tbscripts'));
				add_action("admin_print_styles", array(&$this, 'create_admin_pages_tbstyles'));
			} // End if admin alert
			
		} // End create_admin_pages


		function create_admin_pages_scripts() {
			// Load WPFMP JS, needs jQuery and can be loaded in the footer
			wp_enqueue_script('wordpress_file_monitor_plus_js_function', plugins_url('js/function.js', __FILE__), array('jquery'), '1.0', true);
		} // End create_admin_pages_scripts function


		function create_admin_pages_tbscripts() {
			// Load ThickBox JS
			wp_enqueue_script('thickbox');
		} // End create_admin_pages_tbscripts function


		function create_admin_pages_tbstyles() {
			// Load ThickBox CSS
			wp_enqueue_style('thickbox');
		} // End create_admin_pages_tbstyles function


		/**
		 * WPFMP settings page. Configure all the plugin settings here
		 *
		 * Two forms on this page. First one is to trigger a manual scan and second one
		 * that has all the form elements to configure the plugin. At the top we are checking
		 * for sucessful and unsucessful saving of settings and show notices if needed.
		 * Not that the loaded WPFMP JS will show hide some settings based on other settings.
		 *
		 * Next version I will look at using WordPress Settings API. For now this will do :)
		*/
		function settings_page() {
			?>
			<div class="wrap">
            	<div id="icon-options-general" class="icon32">&nbsp;</div>
				<h2><?php _e("WordPress File Monitor Plus Settings", "wordpress-file-monitor-plus"); ?></h2>
                <?php if ($this->settingsUpdated === true) : // Were the settings saved sucessfully? ?>
                	<div class="updated"><p><strong><?php _e("Settings Updated", "wordpress-file-monitor-plus"); ?></strong></p></div>
                <?php endif; ?>
                <?php if($this->scanRan === true) : // Was a manual scan run? ?>
                	<div class="updated"><p><strong><?php _e("Manual Scan Complete", "wordpress-file-monitor-plus"); ?></strong></p></div>
                <?php endif; ?>
                <?php if ($this->settingsFailed === true) : // Were there errors when saving? ?>
                	<div class="error">
                      <p><strong><?php _e("Error", "wordpress-file-monitor-plus"); ?></strong></p>
                      <p><?php _e("Settings have not been saved. Please rectify the following issues and resave:", "wordpress-file-monitor-plus"); ?></p>
                      <p><?php echo $this->settingsFailedMessage; // Show what errors happened ?></p>
                    </div>
                <?php endif; ?>
				<form style="float:left; clear:both" name="sc_wpfmp_manual_scan" action="" method="post">
					<?php wp_nonce_field('sc-wpfmp-update-settings'); ?>
					<input type="hidden" name="sc_wpfmp_action" value="manual_scan" />
					<table class="form-table">
						<tr>
							<td><p class="submit"><input type="submit" name="Submit" value="<?php _e("Perform Manual Scan Now", "wordpress-file-monitor-plus"); ?>" /></p></td>
						</tr>
					</table>
				</form>
                <form style="float:left; clear:both" name="sc_wpfmp_settings" action="" method="post">
                	<?php wp_nonce_field('sc-wpfmp-update-settings'); ?>
                    <input type="hidden" name="sc_wpfmp_action" value="update_settings" />
                    <table class="form-table">
                        <tr>
                            <td valign="middle"><label for="sc_wpfmp_form_cron_method"><?php _e("Cron Method: ", "wordpress-file-monitor-plus"); ?></label></td>
                            <td valign="middle">
								<?php $selected[$this->settings['cron_method']] = " selected=\"selected\""; ?>
                                <select id="sc_wpfmp_form_cron_method" name="cron_method">
                                    <option value="wordpress"<?php echo $selected['wordpress']; ?>><?php _e("WordPress Cron", "wordpress-file-monitor-plus"); ?></option>
                                    <option value="other"<?php echo $selected['other']; ?>><?php _e("Other Cron", "wordpress-file-monitor-plus"); ?></option>
                                    </select>
                                <?php unset($selected); ?>
                            </td>
                        </tr>
                        <tr id="sc_wpfmp_cron_other">
                            <td valign="middle"><?php _e("Cron Command:", "wordpress-file-monitor-plus"); ?> </td>
                            <td valign="middle"><pre>wget <?php echo site_url(); ?>/index.php?sc_wpfmp_scan=1&amp;sc_wpfmp_key=<?php echo $this->settings['security_key']; ?></pre></td>
                        </tr>
                        <tr id="sc_wpfmp_cron_wordpress">
                            <td valign="middle"><label for="sc_wpfmp_form_file_check_interval"><?php _e("File Check Interval: ", "wordpress-file-monitor-plus"); ?></label></td>
                            <td valign="middle">
								<?php $selected[$this->settings['file_check_interval']] = " selected=\"selected\""; ?>
                                <select id="sc_wpfmp_form_file_check_interval" name="file_check_interval">
                                	<?php foreach($this->cronIntervals as $key => $data) : // Loop through available cron intervals set at the top of this plugin ?>
                                    	<option value="<?php echo $key; ?>"<?php echo $selected[$key]; ?>><?php echo $data['name']; ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <?php unset($selected); ?>
                            </td>
                        </tr>
                        <tr>
                            <td valign="middle"><label for="sc_wpfmp_form_notify_by_email"><?php _e("Notify By Email: ", "wordpress-file-monitor-plus"); ?></label></td>
                            <td valign="middle">
								<?php $selected[(int)$this->settings['notify_by_email']] = " selected=\"selected\""; ?>
                                <select id="sc_wpfmp_form_notify_by_email" name="notify_by_email">
                                    <option value="1"<?php echo $selected[1]; ?>><?php _e("Yes", "wordpress-file-monitor-plus"); ?></option>
                                    <option value="0"<?php echo $selected[0]; ?>><?php _e("No", "wordpress-file-monitor-plus"); ?></option>
                                </select>
                                <?php unset($selected); ?>
                            </td>
                        </tr>
                        <tr id="sc_wpfmp_from_address">
                        	<td valign="middle"><label for="sc_wpfmp_form_from_address"><?php _e("From Email Address: ", "wordpress-file-monitor-plus"); ?></label></td>
                            <td valign="middle"><input id="sc_wpfmp_form_from_address" name="from_address" value="<?php echo $this->settings['from_address']; ?>" size="40" /></td>
                        </tr>
                        <tr id="sc_wpfmp_notify_address">
                        	<td valign="middle"><label for="sc_wpfmp_form_notify_address"><?php _e("Notify Email Address: ", "wordpress-file-monitor-plus"); ?></label></td>
                            <td valign="middle"><input id="sc_wpfmp_form_notify_address" name="notify_address" value="<?php echo $this->settings['notify_address']; ?>" size="40" /></td>
                        </tr>
                        <tr>
                            <td valign="middle"><label for="sc_wpfmp_form_display_admin_alert"><?php _e("Admin Alert: ", "wordpress-file-monitor-plus"); ?></label></td>
                            <td valign="middle">
								<?php $selected[(int)$this->settings['display_admin_alert']] = " selected=\"selected\""; ?>
                                <select id="sc_wpfmp_form_display_admin_alert" name="display_admin_alert">
                                    <option value="1"<?php echo $selected[1]; ?>><?php _e("Yes", "wordpress-file-monitor-plus"); ?></option>
                                    <option value="0"<?php echo $selected[0]; ?>><?php _e("No", "wordpress-file-monitor-plus"); ?></option>
                                </select>
                                <?php unset($selected); ?>
                            </td>
                        </tr>
                        <tr>
							<td valign="top"><?php _e("File Check Methods: ", "wordpress-file-monitor-plus"); ?></td>
							<td valign="top">
                            	<input id="sc_wpfmp_form_file_check_use_size" name="file_check_use_size" type="checkbox" value="1"<?php if($this->settings['file_check_use_size'] == 1) : ?> checked="checked"<?php endif; ?> /><label for="sc_wpfmp_form_file_check_use_size"><?php _e(" File Size", "wordpress-file-monitor-plus"); ?></label><br />
                            	<input id="sc_wpfmp_form_file_check_use_modified" name="file_check_use_modified" type="checkbox" value="1"<?php if($this->settings['file_check_use_modified'] == 1) : ?> checked="checked"<?php endif; ?> /><label for="sc_wpfmp_form_file_check_use_modified"><?php _e(" Date Modified", "wordpress-file-monitor-plus"); ?></label><br />
                            	<input id="sc_wpfmp_form_file_check_use_md5" name="file_check_use_md5" type="checkbox" value="1"<?php if($this->settings['file_check_use_md5'] == 1) : ?> checked="checked"<?php endif; ?> /><label for="sc_wpfmp_form_file_check_use_md5"><?php _e(" File Hash", "wordpress-file-monitor-plus"); ?></label>
							</td>
                        </tr>
                        <tr>
                        	<td valign="middle"><label for="sc_wpfmp_form_site_root"><?php _e("File Check Root: ", "wordpress-file-monitor-plus"); ?></label></td>
                            <td valign="middle"><input id="sc_wpfmp_form_site_root" name="site_root" value="<?php echo $this->settings['site_root']; ?>" /><?php printf(__(" (Default: %s)", "wordpress-file-monitor-plus"), realpath(ABSPATH)); ?></td>
                        </tr>
                        <tr>
                        	<td valign="top"><label for="sc_wpfmp_form_exclude_files_wild"><?php _e("File Names To Ignore: ", "wordpress-file-monitor-plus"); ?></label></td>
                            <td valign="top"><textarea id="sc_wpfmp_form_exclude_files_wild" name="exclude_files_wild" cols="25" rows="3"><?php echo implode("\n", $this->settings['exclude_files_wild']); ?></textarea></td>
                        </tr>
                        <tr>
                        	<td valign="top"><label for="sc_wpfmp_form_exclude_paths_wild"><?php _e("Dir Names To Ignore: ", "wordpress-file-monitor-plus"); ?></label></td>
                            <td valign="top"><textarea id="sc_wpfmp_form_exclude_paths_wild" name="exclude_paths_wild" cols="25" rows="3"><?php echo implode("\n", $this->settings['exclude_paths_wild']); ?></textarea></td>
                        </tr>
                        <tr>
                        	<td valign="top"><label for="sc_wpfmp_form_exclude_files"><?php _e("Exact Files To Ignore: ", "wordpress-file-monitor-plus"); ?></label></td>
                            <td valign="top"><textarea id="sc_wpfmp_form_exclude_files" name="exclude_files" cols="50" rows="3"><?php echo implode("\n", $this->settings['exclude_files']); ?></textarea></td>
                        </tr>
                        <tr>
                        	<td valign="top"><label for="sc_wpfmp_form_exclude_paths"><?php _e("Exact Dirs To Ignore: ", "wordpress-file-monitor-plus"); ?></label></td>
                            <td valign="top"><textarea id="sc_wpfmp_form_exclude_paths" name="exclude_paths" cols="50" rows="3"><?php echo implode("\n", $this->settings['exclude_paths']); ?></textarea></td>
                        </tr>
                    </table>
                    <p class="submit"><input class='button-primary' type="submit" name="Submit" value="<?php _e("Save Settings", "wordpress-file-monitor-plus"); ?>" /></p>
                </form>
            </div>
            <?php			
		} // End settings_page function


		/**
		 * Check for form submission, external or manual scan and show alert details
		 *
		 * Firstly checks if a external cron has requested to scan, only allow scan if
		 * credentials are correct (Enabled and Security Key). Secondly deal with any form
		 * submissions checking the admin referrer. Thirdly check if request to show admin alert
		*/
		function things_to_do() {
			
			// Check if a scan is being requested external and that the correct security key is provided and the that the settings allow an external cron
			if(($_GET['sc_wpfmp_scan'] == 1) && ($_GET['sc_wpfmp_key'] == $this->settings['security_key']) && ($this->settings['cron_method'] == "other")) {
				do_action('sc_wpfmp_scan'); // Go run file check scan.
				_e("Scan Successful", "wordpress-file-monitor-plus"); // Simple message to say the cron ran :)
				exit; // No point showing any other content as this is and external cron running this.
			} // End if other cron scan
			
			// Check for form submission from WPFMP
			if (isset($_POST['sc_wpfmp_action'])) {
				check_admin_referer('sc-wpfmp-update-settings'); // Security check
				
				// Switch through what action has been chosen
				switch ($_POST['sc_wpfmp_action']) {
					case 'update_settings': // Settings form submitted
						$this->update_settings($_POST);
					break;
					case 'manual_scan': // Manual scan submitted
						do_action('sc_wpfmp_scan');
					break;
					case 'clear_admin_alert': // Clear admin alert submitted
						$this->settings['is_admin_alert'] = 0; // Set active alert off
						$this->save_settings(); // Save settings
					break;
					default: // Should never end up here
						exit; // but if you do you go nowhere
					break;
				} // End switch
				
			} // End check for form submission
			
			// Show admin alert data if requestion and if there is one and if enabled and if the user has permission to see the data.
			if($_GET['display'] == "show_alert" && $this->settings['is_admin_alert'] == 1 && $this->settings['display_admin_alert'] == 1 && current_user_can(SC_WPFMP_ADMIN_ALERT_PERMISSION)) {
				$this->show_admin_alert(); // Go show the admin alert data
				exit; // Dont show anything else just the data.
			} // End if show alert data allowed
			
		} // End things_to_fo function


		/**
		 * Take values from posted settings page and validate and save
		 *
		 * @param array $settings is $_POST from form on settings page 
		*/
		function update_settings($settings) {
			
			// Check the cron method is only wordpress or other
			if(!($settings['cron_method'] == "wordpress" || $settings['cron_method'] == "other")) {
				$this->settingsFailed = true;
				$this->settingsFailedMessage .= __("Invalid cron method selected", "wordpress-file-monitor-plus")."<br />";
			}
			
			$this->settings['cron_method'] = $settings['cron_method'];
			
			// Check if file check interval is manual, hourly, daily or twicedaily
			if(!($settings['file_check_interval'] == "manual" || $settings['file_check_interval'] == "hourly" || $settings['file_check_interval'] == "twicedaily" || $settings['file_check_interval'] == "daily")) {
				$this->settingsFailed = true;
				$this->settingsFailedMessage .= __("Invalid file check interval selected", "wordpress-file-monitor-plus")."<br />";
			}
			
			$this->settings['file_check_interval'] = $settings['file_check_interval'];
			
			// can only be 1 or 0 so type cast to INT
			$this->settings['notify_by_email'] = (int) $settings['notify_by_email'];
			
			// Are we to notify by email? if not remove notify email address from settings
			if($this->settings['notify_by_email'] == 0) {
				$this->settings['from_address'] = "";
				$this->settings['notify_address'] = "";
			} else {
				$this->settings['from_address'] = $settings['from_address'];
				$this->settings['notify_address'] = $settings['notify_address'];
				
				// Check for valid from address
				if(!is_email($this->settings['from_address'])) {
					$this->settingsFailed = true;
					$this->settingsFailedMessage .= __("Invalid from email address entered", "wordpress-file-monitor-plus")."<br />";
				}
				
				// Check for valid notify address
				if(!is_email($this->settings['notify_address'])) {
					$this->settingsFailed = true;
					$this->settingsFailedMessage .= __("Invalid notify email address entered", "wordpress-file-monitor-plus")."<br />";
				}
				
			}
			
			$this->settings['display_admin_alert'] = (int) $settings['display_admin_alert'];			
			
			// What file check methods are to be used? 1's or 0's only
			$this->settings['file_check_use_size'] = (int) $settings['file_check_use_size'];
			$this->settings['file_check_use_modified'] = (int) $settings['file_check_use_modified'];
			$this->settings['file_check_use_md5'] = (int) $settings['file_check_use_md5'];
			
			$this->settings['site_root'] = realpath($settings['site_root']);
			
			// Check if the site path that is entered is a dir and is readable.
			if(!(is_dir($this->settings['site_root']) && is_readable($this->settings['site_root']))) {
				$this->settingsFailed = true;
				$this->settingsFailedMessage .= __("File check root is not valid. Make sure that PHP has read permissions of the entered file check root", "wordpress-file-monitor-plus")."<br />";
			}
			
			// explode newlines into array. while we are at it lets trim any whitespace off the ends.
			$this->settings['exclude_files_wild'] = array_map('trim', (array) explode("\n", $settings['exclude_files_wild']));
			$this->settings['exclude_paths_wild'] = array_map('trim', (array) explode("\n", $settings['exclude_paths_wild']));
			$this->settings['exclude_files'] = array_map('trim', (array) explode("\n", $settings['exclude_files']));
			$this->settings['exclude_paths'] = array_map('trim', (array) explode("\n", $settings['exclude_paths']));

			// Did we pass validation?
			if($this->settingsFailed !== true) {
				$this->save_settings(); // Save settings
				$this->settingsUpdated = true; // Let the user know
				$this->enable_cron(); // do we need to enable a cron?
			}
			
		} // End update_settings function
		
		
		/**
		 * Save settings to wordpress options db table.
		*/
		function save_settings() {
			update_option('sc_wpfmp_settings', maybe_serialize($this->settings));
		} // end save_settings function


		/**
		 * Scan files and compare new scan data against old
		 *
		 * Load old scan data from options table and capture new scan data by scanning
		 * dirs. Sort the new scan data so always sorted by dir name. Save the new scan
		 * data to options table. If we have old scan data to compare against the do it.
		 * If file changes are found then start alert processes.
		*/
		function scan() {
			// Get old data
			$this->oldScanData = maybe_unserialize(get_option('sc_wpfmp_scan_data'));
			// Get new data
			$this->newScanData = (array) $this->scan_dirs($this->settings['site_root']);
			// Lets make sure that the new data is always sorted
			ksort($this->newScanData);
			// Save newScanData back to database
			update_option('sc_wpfmp_scan_data', maybe_serialize($this->newScanData));
			
			// Only do checks for file ammends/aditions/removals if we have some old
			// data to check against. Wont have old data if this is first scan.
			if(is_array($this->oldScanData)) { 
				// Find which files have been added/removed
				$files_added = array_diff_assoc($this->newScanData, $this->oldScanData);
				$files_removed = array_diff_assoc($this->oldScanData, $this->newScanData);
				// remove files from arrays so ready to do a compare for files changed
				$comp_newdata = array_diff_key($this->newScanData, $files_added);
				$comp_olddata = array_diff_key($this->oldScanData, $files_removed);
				$changed_files = $this->array_compare($comp_newdata, $comp_olddata);
				// get counts
				$count_files_changed = count($changed_files[0]);
				$count_files_addedd = count($files_added);
				$count_files_removed = count($files_removed);
				
				// Any file changes?
				if(($count_files_changed >= 1) || ($count_files_addedd >= 1) || ($count_files_removed >= 1)) {
					// Get html for the alert
					$alertMessage = $this->format_alert($files_added, $files_removed, $changed_files);
					// save html into options table to be shown later
					update_option('sc_wpfmp_admin_alert_content', $alertMessage);
					// yes there is an admin alert
					$this->settings["is_admin_alert"] = 1;
					// Save settings to save admin alert flag.
					$this->save_settings();
					
					// Are we to notify by email? then do it.
					if($this->settings['notify_by_email'] == 1) {
						$this->send_notify_email($alertMessage);
					} // End if notify by email
					
				} // End if and file changes
				
			} // End if old data exists
			
			// A scan ran. we do this to show in admin panel it did.
			$this->scanRan = true;
		}// End scan function 
		
		
		/**
		 * Recursivly scan directories
		 *
		 * Scans path for files and folders. Folders are scan recurivly.
		 * File information is captured based on settings. Files and dirs
		 * are ignore if they have been set to in settings.
		 *
		 * @param string $path full path to scan
		 * @return array $dirs holds array of all captured files and their details.
		*/
		function scan_dirs($path) {
			
			// Open path
			if ($handle = opendir($path)) {
				
				// Loop through contents of dir
				while (false !== ($file = readdir($handle))) {
					
					// Ignore . and ..
					if ($file != "." && $file != "..") {
						
						// We have a directory
						if(filetype($path."/".$file) === 'dir') {
							
							// check if this dir name or dir path is to be ignored
							if(!(in_array($file, $this->settings['exclude_paths_wild']) || in_array($path."/".$file, $this->settings['exclude_paths']))) {
								// We are all good lets continue down the rabit hole.
								$dirs = array_merge((array) $dirs, (array) $this->scan_dirs($path."/".$file));
							} // end dir path dir name ignore
							
						// We have a file
						} else {
							
							// check if this file name or file path is to be ignored
							if(!(in_array($file, $this->settings['exclude_files_wild']) || in_array($path."/".$file, $this->settings['exclude_files']))) {
								// We are all good lets get the data of the the file.
								$dirs[$path."/".$file] = array();
								
								// Check file size?
								if($this->settings['file_check_use_size'] == 1) {
									$dirs[$path."/".$file]["size"] = filesize($path."/".$file);
								}
								
								// Check modified date?
								if($this->settings['file_check_use_modified'] == 1) {
									$dirs[$path."/".$file]["modified"] = filemtime($path."/".$file);
								}
								
								// Check file hash?
								if($this->settings['file_check_use_md5'] == 1) {
									$dirs[$path."/".$file]["md5"] = md5_file($path."/".$file);
								}
								
							} // end file name file path ignore
							
						} // end if else dir file
						
					} // end if . or ..
					
				} // end while list files and dirs
				
				closedir($handle);
			} // end if path opendir doable
			
			return $dirs;
		} // end scan_dirs function
		
		
		/**
		 * Creates HTML for email and admin alert
		 *
		 * @param array $files_added Array holding any files that have been added
		 * @param array $files_removed Array holding any files that have been removed
		 * @param array $changed_files Array holding any files that have been changed
		 * @return string $alertMessage return formatted HTML
		*/
		function format_alert($files_added, $files_removed, $changed_files) {
			$alertMessage  = sprintf(__("Files Changed: %d", "wordpress-file-monitor-plus"), count($changed_files[0]))."<br />";
			$alertMessage .= sprintf(__("Files Added: %d", "wordpress-file-monitor-plus"), count($files_added))."<br />";
			$alertMessage .= sprintf(__("Files Removed: %d", "wordpress-file-monitor-plus"), count($files_removed))."<br />";
			$alertMessage .= "<br />";
			
			// Only do this if some changed files
			if(count($changed_files[0]) >= 1) {
				$alertMessage .= "<strong>".__("Files Changed:", "wordpress-file-monitor-plus")."</strong>";
				$alertMessage .= "<table class='widefat' width='100%' border='1' cellspacing='0' cellpadding='2'>";
				$alertMessage .= "  <thead>";
				$alertMessage .= "  <tr>";
				$alertMessage .= "    <th width='100%'>".__("File", "wordpress-file-monitor-plus")."</th>";
				
				if($this->settings['file_check_use_size'] == 1) {
					$alertMessage .= "    <th nowrap='nowrap'>".__("New Filesize", "wordpress-file-monitor-plus")."</th>";
					$alertMessage .= "    <th nowrap='nowrap'>".__("Old Filesize", "wordpress-file-monitor-plus")."</th>";
				}
				
				if($this->settings['file_check_use_modified'] == 1) {
					$alertMessage .= "    <th nowrap='nowrap'>".__("New Modified", "wordpress-file-monitor-plus")."</th>";
					$alertMessage .= "    <th nowrap='nowrap'>".__("Old Modified", "wordpress-file-monitor-plus")."</th>";
				}
				
				if($this->settings['file_check_use_md5'] == 1) {
					$alertMessage .= "    <th nowrap='nowrap'>".__("New Hash", "wordpress-file-monitor-plus")."</th>";
					$alertMessage .= "    <th nowrap='nowrap'>".__("Old Hash", "wordpress-file-monitor-plus")."</th>";
				}
				
				$alertMessage .= "  </tr>";
				$alertMessage .= "  </thead>";
				$alertMessage .= "  <tbody>";
				
				foreach($changed_files[0] as $key => $data) {
					$alertMessage .= "  <tr>";
					$alertMessage .= "    <td>".$key."</td>";
					
					if($this->settings['file_check_use_size'] == 1) {
						$alertMessage .= "    <td nowrap='nowrap'>".$this->formatRawSize($this->newScanData[$key]["size"])."</td>";
						$alertMessage .= "    <td nowrap='nowrap'>".$this->formatRawSize($this->oldScanData[$key]["size"])."</td>";
					}
					
					if($this->settings['file_check_use_modified'] == 1) {
						$alertMessage .= "    <td nowrap='nowrap'>".date("l, dS F, Y @ h:ia", $this->newScanData[$key]["modified"])."</td>";
						$alertMessage .= "    <td nowrap='nowrap'>".date("l, dS F, Y @ h:ia", $this->oldScanData[$key]["modified"])."</td>";
					}
					
					if($this->settings['file_check_use_md5'] == 1) {
						$alertMessage .= "    <td nowrap='nowrap'>".$this->newScanData[$key]["md5"]."</td>";
						$alertMessage .= "    <td nowrap='nowrap'>".$this->oldScanData[$key]["md5"]."</td>";
					}
					
					$alertMessage .= "  </tr>";
				}
				
				$alertMessage .= "  </tbody>";
				$alertMessage .= "</table>";
				$alertMessage .= "<br /><br />";
			} // End if changed files
			
			// Only do this if added files
			if(count($files_added) >= 1) {
				
				$alertMessage .= "<strong>".__("Files Added:", "wordpress-file-monitor-plus")."</strong>";
				$alertMessage .= "<table class='widefat' width='100%' border='1' cellspacing='0' cellpadding='2'>";
				$alertMessage .= "  <thead>";
				$alertMessage .= "  <tr>";
				$alertMessage .= "    <th width='100%'>".__("File", "wordpress-file-monitor-plus")."</th>";
				
				if($this->settings['file_check_use_size'] == 1) {
					$alertMessage .= "    <th nowrap='nowrap'>".__("New Filesize", "wordpress-file-monitor-plus")."</th>";
				}
				
				if($this->settings['file_check_use_modified'] == 1) {
					$alertMessage .= "    <th nowrap='nowrap'>".__("New Modified", "wordpress-file-monitor-plus")."</th>";
				}
				
				if($this->settings['file_check_use_md5'] == 1) {
					$alertMessage .= "    <th nowrap='nowrap'>".__("New Hash", "wordpress-file-monitor-plus")."</th>";
				}
				
				$alertMessage .= "  </tr>";
				$alertMessage .= "  </thead>";
				$alertMessage .= "  <tbody>";
				
				foreach($files_added as $key => $data) {
					$alertMessage .= "  <tr>";
					$alertMessage .= "    <td>".$key."</td>";
					
					if($this->settings['file_check_use_size'] == 1) {
						$alertMessage .= "    <td nowrap='nowrap'>".$this->formatRawSize($this->newScanData[$key]["size"])."</td>";
					}
					
					if($this->settings['file_check_use_modified'] == 1) {
						$alertMessage .= "    <td nowrap='nowrap'>".date("l, dS F, Y @ h:ia", $this->newScanData[$key]["modified"])."</td>";
					}
					
					if($this->settings['file_check_use_md5'] == 1) {
						$alertMessage .= "    <td nowrap='nowrap'>".$this->newScanData[$key]["md5"]."</td>";
					}
					
					$alertMessage .= "  </tr>";
				}
				
				$alertMessage .= "  </tbody>";
				$alertMessage .= "</table>";
				$alertMessage .= "<br /><br />";
			} // End if added files
			
			// Only do this if removed files
			if(count($files_removed) >= 1) {
				$alertMessage .= "<strong>".__("Files Removed:", "wordpress-file-monitor-plus")."</strong>";
				$alertMessage .= "<table class='widefat' width='100%' border='1' cellspacing='0' cellpadding='2'>";
				$alertMessage .= "  <thead>";
				$alertMessage .= "  <tr>";
				$alertMessage .= "    <th width='100%'>".__("File", "wordpress-file-monitor-plus")."</th>";
				
				if($this->settings['file_check_use_size'] == 1) {
					$alertMessage .= "    <th nowrap='nowrap'>".__("Old Filesize", "wordpress-file-monitor-plus")."</th>";
				}
				
				if($this->settings['file_check_use_modified'] == 1) {
					$alertMessage .= "    <th nowrap='nowrap'>".__("Old Modified", "wordpress-file-monitor-plus")."</th>";
				}
				
				if($this->settings['file_check_use_md5'] == 1) {
					$alertMessage .= "    <th nowrap='nowrap'>".__("Old Hash", "wordpress-file-monitor-plus")."</th>";
				}
				
				$alertMessage .= "  </tr>";
				$alertMessage .= "  </thead>";
				$alertMessage .= "  <tbody>";
				
				foreach($files_removed as $key => $data) {
					$alertMessage .= "  <tr>";
					$alertMessage .= "    <td>".$key."</td>";
					
					if($this->settings['file_check_use_size'] == 1) {
						$alertMessage .= "    <td nowrap='nowrap'>".$this->formatRawSize($this->oldScanData[$key]["size"])."</td>";
					}
					
					if($this->settings['file_check_use_modified'] == 1) {
						$alertMessage .= "    <td nowrap='nowrap'>".date("l, dS F, Y @ h:ia", $this->oldScanData[$key]["modified"])."</td>";
					}
					
					if($this->settings['file_check_use_md5'] == 1) {
						$alertMessage .= "    <td nowrap='nowrap'>".$this->oldScanData[$key]["md5"]."</td>";
					}
					
					$alertMessage .= "  </tr>";
				}
				
				$alertMessage .= "  </tbody>";
				$alertMessage .= "</table>";
				$alertMessage .= "<br /><br />";
			} // End if removed files
			
			return $alertMessage;
		}// End format_alert function
		
		
		/**
		 * Sends admin alert email
		 *
		 * Send admin the alert email. I first tried to use the $headers param on
		 * wp_mail but found that filters already attached to wp_mail override headers.
		 * So I have added filters for from/from_name/content_type to make sure they stick.
		 * Not only that, I have removed them after I have sent the mail so not to get in
		 * the way of anything else.
		*/
		function send_notify_email($alertMessage) {
			$subject = sprintf(__("WordPress File Monitor: Alert (%s)", "wordpress-file-monitor-plus"), site_url());
			add_filter('wp_mail_from', array(&$this, 'sc_wpfmp_wp_mail_from'));
			add_filter('wp_mail_from_name', array(&$this, 'sc_wpfmp_wp_mail_from_name'));
			add_filter('wp_mail_content_type', array(&$this, 'sc_wpfmp_wp_mail_content_type'));
			wp_mail($this->settings['notify_address'], $subject, $alertMessage);
			remove_filter('wp_mail_from', array(&$this, 'sc_wpfmp_wp_mail_from'));
			remove_filter('wp_mail_from_name', array(&$this, 'sc_wpfmp_wp_mail_from_name'));
			remove_filter('wp_mail_content_type', array(&$this, 'sc_wpfmp_wp_mail_content_type'));
		} // End send_notify_email function
		function sc_wpfmp_wp_mail_from() { return $this->settings['from_address']; }
		function sc_wpfmp_wp_mail_from_name() { return "WordPress File Monitor Plus"; }
		function sc_wpfmp_wp_mail_content_type() { return "text/html"; }


		/**
		 * Shows warning that files have changed in Wordpress Admin
		*/
		function admin_alert() {
			
			// Is there an alert to show, is it enabled and does the user have permission to see it?
			if ($this->settings['is_admin_alert'] == 1 && $this->settings['display_admin_alert'] == 1 && current_user_can(SC_WPFMP_ADMIN_ALERT_PERMISSION)) { ?>
				<div class="error">
                	<p>
                        <?php _e("<strong>Warning!</strong> WordPress File Monitor Plus has detected a change in the files on your site.", "wordpress-file-monitor-plus"); ?>
                        <br/><br/>
                        <a class="button-secondary thickbox" href="<?php echo site_url(); ?>/wp-admin/options-general.php?page=wordpress-file-monitor-plus&amp;display=show_alert" title="<?php _e("View file changes and clear this alert", "wordpress-file-monitor-plus"); ?>"><?php _e("View file changes and clear this alert", "wordpress-file-monitor-plus"); ?></a>
                    </p>
				</div>
			<?php } // End if admin alert
			
		} // End  admin_alert function
		
		
		/**
		 * Show form to clear admin alert along with last alert. 
		*/
		function show_admin_alert() {
			$alert_content = get_option('sc_wpfmp_admin_alert_content');
			?>
			<form action="" method="post">
				<?php wp_nonce_field('sc-wpfmp-update-settings'); ?>
				<input type="hidden" name="sc_wpfmp_action" value="clear_admin_alert" />
				<p class="submit"><input type="submit" value="<?php _e("Remove Admin Alert", "wordpress-file-monitor-plus"); ?>"></p>
			</form>
			<?php
			echo $alert_content;
		} // End show_admin_alert function


		/**
		 * Create a WordPress cron if criteria met
		 *
		 * Firstly check if we need to setup a cron. If we are manual or using external cron then disabled any
		 * wordpress cron. Secondly check if we have a cron already setup. If not lets create one. If we do have
		 * one setup then check if the cron setup has the same interval we have now. If we dont have the same interval
		 * then delete the old cron and create a new one. If you know a better way to do this logic give me shout.
		*/
		function enable_cron() {
			
			// Are we to have a wordpress cron?
			if($this->settings['cron_method'] == "other" || $this->settings['file_check_interval'] == "manual") {
				$this->disable_cron(); // No, lets disable any that we might have.
			} else {
				
				// Do we already have one setup?
				if(wp_next_scheduled('sc_wpfmp_scan')) {
					// Yes we do lets find out what schedule we are on.
					$currentSchedule = wp_get_schedule('sc_wpfmp_scan');
					
					// Is the schedule of the existing cron the same as the settings?
					if($currentSchedule != $this->settings['file_check_interval']) {
						$this->disable_cron(); // No, lets remove old cron
						// and create new cron with correct schedule.
						wp_schedule_event((time() + $this->cronIntervals[$this->settings['file_check_interval']]['seconds']), $this->settings['file_check_interval'], 'sc_wpfmp_scan');
					} // End if cron schedule matches.
					
				} else {
					// No existing cron lets create one with correct interval.
					wp_schedule_event((time() + $this->cronIntervals[$this->settings['file_check_interval']]['seconds']), $this->settings['file_check_interval'], 'sc_wpfmp_scan');
				} // End if cron exists
				
			} // End if should have a wordpress cron
			
		} // End enable_cron function
		

		/**
		 * Remove any Wordpress cron our plugin may have created/
		*/
		function disable_cron() {
			wp_clear_scheduled_hook('sc_wpfmp_scan');
		} // End disable_cron function


		/**
		 * Function to add settings link to plugin. Shouldn't need any explanation
		*/
		function plugin_action_links($links, $file) {
			static $this_plugin;
			
			if (!$this_plugin) {
				$this_plugin = plugin_basename(__FILE__);
			}
			
			if ($file == $this_plugin){
				$settings_link = '<a href="'.site_url().'/wp-admin/options-general.php?page=wordpress-file-monitor-plus">'.__("Settings", "wordpress-file-monitor-plus").'</a>';
				array_unshift($links, $settings_link);
			}
			
			return $links;
		} // End plugin_action_links function


		/**
		 * Compares two arrays and returns the difference
		 *
		 * This is a function I picked up from PHP.net some time ago
		 * and can no longer find the author so unable to give credit.
		 *
		 * @param array $array1
		 * @param array $array2
		 * @result array $diff 
		*/
		function array_compare($array1, $array2) { 
			$diff = false; 
			foreach ($array1 as $key => $value) { 
				if (!array_key_exists($key,$array2)) { 
					$diff[0][$key] = $value; 
				} elseif (is_array($value)) { 
					if (!is_array($array2[$key])) { 
						$diff[0][$key] = $value; 
						$diff[1][$key] = $array2[$key]; 
					} else { 
						$new = $this->array_compare($value, $array2[$key]); 
						if ($new !== false) { 
							if (isset($new[0])) {
								$diff[0][$key] = $new[0];
							}
							if (isset($new[1])) {
								$diff[1][$key] = $new[1]; 
							}
						}
					}
				} elseif ($array2[$key] !== $value) { 
					$diff[0][$key] = $value; 
					$diff[1][$key] = $array2[$key]; 
				}
			}
			foreach ($array2 as $key => $value) { 
				if (!array_key_exists($key,$array1)) { 
					$diff[1][$key] = $value; 
				}
			}
			return $diff; 
		} // End array_compare function
		

		/**
		 * Returns human readable format of filesize.
		 *
		 * Taken from @link and modified slightly. No need to floor($e) twice and
		 * put in check if 0 decimal place then dont show decimal.
		 *
		 * @link http://www.stemkoski.com/how-to-format-raw-byte-file-size-into-a-humanly-readable-value-using-php/
		 * @param int $bytes Byte size of file
		 * @return string $output Human readable format of byte size.
		*/
		function formatRawSize($bytes) {
			
			if(!empty($bytes)) {
				$s = array('B', 'KB', 'MB', 'GB', 'TB', 'PB');
				$e = floor(log($bytes)/log(1024));
				$output = sprintf('%.' . ($e == 0 ? 0 : 2) . 'f '.$s[$e], ($bytes/pow(1024, $e)));
				return $output;
			}
			
		} // End formatRawSize function
		
		
	} // End sc_WordPressFileMonitorPlus class
} // End if class_exists


// Create Instance of WPFMP Class
if (!isset($sc_wpfmp) && function_exists('add_action')) {
	$sc_wpfmp = new sc_WordPressFileMonitorPlus();
} // End if class is set

?>