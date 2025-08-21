<?php

namespace WpabCb\Data;

/**
 * The file that defines the custom post types and statuses for the plugin.
 *
 * A class definition that registers all post types and statuses for backend data storage.
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
 * The custom post type and status definition class.
 *
 * This is used to register the `campaignbay_campaign` post type and the custom statuses
 * like 'Active', 'Scheduled', and 'Expired'. The post type itself has no visible UI in the admin,
 * as it is managed entirely by a React application via the REST API.
 *
 * @since      1.0.0
 * @package    WPAB_CampaignBay
 * @author     WP Anchor Bay <wpanchorbay@gmail.com>
 */
class PostTypes {
	/**
	 * The single instance of the class.
	 *
	 * @since 1.0.0
	 * @var   PostTypes
	 * @access private
	 */
	private static $instance = null;
	/**
	 * Gets an instance of this object.
	 * Prevents duplicate instances which avoid artefacts and improves performance.
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
	 * @see PostTypes::get_instance()
	 *
	 * @since 1.0.0
	 * @access private
	 */
	private function __construct() {
		/* We do nothing here! */
	}

	/**
	 * Hook into WordPress actions and filters.
	 *
	 * @since 1.0.0
	 */
	public function run() {
		add_action( 'init', array( $this, 'register_post_type' ) , 1);
		add_action( 'init', array( $this, 'register_post_statuses' ) ,1);
		// TODO: Add meta fields
		// add_action( 'init', array( $this, 'register_meta_fields' ) );
		// This filter is kept for debugging purposes. It will only have an effect
		// if a developer temporarily sets 'show_ui' to true.
		add_filter( 'display_post_states', array( $this, 'add_display_post_states' ), 10, 2 );
	}

	/**
	 * Register the `campaignbay_campaign` Custom Post Type.
	 *
	 * This CPT is registered without a visible UI in the admin area. It serves as a
	 * backend data store to be managed by the plugin's React interface via the REST API.
	 *
	 * Note: The 'Campaign Type' (category) and other rules are stored as post meta,
	 * not registered as part of the CPT itself. This is handled by the API controller.
	 *
	 * @since 1.0.0
	 */
	public function register_post_type() {
		$labels = array(
			'name'          => _x( 'Campaigns', 'Post Type General Name', 'campaignbay' ),
			'singular_name' => _x( 'Campaign', 'Post Type Singular Name', 'campaignbay' ),
			'all_items'     => __( 'All Campaigns', 'campaignbay' ),
			'add_new_item'  => __( 'Add New Campaign', 'campaignbay' ),
			'add_new'       => __( 'Add New', 'campaignbay' ),
			'edit_item'     => __( 'Edit Campaign', 'campaignbay' ),
			'update_item'   => __( 'Update Campaign', 'campaignbay' ),
			'search_items'  => __( 'Search Campaign', 'campaignbay' ),
		);

		$args = array(
			'label'               => __( 'Campaign', 'campaignbay' ),
			'description'         => __( 'Discount Campaigns for WooCommerce', 'campaignbay' ),
			'labels'              => $labels,
			'supports'            => array( 'title' ), // We only need a title for internal reference.
			'hierarchical'        => false,
			'public'              => false,
			'show_ui'             => false,
			'show_in_menu'        => false,
			'show_in_admin_bar'   => false,
            // 'show_ui'             => true,
			// 'show_in_menu'        => true,
			// 'show_in_admin_bar'   => true,
			'show_in_nav_menus'   => false,
			'can_export'          => true,
			'has_archive'         => false,
			'exclude_from_search' => true,
			'publicly_queryable'  => true,
			'capability_type'     => 'post',
			'show_in_rest'        => true,  // CRITICAL: This makes the CPT available to the REST API and React app.
			'rest_base'           => 'campaigns', // The endpoint will be /wp-json/campaignbay/v1/campaigns/
			'rest_controller_class' => 'WP_REST_Posts_Controller',
		);
		register_post_type( 'campaignbay_campaign', $args );
	}

