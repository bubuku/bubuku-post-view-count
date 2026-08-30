<?php
/**
 * Db Class.
 *
 * @package Bubuku Post View Count
 * @author     Luis Ruiz <lruiz@bubuku.com>
 * @copyright  2022 Bubuku
 * @version    1.2.1
 */

declare( strict_types=1 );

namespace Bubuku\Plugins\PostViewCount\Core;

defined( 'ABSPATH' ) || exit;

class Db {

	/**
	 * Record a view: two atomic upserts (aggregate + daily), an optional
	 * upsert per session dimension (F5), then mirror the running total into
	 * post meta for backwards compatibility.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $dims    Already-whitelisted dimension => value pairs, e.g.
	 *                       ['viewport' => '576-991', 'referrer' => 'search'].
	 *                       Empty by default; the caller (RestApi) is
	 *                       responsible for validating against Dimensions
	 *                       before calling this method.
	 * @return array{views:int,first_viewed_at:?string,last_viewed_at:?string}
	 */
	public function record_view( int $post_id, array $dims = array() ): array {
		$now = current_time( 'mysql', true );

		$grouped = array();

		foreach ( $dims as $dimension => $value ) {
			if ( '' !== $value ) {
				$grouped[ $dimension ] = array( $value => 1 );
			}
		}

		return $this->upsert_views( $post_id, $now, substr( $now, 0, 10 ), 1, $grouped );
	}

	/**
	 * Record several views for a post/day in one batch — used by the write
	 * buffer (F7, `Core\WriteBuffer::flush()`) to coalesce many increments
	 * accumulated in the object cache into a single upsert per table, instead
	 * of one per view. `$now` (first/last viewed timestamp) reflects the
	 * flush time rather than each individual view's timestamp — an accepted
	 * trade-off of buffering, bounded by the flush interval.
	 *
	 * @param int   $post_id Post ID.
	 * @param string $day    UTC day (Y-m-d) the buffered views belong to.
	 * @param int   $count   Total number of views to add (>= 1).
	 * @param array $dims    dimension => [ value => count ] pairs, already
	 *                       whitelisted by the caller.
	 * @return array{views:int,first_viewed_at:?string,last_viewed_at:?string}
	 */
	public function record_view_bulk( int $post_id, string $day, int $count, array $dims = array() ): array {
		return $this->upsert_views( $post_id, current_time( 'mysql', true ), $day, $count, $dims );
	}

	/**
	 * Shared upsert logic behind record_view() and record_view_bulk(): three
	 * atomic `INSERT ... ON DUPLICATE KEY UPDATE` (aggregate, daily, one per
	 * dimension value), then the post meta mirror. `views = views +
	 * VALUES(views)` (rather than a literal increment) is what lets the same
	 * query shape serve both a single view (count = 1) and a batched flush
	 * (count = N) without a second SQL template.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $now     UTC datetime to store as first/last viewed.
	 * @param string $day     UTC day (Y-m-d) for the daily/dims tables.
	 * @param int    $count   Views to add.
	 * @param array  $dims    dimension => [ value => count ] pairs.
	 * @return array{views:int,first_viewed_at:?string,last_viewed_at:?string}
	 */
	private function upsert_views( int $post_id, string $now, string $day, int $count, array $dims ): array {
		global $wpdb;

		$views_table = Schema::table_views();
		$daily_table = Schema::table_daily();
		$dims_table  = Schema::table_dims();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Atomic upsert on the plugin's own view-counter table, no equivalent WP API; a running counter must never be served from cache. $views_table is an internal constant (Schema::table_views()), never user input.
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$views_table} is an internal constant (Schema::table_views()), never user input.
				"INSERT INTO {$views_table} (post_id, views, first_viewed_at, last_viewed_at) VALUES (%d, %d, %s, %s) ON DUPLICATE KEY UPDATE views = views + VALUES(views), last_viewed_at = VALUES(last_viewed_at)",
				$post_id,
				$count,
				$now,
				$now
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Atomic upsert on the plugin's own daily-counter table, no equivalent WP API; a running counter must never be served from cache. $daily_table is an internal constant (Schema::table_daily()), never user input.
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$daily_table} is an internal constant (Schema::table_daily()), never user input.
				"INSERT INTO {$daily_table} (post_id, day, views) VALUES (%d, %s, %d) ON DUPLICATE KEY UPDATE views = views + VALUES(views)",
				$post_id,
				$day,
				$count
			)
		);

		foreach ( $dims as $dimension => $values ) {
			foreach ( $values as $value => $value_count ) {
				if ( '' === $value || $value_count <= 0 ) {
					continue;
				}

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Atomic upsert on the plugin's own session-dimensions table, no equivalent WP API; a running counter must never be served from cache. $dims_table is an internal constant (Schema::table_dims()), never user input.
				$wpdb->query(
					$wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$dims_table} is an internal constant (Schema::table_dims()), never user input.
						"INSERT INTO {$dims_table} (post_id, day, dimension, value, views) VALUES (%d, %s, %s, %s, %d) ON DUPLICATE KEY UPDATE views = views + VALUES(views)",
						$post_id,
						$day,
						$dimension,
						$value,
						$value_count
					)
				);
			}
		}

		$stats = $this->get_stats( $post_id );

		$this->mirror_post_meta( $post_id, $stats );

		return $stats;
	}

	/**
	 * Record one hit from a known AI crawler (F6, opt-in, `Frontend\AiCrawlerTracker`).
	 * Deliberately separate from record_view(): it never touches the aggregate/daily
	 * tables, the session dimensions or the post meta mirror — mixing bot hits into the
	 * human view count would contaminate every metric built on top of it.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $bot     Canonical bot name from Core\AiCrawlers::SIGNATURES.
	 * @return void
	 */
	public function record_ai_crawl( int $post_id, string $bot ): void {
		global $wpdb;

		$now = current_time( 'mysql', true );
		$day = substr( $now, 0, 10 );

		$table = Schema::table_ai_crawls();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Atomic upsert on the plugin's own AI-crawler table, no equivalent WP API; a running counter must never be served from cache. $table is an internal constant (Schema::table_ai_crawls()), never user input.
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$table} is an internal constant (Schema::table_ai_crawls()), never user input.
				"INSERT INTO {$table} (post_id, day, bot, views) VALUES (%d, %s, %s, 1) ON DUPLICATE KEY UPDATE views = views + 1",
				$post_id,
				$day,
				$bot
			)
		);
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Uninstall-only table drop; DDL can't use placeholders for identifiers, and the name is an internal constant (Schema::table_dims()), never user input.
		$wpdb->query( 'DROP TABLE IF EXISTS ' . Schema::table_dims() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Uninstall-only table drop; DDL can't use placeholders for identifiers, and the name is an internal constant (Schema::table_ai_crawls()), never user input.
		$wpdb->query( 'DROP TABLE IF EXISTS ' . Schema::table_ai_crawls() );
	}
}
