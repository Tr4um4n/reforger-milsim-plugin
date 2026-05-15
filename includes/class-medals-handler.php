<?php
/**
 * Medals & Ribbon Rack Handler Class
 * @package ReforgerMilsimManagement
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class RMM_Medals_Handler {

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_medal_submenu' ) );
		add_shortcode( 'clan_pasador_medallas', array( $this, 'render_ribbon_rack' ) );
	}

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
		echo '<div class="notice notice-success is-dismissible"><p>Condecoración otorgada.</p></div>';
	}

	public function render_ribbon_rack( $atts ) {
		global $wpdb;
		$atts = shortcode_atts( array( 'user_id' => get_current_user_id() ), $atts );
		$user_id = intval( $atts['user_id'] );
		if ( ! $user_id ) return '';

		$user_data = get_userdata( $user_id );
		$query = $wpdb->prepare(
			"SELECT oc.motivo, p.ID, p.post_title FROM {$wpdb->prefix}operador_condecoraciones oc
			 JOIN {$wpdb->posts} p ON oc.condecoracion_id = p.ID
			 WHERE oc.usuario_id = %d",
			$user_id
		);

		$medals = $wpdb->get_results( $query );
		if ( empty($medals) ) return '';

		ob_start();
		?>
		<div class="rmm-ribbon-rack max-w-fit">
			<div class="bg-black text-gray-400 px-3 py-1 text-xs font-bold uppercase"><?php echo esc_html( $user_data->display_name ); ?></div>
			<div class="grid grid-cols-6 gap-0 bg-gray-900 border-2 border-gray-900">
				<?php foreach ( $medals as $m ) : ?>
					<img src="<?php echo get_the_post_thumbnail_url( $m->ID, 'metopa-militar' ); ?>" 
						 title="<?php echo esc_attr( $m->post_title . ' - ' . $m->motivo ); ?>"
						 class="w-[120px] h-[35px] block object-cover grayscale hover:grayscale-0 transition-all">
				<?php endforeach; ?>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}
}
