<?php
defined( 'ABSPATH' ) || exit;

class LinkerFlow_REST_Posts {

	// Page-builder markers detected in post meta or content.
	const PAGE_BUILDER_META = array( '_elementor_data', '_elementor_edit_mode' );
	const PAGE_BUILDER_CONTENT_MARKERS = array( 'et_pb_', '[vc_row', '[fusion_builder' );

	public function register() {
		register_rest_route(
			LinkerFlow::NAMESPACE,
			'/posts',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_posts' ),
				'permission_callback' => array( 'LinkerFlow_Auth', 'permission_callback' ),
				'args'                => $this->list_args(),
			)
		);

		register_rest_route(
			LinkerFlow::NAMESPACE,
			'/count',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'count_posts' ),
				'permission_callback' => array( 'LinkerFlow_Auth', 'permission_callback' ),
				'args'                => array(
					'post_type' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		register_rest_route(
			LinkerFlow::NAMESPACE,
			'/posts/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'update_post' ),
				'permission_callback' => array( 'LinkerFlow_Auth', 'permission_callback' ),
				'args'                => array(
					'id'           => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'post_content' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);
	}

	public function list_posts( WP_REST_Request $request ) {
		$post_type     = sanitize_key( $request->get_param( 'post_type' ) );
		$page          = max( 1, (int) $request->get_param( 'page' ) );
		$per_page      = max( 1, min( 100, (int) $request->get_param( 'per_page' ) ) );
		$modified_after = $request->get_param( 'modified_after' );

		$args = array(
			'post_type'      => $post_type,
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'no_found_rows'  => false,
		);

		if ( $modified_after ) {
			$args['date_query'] = array(
				array(
					'column' => 'post_modified',
					'after'  => $modified_after,
				),
			);
		}

		$query = new WP_Query( $args );

		$total      = (int) $query->found_posts;
		$total_pages = (int) $query->max_num_pages;

		$items = array();
		foreach ( $query->posts as $post ) {
			$reason = $this->page_builder_reason( $post );
			if ( $reason ) {
				// Excluded; skip without breaking ingestion.
				continue;
			}

			$items[] = array(
				'id'           => $post->ID,
				'permalink'    => get_permalink( $post->ID ),
				'title'        => $post->post_title,
				'post_type'    => $post->post_type,
				'status'       => $post->post_status,
				'locale'       => $this->get_locale( $post->ID ),
				'post_content' => $post->post_content,
				'post_modified' => $post->post_modified,
			);
		}

		$response = new WP_REST_Response( $items, 200 );
		$response->header( 'X-WP-Total', $total );
		$response->header( 'X-WP-TotalPages', $total_pages );

		return $response;
	}

	public function count_posts( WP_REST_Request $request ) {
		$post_type  = sanitize_key( $request->get_param( 'post_type' ) );
		$post_types = $post_type ? array( $post_type ) : $this->get_supported_post_types();
		$published  = 0;

		foreach ( $post_types as $type ) {
			$counts = wp_count_posts( $type );
			if ( isset( $counts->publish ) ) {
				$published += (int) $counts->publish;
			}
		}

		return rest_ensure_response( array( 'published' => $published ) );
	}

	public function update_post( WP_REST_Request $request ) {
		$id   = (int) $request->get_param( 'id' );
		$post = get_post( $id );

		if ( ! $post || 'publish' !== $post->post_status ) {
			return new WP_Error(
				'linkerflow_not_found',
				'Post not found.',
				array( 'status' => 404 )
			);
		}

		// Guard: refuse to overwrite page-builder content.
		$reason = $this->page_builder_reason( $post );
		if ( $reason ) {
			return new WP_Error(
				'linkerflow_read_only',
				$reason,
				array( 'status' => 409 )
			);
		}

		$result = wp_update_post(
			array(
				'ID'           => $id,
				// wp_update_post expects raw content; kses applied internally based on user cap.
				'post_content' => wp_kses_post( $request->get_param( 'post_content' ) ),
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			return new WP_Error(
				'linkerflow_update_failed',
				$result->get_error_message(),
				array( 'status' => 500 )
			);
		}

		$revisions   = wp_get_post_revisions( $id );
		$revision    = $revisions ? array_shift( $revisions ) : null;
		$revision_id = $revision ? $revision->ID : null;

		return rest_ensure_response(
			array(
				'id'          => $id,
				'updated'     => true,
				'revision_id' => $revision_id,
			)
		);
	}

	private function page_builder_reason( WP_Post $post ) {
		foreach ( self::PAGE_BUILDER_META as $meta_key ) {
			if ( get_post_meta( $post->ID, $meta_key, true ) ) {
				return 'Content managed by Elementor.';
			}
		}

		foreach ( self::PAGE_BUILDER_CONTENT_MARKERS as $marker ) {
			if ( false !== strpos( $post->post_content, $marker ) ) {
				return 'Content managed by an unsupported page builder.';
			}
		}

		return null;
	}

	// Returns the post's language code (Polylang/WPML slug) or null on a monolingual
	// site. Null, not get_locale(): LinkerFlow has no locale rows for a monolingual site,
	// and a "en_US"-style code would never match, so every post must read as primary.
	private function get_locale( int $post_id ) {
		if ( function_exists( 'pll_get_post_language' ) ) {
			$lang = pll_get_post_language( $post_id, 'slug' );
			return $lang ?: null;
		}

		if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			$lang = apply_filters( 'wpml_post_language_details', null, $post_id );
			if ( $lang && isset( $lang['language_code'] ) ) {
				return $lang['language_code'];
			}
		}

		return null;
	}

	private function get_supported_post_types() {
		$post_types = get_post_types( array( 'public' => true ), 'names' );

		return array_values(
			array_filter(
				$post_types,
				function ( $post_type ) {
					return 'attachment' !== $post_type;
				}
			)
		);
	}

	private function list_args() {
		return array(
			'post_type'      => array(
				'required' => true,
				'type'     => 'string',
			),
			'page'           => array(
				'required' => false,
				'type'     => 'integer',
				'default'  => 1,
				'minimum'  => 1,
			),
			'per_page'       => array(
				'required' => false,
				'type'     => 'integer',
				'default'  => 20,
				'minimum'  => 1,
				'maximum'  => 100,
			),
			'modified_after' => array(
				'required' => false,
				'type'     => 'string',
			),
		);
	}
}
