import { __ } from '@wordpress/i18n';
import { createInterpolateElement } from '@wordpress/element';

function HeaderMain() {
	return (
		<div className="bk-header-main">
			<div className="bk-icon">
				<svg
					width="36"
					height="36"
					viewBox="0 0 24 24"
					fill="none"
					stroke="currentColor"
					strokeWidth="2"
					strokeLinecap="round"
					strokeLinejoin="round"
					aria-hidden="true"
				>
					<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
					<circle cx="12" cy="12" r="3" />
				</svg>
			</div>
			<div>
				<p className="bk-title">
					{ createInterpolateElement(
						/* translators: <span> wraps the secondary word in the title */
						__( 'Post View Count <span>Ajustes</span>', 'bubuku-post-view-count' ),
						{ span: <span /> }
					) }
				</p>
				<p className="bk-subtitle">
					{ __(
						'Conteo de vistas por post y estadísticas de tráfico.',
						'bubuku-post-view-count'
					) }
				</p>
			</div>
		</div>
	);
}

export default HeaderMain;
