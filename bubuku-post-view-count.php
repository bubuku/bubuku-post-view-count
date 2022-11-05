<?php
/**
 * Plugin Name: Bubuku Post View Count
 * Description: Plugin created by Bubuku to count how many times a post has been viewed
 * Requires at least: 5.2
 * Requires PHP:      7.2
 * Version:     1.0.2
 * Author:      Bubuku
 * Author URI:  https://www.bubuku.com/
 * Text Domain: bubuku-post-view-count
 * Domain Path: /languages
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

// Detects if the plugin has been entered directly.
defined( 'ABSPATH') || die( 'Hello, Pepiño!' );

/**
 * Bootstrap the plugin.
 */
require_once 'vendor/autoload.php';

use Bubuku\Plugins\PostViewCount\PCV_plugin;

if ( class_exists( 'Bubuku\Plugins\PostViewCount\PCV_plugin' ) ) {
	$the_plugin = new PCV_plugin();
}

register_activation_hook( __FILE__, [ $the_plugin, 'activate' ] );
register_deactivation_hook( __FILE__, [ $the_plugin, 'deactivate' ] );