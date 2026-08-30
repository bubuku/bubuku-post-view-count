<?php
/**
 * Query Class.
 *
 * @package Bubuku Post View Count
 * @author     Luis Ruiz <lruiz@bubuku.com>
 * @copyright  2022 Bubuku
 * @version    1.2.1
 */

declare( strict_types=1 );

namespace Bubuku\Plugins\PostViewCount\Core;

use Bubuku\Plugins\PostViewCount\Admin\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only analytics queries over the plugin's own tables (docs/ANALYTICS-PLAN.md §4.1).
 * No dependency on MCP: reusable by the settings page, WP-CLI, or a future tool. All SQL
 * lives here and only here — nothing else in the plugin queries these tables for reporting.
 */
class Query {

	/**
	 * Object cache group for query results.
	 */
	const CACHE_GROUP = 'bbk_postview_query';

	/**
	 * Object cache TTL, in seconds.
	 */
	const CACHE_TTL = 300;

	/**
	 * Hard cap applied to every `limit`/`page` argument, regardless of what is requested.
	 */
	const MAX_LIMIT = 100;

	/**
	 * Most-viewed posts. Uses the all-time aggregate when no window is given (exact total),
	 * or the daily aggregate when `since`/`until` narrow the window (exact for that window,
	 * limited to data collected since the daily table started — see §1.6).
	 *
	 * @param string[]    $post_types Post types to include; empty means every enabled type.
	 * @param string|null $since      Inclusive start day (Y-m-d, UTC). Null = no lower bound.
	 * @param string|null $until      Inclusive end day (Y-m-d, UTC). Null = today (UTC).
	 * @param int         $limit      Max rows, capped at self::MAX_LIMIT.
	 * @param int         $page       1-based page number.
	 * @return array<int, array{id:int,title:string,url:string,views:int}>
	 */
	public static function most_viewed( array $post_types = array(), ?string $since = null, ?string $until = null, int $limit = 10, int $page = 1 ): array {
		global $wpdb;

		$post_types = self::resolve_post_types( $post_types );

		if ( empty( $post_types ) ) {
			return array();
		}

		$limit  = self::cap_limit( $limit );
		$offset = ( max( 1, $page ) - 1 ) * $limit;

		$cache_key = 'most_viewed_' . md5( (string) wp_json_encode( array( $post_types, $since, $until, $limit, $offset ) ) );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return $cached;
		}

		$type_placeholders = self::placeholders( $post_types, '%s' );

