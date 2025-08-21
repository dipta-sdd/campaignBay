<?php

namespace WpabCb\Api;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Import WordPress REST API classes
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * The parent class of all api class of this plugin.
 *
 *
 * @since      1.0.0
 *
 * @package    WPAB_CampaignBay
 * @subpackage WPAB_CampaignBayincludes/api
 */

/**
 * The common variables and methods of api of the plugin.
 *
 * Define namespace, vresion and other common properties and methods.
 *
 * @package    WPAB_CampaignBay
 * @subpackage WPAB_CampaignBayincludes/api
 * @author     dipta-sdd <sankarsandipta@gmail.com>
 */

/**
 * ApiController
 *
 * @package    WPAB_CampaignBay
 * @since 1.0.1
 */
class ApiController extends WP_REST_Controller {

	/**
	 * The single instance of the class.
	 *
	 * @since 1.0.0
	 * @var   ApiController
	 * @access private
	 */
	private static $instance = null;
	/**
	 * Rest route namespace.
	 *
	 * @var ApiController
	 */
	public $namespace = CAMPAIGNBAY_TEXT_DOMAIN . '/';

	/**
	 * Rest route version.
	 *
	 * @var ApiController
	 */
	public $version = 'v1';

	/**
	 * Whether the controller supports batching.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	protected $allow_batch = array( 'v1' => true );

	/**
	 * Table name.
	 *
	 * @var string
	 */
	public $type;

	/**
	 * Constructor
	 *
	 * @since    1.0.0
	 */
	public function __construct() {}

	/**
	 * Initialize the class
	 */
	public function run() {
		/*Custom Rest Routes*/
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

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
	 * Throw error on object clone
	 *
	 * The whole idea of the singleton design pattern is that there is a single
	 * object therefore, we don't want the object to be cloned.
	 *
	 * @access public
	 * @return void
	 * @since 1.0.0
	 */
	public function __clone() {
		// Cloning instances of the class is forbidden.
		_doing_it_wrong( __FUNCTION__, esc_html__( 'Cheatin&#8217; huh?', 'campaignbay' ), '1.0.0' );
	}

	/**
	 * Disable unserializing of the class
	 *
	 * @access public
	 * @return void
	 * @since 1.0.0
	 */
	public function __wakeup() {
		// Unserializing instances of the class is forbidden.
		_doing_it_wrong( __FUNCTION__, esc_html__( 'Cheatin&#8217; huh?', 'campaignbay' ), '1.0.0' );
	}
}
 