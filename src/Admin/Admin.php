<?php
/**
 * Admin Class.
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
 * Orchestrates the admin settings page (menu registration, asset enqueue).
 * The UI itself is the React app in assets/src/js/admin — see
 * docs/PENDING-ADMIN-UI-REACT.md.
 */
class Admin {

	/**
	 * @var string
	 */
	private $plugin_name;

	/**
	 * @var string
	 */
	private $version;

	public function __construct( string $plugin_name, string $version ) {
		$this->plugin_name = $plugin_name;
		$this->version     = $version;
	}

	/**
	 * @return void
	 */
	public function register() {
		$page = new AdminPage( $this->plugin_name, $this->version );

		add_action( 'admin_menu', array( $page, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $page, 'enqueue_assets' ) );
	}
}
