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
		add_shortcode( 'raid_apuntarse', array( $this, 'render_raid_join_button' ) );
				add_shortcode( 'raid_fecha', array( $this, 'render_raid_field' ) );
				add_shortcode( 'raid_hora', array( $this, 'render_raid_field' ) );
				add_shortcode( 'raid_servidor', array( $this, 'render_raid_field' ) );
				add_shortcode( 'raid_participantes', array( $this, 'render_raid_field' ) );
				add_shortcode( 'raid_estado', array( $this, 'render_raid_field' ) );
				add_shortcode( 'raid_justificacion', array( $this, 'render_raid_field' ) );
								add_shortcode( 'raid_notas', array( $this, 'render_raid_field' ) );
												add_shortcode( 'raid_solicitante', array( $this, 'render_raid_field' ) );
						add_shortcode( 'raid_aprobar', array( $this, 'render_raid_approve_buttons' ) );
								add_shortcode( 'raid_lista_participantes', array( $this, 'render_raid_participants_list' ) );
								add_shortcode( 'raid_boton_participar', array( $this, 'render_raid_join_only_button' ) );
										add_shortcode( 'raid_faq', array( $this, 'render_raid_faq' ) );
		add_action( 'wp_ajax_rmm_send_raid_request', array( $this, 'ajax_send_raid_request' ) );
		add_action( 'wp_ajax_rmm_raid_join', array( $this, 'ajax_raid_join' ) );
		add_action( 'wp_ajax_rmm_raid_leave', array( $this, 'ajax_raid_leave' ) );
		add_action( 'wp_ajax_nopriv_rmm_raid_join', '__return_false' );
		add_action( 'wp_ajax_nopriv_rmm_raid_leave', '__return_false' );
				add_action( 'wp_ajax_rmm_raid_decide', array( $this, 'ajax_raid_decide' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_endpoints' ) );
				add_action( 'publish_eventos_partidas', array( $this, 'notify_raid_channel_on_event' ), 10, 2 );
				add_action( 'save_post_eventos_partidas', array( $this, 'handle_event_cancellation' ), 20, 3 );
				add_action( 'wp_ajax_rmm_resend_telegram_event', array( $this, 'ajax_resend_telegram_event' ) );
				add_filter( 'the_content', array( $this, 'inject_raid_join_to_content' ) );
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
		$now_timestamp = current_time( 'timestamp' ); 
		$min_timestamp = $now_timestamp + 3600; 
		$today = date( 'Y-m-d', $now_timestamp ); 
		$min_hour = (int) date( 'H', $min_timestamp ); 
		$min_minute = (int) date( 'i', $min_timestamp ); 
		// Si todas las horas de hoy ya pasaron, empezar mañana 
		if ( $min_hour >= 23 || ( $min_hour == 22 && $min_minute > 30 ) ) { 
			$today = date( 'Y-m-d', strtotime( '+1 day', $now_timestamp ) ); 
			$min_hour = 0; 
			$min_minute = 0; 
		}
		$max_date = date( 'Y-m-d', strtotime( '+14 days', $now_timestamp ) );

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
							for ( $h = 0; $h <= 23; $h++ ) {
								for ( $m = 0; $m < 60; $m += 30 ) {
									$time = sprintf( '%02d:%02d', $h, $m );
									$label = sprintf( '%02d:%02dh', $h, $m );
 	 	 	 	 	 	 	 	 	 	 	if ( $h < $min_hour || ( $h == $min_hour && $m <= $min_minute ) ) continue;
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

				$.post('<?php echo admin_url("admin-ajax.php"); ?>', {
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

			// Usar hora WP para preselección
						var wpNow = new Date(<?php echo current_time('timestamp') * 1000; ?>);
						var currentHour = wpNow.getHours();
						var currentMinute = wpNow.getMinutes();
						var minHour = currentHour + 1;
						var minMinute = currentMinute;
						if (minMinute > 0 && minMinute <= 30) minMinute = 30;
						else if (minMinute > 30) { minHour++; minMinute = 0; }
						if (minHour >= 23) {
							// Mañana
							var tomorrow = new Date(wpNow);
							tomorrow.setDate(tomorrow.getDate() + 1);
							$('#raid_date').val(tomorrow.toISOString().split('T')[0]);
							$('#raid_time').val('20:00');
						} else {
							// Redondear a siguiente en punto o y media
							var nextMinute = currentMinute < 30 ? 30 : 0;
							var nextHour = currentHour + 1;
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

		// Validar que la fecha no sea pasada (usando WP timezone)
				$now_timestamp = current_time( 'timestamp' );
				$raid_datetime = strtotime( "$date $time" );
				if ( $raid_datetime < $now_timestamp + 3600 ) {
					wp_send_json_error( __( 'La hora debe ser al menos 1h desde ahora. Solo en punto o y media.', 'reforger-milsim' ) );
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

					// Crear post tipo raid_eventos
					$post_id = wp_insert_post( array(
						'post_type'    => 'raid_eventos',
						'post_title'   => 'RAID SOLICITADA',
						'post_status'  => 'publish',
						'post_content' => $notes,
						'meta_input'   => array(
												'raid_fecha'      => $date,
												'raid_hora'       => $time . ':00',
												'raid_servidor'   => $server,
												'raid_password'   => $password,
												'raid_estado'     => 'activa',
												'raid_solicitante' => get_current_user_id(),
											),
					));

					if ( is_wp_error( $post_id ) || ! $post_id ) {
						wp_send_json_error( __( 'Error al crear el evento RAID.', 'reforger-milsim' ) );
					}

		// Construir mensaje con enlace de confirmación

		// Construir mensaje con enlace de confirmación

		$msg = "📢 <b>¡Nueva solicitud de misión!</b>\n\n";
		$msg .= "👤 <b>" . esc_html( $user->display_name ) . "</b> ha solicitado crear una misión.\n\n";
		$msg .= "📅 <b>$date_formatted</b>\n";
		$msg .= "🕒 <b>$time</b>h\n";

		if ( ! empty( $server ) ) {
			$msg .= "🏷 Servidor: <b>$server</b>\n";
		}
		if ( ! empty( $notes ) ) {
			$msg .= "📝 <b>Notas:</b> " . esc_html( $notes ) . "\n";
		}

		$msg .= "\n<i>Confirma tu asistencia 👇</i>";

		// Enviar a Telegram (bot de RAIDs)
				try {
					$token = get_option( 'rmm_raid_telegram_token', '' );
					$chat_id = get_option( 'rmm_raid_telegram_chat_id', '-1003157817672' );

					if ( empty( $token ) || empty( $chat_id ) ) {
						wp_send_json_error( __( 'El bot de RAIDs no está configurado. Ve a Ajustes > Configuración.', 'reforger-milsim' ) );
					}

					$url = "https://api.telegram.org/bot{$token}/sendMessage";

					// URL base para confirmación (web) — página de la raid
									$site_url = get_site_url();
									$raid_url = get_permalink( $post_id ) . '?tg_confirm=1';

					$args = array(
						'method'    => 'POST',
						'timeout'   => 15,
						'sslverify' => false,
						'body'      => array(
							'chat_id'    => $chat_id,
							'text'       => $msg,
							'parse_mode' => 'HTML',
							'reply_markup' => json_encode(array(
								'inline_keyboard' => array(
									array(
										array(
											'text' => '✅ Confirmar asistencia',
																				'url'  => $raid_url
										)
									),
									array(
										array(
											'text' => '📅 Ver en calendario',
											'url'  => $site_url . '/calendario-de-partidas/'
										)
									)
								)
							))
						),
					);

					$response = wp_remote_post( $url, $args );

									if ( is_wp_error( $response ) ) {
										wp_send_json_error( $response->get_error_message() );
									}

									$code = wp_remote_retrieve_response_code( $response );
									$body = wp_remote_retrieve_body( $response );
									if ( $code === 200 ) {
										wp_send_json_success( __( '¡Solicitud enviada al chat de RAIDs!', 'reforger-milsim' ) );
									} else {
										$err = json_decode( $body, true );
										$err_msg = isset( $err['description'] ) ? $err['description'] : "HTTP $code";
										wp_send_json_error( __( 'Error Telegram: ' . $err_msg, 'reforger-milsim' ) );
					}
		} catch ( Exception $e ) {
			wp_send_json_error( $e->getMessage() );
		}
	}

	/**
	 * Registrar endpoints REST
	 */
	public function register_rest_endpoints() {
		// Endpoint para confirmar asistencia (botón de Telegram)
		register_rest_route( 'clan/v1', '/raid/confirm', array(
			'methods'  => 'GET',
			'callback' => array( $this, 'handle_raid_confirm' ),
			'permission_callback' => '__return_true',
		));
		
		// Endpoint para listar raids (calendario)
		register_rest_route( 'clan/v1', '/raids', array(
			'methods'  => 'GET',
			'callback' => array( $this, 'get_raids_for_calendar' ),
			'permission_callback' => '__return_true',
		));
	}

	/**
	 * Maneja la confirmación de asistencia desde el botón de Telegram
	 */
	public function handle_raid_confirm( $request ) {
		
			$raid_id = intval( $request->get_param( 'raid_id' ) );

			// Si viene un telegram_user_id del botón, intentar auto-confirmar
			$tg_id = sanitize_text_field( $request->get_param( 'tg_id' ) );
			if ( ! empty( $tg_id ) ) {
				// Buscar usuario WP con ese telegram_id
				$users = get_users( array( 'meta_key' => 'rmm_telegram_id', 'meta_value' => $tg_id, 'number' => 1 ) );
				if ( ! empty( $users ) ) {
					$wp_user = $users[0];
					$participants = get_post_meta( $raid_id, 'raid_participantes', true ) ?: array();
					if ( ! isset( $participants[ $wp_user->ID ] ) ) {
						$participants[ $wp_user->ID ] = $wp_user->display_name;
						update_post_meta( $raid_id, 'raid_participantes', $participants );
					}
					$count = count( $participants );
					header( 'Content-Type: text/html; charset=utf-8' );
					echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Confirmado</title><style>body{background:#0d1117;color:#c9d1d9;font-family:sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;margin:0}.box{text-align:center;padding:40px;background:#161b22;border:1px solid #21262d;border-radius:12px;max-width:400px}h1{color:#22c55e;font-size:1.2rem}p{font-size:0.9rem;color:#8b949e}.count{font-size:2rem;color:#CFDC35;font-weight:800}</style></head><body><div class="box"><h1>✅ Confirmado automáticamente</h1><p>' . esc_html( $wp_user->display_name ) . ', te hemos reconocido por tu Telegram ID.</p><p style="margin-top:16px">👥 <span class="count">' . $count . '</span></p></div></body></html>';
					exit;
				}
			}

			// Si es POST, procesar confirmación
			if ( $request->get_method() === 'POST' ) {
				return $this->process_confirm_submit( $request );
			}

			// GET: mostrar formulario
			header( 'Content-Type: text/html; charset=utf-8' );

			if ( ! $raid_id ) {
				echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Error</title><style>body{background:#0d1117;color:#c9d1d9;font-family:sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;margin:0}.box{text-align:center;padding:40px;background:#161b22;border:1px solid #21262d;border-radius:12px;max-width:400px}h1{color:#ef4444}</style></head><body><div class="box"><h1>❌ Error</h1><p>Faltan datos. Usa el botón de Telegram correctamente.</p></div></body></html>';
				exit;
			}

			// Obtener datos de la raid desde el CPT
			$raid_post = get_post( $raid_id );
			if ( ! $raid_post || $raid_post->post_type !== 'raid_eventos' ) {
				echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>No encontrada</title><style>body{background:#0d1117;color:#c9d1d9;font-family:sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;margin:0}.box{text-align:center;padding:40px;background:#161b22;border:1px solid #21262d;border-radius:12px}h1{color:#ef4444}</style></head><body><div class="box"><h1>❌ No encontrada</h1><p>Esta solicitud de misión ya no existe.</p></div></body></html>';
				exit;
			}

			$autor_id = $raid_post->post_author;
			$user = get_userdata( $autor_id );
			$nombre = $user ? $user->display_name : 'Desconocido';
			$raid_fecha = get_post_meta( $raid_id, 'raid_fecha', true );
			$raid_hora  = get_post_meta( $raid_id, 'raid_hora', true );
			$raid_servidor = get_post_meta( $raid_id, 'raid_servidor', true );
			$dt = new DateTime( $raid_fecha );
			$days = array( 'Monday' => 'Lunes', 'Tuesday' => 'Martes', 'Wednesday' => 'Miércoles', 'Thursday' => 'Jueves', 'Friday' => 'Viernes', 'Saturday' => 'Sábado', 'Sunday' => 'Domingo' );
			$dia = $days[ $dt->format('l') ] ?? $dt->format('l');

			// Contar confirmados
			$participants = get_post_meta( $raid_id, 'raid_participantes', true ) ?: array();
			$count = count( $participants );

			?>
			<!DOCTYPE html>
			<html>
			<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
			<title>Confirmar Asistencia</title>
			<style>
				body { background:#0d1117; color:#c9d1d9; font-family:'Inter',sans-serif; display:flex; align-items:center; justify-content:center; min-height:100vh; margin:0; }
				.box { padding:32px; background:#161b22; border:1px solid #21262d; border-radius:12px; max-width:420px; width:90%; }
				h1 { font-size:1.1rem; color:#CFDC35; margin:0 0 16px; text-align:center; }
				.info { font-size:0.8rem; color:#8b949e; margin-bottom:20px; line-height:1.6; }
				.info strong { color:#e5e7eb; }
				label { display:block; font-size:0.65rem; text-transform:uppercase; letter-spacing:0.05em; color:#8b949e; margin-bottom:4px; }
				input { width:100%; background:#0d1117; border:1px solid #30363d; border-radius:6px; padding:10px 12px; color:#c9d1d9; font-size:0.9rem; box-sizing:border-box; }
				input:focus { border-color:#CFDC35; outline:none; }
				button { width:100%; background:#CFDC35; color:#000; border:none; border-radius:6px; padding:12px; font-size:0.85rem; font-weight:700; cursor:pointer; margin-top:12px; }
				.count { text-align:center; font-size:0.8rem; color:#8b949e; margin-top:16px; }
				.count span { color:#CFDC35; font-weight:700; font-size:1.2rem; }
			</style></head>
			<body>
			<div class="box">
				<h1>🎯 Confirmar Asistencia</h1>
				<div class="info">
					<strong><?php echo esc_html( $nombre ); ?></strong> ha solicitado una misión.<br>
					📅 <strong><?php echo $dia . ' ' . $dt->format('j'); ?></strong> a las <strong><?php echo esc_html( $raid_hora ); ?></strong>h<br>
					<?php if ( $raid_servidor ): ?>🏷 Servidor: <strong><?php echo esc_html( $raid_servidor ); ?></strong><?php endif; ?>
				</div>
				<form method="post" action="">
					<label>Tu nombre o alias de Telegram</label>
					<input type="text" name="name" placeholder="Ej: @Trauman" required>
					<input type="hidden" name="raid_id" value="<?php echo $raid_id; ?>">
					<button type="submit">✅ Confirmar Asistencia</button>
				</form>
				<div class="count">👥 <span><?php echo $count; ?></span> confirmados</div>
			</div>
			</body>
			</html>
			<?php
			exit;
		}

		/**
	 * Procesa el POST del formulario de confirmación
	 */
	private function process_confirm_submit( $request ) {

				$raid_id = intval( $request->get_param( 'raid_id' ) );
				$nombre = sanitize_text_field( $request->get_param( 'name' ) );

				header( 'Content-Type: text/html; charset=utf-8' );

				if ( ! $raid_id || empty( $nombre ) ) {
					echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Error</title><style>body{background:#0d1117;color:#c9d1d9;font-family:sans-serif;display:flex;align-items:center;justify-content:center;height:100vh}.box{text-align:center;padding:40px;background:#161b22;border:1px solid #21262d;border-radius:12px}h1{color:#ef4444}</style></head><body><div class="box"><h1>❌ Error</h1><p>Faltan datos.</p></div></body></html>';
					exit;
				}

				// Verificar duplicado en post meta
				$participants = get_post_meta( $raid_id, 'raid_participantes', true ) ?: array();
				$exists = in_array( $nombre, $participants );

				if ( $exists ) {
					echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Ya Confirmado</title><style>body{background:#0d1117;color:#c9d1d9;font-family:sans-serif;display:flex;align-items:center;justify-content:center;height:100vh}.box{text-align:center;padding:40px;background:#161b22;border:1px solid #21262d;border-radius:12px;max-width:400px}.icon{font-size:3rem}h1{color:#f59e0b;font-size:1.2rem}</style></head><body><div class="box"><div class="icon">✅</div><h1>Ya confirmado</h1><p>Ya habías confirmado tu asistencia a esta misión.</p></div></body></html>';
					exit;
				}

				// Guardar participante (no-WP, desde Telegram)
				$participants[ 'tg_' . uniqid() ] = $nombre;
				update_post_meta( $raid_id, 'raid_participantes', $participants );

				$count = count( $participants );

				echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Confirmado</title><style>body{background:#0d1117;color:#c9d1d9;font-family:sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;margin:0}.box{text-align:center;padding:40px;background:#161b22;border:1px solid #21262d;border-radius:12px;max-width:400px}.icon{font-size:3rem;margin-bottom:16px}h1{font-size:1.2rem;color:#22c55e;margin:0 0 8px}p{font-size:0.9rem;color:#8b949e;margin:4px 0}.count{font-size:2rem;font-weight:800;color:#CFDC35}</style></head><body><div class="box"><div class="icon">✅</div><h1>¡Asistencia confirmada!</h1><p>' . esc_html( $nombre ) . ', te has apuntado a la misión.</p><p style="margin-top:16px">👥 Participantes: <span class="count">' . $count . '</span></p></div></body></html>';
				exit;
			}

	/**
	 * Devuelve las raids para el calendario FullCalendar
	 */
	public function get_raids_for_calendar( $request ) {
		$raids = get_posts( array(
					'post_type'      => 'raid_eventos',
					'numberposts'    => -1,
					'post_status'    => 'publish',
					'meta_query'     => array(
						array(
							'key'     => 'raid_estado',
							'value'   => array( 'activa', 'aprobada', 'denegada' ),
							'compare' => 'IN',
						),
					),
					'orderby'        => 'meta_value',
					'meta_key'       => 'raid_fecha',
					'order'          => 'ASC',
				));

		$events = array();
		foreach ( $raids as $raid ) {
			$fecha = get_post_meta( $raid->ID, 'raid_fecha', true );
			$hora  = get_post_meta( $raid->ID, 'raid_hora', true );
			$participants = get_post_meta( $raid->ID, 'raid_participantes', true ) ?: array();

			$estado_color = get_post_meta( $raid->ID, 'raid_estado', true ) ?: 'activa';
					$colors = array( 'activa' => '#CFDC35', 'aprobada' => '#22c55e', 'denegada' => '#ef4444', 'finalizada' => '#6b7280', 'cancelada' => '#374151' );
					$color = $colors[ $estado_color ] ?? '#CFDC35';

					$events[] = array(
						'id'        => 'raid_' . $raid->ID,
						'title'     => '🎯 ' . $raid->post_title,
						'start'     => $fecha . 'T' . $hora,
						'url'       => get_permalink( $raid->ID ),
						'color'     => $color,
						'textColor' => ( $estado_color === 'activa' || $estado_color === 'denegada' ) ? '#000' : '#fff',
				'extendedProps' => array(
					'tipo'         => 'raid',
					'participantes' => count( $participants ),
				),
			);
		}

		return rest_ensure_response( $events );
	}

			/**
			 * Notifica al canal de RAIDs cuando se publica un evento oficial
			 */
			public function notify_raid_channel_on_event( $post_id, $post ) {
				$token = get_option( 'rmm_raid_telegram_token', '' );
				$chat_id = get_option( 'rmm_raid_telegram_chat_id', '-1003157817672' );

				if ( empty( $token ) || empty( $chat_id ) ) return;

							// Evitar envío duplicado
							if ( get_post_meta( $post_id, '_raid_notified', true ) ) return;

				$fecha_inicio = get_post_meta( $post_id, 'fecha_inicio', true );
				$fecha_fin    = get_post_meta( $post_id, 'fecha_fin', true );

				// Normalizar formato datetime-local (T) a espacio
				$fecha_inicio = str_replace( 'T', ' ', trim( $fecha_inicio ) );
				if ( ! preg_match( '/:\d{2}:\d{2}$/', $fecha_inicio ) ) $fecha_inicio .= ':00';

				$dt = DateTime::createFromFormat( 'Y-m-d H:i:s', $fecha_inicio, wp_timezone() );
							if ( ! $dt ) return;
							$days = array( 'Monday' => 'Lunes', 'Tuesday' => 'Martes', 'Wednesday' => 'Miércoles', 'Thursday' => 'Jueves', 'Friday' => 'Viernes', 'Saturday' => 'Sábado', 'Sunday' => 'Domingo' );
							$dia = $days[ $dt->format('l') ] ?? $dt->format('l');
							$hora = $dt->format('H:i');

				$msg = "📢 <b>¡MISIÓN OFICIAL CREADA!</b>

";
				$msg .= "🎮 <b>" . esc_html( $post->post_title ) . "</b>
";
				$msg .= "📅 <b>$dia " . $dt->format('j') . "</b>
";
				$msg .= "🕒 <b>{$hora}h (hora peninsular)</b>\n";
				$msg .= "\n
🔗 " . get_permalink( $post_id ) . "
";
				$msg .= "
<i>Reserva tu slot en la web. ¡No faltes!</i>";

				wp_remote_post( "https://api.telegram.org/bot{$token}/sendMessage", array(
					'timeout'   => 15,
					'sslverify' => false,
					'body'      => array(
						'chat_id'    => $chat_id,
						'text'       => $msg,
						'parse_mode' => 'HTML',
										),
									));

									// Marcar como notificado para no duplicar
									update_post_meta( $post_id, '_raid_notified', 1 );
													}

										/**
										 * Shortcode [raid_apuntarse] - Botón de apuntarse/desapuntarse
	 */
	public function render_raid_join_button( $atts ) {
			if ( ! is_singular( 'raid_eventos' ) ) return '';
			if ( ! is_user_logged_in() ) {
						$login_url = wp_login_url( get_permalink() );
						return '<a href="' . esc_url( $login_url ) . '" style="display:block;text-decoration:none;background:#161b22;border:1px solid #21262d;border-radius:8px;padding:16px;text-align:center;color:#58a6ff;font-family:sans-serif;margin:20px 0;"><i class="fa-solid fa-lock"></i> ' . __( 'Inicia sesión para apuntarte.', 'reforger-milsim' ) . '</a>';
					}

			// Verificar rol permitido
					$user = wp_get_current_user();
					$allowed_roles = array( 'recluta', 'activo', 'aliado', 'reservista', 'baja_indefinida', 'administrator' );
					if ( ! array_intersect( $allowed_roles, (array) $user->roles ) ) {
						return '<div style="background:#161b22;border:1px solid #21262d;border-radius:8px;padding:16px;text-align:center;color:#8b949e;font-family:sans-serif;margin:20px 0;"><i class="fa-solid fa-shield-halved"></i> ' . __( 'No tienes rango suficiente para apuntarte.', 'reforger-milsim' ) . '</div>';
					}

							$post_id = get_the_ID();
		$user_id = get_current_user_id();
		$participants = get_post_meta( $post_id, 'raid_participantes', true ) ?: array();
		$is_joined = isset( $participants[ $user_id ] );
		$count = count( $participants );

		// Auto-apuntar si el usuario tiene Telegram ID configurado y viene del botón de Telegram
		$telegram_id = get_user_meta( $user_id, 'rmm_telegram_id', true );
		$auto_confirm = isset( $_GET['tg_confirm'] ) && $_GET['tg_confirm'] === '1';
		if ( $auto_confirm && ! $is_joined && ! empty( $telegram_id ) ) {
			$participants[ $user_id ] = $user->display_name;
			update_post_meta( $post_id, 'raid_participantes', $participants );
			$is_joined = true;
			$count = count( $participants );
		}

		$estado = get_post_meta( $post_id, 'raid_estado', true );
		$is_active = ( $estado === 'activa' );

				ob_start();
				?>
				<?php if ( $auto_confirm && $is_joined ) : ?>
					<div style="background:rgba(207,220,53,0.08);border:1px solid #CFDC35;border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:0.75rem;color:#CFDC35;text-align:center;">
						✅ ¡Te hemos reconocido por tu Telegram ID! Asistencia confirmada automáticamente.
					</div>
				<?php endif; ?>
				<div class="rmm-raid-join-widget" style="background:#0d1117;border:1px solid #21262d;border-radius:8px;padding:20px;font-family:'Inter',sans-serif;color:#c9d1d9;margin:20px 0;">
			<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
				<span style="font-size:0.7rem;text-transform:uppercase;letter-spacing:0.05em;color:#8b949e;">
					<i class="fa-solid fa-users"></i> <?php _e( 'Participantes', 'reforger-milsim' ); ?>: 
					<strong style="color:#CFDC35;font-size:1.1rem;"><?php echo $count; ?></strong>
				</span>
				<?php if ( $is_active && ! $is_joined ) : ?>
					<button id="rmm-raid-join-btn" data-raid="<?php echo $post_id; ?>" style="background:#CFDC35;color:#000;border:none;border-radius:6px;padding:10px 20px;font-weight:700;cursor:pointer;text-transform:uppercase;font-size:0.8rem;">
						<i class="fa-solid fa-check"></i> <?php _e( 'Apuntarme', 'reforger-milsim' ); ?>
					</button>
				<?php elseif ( $is_active && $is_joined ) : ?>
					<button id="rmm-raid-leave-btn" data-raid="<?php echo $post_id; ?>" style="background:#ef4444;color:#fff;border:none;border-radius:6px;padding:10px 20px;font-weight:700;cursor:pointer;text-transform:uppercase;font-size:0.8rem;">
						<i class="fa-solid fa-xmark"></i> <?php _e( 'Desapuntarme', 'reforger-milsim' ); ?>
					</button>
				<?php else : ?>
					<span style="background:#30363d;color:#8b949e;border-radius:6px;padding:10px 20px;font-weight:700;font-size:0.8rem;text-transform:uppercase;">
										🔒 SOLICITUD CERRADA
									</span>
				<?php endif; ?>
			</div>
			<div style="margin-top:16px;">
				<?php if ( ! empty( $participants ) ) : ?>
					<div style="display:flex;flex-wrap:wrap;gap:6px;">
						<?php foreach ( $participants as $uid => $name ) : 
							$p_user = get_userdata( $uid );
							$display = $p_user ? $p_user->display_name : $name;
						?>
							<span style="display:inline-flex;align-items:center;gap:4px;background:#161b22;border:1px solid #21262d;border-radius:20px;padding:4px 12px;font-size:0.7rem;color:#e5e7eb;">
								<?php echo get_avatar( $uid, 20, '', '', array( 'style' => 'border-radius:50%;width:20px;height:20px;' ) ); ?>
								<?php echo esc_html( $display ); ?>
							</span>
						<?php endforeach; ?>
					</div>
				<?php else : ?>
					<p style="font-size:0.7rem;color:#484f58;"><?php _e( 'Nadie se ha apuntado todavía.', 'reforger-milsim' ); ?></p>
				<?php endif; ?>
			</div>
		</div>
		<?php if ( $is_active ) : ?>
		<script>
		jQuery(document).ready(function($) {
			$('#rmm-raid-join-btn').on('click', function() {
				var btn = $(this); var raidId = btn.data('raid');
				btn.prop('disabled', true).text('...');
				$.post('<?php echo admin_url("admin-ajax.php"); ?>', {
					action: 'rmm_raid_join', raid_id: raidId,
					_ajax_nonce: '<?php echo wp_create_nonce("rmm_raid_join"); ?>'
				}, function(r) { if (r.success) location.reload(); else alert(r.data || 'Error'); });
			});
			$('#rmm-raid-leave-btn').on('click', function() {
				var btn = $(this); var raidId = btn.data('raid');
				btn.prop('disabled', true).text('...');
				$.post('<?php echo admin_url("admin-ajax.php"); ?>', {
					action: 'rmm_raid_leave', raid_id: raidId,
					_ajax_nonce: '<?php echo wp_create_nonce("rmm_raid_leave"); ?>'
				}, function(r) { if (r.success) location.reload(); else alert(r.data || 'Error'); });
			});
		});
		</script>
		<?php endif; ?>
		<?php
		return ob_get_clean();
	}

	public function ajax_raid_join() {
		check_ajax_referer( 'rmm_raid_join' );
		$uid = get_current_user_id();
		if ( ! $uid ) wp_send_json_error( 'Debes iniciar sesión.' );
		$raid_id = intval( $_POST['raid_id'] );
		$parts = get_post_meta( $raid_id, 'raid_participantes', true ) ?: array();
		if ( isset( $parts[ $uid ] ) ) wp_send_json_error( 'Ya estás apuntado.' );
		$user = wp_get_current_user();
		$parts[ $uid ] = $user->display_name;
		update_post_meta( $raid_id, 'raid_participantes', $parts );
		wp_send_json_success( 'Apuntado.' );
	}

	public function ajax_raid_leave() {
		check_ajax_referer( 'rmm_raid_leave' );
		$uid = get_current_user_id();
		if ( ! $uid ) wp_send_json_error( 'Debes iniciar sesión.' );
		$raid_id = intval( $_POST['raid_id'] );
		$parts = get_post_meta( $raid_id, 'raid_participantes', true ) ?: array();
		if ( ! isset( $parts[ $uid ] ) ) wp_send_json_error( 'No estás apuntado.' );
		unset( $parts[ $uid ] );
		update_post_meta( $raid_id, 'raid_participantes', $parts );
		wp_send_json_success( 'Desapuntado.' );
				}

			/**
			 * Shortcode [raid_faq] — Preguntas frecuentes sobre qué es una RAID
			 */
			public function render_raid_faq( $atts ) {
				ob_start();
				?>
				<div class="rmm-raid-faq" style="font-family:'Inter',sans-serif;color:#c9d1d9;max-width:700px;">
					<style>
						.rmm-raid-faq details { background:#161b22; border:1px solid #21262d; border-radius:8px; margin-bottom:8px; overflow:hidden; }
						.rmm-raid-faq summary { padding:14px 18px; font-size:0.85rem; font-weight:700; color:#CFDC35; cursor:pointer; text-transform:uppercase; letter-spacing:0.04em; user-select:none; display:flex; align-items:center; gap:8px; }
						.rmm-raid-faq summary i { font-size:0.7rem; transition:transform 0.2s; }
						.rmm-raid-faq details[open] summary i { transform:rotate(90deg); }
						.rmm-raid-faq details p, .rmm-raid-faq details ul { padding:0 18px 14px; font-size:0.8rem; color:#8b949e; line-height:1.7; margin:0; }
						.rmm-raid-faq details ul { padding-left:36px; list-style-type:square; }
						.rmm-raid-faq details ul li { margin-bottom:4px; }
						.rmm-raid-faq details ul li strong { color:#e5e7eb; }
					</style>

					<details open>
						<summary><i class="fa-solid fa-chevron-right"></i> ¿Qué es una RAID?</summary>
						<p>Una <strong style="color:#e5e7eb;">RAID</strong> es una partida rápida no oficial organizada por miembros del clan. A diferencia de los Eventos Oficiales, las RAIDs no tienen estructura de mando ni ORBAT predefinido. Son partidas informales para jugar y practicar.</p>
					</details>

					<details>
						<summary><i class="fa-solid fa-chevron-right"></i> ¿Quién puede solicitar una RAID?</summary>
						<p>Cualquier miembro con rango <strong style="color:#e5e7eb;">Activo</strong> o <strong style="color:#e5e7eb;">Aliado</strong> puede solicitar una RAID desde la web usando el formulario <code style="background:#0d1117;padding:2px 8px;border-radius:3px;color:#CFDC35;">[clan_solicitar_raid]</code>. La solicitud se publica en el grupo de Telegram para que otros miembros confirmen asistencia.</p>
					</details>

					<details>
						<summary><i class="fa-solid fa-chevron-right"></i> ¿Quién puede apuntarse a una RAID?</summary>
						<p>Pueden apuntarse los miembros con los siguientes rangos:</p>
						<ul>
							<li><strong>Recluta</strong></li>
							<li><strong>Activo</strong></li>
							<li><strong>Aliado</strong></li>
							<li><strong>Reservista</strong></li>
							<li><strong>Baja Indefinida</strong></li>
						</ul>
					</details>

					<details>
						<summary><i class="fa-solid fa-chevron-right"></i> ¿Cómo funciona el proceso?</summary>
						<p>
							<strong style="color:#CFDC35;">1. Solicitud:</strong> Un miembro Activo o Aliado rellena el formulario con fecha, hora y servidor.<br>
							<strong style="color:#CFDC35;">2. Telegram:</strong> La solicitud se publica en el grupo con un botón para confirmar asistencia.<br>
							<strong style="color:#CFDC35;">3. Confirmación:</strong> Los miembros confirman desde Telegram o desde la web.<br>
							<strong style="color:#CFDC35;">4. Aprobación:</strong> Un Fundador, Admin o Editor revisa la solicitud y la aprueba o deniega con una justificación.<br>
							<strong style="color:#CFDC35;">5. Partida:</strong> Si se aprueba, la RAID se convierte en una partida oficial. ¡A jugar!
						</p>
					</details>

					<details>
						<summary><i class="fa-solid fa-chevron-right"></i> ¿En qué se diferencia de un Evento Oficial?</summary>
						<p>Los <strong style="color:#22c55e;">Eventos Oficiales</strong> son misiones planificadas con antelación, tienen ORBAT, slots asignados y suelen ser más serios. Las <strong style="color:#CFDC35;">RAIDs</strong> son partidas improvisadas, sin estructura, para cuando alguien quiere jugar en el momento.</p>
					</details>
				</div>
				<?php
				return ob_get_clean();
			}

			/**
			 * Auto-inyecta [raid_apuntarse] en las páginas de raid_eventos
			 */
			public function inject_raid_join_to_content( $content ) {
							if ( ! is_singular( 'raid_eventos' ) || ! in_the_loop() || ! is_main_query() ) return $content;
							return $content . do_shortcode( '[raid_apuntarse]' ) . do_shortcode( '[raid_aprobar]' );
						}

				/**
				 * Shortcode genérico para mostrar campos de RAID
				 * [raid_fecha], [raid_hora], [raid_servidor], [raid_participantes], [raid_estado], [raid_justificacion]
				 */
				public function render_raid_field( $atts, $content, $tag ) {
					if ( ! is_singular( 'raid_eventos' ) ) return '';
					$post_id = get_the_ID();

					switch ( $tag ) {
						case 'raid_fecha':
							$fecha = get_post_meta( $post_id, 'raid_fecha', true );
							if ( ! $fecha ) return '';
							$dt = DateTime::createFromFormat( 'Y-m-d', $fecha );
							if ( ! $dt ) return esc_html( $fecha );
							$days = array( 'Monday' => 'Lunes', 'Tuesday' => 'Martes', 'Wednesday' => 'Miércoles', 'Thursday' => 'Jueves', 'Friday' => 'Viernes', 'Saturday' => 'Sábado', 'Sunday' => 'Domingo' );
							return esc_html( $days[ $dt->format('l') ] . ' ' . $dt->format('j') );

						case 'raid_hora':
							return esc_html( get_post_meta( $post_id, 'raid_hora', true ) ?: '' );

						case 'raid_servidor':
							return esc_html( get_post_meta( $post_id, 'raid_servidor', true ) ?: '' );

						case 'raid_participantes':
							$parts = get_post_meta( $post_id, 'raid_participantes', true ) ?: array();
							return count( $parts );

						case 'raid_estado':
							$estado = get_post_meta( $post_id, 'raid_estado', true ) ?: 'activa';
							$labels = array( 'activa' => '🟡 Pendiente', 'aprobada' => '🟢 Aprobada', 'denegada' => '🔴 Denegada', 'finalizada' => '✅ Finalizada', 'cancelada' => '🚫 Cancelada' );
							return esc_html( $labels[ $estado ] ?? $estado );

						case 'raid_justificacion':
												return esc_html( get_post_meta( $post_id, 'raid_justificacion', true ) ?: '' );

											case 'raid_notas':
																			$raid_post = get_post( $post_id );
																			return $raid_post ? wp_kses_post( $raid_post->post_content ) : '';

																		case 'raid_solicitante':
																			$uid = get_post_meta( $post_id, 'raid_solicitante', true );
																			if ( ! $uid ) return '';
																			$u = get_userdata( $uid );
																			return $u ? esc_html( $u->display_name ) : '';

						default:
							return '';
					}
				}

				/**
				 * Shortcode [raid_aprobar] — Botones de aprobar/denegar para admins/editores/fundadores
				 */
				public function render_raid_approve_buttons( $atts ) {
					if ( ! is_singular( 'raid_eventos' ) ) return '';
					if ( ! is_user_logged_in() ) return '';

					$user = wp_get_current_user();
					$can_approve = array_intersect( array( 'administrator', 'editor', 'fundador' ), (array) $user->roles );
					if ( ! $can_approve ) return '';

					$post_id = get_the_ID();
					$estado = get_post_meta( $post_id, 'raid_estado', true ) ?: 'activa';
					if ( $estado !== 'activa' ) return '';

					ob_start();
					?>
					<div class="rmm-raid-approve-widget" style="background:#0d1117;border:1px solid #CFDC35;border-radius:8px;padding:16px;margin:16px 0;font-family:'Inter',sans-serif;color:#c9d1d9;">
						<h4 style="font-size:0.75rem;color:#CFDC35;margin:0 0 12px;text-transform:uppercase;letter-spacing:0.05em;">
							<i class="fa-solid fa-gavel"></i> <?php _e( 'Gestión de RAID', 'reforger-milsim' ); ?>
						</h4>
						<textarea id="raid_justificacion" placeholder="Justificación (obligatoria para denegar)..." style="width:100%;background:#161b22;border:1px solid #30363d;border-radius:6px;padding:10px;color:#c9d1d9;font-size:0.8rem;resize:vertical;min-height:60px;box-sizing:border-box;"></textarea>
						<div style="display:flex;gap:10px;margin-top:10px;">
							<button id="btn_aprobar_raid" data-raid="<?php echo $post_id; ?>" style="flex:1;background:#22c55e;color:#fff;border:none;border-radius:6px;padding:10px;font-weight:700;cursor:pointer;text-transform:uppercase;font-size:0.75rem;">
								✅ Aprobar
							</button>
							<button id="btn_denegar_raid" data-raid="<?php echo $post_id; ?>" style="flex:1;background:#ef4444;color:#fff;border:none;border-radius:6px;padding:10px;font-weight:700;cursor:pointer;text-transform:uppercase;font-size:0.75rem;">
								❌ Denegar
							</button>
						</div>
						<div id="raid_approve_status" style="text-align:center;font-size:0.75rem;margin-top:8px;"></div>
					</div>
					<script>
					jQuery(document).ready(function($) {
						function sendDecision(accion) {
							var just = $('#raid_justificacion').val().trim();
							if (accion === 'denegar' && !just) {
								alert('Debes escribir una justificación para denegar la RAID.');
								return;
							}
							$('#btn_aprobar_raid, #btn_denegar_raid').prop('disabled', true);
							$('#raid_approve_status').html('<span style="color:#f59e0b;">Procesando...</span>');
							$.post('<?php echo admin_url("admin-ajax.php"); ?>', {
								action: 'rmm_raid_decide',
								raid_id: <?php echo $post_id; ?>,
								decision: accion,
								justificacion: just,
								_ajax_nonce: '<?php echo wp_create_nonce("rmm_raid_decide"); ?>'
							}, function(r) {
								if (r.success) location.reload();
								else $('#raid_approve_status').html('<span style="color:#ef4444;">' + (r.data||'Error') + '</span>');
							});
						}
						$('#btn_aprobar_raid').on('click', function() { sendDecision('aprobar'); });
						$('#btn_denegar_raid').on('click', function() { sendDecision('denegar'); });
					});
					</script>
					<?php
					return ob_get_clean();
				}

				/**
				 * AJAX: Aprobar o denegar una RAID
				 */
				public function ajax_raid_decide() {
					check_ajax_referer( 'rmm_raid_decide' );
					$user = wp_get_current_user();
					$can = array_intersect( array( 'administrator', 'editor', 'fundador' ), (array) $user->roles );
					if ( ! $can ) wp_send_json_error( 'Sin permisos.' );

					$raid_id = intval( $_POST['raid_id'] );
					$decision = sanitize_text_field( $_POST['decision'] );
					$justificacion = sanitize_textarea_field( $_POST['justificacion'] );

					if ( ! in_array( $decision, array( 'aprobar', 'denegar' ) ) ) wp_send_json_error( 'Decisión inválida.' );

					$estado = $decision === 'aprobar' ? 'aprobada' : 'denegada';
					update_post_meta( $raid_id, 'raid_estado', $estado );
					update_post_meta( $raid_id, 'raid_justificacion', $justificacion );

					// Notificar a Telegram
					$token = get_option( 'rmm_raid_telegram_token', '' );
					$chat_id = get_option( 'rmm_raid_telegram_chat_id', '-1003157817672' );

					if ( $token && $chat_id ) {
						$raid = get_post( $raid_id );
						$parts = get_post_meta( $raid_id, 'raid_participantes', true ) ?: array();
						$count = count( $parts );
						$icon = $decision === 'aprobar' ? '🟢' : '🔴';
						$accion_label = $decision === 'aprobar' ? 'APROBADA' : 'DENEGADA';

						$msg = "{$icon} <b>RAID {$accion_label}</b>\n\n";
						$msg .= "🎯 <b>" . esc_html( $raid->post_title ) . "</b>\n";
						$msg .= "👤 Por: " . esc_html( $user->display_name ) . "\n";
						$msg .= "👥 Participantes: {$count}\n";
						if ( $justificacion ) {
							$msg .= "📝 <b>Justificación:</b> " . esc_html( $justificacion ) . "\n";
						}
						$msg .= "\n🔗 " . get_permalink( $raid_id );

						wp_remote_post( "https://api.telegram.org/bot{$token}/sendMessage", array(
							'timeout' => 15, 'sslverify' => false,
							'body' => array( 'chat_id' => $chat_id, 'text' => $msg, 'parse_mode' => 'HTML' ),
						));
					}

					wp_send_json_success( 'Decisión guardada.' );
									}

						/**
						 * Shortcode [raid_lista_participantes] — Lista formateada de participantes con avatares
						 */
						public function render_raid_participants_list( $atts ) {
							if ( ! is_singular( 'raid_eventos' ) ) return '';
							$post_id = get_the_ID();
							$participants = get_post_meta( $post_id, 'raid_participantes', true ) ?: array();

							if ( empty( $participants ) ) {
								return '<p style="font-size:0.75rem;color:#484f58;text-align:center;padding:12px;">' . __( 'Nadie se ha apuntado todavía.', 'reforger-milsim' ) . '</p>';
							}

							ob_start();
							?>
							<div class="rmm-raid-participants-list" style="display:flex;flex-wrap:wrap;gap:8px;">
								<?php foreach ( $participants as $uid => $name ) : 
									$p_user = get_userdata( $uid );
									$display = $p_user ? $p_user->display_name : $name;
								?>
									<div style="display:inline-flex;align-items:center;gap:8px;background:#161b22;border:1px solid #21262d;border-radius:8px;padding:6px 14px 6px 6px;">
										<?php echo get_avatar( $uid, 32, '', '', array( 'style' => 'border-radius:50%;width:32px;height:32px;' ) ); ?>
										<div>
											<span style="display:block;font-size:0.8rem;font-weight:600;color:#e5e7eb;line-height:1.2;"><?php echo esc_html( $display ); ?></span>
										</div>
									</div>
								<?php endforeach; ?>
							</div>
							<?php
							return ob_get_clean();
						}

						/**
						 * Shortcode [raid_boton_participar] — Solo el botón de apuntarse/desapuntarse (sin lista)
						 */
						public function render_raid_join_only_button( $atts ) {
							if ( ! is_singular( 'raid_eventos' ) ) return '';
							if ( ! is_user_logged_in() ) {
								$login_url = wp_login_url( get_permalink() );
								return '<a href="' . esc_url( $login_url ) . '" style="display:inline-block;text-decoration:none;background:#161b22;border:1px solid #30363d;border-radius:6px;padding:10px 20px;font-size:0.8rem;font-weight:600;color:#58a6ff;text-transform:uppercase;letter-spacing:0.04em;"><i class="fa-solid fa-lock"></i> ' . __( 'Inicia sesión', 'reforger-milsim' ) . '</a>';
							}

							$user = wp_get_current_user();
							$allowed = array( 'recluta', 'activo', 'aliado', 'reservista', 'baja_indefinida', 'administrator' );
							if ( ! array_intersect( $allowed, (array) $user->roles ) ) {
								return '<span style="display:inline-block;background:#30363d;color:#8b949e;border-radius:6px;padding:10px 20px;font-size:0.8rem;font-weight:600;text-transform:uppercase;">' . __( 'Sin rango', 'reforger-milsim' ) . '</span>';
							}

							$post_id = get_the_ID();
							$estado = get_post_meta( $post_id, 'raid_estado', true ) ?: 'activa';
							$participants = get_post_meta( $post_id, 'raid_participantes', true ) ?: array();
							$user_id = get_current_user_id();
							$is_joined = isset( $participants[ $user_id ] );

							// Auto-confirm si viene de Telegram
							$tg_id = get_user_meta( $user_id, 'rmm_telegram_id', true );
							$auto_confirm = isset( $_GET['tg_confirm'] ) && $_GET['tg_confirm'] === '1';
							if ( $auto_confirm && ! $is_joined && ! empty( $tg_id ) && $estado === 'activa' ) {
								$participants[ $user_id ] = $user->display_name;
								update_post_meta( $post_id, 'raid_participantes', $participants );
								$is_joined = true;
							}

							if ( $estado !== 'activa' ) {
														return '<span style="display:inline-block;background:#30363d;color:#8b949e;border-radius:6px;padding:10px 20px;font-size:0.8rem;font-weight:600;text-transform:uppercase;">🔒 SOLICITUD CERRADA</span>';
													}

							ob_start();
							?>
							<?php if ( $auto_confirm && $is_joined ) : ?>
								<div style="background:rgba(207,220,53,0.08);border:1px solid #CFDC35;border-radius:6px;padding:8px 16px;margin-bottom:8px;font-size:0.7rem;color:#CFDC35;text-align:center;">
									✅ ¡Reconocido por Telegram ID! Confirmado automáticamente.
								</div>
							<?php endif; ?>
							<?php if ( ! $is_joined ) : ?>
								<button id="rmm-raid-join-only-btn" data-raid="<?php echo $post_id; ?>" style="background:#CFDC35;color:#000;border:none;border-radius:6px;padding:10px 24px;font-weight:700;font-size:0.8rem;cursor:pointer;text-transform:uppercase;letter-spacing:0.04em;">
									<i class="fa-solid fa-check"></i> <?php _e( 'Apuntarme', 'reforger-milsim' ); ?>
								</button>
							<?php else : ?>
								<button id="rmm-raid-leave-only-btn" data-raid="<?php echo $post_id; ?>" style="background:#ef4444;color:#fff;border:none;border-radius:6px;padding:10px 24px;font-weight:700;font-size:0.8rem;cursor:pointer;text-transform:uppercase;letter-spacing:0.04em;">
									<i class="fa-solid fa-xmark"></i> <?php _e( 'Desapuntarme', 'reforger-milsim' ); ?>
								</button>
							<?php endif; ?>
							<script>
							jQuery(document).ready(function($) {
								$('#rmm-raid-join-only-btn').on('click', function() {
									var btn = $(this); btn.prop('disabled', true).text('...');
									$.post('<?php echo admin_url("admin-ajax.php"); ?>', {
										action: 'rmm_raid_join', raid_id: btn.data('raid'),
										_ajax_nonce: '<?php echo wp_create_nonce("rmm_raid_join"); ?>'
									}, function(r) { if (r.success) location.reload(); else alert(r.data); });
								});
								$('#rmm-raid-leave-only-btn').on('click', function() {
									var btn = $(this); btn.prop('disabled', true).text('...');
									$.post('<?php echo admin_url("admin-ajax.php"); ?>', {
										action: 'rmm_raid_leave', raid_id: btn.data('raid'),
										_ajax_nonce: '<?php echo wp_create_nonce("rmm_raid_leave"); ?>'
									}, function(r) { if (r.success) location.reload(); else alert(r.data); });
								});
							});
							</script>
							<?php
							return ob_get_clean();
						}
					
	/**
	 * Manejar cancelacion de evento: editar mensaje de Telegram
	 */
	public function handle_event_cancellation( $post_id, $post, $update ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
		if ( $post->post_status !== 'publish' ) return;
		
		$estado = get_post_meta( $post_id, 'estado', true );
		$motivo = get_post_meta( $post_id, 'motivo_cancelacion', true );
		
		// Si hay motivo pero el estado no es cancelada, forzar cancelada
		if ( ! empty( $motivo ) && $estado !== 'cancelada' ) {
			$estado = 'cancelada';
			update_post_meta( $post_id, 'estado', 'cancelada' );
		}
		
		if ( $estado !== 'cancelada' ) return;
		$msg_id = get_post_meta( $post_id, '_tg_message_id', true );
		
		$token   = get_option( 'rmm_raid_telegram_token', '' );
		$chat_id = get_option( 'rmm_raid_telegram_chat_id', '-1003157817672' );
		
		if ( empty( $token ) || empty( $chat_id ) ) return;
		
		if ( $msg_id ) {
			$msg  = "🚫 <b>CANCELADO</b> — " . esc_html( $post->post_title ) . "
";
			if ( $motivo ) $msg .= "
📝 Motivo: " . esc_html( $motivo );
			
			wp_remote_post( "https://api.telegram.org/bot{$token}/editMessageText", array(
				'timeout'   => 15, 'sslverify' => false,
				'body' => array(
					'chat_id' => $chat_id, 'message_id' => $msg_id,
					'text' => $msg, 'parse_mode' => 'HTML',
				),
			));
		} else {
			$msg  = "🚫 <b>EVENTO CANCELADO</b>
";
			$msg .= "🎮 " . esc_html( $post->post_title ) . "
";
			if ( $motivo ) $msg .= "📝 " . esc_html( $motivo ) . "
";
			$msg .= "
🔗 " . get_permalink( $post_id );
			
			wp_remote_post( "https://api.telegram.org/bot{$token}/sendMessage", array(
				'timeout' => 15, 'sslverify' => false,
				'body' => array(
					'chat_id' => $chat_id, 'text' => $msg, 'parse_mode' => 'HTML',
				),
			));
		}
	}


	/**
	 * AJAX: Reenviar notificacion de evento a Telegram
	 */
	public function ajax_resend_telegram_event() {
		if ( ! current_user_can( 'edit_post', intval( $_POST['post_id'] ) ) ) {
			wp_send_json_error( 'Permiso denegado' );
		}
		
		$post_id = intval( $_POST['post_id'] );
		$post = get_post( $post_id );
		if ( ! $post || $post->post_type !== 'eventos_partidas' ) {
			wp_send_json_error( 'Evento no encontrado' );
		}
		
		$estado = get_post_meta( $post_id, 'estado', true );
		
		if ( $estado === 'cancelada' ) {
			// Reenviar como cancelacion (edita el mensaje original si existe)
			$this->handle_event_cancellation( $post_id, $post, true );
			
			// Enviar tambien un mensaje nuevo de aviso de cambios
			$token   = get_option( 'rmm_raid_telegram_token', '' );
			$chat_id = get_option( 'rmm_raid_telegram_chat_id', '-1003157817672' );
			if ( $token && $chat_id ) {
				$fecha = get_post_meta( $post_id, 'fecha_inicio', true );
				$fecha_txt = $fecha ? date_i18n( 'j \d\e F \a \l\a\s H:i', strtotime( str_replace('T',' ',$fecha) ) ) : 'fecha desconocida';
				$msg  = "🔄 <b>Cambios en la partida del " . esc_html( $fecha_txt ) . "</b>
";
				$msg .= "🎮 " . esc_html( $post->post_title ) . "
";
				$msg .= "
🔗 " . get_permalink( $post_id );
				wp_remote_post( "https://api.telegram.org/bot{$token}/sendMessage", array(
					'timeout' => 15, 'sslverify' => false,
					'body' => array( 'chat_id' => $chat_id, 'text' => $msg, 'parse_mode' => 'HTML' ),
				));
			}
			wp_send_json_success( 'Mensaje de cancelacion actualizado y aviso de cambios enviado a Telegram' );
		} else {
			// Eliminar flag para permitir reenvio del mensaje original
			delete_post_meta( $post_id, '_raid_notified' );
			$this->notify_raid_channel_on_event( $post_id, $post );
			
			if ( get_post_meta( $post_id, '_raid_notified', true ) ) {
				wp_send_json_success( 'Enviado correctamente' );
			} else {
				wp_send_json_error( 'No se pudo enviar. Verifica token y chat ID en Ajustes.' );
			}
		}
	}

}