<?php
/**
 * Block Class.
 *
 * @package Bubuku Post View Count
 * @author     Luis Ruiz <lruiz@bubuku.com>
 * @copyright  2022 Bubuku
 * @version    1.3.0
 */

declare( strict_types=1 );

namespace Bubuku\Plugins\PostViewCount\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the `bubuku/post-views` block from `assets/blocks/post-views/`.
 * Deliberately no build step (see AGENTS.md): the editor script is hand-written
 * plain JS (no JSX) and the frontend/editor render both go through render.php,
 * which delegates to ViewsDisplay — the same renderer used by Shortcode.
 */
class Block {

	public function __construct() {
		add_action( 'init', array( $this, 'register' ) );
	}

	/**
	 * @return void
	 */
	public function register() {
		register_block_type( BBK_PLUGIN_ASSETS_PATH . '/blocks/post-views' );
	}
}
