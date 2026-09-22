<?php
/**
 * AiCrawlers Class.
 *
 * @package Bubuku Post View Count
 * @author     Luis Ruiz <lruiz@bubuku.com>
 * @copyright  2022 Bubuku
 * @version    1.3.0
 */

declare( strict_types=1 );

namespace Bubuku\Plugins\PostViewCount\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Closed catalog of known AI-crawler User-Agent signatures (docs/ANALYTICS-PLAN.md, F6
 * "Rastreo por IA"). Deliberately separate from `Admin\Settings::bot_signatures()`: that
 * list only decides whether to exclude a request from the *human* view count; this one
 * identifies *which* AI crawler made the request, for a dedicated, opt-in count that must
 * never mix with human traffic (`Frontend\AiCrawlerTracker`).
 *
 * Each entry maps its canonical name to a vendor and a category. The signature matched
 * against the User-Agent header is the canonical name itself (case-insensitive substring),
 * same convention the original 9-entry list used — sites that see a variant UA can adjust
 * matching with the `bbk_postview_ai_crawlers` filter without waiting for a plugin update.
 */
class AiCrawlers {

	const CATEGORY_ASSISTANT = 'assistant';
	const CATEGORY_SCRAPER   = 'scraper';
	const CATEGORY_SEARCH    = 'search';
	const CATEGORY_TRAINING  = 'training';

	/**
	 * Valid category slugs, in the display order used by the admin catalog and legend.
	 */
	const CATEGORIES = array(
		self::CATEGORY_ASSISTANT,
		self::CATEGORY_SCRAPER,
		self::CATEGORY_SEARCH,
		self::CATEGORY_TRAINING,
	);

