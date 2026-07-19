<?php
/**
 * Plugin Name: LinkerFlow - Internal Linking for SEO
 * Plugin URI:  https://github.com/FranckWebPro/LinkerFlow-plugin-WP
 * Description: Improve your site's ranking (SEO) and your user experience with automated internal link building. Fix broken links and health issues in one click.
 * Version:     1.0.5
 * Author:      LinkerFlow
 * Author URI:  https://www.linkerflow.io
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: linkerflow
 * Requires at least: 6.5
 * Requires PHP: 7.4
 */

/*
 * The LinkerFlow WordPress plugin source code is licensed under the GNU
 * General Public License as published by the Free Software Foundation, either
 * version 2 of the License, or any later version.
 *
 * Source code: https://github.com/FranckWebPro/LinkerFlow-plugin-WP
 */

defined( 'ABSPATH' ) || exit;

define( 'LINKERFLOW_VERSION', '1.0.5' );
define( 'LINKERFLOW_DIR', plugin_dir_path( __FILE__ ) );
define( 'LINKERFLOW_URL', plugin_dir_url( __FILE__ ) );

require_once LINKERFLOW_DIR . 'includes/class-auth.php';
require_once LINKERFLOW_DIR . 'includes/class-admin.php';
require_once LINKERFLOW_DIR . 'includes/class-rest-connect.php';
require_once LINKERFLOW_DIR . 'includes/class-rest-post-types.php';
require_once LINKERFLOW_DIR . 'includes/class-rest-languages.php';
require_once LINKERFLOW_DIR . 'includes/class-page-builders.php';
require_once LINKERFLOW_DIR . 'includes/class-rest-posts.php';
require_once LINKERFLOW_DIR . 'includes/class-linkerflow.php';

LinkerFlow::init();
