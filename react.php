<?php
/*
Plugin Name: React
Description: 💩 Reactions.
Version: 0.1
*/

class React {
	/**
	 * React constructor.
	 */
	public function __construct() {
		$this->enqueue();

		add_action( 'wp_head',     array( $this, 'print_settings' ) );
		add_action( 'wp_footer',   array( $this, 'print_selector' ) );

 		add_filter( 'the_content', array( $this, 'the_content'    ) );
	}

	/**
	 * Initialises the reactions.
	 *
	 * @return React Static instance of the React class.
	 */
	public static function init() {
		static $instance;

		if ( ! $instance ) {
			$instance = new React;
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
					emoji_url: '<?php echo plugins_url( 'emoji.json', __FILE__ ) ?>'
				}
			</script>
		<?php
	}

	/**
	 * Enqueue relevant JS and CSS
	 */
	public function enqueue() {
		wp_enqueue_style( 'react-emoji', plugins_url( 'react.css', __FILE__ ) );

		wp_enqueue_script( 'react-emoji', plugins_url( 'react.js', __FILE__ ), array(), false, true );
	}

	/**
	 * Add the reaction buttons to the post content.
	 * @param  string $content The content HTML
	 * @return string The content HTML, with the react buttons attached
	 */
	public function the_content( $content ) {
		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return $content;
		}

		$reactions = get_comments( array(
			'post_id' => $post_id,
			'type'    => 'reaction',
		) );

		$reactions_summary = array();
		foreach( $reactions as $reaction ) {
			if ( ! isset( $reactions_summary[ $reaction->comment_content ] ) ) {
				$reactions_summary[ $reaction->comment_content ] = 0;
			}

			$reactions_summary[ $reaction->comment_content ]++;
		}

		$content .= '<div class="emoji-reactions">';

		foreach ( $reactions_summary as $emoji => $count ) {
			$content .= "<div data-emoji='$emoji' data-count='$count' data-post='$post_id' class='emoji-reaction'><div class='emoji'>$emoji</div><div class='count'>$count</div>";
		}

		/* translators: This is the emoji used for the "Add new emoji reaction" button */
		$content .= "<div data-post='$post_id' class='emoji-reaction-add'><div class='emoji'>" . __( '😃+', 'reactions' ) . '</div></div>';
		$content .= '</div>';
		return $content;
	}

	public function print_selector() {
		?>
			<div id="emoji-reaction-selector" style="display: none;">
				<div class="tabs">
					<div data-tab="0" alt="<?php echo __( 'People',   'reactions' ); ?>" class="emoji-reaction-tab"><?php echo __( '😀', 'reactions' ); ?></div>
					<div data-tab="1" alt="<?php echo __( 'Nature',   'reactions' ); ?>" class="emoji-reaction-tab"><?php echo __( '🌿', 'reactions' ); ?></div>
					<div data-tab="2" alt="<?php echo __( 'Food',     'reactions' ); ?>" class="emoji-reaction-tab"><?php echo __( '🍔', 'reactions' ); ?></div>
					<div data-tab="3" alt="<?php echo __( 'Activity', 'reactions' ); ?>" class="emoji-reaction-tab"><?php echo __( '⚽️', 'reactions' ); ?></div>
					<div data-tab="4" alt="<?php echo __( 'Places',   'reactions' ); ?>" class="emoji-reaction-tab"><?php echo __( '✈️', 'reactions' ); ?></div>
					<div data-tab="5" alt="<?php echo __( 'Objects',  'reactions' ); ?>" class="emoji-reaction-tab"><?php echo __( '💡', 'reactions' ); ?></div>
					<div data-tab="6" alt="<?php echo __( 'Symbols',  'reactions' ); ?>" class="emoji-reaction-tab"><?php echo __( '❤', 'reactions' ); ?></div>
					<div data-tab="7" alt="<?php echo __( 'Flags',    'reactions' ); ?>" class="emoji-reaction-tab"><?php echo __( '🇺🇸', 'reactions' ); ?></div>
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

add_action( 'init', array( 'React', 'init' ) );
