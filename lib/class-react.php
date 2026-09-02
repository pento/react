<?php
/**
 * Front-end integration for the Reactions plugin.
 *
 * @package react
 */

/**
 * Class React
 */
class React {

	/**
	 * The comment_type reactions are stored under.
	 *
	 * @var string
	 */
	const COMMENT_TYPE = 'reaction';

	/**
	 * API endpoints
	 *
	 * @var WP_REST_React_Controller
	 */
	public $api;

	/**
	 * React constructor.
	 */
	public function __construct() {
		$this->api = new WP_REST_React_Controller();

		add_action( 'rest_api_init', array( $this->api, 'register_routes' ) );
		add_action( 'wp_insert_comment', array( $this, 'invalidate_reaction_count_cache' ), 10, 2 );
		add_action( 'delete_comment', array( $this, 'invalidate_reaction_count_cache_on_delete' ), 10, 1 );
		add_action( 'transition_comment_status', array( $this, 'invalidate_reaction_count_cache_on_status_change' ), 10, 3 );

		if ( is_admin() ) {
			return;
		}

		$this->enqueue();

		add_filter( 'the_content', array( $this, 'the_content' ) );

		add_filter( 'comments_clauses', array( $this, 'exclude_reactions_from_comments_clauses' ), 10, 2 );
		add_filter( 'get_comments_number', array( $this, 'exclude_reactions_from_comments_number' ), 10, 2 );
	}

	/**
	 * Initialises the reactions.
	 *
	 * @return React Static instance of the React class.
	 */
	public static function init() {
		static $instance;

		if ( ! $instance ) {
			$instance = new React();
		}

		return $instance;
	}

	/**
	 * Enqueue relevant JS and CSS
	 */
	public function enqueue() {
		wp_enqueue_style( 'react-emoji', REACT_URL . '/static/react.css', array(), REACT_VERSION );

		wp_enqueue_script( 'react-emoji', REACT_URL . '/static/react.js', array(), REACT_VERSION, true );

		/*
		 * Only site-wide configuration belongs here. enqueue() is called from
		 * the constructor on `init`, so there is no queried post yet -- and
		 * anything per-post has to be rendered as a data attribute by
		 * self::the_content() instead.
		 */
		$icons = array();
		foreach ( React_Settings::get_icons() as $slug => $icon ) {
			$icons[ React_Settings::icon_token( $slug ) ] = array(
				'label' => $icon['label'],
				'svg'   => $icon['content'],
			);
		}

		$settings = array(
			'emoji_data_url'   => esc_url_raw( REACT_URL . '/static/emoji-data.json' ),
			'endpoint'         => esc_url_raw( get_rest_url( null, $this->api->namespace . '/' . $this->api->rest_base ) ),
			'nonce'            => wp_create_nonce( 'wp_rest' ),
			'enable_picker'    => React_Settings::get( 'react_enable_picker' ),
			'allow_skin_tones' => React_Settings::get( 'react_allow_skin_tones' ),
			'always_visible'   => React_Settings::get_always_visible_reactions(),
			'icons'            => $icons,
		);

		wp_add_inline_script(
			'react-emoji',
			'window.wp = window.wp || {}; window.wp.react = window.wp.react || {}; window.wp.react.settings = ' . wp_json_encode( $settings ) . ';',
			'before'
		);
	}

	/**
	 * Add the reaction buttons to the post content.
	 *
	 * @param  string $content The content HTML.
	 * @return string The content HTML, with the react buttons attached.
	 */
	public function the_content( $content ) {
		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return $content;
		}

		$reactions = get_comments(
			array(
				'post_id' => $post_id,
				'type'    => self::COMMENT_TYPE,
			)
		);

		$reactions_summary = array();
		foreach ( $reactions as $reaction ) {
			if ( ! isset( $reactions_summary[ $reaction->comment_content ] ) ) {
				$reactions_summary[ $reaction->comment_content ] = 0;
			}

			++$reactions_summary[ $reaction->comment_content ];
		}

		/*
		 * Seed the configured defaults at zero so they're always offered, then
		 * let the real tallies overwrite them. array_merge() rather than "+"
		 * because the tallied count has to win, and because this ordering is
		 * the point: defaults keep their configured order, and anything else
		 * people have reacted with follows on behind.
		 */
		$always_visible    = array_fill_keys( React_Settings::get_always_visible_reactions(), 0 );
		$reactions_summary = array_merge( $always_visible, $reactions_summary );

		/*
		 * Logged-out visitors on a login-gated site still see the counts, but
		 * get a link to log in rather than a button that would be refused.
		 */
		$gated     = React_Settings::get( 'react_require_login' ) && ! is_user_logged_in();
		$can_react = comments_open( $post_id ) && ! $gated;
		$login_url = $gated ? wp_login_url( get_permalink( $post_id ) ) : '';

