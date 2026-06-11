<?php
defined( 'ABSPATH' ) || exit;

class LinkerFlow_REST_Languages {

	public function register() {
		register_rest_route(
			LinkerFlow::NAMESPACE,
			'/languages',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => array( 'LinkerFlow_Auth', 'permission_callback' ),
			)
		);
	}

	public function handle( WP_REST_Request $request ) {
		if ( $this->is_polylang_active() ) {
			return rest_ensure_response( $this->get_polylang_data() );
		}

		if ( $this->is_wpml_active() ) {
			return rest_ensure_response( $this->get_wpml_data() );
		}

		return rest_ensure_response(
			array(
				'multilingual' => false,
				'plugin'       => null,
				'default'      => get_locale(),
				'languages'    => array(),
			)
		);
	}

	private function is_polylang_active() {
		return function_exists( 'pll_languages_list' );
	}

	private function is_wpml_active() {
		return defined( 'ICL_SITEPRESS_VERSION' );
	}

	private function get_polylang_data() {
		$default   = pll_default_language( 'slug' );
		$languages = pll_languages_list( array( 'fields' => '' ) );

		$items = array();
		foreach ( $languages as $lang ) {
			$items[] = array(
				'code'         => $lang->slug,
				'label'        => $lang->name,
				'subdirectory' => $this->parse_subdirectory( $lang->home_url ),
				'default'      => ( $lang->slug === $default ),
			);
		}

		return array(
			'multilingual' => true,
			'plugin'       => 'polylang',
			'default'      => $default,
			'languages'    => $items,
		);
	}

	private function get_wpml_data() {
		global $sitepress;

		$default   = $sitepress->get_default_language();
		$languages = $sitepress->get_active_languages();

		$items = array();
		foreach ( $languages as $code => $lang ) {
			$home      = $sitepress->language_url( $code );
			$items[]   = array(
				'code'         => $code,
				'label'        => $lang['native_name'],
				'subdirectory' => $this->parse_subdirectory( $home ),
				'default'      => ( $code === $default ),
			);
		}

		return array(
			'multilingual' => true,
			'plugin'       => 'wpml',
			'default'      => $default,
			'languages'    => $items,
		);
	}

	private function parse_subdirectory( string $url ) {
		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( ! $path || '/' === $path ) {
			return '/';
		}
		// Normalise: always trailing slash, always leading slash.
		return '/' . trim( $path, '/' ) . '/';
	}
}
