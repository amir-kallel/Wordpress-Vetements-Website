<?php
/**
 * Class use to register REST API endpoints used by the WAF
 *
 * @package automattic/jetpack-waf
 */

namespace Automattic\Jetpack\Waf;

use Automattic\Jetpack\Connection\REST_Connector;
use Automattic\Jetpack\Waf\Brute_Force_Protection\Brute_Force_Protection;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Defines our endponts.
 */
class REST_Controller {
	/**
	 * Register REST API endpoints.
	 *
	 * @return void
	 */
	public static function register_rest_routes() {
		// Ensure routes are only initialized once.
		static $routes_registered = false;
		if ( $routes_registered ) {
			return;
		}

		register_rest_route(
			'jetpack/v4',
			'/waf',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => __CLASS__ . '::waf',
				'permission_callback' => __CLASS__ . '::waf_permissions_callback',
			)
		);

		register_rest_route(
			'jetpack/v4',
			'/waf',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => __CLASS__ . '::update_waf',
				'permission_callback' => __CLASS__ . '::waf_permissions_callback',
			)
		);

		register_rest_route(
			'jetpack/v4',
			'/waf/update-rules',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => __CLASS__ . '::update_rules',
				'permission_callback' => __CLASS__ . '::waf_permissions_callback',
			)
		);

		$routes_registered = true;
	}

	/**
	 * Update rules endpoint
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_rules() {
		try {
			Waf_Rules_Manager::generate_automatic_rules();
			Waf_Rules_Manager::generate_rules();
		} catch ( Waf_Exception $e ) {
			return $e->get_wp_error();
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'message' => __( 'Rules updated succesfully', 'jetpack-waf' ),
			)
		);
	}

	/**
	 * WAF Endpoint
	 *
	 * @return WP_REST_Response
	 */
	public static function waf() {
		return rest_ensure_response(
			array_merge(
				Waf_Runner::get_config(),
				array(
					'waf_supported'                => Waf_Runner::is_supported_environment(),
					'automatic_rules_last_updated' => Waf_Stats::get_automatic_rules_last_updated(),
				)
			)
		);
	}

	/**
	 * Update WAF Endpoint
	 *
	 * @param WP_REST_Request $request The API request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_waf( $request ) {
		// Automatic Rules Enabled
		if ( isset( $request[ Waf_Rules_Manager::AUTOMATIC_RULES_ENABLED_OPTION_NAME ] ) ) {
			update_option( Waf_Rules_Manager::AUTOMATIC_RULES_ENABLED_OPTION_NAME, $request->get_param( Waf_Rules_Manager::AUTOMATIC_RULES_ENABLED_OPTION_NAME ) ? '1' : '' );
		}

		/**
		 * IP Lists Enabled
		 *
		 * @deprecated 0.17.0 This is a legacy option maintained here for backwards compatibility.
		 */
		if ( isset( $request['jetpack_waf_ip_list'] ) ) {
			update_option( Waf_Rules_Manager::IP_BLOCK_LIST_ENABLED_OPTION_NAME, $request['jetpack_waf_ip_list'] ? '1' : '' );
			update_option( Waf_Rules_Manager::IP_ALLOW_LIST_ENABLED_OPTION_NAME, $request['jetpack_waf_ip_list'] ? '1' : '' );
		}

		// IP Block List
		if ( isset( $request[ Waf_Rules_Manager::IP_BLOCK_LIST_OPTION_NAME ] ) ) {
			update_option( Waf_Rules_Manager::IP_BLOCK_LIST_OPTION_NAME, $request[ Waf_Rules_Manager::IP_BLOCK_LIST_OPTION_NAME ] );
		}
		if ( isset( $request[ Waf_Rules_Manager::IP_BLOCK_LIST_ENABLED_OPTION_NAME ] ) ) {
			update_option( Waf_Rules_Manager::IP_BLOCK_LIST_ENABLED_OPTION_NAME, $request[ Waf_Rules_Manager::IP_BLOCK_LIST_ENABLED_OPTION_NAME ] ? '1' : '' );
		}

		// IP Allow List
		if ( isset( $request[ Waf_Rules_Manager::IP_ALLOW_LIST_OPTION_NAME ] ) ) {
			update_option( Waf_Rules_Manager::IP_ALLOW_LIST_OPTION_NAME, $request[ Waf_Rules_Manager::IP_ALLOW_LIST_OPTION_NAME ] );
		}
		if ( isset( $request[ Waf_Rules_Manager::IP_ALLOW_LIST_ENABLED_OPTION_NAME ] ) ) {
			update_option( Waf_Rules_Manager::IP_ALLOW_LIST_ENABLED_OPTION_NAME, $request[ Waf_Rules_Manager::IP_ALLOW_LIST_ENABLED_OPTION_NAME ] ? '1' : '' );
		}

		// Share Data
		if ( isset( $request[ Waf_Runner::SHARE_DATA_OPTION_NAME ] ) ) {
			// If a user disabled the regular share we should disable the debug share data option.
			if ( ! $request[ Waf_Runner::SHARE_DATA_OPTION_NAME ] ) {
				update_option( Waf_Runner::SHARE_DEBUG_DATA_OPTION_NAME, '' );
			}

			update_option( Waf_Runner::SHARE_DATA_OPTION_NAME, $request[ Waf_Runner::SHARE_DATA_OPTION_NAME ] ? '1' : '' );
		}

		// Share Debug Data
		if ( isset( $request[ Waf_Runner::SHARE_DEBUG_DATA_OPTION_NAME ] ) ) {
			// If a user toggles the debug share we should enable the regular share data option.
			if ( $request[ Waf_Runner::SHARE_DEBUG_DATA_OPTION_NAME ] ) {
				update_option( Waf_Runner::SHARE_DATA_OPTION_NAME, 1 );
			}

			update_option( Waf_Runner::SHARE_DEBUG_DATA_OPTION_NAME, $request[ Waf_Runner::SHARE_DEBUG_DATA_OPTION_NAME ] ? '1' : '' );
		}

		// Brute Force Protection
		if ( isset( $request['brute_force_protection'] ) ) {
			$enable_brute_force             = (bool) $request['brute_force_protection'];
			$brute_force_protection_toggled =
				$enable_brute_force
					? Brute_Force_Protection::enable()
					: Brute_Force_Protection::disable();

			if ( ! $brute_force_protection_toggled ) {
				return new WP_Error(
					$enable_brute_force
						? 'brute_force_protection_activation_failed'
						: 'brute_force_protection_deactivation_failed',
					$enable_brute_force
						? __( 'Brute force protection could not be activated.', 'jetpack-waf' )
						: __( 'Brute force protection could not be deactivated.', 'jetpack-waf' ),
					array( 'status' => 500 )
				);
			}
		}

		// Only attempt to update the WAF if the module is supported
		if ( Waf_Runner::is_supported_environment() ) {
			try {
				Waf_Runner::update_waf();
			} catch ( Waf_Exception $e ) {
				return $e->get_wp_error();
			}
		}

		return self::waf();
	}

	/**
	 * WAF Endpoint Permissions Callback
	 *
	 * @return bool|WP_Error True if user can view the Jetpack admin page.
	 */
	public static function waf_permissions_callback() {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		return new WP_Error(
			'invalid_user_permission_manage_options',
			REST_Connector::get_user_permissions_error_msg(),
			array( 'status' => rest_authorization_required_code() )
		);
	}
}
