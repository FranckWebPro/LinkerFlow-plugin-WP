<?php
/**
 * Plugin Name: LinkerFlow
 * Plugin URI:  https://www.linkerflow.io
 * Description: Connect your WordPress site to LinkerFlow for automated internal linking.
 * Version:     1.0.0
 * Author:      LinkerFlow
 * Author URI:  https://www.linkerflow.io
 * License:     GPL-2.0-or-later
 * Text Domain: linkerflow
 * Requires at least: 6.5
 * Requires PHP: 7.4
 */

defined( 'ABSPATH' ) || exit;

define( 'LINKERFLOW_VERSION', '1.0.0' );
define( 'LINKERFLOW_DIR', plugin_dir_path( __FILE__ ) );
define( 'LINKERFLOW_URL', plugin_dir_url( __FILE__ ) );

require_once LINKERFLOW_DIR . 'includes/class-auth.php';
require_once LINKERFLOW_DIR . 'includes/class-admin.php';
require_once LINKERFLOW_DIR . 'includes/class-rest-connect.php';
require_once LINKERFLOW_DIR . 'includes/class-rest-post-types.php';
require_once LINKERFLOW_DIR . 'includes/class-rest-languages.php';
require_once LINKERFLOW_DIR . 'includes/class-rest-posts.php';
require_once LINKERFLOW_DIR . 'includes/class-linkerflow.php';

LinkerFlow::init();
