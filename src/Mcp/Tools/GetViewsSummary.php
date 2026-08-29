<?php
/**
 * GetViewsSummary Tool.
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
 * Tool MCP `bubuku-views/get-views-summary` (docs/ANALYTICS-PLAN.md §4.3). Solo puede
 * cargarse con el hub presente — instanciada por `SatelliteConnector::register_tools()`,
 * nunca antes. Toda la lógica de consulta vive en `Core\Query::summary()`.
 */
class GetViewsSummary extends Abstract_Satellite_Tool {

	/**
	 * @return string
	 */
	public function get_name(): string {
		return 'bubuku-views/get-views-summary';
	}

	/**
	 * @return string
	 */
	public function get_label(): string {
		return __( 'Resumen de vistas del sitio', 'bubuku-post-view-count' );
	}

	/**
	 * @return string
	 */
	public function get_description(): string {
		return __( 'Devuelve totales del sitio: vistas acumuladas, cuántos posts tienen tráfico y cuántos no, para unos tipos de contenido dados.', 'bubuku-post-view-count' );
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
					'description' => 'Tipos de contenido a incluir. Vacío = todos los habilitados en el plugin.',
				),
				'since'      => array(
					'type'        => 'string',
					'description' => 'Fecha de inicio inclusive (YYYY-MM-DD, UTC) para el total de vistas. Vacío = total histórico.',
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
		$summary = Query::summary(
			isset( $args['post_types'] ) ? (array) $args['post_types'] : array(),
			isset( $args['since'] ) && '' !== $args['since'] ? (string) $args['since'] : null
		);

		return array_merge(
			$summary,
			array(
				'computed_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
			)
		);
	}

	/**
	 * @return array{examples?: string[], criteria?: string, notes?: string}
	 */
	public function get_help(): array {
		return array(
			'examples' => array(
				'¿Cuántas vistas acumuladas tiene el blog?',
				'¿Cuántos posts no tienen ni una visita?',
			),
			'criteria' => __( 'Úsala para totales agregados del sitio, no para un ranking. Para un ranking usa list-most-viewed.', 'bubuku-post-view-count' ),
		);
	}

	/**
	 * @param array<string, mixed> $args   Argumentos con los que se ejecutó.
	 * @param mixed                $result Valor devuelto por execute_callback().
	 * @return string
	 */
	public function get_log_summary( array $args, $result ): string {
		unset( $args );

		$total_views = is_array( $result ) && isset( $result['total_views'] ) ? (int) $result['total_views'] : 0;

		return sprintf( 'Computed views summary (%d total views)', $total_views );
	}
}
