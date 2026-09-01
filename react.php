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

	add_action( 'init', array( 'React', 'init' ) );
}

add_action( 'plugins_loaded', 'react_load' );
