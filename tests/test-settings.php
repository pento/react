<?php
/**
 * Tests for the plugin's settings and custom reaction icons.
 *
 * @package react
 */

/**
 * Class React_Test_Settings
 */
class React_Test_Settings extends WP_UnitTestCase {

	/**
	 * A minimal, valid icon: one path, no stroke, nothing kses will strip.
	 *
	 * @var string
	 */
	const VALID_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M1 21h4V9H1v12z"/></svg>';

	/**
	 * Register a custom icon and return the value a reaction would be stored as.
	 *
	 * Slugs have to be unique per test: both icon registries are process
	 * singletons with no reset between tests, and re-registering the same name
	 * emits a _doing_it_wrong() notice that WP_UnitTestCase turns into a
	 * failure.
	 *
	 * @param string $slug    Icon slug.
	 * @param bool   $retired Whether the icon is retired.
	 * @return string The reaction value for the icon.
	 */
	private function register_icon( $slug, $retired = false ) {
		update_option(
			'react_custom_icons',
			array(
				$slug => array(
					'label'   => 'Test ' . $slug,
					'content' => React_Settings::sanitize_svg( self::VALID_SVG ),
					'retired' => $retired,
				),
			),
			false
		);

		React_Settings::register_icons();

		return React_Settings::icon_token( $slug );
	}

	/**
	 * Every setting should have a usable value before anything is saved.
	 */
	public function test_defaults_are_returned_when_nothing_is_stored() {
		$this->assertTrue( React_Settings::get( 'react_enable_picker' ) );
		$this->assertTrue( React_Settings::get( 'react_allow_skin_tones' ) );
		$this->assertFalse( React_Settings::get( 'react_require_login' ) );
		$this->assertContains( '👍️', React_Settings::get( 'react_default_emoji' ) );
		$this->assertSame( array(), React_Settings::get( 'react_custom_icons' ) );
	}

	/**
	 * An unknown option name shouldn't invent a value.
	 */
	public function test_unknown_setting_returns_null() {
		$this->assertNull( React_Settings::get( 'react_not_a_setting' ) );
	}

	/**
	 * The options.php save loop passes null for a checkbox that wasn't
	 * submitted, which for a checkbox means "off" -- so the callback has to be
	 * total over null rather than passing it through to be stored.
	 */
	public function test_checkbox_sanitizer_is_total_over_null() {
		$this->assertSame( '0', React_Settings::sanitize_bool( null ) );
		$this->assertSame( '0', React_Settings::sanitize_bool( '' ) );
		$this->assertSame( '0', React_Settings::sanitize_bool( '0' ) );
		$this->assertSame( '0', React_Settings::sanitize_bool( 0 ) );
		$this->assertSame( '1', React_Settings::sanitize_bool( '1' ) );
		$this->assertSame( '1', React_Settings::sanitize_bool( 1 ) );
		$this->assertSame( '1', React_Settings::sanitize_bool( true ) );
	}

	/**
	 * Turning off a setting that defaults to on has to actually persist on a
	 * site that has never saved the Discussion page before.
	 *
	 * Storing a boolean false here would collide with the false that
	 * get_option() returns for an absent option, and update_option() would
	 * discard the write as a no-op -- so the box would silently spring back
	 * to ticked. Exercised through the sanitize callback, which is the path
	 * options.php actually takes.
	 */
	public function test_unticking_a_default_on_checkbox_persists() {
		$this->assertTrue( React_Settings::get( 'react_enable_picker' ) );
		$this->assertFalse( get_option( 'react_enable_picker' ) );

		update_option( 'react_enable_picker', React_Settings::sanitize_bool( null ) );

		$this->assertFalse( React_Settings::get( 'react_enable_picker' ) );
	}

	/**
	 * Removing every default emoji is a legitimate choice, and arrives as null.
	 */
	public function test_default_emoji_sanitizer_treats_null_as_empty() {
		$this->assertSame( array(), React_Settings::sanitize_default_emoji( null ) );
	}

	/**
	 * The stored defaults widen what the REST endpoint accepts, so anything
	 * that isn't a real emoji has to be dropped rather than trusted.
	 */
	public function test_default_emoji_sanitizer_rejects_arbitrary_strings() {
		$clean = React_Settings::sanitize_default_emoji(
			array( '👍️', '<script>alert(1)</script>', 'not-an-emoji', '❤️' )
		);

		$this->assertSame( array( '👍️', '❤️' ), $clean );
	}

	/**
	 * Order is meaningful (it's the display order) and duplicates are not.
	 */
	public function test_default_emoji_sanitizer_preserves_order_and_dedupes() {
		$clean = React_Settings::sanitize_default_emoji( array( '❤️', '👍️', '❤️' ) );

		$this->assertSame( array( '❤️', '👍️' ), $clean );
	}

	/**
	 * The list is capped, so a huge paste can't bloat the option.
	 */
	public function test_default_emoji_sanitizer_enforces_a_cap() {
		$emoji = array( '😀', '😃', '😄', '😁', '😆', '😅', '🤣', '😂', '🙂', '🙃', '🫠', '😉', '😊', '😇' );

		$clean = React_Settings::sanitize_default_emoji( $emoji );

		$this->assertCount( React_Settings::MAX_DEFAULT_EMOJI, $clean );
	}

