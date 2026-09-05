<?php
/**
 * GetContentTrends Tool.
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
 * Tool MCP `bubuku-views/get-content-trends` (docs/ANALYTICS-PLAN.md §4, F4). Solo puede
 * cargarse con el hub presente — instanciada por `SatelliteConnector::register_tools()`,
 * nunca antes. Toda la lógica de consulta vive en `Core\Query::trend()`.
 */
class GetContentTrends extends Abstract_Satellite_Tool {

	/**
	 * @return string
	 */
	public function get_name(): string {
		return 'bubuku-views/get-content-trends';
	}

	/**
	 * @return string
	 */
	public function get_label(): string {
		return __( 'Content view trends', 'bubuku-post-view-count' );
	}

	/**
	 * @return string
	 */
	public function get_description(): string {
		return __( 'Returns views over time, bucketed by day, week or month, for specific posts or content types.', 'bubuku-post-view-count' );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_input_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'post_ids'    => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'integer' ),
					'description' => 'Specific post IDs to include. Takes precedence over post_types when both are given.',
				),
				'post_types'  => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => 'Content types to include when post_ids is empty. Empty = every type enabled in the plugin.',
				),
				'granularity' => array(
					'type'        => 'string',
					'enum'        => array( 'day', 'week', 'month' ),
					'default'     => 'day',
					'description' => 'Bucket size for the trend.',
				),
				'from'        => array(
					'type'        => 'string',
					'description' => 'Inclusive start date (YYYY-MM-DD, UTC). Empty = 3 months ago.',
				),
				'to'          => array(
					'type'        => 'string',
					'description' => 'Inclusive end date (YYYY-MM-DD, UTC). Empty = today.',
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
		$trend = Query::trend(
			isset( $args['post_ids'] ) ? array_map( 'absint', (array) $args['post_ids'] ) : array(),
			isset( $args['post_types'] ) ? (array) $args['post_types'] : array(),
			isset( $args['granularity'] ) ? (string) $args['granularity'] : 'day',
			isset( $args['from'] ) && '' !== $args['from'] ? (string) $args['from'] : null,
			isset( $args['to'] ) && '' !== $args['to'] ? (string) $args['to'] : null
		);

		return array(
			'trend' => $trend,
			'meta'  => Query::measurement_metadata(),
		);
	}

	/**
	 * @return array{examples?: string[], criteria?: string, notes?: string}
	 */
	public function get_help(): array {
		return array(
			'examples' => array(
				'How has traffic to this post evolved over the last 3 months?',
				'Show me the weekly view trend for the blog',
			),
			'criteria' => __( 'Use it for a time series. For a single-point ranking or total, use list-most-viewed or get-views-summary instead.', 'bubuku-post-view-count' ),
		);
	}

	/**
	 * @param array<string, mixed> $args   Argumentos con los que se ejecutó.
	 * @param mixed                $result Valor devuelto por execute_callback().
	 * @return string
	 */
	public function get_log_summary( array $args, $result ): string {
		unset( $args );

		$count = is_array( $result ) && isset( $result['trend'] ) ? count( $result['trend'] ) : 0;

		return sprintf( 'Computed content trend (%d bucket(s))', $count );
	}
}
