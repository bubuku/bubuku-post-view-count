<?php
/**
 * Schema Class.
 *
 * @package Bubuku Post View Count
 * @author     Luis Ruiz <lruiz@bubuku.com>
 * @copyright  2022 Bubuku
 * @version    1.2.1
 */

declare( strict_types=1 );

namespace Bubuku\Plugins\PostViewCount\Core;

defined( 'ABSPATH' ) || exit;

class Schema {

	/**
	 * Current schema version. Bump when the table structure changes.
	 */
	const VERSION = 4;

	const OPTION_SCHEMA_VERSION = 'bbk_schema_version';

	/**
	 * Option storing the UTC datetime the daily aggregate table started collecting data.
	 * The migration from postmeta (§1.6) never backfills daily history, so any query with
	 * a time window needs this to tell callers — MCP tools in particular — how far back
	 * the data actually goes.
	 */
	const OPTION_DAILY_SINCE = 'bbk_postview_daily_since';

	const OPTION_MIGRATION_CURSOR = 'bbk_postview_migration_cursor';

	const OPTION_MIGRATION_STATUS = 'bbk_postview_migration_status';

	const OPTION_INGESTION_PAUSED = 'bbk_postview_ingestion_paused';

	const PURGE_CRON_HOOK = 'bbk_postview_purge_daily';

	const PURGE_CONTINUE_CRON_HOOK = 'bbk_postview_purge_continue';

	const PURGE_BATCH_SIZE = 10000;

	const MIGRATION_CRON_HOOK = 'bbk_postview_migrate_batch';

	const MIGRATION_BATCH_SIZE = 500;

	const DEFAULT_RETENTION_DAYS = 400;

	const MEASUREMENT_VERSION = 2;

	const MEASUREMENT_DEFINITION = 'five_cumulative_visible_seconds';

	public function __construct() {
		add_action( 'plugins_loaded', array( $this, 'maybe_upgrade' ) );
		add_action( self::PURGE_CRON_HOOK, array( $this, 'purge_daily' ) );
		add_action( self::PURGE_CONTINUE_CRON_HOOK, array( $this, 'purge_daily' ) );
		add_action( self::MIGRATION_CRON_HOOK, array( $this, 'migrate_batch' ) );
		add_action( 'wp_initialize_site', array( $this, 'install_on_new_site' ) );
	}

	/**
	 * Table name for the per-post aggregate.
	 *
	 * @return string
	 */
	public static function table_views(): string {
		global $wpdb;

		return $wpdb->prefix . 'bbk_post_views';
	}

	/**
	 * Table name for the daily aggregate.
	 *
	 * @return string
	 */
	public static function table_daily(): string {
		global $wpdb;

		return $wpdb->prefix . 'bbk_post_views_daily';
	}

	/**
	 * Table name for the session-dimensions aggregate (F5: viewport/referrer).
	 *
	 * @return string
	 */
	public static function table_dims(): string {
		global $wpdb;

		return $wpdb->prefix . 'bbk_post_view_dims';
	}

	/**
	 * Table name for the AI-crawler hit aggregate (F6, opt-in, separate from human traffic).
	 *
	 * @return string
	 */
	public static function table_ai_crawls(): string {
		global $wpdb;

		return $wpdb->prefix . 'bbk_post_ai_crawls';
	}

	/**
	 * Table name for short-lived, atomic view deduplication records.
	 *
	 * @return string
	 */
	public static function table_dedupe(): string {
		global $wpdb;

		return $wpdb->prefix . 'bbk_post_view_dedupe';
	}

	/**
	 * Runs on plugin activation.
	 *
	 * @param bool $network_wide Whether the plugin is being network-activated.
	 * @return void
	 */
	public function activate( bool $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			foreach ( get_sites( array( 'fields' => 'ids' ) ) as $site_id ) {
				switch_to_blog( (int) $site_id );
				$this->install_current_site();
				restore_current_blog();
			}

			return;
		}

