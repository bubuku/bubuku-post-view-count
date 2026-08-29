<?php
/**
 * Plugin Class.
 *
 * @package Bubuku Post View Count
 * @author     Luis Ruiz <lruiz@bubuku.com>
 * @copyright  2022 Bubuku
 * @version    1.2.0
 */

declare( strict_types=1 );

namespace Bubuku\Plugins\PostViewCount\Core;

use Bubuku\Plugins\PostViewCount\Admin\SettingsPage;
use Bubuku\Plugins\PostViewCount\Api\RestApi;
use Bubuku\Plugins\PostViewCount\Frontend\Assets;

defined( 'ABSPATH' ) || exit;

class Plugin {

	/**
	 * @var Schema
	 */
	private $schema;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->schema = new Schema();
		add_action( 'plugins_loaded', array( $this, 'init' ) );
	}

	/**
	 * Initialize plugin
	 */
	public function init() {
		new Assets();
		new RestApi();

		if ( is_admin() ) {
			new SettingsPage();
		}
	}

	/**
	 * Runs on plugin activation.
	 *
	 * @param bool $network_wide Whether the plugin is being network-activated.
	 */
	public function activate( bool $network_wide = false ) {
		$this->schema->activate( $network_wide );
	}

	/**
	 * Runs on plugin deactivation.
	 */
	public function deactivate() {
		$this->schema->deactivate();
	}
}
