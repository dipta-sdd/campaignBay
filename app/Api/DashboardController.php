<?php

namespace WpabCb\Api;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Import WordPress REST API classes
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use WP_Query;
use DateTime;
use DateInterval;

/**
 * The REST API Controller for the Dashboard.
 *
 * @since      1.0.0
 * @package    WPAB_CampaignBay
 * @author     WP Anchor Bay <wpanchorbay@gmail.com>
 */
class DashboardController extends ApiController {

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function run() {
		$this->rest_base = 'dashboard';
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		$namespace = $this->namespace . $this->version;

		register_rest_route(
			$namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_dashboard_data' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => $this->get_collection_params(),
				),
			)
		);
	}

	public function permissions_check( $request ) {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Main callback to fetch all data for the dashboard.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_dashboard_data( $request ) {
		$period = $request->get_param( 'period' );
		$start_date = $request->get_param( 'start_date' );
		$end_date = $request->get_param( 'end_date' );
		
		list($current_start, $current_end, $previous_start, $previous_end) = $this->parse_date_range( $period, $start_date, $end_date );

		$response = array(
			'kpis'            => $this->get_kpi_data( $current_start, $current_end, $previous_start, $previous_end ),
			'charts'          => $this->get_chart_data( $current_start, $current_end ),
			'recent_activity' => $this->get_recent_activity(),
		);

		return new WP_REST_Response( $response, 200 );
	}

	/**
	 * Calculates KPI data for the dashboard.
	 *
	 * @param string $current_start  Start date for the current period.
	 * @param string $current_end    End date for the current period.
	 * @param string $previous_start Start date for the previous period.
	 * @param string $previous_end   End date for the previous period.
	 * @return array
	 */
	private function get_kpi_data( $current_start, $current_end, $previous_start, $previous_end ) {
		global $wpdb;
		$logs_table = $wpdb->prefix . 'wpab_cb_logs';
		$success_statuses = "'processing', 'completed'";

		// --- Get Current Period Data ---
		$current_sql = $wpdb->prepare(
			"SELECT
				SUM(total_discount) as total_discount_value,
				SUM(order_total) as sales_from_campaigns,
				COUNT(DISTINCT order_id) as discounted_orders
			 FROM {$logs_table}
			 WHERE log_type = 'sale' AND order_status IN ({$success_statuses})
			 AND timestamp BETWEEN %s AND %s",
			$current_start,
			$current_end
		);
		$current_data = $wpdb->get_row( $current_sql, ARRAY_A );

		// --- Get Previous Period Data for Comparison ---
		$previous_sql = $wpdb->prepare(
			"SELECT
				SUM(total_discount) as total_discount_value,
				COUNT(DISTINCT order_id) as discounted_orders
			 FROM {$logs_table}
			 WHERE log_type = 'sale' AND order_status IN ({$success_statuses})
			 AND timestamp BETWEEN %s AND %s",
			$previous_start,
			$previous_end
		);
		$previous_data = $wpdb->get_row( $previous_sql, ARRAY_A );

		// --- Get Active Campaign Count ---
		$active_campaigns_query = new WP_Query( array(
			'post_type'      => 'wpab_cb_campaign',
			'post_status'    => 'wpab_cb_active',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		) );
		
		return array(
			'active_campaigns' => array(
				'value' => $active_campaigns_query->found_posts,
			),
			'total_discount_value' => array(
				'value'  => (float) $current_data['total_discount_value'] ?? 0,
				'change' => $this->calculate_percentage_change( $current_data['total_discount_value'], $previous_data['total_discount_value'] ),
			),
			'discounted_orders' => array(
				'value'  => (int) $current_data['discounted_orders'] ?? 0,
				'change' => $this->calculate_percentage_change( $current_data['discounted_orders'], $previous_data['discounted_orders'] ),
			),
			'sales_from_campaigns' => array(
				'value' => (float) $current_data['sales_from_campaigns'] ?? 0,
			),
		);
	}

	/**
	 * Calculates Chart data for the dashboard.
	 *
	 * @param string $start_date Start date for the period.
	 * @param string $end_date   End date for the period.
	 * @return array
	 */
	private function get_chart_data( $start_date, $end_date ) {
		global $wpdb;
		$logs_table = $wpdb->prefix . 'wpab_cb_logs';
		$success_statuses = "'processing', 'completed'";

		// --- Discount Trends (Line Chart) ---
		$trends_sql = $wpdb->prepare(
			"SELECT DATE(timestamp) as date, SUM(total_discount) as value
			 FROM {$logs_table}
			 WHERE log_type = 'sale' AND order_status IN ({$success_statuses})
			 AND timestamp BETWEEN %s AND %s
			 GROUP BY DATE(timestamp)
			 ORDER BY date ASC",
			$start_date,
			$end_date
		);
		$discount_trends = $wpdb->get_results( $trends_sql, ARRAY_A );

		// --- Top Campaigns (Bar Chart) ---
		$top_campaigns_sql = $wpdb->prepare(
			"SELECT campaign_id, SUM(total_discount) as value
			 FROM {$logs_table}
			 WHERE log_type = 'sale' AND order_status IN ({$success_statuses})
			 AND timestamp BETWEEN %s AND %s
			 GROUP BY campaign_id
			 ORDER BY value DESC
			 LIMIT 5",
			$start_date,
			$end_date
		);
		$top_campaigns = $wpdb->get_results( $top_campaigns_sql, ARRAY_A );
		
		// Add campaign titles to the results
		foreach ( $top_campaigns as &$campaign_data ) {
			$campaign_data['name'] = get_the_title( $campaign_data['campaign_id'] );
		}

		return array(
			'discount_trends' => $discount_trends,
			'top_campaigns'   => $top_campaigns,
		);
	}
	
	/**
	 * Gets the most recent activity logs.
	 *
	 * @return array
	 */
	private function get_recent_activity() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'wpab_cb_logs';
		
		$results = $wpdb->get_results(
			"SELECT timestamp, extra_data, user_id, campaign_id FROM {$table_name}
			 WHERE log_type = 'activity'
			 ORDER BY timestamp DESC
			 LIMIT 5",
			ARRAY_A
		);

		$activity_log = array();
		foreach ( $results as $row ) {
			$user_info = get_userdata( $row['user_id'] );
			$extra_data = json_decode( $row['extra_data'], true );

			$activity_log[] = array(
				'timestamp' => $row['timestamp'],
				'activity'  => sprintf( "Campaign '%s' %s", esc_html($extra_data['title']), esc_html($extra_data['message']) ),
				'user'      => $user_info ? $user_info->display_name : 'System',
				'link'      => get_edit_post_link( $row['campaign_id'], 'raw' ),
			);
		}
		return $activity_log;
	}

	/**
	 * Parses the date range parameters from the request.
	 *
	 * @param string $period Preset period ('7days', '30days', 'year').
	 * @param string $start_date Custom start date.
	 * @param string $end_date Custom end date.
	 * @return array [current_start, current_end, previous_start, previous_end]
	 */
	private function parse_date_range( $period, $start_date, $end_date ) {
		$timezone = new \DateTimeZone( wp_timezone_string() );
		$end = new DateTime( 'now', $timezone );
		$end->setTime(23, 59, 59);
		$start = new DateTime( 'now', $timezone );
		$start->setTime(0, 0, 0);

		if ( 'custom' === $period && $start_date && $end_date ) {
			$start = new DateTime( $start_date, $timezone );
			$start->setTime(0, 0, 0);
			$end = new DateTime( $end_date, $timezone );
			$end->setTime(23, 59, 59);
		} else {
			switch ( $period ) {
				case '7days':
					$start->sub( new DateInterval( 'P6D' ) );
					break;
				case 'year':
					$start->sub( new DateInterval( 'P1Y' ) );
					break;
				case '30days':
				default:
					$start->sub( new DateInterval( 'P29D' ) );
					break;
			}
		}

		$current_start_str = $start->format( 'Y-m-d H:i:s' );
		$current_end_str = $end->format( 'Y-m-d H:i:s' );
		
		$interval = $end->diff( $start );
		$days_diff = $interval->days + 1;

		$previous_end = clone $start;
		$previous_end->sub( new DateInterval( 'P1D' ) );
		$previous_start = clone $previous_end;
		$previous_start->sub( new DateInterval( 'P' . ($days_diff - 1) . 'D' ) );

		return [
			$current_start_str,
			$current_end_str,
			$previous_start->format( 'Y-m-d H:i:s' ),
			$previous_end->format( 'Y-m-d H:i:s' ),
		];
	}

	/**
	 * Calculates the percentage change between two numbers.
	 *
	 * @param float $current Current value.
	 * @param float $previous Previous value.
	 * @return float
	 */
	private function calculate_percentage_change( $current, $previous ) {
		if ( (float) $previous === 0.0 ) {
			return (float) $current > 0 ? 100.0 : 0.0;
		}
		return round( ( ( $current - $previous ) / $previous ) * 100, 2 );
	}

	/**
	 * Defines the query parameters the endpoint accepts.
	 *
	 * @return array
	 */
	public function get_collection_params() {
		return array(
			'period' => array(
				'description' => __( 'The time period for the report.', 'campaignbay' ),
				'type'        => 'string',
				'enum'        => array( '7days', '30days', 'year', 'custom' ),
				'default'     => '30days',
			),
			'start_date' => array(
				'description' => __( 'Custom start date for the report (Y-m-d).', 'campaignbay' ),
				'type'        => 'string',
				'format'      => 'date',
			),
			'end_date' => array(
				'description' => __( 'Custom end date for the report (Y-m-d).', 'campaignbay' ),
				'type'        => 'string',
				'format'      => 'date',
			),
		);
	}
}