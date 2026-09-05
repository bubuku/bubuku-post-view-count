<?php
/**
 * GetDimsBreakdown Tool.
 *
 * @package Bubuku Post View Count
 * @author     Luis Ruiz <lruiz@bubuku.com>
 * @copyright  2022 Bubuku
 * @version    1.2.1
 */

declare( strict_types=1 );

namespace Bubuku\Plugins\PostViewCount\Mcp\Tools;

use Bubuku\Plugins\PostViewCount\Core\Dimensions;
use Bubuku\Plugins\PostViewCount\Core\Query;
use BubukuConex\Abstract_Satellite_Tool;

defined( 'ABSPATH' ) || exit;

/**
 * Tool MCP `bubuku-views/get-dims-breakdown` (docs/ANALYTICS-PLAN.md, Fase F5). Solo puede
 * cargarse con el hub presente — instanciada por `SatelliteConnector::register_tools()`,
 * nunca antes. Toda la lógica de consulta vive en `Core\Query::dims_breakdown()`.
 */
class GetDimsBreakdown extends Abstract_Satellite_Tool {

	/**
	 * @return string
	 */
	public function get_name(): string {
		return 'bubuku-views/get-dims-breakdown';
	}

	/**
	 * @return string
	 */
	public function get_label(): string {
		return __( 'Traffic breakdown by device or referrer', 'bubuku-post-view-count' );
	}

	/**
	 * @return string
	 */
	public function get_description(): string {
		return __( 'Breaks down site-wide traffic by device screen size ("viewport") or by where visitors came from ("referrer": direct, internal, search, social, ai assistants, other), aggregated per day, never per individual visit.', 'bubuku-post-view-count' );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_input_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'dimension'  => array(
					'type'        => 'string',
					'enum'        => Dimensions::DIMENSIONS,
					'description' => 'Which dimension to break down: "viewport" (device screen size) or "referrer" (where the visit came from).',
				),
				'post_types' => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => 'Content types to include. Empty = every type enabled in the plugin.',
				),
				'since'      => array(
					'type'        => 'string',
					'description' => 'Inclusive start date (YYYY-MM-DD, UTC). Empty = 3 months ago.',
				),
				'until'      => array(
					'type'        => 'string',
					'description' => 'Inclusive end date (YYYY-MM-DD, UTC). Empty = today.',
				),
			),
			'required'   => array( 'dimension' ),
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
		$dimension = isset( $args['dimension'] ) ? (string) $args['dimension'] : '';
		$breakdown = Query::dims_breakdown(
			$dimension,
			isset( $args['post_types'] ) ? (array) $args['post_types'] : array(),
			isset( $args['since'] ) && '' !== $args['since'] ? (string) $args['since'] : null,
			isset( $args['until'] ) && '' !== $args['until'] ? (string) $args['until'] : null
		);

		return array(
			'breakdown' => $breakdown,
			'coverage'  => Query::dimension_coverage(
				$dimension,
				isset( $args['post_types'] ) ? (array) $args['post_types'] : array(),
				isset( $args['since'] ) && '' !== $args['since'] ? (string) $args['since'] : null,
				isset( $args['until'] ) && '' !== $args['until'] ? (string) $args['until'] : null
			),
			'meta'      => Query::measurement_metadata(),
		);
	}

	/**
	 * @return array{examples?: string[], criteria?: string, notes?: string}
	 */
	public function get_help(): array {
		return array(
			'examples' => array(
				'What device size do most of our visitors use?',
				'Where does most of our traffic come from?',
			),
			'criteria' => __( 'Use it for a site-wide breakdown of device size or referrer source. It is not per-post — for a single post use get-post-views.', 'bubuku-post-view-count' ),
			'notes'    => __( 'Data only covers the period since this dimension started being recorded — see data_available_since in the response.', 'bubuku-post-view-count' ),
		);
	}

	/**
	 * @param array<string, mixed> $args   Argumentos con los que se ejecutó.
	 * @param mixed                $result Valor devuelto por execute_callback().
	 * @return string
	 */
	public function get_log_summary( array $args, $result ): string {
		$dimension = isset( $args['dimension'] ) ? (string) $args['dimension'] : '?';
		$count     = is_array( $result ) && isset( $result['breakdown'] ) ? count( $result['breakdown'] ) : 0;

		return sprintf( 'Computed %s breakdown (%d value(s))', $dimension, $count );
	}
}
