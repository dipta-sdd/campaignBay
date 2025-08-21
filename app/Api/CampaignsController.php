<?php // phpcs:ignore Class file names should be based on the class name with "class-" prepended.

namespace WpabCb\Api;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Import WordPress REST API classes
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use WP_Query;
use WpabCb\Engine\Campaign;
use WpabCb\Engine\CampaignManager;
use WpabCb\Core\Logger;

/**
 * The REST API Controller for Campaigns.
 *
 * @link       https://wpanchorbay.com
 * @since      1.0.0
 *
 * @package    WPAB_CampaignBay
 * @subpackage WPAB_CampaignBay/includes/api
 */

/**
 * The Campaign API controller class.
 *
 * This class defines the REST API endpoints for managing campaigns.
 * It uses the Campaign class to interact with the database.
 *
 * @since      1.0.0
 * @package    WPAB_CampaignBay
 * @author     WP Anchor Bay <wpanchorbay@gmail.com>
 */
class CampaignsController extends ApiController {
	/**
	 * The single instance of the class.
	 *
	 * @since 1.0.0
	 * @var   CampaignsController
	 * @access private
	 */
	private static $instance = null;
	/**
	 * Gets an instance of this object.
	 * Prevents duplicate instances which avoid artefacts and improves performance.
	 *
	 * @static
	 * @access public
	 * @return object
	 * @since 1.0.0
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
	 * Initialize the class.
	 *
	 * @since 1.0.0
	 */
	public function run() {
		$this->rest_base = 'campaigns';
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the routes for the objects of the controller.
	 *
	 * @since 1.0.0
	 */
	public function register_routes() {
		$namespace = $this->namespace . $this->version;

		// campaigns
		register_rest_route(
			$namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => $this->get_collection_params(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::CREATABLE ),
				),
				'schema' => array( $this, 'get_item_schema' ), // Corrected from get_public_item_schema
			)
		);

