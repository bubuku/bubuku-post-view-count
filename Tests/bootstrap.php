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
	define( 'ARRAY_A', 'ARRAY_A' );

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
			$headers       = array_change_key_case( $headers, CASE_LOWER );
			$this->headers = array();

			foreach ( $headers as $key => $value ) {
				$this->headers[ str_replace( '-', '_', $key ) ] = $value;
			}
		}

		public function get_param( string $key ) {
			return $this->params[ $key ] ?? null;
		}

		/**
		 * @return array<string, mixed>
		 */
		public function get_json_params(): array {
			return $this->params;
		}

		public function get_header( string $key ): string {
			// Mirrors WP_REST_Request::get_header(): header keys are normalized
			// to underscores, so lookups work regardless of "-" or "_" in $key.
			return $this->headers[ str_replace( '-', '_', strtolower( $key ) ) ] ?? '';
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

		public function header( string $key, string $value ): void {
			unset( $key, $value );
		}
	}

	// These are defined in the global namespace (rather than alongside TestState
	// below) so PHP's namespace fallback for unqualified function calls resolves
	// them regardless of which sub-namespace (Core, Api, Frontend) calls them —
	// exactly like real WordPress functions, which are always global.

	function add_action(): void {
	}

	function add_filter(): void {
	}

	function wp_next_scheduled(): bool {
		return false;
	}

	function wp_schedule_event(): bool {
		return true;
	}

	function wp_schedule_single_event(): bool {
		return true;
	}

	function wp_clear_scheduled_hook(): bool {
		return true;
	}

	function wp_using_ext_object_cache(): bool {
		return TestState::$ext_object_cache;
	}

	function is_multisite(): bool {
		return false;
	}

	/**
	 * Stand-in for wp-admin/includes/upgrade.php's dbDelta(): schema creation
	 * isn't exercised by this dependency-free suite (no real database), so it's
	 * a no-op — Schema::create_tables() still requires ABSPATH . 'wp-admin/
	 * includes/upgrade.php' (see Tests/wp-admin/includes/upgrade.php), which is
	 * why this is defined before that require runs.
	 *
	 * @param string $sql Unused.
	 * @return array
	 */
	function dbDelta( string $sql ): array {
		unset( $sql );

		return array();
	}

	function wp_cache_add( string $key, $value, string $group, int $ttl = 0 ): bool {
		unset( $ttl );

		if ( isset( TestState::$cache[ $group ][ $key ] ) ) {
			return false;
		}

		TestState::$cache[ $group ][ $key ] = $value;

		return true;
	}

	function wp_cache_incr( string $key, int $offset, string $group ) {
		$current = TestState::$cache[ $group ][ $key ] ?? 0;

		TestState::$cache[ $group ][ $key ] = (int) $current + $offset;

		return TestState::$cache[ $group ][ $key ];
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

	function current_user_can( string $capability ): bool {
		return TestState::$current_user_can[ $capability ] ?? false;
	}

	function get_post_meta( int $post_id, string $key, bool $single ) {
		unset( $single );

		return TestState::$meta[ $post_id ][ $key ] ?? '';
	}

	function add_post_meta( int $post_id, string $key, $value, bool $unique ): bool {
		unset( $unique );

		if ( isset( TestState::$meta[ $post_id ][ $key ] ) ) {
			return false;
		}

		TestState::$meta[ $post_id ][ $key ] = $value;

		return true;
	}

	function update_post_meta( int $post_id, string $key, $value ): bool {
		TestState::$meta[ $post_id ][ $key ] = $value;

		return true;
	}

	function delete_post_meta_by_key( string $key ): bool {
		foreach ( array_keys( TestState::$meta ) as $post_id ) {
			unset( TestState::$meta[ $post_id ][ $key ] );
		}

		return true;
	}

	function wp_cache_delete( $key, string $group ): void {
		TestState::$cache_deletions[] = $key;
		unset( TestState::$cache[ $group ][ $key ] );
	}

	function get_transient( string $key ) {
		return TestState::$transients[ $key ] ?? false;
	}

	function set_transient( string $key, int $value, int $expiration ): bool {
		unset( $expiration );
		TestState::$transients[ $key ] = $value;

		return true;
	}

	function get_option( string $key, $default = false ) {
		return TestState::$options[ $key ] ?? $default;
	}

	function update_option( string $key, $value ): bool {
		TestState::$options[ $key ] = $value;

		return true;
	}

	function add_option( string $key, $value ): bool {
		if ( isset( TestState::$options[ $key ] ) ) {
			return false;
		}

		TestState::$options[ $key ] = $value;

		return true;
	}

	function url_to_postid( string $url ) {
		return TestState::$url_to_post_id[ $url ] ?? 0;
	}

	function delete_option( string $key ): bool {
		unset( TestState::$options[ $key ] );

		return true;
	}

	function apply_filters( string $tag, $value ) {
		unset( $tag );

		return $value;
	}

	function current_time( string $type, bool $gmt = false ) {
		unset( $type, $gmt );

		return TestState::$now;
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

	function esc_html__( string $text, string $domain ): string {
		unset( $domain );

		return $text;
	}

	function esc_html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES );
	}

	function number_format_i18n( $number, int $decimals = 0 ): string {
		return number_format( (float) $number, $decimals );
	}

	function wp_date( string $format, int $timestamp ): string {
		return gmdate( $format, $timestamp );
	}

	function sanitize_key( string $key ): string {
		return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', $key ) );
	}

	function get_post_types( array $args = array(), string $output = 'names' ) {
		unset( $args );

		// Mirrors real WordPress: 'attachment' is public too, and would show up
		// here just like 'post'/'page' unless a caller explicitly excludes it.
		$names = array( 'post', 'page', 'attachment' );

		if ( 'objects' !== $output ) {
			return array_combine( $names, $names );
		}

		$objects = array();

		foreach ( $names as $name ) {
			$objects[ $name ] = (object) array(
				'name'   => $name,
				'labels' => (object) array( 'name' => ucfirst( $name ) ),
			);
		}

		return $objects;
	}

	function get_the_title( int $post_id ): string {
		return TestState::$post_titles[ $post_id ] ?? ( 'Post ' . $post_id );
	}

	function get_permalink( int $post_id ): string {
		return 'https://test.wp.local/?p=' . $post_id;
	}

	function wp_json_encode( $value ) {
		return json_encode( $value );
	}

	function wp_cache_get( string $key, string $group ) {
		return TestState::$cache[ $group ][ $key ] ?? false;
	}

	function wp_cache_set( string $key, $value, string $group, int $ttl = 0 ): bool {
		unset( $ttl );
		TestState::$cache[ $group ][ $key ] = $value;

		return true;
	}

	function get_editable_roles(): array {
		return array(
			'administrator' => array(
				'name'         => 'Administrator',
				'capabilities' => array( 'edit_posts' => true ),
			),
			'editor'        => array(
				'name'         => 'Editor',
				'capabilities' => array( 'edit_posts' => true ),
			),
			'subscriber'    => array(
				'name'         => 'Subscriber',
				'capabilities' => array( 'edit_posts' => false ),
			),
		);
	}

	function translate_user_role( string $name ): string {
		return $name;
	}
}

