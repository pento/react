<?php

$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
	$_tests_dir = '/tmp/wordpress-tests-lib';
}

require_once( $_tests_dir . '/includes/functions.php' );

/**
 * Load the plugin.
 */
function _manually_load_react_plugin() {
	require_once( dirname( __FILE__ ) . '/../vendor/json-rest-api/plugin.php' );
	require_once( dirname( __FILE__ ) . '/../react.php' );
}

tests_add_filter( 'muplugins_loaded', '_manually_load_react_plugin' );

require $_tests_dir . '/includes/bootstrap.php';

// Helper classes
if ( ! class_exists( 'WP_Test_REST_TestCase' ) ) {
	require_once( dirname( __FILE__ ) . '/class-wp-test-rest-testcase.php' );
}

if ( ! class_exists( 'WP_Test_REST_Controller_Testcase' ) ) {
	require_once( dirname( __FILE__ ) . '/class-wp-test-rest-controller-testcase.php' );
}

if ( ! class_exists( 'WP_Test_Spy_REST_Server' ) ) {
	require_once( dirname( __FILE__ ) . '/class-wp-test-spy-rest-server.php' );
}
