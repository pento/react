<?php

/**
 * Test frontend stuff.
 *
 * @package react
 */

class React_Test_Frontend extends WP_UnitTestCase {
	/**
	 * Test that the container is added to a post
	 */
	function test_container_exists() {
		$post_id = $this->factory->post->create();

		$this->go_to( get_permalink( $post_id ) );

		ob_start();
		the_content();
		$content = ob_get_clean();

		$this->assertGreaterThanOrEqual( 0, strpos( '<div class="emoji-reactions"', $content ) );
	}

	/**
	 * Test that the Add Reaction button is added to a post
	 */
	function test_add_button_exists() {
		$post_id = $this->factory->post->create();

		$this->go_to( get_permalink( $post_id ) );

		ob_start();
		the_content();
		$content = ob_get_clean();

		$this->assertEquals( 1, preg_match( '/<div [^>]*class="emoji-reaction-add"/', $content ) );
	}

	/**
	 * Test that React::the_content() doesn't change the content when not in the loop.
	 */
	function test_content_not_changed_outside_loop() {
		$react = React::init();

		$content ='foo';

		$this->assertEquals( $content, $react->the_content( $content ) );
	}
}
