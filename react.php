<?php
/**
 * Plugin Name: React
 * Description: 💩 Reactions.
 * Version: 0.1
 * Text Domain: react
 *
 * @package react
 */

define( 'REACT_URL', plugins_url( '', __FILE__ ) );
define( 'REACT_VERSION', '0.1' );

/**
 * Loads the plugin.
 */
function react_load() {
	load_plugin_textdomain( 'react' );

	require_once __DIR__ . '/lib/class-wp-rest-react-controller.php';

	require_once __DIR__ . '/lib/class-react.php';

	require_once __DIR__ . '/lib/class-react-settings.php';

	/*
	 * Hooked from here rather than from React::__construct(), which itself
	 * runs on `init`: adding an `init` callback from inside one at the same
	 * priority is silently dropped, because WP_Hook iterates a copy of its
	 * callback array. The settings also need to be reachable in wp-admin,
	 * which React::__construct() returns early from.
	 */
	React_Settings::bootstrap();

	add_action( 'init', array( 'React', 'init' ) );
}

add_action( 'plugins_loaded', 'react_load' );
