<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'linkerflow_secret' );
delete_option( 'linkerflow_nonce' );
delete_option( 'linkerflow_nonce_expiry' );
delete_option( 'linkerflow_state' );
