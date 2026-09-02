/**
 * Copies the emoji dataset `<emoji-picker>` needs from the
 * `emoji-picker-element-data` npm package into `static/`, so it can be
 * self-hosted by this plugin rather than the library's default of fetching
 * it from a CDN (jsDelivr) at runtime -- WordPress.org plugin guidelines
 * expect a plugin to ship everything it needs rather than loading assets
 * from a third-party host.
 *
 * Not run automatically as part of installing or testing the plugin --
 * only as part of `npm run build`, alongside the webpack bundle. Re-run
 * manually (`npm run build:emoji-data`) after bumping the
 * `emoji-picker-element-data` version to pick up new/changed emoji.
 */

const fs = require( 'fs' );
const path = require( 'path' );

const source = path.resolve(
	__dirname,
	'../node_modules/emoji-picker-element-data/en/emojibase/data.json'
);
const destination = path.resolve( __dirname, '../static/emoji-data.json' );

fs.copyFileSync( source, destination );

console.log( 'Copied emoji data to ' + destination ); // eslint-disable-line no-console
