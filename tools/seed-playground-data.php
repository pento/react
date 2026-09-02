<?php
/**
 * Seed test data for WordPress Playground.
 *
 * @package react
 */

// Create a demo post if it doesn't already exist.
$existing = get_page_by_path( 'react-plugin-demo', OBJECT, 'post' );
if ( ! $existing ) {
	$demo_post_id = wp_insert_post(
		array(
			'post_title'     => 'Try Emoji Reactions!',
			'post_name'      => 'react-plugin-demo',
			'post_content'   => '<!-- wp:paragraph -->' . "\n" . '<p>Welcome to the React plugin demo! Below you can see how users can react to your content using emojis.</p>' . "\n" . '<!-- /wp:paragraph -->',
			'post_status'    => 'publish',
			'comment_status' => 'open',
		)
	);

	if ( ! is_wp_error( $demo_post_id ) ) {
		/*
		 * Seed the post with some initial emoji reactions.
		 *
		 * These have to be the dataset's own fully-qualified forms, exactly as
		 * they appear in static/emoji-data.json -- a bare U+2764 heart, for
		 * instance, is not a dataset key, so the REST endpoint would refuse to
		 * remove it and it would render as a second, un-clickable heart beside
		 * the configured default.
		 */
		$initial_reactions = array(
			'😀'  => 5,
			'🌿'  => 3,
			'🍔'  => 8,
			'⚽️' => 2,
			'💡'  => 4,
			'❤️' => 12,
		);

		foreach ( $initial_reactions as $emoji => $count ) {
			for ( $i = 0; $i < $count; $i++ ) {
				wp_insert_comment(
					array(
						'comment_post_ID'  => $demo_post_id,
						'comment_content'  => $emoji,
						'comment_type'     => 'reaction',
						'comment_approved' => 1,
					)
				);
			}
		}
	}
}
