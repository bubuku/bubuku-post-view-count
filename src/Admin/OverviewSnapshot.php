<?php
/**
 * OverviewSnapshot Class.
 *
 * @package Bubuku Post View Count
 * @author     Luis Ruiz <lruiz@bubuku.com>
 * @copyright  2022 Bubuku
 * @version    1.3.0
 */

declare( strict_types=1 );

namespace Bubuku\Plugins\PostViewCount\Admin;

use Bubuku\Plugins\PostViewCount\Core\Query;

defined( 'ABSPATH' ) || exit;

/**
 * Stale-while-revalidate cache for `DashboardWidget` (docs/DASHBOARD-SUMMARY-PLAN.md §4),
 * so `wp-admin/index.php` never pays for `Query::overview()` in its own request. Same
 * pattern as `Bubuku-Media-Library`'s `BML_reports::get_img_summary_cached()`: a snapshot
 * option (autoload 'no', read on every dashboard load) refreshed by a single-event cron a
 * few seconds after it goes stale, with a short lock so concurrent requests don't all
 * trigger a rebuild at once.
 */
class OverviewSnapshot {

	/**
	 * Option holding the last computed snapshot. Autoload 'no' — it is only ever
	 * read from wp-admin/index.php, never on the front end.
	 */
	const OPTION_SNAPSHOT = 'bbk_postview_overview_snapshot';

	/**
	 * Short-lived transient preventing two concurrent synchronous rebuilds.
	 */
	const LOCK_TRANSIENT = 'bbk_postview_overview_snapshot_lock';

	const LOCK_TTL = 120; // 2 min.

	/**
	 * Cron hook that asynchronously refreshes a stale snapshot.
	 */
	const REFRESH_HOOK = 'bbk_postview_overview_snapshot_refresh';

	/**
	 * How long a snapshot is served as-is before a refresh is scheduled.
	 */
	const TTL = 900; // 15 min.

	/**
	 * Hard ceiling on how long a snapshot can be served stale before this class stops
	 * trusting WP-Cron and rebuilds synchronously instead (docs/PENDING-1.3.1-FIXES.md
	 * §4). Without this, a site with WP-Cron disabled and no system cron, or with
	 * loopback requests blocked (self-signed local certs, staging behind basic auth),
	 * never runs `REFRESH_HOOK` again after the first stale read — the widget then
	 * shows the same numbers forever. Four times the TTL gives WP-Cron plenty of
	 * chances to catch up before this fallback ever triggers on a healthy site.
	 */
	const MAX_STALE = self::TTL * 4; // 1 hour.

	/**
	 * Per-request guard: a bulk import triggering mark_stale() on every save_post
	 * must not schedule the same single event dozens of times in one request.
	 *
	 * @var bool
	 */
	private static $refresh_scheduled = false;

	public function __construct() {
		add_action( self::REFRESH_HOOK, array( $this, 'refresh' ) );
	}

	/**
	 * Returns the snapshot to render, rebuilding synchronously on the first call this
	 * ever runs on a site, and again whenever an existing snapshot has gone past
	 * `MAX_STALE` (WP-Cron never ran, or ran and failed silently). Every other call is
	 * either a fresh read from the option, a stale-but-usable read (with an async
	 * refresh scheduled), or a "warming" placeholder while another request is doing a
	 * synchronous build.
	 *
	 * @return array
	 */
	public static function get_cached(): array {
		$snapshot = get_option( self::OPTION_SNAPSHOT, null );

		if ( is_array( $snapshot ) && isset( $snapshot['generated_at'] ) ) {
			$age = time() - (int) $snapshot['generated_at'];

			if ( $age < self::TTL ) {
				return $snapshot;
			}

			if ( $age < self::MAX_STALE ) {
				self::schedule_refresh();
				$snapshot['stale'] = true;

				return $snapshot;
			}

			// Past MAX_STALE: whatever kept the cron refresh from running has had four
			// TTL windows to recover on its own and hasn't, so serve current data now
			// rather than keep trusting it. Still respects the build lock — a stuck
			// cron doesn't mean it's safe to run this query on every concurrent request.
			if ( get_transient( self::LOCK_TRANSIENT ) ) {
				$snapshot['stale'] = true;

				return $snapshot;
			}

			return self::rebuild_synchronously();
		}

		if ( get_transient( self::LOCK_TRANSIENT ) ) {
			return self::warming_payload();
		}

		return self::rebuild_synchronously();
	}

