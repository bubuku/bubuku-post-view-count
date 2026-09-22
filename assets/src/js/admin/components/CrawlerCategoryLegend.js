import CrawlerCategoryDot from './CrawlerCategoryDot';

/**
 * CrawlerCategoryLegend — the 4 AI crawler categories with their color dot,
 * shared between the Ajustes catalog panel and the Estadísticas "AI bot
 * crawling" table so both read the same colors the same way.
 *
 * @param {Object}                props
 * @param {Object<string,string>} props.categories - Category slug -> translated label, from `context.ai_crawler_catalog.categories`.
 */
const CrawlerCategoryLegend = ( { categories } ) => {
	const entries = Object.entries( categories || {} );

	if ( ! entries.length ) {
		return null;
	}

	return (
		<ul className="bbk-crawler-legend">
			{ entries.map( ( [ category, label ] ) => (
				<li key={ category } className="bbk-crawler-legend__item">
					<CrawlerCategoryDot category={ category } label={ label } />
					<span>{ label }</span>
				</li>
			) ) }
		</ul>
	);
};

export default CrawlerCategoryLegend;
