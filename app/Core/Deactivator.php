<?php // phpcs:ignore Class file names should be based on the class name with "class-" prepended.

namespace WpabCb\Core;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fired during plugin deactivation
 *
 *
 * @since      1.0.0
 *
 * @package    WPAB_CampaignBay
 * @subpackage WPAB_CampaignBayincludes
 */

/**
 * Fired during plugin deactivation.
 *
 * This class defines all code necessary to run during the plugin's deactivation.
 *
 * @since      1.0.0
 * @package    WPAB_CampaignBay
 * @subpackage WPAB_CampaignBayincludes
 * @author     dipta-sdd <sankarsandipta@gmail.com>
 */
class Deactivator {

	/**
	 * Fired during plugin deactivation.
	 *
	 * Removing options and all data related to plugin if user select remove data on deactivate.
	 *
	 * @since 1.0.0
	 * @access public
	 * @return void
	 */
	public static function deactivate() {
		self::remove_custom_capabilities();
	}

	/**
	 * Removes the custom plugin capabilities from all roles.
	 *
	 * This is a cleanup best practice to ensure no orphaned capabilities are
	 * left in the database after the plugin is deactivated.
	 *
	 * @since 1.0.0
	 * @access private
	 * @return void
	 */
	private static function remove_custom_capabilities() {
		// Get all editable roles.
		$roles = get_editable_roles();
		
		$custom_capability = 'manage_campaignbay';

		// Loop through all roles and remove our capability if it exists.
		foreach ( $roles as $role_name => $role_info ) {
			$role = get_role( $role_name );
			if ( $role && $role->has_cap( $custom_capability ) ) {
				$role->remove_cap( $custom_capability );
			}
		}
	}
} 