	/**
	 * Register Custom Post Statuses.
	 *
	 * These statuses are registered to be used programmatically and via the REST API.
	 *
	 * @since 1.0.0
	 */
	public function register_post_statuses() {
		register_post_status(
			'campaignbay_active',
			array(
				'label'                     => _x( 'Active', 'post status', 'campaignbay' ),
				'public'                    => true,
				'show_in_admin_all_list'    => false,
				'show_in_admin_status_list' => true,
				/* translators: %s: number of posts. */
				'label_count'               => _n_noop( 'Active <span class="count">(%s)</span>', 'Active <span class="count">(%s)</span>', 'campaignbay' ),
			)
		);

		register_post_status(
			'cb_scheduled',
			array(
				'label'                     => _x( 'Scheduled', 'post status', 'campaignbay' ),
				'public'                    => true,
				'show_in_admin_all_list'    => false,
				'show_in_admin_status_list' => true,
				/* translators: %s: number of posts. */
				'label_count'               => _n_noop( 'Scheduled <span class="count">(%s)</span>', 'Scheduled <span class="count">(%s)</span>', 'campaignbay' ),
			)
		);

		register_post_status(
			'campaignbay_expired',
			array(
				'label'                     => _x( 'Expired', 'post status', 'campaignbay' ),
				'public'                    => true,
				'show_in_admin_all_list'    => false,
				'show_in_admin_status_list' => true,
				/* translators: %s: number of posts. */
				'label_count'               => _n_noop( 'Expired <span class="count">(%s)</span>', 'Expired <span class="count">(%s)</span>', 'campaignbay' ),
			)
		);
		register_post_status(
			'campaignbay_inactive',
			array(
				'label'                     => _x( 'Inactive', 'post status', 'campaignbay' ),
				'public'                    => true,
				'show_in_admin_all_list'    => false,
				'show_in_admin_status_list' => true,
				/* translators: %s: number of posts. */
				'label_count'               => _n_noop( 'Inactive <span class="count">(%s)</span>', 'Inactive <span class="count">(%s)</span>', 'campaignbay' ),
			)
		);
	}



	/**
	 * Register the meta fields for the `campaignbay_campaign` post type.
	 *
	 * This makes them available in the REST API for the React UI.
	 *
	 * @since 1.0.0
	 */
	public function register_meta_fields() {
		$meta_keys = campaignbay_get_campaign_meta_keys(); 

		foreach ( $meta_keys as $meta_key ) {
			register_post_meta(
				'campaignbay_campaign',
				'_campaignbay_' . $meta_key, 
				array(
					'show_in_rest'  => true,
					'single'        => true, 
					'type'          => 'string', // A default type, can be more specific.
					'auth_callback' => function() {
						return current_user_can( 'edit_posts' );
					},
				)
			);
		}

		// Special handling for the tiers array
		register_post_meta(
			'campaignbay_campaign',
			'_campaignbay_campaign_tiers',
			array(
				'show_in_rest'  => true,
				'single'        => true,
				'type'          => 'array',
				'auth_callback' => function() {
					return current_user_can( 'edit_posts' );
				},
			)
		);
	}



	/**
	 * Add the custom post status to the display states.
	 *
	 * This is kept for debugging. It will only have a visible effect if a developer
	 * temporarily sets 'show_ui' to true in the CPT arguments.
	 *
	 * @param array   $post_states An array of post display states.
	 * @param WP_Post $post        The current post object.
	 * @return array  The modified post states.
	 */
	public function add_display_post_states( $post_states, $post ) {
		if ( ! is_admin() || $post->post_type !== 'campaignbay_campaign' ) {
			return $post_states;
		}

		if ( 'campaignbay_active' === $post->post_status ) {
			$post_states['campaignbay_active'] = __( 'Active', 'campaignbay' );
		}

		if ( 'campaignbay_scheduled' === $post->post_status ) {
			$post_states['campaignbay_scheduled'] = __( 'Scheduled', 'campaignbay' );
		}

		if ( 'campaignbay_expired' === $post->post_status ) {
			$post_states['campaignbay_expired'] = __( 'Expired', 'campaignbay' );
		}

		return $post_states;
	}
}