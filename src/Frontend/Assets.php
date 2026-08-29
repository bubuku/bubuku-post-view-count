<?php
/**
 * Assets Class.
 *
 * @package Bubuku Post View Count
 * @author     Luis Ruiz <lruiz@bubuku.com>
 * @copyright  2022 Bubuku
 * @version    1.1.0
 */

declare( strict_types=1 );

namespace Bubuku\Plugins\PostViewCount\Frontend;

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
		if ( is_admin() || ! is_singular( 'post' ) ) {
			return;
		}

		// Don't count views from users who can already edit content.
		if ( current_user_can( 'edit_posts' ) ) {
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
