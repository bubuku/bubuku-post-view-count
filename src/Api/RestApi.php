<?php
/**
 * Restapi Class.
 *
 * @package Bubuku Post View Count
 * @author     Luis Ruiz <lruiz@bubuku.com>
 * @copyright  2022 Bubuku
 * @version    1.2.1
 */

declare( strict_types=1 );

namespace Bubuku\Plugins\PostViewCount\Api;

use Bubuku\Plugins\PostViewCount\Admin\Settings;
use Bubuku\Plugins\PostViewCount\Core\Db;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

class RestApi {

	/**
	 * How long (in seconds) a given post/visitor pair is deduplicated for.
	 */
	const DEDUPE_TTL = 30 * MINUTE_IN_SECONDS;

	/**
	 * @var Db
	 */
	private $db;

	public function __construct() {
		$this->init();
	}

	private function init() {
		$this->db = new Db();
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
				// deduplication, strict post_id validation and a same-origin check.
				'permission_callback' => array( $this, 'check_request_origin' ),
			)
		);
	}

	/**
	 * Allow view increments only from pages served by this WordPress site.
	 *
	 * This prevents cross-origin browser requests. Origin and Referer are client
	 * headers, so deduplication remains necessary to limit non-browser abuse.
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return true|WP_Error
	 */
	public function check_request_origin( WP_REST_Request $request ) {
		$request_url = (string) $request->get_header( 'origin' );

		if ( '' === $request_url ) {
			$request_url = (string) $request->get_header( 'referer' );
		}

		if ( '' !== $request_url && $this->origin_from_url( $request_url ) === $this->origin_from_url( home_url( '/' ) ) ) {
			return true;
		}

		return new WP_Error(
			'bbk_postview_forbidden_origin',
			__( 'View increments are only accepted from this website.', 'bubuku-post-view-count' ),
			array( 'status' => 403 )
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

		return in_array( get_post_type( $post_id ), Settings::enabled_post_types(), true )
			&& is_post_publicly_viewable( $post_id );
	}

	/**
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function set_post_views( WP_REST_Request $request ) {
		$post_id = absint( $request->get_param( 'post_id' ) );

		if ( $this->is_deduped( $post_id, $request ) || $this->is_bot_request( $request ) ) {
			return new WP_REST_Response( $this->response_data( $this->db->get_stats( $post_id ) ), 200 );
		}

		$this->mark_deduped( $post_id, $request );

		$stats = $this->db->record_view( $post_id );

		return new WP_REST_Response( $this->response_data( $stats ), 200 );
	}

	/**
	 * Shape the public REST response — keeps the historical `count` key and adds
	 * `last_viewed_at` now that the aggregate table tracks it.
	 *
	 * @param array $stats Stats as returned by Db::get_stats()/record_view().
	 * @return array{count:int,last_viewed_at:?string}
	 */
	private function response_data( array $stats ): array {
		return array(
			'count'          => $stats['views'],
			'last_viewed_at' => $stats['last_viewed_at'],
		);
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
	 * Whether this request should be ignored as coming from a known bot,
	 * per the `exclude_bots` setting (Admin\Settings). Views from bots are
	 * never recorded, but the response still reflects the current stats.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return bool
	 */
	private function is_bot_request( WP_REST_Request $request ): bool {
		if ( ! Settings::exclude_bots() ) {
			return false;
		}

		return Settings::is_bot_user_agent( $request->get_header( 'user_agent' ) );
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

	/**
	 * Extract a normalized scheme, host and port from a URL.
	 *
	 * @param string $url URL to normalize.
	 * @return string
	 */
	private function origin_from_url( string $url ): string {
		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}

		$scheme = strtolower( (string) $parts['scheme'] );
		$host   = strtolower( (string) $parts['host'] );
		$port   = isset( $parts['port'] ) ? absint( $parts['port'] ) : 0;

		if ( ( 'http' === $scheme && 80 === $port ) || ( 'https' === $scheme && 443 === $port ) ) {
			$port = 0;
		}

		return $scheme . '://' . $host . ( $port ? ':' . $port : '' );
	}
}
