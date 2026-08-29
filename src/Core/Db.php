<?php
/**
 * Db Class.
 *
 * @package Bubuku Post View Count
 * @author     Luis Ruiz <lruiz@bubuku.com>
 * @copyright  2022 Bubuku
 * @version    1.1.1
 */

declare( strict_types=1 );

namespace Bubuku\Plugins\PostViewCount\Core;

defined( 'ABSPATH' ) || exit;

class Db {

	/**
	 * Increment (atomically) the "views" meta of a post and return the new total.
	 *
	 * @param int $post_id Post ID.
	 * @return int
	 */
	public function set_post_views( int $post_id ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- A direct query is required for an atomic increment.
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->postmeta} SET meta_value = meta_value + 1 WHERE post_id = %d AND meta_key = 'views'",
				$post_id
			)
		);

		if ( ! $updated ) {
			add_post_meta( $post_id, 'views', 1, true );
		}

		wp_cache_delete( $post_id, 'post_meta' );

		return (int) get_post_meta( $post_id, 'views', true );
	}

	/**
	 * Remove all "views" post meta from the current site.
	 *
	 * @return void
	 */
	public function remove_all_post_meta() {
		delete_post_meta_by_key( 'views' );
	}
}
