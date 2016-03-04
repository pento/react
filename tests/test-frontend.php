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

		$this->assertEquals( 1, preg_match( "/<div [^>]*class='emoji-reaction-add'/", $content ) );
	}

	/**
	 * Test that React::the_content() doesn't change the content when not in the loop.
	 */
	function test_content_not_changed_outside_loop() {
		$react = React::init();

		$content ='foo';

		$this->assertEquals( $content, $react->the_content( $content ) );
	}

	/**
	 * Test that the emoji.json URL is passed.
	 */
	function test_json_url_is_passed() {
		$post_id = $this->factory->post->create();

		$this->go_to( get_permalink( $post_id ) );

		ob_start();
		wp_head();
		$head = ob_get_clean();

		$this->assertEquals( 1, preg_match( "/emoji_url: '[^']*emoji.json'/", $head ) );
	}

	function test_selector_in_footer() {
		$post_id = $this->factory->post->create();

		$this->go_to( get_permalink( $post_id ) );

		ob_start();
		wp_footer();
		$footer = ob_get_clean();

		$this->assertGreaterThanOrEqual( 0, strpos( '<div class="emoji-reaction-selector"', $footer ) );
	}
}