	/**
	 * Canonical bot name => [ vendor, category ]. Category legend:
	 * - assistant: fetches a page live to answer a human user's request.
	 * - scraper:   extracts content for third-party pipelines/datasets.
	 * - search:    indexes for an AI-assisted or generative search engine.
	 * - training:  collects corpus to train models.
	 */
	const CRAWLERS = array(
		// -- Asistente IA --------------------------------------------------
		'Amzn-User'               => array( 'Amazon', self::CATEGORY_ASSISTANT ),
		'ChatGPT Agent'           => array( 'OpenAI', self::CATEGORY_ASSISTANT ),
		'ChatGPT-User'            => array( 'OpenAI', self::CATEGORY_ASSISTANT ),
		'Claude-Code'             => array( 'Anthropic', self::CATEGORY_ASSISTANT ),
		'Claude-User'             => array( 'Anthropic', self::CATEGORY_ASSISTANT ),
		'Claude-Web'              => array( 'Anthropic', self::CATEGORY_ASSISTANT ),
		'Cohere-AI'               => array( 'Cohere', self::CATEGORY_ASSISTANT ),
		'Devin'                   => array( 'Cognition AI', self::CATEGORY_ASSISTANT ),
		'DuckAssistBot'           => array( 'DuckDuckGo', self::CATEGORY_ASSISTANT ),
		'Gemini-Deep-Research'    => array( 'Google', self::CATEGORY_ASSISTANT ),
		'Google-Agent'            => array( 'Google', self::CATEGORY_ASSISTANT ),
		'Google-NotebookLM'       => array( 'Google', self::CATEGORY_ASSISTANT ),
		'Google-Read-Aloud'       => array( 'Google', self::CATEGORY_ASSISTANT ),
		'Kimi-User'               => array( 'Moonshot AI', self::CATEGORY_ASSISTANT ),
		'MistralAI-User'          => array( 'Mistral AI', self::CATEGORY_ASSISTANT ),
		'Perplexity-User'         => array( 'Perplexity', self::CATEGORY_ASSISTANT ),

		// -- Extractor de datos ---------------------------------------------
		'Crawlspace'              => array( '', self::CATEGORY_SCRAPER ),
		'Diffbot'                 => array( 'Diffbot', self::CATEGORY_SCRAPER ),
		'FirecrawlAgent'          => array( 'Firecrawl', self::CATEGORY_SCRAPER ),
		'Google-CloudVertexBot'   => array( 'Google', self::CATEGORY_SCRAPER ),
		'GoogleOther'             => array( 'Google', self::CATEGORY_SCRAPER ),
		'ICC-Crawler'             => array( 'NICT', self::CATEGORY_SCRAPER ),
		'ImagesiftBot'            => array( 'Imagesift', self::CATEGORY_SCRAPER ),
		'Kangaroo Bot'            => array( '', self::CATEGORY_SCRAPER ),
		'Meta-ExternalFetcher'    => array( 'Meta', self::CATEGORY_SCRAPER ),
		'OAI-AdsBot'              => array( 'OpenAI', self::CATEGORY_SCRAPER ),
		'Omgili'                  => array( 'Webz.io', self::CATEGORY_SCRAPER ),
		'PanguBot'                => array( 'Huawei', self::CATEGORY_SCRAPER ),
		'Webzio-Extended'         => array( 'Webz.io', self::CATEGORY_SCRAPER ),
		'bedrockbot'              => array( 'Amazon', self::CATEGORY_SCRAPER ),

		// -- Búsqueda IA ------------------------------------------------------
		'Amzn-SearchBot'          => array( 'Amazon', self::CATEGORY_SEARCH ),
		'Applebot'                => array( 'Apple', self::CATEGORY_SEARCH ),
		'BingBot'                 => array( 'Microsoft', self::CATEGORY_SEARCH ),
		'Claude-SearchBot'        => array( 'Anthropic', self::CATEGORY_SEARCH ),
		'Cloudflare-AutoRAG'      => array( 'Cloudflare', self::CATEGORY_SEARCH ),
		'Meta-WebIndexer'         => array( 'Meta', self::CATEGORY_SEARCH ),
		'MistralAI-Index'         => array( 'Mistral AI', self::CATEGORY_SEARCH ),
		'OAI-SearchBot'           => array( 'OpenAI', self::CATEGORY_SEARCH ),
		'PerplexityBot'           => array( 'Perplexity', self::CATEGORY_SEARCH ),
		'PetalBot'                => array( 'Huawei', self::CATEGORY_SEARCH ),
		'YouBot'                  => array( 'You.com', self::CATEGORY_SEARCH ),
		'iAskSpider'              => array( 'iAsk.AI', self::CATEGORY_SEARCH ),

		// -- Entrenamiento IA -----------------------------------------------
		'AI2Bot-Dolma'            => array( 'Allen Institute', self::CATEGORY_TRAINING ),
		'AI2Bot'                  => array( 'Allen Institute', self::CATEGORY_TRAINING ),
		'Amazonbot'               => array( 'Amazon', self::CATEGORY_TRAINING ),
		'Anthropic-AI'            => array( 'Anthropic', self::CATEGORY_TRAINING ),
		'Applebot-Extended'       => array( 'Apple', self::CATEGORY_TRAINING ),
		'Bytespider'              => array( 'ByteDance', self::CATEGORY_TRAINING ),
		'CCBot'                   => array( 'Common Crawl', self::CATEGORY_TRAINING ),
		'ClaudeBot'               => array( 'Anthropic', self::CATEGORY_TRAINING ),
		'Cohere Training Crawler' => array( 'Cohere', self::CATEGORY_TRAINING ),
		'DeepSeekBot'             => array( 'DeepSeek', self::CATEGORY_TRAINING ),
		'FacebookBot'             => array( 'Meta', self::CATEGORY_TRAINING ),
		'FriendlyCrawler'         => array( '', self::CATEGORY_TRAINING ),
		'GPTBot'                  => array( 'OpenAI', self::CATEGORY_TRAINING ),
		'Google-Extended'         => array( 'Google', self::CATEGORY_TRAINING ),
		'GrokBot'                 => array( 'xAI', self::CATEGORY_TRAINING ),
		'Meta-ExternalAgent'      => array( 'Meta', self::CATEGORY_TRAINING ),
		'SBIntuitionsBot'         => array( 'SB Intuitions', self::CATEGORY_TRAINING ),
		'TikTokSpider'            => array( 'ByteDance', self::CATEGORY_TRAINING ),
		'TimpiBot'                => array( 'Timpi', self::CATEGORY_TRAINING ),
		'YandexAdditional'        => array( 'Yandex', self::CATEGORY_TRAINING ),
		'img2dataset'             => array( 'LAION', self::CATEGORY_TRAINING ),
	);

