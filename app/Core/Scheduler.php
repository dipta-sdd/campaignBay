<?php

namespace WpabCb\Core;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Import necessary classes
use WpabCb\Engine\Campaign;
use WpabCb\Engine\CampaignManager;
use WP_Post;
use WP_Query;

/**
 * Handles the WP-Cron scheduling for campaigns.
 *
 * This class is responsible for scheduling, unscheduling, and executing the activation
 * and expiration of time-based campaigns based on their post status.
 *
 * @since      1.0.0
 * @package    WPAB_CampaignBay
 * @author     WP Anchor Bay <wpanchorbay@gmail.com>
 */
class Scheduler {

	/**
	 * The single instance of the class.
	 *
	 * @since 1.0.0
	 * @var   Scheduler
	 * @access private
	 */
	private static $instance = null;

	/**
	 * The array of hooks to be registered by the main loader.
	 *
	 * @since 1.0.0
	 * @access private
	 * @var array
	 */
	private $hooks = array();

	// Define our custom hook names as constants for consistency and to avoid typos.
	const ACTIVATION_HOOK   = 'campaignbay_activate_campaign_event';
	const DEACTIVATION_HOOK = 'campaignbay_deactivate_campaign_event';


	/**
	 * Gets an instance of this object.
	 *
	 * @static
	 * @access public
	 * @since 1.0.0
	 * @return Scheduler
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor to define hooks and enforce singleton pattern.
	 *
	 * @since 1.0.0
	 */
	private function __construct() {
		$this->define_hooks();
	}

	public function run() {
		// $this->check_scheduled_campaigns();

	}

	/**
	 * Defines all hooks this class needs to run.
	 *
	 * @since 1.0.0
	 * @access private
	 */
	private function define_hooks() {
		// The main trigger to set up schedules when a campaign is saved or updated.
		$this->add_action( 'campaignbay_campaign_save', 'handle_campaign_save', 10, 2 );

		// The hooks that our cron events will trigger to change the campaign status.
		$this->add_action( self::ACTIVATION_HOOK, 'run_campaign_activation', 10, 1 );
		$this->add_action( self::DEACTIVATION_HOOK, 'run_campaign_deactivation', 10, 1 );

		// Hook to clean up schedules if a campaign is deleted from the database.
		$this->add_action( 'campaignbay_campaign_delete', 'clear_campaign_schedules_on_delete', 10, 1 );

		// Hook to check for scheduled campaigns.
		// dont change this priority, it must be 2 to run after the post type is registered.
		$this->add_action( 'init', 'run_scheduled_campaigns_cron', 2, 0 );
	}

	/**
	 * Returns the complete array of hooks to be registered by the main loader.
	 *
	 * @since 1.0.0
	 * @return array
	 */
	public function get_hooks() {
		return $this->hooks;
	}

	/**
	 * Main handler that runs when a campaign post is saved.
	 * It decides whether to schedule or unschedule events based on the new post status.
	 *
	 * @param int     $campaign_id The post ID of the campaign.
	 * @param WP_Post $campaign    The post object.
	 */
	public function handle_campaign_save( $campaign_id, $campaign ) {
		$campaign = new Campaign( $campaign_id );
		$this->clear_campaign_schedules( $campaign_id );
		$status = $campaign->get_status();
		if ( 'cb_scheduled' !== $status ) {
			campaignbay_log( sprintf( 'Campaign #%d status is "%s", not "scheduled". No new events will be scheduled.', $campaign_id, $campaign->get_status() ), 'DEBUG' );
			return;
		}

		$start_timestamp = $campaign->get_start_timestamp();
		$end_timestamp   = $campaign->get_end_timestamp();
		$current_time    = time();

		
	
		// Schedule the activation event if the start time is in the future.
		if ( $start_timestamp && $start_timestamp > $current_time ) {
			wp_schedule_single_event( $start_timestamp, self::ACTIVATION_HOOK, array( 'campaign_id' => $campaign_id ) );
			campaignbay_log( sprintf( 'Scheduled activation for campaign #%s at timestamp %s.', $campaign_id, $start_timestamp ), 'INFO' );
		} elseif ( $start_timestamp && $start_timestamp <= $current_time ) {
			$this->run_campaign_activation( $campaign_id );
			campaignbay_log( sprintf( 'Campaign #%s activation time is in the past, running activation now.', $campaign_id ), 'INFO' );
		}

		// Schedule the deactivation event if the end time is in the future.
		if ( $end_timestamp && $end_timestamp > $current_time ) {
			wp_schedule_single_event( $end_timestamp, self::DEACTIVATION_HOOK, array( 'campaign_id' => $campaign_id ) );
			campaignbay_log( sprintf( 'Scheduled deactivation for campaign #%s at timestamp %s.', $campaign_id, $end_timestamp ), 'INFO' );
		}
	}

