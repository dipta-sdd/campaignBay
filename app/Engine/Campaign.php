<?php

namespace WpabCb\Engine;

/**
 * The file that defines the Campaign model class.
 *
 * A class definition that encapsulates all data and functionality for a single discount campaign.
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

use WP_Query;
use WP_Post;
use Exception;
use DateTime;
use DateTimeZone;
use WpabCb\Core\Logger;

/**
 * The Campaign model class.
 *
 * This class represents a single discount campaign and provides methods
 * to create, read, update, and delete campaign data.
 *
 * @since      1.0.0
 * @package    WPAB_CampaignBay
 * @author     WP Anchor Bay <wpanchorbay@gmail.com>
 */
class Campaign {

	/**
	 * The campaign Post ID.
	 *
	 * @since 1.0.0
	 * @access public
	 * @var int
	 */
	public $id = 0;

	/**
	 * The WP_Post object for the campaign.
	 *
	 * @since 1.0.0
	 * @access private
	 * @var WP_Post|null
	 */
	private $post;

	/**
	 * The campaign's metadata.
	 *
	 * @since 1.0.0
	 * @access private
	 * @var array
	 */
	private $meta = array();

	/**
	 * A flat list of all product and variation IDs this campaign applies to.
	 *
	 * @since 1.0.0
	 * @access private
	 * @var int[]
	 */
	private $applicable_product_ids = array();

	/**
	 * The number of times this campaign has been successfully used.
	 *
	 * @since 1.0.0
	 * @access private
	 * @var int|null
	 */
	private $usage_count = null;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 * @param int|WP_Post $campaign The campaign ID or WP_Post object.
	 */
	public function __construct( $campaign ) {
		if ( $campaign instanceof WP_Post ) {
			$this->id   = $campaign->ID;
			$this->post = $campaign;
		} elseif ( is_numeric( $campaign ) ) {
			$this->id   = absint( $campaign );
			$this->post = get_post( $this->id );
		}

		if ( ! $this->post || 'campaignbay_campaign' !== $this->post->post_type ) {
			throw new Exception( 'Invalid campaign provided.' );
		}

		$this->load_meta();
		$this->load_applicable_product_ids(); // Pre-calculate the list of products this campaign applies to.
		// $this->load_usage_count(); // Load the usage count.
	}

	/**
	 * Gets the current usage count for the campaign.
	 *
	 * This method uses a "lazy loading" pattern. The database is only queried
	 * the first time this method is called for the object instance. Subsequent
	 * calls will return the cached value from the object's property.
	 *
	 * @since 1.0.0
	 * @return int The number of times the campaign has been used on successful orders.
	 */
	public function get_usage_count() {
		
		// Check if the usage count has already been loaded for this object.
		if ( null === $this->usage_count ) {
			// If not, load it now.
			$this->load_usage_count();
		}
		return $this->usage_count;
	}

	/**
	 * Loads the usage count from the logs table and caches it in the object's property.
	 * This method is now only called when needed.
	 *
	 * @since 1.0.0
	 * @access private
	 */
	public function load_usage_count() {
		campaignbay_log('load_usage_count for campaign: ' . $this->get_id(), 'DEBUG' );
		global $wpdb;
		$table_name = $wpdb->prefix . 'campaignbay_logs';

		// Perform a direct, indexed query to count distinct successful orders for this campaign.
		$sql = "SELECT COUNT(DISTINCT order_id)
				 FROM {$table_name}
				 WHERE campaign_id = %d
				 AND log_type = 'sale'
				 AND order_status IN ('processing', 'completed')";
		
		//phpcs:ignore 
		$count = $wpdb->get_var(
			//phpcs:ignore
			$wpdb->prepare(
				//phpcs:ignore
				$sql,
				$this->id
			)
		);
		campaignbay_log( 'Usage count loaded for campaign: #' . $this->get_id() . ' ' . $this->get_title() . ' - ' . $count, 'DEBUG' );
		$this->usage_count = (int) $count;
		$this->update_meta( 'usage_count', $this->usage_count );
		campaignbay_log( 'Usage count loaded for campaign: #' . $this->get_id() . ' ' . $this->get_title() . ' - ' . $this->usage_count, 'DEBUG' );
	}

