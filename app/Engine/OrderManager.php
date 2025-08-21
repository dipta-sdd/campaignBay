<?php

namespace WpabCb\Engine;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Import necessary classes.
use WC_Order;

/**
 * Handles actions related to WooCommerce order status changes.
 *
 * This class is responsible for logging campaign usage and recording analytics
 * based on order status transitions.
 *
 * @since      1.0.0
 * @package    WPAB_CampaignBay
 * @author     WP Anchor Bay <wpanchorbay@gmail.com>
 */
class OrderManager {

	private static $instance = null;
	private $hooks = array();

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->define_hooks();
	}

	/**
	 * Defines all hooks this class needs to run.
	 */
	private function define_hooks() {
		// Use the generic hook to capture ALL status changes.
		$this->add_action( 'woocommerce_order_status_changed', 'handle_order_status_change', 10, 4 );
	}

	/**
	 * Returns the complete array of hooks to be registered by the main loader.
	 */
	public function get_hooks() {
		return $this->hooks;
	}

	/**
	 * Main callback that logs every important order status change.
	 *
	 * @param int      $order_id    The ID of the order.
	 * @param string   $old_status  The old order status (without wc- prefix).
	 * @param string   $new_status  The new order status (without wc- prefix).
	 * @param WC_Order $order       The order object.
	 */
	public function handle_order_status_change( $order_id, $old_status, $new_status, $order ) {
		campaignbay_log( sprintf( 'Order #%d status changed from "%s" to "%s". Handling event.', $order_id, $old_status, $new_status ), 'INFO' );

		// Get the discount breakdown we saved from the cart.
		$discount_breakdown = $order->get_meta( '_campaignbay_discount_breakdown', true );

		if ( empty( $discount_breakdown ) || ! is_array( $discount_breakdown ) ) {
			campaignbay_log( sprintf( 'No campaign data found on order #%d. Aborting log.', $order_id ), 'DEBUG' );
			return; // This order was not processed by our plugin.
		}
        do_action( 'campaignbay_create_order');
		// Loop through the breakdown, which has one entry per campaign that was applied.
		foreach ( $discount_breakdown as $campaign_id => $data ) {
			$this->log_sale_event( $campaign_id, $order, $data, $new_status );
		}
	}

	/**
	 * Adds or updates a log entry for a campaign on a specific order.
	 *
	 * This function is the single source of truth for all sale-related logging.
	 * It will UPDATE an existing log if the status changes (e.g., from 'processing' to 'completed').
	 *
	 * @param int      $campaign_id   The ID of the campaign used.
	 * @param WC_Order $order         The order object.
	 * @param array    $data          The discount data for this campaign from the breakdown.
	 * @param string   $new_status    The new status of the order.
	 */
	private function log_sale_event( $campaign_id, $order, $data, $new_status ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'campaignbay_logs';
		$order_id   = $order->get_id();

		// Calculate the base total and the total discount for this specific campaign.
		$base_total     = (float) ( $data['total_old_price'] ?? 0 );
		$new_total      = (float) ( $data['total_new_price'] ?? 0 );
		$total_discount = $base_total - $new_total;

		// Prepare the flexible JSON data column.
		$extra_data = array(
			'campaign_title' => $data['title'] ?? 'Unknown',
		);

		// Prepare the data for the database record.
		$log_data = array(
			'campaign_id'    => $campaign_id,
			'order_id'       => $order_id,
			'user_id'        => $order->get_customer_id(),
			'log_type'       => 'sale', // All order events are of type 'sale'.
			'base_total'     => $base_total,
			'total_discount' => $total_discount,
			'order_total'    => $order->get_total(),
			'order_status'   => $new_status,
			'extra_data'     => wp_json_encode( $extra_data ),
			'timestamp'      => current_time( 'mysql' ),
		);
		
		// Check if a log for this order and campaign already exists.
		$existing_log_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT log_id FROM $table_name WHERE order_id = %d AND campaign_id = %d",
				$order_id,
				$campaign_id
			)
		);
		
		if ( $existing_log_id ) {
			// If it exists, UPDATE it with the new status and timestamp.
			campaignbay_log( sprintf( 'Updating existing log entry #%d for order #%d, campaign #%d. New status: %s', $existing_log_id, $order_id, $campaign_id, $new_status ), 'DEBUG' );
			$wpdb->update( $table_name, $log_data, array( 'log_id' => $existing_log_id ) );
		} else {
			// If it doesn't exist, INSERT a new record.
			campaignbay_log( sprintf( 'Creating new log entry for order #%d, campaign #%d. Status: %s', $order_id, $campaign_id, $new_status ), 'DEBUG' );
			$wpdb->insert( $table_name, $log_data );
			$campaign = new Campaign( $campaign_id );
			$campaign->load_usage_count();
			campaignbay_log('usage_count: ' . $campaign->get_usage_count(), 'DEBUG' );
			CampaignManager::get_instance()->clear_cache('order_manager');
		}
		
	}

	/**
	 * Adds a new action to the hooks array.
	 *
	 * @param string $hook             The name of the WordPress action that is being registered.
	 * @param string $callback         The name of the function definition on this object.
	 * @param int    $priority         Optional. The priority at which the function should be fired. Default is 10.
	 * @param int    $accepted_args    Optional. The number of arguments that should be passed to the $callback. Default is 4.
	 */
	private function add_action( $hook, $callback, $priority = 10, $accepted_args = 4 ) {
		$this->hooks[] = array(
			'type'          => 'action',
			'hook'          => $hook,
			'callback'      => $callback,
			'priority'      => $priority,
			'accepted_args' => $accepted_args,
		);
	}
}

if ( ! function_exists( 'campaignbay_order_manager' ) ) {
	/**
	 * Returns the single instance of the Order Manager class.
	 *
	 * @since 1.0.0
	 * @return OrderManager
	 */
	function campaignbay_order_manager() {
		return OrderManager::get_instance();
	}
}