		// campaigns/{id}
		register_rest_route(
			$namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			array(
				'args'   => array(
					'id' => array(
						'description' => __( 'Unique identifier for the campaign.', 'campaignbay' ),
						'type'        => 'integer',
					),
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'context' => $this->get_context_param( array( 'default' => 'view' ) ),
					),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::EDITABLE ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'force' => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'Whether to bypass trash and force deletion.', 'campaignbay' ),
						),
					),
				),
				'schema' => array( $this, 'get_item_schema' ),
			)
		);

		// campaigns/bulk
		register_rest_route(
			$namespace,
			'/' . $this->rest_base . '/bulk',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_items' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => $this->get_bulk_update_args(), // <-- Use specific args
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_items' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => $this->get_bulk_delete_args(), // <-- Use specific args
				),
			)
		);
	}

	/**
	 * Check if a given request has access to read and manage campaigns.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return bool|WP_Error True if the request has read access for the item, otherwise false or WP_Error object.
	 */
	public function permissions_check( $request ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Sorry, you are not allowed to manage campaigns.', 'campaignbay' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}


	/**
	 * Get a collection of campaigns.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function get_items( $request ) {
		$args = array(
			'post_type'      => 'campaignbay_campaign',
			'posts_per_page' => $request->get_param( 'per_page' ) ?: 10,
			'paged'          => $request->get_param( 'page' ) ?: 1,
			's'              => $request->get_param( 'search' ) ?: '',
			
			// --- CHANGED: Update the default sorting parameters ---
			'orderby'        => $request->get_param( 'orderby' ) ?: 'modified', // Default to sorting by last modified date
			'order'          => $request->get_param( 'order' ) ?: 'desc',     // Default to descending order (most recent first)
		);

		// Handle Status Filtering
		$status = $request->get_param( 'status' );
		if ( ! empty( $status ) && 'all' !== $status ) {
			// Prepend the custom status prefix if it's not a built-in status
			if ( ! in_array( $status, array( 'draft', 'publish', 'trash' ), true ) ) {
				$args['post_status'] = 'campaignbay_' . $status;
			} else {
				$args['post_status'] = $status;
			}
		} else {
			$args['post_status'] = 'any';
		}

		// Handle Meta Filtering for campaign_type
		$campaign_type_filter = $request->get_param( 'type' );
		if ( ! empty( $campaign_type_filter ) ) {
			// Initialize the meta_query array correctly
			$meta_query = array(
				'relation' => 'AND',
			);
			$meta_query[] = array(
				'key'     => '_campaignbay_campaign_type', // Ensure the key has the underscore prefix
				'value'   => sanitize_text_field( $campaign_type_filter ),
				'compare' => '=',
			);
			$args['meta_query'] = $meta_query;
		}

		$query         = new WP_Query( $args );
		$response_data = array();

		if ( $query->have_posts() ) {
			foreach ( $query->get_posts() as $post ) {
				$campaign = new Campaign( $post );
				if ( $campaign ) {
					$response_data[] = $this->prepare_item_for_response( $campaign, $request );
				}
			}
		}
		
		$response = new WP_REST_Response( $response_data, 200 );
		$response->header( 'X-WP-Total', $query->found_posts );
		$response->header( 'X-WP-TotalPages', $query->max_num_pages );

		return $response;
	}

	/**
	 * Get a single campaign.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function get_item( $request ) {
		$id       = (int) $request['id'];
		$campaign = new Campaign( $id );

		if ( ! $campaign ) {
			return new WP_Error( 'rest_campaign_not_found', __( 'Campaign not found.', 'campaignbay' ), array( 'status' => 404 ) );
		}

		$data = $this->prepare_item_for_response( $campaign, $request );
		return new WP_REST_Response( $data, 200 );
	}

	/**
	 * Create a single campaign.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function create_item( $request ) {
		$params   = $request->get_json_params();
		$campaign = Campaign::create( $params );

		if ( is_wp_error( $campaign ) ) {
			return $campaign;
		}

		$data     = $this->prepare_item_for_response( $campaign, $request );
		$response = new WP_REST_Response( $data, 201 );
		$response->header( 'Location', rest_url( sprintf( '%s/%s/%d', $this->namespace . $this->version, $this->rest_base, $campaign->get_id() ) ) );

		return $response;
	}
	/**
	 * Update a single campaign.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function update_item( $request ) {
		$id       = (int) $request['id'];
		$campaign = new Campaign( $id );

		if ( ! $campaign ) {
			return new WP_Error( 'rest_campaign_not_found', __( 'Campaign not found.', 'campaignbay' ), array( 'status' => 404 ) );
		}

		$params = $request->get_json_params();
		$result = $campaign->update( $params );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Get the fresh, updated campaign object to return.
		$updated_campaign = new Campaign( $id );
		$data             = $this->prepare_item_for_response( $updated_campaign, $request );

		return new WP_REST_Response( $data, 200 );
	}

	/**
	 * Delete a single campaign.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function delete_item( $request ) {
		$campaign_id = $request->get_param( 'id' );
		$force       = $request->get_param( 'force' );

		$campaign = new Campaign( $campaign_id );
		if ( ! $campaign ) {
			return new WP_Error( 'rest_campaign_not_found', __( 'Campaign not found.', 'campaignbay' ), array( 'status' => 404 ) );
		}

		// $result = Campaign::delete( $campaign_id, $force );
		$result = $campaign->delete( $campaign_id, $force );
		if ( ! $result ) {
			return new WP_Error( 'rest_cannot_delete', __( 'Failed to delete campaign.', 'campaignbay' ), array( 'status' => 500 ) );
		}

		return new WP_REST_Response( null, 204 );
	}

		/**
	 * Bulk update campaigns' statuses.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function update_items( $request ) {
		$params = $request->get_json_params();
		$ids    = isset( $params['ids'] ) ? array_map( 'absint', $params['ids'] ) : array();
		$status = isset( $params['status'] ) ? sanitize_key( $params['status'] ) : '';

		if ( empty( $ids ) ) {
			return new WP_Error( 'rest_invalid_ids', __( 'Campaign IDs are required.', 'campaignbay' ), array( 'status' => 400 ) );
		}
		if ( empty( $status ) ) {
			return new WP_Error( 'rest_invalid_status', __( 'A valid status is required.', 'campaignbay' ), array( 'status' => 400 ) );
		}
		
		$updated_count = 0;
		foreach ( $ids as $id ) {
			$result = wp_update_post(
				array(
					'ID'          => $id,
					'post_status' => $status,
				)
			);
			if ( ! is_wp_error( $result ) ) {
				$updated_count++;
				$post = get_post( $id );
				$title = $post->post_title;
				// Log the activity.
				Logger::get_instance()->log(
					'activity',
					'updated',
					array( 'campaign_id' => $id, 'extra_data' => [
						'title' =>$title,
					] )
				);
			}
		}
		
		// Clear the cache after all updates are done.
		CampaignManager::get_instance()->clear_cache();

		return new WP_REST_Response(
			array(
				'success' => true,
				'updated' => $updated_count,
			),
			200
		);
	}

	/**
	 * Bulk delete campaigns.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function delete_items( $request ) {
		$params = $request->get_json_params();
		$ids    = isset( $params['ids'] ) ? array_map( 'absint', $params['ids'] ) : array();
		
		if ( empty( $ids ) ) {
			return new WP_Error( 'rest_invalid_ids', __( 'Campaign IDs are required.', 'campaignbay' ), array( 'status' => 400 ) );
		}
		
		$deleted_count = 0;
		foreach ( $ids as $id ) {
			// Using the Campaign::delete method ensures our custom hooks are fired.
			$campaign = new Campaign( $id );
			if ( Campaign::delete( $id, true ) ) {
				$deleted_count++;
			}
		}

		// Cache is cleared by the `before_delete_post` hook, so no need to do it here.

		return new WP_REST_Response(
			array(
				'success' => true,
				'deleted' => $deleted_count,
			),
			200
		);
	}

	/**
	 * Prepare a single campaign output for response.
	 *
	 * @param Campaign $campaign Campaign object.
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response object.
	 */
	public function prepare_item_for_response( $campaign, $request ) {
		$data = array(
			'id'     => $campaign->get_id(),
			'title'  => $campaign->get_title(),
			'status' => $campaign->get_status(),
			'modified' => $campaign->get_date_modified(),
		);

		$meta_keys = campaignbay_get_campaign_meta_keys();
		foreach ( $meta_keys as $key ) {
			$data[ $key ] = $campaign->get_meta( $key );
		}


		return $data;
	}

	

	/**
	 * Get the query params for collections.
	 *
	 * @return array
	 */
	public function get_collection_params() {
		$params = parent::get_collection_params();

		$params['per_page']['default'] = 10;
		$params['per_page']['maximum'] = 100;

		return $params;
	}

	/**
	 * Get the campaign schema, conforming to JSON Schema.
	 *
	 * @return array
	 */
	public function get_item_schema() {
		if ( isset( $this->schema ) ) {
			return $this->add_additional_fields_schema( $this->schema );
		}

		$schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'campaign',
			'type'       => 'object',
			'properties' => array(
				'id'                  => array(
					'description' => __( 'Unique identifier for the campaign.', 'campaignbay' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'title'               => array(
					'description' => __( 'The title for the campaign.', 'campaignbay' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
					'required'    => true,
				),
				'status'              => array(
					'description' => __( 'A named status for the campaign.', 'campaignbay' ),
					'type'        => 'string',
					'enum'        => array( 'cb_scheduled', 'campaignbay_inactive', 'cb_scheduled', 'campaignbay_expired' ),
					'context'     => array( 'view', 'edit' ),
				),
				'campaign_type'       => array(
					'description' => __( 'The core type of the campaign.', 'campaignbay' ),
					'type'        => 'string',
					'enum'        => array( 'earlybird', 'scheduled', 'quantity' ),
					'context'     => array( 'view', 'edit' ),
					'required'    => true,
				),
				'discount_type'       => array(
					'description' => __( 'The type of discount (percentage, fixed).', 'campaignbay' ),
					'type'        => 'string',
					'enum'        => array( 'percentage', 'fixed' ),
					'context'     => array( 'view', 'edit' ),
				),
				'discount_value'      => array(
					'description' => __( 'The numeric value of the discount.', 'campaignbay' ),
					'type'        => 'number',
					'context'     => array( 'view', 'edit' ),
				),
				'target_type'         => array(
					'description' => __( 'The scope of the rule (store, categories, products, tags).', 'campaignbay' ),
					'type'        => 'string',
					'enum'        => array( 'entire_store', 'category', 'product', 'tag' ),
					'context'     => array( 'view', 'edit' ),
				),
				'target_ids'          => array(
					'description' => __( 'Array of category, product, or tag IDs.', 'campaignbay' ),
					'type'        => 'array',
					'items'       => array( 'type' => 'integer' ),
					'context'     => array( 'view', 'edit' ),
				),
				'exclude_sale_items'  => array(
					'description' => __( 'Prevent double-discounting.', 'campaignbay' ),
					'type'        => 'boolean',
					'context'     => array( 'view', 'edit' ),
				),
				
				'start_datetime'     => array(
					'description' => __( 'The rule\'s start date/time (ISO 8601 string).', 'campaignbay' ),
					'type'        => 'string',
					'format'      => 'date-time',
					'context'     => array( 'view', 'edit' ),
				),
				'end_datetime'       => array(
					'description' => __( 'The rule\'s end date/time (ISO 8601 string).', 'campaignbay' ),
					'type'        => 'string',
					'format'      => 'date-time',
					'context'     => array( 'view', 'edit' ),
				),
				'timezone_offset'     => array(
					'description' => __( 'The timezone identifier.', 'campaignbay' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
					'required'    => true,
				),
				'campaign_tiers'      => array(
					'description' => __( 'The tiers of the campaign.', 'campaignbay' ),
					'type'        => 'array',
					'items'       => array( 'type' => 'object' ),
					'context'     => array( 'view', 'edit' ),
				),
				'usage_count'         => array(
					'description' => __( 'The number of times the campaign has been used.', 'campaignbay' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'edit' ),
				),
			),
		);

		$this->schema = $schema;

		return $this->add_additional_fields_schema( $this->schema );
	}

	/**
	 * Defines the arguments for the bulk update endpoint.
	 *
	 * @since 1.0.0
	 * @return array
	 */
	private function get_bulk_update_args() {
		return array(
			'ids'    => array(
				'description' => __( 'An array of campaign IDs to update.', 'campaignbay' ),
				'type'        => 'array',
				'items'       => array( 'type' => 'integer' ),
				'required'    => true,
			),
			'status' => array(
				'description' => __( 'The new status to apply to the campaigns.', 'campaignbay' ),
				'type'        => 'string',
				'enum'        => array( 'cb_scheduled', 'campaignbay_inactive' ),
				'required'    => true,
			),
		);
	}

	/**
	 * Defines the arguments for the bulk delete endpoint.
	 *
	 * @since 1.0.0
	 * @return array
	 */
	private function get_bulk_delete_args() {
		return array(
			'ids' => array(
				'description' => __( 'An array of campaign IDs to delete.', 'campaignbay' ),
				'type'        => 'array',
				'items'       => array( 'type' => 'integer' ),
				'required'    => true,
			),
		);
	}

}