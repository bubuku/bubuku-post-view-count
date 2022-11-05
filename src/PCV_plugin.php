<?php
/**
 * Plugin Class.
 *
 * @package Bubuku Post View Count
 * @author     Luis Ruiz <lruiz@bubuku.com>
 * @copyright  2022 Bubuku
 * @version    1.0.2
 */

namespace Bubuku\Plugins\PostViewCount;

defined( 'ABSPATH') || die( 'Hello, Pepiño!' );

class PCV_plugin {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'plugins_loaded', [ $this, 'init' ] );
	}

	/**
	 * Initialize plugin
	 */
	public function init() {
		define( 'BBK_PLUGIN_PATH', untrailingslashit( plugin_dir_path( __DIR__ ) ) );
		define( 'BBK_PLUGIN_URL', untrailingslashit( plugin_dir_url( __DIR__ ) ) );
		define( 'BBK_PLUGIN_BASE', untrailingslashit( plugin_dir_url( __FILE__ ) ) );
		define( 'BBK_PLUGIN_ASSETS_PATH', BBK_PLUGIN_PATH . '/assets' );
		define( 'BBK_PLUGIN_ASSETS_URL', BBK_PLUGIN_URL . '/assets' );
		define( 'BBK_PLUGIN_ENDPOINTS_URL', 'bbk_postview/v1' );
		define( 'BBK_PLUGIN_VERSION', '1.0.2' );
		define( 'BBK_PLUGIN_NONCE', wp_create_nonce('post-view'. BBK_PLUGIN_VERSION) );

		load_plugin_textdomain( 'bubuku-post-view-count', false, BBK_PLUGIN_BASE . '/languages/' );

        new PCV_assets();
		new PCV_restapi();
	}

    public function activate() {

    }

    public function deactivate() {

    }

}
