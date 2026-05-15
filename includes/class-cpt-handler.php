<?php
/**
 * CPT Handler Class
 *
 * Handles registration of Custom Post Types and their taxonomies.
 *
 * @package ReforgerMilsimManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RMM_CPT_Handler {

	/**
	 * Initialize hooks.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register_cpts' ) );
	}

	/**
	 * Register Custom Post Types.
	 */
	public function register_cpts() {
		// Register Misiones
		register_post_type( 'misiones', array(
			'labels' => array(
				'name'          => __( 'Misiones', 'reforger-milsim' ),
				'singular_name' => __( 'Misión', 'reforger-milsim' ),
			),
			'public'      => true,
			'has_archive' => true,
			'menu_icon'   => 'dashicons-shield-alt',
			'supports'    => array( 'title', 'editor', 'thumbnail', 'excerpt' ),
			'show_in_rest' => true,
		) );

		// Register Eventos Partidas
		register_post_type( 'eventos_partidas', array(
			'labels' => array(
				'name'          => __( 'Eventos', 'reforger-milsim' ),
				'singular_name' => __( 'Evento', 'reforger-milsim' ),
			),
			'public'      => true,
			'has_archive' => true,
			'menu_icon'   => 'dashicons-calendar-alt',
			'supports'    => array( 'title', 'editor', 'thumbnail' ),
			'show_in_rest' => true,
		) );

		// Register Condecoraciones
		register_post_type( 'condecoraciones', array(
			'labels' => array(
				'name'          => __( 'Condecoraciones', 'reforger-milsim' ),
				'singular_name' => __( 'Condecoración', 'reforger-milsim' ),
			),
			'public'      => true,
			'has_archive' => true,
			'menu_icon'   => 'dashicons-awards',
			'supports'    => array( 'title', 'editor', 'thumbnail' ),
			'show_in_rest' => true,
		) );
	}
}