	/**
	 * Identifies the AI crawler behind a User-Agent, if any.
	 *
	 * @param string $user_agent Raw User-Agent header value.
	 * @return string|null Canonical bot name from self::CRAWLERS, or null if none match.
	 */
	public static function detect( string $user_agent ): ?string {
		if ( '' === $user_agent ) {
			return null;
		}

		foreach ( self::ordered_names() as $name ) {
			if ( false !== stripos( $user_agent, $name ) ) {
				return $name;
			}
		}

		return null;
	}

	/**
	 * The category slug for a canonical bot name, or null if unknown.
	 *
	 * @param string $bot Canonical bot name, e.g. from self::detect() or a stored row.
	 * @return string|null
	 */
	public static function category_for( string $bot ): ?string {
		$crawlers = self::crawlers();

		return isset( $crawlers[ $bot ] ) ? $crawlers[ $bot ][1] : null;
	}

	/**
	 * The vendor for a canonical bot name, or '' if unknown/unlisted.
	 *
	 * @param string $bot Canonical bot name.
	 * @return string
	 */
	public static function vendor_for( string $bot ): string {
		$crawlers = self::crawlers();

		return isset( $crawlers[ $bot ] ) ? $crawlers[ $bot ][0] : '';
	}

	/**
	 * Translated label for a category slug, for the admin legend/catalog.
	 *
	 * @param string $category One of self::CATEGORIES.
	 * @return string
	 */
	public static function category_label( string $category ): string {
		switch ( $category ) {
			case self::CATEGORY_ASSISTANT:
				return __( 'AI assistant', 'bubuku-post-view-count' );
			case self::CATEGORY_SCRAPER:
				return __( 'Data scraper', 'bubuku-post-view-count' );
			case self::CATEGORY_SEARCH:
				return __( 'AI search', 'bubuku-post-view-count' );
			case self::CATEGORY_TRAINING:
				return __( 'AI training', 'bubuku-post-view-count' );
			default:
				return $category;
		}
	}

	/**
	 * Full catalog payload for the admin UI: one row per crawler, sorted by category
	 * (in self::CATEGORIES order) then by name, plus the translated category labels —
	 * everything the React "Rastreadores IA monitorizados" panel needs to render itself.
	 *
	 * @return array{ categories: array<string,string>, crawlers: array<int,array{ name: string, vendor: string, category: string }> }
	 */
	public static function catalog(): array {
		$categories = array();

		foreach ( self::CATEGORIES as $category ) {
			$categories[ $category ] = self::category_label( $category );
		}

		$rows = array();

		foreach ( self::crawlers() as $name => $meta ) {
			$rows[] = array(
				'name'     => $name,
				'vendor'   => $meta[0],
				'category' => $meta[1],
			);
		}

		usort(
			$rows,
			static function ( array $a, array $b ): int {
				$order       = array_flip( self::CATEGORIES );
				$by_category = ( $order[ $a['category'] ] ?? 99 ) <=> ( $order[ $b['category'] ] ?? 99 );

				return 0 !== $by_category ? $by_category : strcasecmp( $a['name'], $b['name'] );
			}
		);

		return array(
			'categories' => $categories,
			'crawlers'   => $rows,
		);
	}

	/**
	 * `self::CRAWLERS`, filterable so a site can add, remove or adjust an entry (e.g. to
	 * match a variant User-Agent it actually sees in its logs) without a plugin update.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	private static function crawlers(): array {
		return apply_filters( 'bbk_postview_ai_crawlers', self::CRAWLERS );
	}

	/**
	 * `crawlers()` keys ordered by length, longest first. Needed so a name that is a
	 * substring of another (`Applebot` ⊂ `Applebot-Extended`, `AI2Bot` ⊂ `AI2Bot-Dolma`)
	 * never shadows the longer, more specific match in detect().
	 *
	 * @return string[]
	 */
	private static function ordered_names(): array {
		$names = array_keys( self::crawlers() );

		usort(
			$names,
			static function ( string $a, string $b ): int {
				return strlen( $b ) <=> strlen( $a );
			}
		);

		return $names;
	}
}
