<?php
/**
 * Test frontend stuff.
 *
 * @package react
 */

/**
 * Class React_Test_Frontend
 */
class React_Test_Frontend extends WP_UnitTestCase {
	/**
	 * Test that the container is added to a post.
	 */
	public function test_container_exists() {
		$post_id = $this->factory->post->create();

		$this->go_to( get_permalink( $post_id ) );

		ob_start();
		the_content();
		$content = ob_get_clean();

		$this->assertGreaterThanOrEqual( 0, strpos( '<div class="emoji-reactions"', $content ) );
	}

	/**
	 * Test that the Add Reaction button is added to a post.
	 */
	public function test_add_button_exists() {
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
	public function test_content_not_changed_outside_loop() {
		$react = React::init();

		$content = 'foo';

		$this->assertEquals( $content, $react->the_content( $content ) );
	}

	/**
	 * Test that the emoji.json URL is passed.
	 */
	public function test_json_url_is_passed() {
		$post_id = $this->factory->post->create();

		$this->go_to( get_permalink( $post_id ) );

		ob_start();
		wp_head();
		$head = ob_get_clean();

		$this->assertEquals( 1, preg_match( "/emoji_url: '[^']*emoji.json'/", $head ) );
	}

	/**
	 * Test that the reaction selector markup is added to the footer.
	 */
	public function test_selector_in_footer() {
		$post_id = $this->factory->post->create();

		$this->go_to( get_permalink( $post_id ) );

		$this->setExpectedDeprecated( 'the_block_template_skip_link' );

		ob_start();
		wp_footer();
		$footer = ob_get_clean();

		$this->assertGreaterThanOrEqual( 0, strpos( '<div class="emoji-reaction-selector"', $footer ) );
	}

	/**
	 * Test that reactions are excluded from the theme's main comment list query.
	 */
	public function test_reactions_excluded_from_comments_template_query() {
		$react = React::init();

		$args = $react->exclude_reactions_from_comments_template( array() );

		$this->assertContains( 'reaction', $args['type__not_in'] );
	}

	/**
	 * Test that reactions don't inflate the post's displayed comment count.
	 */
	public function test_reactions_excluded_from_comments_number() {
		$post_id = $this->factory->post->create();

		$this->factory->comment->create(
			array(
				'comment_post_ID'  => $post_id,
				'comment_type'     => 'comment',
				'comment_approved' => 1,
			)
		);

		$this->factory->comment->create(
			array(
				'comment_post_ID'  => $post_id,
				'comment_type'     => 'reaction',
				'comment_approved' => 1,
			)
		);

		$react = React::init();

		$this->assertEquals( 1, $react->exclude_reactions_from_comments_number( 2, $post_id ) );
	}
}
