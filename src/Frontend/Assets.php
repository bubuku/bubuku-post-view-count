<?php
/**
 * Assets Class.
 *
 * @package Bubuku Post View Count
 * @author     Luis Ruiz <lruiz@bubuku.com>
 * @copyright  2022 Bubuku
 * @version    1.2.0
 */

declare( strict_types=1 );

namespace Bubuku\Plugins\PostViewCount\Frontend;

use Bubuku\Plugins\PostViewCount\Admin\Settings;

defined( 'ABSPATH' ) || exit;

class Assets {

	public function __construct() {
		$this->init();
	}

	private function init() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_front_assets' ) );
	}

	/**
	 * Enqueue Front Scripts
	 */
	public function enqueue_front_assets() {
		if ( is_admin() || ! is_singular( Settings::enabled_post_types() ) ) {
			return;
		}

		// Don't count views from users belonging to an excluded role (default: anyone who can edit content).
		if ( Settings::is_current_user_excluded() ) {
			return;
		}

		$post_id = get_queried_object_id();

		if ( ! $post_id ) {
			return;
		}

		wp_enqueue_script(
			'bk-post-view-js',
			BBK_PLUGIN_ASSETS_URL . '/js/common.js',
			array(),
			BBK_PLUGIN_VERSION,
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);

		wp_localize_script(
			'bk-post-view-js',
			'bbk_post_view',
			array(
				'api_public' => rest_url( BBK_PLUGIN_ENDPOINTS_URL ),
				'post_id'    => $post_id,
			)
		);
	}
}
