<?php
/**
 * Raid Request Handler
 * Shortcode [clan_solicitar_raid] para solicitar RAIDs desde la web
 * Roles permitidos: activo, aliado
 * 
 * @package ReforgerMilsimManagement
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class RMM_Raid_Handler {

	public function __construct() {
		add_shortcode( 'clan_solicitar_raid', array( $this, 'render_raid_form' ) );
		add_action( 'wp_ajax_rmm_send_raid_request', array( $this, 'ajax_send_raid_request' ) );
	}

	/**
	 * Verifica si el usuario actual tiene rol permitido
	 */
	private function user_can_request() {
		if ( ! is_user_logged_in() ) return false;
		$user = wp_get_current_user();
		$allowed = array( 'activo', 'aliado' );
		return (bool) array_intersect( $allowed, (array) $user->roles );
	}

	/**
	 * Renderiza el formulario de solicitud de RAID
	 */
	public function render_raid_form( $atts ) {
		if ( ! $this->user_can_request() ) {
			return '<div class="rmm-raid-widget" style="background:#0d1117; border:1px solid #21262d; border-radius:8px; padding:24px; text-align:center; color:#8b949e; font-family:\'Inter\',sans-serif;">
				<i class="fa-solid fa-lock" style="font-size:2rem; display:block; margin-bottom:12px; color:#484f58;"></i>
				<p style="font-size:0.85rem; margin:0;">' . __( 'Solo los miembros con rango <strong>Activo</strong> o <strong>Aliado</strong> pueden solicitar RAIDs.', 'reforger-milsim' ) . '</p>
			</div>';
		}

		$user = wp_get_current_user();
		$today = date('Y-m-d');
		$max_date = date('Y-m-d', strtotime('+14 days'));

		ob_start();
		?>
		<div class="rmm-raid-widget" style="background:#0d1117; border:1px solid #21262d; border-radius:8px; padding:24px; font-family:'Inter',sans-serif; color:#c9d1d9;">
			<h3 style="font-size:0.8rem; font-weight:700; text-transform:uppercase; letter-spacing:0.06em; color:#58a6ff; margin:0 0 20px; display:flex; align-items:center; gap:8px;">
				<i class="fa-solid fa-crosshairs"></i> <?php _e( 'Solicitar una RAID', 'reforger-milsim' ); ?>
			</h3>

			<form id="rmm-raid-form" onsubmit="return false;" style="display:flex; flex-direction:column; gap:14px;">
				
				<!-- Fecha -->
				<div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
					<div>
						<label style="display:block; font-size:0.6rem; text-transform:uppercase; letter-spacing:0.05em; color:#8b949e; margin-bottom:4px;">
							<i class="fa-solid fa-calendar-days"></i> <?php _e( 'Fecha', 'reforger-milsim' ); ?>
						</label>
						<input type="date" id="raid_date" name="raid_date" 
							min="<?php echo $today; ?>" max="<?php echo $max_date; ?>"
							value="<?php echo $today; ?>"
							required
							style="width:100%; background:#161b22; border:1px solid #30363d; border-radius:6px; padding:10px 12px; color:#c9d1d9; font-family:'Inter',sans-serif; font-size:0.85rem;">
					</div>
					
					<!-- Hora -->
					<div>
						<label style="display:block; font-size:0.6rem; text-transform:uppercase; letter-spacing:0.05em; color:#8b949e; margin-bottom:4px;">
							<i class="fa-solid fa-clock"></i> <?php _e( 'Hora', 'reforger-milsim' ); ?>
						</label>
						<select id="raid_time" name="raid_time" required
							style="width:100%; background:#161b22; border:1px solid #30363d; border-radius:6px; padding:10px 12px; color:#c9d1d9; font-family:'Inter',sans-serif; font-size:0.85rem;">
							<option value="">-- <?php _e( 'Selecciona hora', 'reforger-milsim' ); ?> --</option>
							<?php
							for ( $h = 15; $h <= 23; $h++ ) {
								for ( $m = 0; $m < 60; $m += 30 ) {
									$time = sprintf( '%02d:%02d', $h, $m );
									$label = sprintf( '%02d:%02dh', $h, $m );
									echo "<option value=\"$time\">$label</option>";
								}
							}
							?>
						</select>
					</div>
				</div>

				<!-- Servidor -->
							<div>
								<label style="display:block; font-size:0.6rem; text-transform:uppercase; letter-spacing:0.05em; color:#8b949e; margin-bottom:4px;">
									<i class="fa-solid fa-server"></i> <?php _e( 'Servidor', 'reforger-milsim' ); ?>
								</label>
								<input type="text" id="raid_server" name="raid_server" 
									value="<?php echo esc_attr( get_option( 'rmm_ptero_stable_server_id', '' ) ? 'STABLE' : '' ); ?>"
									placeholder="Ej: STABLE / TESTING"
									style="width:100%; background:#161b22; border:1px solid #30363d; border-radius:6px; padding:10px 12px; color:#c9d1d9; font-family:'Inter',sans-serif; font-size:0.85rem;">
							</div>

							<!-- Notas -->
				<div>
					<label style="display:block; font-size:0.6rem; text-transform:uppercase; letter-spacing:0.05em; color:#8b949e; margin-bottom:4px;">
						<i class="fa-solid fa-note-sticky"></i> <?php _e( 'Notas / Requisitos (opcional)', 'reforger-milsim' ); ?>
					</label>
					<textarea id="raid_notes" name="raid_notes" rows="3"
						placeholder="Ej: Llevad mods actualizados, traed vehículos, etc."
						style="width:100%; background:#161b22; border:1px solid #30363d; border-radius:6px; padding:10px 12px; color:#c9d1d9; font-family:'Inter',sans-serif; font-size:0.85rem; resize:vertical; min-height:60px;"></textarea>
				</div>

				<!-- Botón -->
				<button type="button" id="btn_send_raid" class="rmm-btn btn-primary" style="
					background: #101010;
					border: 1px solid #CFDC35;
									border-radius: 6px;
					padding: 12px 24px;
					font-size: 0.8rem;
					font-weight: 700;
					text-transform: uppercase;
					letter-spacing: 0.05em;
					color: #CFDC35;
					cursor: pointer;
					transition: all 0.2s ease;
					display: flex;
					align-items: center;
					justify-content: center;
					gap: 8px;
				" onmouseover="this.style.filter='brightness(1.1)'" onmouseout="this.style.filter='brightness(1)'">
					<i class="fa-solid fa-paper-plane"></i> <?php _e( 'Enviar Solicitud de RAID', 'reforger-milsim' ); ?>
				</button>

				<div id="raid_status" style="text-align:center; font-size:0.8rem; min-height:20px;"></div>
			</form>
		</div>

		<script>
		jQuery(document).ready(function($) {
			$('#rmm-raid-form input, #rmm-raid-form select, #rmm-raid-form textarea').on('focus', function() {
				$(this).css({
					'border-color': '#CFDC35',
										'outline': 'none',
										'box-shadow': '0 0 0 2px rgba(207,220,53,0.15)'
				});
			}).on('blur', function() {
				$(this).css({
					'border-color': '#30363d',
					'box-shadow': 'none'
				});
			});

			$('#btn_send_raid').on('click', function() {
				var date = $('#raid_date').val();
				var time = $('#raid_time').val();
				var server = $('#raid_server').val().trim();
							var notes = $('#raid_notes').val().trim();

				if (!date || !time) {
					$('#raid_status').html('<span style="color:#ef4444;"><i class="fa-solid fa-circle-xmark"></i> Selecciona fecha y hora.</span>');
					return;
				}

				var btn = $(this);
				btn.prop('disabled', true).css('opacity', '0.6');
				$('#raid_status').html('<span style="color:#f59e0b;"><i class="fa-solid fa-spinner fa-spin"></i> Enviando solicitud...</span>');

				$.post(ajaxurl, {
								action: 'rmm_send_raid_request',
								date: date,
								time: time,
								server: server,
								notes: notes,
								_ajax_nonce: '<?php echo wp_create_nonce( "rmm_raid_request" ); ?>'
							}, function(response) {
								btn.prop('disabled', false).css('opacity', '1');
								if (response.success) {
									$('#raid_status').html('<span style="color:#22c55e;"><i class="fa-solid fa-circle-check"></i> ' + response.data + '</span>');
									$('#raid_notes').val('');
								} else {
						$('#raid_status').html('<span style="color:#ef4444;"><i class="fa-solid fa-circle-xmark"></i> ' + (response.data || 'Error al enviar') + '</span>');
					}
				}).fail(function() {
					btn.prop('disabled', false).css('opacity', '1');
					$('#raid_status').html('<span style="color:#ef4444;"><i class="fa-solid fa-circle-xmark"></i> Error de conexión</span>');
				});
			});

			// Preseleccionar siguiente hora redonda
			var now = new Date();
			var currentHour = now.getHours();
			var currentMinute = now.getMinutes();
			// Si es antes de las 15h, default 20:00; si es después de las 23h, mañana
			if (currentHour < 15) {
				$('#raid_time').val('20:00');
			} else if (currentHour >= 23) {
				var tomorrow = new Date(now);
				tomorrow.setDate(tomorrow.getDate() + 1);
				$('#raid_date').val(tomorrow.toISOString().split('T')[0]);
				$('#raid_time').val('20:00');
			} else {
				// Redondear a la siguiente media hora
				var nextHour = currentHour;
				var nextMinute = currentMinute < 30 ? 30 : 0;
				if (nextMinute === 0) nextHour++;
				if (nextHour > 23) nextHour = 20;
				$('#raid_time').val(
					String(nextHour).padStart(2,'0') + ':' + String(nextMinute).padStart(2,'0')
				);
			}
		});
		</script>
		<?php
		return ob_get_clean();
	}

	/**
	 * AJAX: Enviar solicitud de RAID a Telegram
	 */
	public function ajax_send_raid_request() {
		if ( ! $this->user_can_request() ) {
			wp_send_json_error( __( 'No tienes permisos para solicitar RAIDs.', 'reforger-milsim' ) );
		}

		check_ajax_referer( 'rmm_raid_request' );

		$user = wp_get_current_user();
		$date = isset( $_POST['date'] ) ? sanitize_text_field( $_POST['date'] ) : '';
		$time = isset( $_POST['time'] ) ? sanitize_text_field( $_POST['time'] ) : '';
		$server = isset( $_POST['server'] ) ? sanitize_text_field( $_POST['server'] ) : '';
		$password = get_option( 'rmm_raid_password', '' );
		$notes = isset( $_POST['notes'] ) ? sanitize_textarea_field( $_POST['notes'] ) : '';

		if ( empty( $date ) || empty( $time ) ) {
			wp_send_json_error( __( 'Fecha y hora son obligatorios.', 'reforger-milsim' ) );
		}

		// Validar que la fecha no sea pasada
		$raid_datetime = strtotime( "$date $time" );
		if ( $raid_datetime < time() ) {
			wp_send_json_error( __( 'La fecha y hora no pueden ser pasadas.', 'reforger-milsim' ) );
		}

		// Formatear fecha al estilo del bot
		$dt = new DateTime( $date );
		$days = array(
			'Monday'    => 'Lunes',
			'Tuesday'   => 'Martes',
			'Wednesday' => 'Miércoles',
			'Thursday'  => 'Jueves',
			'Friday'    => 'Viernes',
			'Saturday'  => 'Sábado',
			'Sunday'    => 'Domingo'
		);
		$dia_semana = $days[ $dt->format('l') ] ?? $dt->format('l');
		$dia_num = $dt->format('j');
		$date_formatted = "$dia_semana $dia_num";

		// Construir mensaje
		$msg = "📢 <b>Solicitud de RAID desde la Web</b>\n\n";
		$msg .= "📅 <b>$date_formatted</b>\n";
		$msg .= "🕒 <b>$time</b>h\n";
		$msg .= "👤 Solicitante: <b>" . esc_html( $user->display_name ) . "</b>\n";

		if ( ! empty( $server ) ) {
			$msg .= "🏷 Servidor: <b>[=TFR=] $server</b>\n";
		}
		if ( ! empty( $password ) ) {
			$msg .= "🔑 Contraseña: <code>$password</code>\n";
		}
		if ( ! empty( $notes ) ) {
			$msg .= "\n📝 <b>Notas:</b>\n" . esc_html( $notes ) . "\n";
		}

		$msg .= "\n<i>Solicitud enviada desde la web de gestión.</i>";

		// Enviar a Telegram
		try {
			$ptero = new RMM_Pterodactyl_Handler();
			$text = wp_strip_all_tags( $msg );
			$result = $ptero->notify_telegram( $text );

			if ( $result ) {
				wp_send_json_success( __( '¡Solicitud enviada al chat de Telegram!', 'reforger-milsim' ) );
			} else {
				wp_send_json_error( __( 'No se pudo enviar a Telegram. Verifica la configuración.', 'reforger-milsim' ) );
			}
		} catch ( Exception $e ) {
			wp_send_json_error( $e->getMessage() );
		}
	}
}