	/**
	 * A failsafe method that manually checks all scheduled and active campaigns.
	 *
	 * This acts as a safety net for unreliable WP-Cron environments. It queries for
	 * any campaigns that should have been activated or deactivated by now and corrects
	 * their status, relying on the Campaign object's internal date logic.
	 *
	 * @since 1.0.0
	 */
	public function run_scheduled_campaigns_cron() {
		$campaigns_to_check_query = new WP_Query(
			array(
				'post_type'      => 'campaignbay_campaign',
				// Get all campaigns that are currently in a state that could potentially change.
				'post_status'    => array( 'cb_scheduled', 'cb_scheduled' ),
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'fields'         => 'ids', // Performance: only fetch the post IDs.
			)
		);

		if ( ! $campaigns_to_check_query->have_posts() ) {
			return;
		}

		// Get the current time once as a UTC timestamp.
		$current_timestamp    = time();
		$cache_needs_clearing = false;

		foreach ( $campaigns_to_check_query->get_posts() as $campaign_id ) {
			$campaign = new Campaign( $campaign_id );

			$start_timestamp = $campaign->get_start_timestamp();
			$end_timestamp   = $campaign->get_end_timestamp();
			
			if ( 'cb_scheduled' === $campaign->get_status() && $start_timestamp && $start_timestamp <= $current_timestamp ) {
				campaignbay_log( sprintf( 'Failsafe: Activating campaign #%d (%s) as its start time (%s) has passed. Current time: %s.', $campaign_id, $campaign->get_title(), date( 'Y-m-d H:i:s', $start_timestamp ), date( 'Y-m-d H:i:s', $current_timestamp ) ), 'INFO' );
				$this->run_campaign_activation( $campaign->get_id() );
				$cache_needs_clearing = true;
			}
			
			// --- Check for Deactivation ---
			// If the campaign is active and its end time has passed, expire it.
			if ( 'cb_scheduled' === $campaign->get_status() && $end_timestamp && $end_timestamp <= $current_timestamp ) {
				campaignbay_log( sprintf( 'Failsafe: Expiring campaign #%d (%s) as its end time (%s) has passed. Current time: %s.', $campaign_id, $campaign->get_title(), date( 'Y-m-d H:i:s', $end_timestamp ), date( 'Y-m-d H:i:s', $current_timestamp ) ), 'INFO' );
				$this->run_campaign_deactivation( $campaign->get_id() );
				$cache_needs_clearing = true;
			}
		}

		if ( $cache_needs_clearing ) {
			CampaignManager::get_instance()->clear_cache();
		}
	}

	/**
	 * Runs when the activation cron event fires. Changes status to 'active'.
	 *
	 * @param int $campaign_id The ID of the campaign to activate.
	 */
	public function run_campaign_activation( $campaign_id ) {
		campaignbay_log( sprintf( 'WP-Cron: Running activation for campaign #%d.', $campaign_id ), 'INFO' );
		
		// Update the post status to 'active'.
		wp_update_post(
			array(
				'ID'          => $campaign_id,
				'post_status' => 'cb_scheduled',
			)
		);
		// Clear the main campaign cache so the change is reflected immediately on the frontend.
		CampaignManager::get_instance()->clear_cache('scheduler');
	}

	/**
	 * Runs when the deactivation cron event fires. Changes status to 'expired'.
	 *
	 * @param int $campaign_id The ID of the campaign to expire.
	 */
	public function run_campaign_deactivation( $campaign_id ) {
		campaignbay_log( sprintf( 'WP-Cron: Running deactivation for campaign #%d.', $campaign_id ), 'INFO' );
		
		// Update the post status to 'expired'.
		wp_update_post(
			array(
				'ID'          => $campaign_id,
				'post_status' => 'campaignbay_expired',
			)
		);
		// Clear the main campaign cache.
		CampaignManager::get_instance()->clear_cache('scheduler');
	}

	/**
	 * Clears schedules when a post is about to be deleted.
	 *
	 * @param int $campaign_id The post ID being deleted.
	 */
	public function clear_campaign_schedules_on_delete( $campaign_id ) {
		if ( 'campaignbay_campaign' === get_post_type( $campaign_id ) ) {
			$this->clear_campaign_schedules( $campaign_id );
		}
	}

	/**
	 * Clears all scheduled cron events associated with a specific campaign.
	 *
	 * @param int $campaign_id The post ID of the campaign.
	 */
	private function clear_campaign_schedules( $campaign_id	 ) {
		// We must pass the same arguments array we used when scheduling to ensure the correct hook is cleared.
		$args = array( 'campaign_id' => $campaign_id );
		wp_clear_scheduled_hook( self::ACTIVATION_HOOK, $args );
		wp_clear_scheduled_hook( self::DEACTIVATION_HOOK, $args );
		campaignbay_log( sprintf( 'Cleared all schedules for campaign #%d.', $campaign_id ), 'DEBUG' );
	}

	/**
	 * Adds a new action to the hooks array.
	 *
	 * @param string $hook The hook name.
	 * @param string $callback The callback method on this object.
	 * @param int    $priority The priority.
	 * @param int    $accepted_args The number of accepted arguments.
	 */
	private function add_action( $hook, $callback, $priority = 10, $accepted_args = 2 ) {
		$this->hooks[] = array(
			'type'          => 'action',
			'hook'          => $hook,
			'callback'      => $callback,
			'priority'      => $priority,
			'accepted_args' => $accepted_args,
		);
	}


}