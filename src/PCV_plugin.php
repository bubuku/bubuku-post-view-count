<?php
/**
 * Plugin Class.
 *
 * @package Bubuku Post View Count
 * @author     Luis Ruiz <lruiz@bubuku.com>
 * @copyright  2022 Bubuku
 * @version    1.1.0
 */

declare( strict_types=1 );

namespace Bubuku\Plugins\PostViewCount;

defined( 'ABSPATH' ) || exit;

class PCV_plugin {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'plugins_loaded', array( $this, 'init' ) );
	}

	/**
	 * Initialize plugin
	 */
	public function init() {
		add_action( 'init', array( $this, 'load_textdomain' ) );

		new PCV_assets();
		new PCV_restapi();
	}

	/**
	 * Load plugin translations.
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			'bubuku-post-view-count',
			false,
			dirname( plugin_basename( BBK_PLUGIN_FILE ) ) . '/languages'
		);
	}

	/**
	 * Runs on plugin activation.
	 */
	public function activate() {
	}

	/**
	 * Runs on plugin deactivation.
	 */
	public function deactivate() {
	}
}