		if ( null === $since && null === $until ) {
			$views_table = Schema::table_views();

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Read query over the plugin's own table, no equivalent WP API; short-lived object cache applied above. $views_table is an internal constant (Schema::table_views()), never user input.
			$rows = $wpdb->get_results(
				// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- {$type_placeholders} expands to a dynamic run of %s placeholders at runtime, which this static sniff cannot count.
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$views_table} is an internal constant (Schema::table_views()), never user input; {$type_placeholders} is a fixed run of %s placeholders.
					"SELECT v.post_id AS post_id, v.views AS total_views FROM {$views_table} v INNER JOIN {$wpdb->posts} p ON p.ID = v.post_id WHERE p.post_type IN ({$type_placeholders}) AND p.post_status = 'publish' ORDER BY v.views DESC LIMIT %d OFFSET %d",
					...array_merge( $post_types, array( $limit, $offset ) )
				)
			);
		} else {
			$daily_table = Schema::table_daily();
			$since       = $since ?? '1970-01-01';
			$until       = $until ?? current_time( 'Y-m-d', true );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Read query over the plugin's own table, no equivalent WP API; short-lived object cache applied above. $daily_table is an internal constant (Schema::table_daily()), never user input.
			$rows = $wpdb->get_results(
				// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- {$type_placeholders} expands to a dynamic run of %s placeholders at runtime, which this static sniff cannot count.
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$daily_table} is an internal constant (Schema::table_daily()), never user input; {$type_placeholders} is a fixed run of %s placeholders.
					"SELECT d.post_id AS post_id, SUM(d.views) AS total_views FROM {$daily_table} d INNER JOIN {$wpdb->posts} p ON p.ID = d.post_id WHERE p.post_type IN ({$type_placeholders}) AND p.post_status = 'publish' AND d.day BETWEEN %s AND %s GROUP BY d.post_id ORDER BY total_views DESC LIMIT %d OFFSET %d",
					...array_merge( $post_types, array( $since, $until, $limit, $offset ) )
				)
			);
		}

		$result = self::format_view_rows( is_array( $rows ) ? $rows : array() );

		wp_cache_set( $cache_key, $result, self::CACHE_GROUP, self::CACHE_TTL );

		return $result;
	}

	/**
	 * Published posts that have never been viewed, or not viewed recently. Explicitly
	 * includes posts with no row at all in the aggregate table (never viewed) — see §4.1.
	 *
	 * @param string|null $not_viewed_since  Cutoff (Y-m-d H:i:s, UTC). Null = 6 months ago.
	 * @param string|null $published_before  Only posts published on/before this date (Y-m-d H:i:s, UTC). Null = now.
	 * @param string[]    $post_types        Post types to include; empty means every enabled type.
	 * @param int         $limit             Max rows, capped at self::MAX_LIMIT.
	 * @return array<int, array{id:int,title:string,url:string,last_viewed_at:?string}>
	 */
	public static function stale( ?string $not_viewed_since = null, ?string $published_before = null, array $post_types = array(), int $limit = 10 ): array {
		global $wpdb;

		$post_types = self::resolve_post_types( $post_types );

		if ( empty( $post_types ) ) {
			return array();
		}

		$limit            = self::cap_limit( $limit );
		$not_viewed_since = $not_viewed_since ?? gmdate( 'Y-m-d H:i:s', strtotime( '-6 months' ) );
		$published_before = $published_before ?? current_time( 'mysql', true );

		$views_table       = Schema::table_views();
		$type_placeholders = self::placeholders( $post_types, '%s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Read query over the plugin's own table, no equivalent WP API. $views_table is an internal constant (Schema::table_views()), never user input.
		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- {$type_placeholders} expands to a dynamic run of %s placeholders at runtime, which this static sniff cannot count.
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$views_table} is an internal constant (Schema::table_views()), never user input; {$type_placeholders} is a fixed run of %s placeholders.
				"SELECT p.ID AS post_id, v.last_viewed_at AS last_viewed_at FROM {$wpdb->posts} p LEFT JOIN {$views_table} v ON v.post_id = p.ID WHERE p.post_type IN ({$type_placeholders}) AND p.post_status = 'publish' AND p.post_date_gmt <= %s AND ( v.last_viewed_at IS NULL OR v.last_viewed_at < %s ) ORDER BY ( v.last_viewed_at IS NULL ) DESC, v.last_viewed_at ASC LIMIT %d",
				...array_merge( $post_types, array( $published_before, $not_viewed_since, $limit ) )
			)
		);

		$result = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$post_id  = (int) $row->post_id;
			$result[] = array(
				'id'             => $post_id,
				'title'          => get_the_title( $post_id ),
				'url'            => get_permalink( $post_id ),
				'last_viewed_at' => $row->last_viewed_at,
			);
		}

		return $result;
	}

	/**
	 * Full stats for a single post: running total, first/last view, and a 90-day daily series.
	 *
	 * @param int $post_id Post ID.
	 * @return array{id:int,title:string,url:string,views:int,first_viewed_at:?string,last_viewed_at:?string,daily:array}
	 */
	public static function post_stats( int $post_id ): array {
		$stats = ( new Db() )->get_stats( $post_id );

		return array(
			'id'              => $post_id,
			'title'           => get_the_title( $post_id ),
			'url'             => get_permalink( $post_id ),
			'views'           => $stats['views'],
			'first_viewed_at' => $stats['first_viewed_at'],
			'last_viewed_at'  => $stats['last_viewed_at'],
			'daily'           => self::daily_series( $post_id ),
		);
	}

	/**
	 * Views over time, bucketed by day/week/month, for a set of posts or post types.
	 * `post_ids` takes precedence over `post_types` when both are given.
	 *
	 * @param int[]    $post_ids    Specific post IDs to include.
	 * @param string[] $post_types  Post types to include when `post_ids` is empty.
	 * @param string   $granularity One of 'day', 'week', 'month'.
	 * @param string|null $from     Inclusive start day (Y-m-d, UTC). Null = 3 months ago.
	 * @param string|null $to       Inclusive end day (Y-m-d, UTC). Null = today (UTC).
	 * @return array<int, array{bucket:string,total_views:int}>
	 */
	public static function trend( array $post_ids = array(), array $post_types = array(), string $granularity = 'day', ?string $from = null, ?string $to = null ): array {
		global $wpdb;

		$granularity = in_array( $granularity, array( 'day', 'week', 'month' ), true ) ? $granularity : 'day';
		$from        = $from ?? gmdate( 'Y-m-d', strtotime( '-3 months' ) );
		$to          = $to ?? current_time( 'Y-m-d', true );

		$cache_key = 'trend_' . md5( (string) wp_json_encode( array( $post_ids, $post_types, $granularity, $from, $to ) ) );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return $cached;
		}

		$daily_table = Schema::table_daily();

		$buckets = array(
			'day'   => 'd.day',
			'week'  => 'DATE_SUB(d.day, INTERVAL WEEKDAY(d.day) DAY)',
			'month' => "DATE_FORMAT(d.day, '%%Y-%%m-01')",
		);
		$bucket  = $buckets[ $granularity ];

		$join   = '';
		$where  = array( 'd.day BETWEEN %s AND %s' );
		$params = array( $from, $to );

		if ( ! empty( $post_ids ) ) {
			$post_ids = array_values( array_unique( array_map( 'absint', $post_ids ) ) );
			$where[]  = 'd.post_id IN (' . self::placeholders( $post_ids, '%d' ) . ')';
			$params   = array_merge( $params, $post_ids );
		} else {
			$post_types = self::resolve_post_types( $post_types );

			if ( empty( $post_types ) ) {
				return array();
			}

			$join    = "INNER JOIN {$wpdb->posts} p ON p.ID = d.post_id";
			$where[] = 'p.post_type IN (' . self::placeholders( $post_types, '%s' ) . ') AND p.post_status = \'publish\'';
			$params  = array_merge( $params, $post_types );
		}

		$where_sql = implode( ' AND ', $where );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Read query over the plugin's own table, no equivalent WP API. $daily_table is an internal constant (Schema::table_daily()), never user input.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- {$daily_table}/{$bucket}/{$join}/{$where_sql} are internal constants or fixed placeholder runs, never user input; the sniff can't see the %d/%s placeholders they expand to.
				"SELECT {$bucket} AS bucket, SUM(d.views) AS total_views FROM {$daily_table} d {$join} WHERE {$where_sql} GROUP BY bucket ORDER BY bucket ASC",
				...$params
			),
			ARRAY_A
		);

		$result = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$result[] = array(
				'bucket'      => (string) $row['bucket'],
				'total_views' => (int) $row['total_views'],
			);
		}

		wp_cache_set( $cache_key, $result, self::CACHE_GROUP, self::CACHE_TTL );

		return $result;
	}

	/**
	 * Site-wide totals for a set of post types.
	 *
	 * @param string[]    $post_types Post types to include; empty means every enabled type.
	 * @param string|null $since      Inclusive start day (Y-m-d, UTC) for `total_views`. Null = all-time.
	 * @return array{total_views:int,posts_with_traffic:int,posts_without_traffic:int}
	 */
	public static function summary( array $post_types = array(), ?string $since = null ): array {
		global $wpdb;

		$empty = array(
			'total_views'           => 0,
			'posts_with_traffic'    => 0,
			'posts_without_traffic' => 0,
		);

		$post_types = self::resolve_post_types( $post_types );

		if ( empty( $post_types ) ) {
			return $empty;
		}

		$views_table       = Schema::table_views();
		$type_placeholders = self::placeholders( $post_types, '%s' );

		if ( null === $since ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Read query over the plugin's own table, no equivalent WP API. $views_table is an internal constant (Schema::table_views()), never user input.
			$total_views = (int) $wpdb->get_var(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- {$views_table} is an internal constant, never user input; {$type_placeholders} is a fixed run of %s placeholders the sniff can't see through.
					"SELECT COALESCE(SUM(v.views), 0) FROM {$views_table} v INNER JOIN {$wpdb->posts} p ON p.ID = v.post_id WHERE p.post_type IN ({$type_placeholders}) AND p.post_status = 'publish'",
					...$post_types
				)
			);
		} else {
			$daily_table = Schema::table_daily();

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Read query over the plugin's own table, no equivalent WP API. $daily_table is an internal constant (Schema::table_daily()), never user input.
			$total_views = (int) $wpdb->get_var(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$daily_table} is an internal constant, never user input; {$type_placeholders} is a fixed run of %s placeholders.
					"SELECT COALESCE(SUM(d.views), 0) FROM {$daily_table} d INNER JOIN {$wpdb->posts} p ON p.ID = d.post_id WHERE p.post_type IN ({$type_placeholders}) AND p.post_status = 'publish' AND d.day >= %s",
					...array_merge( $post_types, array( $since ) )
				)
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Read query over the plugin's own table, no equivalent WP API. $views_table is an internal constant (Schema::table_views()), never user input.
		$with_traffic = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- {$views_table} is an internal constant, never user input; {$type_placeholders} is a fixed run of %s placeholders the sniff can't see through.
				"SELECT COUNT(DISTINCT v.post_id) FROM {$views_table} v INNER JOIN {$wpdb->posts} p ON p.ID = v.post_id WHERE p.post_type IN ({$type_placeholders}) AND p.post_status = 'publish'",
				...$post_types
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Read query over WP core's own posts table; caching a live count here would go stale on every publish. $type_placeholders is a fixed run of %s placeholders built from self::placeholders(), never user input.
		$total_published = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- {$type_placeholders} is a fixed run of %s placeholders, never user input, that the sniff can't see through.
				"SELECT COUNT(*) FROM {$wpdb->posts} p WHERE p.post_type IN ({$type_placeholders}) AND p.post_status = 'publish'",
				...$post_types
			)
		);

		return array(
			'total_views'           => $total_views,
			'posts_with_traffic'    => $with_traffic,
			'posts_without_traffic' => max( 0, $total_published - $with_traffic ),
		);
	}

	/**
	 * Posts whose daily views are rising or falling, comparing the last `$period_days` days
	 * against the equal-length period immediately before them (docs/ANALYTICS-PLAN.md §5,
	 * "listados en alza/en caída" pendiente de la Fase 4). Unlike most_viewed(), this always
	 * reads the daily aggregate — there is no all-time variant of a two-period comparison.
	 *
	 * `min_views` filters noise: a post going from 0 to 1 view has an undefined (null)
	 * percentage change and would otherwise dominate the "rising" list without meaning
	 * anything. A post with zero views in both periods is never returned (nothing changed).
	 *
	 * @param string[] $post_types  Post types to include; empty means every enabled type.
	 * @param int      $period_days Length of each period being compared, in days.
	 * @param int      $limit       Max rows per list (rising/falling), capped at self::MAX_LIMIT.
	 * @param int      $min_views   Minimum combined views (current + previous period) to be considered.
	 * @return array{
	 *     rising: array<int, array{id:int,title:string,url:string,current_views:int,previous_views:int,delta:int,delta_pct:?float}>,
	 *     falling: array<int, array{id:int,title:string,url:string,current_views:int,previous_views:int,delta:int,delta_pct:?float}>,
	 *     period: array{current:array{from:string,to:string},previous:array{from:string,to:string}}
	 * }
	 */
	public static function momentum( array $post_types = array(), int $period_days = 30, int $limit = 10, int $min_views = 1 ): array {
		global $wpdb;

		$current_end    = current_time( 'Y-m-d', true );
		$period_days    = max( 1, $period_days );
		$current_start  = gmdate( 'Y-m-d', strtotime( "{$current_end} -" . ( $period_days - 1 ) . ' days' ) );
		$previous_end   = gmdate( 'Y-m-d', strtotime( "{$current_start} -1 day" ) );
		$previous_start = gmdate( 'Y-m-d', strtotime( "{$previous_end} -" . ( $period_days - 1 ) . ' days' ) );

		$empty = array(
			'rising'  => array(),
			'falling' => array(),
			'period'  => array(
				'current'  => array(
					'from' => $current_start,
					'to'   => $current_end,
				),
				'previous' => array(
					'from' => $previous_start,
					'to'   => $previous_end,
				),
			),
		);

		$post_types = self::resolve_post_types( $post_types );

		if ( empty( $post_types ) ) {
			return $empty;
		}

		$limit     = self::cap_limit( $limit );
		$min_views = max( 0, $min_views );

		$cache_key = 'momentum_' . md5( (string) wp_json_encode( array( $post_types, $period_days, $limit, $min_views ) ) );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return $cached;
		}

		$daily_table       = Schema::table_daily();
		$type_placeholders = self::placeholders( $post_types, '%s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Read query over the plugin's own table, no equivalent WP API. $daily_table is an internal constant (Schema::table_daily()), never user input.
		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- {$type_placeholders} expands to a dynamic run of %s placeholders at runtime, which this static sniff cannot count.
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$daily_table} is an internal constant (Schema::table_daily()), never user input; {$type_placeholders} is a fixed run of %s placeholders.
				"SELECT d.post_id AS post_id, SUM(CASE WHEN d.day >= %s THEN d.views ELSE 0 END) AS current_views, SUM(CASE WHEN d.day < %s THEN d.views ELSE 0 END) AS previous_views FROM {$daily_table} d INNER JOIN {$wpdb->posts} p ON p.ID = d.post_id WHERE p.post_type IN ({$type_placeholders}) AND p.post_status = 'publish' AND d.day BETWEEN %s AND %s GROUP BY d.post_id",
				array_merge( array( $current_start, $current_start ), $post_types, array( $previous_start, $current_end ) )
			),
			ARRAY_A
		);

		$rising  = array();
		$falling = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$current  = (int) $row['current_views'];
			$previous = (int) $row['previous_views'];

			if ( ( $current + $previous ) < $min_views ) {
				continue;
			}

			$delta = $current - $previous;

			if ( 0 === $delta ) {
				continue;
			}

			$post_id = (int) $row['post_id'];
			$item    = array(
				'id'             => $post_id,
				'title'          => get_the_title( $post_id ),
				'url'            => get_permalink( $post_id ),
				'current_views'  => $current,
				'previous_views' => $previous,
				'delta'          => $delta,
				'delta_pct'      => $previous > 0 ? round( ( $delta / $previous ) * 100, 1 ) : null,
			);

			if ( $delta > 0 ) {
				$rising[] = $item;
			} else {
				$falling[] = $item;
			}
		}

		usort(
			$rising,
			static function ( array $a, array $b ): int {
				return $b['delta'] <=> $a['delta'];
			}
		);
		usort(
			$falling,
			static function ( array $a, array $b ): int {
				return $a['delta'] <=> $b['delta'];
			}
		);

		$empty['rising']  = array_slice( $rising, 0, $limit );
		$empty['falling'] = array_slice( $falling, 0, $limit );

		wp_cache_set( $cache_key, $empty, self::CACHE_GROUP, self::CACHE_TTL );

		return $empty;
	}

	/**
	 * Site-wide breakdown of a session dimension (F5: 'viewport'|'referrer'), summed across
	 * matching posts and ordered by views DESC. Fixed, small cardinality (at most 6 rows for
	 * 'referrer', 4 for 'viewport' — one per Dimensions::values_for() entry), so unlike every
	 * other method here this never needs a LIMIT/cap_limit().
	 *
	 * @param string      $dimension  One of Dimensions::DIMENSIONS.
	 * @param string[]    $post_types Post types to include; empty means every enabled type.
	 * @param string|null $since      Inclusive start day (Y-m-d, UTC). Null = 3 months ago.
	 * @param string|null $until      Inclusive end day (Y-m-d, UTC). Null = today (UTC).
	 * @return array<int, array{value:string,views:int}>
	 */
	public static function dims_breakdown( string $dimension, array $post_types = array(), ?string $since = null, ?string $until = null ): array {
		global $wpdb;

		if ( empty( Dimensions::values_for( $dimension ) ) ) {
			return array();
		}

		$post_types = self::resolve_post_types( $post_types );

		if ( empty( $post_types ) ) {
			return array();
		}

		$since = $since ?? gmdate( 'Y-m-d', strtotime( '-3 months' ) );
		$until = $until ?? current_time( 'Y-m-d', true );

		$cache_key = 'dims_breakdown_' . md5( (string) wp_json_encode( array( $dimension, $post_types, $since, $until ) ) );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return $cached;
		}

		$dims_table        = Schema::table_dims();
		$type_placeholders = self::placeholders( $post_types, '%s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Read query over the plugin's own table, no equivalent WP API; short-lived object cache applied above. $dims_table is an internal constant (Schema::table_dims()), never user input.
		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- {$type_placeholders} expands to a dynamic run of %s placeholders at runtime, which this static sniff cannot count.
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$dims_table} is an internal constant (Schema::table_dims()), never user input; {$type_placeholders} is a fixed run of %s placeholders.
				"SELECT d.value AS value, SUM(d.views) AS total_views FROM {$dims_table} d INNER JOIN {$wpdb->posts} p ON p.ID = d.post_id WHERE d.dimension = %s AND p.post_type IN ({$type_placeholders}) AND p.post_status = 'publish' AND d.day BETWEEN %s AND %s GROUP BY d.value ORDER BY total_views DESC",
				...array_merge( array( $dimension ), $post_types, array( $since, $until ) )
			),
			ARRAY_A
		);

		$result = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$result[] = array(
				'value' => (string) $row['value'],
				'views' => (int) $row['total_views'],
			);
		}

		wp_cache_set( $cache_key, $result, self::CACHE_GROUP, self::CACHE_TTL );

		return $result;
	}

	/**
	 * AI traffic, split into the two things docs/ANALYTICS-PLAN.md (F6) says must never be
	 * mixed: human visitors referred by an AI assistant (already captured by the F5
	 * `referrer` dimension, value 'ai' — reuses dims_breakdown(), no duplicated SQL) and
	 * non-JS AI crawlers hitting pages directly (opt-in, its own table — see
	 * Core\AiCrawlers / Frontend\AiCrawlerTracker). Site-wide, same shape/caching pattern
	 * as dims_breakdown().
	 *
	 * @param string[]    $post_types Post types to include; empty means every enabled type.
	 * @param string|null $since      Inclusive start day (Y-m-d, UTC). Null = 3 months ago.
	 * @param string|null $until      Inclusive end day (Y-m-d, UTC). Null = today (UTC).
	 * @return array{referrals:array{views:int},crawlers:array<int,array{bot:string,views:int}>,ai_crawler_tracking_enabled:bool}
	 */
	public static function ai_traffic( array $post_types = array(), ?string $since = null, ?string $until = null ): array {
		global $wpdb;

		$referral_views = 0;

		foreach ( self::dims_breakdown( 'referrer', $post_types, $since, $until ) as $row ) {
			if ( 'ai' === $row['value'] ) {
				$referral_views = $row['views'];
				break;
			}
		}

		$resolved_post_types = self::resolve_post_types( $post_types );
		$crawlers            = array();

		if ( ! empty( $resolved_post_types ) ) {
			$since = $since ?? gmdate( 'Y-m-d', strtotime( '-3 months' ) );
			$until = $until ?? current_time( 'Y-m-d', true );

			$cache_key = 'ai_crawlers_' . md5( (string) wp_json_encode( array( $resolved_post_types, $since, $until ) ) );
			$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

			if ( false !== $cached ) {
				$crawlers = $cached;
			} else {
				$ai_crawls_table   = Schema::table_ai_crawls();
				$type_placeholders = self::placeholders( $resolved_post_types, '%s' );

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Read query over the plugin's own table, no equivalent WP API; short-lived object cache applied above. $ai_crawls_table is an internal constant (Schema::table_ai_crawls()), never user input.
				$rows = $wpdb->get_results(
					// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- {$type_placeholders} expands to a dynamic run of %s placeholders at runtime, which this static sniff cannot count.
					$wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$ai_crawls_table} is an internal constant (Schema::table_ai_crawls()), never user input; {$type_placeholders} is a fixed run of %s placeholders.
						"SELECT c.bot AS bot, SUM(c.views) AS total_views FROM {$ai_crawls_table} c INNER JOIN {$wpdb->posts} p ON p.ID = c.post_id WHERE p.post_type IN ({$type_placeholders}) AND p.post_status = 'publish' AND c.day BETWEEN %s AND %s GROUP BY c.bot ORDER BY total_views DESC",
						...array_merge( $resolved_post_types, array( $since, $until ) )
					),
					ARRAY_A
				);

				foreach ( is_array( $rows ) ? $rows : array() as $row ) {
					$crawlers[] = array(
						'bot'   => (string) $row['bot'],
						'views' => (int) $row['total_views'],
					);
				}

				wp_cache_set( $cache_key, $crawlers, self::CACHE_GROUP, self::CACHE_TTL );
			}
		}

		return array(
			'referrals'                   => array( 'views' => $referral_views ),
			'crawlers'                    => $crawlers,
			'ai_crawler_tracking_enabled' => Settings::ai_crawler_tracking(),
		);
	}

	/**
	 * Requested post types intersected with the enabled ones (§3.1) — never trust a
	 * caller-supplied list, and never query a type the site has excluded from counting.
	 * Empty input means "every enabled type".
	 *
	 * @param string[] $requested Requested post types.
	 * @return string[]
	 */
	private static function resolve_post_types( array $requested ): array {
		$enabled = Settings::enabled_post_types();

		if ( empty( $requested ) ) {
			return $enabled;
		}

		return array_values( array_intersect( array_map( 'sanitize_key', $requested ), $enabled ) );
	}

	/**
	 * Caps a requested limit to [1, self::MAX_LIMIT] — every public method takes a `limit`
	 * argument that could otherwise be used to pull the entire table in one query.
	 *
	 * @param int $limit Requested limit.
	 * @return int
	 */
	private static function cap_limit( int $limit ): int {
		return max( 1, min( self::MAX_LIMIT, $limit ) );
	}

	/**
	 * Builds a fixed run of `%d`/`%s` placeholders for an SQL `IN (...)` clause.
	 *
	 * @param array  $values    Values the clause will hold (only the count is used).
	 * @param string $type      Either '%d' or '%s'.
	 * @return string
	 */
	private static function placeholders( array $values, string $type ): string {
		return implode( ',', array_fill( 0, count( $values ), $type ) );
	}

	/**
	 * The 90-day daily series for a post, used by post_stats().
	 *
	 * @param int $post_id Post ID.
	 * @param int $days    How many days back to include.
	 * @return array<int, array{day:string,views:int}>
	 */
	private static function daily_series( int $post_id, int $days = 90 ): array {
		global $wpdb;

		$daily_table = Schema::table_daily();
		$since       = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Read query over the plugin's own table, no equivalent WP API. $daily_table is an internal constant (Schema::table_daily()), never user input.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$daily_table} is an internal constant (Schema::table_daily()), never user input.
				"SELECT day, views FROM {$daily_table} WHERE post_id = %d AND day >= %s ORDER BY day ASC",
				$post_id,
				$since
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Formats `(post_id, total_views)` rows from most_viewed() into the public shape.
	 *
	 * @param array $rows Raw rows from $wpdb->get_results().
	 * @return array<int, array{id:int,title:string,url:string,views:int}>
	 */
	private static function format_view_rows( array $rows ): array {
		$result = array();

		foreach ( $rows as $row ) {
			$post_id  = (int) $row->post_id;
			$result[] = array(
				'id'    => $post_id,
				'title' => get_the_title( $post_id ),
				'url'   => get_permalink( $post_id ),
				'views' => (int) $row->total_views,
			);
		}

		return $result;
	}
}
