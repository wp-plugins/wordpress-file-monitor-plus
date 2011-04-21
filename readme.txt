=== Plugin Name ===
Contributors: l3rady
Donate link: http://l3rady.com/donate
Tags: security, files, monitor, plugin
Requires at least: 3.1
Tested up to: 3.1.1
Stable tag: 1.1

Monitor files under your WP installation for changes.  When a change occurs, be notified via email. This plugin is a fork of WordPress File Monitor.

== Description ==

Monitors your WordPress installation for added/deleted/changed files.  When a change is detected an email alert can be sent to a specified address.

*Features*

- Monitors file system for added/deleted/changed files
- Sends email when a change is detected
- Administration area alert to notify you of changes in case email is not received
- Ability to monitor files for changes based on file hash, time stamp and/or file size
- Ability to exclude files and directories from scan (for instance if you use a caching system that stores its files within the monitored zone)
- Site URL included in notification email in case plugin is in use on multiple sites
- Ability to run the file checking via an external cron so not to slow down visits to your website and to give greater flexibility over scheduling

== Installation ==

* Upload to a directory named "wordpress-file-monitor-plus" in your plugins directory (usually wp-content/plugins/).
* Activate plugin
* Visit Settings page under Settings -> WordPress File Monitor Plus in your WordPress Administration Area
* Configure plugin settings
* Optionally change the path to the 'File Check Root'.  If you install WordPress inside a subdirectory for instance, you could set this to the directory above that to monitor files outside of the WordPress installation.

== Frequently Asked Questions ==

= I don't think the plugin is working. What should I do? =

[Enable debuging mode in WordPress] and run the plugin and see if any errors are reported from the plugin. If you find errors and don't know how to fix head over to the [WordPress forums]

[Enable debuging mode in WordPress]: http://codex.wordpress.org/Editing_wp-config.php#Debug
[WordPress forums]: http://wordpress.org/tags/wordpress-file-monitor-plus?forum_id=10

= Only admins can see the admin alert. Is it possible to let other user roles see the admin notice? =

Yes you can, add the following code to your wp-config.php file: `define('SC_WPFMP_ADMIN_ALERT_PERMISSION', 'capability');` and change the capability to a level you want. Please visit [Roles and Capabilities] to see all available capabilities that you can set to.

[Roles and Capabilities]: http://codex.wordpress.org/Roles_and_Capabilities

= My website has error_log files that are always changing. How do I get WPFMP to ignore them? =

In settings you can add `error_log` to 'File Names To Ignore' if you want to ignore the error_log file in any directory or add `/full/path/to/error_log` to 'Exact Files To Ignore' if you just want to ignore one perticular error log

= How do I get WPFMP to ignore multiple files and directories? =

Each of the settings 'File Names To Ignore/Dir Names To Ignore/Exact Files To Ignore/Exact Dirs To Ignore' allow multiple entries. To allow multiple entries make sure each entry is on a new line.

= What is the 'other cron' method? =

What this does is stops WordPress from running the 'File Check Scan' on the built in cron scheduler and allows you to run it externally. If you know how to setup a cron externally its recommended that you use this method because scans can take up to 10 seconds and longer. If you rely on the WordPress cron then a vistor may land on your site when the file scan is scheduled to run and the user will have to end up waiting for the scan to finish checking. Waiting for 10 seconds or even longer for a page to load is not great for your visitors.

= I'm getting the error [Got a packet bigger than 'max_allowed_packet' bytes] =

This is due to the scan data being too large to insert into the DB at once. There is one of three things you can do to rectify:
1. Change the `Data Save Method` to `file` instead of `database`. 
2. Reduce the number of `File Check Methods` down to one. I'd recommend you just use `Date Modified` or `File Hash`. 
3. Make a request to your webhost to increase the MySQL `max_allowed_packet` to a larger value. 

== Screenshots ==

1. Settings page
2. Admin alert
3. Admin changed files report
4. Email changed files report

== Changelog ==

= 1.1 =
* Added setting to be able to save scan data and admin alert content to file rather than the database

= 1.0 =
* Initial release