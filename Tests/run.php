<?php
/**
 * Dependency-free automated tests for the view counter.
 *
 * Run with: php Tests/run.php
 *
 * @package Bubuku Post View Count
 */

declare( strict_types=1 );

use Bubuku\Plugins\PostViewCount\Admin\Settings;
use Bubuku\Plugins\PostViewCount\Api\RestApi;
use Bubuku\Plugins\PostViewCount\Api\TrendsApi;
use Bubuku\Plugins\PostViewCount\Core\Db;
use Bubuku\Plugins\PostViewCount\Core\Dimensions;
use Bubuku\Plugins\PostViewCount\Core\Query;
use Bubuku\Plugins\PostViewCount\Core\Schema;
use Bubuku\Plugins\PostViewCount\Frontend\ViewsDisplay;
use Bubuku\Plugins\PostViewCount\Mcp\Tools\GetContentTrends;
use Bubuku\Plugins\PostViewCount\Mcp\Tools\GetDimsBreakdown;
use Bubuku\Plugins\PostViewCount\Mcp\Tools\GetPostViews;
use Bubuku\Plugins\PostViewCount\Mcp\Tools\GetViewsSummary;
use Bubuku\Plugins\PostViewCount\Mcp\Tools\ListMomentum;
use Bubuku\Plugins\PostViewCount\Mcp\Tools\ListMostViewed;
use Bubuku\Plugins\PostViewCount\Mcp\Tools\ListStaleContent;
use Bubuku\Plugins\PostViewCount\TestState;
use Bubuku\Plugins\PostViewCount\TestWpdb;

require_once __DIR__ . '/bootstrap.php';

/**
 * Assert two values are identical.
 *
 * @param mixed  $expected Expected value.
 * @param mixed  $actual   Actual value.
 * @param string $message  Failure message.
 * @return void
 */
function bbk_test_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException(
			$message . ' Expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . '.'
		);
	}
}

/**
 * Assert that a value is a WordPress error with the expected status.
 *
 * @param mixed $actual Actual value.
 * @param int   $status Expected HTTP status.
 * @return void
 */
function bbk_test_error_status( $actual, int $status ): void {
	bbk_test_same( true, $actual instanceof WP_Error, 'Expected a WP_Error.' );
	bbk_test_same( $status, $actual->get_error_data()['status'], 'Unexpected error status.' );
}

/** @var TestWpdb $wpdb */
$wpdb = new TestWpdb();

