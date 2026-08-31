import { formatNumber } from '../format';

/**
 * DataTable — Reusable, responsive results table for admin screens.
 *
 * UI-only and data-agnostic: the parent supplies the column definitions and the
 * row objects. Each column maps to a property of the row (or a custom renderer).
 * Wraps the table in an `overflow-x: auto` container so it stays usable on
 * narrow viewports.
 *
 * @param {Object}        props
 * @param {Array<Object>} props.columns      - Column definitions (left to right).
 * @param {Array<Object>} props.rows         - Array of row objects.
 * @param {Function}      [props.rowKey]     - `( row, index ) => string|number` unique key. Defaults to index.
 * @param {string}        [props.emptyLabel] - Text shown when `rows` is empty. If omitted, renders nothing.
 * @param {boolean}       [props.showTotal]  - Adds a footer row summing every `numeric` column across `rows`.
 * @param {string}        [props.totalLabel] - Label shown in the footer row's first cell.
 */
const DataTable = ( {
	columns,
	rows,
	rowKey,
	emptyLabel,
	showTotal,
	totalLabel,
} ) => {
	if ( ! rows || rows.length === 0 ) {
		return emptyLabel ? (
			<p className="bk-data-table__empty">{ emptyLabel }</p>
		) : null;
	}

	const getKey = ( row, index ) => ( rowKey ? rowKey( row, index ) : index );
	const columnTotal = ( col ) =>
		rows.reduce(
			( sum, row ) => sum + ( Number( row[ col.key ] ) || 0 ),
			0
		);
	const renderFooterCell = ( col, index ) => {
		if ( col.numeric ) {
			return formatNumber( columnTotal( col ) );
		}

		return 0 === index ? totalLabel : null;
	};
	const renderCell = ( col, row ) => {
		if ( col.render ) {
			return col.render( row );
		}

		return col.numeric ? formatNumber( row[ col.key ] ) : row[ col.key ];
	};

	return (
		<div className="bk-data-table">
			<table className="bk-data-table__table">
				<thead>
					<tr>
						{ columns.map( ( col ) => (
							<th
								key={ col.key }
								className={
									col.numeric
										? 'bk-data-table__num'
										: undefined
								}
							>
								{ col.label }
							</th>
						) ) }
					</tr>
				</thead>
				<tbody>
					{ rows.map( ( row, index ) => (
						<tr key={ getKey( row, index ) }>
							{ columns.map( ( col ) => (
								<td
									key={ col.key }
									className={
										col.numeric
											? 'bk-data-table__num'
											: undefined
									}
								>
									{ renderCell( col, row ) }
								</td>
							) ) }
						</tr>
					) ) }
				</tbody>
				{ showTotal && (
					<tfoot>
						<tr className="bk-data-table__total">
							{ columns.map( ( col, index ) => (
								<td
									key={ col.key }
									className={
										col.numeric
											? 'bk-data-table__num'
											: undefined
									}
								>
									{ renderFooterCell( col, index ) }
								</td>
							) ) }
						</tr>
					</tfoot>
				) }
			</table>
		</div>
	);
};

export default DataTable;
