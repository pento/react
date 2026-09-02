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
	 * Test that the emoji-data.json URL is passed.
	 */
	public function test_json_url_is_passed() {
		$this->expect_footer_deprecation();

		$post_id = $this->factory->post->create();

		$this->go_to( get_permalink( $post_id ) );

		ob_start();
		wp_footer();
		$footer = ob_get_clean();

		$this->assertEquals( 1, preg_match( '/"emoji_data_url":"[^"]*emoji-data\.json"/', $footer ) );
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

	/**
	 * The configured default reactions render even before anyone has reacted.
	 */
	public function test_default_reactions_render_at_zero() {
		update_option( 'react_default_emoji', array( '😀', '🎉' ) );

		$post_id = $this->factory->post->create();
		$this->go_to( get_permalink( $post_id ) );

		ob_start();
		the_content();
		$content = ob_get_clean();

		$this->assertStringContainsString( "data-emoji='😀' data-count='0'", $content );
		$this->assertStringContainsString( "data-emoji='🎉' data-count='0'", $content );
		$this->assertStringContainsString( 'is-zero', $content );
	}

	/**
	 * The count element is always present, even at zero -- the front-end JS
	 * writes straight into it, so omitting it would throw on the first
	 * reaction.
	 */
	public function test_zero_count_bubbles_still_have_a_count_element() {
		update_option( 'react_default_emoji', array( '😀' ) );

		$post_id = $this->factory->post->create();
		$this->go_to( get_permalink( $post_id ) );

		ob_start();
		the_content();
		$content = ob_get_clean();

		$this->assertEquals( 1, preg_match( "/class='emoji-reaction is-zero'>.*?<div class='count'>0<\/div>/", $content ) );
	}

	/**
	 * Defaults keep their configured order, and anything else follows behind.
	 */
	public function test_default_reactions_keep_their_configured_order() {
		update_option( 'react_default_emoji', array( '😀', '🎉' ) );

		$post_id = $this->factory->post->create();
		$this->factory->comment->create(
			array(
				'comment_post_ID'  => $post_id,
				'comment_type'     => 'reaction',
				'comment_content'  => '👏',
				'comment_approved' => 1,
			)
		);

		$this->go_to( get_permalink( $post_id ) );

		ob_start();
		the_content();
		$content = ob_get_clean();

		$this->assertLessThan( strpos( $content, '🎉' ), strpos( $content, '😀' ) );
		$this->assertLessThan( strpos( $content, '👏' ), strpos( $content, '🎉' ) );
	}

	/**
	 * A real tally should beat the zero seed rather than being overwritten by it.
	 */
	public function test_a_reacted_default_shows_its_real_count() {
		update_option( 'react_default_emoji', array( '😀' ) );

		$post_id = $this->factory->post->create();
		$this->factory->comment->create_many(
			3,
			array(
				'comment_post_ID'  => $post_id,
				'comment_type'     => 'reaction',
				'comment_content'  => '😀',
				'comment_approved' => 1,
			)
		);

		$this->go_to( get_permalink( $post_id ) );

		ob_start();
		the_content();
		$content = ob_get_clean();

		$this->assertStringContainsString( "data-emoji='😀' data-count='3'", $content );
		$this->assertStringNotContainsString( 'is-zero', $content );
	}

	/**
	 * With the picker off there's nothing to open, so the button shouldn't render.
	 */
	public function test_add_button_is_hidden_when_the_picker_is_disabled() {
		update_option( 'react_enable_picker', '0' );

		$post_id = $this->factory->post->create();
		$this->go_to( get_permalink( $post_id ) );

		ob_start();
		the_content();
		$content = ob_get_clean();

		$this->assertStringNotContainsString( 'emoji-reaction-add', $content );
	}

	/**
	 * A logged-out visitor on a login-gated site keeps the counts but gets a
	 * link to log in.
	 */
	public function test_login_gate_renders_links_for_logged_out_visitors() {
		update_option( 'react_require_login', '1' );
		update_option( 'react_default_emoji', array( '😀' ) );
		wp_set_current_user( 0 );

		$post_id = $this->factory->post->create();
		$this->go_to( get_permalink( $post_id ) );

		ob_start();
		the_content();
		$content = ob_get_clean();

		$this->assertStringContainsString( 'emoji-reaction-login', $content );
		$this->assertStringContainsString( 'wp-login.php', $content );
		$this->assertStringContainsString( "data-emoji='😀'", $content );
	}

	/**
	 * A logged-in visitor sees the normal buttons even with the gate on.
	 */
	public function test_login_gate_does_not_apply_to_logged_in_users() {
		update_option( 'react_require_login', '1' );
		wp_set_current_user( $this->factory->user->create() );

		$post_id = $this->factory->post->create();
		$this->go_to( get_permalink( $post_id ) );

		ob_start();
		the_content();
		$content = ob_get_clean();

		$this->assertStringNotContainsString( 'emoji-reaction-login', $content );
		$this->assertStringContainsString( 'emoji-reaction-add', $content );
	}

	/**
	 * A custom icon reaction renders as the registered SVG.
	 */
	public function test_custom_icon_reaction_renders_as_svg() {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M1 21h4V9H1v12z"/></svg>';

		update_option(
			'react_custom_icons',
			array(
				'frontend-icon' => array(
					'label'   => 'Frontend Icon',
					'content' => React_Settings::sanitize_svg( $svg ),
					'retired' => false,
				),
			),
			false
		);
		React_Settings::register_icons();

		$post_id = $this->factory->post->create();
		$this->go_to( get_permalink( $post_id ) );

		ob_start();
		the_content();
		$content = ob_get_clean();

		$this->assertStringContainsString( 'emoji-reaction-icon', $content );
		$this->assertStringContainsString( '<svg', $content );
		$this->assertStringContainsString( '<path', $content );
	}

	/**
	 * A reaction whose icon is no longer registered should be skipped rather
	 * than rendering an empty bubble with a live count.
	 */
	public function test_reaction_for_an_unregistered_icon_is_skipped() {
		$post_id = $this->factory->post->create();
		$this->factory->comment->create(
			array(
				'comment_post_ID'  => $post_id,
				'comment_type'     => 'reaction',
				'comment_content'  => 'icon:react-custom/long-gone',
				'comment_approved' => 1,
			)
		);

		$this->go_to( get_permalink( $post_id ) );

		ob_start();
		the_content();
		$content = ob_get_clean();

		$this->assertStringNotContainsString( 'long-gone', $content );
	}

	/**
	 * The new settings reach the browser.
	 */
	public function test_settings_are_passed_to_the_script() {
		$this->expect_footer_deprecation();

		$post_id = $this->factory->post->create();
		$this->go_to( get_permalink( $post_id ) );

		ob_start();
		wp_footer();
		$footer = ob_get_clean();

		$this->assertEquals( 1, preg_match( '/"enable_picker":true/', $footer ) );
		$this->assertEquals( 1, preg_match( '/"allow_skin_tones":true/', $footer ) );
		$this->assertEquals( 1, preg_match( '/"always_visible":\[/', $footer ) );
	}
}
