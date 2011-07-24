<?php
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

class sc_WordPressFileMonitorPlusSettings extends sc_WordPressFileMonitorPlus {

    protected static $frequency_intervals = array("hourly", "twicedaily", "daily", "manual");


    public function __construct() {
		$this->settingsUpToDate(); // Check settings are up to date
        add_action('admin_menu', array(__CLASS__, 'admin_settings_menu')); // Add admin settings menu
        add_action('admin_init', array(__CLASS__, 'admin_settings_init')); // Add admin init functions
        add_filter('plugin_action_links', array(__CLASS__, 'plugin_action_links'), 10, 2); // Add settings link to plugin in plugin list
    }


    /**
     * Check if this plugin settings are up to date. Firstly check the version in
     * the DB. If they don't match then load in defaults but don't override values
     * already set. Also this will remove obsolete settings that are not needed.
     *
     * @return void
     */
    protected function settingsUpToDate() {
        $current_ver = get_option(parent::$settings_option_field_ver); // Get current plugin version
        if(parent::$settings_option_field_current_ver != $current_ver) { // is the version the same as this plugin?
            $options = (array) maybe_unserialize(get_option(parent::$settings_option_field)); // get current settings from DB
            $defaults = array( // Here are the default values
					'cron_method' => 'wordpress', // Cron method to be used for scheduling scans
					'file_check_interval' => 'daily', // How often should the cron run
					'notify_by_email' => 1, // Do we want to be notified by email when there is a file change?
					'data_save' => 'database', // Where to save scan data and admin alert message
					'from_address' => get_option('admin_email'), // Email address the notification comes from
					'notify_address' => get_option('admin_email'), // Email address the notification is sent to
					'site_root' => realpath(ABSPATH), // The file check path to start checking files from
					'exclude_paths' => array(), // What exact directories should we ignore?
					'exclude_files' => array(), // What exact files should we ignore?
					'exclude_paths_wild' => array(), // What directory names should we ignore?
					'exclude_files_wild' => array(), // What file names should we ignore
                    'file_check_method' => array(
                        'size' => 1, // Should we log the filesize of files?
                        'modified' => 1, // Should we log the modified date of files?
                        'md5' => 1 // Should we log the hash of files using md5_file()?
                    ),
					'display_admin_alert' => 1, // Do we allow the plugin to notify us when there is a change in the admin area?
					'is_admin_alert' => 0, // Is there a live admin alert?
					'security_key' => sha1(microtime(true).mt_rand(10000,90000)), // Generate a random key to be used for Other Cron Method
					// The security key is only shown to the admin and has to be used for triggering a manual scan via an external cron.
					// This is to stop non admin users from being able to trigger the cron and potentially abuse server resources.
                    'file_extension_mode' => 0, // 0 = Disabled, 1 = ignore below extensions, 2 = only scan below extensions.
                    'file_extensions' => array('jpg', 'jpeg', 'jpe', 'gif', 'png', 'bmp', 'tif', 'tiff', 'ico') // List of extensions separated by pipe.
			);
            // Intersect current options with defaults. Basically removing settings that are obsolete
            $options = array_intersect_key($options, $defaults);
            // Merge current settings with defaults. Basically adding any new settings with defaults that we dont have.
            $options = array_merge($defaults, $options);
            update_option(parent::$settings_option_field, $options); // update settings
            update_option(parent::$settings_option_field_ver, parent::$settings_option_field_current_ver); // update settings version
        }
    }


    /**
     * Adds settings link on plugin list
     *
     * @param array $links
     * @param string $file
     * @return array $links
     */
    public function plugin_action_links($links, $file) {
        static $this_plugin;
        if (!$this_plugin) { $this_plugin = "wordpress-file-monitor-plus/wordpress-file-monitor-plus.php"; }
        if ($this_plugin == $file){
            $settings_link = '<a href="'.admin_url("options-general.php?page=wordpress-file-monitor-plus").'">'.__("Settings", "wordpress-file-monitor-plus").'</a>';
            array_unshift($links, $settings_link);
            $settings_link = '<a href="'.admin_url("options-general.php?page=wordpress-file-monitor-plus&sc_wpfmp_action=sc_wpfmp_scan").'">'.__("Manual Scan", "wordpress-file-monitor-plus").'</a>';
            array_unshift($links, $settings_link);
        }
        return $links;
    }


