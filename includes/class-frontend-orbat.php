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
		add_shortcode( 'fecha_evento', array( $this, 'render_fecha_evento_shortcode' ) );
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
		
		return ucfirst( wp_date( 'l, j \d\e F \a \l\a\s H:i', strtotime( $fecha_inicio ) ) );
	}

	public function render_legacy_orbat_shortcode( $atts ) {
		return $this->render_rmm_orbat_shortcode($atts) . $this->render_addons_list_shortcode($atts);
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

	public function render_rmm_orbat_shortcode( $atts ) {
		$post_id = get_the_ID();
		$post_type = get_post_type($post_id);
		
		if ( ! in_array( $post_type, array( 'eventos_partidas', 'misiones' ) ) ) return '';

		if ( $post_type === 'misiones' ) {
			$orbat = get_post_meta( $post_id, 'orbat_maestro', true );
		} else {
			$orbat = get_post_meta( $post_id, 'orbat_activo', true );
		}

		if ( empty($orbat) ) return '<p>No hay ORBAT definido.</p>';

		$current_user_id = get_current_user_id();
		$user_medals = $this->get_user_medal_ids( $current_user_id );

		ob_start();
		
		$current_user_id = get_current_user_id();
		$user_medals = $this->get_user_medal_ids( $current_user_id );
		
		// ORBAT Grid First
		?>
		<div class="rmm-orbat-wrapper">
			<?php foreach ( $orbat as $squad ) : ?>
				<div class="rmm-squad-container">
					<div class="rmm-squad-header">
						<h3 class="rmm-squad-name"><?php echo esc_html($squad['escuadra']); ?></h3>
					</div>
					<div class="rmm-slots-grid">
						<?php foreach ( $squad['slots'] as $slot ) : ?>
							<?php 
								$occupied = !empty($slot['usuario_id']);
								$user = $occupied ? get_userdata($slot['usuario_id']) : null;
								$missing = $this->get_missing_medals($slot['condecoraciones_requeridas'], $user_medals);
								$can_reserve = empty($missing) && current_user_can('reserve_orbat_slot') && $post_type === 'eventos_partidas';
							?>
							<div class="rmm-slot-card <?php echo $occupied ? 'is-occupied' : 'is-vacant'; ?>">
								<div class="rmm-slot-role">
									<?php echo esc_html(strtoupper($slot['rol'] ?: 'Líder de Escuadra')); ?>
								</div>
								<div class="rmm-slot-action">
								<?php if ($occupied) : ?>
									<span class="rmm-slot-user"><?php echo esc_html($user->display_name); ?></span>
									<?php if ( (int)$slot['usuario_id'] === $current_user_id && $post_type === 'eventos_partidas' ) : ?>
										<button data-uuid="<?php echo esc_attr($slot['id']); ?>" data-post-id="<?php echo $post_id; ?>" class="elementor-button elementor-size-sm rmm-leave-btn" style="background-color:#dc3232; margin-top:5px; padding:5px 10px; font-size:10px;">
											<span class="elementor-button-content-wrapper"><span class="elementor-button-text">Desapuntarse</span></span>
										</button>
									<?php endif; ?>
								<?php elseif ($post_type === 'eventos_partidas') : ?>
									<?php if ($can_reserve) : ?>
										<button data-uuid="<?php echo esc_attr($slot['id']); ?>" data-post-id="<?php echo $post_id; ?>" class="elementor-button elementor-size-sm rmm-reserve-btn">
											<span class="elementor-button-content-wrapper"><span class="elementor-button-text">Reclamar</span></span>
										</button>
									<?php else : ?>
										<button disabled class="elementor-button elementor-size-sm rmm-locked-btn">
											<span class="elementor-button-content-wrapper"><span class="elementor-button-text">Bloqueado</span></span>
										</button>
										<?php if(!empty($missing)) : ?><p class="rmm-missing-medals">Faltan: <?php echo implode(', ', $missing); ?></p><?php endif; ?>
									<?php endif; ?>
								<?php else : ?>
									<span class="rmm-slot-status">VACANTE</span>
								<?php endif; ?>
								</div>
							</div>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endforeach; ?>
		</div>

		<?php
		return ob_get_clean();
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
}
