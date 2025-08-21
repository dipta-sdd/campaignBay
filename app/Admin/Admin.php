<?php

namespace WpabCb\Admin;

use WpabCb\Core\Common;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The admin-specific functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the admin-specific stylesheet and JavaScript.
 *
 * @package    WPAB_CampaignBay
 * @subpackage WPAB_CampaignBayadmin
 * @author     dipta-sdd <sankarsandipta@gmail.com>
 */
class Admin {

	/**
	 * The single instance of the class.
	 *
	 * @since 1.0.0
	 * @var   Admin
	 * @access private
	 */
	private static $instance = null;
	
	/**
	 * Menu info.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      array    $menu_info    Admin menu information.
	 */
	private $menu_info;



	

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
	 * Add Admin Page Menu page.
	 *
	 * @access public
	 *
	 * @since    1.0.0
	 */
	public function add_admin_menu() {
		$white_label     = Common::get_instance()->get_white_label();
		$this->menu_info = array(
			'page_title' => $white_label['plugin_name'],
			'menu_title' => $white_label['menu_label'],
			'menu_slug'  => CAMPAIGNBAY_PLUGIN_NAME,
			'icon_url'   => $white_label['menu_icon'],
			'position'   => $white_label['position'],
			'docs_uri' => $white_label['docs_uri'],
		);

		add_menu_page(
			$this->menu_info['page_title'],
			$this->menu_info['menu_title'],
			'manage_options',
			$this->menu_info['menu_slug'],
			array( $this, 'add_setting_root_div' ),
			$this->menu_info['icon_url'],
			$this->menu_info['position'],
		);
		add_submenu_page(
				$this->menu_info['menu_slug'],
				$this->menu_info['page_title'],
				esc_html__( 'Dashboard', 'campaignbay' ),
				'manage_options',
				CAMPAIGNBAY_TEXT_DOMAIN,
				array( $this, 'add_setting_root_div' )
			);
		$submenu_pages = array();
		$submenu_pages[] = array(
			'menu_title' => 'All Campaigns',
			'menu_slug'  => '#/campaigns',
		);
		$submenu_pages[] = array(
			'menu_title' => 'Add Campaign',
			'menu_slug'  => '#/campaigns/add',
		);
		$submenu_pages[] = array(
			'menu_title' => 'Settings',
			'menu_slug'  => '#/settings',
		);
		$submenu_pages[] = array(
			'menu_title' => 'Help',
			'menu_slug'  => '#/help',
		);
		foreach ( $submenu_pages as $submenu_page ) {
			add_submenu_page(
				$this->menu_info['menu_slug'],
				esc_html( $submenu_page['menu_title'] . '-' . $this->menu_info['page_title'] ),
				$submenu_page['menu_title'],
				'manage_options',
				CAMPAIGNBAY_TEXT_DOMAIN . $submenu_page['menu_slug'],
				array( $this, 'add_setting_root_div' )
			);
		}
	}


	/**
	 * Check if current page is menu page.
	 *
	 * @access public
	 *
	 * @since    1.0.0
	 * @return bool
	 */
	public function is_menu_page() {
		$screen              = get_current_screen();
		$admin_scripts_bases = array( 'toplevel_page_' . CAMPAIGNBAY_PLUGIN_NAME );
		if ( ! ( isset( $screen->base ) && in_array( $screen->base, $admin_scripts_bases, true ) ) ) {
			return false;
		}
		return true;
	}
	// public function is_menu_page() {
	// 	$screen = get_current_screen();
	// 	if ( ! $screen ) {
	// 		return false;
	// 	}
	// 	return strpos( $screen->id, CAMPAIGNBAY_PLUGIN_NAME ) !== false;
	// }

	/**
	 * Add has sticky header class.
	 *
	 * @access public
	 *
	 * @since    1.0.0
	 * @param string $classes The classes.
	 * @return string
	 */
	public function add_has_sticky_header( $classes ) {
		if ( $this->is_menu_page() ) {
			$classes .= ' at-has-hdr-stky ';
		}
		return $classes;
	}

	/**
	 * Add setting root div.
	 *
	 * @access public
	 *
	 * @since    1.0.0
	 */
	public function add_setting_root_div() {
		echo '<div id="' . esc_attr( CAMPAIGNBAY_PLUGIN_NAME ) . '"></div>';
	}

