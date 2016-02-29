<?php
/*
Plugin Name: React
Description: 💩 Reactions.
Version: 0.1
*/

class React {
	function __construct() {
		$this->enqueue();

		add_filter( 'the_content', array( $this, 'the_content' ) );
	}

	static function init() {
		static $instance;

		if ( ! $instance ) {
			$instance = new React;
		}

		return $instance;
	}

	function enqueue() {
		wp_enqueue_style( 'react-emoji', plugins_url( 'emoji.css', __FILE__ ) );

		wp_enqueue_style( 'react-emoji-picker-emoji-picker', plugins_url( 'emoji-picker/lib/js/emoji-picker.js', __FILE__ ), array( 'jquery' ), false, true );
	}

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

		$content .= '<div class="emoji-reaction-add"><div class="emoji">😃+</div></div>';
		$content .= '</div>';
		return $content;
	}
}

add_action( 'init', array( 'React', 'init' ) );