    /*
     * EVERYTHING SETTINGS
     *
     * I'm not going to comment any of this as its all pretty
     * much straight forward use of the WordPress Settings API.
     */
    public function admin_settings_menu() {
        $options = get_option(parent::$settings_option_field); // get settings
        if(current_user_can(SC_WPFMP_ADMIN_ALERT_PERMISSION) && "wordpress-file-monitor-plus" == $_GET['page'] && isset($_GET['sc_wpfmp_action'])) {
            switch($_GET['sc_wpfmp_action']) {
                case "sc_wpfmp_scan" :
                    do_action(parent::$cron_name);
                    add_settings_error("sc_wpfmp_settings_main", "sc_wpfmp_settings_main_error", __("Manual scan completed", "wordpress-file-monitor-plus"), "updated");
                break;
                case "sc_wpfmp_reset_settings" :
                    delete_option(parent::$settings_option_field);
                    delete_option(parent::$settings_option_field_ver);
                    self::settingsUpToDate();
                    add_settings_error("sc_wpfmp_settings_main", "sc_wpfmp_settings_main_error", __("Settings reset", "wordpress-file-monitor-plus"), "updated");
                break;
                case "sc_wpfmp_clear_admin_alert" :
                    $options['is_admin_alert'] = 0;
                    update_option(parent::$settings_option_field, $options);
                    add_settings_error("sc_wpfmp_settings_main", "sc_wpfmp_settings_main_error", __("Admin alert cleared", "wordpress-file-monitor-plus"), "updated");
                break;
                case "sc_wpfmp_view_alert" :
                    $alert_content = parent::getPutAlertContent("get");
                    echo $alert_content;
                    exit;
                break;
                default:
                    add_settings_error("sc_wpfmp_settings_main", "sc_wpfmp_settings_main_error", __("Invalid action encountered", "wordpress-file-monitor-plus"), "error");
                break;
            }
        }
        $page = add_options_page('WordPress File Monitor Plus', 'WordPress File Monitor Plus', 'manage_options', 'wordpress-file-monitor-plus', array(__CLASS__, 'settings_page'));
        add_action("admin_print_scripts-$page", array(__CLASS__, 'create_admin_pages_scripts')); // Add js to my settings page
        if(1 == $options['is_admin_alert'] && 1 == $options['display_admin_alert'] && current_user_can(SC_WPFMP_ADMIN_ALERT_PERMISSION)) { // is there an admin display?
            add_action("admin_print_scripts", array(__CLASS__, 'create_admin_pages_tbscripts')); // load thickbox js
            add_action("admin_print_styles", array(__CLASS__, 'create_admin_pages_tbstyles')); // load thickbox css
        }
    }
    public function settings_page() {
        ?>
        <div class="wrap">
        <?php screen_icon(); ?>
        <h2><?php _e("WordPress File Monitor Plus", "wordpress-file-monitor-plus"); ?></h2>
        <form action="options.php" method="post">
        <?php
        $_SERVER['REQUEST_URI'] = remove_query_arg( array( 'sc_wpfmp_action', 'sc_wpfmp_scan', 'sc_wpfmp_reset_settings', 'sc_wpfmp_clear_admin_alert', 'sc_wpfmp_clear_admin_alert' ) );
        settings_fields("sc_wpfmp_settings");
        do_settings_sections("wordpress-file-monitor-plus");
        ?>
        <p class="submit">
          <?php submit_button(__("Save changes", "wordpress-file-monitor-plus"), "primary", "submit", false); ?>
          <a class="button-secondary" href="<?php echo admin_url("options-general.php?page=wordpress-file-monitor-plus&sc_wpfmp_action=sc_wpfmp_scan"); ?>"><?php _e("Manual scan", "wordpress-file-monitor-plus"); ?></a>
          <a class="button-secondary" href="<?php echo admin_url("options-general.php?page=wordpress-file-monitor-plus&sc_wpfmp_action=sc_wpfmp_reset_settings"); ?>"><?php _e("Reset settings to defaults", "wordpress-file-monitor-plus"); ?></a>
        </p>
        </form>
        </div>
        <?php
    }
    public function admin_settings_init() {
        register_setting(parent::$settings_option_field, parent::$settings_option_field, array(__CLASS__, "sc_wpfmp_settings_validate")); // Register Main Settings
        add_settings_section("sc_wpfmp_settings_main", __("Settings", "wordpress-file-monitor-plus"), array(__CLASS__, "sc_wpfmp_settings_main_text"), "wordpress-file-monitor-plus"); // Make settings main section
        add_settings_field("sc_wpfmp_settings_main_cron_method", __("Cron Method", "wordpress-file-monitor-plus"), array(__CLASS__, "sc_wpfmp_settings_main_field_cron_method"), "wordpress-file-monitor-plus", "sc_wpfmp_settings_main");
        add_settings_field("sc_wpfmp_settings_main_file_check_interval", __("File Check Interval", "wordpress-file-monitor-plus"), array(__CLASS__, "sc_wpfmp_settings_main_field_file_check_interval"), "wordpress-file-monitor-plus", "sc_wpfmp_settings_main");
        add_settings_field("sc_wpfmp_settings_main_data_save", __("Data Save Method", "wordpress-file-monitor-plus"), array(__CLASS__, "sc_wpfmp_settings_main_field_data_save"), "wordpress-file-monitor-plus", "sc_wpfmp_settings_main");
        add_settings_field("sc_wpfmp_settings_main_notify_by_email", __("Notify By Email", "wordpress-file-monitor-plus"), array(__CLASS__, "sc_wpfmp_settings_main_field_notify_by_email"), "wordpress-file-monitor-plus", "sc_wpfmp_settings_main");
        add_settings_field("sc_wpfmp_settings_main_from_address", __("From Email Address", "wordpress-file-monitor-plus"), array(__CLASS__, "sc_wpfmp_settings_main_field_from_address"), "wordpress-file-monitor-plus", "sc_wpfmp_settings_main");
        add_settings_field("sc_wpfmp_settings_main_notify_address", __("Notify Email Address", "wordpress-file-monitor-plus"), array(__CLASS__, "sc_wpfmp_settings_main_field_notify_address"), "wordpress-file-monitor-plus", "sc_wpfmp_settings_main");
        add_settings_field("sc_wpfmp_settings_main_display_admin_alert", __("Admin Alert", "wordpress-file-monitor-plus"), array(__CLASS__, "sc_wpfmp_settings_main_field_display_admin_alert"), "wordpress-file-monitor-plus", "sc_wpfmp_settings_main");
        add_settings_field("sc_wpfmp_settings_main_file_check_method", __("File Check Method", "wordpress-file-monitor-plus"), array(__CLASS__, "sc_wpfmp_settings_main_field_file_check_method"), "wordpress-file-monitor-plus", "sc_wpfmp_settings_main");
        add_settings_field("sc_wpfmp_settings_main_site_root", __("File Check Root", "wordpress-file-monitor-plus"), array(__CLASS__, "sc_wpfmp_settings_main_field_site_root"), "wordpress-file-monitor-plus", "sc_wpfmp_settings_main");
        add_settings_field("sc_wpfmp_settings_main_exclude_files_wild", __("File Names To Ignore", "wordpress-file-monitor-plus"), array(__CLASS__, "sc_wpfmp_settings_main_field_exclude_files_wild"), "wordpress-file-monitor-plus", "sc_wpfmp_settings_main");
        add_settings_field("sc_wpfmp_settings_main_exclude_paths_wild", __("Dir Names To Ignore", "wordpress-file-monitor-plus"), array(__CLASS__, "sc_wpfmp_settings_main_field_exclude_paths_wild"), "wordpress-file-monitor-plus", "sc_wpfmp_settings_main");
        add_settings_field("sc_wpfmp_settings_main_exclude_files", __("Exact Files To Ignore", "wordpress-file-monitor-plus"), array(__CLASS__, "sc_wpfmp_settings_main_field_exclude_files"), "wordpress-file-monitor-plus", "sc_wpfmp_settings_main");
        add_settings_field("sc_wpfmp_settings_main_exclude_paths", __("Exact Dirs To Ignore", "wordpress-file-monitor-plus"), array(__CLASS__, "sc_wpfmp_settings_main_field_exclude_paths"), "wordpress-file-monitor-plus", "sc_wpfmp_settings_main");
        add_settings_field("sc_wpfmp_settings_main_file_extension_mode", __("File Extensions Scan", "wordpress-file-monitor-plus"), array(__CLASS__, "sc_wpfmp_settings_main_field_file_extension_mode"), "wordpress-file-monitor-plus", "sc_wpfmp_settings_main");
        add_settings_field("sc_wpfmp_settings_main_file_extensions", __("File Extensions", "wordpress-file-monitor-plus"), array(__CLASS__, "sc_wpfmp_settings_main_field_file_extensions"), "wordpress-file-monitor-plus", "sc_wpfmp_settings_main");
    }
    public function sc_wpfmp_settings_validate($input) {
        $valid = get_option(parent::$settings_option_field);
        if(in_array($input['cron_method'], array("wordpress", "other"))) {
            $valid['cron_method'] = $input['cron_method'];
        } else {
            add_settings_error("sc_wpfmp_settings_main_cron_method", "sc_wpfmp_settings_main_cron_method_error", __("Invalid cron method selected", "wordpress-file-monitor-plus"), "error");
        }
        if("other" == $valid['cron_method']) { // If cron method is other
            $input['file_check_interval'] = "manual"; // then force scan interval to manual
        }
        if(in_array($input['file_check_interval'], self::$frequency_intervals)) {
            $valid['file_check_interval'] = $input['file_check_interval'];
            parent::enable_cron($input['file_check_interval']);
        } else {
            add_settings_error("sc_wpfmp_settings_main_file_check_interval", "sc_wpfmp_settings_main_file_check_interval_error", __("Invalid file check interval selected", "wordpress-file-monitor-plus"), "error");
        }
        if(in_array($input['data_save'], array("database", "file"))) {
            $valid['data_save'] = $input['data_save'];
        } else {
            add_settings_error("sc_wpfmp_settings_main_data_save", "sc_wpfmp_settings_main_data_save_error", __("Invalid data save method selected", "wordpress-file-monitor-plus"), "error");
        }
        $sanitized_notify_by_email = absint($input['notify_by_email']);
        if(1 === $sanitized_notify_by_email || 0 === $sanitized_notify_by_email) {
            $valid['notify_by_email'] = $sanitized_notify_by_email;
        } else {
            add_settings_error("sc_wpfmp_settings_main_notify_by_email", "sc_wpfmp_settings_main_notify_by_email_error", __("Invalid notify by email selected", "wordpress-file-monitor-plus"), "error");
        }
        $sanitized_email_from = sanitize_email($input['from_address']);
        if(is_email($sanitized_email_from)) {
            $valid['from_address'] = $sanitized_email_from;
        } else {
            add_settings_error("sc_wpfmp_settings_main_from_address", "sc_wpfmp_settings_main_from_address_error", __("Invalid from email address entered", "wordpress-file-monitor-plus"), "error");
        }
        $sanitized_email_to = sanitize_email($input['notify_address']);
        if(is_email($sanitized_email_to)) {
            $valid['notify_address'] = $sanitized_email_to;
        } else {
            add_settings_error("sc_wpfmp_settings_main_notify_address", "sc_wpfmp_settings_main_notify_address_error", __("Invalid notify email address entered", "wordpress-file-monitor-plus"), "error");
        }
        $sanitized_display_admin_alert = absint($input['display_admin_alert']);
        if(1 === $sanitized_display_admin_alert || 0 === $sanitized_display_admin_alert) {
            $valid['display_admin_alert'] = $sanitized_display_admin_alert;
        } else {
            add_settings_error("sc_wpfmp_settings_main_display_admin_alert", "sc_wpfmp_settings_main_display_admin_alert_error", __("Invalid display admin alert selected", "wordpress-file-monitor-plus"), "error");
        }
        $valid['file_check_method'] = array_map(array(__CLASS__, 'file_check_method_func'), $input['file_check_method']);
        $sanitized_site_root = realpath($input['site_root']);
        if(is_dir($sanitized_site_root) && is_readable($sanitized_site_root)) {
            $valid['site_root'] = $sanitized_site_root;
        } else {
            add_settings_error("sc_wpfmp_settings_main_site_root", "sc_wpfmp_settings_main_site_root_error", __("File check root is not valid. Make sure that PHP has read permissions of the entered file check root", "wordpress-file-monitor-plus"), "error");
        }
        $valid['exclude_files_wild'] = self::textarea_newlines_to_array($input['exclude_files_wild']);
        $valid['exclude_paths_wild'] = self::textarea_newlines_to_array($input['exclude_paths_wild']);
        $valid['exclude_files'] = self::textarea_newlines_to_array($input['exclude_files']);
        $valid['exclude_paths'] = self::textarea_newlines_to_array($input['exclude_paths']);
        if(!empty($valid['exclude_paths'])) {
            $valid['exclude_paths'] = array_map('realpath', $valid['exclude_paths']);
        }
        $sanitized_file_extension_mode = absint($input['file_extension_mode']);
        if(2 === $sanitized_file_extension_mode || 1 === $sanitized_file_extension_mode || 0 === $sanitized_file_extension_mode) {
            $valid['file_extension_mode'] = $sanitized_file_extension_mode;
        } else {
            add_settings_error("sc_wpfmp_settings_main_file_extension_mode", "sc_wpfmp_settings_main_file_extension_mode_error", __("Invalid file extension mode selected", "wordpress-file-monitor-plus"), "error");
        }
        $valid['file_extensions'] = self::file_extensions_to_array($input['file_extensions']);
        return $valid;
    }
    public function sc_wpfmp_settings_main_text() {}
    public function sc_wpfmp_settings_main_field_cron_method() {
        $options = get_option(parent::$settings_option_field);
        ?>
        <select name="<?php echo parent::$settings_option_field ?>[cron_method]">
            <option value="wordpress" <?php selected( $options['cron_method'], "wordpress" ); ?>><?php _e("WordPress Cron", "wordpress-file-monitor-plus"); ?></option>
            <option value="other" <?php selected( $options['cron_method'], "other" ); ?>><?php _e("Other Cron", "wordpress-file-monitor-plus"); ?></option>
        </select>
        <div>
            <br />
            <span class="description"><?php _e("Cron Command: ", "wordpress-file-monitor-plus"); ?></span>
            <pre>wget -q "<?php echo site_url(); ?>/index.php?sc_wpfmp_scan=1&amp;sc_wpfmp_key=<?php echo $options['security_key']; ?>" -O /dev/null >/dev/null 2>&amp;1</pre>
        </div>
        <?php
    }
    public function sc_wpfmp_settings_main_field_file_check_interval() {
        $options = get_option(parent::$settings_option_field);
        ?>
        <select name="<?php echo parent::$settings_option_field ?>[file_check_interval]">
            <option value="<?php echo self::$frequency_intervals[0]; ?>" <?php selected( $options['file_check_interval'], self::$frequency_intervals[0] ); ?>><?php _e("Hourly", "wordpress-file-monitor-plus"); ?></option>
            <option value="<?php echo self::$frequency_intervals[1]; ?>" <?php selected( $options['file_check_interval'], self::$frequency_intervals[1] ); ?>><?php _e("Twice Daily", "wordpress-file-monitor-plus"); ?></option>
            <option value="<?php echo self::$frequency_intervals[2]; ?>" <?php selected( $options['file_check_interval'], self::$frequency_intervals[2] ); ?>><?php _e("Daily", "wordpress-file-monitor-plus"); ?></option>
            <option value="<?php echo self::$frequency_intervals[3]; ?>" <?php selected( $options['file_check_interval'], self::$frequency_intervals[3] ); ?>><?php _e("Manual", "wordpress-file-monitor-plus"); ?></option>
        </select>
        <?php
    }
    public function sc_wpfmp_settings_main_field_data_save() {
        $options = get_option(parent::$settings_option_field);
        ?>
        <select name="<?php echo parent::$settings_option_field ?>[data_save]">
            <option value="database" <?php selected( $options['data_save'], "database" ); ?>><?php _e("Database", "wordpress-file-monitor-plus"); ?></option>
            <option value="file" <?php selected( $options['data_save'], "file" ); ?>><?php _e("File", "wordpress-file-monitor-plus"); ?></option>
        </select>
        <?php
    }
    public function sc_wpfmp_settings_main_field_notify_by_email() {
        $options = get_option(parent::$settings_option_field);
        ?>
        <select name="<?php echo parent::$settings_option_field ?>[notify_by_email]">
            <option value="1" <?php selected( $options['notify_by_email'], 1 ); ?>><?php _e("Yes", "wordpress-file-monitor-plus"); ?></option>
            <option value="0" <?php selected( $options['notify_by_email'], 0 ); ?>><?php _e("No", "wordpress-file-monitor-plus"); ?></option>
        </select>
        <?php
    }
    public function sc_wpfmp_settings_main_field_from_address() {
        $options = get_option(parent::$settings_option_field);
        ?><input class="regular-text" name="<?php echo parent::$settings_option_field ?>[from_address]" value="<?php echo $options['from_address']; ?>" /><?php
    }
    public function sc_wpfmp_settings_main_field_notify_address() {
        $options = get_option(parent::$settings_option_field);
        ?><input class="regular-text" name="<?php echo parent::$settings_option_field ?>[notify_address]" value="<?php echo $options['notify_address']; ?>" /><?php
    }
    public function sc_wpfmp_settings_main_field_display_admin_alert() {
        $options = get_option(parent::$settings_option_field);
        ?>
        <select name="<?php echo parent::$settings_option_field ?>[display_admin_alert]">
            <option value="1" <?php selected( $options['display_admin_alert'], 1 ); ?>><?php _e("Yes", "wordpress-file-monitor-plus"); ?></option>
            <option value="0" <?php selected( $options['display_admin_alert'], 0 ); ?>><?php _e("No", "wordpress-file-monitor-plus"); ?></option>
        </select>
        <?php
    }
    public function sc_wpfmp_settings_main_field_file_check_method() {
        $options = get_option(parent::$settings_option_field);
        ?>
        <input name="<?php echo parent::$settings_option_field ?>[file_check_method][size]" type="checkbox" value="1" <?php checked( $options['file_check_method']['size'], 1 ); ?> /><?php _e(" File Size", "wordpress-file-monitor-plus"); ?><br />
        <input name="<?php echo parent::$settings_option_field ?>[file_check_method][modified]" type="checkbox" value="1" <?php checked( $options['file_check_method']['modified'], 1 ); ?> /><?php _e(" Date Modified", "wordpress-file-monitor-plus"); ?><br />
        <input name="<?php echo parent::$settings_option_field ?>[file_check_method][md5]" type="checkbox" value="1" <?php checked( $options['file_check_method']['md5'], 1 ); ?> /><?php _e(" File Hash", "wordpress-file-monitor-plus"); ?>
        <?php
    }
    public function sc_wpfmp_settings_main_field_site_root() {
        $options = get_option(parent::$settings_option_field);
        ?><input name="<?php echo parent::$settings_option_field ?>[site_root]" value="<?php echo $options['site_root']; ?>" /> <span class="description"><?php printf(__("Default: %s", "wordpress-file-monitor-plus"), realpath(ABSPATH)); ?></span><?php
    }
    public function sc_wpfmp_settings_main_field_exclude_files_wild() {
        $options = get_option(parent::$settings_option_field);
        ?><textarea name="<?php echo parent::$settings_option_field ?>[exclude_files_wild]" cols="25" rows="3"><?php echo implode("\n", $options['exclude_files_wild']); ?></textarea><?php
    }
    public function sc_wpfmp_settings_main_field_exclude_paths_wild() {
        $options = get_option(parent::$settings_option_field);
        ?><textarea name="<?php echo parent::$settings_option_field ?>[exclude_paths_wild]" cols="25" rows="3"><?php echo implode("\n", $options['exclude_paths_wild']); ?></textarea><?php
    }
    public function sc_wpfmp_settings_main_field_exclude_files() {
        $options = get_option(parent::$settings_option_field);
        ?><textarea name="<?php echo parent::$settings_option_field ?>[exclude_files]" cols="25" rows="3"><?php echo implode("\n", $options['exclude_files']); ?></textarea><?php
    }
    public function sc_wpfmp_settings_main_field_exclude_paths() {
        $options = get_option(parent::$settings_option_field);
        ?><textarea name="<?php echo parent::$settings_option_field ?>[exclude_paths]" cols="25" rows="3"><?php echo implode("\n", $options['exclude_paths']); ?></textarea><?php
    }
    public function sc_wpfmp_settings_main_field_file_extension_mode() {
        $options = get_option(parent::$settings_option_field);
        ?>
        <select name="<?php echo parent::$settings_option_field ?>[file_extension_mode]">
            <option value="0" <?php selected( $options['file_extension_mode'], 0 ); ?>><?php _e("Disabled", "wordpress-file-monitor-plus"); ?></option>
            <option value="1" <?php selected( $options['file_extension_mode'], 1 ); ?>><?php _e("Exclude files that have an extension listed below", "wordpress-file-monitor-plus"); ?></option>
            <option value="2" <?php selected( $options['file_extension_mode'], 2 ); ?>><?php _e("Only scan files that have an extension listed below", "wordpress-file-monitor-plus"); ?></option>
        </select>
        <?php
    }
    public function sc_wpfmp_settings_main_field_file_extensions() {
        $options = get_option(parent::$settings_option_field);
        ?><input class="regular-text" name="<?php echo parent::$settings_option_field ?>[file_extensions]" value="<?php echo implode($options['file_extensions'], "|"); ?>" /> <span class="description"><?php _e("Separate extensions with | character.", "wordpress-file-monitor-plus"); ?></span><?php
    }
    public function create_admin_pages_scripts() {
        wp_enqueue_script('wordpress_file_monitor_plus_js_function', plugins_url('js/function.js', "wordpress-file-monitor-plus/wordpress-file-monitor-plus.php"), array('jquery'), '1.2', true);
    }
    public function create_admin_pages_tbscripts() {
        wp_enqueue_script('thickbox');
    }
    public function create_admin_pages_tbstyles() {
        wp_enqueue_style('thickbox');
    }
    protected function file_check_method_func($n) {
        $n = absint($n);
        if(1 !== $n) { $n = 0; }
        return $n;
    }


    /**
     * Takes multiline input from textarea and splits newlines into an array.
     *
     * @param string $input Text from textarea
     * @return array $output
     */
    protected function textarea_newlines_to_array($input) {
        $output = (array) explode("\n", $input); // Split textarea input by new lines
        $output = array_map('trim', $output); // trim whitespace off end of line.
        $output = array_filter($output); // remove empty lines from array
        return $output; // return array.
    }


    /**
     * Takes extension list "foo|bar|foo|bar" and converts into array.
     *
     * @param string $input Extension list from settings page input
     * @return array $output
     */
    protected function file_extensions_to_array($input) {
        $output = strtolower($input); // set all to lower case
        $output = preg_replace("/[^a-z0-9|]+/", "", $output); // strip characters that cannot make up valid extension
        $output = (array) explode("|", $output); // Split into array
        $output = array_filter($output); // remove empty entries from array
        return $output;
    }
}
?>