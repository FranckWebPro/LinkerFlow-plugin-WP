<?php
defined( 'ABSPATH' ) || exit;

class LinkerFlow_REST_Post_Types {

	public function register() {
		register_rest_route(
			LinkerFlow::NAMESPACE,
			'/post-types',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => array( 'LinkerFlow_Auth', 'permission_callback' ),
			)
		);
	}

	public function handle( WP_REST_Request $request ) {
		$post_types = get_post_types( array( 'public' => true ), 'objects' );

		$result = array();
		foreach ( $post_types as $type ) {
			if ( 'attachment' === $type->name ) {
				continue;
			}

			$result[] = array(
				'slug'  => $type->name,
				'label' => $type->label,
			);
		}

		return rest_ensure_response( array( 'post_types' => $result ) );
	}
}
