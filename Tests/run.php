<?php
/**
 * Dependency-free automated tests for the view counter.
 *
 * Run with: php Tests/run.php
 *
 * @package Bubuku Post View Count
 */

declare( strict_types=1 );

use Bubuku\Plugins\PostViewCount\Api\RestApi;
use Bubuku\Plugins\PostViewCount\Core\Db;
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
	'increments an existing view count atomically'  => static function (): void {
		TestState::reset();
		TestState::$meta[42] = 7;

		$count = ( new Db() )->set_post_views( 42 );

		bbk_test_same( 8, $count, 'The returned count was not incremented.' );
		bbk_test_same( 8, TestState::$meta[42], 'The stored count was not incremented.' );
		bbk_test_same( array( 42 ), TestState::$cache_deletions, 'Post meta cache was not cleared.' );
	},
	'creates the first view when no counter exists' => static function (): void {
		TestState::reset();

		$count = ( new Db() )->set_post_views( 7 );

		bbk_test_same( 1, $count, 'The first view should create a count of one.' );
		bbk_test_same( 1, TestState::$meta[7], 'The first view was not stored.' );
	},
	'accepts requests from the site origin'         => static function (): void {
		$api     = new RestApi();
		$request = new WP_REST_Request( array(), array( 'Origin' => 'https://test.wp.local' ) );

		bbk_test_same( true, $api->check_request_origin( $request ), 'The site origin should be accepted.' );
	},
	'accepts a same-origin referer when Origin is unavailable' => static function (): void {
		$api     = new RestApi();
		$request = new WP_REST_Request( array(), array( 'Referer' => 'https://test.wp.local/example/' ) );

		bbk_test_same( true, $api->check_request_origin( $request ), 'A same-origin referer should be accepted.' );
	},
	'rejects requests from another origin'          => static function (): void {
		$api     = new RestApi();
		$request = new WP_REST_Request( array(), array( 'Origin' => 'https://attacker.example' ) );

		bbk_test_error_status( $api->check_request_origin( $request ), 403 );
	},
	'rejects requests without origin evidence'      => static function (): void {
		$api = new RestApi();

		bbk_test_error_status( $api->check_request_origin( new WP_REST_Request() ), 403 );
	},
	'increments once and deduplicates a repeated REST view' => static function (): void {
		TestState::reset();
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

		bbk_test_same( array( 'count' => 1 ), $first->get_data(), 'The REST endpoint did not return the first increment.' );
		bbk_test_same( array( 'count' => 1 ), $second->get_data(), 'A duplicate REST request changed the count.' );
		bbk_test_same( 1, TestState::$meta[99], 'A duplicate REST request was stored.' );
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
