<?php
/**
 * Frontend ORBAT & Reservations Class
 * @package ReforgerMilsimManagement
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class RMM_Frontend_ORBAT {

	public function __construct() {
		add_shortcode( 'clan_orbat', array( $this, 'render_legacy_orbat_shortcode' ) );
		add_shortcode( 'rmm_orbat', array( $this, 'render_rmm_orbat_shortcode' ) );
		add_shortcode( 'rmm_addons_list', array( $this, 'render_addons_list_shortcode' ) );
		add_shortcode( 'rmm_summary', array( $this, 'render_rmm_summary_shortcode' ) );
		add_shortcode( 'rmm_description', array( $this, 'render_rmm_description_shortcode' ) );
		add_shortcode( 'rmm_workshop_url', array( $this, 'render_rmm_workshop_url_shortcode' ) );
		add_shortcode( 'rmm_title', array( $this, 'render_rmm_title_shortcode' ) );
		add_shortcode( 'rmm_author', array( $this, 'render_rmm_author_shortcode' ) );
		add_shortcode( 'fecha_evento', array( $this, 'render_fecha_evento_shortcode' ) );
		add_shortcode( 'rmm_missions_grid', array( $this, 'render_missions_grid_shortcode' ) );
		add_shortcode( 'rmm_evento_estado', array( $this, 'render_evento_estado_shortcode' ) );
		add_action( 'wp_ajax_reclamar_slot', array( $this, 'handle_slot_reservation' ) );
		add_action( 'wp_ajax_liberar_slot', array( $this, 'handle_slot_leave' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
	}

	public function enqueue_frontend_assets() {
		wp_enqueue_script( 'rmm-frontend-js', RMM_PLUGIN_URL . 'assets/js/rmm-frontend.js', array('jquery'), RMM_VERSION, true );
		wp_localize_script( 'rmm-frontend-js', 'rmmFrontend', array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'rmm_frontend_nonce' )
		));
	}

	public function render_fecha_evento_shortcode( $atts ) {
			$post_id = get_the_ID();
			if ( ! $post_id ) return '';
		
			$fecha_inicio = get_post_meta( $post_id, 'fecha_inicio', true );
			if ( empty( $fecha_inicio ) ) return '';

			// La fecha se guarda en hora local (UTC+2 Bilbao). Soportar formatos con/sin T, con/sin segundos.
					$fecha_inicio = str_replace( 'T', ' ', trim( $fecha_inicio ) );
					if ( ! preg_match( '/:\d{2}:\d{2}$/', $fecha_inicio ) ) {
								$fecha_inicio .= ':00';
							}
					$dt = DateTime::createFromFormat( 'Y-m-d H:i:s', $fecha_inicio, wp_timezone() );
					if ( ! $dt ) return '';
		
					$offset = $dt->format('Z') / 3600;
					$utc = 'UTC' . ($offset >= 0 ? '+' : '') . $offset;
		
					return ucfirst( wp_date( 'l, j \d\e F \a \l\a\s H:i', $dt->getTimestamp() ) ) . ' (' . $utc . ' Peninsular)';
		}

	public function render_legacy_orbat_shortcode( $atts ) {
		return $this->render_rmm_orbat_shortcode($atts) . $this->render_addons_list_shortcode($atts);
	}

	public function render_rmm_title_shortcode( $atts ) {
		$post_id = get_the_ID();
		if ( ! $post_id ) return '';
		// get_post_field with 'raw' context bypasses the 'the_title' filters (like the one appending the date)
		return esc_html( get_post_field( 'post_title', $post_id, 'raw' ) );
	}

	public function render_rmm_author_shortcode( $atts ) {
		$post_id = get_the_ID();
		if ( ! $post_id ) return '';
		$target_id = get_post_type($post_id) === 'eventos_partidas' ? (get_post_meta($post_id, 'mision_id', true) ?: $post_id) : $post_id;
		$author = get_post_meta( $target_id, 'rmm_author', true );
		if ( empty( $author ) ) return '<em>[Autor no definido]</em>';
		return esc_html( $author );
	}

	public function render_rmm_summary_shortcode( $atts ) {
		$post_id = get_the_ID();
		if ( ! $post_id ) return '';
		$target_id = get_post_type($post_id) === 'eventos_partidas' ? (get_post_meta($post_id, 'mision_id', true) ?: $post_id) : $post_id;
		$summary = get_post_meta( $target_id, 'rmm_summary', true );
		if ( empty( $summary ) ) return '';
		return '<div class="rmm-summary-box">' . wpautop( esc_html( $summary ) ) . '</div>';
	}

	public function render_rmm_description_shortcode( $atts ) {
		$post_id = get_the_ID();
		if ( ! $post_id ) return '';
		$target_id = get_post_type($post_id) === 'eventos_partidas' ? (get_post_meta($post_id, 'mision_id', true) ?: $post_id) : $post_id;
		$description = get_post_meta( $target_id, 'rmm_description', true );
		if ( empty( $description ) ) return '';
		return '<div class="rmm-description-box">' . wpautop( esc_html( $description ) ) . '</div>';
	}

	public function render_rmm_workshop_url_shortcode( $atts ) {
		$post_id = get_the_ID();
		if ( ! $post_id ) return '';
		$target_id = get_post_type($post_id) === 'eventos_partidas' ? (get_post_meta($post_id, 'mision_id', true) ?: $post_id) : $post_id;
		$url = get_post_meta( $target_id, 'workshop_url', true );
		if ( empty( $url ) ) return '';
		
		// Optional arguments for button text and CSS classes
		$a = shortcode_atts( array(
			'text' => 'Ver en Steam Workshop',
			'class' => 'rmm-workshop-btn button elementor-button'
		), $atts );

		return '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer" class="' . esc_attr( $a['class'] ) . '">' . esc_html( $a['text'] ) . '</a>';
	}

	public function render_evento_estado_shortcode( $atts ) {
		$post_id = get_the_ID();
		if ( ! $post_id ) return '';
		
		$estado  = get_post_meta( $post_id, 'estado', true );
		$motivo  = get_post_meta( $post_id, 'motivo_cancelacion', true );
		
		$labels = array(
			'abierta'    => 'Abierta',
			'en_curso'   => 'En curso',
			'debriefing' => 'Debriefing',
			'finalizada' => 'Finalizada',
			'cancelada'  => 'Cancelada',
		);
		
		$colors = array(
			'abierta'    => '#849b4c',
			'en_curso'   => '#d97706',
			'debriefing' => '#7c3aed',
			'finalizada' => '#6b7280',
			'cancelada'  => '#dc2626',
		);
		
		$label = $labels[ $estado ] ?? ucfirst( $estado );
		$color = $colors[ $estado ] ?? '#6b7280';
		
		$html  = '<span class="rmm-evento-estado" style="display:inline-block;padding:4px 12px;border-radius:3px;font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;';
		$html .= 'background:' . $color . '22;color:' . $color . ';border:1px solid ' . $color . '44;">';
		$html .= esc_html( $label );
		$html .= '</span>';
		
		if ( $estado === 'cancelada' && ! empty( $motivo ) ) {
			$html .= ' <span style="color:#fca5a5;font-size:0.7rem;font-style:italic;">' . esc_html( $motivo ) . '</span>';
		}
		
		return $html;
	}

	public function render_rmm_orbat_shortcode( $atts ) {
		$post_id = get_the_ID();
		$post_type = get_post_type($post_id);
		
		if ( ! in_array( $post_type, array( 'eventos_partidas', 'misiones' ) ) ) return '';

		$a = shortcode_atts( array(
			'mode' => '', // 'milsim' o 'cards'
		), $atts );

		if ( $post_type === 'misiones' ) {
			$orbat = get_post_meta( $post_id, 'orbat_maestro', true );
		} else {
			$orbat = get_post_meta( $post_id, 'orbat_activo', true );
		}

		if ( empty($orbat) ) return '<p>No hay ORBAT definido.</p>';

		ob_start();

		$mode = $a['mode'];
		if ( empty( $mode ) ) {
			$mode = ( $post_type === 'misiones' ) ? 'milsim' : 'cards';
		}

		if ( $mode === 'milsim' ) {
			$this->render_orbat_mission_mode( $orbat );
		} else {
			$this->render_orbat_event_mode( $orbat, $post_id );
		}

		return ob_get_clean();
	}

	/**
	 * ORBAT: Modo Misión — Diagrama táctico informativo MILSIM
	 */
	private function render_orbat_mission_mode( $orbat ) {
		$total_slots = 0;
		foreach ( $orbat as $squad ) {
			$total_slots += count($squad['slots']);
		}
		?>
		<div class="rmm-orbat-wrapper rmm-orbat-mission">
			<!-- Header Táctico -->
			<div class="rmm-tac-header">
				<div class="rmm-tac-header-left">
					<span class="rmm-tac-label">ORDEN DE BATALLA</span>
					<span class="rmm-tac-classification">ESTRUCTURA OPERATIVA</span>
				</div>
				<div class="rmm-tac-header-right">
					<span class="rmm-tac-stat"><strong><?php echo count($orbat); ?></strong> ESC</span>
					<span class="rmm-tac-divider">|</span>
					<span class="rmm-tac-stat"><strong><?php echo $total_slots; ?></strong> EFE</span>
				</div>
			</div>

			<?php foreach ( $orbat as $idx => $squad ) : ?>
			<div class="rmm-tac-squad">
				<div class="rmm-tac-squad-header">
					<span class="rmm-tac-squad-icon">◆</span>
					<span class="rmm-tac-squad-name"><?php echo esc_html($squad['escuadra']); ?></span>
					<span class="rmm-tac-squad-count"><?php echo count($squad['slots']); ?> efectivos</span>
				</div>
				<?php if ( !empty($squad['frequencies']) ) : ?>
				<div class="rmm-tac-frequencies" style="padding:5px 15px; background:rgba(255,255,255,0.02); font-size:0.85em; color:#aaa; border-bottom:1px solid rgba(255,255,255,0.05); display:flex; flex-wrap:wrap; gap:10px;">
					<?php foreach ( $squad['frequencies'] as $freq ) : ?>
						<span class="rmm-tac-freq-item">
							<strong style="color:#FFC107;"><?php echo esc_html($freq['purpose'] ?: 'Radio'); ?>:</strong> 
							<?php echo esc_html($freq['type']); ?> Ch <?php echo esc_html($freq['channel']); ?> 
							(<?php echo esc_html($freq['frequency']); ?> MHz)
						</span>
					<?php endforeach; ?>
				</div>
				<?php endif; ?>
				<div class="rmm-tac-roster">
					<?php
					// Group slots by role for a cleaner display
					$roles_count = array();
					foreach ( $squad['slots'] as $slot ) {
						$role = $slot['rol'] ?: 'Líder de Escuadra';
						if ( !isset($roles_count[$role]) ) {
							$roles_count[$role] = 0;
						}
						$roles_count[$role]++;
					}
					?>
					<?php foreach ( $roles_count as $role => $count ) : ?>
					<div class="rmm-tac-role-row">
						<span class="rmm-tac-role-icon"><?php echo rmm_get_role_icon_html($role); ?></span>
						<span class="rmm-tac-role-name"><?php echo esc_html($role); ?></span>
						<span class="rmm-tac-role-qty">×<?php echo $count; ?></span>
					</div>
					<?php endforeach; ?>
				</div>
			</div>
			<?php endforeach; ?>
		</div>

		<style>
		/* === ORBAT MISIÓN: Diagrama Táctico MILSIM === */
		.rmm-orbat-mission { font-family: 'Courier New', 'Consolas', monospace; }

		.rmm-tac-header { display: flex; justify-content: space-between; align-items: center; padding: 14px 20px; background: rgba(0, 0, 0, 0.6); border: 1px solid rgba(100, 180, 100, 0.2); border-left: 4px solid #4CAF50; border-radius: 4px; }
		.rmm-tac-header-left { display: flex; flex-direction: column; gap: 2px; }
		.rmm-tac-label { font-size: 1.1em; font-weight: 800; color: #4CAF50; letter-spacing: 3px; text-transform: uppercase; }
		.rmm-tac-classification { font-size: 0.65em; color: #666; letter-spacing: 2px; text-transform: uppercase; }
		.rmm-tac-header-right { display: flex; align-items: center; gap: 12px; }
		.rmm-tac-stat { font-size: 0.85em; color: #aaa; letter-spacing: 1px; }
		.rmm-tac-stat strong { color: #4CAF50; font-size: 1.3em; }
		.rmm-tac-divider { color: #333; }

		.rmm-tac-squad { background: rgba(10, 10, 10, 0.5); border: 1px solid rgba(255,255,255,0.06); border-radius: 4px; overflow: hidden; margin-top: 12px; }
		.rmm-tac-squad-header { display: flex; align-items: center; gap: 10px; padding: 10px 18px; background: rgba(0,0,0,0.4); border-bottom: 1px solid rgba(100, 180, 100, 0.15); }
		.rmm-tac-squad-icon { color: #4CAF50; font-size: 0.7em; }
		.rmm-tac-squad-name { font-size: 1em; font-weight: 800; color: #ddd; text-transform: uppercase; letter-spacing: 2px; flex-grow: 1; }
		.rmm-tac-squad-count { font-size: 0.7em; color: #666; letter-spacing: 1px; text-transform: uppercase; background: rgba(255,255,255,0.04); padding: 3px 10px; border-radius: 3px; }

		.rmm-tac-roster { padding: 8px 0; }
		.rmm-tac-role-row { display: flex; align-items: center; gap: 12px; padding: 7px 20px; border-bottom: 1px solid rgba(255,255,255,0.03); transition: background 0.15s; }
		.rmm-tac-role-row:last-child { border-bottom: none; }
		.rmm-tac-role-row:hover { background: rgba(76, 175, 80, 0.04); }
		.rmm-tac-role-icon { font-size: 1em; width: 22px; text-align: center; }
		.rmm-tac-role-name { flex-grow: 1; font-size: 0.85em; color: #bbb; text-transform: uppercase; letter-spacing: 0.5px; }
		.rmm-tac-role-qty { font-size: 0.8em; font-weight: 800; color: #4CAF50; background: rgba(76, 175, 80, 0.1); padding: 2px 10px; border-radius: 3px; min-width: 30px; text-align: center; }
		</style>
		<?php
	}

	/**
	 * Get tactical icon for a role
	 */
	private function get_role_icon( $role ) {
		$icons = array(
			'Líder de Escuadra'   => '⚔️',
			'Médico'              => '🏥',
			'Fusilero'            => '🪖',
			'Fusilero Automático' => '🔫',
			'Granadero'           => '💥',
			'Antitanque'          => '🚀',
			'RTO'                 => '📡',
			'Piloto'              => '🚁',
			'Tirador'             => '🎯',
			'Tirador Designado'   => '🎯',
			'Spoter'              => '🔭',
			'Spotter'             => '🔭',
		);
		return isset($icons[$role]) ? $icons[$role] : '👤';
	}

	/**
	 * ORBAT: Modo Evento — Cards interactivas con reservas
	 */
	private function render_orbat_event_mode( $orbat, $post_id ) {
		$post_type = get_post_type( $post_id );
		$current_user_id = get_current_user_id();
		$user_medals = $this->get_user_medal_ids( $current_user_id );
		?>
		<div class="rmm-orbat-wrapper rmm-orbat-event">
			<?php foreach ( $orbat as $squad ) : ?>
				<div class="rmm-squad-container">
					<div class="rmm-squad-header">
						<h3 class="rmm-squad-name"><?php echo esc_html($squad['escuadra']); ?></h3>
					</div>
					<?php if ( !empty($squad['frequencies']) ) : ?>
					<div class="rmm-squad-frequencies" style="padding:8px 20px; background:rgba(0,0,0,0.1); font-size:0.85em; color:#aaa; border-bottom:1px solid rgba(255,255,255,0.05); display:flex; flex-wrap:wrap; gap:15px;">
						<?php foreach ( $squad['frequencies'] as $freq ) : ?>
							<span class="rmm-freq-item">
								<strong style="color:#FFC107;"><?php echo esc_html($freq['purpose'] ?: 'Radio'); ?>:</strong> 
								<?php echo esc_html($freq['type']); ?> Ch <?php echo esc_html($freq['channel']); ?> 
								(<?php echo esc_html($freq['frequency']); ?> MHz)
							</span>
						<?php endforeach; ?>
					</div>
					<?php endif; ?>
					<div class="rmm-slots-grid">
						<?php foreach ( $squad['slots'] as $slot ) : ?>
							<?php 
								$occupied = !empty($slot['usuario_id']);
								$user = $occupied ? get_userdata($slot['usuario_id']) : null;
								$missing = $this->get_missing_medals($slot['condecoraciones_requeridas'] ?? array(), $user_medals);
								$can_reserve = empty($missing) && current_user_can('reserve_orbat_slot');
							?>
							<div class="rmm-slot-card <?php echo $occupied ? 'is-occupied' : 'is-vacant'; ?>">
								<div class="rmm-slot-header" style="display:flex; align-items:center; gap:8px; margin-bottom:10px;">
									<span class="rmm-slot-icon"><?php echo rmm_get_role_icon_html($slot['rol'] ?: 'Líder de Escuadra'); ?></span>
									<span class="rmm-slot-role" style="margin-bottom:0; flex-grow:1;"><?php echo esc_html(strtoupper($slot['rol'] ?: 'Líder de Escuadra')); ?></span>
								</div>
								<div class="rmm-slot-action">
								<?php if ($occupied) : ?>
									<span class="rmm-slot-user"><?php echo esc_html($user->display_name); ?></span>
									<?php if ( (int)$slot['usuario_id'] === $current_user_id && $post_type === 'eventos_partidas' ) : ?>
										<button data-uuid="<?php echo esc_attr($slot['id']); ?>" data-post-id="<?php echo $post_id; ?>" class="rmm-leave-btn">Desapuntarse</button>
									<?php endif; ?>
								<?php else : ?>
									<?php if ($can_reserve && $post_type === 'eventos_partidas') : ?>
										<button data-uuid="<?php echo esc_attr($slot['id']); ?>" data-post-id="<?php echo $post_id; ?>" class="rmm-reserve-btn">Reclamar Slot</button>
									<?php elseif ($post_type === 'eventos_partidas') : ?>
										<button disabled class="rmm-locked-btn">Bloqueado</button>
										<?php if(!empty($missing)) : ?><p class="rmm-missing-medals">Faltan: <?php echo implode(', ', $missing); ?></p><?php endif; ?>
									<?php else : ?>
										<span class="rmm-slot-status">VACANTE</span>
									<?php endif; ?>
								<?php endif; ?>
								</div>
							</div>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endforeach; ?>
		</div>

		<style>
		/* === ORBAT EVENTO: Cards Interactivas === */
		.rmm-orbat-event { display: flex; flex-direction: column; gap: 30px; font-family: var(--e-global-typography-text-font-family), inherit; }
		.rmm-squad-container { background: rgba(20, 20, 20, 0.4); border: 1px solid rgba(255, 255, 255, 0.05); border-radius: 8px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.2); }
		.rmm-squad-header { padding: 12px 20px; background: rgba(0,0,0,0.3); border-bottom: 1px solid rgba(255,255,255,0.05); }
		.rmm-squad-name { margin: 0; font-size: 1.3em; font-weight: 800; color: var(--e-global-color-primary, #fff); text-transform: uppercase; letter-spacing: 1px; }

		.rmm-slots-grid { display: flex; flex-wrap: wrap; gap: 15px; padding: 20px; align-items: stretch; }
		.rmm-slot-card { flex: 1 1 200px; max-width: 280px; min-width: 200px; padding: 15px; background: rgba(30, 30, 30, 0.5); border: 1px solid rgba(255,255,255,0.1); border-radius: 6px; display: flex; flex-direction: column; justify-content: space-between; gap: 12px; transition: transform 0.2s, background 0.2s; position: relative; overflow: hidden; }
		.rmm-slot-card:hover { transform: translateY(-2px); background: rgba(40, 40, 40, 0.8); }
		.rmm-slot-card.is-occupied { border-left: 4px solid #4CAF50; background: rgba(76, 175, 80, 0.05); }
		.rmm-slot-card.is-vacant { border-left: 4px solid #FFC107; }

		.rmm-slot-role { font-size: 0.75em; text-transform: uppercase; font-weight: 800; color: #888; letter-spacing: 0.5px; margin-bottom: 5px; }
		.rmm-slot-user { font-size: 1.1em; font-weight: 600; color: #eee; display: block; margin-bottom: 8px; }
		.rmm-slot-action { display: flex; flex-direction: column; justify-content: flex-end; flex-grow: 1; }

		.rmm-reserve-btn { background-color: #FFC107 !important; color: #000 !important; width: 100%; font-weight: bold !important; transition: all 0.2s !important; border-radius: 4px !important; border: none !important; text-transform: uppercase; font-size: 0.85em; padding: 10px; cursor: pointer; }
		.rmm-reserve-btn:hover { background-color: #FFB300 !important; transform: scale(1.02); }

		.rmm-leave-btn { background-color: #dc3232 !important; color: #fff !important; width: 100%; border-radius: 4px !important; border: none !important; font-weight: bold !important; opacity: 0.9; text-transform: uppercase; font-size: 0.75em; padding: 8px; margin-top: auto; cursor: pointer; }
		.rmm-leave-btn:hover { opacity: 1; }

		.rmm-locked-btn { background-color: #444 !important; color: #888 !important; width: 100%; border: none !important; border-radius: 4px !important; cursor: not-allowed; padding: 10px; text-transform: uppercase; font-size: 0.85em; }
		.rmm-missing-medals { font-size: 0.7em; color: #ff5252; margin: 8px 0 0 0; text-align: center; }
		.rmm-slot-status { font-size: 0.8em; color: #FFC107; font-weight: bold; text-align: center; display: block; padding: 5px; background: rgba(255, 193, 7, 0.1); border-radius: 4px; }
		</style>

		<?php
	}

	public function render_addons_list_shortcode( $atts ) {
		$post_id = get_the_ID();
		$post_type = get_post_type($post_id);
		if ( ! in_array( $post_type, array( 'eventos_partidas', 'misiones' ) ) ) return '';

		ob_start();

		// Addons / Dependencies Section Second (Collapsible)
		$mission_id = get_post_meta( $post_id, 'mision_id', true );
		$target_id  = !empty( $mission_id ) ? $mission_id : $post_id;
		$addons = get_post_meta( $target_id, 'addons_requeridos', true );
		
		if ( !empty($addons) && is_array($addons) ) :
		?>
		<details class="rmm-addons-collapsible" style="margin-top:20px; border-top:1px solid #333; padding-top:20px; cursor:pointer;">
			<summary style="font-weight:bold; color:#2271b1; font-size:1.1em; list-style:none; outline:none;">
				📦 VER ADDONS REQUERIDOS (<?php echo count($addons); ?>)
			</summary>
			<div class="rmm-addons-box" style="margin-top:15px; padding:15px; background:rgba(255,255,255,0.05); border-radius:8px;">
				<ul class="rmm-addons-list" style="columns: 2; -webkit-columns: 2; list-style-type: square; margin-left:20px;">
					<?php foreach ( $addons as $addon ) : ?>
						<li style="font-size:0.9em; margin-bottom:5px;"><?php echo esc_html($addon); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
		</details>
		<style>
			.rmm-addons-box { margin-bottom: 25px; padding: 15px; border-left: 4px solid var(--e-global-color-primary, #2271b1); background-color: rgba(0,0,0,0.03); }
			.rmm-addons-title { margin: 0 0 10px 0; font-size: 1.1em; color: var(--e-global-color-secondary, inherit); }
			.rmm-addons-list { margin: 0; padding-left: 20px; list-style-type: disc; font-size: 0.9em; opacity: 0.8; }
		</style>
		<?php endif; ?>
		
		<?php
		return ob_get_clean();
	}

	public function handle_slot_reservation() {
		check_ajax_referer( 'rmm_frontend_nonce', 'nonce' );
		if ( ! is_user_logged_in() || ! current_user_can('reserve_orbat_slot') ) wp_send_json_error( 'Sin permisos' );

		$post_id = intval($_POST['post_id']);
		$uuid = sanitize_text_field($_POST['uuid']);
		$orbat = get_post_meta( $post_id, 'orbat_activo', true );
		$current_user_id = get_current_user_id();

		// BLOQUEO: Verificar si el usuario ya tiene algún slot en este ORBAT
		foreach ( $orbat as $squad ) {
			foreach ( $squad['slots'] as $s ) {
				if ( (int)$s['usuario_id'] === $current_user_id ) {
					wp_send_json_error( 'Ya tienes un slot reservado en esta operación.' );
				}
			}
		}

		foreach ( $orbat as &$squad ) {
			foreach ( $squad['slots'] as &$slot ) {
				if ( $slot['id'] === $uuid ) {
					if ( !empty($slot['usuario_id']) ) wp_send_json_error( 'Ocupado' );
					$slot['usuario_id'] = get_current_user_id();
					update_post_meta( $post_id, 'orbat_activo', $orbat );
					wp_send_json_success( 'Reservado' );
				}
			}
		}
		wp_send_json_error( 'Error' );
	}

	public function handle_slot_leave() {
		check_ajax_referer( 'rmm_frontend_nonce', 'nonce' );
		if ( ! is_user_logged_in() ) wp_send_json_error( 'Sin permisos' );

		$post_id = intval($_POST['post_id']);
		$uuid = sanitize_text_field($_POST['uuid']);
		$orbat = get_post_meta( $post_id, 'orbat_activo', true );
		$current_user_id = get_current_user_id();

		foreach ( $orbat as &$squad ) {
			foreach ( $squad['slots'] as &$slot ) {
				if ( $slot['id'] === $uuid ) {
					if ( (int)$slot['usuario_id'] !== $current_user_id ) wp_send_json_error( 'No puedes liberar un slot que no es tuyo.' );
					$slot['usuario_id'] = null; // Liberar el slot
					update_post_meta( $post_id, 'orbat_activo', $orbat );
					wp_send_json_success( 'Slot liberado' );
				}
			}
		}
		wp_send_json_error( 'Slot no encontrado' );
	}

	private function get_user_medal_ids( $user_id ) {
		global $wpdb;
		return $wpdb->get_col( $wpdb->prepare("SELECT condecoracion_id FROM {$wpdb->prefix}operador_condecoraciones WHERE usuario_id = %d", $user_id) );
	}

	private function get_missing_medals( $required, $user_medals ) {
		if ( empty($required) ) return array();
		$missing = array_diff( $required, $user_medals );
		return array_map( 'get_the_title', $missing );
	}

	public function render_missions_grid_shortcode( $atts ) {
		// Desactivar renderizado en el editor de Elementor para evitar conflictos
		if ( class_exists( '\Elementor\Plugin' ) && \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
			return '<div style="padding:20px; background:#1a1a1a; border:1px solid #333; color:#849b4c; text-align:center; font-family:monospace;">[rmm_missions_grid] - Vista previa desactivada en el editor para evitar conflictos.</div>';
		}

		$a = shortcode_atts( array(
			'posts_per_page' => 8,
		), $atts );

		$query = new WP_Query( array(
			'post_type' => 'misiones',
			'posts_per_page' => $a['posts_per_page'],
			'post_status' => 'publish',
		) );

		if ( ! $query->have_posts() ) {
			return '<p>No hay misiones publicadas.</p>';
		}

		$authors = array();
		$cards_html = '';

		while ( $query->have_posts() ) : $query->the_post(); 
			$post_id = get_the_ID();
			$author = get_post_meta( $post_id, 'rmm_author', true ) ?: 'Autor desconocido';
			$authors[] = $author;
			
			$addons = get_post_meta( $post_id, 'addons_requeridos', true );
			$addons_count = is_array($addons) ? count($addons) : 0;
			
			// Check for ACE/RHS
			$has_ace = false;
			$has_rhs = false;
			if ( is_array($addons) ) {
				foreach ( $addons as $addon ) {
					if ( stripos( $addon, 'ACE' ) !== false ) $has_ace = true;
					if ( stripos( $addon, 'RHS' ) !== false ) $has_rhs = true;
				}
			}

			// Count slots in ORBAT
			$orbat = get_post_meta( $post_id, 'orbat_maestro', true );
			$slots_count = 0;
			if ( is_array($orbat) ) {
				foreach ( $orbat as $squad ) {
					if ( isset($squad['slots']) ) {
						$slots_count += count($squad['slots']);
					}
				}
			}

			$thumb_url = get_the_post_thumbnail_url( $post_id, 'large' ) ?: 'https://via.placeholder.com/800x450?text=Sin+Imagen';

			ob_start();
			?>
			<a href="<?php the_permalink(); ?>" class="rmm-grid-card" data-author="<?php echo esc_attr($author); ?>" data-ace="<?php echo $has_ace ? '1' : '0'; ?>" data-rhs="<?php echo $has_rhs ? '1' : '0'; ?>" data-slots="<?php echo $slots_count; ?>" data-addons="<?php echo $addons_count; ?>">
				<div class="rmm-grid-thumb" style="background-image: url('<?php echo esc_url($thumb_url); ?>');">
					<div class="rmm-grid-thumb-overlay">
						<span class="rmm-grid-stat">📦 <?php echo $addons_count; ?> Addons</span>
						<div class="rmm-grid-badges">
							<?php if ($has_ace) : ?><span class="rmm-badge rmm-badge-ace">ACE</span><?php endif; ?>
							<?php if ($has_rhs) : ?><span class="rmm-badge rmm-badge-rhs">RHS</span><?php endif; ?>
							<?php if ($slots_count > 0) : ?><span class="rmm-badge rmm-badge-slots">👥 <?php echo $slots_count; ?></span><?php endif; ?>
						</div>
					</div>
				</div>
				<div class="rmm-grid-info">
					<h3 class="rmm-grid-title"><?php the_title(); ?></h3>
					<span class="rmm-grid-author">by <?php echo esc_html($author); ?></span>
				</div>
			</a>
			<?php
			$cards_html .= ob_get_clean();
		endwhile; 
		wp_reset_postdata();

		$unique_authors = array_unique($authors);

		ob_start();
		?>
		<div class="rmm-grid-filters">
			<div class="rmm-filter-group">
				<label for="rmm-filter-author">Autor:</label>
				<select id="rmm-filter-author">
					<option value="">Todos</option>
					<?php foreach ($unique_authors as $ua) : ?>
						<option value="<?php echo esc_attr($ua); ?>"><?php echo esc_html($ua); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="rmm-filter-group">
				<label for="rmm-filter-min-slots">Slots Mín.:</label>
				<input type="number" id="rmm-filter-min-slots" min="0" placeholder="0" style="width: 60px; background: #2a2a2a; color: #fff; border: 1px solid #444; padding: 5px; border-radius: 3px;">
			</div>
			<div class="rmm-filter-group">
				<label for="rmm-filter-max-addons">Addons Máx.:</label>
				<input type="number" id="rmm-filter-max-addons" min="0" placeholder="Máx" style="width: 60px; background: #2a2a2a; color: #fff; border: 1px solid #444; padding: 5px; border-radius: 3px;">
			</div>
			<div class="rmm-filter-group">
				<span>ACE:</span>
				<label class="rmm-switch">
					<input type="checkbox" id="rmm-filter-ace" checked>
					<span class="rmm-slider round"></span>
				</label>
			</div>
			<div class="rmm-filter-group">
				<span>RHS:</span>
				<label class="rmm-switch">
					<input type="checkbox" id="rmm-filter-rhs" checked>
					<span class="rmm-slider round"></span>
				</label>
			</div>
		</div>

		<div class="rmm-missions-grid">
			<?php echo $cards_html; ?>
		</div>

		<style>
			.rmm-missions-grid {
				display: grid;
				grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
				gap: 20px;
				padding: 20px 0;
			}
			.rmm-grid-card {
				background: #1a1a1a;
				border: 1px solid #333;
				border-radius: 4px;
				overflow: hidden;
				text-decoration: none !important;
				transition: transform 0.2s, border-color 0.2s;
				display: flex;
				flex-direction: column;
			}
			.rmm-grid-card:hover {
				transform: translateY(-5px);
				border-color: #849b4c;
			}
			.rmm-grid-thumb {
				height: 160px;
				background-size: cover;
				background-position: center;
				position: relative;
			}
			.rmm-grid-thumb-overlay {
				position: absolute;
				bottom: 0;
				left: 0;
				right: 0;
				background: linear-gradient(transparent, rgba(0,0,0,0.8));
				padding: 10px;
				display: flex;
				justify-content: space-between;
				align-items: center;
				color: #fff;
				font-size: 11px;
			}
			.rmm-grid-stat {
				font-weight: bold;
			}
			.rmm-grid-badges {
				display: flex;
				gap: 5px;
			}
			.rmm-badge {
				padding: 2px 6px;
				border-radius: 3px;
				font-size: 10px;
				font-weight: bold;
				text-transform: uppercase;
			}
			.rmm-badge-ace { background: #4CAF50; color: #fff; }
			.rmm-badge-rhs { background: #2196F3; color: #fff; }
			.rmm-badge-slots { background: rgba(255,255,255,0.1); color: #fff; }
			.rmm-grid-info {
				padding: 15px;
				display: flex;
				flex-direction: column;
				gap: 5px;
			}
			.rmm-grid-title {
				margin: 0;
				font-size: 14px;
				color: #849b4c; /* Color táctico */
				text-transform: uppercase;
				letter-spacing: 0.5px;
			}
			.rmm-grid-author {
				font-size: 12px;
				color: #888;
			}
			/* Estilos de Filtros */
			.rmm-grid-filters {
				display: flex;
				flex-wrap: wrap;
				gap: 20px;
				align-items: center;
				margin-bottom: 20px;
				background: #1a1a1a;
				padding: 15px;
				border: 1px solid #333;
				border-radius: 4px;
			}
			.rmm-filter-group {
				display: flex;
				align-items: center;
				gap: 10px;
				color: #888;
				font-size: 14px;
			}
			.rmm-filter-group select {
				background: #2a2a2a;
				color: #fff;
				border: 1px solid #444;
				padding: 5px 10px;
				border-radius: 3px;
			}
			/* Estilos de Switch */
			.rmm-switch {
			  position: relative;
			  display: inline-block;
			  width: 40px;
			  height: 20px;
			}
			.rmm-switch input {
			  opacity: 0;
			  width: 0;
			  height: 0;
			}
			.rmm-slider {
			  position: absolute;
			  cursor: pointer;
			  top: 0;
			  left: 0;
			  right: 0;
			  bottom: 0;
			  background-color: #2a2a2a;
			  border: 1px solid #444;
			  transition: .4s;
			}
			.rmm-slider:before {
			  position: absolute;
			  content: "";
			  height: 14px;
			  width: 14px;
			  left: 2px;
			  bottom: 2px;
			  background-color: #888;
			  transition: .4s;
			}
			input:checked + .rmm-slider {
			  background-color: #849b4c;
			  border-color: #849b4c;
			}
			input:checked + .rmm-slider:before {
			  background-color: #fff;
			  transform: translateX(20px);
			}
			.rmm-slider.round {
			  border-radius: 20px;
			}
			.rmm-slider.round:before {
			  border-radius: 50%;
			}
		</style>

		<script>
			jQuery(document).ready(function($) {
				const cards = $('.rmm-grid-card');
				const authorSelect = $('#rmm-filter-author');
				const minSlotsInput = $('#rmm-filter-min-slots');
				const maxAddonsInput = $('#rmm-filter-max-addons');
				const aceSwitch = $('#rmm-filter-ace');
				const rhsSwitch = $('#rmm-filter-rhs');

				function applyFilters() {
					const selectedAuthor = authorSelect.val();
					const minSlots = parseInt(minSlotsInput.val()) || 0;
					const maxAddons = parseInt(maxAddonsInput.val()) || Infinity;
					const filterAce = aceSwitch.is(':checked');
					const filterRhs = rhsSwitch.is(':checked');

					cards.each(function() {
						const card = $(this);
						const author = card.data('author');
						const hasAce = card.data('ace') == '1';
						const hasRhs = card.data('rhs') == '1';
						const slots = parseInt(card.data('slots')) || 0;
						const addons = parseInt(card.data('addons')) || 0;

						let show = true;

						if (selectedAuthor && author !== selectedAuthor) show = false;
						
						// Si el switch está ON, debe tener el addon. Si está OFF, NO debe tenerlo.
						if (filterAce && !hasAce) show = false;
						if (!filterAce && hasAce) show = false;
						
						if (filterRhs && !hasRhs) show = false;
						if (!filterRhs && hasRhs) show = false;
						
						if (slots < minSlots) show = false;
						if (addons > maxAddons) show = false;

						if (show) {
							card.fadeIn(200);
						} else {
							card.fadeOut(200);
						}
					});
				}

				// Aplicar filtros al cargar para respetar los switches activos por defecto
				applyFilters();

				authorSelect.on('change', applyFilters);
				minSlotsInput.on('input', applyFilters);
				maxAddonsInput.on('input', applyFilters);
				aceSwitch.on('change', applyFilters);
				rhsSwitch.on('change', applyFilters);
			});
		</script>
		<?php
		return ob_get_clean();
	}
}
