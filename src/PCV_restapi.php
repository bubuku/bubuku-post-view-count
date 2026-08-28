<?php
/**
 * Restapi Class.
 *
 * @package Bubuku Post View Count
 * @author     Luis Ruiz <lruiz@bubuku.com>
 * @copyright  2022 Bubuku
 * @version    1.1.0
 */

declare( strict_types=1 );

namespace Bubuku\Plugins\PostViewCount;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

class PCV_restapi {

	/**
	 * How long (in seconds) a given post/visitor pair is deduplicated for.
	 */
	const DEDUPE_TTL = 30 * MINUTE_IN_SECONDS;

	/**
	 * @var PCV_db
	 */
	private $db;

	public function __construct() {
		$this->init();
	}

	private function init() {
		$this->db = new PCV_db();
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * We define the routes that our REST API will have.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			BBK_PLUGIN_ENDPOINTS_URL,
			'set-post-views',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'set_post_views' ),
				'args'                => array(
					'post_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'validate_callback' => array( $this, 'validate_post_id' ),
					),
				),
				// Anonymous by design: views must be countable for logged-out visitors
				// behind full-page caching. Abuse is mitigated with per-visitor
				// deduplication and strict post_id validation, not with a capability
				// check or a nonce (both are meaningless for anonymous traffic).
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Validate that the given post_id points to a real, publicly viewable post.
	 *
	 * @param mixed $param Raw request value.
	 * @return bool
	 */
	public function validate_post_id( $param ): bool {
		if ( ! is_numeric( $param ) ) {
			return false;
		}

		$post_id = absint( $param );

		return 'post' === get_post_type( $post_id )
			&& is_post_publicly_viewable( $post_id );
	}

	/**
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function set_post_views( WP_REST_Request $request ) {
		$post_id = absint( $request->get_param( 'post_id' ) );

		if ( $this->is_deduped( $post_id, $request ) ) {
			return new WP_REST_Response(
				array( 'count' => (int) get_post_meta( $post_id, 'views', true ) ),
				200
			);
		}

		$this->mark_deduped( $post_id, $request );

		$count = $this->db->set_post_views( $post_id );

		return new WP_REST_Response( array( 'count' => $count ), 200 );
	}

	/**
	 * Whether this visitor has already registered a view for this post recently.
	 *
	 * @param int              $post_id Post ID.
	 * @param WP_REST_Request  $request Current request.
	 * @return bool
	 */
	private function is_deduped( int $post_id, WP_REST_Request $request ): bool {
		return (bool) get_transient( $this->dedupe_key( $post_id, $request ) );
	}

	/**
	 * Mark this visitor/post pair as already counted.
	 *
	 * @param int              $post_id Post ID.
	 * @param WP_REST_Request  $request Current request.
	 * @return void
	 */
	private function mark_deduped( int $post_id, WP_REST_Request $request ) {
		set_transient( $this->dedupe_key( $post_id, $request ), 1, self::DEDUPE_TTL );
	}

	/**
	 * Build the transient key used to deduplicate a visitor for a given post.
	 *
	 * @param int              $post_id Post ID.
	 * @param WP_REST_Request  $request Current request.
	 * @return string
	 */
	private function dedupe_key( int $post_id, WP_REST_Request $request ): string {
		$ip         = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$user_agent = $request->get_header( 'user_agent' ) ? sanitize_text_field( $request->get_header( 'user_agent' ) ) : '';

		return 'bbk_view_' . md5( $post_id . '|' . $ip . '|' . $user_agent );
	}
}