	/**
	 * Slugs have to satisfy the icon registry's own pattern.
	 */
	public function test_icon_slug_sanitizer() {
		$this->assertSame( 'party-popper', React_Settings::sanitize_icon_slug( 'Party Popper' ) );
		$this->assertSame( 'heart', React_Settings::sanitize_icon_slug( '--heart--' ) );
		$this->assertSame( 'a1', React_Settings::sanitize_icon_slug( 'a1' ) );
		$this->assertSame( '', React_Settings::sanitize_icon_slug( '///' ) );
		$this->assertSame( '', React_Settings::sanitize_icon_slug( '' ) );
	}

	/**
	 * A well-behaved icon should survive the checks intact.
	 */
	public function test_preflight_accepts_a_flat_filled_svg() {
		$clean = React_Settings::preflight_svg( self::VALID_SVG );

		$this->assertNotWPError( $clean );
		$this->assertStringContainsString( '<path', $clean );
	}

	/**
	 * Core's kses keeps the children of elements it strips, so a <g transform>
	 * wrapper silently disappears and leaves its paths misplaced. Better to
	 * refuse it outright.
	 */
	public function test_preflight_rejects_unsupported_elements() {
		$svg = '<svg viewBox="0 0 24 24"><g transform="translate(2,2)"><path d="M1 1h4v4H1z"/></g></svg>';

		$error = React_Settings::preflight_svg( $svg );

		$this->assertWPError( $error );
		$this->assertSame( 'react_icon_unsupported_elements', $error->get_error_code() );
	}

	/**
	 * Stroke attributes are stripped, which turns an outline icon into a blob.
	 */
	public function test_preflight_rejects_stroke_based_icons() {
		$svg = '<svg viewBox="0 0 24 24"><path d="M1 1h4v4H1z" stroke="currentColor" stroke-width="2"/></svg>';

		$error = React_Settings::preflight_svg( $svg );

		$this->assertWPError( $error );
		$this->assertSame( 'react_icon_stroke', $error->get_error_code() );
	}

	/**
	 * A shape-only icon sanitizes down to an empty but non-empty-string <svg>,
	 * which the registry accepts and then renders as a blank bubble.
	 */
	public function test_preflight_rejects_an_icon_that_sanitizes_to_nothing() {
		$svg = '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/></svg>';

		$error = React_Settings::preflight_svg( $svg );

		$this->assertWPError( $error );
		$this->assertContains(
			$error->get_error_code(),
			array( 'react_icon_unsupported_elements', 'react_icon_blank' )
		);
	}

	/**
	 * Anything that isn't an SVG at all.
	 */
	public function test_preflight_rejects_non_svg_input() {
		$this->assertWPError( React_Settings::preflight_svg( 'PNG' ) );
		$this->assertWPError( React_Settings::preflight_svg( '' ) );
	}

	/**
	 * Script and event handlers should not survive sanitization.
	 */
	public function test_sanitize_svg_strips_scripts_and_handlers() {
		$svg = '<svg viewBox="0 0 24 24" onload="alert(1)"><script>alert(2)</script><path d="M1 1h4v4H1z" onclick="alert(3)"/></svg>';

		$clean = React_Settings::sanitize_svg( $svg );

		$this->assertStringNotContainsString( 'script', $clean );
		$this->assertStringNotContainsString( 'onload', $clean );
		$this->assertStringNotContainsString( 'onclick', $clean );
		$this->assertStringContainsString( '<path', $clean );
	}

	/**
	 * The icon token has to round-trip, and must not be confusable with an emoji.
	 */
	public function test_icon_token_round_trips() {
		$token = React_Settings::icon_token( 'party' );

		$this->assertSame( 'party', React_Settings::parse_icon_token( $token ) );
		$this->assertFalse( React_Settings::parse_icon_token( '👍' ) );
		$this->assertFalse( React_Settings::parse_icon_token( 'icon:somewhere-else/party' ) );
		$this->assertFalse( React_Settings::parse_icon_token( '' ) );
	}

	/**
	 * Plain emoji are known regardless of settings.
	 */
	public function test_known_reactions_include_emoji_and_skin_tones() {
		$this->assertTrue( React_Settings::is_known_reaction( '👍️' ) );
		$this->assertTrue( React_Settings::is_known_reaction( '👋🏽' ) );
		$this->assertFalse( React_Settings::is_known_reaction( 'nope' ) );
		$this->assertFalse( React_Settings::is_known_reaction( '<script>alert(1)</script>' ) );
	}

	/**
	 * The whole point of the known/offerable split: turning skin tones off
	 * must not make an existing toned reaction impossible to remove.
	 */
	public function test_disabling_skin_tones_keeps_existing_reactions_removable() {
		update_option( 'react_allow_skin_tones', '0' );

		$this->assertTrue( React_Settings::is_known_reaction( '👋🏽' ) );
		$this->assertFalse( React_Settings::is_offerable_reaction( '👋🏽' ) );
		$this->assertTrue( React_Settings::is_offerable_reaction( '👋' ) );
	}

