<?php
/**
 * Db Class.
 *
 * @package Bubuku Post View Count
 * @author     Luis Ruiz <lruiz@bubuku.com>
 * @copyright  2022 Bubuku
 * @version    1.2.0
 */

declare( strict_types=1 );

namespace Bubuku\Plugins\PostViewCount\Core;

defined( 'ABSPATH' ) || exit;

class Db {

	/**
	 * Record a view: two atomic upserts (aggregate + daily), then mirror the
	 * running total into post meta for backwards compatibility.
	 *
	 * @param int $post_id Post ID.
	 * @return array{views:int,first_viewed_at:?string,last_viewed_at:?string}
	 */
	public function record_view( int $post_id ): array {
		global $wpdb;

		$now = current_time( 'mysql', true );
		$day = substr( $now, 0, 10 );

		$views_table = Schema::table_views();
		$daily_table = Schema::table_daily();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Atomic upsert on the plugin's own view-counter table, no equivalent WP API; a running counter must never be served from cache. $views_table is an internal constant (Schema::table_views()), never user input.
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$views_table} is an internal constant (Schema::table_views()), never user input.
				"INSERT INTO {$views_table} (post_id, views, first_viewed_at, last_viewed_at) VALUES (%d, 1, %s, %s) ON DUPLICATE KEY UPDATE views = views + 1, last_viewed_at = VALUES(last_viewed_at)",
				$post_id,
				$now,
				$now
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Atomic upsert on the plugin's own daily-counter table, no equivalent WP API; a running counter must never be served from cache. $daily_table is an internal constant (Schema::table_daily()), never user input.
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$daily_table} is an internal constant (Schema::table_daily()), never user input.
				"INSERT INTO {$daily_table} (post_id, day, views) VALUES (%d, %s, 1) ON DUPLICATE KEY UPDATE views = views + 1",
				$post_id,
				$day
			)
		);

		$stats = $this->get_stats( $post_id );

		$this->mirror_post_meta( $post_id, $stats );

		return $stats;
	}

	/**
	 * Thin alias of record_view() for backwards compatibility with existing
	 * consumers that only need the running total.
	 *
	 * @param int $post_id Post ID.
	 * @return int
	 */
	public function set_post_views( int $post_id ): int {
		return $this->record_view( $post_id )['views'];
	}

	/**
	 * Read the current stats for a post from the aggregate table.
	 *
	 * @param int $post_id Post ID.
	 * @return array{views:int,first_viewed_at:?string,last_viewed_at:?string}
	 */
	public function get_stats( int $post_id ): array {
		global $wpdb;

		$views_table = Schema::table_views();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Reads the current counter from the plugin's own table, no equivalent WP API; a running counter must never be served from cache. $views_table is an internal constant (Schema::table_views()), never user input.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$views_table} is an internal constant (Schema::table_views()), never user input.
				"SELECT views, first_viewed_at, last_viewed_at FROM {$views_table} WHERE post_id = %d",
				$post_id
			),
			ARRAY_A
		);

		return array(
			'views'           => $row ? (int) $row['views'] : 0,
			'first_viewed_at' => $row['first_viewed_at'] ?? null,
			'last_viewed_at'  => $row['last_viewed_at'] ?? null,
		);
	}

	/**
	 * Mirror the running total and last-view date into post meta. The table is
	 * the source of truth; the meta is a convenience copy for themes/queries
	 * that already read `views` (a public, already-shipped contract).
	 *
	 * @param int   $post_id Post ID.
	 * @param array $stats   Stats as returned by get_stats()/record_view().
	 * @return void
	 */
	private function mirror_post_meta( int $post_id, array $stats ) {
		if ( ! apply_filters( 'bbk_postview_mirror_meta', true ) ) {
			return;
		}

		update_post_meta( $post_id, 'views', $stats['views'] );
		update_post_meta( $post_id, 'views_last', $stats['last_viewed_at'] );
		wp_cache_delete( $post_id, 'post_meta' );
	}

	/**
	 * Remove all "views"/"views_last" post meta from the current site.
	 *
	 * @return void
	 */
	public function remove_all_post_meta() {
		delete_post_meta_by_key( 'views' );
		delete_post_meta_by_key( 'views_last' );
	}

	/**
	 * Drop the plugin's own tables on the current site.
	 *
	 * @return void
	 */
	public function drop_tables() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Uninstall-only table drop; DDL can't use placeholders for identifiers, and the name is an internal constant (Schema::table_daily()), never user input.
		$wpdb->query( 'DROP TABLE IF EXISTS ' . Schema::table_daily() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Uninstall-only table drop; DDL can't use placeholders for identifiers, and the name is an internal constant (Schema::table_views()), never user input.
		$wpdb->query( 'DROP TABLE IF EXISTS ' . Schema::table_views() );
	}
}
