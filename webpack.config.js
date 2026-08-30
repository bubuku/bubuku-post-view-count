const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

module.exports = {
	...defaultConfig,
	entry: {
		admin: [
			path.resolve( __dirname, 'assets/src/js/admin/index.js' ),
			path.resolve( __dirname, 'assets/src/scss/admin/style.scss' ),
		],
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