		$markup = '<div class="emoji-reactions">';

		foreach ( $reactions_summary as $emoji => $count ) {
			$icon = React_Settings::render_icon( $emoji );

			if ( '' === $icon && false !== React_Settings::parse_icon_token( $emoji ) ) {
				/*
				 * An icon reaction whose icon is no longer registered. Skip it
				 * rather than rendering an empty bubble with a live count --
				 * the comment rows survive, so restoring the icon brings them
				 * back.
				 */
				continue;
			}

			$classes = 'emoji-reaction';
			if ( 0 === $count ) {
				$classes .= ' is-zero';
			}
			if ( '' !== $icon ) {
				$classes .= ' emoji-reaction-icon';
			}

			$glyph = '' !== $icon ? $icon : esc_html( $emoji );

			if ( $gated ) {
				$markup .= sprintf(
					"<a href='%s' data-emoji='%s' data-count='%d' data-post='%d' class='%s emoji-reaction-login'><div class='emoji'>%s</div><div class='count'>%d</div></a>",
					esc_url( $login_url ),
					esc_attr( $emoji ),
					$count,
					$post_id,
					esc_attr( $classes ),
					$glyph, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Either esc_html()'d above, or icon markup from wp_get_icon().
					$count
				);

				continue;
			}

			$markup .= sprintf(
				"<div data-emoji='%s' data-count='%d' data-post='%d' class='%s'><div class='emoji'>%s</div><div class='count'>%d</div></div>",
				esc_attr( $emoji ),
				$count,
				$post_id,
				esc_attr( $classes ),
				$glyph, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Either esc_html()'d above, or icon markup from wp_get_icon().
				$count
			);
		}

		if ( $gated && comments_open( $post_id ) ) {
			$markup .= sprintf(
				"<a href='%s' data-post='%d' class='emoji-reaction-add emoji-reaction-login'><div class='emoji'>%s</div></a>",
				esc_url( $login_url ),
				$post_id,
				esc_html__( 'Log in to react', 'react' )
			);
		} elseif ( $can_react && React_Settings::get( 'react_enable_picker' ) ) {
			/* translators: This is the emoji used for the "Add new emoji reaction" button */
			$markup .= "<div data-post='$post_id' class='emoji-reaction-add'><div class='emoji'>" . __( '😃+', 'react' ) . '</div></div>';
		}
		$markup .= '</div>';

		/**
		 * Filters the reactions widget markup appended to the post content.
		 *
		 * Lets a theme restyle or replace the widget's markup without
		 * having to override the whole the_content() method. Whatever this
		 * returns is expected to still be valid, escaped HTML -- this
		 * filter runs after all user-controlled values (the emoji, the
		 * post ID) have already been escaped into $markup.
		 *
		 * @param string $markup  The reactions widget HTML.
		 * @param int    $post_id The post ID.
		 */
		$content .= apply_filters( 'react_reaction_markup', $markup, $post_id );