namespace Bubuku\Plugins\PostViewCount {

	final class TestState {

		/** @var array<int, array<string, mixed>> */
		public static $meta = array();

		/** @var array<int, array<string, mixed>> */
		public static $views = array();

		/** @var array<string, int> */
		public static $daily = array();

		/** @var array<string, int> Keyed by "post_id|day|dimension|value". */
		public static $dims = array();

		/** @var array<string, int> Keyed by "post_id|day|bot". */
		public static $ai_crawls = array();

		/** @var array<string, int> */
		public static $transients = array();

		/** @var array<string, mixed> */
		public static $options = array();

		/** @var array<int, bool> */
		public static $cache_deletions = array();

		/** @var array<string, mixed> */
		public static $route = array();

		/** @var array<int, string> */
		public static $post_titles = array();

		/** @var array<string, array<string, mixed>> Simulated object cache, keyed by group then key. */
		public static $cache = array();

		/** @var array<string, int> Simulated url_to_postid() lookups. */
		public static $url_to_post_id = array();

		/** @var array<string, bool> Simulated current_user_can() results, keyed by capability. */
		public static $current_user_can = array();

		/** @var string Simulated `current_time( 'mysql', true )`. */
		public static $now = '2026-01-01 00:00:00';

		/** @var bool Simulated `wp_using_ext_object_cache()` result. */
		public static $ext_object_cache = false;

