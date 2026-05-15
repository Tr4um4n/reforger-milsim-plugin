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
define( 'RMM_VERSION', '1.0.2' );
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
		// Global Frontend Filters
		add_filter( 'the_content', array( $this, 'prepend_mission_event_header' ) );
		add_filter( 'the_title', array( $this, 'append_time_to_title' ), 10, 2 );
	}

	/**
	 * Inyecta la imagen destacada y la hora de inicio en la cabecera del post
	 */
	public function prepend_mission_event_header( $content ) {
		if ( is_singular( array( 'misiones', 'eventos_partidas' ) ) && in_the_loop() && is_main_query() ) {
			$post_id = get_the_ID();
			$html = '<div class="rmm-frontend-header" style="margin-bottom:30px;">';
			
			// Imagen Destacada
			if ( has_post_thumbnail( $post_id ) ) {
				$html .= '<div class="rmm-featured-image" style="margin-bottom:20px;">' . get_the_post_thumbnail( $post_id, 'full', array( 'style' => 'width:100%; height:auto; border-radius:12px; box-shadow: 0 10px 30px rgba(0,0,0,0.5);' ) ) . '</div>';
			}
			
			// Hora de Inicio (Solo para Eventos)
			if ( get_post_type( $post_id ) === 'eventos_partidas' ) {
				$fecha_inicio = get_post_meta( $post_id, 'fecha_inicio', true );
				if ( $fecha_inicio ) {
					$html .= '<div class="rmm-event-time-badge" style="background:#2271b1; color:#fff; display:inline-block; padding:8px 15px; border-radius:4px; font-weight:bold; letter-spacing:1px; margin-bottom:15px;">';
					$html .= '⏱ INICIO: ' . date( 'd/m/Y - H:i', strtotime( $fecha_inicio ) ) . 'h';
					$html .= '</div>';
				}
			}
			
			$html .= '</div>';
			return $html . $content;
		}
		return $content;
	}

	/**
	 * Añade la hora al título en la vista de lista/single
	 */
	public function append_time_to_title( $title, $id = null ) {
		if ( ! is_admin() && $id && get_post_type( $id ) === 'eventos_partidas' ) {
			$fecha_inicio = get_post_meta( $id, 'fecha_inicio', true );
			if ( $fecha_inicio ) {
				$timestamp = strtotime( $fecha_inicio );
				$date_str = date( 'Y/m/d', $timestamp );
				$day_name = date_i18n( 'l', $timestamp );
				$time = date( 'H:i', $timestamp );
				
				$meta_title = " {$date_str} {$day_name} - {$time}h";
				if ( strpos( $title, $meta_title ) === false ) {
					$title .= ' <span style="font-size:0.7em; color:#aaa; font-weight:normal;">[' . $meta_title . ']</span>';
				}
			}
		}
		return $title;
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
