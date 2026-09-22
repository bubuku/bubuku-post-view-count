const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

module.exports = {
	...defaultConfig,
	entry: {
		admin: [
			path.resolve( __dirname, 'assets/src/js/admin/index.js' ),
			path.resolve( __dirname, 'assets/src/scss/admin/style.scss' ),
		],
		// CSS-only entry for the wp-admin dashboard widget (Admin\DashboardWidget).
		// wp-scripts still emits a residual widget.js — never enqueued, see
		// AdminPage::enqueue_assets().
		widget: path.resolve( __dirname, 'assets/src/scss/widget/style.scss' ),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'assets/build' ),
	},
	resolve: {
		...defaultConfig.resolve,
		alias: {
			...defaultConfig.resolve?.alias,
			'@': path.resolve( __dirname, 'assets' ),
		},
	},
};
