<?php
defined( 'ABSPATH' ) || exit;

class LinkerFlow_Admin {

	const NONCE_OPTION        = 'linkerflow_nonce';
	const NONCE_EXPIRY_OPTION = 'linkerflow_nonce_expiry';
	const STATE_OPTION        = 'linkerflow_state';
	const APP_CONNECT_URL     = 'https://app.linkerflow.io/api/wordpress/connect';
	const NONCE_TTL           = 3600; // seconds

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_connect_action' ) );
		add_filter( 'plugin_row_meta', array( __CLASS__, 'open_plugin_site_in_new_tab' ), 10, 4 );
	}

	public static function add_menu() {
		add_options_page(
			__( 'LinkerFlow', 'linkerflow' ),
			__( 'LinkerFlow', 'linkerflow' ),
			'manage_options',
			'linkerflow',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function render_page() {
		$connected = LinkerFlow_Auth::is_connected();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'LinkerFlow', 'linkerflow' ); ?></h1>

			<?php if ( $connected ) : ?>
				<p><?php esc_html_e( 'Your site is connected to LinkerFlow.', 'linkerflow' ); ?></p>
			<?php else : ?>
				<p><?php esc_html_e( 'Connect your site to LinkerFlow to enable automated internal linking.', 'linkerflow' ); ?></p>
				<form method="post" action="">
					<?php wp_nonce_field( 'linkerflow_connect', 'linkerflow_wp_nonce' ); ?>
					<input type="hidden" name="linkerflow_action" value="connect">
					<?php submit_button( __( 'Connect to LinkerFlow', 'linkerflow' ) ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function open_plugin_site_in_new_tab( $plugin_meta, $plugin_file, $plugin_data, $status ) {
		if ( plugin_basename( LINKERFLOW_DIR . 'linkerflow.php' ) !== $plugin_file ) {
			return $plugin_meta;
		}

		foreach ( $plugin_meta as $index => $meta ) {
			if ( false === strpos( $meta, 'href="https://www.linkerflow.io"' ) ) {
				continue;
			}

			$plugin_meta[ $index ] = str_replace(
				'<a href="https://www.linkerflow.io"',
				'<a target="_blank" rel="noopener noreferrer" href="https://www.linkerflow.io"',
				$meta
			);
		}

		return $plugin_meta;
	}

	public static function handle_connect_action() {
		if (
			! isset( $_POST['linkerflow_action'] ) ||
			'connect' !== $_POST['linkerflow_action']
		) {
			return;
		}

		if (
			! current_user_can( 'manage_options' ) ||
			! check_admin_referer( 'linkerflow_connect', 'linkerflow_wp_nonce' )
		) {
			wp_die( esc_html__( 'Permission denied.', 'linkerflow' ) );
		}

		$nonce  = wp_generate_uuid4();
		$state  = wp_generate_uuid4();
		$expiry = time() + self::NONCE_TTL;

		update_option( self::NONCE_OPTION, $nonce, false );
		update_option( self::NONCE_EXPIRY_OPTION, $expiry, false );
		update_option( self::STATE_OPTION, $state, false );

		$redirect = add_query_arg(
			array(
				'site_url' => rawurlencode( home_url() ),
				'nonce'    => rawurlencode( $nonce ),
				'state'    => rawurlencode( $state ),
			),
			self::APP_CONNECT_URL
		);

		wp_redirect( $redirect );
		exit;
	}
}
