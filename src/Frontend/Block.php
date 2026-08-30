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
 * Registers the `bubuku/post-views` block from the compiled
 * `assets/build/blocks/post-views/` (built from `assets/src/blocks/post-views/`
 * — see docs/PENDING-ADMIN-UI-REACT.md Fase 7). The frontend/editor render both
 * go through render.php, which delegates to ViewsDisplay — the same renderer
 * used by Shortcode.
 */
class Block {

	public function __construct() {
		add_action( 'init', array( $this, 'register' ) );
	}

	/**
	 * @return void
	 */
	public function register() {
		$path = BBK_PLUGIN_PATH . '/assets/build/blocks/post-views';

		if ( ! file_exists( $path . '/block.json' ) ) {
			return;
		}

		register_block_type( $path );
	}
}
