/**
 * StatCard — compact "at a glance" figure for SummaryCards. Deliberately not a
 * DashboardCard: those are section containers with a header/body split, this is
 * a single number with an optional trend line, sized to sit four-across above
 * them.
 *
 * @param {Object}           props
 * @param {Object}           props.icon      SVG icon element.
 * @param {string}           props.label     Short label above the figure.
 * @param {string}           [props.value]   Formatted headline figure. Omitted while loading.
 * @param {string}           [props.note]    Secondary line under the figure (context, or a delta).
 * @param {'up'|'down'|null} [props.trend]   Colors `note` accordingly when set.
 * @param {boolean}          [props.loading] Renders a skeleton instead of `value`/`note`.
 */
const StatCard = ( {
	icon,
	label,
	value,
	note,
	trend = null,
	loading = false,
} ) => {
	const noteModifier = trend ? ` bbk-stat-card__note--${ trend }` : '';

	return (
		<div className="bbk-stat-card">
			<div className="bbk-stat-card__icon">{ icon }</div>
			<div className="bbk-stat-card__body">
				<span className="bbk-stat-card__label">{ label }</span>
				{ loading ? (
					<span className="bbk-stat-card__skeleton" />
				) : (
					<span className="bbk-stat-card__value">{ value }</span>
				) }
				{ ! loading && note && (
					<span className={ `bbk-stat-card__note${ noteModifier }` }>
						{ note }
					</span>
				) }
			</div>
		</div>
	);
};

export default StatCard;
