<?php

namespace WpabCb\Data;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DbManager {
    /**
     * The single instance of the class.
     *
     * @since 1.0.0
     * @var   DbManager
     * @access private
     */
    private static $instance = null;
    /**
     * Gets an instance of this object.
     * Prevents duplicate instances which avoids artefacts and improves performance.
     *
     * @static
     * @access public
     * @since 1.0.0
     * @return object
     */
    public static function get_instance() {
		// Store the instance locally to avoid private static replication.
		static $instance = null;
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

    /**
     * A dummy constructor to prevent the class from being loaded more than once.
     *
     * @see DbManager::get_instance()
     *
     * @since 1.0.0
     * @access private
     */
    private function __construct() {
        // Prevent direct instantiation.
    }

    /**
     * Run the database manager.
     *
     * This method is called to initialize the database manager and create necessary tables.
     *
     * @since 1.0.0
     */
    public function create_tables() {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $this->create_logs_table();
    }

    /**
     * Create the logs table.
     *
     * This table stores analytics and activity data.
     *
     * @since 1.0.0
     * @access private
     */
    private function create_logs_table() {
		global $wpdb;
		$table_name      = $wpdb->prefix . 'campaignbay_logs';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			log_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			campaign_id BIGINT(20) UNSIGNED NOT NULL,
			order_id BIGINT(20) UNSIGNED DEFAULT 0 NOT NULL,
			user_id BIGINT(20) UNSIGNED DEFAULT 0 NOT NULL,
			log_type VARCHAR(20) NOT NULL,
            base_total DECIMAL(10, 2) DEFAULT 0.00 NOT NULL,
			total_discount DECIMAL(10, 2) DEFAULT 0.00 NOT NULL,
			order_total DECIMAL(10, 2) DEFAULT 0.00 NOT NULL,
			order_status VARCHAR(20) DEFAULT '' NOT NULL,
            extra_data JSON DEFAULT NULL,
            timestamp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (log_id),
			KEY campaign_id (campaign_id),
			KEY order_status (order_status),
			KEY timestamp (timestamp)
		) $charset_collate;";

		dbDelta( $sql );
	}

}