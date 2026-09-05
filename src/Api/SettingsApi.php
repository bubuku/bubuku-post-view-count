<?php
/**
 * SettingsApi Class.
 *
 * @package Bubuku Post View Count
 * @author     Luis Ruiz <lruiz@bubuku.com>
 * @copyright  2022 Bubuku
 * @version    1.3.0
 */

declare( strict_types=1 );

namespace Bubuku\Plugins\PostViewCount\Api;

use Bubuku\Plugins\PostViewCount\Admin\Settings;
use Bubuku\Plugins\PostViewCount\Core\Db;
use Bubuku\Plugins\PostViewCount\Core\Query;
use Bubuku\Plugins\PostViewCount\Core\Schema;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * REST backend for the React admin settings page (docs/PENDING-ADMIN-UI-REACT.md
 * Fase 2). Separate from `Api\RestApi` on purpose, same precedent as `Api\TrendsApi`
 * (see docs/ARCHITECTURE.md): `Api\RestApi` is the public, anonymous view counter
 * with its own same-origin/dedupe security model — this is a capability-gated
 * admin surface, a different concern that must not be mixed into it.
 */
class SettingsApi {

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			BBK_PLUGIN_ENDPOINTS_URL,
			'settings',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_settings' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			BBK_PLUGIN_ENDPOINTS_URL,
			'settings',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'update_settings' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			BBK_PLUGIN_ENDPOINTS_URL,
			'settings/data',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'delete_data' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
	}

	/**
	 * Only site admins can read or change these settings.
	 *
	 * @return bool
	 */
	public function check_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Current settings plus the read-only context the React form needs to
	 * render itself — see docs/PENDING-ADMIN-UI-REACT.md Fase 2.
	 *
	 * @return WP_REST_Response
	 */
	public function get_settings(): WP_REST_Response {
		$post_types = array();

		foreach ( Settings::selectable_post_types() as $post_type ) {
			$post_types[ $post_type->name ] = $post_type->labels->name;
		}

		$roles = array();

		foreach ( Settings::selectable_roles() as $slug => $role ) {
			$roles[ $slug ] = translate_user_role( $role['name'] );
		}

		return new WP_REST_Response(
			array_merge(
				Settings::get_all(),
				array(
					'available_post_types'   => $post_types,
					'available_roles'        => $roles,
					'bot_signature_examples' => Settings::bot_signature_examples(),
					'has_object_cache'       => wp_using_ext_object_cache(),
					'daily_data_since'       => Schema::daily_data_since(),
				)
			),
			200
		);
	}

	/**
	 * Saves the submitted settings, reusing `Settings::sanitize()` — the same
	 * sanitizer the (now removed) Settings API used.
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_REST_Response
	 */
	public function update_settings( WP_REST_Request $request ): WP_REST_Response {
		$sanitized = Settings::sanitize( $request->get_json_params() );

		update_option( Settings::OPTION_KEY, $sanitized );
		Query::invalidate_cache();

		return new WP_REST_Response( $sanitized, 200 );
	}

	/**
	 * "Eliminar todos los datos ahora" — drops and recreates the tables and
	 * removes the mirrored post meta, without uninstalling the plugin. Same
	 * flow as the removed `SettingsPage::handle_reset_data()`, now reachable
	 * over REST instead of `admin-post.php`.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_data() {
		update_option( Schema::OPTION_INGESTION_PAUSED, true, false );

		try {
			$db = new Db();
			$db->drop_tables();
			$db->remove_all_post_meta();

			delete_option( Schema::OPTION_SCHEMA_VERSION );
			delete_option( Schema::OPTION_DAILY_SINCE );
			delete_option( Schema::OPTION_MIGRATION_CURSOR );
			delete_option( Schema::OPTION_MIGRATION_STATUS );
			delete_option( 'bbk_postview_buffer_index' );

			// Recreate the empty schema and its collection-start metadata.
			( new Schema() )->activate( false );
			if ( (int) get_option( Schema::OPTION_SCHEMA_VERSION, 0 ) !== Schema::VERSION ) {
				return new WP_Error( 'bbk_postview_reset_failed', __( 'The analytics tables could not be recreated.', 'bubuku-post-view-count' ), array( 'status' => 500 ) );
			}
			Query::invalidate_cache();
		} finally {
			delete_option( Schema::OPTION_INGESTION_PAUSED );
		}

		return new WP_REST_Response( array( 'deleted' => true ), 200 );
	}
}
