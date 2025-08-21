<?php

namespace WpabCb\Engine;

use WC_Product;
use \WpabCb\Engine\CampaignManager;
use WP_Error;

/**
 * The file that defines the Pricing Engine class.
 *
 * A class definition that handles all pricing interactions with WooCommerce.
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
 * The Pricing Engine class.
 *
 * This class is responsible for applying discount logic by hooking into WooCommerce
 * pricing filters and actions. It is the "engine" that drives the customer-facing changes.
 *
 * @since      1.0.0
 * @package    WPAB_CampaignBay
 * @author     WP Anchor Bay <wpanchorbay@gmail.com>
 */
class PricingEngine {

	/**
	 * The single instance of the class.
	 *
	 * @since 1.0.0
	 * @var   CAMPAIGNBAY_Pricing_Engine
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
	 * The request-level cache for calculated product discounts.
	 *
	 * @since 1.0.0
	 * @access private
	 * @var array
	 */
	private $product_discount_cache = array();

	

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
		if(campaignbay_get_options('global_enableAddon')){
			$this->define_hooks();
		}
	}

	/**
	 * Defines all hooks this class needs to run.
	 *
	 * @since 1.0.0
	 * @access private
	 */
	private function define_hooks() {

		$default_priority = campaignbay_get_options('global_defaultPriority') ?? 10;

		$enabled_plugin = campaignbay_get_options('global_enableAddon');
		if(!$enabled_plugin){
			return;
		}
		/**
		 * load the product summary hook
		 * priority 9 is the last hook before the price html hook
		 * cannot use $default_priority
		 */

		$this->add_action( 'woocommerce_single_product_summary', 'action_single_product_summary', 9, 0 );
		// load the price html hook
		$this->add_filter( 'woocommerce_get_price_html', 'display_discounted_price_html', $default_priority, 2);
		// load the price html hook
		$this->add_filter( 'woocommerce_variable_price_html', 'display_variable_price_html', $default_priority, 3);

		$this->add_filter( 'woocommerce_variation_prices', 'filter_variation_prices', $default_priority, 2);

		// load the product is on sale hook
		$this->add_filter( 'woocommerce_product_is_on_sale', 'filter_is_product_on_sale', $default_priority, 2);

		// load the before calculate totals hook
		$this->add_action( 'woocommerce_before_calculate_totals', 'apply_discounts_and_prepare_notices', $default_priority, 1 );

		$this->add_action( 'woocommerce_after_calculate_totals', 'cart_after_calculate_totals', $default_priority, 1 );

		

		// Conditionally add the hook for inline "add more" notices in the cart, based on settings.
		if( campaignbay_get_options('cart_showNextDiscountBar') ){
			$this->add_filter( 'woocommerce_after_cart_item_name', 'display_inline_cart_notice', $default_priority, 2 );
		}

		// Cart item hooks for formatting the price and subtotal columns.
		$this->add_filter( 'woocommerce_cart_item_price', 'display_cart_item_price', $default_priority, 3);
		$this->add_filter( 'woocommerce_cart_item_subtotal', 'display_cart_item_subtotal', $default_priority, 3);


		// product variation single variation hooks
		$this->add_filter( 'woocommerce_variation_prices_price', 'filter_variation_prices_price', $default_priority, 3);
		$this->add_filter( 'woocommerce_variation_prices_regular_price', 'filter_variation_prices_regular_price', $default_priority, 3);
		$this->add_filter( 'woocommerce_variation_prices_sale_price', 'filter_variation_prices_sale_price', $default_priority, 3);

		// save the discount breakdown to the order meta
		$this->add_action( 'woocommerce_checkout_create_order', 'save_discount_breakdown_to_order_meta', $default_priority, 2 );
		$this->add_action( 'woocommerce_store_api_checkout_update_order_meta', 'save_discount_breakdown_to_order_meta', $default_priority, 1 );

		// prevent coupon stacking
		if( ! campaignbay_get_options('cart_allowWcCouponStacking') ){
			$this->add_action( 'woocommerce_coupon_is_valid_for_product', 'prevent_coupon_stacking_to_product', $default_priority, 4 );
		}

	}

	/**
	 * Prevent a coupon from being applied if an automatic campaign is active.
	 *
	 * @since 1.0.0
	 * @hook woocommerce_coupon_is_valid_for_product
	 * @param bool $is_valid The original validity of the coupon.
	 * @param WC_Product $product The product object.
	 * @param WC_Coupon $coupon The coupon object.
	 * @param array $values The values of the cart item.
	 * @return bool The modified validity of the coupon.
	 */
	public function prevent_coupon_stacking_to_product( $is_valid, $product, $coupon, $values ){
		$discount_data = $this->get_or_calculate_product_discount( $product );
		if( $discount_data['on_campaign'] ){
			return false;
		}
		return $is_valid;
	}

	/**
	 * Save the discount breakdown to the order meta.
	 *
	 * @since 1.0.0
	 * @hook woocommerce_checkout_create_order
	 * @hook woocommerce_store_api_checkout_update_order_meta
	 * @param WC_Order $order The order object.
	 * @param array $data The order data.
	 */
	public function save_discount_breakdown_to_order_meta( $order, $data= null ) {
		// The cart object is available globally via WC()->cart at this point.
		$cart = WC()->cart;

		if ( $cart && ! empty( $cart->campaignbay_discount_breakdown ) ) {
			// Get the breakdown array we created in the previous hook.
			$breakdown = $cart->campaignbay_discount_breakdown;

			// Save the entire breakdown array to a single meta key on the order.
			$order->update_meta_data( '_campaignbay_discount_breakdown', $breakdown );
			campaignbay_log( sprintf( 'Saved discount breakdown to order #%d (Classic Checkout).', $order->get_id() ), 'INFO' );
		}
	}

	/**
	 * Filter the variation prices price.
	 * This is used to apply the simple price to the variation prices.
	 *
	 * @since 1.0.0
	 * @hook woocommerce_variation_prices_price
	 * @param float $price The original price.
	 * @param WC_Product_Variation $variation The variation object.
	 * @param WC_Product_Variable $product The product object.
	 * @return float The modified price.
	 */
	public function filter_variation_prices_price( $price, $variation, $product ){
		$discount_data = $this->get_or_calculate_product_discount( $variation );
		if( $discount_data['on_campaign'] && isset( $discount_data['discounts']['best_price'] ) ){
			$price = $discount_data['discounts']['best_price'] < $price ? $discount_data['discounts']['best_price'] : $price;
		}
		return $price;
	}

	/**
	 * Filter the variation prices regular price.
	 * This is used to apply the simple regular price to the variation prices.
	 *
	 * @since 1.0.0
	 * @hook woocommerce_variation_prices_regular_price
	 * @param float $price The original price.
	 * @param WC_Product_Variation $variation The variation object.
	 * @param WC_Product_Variable $product The product object.
	 * @return float The modified price.
	 */	
	public function filter_variation_prices_regular_price( $price, $variation, $product ){
		$discount_data = $this->get_or_calculate_product_discount( $variation );
		if( $discount_data['on_campaign'] && isset( $discount_data['discounts']['simple']['regular_price'] ) ){
			return $discount_data['discounts']['simple']['regular_price'] < $price ? $discount_data['discounts']['simple']['regular_price'] : $price;
		}
		return $price;
	}
	
	/**
	 * Filter the variation prices sale price.
	 * This is used to apply the simple price to the variation prices.
	 *
	 * @since 1.0.0
	 * @hook woocommerce_variation_prices_sale_price
	 * @param float $price The original price.
	 * @param WC_Product_Variation $variation The variation object.
	 * @param WC_Product_Variable $product The product object.
	 * @return float The modified price.
	 */
	public function filter_variation_prices_sale_price( $price, $variation, $product ){
		$discount_data = $this->get_or_calculate_product_discount( $variation );
		if( $discount_data['on_campaign'] && isset( $discount_data['discounts']['best_price'] ) ){
			return $discount_data['discounts']['best_price'] < $price ? $discount_data['discounts']['best_price'] : $price;
		}
		return $price;
	}

	/**
	 * Filter the variable price html.
	 * This is used to apply the simple price to the variable price html.
	 *
	 * @since 1.0.0
	 * @hook woocommerce_variable_price_html
	 * @param string $price_html The original price html.
	 * @param WC_Product_Variable $product The product object.
	 * @return string The modified price html.
	 */
	public function display_variable_price_html( $price_html, $product){
		$prices = $product->get_variation_prices(true);
		if ( empty( $prices['price'] ) ){
			return $price_html;
		}
		$min_price     = current( $prices['price'] );
		$max_price     = end( $prices['price'] );
		$min_reg_price = current( $prices['regular_price'] );
		$max_reg_price = end( $prices['regular_price'] );

		$sale_price = $min_price !== $max_price ? wc_format_price_range( $min_price, $max_price ) : wc_price( $min_price );
		$regular_price = $min_reg_price !== $max_reg_price ? wc_format_price_range( $min_reg_price, $max_reg_price ) : wc_price( $min_reg_price );
		$price_html = wc_format_sale_price( $regular_price, $sale_price );
		return $price_html;
	}

	// no need work is done by filter_variation_prices_price & filter_variation_prices_sale_price & filter_variation_prices_regular_price
	/**
	 * Test variation prices filter.
	 * This is used to  the variation prices.
	 *
	 * @since 1.0.0
	 * @hook woocommerce_variation_prices
	 * @param array $prices The prices array.
	 * @param WC_Product $product The product object.
	 * @return array The modified prices array.
	 */
	public function filter_variation_prices( $prices, $product ){
		foreach( $prices['price'] as $key => $price ){
			$product_variation = wc_get_product( $key );
			$discount_data = $this->get_or_calculate_product_discount( $product_variation );
			if( $discount_data['on_campaign'] && isset( $discount_data['discounts']['best_price']) && $discount_data['discounts']['best_price'] ){
				$simple_price = $discount_data['discounts']['best_price'];
				$prices['sale_price'][$key] = $simple_price;
				$prices['price'][$key] = $simple_price < $prices['price'][$key] ? $simple_price : $prices['price'][$key];
			}
		}
		return $prices;
	}




	/**
	 * Cart after calculate totals filter.
	 * This is used to calculate the total discount and apply it to the cart.
	 * woocommerce_after_calculate_totals cannot do the job because it is calculated after that hook.
	 *
	 * @since 1.0.0
	 * @hook woocommerce_after_calculate_totals
	 * @param WC_Cart $cart The cart object.
	 */
	public function cart_after_calculate_totals( $cart ){
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
            return;
        }
		if( campaignbay_get_options('cart_showDiscountBreakdown') ){
			$discount_breakdown = $cart->campaignbay_discount_breakdown ?? array();

			foreach( $discount_breakdown as $campaign_id => $campaign_data ){
				$total_old_price = $campaign_data['total_old_price'];
				$total_new_price = $campaign_data['total_new_price'];
				$discount_value = $total_old_price - $total_new_price;
				$current_subtotal = $cart->get_subtotal();
				$current_total = $cart->get_total('edit');
				$cart->set_subtotal( $current_subtotal + $discount_value, true );

				// $cart->set_total( $current_total + $applied_discount, true );
				$cart->fees_api()->add_fee( array(
					'name' => $campaign_data['title'],
					'amount' => 0 - $discount_value,
					'total' => 0 - $discount_value,
				));

				if( campaignbay_get_options( 'debug_enableMode' ) ){
					campaignbay_log('------------------------------------------------------------------------------------------------', 'DEBUG' );
					campaignbay_log('campaign: ' . $campaign_id . '  ' . $campaign_data['title'] , 'DEBUG' );
					campaignbay_log('discount: ' . $discount_value, 'DEBUG' );
					campaignbay_log('total_old_price: ' . $total_old_price, 'DEBUG' );
					campaignbay_log('total_new_price: ' . $total_new_price, 'DEBUG' );
					campaignbay_log('current_total: ' . $current_total, 'DEBUG' );
					campaignbay_log('current_subtotal: ' . $current_subtotal, 'DEBUG' );
					campaignbay_log('new_total: ' . $cart->get_total('edit'), 'DEBUG' );
					campaignbay_log('new_subtotal: ' . $cart->get_subtotal(), 'DEBUG' );
					campaignbay_log('------------------------------------------------------------------------------------------------', 'DEBUG' );
				}
				
			}
		}
	}





	/**
	 * Apply discounts to the cart by calling the master calculation method.
	 * This is the main function that is called when the cart is calculated.
	 * It is responsible for calculating the discounts and applying them to the cart.
	 * It is also responsible for preparing the notices that are displayed to the user.
	 *
	 * @since 1.0.0
	 * @hook woocommerce_before_calculate_totals
	 * @param WC_Cart $cart The cart object.
	 */
	public function apply_discounts_and_prepare_notices( $cart ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}
		$cart->campaignbay_discount_breakdown = array();
		$allow_campaign_stacking = campaignbay_get_options('cart_allowCampaignStacking');
		
		foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
			// Get the product, quantity and discount data.
			$product       = $cart_item['data'];
			$quantity      = $cart_item['quantity'];
			$discount_data = $this->get_or_calculate_product_discount( $product );

			// If the product is not on a campaign, skip it.
			if ( ! $discount_data['on_campaign'] ) {
				unset( $cart->cart_contents[ $cart_item_key ]['cb_discount_data'] );
				continue;
			}
			// Initialize our custom notice key for this item.
			$cart->cart_contents[ $cart_item_key ]['cb_discount_data'] = array(
				'message' => null,
				'old_price' => $product->get_regular_price(),
				'new_price' => null
			);

			// Price Calculation Logic
			// Get the base price and the best price for the product.
			$base_price = $discount_data['base_price'];


			if( isset( $discount_data['discounts']['simple'] ) && $discount_data['discounts']['simple']['is_applied'] ){
				$simple_price = $discount_data['discounts']['simple']['price'] ?? $base_price;
				// If the campaign stacking is allowed, use the simple price as the base price.
				$base_price = $allow_campaign_stacking ? $simple_price : $base_price;
				$cart_item['data']->set_price( max( 0, $simple_price ) );
			}
			
			// Initialize the quantity price and the next tier.
			// These will be used to calculate the price based on the quantity.
			$quantity_price = null;
			$needed_quantity = null;
			
			// If the campaign is a quantity campaign, we need to calculate the price based on the quantity.
			if( isset( $discount_data['discounts']['quantity']['price'] ) && ! empty( $discount_data['discounts']['quantity']['tiers'] )){

				
				// Sort the tiers by the minimum quantity.
				$tiers = $discount_data['discounts']['quantity']['tiers'];
				// no need to sort the tiers, it is already sorted.
				// usort($tiers, function( $a, $b ) { return (int) ( $a['min'] ?? 0 ) <=> (int) ( $b['min'] ?? 0 ); });
				
				// Initialize the current and next tier.
				$current_tier = null;
				
				$next_tier_price = null;

				// Loop through the tiers and find the current and next tier.
				foreach ( $tiers as $tier ) {
					if( (int) $tier['min']  > $quantity ){
						$next_tier = $tier;
						$next_tier_price = $this->calculate_tier_price( $next_tier, $base_price );
						// if( $next_tier_price < $simple_price ){
						// 	break;
						// }
						break;
					}
					if ( (int) $tier['min'] <= $quantity ) {
						$current_tier = $tier;
					}
				}

				// If the current tier is found, calculate the price based on the current tier.
				if( $current_tier ){
					$quantity_price = $this->calculate_tier_price( $current_tier, $base_price );
					if( $quantity_price === $next_tier_price ){
						$next_tier_price = null;
					}
				}
				
				// If the next tier is found and the next discount bar is enabled, calculate the price based on the next tier.
				if( $next_tier_price !== null && $next_tier_price < $simple_price && campaignbay_get_options('cart_showNextDiscountBar') ){

					// Get the format for the next discount bar.
					$format = campaignbay_get_options( 'cart_nextDiscountFormat' );

					if ( !empty( $format ) ) {

						// Get the needed quantity and the discount value.
						$needed_quantity = (int) $next_tier['min'] - $quantity;
						$discount_value  = (float) $next_tier['value'];
						$discount_type   = $next_tier['type'];
						$discount_string = ( 'percentage' === $discount_type ) ? $discount_value . '%' : wc_price( $discount_value );

						// Replace the placeholders in the format with the needed quantity and the discount value.
						$replacements = array(
							'{remaining_amount}' => $needed_quantity,
							'{discount_percentage}'     => $discount_string,
						);
						$message = str_replace( array_keys( $replacements ), array_values( $replacements ), $format );
						
						// Attach the generated message to the cart item array.
						$cart->cart_contents[ $cart_item_key ]['cb_discount_data']['message'] = $message;
					}
				}
			}

			if ( $quantity_price !== null && $quantity_price < $base_price ) {

			
				$cart_item['data']->set_price( max( 0, $quantity_price ) );
				$campaign_id = $discount_data['discounts']['quantity']['campaign_id'];

				// storing the discount data in the cart item array for other actions and filters.
				$cart->cart_contents[ $cart_item_key ]['cb_discount_data']['old_price'] = $product->get_regular_price();
				$cart->cart_contents[ $cart_item_key ]['cb_discount_data']['new_price'] = $quantity_price;
				$cart->cart_contents[ $cart_item_key ]['cb_discount_data']['quantity'] = $quantity;
				$cart->cart_contents[ $cart_item_key ]['cb_discount_data']['on_campaign'] = true;

				// Initialize the discount breakdown array if it doesn't exist.
				if ( ! isset( $cart->campaignbay_discount_breakdown[ $campaign_id ] ) ) {
					$cart->campaignbay_discount_breakdown[ $campaign_id ] = array(
						'title'    => $discount_data['discounts']['quantity']['campaign_title'],
						'total_old_price' => 0,
						'total_new_price' => 0,
					);
				}

				// Add the discount to the discount breakdown array.
				$cart->campaignbay_discount_breakdown[ $campaign_id ]['total_old_price'] += (float) $base_price * (float) $quantity;
				$cart->campaignbay_discount_breakdown[ $campaign_id ]['total_new_price'] += (float) $quantity_price * (float) $quantity;

				if($allow_campaign_stacking && isset( $discount_data['discounts']['simple']['price'] ) ){
					$campaign_id = $discount_data['discounts']['simple']['campaign_id'];
					if ( ! isset( $cart->campaignbay_discount_breakdown[ $campaign_id ] ) ) {
						$cart->campaignbay_discount_breakdown[ $campaign_id ] = array(
							'title'    => $discount_data['discounts']['simple']['campaign_title'],
							'total_old_price' => 0,
							'total_new_price' => 0,
						);
					}
					$cart->campaignbay_discount_breakdown[ $campaign_id ]['total_old_price'] += (float) $discount_data['base_price'] * (float) $quantity;
					$cart->campaignbay_discount_breakdown[ $campaign_id ]['total_new_price'] += (float) $discount_data['discounts']['simple']['price'] * (float) $quantity;
				}
			}elseif( isset( $discount_data['discounts']['simple']['price'] ) ){
				$cart->cart_contents[ $cart_item_key ]['cb_discount_data']['old_price'] = $discount_data['base_price'];
				$cart->cart_contents[ $cart_item_key ]['cb_discount_data']['new_price'] = $discount_data['discounts']['simple']['price'];
				$cart->cart_contents[ $cart_item_key ]['cb_discount_data']['quantity'] = $quantity;
				$cart->cart_contents[ $cart_item_key ]['cb_discount_data']['on_campaign'] = true;
				$campaign_id = $discount_data['discounts']['simple']['campaign_id'];
				if ( ! isset( $cart->campaignbay_discount_breakdown[ $campaign_id ] ) ) {
					$cart->campaignbay_discount_breakdown[ $campaign_id ] = array(
						'title'    => $discount_data['discounts']['simple']['campaign_title'],
						'total_old_price' => 0,
						'total_new_price' => 0,
					);
				}
				$cart->campaignbay_discount_breakdown[ $campaign_id ]['total_old_price'] += (float) $discount_data['base_price'] * (float) $quantity;
				$cart->campaignbay_discount_breakdown[ $campaign_id ]['total_new_price'] += (float) $discount_data['discounts']['simple']['price'] * (float) $quantity;
			}

			if( campaignbay_get_options('debug_enableMode') ){
				campaignbay_log('------------------------------------------------------------------------------------------------', 'DEBUG' );
				campaignbay_log('cart_item_key: ' . $cart_item_key . ' ' . $product->get_name() . ' ' . $product->get_id(), 'DEBUG' );
				campaignbay_log('quantity: ' . $quantity, 'DEBUG' );
				campaignbay_log('regular_price: ' . $product->get_regular_price(), 'DEBUG' );
				campaignbay_log('campaign_price: ' . $base_price, 'DEBUG' );
				campaignbay_log('quantity_price: ' . $quantity_price, 'DEBUG' );
				campaignbay_log('simple_price: ' . $simple_price, 'DEBUG' );
				campaignbay_log('best_price: ' . $discount_data['discounts']['best_price'], 'DEBUG' );
				campaignbay_log('price: ' . $product->get_price(), 'DEBUG' );
				campaignbay_log('campaign_id: ' . $campaign_id, 'DEBUG' );
			}
			
		}


	}





	/**
	 * Displays a promotional notice directly under the product name in the cart.
	 *
	 * @since 1.0.0
	 * @hook woocommerce_cart_item_name
	 * @param array  $cart_item The cart item data.
	 * @param string $cart_item_key The key for the cart item.
	 * @return void
	 */
	public function display_inline_cart_notice( $cart_item, $cart_item_key ) {
		// Get the discount data for the cart item.
		$discount_data = $cart_item['cb_discount_data'] ?? null;
		if( ! $discount_data ){
			return;
		}
		// If no message is set, return.
		if ( ! $discount_data['message'] ) {
			return;
		}
		// Echo the message.
		echo '<div class="wpab-cb-cart-item-notice" style="font-size: 0.9em; color: #777;">' . wp_kses_post( $discount_data['message'] ) . '</div>';
	}

	/**
	 * Display the cart item price.
	 *
	 * @since 1.0.0
	 * @hook woocommerce_cart_item_price
	 * @param string     $price The original price.
	 * @param array      $cart_item The cart item data.
	 * @param string     $cart_item_key The key for the cart item.
	 * @return string The modified price.
	 */
	public function display_cart_item_price( $price, $cart_item, $cart_item_key ) {
		// Get the discount data for the cart item.
		$discount_data = $cart_item['cb_discount_data'] ?? null;
		if( ! $discount_data ){
			return $price;
		}
		// If no discount data is set, return the original price.
		if (  ! $discount_data['new_price'] || ! $discount_data['old_price']) {
			return $price;
		}
		
		// Get the regular price and the sale price.
		$regular_price = (float) $discount_data['old_price'];
		$sale_price    = (float) $discount_data['new_price'];
		// Format the price using WooCommerce's standard sale price function.
		$formatted_price =wc_format_sale_price(
			wc_price( $regular_price ),
			wc_price( $sale_price )
		);
		// Return the formatted price.
		return $formatted_price;
	}

	/**
	 * Modify the price HTML on product/shop pages to show the discount.
	 *
	 * @since 1.0.0
	 * @hook woocommerce_get_price_html
	 * @param string     $price_html The original price HTML.
	 * @param WC_Product $product    The product object.
	 * @return string The modified price HTML.
	 */
	public function display_discounted_price_html( $price_html, $product ) {
		// If the product is a variation, use the parent product's price as the regular price.
		if ( $product->is_type( 'variable' ) ) {
			
			$discount_data = $this->get_or_calculate_product_discount($product);
			$prices = $product->get_variation_prices( true );
			$min_price = min($prices['price']);
			$max_price = max($prices['price']);
			$min_regular_price = min($prices['regular_price']);
			$max_regular_price = max($prices['regular_price']);
			$price_html = '';
			$regular_price_html = '';
			if($max_price !== $min_price){
				$price_html = wc_format_price_range( $max_price, $min_price );
			} else{
				$price_html = wc_price( $max_price );
			}
			if($max_regular_price != $min_regular_price){
				$regular_price_html = wc_format_price_range( $max_regular_price, $min_regular_price );
			} else{
				$regular_price_html = wc_price( $max_regular_price );
			}
			if($discount_data['on_campaign'] ){
				$price_html = wc_format_sale_price( $regular_price_html, $price_html );
			} else if( ! $product->is_on_sale('edit') ){
				$price_html = $regular_price_html;	
			}
			return $price_html;
		}
		// Get the discount data for the product.
		$discount_data = $this->get_or_calculate_product_discount( $product );
		// Get the regular price.
		$regular_price = (float) $product->get_regular_price( 'edit' );
		// If the campaign is active and provides the best price, return the formatted price.
		if ( $discount_data['on_campaign'] && isset( $discount_data['discounts']['best_price'] ) && $discount_data['discounts']['best_price'] < $regular_price ) {
			// If the option to show the discounted price is enabled, return the formatted price.
			if( campaignbay_get_options('product_showDiscountedPrice') && isset( $discount_data['discounts']['best_price'] ) ){
				$sale_price    = (float) $discount_data['discounts']['best_price'];
				// Format the price using WooCommerce's standard sale price function.
				$price_html = wc_format_sale_price(
					wc_price( $regular_price ),
					wc_price( $sale_price )
				);
			} else{
				// If the option to show the discounted price is disabled, return the sale price.
				$price_html = wc_price( $discount_data['discounts']['best_price'] );
			}
			
		}

		return $price_html;
	}


	/**
	 * Filter the product is on sale.
	 *
	 * @since 1.0.0
	 * @hook woocommerce_product_is_on_sale
	 * @param bool      $is_on_sale The original is on sale value.
	 * @param WC_Product $product    The product object.
	 * @return bool The modified is on sale value.
	 */
	public function filter_is_product_on_sale( $is_on_sale, $product ) {
		if( ! campaignbay_get_options('product_showDiscountedPrice') ) {
			return $is_on_sale;
		}
		// Get the discount data for the product.
		$discount_data = $this->get_or_calculate_product_discount( $product );
		// If the campaign is active, return true.
		return $is_on_sale || ( $discount_data['on_campaign']  );
	}

	/**
	 * Action to display the product summary message.
	 *
	 * @since 1.0.0
	 * @hook woocommerce_single_product_summary
	 */
	public function action_single_product_summary() {
		global $product;
		// If the product is not a valid WC_Product object, log an error and return.
		if ( ! $product instanceof WC_Product ) {
			campaignbay_log('Invalid product type: ' . gettype( $product ), 'ERROR' );
			return;
		}
		// Get the discount data for the product.
		$discount_data = $this->get_or_calculate_product_discount( $product );

		if($product->is_type('variable')){
			campaignbay_log('variable product___________________________________________---', 'DEBUG' );
			$children = $product->get_children();
			foreach ( $children as $child_id ) {
				$child = wc_get_product( $child_id );
				$child_discount_data = $this->get_or_calculate_product_discount( $child );
				if( $child_discount_data['on_campaign'] ){
					$discount_data = $child_discount_data;
					break;
				}
			}
		}
		// Only show the message if our campaign is active.
		if ( $discount_data['on_campaign'] ) {
			$simple_message = $this->generate_product_summary_simple_message( $discount_data );
			$simple_message ? $this->print_notice( $simple_message ) : null;

			if( isset( $discount_data['discounts']['quantity']['table'] ) ){
				$this->print_notice( $discount_data['discounts']['quantity']['table'] );
			}
			
		}
	}



	/**
	 * Generate the product summary message for the simple campaign.
	 *
	 * @since 1.0.0
	 * @param array $discount_data The discount data.
	 * @return string The message text.	
	 */
	public function generate_product_summary_simple_message( $discount_data ) {
		if( ! $discount_data['on_campaign'] ){
			return null;
		}
		// Get the format for the product summary message.
		$format = campaignbay_get_options( 'product_messageFormat' );
		if( empty( $format ) ){
			return null;
		}
		// Get the base price and the best price for the product.
		$base_price = $discount_data['base_price'];
		$discounted_price = (float) $discount_data['discounts']['best_price'] ?? 0;
		
		// Don't show if there's no actual saving.
		if ( $discounted_price >= $base_price ) {
			return null;
		}

		// Calculate the amount off and the percentage off.
		$amount_off = $base_price - $discounted_price;
		$percentage_off = round( ( $amount_off / $base_price ) * 100 );

		// Replace the placeholders in the format with the amount off and the percentage off.
		$replacements = array(
			'{percentage_off}' => $percentage_off . '%',
			'{amount_off}'     => wp_strip_all_tags( wc_price( $amount_off ) ),
		);
		$message_text = str_replace( array_keys( $replacements ), array_values( $replacements ), $format );
		
		return $message_text;
	}

	/**
	 * Print the notice.
	 *
	 * @since 1.0.0
	 * @param string $message The message to print.
	 */
	public function print_notice( $message ) {
		echo '<div class="woocommerce-info" style="margin-bottom: 0px; border-top: none;">' . wp_kses_post( $message ) . '</div>';
	}

	/**
	 * Display the cart item subtotal.
	 *
	 * @since 1.0.0
	 * @hook woocommerce_cart_item_subtotal
	 * @param string $subtotal The original subtotal.
	 * @param array  $cart_item The cart item data.
	 * @param string $cart_item_key The key for the cart item.
	 * @return string The modified subtotal.
	 */
	public function display_cart_item_subtotal( $subtotal, $cart_item, $cart_item_key ) {
		// Get the discount data for the cart item.
		if( ! isset( $cart_item['cb_discount_data'] ) ){
			return $subtotal;
		}
		$discount_data = $cart_item['cb_discount_data'];
		
		// If no discount data is set, or the option to show the discounted price is disabled, return the original subtotal.
		if ( ! $discount_data['new_price'] || ! $discount_data['old_price'] || ! $discount_data['quantity'] || ! campaignbay_get_options('product_showDiscountedPrice') ) {
			$quantity = $cart_item['quantity'];
			$price = $cart_item['data']->get_price();
			$regular_price = $price * $quantity;
			$sale_price = $price * $quantity;
		}
		else{
		// Get the regular price and the sale price.
		$regular_price = (float) $discount_data['old_price'] * (int) $discount_data['quantity'];
		$sale_price    = (float) $discount_data['new_price'] *  (int) $discount_data['quantity'];
		}

		// Format the price using WooCommerce's standard sale price function.
		if( $regular_price === $sale_price ){
			return wc_price( $regular_price );
		}
		return wc_format_sale_price(
			wc_price( $regular_price ),
			wc_price( $sale_price )
		);
	}

	
	
	
	
	/**
	 * Adds a new action or filter to the hooks array.
	 *
	 * @since 1.0.0
	 * @access private
	 * @param string $type 'action' or 'filter'.
	 * @param string $hook The hook name.
	 * @param string $callback The callback method on this object.
	 * @param int    $priority The priority.
	 * @param int    $accepted_args The number of accepted arguments.
	 */
	private function add_hook( $type, $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$this->hooks[] = array(
			'type'          => $type,
			'hook'          => $hook,
			'callback'      => $callback,
			'priority'      => $priority,
			'accepted_args' => $accepted_args,
		);
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
		$this->add_hook( 'action', $hook, $callback, $priority, $accepted_args );
	}

	/**
	 * Adds a new filter to the hooks array.
	 *
	 * @since 1.0.0
	 * @access private
	 * @param string $hook The hook name.
	 * @param string $callback The callback method on this object.
	 * @param int    $priority The priority.
	 * @param int    $accepted_args The number of accepted arguments.
	 */
	private function add_filter( $hook, $callback, $priority = 10, $accepted_args = 2 ) {
		$this->add_hook( 'filter', $hook, $callback, $priority, $accepted_args );
	}


	/**
	 * Returns the complete array of hooks to be registered by the main loader.
	 *
	 * @since 1.0.0
	 * @access public
	 * @return array
	 */
	public function get_hooks() {
		return $this->hooks;
	}

	/**
	 * Master calculation method. Gets all applicable discounts for a product, using the cache if available.
	 *
	 * This function serves as the single source of truth for a product's potential discounts.
	 * It categorizes discounts into 'simple' (direct price changes) and 'quantity',
	 * and finds the single best 'simple' discount while collecting all others.
	 *
	 * @since 1.0.0
	 * @param WC_Product $product The product object.
	 * @return array An array containing all applicable discount information, structured by type.
	 */
	public function get_or_calculate_product_discount( $product ) {
		// If the product is not a valid WC_Product object, log an error and return.
		
		if ( ! $product instanceof WC_Product ) {
			//campaignbay_log('Invalid product type: ' . gettype( $product ), 'ERROR' );
			return array(
				'base_price' => 0,
				'on_sale'   => false,
				'on_campaign' => false,
				'discounts'  => array(),
			);
		}
		// Get the product ID.
		$product_id = $product->get_id();
		// If the option to exclude sale items is enabled and the product is on sale, return the sale price.
		if( campaignbay_get_options('product_excludeSaleItems') && $product->is_on_sale('edit') ) {
			campaignbay_log(' is on sale', 'DEBUG' );
			return array(
				'base_price' => (float) $product->get_sale_price('edit'),
				'on_sale'   => true,
				'on_campaign' => false,
				'discounts'  => array(),
			);
		}
		
		// Check the request-level cache first to avoid recalculating the discount for the same product.
		if ( isset( $this->product_discount_cache[ $product_id ] ) ) {
			// Log the cache hit.
			return $this->product_discount_cache[ $product_id ];
		}

		// Get the campaign manager.
		$campaign_manager = CampaignManager::get_instance();
		// Get all active campaigns.
		$active_campaigns = $campaign_manager->get_active_campaigns();

		$base_price = (float) $product->get_regular_price();
		// Initialize the discount data array.
		$discount_data = array(
			'base_price' => $base_price,
			'on_sale'   => false,
			'on_campaign' => false,
			'discounts'  => array(
				'simple' => array(
					'is_applied' => false,
				),
				'quantity' => array(
					'is_applied' => false,
				),
			),
		);

		if ( empty( $active_campaigns ) || ! is_array( $active_campaigns ) ) {
			campaignbay_log('no active campaigns (' . $product_id . ' - ' . $product->get_name() . ')', 'DEBUG' );
			// If there are no active campaigns, return the discount data.
			$this->product_discount_cache[ $product_id ] = $discount_data;
			return $discount_data;
		}
		$allow_campaign_stacking = campaignbay_get_options('cart_allowCampaignStacking');
		
		// 3. Loop through all active campaigns to categorize and evaluate them.
		foreach ( $active_campaigns as $campaign ) {
			
			// Get the campaign type.
			$campaign_type = $campaign->get_meta( 'campaign_type' );

			if($campaign_type !== 'scheduled' && $campaign_type !== 'earlybird'){
				continue;
			}

			// If the campaign is not applicable to the product, skip it.
			if( ! $campaign->is_applicable_to_product( $product_id ) ) {
				continue;
			}
			// Initialize the discounted price.
			$discounted_price = null;

			$type_data = array(
				'campaign_id' => $campaign->get_id(),
				'campaign_type' => $campaign_type,
				'campaign_title' => $campaign->get_title(),
			);

			// If the campaign is a scheduled campaign, calculate the simple price.
			if ( 'scheduled' === $campaign_type ) {
				$discounted_price = $this->calculate_simple_price( $campaign, $base_price );
				$type_data['discount_value'] = $campaign->get_meta('discount_value');
				$type_data['discount_type'] = $campaign->get_meta('discount_type');

			}elseif( 'earlybird' === $campaign_type ){
				$tiers = $campaign->get_meta( 'campaign_tiers' );
				if ( ! is_array( $tiers ) || empty( $tiers ) ) {
					continue;
				}
				// Find the correct tier based on usage count.
				$usage_count  = $campaign->get_usage_count();
				$current_tier = null;
				campaignbay_log('_________________________________earlybird_________________________________', 'DEBUG' );
				campaignbay_log('_________________________________earlybird_________________________________', 'DEBUG' );
				campaignbay_log('_________________________________earlybird_________________________________', 'DEBUG' );
				
				campaignbay_log('usage_count: ' . $usage_count, 'DEBUG' );
				// Find the first tier where the current usage is less than the max.
				foreach ( $tiers as $tier ) {
					if( ! isset( $tier['quantity'] ) || ! isset( $tier['total'] )){
						continue;
					}
					$tier_quantity = (int) $tier['quantity'];
					// sum of quantity of previous tiers , precalculated on creation of campaign . its better to calculate it once
					$tier_max = (int) $tier['total'] + $tier_quantity;
					if( $tier_quantity === '' || $tier_max === '' || $tier_quantity === 0 ){
						continue;
					}
					if ( $usage_count < $tier_max ) {
						$current_tier = $tier;
						break; // Found the correct tier, no need to search further.
					}
				}
				campaignbay_log('current_tier: ' . print_r($current_tier, true), 'DEBUG' );
				// If a valid tier was found, calculate the price for it.
				if ( !$current_tier ) {
					continue;
				}
				$discounted_price = $this->calculate_tier_price( $current_tier, $base_price );
				$type_data['tiers'] = $tiers; // Still pass all tiers for potential notices.
				
			}

			if( $discounted_price === null ){
				continue;
			}
			if( isset( $discount_data['discounts']['simple']['price'] ) && ! $this->is_better_campaign( $discount_data['discounts']['simple']['price'], $discounted_price )){
				continue;
			}

			$discount_data['on_campaign'] = true;	
			$discount_data['discounts']['best_price'] = $discounted_price;
			$discount_data['discounts']['simple'] = $type_data;
			$discount_data['discounts']['simple']['price'] = $discounted_price;
			$discount_data['discounts']['simple']['discount_per_item'] = $base_price - $discounted_price;
			$discount_data['discounts']['simple']['is_applied'] = true;
			$product->set_price( $discounted_price );
		} 
		if( $allow_campaign_stacking && isset( $discount_data['discounts']['simple']['price'] ) ){
			$base_price = $discount_data['discounts']['simple']['price'];
		}

		foreach ( $active_campaigns as $campaign ) {
			
			// Get the campaign type.
			$campaign_type = $campaign->get_meta( 'campaign_type' );

			if($campaign_type !== 'quantity'){
				continue;
			}

			// If the campaign is not applicable to the product, skip it.
			if( ! $campaign->is_applicable_to_product( $product_id ) ) {
				continue;
			}
			// Initialize the discounted price.
			$discounted_price = null;

			$type_data = array(
				'campaign_id' => $campaign->get_id(),
				'campaign_type' => $campaign_type,
				'campaign_title' => $campaign->get_title(),
			);

			$tiers = $campaign->get_meta('campaign_tiers');
			// If the tiers are empty, skip the campaign.
			if ( ! is_array( $tiers ) || empty( $tiers ) ) {
				continue;
			}
			// Calculate the tier price.
			$discounted_price = $this->calculate_tier_price( $tiers[0], $base_price );
			$type_data['tiers'] = $tiers;
			if( isset( $discount_data['discounts']['quantity']['price'] ) && ! $this->is_better_campaign( $discount_data['discounts']['quantity']['price'], $discounted_price )){
				continue;
			}
			
			$discount_data['discounts']['quantity'] = $type_data;
			$discount_data['discounts']['quantity']['price'] = $discounted_price;
			$discount_data['discounts']['quantity']['discount_per_item'] = $base_price - $discounted_price;
			$discount_data['discounts']['quantity']['is_applied'] = false;
			
			$discount_data['discounts']['quantity']['table'] = $this->generate_quantity_tier_table( $tiers );

		}
		if( $allow_campaign_stacking && isset( $discount_data['discounts']['quantity']['price'] ) ){
			$discount_data['on_campaign'] = true;
			$product->set_price( max( 0, $discount_data['discounts']['quantity']['price'] ) );
			$discount_data['discounts']['best_price'] = $discount_data['discounts']['quantity']['price'];
			$discount_data['discounts']['quantity']['is_applied'] = true;
		}elseif( ! $allow_campaign_stacking && isset( $discount_data['discounts']['quantity']['price'] ) ){
			$simple_price = $discount_data['discounts']['simple']['price'];
			$quantity_price = $discount_data['discounts']['quantity']['price'];
			if(!isset( $discount_data['discounts']['simple']['price'] ) || $this->is_better_campaign( $quantity_price, $simple_price )){
				if($discount_data['discounts']['quantity']['tiers'][0]['min'] === 1){
					$product->set_price( max( 0, $quantity_price ) );
					$discount_data['on_campaign'] = true;
					$discount_data['discounts']['best_price'] = $quantity_price;
					$discount_data['discounts']['quantity']['is_applied'] = true;
					$discount_data['discounts']['simple']['is_applied'] = false;
				}
			}
		}

		// Store the final, structured result in the cache for this page load.
		$this->product_discount_cache[ $product_id ] = $discount_data;
		if( campaignbay_get_options( 'debug_enableMode' ) ){
			campaignbay_log('------------------------------------------------------------------------------------------------', 'DEBUG' );
			campaignbay_log('---------------------------------------------start----------------------------------------------', 'DEBUG' );
			campaignbay_log('------------------------------------------------------------------------------------------------', 'DEBUG' );
			campaignbay_log('product_discount_cache: ' . $product_id . ' ' . $product->get_name(), 'DEBUG' );
			campaignbay_log('base_price: ' . $discount_data['base_price'], 'DEBUG' );
			if( isset( $discount_data['discounts']['best_price'] ) ){
				campaignbay_log('best_price: ' . $discount_data['discounts']['best_price'], 'DEBUG' );
			}
			campaignbay_log('price: ' . $product->get_price(), 'DEBUG' );
			campaignbay_log('on_sale: ' . $discount_data['on_sale'], 'DEBUG' );
			campaignbay_log('on_campaign: ' . $discount_data['on_campaign'], 'DEBUG' );
			if( isset( $discount_data['discounts']['best_price'] ) ){
				campaignbay_log('best_price: ' . $discount_data['discounts']['best_price'], 'DEBUG' );
			}
			if( isset( $discount_data['discounts']['simple']['price'] ) ){
				$simple = $discount_data['discounts']['simple'];
				campaignbay_log('	simple_price: ' . $simple['price'], 'DEBUG' );
				campaignbay_log('	simple_campaign_id: ' . $simple['campaign_id'], 'DEBUG' );
				campaignbay_log('	simple_campaign_type: ' . $simple['campaign_type'], 'DEBUG' );
				campaignbay_log('	simple_campaign_title: ' . $simple['campaign_title'], 'DEBUG' );
				campaignbay_log('	simple_is_applied: ' . $simple['is_applied'], 'DEBUG' );
			}
			if( isset( $discount_data['discounts']['quantity']['price'] ) ){
				$quantity = $discount_data['discounts']['quantity'];
				campaignbay_log('	quantity_price: ' . $quantity['price'], 'DEBUG' );
				campaignbay_log('	quantity_campaign_id: ' . $quantity['campaign_id'], 'DEBUG' );
				campaignbay_log('	quantity_campaign_type: ' . $quantity['campaign_type'], 'DEBUG' );
				campaignbay_log('	quantity_campaign_title: ' . $quantity['campaign_title'], 'DEBUG' );
				campaignbay_log('	quantity_is_applied: ' . $quantity['is_applied'], 'DEBUG' );
			}

			campaignbay_log('------------------------------------------------------------------------------------------------', 'DEBUG' );
			campaignbay_log('---------------------------------------------end----------------------------------------------', 'DEBUG' );
			campaignbay_log('------------------------------------------------------------------------------------------------', 'DEBUG' );
		}
		return $discount_data;
	}

	/**
	 * Generate the quantity tier table.
	 *
	 * @since 1.0.0
	 * @access private
	 * @param array     $tiers The tiers array.
	 * @return string The generated table.
	 */
	private function generate_quantity_tier_table( $tiers ){
		if( ! is_array( $tiers ) || empty( $tiers ) ){
			return '';
		}
		$table = '<table class="'. CAMPAIGNBAY_PLUGIN_NAME .'-quantity-tier-table">';
		foreach( $tiers as $tier ){
			$table .= '<tr><td>Buy minimum ' . $tier['min'] . ' pcs to get ' . $tier['value'] . ' ';
			if( $tier['type'] === 'percentage' ){
				$table .= '% off!';
			}else{
				$table .= 'off per item!';
			}
			$table .= '</td></tr>';
		}
		$table .= '</table>';
		return $table;
	}


	/**
	 * Calculate the simple price for a campaign.
	 *
	 * @since 1.0.0
	 * @access private
	 * @param CAMPAIGNBAY_Campaign $campaign The campaign object.
	 * @param float       $base_price The base price of the product.
	 * @return float The calculated price.
	 */
	private function calculate_simple_price( $campaign, $base_price ) {
		// Get the discount value and type.
		$discount_value = (float) $campaign->get_meta( 'discount_value' );
		$discount_type = $campaign->get_meta( 'discount_type' );
		
		// If the discount type is percentage calculate the fixed discount value.
		if ( 'percentage' === $discount_type ) {
			$discount_value = $base_price * ( $discount_value / 100 ) ;
		}
		// Return the calculated price.
		return floatval( max( 0, $base_price - $discount_value ) );	
	}

	/**
	 * Calculate the price for a tier.
	 *
	 * @since 1.0.0
	 * @access private
	 * @param array     $tier The tier array.
	 * @param float     $base_price The base price of the product.
	 * @return float The calculated price.
	 */
	private function calculate_tier_price( $tier, $base_price ) {
		// If the tier is not an array or is empty, return null.
		if( ! is_array( $tier ) || empty( $tier ) ){
			return null;
		}
		// Get the tier value and type.
		$tier_value = isset( $tier['value'] ) ? (float) $tier['value'] : 0;
		$tier_type = isset( $tier['type'] ) ? $tier['type'] : 'percentage';

		// If the tier type is percentage, calculate the fixed discount value.
		if ( 'percentage' === $tier_type ) {
			$tier_value = $base_price * ( $tier_value / 100 ) ;
		}
		// Return the calculated price.
		return floatval( max( 0, $base_price - $tier_value ) );
	}


	/**
	 * Check if a campaign is better than the current best campaign.
	 *
	 * @since 1.0.0
	 * @access private
	 * @param float     $best_price The current best price.
	 * @param float     $tier_price The price of the tier.
	 * @return bool True if the campaign is better, false otherwise.
	 */
	private function is_better_campaign( $best_price, $tier_price ) {
		// If the tier price is not set, return false.
		if( $tier_price === null ){
			return false;
		}
		if( $best_price === null ){
			return true;
		}
		// If the priority method is set to apply the highest price, return true if the tier price is less than the best price.
		if( 'apply_highest' === campaignbay_get_options( 'product_priorityMethod' ) ){
			return  $tier_price < $best_price ;
		}
		// If the priority method is set to apply the lowest price, return true if the tier price is greater than the best price.
		return $tier_price > $best_price;
	}
}