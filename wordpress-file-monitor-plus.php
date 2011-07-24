<?php
/*
Plugin Name: WordPress File Monitor Plus
Plugin URI: http://l3rady.com/projects/wordpress-file-monitor-plus/
Description: Monitor your website for added/changed/deleted files
Author: Scott Cariss
Version: 1.3
Author URI: http://l3rady.com/
Text Domain: wordpress-file-monitor-plus
Domain Path: /languages
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

        protected static $settings_option_field = "sc_wpfmp_settings"; // Option name for settings
        protected static $settings_option_field_ver = "sc_wpfmp_settings_ver"; // Option name for settings version
        protected static $settings_option_field_current_ver = "1.3"; // Current settings version
        protected static $cron_name = "sc_wpfmp_scan"; // Name of cron
        protected static $frequency_intervals = array("hourly", "twicedaily", "daily", "manual"); // What cron schedules are available
		
		public function __construct() {
			load_plugin_textdomain('wordpress-file-monitor-plus', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/'); // Internationalization
            if(!defined('SC_WPFMP_ADMIN_ALERT_PERMISSION')) {define('SC_WPFMP_ADMIN_ALERT_PERMISSION', 'manage_options');} // Define the permission to see/read/remove admin alert if not already set in config
			register_activation_hook(__FILE__, array(__CLASS__, 'activate')); // plugin activate
			register_deactivation_hook(__FILE__, array(__CLASS__, 'deactive')); // plugin deactivate
            add_filter('sc_wpfmp_format_file_modified_time', array(__CLASS__, 'format_file_modified_time'), 10, 2); // Create filter for formating the file modified time
			add_action('init', array(__CLASS__, 'things_to_do')); // Check for things to do when needed
			add_action('admin_notices', array(__CLASS__, 'admin_alert')); // Admin alert show in dashboard
			add_action(self::$cron_name, array(__CLASS__, 'scan')); // Create a hook for scanning
			add_action('sc_wpfmp_enable_wp_cron', array(__CLASS__, 'enable_cron')); // Create a hook for enabling WPFMP cron
			add_action('sc_wpfmp_disable_wp_cron', array(__CLASS__, 'disable_cron')); // Create a hook for disabling WPFMP cron
			add_action('sc_wpfmp_send_notify_email', array(__CLASS__, 'send_notify_email')); // Create a hook for sending alert email
		}


		/**
		 * What to do when plugin is activated
		 *
         * @return void
		*/
		public function activate() {
			do_action("sc_wpfmp_enable_wp_cron"); // Go enable cron
		}


		/**
		 * What to do when plugin is deactivated
		 *
         * @return void
		*/
		public function deactive() {
			do_action("sc_wpfmp_disable_wp_cron"); // Go disable cron
		}


		/**
		 * Check for form submission, external or manual scan and show alert details
		 *
         * @return void
		*/
		function things_to_do() {
			$options = get_option(self::$settings_option_field); // get settings
			if((1 == $_GET['sc_wpfmp_scan']) && ($options['security_key'] == $_GET['sc_wpfmp_key']) && ("other" == $options['cron_method'])) { // Check if a scan is being requested external and that the correct security key is provided and the that the settings allow an external cron
				do_action('sc_wpfmp_scan'); // Go run file check scan.
				_e("Scan Successful", "wordpress-file-monitor-plus"); // Simple message to say the cron ran :)
				exit; // No point showing any other content as this is and external cron running this.
			}
		}


		/**
		 * Scan files and compare new scan data against old
         *
         * @return void
		*/
		public function scan() {
            $options = get_option(self::$settings_option_field); // Get settings
			$oldScanData = self::getPutScanData("get"); // Get old data
			$newScanData = (array) self::scan_dirs(); // Get new data
			ksort($newScanData);// Lets make sure that the new data is always sorted
			self::getPutScanData("put", $newScanData); // Save newScanData back to database or file
			if(is_array($oldScanData)) { // Only do checks for file ammends/aditions/removals if we have some old
				$files_added = array_diff_assoc($newScanData, $oldScanData); // See which files have been added since last scan
				$files_removed = array_diff_assoc($oldScanData, $newScanData); // See which files have been removed since last scan
				$comp_newdata = array_diff_key($newScanData, $files_added); // remove added files
				$comp_olddata = array_diff_key($oldScanData, $files_removed); // remove removed files
				$changed_files = self::array_compare($comp_newdata, $comp_olddata); // Compare old scan to new scan
				$count_files_changed = count($changed_files[0]); // number of files changed
				$count_files_addedd = count($files_added); // number of files added
				$count_files_removed = count($files_removed); // number of files removed
				if((1 <= $count_files_changed) || (1 <= $count_files_addedd) || (1 <= $count_files_removed)) { // Any file changes?
					$alertMessage = self::format_alert($files_added, $files_removed, $changed_files, $oldScanData, $newScanData); // get html alert
					self::getPutAlertContent("put", $alertMessage); // save html into DB or file to be shown later
					$options["is_admin_alert"] = 1; // yes there is an admin alert
                    update_option(self::$settings_option_field, $options); // Save settings to save admin alert flag.
					if(1 == $options['notify_by_email']) { // Are we to notify by email? then do it.
						do_action("sc_wpfmp_send_notify_email", $alertMessage); // go alert
					}
                }
			}
		}

		
		/**
		 * Recursivly scan directories
		 *
		 * @param string $path full path to scan
		 * @return array $dirs holds array of all captured files and their details.
		*/
		protected function scan_dirs($path = "") {
            static $options; // Set settings as static so not to repeat get options as we recurse.
            if(!$options) { // Are settings set?
                $options = get_option(self::$settings_option_field); // Get settings
                if("file" == $options['data_save']) { // are we saving to file?
                    $options['exclude_files'][] = dirname(__FILE__).DIRECTORY_SEPARATOR."data".DIRECTORY_SEPARATOR.".sc_wpfmp_scan_data"; // add file to ignore
                    $options['exclude_files'][] = dirname(__FILE__).DIRECTORY_SEPARATOR."data".DIRECTORY_SEPARATOR.".sc_wpfmp_admin_alert_content"; // add file to ignore
                }
                if(1 == $options['file_extension_mode']) {
                    $options['file_extensions'] = apply_filters("sc_wpfmp_filter_ignore_extensions", $options['file_extensions']); // Allow other plugins to add remove extensions
                } elseif(2 == $options['file_extension_mode']) {
                    $options['file_extensions'] = apply_filters("sc_wpfmp_filter_scan_extensions", $options['file_extensions']); // Allow other plugins to add remove extensions
                }
                $options['exclude_paths'] = apply_filters("sc_wpfmp_filter_exclude_paths", $options['exclude_paths']);
                $options['exclude_files'] = apply_filters("sc_wpfmp_filter_exclude_files", $options['exclude_files']);
                $options['exclude_paths_wild'] = apply_filters("sc_wpfmp_filter_exclude_paths_wild", $options['exclude_paths_wild']);
                $options['exclude_files_wild'] = apply_filters("sc_wpfmp_filter_exclude_files_wild", $options['exclude_files_wild']);
            }
			if ($handle = opendir($options['site_root'].$path)) { // Open dir
				while (false !== ($file = readdir($handle))) { // loop through dirs
					if ("." != $file  && ".." != $file) { // ignore . and ..
						if('dir' === filetype($options['site_root'].$path.DIRECTORY_SEPARATOR.$file)) { // is this a directory?
							if(!(in_array($file, $options['exclude_paths_wild']) || in_array($options['site_root'].$path.DIRECTORY_SEPARATOR.$file, $options['exclude_paths']))) {// check if this dir name or dir path is to be ignored
								$dirs = array_merge((array) $dirs, (array) self::scan_dirs($path.DIRECTORY_SEPARATOR.$file)); // We are all good lets continue down the rabbit hole.
							}
						} else { // is must be a file if not a directory
                            if(0 == $options['file_extension_mode'] || (1 == $options['file_extension_mode'] && !in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), $options['file_extensions'])) || (2 == $options['file_extension_mode'] && in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), $options['file_extensions']))) {
                                if(!(in_array($file, $options['exclude_files_wild']) || in_array($options['site_root'].$path.DIRECTORY_SEPARATOR.$file, $options['exclude_files']))) { // check if this file name or file path is to be ignored
                                    $dirs[$path.DIRECTORY_SEPARATOR.$file] = array(); // We are all good lets get the data of the the file.
                                    if(1 == $options['file_check_method']['size']) { // are we to check its filesize?
                                        $dirs[$path.DIRECTORY_SEPARATOR.$file]["size"] = filesize($options['site_root'].$path.DIRECTORY_SEPARATOR.$file);
                                    }
                                    if(1 == $options['file_check_method']['modified']) { // are we to check its modified date?
                                        $dirs[$path.DIRECTORY_SEPARATOR.$file]["modified"] = filemtime($options['site_root'].$path.DIRECTORY_SEPARATOR.$file);
                                    }
                                    if(1 == $options['file_check_method']['md5']) { // are we to check its file hash?
                                        $dirs[$path.DIRECTORY_SEPARATOR.$file]["md5"] = md5_file($options['site_root'].$path.DIRECTORY_SEPARATOR.$file);
                                    }
                                }
                            }
						}
					}
				}
				closedir($handle); // close directory
			}
			return $dirs; // return the files we found in this dir
		}


        /**
         * Creates HTML for email and admin alert
         *
         * @param array $files_added Array holding any files that have been added
         * @param array $files_removed Array holding any files that have been removed
         * @param array $changed_files Array holding any files that have been changed
         * @param array $oldScanData Array holding all files in old scan data
         * @param array $newScanData Array holding all files in new scan data
         * @return string $alertMessage return formatted HTML
         */
		protected function format_alert($files_added, $files_removed, $changed_files, $oldScanData, $newScanData) {
            $options = get_option(self::$settings_option_field); // Get settings
            $alertMessage = "";
            if(1 == $options['display_admin_alert']) {
                $alertMessage .= "<a class='button-secondary' href='".admin_url("options-general.php?page=wordpress-file-monitor-plus&sc_wpfmp_action=sc_wpfmp_clear_admin_alert")."'>".__("Clear admin alert", "wordpress-file-monitor-plus")."</a><br /><br />";
            }
			$alertMessage .= sprintf(__("Files Changed: %d", "wordpress-file-monitor-plus"), count($changed_files[0]))."<br />";
			$alertMessage .= sprintf(__("Files Added: %d", "wordpress-file-monitor-plus"), count($files_added))."<br />";
			$alertMessage .= sprintf(__("Files Removed: %d", "wordpress-file-monitor-plus"), count($files_removed))."<br />";
			$alertMessage .= "<br />";
			if(count($changed_files[0]) >= 1) { // Only do this if some changed files
				$alertMessage .= "<strong>".__("Files Changed:", "wordpress-file-monitor-plus")."</strong>";
				$alertMessage .= "<table class='widefat' width='100%' border='1' cellspacing='0' cellpadding='2'>";
				$alertMessage .= "  <thead>";
				$alertMessage .= "  <tr>";
				$alertMessage .= "    <th width='100%'>".__("File", "wordpress-file-monitor-plus")."</th>";
				if(1 == $options['file_check_method']['size']) {
					$alertMessage .= "    <th nowrap='nowrap'>".__("New Filesize", "wordpress-file-monitor-plus")."</th>";
					$alertMessage .= "    <th nowrap='nowrap'>".__("Old Filesize", "wordpress-file-monitor-plus")."</th>";
				}
				if(1 == $options['file_check_method']['modified']) {
					$alertMessage .= "    <th nowrap='nowrap'>".__("New Modified", "wordpress-file-monitor-plus")."</th>";
					$alertMessage .= "    <th nowrap='nowrap'>".__("Old Modified", "wordpress-file-monitor-plus")."</th>";
				}
				if(1 == $options['file_check_method']['md5']) {
					$alertMessage .= "    <th nowrap='nowrap'>".__("New Hash", "wordpress-file-monitor-plus")."</th>";
					$alertMessage .= "    <th nowrap='nowrap'>".__("Old Hash", "wordpress-file-monitor-plus")."</th>";
				}
				$alertMessage .= "  </tr>";
				$alertMessage .= "  </thead>";
				$alertMessage .= "  <tbody>";
				foreach($changed_files[0] as $key => $data) {
					$alertMessage .= "  <tr>";
					$alertMessage .= "    <td>".$key."</td>";
					if(1 == $options['file_check_method']['size']) {
						$alertMessage .= "    <td nowrap='nowrap'>".self::formatRawSize($newScanData[$key]["size"])."</td>";
						$alertMessage .= "    <td nowrap='nowrap'>".self::formatRawSize($oldScanData[$key]["size"])."</td>";
					}
					if(1 == $options['file_check_method']['modified']) {
						$alertMessage .= "    <td nowrap='nowrap'>".apply_filters("sc_wpfmp_format_file_modified_time", NULL, $newScanData[$key]["modified"])."</td>";
						$alertMessage .= "    <td nowrap='nowrap'>".apply_filters("sc_wpfmp_format_file_modified_time", NULL, $oldScanData[$key]["modified"])."</td>";
					}
					if(1 == $options['file_check_method']['md5']) {
						$alertMessage .= "    <td nowrap='nowrap'>".$newScanData[$key]["md5"]."</td>";
						$alertMessage .= "    <td nowrap='nowrap'>".$oldScanData[$key]["md5"]."</td>";
					}
					$alertMessage .= "  </tr>";
				}
				$alertMessage .= "  </tbody>";
				$alertMessage .= "</table>";
				$alertMessage .= "<br /><br />";
			}
			if(count($files_added) >= 1) {// Only do this if added files
				$alertMessage .= "<strong>".__("Files Added:", "wordpress-file-monitor-plus")."</strong>";
				$alertMessage .= "<table class='widefat' width='100%' border='1' cellspacing='0' cellpadding='2'>";
				$alertMessage .= "  <thead>";
				$alertMessage .= "  <tr>";
				$alertMessage .= "    <th width='100%'>".__("File", "wordpress-file-monitor-plus")."</th>";
				if(1 == $options['file_check_method']['size']) {
					$alertMessage .= "    <th nowrap='nowrap'>".__("New Filesize", "wordpress-file-monitor-plus")."</th>";
				}
				if(1 == $options['file_check_method']['modified']) {
					$alertMessage .= "    <th nowrap='nowrap'>".__("New Modified", "wordpress-file-monitor-plus")."</th>";
				}
				if(1 == $options['file_check_method']['md5']) {
					$alertMessage .= "    <th nowrap='nowrap'>".__("New Hash", "wordpress-file-monitor-plus")."</th>";
				}
				$alertMessage .= "  </tr>";
				$alertMessage .= "  </thead>";
				$alertMessage .= "  <tbody>";
				foreach($files_added as $key => $data) {
					$alertMessage .= "  <tr>";
					$alertMessage .= "    <td>".$key."</td>";
					if(1 == $options['file_check_method']['size']) {
						$alertMessage .= "    <td nowrap='nowrap'>".self::formatRawSize($newScanData[$key]["size"])."</td>";
					}
					if(1 == $options['file_check_method']['modified']) {
						$alertMessage .= "    <td nowrap='nowrap'>".apply_filters("sc_wpfmp_format_file_modified_time", NULL, $newScanData[$key]["modified"])."</td>";
					}
					if(1 == $options['file_check_method']['md5']) {
						$alertMessage .= "    <td nowrap='nowrap'>".$newScanData[$key]["md5"]."</td>";
					}
					$alertMessage .= "  </tr>";
				}
				$alertMessage .= "  </tbody>";
				$alertMessage .= "</table>";
				$alertMessage .= "<br /><br />";
			}
			if(count($files_removed) >= 1) {// Only do this if removed files
				$alertMessage .= "<strong>".__("Files Removed:", "wordpress-file-monitor-plus")."</strong>";
				$alertMessage .= "<table class='widefat' width='100%' border='1' cellspacing='0' cellpadding='2'>";
				$alertMessage .= "  <thead>";
				$alertMessage .= "  <tr>";
				$alertMessage .= "    <th width='100%'>".__("File", "wordpress-file-monitor-plus")."</th>";
				if(1 == $options['file_check_method']['size']) {
					$alertMessage .= "    <th nowrap='nowrap'>".__("Old Filesize", "wordpress-file-monitor-plus")."</th>";
				}
				if(1 == $options['file_check_method']['modified']) {
					$alertMessage .= "    <th nowrap='nowrap'>".__("Old Modified", "wordpress-file-monitor-plus")."</th>";
				}
				if(1 == $options['file_check_method']['md5']) {
					$alertMessage .= "    <th nowrap='nowrap'>".__("Old Hash", "wordpress-file-monitor-plus")."</th>";
				}
				$alertMessage .= "  </tr>";
				$alertMessage .= "  </thead>";
				$alertMessage .= "  <tbody>";
				foreach($files_removed as $key => $data) {
					$alertMessage .= "  <tr>";
					$alertMessage .= "    <td>".$key."</td>";
					if(1 == $options['file_check_method']['size']) {
						$alertMessage .= "    <td nowrap='nowrap'>".self::formatRawSize($oldScanData[$key]["size"])."</td>";
					}
					if(1 == $options['file_check_method']['modified']) {
						$alertMessage .= "    <td nowrap='nowrap'>".apply_filters("sc_wpfmp_format_file_modified_time", NULL, $oldScanData[$key]["modified"])."</td>";
					}
					if(1 == $options['file_check_method']['md5']) {
						$alertMessage .= "    <td nowrap='nowrap'>".$oldScanData[$key]["md5"]."</td>";
					}
					$alertMessage .= "  </tr>";
				}
				$alertMessage .= "  </tbody>";
				$alertMessage .= "</table>";
				$alertMessage .= "<br /><br />";
			}
            if(1 == $options['display_admin_alert']) {
                $alertMessage .= "<a class='button-secondary' href='".admin_url("options-general.php?page=wordpress-file-monitor-plus&sc_wpfmp_action=sc_wpfmp_clear_admin_alert")."'>".__("Clear admin alert", "wordpress-file-monitor-plus")."</a><br /><br />";
            }
			return $alertMessage;
		}


        /**
         * Sends admin alert email
         *
         * @param $alertMessage string
         * @return void
         */
		public function send_notify_email($alertMessage) {
            $options = get_option(self::$settings_option_field); // Get settings
            $subject = sprintf(__("WordPress File Monitor Plus: Alert (%s)", "wordpress-file-monitor-plus"), site_url()); // build subject
            $subject = apply_filters("sc_wpfmp_format_email_subject", $subject); // allow filter to alter subject
			add_filter('wp_mail_from', array(__CLASS__, 'sc_wpfmp_wp_mail_from')); // add filter to modify the mail from
			add_filter('wp_mail_from_name', array(__CLASS__, 'sc_wpfmp_wp_mail_from_name')); // add filter to modify the mail from name
			add_filter('wp_mail_content_type', array(__CLASS__, 'sc_wpfmp_wp_mail_content_type')); // add filter to modify the mail content type
			wp_mail($options['notify_address'], $subject, $alertMessage); // send mail
			remove_filter('wp_mail_from', array(__CLASS__, 'sc_wpfmp_wp_mail_from')); // remove applied filter
			remove_filter('wp_mail_from_name', array(__CLASS__, 'sc_wpfmp_wp_mail_from_name')); // remove applied filter
			remove_filter('wp_mail_content_type', array(__CLASS__, 'sc_wpfmp_wp_mail_content_type')); // remove applied filter
        }


        /**
         * Set from address for email notification
         *
         * @return void
         */
		public function sc_wpfmp_wp_mail_from() {
            $options = get_option(self::$settings_option_field); // Get settings
            return $options['from_address']; // Return the from address
        }


        /**
         * Set from name for email notification
         *
         * @return string $from_name
         */
		public function sc_wpfmp_wp_mail_from_name() {
            $from_name = __("WordPress File Monitor Plus", "wordpress-file-monitor-plus");
            $from_name = apply_filters("sc_wpfmp_format_email_from_name", $from_name); // allow filter to alter the from name
            return $from_name; // return from name
        }


        /**
         * Set content type for email notification
         *
         * @return string
         */
		public function sc_wpfmp_wp_mail_content_type() { return "text/html"; }


		/**
		 * Function deals with getting and putting scan data to and from DB or FILE
		 *
		 * @param string $getorput "get" to get data "put" to put data
		 * @param array $data if putting data this should contain array of new scan data
		 * @return array $data if getting data this should contain array of old scan data
		*/
		protected function getPutScanData($getorput, $data = NULL) {
			$options = get_option(self::$settings_option_field); // Get settings
			if("file" == $options['data_save']) { // Check how data is to be saved
				$scandatafile = dirname(__FILE__)."/data/.sc_wpfmp_scan_data";
				if("get" == $getorput) { // Are we getting or putting data
					if(file_exists($scandatafile)) { // Check if file exists. No point reading from file if it doesnt exist yet
						$data = maybe_unserialize(file_get_contents($scandatafile));
						return $data;
					} else {
						return NULL;	
					}
				} else {
					file_put_contents($scandatafile, maybe_serialize($data)); // Save contents to file
				}
			} else {
				if("get" == $getorput) { // Are we getting or putting data
					$data = maybe_unserialize(get_option('sc_wpfmp_scan_data'));
					return $data;
				} else {
					update_option('sc_wpfmp_scan_data', maybe_serialize($data));
                }
			}
		}


		/**
		 * Function deals with getting and putting Admin Alert Content to and from DB or FILE
		 *
		 * @param string $getorput "get" to get data "put" to put data
		 * @param string $data if putting data this should contain alert data
		 * @return string $data if getting data this should contain alert data
		*/
		protected function getPutAlertContent($getorput, $data = NULL) {
            $options = get_option(self::$settings_option_field); // Get settings
			if("file" == $options['data_save']) { // Check how data is to be saved
				$scandatafile = dirname(__FILE__)."/data/.sc_wpfmp_admin_alert_content";
				if("get" == $getorput) {// Are we getting or putting data
					if(file_exists($scandatafile)) { // Check if file exists. No point reading from file if it doesnt exist yet
						$data = file_get_contents($scandatafile);
						return $data;
					} else {
						return NULL;	
					}
				} else {
					file_put_contents($scandatafile, $data); // Save contents to file
				}
			} else {
				if("get" == $getorput) { // Are we getting or putting data
					$data = get_option('sc_wpfmp_admin_alert_content');
					return $data;
				} else {
					update_option('sc_wpfmp_admin_alert_content', $data);
				}
			}
		}


        /**
         * Admin notice
         *
         * @return void
         */
		public function admin_alert() {
            $options = get_option(self::$settings_option_field); // Get settings
			if (1 == $options['is_admin_alert'] && 1 == $options['display_admin_alert'] && current_user_can(SC_WPFMP_ADMIN_ALERT_PERMISSION)) : ?>
				<div class="error">
                	<p>
                        <?php _e("<strong>Warning!</strong> WordPress File Monitor Plus has detected a change in the files on your site.", "wordpress-file-monitor-plus"); ?>
                        <br/><br/>
                        <a class="button-secondary thickbox" href="<?php echo admin_url("options-general.php?page=wordpress-file-monitor-plus&sc_wpfmp_action=sc_wpfmp_view_alert"); ?>" title="<?php _e("View file changes and clear this alert", "wordpress-file-monitor-plus"); ?>"><?php _e("View file changes and clear this alert", "wordpress-file-monitor-plus"); ?></a>
                    </p>
				</div>
			<?php endif;
		}


        /**
         * Sets up cron schedule in WP if needed.
         *
         * @param bool|string $manual_interval
         * @return void
         */
		public function enable_cron($manual_interval = false) {
			$options = get_option(self::$settings_option_field); // Get settings
			$currentSchedule = wp_get_schedule(self::$cron_name); // find if a schedule already exists
			if(!empty($manual_interval)) {$options['file_check_interval'] = $manual_interval;} // if a manual cron interval is set, use this
			if("manual" == $options['file_check_interval']) {
                do_action("sc_wpfmp_disable_wp_cron"); // Make sure no cron is setup as we are manual
            } else {
                if($currentSchedule != $options['file_check_interval']) { // check if the current schedule matches the one set in settings
                    if(in_array($options['file_check_interval'], self::$frequency_intervals)) { // check the cron setting is valid
                        do_action("sc_wpfmp_disable_wp_cron"); // remove any crons for this plugin first so we don't end up with multiple crons doing the same thing.
                        wp_schedule_event(time(), $options['file_check_interval'], self::$cron_name); // schedule cron for this plugin.
                    }
                }
            }
		}
		

		/**
		 * Remove any WordPress cron our plugin may have created
         *
         * @return void
		*/
		public function disable_cron() {
			wp_clear_scheduled_hook(self::$cron_name);
		}


		/**
		 * Compares two arrays and returns the difference
		 *
		 * This is a function I picked up from PHP.net some time ago
		 * and can no longer find the author so unable to give credit.
		 *
		 * @param array $array1
		 * @param array $array2
		 * @return array $diff
		*/
		public function array_compare($array1, $array2) { 
			$diff = false; 
			foreach ($array1 as $key => $value) { 
				if (!array_key_exists($key,$array2)) { 
					$diff[0][$key] = $value;
				} elseif (is_array($value)) { 
					if (!is_array($array2[$key])) { 
						$diff[0][$key] = $value; 
						$diff[1][$key] = $array2[$key]; 
					} else { 
						$new = self::array_compare($value, $array2[$key]);
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
		}
		

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
		public function formatRawSize($bytes) {
			if(!empty($bytes)) {
				$s = array('B', 'KB', 'MB', 'GB', 'TB', 'PB');
				$e = floor(log($bytes)/log(1024));
				$output = sprintf('%.' . ($e == 0 ? 0 : 2) . 'f '.$s[$e], ($bytes/pow(1024, $e)));
				return $output;
			}
		}


        /**
         * Filter for formatting the file modified time
         *
         * @param string $formatted
         * @param int $timestamp unix timestamp
         * @return string
        */
        public function format_file_modified_time($formatted = NULL, $timestamp) {
            $date_format = get_option( 'date_format' ); // Get wordpress date format
            $time_format = get_option( 'time_format' ); // Get wordpress time format
            $gmt_offset = get_option( 'gmt_offset' ); // Get wordpress gmt offset
            $formatted = gmdate($date_format." @ ".$time_format, ($timestamp + ($gmt_offset * 3600)));
            return $formatted;
        }

	}

}

// Include Settings Class
require_once("classes/wpfmp.settings.class.php");

// Create instance of plugin classes
$sc_wpfmp = new sc_WordPressFileMonitorPlus();
$sc_wpfmp_settings = new sc_WordPressFileMonitorPlusSettings();
?>