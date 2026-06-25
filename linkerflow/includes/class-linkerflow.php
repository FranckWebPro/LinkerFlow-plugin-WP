<?php
defined( 'ABSPATH' ) || exit;

class LinkerFlow {

	const NAMESPACE = 'linkerflow/v1';

	// Public post types registered by page builders and WordPress internals that are not
	// editorial content. They satisfy `public => true` so they would otherwise show up as
	// selectable collections and pass crawl validation. Filterable via
	// `linkerflow_excluded_post_types` for site-specific additions.
	const EXCLUDED_POST_TYPES = array(
		'attachment',
		'elementor_library',
		'e-floating-buttons',
		'e-landing-page',
		'et_pb_layout',
		'et_template',
		'et_header_layout',
		'et_footer_layout',
		'et_body_layout',
		'wp_block',
		'wp_template',
		'wp_template_part',
		'wp_navigation',
	);

	public static function excluded_post_types() {
		return apply_filters( 'linkerflow_excluded_post_types', self::EXCLUDED_POST_TYPES );
	}

	public static function is_excluded_post_type( $post_type ) {
		return in_array( $post_type, self::excluded_post_types(), true );
	}

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		LinkerFlow_Admin::init();
	}

	public static function register_routes() {
		( new LinkerFlow_REST_Connect() )->register();
		( new LinkerFlow_REST_Post_Types() )->register();
		( new LinkerFlow_REST_Languages() )->register();
		( new LinkerFlow_REST_Posts() )->register();
	}
}