	/**
	 * The bare skin-tone modifiers are in the dataset but never shown by the
	 * picker, so they shouldn't be offerable once tones are off either.
	 */
	public function test_bare_skin_tone_modifiers_are_not_offerable() {
		update_option( 'react_allow_skin_tones', '0' );

		$this->assertFalse( React_Settings::is_offerable_reaction( '🏽' ) );
	}

	/**
	 * With the picker off, only the configured set may be added -- but
	 * everything else stays removable.
	 */
	public function test_disabling_the_picker_narrows_what_can_be_added() {
		update_option( 'react_enable_picker', '0' );
		update_option( 'react_default_emoji', array( '👍️' ) );

		$this->assertTrue( React_Settings::is_offerable_reaction( '👍️' ) );
		$this->assertFalse( React_Settings::is_offerable_reaction( '🎉' ) );
		$this->assertTrue( React_Settings::is_known_reaction( '🎉' ) );
	}

	/**
	 * A registered icon can be reacted with.
	 */
	public function test_registered_icon_is_known_and_offerable() {
		$token = $this->register_icon( 'known-offerable' );

		$this->assertTrue( React_Settings::is_known_reaction( $token ) );
		$this->assertTrue( React_Settings::is_offerable_reaction( $token ) );
	}

	/**
	 * Retiring an icon stops new reactions but keeps existing ones removable,
	 * and keeps them rendering.
	 */
	public function test_retired_icon_stays_known_but_is_not_offerable() {
		$token = $this->register_icon( 'retired-icon', true );

		$this->assertTrue( React_Settings::is_known_reaction( $token ) );
		$this->assertFalse( React_Settings::is_offerable_reaction( $token ) );
		$this->assertNotSame( '', React_Settings::render_icon( $token ) );
	}

	/**
	 * An icon that was never registered is not a valid reaction.
	 */
	public function test_unregistered_icon_token_is_not_known() {
		$this->assertFalse(
			React_Settings::is_known_reaction( React_Settings::icon_token( 'never-registered' ) )
		);
	}

	/**
	 * Registration should render real markup through the Icon API.
	 */
	public function test_registered_icon_renders() {
		$token = $this->register_icon( 'renders' );

		$markup = React_Settings::render_icon( $token );

		$this->assertStringContainsString( '<svg', $markup );
		$this->assertStringContainsString( '<path', $markup );
	}

	/**
	 * Registering twice must not emit a _doing_it_wrong() notice.
	 */
	public function test_registering_icons_twice_is_safe() {
		$this->register_icon( 'idempotent' );

		React_Settings::register_icons();
		React_Settings::register_icons();

		$this->assertTrue(
			React_Settings::is_known_reaction( React_Settings::icon_token( 'idempotent' ) )
		);
	}

	/**
	 * Default emoji and non-retired icons are always shown; retired ones aren't.
	 */
	public function test_always_visible_reactions() {
		update_option( 'react_default_emoji', array( '👍️', '❤️' ) );
		$token = $this->register_icon( 'always-visible' );

		$always = React_Settings::get_always_visible_reactions();

		$this->assertSame( array( '👍️', '❤️', $token ), $always );
	}

	/**
	 * With skin tones off, a toned default shouldn't be rendered as a bubble
	 * nobody is allowed to click.
	 */
	public function test_always_visible_reactions_drop_toned_defaults_when_tones_are_off() {
		update_option( 'react_default_emoji', array( '👍️', '👋🏽' ) );
		update_option( 'react_allow_skin_tones', '0' );

		$this->assertSame( array( '👍️' ), React_Settings::get_always_visible_reactions() );
	}

	/**
	 * Icons stored directly, bypassing the uploader, still get validated.
	 */
	public function test_custom_icons_sanitizer_drops_unusable_entries() {
		$clean = React_Settings::sanitize_custom_icons(
			array(
				'good'  => array(
					'label'   => 'Good',
					'content' => self::VALID_SVG,
				),
				'blank' => array(
					'label'   => 'Blank',
					'content' => '<svg viewBox="0 0 24 24"></svg>',
				),
				'///'   => array(
					'label'   => 'Bad slug',
					'content' => self::VALID_SVG,
				),
				'nosvg' => array( 'label' => 'No content' ),
			)
		);

		$this->assertSame( array( 'good' ), array_keys( $clean ) );
		$this->assertSame( 'Good', $clean['good']['label'] );
	}

	/**
	 * A missing label shouldn't leave the icon nameless.
	 */
	public function test_custom_icons_sanitizer_falls_back_to_the_slug_for_a_label() {
		$clean = React_Settings::sanitize_custom_icons(
			array(
				'unlabelled' => array( 'content' => self::VALID_SVG ),
			)
		);

		$this->assertSame( 'unlabelled', $clean['unlabelled']['label'] );
	}

	/**
	 * Custom icon markup has no business being read on every request.
	 */
	public function test_custom_icons_option_is_not_autoloaded() {
		$this->register_icon( 'not-autoloaded' );

		$this->assertNotContains( 'react_custom_icons', array_keys( wp_load_alloptions() ) );
	}
}
