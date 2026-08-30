<?php
/**
 * WriteBuffer Class.
 *
 * @package Bubuku Post View Count
 * @author     Luis Ruiz <lruiz@bubuku.com>
 * @copyright  2022 Bubuku
 * @version    1.3.0
 */

declare( strict_types=1 );

namespace Bubuku\Plugins\PostViewCount\Core;

use Bubuku\Plugins\PostViewCount\Admin\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Best-effort write buffer for high-traffic sites (docs/ANALYTICS-PLAN.md
 * §F7). Coalesces view increments for the same post/day into the object
 * cache and flushes them to the database in one batch per post via WP-Cron
 * (`Schema::BUFFER_FLUSH_CRON_HOOK`), instead of one upsert per view.
 *
 * Opt-in (`Admin\Settings::write_buffer_enabled()`, off by default) and only
 * takes effect with a persistent external object cache: without one nothing
 * survives between requests, so buffering would silently drop views instead
 * of saving writes.
 *
 * Trade-offs, accepted deliberately for an opt-in performance feature:
 * - Flushing reads a counter then deletes it without a true atomic
 *   get-and-delete (the plain WP_Object_Cache API has none), so a view
 *   landing in the same instant as a flush can be undercounted by one.
 * - `first_viewed_at`/`last_viewed_at` reflect the flush time, not the exact
 *   view time, within the flush interval (`Schema::BUFFER_FLUSH_INTERVAL`).
 * - Registering a post/day in the flush index costs one `update_option()`
 *   call, but only once per flush interval per distinct post — not once per
 *   view — which is what makes this worth doing under real traffic.
 */
class WriteBuffer {

	/**
	 * Object cache group for buffered counters.
	 */
	const GROUP = 'bbk_postview_buffer';

	/**
	 * Option holding the list of "post_id|day" keys pending a flush.
	 */
	const OPTION_INDEX = 'bbk_postview_buffer_index';

	/**
	 * Whether increments should go through the buffer right now.
	 *
	 * @return bool
	 */
	public static function enabled(): bool {
		return Settings::write_buffer_enabled() && wp_using_ext_object_cache();
	}

	/**
	 * Buffer one view for later flushing instead of writing it immediately.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $dims    Already-whitelisted dimension => value pairs.
	 * @return void
	 */
	public static function buffer( int $post_id, array $dims ) {
		$key = self::buffer_key( $post_id );

		self::incr( self::count_cache_key( $key ) );

		foreach ( $dims as $dimension => $value ) {
			if ( '' !== $value ) {
				self::incr( self::dim_cache_key( $key, $dimension, $value ) );
			}
		}

		self::register( $key );
	}

	/**
	 * Currently buffered, not-yet-persisted view count for a post's current
	 * UTC day — used by `Api\RestApi` to keep the response count accurate
	 * while buffering is on, without waiting for the next flush.
	 *
	 * @param int $post_id Post ID.
	 * @return int
	 */
	public static function pending_views( int $post_id ): int {
		return (int) wp_cache_get( self::count_cache_key( self::buffer_key( $post_id ) ), self::GROUP );
	}

	/**
	 * Flushes every buffered post/day into the database in one batch each.
	 * Hooked to the recurring `Schema::BUFFER_FLUSH_CRON_HOOK`.
	 *
	 * @return void
	 */
	public static function flush() {
		$index = get_option( self::OPTION_INDEX, array() );

		if ( empty( $index ) ) {
			return;
		}

		update_option( self::OPTION_INDEX, array() );

		$db = new Db();

		foreach ( (array) $index as $key ) {
			self::flush_key( $db, (string) $key );
		}
	}

	/**
	 * Flush a single buffered "post_id|day" key: reads and clears its view
	 * and dimension counters, then writes them in one batch.
	 *
	 * @param Db     $db  Db instance to write through.
	 * @param string $key "post_id|day".
	 * @return void
	 */
	private static function flush_key( Db $db, string $key ) {
		list( $post_id, $day ) = array_pad( explode( '|', $key, 2 ), 2, '' );
		$post_id               = (int) $post_id;

		if ( 0 === $post_id || '' === $day ) {
			return;
		}

		$count_cache_key = self::count_cache_key( $key );
		$count           = (int) wp_cache_get( $count_cache_key, self::GROUP );

		wp_cache_delete( $count_cache_key, self::GROUP );
		wp_cache_delete( self::seen_cache_key( $key ), self::GROUP );

		$dims = array();

		foreach ( Dimensions::DIMENSIONS as $dimension ) {
			foreach ( Dimensions::values_for( $dimension ) as $value ) {
				$dim_cache_key = self::dim_cache_key( $key, $dimension, $value );
				$dim_count     = (int) wp_cache_get( $dim_cache_key, self::GROUP );

				if ( $dim_count > 0 ) {
					$dims[ $dimension ][ $value ] = $dim_count;
					wp_cache_delete( $dim_cache_key, self::GROUP );
				}
			}
		}

		if ( $count > 0 ) {
			$db->record_view_bulk( $post_id, $day, $count, $dims );
		}
	}

	/**
	 * Register a "post_id|day" key in the flush index, at most once per
	 * flush interval: a "seen" flag (cleared on flush, same TTL as the flush
	 * interval) guards the `update_option()` call so a hot post only costs
	 * one option write per interval, not one per view.
	 *
	 * @param string $key "post_id|day".
	 * @return void
	 */
	private static function register( string $key ) {
		if ( ! wp_cache_add( self::seen_cache_key( $key ), 1, self::GROUP, Schema::BUFFER_FLUSH_INTERVAL ) ) {
			return;
		}

		$index = get_option( self::OPTION_INDEX, array() );

		if ( ! in_array( $key, $index, true ) ) {
			$index[] = $key;
			update_option( self::OPTION_INDEX, $index );
		}
	}

	/**
	 * Atomically increment a counter cache key, creating it first if missing.
	 *
	 * @param string $cache_key Full cache key.
	 * @return void
	 */
	private static function incr( string $cache_key ) {
		wp_cache_add( $cache_key, 0, self::GROUP );
		wp_cache_incr( $cache_key, 1, self::GROUP );
	}

	/**
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private static function buffer_key( int $post_id ): string {
		return $post_id . '|' . substr( current_time( 'mysql', true ), 0, 10 );
	}

	/**
	 * @param string $key "post_id|day".
	 * @return string
	 */
	private static function count_cache_key( string $key ): string {
		return 'count:' . $key;
	}

	/**
	 * @param string $key "post_id|day".
	 * @return string
	 */
	private static function seen_cache_key( string $key ): string {
		return 'seen:' . $key;
	}

	/**
	 * @param string $key       "post_id|day".
	 * @param string $dimension Dimension name.
	 * @param string $value     Dimension value.
	 * @return string
	 */
	private static function dim_cache_key( string $key, string $dimension, string $value ): string {
		return 'dim:' . $key . '|' . $dimension . '|' . $value;
	}
}
