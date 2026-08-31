/**
 * Locale-aware integer formatter (thousand separators) — shared by every
 * admin table that displays a view count, so a French install reads
 * "1 234" and a US one "1,234" instead of a bare, ungrouped number.
 *
 * @param {number} value Value to format.
 * @return {string} Formatted value.
 */
export function formatNumber( value ) {
	return new Intl.NumberFormat(
		document.documentElement.lang || 'en'
	).format( Number( value ) || 0 );
}
