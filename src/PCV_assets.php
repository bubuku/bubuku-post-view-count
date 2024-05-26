<?php
/**
 * Assets Class.
 *
 * @package Bubuku Post View Count
 * @author     Luis Ruiz <lruiz@bubuku.com>
 * @copyright  2022 Bubuku
 * @version    1.0.4
 */

namespace Bubuku\Plugins\PostViewCount;

defined( 'ABSPATH') || die( 'Hello, Pepiño!' );

class PCV_assets {

    public function __construct() {
		$this->init();
	}

	private function init() {
		add_action( 'enqueue_block_assets', [ $this, 'enqueue_front_assets' ] );
	}

    /**
	 * Enqueue Front Scripts
	 */
    public function enqueue_front_assets() {
        global $post;

        if ( !is_admin() && is_singular('post') ) {
            $js_dependencies = ['jquery'];
            
            wp_enqueue_script(
                'bk-post-view-js',
                BBK_PLUGIN_ASSETS_URL . '/js/common.js',
                $js_dependencies,
                true
            );

            $args = array(
                'nonce'         => BBK_PLUGIN_NONCE,
                'api_public'    => '/wp-json/'. BBK_PLUGIN_ENDPOINTS_URL,
                'post_id'       => $post->ID
            );
            wp_localize_script( 'bk-post-view-js', 'bbk_post_view', $args );
        }
    }
}