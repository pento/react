<?php
/**
 * Test the API endpoints.
 *
 * @package react
 */

/**
 * Class WP_Test_REST_Reactions_Controller
 */
class WP_Test_REST_Reactions_Controller extends WP_Test_REST_Controller_Testcase {
	public function set_up() {
		parent::set_up();
		global $wp_rest_server;
		$this->server = $wp_rest_server;
	}

	public function test_register_routes() {
		$routes = $this->server->get_routes();
		$this->assertArrayHasKey( '/wp/v2/react', $routes );
		$this->assertCount( 2, $routes['/wp/v2/react'] );
	}

	public function test_context_param() {
		$this->markTestSkipped( 'Reactions does not implement schema context.' );
	}

	public function test_get_items() {
		$post_id = $this->factory->post->create();
		$request = new WP_REST_Request( 'GET', '/wp/v2/react' );
		$request->set_param( 'post', array( $post_id ) );
		$response = $this->server->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );
	}

	public function test_get_item() {
		$this->markTestSkipped( 'Get single reaction is not supported.' );
	}

	public function test_create_item() {
		$post_id = $this->factory->post->create();

		// Set current user as administrator to bypass comments moderation/permission checks.
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$request = new WP_REST_Request( 'POST', '/wp/v2/react' );
		$request->set_param( 'post', $post_id );
		$request->set_param( 'emoji', '😀' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertCount( 1, $data );
		$this->assertEquals( '😀', $data[0]['emoji'] );
		$this->assertEquals( 1, $data[0]['count'] );
	}

	public function test_create_item_sets_comment_author_for_logged_in_user() {
		$post_id = $this->factory->post->create();

		$user_id = $this->factory->user->create(
			array(
				'role'         => 'administrator',
				'display_name' => 'Jane Reactor',
				'user_email'   => 'jane@example.com',
			)
		);
		wp_set_current_user( $user_id );

		$request = new WP_REST_Request( 'POST', '/wp/v2/react' );
		$request->set_param( 'post', $post_id );
		$request->set_param( 'emoji', '😀' );
		$this->server->dispatch( $request );

		$comments = get_comments(
			array(
				'post_id' => $post_id,
				'type'    => 'reaction',
			)
		);
		$this->assertCount( 1, $comments );
		$this->assertSame( $user_id, (int) $comments[0]->user_id );
		$this->assertSame( 'Jane Reactor', $comments[0]->comment_author );
		$this->assertSame( 'jane@example.com', $comments[0]->comment_author_email );
	}

	public function test_create_item_toggles_reaction_off() {
		$post_id = $this->factory->post->create();

		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$request = new WP_REST_Request( 'POST', '/wp/v2/react' );
		$request->set_param( 'post', $post_id );
		$request->set_param( 'emoji', '😀' );

		$first_response = $this->server->dispatch( $request );
		$this->assertCount( 1, $first_response->get_data() );

		$second_response = $this->server->dispatch( $request );
		$this->assertCount( 0, $second_response->get_data() );

		$comments = get_comments(
			array(
				'post_id' => $post_id,
				'type'    => 'reaction',
			)
		);
		$this->assertCount( 0, $comments );
	}

	public function test_create_item_toggles_anonymous_reaction_via_client_id() {
		$post_id = $this->factory->post->create();

		$request = new WP_REST_Request( 'POST', '/wp/v2/react' );
		$request->set_param( 'post', $post_id );
		$request->set_param( 'emoji', '😀' );
		$request->set_param( 'client_id', 'client-one' );

		$first_response = $this->server->dispatch( $request );
		$this->assertCount( 1, $first_response->get_data() );

		$second_response = $this->server->dispatch( $request );
		$this->assertCount( 0, $second_response->get_data() );

		// A different anonymous visitor reacting with the same emoji is independent.
		$other_request = new WP_REST_Request( 'POST', '/wp/v2/react' );
		$other_request->set_param( 'post', $post_id );
		$other_request->set_param( 'emoji', '😀' );
		$other_request->set_param( 'client_id', 'client-two' );

		$other_response = $this->server->dispatch( $other_request );
		$this->assertCount( 1, $other_response->get_data() );
		$this->assertEquals( 1, $other_response->get_data()[0]['count'] );
	}

	public function test_create_item_rejects_non_whitelisted_emoji() {
		$post_id = $this->factory->post->create();

		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$request = new WP_REST_Request( 'POST', '/wp/v2/react' );
		$request->set_param( 'post', $post_id );
		$request->set_param( 'emoji', '<script>alert(1)</script>' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 400, $response->get_status() );

		$comments = get_comments(
			array(
				'post_id' => $post_id,
				'type'    => 'reaction',
			)
		);
		$this->assertCount( 0, $comments );
	}

	public function test_update_item() {
		$this->markTestSkipped( 'Update reaction is not supported.' );
	}

	public function test_delete_item() {
		$this->markTestSkipped( 'Delete reaction is not supported.' );
	}

	public function test_prepare_item() {
		$controller = new WP_REST_React_Controller();
		$reaction   = array(
			'emoji'   => '😀',
			'count'   => 5,
			'post_id' => 123,
		);
		$request    = new WP_REST_Request( 'GET', '/wp/v2/react' );
		$response   = $controller->prepare_item_for_response( $reaction, $request );
		$data       = $response->get_data();

		$this->assertEquals( '😀', $data['emoji'] );
		$this->assertEquals( 5, $data['count'] );
		$this->assertEquals( 123, $data['post_id'] );
	}

	public function test_get_item_schema() {
		$controller = new WP_REST_React_Controller();
		$schema     = $controller->get_item_schema();
		$this->assertIsArray( $schema );
	}

	public function test_create_item_rejects_logged_out_when_login_is_required() {
		update_option( 'react_require_login', '1' );

		$post_id = $this->factory->post->create();
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'POST', '/wp/v2/react' );
		$request->set_param( 'post', $post_id );
		$request->set_param( 'emoji', '😀' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 401, $response->get_status() );
		$this->assertEquals( 'rest_reaction_login_required', $response->get_data()['code'] );

		$comments = get_comments(
			array(
				'post_id' => $post_id,
				'type'    => 'reaction',
			)
		);
		$this->assertCount( 0, $comments );
	}

	public function test_create_item_allows_logged_in_when_login_is_required() {
		update_option( 'react_require_login', '1' );

		$post_id = $this->factory->post->create();
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );

		$request = new WP_REST_Request( 'POST', '/wp/v2/react' );
		$request->set_param( 'post', $post_id );
		$request->set_param( 'emoji', '😀' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
	}

	public function test_create_item_rejects_an_emoji_outside_the_default_set_when_the_picker_is_off() {
		update_option( 'react_enable_picker', '0' );
		update_option( 'react_default_emoji', array( '😀' ) );

		$post_id = $this->factory->post->create();
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );

		$request = new WP_REST_Request( 'POST', '/wp/v2/react' );
		$request->set_param( 'post', $post_id );
		$request->set_param( 'emoji', '🎉' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 403, $response->get_status() );
		$this->assertEquals( 'rest_reaction_not_offered', $response->get_data()['code'] );
	}

	public function test_create_item_allows_the_default_set_when_the_picker_is_off() {
		update_option( 'react_enable_picker', '0' );
		update_option( 'react_default_emoji', array( '😀' ) );

		$post_id = $this->factory->post->create();
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );

		$request = new WP_REST_Request( 'POST', '/wp/v2/react' );
		$request->set_param( 'post', $post_id );
		$request->set_param( 'emoji', '😀' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
	}

	public function test_create_item_rejects_a_skin_tone_when_they_are_disallowed() {
		update_option( 'react_allow_skin_tones', '0' );

		$post_id = $this->factory->post->create();
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );

		$request = new WP_REST_Request( 'POST', '/wp/v2/react' );
		$request->set_param( 'post', $post_id );
		$request->set_param( 'emoji', '👋🏽' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 403, $response->get_status() );
		$this->assertEquals( 'rest_reaction_not_offered', $response->get_data()['code'] );
	}

	/**
	 * Turning a setting off must never strand a reaction somebody already
	 * left. Removal goes through the same route as creation, so if the
	 * settings check were applied to both, this reaction could be seen but
	 * never un-reacted.
	 */
	public function test_an_existing_skin_toned_reaction_can_still_be_removed_after_tones_are_disallowed() {
		$post_id = $this->factory->post->create();
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$request = new WP_REST_Request( 'POST', '/wp/v2/react' );
		$request->set_param( 'post', $post_id );
		$request->set_param( 'emoji', '👋🏽' );
		$this->assertEquals( 200, $this->server->dispatch( $request )->get_status() );

		update_option( 'react_allow_skin_tones', '0' );

		$request = new WP_REST_Request( 'POST', '/wp/v2/react' );
		$request->set_param( 'post', $post_id );
		$request->set_param( 'emoji', '👋🏽' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$comments = get_comments(
			array(
				'post_id' => $post_id,
				'type'    => 'reaction',
			)
		);
		$this->assertCount( 0, $comments );
	}

	/**
	 * The same guarantee, for a reaction using an icon that has since been
	 * retired.
	 */
	public function test_a_retired_icon_reaction_can_still_be_removed() {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M1 21h4V9H1v12z"/></svg>';

		$icon = array(
			'label'   => 'Removable',
			'content' => React_Settings::sanitize_svg( $svg ),
			'retired' => false,
		);

		update_option( 'react_custom_icons', array( 'removable-icon' => $icon ), false );
		React_Settings::register_icons();

		$token   = React_Settings::icon_token( 'removable-icon' );
		$post_id = $this->factory->post->create();
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );

		$request = new WP_REST_Request( 'POST', '/wp/v2/react' );
		$request->set_param( 'post', $post_id );
		$request->set_param( 'emoji', $token );
		$this->assertEquals( 200, $this->server->dispatch( $request )->get_status() );

		$icon['retired'] = true;
		update_option( 'react_custom_icons', array( 'removable-icon' => $icon ), false );

		$request = new WP_REST_Request( 'POST', '/wp/v2/react' );
		$request->set_param( 'post', $post_id );
		$request->set_param( 'emoji', $token );

		$this->assertEquals( 200, $this->server->dispatch( $request )->get_status() );

		$comments = get_comments(
			array(
				'post_id' => $post_id,
				'type'    => 'reaction',
			)
		);
		$this->assertCount( 0, $comments );
	}

	public function test_create_item_rejects_an_unregistered_icon_token() {
		$post_id = $this->factory->post->create();
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );

		$request = new WP_REST_Request( 'POST', '/wp/v2/react' );
		$request->set_param( 'post', $post_id );
		$request->set_param( 'emoji', 'icon:react-custom/nope' );
		$response = $this->server->dispatch( $request );

		// The REST framework wraps a validate_callback error, so the
		// specific code is nested rather than top-level.
		$this->assertEquals( 400, $response->get_status() );
		$this->assertEquals( 'rest_invalid_param', $response->get_data()['code'] );

		$comments = get_comments(
			array(
				'post_id' => $post_id,
				'type'    => 'reaction',
			)
		);
		$this->assertCount( 0, $comments );
	}
}
