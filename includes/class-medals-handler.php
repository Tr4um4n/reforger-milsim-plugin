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
		<div class="rmm-operators-grid-wrapper" style="font-family: 'Inter', system-ui, sans-serif;">
			<div class="rmm-members-grid">
				<?php foreach ( $users as $user ) : 
					$uid = $user->ID;
					$medals = isset( $all_user_medals[$uid] ) ? $all_user_medals[$uid] : array();
					
					// Estadísticas
					$kills        = intval( get_user_meta( $uid, 'rmm_kills', true ) ?: 0 );
					$deaths       = intval( get_user_meta( $uid, 'rmm_deaths', true ) ?: 0 );
					$hours        = intval( get_user_meta( $uid, 'rmm_hours', true ) ?: 0 );
					$shots_fired  = intval( get_user_meta( $uid, 'rmm_shots_fired', true ) ?: 0 );
					$shots_hit    = intval( get_user_meta( $uid, 'rmm_shots_hit', true ) ?: 0 );
					
					$attendance   = isset( $all_user_attendance[$uid] ) ? intval( $all_user_attendance[$uid] ) : 0;
					$pref_role    = isset( $all_user_pref_roles[$uid] ) ? $all_user_pref_roles[$uid] : __( 'No definido', 'reforger-milsim' );
					
					$kd_ratio     = $deaths > 0 ? number_format( $kills / $deaths, 2 ) : number_format( $kills, 2 );
					$accuracy     = $shots_fired > 0 ? number_format( ($shots_hit / $shots_fired) * 100, 1 ) . '%' : '—';
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
					
					// Color del borde según rol
					$border_color = '#849b4c'; // verde oliva táctico por defecto
					if ( $main_role === 'fundador' || $main_role === 'administrator' ) {
						$border_color = '#d97706'; // dorado/ámbar para mando
					} elseif ( $main_role === 'veterano' ) {
						$border_color = '#7c3aed'; // púrpura para veteranos
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
									<div class="rmm-status-dot" title="Activo"></div>
								</div>
								<h3 class="rmm-card-name"><?php echo esc_html( $user->display_name ); ?></h3>
								<span class="rmm-card-rank"><?php echo esc_html( $role_name ); ?></span>
							</div>

							<!-- Stats rápidos visibles siempre -->
							<div class="rmm-card-stats">
								<div class="rmm-stat">
									<span class="rmm-stat-value"><?php echo $attendance; ?></span>
									<span class="rmm-stat-label">Misiones</span>
								</div>
								<div class="rmm-stat">
									<span class="rmm-stat-value"><?php echo $kd_ratio; ?></span>
									<span class="rmm-stat-label">K/D</span>
								</div>
								<div class="rmm-stat">
									<span class="rmm-stat-value"><?php echo $hours; ?>h</span>
									<span class="rmm-stat-label">Horas</span>
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
									<span class="rmm-no-medals"><?php _e( 'Sin condecoraciones', 'reforger-milsim' ); ?></span>
								<?php endif; ?>
							</div>

						</div>

						<!-- Overlay lateral que se desliza en hover -->
						<div class="rmm-card-overlay">
							<div class="rmm-overlay-scroll">
								<h4 class="rmm-overlay-title">
									<span>📂 DOSSIER</span>
									<span class="rmm-overlay-id">#<?php echo $uid; ?></span>
								</h4>
								
								<div class="rmm-overlay-grid">
									<div class="rmm-overlay-item">
										<span class="rmm-overlay-label">Rol Preferido</span>
										<span class="rmm-overlay-val"><?php echo esc_html( $pref_role ); ?></span>
									</div>
									<div class="rmm-overlay-item">
										<span class="rmm-overlay-label">Bajas / Muertes</span>
										<span class="rmm-overlay-val"><?php echo "$kills / $deaths"; ?></span>
									</div>
									<div class="rmm-overlay-item">
										<span class="rmm-overlay-label">Precisión</span>
										<span class="rmm-overlay-val"><?php echo $accuracy; ?></span>
									</div>
									<div class="rmm-overlay-item">
										<span class="rmm-overlay-label">Disparos / Impactos</span>
										<span class="rmm-overlay-val"><?php echo "$shots_fired / $shots_hit"; ?></span>
									</div>
								</div>
								
								<div class="rmm-overlay-enrol">
									<span class="rmm-overlay-label">Enlistado</span>
									<span class="rmm-overlay-val"><?php echo $enrol_date_f; ?></span>
								</div>
							</div>
							
							<div class="rmm-overlay-action">
								<span>Ver expediente completo →</span>
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
					grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
					gap: 12px;
				}
				.rmm-card-body {
					padding: 14px 10px 10px;
				}
				.rmm-avatar-img {
					width: 60px !important;
					height: 60px !important;
				}
				.rmm-card-name {
					font-size: 0.8rem;
				}
				.rmm-stat-value {
					font-size: 0.85rem;
				}
				.rmm-ribbon {
					width: 50px;
					height: 15px;
				}
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
