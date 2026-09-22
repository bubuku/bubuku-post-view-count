/**
 * CrawlerCategoryDot — small colored indicator for an AI crawler category
 * (assistant / scraper / search / training). Color is never the only carrier
 * of meaning: the dot always ships a `title` and an accessible label, and
 * callers that show it next to a bare bot name (ExpandableViewsTable) pair it
 * with CrawlerCategoryLegend so the color can be looked up.
 *
 * @param {Object} props
 * @param {string} props.category - One of the AiCrawlers category slugs (assistant, scraper, search, training).
 * @param {string} props.label    - Translated category label, used as the dot's title/aria-label.
 */
const CrawlerCategoryDot = ( { category, label } ) => (
	<span
		className={ `bbk-crawler-dot bbk-crawler-dot--${ category }` }
		role="img"
		aria-label={ label }
		title={ label }
	/>
);

export default CrawlerCategoryDot;
