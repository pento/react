<?php
/**
 * Regenerates static/emoji.json (the compact, categorized emoji data
 * static/react.js actually loads) from the upstream iamcal/emoji-data
 * project.
 *
 * This is a standalone, manual/offline step -- it's not run by any
 * npm/composer script automatically as part of installing, building, or
 * testing the plugin, and it isn't part of the plugin's runtime. It's a
 * one-off CLI script: `php tools/compile_emoji.php`, or `composer
 * compile-emoji`.
 *
 * Re-run it whenever the upstream emoji-data project adds/changes emoji
 * (e.g. after a new Unicode emoji release, or when WordPress core bumps
 * the version of Twemoji it ships, since this filters to only emoji
 * Twemoji actually has images for).
 */

// Compile emoji data list into an autocompleteable list

$contents = file_get_contents( 'https://raw.githubusercontent.com/iamcal/emoji-data/master/emoji.json' );
file_put_contents( dirname( __DIR__ ) . '/static/emoji-raw.json', $contents );

$data = json_decode( $contents );
$map  = array();

$categories = array( 'People', 'Nature', 'Foods', 'Activity', 'Places', 'Objects', 'Symbols', 'Flags' );

foreach ( $data as $emoji ) {
	// Exclude any not supported by Twemoji
	if ( empty( $emoji->has_img_twitter ) ) {
		continue;
	}

	$category = array_search( $emoji->category, $categories );
	if ( false === $category ) {
		if ( 0 === strpos( $emoji->short_name, 'flag-' ) ) {
			$category = 7;
		} else {
			$category = 100;
		}
	}
	$code = '0x' . $emoji->unified;
	$code = str_replace( '-', '-0x', $code );
	$code = explode( '-', $code );

	$map[ $category ][] = array(
		'code'       => $code,
		'sort_order' => $emoji->sort_order,
	);
}

ksort( $map );

foreach ( $map as $category => $emoji_list ) {
	usort(
		$map[ $category ],
		function ( $a, $b ) {
			if ( $a['sort_order'] == $b['sort_order'] ) {
				return 0;
			}

			return ( $a['sort_order'] < $b['sort_order'] ) ? -1 : 1;
		}
	);

	foreach ( $map[ $category ] as $id => $emoji ) {
		$map[ $category ][ $id ] = $emoji['code'];
	}
}

file_put_contents( dirname( __DIR__ ) . '/static/emoji.json', json_encode( $map ) );
