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
use Bubuku\Plugins\PostViewCount\Core\Schema;
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
		return __( 'Contenido más visto', 'bubuku-post-view-count' );
	}

	/**
	 * @return string
	 */
	public function get_description(): string {
		return __( 'Devuelve los posts con más vistas, opcionalmente acotado a una ventana de fechas y a unos tipos de contenido.', 'bubuku-post-view-count' );
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
					'description' => 'Fecha de inicio inclusive (YYYY-MM-DD, UTC). Vacío = total histórico.',
				),
				'until'      => array(
					'type'        => 'string',
					'description' => 'Fecha de fin inclusive (YYYY-MM-DD, UTC). Vacío = hoy.',
				),
				'limit'      => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 100,
					'default'     => 10,
					'description' => 'Número máximo de resultados. Tope duro: 100.',
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
			'meta'    => array(
				'computed_at'          => gmdate( 'Y-m-d\TH:i:s\Z' ),
				'data_available_since' => Schema::daily_data_since(),
			),
		);
	}

	/**
	 * @return array{examples?: string[], criteria?: string, notes?: string}
	 */
	public function get_help(): array {
		return array(
			'examples' => array(
				'¿Qué es lo más leído del blog en los últimos 6 meses?',
				'Dame el top 10 de posts más vistos de siempre',
			),
			'criteria' => __( 'Úsala cuando pidan un ranking por número de vistas. Si en cambio piden contenido sin visitas recientes, usa list-stale-content.', 'bubuku-post-view-count' ),
			'notes'    => __( 'Sin ventana de fechas devuelve el total histórico exacto. Con ventana, los datos solo cubren desde que se instaló el agregado diario — ver data_available_since en la respuesta.', 'bubuku-post-view-count' ),
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
