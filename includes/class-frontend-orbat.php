<?php
/**
 * Frontend ORBAT & Reservations Class
 * @package ReforgerMilsimManagement
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class RMM_Frontend_ORBAT {

	public function __construct() {
		add_shortcode( 'clan_orbat', array( $this, 'render_orbat_shortcode' ) );
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

	public function render_orbat_shortcode( $atts ) {
		$post_id = get_the_ID();
		if ( get_post_type($post_id) !== 'eventos_partidas' ) return '';

		$orbat = get_post_meta( $post_id, 'orbat_activo', true );
		if ( empty($orbat) ) return '<p>No hay ORBAT definido.</p>';

		$current_user_id = get_current_user_id();
		$user_medals = $this->get_user_medal_ids( $current_user_id );

		ob_start();
		
		// Addons / Dependencies Section
		// Si es un evento, los addons están guardados en su misión enlazada.
		$mission_id = get_post_meta( $post_id, 'mision_id', true );
		$target_id  = !empty( $mission_id ) ? $mission_id : $post_id;
		$addons = get_post_meta( $target_id, 'addons_requeridos', true );
		
		if ( !empty($addons) && is_array($addons) ) :
		?>
		<div class="rmm-addons-box">
			<h4 class="rmm-addons-title">📦 Addons Requeridos</h4>
			<ul class="rmm-addons-list">
				<?php foreach ( $addons as $addon ) : ?>
					<li><?php echo esc_html($addon); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php endif; ?>

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
								$can_reserve = empty($missing) && current_user_can('reserve_orbat_slot');
							?>
							<div class="rmm-slot-card <?php echo $occupied ? 'is-occupied' : 'is-vacant'; ?>">
								<div class="rmm-slot-role">
									<span><?php echo esc_html($slot['rol']); ?></span>
								</div>
								<div class="rmm-slot-action">
								<?php if ($occupied) : ?>
									<span class="rmm-slot-user"><?php echo esc_html($user->display_name); ?></span>
									<?php if ( $slot['usuario_id'] == $current_user_id ) : ?>
										<button data-uuid="<?php echo esc_attr($slot['id']); ?>" data-post-id="<?php echo $post_id; ?>" class="elementor-button elementor-size-sm rmm-leave-btn" style="background-color:#dc3232; margin-top:5px; padding:5px 10px; font-size:10px;">
											<span class="elementor-button-content-wrapper"><span class="elementor-button-text">Desapuntarse</span></span>
										</button>
									<?php endif; ?>
								<?php elseif ($can_reserve) : ?>
									<button data-uuid="<?php echo esc_attr($slot['id']); ?>" data-post-id="<?php echo $post_id; ?>" class="elementor-button elementor-size-sm rmm-reserve-btn">
										<span class="elementor-button-content-wrapper"><span class="elementor-button-text">Reclamar</span></span>
									</button>
								<?php else : ?>
									<button disabled class="elementor-button elementor-size-sm rmm-locked-btn">
										<span class="elementor-button-content-wrapper"><span class="elementor-button-text">Bloqueado</span></span>
									</button>
									<?php if(!empty($missing)) : ?><p class="rmm-missing-medals">Faltan: <?php echo implode(', ', $missing); ?></p><?php endif; ?>
								<?php endif; ?>
								</div>
							</div>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
		
		<style>
			/* CSS Estructural para integración con Elementor */
			.rmm-addons-box { margin-bottom: 25px; padding: 15px; border-left: 4px solid var(--e-global-color-primary, #2271b1); background-color: rgba(0,0,0,0.03); }
			.rmm-addons-title { margin: 0 0 10px 0; font-size: 1.1em; color: var(--e-global-color-secondary, inherit); }
			.rmm-addons-list { margin: 0; padding-left: 20px; list-style-type: disc; font-size: 0.9em; opacity: 0.8; }
			
			.rmm-orbat-wrapper { display: flex; flex-direction: column; gap: 20px; font-family: var(--e-global-typography-text-font-family), inherit; }
			.rmm-squad-container { border: 1px solid rgba(128,128,128,0.2); border-radius: 4px; overflow: hidden; background: transparent; }
			.rmm-squad-header { padding: 10px 15px; background: rgba(0,0,0,0.05); border-bottom: 1px solid rgba(128,128,128,0.2); }
			.rmm-squad-name { margin: 0; font-size: 1.2em; font-weight: bold; color: var(--e-global-color-primary, inherit); text-transform: uppercase; }
			
			.rmm-slots-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 10px; padding: 10px; }
			.rmm-slot-card { padding: 15px; border: 1px solid rgba(128,128,128,0.2); border-radius: 4px; display: flex; flex-direction: column; justify-content: space-between; gap: 10px; transition: background 0.2s; }
			.rmm-slot-card.is-occupied { background: rgba(0,200,0,0.05); border-color: rgba(0,200,0,0.2); }
			
			.rmm-slot-role { font-size: 0.85em; text-transform: uppercase; font-weight: 700; opacity: 0.7; border-bottom: 1px solid rgba(128,128,128,0.2); padding-bottom: 5px; }
			.rmm-slot-user { font-size: 1.1em; font-weight: 600; color: var(--e-global-color-text, inherit); }
			
			.rmm-locked-btn { background-color: rgba(128,128,128,0.2) !important; color: inherit !important; opacity: 0.7; cursor: not-allowed; width: 100%; border: none !important; }
			.rmm-reserve-btn { width: 100%; border-radius: 3px; }
			.rmm-missing-medals { font-size: 0.75em; color: #dc3232; margin: 5px 0 0 0; text-align: center; }
		</style>
		<?php
		return ob_get_clean();
	}

	public function handle_slot_reservation() {
		check_ajax_referer( 'rmm_frontend_nonce', 'nonce' );
		if ( ! is_user_logged_in() || ! current_user_can('reserve_orbat_slot') ) wp_send_json_error( 'Sin permisos' );

		$post_id = intval($_POST['post_id']);
		$uuid = sanitize_text_field($_POST['uuid']);
		$orbat = get_post_meta( $post_id, 'orbat_activo', true );

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
					if ( $slot['usuario_id'] != $current_user_id ) wp_send_json_error( 'No puedes liberar un slot que no es tuyo.' );
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
