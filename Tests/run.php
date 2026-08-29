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
use Bubuku\Plugins\PostViewCount\Core\Db;
use Bubuku\Plugins\PostViewCount\Core\Schema;
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
	'two views on different days produce two daily rows' => static function (): void {
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
	'migrating from postmeta is idempotent'              => static function (): void {
		TestState::reset();
		TestState::$meta[10] = array( 'views' => 9 );
		TestState::$meta[11] = array( 'views' => 3 );

		$schema = new Schema();
		$schema->migrate_batch( 0 );
		$schema->migrate_batch( 0 );

		bbk_test_same( 9, TestState::$views[10]['views'], 'Migrating twice must not duplicate or sum the count.' );
		bbk_test_same( 3, TestState::$views[11]['views'], 'Migrating twice must not duplicate or sum the count.' );
	},
	'accepts requests from the site origin'              => static function (): void {
		$api     = new RestApi();
		$request = new WP_REST_Request( array(), array( 'Origin' => 'https://test.wp.local' ) );

		bbk_test_same( true, $api->check_request_origin( $request ), 'The site origin should be accepted.' );
	},
	'accepts a same-origin referer when Origin is unavailable' => static function (): void {
		$api     = new RestApi();
		$request = new WP_REST_Request( array(), array( 'Referer' => 'https://test.wp.local/example/' ) );

		bbk_test_same( true, $api->check_request_origin( $request ), 'A same-origin referer should be accepted.' );
	},
	'rejects requests from another origin'               => static function (): void {
		$api     = new RestApi();
		$request = new WP_REST_Request( array(), array( 'Origin' => 'https://attacker.example' ) );

		bbk_test_error_status( $api->check_request_origin( $request ), 403 );
	},
	'rejects requests without origin evidence'           => static function (): void {
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