	/**
	 * Enqueue resources.
	 *
	 * @access public
	 *
	 * @since    1.0.0
	 */
	public function enqueue_resources() {
		$this->enqueue_global_admin_styles();
		if ( ! $this->is_menu_page() ) {
			return;
		}
	
		$deps_file = CAMPAIGNBAY_PATH . 'build/admin.asset.php';
		$dependency = array( 'wp-i18n' );
		$version    = CAMPAIGNBAY_VERSION;
	
		if ( file_exists( $deps_file ) ) {
			$deps_file  = require $deps_file;
			$dependency = $deps_file['dependencies'];
			$version    = $deps_file['version'];
		}
	
		wp_enqueue_script( CAMPAIGNBAY_PLUGIN_NAME, CAMPAIGNBAY_URL . 'build/admin.js', $dependency, $version, true );
	
		wp_enqueue_style( CAMPAIGNBAY_PLUGIN_NAME, CAMPAIGNBAY_URL . 'build/admin.css', array( 'wp-components' ), $version );
		wp_style_add_data( CAMPAIGNBAY_PLUGIN_NAME, 'rtl', 'replace' );
		$woocommerce_currency_symbol = get_woocommerce_currency_symbol();
		$localize = apply_filters(
			CAMPAIGNBAY_OPTION_NAME  . '_admin_localize',
			array(
				'version'     => $version,
				'root_id'     => CAMPAIGNBAY_PLUGIN_NAME,
				'nonce'       => wp_create_nonce( 'wp_rest' ),
				'store'       => CAMPAIGNBAY_PLUGIN_NAME,
				'rest_url'    => get_rest_url(),
				'white_label' => Common::get_instance()->get_white_label(),
				'woocommerce_currency_symbol' => $woocommerce_currency_symbol,
				'wpSettings' => array(
					'dateFormat' => get_option( 'date_format' ),
					'timeFormat' => get_option( 'time_format' ),
				),
			)
		);
	
		wp_localize_script( CAMPAIGNBAY_PLUGIN_NAME, 'campaignbay_Localize', $localize );
		
		// --- START OF DEBUGGING BLOCK ---
	
		$path_to_check = CAMPAIGNBAY_PATH . 'languages';
		// campaignbay_log( '--------------------' );
		// campaignbay_log( 'Checking for translations...' );
		// campaignbay_log( 'Script Handle: ' . CAMPAIGNBAY_PLUGIN_NAME );
		// campaignbay_log( 'Text Domain: ' . 'campaignbay' );
		// campaignbay_log( 'Full Path Being Checked: ' . $path_to_check );
		// campaignbay_log( 'Does path exist? ' . ( file_exists( $path_to_check ) ? 'Yes' : 'No' ) );
		// wasted 3 hours on this because of poor documentation, there was no issue here
		// // --- END OF DEBUGGING BLOCK ---
	
		$result = wp_set_script_translations(
			CAMPAIGNBAY_PLUGIN_NAME,
			'campaignbay',
			$path_to_check
		);
		
		// Log the result of the function call
		// campaignbay_log( 'Result of wp_set_script_translations: ' . ( $result ? 'True (Success)' : 'False (Failure)' ) );

	}

	/**
	 * Enqueues CSS that should be present on all admin pages.
	 *
	 * @since 1.0.0
	 * @access private
	 */
	private function enqueue_global_admin_styles() {
		wp_enqueue_style(
			'wpab-cb-admin-menu', // A new, unique handle
			CAMPAIGNBAY_URL . 'assets/css/admin-menu.css',
			array(),
			CAMPAIGNBAY_VERSION
		);
	}


