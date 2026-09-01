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
}
