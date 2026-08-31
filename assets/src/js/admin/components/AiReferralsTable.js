import { Fragment, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { formatNumber } from '../format';

const AI_ASSISTANT_LABELS = {
	chatgpt: __( 'ChatGPT', 'bubuku-post-view-count' ),
	claude: __( 'Claude', 'bubuku-post-view-count' ),
	perplexity: __( 'Perplexity', 'bubuku-post-view-count' ),
	copilot: __( 'Copilot', 'bubuku-post-view-count' ),
	gemini: __( 'Gemini', 'bubuku-post-view-count' ),
	other: __( 'Other AI assistant', 'bubuku-post-view-count' ),
};

/**
 * AiReferralsTable — AI referrals grouped by assistant (ChatGPT, Claude, ...)
 * instead of by page. Each row expands into the pages that received those
 * referrals — the page is still recorded and available, just not shown by
 * default, mirroring the "AI bot crawling" table next to it.
 *
 * @param {Object}        props
 * @param {Array<Object>} props.rows         - `[{ assistant, views, posts: [{ id, title, url, views }] }]`.
 * @param {string}        [props.emptyLabel] - Text shown when `rows` is empty. If omitted, renders nothing.
 * @param {string}        [props.totalLabel] - Label shown in the footer row's first cell.
 */
const AiReferralsTable = ( { rows, emptyLabel, totalLabel } ) => {
	const [ expanded, setExpanded ] = useState( null );

	if ( ! rows || rows.length === 0 ) {
		return emptyLabel ? (
			<p className="bk-data-table__empty">{ emptyLabel }</p>
		) : null;
	}

	const total = rows.reduce( ( sum, row ) => sum + row.views, 0 );

	return (
		<div className="bk-data-table bbk-ai-referrals-table">
			<table className="bk-data-table__table">
				<thead>
					<tr>
						<th>{ __( 'AI', 'bubuku-post-view-count' ) }</th>
						<th className="bk-data-table__num">
							{ __( 'Views', 'bubuku-post-view-count' ) }
						</th>
					</tr>
				</thead>
				<tbody>
					{ rows.map( ( row ) => {
						const isOpen = expanded === row.assistant;
						const hasPosts = row.posts && row.posts.length > 0;

						return (
							<Fragment key={ row.assistant }>
								<tr>
									<td>
										<button
											type="button"
											className="bbk-ai-referrals-table__toggle"
											aria-expanded={ isOpen }
											disabled={ ! hasPosts }
											onClick={ () =>
												setExpanded(
													isOpen
														? null
														: row.assistant
												)
											}
										>
											{ AI_ASSISTANT_LABELS[
												row.assistant
											] || row.assistant }
										</button>
									</td>
									<td className="bk-data-table__num">
										{ formatNumber( row.views ) }
									</td>
								</tr>
								{ isOpen && hasPosts && (
									<tr className="bbk-ai-referrals-table__detail">
										<td colSpan={ 2 }>
											<ul>
												{ row.posts.map( ( post ) => (
													<li key={ post.id }>
														<a
															href={ post.url }
															target="_blank"
															rel="noopener noreferrer"
														>
															{ post.title }
														</a>
														<span>
															{ formatNumber(
																post.views
															) }
														</span>
													</li>
												) ) }
											</ul>
										</td>
									</tr>
								) }
							</Fragment>
						);
					} ) }
				</tbody>
				<tfoot>
					<tr className="bk-data-table__total">
						<td>{ totalLabel }</td>
						<td className="bk-data-table__num">
							{ formatNumber( total ) }
						</td>
					</tr>
				</tfoot>
			</table>
		</div>
	);
};

export default AiReferralsTable;
