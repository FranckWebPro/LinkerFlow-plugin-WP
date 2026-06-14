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
						'validate_callback' => array( $this, 'validate_public_post_type' ),
					),
					'lang'      => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			LinkerFlow::NAMESPACE,
			'/posts/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_post_content' ),
				'permission_callback' => array( 'LinkerFlow_Auth', 'permission_callback' ),
				'args'                => array(
					'id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
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
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'wp_kses_post',
					),
				),
			)
		);
	}

	// Returns one published post's current content so LinkerFlow applies a link on top of the
	// live HTML rather than a possibly-stale crawl snapshot. Same guards as update_post.
	public function get_post_content( WP_REST_Request $request ) {
		$id   = (int) $request->get_param( 'id' );
		$post = get_post( $id );

		if ( ! $post || 'publish' !== $post->post_status || $post->post_password || ! $this->is_supported_post_type( $post->post_type ) ) {
			return new WP_Error(
				'linkerflow_not_found',
				__( 'Post not found.', 'linkerflow' ),
				array( 'status' => 404 )
			);
		}

		$reason = $this->page_builder_reason( $post );
		if ( $reason ) {
			return new WP_Error(
				'linkerflow_read_only',
				$reason,
				array( 'status' => 409 )
			);
		}

		return rest_ensure_response(
			array(
				'id'           => $post->ID,
				'permalink'    => get_permalink( $post->ID ),
				'post_content' => $post->post_content,
				'status'       => $post->post_status,
				'locale'       => $this->get_locale( $post->ID ),
			)
		);
	}

	public function list_posts( WP_REST_Request $request ) {
		$post_type     = sanitize_key( $request->get_param( 'post_type' ) );
		$page          = max( 1, (int) $request->get_param( 'page' ) );
		$per_page      = max( 1, min( 100, (int) $request->get_param( 'per_page' ) ) );
		$modified_after = sanitize_text_field( (string) $request->get_param( 'modified_after' ) );
		$meta_source   = sanitize_key( $request->get_param( 'meta_source' ) );

		$args = array(
			'post_type'      => $post_type,
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'no_found_rows'  => false,
			'has_password'   => false,
		);

		if ( $modified_after ) {
			$modified_timestamp = rest_parse_date( $modified_after );
			$args['date_query'] = array(
				array(
					'column' => 'post_modified',
					'after'  => gmdate( 'Y-m-d H:i:s', $modified_timestamp ),
				),
			);
		}

		$query = $this->run_language_query( $args, 'all' );

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
				'meta_description' => $this->resolve_meta_description( $post, $meta_source ),
			);
		}

		$response = new WP_REST_Response( $items, 200 );
		$response->header( 'X-WP-Total', $total );
		$response->header( 'X-WP-TotalPages', $total_pages );

		return $response;
	}

	public function count_posts( WP_REST_Request $request ) {
		$post_type  = sanitize_key( $request->get_param( 'post_type' ) );
		$lang       = sanitize_text_field( (string) $request->get_param( 'lang' ) );
		$post_types = $post_type ? array( $post_type ) : $this->get_supported_post_types();
		$published  = 0;

		foreach ( $post_types as $type ) {
			$published += $this->count_public_posts( $type, $lang );
		}

		return rest_ensure_response( array( 'published' => $published ) );
	}

	public function update_post( WP_REST_Request $request ) {
		$id   = (int) $request->get_param( 'id' );
		$post = get_post( $id );

		if ( ! $post || 'publish' !== $post->post_status || $post->post_password || ! $this->is_supported_post_type( $post->post_type ) ) {
			return new WP_Error(
				'linkerflow_not_found',
				__( 'Post not found.', 'linkerflow' ),
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
				'post_content' => $request->get_param( 'post_content' ),
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
				return __( 'Content managed by Elementor.', 'linkerflow' );
			}
		}

		foreach ( self::PAGE_BUILDER_CONTENT_MARKERS as $marker ) {
			if ( false !== strpos( $post->post_content, $marker ) ) {
				return __( 'Content managed by an unsupported page builder.', 'linkerflow' );
			}
		}

		return null;
	}

	private function is_polylang_active() {
		return function_exists( 'pll_languages_list' );
	}

	private function is_wpml_active() {
		return defined( 'ICL_SITEPRESS_VERSION' );
	}

	// Runs a WP_Query honouring a language selector ('' = plugin default, 'all' = every
	// language, a slug = that language) so secondary translations are indexed, not just the default.
	private function run_language_query( array $args, string $lang = '' ) {
		if ( '' === $lang ) {
			return new WP_Query( $args );
		}

		if ( $this->is_polylang_active() ) {
			if ( 'all' === $lang ) {
				$codes        = pll_languages_list( array( 'fields' => 'slug' ) );
				$args['lang'] = ( is_array( $codes ) && $codes ) ? implode( ',', $codes ) : '';
			} else {
				$args['lang'] = $lang;
			}
			return new WP_Query( $args );
		}

		if ( $this->is_wpml_active() ) {
			do_action( 'wpml_switch_language', $lang );
			try {
				return new WP_Query( $args );
			} finally {
				do_action( 'wpml_switch_language', null );
			}
		}

		return new WP_Query( $args );
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

	public function validate_public_post_type( $value, $request = null, $param = '' ) {
		if ( null === $value || '' === $value ) {
			return true;
		}

		return $this->is_supported_post_type( sanitize_key( $value ) );
	}

	public function validate_modified_after( $value, $request = null, $param = '' ) {
		if ( null === $value || '' === $value ) {
			return true;
		}

		return null !== rest_parse_date( sanitize_text_field( (string) $value ) );
	}

	public function validate_meta_source( $value, $request = null, $param = '' ) {
		if ( null === $value || '' === $value ) {
			return true;
		}

		return in_array( sanitize_key( $value ), array( 'yoast', 'rankmath', 'excerpt' ), true );
	}

	// Resolves a post's meta description from the source LinkerFlow selected at onboarding
	// (Yoast or Rank Math post meta, or the WordPress excerpt). SEO plugins can store
	// template variables (e.g. %%title%%) in the field; expand them when the plugin helper
	// is available, otherwise return the raw stored value. Falls back to the excerpt when
	// the chosen source is empty for a given post, then to null.
	private function resolve_meta_description( WP_Post $post, $source ) {
		$value = '';

		switch ( $source ) {
			case 'yoast':
				$value = (string) get_post_meta( $post->ID, '_yoast_wpseo_metadesc', true );
				if ( '' !== $value && function_exists( 'wpseo_replace_vars' ) ) {
					$value = wpseo_replace_vars( $value, $post );
				}
				break;
			case 'rankmath':
				$value = (string) get_post_meta( $post->ID, 'rank_math_description', true );
				if ( '' !== $value && class_exists( '\\RankMath\\Helper' ) ) {
					$value = \RankMath\Helper::replace_vars( $value, $post );
				}
				break;
			case 'excerpt':
				$value = (string) $post->post_excerpt;
				break;
		}

		$value = trim( wp_strip_all_tags( $value ) );

		if ( '' === $value && 'excerpt' !== $source ) {
			$value = trim( wp_strip_all_tags( (string) $post->post_excerpt ) );
		}

		return '' !== $value ? $value : null;
	}

	private function is_supported_post_type( string $post_type ) {
		return in_array( $post_type, $this->get_supported_post_types(), true );
	}

	private function count_public_posts( string $post_type, string $lang = '' ) {
		$query = $this->run_language_query(
			array(
				'post_type'      => $post_type,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => false,
				'has_password'   => false,
			),
			$lang
		);

		return (int) $query->found_posts;
	}

	private function list_args() {
		return array(
			'post_type'      => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_key',
				'validate_callback' => array( $this, 'validate_public_post_type' ),
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
				'required'          => false,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => array( $this, 'validate_modified_after' ),
			),
			'meta_source'    => array(
				'required'          => false,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_key',
				'validate_callback' => array( $this, 'validate_meta_source' ),
			),
		);
	}
}
