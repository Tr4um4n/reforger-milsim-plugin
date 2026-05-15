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

	public function __construct() {
		add_action( 'init', array( $this, 'register_roles' ) );
		
		// Perfil de Usuario
		add_action( 'show_user_profile', array( $this, 'render_user_profile_fields' ) );
		add_action( 'edit_user_profile', array( $this, 'render_user_profile_fields' ) );
		add_action( 'personal_options_update', array( $this, 'save_user_profile_fields' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_user_profile_fields' ) );
	}

	public function register_roles() {
		self::init_roles();
	}

	/**
	 * Initialize MILSIM roles and capabilities.
	 */
	public static function init_roles() {
		// Otorga permisos de ORBAT a los administradores y extrae sus capacidades
		$admin_role = get_role( 'administrator' );
		$admin_caps = array();
		if ( $admin_role ) {
			$admin_role->add_cap( 'reserve_orbat_slot' );
			$admin_caps = $admin_role->capabilities;
		}

		$roles = array(
			'fundador'        => array(
				'display_name' => 'Fundador',
				'capabilities' => array_merge( $admin_caps, array( 'reserve_orbat_slot' => true ) ),
			),
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

	public static function remove_roles() {
		$role_keys = array(
			'fundador',
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

	/**
	 * RENDER: Campos extra en el perfil de usuario
	 */
	public function render_user_profile_fields( $user ) {
		?>
		<h3><?php _e( 'Información Táctica (Arma Reforger)', 'reforger-milsim' ); ?></h3>
		<table class="form-table">
			<tr>
				<th><label for="steamid_64"><?php _e( 'SteamID 64', 'reforger-milsim' ); ?></label></th>
				<td>
					<input type="text" name="steamid_64" id="steamid_64" value="<?php echo esc_attr( get_the_author_meta( 'steamid_64', $user->ID ) ); ?>" class="regular-text" />
					<p class="description"><?php _e( 'Formato numérico de 17 dígitos (ej: 76561198...).', 'reforger-milsim' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="bohemia_uid"><?php _e( 'Bohemia UID', 'reforger-milsim' ); ?></label></th>
				<td>
					<input type="text" name="bohemia_uid" id="bohemia_uid" value="<?php echo esc_attr( get_the_author_meta( 'bohemia_uid', $user->ID ) ); ?>" class="regular-text" />
					<p class="description"><?php _e( 'Identificador único de Bohemia Interactive para la telemetría.', 'reforger-milsim' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * SAVE: Guardar los campos extra del perfil
	 */
	public function save_user_profile_fields( $user_id ) {
		if ( ! current_user_can( 'edit_user', $user_id ) ) return false;
		
		if ( isset( $_POST['steamid_64'] ) ) {
			update_user_meta( $user_id, 'steamid_64', sanitize_text_field( $_POST['steamid_64'] ) );
		}
		if ( isset( $_POST['bohemia_uid'] ) ) {
			update_user_meta( $user_id, 'bohemia_uid', sanitize_text_field( $_POST['bohemia_uid'] ) );
		}
	}
}
