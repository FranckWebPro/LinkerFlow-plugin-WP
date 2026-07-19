<?php
defined( 'ABSPATH' ) || exit;

class LinkerFlow_Auth {

	const SECRET_OPTION = 'linkerflow_secret';

	public static function permission_callback( WP_REST_Request $request ) {
		$secret = get_option( self::SECRET_OPTION );
		if ( ! $secret ) {
			return new WP_Error(
				'linkerflow_unauthorized',
				__( 'Invalid or missing credential.', 'linkerflow-internal-linking-for-seo' ),
				array( 'status' => 401 )
			);
		}

		$header = $request->get_header( 'authorization' );
		if ( ! $header || strpos( $header, 'Bearer ' ) !== 0 ) {
			return new WP_Error(
				'linkerflow_unauthorized',
				__( 'Invalid or missing credential.', 'linkerflow-internal-linking-for-seo' ),
				array( 'status' => 401 )
			);
		}

		$provided = substr( $header, 7 );
		if ( ! hash_equals( $secret, $provided ) ) {
			return new WP_Error(
				'linkerflow_unauthorized',
				__( 'Invalid or missing credential.', 'linkerflow-internal-linking-for-seo' ),
				array( 'status' => 401 )
			);
		}

		return true;
	}

	public static function store_secret( string $secret ) {
		update_option( self::SECRET_OPTION, $secret, false );
	}

	public static function is_connected() {
		return (bool) get_option( self::SECRET_OPTION );
	}
}
