<?php
/**
 * Minimal WordPress test doubles for the dependency-free test suite.
 *
 * @package Bubuku Post View Count
 */

declare( strict_types=1 );

// phpcs:disable -- Test doubles intentionally share this dependency-free bootstrap.

namespace {

	use Bubuku\Plugins\PostViewCount\TestState;

	define( 'ABSPATH', __DIR__ . '/' );
	define( 'MINUTE_IN_SECONDS', 60 );
	define( 'BBK_PLUGIN_ENDPOINTS_URL', 'bbk_postview/v1' );

	class WP_Error {

		/** @var string */
		private $code;

		/** @var string */
		private $message;

		/** @var mixed */
		private $data;

		public function __construct( string $code, string $message, $data = null ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}

		public function get_error_data() {
			return $this->data;
		}
	}

	class WP_REST_Request {

		/** @var array<string, mixed> */
		private $params;

		/** @var array<string, string> */
		private $headers;

		public function __construct( array $params = array(), array $headers = array() ) {
			$this->params  = $params;
			$this->headers = array_change_key_case( $headers, CASE_LOWER );
		}

		public function get_param( string $key ) {
			return $this->params[ $key ] ?? null;
		}

		public function get_header( string $key ): string {
			return $this->headers[ strtolower( $key ) ] ?? '';
		}
	}

	class WP_REST_Response {

		/** @var mixed */
		private $data;

		/** @var int */
		private $status;

		public function __construct( $data = null, int $status = 200 ) {
			$this->data   = $data;
			$this->status = $status;
		}

		public function get_data() {
			return $this->data;
		}

		public function get_status(): int {
			return $this->status;
		}
	}

	// These are defined in the global namespace (rather than alongside TestState
	// below) so PHP's namespace fallback for unqualified function calls resolves
	// them regardless of which sub-namespace (Core, Api, Frontend) calls them —
	// exactly like real WordPress functions, which are always global.

	function add_action(): void {
	}

	function register_rest_route( string $namespace, string $route, array $args ): void {
		TestState::$route = compact( 'namespace', 'route', 'args' );
	}

	function absint( $value ): int {
		return abs( (int) $value );
	}

	function get_post_type( int $post_id ): string {
		return 0 < $post_id ? 'post' : '';
	}

	function is_post_publicly_viewable( int $post_id ): bool {
		return 0 < $post_id;
	}

	function get_post_meta( int $post_id, string $key, bool $single ) {
		unset( $key, $single );

		return TestState::$meta[ $post_id ] ?? '';
	}

	function add_post_meta( int $post_id, string $key, int $value, bool $unique ): bool {
		unset( $key, $unique );

		if ( array_key_exists( $post_id, TestState::$meta ) ) {
			return false;
		}

		TestState::$meta[ $post_id ] = $value;

		return true;
	}

	function wp_cache_delete( int $post_id, string $group ): void {
		unset( $group );
		TestState::$cache_deletions[] = $post_id;
	}

	function get_transient( string $key ) {
		return TestState::$transients[ $key ] ?? false;
	}

	function set_transient( string $key, int $value, int $expiration ): bool {
		unset( $expiration );
		TestState::$transients[ $key ] = $value;

		return true;
	}

	function sanitize_text_field( string $value ): string {
		return trim( $value );
	}

	function wp_unslash( string $value ): string {
		return stripslashes( $value );
	}

	function home_url( string $path = '' ): string {
		return 'https://test.wp.local' . $path;
	}

	function wp_parse_url( string $url ) {
		return parse_url( $url );
	}

	function __( string $text, string $domain ): string {
		unset( $domain );

		return $text;
	}
}

namespace Bubuku\Plugins\PostViewCount {

	final class TestState {

		/** @var array<int, int> */
		public static $meta = array();

		/** @var array<string, int> */
		public static $transients = array();

		/** @var array<int, bool> */
		public static $cache_deletions = array();

		/** @var array<string, mixed> */
		public static $route = array();

		public static function reset(): void {
			self::$meta            = array();
			self::$transients      = array();
			self::$cache_deletions = array();
			self::$route           = array();
		}
	}

	final class TestWpdb {

		/** @var string */
		public $postmeta = 'wp_postmeta';

		public function prepare( string $query, int $post_id ): int {
			unset( $query );

			return $post_id;
		}

		public function query( int $post_id ): int {
			if ( ! array_key_exists( $post_id, TestState::$meta ) ) {
				return 0;
			}

			++TestState::$meta[ $post_id ];

			return 1;
		}
	}

	require_once dirname( __DIR__ ) . '/src/Core/Db.php';
	require_once dirname( __DIR__ ) . '/src/Api/RestApi.php';
}
