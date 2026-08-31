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

		if ( is_admin() ) {
			return;
		}

		$this->enqueue();

		add_action( 'wp_head', array( $this, 'print_settings' ) );
		add_action( 'wp_footer', array( $this, 'print_selector' ) );

		add_filter( 'the_content', array( $this, 'the_content' ) );

		add_filter( 'comments_template_query_args', array( $this, 'exclude_reactions_from_comments_template' ) );
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
	 * Print the JavaScript settings.
	 */
	public function print_settings() {
		?>
			<script type="text/javascript">
				window.wp = window.wp || {};
				window.wp.react = window.wp.react || {};
				window.wp.react.settings = {
					emoji_url: <?php echo wp_json_encode( esc_url_raw( REACT_URL . '/static/emoji.json' ) ); ?>,
					endpoint:  <?php echo wp_json_encode( esc_url_raw( get_rest_url( null, $this->api->namespace . '/' . $this->api->rest_base ) ) ); ?>
				}
			</script>
		<?php
	}

	/**
	 * Enqueue relevant JS and CSS
	 */
	public function enqueue() {
		wp_enqueue_style( 'react-emoji', REACT_URL . '/static/react.css', array(), REACT_VERSION );

		wp_enqueue_script( 'react-emoji', REACT_URL . '/static/react.js', array(), REACT_VERSION, true );
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
			$content .= "<div data-emoji='$emoji' data-count='$count' data-post='$post_id' class='emoji-reaction'><div class='emoji'>$emoji</div><div class='count'>$count</div></div>";
		}

		if ( comments_open( $post_id ) ) {
			/* translators: This is the emoji used for the "Add new emoji reaction" button */
			$content .= "<div data-post='$post_id' class='emoji-reaction-add'><div class='emoji'>" . __( '😃+', 'react' ) . '</div></div>';
		}
		$content .= '</div>';
		return $content;
	}

	/**
	 * Exclude reactions from the theme's main comment list query.
	 *
	 * Reactions are already displayed via the_content(); showing them again
	 * in the regular comment list is redundant.
	 *
	 * @param array $comment_args Arguments for the comments_template() query.
	 * @return array Filtered arguments.
	 */
	public function exclude_reactions_from_comments_template( $comment_args ) {
		$comment_args['type__not_in'] = array_merge(
			isset( $comment_args['type__not_in'] ) ? (array) $comment_args['type__not_in'] : array(),
			array( 'reaction' )
		);

		return $comment_args;
	}

	/**
	 * Exclude reactions from the post's displayed comment count.
	 *
	 * @param int $count   The number of comments the post has.
	 * @param int $post_id The post ID.
	 * @return int Filtered comment count.
	 */
	public function exclude_reactions_from_comments_number( $count, $post_id ) {
		$reaction_count = get_comments(
			array(
				'post_id' => $post_id,
				'type'    => 'reaction',
				'count'   => true,
			)
		);

		return max( 0, (int) $count - (int) $reaction_count );
	}

	/**
	 * Print the emoji reaction selector markup.
	 */
	public function print_selector() {
		?>
			<div id="emoji-reaction-selector" style="display: none;">
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
		<?php
	}
}
