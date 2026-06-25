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
			if ( LinkerFlow::is_excluded_post_type( $type->name ) ) {
				continue;
			}

			$result[] = array(
				'slug'  => $type->name,
				'label' => $type->label,
			);
		}

		return rest_ensure_response(
			array(
				'post_types'   => $result,
				'meta_sources' => $this->meta_sources(),
			)
		);
	}

	// Meta-description sources available site-wide. SEO plugins store the description in
	// post meta per post, so the choice is one per site (which plugin), not per post type.
	// Excerpt is always offered as a built-in fallback.
	private function meta_sources() {
		$sources = array();

		if ( defined( 'WPSEO_VERSION' ) ) {
			$sources[] = array(
				'slug'  => 'yoast',
				'label' => 'Yoast SEO',
			);
		}

		if ( defined( 'RANK_MATH_VERSION' ) ) {
			$sources[] = array(
				'slug'  => 'rankmath',
				'label' => 'Rank Math',
			);
		}

		$sources[] = array(
			'slug'  => 'excerpt',
			'label' => 'Excerpt',
		);

		return $sources;
	}
}
