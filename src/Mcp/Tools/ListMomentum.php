<?php
/**
 * ListMomentum Tool.
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
 * Tool MCP `bubuku-views/list-momentum` (docs/ANALYTICS-PLAN.md §5, "listados en alza/en
 * caída" de la Fase 4). Solo puede cargarse con el hub presente — instanciada por
 * `SatelliteConnector::register_tools()`, nunca antes. Toda la lógica de consulta vive en
 * `Core\Query::momentum()`.
 */
class ListMomentum extends Abstract_Satellite_Tool {

	/**
	 * @return string
	 */
	public function get_name(): string {
		return 'bubuku-views/list-momentum';
	}

	/**
	 * @return string
	 */
	public function get_label(): string {
		return __( 'Rising and falling content', 'bubuku-post-view-count' );
	}

	/**
	 * @return string
	 */
	public function get_description(): string {
		return __( 'Compares the last N days of views against the equal-length period right before them, and returns which posts are gaining or losing views.', 'bubuku-post-view-count' );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_input_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'post_types'  => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => 'Content types to include. Empty = every type enabled in the plugin.',
				),
				'period_days' => array(
					'type'        => 'integer',
					'default'     => 30,
					'description' => 'Length, in days, of each of the two periods being compared.',
				),
				'limit'       => array(
					'type'        => 'integer',
					'default'     => 10,
					'description' => 'Max posts per list (rising/falling).',
				),
				'min_views'   => array(
					'type'        => 'integer',
					'default'     => 1,
					'description' => 'Minimum combined views across both periods for a post to be considered — filters noise from posts going from 0 to 1 view.',
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
		return Query::momentum(
			isset( $args['post_types'] ) ? (array) $args['post_types'] : array(),
			isset( $args['period_days'] ) ? (int) $args['period_days'] : 30,
			isset( $args['limit'] ) ? (int) $args['limit'] : 10,
			isset( $args['min_views'] ) ? (int) $args['min_views'] : 1
		);
	}

	/**
	 * @return array{examples?: string[], criteria?: string, notes?: string}
	 */
	public function get_help(): array {
		return array(
			'examples' => array(
				'What content is gaining traffic this month?',
				'Which posts are losing views compared to last month?',
			),
			'criteria' => __( 'Use it for a ranking of change between two periods. For a single-period ranking use list-most-viewed, and for a time series use get-content-trends.', 'bubuku-post-view-count' ),
		);
	}

	/**
	 * @param array<string, mixed> $args   Argumentos con los que se ejecutó.
	 * @param mixed                $result Valor devuelto por execute_callback().
	 * @return string
	 */
	public function get_log_summary( array $args, $result ): string {
		unset( $args );

		$rising  = is_array( $result ) && isset( $result['rising'] ) ? count( $result['rising'] ) : 0;
		$falling = is_array( $result ) && isset( $result['falling'] ) ? count( $result['falling'] ) : 0;

		return sprintf( 'Computed momentum (%d rising, %d falling)', $rising, $falling );
	}
}
