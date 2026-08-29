<?php
/**
 * Uninstall plugin
 */

declare( strict_types=1 );

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/src/Core/Db.php';
require_once __DIR__ . '/src/Core/Schema.php';

use Bubuku\Plugins\PostViewCount\Core\Db;
use Bubuku\Plugins\PostViewCount\Core\Schema;

/**
 * Clean up the current site: always clears the cron hooks (never leave one
 * orphaned), and only drops data when the "delete on uninstall" setting is on
 * (default true — see docs/ANALYTICS-PLAN.md §1.8). uninstall.php cannot ask
 * anything; the decision has to already be stored in an option.
 *
 * @return void
 */
function bbk_uninstall_current_site() {
	wp_clear_scheduled_hook( Schema::PURGE_CRON_HOOK );
	wp_clear_scheduled_hook( Schema::MIGRATION_CRON_HOOK );

	$settings    = get_option( 'bbk_postview_settings', array() );
	$delete_data = $settings['delete_data_on_uninstall'] ?? true;

	if ( ! $delete_data ) {
		return;
	}

	$db = new Db();
	$db->drop_tables();
	$db->remove_all_post_meta();

	delete_option( 'bbk_postview_settings' );
	delete_option( Schema::OPTION_SCHEMA_VERSION );
	delete_option( Schema::OPTION_DAILY_SINCE );

	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall-only cleanup of leftover transient options; no object cache is available during uninstall.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_bbk_view_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_bbk_view_' ) . '%'
		)
	);
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids' ) ) as $bbk_site_id ) {
		switch_to_blog( (int) $bbk_site_id );
		bbk_uninstall_current_site();
		restore_current_blog();
	}
} else {
	bbk_uninstall_current_site();
}