	/**
	 * Get settings schema.
	 *
	 * @access public
	 *
	 * @since    1.0.0
	 * @return array settings schema for this plugin.
	 */
	public function get_settings_schema() {
		$setting_properties = apply_filters(
			CAMPAIGNBAY_OPTION_NAME  . '_options_properties',
			array(
				/*==================================================
				* Global Settings Tab
				==================================================*/
				'global_enableAddon'     => array(
					'type'    => 'boolean',
					'default' => true,
				),
				'global_defaultPriority' => array(
					'type'    => 'integer',
					'default' => 10,
				),
				'global_calculationMode' => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
					'default'           => 'after_tax',
				),
				'global_calculationMode' => array(
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_key',
                'default'           => 'after_tax',
				),
				'global_decimalPlaces'   => array(
					'type'    => 'integer',
					'default' => 2,
				),

				/*==================================================
				* Performance & Caching (from Global Tab)
				==================================================*/
				'perf_enableCaching'     => array(
					'type'    => 'boolean',
					'default' => true,
				),

				/*==================================================
				* Debugging & Logging (from Global Tab)
				==================================================*/
				'debug_enableMode'       => array(
					'type'    => 'boolean',
					'default' => false,
				),
				'debug_logLevel'         => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
					'default'           => 'errors_only',
				),

				/*==================================================
				* Product Settings Tab
				==================================================*/
				'product_showDiscountedPrice' => array(
					'type'    => 'boolean',
					'default' => true,
				),
				'product_messageFormat'       => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'default'           => 'You save {percentage_off}!',
				),
				'product_bogoMessageFormat'   => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'default'           => '{campaign_name_strong} : Buy {buy_product_quantity} of this and get {get_product_quantity} {get_product} for free!',
				),
				'product_enableQuantityTable' => array(
					'type'    => 'boolean',
					'default' => true,
				),
				'product_excludeSaleItems'    => array(
					'type'    => 'boolean',
					'default' => true,
				),
				'product_priorityMethod'      => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
					'default'           => 'apply_highest',
				),

				/*==================================================
				* Cart Settings Tab
				==================================================*/
				'cart_allowWcCouponStacking'  => array(
					'type'    => 'boolean',
					'default' => false,
				),
				'cart_allowCampaignStacking'  => array(
					'type'    => 'boolean',
					'default' => false,
				),
				'cart_savedMessageFormat'     => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'default'           => 'You saved {saved_amount} on this order!',
				),
				'cart_showNextDiscountBar'    => array(
					'type'    => 'boolean',
					'default' => true,
				),
				'cart_nextDiscountFormat'     => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'default'           => 'Spend {remaining_amount} more for {discount_percentage} off!',
				),
				'cart_showDiscountBreakdown'  => array(
					'type'    => 'boolean',
					'default' => true,
				),

				/*==================================================
				* Promotion Settings Tab
				==================================================*/
				'promo_enableBar'             => array(
					'type'    => 'boolean',
					'default' => false,
				),
				'promo_barPosition'           => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
					'default'           => 'top_of_page',
				),
				'promo_barBgColor'            => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_hex_color',
					'default'           => '#000000',
				),
				'promo_barTextColor'          => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_hex_color',
					'default'           => '#FFFFFF',
				),
				'promo_barContent'            => array(
					'type'    => 'string',
					'default' => 'FLASH SALE! {percentage_off} on all shirts!',
					// Note: Use a broader sanitize callback like wp_kses_post in the actual save hook if HTML is allowed.
				),
				'promo_barLinkUrl'            => array(
					'type'              => 'string',
					'sanitize_callback' => 'esc_url_raw',
					'default'           => '',
				),
				'promo_barDisplayPages'       => array(
					'type'  => 'array',
					'items' => array(
						'type' => 'string',
					),
					'default' => ['shop_page', 'product_pages'],
				),
				'promo_enableCustomBadges'    => array(
					'type'    => 'boolean',
					'default' => true,
				),

				/*==================================================
				* Advance Settings Tab
				==================================================*/
				'advanced_deleteAllOnUninstall' => array(
					'type'    => 'boolean',
					'default' => false,
				),
				'advanced_customCss'            => array(
					'type'    => 'string',
					'default' => '',
					// Note: Requires special sanitization for CSS (e.g., wp_strip_all_tags)
				),
				'advanced_customJs'             => array(
					'type'    => 'string',
					'default' => '',
					// Note: Requires careful sanitization.
				),
			),
		);

		return array(
			'type'       => 'object',
			'properties' => $setting_properties,
		);
	}


	/**
	 * Register settings.
	 * Common callback function of rest_api_init and admin_init
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_settings() {
		$defaults = campaignbay_default_options();

		register_setting(
			CAMPAIGNBAY_OPTION_NAME  . '_settings_group',
			CAMPAIGNBAY_OPTION_NAME,
			array(
				'type'         => 'object',
				'default'      => $defaults,
				'show_in_rest' => array(
					'schema' => $this->get_settings_schema(),
				),
			)
		);
	}

	/**
	 * Add plugin menu items.
	 *
	 * @access public
	 *
	 * @since 1.0.0
	 * @param string[] $actions     An array of plugin action links. By default this can include
	 *                              'activate', 'deactivate', and 'delete'. With Multisite active
	 *                              this can also include 'network_active' and 'network_only' items.
	 * @param string   $plugin_file Path to the plugin file relative to the plugins directory.
	 * @param array    $plugin_data An array of plugin data. See get_plugin_data()
	 *                              and the {@see 'plugin_row_meta'} filter for the list
	 *                              of possible values.
	 * @param string   $context     The plugin context. By default this can include 'all',
	 *                              'active', 'inactive', 'recently_activated', 'upgrade',
	 *                              'mustuse', 'dropins', and 'search'.
	 * @return array settings schema for this plugin.
	 */
	public function add_plugin_links( $actions, $plugin_file, $plugin_data, $context ) {
		$actions[] = '<a href="' . esc_url( menu_page_url( $this->menu_info['menu_slug'], false ) ) . '">' . esc_html__( 'Settings', 'campaignbay' ) . '</a>';
		$actions[] = '<a href="' . esc_url( $this->menu_info['docs_uri'] ) . '" target="_blank">' . esc_html__( 'Documentation', 'campaignbay' ) . '</a>';
		return $actions;
	}
}
