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
 */
const DataTable = ( { columns, rows, rowKey, emptyLabel } ) => {
	if ( ! rows || rows.length === 0 ) {
		return emptyLabel ? (
			<p className="bk-data-table__empty">{ emptyLabel }</p>
		) : null;
	}

	const getKey = ( row, index ) => ( rowKey ? rowKey( row, index ) : index );

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
									{ col.render
										? col.render( row )
										: row[ col.key ] }
								</td>
							) ) }
						</tr>
					) ) }
				</tbody>
			</table>
		</div>
	);
};

export default DataTable;
