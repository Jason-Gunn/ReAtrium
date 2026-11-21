<?php
/**
 * Plugin Name:       ReAtrium
 * Plugin URI:        https://dialogisticindustries.com/solutions/software/reatrium/
 * Description:       Out-of-the-box, AI-forward SMB intranet and company hub built on WordPress 7+ AI systems, with optional AIGIS governance.
 * Version:           1.0.0-alpha
 * Requires at least: 7.0
 * Requires PHP:      8.1
 * Author:            Dialogistic Industries
 * Author URI:        https://dialogisticindustries.com/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       reatrium
 * Domain Path:       /languages
 *
 * @package ReAtrium
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'REATRIUM_VERSION',     '1.0.0-alpha' );
define( 'REATRIUM_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'REATRIUM_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
define( 'REATRIUM_PLUGIN_FILE', __FILE__ );
define( 'REATRIUM_TEXT_DOMAIN', 'reatrium' );

/**
 * Load ReAtrium core dependencies.
 */
function reatrium_load_dependencies(): void {
	require_once REATRIUM_PLUGIN_DIR . 'includes/core/class-reatrium-loader.php';
	require_once REATRIUM_PLUGIN_DIR . 'includes/core/class-reatrium-activator.php';
	require_once REATRIUM_PLUGIN_DIR . 'includes/core/class-reatrium-deactivator.php';
	require_once REATRIUM_PLUGIN_DIR . 'includes/core/class-reatrium-isolation.php';

	// Extensible Module SDK & Registry.
	require_once REATRIUM_PLUGIN_DIR . 'includes/core/interface-reatrium-module.php';
	require_once REATRIUM_PLUGIN_DIR . 'includes/core/class-reatrium-abstract-module.php';
	require_once REATRIUM_PLUGIN_DIR . 'includes/core/class-reatrium-module-registry.php';

	// AI & Vector layer.
	require_once REATRIUM_PLUGIN_DIR . 'includes/ai/interface-reatrium-vector-store.php';
	require_once REATRIUM_PLUGIN_DIR . 'includes/ai/class-reatrium-vector-store-mysql.php';
	require_once REATRIUM_PLUGIN_DIR . 'includes/ai/class-reatrium-ai-gateway.php';

	// Frontend hybrid portal.
	require_once REATRIUM_PLUGIN_DIR . 'includes/portal/class-reatrium-portal.php';

	// Built-in Intranet & Public AI modules.
	require_once REATRIUM_PLUGIN_DIR . 'includes/modules/class-reatrium-module-announcements.php';
	require_once REATRIUM_PLUGIN_DIR . 'includes/modules/class-reatrium-module-kb.php';
	require_once REATRIUM_PLUGIN_DIR . 'includes/modules/class-reatrium-module-directory.php';
	require_once REATRIUM_PLUGIN_DIR . 'includes/modules/class-reatrium-module-requests.php';
	require_once REATRIUM_PLUGIN_DIR . 'includes/modules/class-reatrium-module-public-ai.php';

	// Admin & Setup Wizard.
	if ( is_admin() ) {
		require_once REATRIUM_PLUGIN_DIR . 'includes/admin/class-reatrium-admin.php';
	}

	require_once REATRIUM_PLUGIN_DIR . 'includes/core/class-reatrium-plugin.php';
}

reatrium_load_dependencies();

register_activation_hook( __FILE__, [ 'ReAtrium_Activator', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'ReAtrium_Deactivator', 'deactivate' ] );

/**
 * Bootstrap ReAtrium on plugins_loaded so third-party module plugins
 * can hook `reatrium_register_modules` regardless of plugin load order.
 */
function run_reatrium(): void {
	$plugin = new ReAtrium_Plugin();
	$plugin->run();
}

add_action( 'plugins_loaded', 'run_reatrium' );

