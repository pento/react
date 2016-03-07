<?php

/**
 * Class WP_REST_React_Controller
 */
class WP_REST_React_Controller extends WP_REST_Controller {
	/**
	 * The namespace of this controller's route.
	 *
	 * @var string
	 */
	public $namespace;

	/**
	 * The base of this controller's route.
	 *
	 * @var string
	 */
	public $rest_base;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->namespace = 'wp/v2';
		$this->rest_base = 'react';
	}

	/**
	 * Register the routes for the objects of the controller.
	 */
	public function register_routes() {
		register_rest_route( $this->namespace, $this->rest_base, array(
			array(
				'methods'             => WP_Rest_Server::READABLE,
				'callback'            => array( $this, 'get_items' ),
				'permission_callback' => array( $this, 'get_items_permissions_check' ),
				'args'                => $this->get_collection_params(),
			),
			array(
				'methods'             => WP_Rest_Server::CREATABLE,
				'callback'            => array( $this, 'create_item' ),
				'permission_callback' => array( $this, 'create_item_permissions_check' ),
				'args'                => $this->get_creation_params(),
			),
			'schema' => array( $this, 'get_public_item_schema' ),
		) );
	}

	/**
	 * Check if a given request has access to read reactions.
	 *
	 * @param  WP_REST_Request $request Full details about the request.
	 * @return WP_Error|boolean
	 */
	public function get_items_permissions_check( $request ) {
		if ( ! empty( $request['post'] ) ) {
			foreach ( (array) $request['post'] as $post_id ) {
				$post = get_post( $post_id );
				if ( ! empty( $post_id ) && $post && ! $this->check_read_post_permission( $post ) ) {
					return new WP_Error( 'rest_cannot_read_post', __( 'Sorry, you cannot read the post for this reaction.', 'react' ), array( 'status' => rest_authorization_required_code() ) );
				} else if ( 0 === $post_id && ! current_user_can( 'moderate_comments' ) ) {
					return new WP_Error( 'rest_cannot_read', __( 'Sorry, you cannot read reactions without a post.', 'react' ), array( 'status' => rest_authorization_required_code() ) );
				}
			}
		}

		return true;
	}

	/**
	 * Get a list of reactions.
	 *
	 * @param  WP_REST_Request $request Full details about the request.
	 * @return WP_Error|WP_REST_Response
	 */
	public function get_items( $request ) {
		$prepared_args = array(
			'post__in' => $request['post'],
			'type'     => 'reaction',
		);

		/**
		 * Filter arguments, before passing to WP_Comment_Query, when querying reactions via the REST API.
		 *
		 * @see https://developer.wordpress.org/reference/classes/wp_comment_query/
		 *
		 * @param array           $prepared_args Array of arguments for WP_Comment_Query.
		 * @param WP_REST_Request $request       The current request.
		 */
		$prepared_args = apply_filters( 'rest_reaction_query', $prepared_args, $request );

		$query = new WP_Comment_Query;
		$query_result = $query->query( $prepared_args );

		$reactions_count = array();
		foreach ( $query_result as $reaction ) {
			if ( empty( $reactions_count[ $reaction->comment_content ] ) ) {
				$reactions_count[ $reaction->comment_content ] = array(
					'count'   => 0,
					'post_id' => $reaction->comment_post_ID,
				);
			}

			$reactions_count[ $reaction->comment_content ]++;
		}

		$reactions = array();
		foreach ( $reactions_count as $emoji => $data ) {
			$reaction = array(
				'emoji'   => $emoji,
				'count'   => $data['count'],
				'post_id' => $data['post_id'],
			);

			$data = $this->prepare_item_for_response( $reaction, $request );
			$reactions[] = $this->prepare_response_for_collection( $data );
		}

		$total_reactions = (int) $query->found_comments;
		$reaction_groups = count( $reactions );

		$response = rest_ensure_response( $reactions );
		$response->header( 'X-WP-Total', $total_reactions );
		$response->header( 'X-WP-TotalGroups', $reaction_groups );

		return $response;
	}

	/**
	 * Check if a given request has access to create a reaction
	 *
	 * @param  WP_REST_Request $request Full details about the request.
	 * @return WP_Error|boolean
	 */
	public function create_item_permissions_check( $request ) {
		if ( ! empty( $request['post'] ) && $post = get_post( (int) $request['post'] ) ) {
			if ( ! $this->check_read_post_permission( $post ) ) {
				return new WP_Error( 'rest_cannot_read_post', __( 'Sorry, you cannot read the post for this reaction.', 'react' ), array( 'status' => rest_authorization_required_code() ) );
			}

			if ( ! comments_open( $post->ID ) ) {
				return new WP_Error( 'rest_reactions_closed', __( 'Sorry, reactions are closed on this post.', 'react' ), array( 'status' => 403 ) );
			}
		}
		return true;
	}

	/**
	 * Create a reaction.
	 *
	 * @param  WP_REST_Request $request Full details about the request.
	 * @return WP_Error|WP_REST_Response
	 */
	public function create_item( $request ) {
		$comment = array(
			'comment_content' => $request['emoji'],
			'comment_post_ID' => $request['post'],
			'comment_type'    => 'reaction',
		);

		wp_insert_comment( $comment );

		return $this->get_items( $request );
	}

	/**
	 * Check if we can read a post.
	 *
	 * Correctly handles posts with the inherit status.
	 *
	 * @param object $post Post object.
	 * @return boolean Can we read it?
	 */
	public function check_read_post_permission( $post ) {
		$posts_controller = new WP_REST_Posts_Controller( $post->post_type );

		return $posts_controller->check_read_permission( $post );
	}

	/**
	 * Prepare a reaction group output for response.
	 *
	 * @param  array            $reaction Reaction data.
	 * @param  WP_REST_Request  $request  Request object.
	 * @return WP_REST_Response $response
	 */
	public function prepare_item_for_response( $reaction, $request ) {
		$data = array(
			'emoji'   => $reaction['emoji'],
			'count'   => (int) $reaction['count'],
			'post_id' => (int) $reaction['post_id'],
		);

		// Wrap the data in a response object
		$response = rest_ensure_response( $data );

		$response->add_links( $this->prepare_links( $reaction ) );

		/**
		 * Filter a reaction group returned from the API.
		 *
		 * Allows modification of the reaction right before it is returned.
		 *
		 * @param WP_REST_Response  $response   The response object.
		 * @param array             $reaction   The original reaction data.
		 * @param WP_REST_Request   $request    Request used to generate the response.
		 */
		return apply_filters( 'rest_prepare_comment', $response, $reaction, $request );
	}

	/**
	 * Prepare a response for inserting into a collection.
	 *
	 * @param WP_REST_Response $response Response object.
	 * @return array Response data, ready for insertion into collection data.
	 */
	public function prepare_response_for_collection( $response ) {
		if ( ! ( $response instanceof WP_REST_Response ) ) {
			return $response;
		}

		$data = (array) $response->get_data();
		$links = WP_REST_Server::get_response_links( $response );
		if ( ! empty( $links ) ) {
			$data['_links'] = $links;
		}

		return $data;
	}

	/**
	 * Prepare links for the request.
	 *
	 * @param array $reaction Reaction.
	 * @return array Links for the given reaction.
	 */
	protected function prepare_links( $reaction ) {
		$links = array(
			'self' => array(
				'href' => rest_url( sprintf( '/%s/%s/%s', $this->namespace, $this->rest_base, $reaction['emoji'] ) ),
			),
			'collection' => array(
				'href' => rest_url( sprintf( '/%s/%s', $this->namespace, $this->rest_base ) ),
			),
		);

		if ( 0 !== (int) $reaction['post_id'] ) {
			$post = get_post( $reaction['post_id'] );
			if ( ! empty( $post->ID ) ) {
				$obj = get_post_type_object( $post->post_type );
				$base = ! empty( $obj->rest_base ) ? $obj->rest_base : $obj->name;
				$links['up'] = array(
					'href'       => rest_url( '/wp/v2/' . $base . '/' . $reaction['post_id'] ),
					'embeddable' => true,
					'post_type'  => $post->post_type,
				);
			}
		}

		return $links;
	}

	/**
	 * Get the query params for collections
	 *
	 * @return array
	 */
	public function get_collection_params() {
		$query_params = array();

		$query_params['post']   = array(
			'default'           => array(),
			'description'       => __( 'Limit result set to resources assigned to specific post ids.', 'react' ),
			'type'              => 'array',
			'sanitize_callback' => 'wp_parse_id_list',
			'validate_callback' => 'rest_validate_request_arg',
		);

		return $query_params;
	}
	/**
	 * Get the query params for collections
	 *
	 * @return array
	 */
	public function get_creation_params() {
		$query_params = array();

		$query_params['post']   = array(
			'default'           => array(),
			'description'       => __( 'The post ID to add a reaction to.', 'react' ),
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'validate_callback' => 'rest_validate_request_arg',
		);

		$query_params['emoji']  = array(
			'default'           => array(),
			'description'       => __( 'The reaction emoji.', 'react' ),
			'type'              => 'string',
			'validate_callback' => 'rest_validate_request_arg',
		);

		return $query_params;
	}
}