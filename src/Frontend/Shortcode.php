<?php
/**
 * Shortcode Class.
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
 * Registers `[bbk_post_views]`. All rendering lives in ViewsDisplay, shared
 * with the Gutenberg block (Block) — this class only wires the shortcode API.
 */
class Shortcode {

	const TAG = 'bbk_post_views';

	public function __construct() {
		add_shortcode( self::TAG, array( $this, 'render' ) );
	}

	/**
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render( $atts ): string {
		$atts = shortcode_atts(
			array(
				'post_id'          => get_the_ID(),
				'show_last_viewed' => '0',
			),
			$atts,
			self::TAG
		);

		$post_id = absint( $atts['post_id'] );

		if ( ! $post_id ) {
			return '';
		}

		return ViewsDisplay::render(
			$post_id,
			array( 'show_last_viewed' => '1' === (string) $atts['show_last_viewed'] )
		);
	}
}
