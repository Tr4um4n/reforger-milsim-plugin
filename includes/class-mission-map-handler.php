<?php
/**
 * Mission Map & MicroDAGR Handler
 * Gestiona mapas de misión en tiempo real y sistema MicroDAGR (GPS móvil)
 * @package ReforgerMilsimManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RMM_Mission_Map_Handler {

	public function __construct() {
		// Shortcodes
		add_shortcode( 'rmm_mission_map', array( $this, 'render_mission_map' ) );
		add_shortcode( 'rmm_microdagr', array( $this, 'render_microdagr_button' ) );

		// REST API para recibir datos del addon
		add_action( 'rest_api_init', array( $this, 'register_rest_endpoints' ) );

		// Página MicroDAGR mobile
		add_action( 'init', array( $this, 'register_microdagr_page' ) );
		add_filter( 'template_include', array( $this, 'load_microdagr_template' ) );

		// Auto-detectar mapa al publicar misión
		add_action( 'save_post_misiones', array( $this, 'auto_create_mission_map' ), 10, 3 );
	}

	/**
	 * Crea automáticamente un mission_map al publicar una misión
	 */
	public function auto_create_mission_map( $post_id, $post, $update ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
		if ( ! current_user_can( 'edit_post', $post_id ) ) return;

		global $wpdb;
		$table = $wpdb->prefix . 'rmm_mission_maps';
		$exists = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM $table WHERE post_id = %d", $post_id
		) );

		if ( ! $exists ) {
			// Intentar detectar mapa del scenario
			$map_name = get_post_meta( $post_id, 'map_name', true );
			if ( empty( $map_name ) ) {
				// Intentar extraer del scenario_id o workshop data
				$scenario_id = get_post_meta( $post_id, 'scenario_id', true );
				if ( strpos( strtolower( $scenario_id ), 'everon' ) !== false ) $map_name = 'everon';
				elseif ( strpos( strtolower( $scenario_id ), 'arland' ) !== false ) $map_name = 'arland';
				elseif ( strpos( strtolower( $scenario_id ), 'cain' ) !== false || strpos( strtolower( $scenario_id ), 'kolgu' ) !== false ) $map_name = 'cain';
				else $map_name = 'everon'; // default
			}

			$wpdb->insert( $table, array(
				'post_id'  => $post_id,
				'map_name' => sanitize_key( $map_name ),
				'height'   => '600px',
				'enabled'  => 1,
			) );
		}
	}

	/**
	 * Shortcode [rmm_mission_map] — Mapa de misión con datos en tiempo real
	 * Atributos: id (post_id), height, session_id
	 */
	public function render_mission_map( $atts ) {
		global $wpdb;
		$atts = shortcode_atts( array(
			'id'         => get_the_ID(),
			'height'     => '600px',
			'session_id' => '',
		), $atts, 'rmm_mission_map' );

		$post_id = intval( $atts['id'] );
		$table_maps = $wpdb->prefix . 'rmm_mission_maps';
		$table_maps_db = $wpdb->prefix . 'rmm_dagr_maps';

		$mission_map = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM $table_maps WHERE post_id = %d AND enabled = 1", $post_id
		) );

		if ( ! $mission_map ) {
			return '<p class="rmm-no-mission-map">No hay mapa configurado para esta misión.</p>';
		}

		$map_name = $mission_map->map_name;
		$height   = $atts['height'] !== '600px' ? $atts['height'] : $mission_map->height;

		// Buscar config del mapa DAGR
		$map_config = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM $table_maps_db WHERE map_name = %s AND enabled = 1", $map_name
		) );

		if ( ! $map_config ) {
			return '<p class="rmm-no-mission-map">Mapa "'.$map_name.'" no configurado en DAGR.</p>';
		}

		// Session actual (activa)
		$session_id = $atts['session_id'];
		if ( empty( $session_id ) ) {
			$table_sessions = $wpdb->prefix . 'rmm_mission_sessions';
			$session = $wpdb->get_row( $wpdb->prepare(
				"SELECT session_id FROM $table_sessions WHERE post_id = %d AND status = 'active' ORDER BY started_at DESC LIMIT 1",
				$post_id
			) );
			if ( $session ) $session_id = $session->session_id;
		}

		// Obtener posiciones actuales (último registro por jugador)
		$positions = array();
		if ( $session_id ) {
			$table_positions = $wpdb->prefix . 'rmm_mission_positions';
			$positions = $wpdb->get_results( $wpdb->prepare(
				"SELECT p.* FROM $table_positions p
				INNER JOIN (
					SELECT steamid, MAX(recorded_at) as max_time
					FROM $table_positions
					WHERE session_id = %s
					GROUP BY steamid
				) latest ON p.steamid = latest.steamid AND p.recorded_at = latest.max_time
				WHERE p.session_id = %s",
				$session_id, $session_id
			), ARRAY_A );
		}

		// Obtener marcadores de la sesión
		$markers = array();
		if ( $session_id ) {
			$table_markers = $wpdb->prefix . 'rmm_mission_markers';
			$markers = $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM $table_markers WHERE session_id = %s ORDER BY recorded_at DESC",
				$session_id
			), ARRAY_A );
		}

		// Cargar objetivos del preset DAGR vinculado
		$preset_markers = array();
		if ( $mission_map->preset_id ) {
			$table_presets = $wpdb->prefix . 'rmm_dagr_presets';
			$preset = $wpdb->get_row( $wpdb->prepare(
				"SELECT markers FROM $table_presets WHERE id = %d", $mission_map->preset_id
			) );
			if ( $preset && ! empty( $preset->markers ) ) {
				$preset_markers = json_decode( $preset->markers, true );
				if ( is_array( $preset_markers ) ) {
					foreach ( $preset_markers as &$pm ) {
						$pm['source'] = 'preset';
						if ( empty( $pm['color'] ) ) {
							$pm['color'] = ( $pm['type'] === 'completed' ) ? '#d2a850' : '#22c55e';
						}
					}
				}
			}
		}

		$uid = 'mission-map-' . uniqid();
		$tiles_url = ! empty( $map_config->tiles_path )
			? $map_config->tiles_path
			: '../mapas/mapa_' . $map_name . '/{z}/{x}/{y}/tile.jpg';

		$min_x = floatval( $map_config->min_x );
		$min_y = floatval( $map_config->min_y );
		$max_x = floatval( $map_config->max_x );
		$max_y = floatval( $map_config->max_y );
		$max_zoom = intval( $map_config->max_zoom );
		$edge_offset = intval( $map_config->edge_offset );
		$scale_factor = floatval( $map_config->scale_factor );

		// MicroDAGR: si usuario logueado con steamid, mostrar botón
		$current_user_id = get_current_user_id();
		$show_microdagr = false;
		$microdagr_token = '';
		if ( $current_user_id && $session_id ) {
			$steamid = get_user_meta( $current_user_id, 'steamid_64', true );
			if ( ! empty( $steamid ) ) {
				$show_microdagr = true;
				// Generar o recuperar token
				$table_tokens = $wpdb->prefix . 'rmm_microdagr_tokens';
				$existing = $wpdb->get_row( $wpdb->prepare(
					"SELECT token FROM $table_tokens WHERE user_id = %d AND session_id = %s AND (expires_at IS NULL OR expires_at > NOW())",
					$current_user_id, $session_id
				) );
				if ( $existing ) {
					$microdagr_token = $existing->token;
				} else {
					$microdagr_token = wp_generate_password( 32, false );
					$wpdb->insert( $table_tokens, array(
						'token'      => $microdagr_token,
						'user_id'    => $current_user_id,
						'steamid'    => $steamid,
						'session_id' => $session_id,
						'expires_at' => date( 'Y-m-d H:i:s', strtotime( '+24 hours' ) ),
					) );
				}
			}
		}

		$positions_json = json_encode( $positions );
		$markers_json   = json_encode( array_merge( $preset_markers, $markers ) );

		ob_start();
		?>
		<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
		<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
		<div id="<?php echo $uid; ?>" style="width:100%;height:<?php echo esc_attr( $height ); ?>;background:#0d1117;border:1px solid #21262d;border-radius:8px;position:relative;">
			<?php if ( $show_microdagr ) : ?>
			<div style="position:absolute;top:10px;right:10px;z-index:1000;">
				<button onclick="openMicroDAGR('<?php echo esc_js( $microdagr_token ); ?>','<?php echo esc_js( $session_id ); ?>')" class="button button-primary" style="background:#22c55e;border-color:#16a34a;color:#fff;">📱 MicroDAGR</button>
			</div>
			<?php endif; ?>
		</div>

		<!-- Modal QR MicroDAGR -->
		<div id="microdagr-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.8);z-index:9999;justify-content:center;align-items:center;">
			<div style="background:#1a1a1a;padding:30px;border-radius:12px;text-align:center;max-width:400px;">
				<h3 style="color:#fff;margin-top:0;">📱 MicroDAGR</h3>
				<p style="color:#aaa;">Escanea con tu móvil para usar como GPS táctico</p>
				<div id="microdagr-qr" style="background:#fff;padding:10px;border-radius:8px;display:inline-block;margin:15px 0;"></div>
				<br>
				<button onclick="document.getElementById('microdagr-modal').style.display='none'" class="button">Cerrar</button>
			</div>
		</div>

		<script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
		<script>
		function openMicroDAGR(token, sessionId) {
			var url = window.location.origin + '/microdagr?token=' + token + '&session=' + sessionId;
			document.getElementById('microdagr-qr').innerHTML = '';
			new QRCode(document.getElementById('microdagr-qr'), {
				text: url,
				width: 256,
				height: 256
			});
			document.getElementById('microdagr-modal').style.display = 'flex';
		}

		(function() {
			var edgeOffset = <?php echo $edge_offset; ?>;
			var scaleFactor = <?php echo $scale_factor; ?>;
			var bounds = [[<?php echo $min_y * $scale_factor; ?>, <?php echo $min_x * $scale_factor; ?>], [<?php echo $max_y * $scale_factor; ?>, <?php echo $max_x * $scale_factor; ?>]];
			var maxZoom = <?php echo $max_zoom; ?>;

			var map = L.map('<?php echo $uid; ?>', {
				zoomControl: true,
				attributionControl: false,
				maxBounds: bounds,
				center: [<?php echo ($max_y * $scale_factor) / 2; ?>, <?php echo ($max_x * $scale_factor) / 2; ?>],
				zoom: Math.floor(maxZoom / 2)
			});

			L.TileLayer.InvertedY = L.TileLayer.extend({
				getTileUrl: function(c) {
					c.y = -(c.y + 1);
					return L.TileLayer.prototype.getTileUrl.call(this, c);
				}
			});

			new L.TileLayer.InvertedY('<?php echo esc_js( $tiles_url ); ?>', {
				maxZoom: maxZoom,
				minZoom: 0,
				zoomReverse: true,
				bounds: bounds,
				errorTileUrl: ''
			}).addTo(map);

			function gameToLatLng(x, y) {
				return [((max_y - y + edgeOffset) * scaleFactor), ((x - edgeOffset) * scaleFactor)];
			}

			var playerMarkers = {};
			var staticMarkers = {};

			// Posiciones de jugadores
			var positions = <?php echo $positions_json; ?>;
			if ( positions && positions.length ) {
				positions.forEach(function(p) {
					var latlng = gameToLatLng(p.pos_x, p.pos_y);
					var isMe = (p.steamid === '<?php echo $current_user_id ? esc_js( get_user_meta( $current_user_id, 'steamid_64', true ) ) : ''; ?>');
					var color = p.is_alive ? (isMe ? '#22c55e' : '#3b82f6') : '#ef4444';
					var size = isMe ? '16px' : '12px';
					var icon = L.divIcon({
						className: 'dagr-player-marker',
						html: '<div style="width:'+size+';height:'+size+';background:'+color+';border:2px solid #fff;border-radius:50%;box-shadow:0 0 8px '+color+';" title="'+(p.player_name||'')+'"></div>',
						iconSize: [20,20],
						iconAnchor: [10,10]
					});
					var marker = L.marker(latlng, { icon: icon }).addTo(map);
					marker.bindTooltip((p.player_name||'')+' ['+Math.round(p.pos_x)+' '+Math.round(p.pos_y)+']', {direction:'top', offset:[0,-12]});
					playerMarkers[p.steamid] = marker;
				});
			}

			// Marcadores de misión
			var markers = <?php echo $markers_json; ?>;
			var markerIcons = {
				'objective': '<div style="width:16px;height:16px;background:#22c55e;border:2px solid #fff;border-radius:3px;transform:rotate(45deg);box-shadow:0 0 8px rgba(34,197,94,0.5);"></div>',
				'enemy': '<div style="width:14px;height:14px;background:#ef4444;border:2px solid #fff;border-radius:50%;box-shadow:0 0 8px rgba(239,68,68,0.5);"></div>',
				'friendly': '<div style="width:14px;height:14px;background:#3b82f6;border:2px solid #fff;border-radius:50%;box-shadow:0 0 8px rgba(59,130,246,0.5);"></div>',
				'marker': '<div style="width:14px;height:14px;background:#d2a850;border:2px solid #fff;border-radius:50%;box-shadow:0 0 8px rgba(210,168,80,0.5);"></div>'
			};
			if ( markers && markers.length ) {
				markers.forEach(function(m) {
					var latlng = gameToLatLng(m.pos_x, m.pos_y);
					var html = markerIcons[m.type] || markerIcons['marker'];
					var icon = L.divIcon({ className: 'dagr-map-marker', html: html, iconSize: [20,20], iconAnchor: [10,10] });
					L.marker(latlng, { icon: icon }).addTo(map).bindTooltip(m.label || m.type, { direction:'top', offset:[0,-12] });
				});
			}

			// Polling cada 10s
			var sessionId = '<?php echo esc_js( $session_id ); ?>';
			if ( sessionId ) {
				setInterval(function() {
					fetch('<?php echo rest_url( 'clan/v1/mission/positions' ); ?>?session=' + sessionId)
						.then(function(r) { return r.json(); })
						.then(function(data) {
							if (!data.players) return;
							var seen = {};
							data.players.forEach(function(p) {
								seen[p.steamid] = true;
								var latlng = gameToLatLng(p.pos_x, p.pos_y);
								var isMe = (p.steamid === '<?php echo esc_js( get_user_meta( $current_user_id, 'steamid_64', true ) ); ?>');
								var color = p.is_alive ? (isMe ? '#22c55e' : '#3b82f6') : '#ef4444';
								var size = isMe ? '16px' : '12px';
								var icon = L.divIcon({
									className: 'dagr-player-marker',
									html: '<div style="width:'+size+';height:'+size+';background:'+color+';border:2px solid #fff;border-radius:50%;box-shadow:0 0 8px '+color+';"></div>',
									iconSize: [20,20],
									iconAnchor: [10,10]
								});
								if (playerMarkers[p.steamid]) {
									playerMarkers[p.steamid].setLatLng(latlng);
								} else {
									var marker = L.marker(latlng, { icon: icon }).addTo(map);
									marker.bindTooltip((p.player_name||'')+' ['+Math.round(p.pos_x)+' '+Math.round(p.pos_y)+']', {direction:'top', offset:[0,-12]});
									playerMarkers[p.steamid] = marker;
								}
							});
							for (var id in playerMarkers) {
								if (!seen[id]) {
									map.removeLayer(playerMarkers[id]);
									delete playerMarkers[id];
								}
							}
						});
				}, 10000);
			}
		})();
		</script>
		<?php
		return ob_get_clean();
	}

	/**
	 * Shortcode [rmm_microdagr] — Botón MicroDAGR (usa session_id o detecta activa)
	 */
	public function render_microdagr_button( $atts ) {
		if ( ! is_user_logged_in() ) return '';

		$current_user_id = get_current_user_id();
		$steamid = get_user_meta( $current_user_id, 'steamid_64', true );
		if ( empty( $steamid ) ) return '';

		global $wpdb;
		$atts = shortcode_atts( array(
			'session_id' => '',
			'post_id'    => get_the_ID(),
		), $atts, 'rmm_microdagr' );

		$session_id = $atts['session_id'];
		if ( empty( $session_id ) ) {
			$table_sessions = $wpdb->prefix . 'rmm_mission_sessions';
			$session = $wpdb->get_row( $wpdb->prepare(
				"SELECT session_id FROM $table_sessions WHERE post_id = %d AND status = 'active' ORDER BY started_at DESC LIMIT 1",
				$atts['post_id']
			) );
			if ( $session ) $session_id = $session->session_id;
		}

		if ( empty( $session_id ) ) return '<p>No hay sesión activa para esta misión.</p>';

		$table_tokens = $wpdb->prefix . 'rmm_microdagr_tokens';
		$existing = $wpdb->get_row( $wpdb->prepare(
			"SELECT token FROM $table_tokens WHERE user_id = %d AND session_id = %s AND (expires_at IS NULL OR expires_at > NOW())",
			$current_user_id, $session_id
		) );

		if ( $existing ) {
			$token = $existing->token;
		} else {
			$token = wp_generate_password( 32, false );
			$wpdb->insert( $table_tokens, array(
				'token'      => $token,
				'user_id'    => $current_user_id,
				'steamid'    => $steamid,
				'session_id' => $session_id,
				'expires_at' => date( 'Y-m-d H:i:s', strtotime( '+24 hours' ) ),
			) );
		}

		ob_start();
		?>
		<button onclick="openMicroDAGRBtn('<?php echo esc_js( $token ); ?>','<?php echo esc_js( $session_id ); ?>')" class="button button-primary" style="background:#22c55e;border-color:#16a34a;color:#fff;font-size:16px;padding:12px 24px;">📱 MicroDAGR</button>

		<div id="microdagr-btn-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.8);z-index:9999;justify-content:center;align-items:center;">
			<div style="background:#1a1a1a;padding:30px;border-radius:12px;text-align:center;max-width:400px;">
				<h3 style="color:#fff;margin-top:0;">📱 MicroDAGR</h3>
				<p style="color:#aaa;">Escanea con tu móvil para usar como GPS táctico</p>
				<div id="microdagr-btn-qr" style="background:#fff;padding:10px;border-radius:8px;display:inline-block;margin:15px 0;"></div>
				<br>
				<button onclick="document.getElementById('microdagr-btn-modal').style.display='none'" class="button">Cerrar</button>
			</div>
		</div>
		<script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
		<script>
		function openMicroDAGRBtn(token, session) {
			var url = window.location.origin + '/microdagr?token=' + token + '&session=' + session;
			document.getElementById('microdagr-btn-qr').innerHTML = '';
			new QRCode(document.getElementById('microdagr-btn-qr'), { text: url, width: 256, height: 256 });
			document.getElementById('microdagr-btn-modal').style.display = 'flex';
		}
		</script>
		<?php
		return ob_get_clean();
	}

	/**
	 * Registrar página /microdagr para acceso mobile
	 */
	public function register_microdagr_page() {
		add_rewrite_rule( '^microdagr/?$', 'index.php?rmm_microdagr=1', 'top' );
		add_rewrite_tag( '%rmm_microdagr%', '1' );
	}

	/**
	 * Cargar template de MicroDAGR
	 */
	public function load_microdagr_template( $template ) {
		global $wp;
		if ( isset( $_GET['token'] ) && isset( $_GET['session'] ) ) {
			// Servir página mobile
			$token = sanitize_text_field( $_GET['token'] );
			$session = sanitize_text_field( $_GET['session'] );

			global $wpdb;
			$table_tokens = $wpdb->prefix . 'rmm_microdagr_tokens';
			$valid = $wpdb->get_row( $wpdb->prepare(
				"SELECT * FROM $table_tokens WHERE token = %s AND session_id = %s AND (expires_at IS NULL OR expires_at > NOW())",
				$token, $session
			) );

			if ( ! $valid ) {
				wp_die( 'Token inválido o expirado.' );
			}

			// Datos de la sesión
			$table_sessions = $wpdb->prefix . 'rmm_mission_sessions';
			$session_data = $wpdb->get_row( $wpdb->prepare(
				"SELECT * FROM $table_sessions WHERE session_id = %s", $session
			) );

			$map_name = $session_data ? $session_data->map_name : 'everon';

			$table_maps = $wpdb->prefix . 'rmm_dagr_maps';
			$map_config = $wpdb->get_row( $wpdb->prepare(
				"SELECT * FROM $table_maps WHERE map_name = %s AND enabled = 1", $map_name
			) );

			$min_x = floatval( $map_config->min_x );
			$min_y = floatval( $map_config->min_y );
			$max_x = floatval( $map_config->max_x );
			$max_y = floatval( $map_config->max_y );
			$max_zoom = intval( $map_config->max_zoom );
			$edge_offset = intval( $map_config->edge_offset );
			$scale_factor = floatval( $map_config->scale_factor );
			$tiles_url = ! empty( $map_config->tiles_path ) ? $map_config->tiles_path : '../mapas/mapa_' . $map_name . '/{z}/{x}/{y}/tile.jpg';
			// Corregir paths relativos para la página mobile
			if ( strpos( $tiles_url, '..' ) === 0 || strpos( $tiles_url, '/' ) === 0 ) {
				$tiles_url = site_url( $tiles_url );
			}

			// Header para mobile
			header( 'Content-Type: text/html; charset=utf-8' );
			?>
<!DOCTYPE html>
<html lang="es">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
	<meta name="apple-mobile-web-app-capable" content="yes">
	<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
	<title>MicroDAGR — <?= esc_html( $map_name ) ?></title>
	<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
	<style>
		* { margin: 0; padding: 0; box-sizing: border-box; }
		html, body { width: 100%; height: 100%; overflow: hidden; background: #0d1117; font-family: monospace; -webkit-tap-highlight-color: transparent; }
		#map { position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: 1; }
		/* UI overlay */
		.dagr-ui { position: fixed; z-index: 100; pointer-events: none; top: 0; left: 0; width: 100%; height: 100%; }
		.dagr-ui > * { pointer-events: auto; }
		#hud {
			position: absolute; bottom: 0; left: 0; right: 0;
			background: rgba(0,0,0,0.85); color: #CFDC35;
			padding: 6px 10px; font-size: 11px;
			display: flex; justify-content: space-between; align-items: center;
		}
		#hud-left div { line-height: 1.4; }
		#compass-ring {
			width: 70px; height: 70px; border-radius: 50%;
			border: 2px solid #CFDC35; position: relative;
			display: flex; align-items: center; justify-content: center;
		}
		#compass-arrow {
			width: 0; height: 0;
			border-left: 5px solid transparent;
			border-right: 5px solid transparent;
			border-bottom: 24px solid #ef4444;
			transition: transform 0.15s;
		}
		#compass-label { position: absolute; top: 1px; font-size: 9px; color: #fff; }
		#wp-list {
			position: absolute; top: 8px; right: 8px;
			background: rgba(0,0,0,0.8); color: #fff; padding: 6px 8px;
			border-radius: 6px; max-height: 40vh; overflow-y: auto;
			font-size: 10px; min-width: 120px;
		}
		#wp-list h4 { margin-bottom: 4px; color: #22c55e; font-size: 11px; }
		.wp-item { padding: 2px 0; border-bottom: 1px solid #333; display: flex; justify-content: space-between; gap: 6px; }
		.wp-item .wp-num { color: #d2a850; font-weight: bold; }
		.dagr-btn {
			position: absolute; border-radius: 50%; border: none; cursor: pointer;
			font-size: 20px; display: flex; align-items: center; justify-content: center;
		}
		#wp-add-btn { top: 8px; left: 8px; width: 40px; height: 40px; background: rgba(34,197,94,0.9); color: #fff; }
		#wp-add-btn.active { background: rgba(239,68,68,0.9); }
		#mode-toggle { top: 8px; left: 56px; width: auto; height: 40px; padding: 0 12px; border-radius: 8px; background: rgba(0,0,0,0.7); color: #fff; border: 1px solid #CFDC35; font-size: 12px; }
		#center-btn { bottom: 70px; right: 12px; width: 40px; height: 40px; background: rgba(34,197,94,0.9); color: #fff; }
		#center-btn.active { background: rgba(239,68,68,0.9); }
		#layer-btn { bottom: 70px; left: 12px; width: 40px; height: 40px; background: rgba(0,0,0,0.7); color: #fff; border: 1px solid #CFDC35; }
		#layer-panel {
			display: none; position: absolute; bottom: 120px; left: 12px;
			background: rgba(0,0,0,0.9); color: #fff; padding: 10px;
			border-radius: 8px; min-width: 160px; font-size: 11px; border: 1px solid #333;
		}
		#layer-panel.show { display: block; }
		.layer-toggle { display: flex; align-items: center; padding: 3px 0; gap: 6px; }
		.layer-toggle input { accent-color: #22c55e; }
		.compass-mode .dagr-ui .dagr-btn:not(#center-btn) { opacity: 0.4; }
	</style>
</head>
<body>
	<div id="map"></div>
	<div class="dagr-ui">
		<button id="wp-add-btn" class="dagr-btn" title="Añadir waypoint">+</button>
		<button id="mode-toggle" class="dagr-btn">🧭 Brújula</button>
		<button id="layer-btn" class="dagr-btn">📑</button>
		<div id="layer-panel">
			<h4 style="margin:0 0 6px;color:#CFDC35;">Capas</h4>
			<label class="layer-toggle"><input type="checkbox" checked data-layer="players"> 👥 Jugadores</label>
			<label class="layer-toggle"><input type="checkbox" checked data-layer="objective"> 🟢 Objetivos</label>
			<label class="layer-toggle"><input type="checkbox" checked data-layer="completed"> 🟡 Completados</label>
			<label class="layer-toggle"><input type="checkbox" checked data-layer="enemy"> 🔴 Enemigos</label>
			<label class="layer-toggle"><input type="checkbox" checked data-layer="friendly"> 🔵 Aliados</label>
			<label class="layer-toggle"><input type="checkbox" checked data-layer="waypoints"> 📍 Waypoints</label>
		</div>
		<button id="center-btn" class="dagr-btn" title="Centrar">◎</button>
		<div id="wp-list"><h4>Waypoints</h4><div id="wp-items"></div></div>
		<div id="hud">
			<div id="hud-left">
				<div>X: <span id="hud-x">----</span> Y: <span id="hud-y">----</span></div>
				<div>Z: <span id="hud-z">--</span>m | Spd: <span id="hud-speed">0</span></div>
			</div>
			<div id="compass-ring">
				<div id="compass-label">N</div>
				<div id="compass-arrow"></div>
			</div>
		</div>
	</div>

	<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
	<script>
	(function() {
		var token = '<?= esc_js( $token ) ?>';
		var sessionId = '<?= esc_js( $session ) ?>';
		var edgeOffset = <?= $edge_offset ?>;
		var scaleFactor = <?= $scale_factor ?>;
		var maxY = <?= $max_y ?>;
		var maxZoom = <?= $max_zoom ?>;
		var bounds = L.latLngBounds(
			L.latLng([<?= $min_y * $scale_factor ?>, <?= $min_x * $scale_factor ?>]),
			L.latLng([<?= $max_y * $scale_factor ?>, <?= $max_x * $scale_factor ?>])
		);

		var map = L.map('map', {
			maxBounds: bounds,
			zoomControl: true,
			attributionControl: false
		});

		L.TileLayer.InvertedY = L.TileLayer.extend({
			getTileUrl: function(c) {
				c.y = -(c.y + 1);
				return L.TileLayer.prototype.getTileUrl.call(this, c);
			}
		});

		new L.TileLayer.InvertedY('<?= esc_js( $tiles_url ) ?>', {
			maxZoom: maxZoom,
			minZoom: 0,
			zoomReverse: true,
			bounds: bounds,
			errorTileUrl: ''
		}).addTo(map);

		map.fitBounds(bounds);

		function gameToLatLng(x, y) {
			return L.latLng([Number(y) + <?= $edge_offset ?>, Number(x) + <?= $edge_offset ?>]);
		}
		function latLngToGame(lat, lng) {
			return { x: Math.round(lng - <?= $edge_offset ?>), y: Math.round(lat - <?= $edge_offset ?>) };
		}

		// Mi marcador
		var myMarker = L.divIcon({
			className: 'dagr-player-marker',
			html: '<div style="width:16px;height:16px;background:#22c55e;border:3px solid #fff;border-radius:50%;box-shadow:0 0 12px #22c55e;"></div>',
			iconSize: [22,22], iconAnchor: [11,11]
		});
		var me = L.marker([0,0], { icon: myMarker, zIndexOffset: 1000 }).addTo(map);
		var followMe = false;

		// Capas
		var layers = { players: [], objective: [], completed: [], enemy: [], friendly: [], waypoints: [] };

		function getLayerType(type) {
			if (type === 'objective') return 'objective';
			if (type === 'completed') return 'completed';
			if (type === 'enemy') return 'enemy';
			if (type === 'friendly') return 'friendly';
			return 'friendly';
		}

		function applyLayers() {
			Object.keys(layers).forEach(function(key) {
				var visible = document.querySelector('[data-layer="'+key+'"]').checked;
				layers[key].forEach(function(m) { visible ? m.addTo(map) : map.removeLayer(m); });
			});
		}

		document.getElementById('layer-btn').addEventListener('click', function() {
			document.getElementById('layer-panel').classList.toggle('show');
		});
		document.querySelectorAll('.layer-toggle input').forEach(function(cb) {
			cb.addEventListener('change', applyLayers);
		});

		// Centrar en mí
		document.getElementById('center-btn').addEventListener('click', function() {
			followMe = !followMe;
			this.classList.toggle('active', followMe);
			if (followMe && me.getLatLng().lat !== 0) map.panTo(me.getLatLng());
		});

		// Heading arrow
		var headingMarker = null;

		// Waypoints
		var waypoints = [];
		var wpMarkers = [];

		function loadWaypoints() {
			fetch('/wp-json/clan/v1/microdagr/waypoints?token=' + token)
				.then(r => r.json())
				.then(data => {
					waypoints = data.waypoints || [];
					renderWaypoints();
				});
		}

		function renderWaypoints() {
			var html = '';
			waypoints.forEach(function(wp, i) {
				html += '<div class="wp-item"><span class="wp-num">'+(i+1)+'.</span> <span>'+wp.label+'</span></div>';
			});
			document.getElementById('wp-items').innerHTML = html || '<em>Sin waypoints</em>';
		}

		// Click para añadir waypoint
		map.on('click', function(e) {
			if (!document.getElementById('wp-add-btn').classList.contains('active')) return;
			var game = latLngToGame(e.latlng.lat, e.latlng.lng);
			var label = prompt('Nombre del waypoint:', 'WP ' + (waypoints.length + 1));
			if (!label) return;

			fetch('/wp-json/clan/v1/microdagr/waypoints', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify({ token: token, label: label, pos_x: game.x, pos_y: game.y, order_index: waypoints.length })
			}).then(r => r.json()).then(() => loadWaypoints());
		});

		document.getElementById('wp-add-btn').addEventListener('click', function() {
			this.classList.toggle('active');
			this.style.background = this.classList.contains('active') ? '#ef4444' : '#22c55e';
		});

		// Compass mode
		var compassMode = false;
		document.getElementById('mode-toggle').addEventListener('click', function() {
			compassMode = !compassMode;
			document.body.classList.toggle('compass-mode', compassMode);
			this.textContent = compassMode ? '🗺️ Mapa' : '🧭 Brújula';
		});

		// Device orientation
		var heading = 0;
		if (window.DeviceOrientationEvent) {
			window.addEventListener('deviceorientation', function(e) {
				if (e.alpha !== null) {
					heading = Math.round(e.alpha);
					document.getElementById('compass-arrow').style.transform = 'rotate(' + heading + 'deg)';
				}
			});
		}

		// Polling posición desde servidor (NO usar GPS del móvil)
		setInterval(function() {
			fetch('/wp-json/clan/v1/mission/positions?session=' + sessionId + '&token=' + token)
				.then(r => r.json())
				.then(data => {
					if (!data.players) return;
					var meData = data.players.find(function(p) { return p.token === token || p.steamid === '<?php echo esc_js( $valid->steamid ); ?>'; });
					if (meData) {
						var latlng = gameToLatLng(meData.pos_x, meData.pos_y);
						me.setLatLng(latlng);
						if (followMe) map.panTo(latlng, { animate: true });

						document.getElementById('hud-x').textContent = Math.round(meData.pos_x);
						document.getElementById('hud-y').textContent = Math.round(meData.pos_y);
						document.getElementById('hud-z').textContent = Math.round(meData.pos_z || 0);
						document.getElementById('hud-speed').textContent = Math.round(meData.speed || 0);

						if (compassMode && meData.heading) {
							var heading = meData.heading;
							document.getElementById('compass-arrow').style.transform = 'rotate(' + heading + 'deg)';
						}
					}

					// Actualizar capa de jugadores
					layers.players.forEach(function(m) { map.removeLayer(m); });
					layers.players = [];
					data.players.forEach(function(p) {
						var isMe = (p.steamid === '<?php echo esc_js( $valid->steamid ); ?>');
						if (isMe) return;
						var latlng = gameToLatLng(p.pos_x, p.pos_y);
						var color = p.is_alive ? '#3b82f6' : '#ef4444';
						var icon = L.divIcon({
							className: 'dagr-player-marker',
							html: '<div style="width:12px;height:12px;background:'+color+';border:2px solid #fff;border-radius:50%;"></div>',
							iconSize: [16,16], iconAnchor: [8,8]
						});
						var marker = L.marker(latlng, { icon: icon }).bindTooltip(p.player_name||'');
						layers.players.push(marker);
						if (document.querySelector('[data-layer="players"]').checked) marker.addTo(map);
					});
				});
		}, 5000);

		// Cargar marcadores del preset y de la sesión
		fetch('/wp-json/clan/v1/mission/markers?session=' + sessionId)
			.then(r => r.json())
			.then(data => {
				if (!data.markers) return;
				var markerIconMap = {
					'objective': '<div style="width:16px;height:16px;background:#22c55e;border:2px solid #fff;border-radius:3px;transform:rotate(45deg);"></div>',
					'completed': '<div style="width:14px;height:14px;background:#d2a850;border:2px solid #fff;border-radius:50%;"></div>',
					'enemy': '<div style="width:14px;height:14px;background:#ef4444;border:2px solid #fff;border-radius:50%;"></div>',
					'friendly': '<div style="width:14px;height:14px;background:#3b82f6;border:2px solid #fff;border-radius:50%;"></div>'
				};
				data.markers.forEach(function(m) {
					var latlng = gameToLatLng(m.pos_x, m.pos_y);
					var html = markerIconMap[m.type] || markerIconMap['friendly'];
					var icon = L.divIcon({ className: 'dagr-map-marker', html: html, iconSize: [20,20], iconAnchor: [10,10] });
					var marker = L.marker(latlng, { icon: icon }).bindTooltip(m.label || m.type);
					var layerType = getLayerType(m.type);
					layers[layerType].push(marker);
					if (document.querySelector('[data-layer="'+layerType+'"]').checked) marker.addTo(map);
				});
			});

		loadWaypoints();
		map.fitBounds(bounds);
	})();
	</script>
</body>
</html>
			<?php
			exit;
		}

		return $template;
	}

	/**
	 * REST Endpoints
	 */
	public function register_rest_endpoints() {
		// Recibir posiciones de partida (addon → WP)
		register_rest_route( 'clan/v1', '/mission/telemetry', array(
			'methods'  => 'POST',
			'callback' => array( $this, 'receive_telemetry' ),
			'permission_callback' => array( $this, 'verify_telemetry_token' ),
		) );

		// Obtener posiciones de sesión (polling)
		register_rest_route( 'clan/v1', '/mission/positions', array(
			'methods'  => 'GET',
			'callback' => array( $this, 'get_mission_positions' ),
			'permission_callback' => '__return_true',
		) );

		// Obtener marcadores de sesión
		register_rest_route( 'clan/v1', '/mission/markers', array(
			'methods'  => 'GET',
			'callback' => array( $this, 'get_mission_markers' ),
			'permission_callback' => '__return_true',
		) );

		// Crear marcador
		register_rest_route( 'clan/v1', '/mission/markers', array(
			'methods'  => 'POST',
			'callback' => array( $this, 'create_mission_marker' ),
			'permission_callback' => array( $this, 'verify_telemetry_token' ),
		) );

		// MicroDAGR: waypoints
		register_rest_route( 'clan/v1', '/microdagr/waypoints', array(
			'methods'  => 'GET',
			'callback' => array( $this, 'get_waypoints' ),
			'permission_callback' => '__return_true',
			'args'     => array( 'token' => array( 'required' => true, 'type' => 'string' ) ),
		) );

		register_rest_route( 'clan/v1', '/microdagr/waypoints', array(
			'methods'  => 'POST',
			'callback' => array( $this, 'create_waypoint' ),
			'permission_callback' => '__return_true',
		) );

		// Endpoint de TEST — simular telemetría
		register_rest_route( 'clan/v1', '/mission/test-session', array(
			'methods'  => 'POST',
			'callback' => array( $this, 'create_test_session' ),
			'permission_callback' => '__return_true',
		) );
	}

	/**
	 * Verificar token de telemetría
	 */
	public function verify_telemetry_token( $request ) {
		$token = $request->get_header( 'X-Milsim-Token' ) ?: $request->get_param( 'token' );
		if ( empty( $token ) ) return false;
		// Verificar formato TFR_*
		return strpos( $token, 'TFR_' ) === 0;
	}

	/**
	 * Recibir telemetría del addon
	 * El addon SOLO necesita enviar preset_id y session_id
	 */
	public function receive_telemetry( $request ) {
		global $wpdb;
		$data = $request->get_json_params() ?: $request->get_params();

		$session_id  = sanitize_text_field( $data['session_id'] ?? '' );
		$preset_id   = intval( $data['preset_id'] ?? 0 );
		$map_name    = sanitize_text_field( $data['map_name'] ?? '' );

		// Si no viene map_name, extraerlo del preset
		if ( empty( $map_name ) && $preset_id ) {
			$table_presets = $wpdb->prefix . 'rmm_dagr_presets';
			$preset = $wpdb->get_row( $wpdb->prepare(
				"SELECT map_name FROM $table_presets WHERE id = %d", $preset_id
			) );
			if ( $preset ) $map_name = $preset->map_name;
		}

		if ( empty( $map_name ) ) $map_name = 'everon';

		// Crear sesión si no existe
		$table_sessions = $wpdb->prefix . 'rmm_mission_sessions';
		$exists = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM $table_sessions WHERE session_id = %s", $session_id
		) );

		if ( ! $exists && $session_id ) {
			$wpdb->insert( $table_sessions, array(
				'session_id' => $session_id,
				'post_id'    => $preset_id,
				'map_name'   => $map_name,
				'status'     => 'active',
			) );
		}

		// Guardar posiciones
		$table_positions = $wpdb->prefix . 'rmm_mission_positions';
		$players = $data['players'] ?? array();
		foreach ( $players as $p ) {
			$wpdb->insert( $table_positions, array(
				'session_id'  => $session_id,
				'steamid'     => sanitize_text_field( $p['steamid'] ?? '' ),
				'bohemia_uid' => sanitize_text_field( $p['bohemia_uid'] ?? '' ),
				'player_name' => sanitize_text_field( $p['name'] ?? '' ),
				'faction'     => sanitize_text_field( $p['faction'] ?? '' ),
				'squad'       => sanitize_text_field( $p['squad'] ?? '' ),
				'role'        => sanitize_text_field( $p['role'] ?? '' ),
				'pos_x'       => floatval( $p['pos_x'] ?? $p['Ejex'] ?? 0 ),
				'pos_y'       => floatval( $p['pos_y'] ?? $p['Ejey'] ?? 0 ),
				'pos_z'       => floatval( $p['pos_z'] ?? 0 ),
				'heading'     => floatval( $p['heading'] ?? $p['Dir'] ?? 0 ),
				'speed'       => floatval( $p['speed_kmh'] ?? 0 ),
				'is_alive'    => ! empty( $p['is_alive'] ) ? 1 : 1,
			) );
		}

		// Guardar marcadores
		$table_markers = $wpdb->prefix . 'rmm_mission_markers';
		$markers = $data['markers'] ?? array();
		foreach ( $markers as $m ) {
			$wpdb->insert( $table_markers, array(
				'session_id'  => $session_id,
				'marker_id'   => sanitize_text_field( $m['id'] ?? uniqid( 'm' ) ),
				'type'        => sanitize_text_field( $m['type'] ?? 'marker' ),
				'label'       => sanitize_text_field( $m['label'] ?? '' ),
				'pos_x'       => floatval( $m['pos_x'] ?? 0 ),
				'pos_y'       => floatval( $m['pos_y'] ?? 0 ),
				'reported_by' => sanitize_text_field( $m['reported_by'] ?? '' ),
				'color'       => sanitize_text_field( $m['color'] ?? '#d2a850' ),
			) );
		}

		return rest_ensure_response( array( 'status' => 'ok', 'players_received' => count( $players ), 'markers_received' => count( $markers ) ) );
	}

	/**
	 * Obtener posiciones de sesión
	 */
	public function get_mission_positions( $request ) {
		global $wpdb;
		$session_id = sanitize_text_field( $request->get_param( 'session' ) );
		if ( empty( $session_id ) ) return rest_ensure_response( array( 'players' => array() ) );

		$table_positions = $wpdb->prefix . 'rmm_mission_positions';
		$positions = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.* FROM $table_positions p
			INNER JOIN (
				SELECT steamid, MAX(recorded_at) as max_time
				FROM $table_positions WHERE session_id = %s
				GROUP BY steamid
			) latest ON p.steamid = latest.steamid AND p.recorded_at = latest.max_time
			WHERE p.session_id = %s",
			$session_id, $session_id
		), ARRAY_A );

		return rest_ensure_response( array( 'players' => $positions, 'count' => count( $positions ) ) );
	}

	/**
	 * Obtener marcadores de sesión
	 */
	public function get_mission_markers( $request ) {
		global $wpdb;
		$session_id = sanitize_text_field( $request->get_param( 'session' ) );
		if ( empty( $session_id ) ) return rest_ensure_response( array( 'markers' => array() ) );

		$table_markers = $wpdb->prefix . 'rmm_mission_markers';
		$markers = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM $table_markers WHERE session_id = %s ORDER BY recorded_at DESC", $session_id
		), ARRAY_A );

		return rest_ensure_response( array( 'markers' => $markers, 'count' => count( $markers ) ) );
	}

	/**
	 * Crear marcador de misión
	 */
	public function create_mission_marker( $request ) {
		global $wpdb;
		$data = $request->get_json_params() ?: $request->get_params();
		$session_id = sanitize_text_field( $data['session_id'] ?? '' );
		if ( empty( $session_id ) ) return rest_ensure_response( array( 'error' => 'No session' ) );

		$table_markers = $wpdb->prefix . 'rmm_mission_markers';
		$wpdb->insert( $table_markers, array(
			'session_id'  => $session_id,
			'marker_id'   => sanitize_text_field( $data['id'] ?? uniqid( 'm' ) ),
			'type'        => sanitize_text_field( $data['type'] ?? 'marker' ),
			'label'       => sanitize_text_field( $data['label'] ?? '' ),
			'pos_x'       => floatval( $data['pos_x'] ?? 0 ),
			'pos_y'       => floatval( $data['pos_y'] ?? 0 ),
			'reported_by' => sanitize_text_field( $data['reported_by'] ?? '' ),
			'color'       => sanitize_text_field( $data['color'] ?? '#d2a850' ),
		) );

		return rest_ensure_response( array( 'status' => 'ok' ) );
	}

	/**
	 * Obtener waypoints MicroDAGR
	 */
	public function get_waypoints( $request ) {
		global $wpdb;
		$token = sanitize_text_field( $request->get_param( 'token' ) );
		if ( empty( $token ) ) return rest_ensure_response( array( 'waypoints' => array() ) );

		$table_waypoints = $wpdb->prefix . 'rmm_waypoints';
		$wps = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM $table_waypoints WHERE token = %s AND is_active = 1 ORDER BY order_index ASC", $token
		), ARRAY_A );

		return rest_ensure_response( array( 'waypoints' => $wps ) );
	}

	/**
	 * Crear waypoint MicroDAGR
	 */
	public function create_waypoint( $request ) {
		global $wpdb;
		$data = $request->get_json_params() ?: $request->get_params();
		$token = sanitize_text_field( $data['token'] ?? '' );
		if ( empty( $token ) ) return rest_ensure_response( array( 'error' => 'No token' ) );

		$table_waypoints = $wpdb->prefix . 'rmm_waypoints';
		$wpdb->insert( $table_waypoints, array(
			'token'       => $token,
			'label'       => sanitize_text_field( $data['label'] ?? 'WP' ),
			'pos_x'       => floatval( $data['pos_x'] ?? 0 ),
			'pos_y'       => floatval( $data['pos_y'] ?? 0 ),
			'order_index' => intval( $data['order_index'] ?? 0 ),
		) );

		return rest_ensure_response( array( 'status' => 'ok' ) );
	}

	/**
	 * Crear sesión de TEST con datos simulados
	 * POST /wp-json/clan/v1/mission/test-session
	 * { "preset_id": 3, "session_id": "test_123" }
	 * Devuelve token MicroDAGR para probar en móvil
	 */
	public function create_test_session( $request ) {
		global $wpdb;
		$data = $request->get_json_params() ?: $request->get_params();
		$preset_id  = intval( $data['preset_id'] ?? 1 );
		$session_id = sanitize_text_field( $data['session_id'] ?? 'test_' . time() );

		// Obtener mapa del preset
		$table_presets = $wpdb->prefix . 'rmm_dagr_presets';
		$preset = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM $table_presets WHERE id = %d", $preset_id
		) );
		if ( ! $preset ) return rest_ensure_response( array( 'error' => 'Preset no encontrado' ) );

		$map_name = $preset->map_name;

		// Crear sesión
		$table_sessions = $wpdb->prefix . 'rmm_mission_sessions';
		$wpdb->delete( $table_sessions, array( 'session_id' => $session_id ) );
		$wpdb->insert( $table_sessions, array(
			'session_id' => $session_id,
			'post_id'    => $preset_id,
			'map_name'   => $map_name,
			'status'     => 'active',
		) );

		// Crear usuario test si no existe
		$test_user_id = 999999;
		$test_steamid = '76561198000000001';

		// Limpiar datos anteriores de esta sesión
		$table_positions = $wpdb->prefix . 'rmm_mission_positions';
		$wpdb->delete( $table_positions, array( 'session_id' => $session_id ) );
		$table_markers = $wpdb->prefix . 'rmm_mission_markers';
		$wpdb->delete( $table_markers, array( 'session_id' => $session_id ) );

		// Insertar jugadores simulados en Everon
		$test_players = array(
			array( 'name' => 'TRAUMAN',     'steamid' => $test_steamid,  'pos_x' => 5420, 'pos_y' => 3210, 'pos_z' => 15, 'heading' => 270, 'squad' => 'Alpha-1', 'faction' => 'BLUFOR', 'role' => 'Líder' ),
			array( 'name' => 'ANTIGRAVITY', 'steamid' => '76561198000000002', 'pos_x' => 5450, 'pos_y' => 3180, 'pos_z' => 12, 'heading' => 265, 'squad' => 'Alpha-1', 'faction' => 'BLUFOR', 'role' => 'Médico' ),
			array( 'name' => 'ZULU_1',      'steamid' => '76561198000000003', 'pos_x' => 6100, 'pos_y' => 4500, 'pos_z' => 8,  'heading' => 180, 'squad' => 'Alpha-2', 'faction' => 'BLUFOR', 'role' => 'Fusilero' ),
			array( 'name' => 'ENEMY_1',     'steamid' => '76561198000000004', 'pos_x' => 7200, 'pos_y' => 5800, 'pos_z' => 22, 'heading' => 90,  'squad' => 'OpFor-A', 'faction' => 'OPFOR',  'role' => 'AT' ),
			array( 'name' => 'ENEMY_2',     'steamid' => '76561198000000005', 'pos_x' => 7350, 'pos_y' => 5650, 'pos_z' => 18, 'heading' => 95,  'squad' => 'OpFor-A', 'faction' => 'OPFOR',  'role' => 'Rifleman' ),
		);
		foreach ( $test_players as $p ) {
			$wpdb->insert( $table_positions, array(
				'session_id'  => $session_id,
				'steamid'     => $p['steamid'],
				'player_name' => $p['name'],
				'squad'       => $p['squad'],
				'faction'     => $p['faction'],
				'role'        => $p['role'],
				'pos_x'       => $p['pos_x'],
				'pos_y'       => $p['pos_y'],
				'pos_z'       => $p['pos_z'],
				'heading'     => $p['heading'],
				'speed'       => rand( 0, 8 ),
				'is_alive'    => 1,
			) );
		}

		// Insertar marcadores de ejemplo
		$test_markers = array(
			array( 'id' => 'obj1', 'type' => 'objective', 'label' => 'Capturar base Alpha', 'pos_x' => 5000, 'pos_y' => 3000 ),
			array( 'id' => 'obj2', 'type' => 'objective', 'label' => 'Destruir almacén',    'pos_x' => 7000, 'pos_y' => 5500 ),
			array( 'id' => 'obj3', 'type' => 'completed', 'label' => 'Reconocer zona',      'pos_x' => 4000, 'pos_y' => 2000 ),
			array( 'id' => 'e1',   'type' => 'enemy',     'label' => 'Tanque T-72',         'pos_x' => 7200, 'pos_y' => 5800 ),
			array( 'id' => 'f1',   'type' => 'friendly',  'label' => 'Punto extracción',    'pos_x' => 3500, 'pos_y' => 1500 ),
		);
		foreach ( $test_markers as $m ) {
			$wpdb->insert( $table_markers, array(
				'session_id' => $session_id,
				'marker_id'  => $m['id'],
				'type'       => $m['type'],
				'label'      => $m['label'],
				'pos_x'      => $m['pos_x'],
				'pos_y'      => $m['pos_y'],
				'color'      => '#d2a850',
			) );
		}

		// Generar token MicroDAGR
		$table_tokens = $wpdb->prefix . 'rmm_microdagr_tokens';
		$token = 'test_' . wp_generate_password( 16, false );
		$wpdb->delete( $table_tokens, array( 'steamid' => $test_steamid, 'session_id' => $session_id ) );
		$wpdb->insert( $table_tokens, array(
			'token'      => $token,
			'user_id'    => 0,
			'steamid'    => $test_steamid,
			'session_id' => $session_id,
			'expires_at' => date( 'Y-m-d H:i:s', strtotime( '+2 hours' ) ),
		) );

		$mobile_url = site_url( '/microdagr?token=' . $token . '&session=' . $session_id );

		return rest_ensure_response( array(
			'status'      => 'ok',
			'session_id'  => $session_id,
			'token'       => $token,
			'mobile_url'  => $mobile_url,
			'qr_url'      => 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . urlencode( $mobile_url ),
			'players'     => count( $test_players ),
			'markers'     => count( $test_markers ),
		) );
	}
}

// Initialize
new RMM_Mission_Map_Handler();
