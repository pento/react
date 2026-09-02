/**
 * Custom webpack config for bundling `src/*.js` into `static/*.js`.
 *
 * This plugin isn't a block (no `block.json`s for @wordpress/scripts to
 * auto-discover an entry from), so the default config's block-oriented
 * `entry`/`plugins` don't apply -- override just the entry/output/plugins,
 * keeping everything else (JS/CSS loaders, minification, mode) from the
 * shared @wordpress/scripts config.
 */

const path = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

// Only DefinePlugin and MiniCssExtractPlugin are relevant here -- the rest
// (PhpFilePathsPlugin, CopyPlugin, RtlCssPlugin, DependencyExtractionWebpackPlugin)
// exist to support block.json discovery and WordPress script-handle
// dependency extraction, neither of which this plugin uses.
const plugins = defaultConfig.plugins.filter( ( plugin ) =>
	[ 'DefinePlugin', 'MiniCssExtractPlugin' ].includes(
		plugin.constructor.name
	)
);

module.exports = {
	...defaultConfig,
	entry: {
		react: path.resolve( __dirname, 'src/react.js' ),
		// Settings > Discussion needs the emoji picker, but must not load
		// the front-end bundle -- that installs a document-level click
		// handler that posts reactions.
		'react-admin': path.resolve( __dirname, 'src/react-admin.js' ),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'static' ),
		filename: '[name].js',
		// `static/` also holds hand-authored/separately-generated files
		// (react.css, emoji-data.json) that aren't webpack output --
		// the default clean-on-build behavior would delete them since
		// they're not part of this compilation.
		clean: false,
	},
	plugins,
};
