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

			// Enqueue FontAwesome para iconos
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
		}

		/**
		 * Enqueue FontAwesome desde CDN
		 */
		public function enqueue_frontend_assets() {
			wp_enqueue_style( 'fontawesome', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css', array(), '6.5.1' );
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

		// Roles que se mostrarán en el listado
		$target_roles = array( 'recluta', 'activo', 'baja_indefinida', 'baja_definitiva', 'expulsado' );

		// Obtener usuarios con los roles especificados
		$users = get_users( array(
			'role__in' => $target_roles,
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
		<div class="rmm-operators-grid-wrapper" style="font-family: 'Inter', system-ui, sans-serif;">
			<div class="rmm-members-grid">
				<?php foreach ( $users as $user ) : 
					$uid = $user->ID;
					$medals = isset( $all_user_medals[$uid] ) ? $all_user_medals[$uid] : array();
					
					// Estadísticas
					$kills        = intval( get_user_meta( $uid, 'rmm_kills', true ) ?: 0 );
					$deaths       = intval( get_user_meta( $uid, 'rmm_deaths', true ) ?: 0 );
					$hours_raw    = floatval( get_user_meta( $uid, 'rmm_hours', true ) ?: 0 );
									$hours_fmt    = $this->format_hours( $hours_raw );
					$shots_fired  = intval( get_user_meta( $uid, 'rmm_shots_fired', true ) ?: 0 );
					$shots_hit    = intval( get_user_meta( $uid, 'rmm_shots_hit', true ) ?: 0 );
					
					$attendance   = isset( $all_user_attendance[$uid] ) ? intval( $all_user_attendance[$uid] ) : 0;
					$pref_role    = isset( $all_user_pref_roles[$uid] ) ? $all_user_pref_roles[$uid] : __( 'No definido', 'reforger-milsim' );
					
					$kd_ratio     = $deaths > 0 ? number_format( $kills / $deaths, 2 ) : number_format( $kills, 2 );
					$accuracy     = $shots_fired > 0 ? number_format( ($shots_hit / $shots_fired) * 100, 1 ) . '%' : '—';
					$enrol_date   = get_user_meta( $uid, 'rmm_enrolment_date', true );
					$enrol_date_f = !empty( $enrol_date ) ? date('d/m/Y', strtotime($enrol_date)) : __( 'No registrada', 'reforger-milsim' );

									// Obtener TODOS los roles del usuario que coincidan con el filtro
									$wp_roles = wp_roles();
									$matched_roles = array();
									$first_role_slug = '';
									foreach ( $user->roles as $r ) {
										if ( in_array( $r, $target_roles ) ) {
											$matched_roles[] = isset( $wp_roles->role_names[$r] ) ? translate_user_role( $wp_roles->role_names[$r] ) : ucfirst($r);
											if ( $first_role_slug === '' ) $first_role_slug = $r;
										}
									}
									if ( empty($matched_roles) ) {
										$matched_roles = array( isset($wp_roles->role_names[$user->roles[0]]) ? translate_user_role($wp_roles->role_names[$user->roles[0]]) : ucfirst($user->roles[0]) );
										$first_role_slug = $user->roles[0] ?? '';
									}
					
									// Configurar enlace del perfil
									$profile_link = !empty($a['profile_url']) ? esc_url( add_query_arg( 'operator_id', $uid, $a['profile_url'] ) ) : esc_url( add_query_arg( 'operator_id', $uid ) );
					
									// Color del borde según rol
									$border_color = '#849b4c';
									if ( $first_role_slug === 'expulsado' ) {
										$border_color = '#ef4444';
									} elseif ( $first_role_slug === 'baja_definitiva' ) {
										$border_color = '#6b7280';
									} elseif ( $first_role_slug === 'baja_indefinida' ) {
										$border_color = '#f59e0b';
									} elseif ( $first_role_slug === 'recluta' ) {
										$border_color = '#3b82f6';
									} elseif ( $first_role_slug === 'activo' ) {
										$border_color = '#22c55e';
									}
					
									$status_icon = 'fa-solid fa-circle';
									$status_color = '#22c55e';
									if ( $first_role_slug === 'expulsado' || $first_role_slug === 'baja_definitiva' ) {
										$status_icon = 'fa-solid fa-circle-xmark';
										$status_color = '#ef4444';
									} elseif ( $first_role_slug === 'baja_indefinida' ) {
										$status_icon = 'fa-solid fa-circle-pause';
										$status_color = '#f59e0b';
									} elseif ( $first_role_slug === 'recluta' ) {
										$status_icon = 'fa-solid fa-circle-up';
										$status_color = '#3b82f6';
									}
					?>
					
					<!-- Card de Operador -->
					<a href="<?php echo $profile_link; ?>" 
					   class="rmm-operator-card" 
					   style="--card-accent: <?php echo $border_color; ?>; text-decoration: none; color: inherit;">
						
						<!-- Barra de acento superior -->
						<div class="rmm-card-accent"></div>
						
						<!-- Cuerpo de la tarjeta -->
						<div class="rmm-card-body">
							
							<!-- Cabecera: Avatar + Nombre + Rango -->
								<div class="rmm-card-header">
									<div class="rmm-avatar-wrap">
										<?php echo get_avatar( $uid, 90, '', '', array( 'class' => 'rmm-avatar-img' ) ); ?>
										<div class="rmm-status-dot" title="<?php echo esc_attr($first_role_slug); ?>" style="background: <?php echo $status_color; ?>;">
											<i class="<?php echo $status_icon; ?>" style="font-size: 8px; color: #fff; position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%);"></i>
										</div>
									</div>
									<h3 class="rmm-card-name"><?php echo esc_html( $user->display_name ); ?></h3>
									<div class="rmm-card-roles">
										<?php foreach ( $matched_roles as $role_label ) : ?>
											<span class="rmm-card-rank"><?php echo esc_html( $role_label ); ?></span>
										<?php endforeach; ?>
									</div>
								</div>

							<!-- Stats rápidos visibles siempre -->
								<div class="rmm-card-stats">
									<div class="rmm-stat">
										<span class="rmm-stat-value"><?php echo $attendance; ?></span>
										<span class="rmm-stat-label"><i class="fa-solid fa-bullseye"></i> Misiones</span>
									</div>
									<div class="rmm-stat">
										<span class="rmm-stat-value"><?php echo $kd_ratio; ?></span>
										<span class="rmm-stat-label"><i class="fa-solid fa-skull"></i> K/D</span>
									</div>
									<div class="rmm-stat">
										<span class="rmm-stat-value"><?php echo $hours_fmt; ?></span>
																			<span class="rmm-stat-label"><i class="fa-solid fa-clock"></i> Horas</span>
									</div>
								</div>

							<!-- Pasador de Medallas -->
							<div class="rmm-card-ribbons">
								<?php if ( ! empty($medals) ) : ?>
									<div class="rmm-ribbons-grid">
										<?php 
										$count = 0;
										foreach ( $medals as $m ) {
											if ( $count >= 6 ) break;
											$thumb_url = get_the_post_thumbnail_url( $m->medal_id, 'metopa-militar' );
											if ( !$thumb_url ) $thumb_url = 'https://via.placeholder.com/120x35/1a1a1a/555?text=Medalla';
											?>
											<img src="<?php echo esc_url($thumb_url); ?>" 
												 class="rmm-ribbon" 
												 title="<?php echo esc_attr( $m->post_title ); ?>"
												 loading="lazy">
											<?php
											$count++;
										}
										if ( count($medals) > 6 ) : ?>
											<span class="rmm-ribbons-more">+<?php echo count($medals) - 6; ?></span>
										<?php endif; ?>
									</div>
								<?php else : ?>
									<span class="rmm-no-medals"><i class="fa-solid fa-ribbon"></i> <?php _e( 'Sin condecoraciones', 'reforger-milsim' ); ?></span>
								<?php endif; ?>
							</div>

						</div>

						<!-- Overlay lateral que se desliza en hover -->
							<div class="rmm-card-overlay">
								<div class="rmm-overlay-scroll">
									<h4 class="rmm-overlay-title">
										<span><i class="fa-solid fa-folder-open"></i> DOSSIER</span>
										<span class="rmm-overlay-id"><i class="fa-solid fa-id-card"></i> #<?php echo $uid; ?></span>
									</h4>
								
									<div class="rmm-overlay-roles">
										<?php foreach ( $matched_roles as $role_label ) : ?>
											<span class="rmm-overlay-role-badge"><?php echo esc_html( $role_label ); ?></span>
										<?php endforeach; ?>
									</div>
								
									<div class="rmm-overlay-grid">
										<div class="rmm-overlay-item">
											<span class="rmm-overlay-label"><i class="fa-solid fa-star"></i> Rol Preferido</span>
											<span class="rmm-overlay-val"><?php echo esc_html( $pref_role ); ?></span>
										</div>
										<div class="rmm-overlay-item">
											<span class="rmm-overlay-label"><i class="fa-solid fa-skull-crossbones"></i> Bajas / Muertes</span>
											<span class="rmm-overlay-val"><?php echo "$kills / $deaths"; ?></span>
										</div>
										<div class="rmm-overlay-item">
											<span class="rmm-overlay-label"><i class="fa-solid fa-crosshairs"></i> Precisión</span>
											<span class="rmm-overlay-val"><?php echo $accuracy; ?></span>
										</div>
										<div class="rmm-overlay-item">
											<span class="rmm-overlay-label"><i class="fa-solid fa-gun"></i> Disparos / Impactos</span>
											<span class="rmm-overlay-val"><?php echo "$shots_fired / $shots_hit"; ?></span>
										</div>
									</div>
								
									<div class="rmm-overlay-enrol">
										<span class="rmm-overlay-label"><i class="fa-solid fa-calendar-check"></i> Enlistado</span>
										<span class="rmm-overlay-val"><?php echo $enrol_date_f; ?></span>
									</div>
								</div>
							
								<div class="rmm-overlay-action">
									<span><i class="fa-solid fa-arrow-right"></i> Ver expediente completo</span>
								</div>
							</div>
					</a>
				<?php endforeach; ?>
			</div>
		</div>
		
		<style>
			/* =============================================
			   GRID DE OPERADORES — Estilo Táctico
			   ============================================= */
			
			.rmm-members-grid {
				display: grid;
				grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
				gap: 20px;
			}
			
			/* ── Tarjeta ── */
			.rmm-operator-card {
				position: relative;
				display: flex;
				flex-direction: column;
				background: linear-gradient(180deg, #1a1d21 0%, #141619 100%);
				border: 1px solid #2a2d31;
				border-radius: 10px;
				overflow: hidden;
				transition: transform 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease;
				cursor: pointer;
				min-height: 320px;
			}
			.rmm-operator-card:hover {
				transform: translateY(-6px);
				border-color: var(--card-accent, #849b4c);
				box-shadow: 0 12px 40px rgba(0,0,0,0.6), 0 0 0 1px var(--card-accent, #849b4c);
			}
			
			/* ── Barra de acento ── */
			.rmm-card-accent {
				height: 3px;
				background: var(--card-accent, #849b4c);
				border-radius: 10px 10px 0 0;
				transition: height 0.3s ease;
			}
			.rmm-operator-card:hover .rmm-card-accent {
				height: 5px;
			}
			
			/* ── Cuerpo ── */
			.rmm-card-body {
				flex: 1;
				display: flex;
				flex-direction: column;
				align-items: center;
				padding: 20px 16px 16px;
				text-align: center;
			}
			
			/* ── Cabecera ── */
			.rmm-card-header {
				display: flex;
				flex-direction: column;
				align-items: center;
				margin-bottom: 14px;
			}
			.rmm-avatar-wrap {
				position: relative;
				margin-bottom: 10px;
			}
			.rmm-avatar-img {
				border-radius: 50% !important;
				border: 3px solid #2a2d31 !important;
				width: 80px !important;
				height: 80px !important;
				object-fit: cover !important;
				box-shadow: 0 4px 15px rgba(0,0,0,0.4);
				transition: border-color 0.3s ease;
			}
			.rmm-operator-card:hover .rmm-avatar-img {
				border-color: var(--card-accent, #849b4c) !important;
			}
			.rmm-status-dot {
				position: absolute;
				bottom: 2px;
				right: 2px;
				width: 14px;
				height: 14px;
				border-radius: 50%;
				background: #22c55e;
				border: 2px solid #141619;
				box-shadow: 0 0 8px rgba(34,197,94,0.4);
			}
			.rmm-card-name {
				font-size: 0.95rem;
				font-weight: 700;
				color: #e5e7eb;
				text-transform: uppercase;
				letter-spacing: 0.03em;
				margin: 0 0 4px;
				line-height: 1.2;
				transition: color 0.3s ease;
			}
			.rmm-operator-card:hover .rmm-card-name {
				color: var(--card-accent, #849b4c);
			}
			.rmm-card-rank {
				font-size: 0.7rem;
				font-weight: 600;
				color: #6b7280;
				text-transform: uppercase;
				letter-spacing: 0.08em;
				padding: 2px 10px;
				border: 1px solid #2a2d31;
				border-radius: 3px;
				background: rgba(255,255,255,0.02);
			}
			
			/* ── Stats ── */
			.rmm-card-stats {
				display: flex;
				gap: 0;
				width: 100%;
				border-top: 1px solid #1f2226;
				border-bottom: 1px solid #1f2226;
				padding: 10px 0;
				margin-bottom: 14px;
			}
			.rmm-stat {
				flex: 1;
				display: flex;
				flex-direction: column;
				align-items: center;
			}
			.rmm-stat-value {
				font-size: 1.05rem;
				font-weight: 700;
				color: #e5e7eb;
				font-family: 'JetBrains Mono', 'SF Mono', 'Fira Code', monospace;
				line-height: 1.2;
			}
			.rmm-stat-label {
				font-size: 0.6rem;
				font-weight: 600;
				color: #555;
				text-transform: uppercase;
				letter-spacing: 0.06em;
				margin-top: 2px;
			}
			
			/* ── Pasador de Medallas ── */
			.rmm-card-ribbons {
				margin-top: auto;
				width: 100%;
				display: flex;
				justify-content: center;
			}
			.rmm-ribbons-grid {
				display: grid;
				grid-template-columns: repeat(3, 1fr);
				gap: 3px;
				padding: 5px;
				background: #0d0e10;
				border: 1px solid #1f2226;
				border-radius: 5px;
				position: relative;
			}
			.rmm-ribbon {
				width: 65px;
				height: 19px;
				display: block;
				object-fit: cover;
				border-radius: 2px;
				transition: transform 0.2s ease, box-shadow 0.2s ease;
			}
			.rmm-ribbon:hover {
				transform: scale(1.8);
				box-shadow: 0 4px 15px rgba(0,0,0,0.7);
				z-index: 5;
				position: relative;
			}
			.rmm-ribbons-more {
				display: flex;
				align-items: center;
				justify-content: center;
				font-size: 0.6rem;
				font-weight: 700;
				color: #849b4c;
				background: #0d0e10;
				border: 1px dashed #333;
				border-radius: 2px;
				min-width: 65px;
				height: 19px;
			}
			.rmm-no-medals {
				font-size: 0.6rem;
				font-weight: 700;
				color: #3a3d42;
				text-transform: uppercase;
				letter-spacing: 0.08em;
			}
			
			/* ── Overlay ── */
			.rmm-card-overlay {
				position: absolute;
				inset: 0;
				display: flex;
				flex-direction: column;
				justify-content: space-between;
				background: rgba(10,11,14,0.97);
				border: 1px solid var(--card-accent, #849b4c);
				border-radius: 10px;
				opacity: 0;
				transition: opacity 0.35s ease;
				pointer-events: none;
			}
			.rmm-operator-card:hover .rmm-card-overlay {
				opacity: 1;
				pointer-events: auto;
			}
			.rmm-overlay-scroll {
				flex: 1;
				padding: 16px;
				overflow-y: auto;
			}
			.rmm-overlay-title {
				display: flex;
				justify-content: space-between;
				align-items: center;
				font-size: 0.7rem;
				font-weight: 700;
				color: var(--card-accent, #849b4c);
				text-transform: uppercase;
				letter-spacing: 0.08em;
				padding-bottom: 8px;
				border-bottom: 1px solid #1f2226;
				margin-bottom: 12px;
			}
			.rmm-overlay-id {
				font-size: 0.6rem;
				color: #555;
				letter-spacing: 0.04em;
			}
			.rmm-overlay-grid {
				display: grid;
				grid-template-columns: 1fr 1fr;
				gap: 10px;
			}
			.rmm-overlay-item {
				display: flex;
				flex-direction: column;
			}
			.rmm-overlay-label {
				font-size: 0.55rem;
				font-weight: 600;
				color: #555;
				text-transform: uppercase;
				letter-spacing: 0.05em;
				margin-bottom: 2px;
			}
			.rmm-overlay-val {
				font-size: 0.75rem;
				font-weight: 600;
				color: #d1d5db;
				line-height: 1.3;
			}
			.rmm-overlay-enrol {
				margin-top: 12px;
				padding-top: 8px;
				border-top: 1px solid #1f2226;
				display: flex;
				flex-direction: column;
			}
			.rmm-overlay-action {
				padding: 10px 16px;
				text-align: center;
				border-top: 1px solid #1f2226;
			}
			.rmm-overlay-action span {
				display: block;
				padding: 7px 0;
				font-size: 0.65rem;
				font-weight: 700;
				color: #0d0e10;
				background: var(--card-accent, #849b4c);
				border-radius: 4px;
				text-transform: uppercase;
				letter-spacing: 0.06em;
				transition: filter 0.2s ease;
			}
			.rmm-overlay-action span:hover {
				filter: brightness(1.15);
			}
			
			/* ── Responsive ── */
			@media (max-width: 640px) {
				.rmm-members-grid {
					grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
					gap: 10px;
				}
				.rmm-operator-card {
					min-height: 260px;
				}
				.rmm-card-body {
					padding: 12px 8px 8px;
				}
				.rmm-avatar-img {
					width: 55px !important;
					height: 55px !important;
				}
				.rmm-card-name {
					font-size: 0.75rem;
				}
				.rmm-card-rank {
					font-size: 0.6rem;
				}
				.rmm-stat-value {
					font-size: 0.8rem;
				}
				.rmm-stat-label {
					font-size: 0.5rem;
				}
				.rmm-ribbon {
					width: 45px;
					height: 13px;
				}
				.rmm-ribbons-more {
					min-width: 45px;
					height: 13px;
					font-size: 0.5rem;
				}
				/* Overlay en movil */
				.rmm-overlay-scroll {
					padding: 10px;
				}
				.rmm-overlay-title {
					font-size: 0.6rem;
					margin-bottom: 8px;
				}
				.rmm-overlay-grid {
					grid-template-columns: 1fr;
					gap: 6px;
				}
				.rmm-overlay-val {
					font-size: 0.7rem;
				}
				.rmm-overlay-action span {
					font-size: 0.6rem;
					padding: 5px 0;
				}
			}
			
			/* ── Roles Múltiples en Card ── */
			.rmm-card-roles {
				display: flex;
				flex-wrap: wrap;
				gap: 4px;
				justify-content: center;
				margin-top: 2px;
			}
			
			/* ── Overlay: Sección de Roles ── */
			.rmm-overlay-roles {
				display: flex;
				flex-wrap: wrap;
				gap: 5px;
				margin-bottom: 12px;
				padding-bottom: 10px;
				border-bottom: 1px solid #1f2226;
			}
			.rmm-overlay-role-badge {
				display: inline-block;
				font-size: 0.55rem;
				font-weight: 700;
				color: #d1d5db;
				text-transform: uppercase;
				letter-spacing: 0.05em;
				padding: 2px 8px;
				border: 1px solid #2a2d31;
				border-radius: 3px;
				background: rgba(255,255,255,0.03);
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
		$hours_raw    = floatval( get_user_meta( $user_id, 'rmm_hours', true ) ?: 0 );
				$hours_fmt    = $this->format_hours( $hours_raw );
		$shots_fired  = intval( get_user_meta( $user_id, 'rmm_shots_fired', true ) ?: 0 );
		$shots_hit    = intval( get_user_meta( $user_id, 'rmm_shots_hit', true ) ?: 0 );
		// Medical stats
		$bandages     = intval( get_user_meta( $user_id, 'rmm_bandages', true ) ?: 0 );
		$tourniquets  = intval( get_user_meta( $user_id, 'rmm_tourniquets', true ) ?: 0 );
		$saline       = intval( get_user_meta( $user_id, 'rmm_saline', true ) ?: 0 );
		$morphine     = intval( get_user_meta( $user_id, 'rmm_morphine', true ) ?: 0 );
		$epinephrine  = intval( get_user_meta( $user_id, 'rmm_epinephrine', true ) ?: 0 );
		// Movilidad y vehiculos
		$dist_walked    = intval( get_user_meta( $user_id, 'rmm_dist_walked', true ) ?: 0 );
		$dist_vehicle   = intval( get_user_meta( $user_id, 'rmm_dist_vehicle', true ) ?: 0 );
		$dist_total     = intval( get_user_meta( $user_id, 'rmm_dist_total', true ) ?: 0 );
		$veh_destroyed  = intval( get_user_meta( $user_id, 'rmm_veh_destroyed', true ) ?: 0 );
		$veh_air        = intval( get_user_meta( $user_id, 'rmm_veh_air', true ) ?: 0 );
		$explosives     = intval( get_user_meta( $user_id, 'rmm_explosives', true ) ?: 0 );
		$steamid_64   = get_user_meta( $user_id, 'steamid_64', true );
		$bohemia_uid  = get_user_meta( $user_id, 'bohemia_uid', true );
		$enrol_date   = get_user_meta( $user_id, 'rmm_enrolment_date', true );
		$enrol_date_f = !empty( $enrol_date ) ? date('d \d\e F \d\e Y', strtotime($enrol_date)) : __( 'No registrada', 'reforger-milsim' );
		
		$kd_ratio     = number_format( $kills / ( $deaths > 0 ? $deaths : 1 ), 2 );
		$accuracy     = $shots_fired > 0 ? number_format( ($shots_hit / $shots_fired) * 100, 1 ) . '%' : '0%';

		// Obtener TODOS los roles del usuario
		$target_roles = array( 'recluta', 'activo', 'baja_indefinida', 'baja_definitiva', 'expulsado' );
		$wp_roles = wp_roles();
		$matched_roles = array();
		$first_role_slug = '';
		foreach ( $user->roles as $r ) {
			if ( in_array( $r, $target_roles ) ) {
				$matched_roles[] = isset( $wp_roles->role_names[$r] ) ? translate_user_role( $wp_roles->role_names[$r] ) : ucfirst($r);
				if ( $first_role_slug === '' ) $first_role_slug = $r;
			}
		}
		if ( empty($matched_roles) ) {
			$matched_roles = array( isset($wp_roles->role_names[$user->roles[0]]) ? translate_user_role($wp_roles->role_names[$user->roles[0]]) : ucfirst($user->roles[0]) );
			$first_role_slug = $user->roles[0] ?? '';
		}
		
		// Obtener historial de roles (timeline)
		$history = get_user_meta( $user_id, 'rmm_role_history', true );
		
		// Fusionar historial de roles + medallas en una sola cronología
		$timeline = array();
		$hidden_entries = get_user_meta( $user_id, 'rmm_hidden_timeline', true ) ?: array();
		
		// Añadir cambios de rango
		if ( ! empty( $history ) && is_array( $history ) ) {
			foreach ( $history as $index => $change ) {
				$entry_id  = ( $change['date'] ?? '' ) . '_' . $index;
				// Saltar entradas ocultas por admin
				if ( in_array( $entry_id, $hidden_entries ) ) continue;
				
				$timestamp = strtotime( $change['date'] ?? 'now' );
				
				// Detectar tipo de entrada: role, manual, medal
				if ( isset( $change['type'] ) && $change['type'] !== 'role' ) {
					// Entrada manual con tipo especifico
					$timeline[] = array(
						'date'   => $timestamp,
						'type'   => $change['type'],
						'desc'   => $change['desc'] ?? '',
						'author' => $change['by'] ?? __( 'Sistema', 'reforger-milsim' ),
					);
				} else {
					// Cambio de rol tradicional
					$timeline[] = array(
						'date'   => $timestamp,
						'type'   => 'role',
						'from'   => $change['from'] ?? '',
						'to'     => $change['to'] ?? '',
						'author' => $change['by'] ?? __( 'Sistema', 'reforger-milsim' ),
					);
				}
			}
		}
		
		// Añadir condecoraciones otorgadas
		if ( ! empty( $medals ) ) {
			foreach ( $medals as $m ) {
				$timestamp = strtotime( $m->fecha_obtenida );
				$admin_data = get_userdata( $m->otorgada_por_admin_id );
				$admin_name = $admin_data ? $admin_data->display_name : __( 'Sistema', 'reforger-milsim' );
				$timeline[] = array(
					'date'       => $timestamp,
					'type'       => 'medal',
					'medal_id'   => $m->medal_id,
					'medal_name' => $m->post_title,
					'motivo'     => $m->motivo,
					'author'     => $admin_name,
				);
			}
		}
		
		// Ordenar por fecha descendente (más reciente primero)
		usort( $timeline, function( $a, $b ) {
			return $b['date'] - $a['date'];
		});
		
		// Remover parámetro operator_id para el botón de volver
		$back_url = remove_query_arg( 'operator_id' );

		ob_start();
		?>
		<div class="rmm-operator-profile rmm-dark-theme" style="font-family: 'Inter', sans-serif; background: #0d1117; color: #c9d1d9; border-radius: 12px; overflow: hidden;">
			
			<!-- Botón volver -->
			<div style="padding: 16px 24px; border-bottom: 1px solid #21262d;">
				<a href="<?php echo esc_url($back_url); ?>" style="text-decoration:none; color: #58a6ff; font-size: 0.8rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; display: inline-flex; align-items: center; gap: 6px;">
					<i class="fa-solid fa-arrow-left"></i> <?php _e( 'Volver al listado de miembros', 'reforger-milsim' ); ?>
				</a>
			</div>

			<!-- Cabecera de Ficha Táctica: 2 columnas -->
			<div class="rmm-profile-header" style="display: grid; grid-template-columns: 280px 1fr; gap: 24px; padding: 24px; border-bottom: 1px solid #21262d;">
				
				<!-- Columna Izquierda: Avatar + Info -->
				<div style="display: flex; flex-direction: column; align-items: center; text-align: center;">
					<?php 
					$border_color = '#849b4c';
					if ( $first_role_slug === 'expulsado' ) $border_color = '#ef4444';
					elseif ( $first_role_slug === 'baja_definitiva' ) $border_color = '#6b7280';
					elseif ( $first_role_slug === 'baja_indefinida' ) $border_color = '#f59e0b';
					elseif ( $first_role_slug === 'recluta' ) $border_color = '#3b82f6';
					elseif ( $first_role_slug === 'activo' ) $border_color = '#22c55e';
					?>
					<?php echo get_avatar( $user_id, 140, '', '', array( 'class' => 'rmm-profile-avatar rmm-profile-avatar-img', 'style' => 'border-radius: 8px; border: 3px solid ' . $border_color . '; object-fit: cover; width: 140px; height: 140px; box-shadow: 0 0 20px rgba(0,0,0,0.5);' ) ); ?>
					
					<h1 style="font-size: 1.5rem; font-weight: 800; color: #fff; text-transform: uppercase; letter-spacing: 0.03em; margin: 14px 0 8px;"><?php echo esc_html( $user->display_name ); ?></h1>
					
					<!-- Todos los roles como badges -->
					<div style="display: flex; flex-wrap: wrap; gap: 5px; justify-content: center; margin-bottom: 14px;">
						<?php foreach ( $matched_roles as $role_label ) : ?>
							<span style="display: inline-block; padding: 3px 10px; font-size: 0.65rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; border: 1px solid <?php echo $border_color; ?>; border-radius: 3px; color: <?php echo $border_color; ?>; background: rgba(255,255,255,0.03);"><?php echo esc_html( $role_label ); ?></span>
						<?php endforeach; ?>
					</div>
					
					<!-- Fecha de enrolamiento -->
					<div style="font-size: 0.7rem; color: #8b949e; display: flex; flex-direction: column; gap: 6px; width: 100%;">
						<div style="display: flex; align-items: center; gap: 6px; justify-content: center;">
							<i class="fa-solid fa-calendar-days" style="color: #58a6ff;"></i>
							<span style="color: #8b949e;"><?php echo $enrol_date_f; ?></span>
						</div>
					</div>
				</div>

				<!-- Columna Derecha: DOSSIER DE COMBATE -->
				<div style="background: #161b22; border: 1px solid #21262d; border-radius: 8px; padding: 20px;">
					<h3 style="font-size: 0.75rem; font-weight: 700; color: #58a6ff; text-transform: uppercase; letter-spacing: 0.08em; border-bottom: 1px solid #21262d; padding-bottom: 10px; margin-bottom: 16px; display: flex; align-items: center; gap: 8px;">
						<i class="fa-solid fa-file-shield"></i> DOSSIER DE COMBATE DEL OPERADOR
					</h3>
					<div class="rmm-profile-dossier-stats" style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px;">
						
						<div style="background: #0d1117; border: 1px solid #21262d; border-radius: 6px; padding: 12px; text-align: center;">
							<i class="fa-solid fa-flag" style="color: #58a6ff; font-size: 1rem; margin-bottom: 6px; display: block;"></i>
							<span style="display: block; font-size: 0.55rem; text-transform: uppercase; color: #8b949e; letter-spacing: 0.05em; margin-bottom: 4px;"><?php _e( 'Misiones Jugadas', 'reforger-milsim' ); ?></span>
							<strong style="font-size: 1.3rem; color: #fff; font-family: monospace;"><?php echo $attendance; ?></strong>
						</div>
						
						<div style="background: #0d1117; border: 1px solid #21262d; border-radius: 6px; padding: 12px; text-align: center;">
							<i class="fa-solid fa-star" style="color: #d2a850; font-size: 1rem; margin-bottom: 6px; display: block;"></i>
							<span style="display: block; font-size: 0.55rem; text-transform: uppercase; color: #8b949e; letter-spacing: 0.05em; margin-bottom: 4px;"><?php _e( 'Rol Preferido', 'reforger-milsim' ); ?></span>
							<strong style="font-size: 0.8rem; color: #fff; font-family: monospace; display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?php echo esc_attr($pref_role); ?>"><?php echo esc_html( $pref_role ); ?></strong>
						</div>
						
						<div style="background: #0d1117; border: 1px solid #21262d; border-radius: 6px; padding: 12px; text-align: center;">
							<i class="fa-solid fa-skull" style="color: #ef4444; font-size: 1rem; margin-bottom: 6px; display: block;"></i>
							<span style="display: block; font-size: 0.55rem; text-transform: uppercase; color: #8b949e; letter-spacing: 0.05em; margin-bottom: 4px;"><?php _e( 'Bajas / Muertes', 'reforger-milsim' ); ?></span>
							<strong style="font-size: 1.3rem; color: #fff; font-family: monospace;"><?php echo "$kills / $deaths"; ?></strong>
						</div>
						
						<div style="background: #0d1117; border: 1px solid #21262d; border-radius: 6px; padding: 12px; text-align: center;">
							<i class="fa-solid fa-crosshairs" style="color: #f59e0b; font-size: 1rem; margin-bottom: 6px; display: block;"></i>
							<span style="display: block; font-size: 0.55rem; text-transform: uppercase; color: #8b949e; letter-spacing: 0.05em; margin-bottom: 4px;"><?php _e( 'K/D Ratio', 'reforger-milsim' ); ?></span>
							<strong style="font-size: 1.3rem; color: #fff; font-family: monospace;"><?php echo $kd_ratio; ?></strong>
						</div>
						
						<div style="background: #0d1117; border: 1px solid #21262d; border-radius: 6px; padding: 12px; text-align: center;">
							<i class="fa-solid fa-bullseye" style="color: #22c55e; font-size: 1rem; margin-bottom: 6px; display: block;"></i>
							<span style="display: block; font-size: 0.55rem; text-transform: uppercase; color: #8b949e; letter-spacing: 0.05em; margin-bottom: 4px;"><?php _e( 'Precisión', 'reforger-milsim' ); ?></span>
							<strong style="font-size: 1.3rem; color: #fff; font-family: monospace;"><?php echo $accuracy; ?></strong>
						</div>
						
						<div style="background: #0d1117; border: 1px solid #21262d; border-radius: 6px; padding: 12px; text-align: center;">
							<i class="fa-solid fa-clock" style="color: #8b949e; font-size: 1rem; margin-bottom: 6px; display: block;"></i>
							<span style="display: block; font-size: 0.55rem; text-transform: uppercase; color: #8b949e; letter-spacing: 0.05em; margin-bottom: 4px;"><?php _e( 'Horas de combate', 'reforger-milsim' ); ?></span>
							<strong style="font-size: 1.3rem; color: #fff; font-family: monospace;"><?php echo $hours_fmt; ?></strong>
						</div>
						
						<div style="background: #0d1117; border: 1px solid #21262d; border-radius: 6px; padding: 12px; text-align: center;">
							<i class="fa-solid fa-gun" style="color: #c9d1d9; font-size: 1rem; margin-bottom: 6px; display: block;"></i>
							<span style="display: block; font-size: 0.55rem; text-transform: uppercase; color: #8b949e; letter-spacing: 0.05em; margin-bottom: 4px;"><?php _e( 'Disparos', 'reforger-milsim' ); ?></span>
							<strong style="font-size: 1.3rem; color: #fff; font-family: monospace;"><?php echo $shots_fired; ?></strong>
						</div>
						
						<div style="background: #0d1117; border: 1px solid #21262d; border-radius: 6px; padding: 12px; text-align: center;">
							<i class="fa-solid fa-bandage" style="color: #f778ba; font-size: 1rem; margin-bottom: 6px; display: block;"></i>
							<span style="display: block; font-size: 0.55rem; text-transform: uppercase; color: #8b949e; letter-spacing: 0.05em; margin-bottom: 4px;"><?php _e( 'Vendajes', 'reforger-milsim' ); ?></span>
							<strong style="font-size: 1.3rem; color: #fff; font-family: monospace;"><?php echo $bandages; ?></strong>
						</div>
						
						<div style="background: #0d1117; border: 1px solid #21262d; border-radius: 6px; padding: 12px; text-align: center;">
							<i class="fa-solid fa-kit-medical" style="color: #d2a850; font-size: 1rem; margin-bottom: 6px; display: block;"></i>
							<span style="display: block; font-size: 0.55rem; text-transform: uppercase; color: #8b949e; letter-spacing: 0.05em; margin-bottom: 4px;"><?php _e( 'Torniquetes', 'reforger-milsim' ); ?></span>
							<strong style="font-size: 1.3rem; color: #fff; font-family: monospace;"><?php echo $tourniquets; ?></strong>
						</div>
						
						<div style="background: #0d1117; border: 1px solid #21262d; border-radius: 6px; padding: 12px; text-align: center;">
							<i class="fa-solid fa-droplet" style="color: #58a6ff; font-size: 1rem; margin-bottom: 6px; display: block;"></i>
							<span style="display: block; font-size: 0.55rem; text-transform: uppercase; color: #8b949e; letter-spacing: 0.05em; margin-bottom: 4px;"><?php _e( 'Salino', 'reforger-milsim' ); ?></span>
							<strong style="font-size: 1.3rem; color: #fff; font-family: monospace;"><?php echo $saline; ?></strong>
						</div>
						
						<div style="background: #0d1117; border: 1px solid #21262d; border-radius: 6px; padding: 12px; text-align: center;">
							<i class="fa-solid fa-syringe" style="color: #7c3aed; font-size: 1rem; margin-bottom: 6px; display: block;"></i>
							<span style="display: block; font-size: 0.55rem; text-transform: uppercase; color: #8b949e; letter-spacing: 0.05em; margin-bottom: 4px;"><?php _e( 'Morfina', 'reforger-milsim' ); ?></span>
							<strong style="font-size: 1.3rem; color: #fff; font-family: monospace;"><?php echo $morphine; ?></strong>
						</div>
						
						<div style="background: #0d1117; border: 1px solid #21262d; border-radius: 6px; padding: 12px; text-align: center;">
							<i class="fa-solid fa-heart-pulse" style="color: #dc2626; font-size: 1rem; margin-bottom: 6px; display: block;"></i>
							<span style="display: block; font-size: 0.55rem; text-transform: uppercase; color: #8b949e; letter-spacing: 0.05em; margin-bottom: 4px;"><?php _e( 'Epinefrina', 'reforger-milsim' ); ?></span>
							<strong style="font-size: 1.3rem; color: #fff; font-family: monospace;"><?php echo $epinephrine; ?></strong>
							</div>
						
							<div style="background: #0d1117; border: 1px solid #21262d; border-radius: 6px; padding: 12px; text-align: center;">
								<i class="fa-solid fa-person-walking" style="color: #58a6ff; font-size: 1rem; margin-bottom: 6px; display: block;"></i>
								<span style="display: block; font-size: 0.55rem; text-transform: uppercase; color: #8b949e; letter-spacing: 0.05em; margin-bottom: 4px;"><?php _e( 'Dist. a pie', 'reforger-milsim' ); ?></span>
								<strong style="font-size: 1.3rem; color: #fff; font-family: monospace;"><?php echo number_format( $dist_walked / 1000, 1 ); ?>km</strong>
							</div>
						
							<div style="background: #0d1117; border: 1px solid #21262d; border-radius: 6px; padding: 12px; text-align: center;">
								<i class="fa-solid fa-car" style="color: #d2a850; font-size: 1rem; margin-bottom: 6px; display: block;"></i>
								<span style="display: block; font-size: 0.55rem; text-transform: uppercase; color: #8b949e; letter-spacing: 0.05em; margin-bottom: 4px;"><?php _e( 'Dist. vehiculo', 'reforger-milsim' ); ?></span>
								<strong style="font-size: 1.3rem; color: #fff; font-family: monospace;"><?php echo number_format( $dist_vehicle / 1000, 1 ); ?>km</strong>
							</div>
						
							<div style="background: #0d1117; border: 1px solid #21262d; border-radius: 6px; padding: 12px; text-align: center;">
								<i class="fa-solid fa-burst" style="color: #f78166; font-size: 1rem; margin-bottom: 6px; display: block;"></i>
								<span style="display: block; font-size: 0.55rem; text-transform: uppercase; color: #8b949e; letter-spacing: 0.05em; margin-bottom: 4px;"><?php _e( 'Vehiculos destruidos', 'reforger-milsim' ); ?></span>
								<strong style="font-size: 1.3rem; color: #fff; font-family: monospace;"><?php echo $veh_destroyed; ?></strong>
							</div>
						
							<div style="background: #0d1117; border: 1px solid #21262d; border-radius: 6px; padding: 12px; text-align: center;">
								<i class="fa-solid fa-helicopter" style="color: #a371f7; font-size: 1rem; margin-bottom: 6px; display: block;"></i>
								<span style="display: block; font-size: 0.55rem; text-transform: uppercase; color: #8b949e; letter-spacing: 0.05em; margin-bottom: 4px;"><?php _e( 'Aeronaves derribadas', 'reforger-milsim' ); ?></span>
								<strong style="font-size: 1.3rem; color: #fff; font-family: monospace;"><?php echo $veh_air; ?></strong>
							</div>
						
							<div style="background: #0d1117; border: 1px solid #21262d; border-radius: 6px; padding: 12px; text-align: center;">
								<i class="fa-solid fa-bomb" style="color: #dc2626; font-size: 1rem; margin-bottom: 6px; display: block;"></i>
								<span style="display: block; font-size: 0.55rem; text-transform: uppercase; color: #8b949e; letter-spacing: 0.05em; margin-bottom: 4px;"><?php _e( 'Explosivos detonados', 'reforger-milsim' ); ?></span>
								<strong style="font-size: 1.3rem; color: #fff; font-family: monospace;"><?php echo $explosives; ?></strong>
							</div>
						
						</div>
				</div>

			</div>

			<!-- Condecoraciones -->
			<div style="padding: 24px; border-bottom: 1px solid #21262d;">
				<h2 style="font-size: 1rem; font-weight: 800; color: #fff; text-transform: uppercase; letter-spacing: 0.06em; border-bottom: 1px solid #21262d; padding-bottom: 10px; margin-bottom: 20px; display: flex; align-items: center; gap: 8px;">
					<i class="fa-solid fa-medal" style="color: #d2a850;"></i> <?php _e( 'EXPEDIENTE DE CONDECORACIONES Y HOJA DE SERVICIO', 'reforger-milsim' ); ?>
				</h2>
				
				<?php if ( ! empty($medals) ) : ?>
					<div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 10px;">
						<?php foreach ( $medals as $m ) : 
							$thumb_url = get_the_post_thumbnail_url( $m->medal_id, 'metopa-militar' );
							if ( !$thumb_url ) $thumb_url = 'https://via.placeholder.com/120x35/1a1a1a/555?text=Medalla';
							$large_url = get_the_post_thumbnail_url( $m->medal_id, 'medium' );
							if ( !$large_url ) $large_url = 'https://via.placeholder.com/300x88/1a1a1a/555?text=Medalla';
							$admin_data = get_userdata( $m->otorgada_por_admin_id );
							$admin_name = $admin_data ? $admin_data->display_name : __( 'Sistema', 'reforger-milsim' );
							$fecha = date('d/m/Y', strtotime($m->fecha_obtenida));
							?>
							
							<div class="rmm-medal-thumb" 
								 style="background: #161b22; border: 1px solid #21262d; border-radius: 6px; padding: 8px; text-align: center; cursor: pointer; transition: all 0.2s ease;"
								 data-medal-title="<?php echo esc_attr( $m->post_title ); ?>"
								 data-medal-image="<?php echo esc_url( $large_url ); ?>"
								 data-medal-date="<?php echo esc_attr( $fecha ); ?>"
								 data-medal-citation="<?php echo esc_attr( $m->motivo ); ?>"
								 data-medal-awarded-by="<?php echo esc_attr( $admin_name ); ?>">
								<img src="<?php echo esc_url($thumb_url); ?>" style="width: 120px; height: 35px; object-fit: cover; display: block; margin: 0 auto 6px; border-radius: 2px;" alt="<?php echo esc_attr( $m->post_title ); ?>">
								<span style="font-size: 0.55rem; font-weight: 700; color: #8b949e; text-transform: uppercase; letter-spacing: 0.04em;"><?php echo esc_html( $m->post_title ); ?></span>
							</div>
						<?php endforeach; ?>
					</div>
				<?php else : ?>
					<div style="background: #161b22; border: 1px dashed #21262d; border-radius: 8px; padding: 30px; text-align: center;">
						<i class="fa-solid fa-ribbon" style="font-size: 2rem; color: #30363d; display: block; margin-bottom: 10px;"></i>
						<p style="color: #8b949e; text-transform: uppercase; font-weight: 700; letter-spacing: 0.06em; font-size: 0.75rem; margin: 0;"><?php _e( 'El operador no tiene condecoraciones registradas.', 'reforger-milsim' ); ?></p>
					</div>
				<?php endif; ?>
			</div>

			<!-- Timeline de Carrera -->
				<div style="padding: 24px;">
					<h2 style="font-size: 1rem; font-weight: 800; color: #fff; text-transform: uppercase; letter-spacing: 0.06em; border-bottom: 1px solid #21262d; padding-bottom: 10px; margin-bottom: 20px; display: flex; align-items: center; gap: 8px;">
						<i class="fa-solid fa-timeline" style="color: #22c55e;"></i> <?php _e( 'CRONOLOGÍA DE CARRERA EN EL CLAN', 'reforger-milsim' ); ?>
					</h2>

					<?php if ( ! empty( $timeline ) ) : ?>
						<div style="position: relative; border-left: 2px solid rgba(34,197,94,0.25); margin-left: 16px; padding-left: 24px; display: flex; flex-direction: column; gap: 20px;">
							<?php foreach ( $timeline as $event ) : 
								$type = $event['type'] ?? 'role';
								$is_role   = ( $type === 'role' );
								$is_medal  = ( $type === 'medal' );
								$is_manual = ! $is_role && ! $is_medal;
								
								// Configuracion visual segun tipo
								$type_config = array(
									'role'      => array( 'color' => '#22c55e', 'bg' => 'rgba(34,197,94,0.1)',  'icon' => 'fa-chevron-up', 'label' => __( 'Cambio de rango', 'reforger-milsim' ) ),
									'medal'     => array( 'color' => '#d2a850', 'bg' => 'rgba(210,168,80,0.1)', 'icon' => 'fa-medal',      'label' => __( 'Condecoración', 'reforger-milsim' ) ),
									'event'     => array( 'color' => '#58a6ff', 'bg' => 'rgba(88,166,255,0.1)',  'icon' => 'fa-flag',       'label' => __( 'Evento', 'reforger-milsim' ) ),
									'promotion' => array( 'color' => '#22c55e', 'bg' => 'rgba(34,197,94,0.1)',  'icon' => 'fa-arrow-up',   'label' => __( 'Promoción', 'reforger-milsim' ) ),
									'training'  => array( 'color' => '#a371f7', 'bg' => 'rgba(163,113,247,0.1)', 'icon' => 'fa-graduation-cap', 'label' => __( 'Formación', 'reforger-milsim' ) ),
									'award'     => array( 'color' => '#d2a850', 'bg' => 'rgba(210,168,80,0.1)', 'icon' => 'fa-award',      'label' => __( 'Reconocimiento', 'reforger-milsim' ) ),
									'other'     => array( 'color' => '#8b949e', 'bg' => 'rgba(139,148,158,0.1)', 'icon' => 'fa-circle',     'label' => __( 'Otro', 'reforger-milsim' ) ),
								);
								$cfg  = $type_config[ $type ] ?? $type_config['other'];
								$dot_color = $cfg['color'];
								$dot_bg    = $cfg['bg'];
								$icon      = $cfg['icon'];
								$label     = $cfg['label'];
								?>
								<div style="position: relative;">
									<span style="position: absolute; left: -29px; top: 2px; display: flex; width: 14px; height: 14px; align-items: center; justify-content: center; border-radius: 50%; background: #0d1117; border: 2px solid <?php echo $dot_color; ?>;">
										<span style="width: 6px; height: 6px; border-radius: 50%; background: <?php echo $dot_color; ?>;"></span>
									</span>
								
									<div style="font-size: 0.75rem;">
										<!-- Cabecera con tipo de evento -->
										<div style="display: flex; align-items: center; gap: 8px; margin-bottom: 4px;">
											<span style="display: inline-flex; align-items: center; gap: 4px; font-size: 0.55rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; padding: 2px 8px; border-radius: 3px; background: <?php echo $dot_bg; ?>; color: <?php echo $dot_color; ?>; border: 1px solid <?php echo $dot_color; ?>33;">
												<i class="fa-solid <?php echo $icon; ?>" style="font-size: 0.5rem;"></i> <?php echo $label; ?>
											</span>
											<strong style="color: #484f58; font-family: monospace; font-size: 0.6rem; letter-spacing: 0.05em;">
												<i class="fa-solid fa-clock" style="margin-right: 3px;"></i> <?php echo esc_html( date('d/m/Y - H:i', $event['date']) ); ?>h
											</strong>
										</div>
									
										<!-- Contenido del evento -->
										<?php if ( $is_role ) : ?>
											<span style="color: #c9d1d9;">
												<?php if ( ! empty( $event['from'] ) ) : ?>
													Promocion: de <code style="background: #161b22; padding: 1px 6px; border-radius: 3px; color: #8b949e;"><?php echo esc_html( $event['from'] ); ?></code> a <code style="background: rgba(34,197,94,0.1); padding: 1px 6px; border-radius: 3px; color: #22c55e; border: 1px solid rgba(34,197,94,0.2);"><?php echo esc_html( $event['to'] ); ?></code>.
												<?php else : ?>
													Ingreso al clan con rango <code style="background: rgba(34,197,94,0.1); padding: 1px 6px; border-radius: 3px; color: #22c55e; border: 1px solid rgba(34,197,94,0.2);"><?php echo esc_html( $event['to'] ); ?></code>.
												<?php endif; ?>
											</span>
										<?php elseif ( $is_medal ) : ?>
											<div style="display: flex; align-items: center; gap: 10px; background: rgba(210,168,80,0.05); border: 1px solid rgba(210,168,80,0.15); border-radius: 6px; padding: 10px 12px;">
												<?php 
												$thumb_url = get_the_post_thumbnail_url( $event['medal_id'], 'metopa-militar' );
												if ( $thumb_url ) : ?>
													<img src="<?php echo esc_url( $thumb_url ); ?>" style="width: 80px; height: 23px; object-fit: cover; border-radius: 2px; flex-shrink: 0;">
												<?php endif; ?>
												<div>
													<strong style="color: #d2a850; font-size: 0.8rem; text-transform: uppercase;"><?php echo esc_html( $event['medal_name'] ); ?></strong>
													<?php if ( ! empty( $event['motivo'] ) ) : ?>
														<p style="color: #8b949e; font-size: 0.7rem; font-style: italic; margin: 4px 0 0; line-height: 1.4;">"<?php echo esc_html( $event['motivo'] ); ?>"</p>
													<?php endif; ?>
												</div>
											</div>
										<?php else : ?>
											<span style="color: #c9d1d9;"><?php echo esc_html( $event['desc'] ?? '' ); ?></span>
										<?php endif; ?>
									
										<span style="display: block; font-size: 0.6rem; color: #484f58; text-transform: uppercase; letter-spacing: 0.05em; margin-top: 6px;">
											<?php 
											if ( $is_role ) {
												echo __( 'Autorizado por:', 'reforger-milsim' );
											} elseif ( $is_medal ) {
												echo __( 'Otorgada por:', 'reforger-milsim' );
											} else {
												echo __( 'Registrado por:', 'reforger-milsim' );
											}
											?> <?php echo esc_html( $event['author'] ); ?>
										</span>
									</div>
								</div>
							<?php endforeach; ?>
						</div>
					<?php else : ?>
						<p style="color: #8b949e; font-size: 0.75rem; font-style: italic;"><?php _e( 'No hay registros de actividad en la hoja de servicio.', 'reforger-milsim' ); ?></p>
					<?php endif; ?>
				</div>

			<!-- Popup Modal de Medalla (oculto por defecto) -->
			<div id="rmm-medal-popup-overlay" class="rmm-medal-popup-overlay" style="display: none;">
				<div class="rmm-medal-popup">
					<button class="rmm-medal-popup-close" id="rmm-medal-popup-close">
						<i class="fa-solid fa-xmark"></i>
					</button>
					<img id="rmm-medal-popup-img" src="" style="width: 300px; height: 88px; object-fit: cover; display: block; margin: 0 auto 16px; border-radius: 4px; border: 1px solid #30363d;" alt="">
					<h3 id="rmm-medal-popup-title" style="font-size: 1.1rem; font-weight: 800; color: #fff; text-align: center; text-transform: uppercase; margin: 0 0 14px;"></h3>
					<div style="font-size: 0.75rem; color: #8b949e; display: flex; flex-direction: column; gap: 8px;">
						<div><strong style="color: #c9d1d9;">Fecha de otorgamiento:</strong> <span id="rmm-medal-popup-date"></span></div>
						<div><strong style="color: #c9d1d9;">Citación:</strong> <p id="rmm-medal-popup-citation" style="margin: 4px 0 0; color: #8b949e; font-style: italic; background: #0d1117; padding: 10px; border-radius: 4px; border-left: 2px solid #d2a850;"></p></div>
						<div><strong style="color: #c9d1d9;">Otorgada por:</strong> <span id="rmm-medal-popup-awarded"></span></div>
					</div>
				</div>
			</div>

			<style>
				.rmm-medal-thumb:hover {
					border-color: #d2a850 !important;
					box-shadow: 0 0 15px rgba(210,168,80,0.25) !important;
					transform: translateY(-2px);
				}
				.rmm-medal-popup-overlay {
					position: fixed;
					top: 0;
					left: 0;
					width: 100%;
					height: 100%;
					background: rgba(0,0,0,0.85);
					z-index: 9999;
					display: flex;
					align-items: center;
					justify-content: center;
					animation: rmmFadeIn 0.25s ease;
				}
				@keyframes rmmFadeIn {
					from { opacity: 0; }
					to { opacity: 1; }
				}
				@keyframes rmmSlideIn {
					from { transform: translateY(-30px); opacity: 0; }
					to { transform: translateY(0); opacity: 1; }
				}
				.rmm-medal-popup {
					background: #1a1d21;
					border: 2px solid #22c55e;
					border-radius: 10px;
					padding: 28px 24px;
					max-width: 500px;
					width: 90%;
					position: relative;
					box-shadow: 0 20px 60px rgba(0,0,0,0.7);
					animation: rmmSlideIn 0.3s ease;
				}
				.rmm-medal-popup-close {
					position: absolute;
					top: 10px;
					right: 14px;
					background: none;
					border: none;
					color: #8b949e;
					font-size: 1.2rem;
					cursor: pointer;
					transition: color 0.2s;
					padding: 4px 8px;
					border-radius: 4px;
				}
				.rmm-medal-popup-close:hover {
					color: #ef4444;
					background: rgba(239,68,68,0.1);
				}
			</style>

			<script>
			(function() {
				var overlay = document.getElementById('rmm-medal-popup-overlay');
				var closeBtn = document.getElementById('rmm-medal-popup-close');
				var popupImg = document.getElementById('rmm-medal-popup-img');
				var popupTitle = document.getElementById('rmm-medal-popup-title');
				var popupDate = document.getElementById('rmm-medal-popup-date');
				var popupCitation = document.getElementById('rmm-medal-popup-citation');
				var popupAwarded = document.getElementById('rmm-medal-popup-awarded');
				
				var thumbs = document.querySelectorAll('.rmm-medal-thumb');
				thumbs.forEach(function(thumb) {
					thumb.addEventListener('click', function() {
						popupImg.src = this.getAttribute('data-medal-image');
						popupTitle.textContent = this.getAttribute('data-medal-title');
						popupDate.textContent = this.getAttribute('data-medal-date');
						popupCitation.textContent = this.getAttribute('data-medal-citation');
						popupAwarded.textContent = this.getAttribute('data-medal-awarded-by');
						overlay.style.display = 'flex';
					});
				});
				
				function closePopup() {
					overlay.style.display = 'none';
				}
				
				closeBtn.addEventListener('click', closePopup);
				overlay.addEventListener('click', function(e) {
					if (e.target === overlay) closePopup();
				});
				document.addEventListener('keydown', function(e) {
					if (e.key === 'Escape' && overlay.style.display === 'flex') closePopup();
				});
			})();
			</script>

		</div>

		<style>
		/* Responsive para el perfil de operador */
		@media (max-width: 768px) {
			.rmm-operator-profile .rmm-profile-header {
				grid-template-columns: 1fr !important;
				gap: 16px !important;
				padding: 16px !important;
			}
			.rmm-operator-profile .rmm-profile-dossier-stats {
				grid-template-columns: repeat(3, 1fr) !important;
				gap: 8px !important;
			}
		}
		@media (max-width: 480px) {
			.rmm-operator-profile .rmm-profile-dossier-stats {
				grid-template-columns: repeat(2, 1fr) !important;
			}
			.rmm-operator-profile .rmm-profile-avatar-img {
				width: 100px !important;
				height: 100px !important;
			}
		}
		</style>

		<?php
		return ob_get_clean();
		}

		/**
		 * Formatea horas (float) a formato legible: "2h 30m" o "45m"
		 */
		private function format_hours( $hours_float ) {
			if ( $hours_float <= 0 ) return '0h';
		
			$h = floor( $hours_float );
			$m = round( ( $hours_float - $h ) * 60 );
		
			if ( $m >= 60 ) {
				$h++;
				$m = 0;
			}
		
			if ( $h > 0 && $m > 0 ) {
				return $h . 'h ' . $m . 'm';
			} elseif ( $h > 0 ) {
				return $h . 'h';
			} else {
				return $m . 'm';
			}
		}
	}
