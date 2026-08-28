<?php
/**
 * Uninstall plugin
 */

declare( strict_types=1 );

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/src/PCV_db.php';

use Bubuku\Plugins\PostViewCount\PCV_db;

$bbk_plugin_db = new PCV_db();

if ( is_multisite() ) {
	$bbk_site_ids = get_sites( array( 'fields' => 'ids' ) );

	foreach ( $bbk_site_ids as $bbk_site_id ) {
		switch_to_blog( $bbk_site_id );
		$bbk_plugin_db->remove_all_post_meta();
		restore_current_blog();
	}
} else {
	$bbk_plugin_db->remove_all_post_meta();
}
