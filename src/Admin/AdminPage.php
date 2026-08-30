<?php
/**
 * AdminPage Class.
 *
 * @package Bubuku Post View Count
 * @author     Luis Ruiz <lruiz@bubuku.com>
 * @copyright  2022 Bubuku
 * @version    1.3.0
 */

declare( strict_types=1 );

namespace Bubuku\Plugins\PostViewCount\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Settings > Post View Count admin page. Renders only the React mount point —
 * the UI is the app built from assets/src/js/admin (see wp-frontend skill /
 * docs/PENDING-ADMIN-UI-REACT.md).
 */
class AdminPage {

	/**
	 * @var string
	 */
	private $plugin_name;

	/**
	 * @var string
	 */
	private $version;

	/**
	 * WordPress menu hook suffix — set by register_menu(), used in enqueue_assets().
	 *
	 * @var string
	 */
	private $hook_suffix = '';

	public function __construct( string $plugin_name, string $version ) {
		$this->plugin_name = $plugin_name;
		$this->version     = $version;
	}

	/**
	 * Registers the submenu page under Settings.
	 *
	 * @return void
	 */
	public function register_menu() {
		$this->hook_suffix = (string) add_options_page(
			__( 'Post View Count', 'bubuku-post-view-count' ),
			__( 'Post View Count', 'bubuku-post-view-count' ),
			'manage_options',
			$this->plugin_name,
			array( $this, 'render' )
		);
	}

	/**
	 * Outputs only the React mount point — all UI is handled by the app.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permisos para acceder a esta página.', 'bubuku-post-view-count' ) );
		}

		echo '<div id="bbk-postview-app"></div>';
	}

	/**
	 * Enqueues the compiled admin bundle, only on this settings page.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( string $hook ) {
		if ( $hook !== $this->hook_suffix ) {
			return;
		}

		$asset_file = BBK_PLUGIN_PATH . '/assets/build/admin.asset.php';

		if ( ! file_exists( $asset_file ) ) {
			$this->show_build_notice();
			return;
		}

		$asset = include $asset_file;

		wp_enqueue_style(
			$this->plugin_name . '-admin',
			BBK_PLUGIN_URL . '/assets/build/style-admin.css',
			array(),
			$asset['version']
		);

		wp_enqueue_script(
			$this->plugin_name . '-admin',
			BBK_PLUGIN_URL . '/assets/build/admin.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_set_script_translations( $this->plugin_name . '-admin', 'bubuku-post-view-count' );

		wp_add_inline_script(
			$this->plugin_name . '-admin',
			'const BbkPostViewCount = ' . wp_json_encode(
				array(
					'api_url'    => rest_url( BBK_PLUGIN_ENDPOINTS_URL ),
					'rest_nonce' => wp_create_nonce( 'wp_rest' ),
				)
			),
			'before'
		);
	}

	/**
	 * Shown when the settings page is opened before `npm run build` has run —
	 * helpful during development, never expected in a released zip.
	 *
	 * @return void
	 */
	private function show_build_notice() {
		echo '<div class="notice notice-error"><p>';
		printf(
			/* translators: %s: npm command */
			esc_html__( 'Los assets del plugin no están compilados. Ejecuta %s primero.', 'bubuku-post-view-count' ),
			'<code>npm run build</code>'
		);
		echo '</p></div>';
	}
}
