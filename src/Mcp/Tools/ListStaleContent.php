<?php
/**
 * ListStaleContent Tool.
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
 * Tool MCP `bubuku-views/list-stale-content` (docs/ANALYTICS-PLAN.md §4.3). Solo puede
 * cargarse con el hub presente — instanciada por `SatelliteConnector::register_tools()`,
 * nunca antes. Toda la lógica de consulta vive en `Core\Query::stale()`.
 */
class ListStaleContent extends Abstract_Satellite_Tool {

	/**
	 * @return string
	 */
	public function get_name(): string {
		return 'bubuku-views/list-stale-content';
	}

	/**
	 * @return string
	 */
	public function get_label(): string {
		return __( 'Content without recent views', 'bubuku-post-view-count' );
	}

	/**
	 * @return string
	 */
	public function get_description(): string {
		return __( 'Returns published posts that have never been viewed, or that have not been viewed in a while. Explicitly includes content that has never had a single view.', 'bubuku-post-view-count' );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_input_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'not_viewed_since' => array(
					'type'        => 'string',
					'description' => 'Not viewed since this date (YYYY-MM-DD HH:MM:SS, UTC). Empty = 6 months ago.',
				),
				'published_before' => array(
					'type'        => 'string',
					'description' => 'Only posts published on/before this date (YYYY-MM-DD HH:MM:SS, UTC). Empty = now.',
				),
				'post_types'       => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => 'Content types to include. Empty = every type enabled in the plugin.',
				),
				'limit'            => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 100,
					'default'     => 10,
					'description' => 'Maximum number of results. Hard cap: 100.',
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
		$results = Query::stale(
			isset( $args['not_viewed_since'] ) && '' !== $args['not_viewed_since'] ? (string) $args['not_viewed_since'] : null,
			isset( $args['published_before'] ) && '' !== $args['published_before'] ? (string) $args['published_before'] : null,
			isset( $args['post_types'] ) ? (array) $args['post_types'] : array(),
			isset( $args['limit'] ) ? (int) $args['limit'] : 10
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
				'Which pages haven\'t been visited in the last 6 months?',
				'Give me published content nobody reads',
			),
			'criteria' => __( 'Use it when asked for content with no recent traffic, or never viewed. If instead they ask for a ranking by views, use list-most-viewed.', 'bubuku-post-view-count' ),
			'notes'    => __( 'Includes content that has never had a single view (null last_viewed_at), not just content that stopped being viewed.', 'bubuku-post-view-count' ),
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

		return sprintf( 'Listed %d stale content item(s)', $count );
	}
}