	/**
	 * Populates the applicable_product_ids property based on the campaign's targeting rules.
	 * This is the core logic for pre-calculating which products are affected.
	 *
	 * @since 1.0.0
	 * @access private
	 */
	private function load_applicable_product_ids() {
		
		$target_type = $this->get_meta( 'target_type' );
		$target_ids  = $this->get_meta( 'target_ids' );

		if ( 'entire_store' === $target_type ) {
			// For store-wide, the list is empty. The pricing engine will interpret this as "applies to all".
			$this->applicable_product_ids = array();
			return;
		}

		if ( empty( $target_ids ) || ! is_array( $target_ids ) ) {
			$this->applicable_product_ids = array();
			return;
		}

		$product_ids = array();

		if ( 'category' === $target_type ) {
			$product_ids = $this->get_products_from_categories( $target_ids );
		} elseif ( 'product' === $target_type ) {
			$product_ids = $target_ids;
		}
		// After getting the initial list, expand any variable products to include their variations.
		$this->applicable_product_ids = $this->expand_variable_products( $product_ids );
	}


	/**
	 * Gets all product IDs from the specified category IDs.
	 *
	 * @since 1.0.0
	 * @access private
	 * @param array $category_ids Array of category IDs.
	 * @return array Array of product IDs.
	 */
	private function get_products_from_categories( $category_ids ) {
		$category_ids = array_map( 'absint', $category_ids );
		$args = array(
			'product_category_id' => $category_ids,
			'limit'    => -1, // Get all matching products.
			'return'   => 'ids', // Performance: only return the IDs.
		);
		$products = wc_get_products( $args );
		if ( is_wp_error( $products ) ) {
			return array();
		}
		return $products;
	}

