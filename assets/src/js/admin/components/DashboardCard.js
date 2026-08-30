/**
 * DashboardCard — Reusable card for the admin Dashboard.
 *
 * @param {Object} props
 * @param {Object} props.icon             - SVG icon element for the card header.
 * @param {string} [props.iconVariant]    - Icon color variant: 'icon-accent' (default), 'icon-success', 'icon-warning'.
 * @param {string} props.title            - Card title (rendered as h2).
 * @param {string} props.claim            - Card subtitle / description.
 * @param {string} [props.animationDelay] - CSS animation delay (e.g. '.15s').
 * @param {Object} [props.headerMeta]     - Optional content rendered to the right of the header.
 * @param {Object} props.children         - Card body content.
 */
const DashboardCard = ( {
	icon,
	iconVariant = 'icon-accent',
	title,
	claim,
	animationDelay,
	headerMeta = null,
	children,
} ) => {
	const style = animationDelay ? { animationDelay } : undefined;

	return (
		<div className="bk-dashboard-card" style={ style }>
			<div className="card-header">
				<div className="card-header-main">
					<div className={ `card-header-icon ${ iconVariant }` }>
						{ icon }
					</div>
					<div className="card-header-text">
						<h2>{ title }</h2>
						<p>{ claim }</p>
					</div>
				</div>
				{ headerMeta && (
					<div className="card-header-meta">{ headerMeta }</div>
				) }
			</div>
			<div className="card-body">{ children }</div>
		</div>
	);
};

export default DashboardCard;
