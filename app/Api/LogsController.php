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

/**
 * The REST API Controller for viewing logs.
 *
 * @since      1.0.0
 * @package    WPAB_CampaignBay
 * @author     WP Anchor Bay <wpanchorbay@gmail.com>
 */
class LogsController extends ApiController {

	/**
	 * The single instance of the class.
	 */
	private static $instance = null;

	/**
	 * Gets an instance of this object.
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Initialize the class.
	 */
	public function run() {
		$this->rest_base = 'logs';
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the routes for the objects of the controller.
	 */
	public function register_routes() {
        $namespace = $this->namespace . $this->version;

        register_rest_route(
            $namespace,
            '/' . $this->rest_base,
            array(
                // This is your existing GET route for viewing logs.
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_log_file_contents' ),
                    'permission_callback' => array( $this, 'permissions_check' ),
                ),
                // --- NEW: Add the DELETE route ---
                array(
                    'methods'             => WP_REST_Server::DELETABLE,
                    'callback'            => array( $this, 'delete_log_files' ),
                    'permission_callback' => array( $this, 'permissions_check' ),
                ),
            )
        );
    }

    public function permissions_check( $request ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            return new WP_Error(
                'rest_forbidden',
                __( 'You do not have permission to manage logs.', 'campaignbay' ),
                array( 'status' => rest_authorization_required_code() )
            );
        }
        return true;
    }

	/**
	 * Get the contents of today's log file.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_log_file_contents( $request ) {
		$upload_dir = wp_upload_dir();
		$log_dir    = $upload_dir['basedir'] . '/' . CAMPAIGNBAY_TEXT_DOMAIN . '-logs/';
		$log_file   = $log_dir . 'plugin-log-' . gmdate( 'Y-m-d' ) . '.log';

		if ( file_exists( $log_file ) ) {
			// Get the file system object.
			$wp_filesystem = campaignbay_file_system();
			$contents      = $wp_filesystem->get_contents( $log_file );

			if ( false === $contents ) {
				return new WP_Error(
					'rest_log_read_error',
					__( 'Could not read the log file. Check file permissions.', 'campaignbay' ),
					array( 'status' => 500 )
				);
			}

			// Reverse the lines so the newest entries appear at the top.
			$lines = explode( "\n", $contents );
			$reversed_lines = array_reverse($lines);
			$reversed_contents = implode("\n", $reversed_lines);

			$response = array( 'log_content' => $reversed_contents );
			return new WP_REST_Response( $response, 200 );

		} else {
			// If the file doesn't exist for today, it's not an error.
			$response = array( 'log_content' => __( 'No logs recorded for today.', 'campaignbay' ) );
			return new WP_REST_Response( $response, 200 );
		}
	}

    public function delete_log_files( $request ) {
        $upload_dir = wp_upload_dir();
        $log_dir    = $upload_dir['basedir'] . '/' . CAMPAIGNBAY_TEXT_DOMAIN . '-logs/';

        if ( ! is_dir( $log_dir ) ) {
            // If the directory doesn't exist, there's nothing to clear.
            return new WP_REST_Response( array( 'message' => __( 'Log directory does not exist. Nothing to clear.', 'campaignbay' ) ), 200 );
        }

        $wp_filesystem = campaignbay_file_system();
        $files         = $wp_filesystem->dirlist( $log_dir );
        $deleted_count = 0;

        if ( ! empty( $files ) ) {
            foreach ( $files as $file ) {
                // Security: Only delete files that end with .log
                if ( '.log' === substr( $file['name'], -4 ) ) {
                    if ( $wp_filesystem->delete( $log_dir . $file['name'] ) ) {
                        $deleted_count++;
                    }
                }
            }
        }

        if ( $deleted_count > 0 ) {
            return new WP_REST_Response(
                array(
                    'success' => true,
                    /* translators: %d: number of log files deleted. */
                    'message' => sprintf( _n( '%d log file cleared.', '%d log files cleared.', $deleted_count, 'campaignbay' ), $deleted_count ),
                ),
                200
            );
        } else {
            return new WP_REST_Response(
                array(
                    'success' => true,
                    'message' => __( 'No log files to clear.', 'campaignbay' ),
                ),
                200
            );
        }
    }
}