	/**
	 * Expands variable products to include their variations.
	 *
	 * @since 1.0.0
	 * @access private
	 * @param array $product_ids Array of product IDs.
	 * @return array Array of product and variation IDs.
	 */
	private function expand_variable_products( $product_ids ) {
		$expanded_ids = array();
		if ( empty( $product_ids ) ) {
			return $expanded_ids;
		}
		foreach ( $product_ids as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				continue;
			}
			// Add the product itself.
			$expanded_ids[] = $product_id;

			// Add the product variations.
			if ( $product->is_type( 'variable' ) ) {
				$variation_ids = $product->get_children();
					if ( ! empty( $variation_ids ) ) {
						$expanded_ids = array_merge( $expanded_ids, $variation_ids );
				}
			}
		}
		// Ensure final list only has unique IDs.
		return array_unique( array_map( 'absint', $expanded_ids ) );
	}

	/**
	 * Gets the list of product IDs this campaign applies to.
	 *
	 * @since 1.0.0
	 * @return array Array of product IDs.
	 */
	public function get_applicable_product_ids() {
		return $this->applicable_product_ids;
	}

	/**
	 * Checks if this campaign applies to a specific product.
	 *
	 * @since 1.0.0
	 * @param int|WC_Product $product The product ID or WC_Product object.
	 * @return bool True if the campaign applies to the product, false otherwise.
	 */
	public function is_applicable_to_product( $product ) {
		
		$product_id = is_object( $product ) ? $product->get_id() : absint( $product );
		if ( !is_numeric( $product_id ) ) {
			return false;
		}
		if ( 'entire_store' === $this->get_meta( 'target_type' ) ) {
			return true;
		}
		if ( 'tag' === $this->get_meta( 'target_type' ) ) {
			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				return false;
			}
			$product_tags = $product->get_tag_ids();	
			foreach ( $product_tags as $product_tag ) {
				if ( in_array( $product_tag, $this->get_meta( 'target_ids' ), true ) ) {
					return true;
				}
			}
			return false;
		}

		return in_array( $product_id, $this->applicable_product_ids, true );
	}

	/**
	 * Throws a validation error with a specific field message.
	 *
	 * @since 1.0.0
	 * @param string $field The field name that failed validation.
	 * @throws Exception Always throws an exception.
	 */
	public static function throw_validation_error( $field = '' ) {
		$message = 'Campaign validation failed.';
		if ( ! empty( $field ) ) {
			$message .= " Field: {$field}";
		}
		throw new Exception( esc_html( $message ) );
	}

	/**
	 * Creates a new campaign.
	 *
	 * @since 1.0.0
	 * @param array $args The campaign arguments.
	 * @return Campaign The created campaign object.
	 * @throws Exception If validation fails.
	 */
	public static function create( $args ) {
		
		// Validate required fields.
		if ( empty( $args['title'] ) ) {
			self::throw_validation_error( 'title' );
		}

		if ( empty( $args['campaign_type'] ) ) {
			self::throw_validation_error( 'campaign_type' );
		}
		// If status is not provided, set it to scheduled if the campaign type is scheduled.
		if ( empty( $args['status'] ) && 'scheduled' !== $args['campaign_type'] ) {
			self::throw_validation_error( 'status' );
		} elseif ( empty( $args['status'] ) && 'scheduled' === $args['campaign_type'] ) {
			$args['status'] = 'cb_scheduled';
		}

		$allowed_statuses = array( 'cb_active', 'cb_inactive', 'cb_scheduled' );
		if ( ! in_array( $args['status'], $allowed_statuses, true ) ) {
			self::throw_validation_error( 'status' );
		}

		$allowed_types = array( 'earlybird', 'scheduled', 'quantity' );
		if ( ! in_array( $args['campaign_type'], $allowed_types, true ) ) {
			self::throw_validation_error( 'campaign_type' );
		}

		if( 'cb_scheduled' === $args['status'] && empty( $args['start_datetime'] ) ){
			self::throw_validation_error( 'start_datetime' );
		}
		if( 'cb_scheduled' === $args['status'] && empty( $args['end_datetime'] ) ){
			self::throw_validation_error( 'end_datetime' );
		}

		if( 'scheduled' === $args['campaign_type'] && empty( $args['discount_value'] ) ){
			self::throw_validation_error( 'discount_value' );
		}
		if( 'scheduled' === $args['campaign_type'] && empty( $args['discount_type'] ) ){
			self::throw_validation_error( 'discount_type' );
		}
		if( 'scheduled' !== $args['campaign_type'] ){
			if( !isset( $args['target_type'] ) || empty( $args['target_type'] ) ){
				self::throw_validation_error( 'target_type' );
			}
			$allowed_target_types = array( 'entire_store', 'category', 'product', 'tag' );
			if( ! in_array( $args['target_type'], $allowed_target_types, true ) ){
				self::throw_validation_error( 'target_type' );
			}
			if( 'entire_store' !== $args['target_type'] && ( empty( $args['target_ids'] ) || !is_array( $args['target_ids'] ) ) ){
				self::throw_validation_error( 'target_ids' );
			}
		}
		

		// Create the post.
		$post_data = array(
			'post_title'  => sanitize_text_field( $args['title'] ),
			'post_type'   => 'campaignbay_campaign',
			'post_status' => sanitize_key( $args['status'] ),
		);

		$post_id = wp_insert_post( $post_data , true );

		if ( is_wp_error( $post_id ) ) {
			throw new Exception( 'Failed to create campaign post.' );
		}

		// Create the campaign object and update it with the provided arguments.
		$campaign = new self( $post_id );
		$campaign->update_meta_from_args( $args );
		/**
		 * Fires after a new campaign is created and all its meta is saved.
		 *
		 * @param int      $campaign_id The ID of the new campaign.
		 * @param Campaign $campaign    The campaign object.
		 */
		do_action( 'campaignbay_campaign_save', $campaign->id, $campaign );
		// Log the activity.
		Logger::get_instance()->log(
			'activity',
			'created',
			array( 'campaign_id' => $campaign->get_id(), 'extra_data' => [
				'title' => $campaign->get_title(),
			] )
		);
		return $campaign;
	}

	/**
	 * Updates the campaign with new data.
	 *
	 * @since 1.0.0
	 * @param array $args The campaign arguments to update.
	 * @return bool True on success, false on failure.
	 */
	public function update( $args ) {
		// --- Update Post Data (if provided) ---
		$post_data = array();
		if ( isset( $args['title'] ) ) {
			$post_data['post_title'] = sanitize_text_field( $args['title'] );
		}
		if ( isset( $args['status'] ) ) {
			$post_data['post_status'] = sanitize_key( $args['status'] );
		}

		if ( ! empty( $post_data ) ) {
			$post_data['ID'] = $this->id;
			$result          = wp_update_post( $post_data, true );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
		if( $args['status'] !== $this->get_status() ){
			$this->update_meta( 'status', $args['status'] );
		}
		$this->update_meta_from_args( $args );

		/**
		 * Fires after a campaign is updated and all its meta is saved.
		 *
		 * @param int      $campaign_id The ID of the updated campaign.
		 * @param Campaign $campaign    The campaign object.
		 */
		do_action( 'campaignbay_campaign_save', $this->id, $this );	
		// Log the activity.
		Logger::get_instance()->log(
			'activity',
			'updated',
			array( 'campaign_id' => $this->get_id(), 'extra_data' => [
				'title' => $this->get_title(),
			] )
		);
		return true;
	}

	/**
	 * Deletes a campaign.
	 *
	 * @since 1.0.0
	 * @param int  $campaign_id The campaign ID to delete.
	 * @param bool $force_delete Whether to force delete or move to trash.
	 * @return bool True on success, false on failure.
	 */
	public static function delete( $campaign_id, $force_delete = true ) {
		$campaign = new self( $campaign_id );
		$title = $campaign->get_title();
		$result = wp_delete_post( $campaign_id, $force_delete );
		/**
		 * Fires after a campaign is deleted.
		 *
		 * @param int      $campaign_id The ID of the deleted campaign.
		 */
		do_action( 'campaignbay_campaign_delete', $campaign_id );
		// Log the activity.
		Logger::get_instance()->log(
			'activity',
			'deleted',
			array( 'campaign_id' => $campaign_id, 'extra_data' => [
				'title' => $title,
			] )
		);
		return ! is_wp_error( $result ) && $result !== false;
	}

	/**
	 * Loads all metadata for this campaign.
	 *
	 * @since 1.0.0
	 * @access private
	 */
	private function load_meta() {
		$meta_keys = campaignbay_get_campaign_meta_keys();

		foreach ( $meta_keys as $key ) {
			$value = get_post_meta( $this->id, '_campaignbay_' . $key, true );
			$this->meta[ $key ] = $value;
		}
	}

	/**
	 * Updates metadata from the provided arguments.
	 *
	 * @since 1.0.0
	 * @access private
	 * @param array $args The arguments containing metadata.
	 */
	private function update_meta_from_args( $args ) {
		$meta_keys = campaignbay_get_campaign_meta_keys();

		foreach ( $meta_keys as $key ) {
			if ( isset( $args[ $key ] ) ) {
				$sanitized_value = $this->sanitize_meta_value( $key, $args[ $key ] );
				$this->update_meta( $key, $sanitized_value );
			}
		}
	}

	/**
	 * Sanitizes a metadata value based on the key.
	 *
	 * @since 1.0.0
	 * @access private
	 * @param string $key The metadata key.
	 * @param mixed  $value The value to sanitize.
	 * @return mixed The sanitized value.
	 */
	private function sanitize_meta_value( $key, $value ) {
		switch ( $key ) {
			case 'discount_value':
				return floatval( $value );

			case 'min_quantity':
			case 'priority':
			case 'usage_count':
				return absint( $value );
			
			case 'start_datetime':
			case 'end_datetime':
				return sanitize_text_field( $value );

			case 'exclude_sale_items':
			case 'apply_to_shipping':
			case 'schedule_enabled':
				return (bool) $value;

			case 'target_ids':
				return is_array( $value ) ? array_map( 'absint', $value ) : array();

			case 'campaign_type':
			case 'rule_status':
			case 'discount_type':
			case 'target_type':
			case 'timezone_offset':
				return sanitize_key( $value );
			case 'campaign_tiers':
				return is_array( $value ) ? array_map( array( $this, 'sanitize_tier_item' ), $value ) : array();

			default:
				return sanitize_text_field( $value );
		}
	}

	/**
	 * Sanitizes a single tier item from the campaign_tiers array.
	 *
	 * @since 1.0.0
	 * @access private
	 * @param array $tier The tier array to sanitize.
	 * @return array The sanitized tier array.
	 */
	private function sanitize_tier_item( $tier ) {
		if ( ! is_array( $tier ) ) {
			return array();
		}

		$sanitized_tier = array();
		// if its a earlybird campaign, we need to sanitize the tier item.
		if( isset( $tier['quantity'] ) ){
			$sanitized_tier['id']    = isset( $tier['id'] ) ? absint( $tier['id'] ) : 0;
			$sanitized_tier['quantity']  = isset( $tier['quantity'] ) ? absint( $tier['quantity'] ) : 0;
			$sanitized_tier['value'] = isset( $tier['value'] ) ? floatval( $tier['value'] ) : 0;
			$sanitized_tier['type']  = isset( $tier['type'] ) ? sanitize_key( $tier['type'] ) : 'percentage';
			$sanitized_tier['total'] = isset( $tier['total'] ) ? floatval( $tier['total'] ) : 0;
			return $sanitized_tier;
		}
		// if its a quantity campaign, we need to sanitize the tier item.
		$sanitized_tier['id']    = isset( $tier['id'] ) ? absint( $tier['id'] ) : 0;
		$sanitized_tier['min']   = isset( $tier['min'] ) ? absint( $tier['min'] ) : 0;
		$sanitized_tier['max']   = isset( $tier['max'] ) ? sanitize_text_field( $tier['max'] ) : ''; // Max can be empty or a number.
		$sanitized_tier['value'] = isset( $tier['value'] ) ? floatval( $tier['value'] ) : 0;
		$sanitized_tier['type']  = isset( $tier['type'] ) ? sanitize_key( $tier['type'] ) : 'percentage';

		return $sanitized_tier;
	}


	/**
	 * Get a specific piece of metadata.
	 *
	 * @since 1.0.0
	 * @param string $key The meta key (without prefix).
	 * @return mixed The value of the meta key.
	 */
	public function get_meta( $key ) {
		return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : null;
	}

	/**
	 * Update a single piece of metadata in the database and in the object.
	 *
	 * @since 1.0.0
	 * @param string $key The meta key (without prefix).
	 * @param mixed  $value The value to save.
	 */
	public function update_meta( $key, $value ) {
		$this->meta[ $key ] = $value;
		update_post_meta( $this->id, '_campaignbay_' . $key, $value );
	}

	/**
	 * Getters for core properties.
	 */
	public function get_id() {
		return $this->id;
	}

	public function get_title() {
		return $this->post->post_title;
	}

	public function get_status() {
		return $this->post->post_status;
	}

	/**
	 * Gets the start datetime string and converts it to the UTC timezone.
	 *
	 * @since 1.0.0
	 * @return string|null The start datetime in 'Y-m-d H:i:s' format (UTC), or null if not set.
	 */
	public function get_start_datetime_utc() {
		$start_datetime_site = $this->get_meta( 'start_datetime' );

		if ( empty( $start_datetime_site ) ) {
			return null;
		}

		try {
			// Create a DateTime object from the saved string, specifying the site's timezone.
			$date = new DateTime( $start_datetime_site, new DateTimeZone( wp_timezone_string() ) );
			// Change the timezone of the object to UTC.
			$date->setTimezone( new DateTimeZone( 'UTC' ) );
			// Return the formatted string.
			return $date->format( 'Y-m-d H:i:s' );
		} catch ( Exception $e ) {
			// Catch potential errors from invalid date formats.
			campaignbay_log( 'Invalid start_datetime format for campaign #' . $this->id, 'ERROR' );
			return null;
		}
	}

	/**
	 * Gets the end datetime string and converts it to the UTC timezone.
	 *
	 * @since 1.0.0
	 * @return string|null The end datetime in 'Y-m-d H:i:s' format (UTC), or null if not set.
	 */
	public function get_end_datetime_utc() {
		$end_datetime_site = $this->get_meta( 'end_datetime' );

		if ( empty( $end_datetime_site ) ) {
			return null;
		}

		try {
			$date = new DateTime( $end_datetime_site, new DateTimeZone( wp_timezone_string() ) );
			$date->setTimezone( new DateTimeZone( 'UTC' ) );
			return $date->format( 'Y-m-d H:i:s' );
		} catch ( Exception $e ) {
			campaignbay_log( 'Invalid end_datetime format for campaign #' . $this->id, 'ERROR' );
			return null;
		}
	}

	/**
	 * Gets the start datetime as a UTC Unix timestamp.
	 *
	 * @since 1.0.0
	 * @return int|null The Unix timestamp, or null if no start date is set.
	 */
	public function get_start_timestamp() {
		$utc_datetime = $this->get_start_datetime_utc();
		return $utc_datetime ? strtotime( $utc_datetime ) : null;
	}

	/**
	 * Gets the end datetime as a UTC Unix timestamp.
	 *
	 * @since 1.0.0
	 * @return int|null The Unix timestamp, or null if no end date is set.
	 */
	public function get_end_timestamp() {
		$utc_datetime = $this->get_end_datetime_utc();
		return $utc_datetime ? strtotime( $utc_datetime ) : null;
	}

	/**
	 * Gets the last modified date of the campaign.
	 *
	 * @since 1.0.0
	 * @return \DateTime|null A DateTime object representing the last modified date in the site's timezone.
	 */
	public function get_date_modified() {
		return $this->post->post_modified ?: null;
	}
}

