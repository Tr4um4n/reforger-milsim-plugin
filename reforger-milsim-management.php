<?php
/**
 * Plugin Name: Arma Reforger MILSIM Management
 * Plugin URI:  https://gure.party
 * Description: Gestión integral para comunidades de Arma Reforger: Misiones, Eventos, ORBAT y Condecoraciones.
 * Version:     1.0.0
 * Author:      Antigravity
 * Author URI:  https://gure.party
 * Text Domain: reforger-milsim
 * License:     GPL2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define Constants
define( 'RMM_VERSION', '1.0.0' );
define( 'RMM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'RMM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Include Classes
$rmm_includes = array(
	'class-db-handler.php',
	'class-cpt-handler.php',
	'class-roles-handler.php',
	'class-metabox-handler.php',
	'class-medals-handler.php',
	'class-frontend-orbat.php',
	'class-calendar-handler.php',
);

foreach ( $rmm_includes as $file ) {
	require_once RMM_PLUGIN_DIR . 'includes/' . $file;
}

/**
 * Main Plugin Class
 */
class ReforgerMilsimManagement {

	/**
	 * Instance of this class.
	 *
	 * @var ReforgerMilsimManagement
	 */
	private static $instance = null;

	/**
	 * Get instance.
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->init_handlers();
		$this->setup_hooks();
	}

	/**
	 * Initialize Handlers.
	 */
	private function init_handlers() {
		new RMM_Roles_Handler();
		new RMM_CPT_Handler();
		new RMM_Metabox_Handler();
		new RMM_Medals_Handler();
		new RMM_Frontend_ORBAT();
		new RMM_Calendar_Handler();
	}

	/**
	 * Setup Hooks.
	 */
	private function setup_hooks() {
		// Activation & Deactivation
		register_activation_hook( __FILE__, array( 'RMM_DB_Handler', 'create_tables' ) );
		register_activation_hook( __FILE__, array( 'RMM_Roles_Handler', 'init_roles' ) );
		register_activation_hook( __FILE__, 'flush_rewrite_rules' );
		register_deactivation_hook( __FILE__, 'flush_rewrite_rules' );

		// Core Setup
		add_action( 'after_setup_theme', array( $this, 'register_image_sizes' ) );
		add_action( 'admin_head', array( $this, 'inject_admin_tactical_css' ) );
	}

	/**
	 * Inject Tactical CSS into WP Admin
	 */
	public function inject_admin_tactical_css() {
		echo '<style>
			#rmm_orbat_manager, #rmm_mission_config, #rmm_event_config { background: #1a1a1a; border: 1px solid #333; color: #eee; }
			#rmm_orbat_manager .postbox-header { border-bottom: 1px solid #333; background: #222; color: #fff; }
			#rmm_orbat_manager .hndle { color: #fff !important; }
			.rmm-squad-card { background: #2a2a2a !important; border: 1px solid #444 !important; color: #eee; }
			.rmm-slot-row { border-bottom: 1px solid #3a3a3a !important; }
			.rmm-slot-row select, .rmm-slot-row input { background: #333 !important; border: 1px solid #555 !important; color: #fff !important; }
			.rmm-status-badge { background: #1e3a1e !important; color: #a5d6a7 !important; }
			.rmm-api-sync-box { background: #222; padding: 15px; border-radius: 8px; border: 1px solid #444; }
		</style>';
	}

	/**
	 * Register custom image sizes.
	 */
	public function register_image_sizes() {
		add_image_size( 'metopa-militar', 120, 35, true );
	}
}

// Initialize Plugin
ReforgerMilsimManagement::get_instance();
