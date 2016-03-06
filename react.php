<?php
/*
Plugin Name: React
Description: 💩 Reactions.
Version: 0.1
*/

define( 'REACT_URL', plugins_url( '', __FILE__ ) );

require_once( dirname( __FILE__ ) . '/lib/class-wp-rest-react-controller.php' );

require_once( dirname( __FILE__ ) . '/lib/class-react.php' );


add_action( 'init', array( 'React', 'init' ) );