		public static function reset(): void {
			self::$meta             = array();
			self::$views            = array();
			self::$daily            = array();
			self::$dims             = array();
			self::$ai_crawls        = array();
			self::$transients       = array();
			self::$options          = array();
			self::$cache_deletions  = array();
			self::$route            = array();
			self::$post_titles      = array();
			self::$cache            = array();
			self::$url_to_post_id   = array();
			self::$current_user_can = array();
			self::$now              = '2026-01-01 00:00:00';
			self::$ext_object_cache = false;
		}
	}

	final class TestWpdb {

		/** @var string */
		public $postmeta = 'wp_postmeta';

		/** @var string */
		public $posts = 'wp_posts';

		/** @var string */
		public $options = 'wp_options';

		/** @var string */
		public $prefix = 'wp_';

		public function prepare( string $query, ...$args ): string {
			$i = 0;

			return preg_replace_callback(
				'/%[ds]/',
				static function ( array $matches ) use ( $args, &$i ) {
					$value = $args[ $i++ ] ?? '';

					return '%d' === $matches[0] ? (string) (int) $value : "'" . addslashes( (string) $value ) . "'";
				},
				$query
			);
		}

		public function esc_like( string $text ): string {
			return addcslashes( $text, '_%\\' );
		}

		public function get_charset_collate(): string {
			return '';
		}

		public function query( string $query ) {
			if ( preg_match( "/INSERT INTO wp_bbk_post_views_daily \\(post_id, day, views\\) VALUES \\((\\d+), '([^']+)', (\\d+)\\)/", $query, $m ) ) {
				$key                     = $m[1] . '|' . $m[2];
				$count                   = (int) $m[3];
				TestState::$daily[ $key ] = ( TestState::$daily[ $key ] ?? 0 ) + $count;

				return 1;
			}

			if ( preg_match( "/INSERT INTO wp_bbk_post_views \\(post_id, views, first_viewed_at, last_viewed_at\\) VALUES \\((\\d+), (\\d+), '([^']+)', '([^']+)'\\)/", $query, $m ) ) {
				$post_id = (int) $m[1];
				$count   = (int) $m[2];
				$now     = $m[3];

				if ( ! isset( TestState::$views[ $post_id ] ) ) {
					TestState::$views[ $post_id ] = array(
						'views'           => $count,
						'first_viewed_at' => $now,
						'last_viewed_at'  => $now,
					);
				} else {
					TestState::$views[ $post_id ]['views']         += $count;
					TestState::$views[ $post_id ]['last_viewed_at'] = $now;
				}

				return 1;
			}

			if ( preg_match( '/INSERT INTO wp_bbk_post_views \\(post_id, views\\) VALUES \\((\\d+), (\\d+)\\) ON DUPLICATE KEY UPDATE views = GREATEST/', $query, $m ) ) {
				$post_id = (int) $m[1];
				$views   = (int) $m[2];

				if ( ! isset( TestState::$views[ $post_id ] ) ) {
					TestState::$views[ $post_id ] = array(
						'views'           => $views,
						'first_viewed_at' => null,
						'last_viewed_at'  => null,
					);
				} else {
					TestState::$views[ $post_id ]['views'] = max( TestState::$views[ $post_id ]['views'], $views );
				}

				return 1;
			}

			if ( preg_match( "/INSERT INTO wp_bbk_post_view_dims \\(post_id, day, dimension, value, views\\) VALUES \\((\\d+), '([^']+)', '([^']+)', '([^']+)', (\\d+)\\)/", $query, $m ) ) {
				$key                    = $m[1] . '|' . $m[2] . '|' . $m[3] . '|' . $m[4];
				$count                  = (int) $m[5];
				TestState::$dims[ $key ] = ( TestState::$dims[ $key ] ?? 0 ) + $count;

				return 1;
			}

			if ( preg_match( "/INSERT INTO wp_bbk_post_ai_crawls \\(post_id, day, bot, views\\) VALUES \\((\\d+), '([^']+)', '([^']+)', 1\\)/", $query, $m ) ) {
				$key                          = $m[1] . '|' . $m[2] . '|' . $m[3];
				TestState::$ai_crawls[ $key ] = ( TestState::$ai_crawls[ $key ] ?? 0 ) + 1;

				return 1;
			}

			if ( preg_match( '/^DROP TABLE IF EXISTS wp_bbk_post_ai_crawls/', $query ) ) {
				TestState::$ai_crawls = array();

				return true;
			}

			if ( preg_match( '/^DROP TABLE IF EXISTS wp_bbk_post_views_daily/', $query ) ) {
				TestState::$daily = array();

				return true;
			}

			if ( preg_match( '/^DROP TABLE IF EXISTS wp_bbk_post_view_dims/', $query ) ) {
				TestState::$dims = array();

				return true;
			}

			if ( preg_match( '/^DROP TABLE IF EXISTS wp_bbk_post_views\b/', $query ) ) {
				TestState::$views = array();

				return true;
			}

			return 0;
		}

