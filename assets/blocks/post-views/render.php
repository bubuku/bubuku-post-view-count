<?php
/**
 * Server-side render for the `bubuku/post-views` block.
 *
 * @package Bubuku Post View Count
 *
 * @var array $attributes Block attributes.
 */

use Bubuku\Plugins\PostViewCount\Frontend\ViewsDisplay;

defined( 'ABSPATH' ) || exit;

$bbk_post_id = get_the_ID();

if ( ! $bbk_post_id ) {
	return;
}

echo wp_kses_post(
	ViewsDisplay::render(
		$bbk_post_id,
		array( 'show_last_viewed' => ! empty( $attributes['showLastViewed'] ) )
	)
);
