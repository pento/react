<?php
// Compile emoji data list into an autocompleteable list

$contents = file_get_contents( 'https://raw.githubusercontent.com/iamcal/emoji-data/master/emoji.json' );
file_put_contents( dirname( __DIR__ ) . '/emoji-raw.json', $contents );

$data = json_decode( $contents );
$map = array();

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

	$map[ $category ][] = array(
		'code'       => $emoji->unified,
		'sort_order' => $emoji->sort_order,
	);
}

ksort( $map );

foreach( $map as $category => $emoji_list ) {
	usort( $map[ $category ], function( $a, $b ) {
		if ( $a['sort_order'] == $b['sort_order'] ) {
			return 0;
		}

		return ( $a['sort_order'] < $b['sort_order'] ) ? -1 : 1;
	} );

	foreach( $map[ $category ] as $id => $emoji ) {
		$map[ $category ][ $id ] = $emoji['code'];
	}
}

file_put_contents( dirname( __DIR__ ) . '/emoji.json', json_encode( $map ) );
