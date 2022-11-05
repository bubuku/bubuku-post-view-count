<?php
/**
 * Restapi Class.
 *
 * @package Bubuku Post View Count
 * @author     Luis Ruiz <lruiz@bubuku.com>
 * @copyright  2022 Bubuku
 * @version    1.0.2
 */

namespace Bubuku\Plugins\PostViewCount;

defined( 'ABSPATH') || die( 'Hello, Pepiño!' );

class PCV_restapi {
    
    private $_namespace;
    private $_db;

    public function __construct() {
		$this->init();
	}

	private function init() {
		$this->_namespace = 'bbk_postview/v1';
        $this->_db = new PCV_db();
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

    /**
    * register_routes
    * 
    * We define the routes that our REST API will have.
    *
    * @return void
    */
    public function register_routes() {
        register_rest_route( $this->_namespace, 'set-post-views', array(
            'methods'  => 'POST',
            'callback' => array ($this, 'set_post_views'),
            'args'     => array('post_id'),
            'permission_callback' => '__return_true'
        ));
    }

    /**
         * set_post_views
         * 
         * @param WP_REST_Request $request Full data about the request.
         *
         * @return void 
         */
        public function set_post_views($request) {
            $post_id = $request['post_id'];
            $nonce = $request['_wpnonce'];

            if ( BBK_PLUGIN_NONCE !== $nonce && empty($post_id)) {
                wp_send_json_error( __('empty Post ID', 'bubuku-post-view-count') );
                die();
            }

            $data = $this->_db->set_post_views($post_id);

            wp_send_json_success(array('count' => $data));
            die();
            
            return $data;
        }
}