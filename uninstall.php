<?php
/*
 * Uninstall plugin
 */

if ( !defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	die( "Hello, Pepiño!" );
}

require_once 'vendor/autoload.php';
use Bubuku\Plugins\PostViewCount\PCV_db;


$plugin_db = new PCV_db();
// Remove all meta "views" from posts
$plugin_db->remove_all_post_meta();