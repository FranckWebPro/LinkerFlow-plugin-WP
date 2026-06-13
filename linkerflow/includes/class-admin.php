<?php
defined( 'ABSPATH' ) || exit;

class LinkerFlow_Admin {

	const NONCE_OPTION        = 'linkerflow_nonce';
	const NONCE_EXPIRY_OPTION = 'linkerflow_nonce_expiry';
	const STATE_OPTION        = 'linkerflow_state';
	const APP_CONNECT_URL     = 'https://app.linkerflow.io/api/wordpress/connect';
	const PRIVACY_URL         = 'https://www.linkerflow.io/privacy';
	const TERMS_URL           = 'https://www.linkerflow.io/terms';
	const NONCE_TTL           = 3600; // seconds

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_connect_action' ) );
		add_action( 'admin_init', array( __CLASS__, 'add_privacy_policy_content' ) );
		add_filter( 'plugin_row_meta', array( __CLASS__, 'open_plugin_site_in_new_tab' ), 10, 4 );
	}

	public static function add_menu() {
		add_menu_page(
			__( 'LinkerFlow', 'linkerflow' ),
			__( 'LinkerFlow', 'linkerflow' ),
			'manage_options',
			'linkerflow',
			array( __CLASS__, 'render_page' ),
			'dashicons-admin-links',
			80
		);
	}

	public static function render_page() {
		$connected = LinkerFlow_Auth::is_connected();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'LinkerFlow', 'linkerflow' ); ?></h1>

			<?php if ( $connected ) : ?>
				<p><?php esc_html_e( 'Your site is connected to the LinkerFlow service.', 'linkerflow' ); ?></p>
			<?php else : ?>
				<p><?php esc_html_e( 'Connect your site to the LinkerFlow service to manage approved internal links.', 'linkerflow' ); ?></p>
				<p>
					<?php
					echo wp_kses(
						sprintf(
							/* translators: 1: privacy policy URL, 2: terms URL. */
							__( 'By connecting, you allow the LinkerFlow service to read published content and update approved internal links through this plugin. Review the <a href="%1$s" target="_blank" rel="noopener noreferrer">privacy policy</a> and <a href="%2$s" target="_blank" rel="noopener noreferrer">terms of use</a>.', 'linkerflow' ),
							esc_url( self::PRIVACY_URL ),
							esc_url( self::TERMS_URL )
						),
						self::allowed_policy_html()
					);
					?>
				</p>
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
		$action = isset( $_POST['linkerflow_action'] ) ? sanitize_key( wp_unslash( $_POST['linkerflow_action'] ) ) : '';
		if (
			! $action ||
			'connect' !== $action
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
				'site_url' => home_url(),
				'nonce'    => $nonce,
				'state'    => $state,
			),
			self::APP_CONNECT_URL
		);

		add_filter( 'allowed_redirect_hosts', array( __CLASS__, 'allow_app_redirect_host' ) );
		wp_safe_redirect( esc_url_raw( $redirect ) );
		exit;
	}

	public static function allow_app_redirect_host( $hosts ) {
		$host = wp_parse_url( self::APP_CONNECT_URL, PHP_URL_HOST );
		if ( $host ) {
			$hosts[] = $host;
		}

		return array_unique( $hosts );
	}

	public static function add_privacy_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content = '<p>' . esc_html__( 'When you connect this site to the LinkerFlow service, the plugin sends the site URL and a one-time connection token to the LinkerFlow application. After connection, the LinkerFlow service can authenticate to this site with a shared secret to read published posts, pages, selected public custom post types, permalinks, language information, and post content. The LinkerFlow service can also update post content through this plugin to publish approved internal links. The plugin does not send WordPress user accounts, passwords, comments, private posts, drafts, settings, or unrelated plugin data.', 'linkerflow' ) . '</p>';
		$content .= '<p>' . wp_kses(
			sprintf(
				/* translators: 1: privacy policy URL, 2: terms URL. */
				__( 'For more information, review the LinkerFlow <a href="%1$s">privacy policy</a> and <a href="%2$s">terms of use</a>.', 'linkerflow' ),
				esc_url( self::PRIVACY_URL ),
				esc_url( self::TERMS_URL )
			),
			self::allowed_policy_html()
		) . '</p>';

		wp_add_privacy_policy_content( 'LinkerFlow', $content );
	}

	private static function allowed_policy_html() {
		return array(
			'a' => array(
				'href'   => array(),
				'rel'    => array(),
				'target' => array(),
			),
		);
	}
}
