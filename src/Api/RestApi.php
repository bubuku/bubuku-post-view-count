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
use Bubuku\Plugins\PostViewCount\Core\Dimensions;
use Bubuku\Plugins\PostViewCount\Core\Schema;
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
					'post_id'             => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'validate_callback' => array( $this, 'validate_post_id' ),
					),
					// Optional (F5): already-classified session dimensions sent by
					// assets/js/common.js. No validate_callback — an unknown/invalid
					// value must never fail the whole request, only be dropped
					// silently before it's written (see set_post_views()).
					'viewport'            => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'referrer'            => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					// Only meaningful (and only ever sent) alongside referrer='ai' —
					// see assets/js/common.js getAiAssistantClass().
					'ai_assistant'        => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'client_version'      => array(
						'required'          => false,
						'type'              => 'string',
						'maxLength'         => 20,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'measurement_version' => array(
						'required' => false,
						'type'     => 'integer',
						'enum'     => array( Schema::MEASUREMENT_VERSION ),
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
		$content_length = absint( $request->get_header( 'content_length' ) );
		$content_type   = strtolower( trim( (string) $request->get_header( 'content_type' ) ) );

		if ( $content_length > 2048 ) {
			return new WP_Error( 'bbk_postview_payload_too_large', __( 'The request body is too large.', 'bubuku-post-view-count' ), array( 'status' => 413 ) );
		}

		if ( '' !== $content_type && 0 !== strpos( $content_type, 'application/json' ) ) {
			return new WP_Error( 'bbk_postview_unsupported_media_type', __( 'Only JSON requests are accepted.', 'bubuku-post-view-count' ), array( 'status' => 415 ) );
		}

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

		if ( get_option( Schema::OPTION_INGESTION_PAUSED, false ) ) {
			return new WP_Error( 'bbk_postview_ingestion_paused', __( 'View counting is temporarily paused.', 'bubuku-post-view-count' ), array( 'status' => 503 ) );
		}

		if ( $this->is_bot_request( $request ) ) {
			return new WP_REST_Response( $this->response_data( $this->db->get_stats( $post_id ), false ), 200 );
		}

		$result = $this->db->record_unique_view(
			$post_id,
			$this->visitor_token( $post_id, $request ),
			$this->network_token(),
			self::DEDUPE_TTL,
			$this->valid_dims( $request )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $this->response_data( $result['stats'], $result['accepted'] ), 200 );
	}

	/**
	 * Build the dimension => value pairs to record, keeping only values that
	 * pass the closed whitelist (Dimensions::values_for()). An absent or
	 * unknown value is dropped silently — it never fails the request.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return array<string,string>
	 */
	private function valid_dims( WP_REST_Request $request ): array {
		if ( Settings::respect_dnt() && $this->has_privacy_signal( $request ) ) {
			return array();
		}

		$dims = array();

		foreach ( Dimensions::DIMENSIONS as $dimension ) {
			$value = (string) $request->get_param( $dimension );

			if ( '' !== $value && in_array( $value, Dimensions::values_for( $dimension ), true ) ) {
				$dims[ $dimension ] = $value;
			}
		}

		return $dims;
	}

	/**
	 * Shape the public REST response — keeps the historical `count` key and adds
	 * `last_viewed_at` now that the aggregate table tracks it.
	 *
	 * @param array $stats    Stats as returned by Db::get_stats()/record_view().
	 * @param bool  $accepted Whether this request created a new durable view.
	 * @return array{count:int,last_viewed_at:?string,accepted:bool,measurement_version:int}
	 */
	private function response_data( array $stats, bool $accepted ): array {
		return array(
			'count'               => $stats['views'],
			'last_viewed_at'      => $stats['last_viewed_at'],
			'accepted'            => $accepted,
			'measurement_version' => Schema::MEASUREMENT_VERSION,
		);
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
	 * Whether the browser sent a DNT or Sec-GPC privacy signal with this
	 * request. `assets/js/common.js` already omits `viewport`/`referrer`
	 * client-side when it detects one, so this is a server-side defense in
	 * depth: both headers are attached by the browser itself to every
	 * outgoing request (including `navigator.sendBeacon()`), so they reach
	 * the endpoint even for a visitor whose client somehow skipped the JS
	 * check (docs/ANALYTICS-PLAN.md §F7). Never affects the view count
	 * itself — only whether dims get written.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return bool
	 */
	private function has_privacy_signal( WP_REST_Request $request ): bool {
		return '1' === $request->get_header( 'dnt' ) || '1' === $request->get_header( 'sec_gpc' );
	}

	/**
	 * Build a non-reversible visitor/post token. No raw network address or user
	 * agent is persisted.
	 *
	 * @param int              $post_id Post ID.
	 * @param WP_REST_Request  $request Current request.
	 * @return string
	 */
	private function visitor_token( int $post_id, WP_REST_Request $request ): string {
		$user_agent = sanitize_text_field( $request->get_header( 'user_agent' ) );

		return hash_hmac( 'sha256', $post_id . '|' . $this->client_ip() . '|' . $user_agent, wp_salt( 'nonce' ) );
	}

	/**
	 * Build a separate network token for aggregate rate controls and diagnostics.
	 *
	 * @return string
	 */
	private function network_token(): string {
		return hash_hmac( 'sha256', $this->client_ip(), wp_salt( 'nonce' ) );
	}

	/**
	 * Read the direct peer address. Proxy headers are intentionally ignored
	 * unless a trusted proxy integration normalizes REMOTE_ADDR upstream.
	 *
	 * @return string
	 */
	private function client_ip(): string {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
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
