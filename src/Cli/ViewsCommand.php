<?php
/**
 * ViewsCommand Class.
 *
 * @package Bubuku Post View Count
 * @author     Luis Ruiz <lruiz@bubuku.com>
 * @copyright  2022 Bubuku
 * @version    1.2.1
 */

declare( strict_types=1 );

namespace Bubuku\Plugins\PostViewCount\Cli;

use Bubuku\Plugins\PostViewCount\Core\Query;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * `wp bbk-views` — thin formatting wrappers over `Core\Query` (docs/ANALYTICS-PLAN.md §4.4).
 * Gives a verifiable surface for the analytics layer without a hub or an MCP client. All
 * three subcommands only format and print — no query logic lives here.
 */
class ViewsCommand {

	/**
	 * Lists the most-viewed posts.
	 *
	 * ## OPTIONS
	 *
	 * [--post_types=<types>]
	 * : Comma-separated content types. Default: every type enabled in the plugin.
	 *
	 * [--since=<date>]
	 * : Inclusive start date (YYYY-MM-DD, UTC). Default: all-time total.
	 *
	 * [--until=<date>]
	 * : Inclusive end date (YYYY-MM-DD, UTC). Default: today.
	 *
	 * [--limit=<number>]
	 * : Maximum number of results. Hard cap: 100.
	 * ---
	 * default: 10
	 * ---
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp bbk-views top --limit=5
	 *     wp bbk-views top --post_types=post,page --since=2026-01-01
	 *
	 * @param array<int, string>    $args       Positional arguments (unused).
	 * @param array<string, string> $assoc_args Associative arguments.
	 * @return void
	 */
	public function top( array $args, array $assoc_args ) {
		unset( $args );

		$results = Query::most_viewed(
			$this->split( $assoc_args['post_types'] ?? '' ),
			$assoc_args['since'] ?? null,
			$assoc_args['until'] ?? null,
			(int) ( $assoc_args['limit'] ?? 10 )
		);

		WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $results, array( 'id', 'title', 'views', 'url' ) );
	}

	/**
	 * Lists published content without recent views (or never viewed).
	 *
	 * ## OPTIONS
	 *
	 * [--not_viewed_since=<date>]
	 * : Not viewed since this date (YYYY-MM-DD HH:MM:SS, UTC). Default: 6 months ago.
	 *
	 * [--published_before=<date>]
	 * : Only posts published on/before this date (YYYY-MM-DD HH:MM:SS, UTC). Default: now.
	 *
	 * [--post_types=<types>]
	 * : Comma-separated content types. Default: every type enabled in the plugin.
	 *
	 * [--limit=<number>]
	 * : Maximum number of results. Hard cap: 100.
	 * ---
	 * default: 10
	 * ---
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp bbk-views stale
	 *     wp bbk-views stale --not_viewed_since='2025-01-01 00:00:00' --limit=20
	 *
	 * @param array<int, string>    $args       Positional arguments (unused).
	 * @param array<string, string> $assoc_args Associative arguments.
	 * @return void
	 */
	public function stale( array $args, array $assoc_args ) {
		unset( $args );

		$results = Query::stale(
			$assoc_args['not_viewed_since'] ?? null,
			$assoc_args['published_before'] ?? null,
			$this->split( $assoc_args['post_types'] ?? '' ),
			(int) ( $assoc_args['limit'] ?? 10 )
		);

		WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $results, array( 'id', 'title', 'last_viewed_at', 'url' ) );
	}

	/**
	 * Shows the view stats for a single post.
	 *
	 * ## OPTIONS
	 *
	 * <post_id>
	 * : The post ID.
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp bbk-views post 42
	 *     wp bbk-views post 42 --format=json
	 *
	 * @param array<int, string>    $args       Positional arguments: [ post_id ].
	 * @param array<string, string> $assoc_args Associative arguments.
	 * @return void
	 */
	public function post( array $args, array $assoc_args ) {
		$post_id = (int) ( $args[0] ?? 0 );

		if ( $post_id <= 0 ) {
			WP_CLI::error( 'Provide a numeric post ID.' );

			return;
		}

		$stats = Query::post_stats( $post_id );
		$daily = $stats['daily'];
		unset( $stats['daily'] );

		WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', array( $stats ), array_keys( $stats ) );

		if ( ! empty( $daily ) && 'table' === ( $assoc_args['format'] ?? 'table' ) ) {
			WP_CLI::log( '' );
			WP_CLI::log( 'Daily views (last 90 days):' );
			WP_CLI\Utils\format_items( 'table', $daily, array( 'day', 'views' ) );
		}
	}

	/**
	 * Splits a WP-CLI comma-separated option into an array, discarding blanks.
	 *
	 * @param string $value Raw option value.
	 * @return string[]
	 */
	private function split( string $value ): array {
		return array_values( array_filter( array_map( 'trim', explode( ',', $value ) ) ) );
	}
}
