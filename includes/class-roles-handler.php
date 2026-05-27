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
		
		// AJAX para el gestor de timeline desde admin
		add_action( 'wp_ajax_rmm_toggle_timeline_entry', array( $this, 'ajax_toggle_timeline_entry' ) );
		add_action( 'wp_ajax_rmm_add_timeline_entry', array( $this, 'ajax_add_timeline_entry' ) );
		add_action( 'wp_ajax_rmm_delete_timeline_entry', array( $this, 'ajax_delete_timeline_entry' ) );
		add_action( 'admin_footer', array( $this, 'inject_timeline_js' ) );
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
		// Solo admin o fundador ven stats de combate y timeline editables
		$current = wp_get_current_user();
		$can_edit_tactical = current_user_can( 'manage_options' ) || in_array( 'fundador', (array) $current->roles );
		
		// Obtener estadísticas existentes
		$steamid_64      = get_the_author_meta( 'steamid_64', $user->ID );
		$bohemia_uid     = get_the_author_meta( 'bohemia_uid', $user->ID );
		$enrolment_date  = get_the_author_meta( 'rmm_enrolment_date', $user->ID );
		$kills           = get_the_author_meta( 'rmm_kills', $user->ID ) ?: 0;
		$deaths          = get_the_author_meta( 'rmm_deaths', $user->ID ) ?: 0;
		$hours           = get_the_author_meta( 'rmm_hours', $user->ID ) ?: 0;
		$shots_fired     = get_the_author_meta( 'rmm_shots_fired', $user->ID ) ?: 0;
		$shots_hit       = get_the_author_meta( 'rmm_shots_hit', $user->ID ) ?: 0;
		$bandages        = get_the_author_meta( 'rmm_bandages', $user->ID ) ?: 0;
		$tourniquets     = get_the_author_meta( 'rmm_tourniquets', $user->ID ) ?: 0;
		$saline          = get_the_author_meta( 'rmm_saline', $user->ID ) ?: 0;
		$morphine        = get_the_author_meta( 'rmm_morphine', $user->ID ) ?: 0;
		$epinephrine     = get_the_author_meta( 'rmm_epinephrine', $user->ID ) ?: 0;
		$dist_walked     = get_the_author_meta( 'rmm_dist_walked', $user->ID ) ?: 0;
		$dist_vehicle    = get_the_author_meta( 'rmm_dist_vehicle', $user->ID ) ?: 0;
		$dist_total      = get_the_author_meta( 'rmm_dist_total', $user->ID ) ?: 0;
		$veh_destroyed   = get_the_author_meta( 'rmm_veh_destroyed', $user->ID ) ?: 0;
		$veh_air         = get_the_author_meta( 'rmm_veh_air', $user->ID ) ?: 0;
		$explosives      = get_the_author_meta( 'rmm_explosives', $user->ID ) ?: 0;
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
				<th><label for="rmm_telegram_id"><?php _e( 'Telegram ID', 'reforger-milsim' ); ?></label></th>
				<td>
					<input type="text" name="rmm_telegram_id" id="rmm_telegram_id" value="<?php echo esc_attr( get_the_author_meta( 'rmm_telegram_id', $user->ID ) ); ?>" class="regular-text" />
					<p class="description"><?php _e( 'ID numérico de Telegram (ej: 12345678). Se usa para auto-confirmar asistencia a RAIDs desde el bot.', 'reforger-milsim' ); ?></p>
				</td>
			</tr>
		</table>

		<?php if ( $can_edit_tactical ) : ?>
		<h3><?php _e( 'Fecha de Enrolamiento', 'reforger-milsim' ); ?></h3>
		<table class="form-table">
			<tr>
				<th><label for="rmm_enrolment_date"><?php _e( 'Fecha', 'reforger-milsim' ); ?></label></th>
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
									<input type="number" name="rmm_hours" id="rmm_hours" value="<?php echo esc_attr( $hours ); ?>" min="0" step="0.1" class="small-text" />
									<p class="description"><?php _e( 'Formato decimal. Ej: 0.5 = 30 min, 2.25 = 2h 15min.', 'reforger-milsim' ); ?></p>
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
			<tr>
				<th><label for="rmm_bandages"><?php _e( 'Vendajes aplicados', 'reforger-milsim' ); ?></label></th>
				<td>
					<input type="number" name="rmm_bandages" id="rmm_bandages" value="<?php echo esc_attr( $bandages ); ?>" min="0" class="small-text" />
				</td>
			</tr>
			<tr>
				<th><label for="rmm_tourniquets"><?php _e( 'Torniquetes aplicados', 'reforger-milsim' ); ?></label></th>
				<td>
					<input type="number" name="rmm_tourniquets" id="rmm_tourniquets" value="<?php echo esc_attr( $tourniquets ); ?>" min="0" class="small-text" />
				</td>
			</tr>
			<tr>
				<th><label for="rmm_saline"><?php _e( 'Salino aplicado', 'reforger-milsim' ); ?></label></th>
				<td>
					<input type="number" name="rmm_saline" id="rmm_saline" value="<?php echo esc_attr( $saline ); ?>" min="0" class="small-text" />
				</td>
			</tr>
			<tr>
				<th><label for="rmm_morphine"><?php _e( 'Morfina aplicada', 'reforger-milsim' ); ?></label></th>
				<td>
					<input type="number" name="rmm_morphine" id="rmm_morphine" value="<?php echo esc_attr( $morphine ); ?>" min="0" class="small-text" />
				</td>
			</tr>
			<tr>
				<th><label for="rmm_epinephrine"><?php _e( 'Epinefrina aplicada', 'reforger-milsim' ); ?></label></th>
				<td>
					<input type="number" name="rmm_epinephrine" id="rmm_epinephrine" value="<?php echo esc_attr( $epinephrine ); ?>" min="0" class="small-text" />
				</td>
			</tr>
			<tr>
				<th><label for="rmm_dist_walked"><?php _e( 'Distancia a pie (m)', 'reforger-milsim' ); ?></label></th>
				<td>
					<input type="number" name="rmm_dist_walked" id="rmm_dist_walked" value="<?php echo esc_attr( $dist_walked ); ?>" min="0" class="regular-text" />
				</td>
			</tr>
			<tr>
				<th><label for="rmm_dist_vehicle"><?php _e( 'Distancia en vehiculo (m)', 'reforger-milsim' ); ?></label></th>
				<td>
					<input type="number" name="rmm_dist_vehicle" id="rmm_dist_vehicle" value="<?php echo esc_attr( $dist_vehicle ); ?>" min="0" class="regular-text" />
				</td>
			</tr>
			<tr>
				<th><label for="rmm_veh_destroyed"><?php _e( 'Vehiculos destruidos', 'reforger-milsim' ); ?></label></th>
				<td>
					<input type="number" name="rmm_veh_destroyed" id="rmm_veh_destroyed" value="<?php echo esc_attr( $veh_destroyed ); ?>" min="0" class="small-text" />
				</td>
			</tr>
			<tr>
				<th><label for="rmm_veh_air"><?php _e( 'Aeronaves derribadas', 'reforger-milsim' ); ?></label></th>
				<td>
					<input type="number" name="rmm_veh_air" id="rmm_veh_air" value="<?php echo esc_attr( $veh_air ); ?>" min="0" class="small-text" />
				</td>
			</tr>
			<tr>
				<th><label for="rmm_explosives"><?php _e( 'Explosivos detonados', 'reforger-milsim' ); ?></label></th>
				<td>
					<input type="number" name="rmm_explosives" id="rmm_explosives" value="<?php echo esc_attr( $explosives ); ?>" min="0" class="small-text" />
				</td>
			</tr>
		</table>

		<h3><?php _e( 'Cronologia de Carrera Militar', 'reforger-milsim' ); ?></h3>
		
		<div id="rmm-timeline-manager" data-user-id="<?php echo $user->ID; ?>" style="max-width:800px;">
			
			<!-- Formulario rapido para añadir -->
			<div style="background:#fff; border:1px solid #ccd0d4; border-radius:4px; padding:12px 15px; margin-bottom:15px;">
				<div style="display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap;">
					<div>
						<label style="display:block; font-weight:600; margin-bottom:2px; font-size:12px;">Fecha</label>
						<input type="datetime-local" id="rmm-new-entry-date" style="width:200px;">
					</div>
					<div>
						<label style="display:block; font-weight:600; margin-bottom:2px; font-size:12px;">Tipo</label>
						<select id="rmm-new-entry-type" style="width:180px;">
							<option value="event">Evento / Participacion</option>
							<option value="promotion">Promocion / Ascenso</option>
							<option value="training">Formacion / Curso</option>
							<option value="award">Condecoracion / Reconocimiento</option>
							<option value="other">Otro</option>
						</select>
					</div>
					<div style="flex:1; min-width:200px;">
						<label style="display:block; font-weight:600; margin-bottom:2px; font-size:12px;">Descripcion</label>
						<input type="text" id="rmm-new-entry-desc" style="width:100%;" placeholder="Ej: Participo en la Operacion Tormenta del Desierto">
					</div>
					<div>
						<button type="button" id="rmm-add-entry-btn" class="button button-primary" style="white-space:nowrap;">+ Anadir</button>
					</div>
				</div>
				<div id="rmm-add-feedback" style="margin-top:8px; font-size:12px; display:none;"></div>
			</div>
			
			<!-- Lista de entradas -->
			<div id="rmm-timeline-list" style="background:#f6f7f7; padding:15px; border-left:4px solid #849b4c; max-height:400px; overflow-y:auto;">
				<?php if ( ! empty( $history ) && is_array( $history ) ) : ?>
					<table style="width:100%; border-collapse:collapse; font-size:13px;">
						<?php foreach ( array_reverse($history) as $index => $change ) : 
							$entry_id = $change['date'] . '_' . $index;
							$hidden_entries = get_user_meta( $user->ID, 'rmm_hidden_timeline', true ) ?: array();
							$is_hidden = in_array( $entry_id, $hidden_entries );
							$entry_type = $change['type'] ?? 'role';
							
							// Texto de la entrada
							if ( $entry_type === 'role' || ! isset($change['type']) ) {
								if ( ! empty($change['from']) ) {
									$entry_text = 'De ' . $change['from'] . ' a ' . ($change['to'] ?? '');
								} else {
									$entry_text = 'Asignado rol ' . ($change['to'] ?? '');
								}
							} else {
								$entry_text = '[' . $entry_type . '] ' . ($change['desc'] ?? '');
							}
							?>
							<tr data-entry-id="<?php echo esc_attr( $entry_id ); ?>" style="<?php echo $is_hidden ? 'opacity:0.4;' : ''; ?> border-bottom:1px solid #e5e5e5;">
								<td style="padding:6px 4px; white-space:nowrap; font-weight:600; color:#555; width:110px;"><?php echo esc_html( date('d/m/Y H:i', strtotime($change['date'])) ); ?></td>
								<td style="padding:6px 4px; <?php echo $is_hidden ? 'text-decoration:line-through;' : ''; ?>"><?php echo esc_html( $entry_text ); ?></td>
								<td style="padding:6px 4px; color:#888; font-size:11px; white-space:nowrap;">por <?php echo esc_html( $change['by'] ?? '' ); ?></td>
								<td style="padding:6px 4px; white-space:nowrap; text-align:right;">
									<button type="button" class="rmm-toggle-btn button button-small" title="<?php echo $is_hidden ? 'Mostrar en perfil publico' : 'Ocultar del perfil publico'; ?>"><?php echo $is_hidden ? 'Mostrar' : 'Ocultar'; ?></button>
									<button type="button" class="rmm-delete-btn button button-small button-link-delete" title="Eliminar permanentemente">Borrar</button>
								</td>
							</tr>
						<?php endforeach; ?>
					</table>
				<?php else : ?>
					<p class="description" style="margin:0;">No hay entradas en la cronologia todavia.</p>
				<?php endif; ?>
			</div>
		</div>
		<?php endif; ?>
		<?php
	}

	/**
	 * SAVE: Guardar los campos extra del perfil y detectar cambios de rol
	 */
	public function save_user_profile_fields( $user_id ) {
		if ( ! current_user_can( 'edit_user', $user_id ) ) return false;
		
		// Solo admin o fundador pueden guardar stats y timeline
		$current = wp_get_current_user();
		$can_edit_tactical = current_user_can( 'manage_options' ) || in_array( 'fundador', (array) $current->roles );
		
		// Info básica (SteamID, BohemiaUID) — todos los editores
		if ( isset( $_POST['steamid_64'] ) ) {
			update_user_meta( $user_id, 'steamid_64', sanitize_text_field( $_POST['steamid_64'] ) );
		}
		if ( isset( $_POST['bohemia_uid'] ) ) {
			update_user_meta( $user_id, 'bohemia_uid', sanitize_text_field( $_POST['bohemia_uid'] ) );
		}
		
		// Stats de combate, enrolamiento y detección de roles — solo admin/fundador
		if ( ! $can_edit_tactical ) return;
		
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
					update_user_meta( $user_id, 'rmm_hours', round( floatval( $_POST['rmm_hours'] ), 4 ) );
				}
		if ( isset( $_POST['rmm_shots_fired'] ) ) {
			update_user_meta( $user_id, 'rmm_shots_fired', intval( $_POST['rmm_shots_fired'] ) );
		}
		if ( isset( $_POST['rmm_shots_hit'] ) ) {
			update_user_meta( $user_id, 'rmm_shots_hit', intval( $_POST['rmm_shots_hit'] ) );
		}
		if ( isset( $_POST['rmm_bandages'] ) ) {
			update_user_meta( $user_id, 'rmm_bandages', intval( $_POST['rmm_bandages'] ) );
		}
		if ( isset( $_POST['rmm_tourniquets'] ) ) {
			update_user_meta( $user_id, 'rmm_tourniquets', intval( $_POST['rmm_tourniquets'] ) );
		}
		if ( isset( $_POST['rmm_saline'] ) ) {
			update_user_meta( $user_id, 'rmm_saline', intval( $_POST['rmm_saline'] ) );
		}
		if ( isset( $_POST['rmm_morphine'] ) ) {
			update_user_meta( $user_id, 'rmm_morphine', intval( $_POST['rmm_morphine'] ) );
		}
		if ( isset( $_POST['rmm_epinephrine'] ) ) {
			update_user_meta( $user_id, 'rmm_epinephrine', intval( $_POST['rmm_epinephrine'] ) );
		}
		if ( isset( $_POST['rmm_dist_walked'] ) ) {
			update_user_meta( $user_id, 'rmm_dist_walked', intval( $_POST['rmm_dist_walked'] ) );
		}
		if ( isset( $_POST['rmm_dist_vehicle'] ) ) {
			update_user_meta( $user_id, 'rmm_dist_vehicle', intval( $_POST['rmm_dist_vehicle'] ) );
		}
		if ( isset( $_POST['rmm_veh_destroyed'] ) ) {
			update_user_meta( $user_id, 'rmm_veh_destroyed', intval( $_POST['rmm_veh_destroyed'] ) );
		}
		if ( isset( $_POST['rmm_veh_air'] ) ) {
			update_user_meta( $user_id, 'rmm_veh_air', intval( $_POST['rmm_veh_air'] ) );
		}
		if ( isset( $_POST['rmm_explosives'] ) ) {
			update_user_meta( $user_id, 'rmm_explosives', intval( $_POST['rmm_explosives'] ) );
		}
		
		// Detectar cambios de rol (compatible con Members plugin que usa $_POST['role'] como array)
		$user = get_userdata( $user_id );
		if ( ! $user ) return;
		
		$old_roles = $user->roles;
		$new_roles = array();
		
		if ( isset( $_POST['role'] ) && is_array( $_POST['role'] ) ) {
			$new_roles = array_map( 'sanitize_text_field', $_POST['role'] );
		} elseif ( isset( $_POST['role'] ) && is_string( $_POST['role'] ) ) {
			$new_roles = array( sanitize_text_field( $_POST['role'] ) );
		}
		
		if ( empty( $new_roles ) ) {
			return; // No se enviaron roles, no hay cambios que registrar
		}
		
		$added   = array_diff( $new_roles, $old_roles );
		$removed = array_diff( $old_roles, $new_roles );
		
		if ( empty( $added ) && empty( $removed ) ) {
			return; // Sin cambios
		}
		
		$wp_roles  = wp_roles();
		$history   = get_user_meta( $user_id, 'rmm_role_history', true ) ?: array();
		$now       = current_time( 'mysql' );
		$editor    = wp_get_current_user();
		$editor_name = $editor ? $editor->display_name : __( 'Sistema', 'reforger-milsim' );
		
		// Registrar roles eliminados
		foreach ( $removed as $slug ) {
			$label = isset( $wp_roles->role_names[ $slug ] ) ? translate_user_role( $wp_roles->role_names[ $slug ] ) : $slug;
			$history[] = array(
				'date' => $now,
				'from' => $label,
				'to'   => __( 'Sin rol', 'reforger-milsim' ),
				'by'   => $editor_name,
			);
		}
		
		// Registrar roles añadidos
		foreach ( $added as $slug ) {
			$label = isset( $wp_roles->role_names[ $slug ] ) ? translate_user_role( $wp_roles->role_names[ $slug ] ) : $slug;
			$previous = ! empty( $old_roles ) ? ( isset( $wp_roles->role_names[ $old_roles[0] ] ) ? translate_user_role( $wp_roles->role_names[ $old_roles[0] ] ) : $old_roles[0] ) : '';
			$history[] = array(
				'date' => $now,
				'from' => $previous,
				'to'   => $label,
				'by'   => $editor_name,
			);
		}
		
		update_user_meta( $user_id, 'rmm_role_history', $history );
	}
	
	/**
	// AJAX: Alternar visibilidad de una entrada del timeline
	public function ajax_toggle_timeline_entry() {
		if ( ! current_user_can( 'edit_user', intval( $_POST['user_id'] ) ) ) {
			wp_send_json_error( __( 'Permiso denegado', 'reforger-milsim' ) );
		}
		if ( ! $this->current_user_is_admin_or_fundador() ) {
			wp_send_json_error( __( 'Solo admin o fundador', 'reforger-milsim' ) );
		}
		
		$user_id  = intval( $_POST['user_id'] );
		$entry_id = sanitize_text_field( $_POST['entry_id'] );
		
		if ( ! $user_id || empty( $entry_id ) ) {
			wp_send_json_error( __( 'Datos invalidos', 'reforger-milsim' ) );
		}
		
		$hidden = get_user_meta( $user_id, 'rmm_hidden_timeline', true ) ?: array();
		
		if ( in_array( $entry_id, $hidden ) ) {
			$hidden = array_values( array_diff( $hidden, array( $entry_id ) ) );
			$action = 'shown';
		} else {
			$hidden[] = $entry_id;
			$action = 'hidden';
		}
		
		update_user_meta( $user_id, 'rmm_hidden_timeline', $hidden );
		wp_send_json_success( array( 'action' => $action ) );
	}
	
	/**
	 * AJAX: Anadir entrada manual al timeline
	 */
	public function ajax_add_timeline_entry() {
		if ( ! current_user_can( 'edit_user', intval( $_POST['user_id'] ) ) ) {
			wp_send_json_error( __( 'Permiso denegado', 'reforger-milsim' ) );
		}
		if ( ! $this->current_user_is_admin_or_fundador() ) {
			wp_send_json_error( __( 'Solo admin o fundador', 'reforger-milsim' ) );
		}
		
		$user_id = intval( $_POST['user_id'] );
		$date    = sanitize_text_field( $_POST['date'] ?? '' );
		$type    = sanitize_text_field( $_POST['type'] ?? 'event' );
		$desc    = sanitize_text_field( $_POST['desc'] ?? '' );
		
		if ( ! $user_id || empty( $date ) || empty( $desc ) ) {
			wp_send_json_error( __( 'Fecha y descripcion son obligatorios', 'reforger-milsim' ) );
		}
		
		$history  = get_user_meta( $user_id, 'rmm_role_history', true ) ?: array();
		$editor   = wp_get_current_user();
		$by       = $editor ? $editor->display_name : __( 'Sistema', 'reforger-milsim' );
		
		$history[] = array(
			'date' => date( 'Y-m-d H:i:s', strtotime( $date ) ),
			'type' => $type,
			'desc' => $desc,
			'by'   => $by,
		);
		
		update_user_meta( $user_id, 'rmm_role_history', $history );
		
		// Devolver el HTML de la nueva entrada
		$index    = count( $history ) - 1;
		$entry_id = $history[ $index ]['date'] . '_' . $index;
		$type_label = array(
			'event' => 'Evento', 'promotion' => 'Promocion', 'training' => 'Formacion',
			'award' => 'Reconocimiento', 'other' => 'Otro'
		);
		$entry_text = '[' . ( $type_label[$type] ?? $type ) . '] ' . $desc;
		
		ob_start();
		?>
		<tr data-entry-id="<?php echo esc_attr( $entry_id ); ?>" style="border-bottom:1px solid #e5e5e5;">
			<td style="padding:6px 4px; white-space:nowrap; font-weight:600; color:#555; width:110px;"><?php echo esc_html( date('d/m/Y H:i', strtotime($date)) ); ?></td>
			<td style="padding:6px 4px;"><?php echo esc_html( $entry_text ); ?></td>
			<td style="padding:6px 4px; color:#888; font-size:11px; white-space:nowrap;">por <?php echo esc_html( $by ); ?></td>
			<td style="padding:6px 4px; white-space:nowrap; text-align:right;">
				<button type="button" class="rmm-toggle-btn button button-small" title="Ocultar del perfil publico">Ocultar</button>
				<button type="button" class="rmm-delete-btn button button-small button-link-delete" title="Eliminar permanentemente">Borrar</button>
			</td>
		</tr>
		<?php
		$html = ob_get_clean();
		
		wp_send_json_success( array( 'html' => $html, 'entry_id' => $entry_id ) );
	}
	
	/**
	 * AJAX: Eliminar una entrada del timeline
	 */
	public function ajax_delete_timeline_entry() {
		if ( ! current_user_can( 'edit_user', intval( $_POST['user_id'] ) ) ) {
			wp_send_json_error( __( 'Permiso denegado', 'reforger-milsim' ) );
		}
		if ( ! $this->current_user_is_admin_or_fundador() ) {
			wp_send_json_error( __( 'Solo admin o fundador', 'reforger-milsim' ) );
		}
		
		$user_id  = intval( $_POST['user_id'] );
		$entry_id = sanitize_text_field( $_POST['entry_id'] );
		
		if ( ! $user_id || empty( $entry_id ) ) {
			wp_send_json_error( __( 'Datos invalidos', 'reforger-milsim' ) );
		}
		
		// Parsear el entry_id: "YYYY-MM-DD HH:MM:SS_index"
		$parts = explode( '_', $entry_id );
		$index = intval( array_pop( $parts ) );
		
		$history = get_user_meta( $user_id, 'rmm_role_history', true ) ?: array();
		
		if ( isset( $history[ $index ] ) ) {
			unset( $history[ $index ] );
			$history = array_values( $history ); // Reindexar
			update_user_meta( $user_id, 'rmm_role_history', $history );
			
			// Limpiar de hidden
			$hidden = get_user_meta( $user_id, 'rmm_hidden_timeline', true ) ?: array();
			$hidden = array_values( array_diff( $hidden, array( $entry_id ) ) );
			update_user_meta( $user_id, 'rmm_hidden_timeline', $hidden );
			
			wp_send_json_success();
		}
		
		wp_send_json_error( __( 'Entrada no encontrada', 'reforger-milsim' ) );
	}
	
	/**
	 * Inyectar JS para el gestor de timeline en el perfil de usuario (admin)
	 */
	public function inject_timeline_js() {
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->base, array( 'user-edit', 'profile' ) ) ) {
			return;
		}
		?>
		<script>
		jQuery(document).ready(function($) {
			var manager = $('#rmm-timeline-manager');
			if ( ! manager.length ) return;
			
			var userId  = manager.data('user-id');
			var list    = $('#rmm-timeline-list');
			var feedback = $('#rmm-add-feedback');
			
			function showFeedback(msg, isError) {
				feedback.text(msg).css('color', isError ? '#d63638' : '#00a32a').show().delay(3000).fadeOut();
			}
			
			// === ANADIR ENTRADA ===
			$('#rmm-add-entry-btn').on('click', function() {
				var btn   = $(this);
				var date  = $('#rmm-new-entry-date').val();
				var type  = $('#rmm-new-entry-type').val();
				var desc  = $('#rmm-new-entry-desc').val().trim();
				
				if ( ! date || ! desc ) {
					showFeedback('Fecha y descripcion son obligatorios', true);
					return;
				}
				
				btn.prop('disabled', true).text('Guardando...');
				
				$.post(ajaxurl, {
					action: 'rmm_add_timeline_entry',
					user_id: userId,
					date: date,
					type: type,
					desc: desc
				}, function(res) {
					if (res.success) {
						// Insertar al principio de la tabla
						var table = list.find('table');
						if ( table.length ) {
							table.prepend(res.data.html);
						} else {
							list.html('<table style="width:100%; border-collapse:collapse; font-size:13px;">' + res.data.html + '</table>');
						}
						$('#rmm-new-entry-desc').val('');
						showFeedback('Entrada anadida', false);
					} else {
						showFeedback('Error: ' + (res.data || 'Desconocido'), true);
					}
					btn.prop('disabled', false).text('+ Anadir');
				});
			});
			
			// === TOGGLE MOSTRAR/OCULTAR ===
			list.on('click', '.rmm-toggle-btn', function() {
				var btn     = $(this);
				var row     = btn.closest('tr');
				var entryId = row.data('entry-id');
				
				btn.prop('disabled', true);
				
				$.post(ajaxurl, {
					action: 'rmm_toggle_timeline_entry',
					user_id: userId,
					entry_id: entryId
				}, function(res) {
					if (res.success) {
						if (res.data.action === 'hidden') {
							row.css('opacity', '0.4');
							row.find('td').eq(1).css('textDecoration', 'line-through');
							btn.text('Mostrar').attr('title', 'Mostrar en perfil publico');
						} else {
							row.css('opacity', '1');
							row.find('td').eq(1).css('textDecoration', 'none');
							btn.text('Ocultar').attr('title', 'Ocultar del perfil publico');
						}
					}
					btn.prop('disabled', false);
				});
			});
			
			// === BORRAR ENTRADA ===
			list.on('click', '.rmm-delete-btn', function() {
				var btn     = $(this);
				var row     = btn.closest('tr');
				var entryId = row.data('entry-id');
				
				if ( ! confirm('Eliminar esta entrada permanentemente?') ) return;
				
				btn.prop('disabled', true);
				
				$.post(ajaxurl, {
					action: 'rmm_delete_timeline_entry',
					user_id: userId,
					entry_id: entryId
				}, function(res) {
					if (res.success) {
						row.slideUp(200, function() {
							row.remove();
							if ( ! list.find('tr').length ) {
								list.html('<p class="description" style="margin:0;">No hay entradas en la cronologia todavia.</p>');
							}
						});
					} else {
						alert('Error: ' + (res.data || 'No se pudo eliminar'));
						btn.prop('disabled', false);
					}
				});
			});
		});
		</script>
		<?php
	}
	
	/**
	 * Verificar si el usuario actual es admin o fundador
	 */
	private function current_user_is_admin_or_fundador() {
		$user = wp_get_current_user();
		return current_user_can( 'manage_options' ) || in_array( 'fundador', (array) $user->roles );
	}
}

