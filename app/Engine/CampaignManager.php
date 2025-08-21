<?php

namespace WpabCb\Engine;

use WpabCb\Engine\Campaign;

/**
 * The file that defines the Campaign Manager class.
 *
 * A class definition that fetches and caches all active discount campaigns.
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

// Import WordPress classes
use WP_Query;

/**
 * The Campaign Manager class.
 *
 * This class is responsible for querying for all active campaigns and caching the result
 * to prevent redundant database calls, especially on high-traffic frontend pages.
 *
 * @since      1.0.0
 * @package    WPAB_CampaignBay
 * @author     WP Anchor Bay <wpanchorbay@gmail.com>
 */
class CampaignManager {

	/**
	 * The single instance of the class.
	 *
	 * @since 1.0.0
	 * @var   CampaignManager
	 * @access private
	 */
	private static $instance = null;

	/**
	 * The array of hooks to be registered.
	 *
	 * @since 1.0.0
	 * @access private
	 * @var array
	 */
	private $hooks = array();

	/**
	 * A local cache of the active campaigns for the current page load.
	 *
	 * @since 1.0.0
	 * @var   array|null
	 * @access private
	 */
	private $active_campaigns = null;

	/**
	 * Gets an instance of this object.
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
	 * Constructor to define and build the hooks array.
	 *
	 * @since 1.0.0
	 */
	private function __construct() {
		$this->define_hooks();
	}

	/**
	 * Defines all hooks this class needs to run using our fluent methods.
	 *
	 * @since 1.0.0
	 * @access private
	 */
	private function define_hooks() {
		$this->add_action( 'save_post_campaignbay_campaign', 'clear_cache' );
		$this->add_action( 'delete_post', 'clear_cache' );
		// $this->add_action( 'campaignbay_create_order', 'clear_cache', 10,1);

	}

	/**
	 * Adds a new action to the hooks array.
	 *
	 * @since 1.0.0
	 * @access private
	 * @param string $hook The hook name.
	 * @param string $callback The callback method on this object.
	 * @param int    $priority The priority.
	 * @param int    $accepted_args The number of accepted arguments.
	 */
	private function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$this->hooks[] = array(
			'type'          => 'action',
			'hook'          => $hook,
			'callback'      => $callback,
			'priority'      => $priority,
			'accepted_args' => $accepted_args,
		);
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
	 * Get all active campaigns, using a multi-level cache.
	 * (This method and others below remain unchanged)
	 *
	 * @since 1.0.0
	 * @return Campaign[] An array of active campaign objects.
	 */
	public function get_active_campaigns() {
		if ( null !== $this->active_campaigns ) {
			
			return $this->active_campaigns;
		}
		$cached_campaigns = get_transient( 'campaignbay_active_campaigns' );
		if ( false !== $cached_campaigns ) {
			campaignbay_log('cached_campaigns found', 'DEBUG' );
			$this->active_campaigns = $cached_campaigns;
			return $this->active_campaigns;
		}
		campaignbay_log('no cached campaigns found, fetching from database', 'DEBUG' );
		$campaign_args = array(
			'post_type'      => 'campaignbay_campaign',
			'post_status'    => 'cb_scheduled',
			'posts_per_page' => -1,
			'no_found_rows'  => true,
		);
		$query = new WP_Query( $campaign_args );
		$campaign_objects = array();
		if ( $query->have_posts() ) {
			foreach ( $query->get_posts() as $post ) {
				$campaign = new Campaign( $post );
				if ( $campaign ) {
					$campaign->load_usage_count();
					$campaign_objects[ $campaign->get_id() ] = $campaign;
				}
			}
		} else {
			campaignbay_log('query has no posts', 'DEBUG' );
		}

		set_transient( 'campaignbay_active_campaigns', $campaign_objects, 60 * MINUTE_IN_SECONDS );
		$this->active_campaigns = $campaign_objects;
		return $this->active_campaigns;
	}

	

	/**
	 * Clears the active campaign transient cache.
	 *
	 * @since 1.0.0
	 */
	public function clear_cache($source = 'unknown') {
		campaignbay_log('clearing cache from ' . $source, 'DEBUG' );
		delete_transient( 'campaignbay_active_campaigns' );
		$this->active_campaigns = null;
	}
}