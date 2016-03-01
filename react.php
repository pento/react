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
	function __construct() {
		$this->enqueue();

		add_filter( 'the_content', array( $this, 'the_content' ) );
	}

	/**
	 * Initialises the reactions.
	 *
	 * @return React Static instance of the React class.
	 */
	static function init() {
		static $instance;

		if ( ! $instance ) {
			$instance = new React;
		}

		return $instance;
	}

	/**
	 * Enqueue relevant JS and CSS
	 */
	function enqueue() {
		wp_enqueue_style( 'react-emoji', plugins_url( 'emoji.css', __FILE__ ) );

		wp_enqueue_script( 'react-emoji', plugins_url( 'emoji.js', __FILE__ ), array(), false, true );
	}

	/**
	 * Add the reaction buttons to the post content.
	 * @param  string $content The content HTML
	 * @return string The content HTML, with the react buttons attached
	 */
	function the_content( $content ) {
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
		$content .= '<div data-post="$post_id" class="emoji-reaction-add"><div class="emoji">' . __( '😃+', 'react' ) . '</div></div>';
		$content .= '</div>';
		return $content;
	}
}

add_action( 'init', array( 'React', 'init' ) );
