<?php
/**
 * Plugin Name: Bubuku Post View Count
 * Description: Plugin created by Bubuku to count how many times a post has been viewed
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Version:     1.2.0
 * Author:      Bubuku
 * Author URI:  https://www.bubuku.com/
 * Text Domain: bubuku-post-view-count
 * License:     EUPL v1.2
 * License URI: https://www.eupl.eu/1.2/en/
 *
 * @package     WordPress
 * @author      Bubuku
 * @copyright   2022 Bubuku
 * @license     GPL-2.0+
 *
 * @wordpress-plugin
 *
 * Prefix:      bbk
 */

declare( strict_types=1 );

// Detects if the plugin has been entered directly.
defined( 'ABSPATH' ) || exit;

define( 'BBK_PLUGIN_FILE', __FILE__ );
define( 'BBK_PLUGIN_PATH', untrailingslashit( plugin_dir_path( __FILE__ ) ) );
define( 'BBK_PLUGIN_URL', untrailingslashit( plugin_dir_url( __FILE__ ) ) );
define( 'BBK_PLUGIN_ASSETS_PATH', BBK_PLUGIN_PATH . '/assets' );
define( 'BBK_PLUGIN_ASSETS_URL', BBK_PLUGIN_URL . '/assets' );
define( 'BBK_PLUGIN_ENDPOINTS_URL', 'bbk_postview/v1' );

$bbk_plugin_data = get_file_data( __FILE__, array( 'Version' => 'Version' ) );
define( 'BBK_PLUGIN_VERSION', $bbk_plugin_data['Version'] );
unset( $bbk_plugin_data );

/**
 * PSR-4 autoloader for the Bubuku\Plugins\PostViewCount\ namespace.
 *
 * The plugin has no runtime Composer dependencies, so a hand-rolled
 * autoloader avoids shipping vendor/ in the distributed package.
 * Composer is still used for dev tooling only (see composer.json).
 *
 * @param string $class_name Fully qualified class name.
 * @return void
 */
function bbk_autoload( $class_name ) {
	$prefix = 'Bubuku\\Plugins\\PostViewCount\\';

	if ( 0 !== strpos( $class_name, $prefix ) ) {
		return;
	}

	$relative_class = str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) );
	$file           = BBK_PLUGIN_PATH . '/src/' . $relative_class . '.php';

	if ( file_exists( $file ) ) {
		require $file;
	}
}
spl_autoload_register( 'bbk_autoload' );

/**
 * Bootstrap the plugin.
 */
( static function () {
	if ( ! class_exists( 'Bubuku\Plugins\PostViewCount\Core\Plugin' ) ) {
		return;
	}

	$plugin = new Bubuku\Plugins\PostViewCount\Core\Plugin();

	register_activation_hook( __FILE__, array( $plugin, 'activate' ) );
	register_deactivation_hook( __FILE__, array( $plugin, 'deactivate' ) );
} )();
