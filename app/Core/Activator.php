<?php

namespace WpabCb\Core;

use WpabCb\Data\DbManager;

/**
 * Fired during plugin activation
 *
 * @link       https://wpanchorbay.com
 * @since      1.0.0
 *
 * @package    WPAB_CampaignBay
 * @subpackage WPAB_CampaignBay/includes
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fired during plugin activation.
 *
 * This class defines all code necessary to run during the plugin's activation.
 *
 * @since      1.0.0
 * @package    WPAB_CampaignBay
 * @subpackage WPAB_CampaignBay/includes
 * @author     WP Anchor Bay <wpanchorbay@gmail.com>
 */
class Activator {

	/**
	 * The main activation method.
	 *
	 * This is fired when the plugin is activated.
	 *
	 * @since    1.0.0
	 */
	public static function activate() {

		// Dependency Check: Is WooCommerce Active? ---
		if ( ! class_exists( 'WooCommerce' ) ) {
			// If the WooCommerce class doesn't exist, stop the activation.
			wp_die(
				// The message displayed to the user.
				esc_html__( 'CampaignBay could not be activated. It requires the WooCommerce plugin to be installed and active.', 'campaignbay' ),
				// The title of the error page.
				esc_html__( 'Plugin Activation Error', 'campaignbay' ),
				// Provides a "Go Back" link.
				array( 'back_link' => true )
			);
		}

		// Environment Check: Is WP-Cron Enabled? ---
		// The `DISABLE_WP_CRON` constant is usually set in wp-config.php.
		if ( defined( 'DISABLE_WP_CRON' ) && true === DISABLE_WP_CRON ) {
			// If WP-Cron is disabled, stop the activation and inform the user.
			wp_die(
				esc_html__( 'CampaignBay requires WP-Cron to be enabled in order for its scheduling features to work. The "DISABLE_WP_CRON" constant is currently set to true in your wp-config.php file. Please remove it or set it to false to activate this plugin.', 'campaignbay' ),
				esc_html__( 'Plugin Activation Error', 'campaignbay' ),
				array( 'back_link' => true )
			);
		}
		// Set up the default options if they don't exist.
		if ( ! get_option( CAMPAIGNBAY_OPTION_NAME ) ) {
			campaignbay_update_options( campaignbay_default_options() );
		}

		// Create custom database tables.
		self::create_custom_tables();

		// Flush rewrite rules to recognize the CPT.
		flush_rewrite_rules();

		// Secure the log directory.
		self::secure_log_directory();
	}

	/**
	 * Instantiates the DB Manager and creates the custom tables.
	 *
	 * @since 1.0.0
	 * @access private
	 */
	private static function create_custom_tables() {
		DbManager::get_instance()->create_tables();
	}

	/**
	 * Secures the log directory by creating an .htaccess file and an index.php file.
	 *
	 * @since    1.0.0
	 * @access private
	 */
	private static function secure_log_directory() {
		$upload_dir = wp_upload_dir();
		$log_dir    = $upload_dir['basedir'] . '/' . CAMPAIGNBAY_TEXT_DOMAIN . '-logs/';

		if ( ! is_dir( $log_dir ) ) {
			wp_mkdir_p( $log_dir );
		}

		$htaccess_file = $log_dir . '.htaccess';
		if ( ! file_exists( $htaccess_file ) ) {
			$htaccess_content = "
			# Protect log files from direct access
			<Files *.log>
				Order allow,deny
				Deny from all
			</Files>
			";
			file_put_contents( $htaccess_file, $htaccess_content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
		}

		// --- Create blank index.php file for security ---
		$index_file = $log_dir . 'index.php';
		if ( ! file_exists( $index_file ) ) {
			$index_content = "<?php\n// Silence is golden.\n";
			file_put_contents( $index_file, $index_content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
		}
	}
}