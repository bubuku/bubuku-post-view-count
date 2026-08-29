<?php
/**
 * ViewsDisplay Class.
 *
 * @package Bubuku Post View Count
 * @author     Luis Ruiz <lruiz@bubuku.com>
 * @copyright  2022 Bubuku
 * @version    1.3.0
 */

declare( strict_types=1 );

namespace Bubuku\Plugins\PostViewCount\Frontend;

use Bubuku\Plugins\PostViewCount\Admin\Settings;
use Bubuku\Plugins\PostViewCount\Core\Db;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the "X views" markup shared by the `[bbk_post_views]` shortcode
 * (Shortcode) and the Gutenberg block's `render_callback` (Block) — the
 * rendering logic lives here once, never duplicated between the two.
 */
class ViewsDisplay {

	/**
	 * @param int   $post_id Post ID.
	 * @param array $args    {
	 *     @type bool $show_last_viewed Whether to append the last-viewed date. Default false.
	 * }
	 * @return string Escaped HTML, or an empty string when the post doesn't count views.
	 */
	public static function render( int $post_id, array $args = array() ): string {
		if ( ! in_array( get_post_type( $post_id ), Settings::enabled_post_types(), true )
			|| ! is_post_publicly_viewable( $post_id ) ) {
			return '';
		}

		$show_last_viewed = ! empty( $args['show_last_viewed'] );
		$stats            = ( new Db() )->get_stats( $post_id );

		$markup = sprintf(
			/* translators: %s: formatted view count. */
			esc_html__( '%s views', 'bubuku-post-view-count' ),
			esc_html( number_format_i18n( $stats['views'] ) )
		);

		if ( $show_last_viewed && $stats['last_viewed_at'] ) {
			$timestamp = strtotime( $stats['last_viewed_at'] . ' UTC' );

			if ( false !== $timestamp ) {
				$markup .= ' &middot; ' . sprintf(
					/* translators: %s: formatted date/time of the last view. */
					esc_html__( 'last view: %s', 'bubuku-post-view-count' ),
					esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp ) )
				);
			}
		}

		return '<span class="bbk-post-views">' . $markup . '</span>';
	}
}
