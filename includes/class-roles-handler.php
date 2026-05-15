<?php
/**
 * Roles Handler Class
 *
 * Handles creation and management of MILSIM roles and capabilities.
 *
 * @package ReforgerMilsimManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RMM_Roles_Handler {

	/**
	 * Initialize MILSIM roles and capabilities.
	 */
	public static function init_roles() {
		$roles = array(
			'visitante'       => array(
				'display_name' => 'Visitante',
				'capabilities' => array( 'read' => true ),
			),
			'recluta'         => array(
				'display_name' => 'Recluta',
				'capabilities' => array(
					'read'                => true,
					'reserve_orbat_slot' => true,
				),
			),
			'activo'          => array(
				'display_name' => 'Activo',
				'capabilities' => array(
					'read'                => true,
					'reserve_orbat_slot' => true,
				),
			),
			'reservista'      => array(
				'display_name' => 'Reservista',
				'capabilities' => array(
					'read'                => true,
					'reserve_orbat_slot' => true,
				),
			),
			'veterano'        => array(
				'display_name' => 'Veterano',
				'capabilities' => array(
					'read'                => true,
					'reserve_orbat_slot' => true,
				),
			),
			'aliado'          => array(
				'display_name' => 'Aliado',
				'capabilities' => array(
					'read'                => true,
					'reserve_orbat_slot' => true,
				),
			),
			'baja_indefinida' => array(
				'display_name' => 'Baja Indefinida',
				'capabilities' => array( 'read' => true ),
			),
			'baja_definitiva' => array(
				'display_name' => 'Baja Definitiva',
				'capabilities' => array( 'read' => true ),
			),
			'expulsado'       => array(
				'display_name' => 'Expulsado',
				'capabilities' => array( 'read' => false ),
			),
		);

		foreach ( $roles as $role_key => $role_data ) {
			add_role( $role_key, $role_data['display_name'], $role_data['capabilities'] );
		}

		// Update default role for new registrations
		update_option( 'default_role', 'visitante' );
	}

	/**
	 * Remove custom roles on deactivation.
	 */
	public static function remove_roles() {
		$role_keys = array(
			'visitante',
			'recluta',
			'activo',
			'reservista',
			'veterano',
			'aliado',
			'baja_indefinida',
			'baja_definitiva',
			'expulsado',
		);

		foreach ( $role_keys as $role_key ) {
			remove_role( $role_key );
		}
	}
}
