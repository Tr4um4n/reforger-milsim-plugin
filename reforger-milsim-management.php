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
 *

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define Constants
define( 'RMM_VERSION', '1.0.0' );
define( 'RMM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'RMM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Include Classes
require_once RMM_PLUGIN_DIR . 'includes/class-db-handler.php';
require_once RMM_PLUGIN_DIR . 'includes/class-cpt-handler.php';
require_once RMM_PLUGIN_DIR . 'includes/class-roles-handler.php';
require_once RMM_PLUGIN_DIR . 'includes/class-metabox-handler.php';
require_once RMM_PLUGIN_DIR . 'includes/class-medals-handler.php';
require_once RMM_PLUGIN_DIR . 'includes/class-frontend-orbat.php';
require_once RMM_PLUGIN_DIR . 'includes/class-calendar-handler.php';

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
