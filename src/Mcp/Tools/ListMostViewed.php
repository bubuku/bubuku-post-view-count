<?php
/**
 * ListMostViewed Tool.
 *
 * @package Bubuku Post View Count
 * @author     Luis Ruiz <lruiz@bubuku.com>
 * @copyright  2022 Bubuku
 * @version    1.2.1
 */

declare( strict_types=1 );

namespace Bubuku\Plugins\PostViewCount\Mcp\Tools;

use Bubuku\Plugins\PostViewCount\Core\Query;
use BubukuConex\Abstract_Satellite_Tool;

defined( 'ABSPATH' ) || exit;

/**
 * Tool MCP `bubuku-views/list-most-viewed` (docs/ANALYTICS-PLAN.md §4.3). Solo puede
 * cargarse con el hub presente — instanciada por `SatelliteConnector::register_tools()`,
 * nunca antes. Toda la lógica de consulta vive en `Core\Query::most_viewed()`.
 */
class ListMostViewed extends Abstract_Satellite_Tool {

	/**
	 * @return string
	 */
	public function get_name(): string {
		return 'bubuku-views/list-most-viewed';
	}

	/**
	 * @return string
	 */
	public function get_label(): string {
		return __( 'Most viewed content', 'bubuku-post-view-count' );
	}

	/**
	 * @return string
	 */
	public function get_description(): string {
		return __( 'Returns the posts with the most views, optionally narrowed to a date window and to specific content types.', 'bubuku-post-view-count' );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_input_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'post_types' => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => 'Content types to include. Empty = every type enabled in the plugin.',
				),
				'since'      => array(
					'type'        => 'string',
					'description' => 'Inclusive start date (YYYY-MM-DD, UTC). Empty = all-time total.',
				),
				'until'      => array(
					'type'        => 'string',
					'description' => 'Inclusive end date (YYYY-MM-DD, UTC). Empty = today.',
				),
				'limit'      => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 100,
					'default'     => 10,
					'description' => 'Maximum number of results. Hard cap: 100.',
				),
				'page'       => array(
					'type'    => 'integer',
					'minimum' => 1,
					'default' => 1,
				),
			),
		);
	}

	/**
	 * @return string
	 */
	public function get_required_capability(): string {
		return 'edit_posts';
	}

	/**
	 * @param array<string, mixed> $args Argumentos validados por el hub contra el schema.
	 * @return array<string, mixed>
	 */
	public function execute_callback( array $args = array() ) {
		$results = Query::most_viewed(
			isset( $args['post_types'] ) ? (array) $args['post_types'] : array(),
			isset( $args['since'] ) && '' !== $args['since'] ? (string) $args['since'] : null,
			isset( $args['until'] ) && '' !== $args['until'] ? (string) $args['until'] : null,
			isset( $args['limit'] ) ? (int) $args['limit'] : 10,
			isset( $args['page'] ) ? (int) $args['page'] : 1
		);

		return array(
			'results' => $results,
			'meta'    => Query::measurement_metadata(),
		);
	}

	/**
	 * @return array{examples?: string[], criteria?: string, notes?: string}
	 */
	public function get_help(): array {
		return array(
			'examples' => array(
				'What has been the most-read content on the blog in the last 6 months?',
				'Give me the top 10 most-viewed posts of all time',
			),
			'criteria' => __( 'Use it when asked for a ranking by view count. If instead they ask for content without recent views, use list-stale-content.', 'bubuku-post-view-count' ),
			'notes'    => __( 'With no date window, returns the exact all-time total. With a window, the data only covers the period since the daily aggregate was installed — see data_available_since in the response.', 'bubuku-post-view-count' ),
		);
	}

	/**
	 * @param array<string, mixed> $args   Argumentos con los que se ejecutó.
	 * @param mixed                $result Valor devuelto por execute_callback().
	 * @return string
	 */
	public function get_log_summary( array $args, $result ): string {
		unset( $args );

		$count = is_array( $result ) && isset( $result['results'] ) ? count( $result['results'] ) : 0;

		return sprintf( 'Listed %d most-viewed post(s)', $count );
	}
}
