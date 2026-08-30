<?php
/**
 * GetAiTraffic Tool.
 *
 * @package Bubuku Post View Count
 * @author     Luis Ruiz <lruiz@bubuku.com>
 * @copyright  2022 Bubuku
 * @version    1.2.2
 */

declare( strict_types=1 );

namespace Bubuku\Plugins\PostViewCount\Mcp\Tools;

use Bubuku\Plugins\PostViewCount\Core\Query;
use Bubuku\Plugins\PostViewCount\Core\Schema;
use BubukuConex\Abstract_Satellite_Tool;

defined( 'ABSPATH' ) || exit;

/**
 * Tool MCP `bubuku-views/get-ai-traffic` (docs/ANALYTICS-PLAN.md, Fase F6). Solo puede
 * cargarse con el hub presente — instanciada por `SatelliteConnector::register_tools()`,
 * nunca antes. Toda la lógica de consulta vive en `Core\Query::ai_traffic()`.
 */
class GetAiTraffic extends Abstract_Satellite_Tool {

	/**
	 * @return string
	 */
	public function get_name(): string {
		return 'bubuku-views/get-ai-traffic';
	}

	/**
	 * @return string
	 */
	public function get_label(): string {
		return __( 'AI traffic: referrals and crawlers', 'bubuku-post-view-count' );
	}

	/**
	 * @return string
	 */
	public function get_description(): string {
		return __( 'Reports AI-related traffic in two separate blocks that must never be mixed: "referrals" (human visitors who arrived from an AI assistant like ChatGPT or Claude, already part of the human view count) and "crawlers" (hits from AI crawlers like GPTBot or ClaudeBot, which never execute JavaScript and are only counted if AI-crawler tracking is enabled in the plugin settings).', 'bubuku-post-view-count' );
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
					'description' => 'Inclusive start date (YYYY-MM-DD, UTC). Empty = 3 months ago.',
				),
				'until'      => array(
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
		$ai_traffic = Query::ai_traffic(
			isset( $args['post_types'] ) ? (array) $args['post_types'] : array(),
			isset( $args['since'] ) && '' !== $args['since'] ? (string) $args['since'] : null,
			isset( $args['until'] ) && '' !== $args['until'] ? (string) $args['until'] : null
		);

		return array_merge(
			$ai_traffic,
			array(
				'meta' => array(
					'computed_at'          => gmdate( 'Y-m-d\TH:i:s\Z' ),
					'data_available_since' => Schema::daily_data_since(),
				),
			)
		);
	}

	/**
	 * @return array{examples?: string[], criteria?: string, notes?: string}
	 */
	public function get_help(): array {
		return array(
			'examples' => array(
				'How much traffic comes from ChatGPT or Claude?',
				'Are AI crawlers like GPTBot hitting our site?',
			),
			'criteria' => __( 'Use it specifically for AI-related traffic. For a general referrer breakdown (search, social, direct, etc.) use get-dims-breakdown instead.', 'bubuku-post-view-count' ),
			'notes'    => __( 'The crawlers block is only populated if AI-crawler tracking is enabled in the plugin settings — check ai_crawler_tracking_enabled in the response before presenting an empty list as "no crawler traffic".', 'bubuku-post-view-count' ),
		);
	}

	/**
	 * @param array<string, mixed> $args   Argumentos con los que se ejecutó.
	 * @param mixed                $result Valor devuelto por execute_callback().
	 * @return string
	 */
	public function get_log_summary( array $args, $result ): string {
		unset( $args );

		$referral_views = is_array( $result ) && isset( $result['referrals']['views'] ) ? (int) $result['referrals']['views'] : 0;
		$crawler_count  = is_array( $result ) && isset( $result['crawlers'] ) ? count( $result['crawlers'] ) : 0;

		return sprintf( 'Computed AI traffic (%d referral view(s), %d crawler(s))', $referral_views, $crawler_count );
	}
}
