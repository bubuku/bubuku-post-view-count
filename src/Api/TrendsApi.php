<?php
/**
 * TrendsApi Class.
 *
 * @package Bubuku Post View Count
 * @author     Luis Ruiz <lruiz@bubuku.com>
 * @copyright  2022 Bubuku
 * @version    1.2.1
 */

declare( strict_types=1 );

namespace Bubuku\Plugins\PostViewCount\Api;

use Bubuku\Plugins\PostViewCount\Core\Dimensions;
use Bubuku\Plugins\PostViewCount\Core\Query;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only, cacheable REST endpoint over `Core\Query::trend()` (docs/ANALYTICS-PLAN.md §4,
 * F4). Separate from `Api\RestApi` on purpose: that class is the public, anonymous,
 * write-only view counter with its own same-origin/dedupe security model — this one is
 * capability-gated and read-only, a different concern that must not be mixed into it.
 */
class TrendsApi {

	const MAX_DATE_RANGE_DAYS = 366;

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			BBK_PLUGIN_ENDPOINTS_URL,
			'trends',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_trends' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'post_ids'    => array(
						'type'     => 'array',
						'maxItems' => 100,
						'items'    => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
					),
					'post_types'  => array(
						'type'     => 'array',
						'maxItems' => 20,
						'items'    => array( 'type' => 'string' ),
					),
					'granularity' => array(
						'type'    => 'string',
						'enum'    => array( 'day', 'week', 'month' ),
						'default' => 'day',
					),
					'from'        => array(
						'type'              => 'string',
						'validate_callback' => array( $this, 'validate_date' ),
					),
					'to'          => array(
						'type'              => 'string',
						'validate_callback' => array( $this, 'validate_date' ),
					),
				),
			)
		);

		register_rest_route(
			BBK_PLUGIN_ENDPOINTS_URL,
			'trends/momentum',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_momentum' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'post_types'  => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
					'period_days' => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => Query::MAX_PERIOD_DAYS,
						'default' => 30,
					),
					'limit'       => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => Query::MAX_LIMIT,
						'default' => 10,
					),
					'min_views'   => array(
						'type'    => 'integer',
						'minimum' => 0,
						'default' => 1,
					),
				),
			)
		);

		register_rest_route(
			BBK_PLUGIN_ENDPOINTS_URL,
			'trends/dims',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_dims_breakdown' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'dimension'  => array(
						'type'     => 'string',
						'enum'     => Dimensions::DIMENSIONS,
						'required' => true,
					),
					'post_types' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
					'since'      => array(
						'type'              => 'string',
						'validate_callback' => array( $this, 'validate_date' ),
					),
					'until'      => array(
						'type'              => 'string',
						'validate_callback' => array( $this, 'validate_date' ),
					),
				),
			)
		);

		register_rest_route(
			BBK_PLUGIN_ENDPOINTS_URL,
			'trends/ai-traffic',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_ai_traffic' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'post_types' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
					'since'      => array(
						'type'              => 'string',
						'validate_callback' => array( $this, 'validate_date' ),
					),
					'until'      => array(
						'type'              => 'string',
						'validate_callback' => array( $this, 'validate_date' ),
					),
					'limit'      => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => Query::MAX_LIMIT,
						'default' => 10,
					),
				),
			)
		);
	}

	/**
	 * Only readers who can already manage post content see traffic figures.
	 *
	 * @return bool
	 */
	public function check_permission(): bool {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Accept only real calendar dates in the REST query contract.
	 *
	 * @param mixed $value Requested value.
	 * @return bool
	 */
	public function validate_date( $value ): bool {
		if ( ! is_string( $value ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return false;
		}

		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value );

		return false !== $date && $date->format( 'Y-m-d' ) === $value;
	}

	/**
	 * Reject inverted or excessively large explicit date ranges.
	 *
	 * @return WP_Error|null
	 */
	private function range_error( ?string $from, ?string $to ): ?WP_Error {
		if ( null === $from || null === $to ) {
			return null;
		}

		$from_date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $from );
		$to_date   = \DateTimeImmutable::createFromFormat( '!Y-m-d', $to );

		if ( false === $from_date || false === $to_date || $from_date > $to_date || $from_date->diff( $to_date )->days > self::MAX_DATE_RANGE_DAYS ) {
			return new WP_Error( 'bbk_postview_invalid_date_range', __( 'The date range is invalid or too large.', 'bubuku-post-view-count' ), array( 'status' => 400 ) );
		}

		return null;
	}

	/**
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_REST_Response
	 */
	public function get_trends( WP_REST_Request $request ) {
		$range_error = $this->range_error( $request->get_param( 'from' ), $request->get_param( 'to' ) );
		if ( $range_error ) {
			return $range_error;
		}

		$granularity = (string) ( $request->get_param( 'granularity' ) ?? 'day' );
		$range       = Query::trend_range(
			$request->get_param( 'from' ),
			$request->get_param( 'to' )
		);
		$trend       = Query::trend(
			(array) ( $request->get_param( 'post_ids' ) ?? array() ),
			(array) ( $request->get_param( 'post_types' ) ?? array() ),
			$granularity,
			$range['from'],
			$range['to']
		);

		$response = new WP_REST_Response(
			array(
				'trend'       => $trend,
				'range'       => $range,
				'granularity' => $granularity,
				'meta'        => Query::measurement_metadata(),
			),
			200
		);

		// Object-cached in Core\Query::trend() (5 min); mirror that at the HTTP layer too,
		// for any reverse proxy or browser cache sitting in front of a per-capability response.
		$response->header( 'Cache-Control', 'private, max-age=300' );

		return $response;
	}

	/**
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_REST_Response
	 */
	public function get_momentum( WP_REST_Request $request ) {
		$momentum = Query::momentum(
			(array) ( $request->get_param( 'post_types' ) ?? array() ),
			(int) ( $request->get_param( 'period_days' ) ?? 30 ),
			(int) ( $request->get_param( 'limit' ) ?? 10 ),
			(int) ( $request->get_param( 'min_views' ) ?? 1 )
		);

		$response = new WP_REST_Response( array_merge( $momentum, array( 'meta' => Query::measurement_metadata() ) ), 200 );

		// Object-cached in Core\Query::momentum() (5 min), same as trend(); mirror that at
		// the HTTP layer too, same private, short-TTL policy as get_trends().
		$response->header( 'Cache-Control', 'private, max-age=300' );

		return $response;
	}

	/**
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_REST_Response
	 */
	public function get_dims_breakdown( WP_REST_Request $request ) {
		$range_error = $this->range_error( $request->get_param( 'since' ), $request->get_param( 'until' ) );
		if ( $range_error ) {
			return $range_error;
		}

		$breakdown = Query::dims_breakdown(
			(string) $request->get_param( 'dimension' ),
			(array) ( $request->get_param( 'post_types' ) ?? array() ),
			$request->get_param( 'since' ),
			$request->get_param( 'until' )
		);
		$coverage  = Query::dimension_coverage(
			(string) $request->get_param( 'dimension' ),
			(array) ( $request->get_param( 'post_types' ) ?? array() ),
			$request->get_param( 'since' ),
			$request->get_param( 'until' )
		);

		$response = new WP_REST_Response(
			array(
				'breakdown' => $breakdown,
				'coverage'  => $coverage,
				'meta'      => Query::measurement_metadata(),
			),
			200
		);

		// Object-cached in Core\Query::dims_breakdown() (5 min), same as trend()/momentum().
		$response->header( 'Cache-Control', 'private, max-age=300' );

		return $response;
	}

	/**
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_REST_Response
	 */
	public function get_ai_traffic( WP_REST_Request $request ) {
		$range_error = $this->range_error( $request->get_param( 'since' ), $request->get_param( 'until' ) );
		if ( $range_error ) {
			return $range_error;
		}

		$ai_traffic = Query::ai_traffic(
			(array) ( $request->get_param( 'post_types' ) ?? array() ),
			$request->get_param( 'since' ),
			$request->get_param( 'until' ),
			(int) ( $request->get_param( 'limit' ) ?? 10 )
		);

		$response = new WP_REST_Response( array_merge( $ai_traffic, array( 'meta' => Query::measurement_metadata() ) ), 200 );

		// Object-cached in Core\Query::ai_traffic() (5 min for the referral
		// breakdown and crawler blocks; the total reuses dims_breakdown()'s cache).
		$response->header( 'Cache-Control', 'private, max-age=300' );

		return $response;
	}
}