		$this->install_current_site();
	}

	/**
	 * Runs on plugin deactivation. Cron hooks are always cleared — they are
	 * rescheduled on the next activation/upgrade if still needed.
	 *
	 * @return void
	 */
	public function deactivate() {
		wp_clear_scheduled_hook( self::PURGE_CRON_HOOK );
		wp_clear_scheduled_hook( self::PURGE_CONTINUE_CRON_HOOK );
		wp_clear_scheduled_hook( self::MIGRATION_CRON_HOOK );
	}

	/**
	 * Creates the schema on a newly created site in a multisite network.
	 *
	 * @param \WP_Site $new_site Newly created site.
	 * @return void
	 */
	public function install_on_new_site( $new_site ) {
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( ! is_plugin_active_for_network( plugin_basename( BBK_PLUGIN_FILE ) ) ) {
			return;
		}

		switch_to_blog( (int) $new_site->blog_id );
		$this->install_current_site();
		restore_current_blog();
	}

	/**
	 * Upgrades the schema on every request if the stored version is behind —
	 * covers updates via FTP/zip, where the activation hook never fires.
	 *
	 * @return void
	 */
	public function maybe_upgrade() {
		if ( (int) get_option( self::OPTION_SCHEMA_VERSION, 0 ) < self::VERSION ) {
			$this->install_current_site();
		}

		if ( (int) get_option( self::OPTION_SCHEMA_VERSION, 0 ) >= self::VERSION ) {
			$this->maybe_resume_migration();
		}
	}

	/**
	 * Creates the tables (idempotent via dbDelta) and schedules recurring jobs
	 * for the current site.
	 *
	 * @return void
	 */
	private function install_current_site() {
		$is_new_install = (int) get_option( self::OPTION_SCHEMA_VERSION, 0 ) < self::VERSION;

		$this->create_tables();

		if ( ! $this->tables_exist() ) {
			update_option( self::OPTION_MIGRATION_STATUS, 'failed', false );

			return;
		}

		$this->schedule_purge();

		if ( $is_new_install ) {
			$this->schedule_migration();
			update_option( self::OPTION_SCHEMA_VERSION, self::VERSION );
			add_option( self::OPTION_DAILY_SINCE, current_time( 'mysql', true ) );
		}
	}

	/**
	 * UTC datetime the daily aggregate started collecting data, or null if this site
	 * somehow never recorded it (should not happen from 1.2.1 onward).
	 *
	 * @return string|null
	 */
	public static function daily_data_since(): ?string {
		$value = get_option( self::OPTION_DAILY_SINCE, '' );

		return '' === $value ? null : (string) $value;
	}

	/**
	 * Creates (or upgrades) the plugin tables via dbDelta().
	 *
	 * @return void
	 */
	private function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$views_table     = self::table_views();
		$daily_table     = self::table_daily();
		$dims_table      = self::table_dims();
		$ai_crawls_table = self::table_ai_crawls();
		$dedupe_table    = self::table_dedupe();

		$sql = "CREATE TABLE {$views_table} (
			post_id BIGINT UNSIGNED NOT NULL,
			views BIGINT UNSIGNED NOT NULL DEFAULT 0,
			first_viewed_at DATETIME NULL,
			last_viewed_at DATETIME NULL,
			PRIMARY KEY  (post_id),
			KEY views (views),
			KEY last_viewed_at (last_viewed_at)
		) {$charset_collate};
		CREATE TABLE {$daily_table} (
			post_id BIGINT UNSIGNED NOT NULL,
			day DATE NOT NULL,
			views INT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (post_id, day),
			KEY day_views (day, views)
		) {$charset_collate};
		CREATE TABLE {$dims_table} (
			post_id BIGINT UNSIGNED NOT NULL,
			day DATE NOT NULL,
			dimension VARCHAR(20) NOT NULL,
			value VARCHAR(100) NOT NULL,
			views INT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (post_id, day, dimension, value),
			KEY day_dimension_value (day, dimension, value)
		) {$charset_collate};
		CREATE TABLE {$ai_crawls_table} (
			post_id BIGINT UNSIGNED NOT NULL,
			day DATE NOT NULL,
			bot VARCHAR(50) NOT NULL,
			views INT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (post_id, day, bot),
			KEY day_bot (day, bot)
		) {$charset_collate};
		CREATE TABLE {$dedupe_table} (
			token_hash CHAR(64) NOT NULL,
			network_hash CHAR(64) NOT NULL,
			post_id BIGINT UNSIGNED NOT NULL,
			created_at DATETIME NOT NULL,
			expires_at DATETIME NOT NULL,
			PRIMARY KEY  (token_hash),
			KEY expires_at (expires_at),
			KEY network_created (network_hash, created_at),
			KEY network_post_created (network_hash, post_id, created_at)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Verify dbDelta created every required table before advancing the schema.
	 *
	 * @return bool
	 */
	private function tables_exist(): bool {
		global $wpdb;

		foreach ( array( self::table_views(), self::table_daily(), self::table_dims(), self::table_ai_crawls(), self::table_dedupe() ) as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Installation verification must inspect the live schema.
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
			if ( $table !== $found ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Schedules the daily purge of the daily aggregate table, if not already scheduled.
	 *
	 * @return void
	 */
	private function schedule_purge() {
		if ( ! wp_next_scheduled( self::PURGE_CRON_HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::PURGE_CRON_HOOK );
		}
	}

	/**
	 * Schedules the one-shot, self-rescheduling migration of postmeta → table.
	 *
	 * @return void
	 */
	private function schedule_migration() {
		if ( 'complete' === get_option( self::OPTION_MIGRATION_STATUS, '' ) ) {
			return;
		}

		update_option( self::OPTION_MIGRATION_STATUS, 'pending', false );

		if ( ! wp_next_scheduled( self::MIGRATION_CRON_HOOK ) ) {
			$cursor = (int) get_option( self::OPTION_MIGRATION_CURSOR, 0 );
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::MIGRATION_CRON_HOOK, array( $cursor ) );
		}
	}

	/**
	 * Restores a lost one-shot migration event while work remains.
	 *
	 * @return void
	 */
	private function maybe_resume_migration() {
		$status = (string) get_option( self::OPTION_MIGRATION_STATUS, '' );

		if ( ! in_array( $status, array( 'pending', 'failed' ), true ) || wp_next_scheduled( self::MIGRATION_CRON_HOOK ) ) {
			return;
		}

		$cursor = (int) get_option( self::OPTION_MIGRATION_CURSOR, 0 );
		wp_schedule_single_event( time() + ( 5 * MINUTE_IN_SECONDS ), self::MIGRATION_CRON_HOOK, array( $cursor ) );
	}

	/**
	 * Copies one batch of legacy `views` post meta into the aggregate table.
	 * Idempotent: re-running never duplicates or double-counts, and reschedules
	 * itself until every row has been copied.
	 *
	 * @param int $cursor Last processed postmeta meta_id.
	 * @return void
	 */
	public function migrate_batch( int $cursor = 0 ) {
		global $wpdb;

		$views_table = self::table_views();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batched one-off migration, no equivalent WP API; each batch reads a fresh offset, so caching would be incorrect.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_id, post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = 'views' AND meta_id > %d ORDER BY meta_id LIMIT %d",
				$cursor,
				self::MIGRATION_BATCH_SIZE
			)
		);

		if ( empty( $rows ) ) {
			if ( ! empty( $wpdb->last_error ) ) {
				update_option( self::OPTION_MIGRATION_STATUS, 'failed', false );
				$this->maybe_resume_migration();

				return;
			}

			update_option( self::OPTION_MIGRATION_STATUS, 'complete', false );

			return;
		}

		$last_meta_id = $cursor;

		foreach ( $rows as $row ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- One-off migration upsert on the plugin's own table, no equivalent WP API; a running counter must never be served from cache. $views_table is an internal constant (self::table_views()), never user input.
			$result = $wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$views_table} is an internal constant (self::table_views()), never user input.
					"INSERT INTO {$views_table} (post_id, views) VALUES (%d, %d) ON DUPLICATE KEY UPDATE views = GREATEST(views, VALUES(views))",
					(int) $row->post_id,
					(int) $row->meta_value
				)
			);

			if ( false === $result ) {
				update_option( self::OPTION_MIGRATION_STATUS, 'failed', false );
				update_option( self::OPTION_MIGRATION_CURSOR, $last_meta_id, false );
				$this->maybe_resume_migration();

				return;
			}

			$last_meta_id = (int) $row->meta_id;
		}

		update_option( self::OPTION_MIGRATION_CURSOR, $last_meta_id, false );
		update_option( self::OPTION_MIGRATION_STATUS, 'pending', false );

		if ( count( $rows ) === self::MIGRATION_BATCH_SIZE ) {
			wp_schedule_single_event(
				time() + MINUTE_IN_SECONDS,
				self::MIGRATION_CRON_HOOK,
				array( $last_meta_id )
			);

			return;
		}

		update_option( self::OPTION_MIGRATION_STATUS, 'complete', false );
	}

	/**
	 * Deletes daily aggregate rows older than the configured retention window.
	 *
	 * @return void
	 */
	public function purge_daily() {
		global $wpdb;

		$settings       = get_option( 'bbk_postview_settings', array() );
		$retention_days = isset( $settings['retention_days'] ) ? (int) $settings['retention_days'] : self::DEFAULT_RETENTION_DAYS;
		$retention_days = max( 1, min( 3650, $retention_days ) );
		$purged         = array();

		$purged[] = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Scheduled maintenance on the plugin's own table has no equivalent WP API and must not be cached.
			$wpdb->prepare(
				'DELETE FROM %i WHERE day < DATE_SUB(UTC_DATE(), INTERVAL %d DAY) LIMIT %d',
				self::table_daily(),
				$retention_days,
				self::PURGE_BATCH_SIZE
			)
		);

		$purged[] = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Scheduled maintenance on the plugin's own table has no equivalent WP API and must not be cached.
			$wpdb->prepare(
				'DELETE FROM %i WHERE day < DATE_SUB(UTC_DATE(), INTERVAL %d DAY) LIMIT %d',
				self::table_dims(),
				$retention_days,
				self::PURGE_BATCH_SIZE
			)
		);

		$purged[] = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Scheduled maintenance on the plugin's own table has no equivalent WP API and must not be cached.
			$wpdb->prepare(
				'DELETE FROM %i WHERE day < DATE_SUB(UTC_DATE(), INTERVAL %d DAY) LIMIT %d',
				self::table_ai_crawls(),
				$retention_days,
				self::PURGE_BATCH_SIZE
			)
		);

		$purged[] = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Scheduled maintenance on the plugin's own table has no equivalent WP API and must not be cached.
			$wpdb->prepare(
				'DELETE FROM %i WHERE expires_at < UTC_TIMESTAMP() LIMIT %d',
				self::table_dedupe(),
				self::PURGE_BATCH_SIZE
			)
		);

		if ( in_array( self::PURGE_BATCH_SIZE, $purged, true ) && ! wp_next_scheduled( self::PURGE_CONTINUE_CRON_HOOK ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::PURGE_CONTINUE_CRON_HOOK );
		}
	}
}
