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
	 * React::enqueue() only runs once, from React::init()'s singleton
	 * constructor, so it registers react-emoji's inline settings script
	 * (with the REST nonce) against whichever $wp_scripts instance exists
	 * at that moment. $wp_scripts itself isn't reset between tests, and a
	 * script's inline "before" data only prints once per instance -- so
	 * without this, only the first test in this class to call wp_footer()
	 * would ever see the settings script in its output.
	 */
	public function set_up() {
		parent::set_up();

		$GLOBALS['wp_scripts'] = null;
		React::init()->enqueue();
	}

	/**
	 * Expect the deprecation notice wp_footer() triggers via WP core's own
	 * the_block_template_skip_link(), unrelated to this plugin. It's only
	 * been deprecated since WP 6.4, so asserting it unconditionally would
	 * fail this plugin's test suite on the older WP versions it still
	 * supports.
	 */
	private function expect_footer_deprecation() {
		global $wp_version;

		if ( version_compare( $wp_version, '6.4', '>=' ) ) {
			$this->setExpectedDeprecated( 'the_block_template_skip_link' );
		}
	}

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
		$this->expect_footer_deprecation();

		$post_id = $this->factory->post->create();

		$this->go_to( get_permalink( $post_id ) );

		ob_start();
		wp_footer();
		$footer = ob_get_clean();

		$this->assertEquals( 1, preg_match( '/"emoji_url":"[^"]*emoji\.json"/', $footer ) );
	}

	/**
	 * Test that a REST nonce is passed to the front end, so logged-in
	 * requests to the reaction endpoint don't get rejected as a failed
	 * cookie-auth check.
	 */
	public function test_rest_nonce_is_passed() {
		$this->expect_footer_deprecation();

		$post_id = $this->factory->post->create();

		$this->go_to( get_permalink( $post_id ) );

		ob_start();
		wp_footer();
		$footer = ob_get_clean();

		$this->assertEquals( 1, preg_match( '/"nonce":"[^"]+"/', $footer ) );
	}

	/**
	 * Test that reaction content is escaped when rendered, so a malicious
	 * comment_content value (however it got into the database) can't inject
	 * markup into the page.
	 */
	public function test_reaction_content_is_escaped() {
		$post_id = $this->factory->post->create();

		$this->factory->comment->create(
			array(
				'comment_post_ID'  => $post_id,
				'comment_type'     => 'reaction',
				'comment_content'  => '\'"><script>alert(1)</script>',
				'comment_approved' => 1,
			)
		);

		$this->go_to( get_permalink( $post_id ) );

		ob_start();
		the_content();
		$content = ob_get_clean();

		$this->assertStringNotContainsString( '<script>alert(1)</script>', $content );
	}

	/**
	 * Test that the reaction selector template is added to the footer.
	 */
	public function test_selector_in_footer() {
		$this->expect_footer_deprecation();

		$post_id = $this->factory->post->create();

		$this->go_to( get_permalink( $post_id ) );

		ob_start();
		wp_footer();
		$footer = ob_get_clean();

		$this->assertStringContainsString( '<script type="text/html" id="tmpl-emoji-reaction-selector">', $footer );
		$this->assertStringContainsString( 'id="emoji-reaction-selector"', $footer );
	}

	/**
	 * Test that a default comment query excludes reactions.
	 */
	public function test_reactions_excluded_from_default_comment_query() {
		React::init();

		$post_id = $this->factory->post->create();

		$this->factory->comment->create(
			array(
				'comment_post_ID'  => $post_id,
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

		$comments = get_comments( array( 'post_id' => $post_id ) );

		$this->assertCount( 1, $comments );
		$this->assertNotEquals( 'reaction', $comments[0]->comment_type );
	}

	/**
	 * Test that a query explicitly asking for reactions still finds them.
	 */
	public function test_reactions_still_queryable_explicitly() {
		React::init();

		$post_id = $this->factory->post->create();

		$this->factory->comment->create(
			array(
				'comment_post_ID'  => $post_id,
				'comment_type'     => 'reaction',
				'comment_approved' => 1,
			)
		);

		$comments = get_comments(
			array(
				'post_id' => $post_id,
				'type'    => 'reaction',
			)
		);

		$this->assertCount( 1, $comments );
	}

	/**
	 * Test that reactions don't inflate the post's displayed comment count.
	 *
	 * Goes through apply_filters() rather than calling
	 * exclude_reactions_from_comments_number() directly, so this also
	 * exercises the actual get_comments_number filter registration
	 * (priority/accepted_args), not just the method's own logic.
	 */
	public function test_reactions_excluded_from_comments_number() {
		React::init();

		$post_id = $this->factory->post->create();

		$this->factory->comment->create(
			array(
				'comment_post_ID'  => $post_id,
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

		$this->assertEquals( 1, apply_filters( 'get_comments_number', 2, $post_id ) );
	}

	/**
	 * Test that an empty post ID is left untouched, rather than triggering a
	 * site-wide reaction count.
	 */
	public function test_comments_number_unchanged_for_empty_post_id() {
		React::init();

		$this->assertEquals( 2, apply_filters( 'get_comments_number', 2, 0 ) );
	}

	/**
	 * Test that the reaction count cache is invalidated when a new reaction
	 * is added, rather than serving a stale count.
	 */
	public function test_reaction_count_cache_invalidated_on_insert() {
		$react = React::init();

		$post_id = $this->factory->post->create();

		$this->assertEquals( 0, $react->get_reaction_count( $post_id ) );

		$this->factory->comment->create(
			array(
				'comment_post_ID'  => $post_id,
				'comment_type'     => 'reaction',
				'comment_approved' => 1,
			)
		);

		$this->assertEquals( 1, $react->get_reaction_count( $post_id ) );
	}

	/**
	 * Test that the reaction count cache is invalidated when a reaction is
	 * deleted, rather than continuing to serve the pre-deletion count
	 * forever.
	 */
	public function test_reaction_count_cache_invalidated_on_delete() {
		$react = React::init();

		$post_id = $this->factory->post->create();

		$comment_id = $this->factory->comment->create(
			array(
				'comment_post_ID'  => $post_id,
				'comment_type'     => 'reaction',
				'comment_approved' => 1,
			)
		);

		$this->assertEquals( 1, $react->get_reaction_count( $post_id ) );

		wp_delete_comment( $comment_id, true );

		$this->assertEquals( 0, $react->get_reaction_count( $post_id ) );
	}

	/**
	 * Test that the reaction count cache is invalidated when a reaction's
	 * comment status changes, e.g. via bulk moderation in wp-admin.
	 */
	public function test_reaction_count_cache_invalidated_on_status_change() {
		$react = React::init();

		$post_id = $this->factory->post->create();

		$comment_id = $this->factory->comment->create(
			array(
				'comment_post_ID'  => $post_id,
				'comment_type'     => 'reaction',
				'comment_approved' => 1,
			)
		);

		$this->assertEquals( 1, $react->get_reaction_count( $post_id ) );

		wp_spam_comment( $comment_id );

		$this->assertEquals( 0, $react->get_reaction_count( $post_id ) );
	}
}
