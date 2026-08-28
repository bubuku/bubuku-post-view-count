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
		new PCV_assets();
		new PCV_restapi();
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