$tests = array(
	'first view creates the row with views=1 and matching first/last timestamps' => static function (): void {
		TestState::reset();
		TestState::$now = '2026-01-01 10:00:00';

		$stats = ( new Db() )->record_view( 42 );

		bbk_test_same(
			array(
				'views'           => 1,
				'first_viewed_at' => '2026-01-01 10:00:00',
				'last_viewed_at'  => '2026-01-01 10:00:00',
			),
			$stats,
			'The first view was not recorded correctly.'
		);
		bbk_test_same( array( 42 ), TestState::$cache_deletions, 'Post meta cache was not cleared.' );
		bbk_test_same( 1, TestState::$meta[42]['views'], 'The views meta mirror was not written.' );
		bbk_test_same( '2026-01-01 10:00:00', TestState::$meta[42]['views_last'], 'The views_last meta mirror was not written.' );
	},
	'second view increments views and updates only last_viewed_at' => static function (): void {
		TestState::reset();
		TestState::$now = '2026-01-01 10:00:00';
		( new Db() )->record_view( 42 );

		TestState::$now = '2026-01-01 18:30:00';
		$stats          = ( new Db() )->record_view( 42 );

		bbk_test_same( 2, $stats['views'], 'The second view did not increment the total.' );
		bbk_test_same( '2026-01-01 10:00:00', $stats['first_viewed_at'], 'first_viewed_at must not change after the first view.' );
		bbk_test_same( '2026-01-01 18:30:00', $stats['last_viewed_at'], 'last_viewed_at was not updated.' );
	},
	'two views on the same day produce a single daily row with views=2' => static function (): void {
		TestState::reset();
		TestState::$now = '2026-01-01 09:00:00';
		( new Db() )->record_view( 7 );
		TestState::$now = '2026-01-01 21:00:00';
		( new Db() )->record_view( 7 );

		bbk_test_same( array( '7|2026-01-01' => 2 ), TestState::$daily, 'Same-day views must aggregate into one row.' );
	},
	'two views on different days produce two daily rows'  => static function (): void {
		TestState::reset();
		TestState::$now = '2026-01-01 09:00:00';
		( new Db() )->record_view( 7 );
		TestState::$now = '2026-01-02 09:00:00';
		( new Db() )->record_view( 7 );

		bbk_test_same(
			array(
				'7|2026-01-01' => 1,
				'7|2026-01-02' => 1,
			),
			TestState::$daily,
			'Different-day views must produce separate daily rows.'
		);
	},
	'set_post_views() keeps returning the plain integer total' => static function (): void {
		TestState::reset();
		TestState::$now = '2026-01-01 00:00:00';

		bbk_test_same( 1, ( new Db() )->set_post_views( 5 ), 'set_post_views() must still return an int.' );
		bbk_test_same( 2, ( new Db() )->set_post_views( 5 ), 'set_post_views() must still return an int.' );
	},
	'record_view() with dims writes one row per dimension, aggregating repeats' => static function (): void {
		TestState::reset();
		TestState::$now = '2026-01-01 10:00:00';
		( new Db() )->record_view(
			42,
			array(
				'viewport' => '576-991',
				'referrer' => 'search',
			)
		);
		( new Db() )->record_view(
			42,
			array(
				'viewport' => '576-991',
				'referrer' => 'search',
			)
		);

		bbk_test_same(
			array(
				'42|2026-01-01|viewport|576-991' => 2,
				'42|2026-01-01|referrer|search'  => 2,
			),
			TestState::$dims,
			'A repeated dimension/value pair on the same day must increment, not duplicate.'
		);
	},
	'record_view() without dims (default) writes nothing to the dims table' => static function (): void {
		TestState::reset();
		TestState::$now = '2026-01-01 10:00:00';
		( new Db() )->record_view( 42 );

		bbk_test_same( array(), TestState::$dims, 'A call with no dims argument must not touch the dims table.' );
	},
	'migrating from postmeta is idempotent'               => static function (): void {
		TestState::reset();
		TestState::$meta[10] = array( 'views' => 9 );
		TestState::$meta[11] = array( 'views' => 3 );

		$schema = new Schema();
		$schema->migrate_batch( 0 );
		$schema->migrate_batch( 0 );

		bbk_test_same( 9, TestState::$views[10]['views'], 'Migrating twice must not duplicate or sum the count.' );
		bbk_test_same( 3, TestState::$views[11]['views'], 'Migrating twice must not duplicate or sum the count.' );
	},
	'accepts requests from the site origin'               => static function (): void {
		$api     = new RestApi();
		$request = new WP_REST_Request( array(), array( 'Origin' => 'https://test.wp.local' ) );

		bbk_test_same( true, $api->check_request_origin( $request ), 'The site origin should be accepted.' );
	},
	'accepts a same-origin referer when Origin is unavailable' => static function (): void {
		$api     = new RestApi();
		$request = new WP_REST_Request( array(), array( 'Referer' => 'https://test.wp.local/example/' ) );

		bbk_test_same( true, $api->check_request_origin( $request ), 'A same-origin referer should be accepted.' );
	},
	'rejects requests from another origin'                => static function (): void {
		$api     = new RestApi();
		$request = new WP_REST_Request( array(), array( 'Origin' => 'https://attacker.example' ) );

		bbk_test_error_status( $api->check_request_origin( $request ), 403 );
	},
	'rejects requests without origin evidence'            => static function (): void {
		$api = new RestApi();

		bbk_test_error_status( $api->check_request_origin( new WP_REST_Request() ), 403 );
	},
	'increments once and deduplicates a repeated REST view' => static function (): void {
		TestState::reset();
		TestState::$now          = '2026-01-01 12:00:00';
		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
		$api                     = new RestApi();
		$request                 = new WP_REST_Request(
			array( 'post_id' => 99 ),
			array(
				'Origin'     => 'https://test.wp.local',
				'User-Agent' => 'Bubuku test',
			)
		);

		$first  = $api->set_post_views( $request );
		$second = $api->set_post_views( $request );

		bbk_test_same(
			array(
				'count'          => 1,
				'last_viewed_at' => '2026-01-01 12:00:00',
			),
			$first->get_data(),
			'The REST endpoint did not return the first increment.'
		);
		bbk_test_same(
			array(
				'count'          => 1,
				'last_viewed_at' => '2026-01-01 12:00:00',
			),
			$second->get_data(),
			'A duplicate REST request changed the count.'
		);
		bbk_test_same( 1, TestState::$views[99]['views'], 'A duplicate REST request was stored.' );
	},
	'set_post_views() records valid viewport/referrer dims and drops an invalid value' => static function (): void {
		TestState::reset();
		TestState::$now          = '2026-01-01 12:00:00';
		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
		$api                     = new RestApi();
		$request                 = new WP_REST_Request(
			array(
				'post_id'  => 101,
				'viewport' => '576-991',
				'referrer' => '9999px-not-a-real-bucket',
			),
			array(
				'Origin'     => 'https://test.wp.local',
				'User-Agent' => 'Bubuku test',
			)
		);

		$response = $api->set_post_views( $request );

		bbk_test_same( 1, $response->get_data()['count'], 'An invalid dimension value must not prevent the view itself from being counted.' );
		bbk_test_same(
			array( '101|2026-01-01|viewport|576-991' => 1 ),
			TestState::$dims,
			'Only the whitelisted viewport value must be recorded; the invalid referrer value must be dropped silently.'
		);
	},
	'a deduplicated REST view records neither the view nor its dims' => static function (): void {
		TestState::reset();
		TestState::$now          = '2026-01-01 12:00:00';
		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
		$api                     = new RestApi();
		$request                 = new WP_REST_Request(
			array(
				'post_id'  => 102,
				'viewport' => '576-991',
				'referrer' => 'search',
			),
			array(
				'Origin'     => 'https://test.wp.local',
				'User-Agent' => 'Bubuku test',
			)
		);

		$api->set_post_views( $request );
		$api->set_post_views( $request );

		bbk_test_same( 1, TestState::$views[102]['views'], 'A deduplicated repeat must not increment the view total.' );
		bbk_test_same(
			array(
				'102|2026-01-01|viewport|576-991' => 1,
				'102|2026-01-01|referrer|search'  => 1,
			),
			TestState::$dims,
			'A deduplicated repeat must not increment the dims either.'
		);
	},
	'enabled_post_types() returns [post] when the option does not exist yet' => static function (): void {
		TestState::reset();

		bbk_test_same( array( 'post' ), Settings::enabled_post_types(), 'The default enabled post type must be "post".' );
	},
	'validate_post_id() rejects a post type that is not enabled' => static function (): void {
		TestState::reset();
		update_option( Settings::OPTION_KEY, array( 'post_types' => array( 'page' ) ) );

		$api = new RestApi();

		bbk_test_same( false, $api->validate_post_id( 1 ), 'A post outside the enabled post types must be rejected.' );
	},
	'validate_post_id() accepts a post type that is enabled' => static function (): void {
		TestState::reset();
		update_option( Settings::OPTION_KEY, array( 'post_types' => array( 'post' ) ) );

		$api = new RestApi();

		bbk_test_same( true, $api->validate_post_id( 1 ), 'A post inside the enabled post types must be accepted.' );
	},
	'Settings::sanitize() discards post types and roles that do not exist' => static function (): void {
		$sanitized = Settings::sanitize(
			array(
				'post_types'     => array( 'post', 'made-up-type' ),
				'excluded_roles' => array( 'editor', 'made-up-role' ),
				'retention_days' => '0',
			)
		);

		bbk_test_same( array( 'post' ), $sanitized['post_types'], 'Unknown post types must be discarded.' );
		bbk_test_same( array( 'editor' ), $sanitized['excluded_roles'], 'Unknown roles must be discarded.' );
		bbk_test_same( 1, $sanitized['retention_days'], 'retention_days must never go below 1.' );
	},
	'Settings::sanitize() falls back to the default post type when none survive' => static function (): void {
		$sanitized = Settings::sanitize( array( 'post_types' => array( 'made-up-type' ) ) );

		bbk_test_same( array( 'post' ), $sanitized['post_types'], 'Falling back to the default post type failed.' );
	},
	'Settings::selectable_post_types() never offers "attachment" (Media)' => static function (): void {
		bbk_test_same( false, isset( Settings::selectable_post_types()['attachment'] ), 'Media must never be a selectable content type.' );
	},
	'Settings::sanitize() rejects "attachment" like any other unknown post type' => static function (): void {
		$sanitized = Settings::sanitize( array( 'post_types' => array( 'attachment' ) ) );

		bbk_test_same( array( 'post' ), $sanitized['post_types'], 'Media must never survive sanitize(), even if submitted directly.' );
	},
	'known bot user agents are never recorded, but the response still reflects current stats' => static function (): void {
		TestState::reset();
		TestState::$now          = '2026-01-01 12:00:00';
		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
		$api                     = new RestApi();
		$request                 = new WP_REST_Request(
			array( 'post_id' => 77 ),
			array(
				'Origin'     => 'https://test.wp.local',
				'User-Agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
			)
		);

		$response = $api->set_post_views( $request );

		bbk_test_same(
			array(
				'count'          => 0,
				'last_viewed_at' => null,
			),
			$response->get_data(),
			'A bot request must not be recorded.'
		);
		bbk_test_same( false, isset( TestState::$views[77] ), 'A bot request must not create a views row.' );
	},
	'Query::most_viewed() returns an empty array for a post type that is not enabled' => static function (): void {
		TestState::reset();
		update_option( Settings::OPTION_KEY, array( 'post_types' => array( 'post' ) ) );

		bbk_test_same( array(), Query::most_viewed( array( 'page' ) ), 'A disabled post type must never be queried, and must return no rows.' );
	},
	'Query::stale() returns an empty array for a post type that is not enabled' => static function (): void {
		TestState::reset();
		update_option( Settings::OPTION_KEY, array( 'post_types' => array( 'post' ) ) );

		bbk_test_same( array(), Query::stale( null, null, array( 'page' ) ), 'A disabled post type must never be queried, and must return no rows.' );
	},
	'Query::summary() returns a zeroed structure for a post type that is not enabled' => static function (): void {
		TestState::reset();
		update_option( Settings::OPTION_KEY, array( 'post_types' => array( 'post' ) ) );

		bbk_test_same(
			array(
				'total_views'           => 0,
				'posts_with_traffic'    => 0,
				'posts_without_traffic' => 0,
			),
			Query::summary( array( 'page' ) ),
			'A disabled post type must never be queried, and must return zeros.'
		);
	},
	'Query::momentum() returns empty lists for a post type that is not enabled' => static function (): void {
		TestState::reset();
		update_option( Settings::OPTION_KEY, array( 'post_types' => array( 'post' ) ) );

		$result = Query::momentum( array( 'page' ) );

		bbk_test_same( array(), $result['rising'], 'A disabled post type must never be queried, and must return no rows.' );
		bbk_test_same( array(), $result['falling'], 'A disabled post type must never be queried, and must return no rows.' );
		bbk_test_same( true, isset( $result['period']['current'], $result['period']['previous'] ), 'momentum() must always report the two compared periods.' );
	},
	'Query::dims_breakdown() returns an empty array for an unknown dimension' => static function (): void {
		TestState::reset();
		update_option( Settings::OPTION_KEY, array( 'post_types' => array( 'post' ) ) );

		bbk_test_same( array(), Query::dims_breakdown( 'not-a-real-dimension' ), 'An unknown dimension must never be queried, and must return no rows.' );
	},
	'Query::dims_breakdown() returns an empty array for a post type that is not enabled' => static function (): void {
		TestState::reset();
		update_option( Settings::OPTION_KEY, array( 'post_types' => array( 'post' ) ) );

		bbk_test_same( array(), Query::dims_breakdown( 'viewport', array( 'page' ) ), 'A disabled post type must never be queried, and must return no rows.' );
	},
	'Query::post_stats() reflects the recorded views, title and URL for a post' => static function (): void {
		TestState::reset();
		TestState::$now                = '2026-01-01 10:00:00';
		TestState::$post_titles[42]    = 'Example Post';
		( new Db() )->record_view( 42 );

		$stats = Query::post_stats( 42 );

		bbk_test_same( 42, $stats['id'], 'post_stats() must return the requested post ID.' );
		bbk_test_same( 'Example Post', $stats['title'], 'post_stats() must return the post title.' );
		bbk_test_same( 'https://test.wp.local/?p=42', $stats['url'], 'post_stats() must return the post permalink.' );
		bbk_test_same( 1, $stats['views'], 'post_stats() must reflect the recorded view count.' );
		bbk_test_same( '2026-01-01 10:00:00', $stats['first_viewed_at'], 'post_stats() must reflect first_viewed_at.' );
		bbk_test_same( '2026-01-01 10:00:00', $stats['last_viewed_at'], 'post_stats() must reflect last_viewed_at.' );
	},
	'ListMostViewed tool delegates to Query::most_viewed() and reports data_available_since' => static function (): void {
		TestState::reset();
		TestState::$options[ Schema::OPTION_DAILY_SINCE ] = '2026-01-01 00:00:00';

		$result = ( new ListMostViewed() )->execute_callback( array( 'limit' => 5 ) );

		bbk_test_same( true, isset( $result['results'] ) && is_array( $result['results'] ), 'The tool must return a "results" array.' );
		bbk_test_same( '2026-01-01 00:00:00', $result['meta']['data_available_since'], 'The tool must report data_available_since from Schema::daily_data_since().' );
	},
	'ListStaleContent tool delegates to Query::stale()'   => static function (): void {
		TestState::reset();

		$result = ( new ListStaleContent() )->execute_callback( array( 'post_types' => array( 'page' ) ) );

		bbk_test_same( array(), $result['results'], 'A disabled post type must return no rows, same as Query::stale().' );
	},
	'GetPostViews tool returns an error when neither post_id nor url is given' => static function (): void {
		TestState::reset();

		$result = ( new GetPostViews() )->execute_callback( array() );

		bbk_test_same( 'missing_post', $result['error']['code'], 'A call with neither post_id nor url must return the missing_post error.' );
	},
	'GetPostViews tool resolves post_id directly and returns post_stats()' => static function (): void {
		TestState::reset();
		TestState::$now             = '2026-01-01 10:00:00';
		TestState::$post_titles[42] = 'Example Post';
		( new Db() )->record_view( 42 );

		$result = ( new GetPostViews() )->execute_callback( array( 'post_id' => 42 ) );

		bbk_test_same( 42, $result['id'], 'GetPostViews must return the requested post ID.' );
		bbk_test_same( 1, $result['views'], 'GetPostViews must reflect the recorded view count.' );
	},
	'GetPostViews tool resolves the post via url when post_id is not given' => static function (): void {
		TestState::reset();
		TestState::$now                                                      = '2026-01-01 10:00:00';
		TestState::$post_titles[42]                                          = 'Example Post';
		TestState::$url_to_post_id['https://test.wp.local/?p=42']            = 42;
		( new Db() )->record_view( 42 );

		$result = ( new GetPostViews() )->execute_callback( array( 'url' => 'https://test.wp.local/?p=42' ) );

		bbk_test_same( 42, $result['id'], 'GetPostViews must resolve the post via url_to_postid() when post_id is absent.' );
	},
	'GetViewsSummary tool delegates to Query::summary() and adds computed_at' => static function (): void {
		TestState::reset();

		$result = ( new GetViewsSummary() )->execute_callback( array() );

		bbk_test_same( 0, $result['total_views'], 'With no data recorded, total_views must be 0.' );
		bbk_test_same( true, isset( $result['computed_at'] ), 'The tool must add a computed_at timestamp.' );
	},
	'GetContentTrends tool delegates to Query::trend() and returns a bucketed series' => static function (): void {
		TestState::reset();

		$result = ( new GetContentTrends() )->execute_callback( array( 'post_ids' => array( 7 ) ) );

		bbk_test_same( array(), $result['trend'], 'With no daily rows recorded, the trend must be an empty series.' );
		bbk_test_same( true, isset( $result['meta']['computed_at'] ), 'The tool must add a computed_at timestamp.' );
	},
	'ListMomentum tool delegates to Query::momentum()'    => static function (): void {
		TestState::reset();
		update_option( Settings::OPTION_KEY, array( 'post_types' => array( 'post' ) ) );

		$result = ( new ListMomentum() )->execute_callback( array( 'post_types' => array( 'page' ) ) );

		bbk_test_same( array(), $result['rising'], 'A disabled post type must return no rows, same as Query::momentum().' );
		bbk_test_same( array(), $result['falling'], 'A disabled post type must return no rows, same as Query::momentum().' );
	},
	'GetDimsBreakdown tool delegates to Query::dims_breakdown()' => static function (): void {
		TestState::reset();
		update_option( Settings::OPTION_KEY, array( 'post_types' => array( 'post' ) ) );

		$result = ( new GetDimsBreakdown() )->execute_callback(
			array(
				'dimension'  => 'viewport',
				'post_types' => array( 'page' ),
			)
		);

		bbk_test_same( array(), $result['breakdown'], 'A disabled post type must return no rows, same as Query::dims_breakdown().' );
		bbk_test_same( true, isset( $result['meta']['computed_at'] ), 'The tool must add a computed_at timestamp.' );
	},
	'TrendsApi::check_permission() requires the edit_posts capability' => static function (): void {
		TestState::reset();

		bbk_test_same( false, ( new TrendsApi() )->check_permission(), 'A visitor without edit_posts must be denied.' );

		TestState::$current_user_can['edit_posts'] = true;

		bbk_test_same( true, ( new TrendsApi() )->check_permission(), 'A user with edit_posts must be allowed.' );
	},
	'TrendsApi::get_trends() delegates to Query::trend()' => static function (): void {
		TestState::reset();
		$request = new WP_REST_Request(
			array(
				'post_ids'    => array( 7 ),
				'granularity' => 'week',
			)
		);

		$response = ( new TrendsApi() )->get_trends( $request );

		bbk_test_same( array( 'trend' => array() ), $response->get_data(), 'With no daily rows recorded, the trend must be an empty series.' );
	},
	'TrendsApi::get_momentum() delegates to Query::momentum()' => static function (): void {
		TestState::reset();
		update_option( Settings::OPTION_KEY, array( 'post_types' => array( 'post' ) ) );
		$request = new WP_REST_Request( array( 'post_types' => array( 'page' ) ) );

		$response = ( new TrendsApi() )->get_momentum( $request );
		$data     = $response->get_data();

		bbk_test_same( array(), $data['rising'], 'A disabled post type must return no rows, same as Query::momentum().' );
		bbk_test_same( array(), $data['falling'], 'A disabled post type must return no rows, same as Query::momentum().' );
	},
	'TrendsApi::get_dims_breakdown() delegates to Query::dims_breakdown()' => static function (): void {
		TestState::reset();
		update_option( Settings::OPTION_KEY, array( 'post_types' => array( 'post' ) ) );
		$request = new WP_REST_Request(
			array(
				'dimension'  => 'viewport',
				'post_types' => array( 'page' ),
			)
		);

		$response = ( new TrendsApi() )->get_dims_breakdown( $request );

		bbk_test_same( array( 'breakdown' => array() ), $response->get_data(), 'A disabled post type must return no rows, same as Query::dims_breakdown().' );
	},
	'ViewsDisplay::render() shows the view count, and the last-viewed date when asked' => static function (): void {
		TestState::reset();
		TestState::$options['date_format'] = 'Y-m-d';
		TestState::$options['time_format'] = 'H:i';
		TestState::$now                    = '2026-01-01 10:30:00';
		( new Db() )->record_view( 42 );
		( new Db() )->record_view( 42 );

		bbk_test_same(
			'<span class="bbk-post-views">2 views</span>',
			ViewsDisplay::render( 42 ),
			'ViewsDisplay::render() must show the recorded view count.'
		);
		bbk_test_same(
			'<span class="bbk-post-views">2 views &middot; last view: 2026-01-01 10:30</span>',
			ViewsDisplay::render( 42, array( 'show_last_viewed' => true ) ),
			'ViewsDisplay::render() must append the last-viewed date when asked.'
		);
	},
	'ViewsDisplay::render() returns an empty string for a post type that is not enabled' => static function (): void {
		TestState::reset();
		update_option( Settings::OPTION_KEY, array( 'post_types' => array( 'page' ) ) );

		bbk_test_same( '', ViewsDisplay::render( 42 ), 'A disabled post type must never be rendered.' );
	},
);

$failures = 0;

foreach ( $tests as $name => $test ) {
	try {
		$test();
		echo "PASS: {$name}\n";
	} catch ( Throwable $throwable ) {
		++$failures;
		fwrite( STDERR, "FAIL: {$name}\n  {$throwable->getMessage()}\n" );
	}
}

echo sprintf( "\n%d tests, %d failures.\n", count( $tests ), $failures );

exit( $failures > 0 ? 1 : 0 );
