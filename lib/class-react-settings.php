<?php
/**
 * Settings integration for the Reactions plugin.
 *
 * @package react
 */

/**
 * Class React_Settings
 *
 * Adds the plugin's options to Settings > Discussion, and registers any custom
 * reaction icons with the Icon Registration API added in WordPress 7.1.
 *
 * Two ideas are worth understanding before changing anything here.
 *
 * First, every setting is enforced in the REST controller, not in the browser.
 * `POST /wp/v2/react` is a public route, so the JS half of these settings is
 * only ever a convenience.
 *
 * Second, reactions already stored in the database outlive any settings change.
 * Turning skin tones off, turning the picker off, or retiring a custom icon must
 * never make an existing reaction impossible to remove, so "may this reaction
 * exist at all" (self::is_known_reaction()) is deliberately a much wider
 * question than "may somebody add this reaction right now"
 * (self::is_offerable_reaction()). Collapsing the two would leave visitors
 * staring at reactions they can't un-react.
 */
class React_Settings {

	/**
	 * The option group core's Settings > Discussion form saves.
	 *
	 * @var string
	 */
	const DISCUSSION_GROUP = 'discussion';

	/**
	 * Option group for settings that aren't part of the Discussion form.
	 *
	 * Registering an option still gets it a `sanitize_option_{$option}`
	 * callback and a default, which is worth having for our own writes --
	 * but adding it to the Discussion group would be actively harmful. See
	 * the note on react_custom_icons in self::register_settings().
	 *
	 * @var string
	 */
	const PRIVATE_GROUP = 'react';

	/**
	 * The id of the settings section added to Settings > Discussion.
	 *
	 * @var string
	 */
	const SECTION = 'react';

	/**
	 * The icon collection custom reaction icons are registered under.
	 *
	 * Must satisfy the registry's own slug rule,
	 * ^[a-z0-9]([a-z0-9_-]*[a-z0-9])?$.
	 *
	 * @var string
	 */
	const ICON_COLLECTION = 'react-custom';

	/**
	 * Prefix marking a stored reaction as a custom icon rather than an emoji.
	 *
	 * Safe as a discriminator because no entry in static/emoji-data.json
	 * starts with "icon" or contains a "/".
	 *
	 * @var string
	 */
	const ICON_PREFIX = 'icon:';

	/**
	 * Largest SVG accepted for a custom icon, in bytes.
	 *
	 * Icon markup is stored in an option, so this is a cap on how much gets
	 * loaded and shipped to the browser, not just on the upload.
	 *
	 * @var int
	 */
	const MAX_SVG_BYTES = 16384;

	/**
	 * Most custom icons that can be registered at once.
	 *
	 * @var int
	 */
	const MAX_ICONS = 12;

	/**
	 * Most emoji that can be configured as always-visible defaults.
	 *
	 * @var int
	 */
	const MAX_DEFAULT_EMOJI = 12;

	/**
	 * Default values for every option this class owns.
	 *
	 * Note that the emoji are the dataset's own fully-qualified forms, which is
	 * not always the obvious spelling: thumbs up is U+1F44D U+FE0F in
	 * static/emoji-data.json, and a bare U+1F44D is not a dataset key at all,
	 * so it would be silently dropped by self::sanitize_default_emoji().
	 *
	 * Read through self::get() rather than relying on register_setting()'s
	 * `default`, so front-end and REST requests get the right value whether or
	 * not the settings have been registered yet.
	 *
	 * @return array Option name => default value.
	 */
	public static function defaults() {
		return array(
			'react_enable_picker'    => true,
			'react_allow_skin_tones' => true,
			'react_require_login'    => false,
			'react_default_emoji'    => array( '👍️', '❤️', '😂', '😮', '😢', '😡' ),
			'react_custom_icons'     => array(),
		);
	}

	/**
	 * Read one of the plugin's settings, normalised.
	 *
	 * Deliberately defensive about the stored shape: `wp option update`, a
	 * migration, or a direct database edit can all write a value that never
	 * passed through our sanitiser.
	 *
	 * @param string $key Option name.
	 * @return mixed The setting value, or null for an unknown key.
	 */
	public static function get( $key ) {
		$defaults = self::defaults();

		if ( ! array_key_exists( $key, $defaults ) ) {
			return null;
		}

		$value = get_option( $key, $defaults[ $key ] );

		if ( is_array( $defaults[ $key ] ) ) {
			return is_array( $value ) ? $value : array();
		}

		return (bool) $value;
	}

