import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import CrawlerCategoryDot from './CrawlerCategoryDot';
import CrawlerCategoryLegend from './CrawlerCategoryLegend';
import { formatNumber } from '../format';

/**
 * AiCrawlerCatalog — collapsible "Rastreadores IA monitorizados" panel inside
 * the Ajustes "AI & privacy" card. Documents every crawler the `ai_crawler_tracking`
 * checkbox above it turns on (all-or-nothing — see AGENTS.md "Versionado" note on
 * the opt-in staying single-checkbox), grouped by category with the same color
 * dots used in the Estadísticas "AI bot crawling" table.
 *
 * @param {Object} props
 * @param {Object} props.catalog - `context.ai_crawler_catalog`: `{ categories, crawlers }` from `Core\AiCrawlers::catalog()`.
 */
const AiCrawlerCatalog = ( { catalog } ) => {
	const [ open, setOpen ] = useState( false );
	const crawlers = catalog?.crawlers || [];
	const categories = catalog?.categories || {};

	if ( ! crawlers.length ) {
		return null;
	}

	return (
		<div className="bbk-crawler-catalog">
			<button
				type="button"
				className="bbk-crawler-catalog__toggle"
				aria-expanded={ open }
				onClick={ () => setOpen( ( prev ) => ! prev ) }
			>
				{ sprintf(
					/* translators: %s: number of monitored AI crawlers. */
					__(
						'AI crawlers monitored (%s)',
						'bubuku-post-view-count'
					),
					formatNumber( crawlers.length )
				) }
			</button>

			{ open && (
				<div className="bbk-crawler-catalog__body">
					<p className="bbk-field-group__help">
						{ __(
							'This plugin monitors the following AI crawlers. The list is updated with every plugin release.',
							'bubuku-post-view-count'
						) }
					</p>

					<CrawlerCategoryLegend categories={ categories } />

					<ul className="bbk-crawler-catalog__grid">
						{ crawlers.map( ( crawler ) => (
							<li
								key={ crawler.name }
								className="bbk-crawler-catalog__card"
							>
								<span className="bbk-crawler-catalog__name">
									{ crawler.name }
								</span>
								<span className="bbk-crawler-catalog__vendor">
									{ crawler.vendor ||
										__(
											'Unknown',
											'bubuku-post-view-count'
										) }
								</span>
								<span
									className={ `bbk-crawler-catalog__badge bbk-crawler-catalog__badge--${ crawler.category }` }
								>
									<CrawlerCategoryDot
										category={ crawler.category }
										label={
											categories[ crawler.category ] ||
											crawler.category
										}
									/>
									{ categories[ crawler.category ] ||
										crawler.category }
								</span>
							</li>
						) ) }
					</ul>
				</div>
			) }
		</div>
	);
};

export default AiCrawlerCatalog;
