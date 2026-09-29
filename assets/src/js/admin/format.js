/**
 * WordPress's own decimal/thousands separators (`$wp_locale->number_format`,
 * localized in `Admin\AdminPage::enqueue_assets()`), the same source
 * `number_format_i18n()` uses for the server-rendered DashboardWidget.
 * Deliberately not `Intl.NumberFormat`: its CLDR grouping data doesn't
 * always agree with WordPress's — e.g. for es-ES it only groups numbers of
 * 5+ digits, so "9160" renders ungrouped next to the widget's "9.160".
 *
 * @return {{decimalPoint: string, thousandsSep: string}} The site's decimal point and thousands separator.
 */
function getLocaleNumberFormat() {
	const numberFormat = window.BbkPostViewCount?.number_format;

	return {
		decimalPoint: numberFormat?.decimal_point ?? '.',
		thousandsSep: numberFormat?.thousands_sep ?? ',',
	};
}

/**
 * Locale-aware integer formatter (thousand separators) — shared by every
 * admin table that displays a view count, so a French install reads
 * "1 234" and a US one "1,234" instead of a bare, ungrouped number. Groups
 * every 3 digits regardless of magnitude, matching `number_format_i18n()`.
 *
 * @param {number} value       Value to format.
 * @param {number} maxDecimals Maximum decimal places to keep (rounded). Defaults to 3, same as `Intl.NumberFormat`'s default.
 * @return {string} Formatted value.
 */
export function formatNumber( value, maxDecimals = 3 ) {
	const num = Number( value ) || 0;
	const { decimalPoint, thousandsSep } = getLocaleNumberFormat();
	const sign = num < 0 ? '-' : '';
	const factor = 10 ** maxDecimals;
	const rounded = Math.round( Math.abs( num ) * factor ) / factor;
	const [ intPart, fracPart ] = rounded.toString().split( '.' );
	const groupedIntPart = intPart.replace(
		/\B(?=(\d{3})+(?!\d))/g,
		thousandsSep
	);

	return sign + groupedIntPart + ( fracPart ? decimalPoint + fracPart : '' );
}
