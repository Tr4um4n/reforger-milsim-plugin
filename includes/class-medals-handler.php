<?php
/**
 * Medals & Ribbon Rack Handler Class
 * @package ReforgerMilsimManagement
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class RMM_Medals_Handler {

	public function __construct() {
		// Bloque 2: Estandarización de Imágenes
		add_action( 'init', array( $this, 'register_image_sizes' ) );
		
		// Bloque 1: Metabox de Prioridad Visual
		add_action( 'add_meta_boxes', array( $this, 'add_priority_metabox' ) );
		add_action( 'save_post', array( $this, 'save_priority_metabox' ) );

		// Bloque 3: Interfaz de Otorgamiento Manual (Backend)
		add_action( 'admin_menu', array( $this, 'register_medal_submenu' ) );
		
		// Bloque 4: El Pasador de Diario - Ribbon Rack (Frontend)
		add_shortcode( 'clan_pasador_medallas', array( $this, 'render_ribbon_rack' ) );

		// Nuevos Shortcodes para Listado y Perfil de Miembros
		add_shortcode( 'clan_lista_miembros', array( $this, 'render_members_list' ) );
		add_shortcode( 'clan_perfil_operador', array( $this, 'render_operator_profile_shortcode' ) );
	}

	/**
	 * Bloque 2: Estandarización de Imágenes
	 */
	public function register_image_sizes() {
		add_image_size( 'metopa-militar', 120, 35, true );
	}

	/**
	 * Bloque 1: Metabox de Prioridad Visual
	 */
	public function add_priority_metabox() {
		add_meta_box(
			'rmm_medal_priority',
			__( 'Jerarquía Militar', 'reforger-milsim' ),
			array( $this, 'render_priority_metabox' ),
			'condecoraciones',
			'side',
			'default'
		);
	}

	public function render_priority_metabox( $post ) {
		$prioridad = get_post_meta( $post->ID, 'prioridad_visual', true );
		if ( $prioridad === '' ) $prioridad = 99; // Por defecto 99
		wp_nonce_field( 'rmm_save_medal_priority', 'rmm_medal_priority_nonce' );
		?>
		<p>
			<label for="prioridad_visual"><?php _e( 'Prioridad Visual (1 = Más alta):', 'reforger-milsim' ); ?></label>
			<input type="number" id="prioridad_visual" name="prioridad_visual" value="<?php echo esc_attr( $prioridad ); ?>" min="1" max="999" class="widefat">
		</p>
		<p class="description"><?php _e( 'Sirve para ordenar el pasador (ej. 1 es la medalla más alta, 99 la más baja).', 'reforger-milsim' ); ?></p>
		<?php
	}

	public function save_priority_metabox( $post_id ) {
		if ( ! isset( $_POST['rmm_medal_priority_nonce'] ) || ! wp_verify_nonce( $_POST['rmm_medal_priority_nonce'], 'rmm_save_medal_priority' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( isset( $_POST['prioridad_visual'] ) ) {
			update_post_meta( $post_id, 'prioridad_visual', intval( $_POST['prioridad_visual'] ) );
		}
	}

	/**
	 * Bloque 3: Interfaz de Otorgamiento Manual (Backend)
	 */
	public function register_medal_submenu() {
		add_submenu_page(
			'edit.php?post_type=condecoraciones',
			__( 'Otorgar Medalla', 'reforger-milsim' ),
			__( 'Otorgar Medalla', 'reforger-milsim' ),
			'manage_options',
			'otorgar-medalla',
			array( $this, 'render_award_medal_page' )
		);
	}

	public function render_award_medal_page() {
		if ( isset( $_POST['rmm_award_medal_nonce'] ) && wp_verify_nonce( $_POST['rmm_award_medal_nonce'], 'rmm_award_medal_action' ) ) {
			$this->process_manual_award();
		}

		$medallas = get_posts( array( 'post_type' => 'condecoraciones', 'numberposts' => -1 ) );
		?>
		<div class="wrap">
			<h1><?php _e( 'Otorgar Medalla al Operador', 'reforger-milsim' ); ?></h1>
			<form method="post" style="max-width: 600px; background: #fff; padding: 20px; border: 1px solid #ccd0d4; margin-top: 20px;">
				<?php wp_nonce_field( 'rmm_award_medal_action', 'rmm_award_medal_nonce' ); ?>
				<p>
					<label><strong>Operador:</strong></label><br>
					<?php wp_dropdown_users( array( 'name' => 'usuario_id', 'class' => 'widefat' ) ); ?>
				</p>
				<p>
					<label><strong>Condecoración:</strong></label><br>
					<select name="condecoracion_id" class="widefat" required>
						<option value="">-- Selecciona Medalla --</option>
						<?php foreach ( $medallas as $m ) : ?>
							<option value="<?php echo $m->ID; ?>"><?php echo esc_html( $m->post_title ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
				<p>
					<label><strong>Motivo de la Citación:</strong></label><br>
					<textarea name="motivo" class="widefat" rows="5" required placeholder="Motivo..."></textarea>
				</p>
				<p>
					<button type="submit" class="button button-primary">Confirmar Otorgamiento</button>
				</p>
			</form>
		</div>
		<?php
	}

	private function process_manual_award() {
		global $wpdb;
		$table = $wpdb->prefix . 'operador_condecoraciones';
		$wpdb->insert(
			$table,
			array(
				'usuario_id'            => intval( $_POST['usuario_id'] ),
				'condecoracion_id'      => intval( $_POST['condecoracion_id'] ),
				'motivo'                => sanitize_textarea_field( $_POST['motivo'] ),
				'otorgada_por_admin_id' => get_current_user_id(),
				'fecha_obtenida'        => current_time( 'mysql' ),
			)
		);
		echo '<div class="notice notice-success is-dismissible"><p>Condecoración otorgada con éxito.</p></div>';
	}

	/**
	 * Bloque 4: El Pasador de Diario - Ribbon Rack (Frontend)
	 */
	public function render_ribbon_rack( $atts ) {
		global $wpdb;
		$atts = shortcode_atts( array( 'user_id' => '' ), $atts );
		$user_id = !empty($atts['user_id']) ? intval($atts['user_id']) : get_current_user_id();
		
		if ( ! $user_id ) return '';

		$query = $wpdb->prepare(
			"SELECT oc.motivo, p.ID, p.post_title FROM {$wpdb->prefix}operador_condecoraciones oc
			 JOIN {$wpdb->posts} p ON oc.condecoracion_id = p.ID
			 LEFT JOIN {$wpdb->postmeta} pm ON oc.condecoracion_id = pm.post_id AND pm.meta_key = 'prioridad_visual'
			 WHERE oc.usuario_id = %d
			 ORDER BY CAST(COALESCE(pm.meta_value, 999) AS UNSIGNED) ASC, oc.fecha_obtenida DESC",
			$user_id
		);

		$medals = $wpdb->get_results( $query );
		if ( empty($medals) ) return '';

		ob_start();
		?>
		<div class="rmm-ribbon-rack-container" style="margin-top: 10px;">
			<div class="grid grid-cols-6 gap-0 max-w-fit bg-gray-900 border-2 border-gray-900 shadow-md">
				<?php foreach ( $medals as $m ) : 
					$thumb_url = get_the_post_thumbnail_url( $m->ID, 'metopa-militar' );
					if ( !$thumb_url ) $thumb_url = 'https://via.placeholder.com/120x35?text=Sin+Imagen';
					?>
					<a href="<?php echo esc_url( get_permalink( $m->ID ) ); ?>">
						<img src="<?php echo esc_url($thumb_url); ?>" 
							 title="<?php echo esc_attr( $m->post_title . ' - ' . $m->motivo ); ?>"
							 class="w-full h-auto block object-cover"
							 style="width:120px; height:35px;" 
						>
					</a>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Shortcode: Listado de Miembros del Clan [clan_lista_miembros]
	 */
	public function render_members_list( $atts ) {
		global $wpdb;
		
		// Si está activado el parámetro de ver operador, renderizamos el perfil detallado del operador en su lugar
		if ( isset( $_GET['operator_id'] ) ) {
			return $this->render_operator_profile( intval( $_GET['operator_id'] ) );
		}

		$a = shortcode_atts( array(
			'profile_url' => '', // URL opcional si tienen una página propia para el perfil, si no se recarga la misma
		), $atts );

		// Obtener usuarios con roles militares activos
		$users = get_users( array(
			'role__in' => array( 'fundador', 'activo', 'recluta', 'reservista', 'veterano', 'aliado', 'administrator' ),
			'orderby'  => 'display_name',
			'order'    => 'ASC'
		) );

		if ( empty( $users ) ) {
			return '<p class="text-gray-400">' . __( 'No se encontraron operadores activos.', 'reforger-milsim' ) . '</p>';
		}

		$user_ids = wp_list_pluck( $users, 'ID' );
		$user_ids_placeholder = implode( ',', array_map( 'intval', $user_ids ) );

		// 1. Consulta optimizada de Medallas para todos los usuarios listados
		$medals_results = $wpdb->get_results(
			"SELECT oc.usuario_id, oc.motivo, oc.fecha_obtenida, p.ID as medal_id, p.post_title
			 FROM {$wpdb->prefix}operador_condecoraciones oc
			 JOIN {$wpdb->posts} p ON oc.condecoracion_id = p.ID
			 LEFT JOIN {$wpdb->postmeta} pm ON oc.condecoracion_id = pm.post_id AND pm.meta_key = 'prioridad_visual'
			 WHERE oc.usuario_id IN ($user_ids_placeholder)
			 ORDER BY CAST(COALESCE(pm.meta_value, 999) AS UNSIGNED) ASC, oc.fecha_obtenida DESC"
		);

		$all_user_medals = array();
		foreach ( $medals_results as $row ) {
			$all_user_medals[$row->usuario_id][] = $row;
		}

		// 2. Consulta optimizada de Asistencias (eventos jugados/asistidos)
		$attendance_results = $wpdb->get_results(
			"SELECT usuario_id, count(*) as count 
			 FROM {$wpdb->prefix}registro_operadores 
			 WHERE estado_asistencia = 'presente' AND usuario_id IN ($user_ids_placeholder) 
			 GROUP BY usuario_id"
		);

		$all_user_attendance = array();
		foreach ( $attendance_results as $row ) {
			$all_user_attendance[$row->usuario_id] = $row->count;
		}

		// 3. Consulta optimizada de Rol Preferido
		$roles_results = $wpdb->get_results(
			"SELECT usuario_id, rol_jugado, count(*) as cnt 
			 FROM {$wpdb->prefix}registro_operadores 
			 WHERE estado_asistencia = 'presente' AND rol_jugado != '' AND usuario_id IN ($user_ids_placeholder) 
			 GROUP BY usuario_id, rol_jugado 
			 ORDER BY cnt DESC"
		);

		$all_user_pref_roles = array();
		foreach ( $roles_results as $row ) {
			if ( ! isset( $all_user_pref_roles[$row->usuario_id] ) ) {
				$all_user_pref_roles[$row->usuario_id] = $row->rol_jugado;
			}
		}

		ob_start();
		?>
		<div class="rmm-operators-grid-wrapper rmm-dark-theme" style="font-family: 'Inter', sans-serif;">
			<div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-6">
				<?php foreach ( $users as $user ) : 
					$uid = $user->ID;
					$medals = isset( $all_user_medals[$uid] ) ? $all_user_medals[$uid] : array();
					
					// Estadísticas para el overlay
					$kills        = intval( get_user_meta( $uid, 'rmm_kills', true ) ?: 0 );
					$deaths       = intval( get_user_meta( $uid, 'rmm_deaths', true ) ?: 0 );
					$hours        = intval( get_user_meta( $uid, 'rmm_hours', true ) ?: 0 );
					$shots_fired  = intval( get_user_meta( $uid, 'rmm_shots_fired', true ) ?: 0 );
					$shots_hit    = intval( get_user_meta( $uid, 'rmm_shots_hit', true ) ?: 0 );
					
					$attendance   = isset( $all_user_attendance[$uid] ) ? intval( $all_user_attendance[$uid] ) : 0;
					$pref_role    = isset( $all_user_pref_roles[$uid] ) ? $all_user_pref_roles[$uid] : __( 'No definido', 'reforger-milsim' );
					
					$kd_ratio     = number_format( $kills / ( $deaths > 0 ? $deaths : 1 ), 2 );
					$accuracy     = $shots_fired > 0 ? number_format( ($shots_hit / $shots_fired) * 100, 1 ) . '%' : '0%';
					$enrol_date   = get_user_meta( $uid, 'rmm_enrolment_date', true );
					$enrol_date_f = !empty( $enrol_date ) ? date('d/m/Y', strtotime($enrol_date)) : __( 'No registrada', 'reforger-milsim' );

					// Obtener rol militar principal
					$wp_roles = wp_roles();
					$main_role = 'visitante';
					foreach ( $user->roles as $r ) {
						if ( in_array( $r, array( 'fundador', 'activo', 'recluta', 'reservista', 'veterano', 'aliado', 'administrator' ) ) ) {
							$main_role = $r;
							break;
						}
					}
					$role_name = isset( $wp_roles->role_names[$main_role] ) ? translate_user_role( $wp_roles->role_names[$main_role] ) : ucfirst($main_role);
					
					// Configurar enlace del perfil
					$profile_link = !empty($a['profile_url']) ? esc_url( add_query_arg( 'operator_id', $uid, $a['profile_url'] ) ) : esc_url( add_query_arg( 'operator_id', $uid ) );
					?>
					
					<!-- Card de Operador -->
					<a href="<?php echo $profile_link; ?>" class="rmm-operator-card group relative block overflow-hidden rounded-lg bg-gray-900 border border-gray-800 p-5 shadow-lg transition-all duration-300 hover:border-green-600/50 hover:shadow-green-950/20" style="text-decoration:none; color:inherit; min-height: 250px;">
						<div class="flex flex-col items-center text-center h-full justify-between">
							
							<!-- Avatar, Nombre y Rango -->
							<div class="w-full flex flex-col items-center">
								<div class="relative mb-3">
									<?php echo get_avatar( $uid, 80, '', '', array( 'class' => 'rounded-full border-2 border-green-700 object-cover shadow-md w-20 h-20' ) ); ?>
									<span class="absolute bottom-0 right-0 h-4 w-4 rounded-full border-2 border-gray-900 bg-green-500" title="Activo"></span>
								</div>
								<h3 class="text-lg font-bold text-gray-100 group-hover:text-green-400 transition-colors uppercase tracking-wide mb-0 mt-1"><?php echo esc_html( $user->display_name ); ?></h3>
								<span class="text-xs font-bold text-gray-500 uppercase tracking-widest mt-1 mb-3" style="color: #849b4c;"><?php echo esc_html( $role_name ); ?></span>
							</div>

							<!-- Pasador de Medallas (Ribbons) -->
							<div class="mt-auto w-full flex justify-center">
								<?php if ( ! empty($medals) ) : ?>
									<div class="grid grid-cols-3 gap-0.5 max-w-fit bg-gray-950 border border-gray-950 p-0.5 shadow-sm">
										<?php 
										$count = 0;
										foreach ( $medals as $m ) {
											if ( $count >= 6 ) break; // Mostrar max 6 en la tarjeta
											$thumb_url = get_the_post_thumbnail_url( $m->medal_id, 'metopa-militar' );
											if ( !$thumb_url ) $thumb_url = 'https://via.placeholder.com/120x35?text=Medalla';
											?>
											<img src="<?php echo esc_url($thumb_url); ?>" class="w-[60px] h-[17px] block object-cover" title="<?php echo esc_attr( $m->post_title ); ?>">
											<?php
											$count++;
										}
										?>
									</div>
								<?php else : ?>
									<span class="text-[10px] uppercase font-bold text-gray-700 tracking-wider"><?php _e( 'Sin condecoraciones', 'reforger-milsim' ); ?></span>
								<?php endif; ?>
							</div>
						</div>

						<!-- Overlay Táctico en Hover -->
						<div class="absolute inset-0 flex flex-col justify-between bg-gray-950/95 p-5 opacity-0 transition-opacity duration-300 group-hover:opacity-100 border border-green-600/50 rounded-lg">
							<div class="text-left w-full">
								<h4 class="text-sm font-bold text-green-400 uppercase tracking-wider border-b border-gray-800 pb-1.5 mb-3 flex items-center justify-between">
									<span>📂 DOSSIER MILITAR</span>
									<span class="text-[9px] text-gray-500">ID: <?php echo $uid; ?></span>
								</h4>
								<div class="grid grid-cols-2 gap-x-3 gap-y-2 text-xs">
									<div>
										<span class="block text-gray-500 uppercase text-[9px] tracking-wider"><?php _e( 'Partidas', 'reforger-milsim' ); ?></span>
										<strong class="text-gray-200"><?php echo $attendance; ?></strong>
									</div>
									<div>
										<span class="block text-gray-500 uppercase text-[9px] tracking-wider"><?php _e( 'Rol Preferido', 'reforger-milsim' ); ?></span>
										<strong class="text-gray-200 truncate block" title="<?php echo esc_attr($pref_role); ?>"><?php echo esc_html( $pref_role ); ?></strong>
									</div>
									<div>
										<span class="block text-gray-500 uppercase text-[9px] tracking-wider"><?php _e( 'Bajas / Muertes', 'reforger-milsim' ); ?></span>
										<strong class="text-gray-200"><?php echo "$kills / $deaths"; ?></strong>
									</div>
									<div>
										<span class="block text-gray-500 uppercase text-[9px] tracking-wider"><?php _e( 'K/D Ratio', 'reforger-milsim' ); ?></span>
										<strong class="text-gray-200"><?php echo $kd_ratio; ?></strong>
									</div>
									<div>
										<span class="block text-gray-500 uppercase text-[9px] tracking-wider"><?php _e( 'Precisión', 'reforger-milsim' ); ?></span>
										<strong class="text-gray-200"><?php echo $accuracy; ?></strong>
									</div>
									<div>
										<span class="block text-gray-500 uppercase text-[9px] tracking-wider"><?php _e( 'Horas', 'reforger-milsim' ); ?></span>
										<strong class="text-gray-200"><?php echo $hours; ?>h</strong>
									</div>
								</div>
								<div class="mt-3 pt-2 border-t border-gray-900 text-[10px]">
									<span class="text-gray-500 uppercase tracking-wider"><?php _e( 'Enlistado:', 'reforger-milsim' ); ?></span>
									<strong class="text-gray-300 ml-1"><?php echo $enrol_date_f; ?></strong>
								</div>
							</div>
							<div class="w-full text-center mt-3">
								<span class="inline-block w-full py-1.5 bg-green-900/40 text-green-400 border border-green-700/50 rounded text-xs font-bold uppercase tracking-wider hover:bg-green-800/60 transition-colors">
									<?php _e( 'Ver expediente completo', 'reforger-milsim' ); ?>
								</span>
							</div>
						</div>
					</a>
				<?php endforeach; ?>
			</div>
		</div>
		<style>
			.rmm-dark-theme {
				--tw-bg-opacity: 1;
				background-color: transparent;
			}
			.rmm-operator-card {
				transition: transform 0.25s ease, border-color 0.25s ease, box-shadow 0.25s ease;
			}
			.rmm-operator-card:hover {
				transform: translateY(-4px);
			}
		</style>
		<?php
		return ob_get_clean();
	}

	/**
	 * Shortcode: Perfil de un Operador [clan_perfil_operador]
	 */
	public function render_operator_profile_shortcode( $atts ) {
		$user_id = isset( $_GET['operator_id'] ) ? intval( $_GET['operator_id'] ) : get_current_user_id();
		if ( ! $user_id ) {
			return '<p class="text-gray-400">' . __( 'Debes iniciar sesión para ver tu perfil táctico.', 'reforger-milsim' ) . '</p>';
		}
		return $this->render_operator_profile( $user_id );
	}

	/**
	 * Renders detailed profile page for a single user (Exposicion grid)
	 */
	public function render_operator_profile( $user_id ) {
		global $wpdb;
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return '<p class="text-red-500">' . __( 'Operador no encontrado.', 'reforger-milsim' ) . '</p>';
		}

		// Obtener medallas detalladas del operador
		$medals = $wpdb->get_results( $wpdb->prepare(
			"SELECT oc.motivo, oc.fecha_obtenida, oc.otorgada_por_admin_id, p.ID as medal_id, p.post_title, p.post_content
			 FROM {$wpdb->prefix}operador_condecoraciones oc
			 JOIN {$wpdb->posts} p ON oc.condecoracion_id = p.ID
			 LEFT JOIN {$wpdb->postmeta} pm ON oc.condecoracion_id = pm.post_id AND pm.meta_key = 'prioridad_visual'
			 WHERE oc.usuario_id = %d
			 ORDER BY CAST(COALESCE(pm.meta_value, 999) AS UNSIGNED) ASC, oc.fecha_obtenida DESC",
			$user_id
		) );

		// Obtener asistencias
		$attendance = intval( $wpdb->get_var( $wpdb->prepare(
			"SELECT count(*) FROM {$wpdb->prefix}registro_operadores WHERE estado_asistencia = 'presente' AND usuario_id = %d",
			$user_id
		) ) );

		// Obtener rol preferido
		$pref_role = $wpdb->get_var( $wpdb->prepare(
			"SELECT rol_jugado FROM {$wpdb->prefix}registro_operadores 
			 WHERE estado_asistencia = 'presente' AND rol_jugado != '' AND usuario_id = %d 
			 GROUP BY rol_jugado ORDER BY count(*) DESC LIMIT 1",
			$user_id
		) ) ?: __( 'Ninguno registrado', 'reforger-milsim' );

		// Cargar estadísticas
		$kills        = intval( get_user_meta( $user_id, 'rmm_kills', true ) ?: 0 );
		$deaths       = intval( get_user_meta( $user_id, 'rmm_deaths', true ) ?: 0 );
		$hours        = intval( get_user_meta( $user_id, 'rmm_hours', true ) ?: 0 );
		$shots_fired  = intval( get_user_meta( $user_id, 'rmm_shots_fired', true ) ?: 0 );
		$shots_hit    = intval( get_user_meta( $user_id, 'rmm_shots_hit', true ) ?: 0 );
		$steamid_64   = get_user_meta( $user_id, 'steamid_64', true );
		$bohemia_uid  = get_user_meta( $user_id, 'bohemia_uid', true );
		$enrol_date   = get_user_meta( $user_id, 'rmm_enrolment_date', true );
		$enrol_date_f = !empty( $enrol_date ) ? date('d \d\e F \d\e Y', strtotime($enrol_date)) : __( 'No registrada', 'reforger-milsim' );
		
		$kd_ratio     = number_format( $kills / ( $deaths > 0 ? $deaths : 1 ), 2 );
		$accuracy     = $shots_fired > 0 ? number_format( ($shots_hit / $shots_fired) * 100, 1 ) . '%' : '0%';

		// Obtener rol militar principal
		$wp_roles = wp_roles();
		$main_role = 'visitante';
		foreach ( $user->roles as $r ) {
			if ( in_array( $r, array( 'fundador', 'activo', 'recluta', 'reservista', 'veterano', 'aliado', 'administrator' ) ) ) {
				$main_role = $r;
				break;
			}
		}
		$role_name = isset( $wp_roles->role_names[$main_role] ) ? translate_user_role( $wp_roles->role_names[$main_role] ) : ucfirst($main_role);
		
		// Obtener historial de roles (timeline)
		$history = get_user_meta( $user_id, 'rmm_role_history', true );
		
		// Remover parámetro operator_id para el botón de volver
		$back_url = remove_query_arg( 'operator_id' );

		ob_start();
		?>
		<div class="rmm-operator-profile rmm-dark-theme bg-gray-950 border border-gray-900 rounded-xl p-6 md:p-8 shadow-2xl text-gray-200" style="font-family: 'Inter', sans-serif;">
			
			<!-- Botón volver -->
			<div class="mb-6">
				<a href="<?php echo esc_url($back_url); ?>" class="inline-flex items-center text-xs font-bold uppercase tracking-wider text-green-500 hover:text-green-400" style="text-decoration:none; gap:5px;">
					⬅️ <?php _e( 'Volver al listado de miembros', 'reforger-milsim' ); ?>
				</a>
			</div>

			<!-- Cabecera de Ficha Táctica -->
			<div class="grid grid-cols-1 lg:grid-cols-3 gap-8 items-start mb-8 pb-8 border-b border-gray-900">
				
				<!-- Avatar y Rango -->
				<div class="flex flex-col items-center md:flex-row md:items-start lg:flex-col lg:items-center text-center md:text-left lg:text-center gap-5">
					<?php echo get_avatar( $user_id, 140, '', '', array( 'class' => 'rounded-xl border-4 border-green-800 object-cover shadow-lg w-36 h-36' ) ); ?>
					<div>
						<h1 class="text-2xl font-black text-gray-100 uppercase tracking-wide mb-1 leading-tight"><?php echo esc_html( $user->display_name ); ?></h1>
						<span class="inline-block px-3 py-1 bg-green-950 text-green-400 border border-green-800 rounded text-xs font-bold uppercase tracking-wider mb-3">
							<?php echo esc_html( $role_name ); ?>
						</span>
						<div class="text-xs text-gray-500 space-y-1">
							<div>📅 Enrolado: <strong class="text-gray-300"><?php echo $enrol_date_f; ?></strong></div>
							<?php if ( !empty($steamid_64) ) : ?>
								<div>🎮 SteamID: <code class="text-gray-400"><?php echo esc_html($steamid_64); ?></code></div>
							<?php endif; ?>
							<?php if ( !empty($bohemia_uid) ) : ?>
								<div>🧩 Bohemia UID: <code class="text-gray-400"><?php echo esc_html($bohemia_uid); ?></code></div>
							<?php endif; ?>
						</div>
					</div>
				</div>

				<!-- Estadísticas Tácticas de Combate (Terminal HUD) -->
				<div class="lg:col-span-2 bg-black/60 border border-gray-900 rounded-lg p-5">
					<h3 class="text-sm font-bold text-green-400 uppercase tracking-widest border-b border-gray-900 pb-2 mb-4">📊 DOSSIER DE COMBATE DEL OPERADOR</h3>
					<div class="grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-4 gap-4">
						
						<div class="p-3 bg-gray-900/50 rounded border border-gray-900">
							<span class="block text-[9px] uppercase tracking-wider text-gray-500 mb-1"><?php _e( 'Misiones Jugadas', 'reforger-milsim' ); ?></span>
							<strong class="text-xl text-gray-100 font-mono"><?php echo $attendance; ?></strong>
						</div>
						<div class="p-3 bg-gray-900/50 rounded border border-gray-900">
							<span class="block text-[9px] uppercase tracking-wider text-gray-500 mb-1"><?php _e( 'Rol Preferido', 'reforger-milsim' ); ?></span>
							<strong class="text-sm text-gray-100 block truncate" title="<?php echo esc_attr($pref_role); ?>"><?php echo esc_html( $pref_role ); ?></strong>
						</div>
						<div class="p-3 bg-gray-900/50 rounded border border-gray-900">
							<span class="block text-[9px] uppercase tracking-wider text-gray-500 mb-1"><?php _e( 'Bajas / Muertes', 'reforger-milsim' ); ?></span>
							<strong class="text-xl text-gray-100 font-mono"><?php echo "$kills / $deaths"; ?></strong>
						</div>
						<div class="p-3 bg-gray-900/50 rounded border border-gray-900">
							<span class="block text-[9px] uppercase tracking-wider text-gray-500 mb-1"><?php _e( 'K/D Ratio', 'reforger-milsim' ); ?></span>
							<strong class="text-xl text-gray-100 font-mono"><?php echo $kd_ratio; ?></strong>
						</div>
						<div class="p-3 bg-gray-900/50 rounded border border-gray-900">
							<span class="block text-[9px] uppercase tracking-wider text-gray-500 mb-1"><?php _e( 'Precisión', 'reforger-milsim' ); ?></span>
							<strong class="text-xl text-gray-100 font-mono"><?php echo $accuracy; ?></strong>
						</div>
						<div class="p-3 bg-gray-900/50 rounded border border-gray-900">
							<span class="block text-[9px] uppercase tracking-wider text-gray-500 mb-1"><?php _e( 'Horas de combate', 'reforger-milsim' ); ?></span>
							<strong class="text-xl text-gray-100 font-mono"><?php echo $hours; ?>h</strong>
						</div>
						<div class="p-3 bg-gray-900/50 rounded border border-gray-900">
							<span class="block text-[9px] uppercase tracking-wider text-gray-500 mb-1"><?php _e( 'Disparos', 'reforger-milsim' ); ?></span>
							<strong class="text-xl text-gray-100 font-mono"><?php echo $shots_fired; ?></strong>
						</div>
						<div class="p-3 bg-gray-900/50 rounded border border-gray-900">
							<span class="block text-[9px] uppercase tracking-wider text-gray-500 mb-1"><?php _e( 'Impactos', 'reforger-milsim' ); ?></span>
							<strong class="text-xl text-gray-100 font-mono"><?php echo $shots_hit; ?></strong>
						</div>

					</div>
				</div>

			</div>

			<!-- Exposición de Condecoraciones (Exposición Grid) -->
			<div class="mb-8">
				<h2 class="text-lg font-black text-gray-100 uppercase tracking-wider border-b border-gray-900 pb-2.5 mb-6 flex items-center" style="gap:8px;">
					<span>🎖️</span> <?php _e( 'EXPEDIENTE DE CONDECORACIONES Y HOJA DE SERVICIO', 'reforger-milsim' ); ?>
				</h2>
				
				<?php if ( ! empty($medals) ) : ?>
					<div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">
						<?php foreach ( $medals as $m ) : 
							$thumb_url = get_the_post_thumbnail_url( $m->medal_id, 'medium' );
							if ( !$thumb_url ) $thumb_url = 'https://via.placeholder.com/300x88?text=Medalla';
							$admin_data = get_userdata( $m->otorgada_por_admin_id );
							$admin_name = $admin_data ? $admin_data->display_name : __( 'Sistema', 'reforger-milsim' );
							?>
							
							<!-- Tarjeta de Medalla Individual (Grande) -->
							<div class="bg-gray-900 border border-gray-800 rounded-lg p-5 flex flex-col justify-between shadow-lg">
								<div class="text-center mb-4">
									<a href="<?php echo esc_url( get_permalink( $m->medal_id ) ); ?>" class="block hover:scale-105 transition-transform">
										<img src="<?php echo esc_url($thumb_url); ?>" class="w-[200px] h-[58px] mx-auto block object-cover shadow border border-gray-950" title="<?php echo esc_attr( $m->post_title ); ?>">
									</a>
									<h4 class="text-md font-extrabold text-gray-100 uppercase mt-3 mb-1"><?php echo esc_html( $m->post_title ); ?></h4>
									<span class="text-[9px] uppercase tracking-wider text-gray-500 font-bold block">
										📅 Otorgada: <?php echo date('d/m/Y', strtotime($m->fecha_obtenida)); ?>
									</span>
								</div>
								
								<div class="bg-black/40 rounded p-3 text-xs text-gray-300 border border-gray-900/50">
									<strong class="block text-green-500 text-[9px] uppercase tracking-wider mb-1">📝 CITACIÓN OFICIAL:</strong>
									<p class="margin-0 leading-relaxed italic">"<?php echo esc_html( $m->motivo ); ?>"</p>
								</div>
								
								<div class="text-[9px] text-gray-500 uppercase tracking-widest text-right mt-3 font-semibold">
									Otorgada por: <span class="text-gray-400"><?php echo esc_html($admin_name); ?></span>
								</div>
							</div>
						<?php endforeach; ?>
					</div>
				<?php else : ?>
					<div class="bg-gray-900/30 border border-gray-900 border-dashed rounded-lg p-8 text-center">
						<p class="text-gray-500 uppercase font-bold tracking-wider mb-0"><?php _e( 'El operador no tiene condecoraciones registradas.', 'reforger-milsim' ); ?></p>
					</div>
				<?php endif; ?>
			</div>

			<!-- Historial de Carrera Militar (Timeline de roles) -->
			<div>
				<h2 class="text-lg font-black text-gray-100 uppercase tracking-wider border-b border-gray-900 pb-2.5 mb-6 flex items-center" style="gap:8px;">
					<span>📅</span> <?php _e( 'CRONOLOGÍA DE CARRERA EN EL CLAN', 'reforger-milsim' ); ?>
				</h2>

				<?php if ( ! empty($history) && is_array($history) ) : ?>
					<div class="relative border-l border-green-800/40 ml-4 pl-6 space-y-6">
						<?php foreach ( array_reverse($history) as $change ) : ?>
							<div class="relative">
								<!-- Punto en el timeline -->
								<span class="absolute -left-[31px] top-0.5 flex h-4 w-4 items-center justify-center rounded-full bg-gray-950 border border-green-500">
									<span class="h-1.5 w-1.5 rounded-full bg-green-500"></span>
								</span>
								<div class="text-xs">
									<strong class="text-green-500 block font-mono text-[10px] tracking-wider mb-1">
										🕒 <?php echo esc_html( date('d/m/Y - H:i', strtotime($change['date'])) ); ?>h
									</strong>
									<span class="text-gray-300">
										<?php if ( ! empty($change['from']) ) : ?>
											Promoción de rango: de <code class="text-gray-400 bg-gray-900 px-1 py-0.5 rounded"><?php echo esc_html($change['from']); ?></code> a <code class="text-green-400 bg-green-950/40 px-1 py-0.5 rounded border border-green-900/30"><?php echo esc_html($change['to']); ?></code>.
										<?php else : ?>
											Ingreso al clan con rango <code class="text-green-400 bg-green-950/40 px-1 py-0.5 rounded border border-green-900/30"><?php echo esc_html($change['to']); ?></code>.
										<?php endif; ?>
									</span>
									<span class="block text-[10px] text-gray-600 uppercase tracking-wider mt-1">Autorizado por: <?php echo esc_html($change['by']); ?></span>
								</div>
							</div>
						<?php endforeach; ?>
					</div>
				<?php else : ?>
					<p class="text-gray-500 text-xs italic"><?php _e( 'No hay registros de cambios de rango en la hoja de servicio.', 'reforger-milsim' ); ?></p>
				<?php endif; ?>
			</div>

		</div>
		<?php
		return ob_get_clean();
	}
}
