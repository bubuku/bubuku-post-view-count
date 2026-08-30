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
use Bubuku\Plugins\PostViewCount\Core\AiCrawlers;
use Bubuku\Plugins\PostViewCount\Core\Db;
use Bubuku\Plugins\PostViewCount\Core\Dimensions;
use Bubuku\Plugins\PostViewCount\Core\Query;
use Bubuku\Plugins\PostViewCount\Core\Schema;
use Bubuku\Plugins\PostViewCount\Core\WriteBuffer;
use Bubuku\Plugins\PostViewCount\Frontend\ViewsDisplay;
use Bubuku\Plugins\PostViewCount\Mcp\Tools\GetAiTraffic;
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
	'AiCrawlers::detect() identifies a known AI crawler and ignores an unrelated User-Agent' => static function (): void {
		bbk_test_same( 'ClaudeBot', AiCrawlers::detect( 'Mozilla/5.0 (compatible; ClaudeBot/1.0; +https://www.anthropic.com)' ), 'A known AI crawler signature must be identified.' );
		bbk_test_same( null, AiCrawlers::detect( 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0' ), 'A regular browser User-Agent must never match an AI crawler.' );
		bbk_test_same( null, AiCrawlers::detect( '' ), 'An empty User-Agent must never match.' );
	},
	'record_ai_crawl() upserts per bot, aggregating repeats and keeping bots separate' => static function (): void {
		TestState::reset();
		TestState::$now = '2026-01-01 10:00:00';
		( new Db() )->record_ai_crawl( 42, 'GPTBot' );
		( new Db() )->record_ai_crawl( 42, 'GPTBot' );
		( new Db() )->record_ai_crawl( 42, 'ClaudeBot' );

		bbk_test_same(
			array(
				'42|2026-01-01|GPTBot'    => 2,
				'42|2026-01-01|ClaudeBot' => 1,
			),
			TestState::$ai_crawls,
			'A repeated bot hit on the same day must increment, not duplicate; a different bot must get its own row.'
		);
	},
	'record_ai_crawl() never touches the human view/daily tables' => static function (): void {
		TestState::reset();
		TestState::$now = '2026-01-01 10:00:00';
		( new Db() )->record_ai_crawl( 42, 'GPTBot' );

		bbk_test_same( array(), TestState::$views, 'An AI crawler hit must never be recorded as a human view.' );
		bbk_test_same( array(), TestState::$daily, 'An AI crawler hit must never be recorded in the daily aggregate.' );
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
	'a DNT header drops dims but still counts the view, with respect_dnt on by default' => static function (): void {
		TestState::reset();
		TestState::$now          = '2026-01-01 12:00:00';
		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

		bbk_test_same( true, Settings::respect_dnt(), 'respect_dnt must be enabled by default.' );

		$api     = new RestApi();
		$request = new WP_REST_Request(
			array(
				'post_id'  => 103,
				'viewport' => '576-991',
				'referrer' => 'search',
			),
			array(
				'Origin'     => 'https://test.wp.local',
				'User-Agent' => 'Bubuku test',
				'DNT'        => '1',
			)
		);

		$response = $api->set_post_views( $request );

		bbk_test_same( 1, $response->get_data()['count'], 'A DNT signal must not prevent the view itself from being counted.' );
		bbk_test_same( array(), TestState::$dims, 'A DNT signal must drop dims server-side, even though the client already sent values.' );
	},
	'a Sec-GPC header drops dims the same way as DNT'     => static function (): void {
		TestState::reset();
		TestState::$now          = '2026-01-01 12:00:00';
		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
		$api                     = new RestApi();
		$request                 = new WP_REST_Request(
			array(
				'post_id'  => 104,
				'viewport' => '576-991',
			),
			array(
				'Origin'  => 'https://test.wp.local',
				'Sec-GPC' => '1',
			)
		);

		$api->set_post_views( $request );

		bbk_test_same( array(), TestState::$dims, 'A Sec-GPC signal must drop dims server-side.' );
	},
	'disabling respect_dnt keeps recording dims even with a DNT header' => static function (): void {
		TestState::reset();
		TestState::$now = '2026-01-01 12:00:00';
		update_option( Settings::OPTION_KEY, array( 'respect_dnt' => false ) );
		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
		$api                     = new RestApi();
		$request                 = new WP_REST_Request(
			array(
				'post_id'  => 105,
				'viewport' => '576-991',
			),
			array(
				'Origin' => 'https://test.wp.local',
				'DNT'    => '1',
			)
		);

		$api->set_post_views( $request );

		bbk_test_same(
			array( '105|2026-01-01|viewport|576-991' => 1 ),
			TestState::$dims,
			'With respect_dnt turned off, a DNT header must not affect dims recording.'
		);
	},
	'Settings::sanitize() honors a submitted respect_dnt value' => static function (): void {
		TestState::reset();

		$sanitized = Settings::sanitize( array( 'respect_dnt' => false ) );
		bbk_test_same( false, $sanitized['respect_dnt'], 'An explicit false must sanitize to false.' );

		$sanitized = Settings::sanitize( array() );
		bbk_test_same( false, $sanitized['respect_dnt'], 'An absent checkbox must sanitize to false, matching the other checkbox fields.' );
	},
	'WriteBuffer::enabled() requires both the setting and a persistent object cache' => static function (): void {
		TestState::reset();

		bbk_test_same( false, WriteBuffer::enabled(), 'Off by default, and with no persistent object cache.' );

		TestState::$ext_object_cache = true;
		bbk_test_same( false, WriteBuffer::enabled(), 'A persistent object cache alone must not be enough; the setting must also be on.' );

		update_option( Settings::OPTION_KEY, array( 'write_buffer' => true ) );
		bbk_test_same( true, WriteBuffer::enabled(), 'With the setting on and a persistent object cache present, buffering must be enabled.' );

		TestState::$ext_object_cache = false;
		bbk_test_same( false, WriteBuffer::enabled(), 'Without a persistent object cache, buffering must stay off even if the setting is on.' );
	},
	'Settings::sanitize() honors a submitted write_buffer value' => static function (): void {
		TestState::reset();

		$sanitized = Settings::sanitize( array( 'write_buffer' => '1' ) );
		bbk_test_same( true, $sanitized['write_buffer'], 'An explicit truthy value must sanitize to true.' );

		$sanitized = Settings::sanitize( array() );
		bbk_test_same( false, $sanitized['write_buffer'], 'An absent checkbox must sanitize to false, matching the other checkbox fields.' );
	},
	'WriteBuffer::buffer() coalesces several increments in the object cache without writing to the DB until flush()' => static function (): void {
		TestState::reset();
		TestState::$now = '2026-01-01 10:00:00';

		WriteBuffer::buffer( 55, array( 'viewport' => '576-991' ) );
		WriteBuffer::buffer( 55, array( 'viewport' => '576-991' ) );
		WriteBuffer::buffer( 55, array( 'viewport' => '992-1399' ) );

		bbk_test_same( array(), TestState::$views, 'Buffered views must not hit the aggregate table before flush().' );
		bbk_test_same( array(), TestState::$daily, 'Buffered views must not hit the daily table before flush().' );
		bbk_test_same( 3, WriteBuffer::pending_views( 55 ), 'pending_views() must reflect every buffered increment so far.' );

		WriteBuffer::flush();

		bbk_test_same( 3, TestState::$views[55]['views'], 'flush() must write the coalesced total in a single batch.' );
		bbk_test_same( 3, TestState::$daily['55|2026-01-01'], 'flush() must add the full batch to the daily aggregate.' );
		bbk_test_same(
			array(
				'55|2026-01-01|viewport|576-991'  => 2,
				'55|2026-01-01|viewport|992-1399' => 1,
			),
			TestState::$dims,
			'flush() must write one row per distinct dimension value, each with its own coalesced count.'
		);
		bbk_test_same( 0, WriteBuffer::pending_views( 55 ), 'flush() must clear the buffered counters it just wrote.' );
	},
	'WriteBuffer registers a post/day in the flush index only once, no matter how many views buffer it' => static function (): void {
		TestState::reset();
		TestState::$now = '2026-01-01 10:00:00';

		WriteBuffer::buffer( 60, array() );
		WriteBuffer::buffer( 60, array() );
		WriteBuffer::buffer( 60, array() );

		bbk_test_same(
			array( '60|2026-01-01' ),
			get_option( WriteBuffer::OPTION_INDEX, array() ),
			'The flush index must list the post/day pair exactly once, keeping the write cost proportional to distinct posts, not views.'
		);
	},
	'WriteBuffer::flush() with nothing buffered is a no-op' => static function (): void {
		TestState::reset();

		WriteBuffer::flush();

		bbk_test_same( array(), TestState::$views, 'An empty flush index must never touch the DB.' );
	},
	'RestApi buffers the write when write_buffer is enabled, and the response count includes the pending views' => static function (): void {
		TestState::reset();
		TestState::$now              = '2026-01-01 10:00:00';
		TestState::$ext_object_cache = true;
		update_option( Settings::OPTION_KEY, array( 'write_buffer' => true ) );

		$api = new RestApi();

		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
		$response1               = $api->set_post_views(
			new WP_REST_Request(
				array( 'post_id' => 70 ),
				array(
					'Origin'     => 'https://test.wp.local',
					'User-Agent' => 'Visitor A',
				)
			)
		);

		$_SERVER['REMOTE_ADDR'] = '127.0.0.2';
		$response2               = $api->set_post_views(
			new WP_REST_Request(
				array( 'post_id' => 70 ),
				array(
					'Origin'     => 'https://test.wp.local',
					'User-Agent' => 'Visitor B',
				)
			)
		);

		bbk_test_same( 1, $response1->get_data()['count'], 'The first buffered view must still be reflected in the response.' );
		bbk_test_same( 2, $response2->get_data()['count'], 'A second buffered view for the same post must add to the pending count already in the response.' );
		bbk_test_same( array(), TestState::$views, 'Buffered views must not hit the DB until the next flush.' );

		WriteBuffer::flush();

		bbk_test_same( 2, TestState::$views[70]['views'], 'flush() must persist every buffered view once the cron runs.' );
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
	'Settings::ai_crawler_tracking() defaults to false, and sanitize() respects the checkbox' => static function (): void {
		TestState::reset();

		bbk_test_same( false, Settings::ai_crawler_tracking(), 'AI-crawler tracking must be disabled by default (docs/ANALYTICS-PLAN.md §F6).' );

		$sanitized = Settings::sanitize( array( 'ai_crawler_tracking' => '1' ) );
		bbk_test_same( true, $sanitized['ai_crawler_tracking'], 'A truthy submitted value must sanitize to true.' );

		$sanitized = Settings::sanitize( array() );
		bbk_test_same( false, $sanitized['ai_crawler_tracking'], 'An absent checkbox must sanitize to false.' );
	},
	'known bot user agents are never recorded, but the response still reflects current stats' => static function (): void {
		TestState::reset();
		TestState::$now          = '2026-01-01 12:00:00';
		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
		update_option( Settings::OPTION_KEY, array( 'exclude_bots' => true ) );
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
	'Settings::exclude_bots() defaults to true, so a known bot is not recorded unless the setting is turned off' => static function (): void {
		TestState::reset();

		bbk_test_same( true, Settings::exclude_bots(), 'exclude_bots must be enabled by default.' );

		TestState::$now          = '2026-01-01 12:00:00';
		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
		$api                     = new RestApi();
		$request                 = new WP_REST_Request(
			array( 'post_id' => 78 ),
			array(
				'Origin'     => 'https://test.wp.local',
				'User-Agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
			)
		);

		$api->set_post_views( $request );

		bbk_test_same( false, isset( TestState::$views[78] ), 'With exclude_bots on (default), a known bot request must not be recorded.' );
	},
	'turning exclude_bots off records a known bot request like any other visitor' => static function (): void {
		TestState::reset();
		update_option( Settings::OPTION_KEY, array( 'exclude_bots' => false ) );

		TestState::$now          = '2026-01-01 12:00:00';
		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
		$api                     = new RestApi();
		$request                 = new WP_REST_Request(
			array( 'post_id' => 79 ),
			array(
				'Origin'     => 'https://test.wp.local',
				'User-Agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
			)
		);

		$api->set_post_views( $request );

		bbk_test_same( true, isset( TestState::$views[79] ), 'With exclude_bots explicitly off, a known bot request must be recorded.' );
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
	'Query::ai_traffic() returns no crawlers for a post type that is not enabled, and reports the tracking flag' => static function (): void {
		TestState::reset();
		update_option( Settings::OPTION_KEY, array( 'post_types' => array( 'post' ) ) );

		$result = Query::ai_traffic( array( 'page' ) );

		bbk_test_same( array(), $result['crawlers'], 'A disabled post type must never be queried, and must return no rows.' );
		bbk_test_same( 0, $result['referrals']['views'], 'With no dims recorded, AI-referral views must be 0.' );
		bbk_test_same( false, $result['ai_crawler_tracking_enabled'], 'The tracking flag must reflect Settings::ai_crawler_tracking() (default false).' );
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
	'GetAiTraffic tool delegates to Query::ai_traffic()'  => static function (): void {
		TestState::reset();
		update_option( Settings::OPTION_KEY, array( 'post_types' => array( 'post' ) ) );

		$result = ( new GetAiTraffic() )->execute_callback( array( 'post_types' => array( 'page' ) ) );

		bbk_test_same( array(), $result['crawlers'], 'A disabled post type must return no rows, same as Query::ai_traffic().' );
		bbk_test_same( 0, $result['referrals']['views'], 'With no dims recorded, AI-referral views must be 0.' );
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
	'TrendsApi::get_ai_traffic() delegates to Query::ai_traffic()' => static function (): void {
		TestState::reset();
		update_option( Settings::OPTION_KEY, array( 'post_types' => array( 'post' ) ) );
		$request = new WP_REST_Request( array( 'post_types' => array( 'page' ) ) );

		$response = ( new TrendsApi() )->get_ai_traffic( $request );
		$data     = $response->get_data();

		bbk_test_same( array(), $data['crawlers'], 'A disabled post type must return no rows, same as Query::ai_traffic().' );
		bbk_test_same( 0, $data['referrals']['views'], 'With no dims recorded, AI-referral views must be 0.' );
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
