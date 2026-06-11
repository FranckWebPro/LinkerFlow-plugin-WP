<?php
defined( 'ABSPATH' ) || exit;

class LinkerFlow_REST_Connect {

	public function register() {
		register_rest_route(
			LinkerFlow::NAMESPACE,
			'/connect',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'nonce'  => array(
						'required' => true,
						'type'     => 'string',
					),
					'secret' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);
	}

	public function handle( WP_REST_Request $request ) {
		$provided_nonce = $request->get_param( 'nonce' );
		$stored_nonce   = get_option( LinkerFlow_Admin::NONCE_OPTION );
		$expiry         = (int) get_option( LinkerFlow_Admin::NONCE_EXPIRY_OPTION, 0 );

		if (
			! $stored_nonce ||
			! hash_equals( $stored_nonce, $provided_nonce ) ||
			time() > $expiry
		) {
			return new WP_Error(
				'linkerflow_unauthorized',
				'Invalid or missing credential.',
				array( 'status' => 401 )
			);
		}

		// Consume the nonce so it cannot be replayed.
		delete_option( LinkerFlow_Admin::NONCE_OPTION );
		delete_option( LinkerFlow_Admin::NONCE_EXPIRY_OPTION );
		delete_option( LinkerFlow_Admin::STATE_OPTION );

		$secret = sanitize_text_field( $request->get_param( 'secret' ) );
		LinkerFlow_Auth::store_secret( $secret );

		return rest_ensure_response(
			array(
				'connected'    => true,
				'site_url'     => home_url(),
				'account_name' => get_bloginfo( 'name' ),
			)
		);
	}
}
