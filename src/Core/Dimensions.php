<?php
/**
 * Dimensions Class.
 *
 * @package Bubuku Post View Count
 * @author     Luis Ruiz <lruiz@bubuku.com>
 * @copyright  2022 Bubuku
 * @version    1.2.1
 */

declare( strict_types=1 );

namespace Bubuku\Plugins\PostViewCount\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Single source of truth for the F5 session-dimension whitelist. RestApi
 * validates the per-dimension value before writing; Query/MCP validate the
 * dimension name before reading. Neither side duplicates the other's list.
 */
class Dimensions {

	/**
	 * Dimension names with a writer implemented. `source` (reserved for F6,
	 * see docs/ANALYTICS-PLAN.md) intentionally has no entry here yet.
	 */
	const DIMENSIONS = array( 'viewport', 'referrer', 'ai_assistant' );

	const VIEWPORT_BUCKETS = array( '<576', '576-991', '992-1399', '>=1400' );

	const REFERRER_CLASSES = array( 'direct', 'internal', 'search', 'social', 'ai', 'other' );

	/**
	 * Specific AI assistant behind a `referrer` value of 'ai' — only ever written
	 * alongside it, never on its own (see assets/src/js/public/common.js getAiAssistantClass()).
	 */
	const AI_ASSISTANT_CLASSES = array( 'chatgpt', 'claude', 'perplexity', 'copilot', 'gemini', 'other' );

	/**
	 * Closed whitelist of values for a given dimension name.
	 *
	 * @param string $dimension Dimension name ('viewport'|'referrer'|'ai_assistant').
	 * @return string[] Empty array for an unknown dimension.
	 */
	public static function values_for( string $dimension ): array {
		if ( 'viewport' === $dimension ) {
			return self::VIEWPORT_BUCKETS;
		}

		if ( 'referrer' === $dimension ) {
			return self::REFERRER_CLASSES;
		}

		if ( 'ai_assistant' === $dimension ) {
			return self::AI_ASSISTANT_CLASSES;
		}

		return array();
	}
}
