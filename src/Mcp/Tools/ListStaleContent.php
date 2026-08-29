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
		return __( 'Contenido sin visitas recientes', 'bubuku-post-view-count' );
	}

	/**
	 * @return string
	 */
	public function get_description(): string {
		return __( 'Devuelve posts publicados que nunca se han visitado, o que no se visitan desde hace tiempo. Incluye explícitamente el contenido que nunca ha tenido ni una vista.', 'bubuku-post-view-count' );
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
					'description' => 'No visitado desde esta fecha (YYYY-MM-DD HH:MM:SS, UTC). Vacío = 6 meses atrás.',
				),
				'published_before' => array(
					'type'        => 'string',
					'description' => 'Solo posts publicados en/antes de esta fecha (YYYY-MM-DD HH:MM:SS, UTC). Vacío = ahora.',
				),
				'post_types'       => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => 'Tipos de contenido a incluir. Vacío = todos los habilitados en el plugin.',
				),
				'limit'            => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 100,
					'default'     => 10,
					'description' => 'Número máximo de resultados. Tope duro: 100.',
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
			'meta'    => array(
				'computed_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
			),
		);
	}

	/**
	 * @return array{examples?: string[], criteria?: string, notes?: string}
	 */
	public function get_help(): array {
		return array(
			'examples' => array(
				'¿Qué páginas no se visitan desde hace 6 meses?',
				'Dame contenido publicado que nadie lee',
			),
			'criteria' => __( 'Úsala cuando pidan contenido sin tráfico reciente o nunca visitado. Si en cambio piden un ranking por vistas, usa list-most-viewed.', 'bubuku-post-view-count' ),
			'notes'    => __( 'Incluye contenido que nunca ha tenido ni una vista (last_viewed_at nulo), no solo el que dejó de visitarse.', 'bubuku-post-view-count' ),
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
