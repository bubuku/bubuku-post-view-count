import { Fragment, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { formatNumber } from '../format';

/**
 * ExpandableViewsTable — rows grouped by a label (AI assistant, crawler bot, ...)
 * instead of by page. Each row expands into the pages that contributed to it —
 * the page is still recorded and available, just not shown by default.
 *
 * @param {Object}                props
 * @param {Array<Object>}         props.rows         - `[{ [labelKey]: string, views, posts: [{ id, title, url, views }] }]`.
 * @param {string}                props.labelKey     - Property name identifying each row (e.g. `'assistant'` or `'bot'`).
 * @param {string}                props.headerLabel  - Text for the first column header.
 * @param {Object<string,string>} [props.labels]     - Optional slug -> display label map. Falls back to the raw value.
 * @param {string}                [props.emptyLabel] - Text shown when `rows` is empty. If omitted, renders nothing.
 * @param {string}                [props.totalLabel] - Label shown in the footer row's first cell.
 */
const ExpandableViewsTable = ( {
	rows,
	labelKey,
	headerLabel,
	labels = {},
	emptyLabel,
	totalLabel,
} ) => {
	const [ expanded, setExpanded ] = useState( null );

	if ( ! rows || rows.length === 0 ) {
		return emptyLabel ? (
			<p className="bk-data-table__empty">{ emptyLabel }</p>
		) : null;
	}

	const total = rows.reduce( ( sum, row ) => sum + row.views, 0 );

	return (
		<div className="bk-data-table bbk-expandable-views-table">
			<table className="bk-data-table__table">
				<thead>
					<tr>
						<th>{ headerLabel }</th>
						<th className="bk-data-table__num">
							{ __( 'Views', 'bubuku-post-view-count' ) }
						</th>
					</tr>
				</thead>
				<tbody>
					{ rows.map( ( row ) => {
						const key = row[ labelKey ];
						const isOpen = expanded === key;
						const hasPosts = row.posts && row.posts.length > 0;

						return (
							<Fragment key={ key }>
								<tr>
									<td>
										<button
											type="button"
											className="bbk-expandable-views-table__toggle"
											aria-expanded={ isOpen }
											disabled={ ! hasPosts }
											onClick={ () =>
												setExpanded(
													isOpen ? null : key
												)
											}
										>
											{ labels[ key ] || key }
										</button>
									</td>
									<td className="bk-data-table__num">
										{ formatNumber( row.views ) }
									</td>
								</tr>
								{ isOpen && hasPosts && (
									<tr className="bbk-expandable-views-table__detail">
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

export default ExpandableViewsTable;
