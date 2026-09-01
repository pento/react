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

		if ( is_admin() ) {
			return;
		}

		$this->enqueue();

		add_action( 'wp_footer', array( $this, 'print_selector' ) );

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

		$settings = array(
			'emoji_url' => esc_url_raw( REACT_URL . '/static/emoji.json' ),
			'endpoint'  => esc_url_raw( get_rest_url( null, $this->api->namespace . '/' . $this->api->rest_base ) ),
			'nonce'     => wp_create_nonce( 'wp_rest' ),
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
				'type'    => 'reaction',
			)
		);

		$reactions_summary = array();
		foreach ( $reactions as $reaction ) {
			if ( ! isset( $reactions_summary[ $reaction->comment_content ] ) ) {
				$reactions_summary[ $reaction->comment_content ] = 0;
			}

			++$reactions_summary[ $reaction->comment_content ];
		}

		$content .= '<div class="emoji-reactions">';

		foreach ( $reactions_summary as $emoji => $count ) {
			$content .= sprintf(
				"<div data-emoji='%s' data-count='%d' data-post='%d' class='emoji-reaction'><div class='emoji'>%s</div><div class='count'>%d</div></div>",
				esc_attr( $emoji ),
				$count,
				$post_id,
				esc_html( $emoji ),
				$count
			);
		}

		if ( comments_open( $post_id ) ) {
			/* translators: This is the emoji used for the "Add new emoji reaction" button */
			$content .= "<div data-post='$post_id' class='emoji-reaction-add'><div class='emoji'>" . __( '😃+', 'react' ) . '</div></div>';
		}
		$content .= '</div>';
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

		if ( in_array( 'reaction', $requested_types, true ) ) {
			return $clauses;
		}

		global $wpdb;
		$clauses['where'] .= $wpdb->prepare( " AND {$wpdb->comments}.comment_type != %s", 'reaction' );

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
	 * The cache is invalidated in invalidate_reaction_count_cache() whenever
	 * a new reaction comment is inserted.
	 *
	 * @param int $post_id The post ID.
	 * @return int Number of reactions the post has.
	 */
	public function get_reaction_count( $post_id ) {
		$post_id = (int) $post_id;
		$cached  = wp_cache_get( $post_id, 'react_reaction_counts' );

		if ( false !== $cached ) {
			return (int) $cached;
		}

		$count = (int) get_comments(
			array(
				'post_id' => $post_id,
				'type'    => 'reaction',
				'count'   => true,
			)
		);

		wp_cache_set( $post_id, $count, 'react_reaction_counts' );

		return $count;
	}

	/**
	 * Invalidate the cached reaction count for a post when a reaction is added.
	 *
	 * @param int        $id      The comment ID.
	 * @param WP_Comment $comment The comment object.
	 */
	public function invalidate_reaction_count_cache( $id, $comment ) {
		if ( 'reaction' !== $comment->comment_type ) {
			return;
		}

		wp_cache_delete( (int) $comment->comment_post_ID, 'react_reaction_counts' );
	}

	/**
	 * Print the emoji reaction selector markup.
	 *
	 * This is printed inside a `text/html` script template, rather than as
	 * live markup, so that it never exists in the DOM -- and so is never
	 * reachable by a user agent (e.g. a screen reader) -- until react.js
	 * decides it's actually needed and injects it itself.
	 */
	public function print_selector() {
		?>
			<script type="text/html" id="tmpl-emoji-reaction-selector">
				<div id="emoji-reaction-selector">
					<div class="tabs">
						<div data-tab="0" aria-label="<?php echo esc_attr__( 'People', 'react' ); ?>" title="<?php echo esc_attr__( 'People', 'react' ); ?>" class="emoji-reaction-tab"><?php echo esc_html__( '😀', 'react' ); ?></div>
						<div data-tab="1" aria-label="<?php echo esc_attr__( 'Nature', 'react' ); ?>" title="<?php echo esc_attr__( 'Nature', 'react' ); ?>" class="emoji-reaction-tab"><?php echo esc_html__( '🌿', 'react' ); ?></div>
						<div data-tab="2" aria-label="<?php echo esc_attr__( 'Food', 'react' ); ?>" title="<?php echo esc_attr__( 'Food', 'react' ); ?>" class="emoji-reaction-tab"><?php echo esc_html__( '🍔', 'react' ); ?></div>
						<div data-tab="3" aria-label="<?php echo esc_attr__( 'Activity', 'react' ); ?>" title="<?php echo esc_attr__( 'Activity', 'react' ); ?>" class="emoji-reaction-tab"><?php echo esc_html__( '⚽️', 'react' ); ?></div>
						<div data-tab="4" aria-label="<?php echo esc_attr__( 'Places', 'react' ); ?>" title="<?php echo esc_attr__( 'Places', 'react' ); ?>" class="emoji-reaction-tab"><?php echo esc_html__( '✈️', 'react' ); ?></div>
						<div data-tab="5" aria-label="<?php echo esc_attr__( 'Objects', 'react' ); ?>" title="<?php echo esc_attr__( 'Objects', 'react' ); ?>" class="emoji-reaction-tab"><?php echo esc_html__( '💡', 'react' ); ?></div>
						<div data-tab="6" aria-label="<?php echo esc_attr__( 'Symbols', 'react' ); ?>" title="<?php echo esc_attr__( 'Symbols', 'react' ); ?>" class="emoji-reaction-tab"><?php echo esc_html__( '❤', 'react' ); ?></div>
						<div data-tab="7" aria-label="<?php echo esc_attr__( 'Flags', 'react' ); ?>" title="<?php echo esc_attr__( 'Flags', 'react' ); ?>" class="emoji-reaction-tab"><?php echo esc_html__( '🇺🇸', 'react' ); ?></div>
					</div>
					<div class="container container-0"></div>
					<div class="container container-1"></div>
					<div class="container container-2"></div>
					<div class="container container-3"></div>
					<div class="container container-4"></div>
					<div class="container container-5"></div>
					<div class="container container-6"></div>
					<div class="container container-7"></div>
				</div>
			</script>
		<?php
	}
}
