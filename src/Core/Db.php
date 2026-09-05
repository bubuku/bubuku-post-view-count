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

use WP_Error;

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
	public function record_view( int $post_id, array $dims = array() ) {
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
	 * Atomically claim a visitor token and record all aggregates for one view.
	 * The dedupe claim and counter writes share a transaction, so neither can
	 * survive if another write fails.
	 *
	 * @param int    $post_id     Post ID.
	 * @param string $token_hash  HMAC of post, network address and user agent.
	 * @param string $network_hash HMAC of the network address for rate limiting.
	 * @param int    $dedupe_ttl  Dedupe lifetime in seconds.
	 * @param array  $dims        Whitelisted dimension => value pairs.
	 * @return array{accepted:bool,stats:array}|WP_Error
	 */
	public function record_unique_view( int $post_id, string $token_hash, string $network_hash, int $dedupe_ttl, array $dims = array() ) {
		global $wpdb;

		$now        = current_time( 'mysql', true );
		$expires_at = gmdate( 'Y-m-d H:i:s', strtotime( $now . ' UTC' ) + $dedupe_ttl );
		$table      = Schema::table_dedupe();
		$rate_error = $this->rate_limit_error( $post_id, $network_hash, $now );

		if ( $rate_error ) {
			return $rate_error;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction required to couple the durable dedupe claim to every aggregate write.
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return $this->storage_error();
		}

		// Remove only this visitor's expired claim before attempting a fresh insert.
		// Keeping both statements inside the transaction lets the unique key arbitrate
		// concurrent requests without relying on DB-specific UPSERT affected-row rules.
		$expired_claim = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transactional maintenance of one short-lived dedupe claim; caching would be incorrect.
			$wpdb->prepare(
				'DELETE FROM %i WHERE token_hash = %s AND expires_at <= %s',
				$table,
				$token_hash,
				$now
			)
		);

		if ( false === $expired_claim ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Roll back the failed claim refresh.

			return $this->storage_error();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The unique token key is the atomic dedupe primitive; caching would be incorrect.
		$claimed = $wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO %i (token_hash, network_hash, post_id, created_at, expires_at) VALUES (%s, %s, %d, %s, %s)',
				$table,
				$token_hash,
				$network_hash,
				$post_id,
				$now,
				$expires_at
			)
		);

		if ( false === $claimed ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction control.

			return new WP_Error( 'bbk_postview_storage_error', __( 'The view could not be stored.', 'bubuku-post-view-count' ), array( 'status' => 503 ) );
		}

		if ( 0 === $claimed ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Release transaction without changing the existing claim.

			return array(
				'accepted' => false,
				'stats'    => $this->get_stats( $post_id ),
			);
		}

		$grouped = array();
		foreach ( $dims as $dimension => $value ) {
			if ( '' !== $value ) {
				$grouped[ $dimension ] = array( $value => 1 );
			}
		}

		$stats = $this->write_aggregates( $post_id, $now, substr( $now, 0, 10 ), 1, $grouped );
		if ( is_wp_error( $stats ) ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Roll back claim and partial aggregates.

			return $stats;
		}

		if ( false === $wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction control.
			return new WP_Error( 'bbk_postview_storage_error', __( 'The view could not be stored.', 'bubuku-post-view-count' ), array( 'status' => 503 ) );
		}

		$this->mirror_post_meta( $post_id, $stats );

		return array(
			'accepted' => true,
			'stats'    => $stats,
		);
	}

	/**
	 * Apply conservative per-network and per-network/post admission limits.
	 * Sites behind large trusted proxies can adjust both values with the filter.
	 *
	 * @return WP_Error|null
	 */
	private function rate_limit_error( int $post_id, string $network_hash, string $now ): ?WP_Error {
		global $wpdb;

		$limits        = apply_filters(
			'bbk_postview_rate_limits',
			array(
				'network_per_minute'      => 120,
				'network_post_per_minute' => 30,
			)
		);
		$network_limit = max( 1, (int) ( $limits['network_per_minute'] ?? 120 ) );
		$post_limit    = max( 1, (int) ( $limits['network_post_per_minute'] ?? 30 ) );
		$since         = gmdate( 'Y-m-d H:i:s', strtotime( $now . ' UTC' ) - MINUTE_IN_SECONDS );
		$table         = Schema::table_dedupe();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Short-lived abuse-control lookup in the plugin's indexed dedupe table.
		$network_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$table} is internal.
				"SELECT COUNT(*) FROM {$table} WHERE network_hash = %s AND created_at >= %s",
				$network_hash,
				$since
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Short-lived abuse-control lookup in the plugin's indexed dedupe table.
		$post_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$table} is internal.
				"SELECT COUNT(*) FROM {$table} WHERE network_hash = %s AND post_id = %d AND created_at >= %s",
				$network_hash,
				$post_id,
				$since
			)
		);

		if ( $network_count < $network_limit && $post_count < $post_limit ) {
			return null;
		}

		return new WP_Error(
			'bbk_postview_rate_limited',
			__( 'Too many view requests. Please retry shortly.', 'bubuku-post-view-count' ),
			array(
				'status'  => 429,
				'headers' => array( 'Retry-After' => '60' ),
			)
		);
	}

	/**
	 * Record several views for a post/day in one transaction. Kept as a public
	 * primitive for repair/import tooling; the live endpoint writes immediately.
	 *
	 * @param int   $post_id Post ID.
	 * @param string $day    UTC day (Y-m-d) the buffered views belong to.
	 * @param int   $count   Total number of views to add (>= 1).
	 * @param array $dims    dimension => [ value => count ] pairs, already
	 *                       whitelisted by the caller.
	 * @return array{views:int,first_viewed_at:?string,last_viewed_at:?string}
	 */
	public function record_view_bulk( int $post_id, string $day, int $count, array $dims = array() ) {
		return $this->upsert_views( $post_id, current_time( 'mysql', true ), $day, $count, $dims );
	}

	/**
	 * Shared upsert logic behind record_view() and record_view_bulk(): three
	 * atomic `INSERT ... ON DUPLICATE KEY UPDATE` (aggregate, daily, one per
	 * dimension value), then the post meta mirror. `views = views +
	 * VALUES(views)` (rather than a literal increment) is what lets the same
	 * query shape serves both a single view (count = 1) and an imported batch
	 * (count = N) without a second SQL template.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $now     UTC datetime to store as first/last viewed.
	 * @param string $day     UTC day (Y-m-d) for the daily/dims tables.
	 * @param int    $count   Views to add.
	 * @param array  $dims    dimension => [ value => count ] pairs.
	 * @return array{views:int,first_viewed_at:?string,last_viewed_at:?string}
	 */
	private function upsert_views( int $post_id, string $now, string $day, int $count, array $dims ) {
		global $wpdb;

		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Keep aggregate tables consistent.
			return $this->storage_error();
		}
		$stats = $this->write_aggregates( $post_id, $now, $day, $count, $dims );

		if ( is_wp_error( $stats ) ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Roll back partial aggregates.

			return $stats;
		}

		if ( false === $wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction control.
			return $this->storage_error();
		}

		$this->mirror_post_meta( $post_id, $stats );

		return $stats;
	}

	/**
	 * Write aggregate rows inside an existing transaction.
	 *
	 * @return array{views:int,first_viewed_at:?string,last_viewed_at:?string}|WP_Error
	 */
	private function write_aggregates( int $post_id, string $now, string $day, int $count, array $dims ) {
		global $wpdb;

		$views_table = Schema::table_views();
		$daily_table = Schema::table_daily();
		$dims_table  = Schema::table_dims();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Atomic upsert on the plugin's own view-counter table, no equivalent WP API; a running counter must never be served from cache. $views_table is an internal constant (Schema::table_views()), never user input.
		$result = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$views_table} is an internal constant (Schema::table_views()), never user input.
				"INSERT INTO {$views_table} (post_id, views, first_viewed_at, last_viewed_at) VALUES (%d, %d, %s, %s) ON DUPLICATE KEY UPDATE views = views + VALUES(views), last_viewed_at = VALUES(last_viewed_at)",
				$post_id,
				$count,
				$now,
				$now
			)
		);
		if ( false === $result ) {
			return $this->storage_error();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Atomic upsert on the plugin's own daily-counter table, no equivalent WP API; a running counter must never be served from cache. $daily_table is an internal constant (Schema::table_daily()), never user input.
		$result = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$daily_table} is an internal constant (Schema::table_daily()), never user input.
				"INSERT INTO {$daily_table} (post_id, day, views) VALUES (%d, %s, %d) ON DUPLICATE KEY UPDATE views = views + VALUES(views)",
				$post_id,
				$day,
				$count
			)
		);
		if ( false === $result ) {
			return $this->storage_error();
		}

		foreach ( $dims as $dimension => $values ) {
			foreach ( $values as $value => $value_count ) {
				if ( '' === $value || $value_count <= 0 ) {
					continue;
				}

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Atomic upsert on the plugin's own session-dimensions table, no equivalent WP API; a running counter must never be served from cache. $dims_table is an internal constant (Schema::table_dims()), never user input.
				$result = $wpdb->query(
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
				if ( false === $result ) {
					return $this->storage_error();
				}
			}
		}

		$stats = $this->get_stats( $post_id );

		return $stats;
	}

	/**
	 * Standard storage failure returned to the REST layer.
	 *
	 * @return WP_Error
	 */
	private function storage_error(): WP_Error {
		return new WP_Error( 'bbk_postview_storage_error', __( 'The view could not be stored.', 'bubuku-post-view-count' ), array( 'status' => 503 ) );
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
		$result = $this->record_view( $post_id );

		return is_wp_error( $result ) ? $this->get_stats( $post_id )['views'] : $result['views'];
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

		$views_updated = update_post_meta( $post_id, 'views', $stats['views'] );
		$last_updated  = update_post_meta( $post_id, 'views_last', $stats['last_viewed_at'] );
		wp_cache_delete( $post_id, 'post_meta' );

		if ( ( ! $views_updated && (int) get_post_meta( $post_id, 'views', true ) !== $stats['views'] ) || ( ! $last_updated && get_post_meta( $post_id, 'views_last', true ) !== $stats['last_viewed_at'] ) ) {
			do_action( 'bbk_postview_meta_mirror_failed', $post_id, $stats );
		}
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Uninstall-only table drop; internal identifier.
		$wpdb->query( 'DROP TABLE IF EXISTS ' . Schema::table_dedupe() );
	}
}
