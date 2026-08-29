<?php
/**
 * GetPostViews Tool.
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
 * Tool MCP `bubuku-views/get-post-views` (docs/ANALYTICS-PLAN.md §4.3). Solo puede
 * cargarse con el hub presente — instanciada por `SatelliteConnector::register_tools()`,
 * nunca antes. Toda la lógica de consulta vive en `Core\Query::post_stats()`.
 */
class GetPostViews extends Abstract_Satellite_Tool {

	/**
	 * @return string
	 */
	public function get_name(): string {
		return 'bubuku-views/get-post-views';
	}

	/**
	 * @return string
	 */
	public function get_label(): string {
		return __( 'Post view stats', 'bubuku-post-view-count' );
	}

	/**
	 * @return string
	 */
	public function get_description(): string {
		return __( 'Returns the total views, first and last view, and the 90-day daily series for a specific post, identified by ID or URL.', 'bubuku-post-view-count' );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_input_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'post_id' => array(
					'type'        => 'integer',
					'description' => 'Post ID. Ignored if url is given.',
				),
				'url'     => array(
					'type'        => 'string',
					'description' => 'Post URL. Only used if post_id is not given.',
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
		$post_id = $this->resolve_post_id( $args );

		if ( null === $post_id ) {
			return array(
				'error' => array(
					'code'    => 'missing_post',
					'message' => __( 'Provide post_id or url to identify the post.', 'bubuku-post-view-count' ),
				),
			);
		}

		if ( 0 === $post_id || ! is_post_publicly_viewable( $post_id ) ) {
			return array(
				'error' => array(
					'code'    => 'post_not_found',
					'message' => __( 'No published post was found with that ID or URL.', 'bubuku-post-view-count' ),
				),
			);
		}

		return Query::post_stats( $post_id );
	}

	/**
	 * Resuelve el post_id a partir de los argumentos: post_id tiene prioridad sobre url.
	 *
	 * @param array<string, mixed> $args Argumentos validados.
	 * @return int|null Null si ninguno de los dos se ha proporcionado.
	 */
	private function resolve_post_id( array $args ): ?int {
		if ( ! empty( $args['post_id'] ) ) {
			return (int) $args['post_id'];
		}

		if ( ! empty( $args['url'] ) ) {
			return (int) url_to_postid( (string) $args['url'] );
		}

		return null;
	}

	/**
	 * @return array{examples?: string[], criteria?: string, notes?: string}
	 */
	public function get_help(): array {
		return array(
			'examples' => array(
				'How many views does this article have?',
				'Give me the view trend for the post at URL https://…',
			),
			'criteria' => __( 'Use it for a specific post. For a ranking or a site-wide summary, use list-most-viewed or get-views-summary.', 'bubuku-post-view-count' ),
		);
	}

	/**
	 * @param array<string, mixed> $args   Argumentos con los que se ejecutó.
	 * @param mixed                $result Valor devuelto por execute_callback().
	 * @return string
	 */
	public function get_log_summary( array $args, $result ): string {
		unset( $args );

		if ( is_array( $result ) && isset( $result['id'], $result['views'] ) ) {
			return sprintf( 'Fetched view stats for post %d (%d views)', (int) $result['id'], (int) $result['views'] );
		}

		return 'Fetched post view stats';
	}
}
