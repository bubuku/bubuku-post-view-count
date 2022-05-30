<?php
/**
 * Db Class.
 *
 * @package Bubuku Post View Count
 * @author     Luis Ruiz <lruiz@bubuku.com>
 * @copyright  2022 Bubuku
 * @version    1.0.1
 */

namespace Bubuku\Plugins\PostViewCount;

defined( 'ABSPATH') || die( 'Hello, Pepiño!' );

class PCV_db {

    public function __construct() {
		$this->init();
	}

	private function init() {

    }

    /**
     * set_post_views
     *
     * @param string $post_id
     * @return string
     */
    public function set_post_views($post_id){
        $count = get_post_meta( $post_id, 'views', true );

        if ( $count == '' ) {
            delete_post_meta( $post_id, 'views' );
            add_post_meta( $post_id, 'views', 1 );
        } else {
            update_post_meta( $post_id, 'views', ++$count );
        }

        return $count;
    }

    /**
     * Remove all meta "views" from posts
     *
     * @return void
     */
    public function remove_all_post_meta(){
        global $wpdb;
        $wpdb->query( "DELETE FROM $wpdb->postmeta WHERE meta_key = 'views'" );
        return true;
    }
}