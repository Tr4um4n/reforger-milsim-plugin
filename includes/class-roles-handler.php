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

		// Registro automático de cambio de roles
		add_action( 'set_user_role', array( $this, 'log_role_change' ), 10, 3 );
	}

	public function register_roles() {
		self::init_roles();
	}

	/**
	 * Registrar el cambio de rol en el historial del operador
	 */
	public function log_role_change( $user_id, $role, $old_roles ) {
		$history = get_user_meta( $user_id, 'rmm_role_history', true );
		if ( ! is_array( $history ) ) {
			$history = array();
		}

		$wp_roles = wp_roles();
		$old_role_names = array();
		if ( is_array( $old_roles ) ) {
			foreach ( $old_roles as $r ) {
				$old_role_names[] = isset( $wp_roles->role_names[$r] ) ? translate_user_role( $wp_roles->role_names[$r] ) : $r;
			}
		}
		$new_role_name = isset( $wp_roles->role_names[$role] ) ? translate_user_role( $wp_roles->role_names[$role] ) : $role;

		$current_user = wp_get_current_user();
		$by = $current_user->ID ? $current_user->display_name : __( 'Sistema', 'reforger-milsim' );

		$history[] = array(
			'date' => current_time( 'mysql' ),
			'from' => implode( ', ', $old_role_names ),
			'to'   => $new_role_name,
			'by'   => $by,
		);
		update_user_meta( $user_id, 'rmm_role_history', $history );
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
		// Obtener estadísticas existentes
		$steamid_64      = get_the_author_meta( 'steamid_64', $user->ID );
		$bohemia_uid     = get_the_author_meta( 'bohemia_uid', $user->ID );
		$enrolment_date  = get_the_author_meta( 'rmm_enrolment_date', $user->ID );
		$kills           = get_the_author_meta( 'rmm_kills', $user->ID ) ?: 0;
		$deaths          = get_the_author_meta( 'rmm_deaths', $user->ID ) ?: 0;
		$hours           = get_the_author_meta( 'rmm_hours', $user->ID ) ?: 0;
		$shots_fired     = get_the_author_meta( 'rmm_shots_fired', $user->ID ) ?: 0;
		$shots_hit       = get_the_author_meta( 'rmm_shots_hit', $user->ID ) ?: 0;
		$history         = get_user_meta( $user->ID, 'rmm_role_history', true );
		?>
		<h3><?php _e( 'Información Táctica (Arma Reforger)', 'reforger-milsim' ); ?></h3>
		<table class="form-table">
			<tr>
				<th><label for="steamid_64"><?php _e( 'SteamID 64', 'reforger-milsim' ); ?></label></th>
				<td>
					<input type="text" name="steamid_64" id="steamid_64" value="<?php echo esc_attr( $steamid_64 ); ?>" class="regular-text" />
					<p class="description"><?php _e( 'Formato numérico de 17 dígitos (ej: 76561198...).', 'reforger-milsim' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="bohemia_uid"><?php _e( 'Bohemia UID', 'reforger-milsim' ); ?></label></th>
				<td>
					<input type="text" name="bohemia_uid" id="bohemia_uid" value="<?php echo esc_attr( $bohemia_uid ); ?>" class="regular-text" />
					<p class="description"><?php _e( 'Identificador único de Bohemia Interactive para la telemetría.', 'reforger-milsim' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="rmm_enrolment_date"><?php _e( 'Fecha de Enrolamiento', 'reforger-milsim' ); ?></label></th>
				<td>
					<input type="date" name="rmm_enrolment_date" id="rmm_enrolment_date" value="<?php echo esc_attr( $enrolment_date ); ?>" class="regular-text" />
					<p class="description"><?php _e( 'Fecha en la que el operador se unió formalmente al clan.', 'reforger-milsim' ); ?></p>
				</td>
			</tr>
		</table>

		<h3><?php _e( 'Estadísticas de Combate (Manual/Addon)', 'reforger-milsim' ); ?></h3>
		<p class="description"><?php _e( 'Estos valores serán actualizados automáticamente por el Addon de Arma Reforger en el futuro, pero puedes editarlos manualmente.', 'reforger-milsim' ); ?></p>
		<table class="form-table">
			<tr>
				<th><label for="rmm_kills"><?php _e( 'Bajas (Kills)', 'reforger-milsim' ); ?></label></th>
				<td>
					<input type="number" name="rmm_kills" id="rmm_kills" value="<?php echo esc_attr( $kills ); ?>" min="0" class="small-text" />
				</td>
			</tr>
			<tr>
				<th><label for="rmm_deaths"><?php _e( 'Muertes (Deaths)', 'reforger-milsim' ); ?></label></th>
				<td>
					<input type="number" name="rmm_deaths" id="rmm_deaths" value="<?php echo esc_attr( $deaths ); ?>" min="0" class="small-text" />
				</td>
			</tr>
			<tr>
				<th><label for="rmm_hours"><?php _e( 'Horas de combate', 'reforger-milsim' ); ?></label></th>
				<td>
					<input type="number" name="rmm_hours" id="rmm_hours" value="<?php echo esc_attr( $hours ); ?>" min="0" class="small-text" />
				</td>
			</tr>
			<tr>
				<th><label for="rmm_shots_fired"><?php _e( 'Disparos realizados', 'reforger-milsim' ); ?></label></th>
				<td>
					<input type="number" name="rmm_shots_fired" id="rmm_shots_fired" value="<?php echo esc_attr( $shots_fired ); ?>" min="0" class="regular-text" />
				</td>
			</tr>
			<tr>
				<th><label for="rmm_shots_hit"><?php _e( 'Impactos logrados', 'reforger-milsim' ); ?></label></th>
				<td>
					<input type="number" name="rmm_shots_hit" id="rmm_shots_hit" value="<?php echo esc_attr( $shots_hit ); ?>" min="0" class="regular-text" />
				</td>
			</tr>
		</table>

		<h3><?php _e( 'Cronología de Carrera Militar', 'reforger-milsim' ); ?></h3>
		<?php if ( ! empty( $history ) && is_array( $history ) ) : ?>
			<div style="background:#f6f7f7; padding: 15px; border-left: 4px solid #849b4c; max-width: 800px; max-height: 250px; overflow-y: auto;">
				<ul style="margin:0; padding-left:20px; list-style-type: square;">
					<?php foreach ( array_reverse($history) as $change ) : ?>
						<li style="margin-bottom:8px;">
							<strong><?php echo esc_html( date('d/m/Y H:i', strtotime($change['date'])) ); ?></strong>:
							<?php if ( ! empty($change['from']) ) : ?>
								De <code><?php echo esc_html( $change['from'] ); ?></code> a 
							<?php else : ?>
								Asignado rol 
							<?php endif; ?>
							<code><?php echo esc_html( $change['to'] ); ?></code> 
							<span style="color:#666; font-size:0.9em;">(por <?php echo esc_html( $change['by'] ); ?>)</span>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php else : ?>
			<p class="description"><?php _e( 'No hay registros de cambios de rol para este operador todavía.', 'reforger-milsim' ); ?></p>
		<?php endif; ?>
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
		if ( isset( $_POST['rmm_enrolment_date'] ) ) {
			update_user_meta( $user_id, 'rmm_enrolment_date', sanitize_text_field( $_POST['rmm_enrolment_date'] ) );
		}
		if ( isset( $_POST['rmm_kills'] ) ) {
			update_user_meta( $user_id, 'rmm_kills', intval( $_POST['rmm_kills'] ) );
		}
		if ( isset( $_POST['rmm_deaths'] ) ) {
			update_user_meta( $user_id, 'rmm_deaths', intval( $_POST['rmm_deaths'] ) );
		}
		if ( isset( $_POST['rmm_hours'] ) ) {
			update_user_meta( $user_id, 'rmm_hours', intval( $_POST['rmm_hours'] ) );
		}
		if ( isset( $_POST['rmm_shots_fired'] ) ) {
			update_user_meta( $user_id, 'rmm_shots_fired', intval( $_POST['rmm_shots_fired'] ) );
		}
		if ( isset( $_POST['rmm_shots_hit'] ) ) {
			update_user_meta( $user_id, 'rmm_shots_hit', intval( $_POST['rmm_shots_hit'] ) );
		}
	}
}