		return $content;
	}

	/**
	 * Exclude reactions from any comment query that isn't explicitly asking for them.
	 *
	 * Reactions are already displayed via the_content(); showing them again
	 * in the regular comment list, a comment feed, or something like the
	 * Recent Comments widget is redundant. Filtering at the SQL clause level
	 * (rather than e.g. comments_template_query_args, which only covers the
	 * theme's main comment-list query) means every one of those surfaces is
	 * covered, without needing to special-case each of them individually.
	 * A query that explicitly asks for the reaction type -- including this
	 * plugin's own queries -- is left untouched.
	 *
	 * @param array            $clauses SQL clauses for the comment query.
	 * @param WP_Comment_Query $query   The query object.
	 * @return array Filtered clauses.
	 */
	public function exclude_reactions_from_comments_clauses( $clauses, $query ) {
		$requested_types = array_merge(
			isset( $query->query_vars['type'] ) ? (array) $query->query_vars['type'] : array(),
			isset( $query->query_vars['type__in'] ) ? (array) $query->query_vars['type__in'] : array()
		);

		if ( in_array( self::COMMENT_TYPE, $requested_types, true ) ) {
			return $clauses;
		}

		global $wpdb;
		$clauses['where'] .= $wpdb->prepare( " AND {$wpdb->comments}.comment_type != %s", self::COMMENT_TYPE );

		return $clauses;
	}

	/**
	 * Exclude reactions from the post's displayed comment count.
	 *
	 * @param int $count   The number of comments the post has.
	 * @param int $post_id The post ID.
	 * @return int Filtered comment count.
	 */
	public function exclude_reactions_from_comments_number( $count, $post_id ) {
		if ( empty( $post_id ) ) {
			return $count;
		}

		return max( 0, (int) $count - $this->get_reaction_count( $post_id ) );
	}

	/**
	 * Get the number of reactions a post has, from cache where possible.
	 *
	 * This runs on every call to get_comments_number(), which can mean once
	 * per post on an archive page -- caching this avoids a COUNT query per
	 * post per page load.
	 * The cache is invalidated in invalidate_reaction_count_cache(),
	 * invalidate_reaction_count_cache_on_delete(), and
	 * invalidate_reaction_count_cache_on_status_change() whenever a reaction
	 * comment is inserted, deleted, or changes status (e.g. trashed,
	 * spammed, or un/approved), and otherwise expires after an hour as a
	 * safety net against any invalidation path this doesn't cover.
	 *
	 * @param int $post_id The post ID.
	 * @return int Number of reactions the post has.
	 */
	public function get_reaction_count( $post_id ) {
		$post_id     = (int) $post_id;
		$cache_group = $this->get_reaction_count_cache_group();
		$cached      = wp_cache_get( $post_id, $cache_group );

		if ( false !== $cached ) {
			return (int) $cached;
		}

		$count = (int) get_comments(
			array(
				'post_id' => $post_id,
				'type'    => self::COMMENT_TYPE,
				'count'   => true,
			)
		);

		/**
		 * Filters the TTL, in seconds, of the cached per-post reaction count.
		 *
		 * @param int $ttl Cache TTL in seconds. Default HOUR_IN_SECONDS.
		 */
		$ttl = apply_filters( 'react_reaction_count_cache_ttl', HOUR_IN_SECONDS );

		wp_cache_set( $post_id, $count, $cache_group, $ttl );

		return $count;
	}

	/**
	 * Get the cache group the per-post reaction count is stored under.
	 *
	 * Used consistently by get_reaction_count() (get + set) and
	 * invalidate_reaction_count_cache() (delete), so all three stay in
	 * sync if this is filtered.
	 *
	 * @return string The cache group name.
	 */
	private function get_reaction_count_cache_group() {
		/**
		 * Filters the cache group the per-post reaction count is stored under.
		 *
		 * @param string $cache_group Cache group name. Default 'react_reaction_counts'.
		 */
		return apply_filters( 'react_reaction_count_cache_group', 'react_reaction_counts' );
	}

	/**
	 * Invalidate the cached reaction count for a post when a reaction is added.
	 *
	 * @param int        $id      The comment ID.
	 * @param WP_Comment $comment The comment object.
	 */
	public function invalidate_reaction_count_cache( $id, $comment ) {
		if ( self::COMMENT_TYPE !== $comment->comment_type ) {
			return;
		}

		$post_id = (int) $comment->comment_post_ID;

		wp_cache_delete( $post_id, $this->get_reaction_count_cache_group() );

		/**
		 * Fires after a post's cached reaction count has been invalidated.
		 *
		 * Fires once per invalidation trigger: a reaction comment being
		 * inserted, deleted, or changing status (e.g. trashed, spammed, or
		 * un/approved) -- see invalidate_reaction_count_cache_on_delete()
		 * and invalidate_reaction_count_cache_on_status_change(), which both
		 * call this method.
		 *
		 * @param int $post_id The post whose cached count was invalidated.
		 */
		do_action( 'react_reaction_count_invalidated', $post_id );
	}

	/**
	 * Invalidate the cached reaction count for a post when a reaction is
	 * about to be deleted.
	 *
	 * Hooks 'delete_comment' rather than 'deleted_comment': the latter's
	 * $comment parameter was only added in WP 4.9.0, and by the time it
	 * fires the comment row is already gone, so there'd be no way to look
	 * up its type/post on an older install. This plugin supports WP 4.4+,
	 * and 'delete_comment' fires before removal on every supported version,
	 * with only the comment ID guaranteed -- so look the comment up from
	 * that while it still exists.
	 *
	 * @param int $comment_id The comment ID about to be deleted.
	 */
	public function invalidate_reaction_count_cache_on_delete( $comment_id ) {
		$comment = get_comment( $comment_id );

		if ( ! $comment ) {
			return;
		}

		$this->invalidate_reaction_count_cache( $comment_id, $comment );
	}

	/**
	 * Invalidate the cached reaction count for a post when a reaction's
	 * comment status changes -- e.g. approved, unapproved, spammed, or
	 * trashed, including via bulk moderation actions in wp-admin.
	 *
	 * @param string     $new_status The new comment status.
	 * @param string     $old_status The old comment status.
	 * @param WP_Comment $comment    The comment object.
	 */
	public function invalidate_reaction_count_cache_on_status_change( $new_status, $old_status, $comment ) {
		$this->invalidate_reaction_count_cache( $comment->comment_ID, $comment );
	}
}
