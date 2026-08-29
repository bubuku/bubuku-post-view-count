<?php
/**
 * Plugin Class.
 *
 * @package Bubuku Post View Count
 * @author     Luis Ruiz <lruiz@bubuku.com>
 * @copyright  2022 Bubuku
 * @version    1.1.1
 */

declare( strict_types=1 );

namespace Bubuku\Plugins\PostViewCount\Core;

use Bubuku\Plugins\PostViewCount\Api\RestApi;
use Bubuku\Plugins\PostViewCount\Frontend\Assets;

defined( 'ABSPATH' ) || exit;

class Plugin {

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
		new Assets();
		new RestApi();
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
