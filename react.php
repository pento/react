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
 * Loads the plugin, once WP_REST_Posts_Controller is available.
 */
function react_load() {
	if ( ! class_exists( 'WP_REST_Posts_Controller' ) ) {
		add_action( 'admin_notices', 'react_rest_api_missing_notice' );
		return;
	}

	load_plugin_textdomain( 'react' );

	require_once __DIR__ . '/lib/class-wp-rest-react-controller.php';

	require_once __DIR__ . '/lib/class-react.php';

	add_action( 'init', array( 'React', 'init' ) );
}

add_action( 'plugins_loaded', 'react_load' );

/**
 * Warns the site admin that Reactions can't run without the REST API.
 */
function react_rest_api_missing_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html__( 'The Reactions plugin requires the WordPress REST API, which does not appear to be available. Reactions will not be displayed until it is.', 'react' )
	);
}
