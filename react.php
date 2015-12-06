<?php
/*
Plugin Name: React
Description: 💩 Reactions.
Version: 0.1
*/

class React {
	function __construct() {
		wp_enqueue_style( 'react-emoji-picker-nanoscroller', plugins_url( 'emoji-picker/lib/css/nanoscroller.css', __FILE__ ) );
		wp_enqueue_style( 'react-emoji-picker-emoji', plugins_url( 'emoji-picker/lib/css/emoji.css', __FILE__ ) );

		wp_enqueue_style( 'react-emoji-picker-nanoscroller', plugins_url( 'emoji-picker/lib/js/nanoscroller.min.js', __FILE__ ), array( 'jquery' ), false, true );
		wp_enqueue_style( 'react-emoji-picker-tether', plugins_url( 'emoji-picker/lib/js/tether.min.js', __FILE__ ), array( 'jquery' ), false, true );
		wp_enqueue_style( 'react-emoji-picker-config', plugins_url( 'emoji-picker/lib/js/config.js', __FILE__ ), array( 'jquery' ), false, true );
		wp_enqueue_style( 'react-emoji-picker-util', plugins_url( 'emoji-picker/lib/js/util.js', __FILE__ ), array( 'jquery' ), false, true );
		wp_enqueue_style( 'react-emoji-picker-jquery-emojiarea', plugins_url( 'emoji-picker/lib/js/query.emojiarea.js', __FILE__ ), array( 'jquery' ), false, true );
		wp_enqueue_style( 'react-emoji-picker-emoji-picker', plugins_url( 'emoji-picker/lib/js/emoji-picker.js', __FILE__ ), array( 'jquery' ), false, true );

		add_filter( 'the_content', array( $this, 'the_content' ) );
	}

	static function init() {
		static $instance;

		if ( ! $instance ) {
			$instance = new React;
		}

		return $instance;
	}

	function the_content( $content ) {
		return $content;
	}
}

add_action( 'init', array( 'React', 'init' ) );