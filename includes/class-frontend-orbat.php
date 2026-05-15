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
		?>
		<div class="rmm-orbat space-y-6">
			<?php foreach ( $orbat as $squad ) : ?>
				<div class="bg-gray-900 border border-gray-800 rounded">
					<div class="px-4 py-2 bg-blue-900/20 border-b border-blue-900/40">
						<h3 class="text-blue-400 font-bold uppercase text-sm"><?php echo esc_html($squad['escuadra']); ?></h3>
					</div>
					<div class="grid grid-cols-1 md:grid-cols-3 gap-2 p-2">
						<?php foreach ( $squad['slots'] as $slot ) : ?>
							<?php 
								$occupied = !empty($slot['usuario_id']);
								$user = $occupied ? get_userdata($slot['usuario_id']) : null;
								$missing = $this->get_missing_medals($slot['condecoraciones_requeridas'], $user_medals);
								$can_reserve = empty($missing) && current_user_can('reserve_orbat_slot');
							?>
							<div class="p-3 border rounded <?php echo $occupied ? 'bg-green-900/10 border-green-900/30' : 'bg-gray-800 border-gray-700'; ?>">
								<div class="flex justify-between mb-2">
									<span class="text-[10px] text-gray-500 uppercase font-bold"><?php echo esc_html($slot['rol']); ?></span>
								</div>
								<?php if ($occupied) : ?>
									<span class="text-sm text-white font-medium"><?php echo esc_html($user->display_name); ?></span>
								<?php elseif ($can_reserve) : ?>
									<button data-uuid="<?php echo esc_attr($slot['id']); ?>" data-post-id="<?php echo $post_id; ?>" class="rmm-reserve-btn w-full py-1 bg-blue-600 text-white text-[10px] font-bold uppercase rounded">Reclamar</button>
								<?php else : ?>
									<button disabled class="w-full py-1 bg-gray-700 text-gray-500 text-[10px] font-bold uppercase rounded">Bloqueado</button>
									<?php if(!empty($missing)) : ?><p class="text-[9px] text-red-500 mt-1">Faltan: <?php echo implode(', ', $missing); ?></p><?php endif; ?>
								<?php endif; ?>
							</div>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
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
