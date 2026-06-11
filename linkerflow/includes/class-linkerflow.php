<?php
defined( 'ABSPATH' ) || exit;

class LinkerFlow {

	const NAMESPACE = 'linkerflow/v1';

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