	/**
	 * Builds a fresh snapshot and persists it, under the same short-lived lock used to
	 * stop concurrent requests from all rebuilding at once — whether this is the very
	 * first build a site ever needs, or a MAX_STALE fallback when the cron refresh
	 * hasn't run.
	 *
	 * @return array
	 */
	private static function rebuild_synchronously(): array {
		set_transient( self::LOCK_TRANSIENT, 1, self::LOCK_TTL );

		try {
			$fresh = self::build();
			self::persist( $fresh );
		} finally {
			delete_transient( self::LOCK_TRANSIENT );
		}

		return $fresh;
	}

	/**
	 * Cron callback for self::REFRESH_HOOK.
	 *
	 * @return void
	 */
	public function refresh() {
		self::persist( self::build() );
	}

	/**
	 * Called wherever `Query::invalidate_cache()` already is (save_post, deleted_post,
	 * settings save/reset) so the widget doesn't keep showing pre-change numbers for
	 * up to 15 minutes. Never rebuilds synchronously on the triggering request — only
	 * schedules the async refresh, same contract as a normal TTL expiry.
	 *
	 * @return void
	 */
	public static function mark_stale() {
		self::schedule_refresh();
	}

	/**
	 * Removes the snapshot, its lock and its pending refresh — used when the plugin's
	 * data is reset (`DELETE /settings/data`) or the schema is torn down.
	 *
	 * @return void
	 */
	public static function clear() {
		delete_option( self::OPTION_SNAPSHOT );
		delete_transient( self::LOCK_TRANSIENT );
		wp_clear_scheduled_hook( self::REFRESH_HOOK );
	}

	/**
	 * @return void
	 */
	private static function schedule_refresh() {
		if ( self::$refresh_scheduled ) {
			return;
		}

		self::$refresh_scheduled = true;

		if ( ! wp_next_scheduled( self::REFRESH_HOOK ) ) {
			wp_schedule_single_event( time() + 5, self::REFRESH_HOOK );
		}
	}

	/**
	 * Computes a fresh snapshot from `Query::overview()`. Numbers are pre-formatted
	 * with `number_format_i18n()` here so `DashboardWidget::render()` never formats
	 * anything itself, keeping the render path a pure, cheap echo of stored strings.
	 *
	 * @return array
	 */
	private static function build(): array {
		$overview = Query::overview();

		return array(
			'generated_at'                => time(),
			'stale'                       => false,
			'warming'                     => false,
			'total_views'                 => $overview['total_views'],
			'total_views_formatted'       => number_format_i18n( $overview['total_views'] ),
			'current_views'               => $overview['current_views'],
			'current_views_formatted'     => number_format_i18n( $overview['current_views'] ),
			'delta'                       => $overview['delta'],
			'delta_pct'                   => $overview['delta_pct'],
			'ai_referral_views'           => $overview['ai_referral_views'],
			'ai_referral_views_formatted' => number_format_i18n( $overview['ai_referral_views'] ),
			'ai_crawler_views'            => $overview['ai_crawler_views'],
			'ai_crawler_views_formatted'  => number_format_i18n( $overview['ai_crawler_views'] ),
			'ai_crawler_tracking_enabled' => Settings::ai_crawler_tracking(),
			'last_7_days'                 => $overview['last_7_days'],
		);
	}

	/**
	 * Zeroed placeholder returned only while a concurrent request is doing the very
	 * first synchronous build a site will ever need — never seen again once a
	 * snapshot exists, since every later path serves stale data instead of this.
	 *
	 * @return array
	 */
	private static function warming_payload(): array {
		$today = current_time( 'Y-m-d', true );
		$days  = array();

		for ( $i = 6; $i >= 0; $i-- ) {
			$days[] = array(
				'day'   => gmdate( 'Y-m-d', strtotime( "{$today} -{$i} days" ) ),
				'views' => 0,
			);
		}

		return array(
			'generated_at'                => 0,
			'stale'                       => true,
			'warming'                     => true,
			'total_views'                 => 0,
			'total_views_formatted'       => '—',
			'current_views'               => 0,
			'current_views_formatted'     => '—',
			'delta'                       => 0,
			'delta_pct'                   => null,
			'ai_referral_views'           => 0,
			'ai_referral_views_formatted' => '—',
			'ai_crawler_views'            => 0,
			'ai_crawler_views_formatted'  => '—',
			'ai_crawler_tracking_enabled' => Settings::ai_crawler_tracking(),
			'last_7_days'                 => $days,
		);
	}

	/**
	 * @param array $snapshot Snapshot to persist.
	 * @return void
	 */
	private static function persist( array $snapshot ) {
		update_option( self::OPTION_SNAPSHOT, $snapshot, false );
	}
}