		public function get_row( string $query, $output = null ) {
			unset( $output );

			if ( preg_match( '/SELECT views, first_viewed_at, last_viewed_at FROM wp_bbk_post_views WHERE post_id = (\d+)/', $query, $m ) ) {
				return TestState::$views[ (int) $m[1] ] ?? null;
			}

			return null;
		}

		public function get_var( string $query ) {
			unset( $query );

			return 0;
		}

		public function get_results( string $query ) {
			if ( preg_match( "/FROM wp_postmeta WHERE meta_key = 'views'/", $query ) ) {
				$offset = 0;

				if ( preg_match( '/OFFSET (\d+)/', $query, $m ) ) {
					$offset = (int) $m[1];
				}

				$rows = array();

				foreach ( TestState::$meta as $post_id => $metas ) {
					if ( isset( $metas['views'] ) ) {
						$rows[ $post_id ] = (object) array(
							'post_id'    => $post_id,
							'meta_value' => $metas['views'],
						);
					}
				}

				ksort( $rows );

				return array_slice( array_values( $rows ), $offset, 500 );
			}

			return array();
		}
	}

	require_once dirname( __DIR__ ) . '/src/Core/Schema.php';
	require_once dirname( __DIR__ ) . '/src/Core/Dimensions.php';
	require_once dirname( __DIR__ ) . '/src/Core/AiCrawlers.php';
	require_once dirname( __DIR__ ) . '/src/Core/Db.php';
	require_once dirname( __DIR__ ) . '/src/Admin/Settings.php';
	require_once dirname( __DIR__ ) . '/src/Core/WriteBuffer.php';
	require_once dirname( __DIR__ ) . '/src/Api/RestApi.php';
	require_once dirname( __DIR__ ) . '/src/Api/TrendsApi.php';
	require_once dirname( __DIR__ ) . '/src/Api/SettingsApi.php';
	require_once dirname( __DIR__ ) . '/src/Core/Query.php';
	require_once dirname( __DIR__ ) . '/src/Frontend/ViewsDisplay.php';
}

namespace BubukuConex {

	// Minimal stand-in for the hub's base class (github.com/bubuku/bubuku-mcp-conex),
	// present only so the satellite tools can be instantiated and exercised in isolation
	// — the real class is never available in this dependency-free harness. Kept to the
	// subset of methods this plugin's tools actually override.
	abstract class Abstract_Satellite_Tool {

		abstract public function get_name(): string;

		abstract public function get_label(): string;

		abstract public function get_description(): string;

		abstract public function get_input_schema(): array;

		abstract public function get_required_capability(): string;

		/**
		 * @param array<string, mixed> $args
		 * @return mixed
		 */
		abstract public function execute_callback( array $args = array() );

		abstract public function get_help(): array;

		/**
		 * @param array<string, mixed> $args
		 * @param mixed                $result
		 */
		abstract public function get_log_summary( array $args, $result ): string;
	}
}

namespace {

	require_once dirname( __DIR__ ) . '/src/Mcp/Tools/ListMostViewed.php';
	require_once dirname( __DIR__ ) . '/src/Mcp/Tools/ListStaleContent.php';
	require_once dirname( __DIR__ ) . '/src/Mcp/Tools/GetPostViews.php';
	require_once dirname( __DIR__ ) . '/src/Mcp/Tools/GetViewsSummary.php';
	require_once dirname( __DIR__ ) . '/src/Mcp/Tools/GetContentTrends.php';
	require_once dirname( __DIR__ ) . '/src/Mcp/Tools/ListMomentum.php';
	require_once dirname( __DIR__ ) . '/src/Mcp/Tools/GetDimsBreakdown.php';
	require_once dirname( __DIR__ ) . '/src/Mcp/Tools/GetAiTraffic.php';
}