	/**
	 * Hook everything up.
	 *
	 * Called from react_load() on `plugins_loaded` rather than from
	 * React::__construct(), which runs on `init` -- adding an `init` callback
	 * from inside an `init` callback at the same priority is silently dropped,
	 * because WP_Hook::apply_filters() iterates a copy of the callback array.
	 */
	public static function bootstrap() {
		add_action( 'init', array( __CLASS__, 'register_icons' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'load-options.php', array( __CLASS__, 'maybe_handle_icon_changes' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin' ) );
	}

	/**
	 * Register the settings, and the Settings > Discussion section they live in.
	 *
	 * Runs on `admin_init`, which is early enough: options.php builds its
	 * allowed-options list after firing `admin_init`.
	 */
	public static function register_settings() {
		$booleans = array(
			'react_enable_picker'    => true,
			'react_allow_skin_tones' => true,
			'react_require_login'    => false,
		);

		foreach ( $booleans as $option => $default ) {
			register_setting(
				self::DISCUSSION_GROUP,
				$option,
				array(
					'type'              => 'boolean',
					'default'           => $default,
					'show_in_rest'      => false,
					'sanitize_callback' => array( __CLASS__, 'sanitize_bool' ),
				)
			);
		}

		$defaults = self::defaults();

		register_setting(
			self::DISCUSSION_GROUP,
			'react_default_emoji',
			array(
				'type'              => 'array',
				'default'           => $defaults['react_default_emoji'],
				'show_in_rest'      => false,
				'sanitize_callback' => array( __CLASS__, 'sanitize_default_emoji' ),
			)
		);

		/*
		 * Registered, but pointedly not in the Discussion group.
		 *
		 * options.php walks the group's allowed options and calls
		 * update_option( $option, $value ) unconditionally, with $value = null
		 * when the field wasn't submitted. Custom icons are managed by file
		 * upload rather than by a form field of their own, so including them
		 * here would wipe every icon on each "Save Changes".
		 */
		register_setting(
			self::PRIVATE_GROUP,
			'react_custom_icons',
			array(
				'type'              => 'array',
				'default'           => array(),
				'show_in_rest'      => false,
				'sanitize_callback' => array( __CLASS__, 'sanitize_custom_icons' ),
			)
		);

		add_settings_section(
			self::SECTION,
			__( 'Reactions', 'react' ),
			array( __CLASS__, 'render_section' ),
			self::DISCUSSION_GROUP
		);

		$fields = array(
			'react_default_emoji'    => array( __( 'Default reactions', 'react' ), 'render_default_emoji_field' ),
			'react_enable_picker'    => array( __( 'Emoji picker', 'react' ), 'render_enable_picker_field' ),
			'react_allow_skin_tones' => array( __( 'Skin tones', 'react' ), 'render_allow_skin_tones_field' ),
			'react_require_login'    => array( __( 'Reacting', 'react' ), 'render_require_login_field' ),
			'react_custom_icons'     => array( __( 'Custom reaction icons', 'react' ), 'render_custom_icons_field' ),
		);

		foreach ( $fields as $option => $field ) {
			list( $label, $callback ) = $field;

			add_settings_field(
				$option,
				$label,
				array( __CLASS__, $callback ),
				self::DISCUSSION_GROUP,
				self::SECTION
			);
		}
	}

	/**
	 * Describe the settings section.
	 */
	public static function render_section() {
		echo '<p>' . esc_html__( 'Controls for the emoji reactions shown under each post.', 'react' ) . '</p>';
	}

	/**
	 * Render a single checkbox field.
	 *
	 * @param string $option      Option name.
	 * @param string $label       Checkbox label.
	 * @param string $description Help text shown under the checkbox.
	 */
	private static function render_checkbox( $option, $label, $description ) {
		printf(
			'<fieldset><legend class="screen-reader-text"><span>%1$s</span></legend>' .
			'<label for="%2$s"><input name="%2$s" type="checkbox" id="%2$s" value="1"%3$s /> %1$s</label>' .
			'<p class="description">%4$s</p></fieldset>',
			esc_html( $label ),
			esc_attr( $option ),
			checked( self::get( $option ), true, false ),
			esc_html( $description )
		);
	}

	/**
	 * Render the "Emoji picker" field.
	 */
	public static function render_enable_picker_field() {
		self::render_checkbox(
			'react_enable_picker',
			__( 'Let visitors react with any emoji', 'react' ),
			__( 'When this is off, the picker button is hidden and only the default reactions above can be used.', 'react' )
		);
	}

	/**
	 * Render the "Skin tones" field.
	 */
	public static function render_allow_skin_tones_field() {
		self::render_checkbox(
			'react_allow_skin_tones',
			__( 'Allow skin tone variations', 'react' ),
			__( 'When this is off, reactions are recorded with the default yellow skin tone. Existing reactions are left alone, so they can still be removed.', 'react' )
		);
	}

	/**
	 * Render the "Reacting" field.
	 */
	public static function render_require_login_field() {
		self::render_checkbox(
			'react_require_login',
			__( 'Users must be logged in to react', 'react' ),
			__( 'Reaction counts stay visible to everyone; logged-out visitors get a link to the log in screen instead of a reaction button.', 'react' )
		);
	}

	/**
	 * Render the "Default reactions" field.
	 *
	 * Each emoji carries its own hidden input, and the browser submits them in
	 * document order -- which is what makes reordering round-trip without any
	 * index bookkeeping. Removing every one submits no value at all, which
	 * sanitize_default_emoji() reads as an empty list.
	 */
	public static function render_default_emoji_field() {
		$emoji = self::get( 'react_default_emoji' );

		echo '<fieldset id="react-default-emoji">';
		echo '<legend class="screen-reader-text"><span>' . esc_html__( 'Default reactions', 'react' ) . '</span></legend>';
		echo '<ul class="react-emoji-list">';

		foreach ( $emoji as $item ) {
			self::render_default_emoji_item( $item );
		}

		echo '</ul>';

		printf(
			'<p><button type="button" class="button button-secondary" id="react-add-default-emoji" aria-expanded="false" aria-controls="react-emoji-picker">%s</button></p>',
			esc_html__( 'Add reaction', 'react' )
		);

		echo '<div id="react-emoji-picker" hidden></div>';

		echo '<p class="description">' . esc_html__( 'Shown under every post, even before anyone has reacted. Drag is not required -- use the remove buttons and add in the order you want.', 'react' ) . '</p>';
		echo '</fieldset>';
	}

	/**
	 * Render one entry in the default reactions list.
	 *
	 * Also used by the admin JS as a template, via a data attribute on the
	 * list, so the markup only exists in one place.
	 *
	 * @param string $emoji The emoji.
	 */
	public static function render_default_emoji_item( $emoji ) {
		printf(
			'<li class="react-emoji-item"><input type="hidden" name="react_default_emoji[]" value="%1$s" />' .
			'<span class="react-emoji-item-glyph" aria-hidden="true">%1$s</span>' .
			'<button type="button" class="button-link react-emoji-remove" aria-label="%2$s"><span aria-hidden="true">&times;</span></button></li>',
			esc_attr( $emoji ),
			/* translators: %s: An emoji. */
			esc_attr( sprintf( __( 'Remove %s from the default reactions', 'react' ), $emoji ) )
		);
	}

	/**
	 * Render the "Custom reaction icons" field.
	 *
	 * The file input and its submit button live inside core's Discussion form,
	 * which has no enctype of its own. Rather than rewriting core's form, the
	 * upload button carries formenctype="multipart/form-data" -- an HTML
	 * attribute on the button, so it works with JavaScript disabled and only
	 * affects the submission it triggers. The form still posts to options.php,
	 * so everything else on the Discussion screen saves at the same time.
	 */
	public static function render_custom_icons_field() {
		$icons = self::get_icons();

		echo '<fieldset id="react-custom-icons">';
		echo '<legend class="screen-reader-text"><span>' . esc_html__( 'Custom reaction icons', 'react' ) . '</span></legend>';

		if ( ! function_exists( 'wp_register_icon' ) ) {
			echo '<p class="description">' . esc_html__( 'Custom reaction icons need WordPress 7.1 or newer.', 'react' ) . '</p></fieldset>';
			return;
		}

		/*
		 * Rendered before the early return below, because the retirement
		 * checkboxes are covered by the same nonce and stay usable once the
		 * icon limit has been reached.
		 */
		wp_nonce_field( 'react_icon_upload', 'react_icon_nonce' );

		if ( $icons ) {
			echo '<ul class="react-icon-list">';

			foreach ( $icons as $slug => $icon ) {
				$retired = ! empty( $icon['retired'] );

				echo '<li class="react-icon-item">';
				echo '<span class="react-icon-item-preview">' . self::render_icon( self::icon_token( $slug ) ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_get_icon() returns markup already run through core's icon sanitizer.
				echo '<span class="react-icon-item-label">' . esc_html( $icon['label'] ) . '</span>';
				echo '<code class="react-icon-item-slug">' . esc_html( $slug ) . '</code>';

				printf(
					'<label class="react-icon-item-toggle"><input type="checkbox" name="react_icon_retire[%1$s]" value="1"%2$s /> %3$s</label>',
					esc_attr( $slug ),
					checked( $retired, true, false ),
					esc_html__( 'Retired', 'react' )
				);

				echo '</li>';
			}

			echo '</ul>';
			echo '<p class="description">' . esc_html__( 'Retiring an icon stops it being offered for new reactions, but keeps existing ones visible and removable. Retired icons are saved with the rest of this page.', 'react' ) . '</p>';
		}

		if ( count( $icons ) >= self::MAX_ICONS ) {
			printf( '<p class="description">%s</p></fieldset>', esc_html( sprintf( /* translators: %d: Maximum number of icons. */ __( 'The limit of %d custom icons has been reached. Remove one before adding another.', 'react' ), self::MAX_ICONS ) ) );
			return;
		}

		printf(
			'<p><label for="react_icon_label">%s</label><br /><input type="text" class="regular-text" id="react_icon_label" name="react_icon_label" /></p>',
			esc_html__( 'Icon name', 'react' )
		);

		echo '<p><input type="file" id="react_icon_file" name="react_icon_file" accept=".svg,image/svg+xml" /></p>';

		printf(
			'<p><button type="submit" class="button button-secondary" formenctype="multipart/form-data" name="react_icon_upload" value="1">%s</button></p>',
			esc_html__( 'Upload icon', 'react' )
		);

		echo '<p class="description">' . wp_kses(
			sprintf(
				/* translators: 1: <path>, 2: <polygon>, 3: stroke. */
				__( 'SVG only. WordPress allows just %1$s and %2$s inside a registered icon and strips %3$s attributes, so flat, filled artwork works and outline icon sets do not. Icons are stored as sanitized markup -- nothing is added to your media library.', 'react' ),
				'<code>&lt;path&gt;</code>',
				'<code>&lt;polygon&gt;</code>',
				'<code>stroke</code>'
			),
			array( 'code' => array() )
		) . '</p>';

		echo '</fieldset>';
	}

	/**
	 * Coerce a checkbox into a stored '1' or '0'.
	 *
	 * Must be total over null: options.php passes null for a checkbox that
	 * wasn't submitted, which for a checkbox correctly means "off".
	 *
	 * Returns a string rather than a bool for a subtle but load-bearing
	 * reason. get_option() answers false for an option that has never been
	 * saved, and update_option() returns early when the new value is
	 * identical to the old one -- so storing boolean false for a
	 * default-on setting on a fresh install would match that absent-row
	 * false, write nothing, and leave self::get() still reading the default
	 * of true. In other words, unchecking the box would appear not to save.
	 * '0' is falsy but is not identical to false, so it always persists.
	 *
	 * @param mixed $value Submitted value.
	 * @return string '1' when the box was ticked, '0' otherwise.
	 */
	public static function sanitize_bool( $value ) {
		return ( ! empty( $value ) && '0' !== $value ) ? '1' : '0';
	}

	/**
	 * Sanitize the configured default reactions.
	 *
	 * Intersecting against the emoji dataset is a security requirement, not
	 * tidiness: these values are added to what the REST endpoint will accept,
	 * so without it an administrator could store arbitrary strings as
	 * comment content -- exactly what validate_emoji() exists to prevent.
	 *
	 * A null value means every entry was removed from the list, which is a
	 * legitimate choice (it restores the pre-settings behaviour of only
	 * showing reactions people have actually left).
	 *
	 * @param mixed $value Submitted value.
	 * @return array Ordered, de-duplicated list of known emoji.
	 */
	public static function sanitize_default_emoji( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$emoji = array();

		foreach ( $value as $candidate ) {
			if ( ! is_string( $candidate ) || '' === $candidate ) {
				continue;
			}

			if ( in_array( $candidate, $emoji, true ) ) {
				continue;
			}

			if ( ! WP_REST_React_Controller::is_known_emoji( $candidate ) ) {
				continue;
			}

			$emoji[] = $candidate;

			if ( count( $emoji ) >= self::MAX_DEFAULT_EMOJI ) {
				break;
			}
		}

		return $emoji;
	}

	/**
	 * Sanitize the stored custom icons.
	 *
	 * Re-runs the SVG checks rather than trusting that the value arrived via
	 * the uploader, since the option can also be written directly.
	 *
	 * @param mixed $value Submitted value.
	 * @return array Slug => icon definition.
	 */
	public static function sanitize_custom_icons( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$icons = array();

		foreach ( $value as $slug => $icon ) {
			if ( count( $icons ) >= self::MAX_ICONS ) {
				break;
			}

			if ( ! is_array( $icon ) || empty( $icon['content'] ) ) {
				continue;
			}

			$slug = self::sanitize_icon_slug( $slug );
			if ( '' === $slug || isset( $icons[ $slug ] ) ) {
				continue;
			}

			$content = self::sanitize_svg( $icon['content'] );
			if ( ! self::svg_has_shapes( $content ) ) {
				continue;
			}

			$label = isset( $icon['label'] ) ? sanitize_text_field( $icon['label'] ) : '';
			if ( '' === $label ) {
				$label = $slug;
			}

			$icons[ $slug ] = array(
				'label'   => $label,
				'content' => $content,
				'retired' => ! empty( $icon['retired'] ),
			);
		}

		return $icons;
	}

	/**
	 * Coerce a string into something the icon registry will accept as a slug.
	 *
	 * @param string $slug Candidate slug.
	 * @return string A valid slug, or an empty string if nothing usable remains.
	 */
	public static function sanitize_icon_slug( $slug ) {
		$slug = strtolower( remove_accents( (string) $slug ) );
		$slug = preg_replace( '/[^a-z0-9_-]+/', '-', $slug );
		$slug = trim( (string) $slug, '-_' );

		if ( ! preg_match( '/^[a-z0-9](?:[a-z0-9_-]*[a-z0-9])?$/', (string) $slug ) ) {
			return '';
		}

		return $slug;
	}

	/**
	 * Run SVG markup through the same allowlist core's icon registry uses.
	 *
	 * Kept in step with WP_Icons_Registry::sanitize_icon_content() so that
	 * what gets stored is exactly what will survive registration -- and so the
	 * markup handed to the front-end JS is already safe.
	 *
	 * @param string $markup SVG markup.
	 * @return string Sanitized markup.
	 */
	public static function sanitize_svg( $markup ) {
		$allowed = array(
			'svg'     => array(
				'class'       => true,
				'xmlns'       => true,
				'width'       => true,
				'height'      => true,
				'viewbox'     => true,
				'aria-hidden' => true,
				'role'        => true,
				'focusable'   => true,
			),
			'path'    => array(
				'fill'      => true,
				'fill-rule' => true,
				'd'         => true,
				'transform' => true,
			),
			'polygon' => array(
				'fill'      => true,
				'fill-rule' => true,
				'points'    => true,
				'transform' => true,
				'focusable' => true,
			),
		);

		return trim( wp_kses( (string) $markup, $allowed ) );
	}

	/**
	 * Whether sanitized SVG markup still draws anything.
	 *
	 * Worth checking separately, because the registry's only guard is
	 * `empty()`: an icon drawn entirely with <circle> sanitizes down to
	 * "<svg></svg>", which registers happily and then renders as a blank
	 * bubble with a live count.
	 *
	 * @param string $markup Sanitized SVG markup.
	 * @return bool
	 */
	public static function svg_has_shapes( $markup ) {
		return 1 === preg_match( '/<(?:path|polygon)[\s>\/]/i', (string) $markup );
	}

	/**
	 * Check an uploaded SVG before it gets anywhere near the registry.
	 *
	 * Core's sanitiser strips disallowed elements but keeps their children, so
	 * a perfectly valid icon can come out silently wrong rather than rejected:
	 * a <g transform="..."> wrapper disappears and leaves its paths misplaced,
	 * a stroke-based icon loses every stroke attribute and renders as a blob,
	 * and a shape-based icon vanishes entirely. Better to say so up front.
	 *
	 * @param string $markup Raw SVG markup.
	 * @return string|WP_Error Sanitized markup, or an error explaining why not.
	 */
	public static function preflight_svg( $markup ) {
		$markup = trim( (string) $markup );

		if ( '' === $markup ) {
			return new WP_Error( 'react_icon_empty', __( 'That file was empty.', 'react' ) );
		}

		if ( ! preg_match( '/<svg[\s>]/i', $markup ) ) {
			return new WP_Error( 'react_icon_not_svg', __( 'That file does not appear to be an SVG image. Custom reaction icons have to be SVGs.', 'react' ) );
		}

		$unsupported = array();
		$elements    = array( 'g', 'circle', 'rect', 'ellipse', 'line', 'polyline', 'use', 'defs', 'mask', 'clippath', 'lineargradient', 'radialgradient', 'text', 'image', 'style', 'symbol', 'filter' );

		foreach ( $elements as $element ) {
			if ( preg_match( '/<' . $element . '[\s>\/]/i', $markup ) ) {
				$unsupported[] = '<' . $element . '>';
			}
		}

		if ( $unsupported ) {
			return new WP_Error(
				'react_icon_unsupported_elements',
				sprintf(
					/* translators: %s: Comma-separated list of SVG element names, e.g. "<g>, <circle>". */
					__( 'WordPress only allows %1$s and %2$s inside a registered icon, so this icon would not render correctly. It uses: %3$s. Try flattening it to paths in your vector editor first.', 'react' ),
					'<code>&lt;path&gt;</code>',
					'<code>&lt;polygon&gt;</code>',
					'<code>' . implode( ', ', array_map( 'esc_html', $unsupported ) ) . '</code>'
				)
			);
		}

		if ( preg_match( '/\sstroke(?:-[a-z]+)?\s*=/i', $markup ) ) {
			return new WP_Error(
				'react_icon_stroke',
				sprintf(
					/* translators: %s: The word "stroke" as a code-formatted SVG attribute name. */
					__( 'WordPress strips %s attributes from registered icons, so an outline-style icon would render as a solid shape. Use a filled icon instead.', 'react' ),
					'<code>stroke</code>'
				)
			);
		}

		$clean = self::sanitize_svg( $markup );

		if ( ! self::svg_has_shapes( $clean ) ) {
			return new WP_Error( 'react_icon_blank', __( 'Nothing was left of that icon once WordPress had sanitized it, so it would render as a blank space.', 'react' ) );
		}

		return $clean;
	}

	/**
	 * Get the stored custom icons.
	 *
	 * @param bool $include_retired Whether to include icons that have been retired.
	 * @return array Slug => icon definition.
	 */
	public static function get_icons( $include_retired = true ) {
		$icons = self::get( 'react_custom_icons' );

		if ( $include_retired ) {
			return $icons;
		}

		$offerable = array();

		foreach ( $icons as $slug => $icon ) {
			if ( empty( $icon['retired'] ) ) {
				$offerable[ $slug ] = $icon;
			}
		}

		return $offerable;
	}

	/**
	 * Build the value a custom icon reaction is stored as.
	 *
	 * @param string $slug Icon slug.
	 * @return string
	 */
	public static function icon_token( $slug ) {
		return self::ICON_PREFIX . self::ICON_COLLECTION . '/' . $slug;
	}

	/**
	 * Pull the icon slug back out of a stored reaction value.
	 *
	 * @param string $value Stored reaction value.
	 * @return string|false The slug, or false if this isn't an icon reaction.
	 */
	public static function parse_icon_token( $value ) {
		$prefix = self::ICON_PREFIX . self::ICON_COLLECTION . '/';

		if ( ! is_string( $value ) || 0 !== strpos( $value, $prefix ) ) {
			return false;
		}

		$slug = substr( $value, strlen( $prefix ) );

		return self::sanitize_icon_slug( $slug ) === $slug ? $slug : false;
	}

	/**
	 * Whether a reaction value is one this site could legitimately have stored.
	 *
	 * Intentionally permissive, and intentionally independent of the current
	 * settings: it governs whether a reaction can be *removed*, so it has to
	 * keep saying yes after skin tones are turned off, after the picker is
	 * turned off, and after an icon is retired.
	 *
	 * @param string $value Reaction value.
	 * @return bool
	 */
	public static function is_known_reaction( $value ) {
		$slug = self::parse_icon_token( $value );

		if ( false !== $slug ) {
			$icons = self::get_icons();

			return isset( $icons[ $slug ] );
		}

		return WP_REST_React_Controller::is_known_emoji( $value );
	}

	/**
	 * Whether a reaction value can be *added* right now.
	 *
	 * This is the settings-dependent half, checked only when creating a new
	 * reaction. See the class docblock for why it's separate.
	 *
	 * @param string $value Reaction value.
	 * @return bool
	 */
	public static function is_offerable_reaction( $value ) {
		$slug = self::parse_icon_token( $value );

		if ( false !== $slug ) {
			$icons = self::get_icons( false );

			return isset( $icons[ $slug ] );
		}

		if ( ! WP_REST_React_Controller::is_known_emoji( $value ) ) {
			return false;
		}

		if ( ! self::get( 'react_allow_skin_tones' ) && WP_REST_React_Controller::has_skin_tone( $value ) ) {
			return false;
		}

		if ( ! self::get( 'react_enable_picker' ) ) {
			return in_array( $value, self::get( 'react_default_emoji' ), true );
		}

		return true;
	}

	/**
	 * Every reaction the front end should always show, in order.
	 *
	 * @return array Ordered list of reaction values.
	 */
	public static function get_always_visible_reactions() {
		$reactions = self::get( 'react_default_emoji' );

		if ( ! self::get( 'react_allow_skin_tones' ) ) {
			$reactions = array_values(
				array_filter(
					$reactions,
					array( 'WP_REST_React_Controller', 'has_no_skin_tone' )
				)
			);
		}

		foreach ( array_keys( self::get_icons( false ) ) as $slug ) {
			$reactions[] = self::icon_token( $slug );
		}

		return $reactions;
	}

	/**
	 * Apply icon uploads and retirements submitted from Settings > Discussion.
	 *
	 * Runs on `load-options.php`, before options.php checks its own referer
	 * and saves the Discussion settings, so both halves of the form take
	 * effect from a single "Save Changes" or "Upload icon".
	 *
	 * Custom icons deliberately aren't part of the Discussion option group --
	 * see the note in self::register_settings() -- so they're written here
	 * instead.
	 */
	public static function maybe_handle_icon_changes() {
		if ( ! isset( $_POST['react_icon_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['react_icon_nonce'] ) ), 'react_icon_upload' ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$icons   = self::get_icons();
		$changed = false;

		/*
		 * The form renders a checkbox for every stored icon, so an absent one
		 * means "not retired" rather than "not submitted".
		 */
		$retire = array();
		if ( isset( $_POST['react_icon_retire'] ) && is_array( $_POST['react_icon_retire'] ) ) {
			$retire = array_map( 'sanitize_key', array_keys( wp_unslash( $_POST['react_icon_retire'] ) ) );
		}

		foreach ( $icons as $slug => $icon ) {
			$retired = in_array( $slug, $retire, true );

			if ( ! empty( $icon['retired'] ) !== $retired ) {
				$icons[ $slug ]['retired'] = $retired;
				$changed                   = true;
			}
		}

		if ( isset( $_POST['react_icon_upload'] ) ) {
			$uploaded = self::handle_icon_upload( $icons );

			if ( is_wp_error( $uploaded ) ) {
				add_settings_error( 'react', $uploaded->get_error_code(), $uploaded->get_error_message(), 'error' );
			} else {
				$icons   = $uploaded;
				$changed = true;

				add_settings_error( 'react', 'react_icon_added', __( 'Reaction icon added.', 'react' ), 'success' );
			}
		}

		if ( $changed ) {
			/*
			 * Explicitly not autoloaded: this holds SVG markup, which has no
			 * business being read on every single request.
			 */
			update_option( 'react_custom_icons', $icons, false );
		}
	}

	/**
	 * Validate and store an uploaded SVG icon.
	 *
	 * Only ever called for a submission from the "Upload icon" button, which
	 * carries formenctype="multipart/form-data" -- so a populated $_FILES is
	 * guaranteed rather than hoped for.
	 *
	 * The file itself is never moved into the uploads directory and never
	 * becomes an attachment. Only its sanitized markup is kept, which is why
	 * this doesn't need SVG added to the allowed upload types.
	 *
	 * @param array $icons Currently stored icons.
	 * @return array|WP_Error The updated icon list, or an error.
	 */
	private static function handle_icon_upload( $icons ) {
		if ( count( $icons ) >= self::MAX_ICONS ) {
			return new WP_Error(
				'react_icon_limit',
				sprintf(
					/* translators: %d: Maximum number of icons. */
					__( 'The limit of %d custom icons has been reached.', 'react' ),
					self::MAX_ICONS
				)
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The caller verified react_icon_nonce before reaching this point.
		$file = isset( $_FILES['react_icon_file'] ) ? $_FILES['react_icon_file'] : array();

		if ( ! isset( $file['error'] ) || UPLOAD_ERR_NO_FILE === (int) $file['error'] ) {
			return new WP_Error( 'react_icon_no_file', __( 'Choose an SVG file to upload.', 'react' ) );
		}

		if ( UPLOAD_ERR_OK !== (int) $file['error'] ) {
			return new WP_Error( 'react_icon_upload_error', __( 'That file could not be uploaded. It may be larger than this site allows.', 'react' ) );
		}

		if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'react_icon_not_uploaded', __( 'That upload could not be verified. Please try again.', 'react' ) );
		}

		if ( isset( $file['size'] ) && (int) $file['size'] > self::MAX_SVG_BYTES ) {
			return new WP_Error(
				'react_icon_too_large',
				sprintf(
					/* translators: %s: A file size, e.g. "16 KB". */
					__( 'That icon is too large. Custom reaction icons have to be under %s.', 'react' ),
					size_format( self::MAX_SVG_BYTES )
				)
			);
		}

		$markup = file_get_contents( $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local upload, not a remote request.

		if ( false === $markup ) {
			return new WP_Error( 'react_icon_unreadable', __( 'That file could not be read.', 'react' ) );
		}

		$content = self::preflight_svg( $markup );

		if ( is_wp_error( $content ) ) {
			return $content;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The caller verified react_icon_nonce before reaching this point.
		$label = isset( $_POST['react_icon_label'] ) ? sanitize_text_field( wp_unslash( $_POST['react_icon_label'] ) ) : '';

		if ( '' === $label && ! empty( $file['name'] ) ) {
			$label = sanitize_text_field( pathinfo( $file['name'], PATHINFO_FILENAME ) );
		}

		$slug = self::sanitize_icon_slug( $label );

		if ( '' === $slug ) {
			return new WP_Error( 'react_icon_no_slug', __( 'Give the icon a name made up of letters or numbers.', 'react' ) );
		}

		if ( isset( $icons[ $slug ] ) ) {
			return new WP_Error(
				'react_icon_duplicate',
				sprintf(
					/* translators: %s: An icon name. */
					__( 'There is already an icon named %s. Pick a different name.', 'react' ),
					'<code>' . esc_html( $slug ) . '</code>'
				)
			);
		}

		$icons[ $slug ] = array(
			'label'   => '' !== $label ? $label : $slug,
			'content' => $content,
			'retired' => false,
		);

		return $icons;
	}

	/**
	 * Enqueue the assets for the settings screen.
	 *
	 * Scoped to Settings > Discussion. Note that this can't reuse
	 * static/react.js: that script installs a document-level click handler
	 * that posts reactions, which has no business running in wp-admin.
	 *
	 * @param string $hook_suffix The current admin page.
	 */
	public static function enqueue_admin( $hook_suffix ) {
		if ( 'options-discussion.php' !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style( 'react-admin', REACT_URL . '/static/react-admin.css', array(), REACT_VERSION );
		wp_enqueue_script( 'react-admin', REACT_URL . '/static/react-admin.js', array(), REACT_VERSION, true );

		$settings = array(
			'emoji_data_url' => esc_url_raw( REACT_URL . '/static/emoji-data.json' ),
			/* translators: %s: An emoji. */
			'removeLabel'    => __( 'Remove %s from the default reactions', 'react' ),
			'maxDefaults'    => self::MAX_DEFAULT_EMOJI,
		);

		wp_add_inline_script(
			'react-admin',
			'window.wp = window.wp || {}; window.wp.react = window.wp.react || {}; window.wp.react.adminSettings = ' . wp_json_encode( $settings ) . ';',
			'before'
		);
	}

	/**
	 * Register the custom icon collection and icons.
	 *
	 * Feature-detected, since the Icon Registration API is new in WordPress
	 * 7.1 and this plugin supports a good deal further back. Registration is
	 * also guarded against running twice, because both registries are process
	 * singletons that emit a _doing_it_wrong() notice on a duplicate -- which
	 * turns into a hard failure under the PHPUnit suite.
	 *
	 * Retired icons are still registered: their reactions have to keep
	 * rendering so visitors can un-react them.
	 */
	public static function register_icons() {
		if ( ! function_exists( 'wp_register_icon_collection' ) || ! function_exists( 'wp_register_icon' ) ) {
			return;
		}

		$icons = self::get_icons();

		if ( ! $icons ) {
			return;
		}

		if ( ! WP_Icon_Collections_Registry::get_instance()->is_registered( self::ICON_COLLECTION ) ) {
			wp_register_icon_collection(
				self::ICON_COLLECTION,
				array(
					'label'       => __( 'Custom reactions', 'react' ),
					'description' => __( 'Icons uploaded for use as post reactions.', 'react' ),
				)
			);
		}

		foreach ( $icons as $slug => $icon ) {
			$name = self::ICON_COLLECTION . '/' . $slug;

			if ( WP_Icons_Registry::get_instance()->is_registered( $name ) ) {
				continue;
			}

			wp_register_icon(
				$name,
				array(
					'label'   => $icon['label'],
					'content' => $icon['content'],
				)
			);
		}
	}

	/**
	 * Render a custom icon reaction.
	 *
	 * @param string $value Stored reaction value.
	 * @param int    $size  Icon size, in pixels.
	 * @return string Icon markup, or an empty string if it can't be rendered.
	 */
	public static function render_icon( $value, $size = 24 ) {
		$slug = self::parse_icon_token( $value );

		if ( false === $slug || ! function_exists( 'wp_get_icon' ) ) {
			return '';
		}

		$icons = self::get_icons();

		if ( ! isset( $icons[ $slug ] ) ) {
			return '';
		}

		return wp_get_icon(
			self::ICON_COLLECTION . '/' . $slug,
			array(
				'size'  => $size,
				'label' => $icons[ $slug ]['label'],
			)
		);
	}
}
