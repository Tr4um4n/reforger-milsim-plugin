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
		add_shortcode( 'rmm_event_map', array( $this, 'render_event_map' ) );

		// REST API para recibir datos del addon
		add_action( 'rest_api_init', array( $this, 'register_rest_endpoints' ) );

		// Página MicroDAGR mobile
		add_action( 'init', array( $this, 'register_microdagr_page' ) );
		add_filter( 'template_include', array( $this, 'load_microdagr_template' ) );

		// Auto-detectar mapa al publicar misión
		add_action( 'save_post_misiones', array( $this, 'auto_create_mission_map' ), 10, 3 );

		// Metabox de selección de mapa para eventos
		add_action( 'add_meta_boxes', array( $this, 'add_event_map_metabox' ) );
		add_action( 'save_post_eventos_partidas', array( $this, 'save_event_map_metabox' ), 10, 3 );
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
			// Primero buscar por post_id
			$session = $wpdb->get_row( $wpdb->prepare(
				"SELECT session_id FROM $table_sessions WHERE post_id = %d AND status = 'active' ORDER BY started_at DESC LIMIT 1",
				$post_id
			) );
			// Si no hay, buscar cualquier sesión activa (addon crea con post_id=0)
			if ( ! $session ) {
				$session = $wpdb->get_row(
					"SELECT session_id FROM $table_sessions WHERE status = 'active' ORDER BY started_at DESC LIMIT 1"
				);
			}
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
						"SELECT token FROM $table_tokens WHERE user_id = %d AND session_id = %s",
						$current_user_id, $session_id
					) );
				if ( $existing ) {
					$microdagr_token = $existing->token;
				} else {
					$microdagr_token = wp_generate_password( 10, false );
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
		<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css">
		<script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js"></script>
		<style>
		/* ══ ACE3 MicroDAGR Military Display ══ */
		.rmm-dagr-device{background:#191919;border:3px solid #2d2d2d;border-radius:6px;padding:8px;box-shadow:inset 0 0 40px rgba(0,0,0,.7),0 4px 24px rgba(0,0,0,.8);font-family:'Courier New',monospace;max-width:100%}
		.rmm-dagr-screen{position:relative;overflow:hidden;border:2px solid #0a0a0a;border-radius:2px;background:#0a0a0a}
		.rmm-dagr-screen .leaflet-container{filter:sepia(.25) brightness(.7) contrast(1.1) saturate(.9)!important;background:#111!important}
		.rmm-dagr-screen .leaflet-control-zoom{border:1px solid #333!important;box-shadow:none!important}
		.rmm-dagr-screen .leaflet-control-zoom a{background:#1a1a1a!important;color:#FFB000!important;border-color:#333!important;width:28px!important;height:28px!important;line-height:28px!important;font-size:14px!important}
		.rmm-dagr-screen .leaflet-control-zoom a:hover{color:#fff!important}
		.rmm-dagr-grid{position:absolute;top:0;left:0;width:100%;height:100%;pointer-events:none;z-index:998}
		.rmm-dagr-hud{background:rgba(0,0,0,.9);border-top:1px solid #2a2a2a;color:#FFB000;font-size:.68rem;padding:5px 12px;display:flex;justify-content:space-between;align-items:center;letter-spacing:.04em;border-radius:0 0 4px 4px;gap:6px;flex-wrap:wrap;user-select:none}
		.rmm-dagr-hud b{color:#FFB000;font-weight:bold;min-width:50px;display:inline-block;text-align:right}
		.rmm-dagr-compass{width:42px;height:42px;border-radius:50%;border:2px solid #FFB000;position:relative;display:flex;align-items:center;justify-content:center;flex-shrink:0;background:rgba(0,0,0,.4)}
		.rmm-dagr-compass .needle{width:0;height:0;border-left:4px solid transparent;border-right:4px solid transparent;border-bottom:14px solid #ef4444;transition:transform .2s}
		.rmm-dagr-compass .north{position:absolute;top:1px;font-size:8px;color:#FFB000;font-weight:bold;line-height:1;pointer-events:none}
		.rmm-dagr-compass .dir{position:absolute;bottom:-2px;font-size:7px;color:#888;line-height:1;pointer-events:none}
		.rmm-dagr-player-icon{transition:all .3s}
		.rmm-dagr-player-icon .dot{width:10px;height:10px;border-radius:50%;border:1.5px solid #fff;box-shadow:0 0 6px currentColor;background:currentColor}
		.rmm-dagr-player-icon.me .dot{width:14px;height:14px;border-width:2px;box-shadow:0 0 10px currentColor;position:relative}
		.rmm-dagr-player-icon.me .dot::after{content:'';position:absolute;top:-10px;left:50%;margin-left:-1px;width:2px;height:10px;background:#FFB000;border-radius:1px;transform-origin:bottom center}
		</style>
		<div class="rmm-dagr-device">
			<div class="rmm-dagr-screen" style="height:<?php echo esc_attr( $height ); ?>;">
				<div id="<?php echo $uid; ?>" style="width:100%;height:100%;"></div>
				<canvas class="rmm-dagr-grid" id="dagr-grid-<?php echo $uid; ?>"></canvas>
			</div>
			<div class="rmm-dagr-hud">
				<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
					<span>GRID <b id="dagr-hud-gx-<?php echo $uid; ?>">----</b> <b id="dagr-hud-gy-<?php echo $uid; ?>">----</b></span>
					<span>ALT <b id="dagr-hud-alt-<?php echo $uid; ?>">---</b>m</span>
					<span>SPD <b id="dagr-hud-spd-<?php echo $uid; ?>">--</b> km/h</span>
				</div>
				<div style="display:flex;align-items:center;gap:8px">
					<?php if ( $show_microdagr ) : ?>
					<button onclick="openMicroDAGR('<?php echo esc_js( $microdagr_token ); ?>','<?php echo esc_js( $session_id ); ?>')" style="padding:4px 10px;font-size:.6rem;text-transform:uppercase;letter-spacing:.06em;font-family:'Courier New',monospace;background:#1a1a1a;border:1px solid #555;border-bottom:3px solid #1a1a1a;color:#FFB000;border-radius:3px;cursor:pointer">DAGR</button>
					<?php endif; ?>
				</div>
				<div class="rmm-dagr-compass">
					<div class="north">N</div>
					<div class="needle" id="dagr-needle-<?php echo $uid; ?>" style="transform:rotate(0deg)"></div>
					<div class="dir" id="dagr-hdg-<?php echo $uid; ?>">---</div>
				</div>
			</div>
		</div>

		<!-- Modal QR MicroDAGR -->
		<div id="microdagr-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.85);z-index:99999;justify-content:center;align-items:center;">
			<div style="background:#191919;border:3px solid #2d2d2d;padding:30px;border-radius:8px;text-align:center;max-width:400px;box-shadow:0 8px 32px rgba(0,0,0,.8);font-family:'Courier New',monospace;">
				<h3 style="color:#C8D840;margin-top:0;font-size:18px;letter-spacing:.05em;text-transform:uppercase;">📡 MicroDAGR</h3>
				<p style="color:#888;font-size:12px;">Escanea con tu móvil para GPS táctico</p>
				<div id="microdagr-qr" style="background:#fff;padding:10px;border-radius:4px;display:inline-block;margin:15px 0;"></div>
				<br>
				<button onclick="document.getElementById('microdagr-modal').style.display='none'" style="background:#252525;border:1px solid #3a3a3a;border-bottom:3px solid #1a1a1a;color:#C8D840;padding:8px 20px;font-size:12px;text-transform:uppercase;letter-spacing:.06em;cursor:pointer;border-radius:4px;font-family:'Courier New',monospace;">CERRAR</button>
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
			var gameMaxY = <?php echo $max_y; ?>;
			var gameMaxX = <?php echo $max_x; ?>;
			var bounds = [[<?php echo $min_y * $scale_factor; ?>, <?php echo $min_x * $scale_factor; ?>], [<?php echo $max_y * $scale_factor; ?>, <?php echo $max_x * $scale_factor; ?>]];
			var maxZoom = <?php echo $max_zoom; ?>;
			var mySteamId = '<?php echo $current_user_id ? esc_js( get_user_meta( $current_user_id, 'steamid_64', true ) ) : ''; ?>';

			var map = L.map('<?php echo $uid; ?>', {
				zoomControl: true,
				attributionControl: false,
				maxBounds: bounds,
				center: [<?php echo ($max_y * $scale_factor) / 2; ?>, <?php echo ($max_x * $scale_factor) / 2; ?>],
				zoom: Math.floor(maxZoom / 2)
			});
			document.getElementById('<?php echo $uid; ?>')._rmmMap = map;

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
				return [((gameMaxY - y + edgeOffset) * scaleFactor), ((x - edgeOffset) * scaleFactor)];
			}
			function latLngToGame(lat, lng) {
				return {
					x: (lng / scaleFactor) + edgeOffset,
					y: gameMaxY - (lat / scaleFactor) + edgeOffset
				};
			}

			// ── HUD references ──
			var hudGX = document.getElementById('dagr-hud-gx-<?php echo $uid; ?>');
			var hudGY = document.getElementById('dagr-hud-gy-<?php echo $uid; ?>');
			var hudAlt = document.getElementById('dagr-hud-alt-<?php echo $uid; ?>');
			var hudSpd = document.getElementById('dagr-hud-spd-<?php echo $uid; ?>');
			var hudNeedle = document.getElementById('dagr-needle-<?php echo $uid; ?>');
			var hudHdg = document.getElementById('dagr-hdg-<?php echo $uid; ?>');

			// ── Grid overlay ──
			var gridCanvas = document.getElementById('dagr-grid-<?php echo $uid; ?>');
			var gridCtx = gridCanvas.getContext('2d');
			function drawGrid() {
				var s = map.getSize();
				gridCanvas.width = s.x; gridCanvas.height = s.y;
				gridCanvas.style.width = s.x + 'px'; gridCanvas.style.height = s.y + 'px';
				gridCtx.clearRect(0, 0, s.x, s.y);
				var z = map.getZoom();
				var sp = z < 3 ? 2000 : z < 5 ? 1000 : z < 7 ? 500 : z < 9 ? 250 : 100;
				var b = map.getBounds();
				var sw = latLngToGame(b.getSouth(), b.getWest());
				var ne = latLngToGame(b.getNorth(), b.getEast());
				var gxMin = Math.max(0, Math.floor(sw.x / sp) * sp);
				var gxMax = Math.min(gameMaxX, Math.ceil(ne.x / sp) * sp);
				var gyMin = Math.max(0, Math.floor(sw.y / sp) * sp);
				var gyMax = Math.min(gameMaxY, Math.ceil(ne.y / sp) * sp);
				gridCtx.strokeStyle = 'rgba(255,176,0,0.09)';
				gridCtx.lineWidth = 0.5;
				gridCtx.fillStyle = 'rgba(255,176,0,0.25)';
				gridCtx.font = '8px "Courier New", monospace';
				for (var gx = gxMin; gx <= gxMax; gx += sp) {
					var ll = gameToLatLng(gx, 0);
					var px = map.latLngToContainerPoint(L.latLng(ll[0], ll[1])).x;
					gridCtx.beginPath(); gridCtx.moveTo(px, 0); gridCtx.lineTo(px, s.y); gridCtx.stroke();
					if (sp >= 1000 && gx % 1000 === 0) gridCtx.fillText(Math.round(gx / 1000), px + 2, s.y - 4);
					else if (sp < 1000 && gx % (sp * 5) === 0) gridCtx.fillText(gx, px + 2, s.y - 4);
				}
				for (var gy = gyMin; gy <= gyMax; gy += sp) {
					var ll = gameToLatLng(0, gy);
					var py = map.latLngToContainerPoint(L.latLng(ll[0], ll[1])).y;
					gridCtx.beginPath(); gridCtx.moveTo(0, py); gridCtx.lineTo(s.x, py); gridCtx.stroke();
					if (sp >= 1000 && gy % 1000 === 0) gridCtx.fillText(Math.round(gy / 1000), 4, py - 3);
					else if (sp < 1000 && gy % (sp * 5) === 0) gridCtx.fillText(gy, 4, py - 3);
				}
			}
			map.on('moveend zoomend', drawGrid);
			setTimeout(drawGrid, 600);

			// ── HUD update ──
			function updateHUD(p) {
				if (!p) return;
				var x = Math.round(p.pos_x), y = Math.round(p.pos_y);
				hudGX.textContent = String(x).padStart(5, '0');
				hudGY.textContent = String(y).padStart(5, '0');
				hudAlt.textContent = Math.round(p.pos_z || 0);
				hudSpd.textContent = Math.round(p.speed || 0);
				var hdg = Math.round(p.heading || 0);
				hudNeedle.style.transform = 'rotate(' + hdg + 'deg)';
				hudHdg.textContent = String(hdg).padStart(3, '0');
			}

			// ── Military-style marker factory ──
			function makePlayerIcon(color, isMe, heading) {
				var cls = 'dagr-player-marker rmm-dagr-player-icon' + (isMe ? ' me' : '');
				var dotSize = isMe ? '14px' : '10px';
				var html = '<div class="dot" style="width:' + dotSize + ';height:' + dotSize + ';background:' + color + ';border:2px solid #fff;border-radius:50%;box-shadow:0 0 10px ' + color + ';"></div>';
				if (isMe && heading !== undefined) {
					html += '<div style="position:absolute;top:-10px;left:50%;margin-left:-1px;width:2px;height:8px;background:#FFB000;border-radius:1px;transform:rotate(' + heading + 'deg);transform-origin:bottom center;"></div>';
				}
				return L.divIcon({ className: cls, html: html, iconSize: isMe ? [20, 24] : [16, 16], iconAnchor: isMe ? [10, 12] : [8, 8] });
			}

			var playerMarkers = {};

			// ── Initial positions ──
			var positions = <?php echo $positions_json; ?>;
			if ( positions && positions.length ) {
				positions.forEach(function(p) {
					var latlng = gameToLatLng(p.pos_x, p.pos_y);
					var isMe = (p.steamid === mySteamId);
					var color = p.is_alive ? (isMe ? '#FFB000' : '#FFB000') : '#ef4444';
					var icon = makePlayerIcon(color, isMe, p.heading);
					var marker = L.marker(latlng, { icon: icon, zIndexOffset: isMe ? 9999 : 0 }).addTo(map);
					var gridLabel = String(Math.round(p.pos_x)).padStart(4,'0') + ' ' + String(Math.round(p.pos_y)).padStart(4,'0');
					marker.bindTooltip((p.player_name || '') + ' [' + gridLabel + ']', { direction: 'top', offset: [0, isMe ? -16 : -10], className: 'rmm-dagr-tooltip' });
					playerMarkers[p.steamid] = marker;
					if (isMe) updateHUD(p);
				});
			}

			// ── Mission markers ──
			var markers = <?php echo $markers_json; ?>;
			var markerIcons = {
				'objective': '<div style="width:14px;height:14px;background:#FFB000;border:2px solid #fff;border-radius:2px;transform:rotate(45deg);box-shadow:0 0 8px rgba(255,176,0,.5);"></div>',
				'enemy': '<div style="width:0;height:0;border-left:7px solid transparent;border-right:7px solid transparent;border-bottom:14px solid #ef4444;filter:drop-shadow(0 0 4px rgba(239,68,68,.5));"></div>',
				'friendly': '<div style="width:12px;height:12px;background:#60a5fa;border:2px solid #fff;border-radius:2px;box-shadow:0 0 8px rgba(96,165,250,.5);"></div>',
				'completed': '<div style="width:12px;height:12px;background:#FFB000;border:2px solid #fff;border-radius:50%;box-shadow:0 0 8px rgba(255,176,0,.4);"></div>',
				'marker': '<div style="width:12px;height:12px;background:#a78bfa;border:2px solid #fff;border-radius:50%;box-shadow:0 0 8px rgba(167,139,250,.5);"></div>'
			};
			if ( markers && markers.length ) {
				markers.forEach(function(m) {
					var latlng = gameToLatLng(m.pos_x, m.pos_y);
					var html = markerIcons[m.type] || markerIcons['marker'];
					var icon = L.divIcon({ className: 'dagr-map-marker', html: html, iconSize: [20, 20], iconAnchor: [10, 10] });
					L.marker(latlng, { icon: icon }).addTo(map).bindTooltip(m.label || m.type, { direction: 'top', offset: [0, -12], className: 'rmm-dagr-tooltip' });
				});
			}

			// ── Polling ──
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
								var isMe = (p.steamid === mySteamId);
								var color = p.is_alive ? (isMe ? '#FFB000' : '#FFB000') : '#ef4444';
								var icon = makePlayerIcon(color, isMe, p.heading);
								if (playerMarkers[p.steamid]) {
									playerMarkers[p.steamid].setLatLng(latlng);
									playerMarkers[p.steamid].setIcon(icon);
								} else {
									var marker = L.marker(latlng, { icon: icon, zIndexOffset: isMe ? 9999 : 0 }).addTo(map);
									var gridLabel = String(Math.round(p.pos_x)).padStart(4,'0') + ' ' + String(Math.round(p.pos_y)).padStart(4,'0');
									marker.bindTooltip((p.player_name || '') + ' [' + gridLabel + ']', { direction: 'top', offset: [0, isMe ? -16 : -10], className: 'rmm-dagr-tooltip' });
									playerMarkers[p.steamid] = marker;
								}
								if (isMe) updateHUD(p);
							});
							for (var id in playerMarkers) {
								if (!seen[id]) {
									map.removeLayer(playerMarkers[id]);
									delete playerMarkers[id];
								}
							}
						});
				}, 5000);
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
			// Fallback: cualquier sesión activa (addon crea con post_id=0)
			if ( ! $session ) {
				$session = $wpdb->get_row(
					"SELECT session_id FROM $table_sessions WHERE status = 'active' ORDER BY started_at DESC LIMIT 1"
				);
			}
			if ( $session ) $session_id = $session->session_id;
		}

		if ( empty( $session_id ) ) return '<p>No hay sesión activa para esta misión.</p>';

		$table_tokens = $wpdb->prefix . 'rmm_microdagr_tokens';
		$existing = $wpdb->get_row( $wpdb->prepare(
			"SELECT token FROM $table_tokens WHERE user_id = %d AND session_id = %s",
			$current_user_id, $session_id
		) );

		if ( $existing ) {
			$token = $existing->token;
		} else {
			$token = wp_generate_password( 10, false );
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
		<button onclick="openMicroDAGRBtn('<?php echo esc_js( $token ); ?>','<?php echo esc_js( $session_id ); ?>')" style="background:#191919;border:3px solid #2d2d2d;border-bottom:4px solid #1a1a1a;color:#C8D840;font-size:14px;padding:12px 24px;font-family:'Courier New',monospace;text-transform:uppercase;letter-spacing:.08em;cursor:pointer;border-radius:4px;box-shadow:0 2px 8px rgba(0,0,0,.5);transition:all .1s" onmouseover="this.style.borderColor='#C8D840'" onmouseout="this.style.borderColor='#2d2d2d'">📡 MicroDAGR</button>

		<div id="microdagr-btn-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.85);z-index:99999;justify-content:center;align-items:center;">
			<div style="background:#191919;border:3px solid #2d2d2d;padding:30px;border-radius:8px;text-align:center;max-width:400px;box-shadow:0 8px 32px rgba(0,0,0,.8);font-family:'Courier New',monospace;">
				<h3 style="color:#C8D840;margin-top:0;font-size:18px;letter-spacing:.05em;text-transform:uppercase;">📡 MicroDAGR</h3>
				<p style="color:#888;font-size:12px;">Escanea con tu móvil para GPS táctico</p>
				<div id="microdagr-btn-qr" style="background:#fff;padding:10px;border-radius:4px;display:inline-block;margin:15px 0;"></div>
				<br>
				<button onclick="document.getElementById('microdagr-btn-modal').style.display='none'" style="background:#252525;border:1px solid #3a3a3a;border-bottom:3px solid #1a1a1a;color:#C8D840;padding:8px 20px;font-size:12px;text-transform:uppercase;letter-spacing:.06em;cursor:pointer;border-radius:4px;font-family:'Courier New',monospace;">CERRAR</button>
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
				"SELECT t.* FROM $table_tokens t
				INNER JOIN {$wpdb->prefix}rmm_mission_sessions s ON t.session_id = s.session_id
				WHERE t.token = %s AND s.status = 'active'",
				$token
			) );

			// Auto-crear token para sesiones test_* o cuando se pasa steamid por URL
			if ( strpos( $session, 'test_' ) === 0 || ! empty( $_GET['steamid'] ) ) {
				$table_sessions_check = $wpdb->prefix . 'rmm_mission_sessions';
				$test_session = $wpdb->get_row( $wpdb->prepare(
					"SELECT * FROM $table_sessions_check WHERE session_id = %s", $session
				) );

				// Si la sesión no existe y es test, crear datos simulados
				if ( ! $test_session && strpos( $session, 'test_' ) === 0 ) {
					$sim_request = new WP_REST_Request( 'POST', '/clan/v1/mission/simulate' );
					$sim_request->set_body_params( array( 'session_id' => $session ) );
					$this->simulate_telemetry_tick( $sim_request );
				}

				// Leer steamid del payload (primer jugador) o de la URL
				$payload_raw  = get_option( 'rmm_simulate_payload', '' );
				$payload_data = json_decode( $payload_raw, true );
				$auto_steamid = '76561198000000001';
				if ( $payload_data && ! empty( $payload_data['players'][0]['steamid'] ) ) {
					$auto_steamid = $payload_data['players'][0]['steamid'];
				}
				if ( ! empty( $_GET['steamid'] ) ) {
					$auto_steamid = sanitize_text_field( $_GET['steamid'] );
				}

				if ( ! $valid ) {
					// Crear token nuevo
					$wpdb->insert( $table_tokens, array(
						'token'      => $token,
						'user_id'    => 0,
						'steamid'    => $auto_steamid,
						'session_id' => $session,
						'expires_at' => date( 'Y-m-d H:i:s', strtotime( '+24 hours' ) ),
					) );
				} elseif ( $valid->steamid !== $auto_steamid ) {
					// Token existe pero steamid no coincide → actualizar
					$wpdb->update( $table_tokens,
						array( 'steamid' => $auto_steamid ),
						array( 'token' => $token )
					);
				}
				$valid = $wpdb->get_row( $wpdb->prepare(
					"SELECT t.* FROM $table_tokens t
					INNER JOIN {$wpdb->prefix}rmm_mission_sessions s ON t.session_id = s.session_id
					WHERE t.token = %s AND s.status = 'active'",
					$token
				) );
			}

			if ( ! $valid ) {
				wp_die( 'Token inválido o expirado. Para testing, usa <code>test_circle</code> como session y <code>test_microdagr</code> como token.' );
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
			// Convertir a URL absoluta correctamente
			if ( strpos( $tiles_url, 'http' ) !== 0 ) {
				// Quitar ../ inicial y construir desde site_url
				$clean = preg_replace( '#^(\.\./)+#', '', $tiles_url );
				$tiles_url = site_url( '/' . $clean );
			}

			// Si el mapa no existe en BD, mostrar error claro
			if ( ! $map_config ) {
				header( 'Content-Type: text/html; charset=utf-8' );
				echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>MicroDAGR</title>';
				echo '<style>body{background:#1a1a1a;color:#FFB000;font-family:monospace;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;text-align:center}';
				echo 'a{color:#FFB000}</style></head><body><div><h2>⚠️ Mapa no configurado</h2>';
				echo '<p>El mapa <b>' . esc_html( $map_name ) . '</b> no existe en <b>Mapas DAGR → Mapas</b>.</p>';
				echo '<p>Ve a <a href="' . admin_url( 'admin.php?page=rmm-dagr-maps&tab=maps' ) . '">admin → Mapas DAGR → Mapas</a> y créalo, o activa la simulación en la pestaña Simulación.</p>';
				echo '<p style="color:#888;font-size:12px;margin-top:20px">Session: ' . esc_html( $session ) . ' | Token: ' . esc_html( $token ) . ' | Mapa: ' . esc_html( $map_name ) . '</p>';
				echo '</div></body></html>';
				exit;
			}

			// Usar el shortcode de preset que FUNCIONA
			$dagr_handler = new RMM_DAGR_Handler();
			$preset_id = $session_data ? intval( $session_data->post_id ) : 0;
			$map_html = $dagr_handler->render_tactical_map( array(
				'map'    => $map_name,
				'height' => '100vh',
				'id'     => $preset_id,
			) );

			header( 'Content-Type: text/html; charset=utf-8' );
			?>
<!DOCTYPE html>
<html>
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no,viewport-fit=cover">
	<meta name="apple-mobile-web-app-capable" content="yes">
	<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
	<title>MicroDAGR</title>
	<style>
		/* ══ ACE3 MicroDAGR Device ══ */
		:root{--safe-top:env(safe-area-inset-top,0px);--safe-bottom:env(safe-area-inset-bottom,0px)}
		*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
		html,body{width:100%;height:100%;overflow:hidden;background:#0a0a0a;font-family:'Courier New',monospace;touch-action:manipulation;-webkit-tap-highlight-color:transparent}

		/* Device shell */
		#dagr-shell{position:fixed;top:0;left:0;right:0;bottom:0;background:#1a1a1a;display:flex;flex-direction:column;padding:var(--safe-top) 5px var(--safe-bottom) 5px}
		#dagr-bezel-top{height:26px;display:flex;align-items:center;justify-content:space-between;padding:0 10px;color:#555;font-size:10px;letter-spacing:.12em;text-transform:uppercase;font-weight:bold}
		#dagr-bezel-top span{color:#666}

		/* Screen - amber military tint (NOT green) */
		#dagr-screen-wrap{flex:1;position:relative;overflow:hidden;border:2px solid #0a0a0a;border-radius:3px;background:#0d0d0d;margin-top:30px}
		#dagr-screen-wrap .leaflet-container{filter:sepia(.25) brightness(.7) contrast(1.1) saturate(.9)!important;background:#111!important}
		#dagr-screen-wrap .leaflet-div-icon{background:transparent!important;border:none!important}
		#dagr-screen-wrap .leaflet-tooltip{background:rgba(0,0,0,.85)!important;border:1px solid #FFB000!important;color:#FFB000!important;font-family:monospace!important;font-size:10px!important;padding:3px 6px!important;border-radius:3px!important;box-shadow:none!important}
		#dagr-screen-wrap .leaflet-tooltip::before{border-top-color:#FFB000!important}
		#dagr-screen-wrap .leaflet-control-zoom{border:1px solid #333!important;box-shadow:none!important;margin:8px!important;margin-top:38px!important}
		#dagr-screen-wrap .leaflet-control-zoom a{background:#1c1c1c!important;color:#FFB000!important;border-color:#333!important;width:36px!important;height:36px!important;line-height:36px!important;font-size:20px!important;border-radius:3px!important}
		#dagr-screen-wrap .leaflet-control-zoom a:hover{color:#fff!important;background:#2a2a2a!important}
		#dagr-frame{width:100%;height:100%}

		/* Grid canvas */
		#dagr-grid-canvas{position:absolute;top:0;left:0;width:100%;height:100%;pointer-events:none;z-index:998}

		/* ── UI Overlay ── */
		#dagr-ui{position:absolute;top:0;left:0;right:0;bottom:0;z-index:9999;pointer-events:none;font-size:12px}
		#dagr-ui>*{pointer-events:auto}
		#dagr-ui button{-webkit-tap-highlight-color:transparent;touch-action:manipulation;outline:none}

		/* ── HUD (Bottom bar) ── */
		#dagr-hud{position:absolute;bottom:0;left:0;right:0;height:56px;padding:0 8px 6px 8px;background:rgba(0,0,0,.93);color:#FFB000;display:flex;justify-content:space-between;align-items:center;border-top:1px solid #333;font-size:11px;letter-spacing:.04em}
		#dagr-hud .hud-block{display:flex;flex-direction:column;align-items:center;min-width:48px}
		#dagr-hud .hud-label{font-size:8px;color:#777;text-transform:uppercase}
		#dagr-hud .hud-value{font-size:14px;font-weight:bold;color:#FFB000}

		/* Compass */
		#dagr-compass{width:46px;height:46px;border-radius:50%;border:2px solid #FFB000;position:relative;display:flex;align-items:center;justify-content:center;flex-shrink:0;background:rgba(0,0,0,.5)}
		#dagr-compass .needle{width:0;height:0;border-left:5px solid transparent;border-right:5px solid transparent;border-bottom:16px solid #ef4444;transition:transform .15s}
		#dagr-compass .north{position:absolute;top:2px;font-size:9px;color:#FFB000;font-weight:bold;line-height:1;pointer-events:none}
		#dagr-compass .hdg{position:absolute;bottom:-3px;font-size:8px;color:#999;line-height:1;pointer-events:none}

		/* ── Top bar ── */
		#dagr-topbar{position:absolute;top:0;left:0;right:0;height:32px;padding:var(--safe-top) 6px 0 6px;background:rgba(0,0,0,.88);display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid #333;font-size:10px;color:#999}

		/* ── Waypoint panel (top-right) ── */
		#dagr-wp-panel{position:absolute;top:calc(38px + var(--safe-top));right:6px;background:rgba(0,0,0,.93);border:1px solid #333;border-radius:4px;color:#FFB000;padding:8px 10px;max-height:50vh;overflow-y:auto;font-size:10px;min-width:130px;max-width:170px;display:none;z-index:10000}
		#dagr-wp-panel.open{display:block}
		#dagr-wp-panel h4{font-size:11px;color:#FFB000;margin:0 0 5px;border-bottom:1px solid #2a2a2a;padding-bottom:4px;text-transform:uppercase;letter-spacing:.05em}
		.dagr-wp-item{display:flex;flex-direction:column;padding:5px 0;border-bottom:1px solid #1a1a1a;gap:2px}
		.dagr-wp-item .wp-name{font-weight:bold;color:#FFB000;font-size:11px;display:flex;justify-content:space-between}
		.dagr-wp-item .wp-info{font-size:9px;color:#999}
		.dagr-wp-item .wp-del{color:#ef4444;cursor:pointer;font-size:14px;padding:0 4px}

		/* ── Layers panel (to the right of buttons) ── */
		#dagr-layer-panel{position:absolute;top:170px;left:72px;background:rgba(0,0,0,.93);border:1px solid #333;border-radius:4px;color:#FFB000;padding:10px 12px;font-size:12px;display:none;min-width:130px;z-index:10000}
		#dagr-layer-panel.open{display:block}
		#dagr-layer-panel h4{font-size:11px;color:#FFB000;margin:0 0 5px;border-bottom:1px solid #2a2a2a;padding-bottom:4px;text-transform:uppercase;letter-spacing:.05em}
		#dagr-layer-panel label{display:flex;align-items:center;padding:5px 0;gap:6px;cursor:pointer;font-size:11px}
		#dagr-layer-panel input{accent-color:#FFB000;width:16px;height:16px}

		/* ── Buttons (LARGE for mobile) ── */
		.dagr-phys-btn{background:#222;border:1px solid #444;border-bottom:4px solid #1a1a1a;color:#FFB000;font-size:12px;text-transform:uppercase;letter-spacing:.08em;cursor:pointer;border-radius:4px;font-family:'Courier New',monospace;padding:10px 14px;transition:all .1s;white-space:nowrap;text-align:center;min-height:42px;min-width:48px;font-weight:bold}
		.dagr-phys-btn:active{border-bottom-width:1px;transform:translateY(3px);background:#2a2a2a}
		.dagr-phys-btn.accent{background:#1a1a1a;border-color:#555;border-bottom-color:#1a1a1a;color:#FFB000}
		.dagr-phys-btn.accent:active{background:#2a2a2a}
		.dagr-phys-btn.on{background:#332200;border-color:#FFB000;color:#FFB000}
		.dagr-icon-btn{width:44px;height:44px;border-radius:50%;border:2px solid #444;background:rgba(0,0,0,.75);color:#FFB000;font-size:20px;display:flex;align-items:center;justify-content:center;cursor:pointer;font-weight:bold}
		.dagr-icon-btn.active{background:rgba(255,176,0,.2);border-color:#FFB000;color:#FFB000;box-shadow:0 0 12px rgba(255,176,0,.3)}
		/* Player arrow marker */
		.dagr-me-marker{background:transparent!important;border:none!important}
		.dagr-me-arrow{transform-origin:9px 15px;transition:transform 0.3s ease}
	</style>
</head>
<body>
<div id="dagr-shell">
	<div id="dagr-bezel-top"><span>MICRODAGR</span><span>GPS</span></div>
	<div id="dagr-screen-wrap">
		<div id="dagr-frame"><?php echo preg_replace('/<div/','<div style="width:100%;height:100%"',$map_html,1); ?></div>
		<canvas id="dagr-grid-canvas"></canvas>
		<!-- UI -->
		<div id="dagr-ui">
			<!-- Top bar -->
			<div id="dagr-topbar">
				<span id="dagr-clock">----</span>
				<button class="dagr-phys-btn" id="btn-wp-menu">WP</button>
			</div>

			<!-- Left button column (pushed down, 4 buttons) -->
			<div style="position:absolute;top:170px;left:8px;display:flex;flex-direction:column;gap:8px">
				<button class="dagr-phys-btn" id="btn-mark" style="min-width:56px;min-height:44px;font-size:13px">MARK</button>
				<button class="dagr-phys-btn" id="btn-mrk" style="min-width:56px;min-height:44px;font-size:13px">MRK</button>
				<button class="dagr-phys-btn" id="btn-draw" style="min-width:56px;min-height:44px;font-size:13px">DRAW</button>
				<button class="dagr-phys-btn" id="btn-map" style="min-width:56px;min-height:44px;font-size:13px">MAP</button>
				<button class="dagr-phys-btn" id="btn-compass" style="min-width:56px;min-height:44px;font-size:13px">CMP</button>
				<button class="dagr-phys-btn" id="btn-layers" style="min-width:56px;min-height:44px;font-size:13px">LAY</button>
			</div>

			<!-- Layers panel (to the RIGHT of buttons, NOT on top) -->
			<div id="dagr-layer-panel">
				<h4>CAPAS</h4>
				<label><input type="checkbox" checked data-layer="players" onchange="dagrToggleLayer(this)">JUGADORES</label>
				<label><input type="checkbox" checked data-layer="markers" onchange="dagrToggleLayer(this)">MARCADORES</label>
				<label><input type="checkbox" checked data-layer="waypoints" onchange="dagrToggleLayer(this)">WAYPOINTS</label>
				<label><input type="checkbox" checked data-layer="lines" onchange="dagrToggleLayer(this)">LINEAS</label>
			</div>

			<!-- Waypoint panel (top-right, toggled by WP button) -->
			<div id="dagr-wp-panel">
				<h4>WAYPOINTS</h4>
				<div id="dagr-wp-items"><em style="color:#555">VACÍO</em></div>
			</div>

			<!-- Marker type selector (NATO icons) -->
			<div id="dagr-mrk-panel" style="display:none;position:absolute;top:38px;left:50%;transform:translateX(-50%);background:rgba(0,0,0,.95);border:1px solid #FFB000;border-radius:4px;padding:8px;z-index:10001;max-width:280px">
				<div style="color:#FFB000;font-size:10px;text-align:center;margin-bottom:6px;text-transform:uppercase;letter-spacing:.05em">TIPO DE MARCADOR</div>
				<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:4px" id="dagr-mrk-types"></div>
			</div>

			<!-- Floating action buttons -->
			<button class="dagr-icon-btn" id="btn-center" style="position:absolute;bottom:80px;right:12px" title="CENTRAR">◎</button>
			<button class="dagr-icon-btn" id="btn-add-wp" style="position:absolute;bottom:136px;right:12px" title="AÑADIR WP">+</button>

			<!-- WP creation form (grid coordinates) -->
			<div id="dagr-wp-form" style="display:none;position:absolute;bottom:76px;left:50%;transform:translateX(-50%);background:rgba(0,0,0,.95);border:2px solid #FFB000;border-radius:6px;padding:12px;z-index:99999;min-width:240px;text-align:center;box-shadow:0 0 20px rgba(255,176,0,.2)">
				<div style="color:#FFB000;font-size:10px;text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px">NUEVO WAYPOINT</div>
				<div style="display:flex;gap:4px;align-items:center;margin:3px 0">
					<span style="color:#888;font-size:10px;min-width:14px">E</span>
					<input id="wp-x" type="text" inputmode="numeric" placeholder="Grid X (1-5 dígitos)" maxlength="5" style="flex:1;padding:8px;background:#111;border:1px solid #333;color:#FFB000;font-family:monospace;font-size:14px;text-align:center;border-radius:3px;outline:none">
				</div>
				<div style="display:flex;gap:4px;align-items:center;margin:3px 0">
					<span style="color:#888;font-size:10px;min-width:14px">N</span>
					<input id="wp-y" type="text" inputmode="numeric" placeholder="Grid Y (1-5 dígitos)" maxlength="5" style="flex:1;padding:8px;background:#111;border:1px solid #333;color:#FFB000;font-family:monospace;font-size:14px;text-align:center;border-radius:3px;outline:none">
				</div>
				<div id="wp-meters-preview" style="color:#888;font-size:9px;margin:2px 0;min-height:14px"></div>
				<input id="wp-name" type="text" placeholder="Nombre" maxlength="30" style="width:100%;margin:3px 0;padding:8px;background:#111;border:1px solid #333;color:#FFB000;font-family:monospace;font-size:14px;text-align:center;border-radius:3px;outline:none">
				<div style="display:flex;gap:4px;margin-top:8px">
					<button id="wp-btn-tap" class="dagr-phys-btn" style="flex:1;font-size:10px">TOCAR MAPA</button>
					<button id="wp-btn-add" class="dagr-phys-btn" style="flex:1;font-size:10px">AÑADIR</button>
				</div>
				<button id="wp-btn-cancel" class="dagr-phys-btn" style="width:100%;margin-top:4px;font-size:10px">CANCELAR</button>
			</div>

			<!-- Compass overlay (shown when CMP active) -->
			<div id="dagr-compass-overlay" style="display:none;position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);pointer-events:none;text-align:center;z-index:9998">
				<div style="width:140px;height:140px;border-radius:50%;border:3px solid #FFB000;position:relative;margin:0 auto;background:rgba(0,0,0,.7);box-shadow:0 0 30px rgba(255,176,0,.15);overflow:hidden">
					<div class="north" style="font-size:14px!important">N</div>
					<div class="needle" id="dagr-cmp-needle" style="width:0;height:0;border-left:7px solid transparent;border-right:7px solid transparent;border-bottom:50px solid #ef4444;transition:transform .15s;position:absolute;bottom:50%;left:50%;margin-left:-7px;transform-origin:bottom center"></div>
				</div>
				<div style="color:#FFB000;font-size:10px;letter-spacing:.05em;margin-top:2px">HEADING</div>
				<div style="font-size:32px;font-weight:bold;color:#FFB000;line-height:1" id="dagr-cmp-hdg">---°</div>
			</div>

			<!-- HUD -->
			<div id="dagr-hud">
				<div class="hud-block"><span class="hud-label">E</span><span class="hud-value" id="hud-grid-e">-----</span></div>
				<div class="hud-block"><span class="hud-label">N</span><span class="hud-value" id="hud-grid-n">-----</span></div>
				<div class="hud-block"><span class="hud-label">ALT</span><span class="hud-value" id="hud-alt">---</span></div>
				<div class="hud-block"><span class="hud-label">SPD</span><span class="hud-value" id="hud-spd">--</span></div>
				<div class="hud-block" id="hud-gps-status" style="min-width:30px"><span class="hud-label" style="color:#ef4444">GPS</span><span class="hud-value" style="color:#ef4444;font-size:10px">--</span></div>
				<div id="dagr-compass">
					<div class="north">N</div>
					<div class="needle" id="dagr-needle" style="transform:rotate(0deg)"></div>
					<div class="hdg" id="dagr-hdg-txt">---</div>
				</div>
			</div>
		</div>
	</div>
</div>

<script>
(function(){
	var TOKEN='<?php echo esc_js( $token ); ?>';
	var SID='<?php echo esc_js( $session ); ?>';
	var MAP_NAME='<?php echo esc_js( $map_name ); ?>';
	var MY_STEAM='<?php echo esc_js( $valid->steamid ); ?>';
	var dagrMap=null, mePos=null, followMe=false, addingWP=false, drawing=false, drawFirst=null, drawTmp=null, selectedLines=[];
	var layers={players:[],markers:[],waypoints:[],lines:[]};

	/* ── Find Leaflet instance via _rmmMap reference ── */
	function waitMap(cb){
		var tries=0, maxTries=50;
		var t=setInterval(function(){
			tries++;
			var el=document.querySelector('.leaflet-container');
			if(el&&el._rmmMap){
				clearInterval(t);
				dagrMap=el._rmmMap;
				$('#dagr-status').textContent='MAPA OK';
				$('#dagr-status').style.color='#4ade80';
				cb();
				return;
			}
			if(el&&el._leaflet_id&&!el._rmmMap){
				// Fallback: buscar en L (por si _rmmMap no está)
				try{
					for(var k in L){var v=L[k];if(v&&v._leaflet_id===el._leaflet_id&&typeof v.addLayer==='function'){dagrMap=v;break}}
				}catch(e){}
				if(dagrMap){clearInterval(t);$('#dagr-status').textContent='MAPA OK (fallback)';$('#dagr-status').style.color='#4ade80';cb();return}
			}
			if(tries>=maxTries){
				clearInterval(t);
				$('#dagr-status').style.color='#ef4444';
				if(!el)$('#dagr-status').textContent='ERROR: No hay .leaflet-container';
				else if(!el._rmmMap&&!el._leaflet_id)$('#dagr-status').textContent='ERROR: Mapa sin inicializar';
				else $('#dagr-status').textContent='ERROR: Mapa no cargó en 10s';
			}
		},200);
	}

	// Status indicator
	var statusEl=document.createElement('div');
	statusEl.id='dagr-status';
	statusEl.style.cssText='position:fixed;top:0;left:50%;transform:translateX(-50%);background:rgba(0,0,0,.9);color:#FFB000;padding:3px 12px;font-size:10px;z-index:99999;border-radius:0 0 4px 4px;font-family:monospace;pointer-events:none';
	statusEl.textContent='CARGANDO...';
	document.body.appendChild(statusEl);

	var $=function(s){return document.querySelector(s)};
	var $$=function(s){return document.querySelectorAll(s)};

	/* ── Helpers ── */
	function g2ll(x,y){return L.latLng([Number(y)+<?php echo $edge_offset; ?>,Number(x)+<?php echo $edge_offset; ?>])}
	function ll2g(ll){return{x:Math.round(ll.lng-<?php echo $edge_offset; ?>),y:Math.round(ll.lat-<?php echo $edge_offset; ?>)}}
	function bearing(a,b){var dx=b.x-a.x,dy=b.y-a.y;return(Math.atan2(dx,dy)*180/Math.PI+360)%360}
	function dist(a,b){return Math.round(Math.sqrt(Math.pow(b.x-a.x,2)+Math.pow(b.y-a.y,2)))}
	function pad(n,w){return String(n).padStart(w,'0')}

	/* ── Grid ↔ Meters conversion ── */
	function gridToMeters(g){
		var s=String(g).replace(/[^0-9]/g,'');
		if(!s)return NaN;
		var n=parseInt(s,10);
		if(s.length<=2)return n*1000;   // 2 dígitos = km
		if(s.length===3)return n*100;   // 3 dígitos = 100m
		if(s.length===4)return n*10;    // 4 dígitos = 10m
		return n;                        // 5 dígitos = 1m
	}
	function metersToGrid(m){
		m=Math.max(0,Math.min(Math.round(m),12800));
		if(m%1000===0)return String(m/1000);           // 4000 → "4"
		if(m%100===0)return pad(m/100,3);              // 4800 → "048"
		if(m%10===0)return pad(m/10,4);                // 4890 → "0489"
		return pad(m,5);                                // 4895 → "04895"
	}

	// Live preview while typing
	$('#wp-x').addEventListener('input',function(){
		var mx=gridToMeters(this.value),my=gridToMeters($('#wp-y').value);
		$('#wp-meters-preview').textContent=(!isNaN(mx)?mx:'?')+'m E / '+(!isNaN(my)?my:'?')+'m N';
	});
	$('#wp-y').addEventListener('input',function(){
		var mx=gridToMeters($('#wp-x').value),my=gridToMeters(this.value);
		$('#wp-meters-preview').textContent=(!isNaN(mx)?mx:'?')+'m E / '+(!isNaN(my)?my:'?')+'m N';
	});

	/* ── Grid canvas ── */
	var gridCv=$('#dagr-grid-canvas'), gridCtx=gridCv.getContext('2d');
	function drawGrid(){
		if(!dagrMap)return;
		var s=dagrMap.getSize();gridCv.width=s.x;gridCv.height=s.y;
		gridCv.style.width=s.x+'px';gridCv.style.height=s.y+'px';
		gridCtx.clearRect(0,0,s.x,s.y);
		var z=dagrMap.getZoom(),sp=z<3?2000:z<5?1000:z<7?500:z<9?250:100;
		var b=dagrMap.getBounds();
		var sw=ll2g(b.getSouthWest()),ne=ll2g(b.getNorthEast());
		var gxMin=Math.max(0,Math.floor(sw.x/sp)*sp),gxMax=Math.min(<?php echo $max_x; ?>,Math.ceil(ne.x/sp)*sp);
		var gyMin=Math.max(0,Math.floor(sw.y/sp)*sp),gyMax=Math.min(<?php echo $max_y; ?>,Math.ceil(ne.y/sp)*sp);
		gridCtx.strokeStyle='rgba(255,176,0,0.08)';gridCtx.lineWidth=0.5;
		gridCtx.fillStyle='rgba(255,176,0,0.25)';gridCtx.font='9px monospace';
		for(var gx=gxMin;gx<=gxMax;gx+=sp){
			var p=dagrMap.latLngToContainerPoint(g2ll(gx,0));
			gridCtx.beginPath();gridCtx.moveTo(p.x,0);gridCtx.lineTo(p.x,s.y);gridCtx.stroke();
			if(sp>=1000&&gx%1000===0)gridCtx.fillText(gx/1000,p.x+2,s.y-3);
			else if(sp<1000&&gx%(sp*5)===0)gridCtx.fillText(gx,p.x+2,s.y-3);
		}
		for(var gy=gyMin;gy<=gyMax;gy+=sp){
			var p=dagrMap.latLngToContainerPoint(g2ll(0,gy));
			gridCtx.beginPath();gridCtx.moveTo(0,p.y);gridCtx.lineTo(s.x,p.y);gridCtx.stroke();
			if(sp>=1000&&gy%1000===0)gridCtx.fillText(gy/1000,4,p.y-3);
			else if(sp<1000&&gy%(sp*5)===0)gridCtx.fillText(gy,4,p.y-3);
		}
	}

	/* ── HUD update + compass overlay ── */
	function updHUD(p){
		if(!p)return;
		$('#hud-grid-e').textContent=pad(Math.round(p.pos_x),5);
		$('#hud-grid-n').textContent=pad(Math.round(p.pos_y),5);
		$('#hud-alt').textContent=Math.round(p.pos_z||0);
		$('#hud-spd').textContent=Math.round(p.speed||0);
		var h=Math.round(p.heading||0);
		$('#dagr-needle').style.transform='rotate('+h+'deg)';
		$('#dagr-hdg-txt').textContent=pad(h,3);
		// Update large compass overlay if visible
		$('#dagr-cmp-needle').style.transform='rotate('+h+'deg)';
		$('#dagr-cmp-hdg').textContent=pad(h,3)+'°';
		// GPS status
		$('#hud-gps-status .hud-label').style.color='#4ade80';
		$('#hud-gps-status .hud-value').style.color='#4ade80';
		$('#hud-gps-status .hud-value').textContent='OK';
		// Update WP bearings on compass overlay
		updateCompassWPs(h);
	}

	/* ── Show WP bearing on compass overlay (active WP only) ── */
	function updateCompassWPs(myHeading){
		if(!mePos)return;
		var container=$('#dagr-compass-overlay');
		if(container.style.display!=='block')return;
		// Clear previous WP elements
		container.querySelectorAll('.dagr-cmp-wp,.dagr-cmp-wp-line').forEach(function(el){el.remove()});
		var ring=container.querySelector('div');
		var cx=70,cy=70,r=62;
		// Solo el WP activo
		var wps=layers.waypoints||[];
		if(wps.length===0||activeWpIdx>=wps.length)return;
		var m=wps[activeWpIdx];
		var ll=m.getLatLng();
		var wp={x:ll.lng-<?php echo $edge_offset; ?>,y:ll.lat-<?php echo $edge_offset; ?>};
		var brg=bearing(mePos,wp);
		var rel=(brg-myHeading+360)%360;
		var rad=rel*Math.PI/180;
		// Line from center to WP dot
		var ex=cx+r*Math.sin(rad),ey=cy-r*Math.cos(rad);
		var line=document.createElement('div');
		line.className='dagr-cmp-wp-line';
		var dx=ex-cx,dy=ey-cy,dist=Math.sqrt(dx*dx+dy*dy);
		line.style.cssText='position:absolute;left:'+cx+'px;top:'+cy+'px;width:'+dist+'px;height:2px;background:#FFB000;transform-origin:0 1px;transform:rotate('+(Math.atan2(dy,dx)*180/Math.PI)+'deg);opacity:0.6;pointer-events:none;box-shadow:0 0 4px #FFB000';
		ring.appendChild(line);
		// Dot at WP position
		var x=ex-6,y=ey-6;
		var dot=document.createElement('div');
		dot.className='dagr-cmp-wp';
		dot.style.cssText='position:absolute;left:'+x+'px;top:'+y+'px;width:12px;height:12px;background:#FFB000;border:2px solid #fff;border-radius:2px;transform:rotate(45deg);box-shadow:0 0 8px #FFB000;pointer-events:none';
		ring.appendChild(dot);
	}

	/* ── Toast feedback ── */
	function toast(msg){
		var el=document.createElement('div');
		el.textContent=msg;
		el.style.cssText='position:fixed;bottom:60px;left:50%;transform:translateX(-50%);background:rgba(0,0,0,.9);color:#FFB000;padding:10px 20px;border-radius:4px;font-family:"Courier New",monospace;font-size:13px;z-index:99999;border:1px solid #FFB000;pointer-events:none';
		document.body.appendChild(el);
		setTimeout(function(){el.remove()},2000);
	}

	/* ── Waypoints ── */
	var activeWpIdx=0;
	function loadWP(){
		fetch('/wp-json/clan/v1/microdagr/waypoints?token='+TOKEN).then(function(r){return r.json()}).then(function(d){
			layers.waypoints.forEach(function(m){dagrMap.removeLayer(m)});layers.waypoints=[];
			var wps=d.waypoints||[];
			if(activeWpIdx>=wps.length)activeWpIdx=Math.max(0,wps.length-1);

			// Render ALL WPs as map markers, but only show active one in panel
			var h='',total=wps.length;
			wps.forEach(function(w,i){
				var brg='--',dst='--',gx=metersToGrid(w.pos_x),gy=metersToGrid(w.pos_y);
				if(mePos){
					var wp={x:Number(w.pos_x),y:Number(w.pos_y)};
					brg=Math.round(bearing(mePos,wp))+'°';
					dst=dist(mePos,wp)+'m';
				}
				// Map marker for all WPs
				var isActive=(i===activeWpIdx);
				var ic=L.divIcon({html:'<div style="width:'+(isActive?14:10)+'px;height:'+(isActive?14:10)+'px;background:#FFB000;border:2px solid #fff;border-radius:2px;transform:rotate(45deg);box-shadow:0 0 '+(isActive?10:6)+'px rgba(255,176,0,.6)"></div>',iconSize:[18,18],iconAnchor:[9,9]});
				var mk=L.marker(g2ll(w.pos_x,w.pos_y),{icon:ic,zIndexOffset:isActive?9998:0}).bindTooltip('WP: '+w.label,{direction:'top',offset:[0,-10]});
				layers.waypoints.push(mk);if(layerVisible.waypoints)mk.addTo(dagrMap);

				// Only render active WP in panel (or all if total<=1 with nav hidden)
				if(i===activeWpIdx){
					h='<div class="dagr-wp-item" data-wp-id="'+w.id+'"><div class="wp-name"><span>◆ '+(i+1)+'/'+total+' '+w.label+'</span><span class="wp-del">✕</span></div><div class="wp-info">BRG '+brg+' | DST '+dst+'</div><div class="wp-info">GRID '+gx+' '+gy+'</div></div>';
				}
			});

			// Navigation row
			var nav='';
			if(total>1){
				nav='<div style="display:flex;gap:4px;margin-top:4px;border-top:1px solid #1a1a1a;padding-top:4px"><button class="dagr-phys-btn wp-nav-btn" data-dir="prev" style="flex:1;font-size:9px;min-height:28px;padding:4px">▲ ANT</button><button class="dagr-phys-btn wp-nav-btn" data-dir="next" style="flex:1;font-size:9px;min-height:28px;padding:4px">▼ SIG</button></div>';
			}

			$('#dagr-wp-items').innerHTML=(h||'<em style="color:#555">VACÍO</em>')+nav;
		});
	}

	function doAddWP(ll_or_x, y_opt, nm_opt){
		// Legacy: called as addWP(ll) from old code paths
		if(typeof y_opt==='undefined'){var g=ll2g(ll_or_x);doAddWP(g.x,g.y,nm_opt||'WP');return}
		var x=ll_or_x,y=y_opt,nm=nm_opt||'WP';
		fetch('/wp-json/clan/v1/microdagr/waypoints',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({token:TOKEN,label:nm,pos_x:x,pos_y:y})}).then(function(r){return r.json()}).then(function(){
			loadWP();
			toast('WP "'+nm+'" en '+metersToGrid(x)+' '+metersToGrid(y));
		});
	}
	// Keep addWP alias for backward compat
	var addWP=function(ll){doAddWP(ll)};

	/* ── Marker icons (PNG reales NATO) ── */
	var NATO_PATH='<?php echo RMM_PLUGIN_URL; ?>assets/nato_icons/';
	function makeIcon(url,w,h){return L.icon({iconUrl:url,iconSize:[w||24,h||24],iconAnchor:[12,12],popupAnchor:[0,-12]})}
	var mkIcons={
		infantry:   makeIcon(NATO_PATH+'Friend%20Land/1.sfgpi-----h----.png'),
		armor:      makeIcon(NATO_PATH+'Friend%20Land/1.sffpa----------.png'),
		heli:       makeIcon(NATO_PATH+'Friend%20Land/1.sffpah---------.png'),
		hq:         makeIcon(NATO_PATH+'Friend%20Land/1.sfgp-----------.png'),
		artillery:  makeIcon(NATO_PATH+'Friend%20Land/1.sffpn----------.png'),
		medical:    makeIcon(NATO_PATH+'Friend%20Land/1.sfgpimc---h----.png'),
		enemy_inf:  makeIcon(NATO_PATH+'Hostile%20Land/3.shfp-----------.png'),
		enemy_armor:makeIcon(NATO_PATH+'Hostile%20Land/3.shfpa----------.png'),
		objective:  makeIcon(NATO_PATH+'Friend%20Land/1.sffp-----------.png'),
		danger:     makeIcon(NATO_PATH+'Friend%20Land/1.sfgp-----------.png'),
		enemy:      makeIcon(NATO_PATH+'Hostile%20Land/3.shfp-----------.png',24,30),
		friendly:   makeIcon(NATO_PATH+'Friend%20Land/1.sffp-----------.png'),
		completed:  makeIcon(NATO_PATH+'Friend%20Land/1.sffp-----------.png'),
		mil_marker: makeIcon(NATO_PATH+'Friend%20Land/1.sffp-----------.png')
	},
	mkDef=makeIcon(NATO_PATH+'Friend%20Land/1.sffp-----------.png');

	// NATO marker types for UI (8 tipos, imágenes reales)
	var natoTypes=[
		{id:'infantry',label:'INF',img:NATO_PATH+'Friend%20Land/1.sfgpi-----h----.png'},
		{id:'armor',label:'ARM',img:NATO_PATH+'Friend%20Land/1.sffpa----------.png'},
		{id:'heli',label:'HEL',img:NATO_PATH+'Friend%20Land/1.sffpah---------.png'},
		{id:'hq',label:'HQ',img:NATO_PATH+'Friend%20Land/1.sfgp-----------.png'},
		{id:'artillery',label:'ART',img:NATO_PATH+'Friend%20Land/1.sffpn----------.png'},
		{id:'medical',label:'MED',img:NATO_PATH+'Friend%20Land/1.sfgpimc---h----.png'},
		{id:'enemy_inf',label:'EINF',img:NATO_PATH+'Hostile%20Land/3.shfp-----------.png'},
		{id:'enemy_armor',label:'EARM',img:NATO_PATH+'Hostile%20Land/3.shfpa----------.png'}
	];

	/* ── Player marker (arrow that rotates with heading) ── */
	var meIcon=L.divIcon({
		className:'dagr-me-marker',
		html:'<div class="dagr-me-arrow" style="width:0;height:0;border-left:9px solid transparent;border-right:9px solid transparent;border-bottom:20px solid #FFB000;filter:drop-shadow(0 0 8px #FFB000);transform-origin:9px 15px;"></div><div style="width:10px;height:10px;background:#FFB000;border:2px solid #fff;border-radius:50%;position:absolute;top:14px;left:4px;box-shadow:0 0 8px #FFB000;"></div>',
		iconSize:[22,30],
		iconAnchor:[11,15]
	});
	var meMarker=null, lastHeading=0;

	waitMap(function(){
		meMarker=L.marker(dagrMap.getCenter(),{icon:meIcon,zIndexOffset:99999}).addTo(dagrMap);
		dagrMap.on('moveend zoomend',drawGrid);
		setTimeout(drawGrid,800);
		// Clock
		setInterval(function(){$('#dagr-clock').textContent=new Date().toLocaleTimeString([],{hour:'2-digit',minute:'2-digit'})},10000);
		$('#dagr-clock').textContent=new Date().toLocaleTimeString([],{hour:'2-digit',minute:'2-digit'});

		/* ── SIMULACIÓN automática para sesiones test_* ── */
		var isTestSession=SID.indexOf('test_')===0;
		if(isTestSession){
			toast('MODO SIMULACIÓN — datos falsos cada 2s');
			// Crear datos iniciales si no hay
			fetch('/wp-json/clan/v1/mission/simulate',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({session_id:SID})});
			// Seguir enviando ticks cada 2s
			setInterval(function(){
				fetch('/wp-json/clan/v1/mission/simulate',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({session_id:SID})});
			},2000);
		}

		/* ── Poll positions (every 4s) ── usa mismo endpoint que el mapa web ── */
		var notFoundCount=0;
		setInterval(function(){
			fetch('/wp-json/clan/v1/dagr/positions?map='+MAP_NAME+'&_='+Date.now()).then(function(r){return r.json()}).then(function(d){
				if(!d||!d.players){$('#dagr-status').textContent='SIN DATOS';return}
				layers.players.forEach(function(m){dagrMap.removeLayer(m)});layers.players=[];
				var foundMe=false;
				d.players.forEach(function(p){
					var ll=g2ll(p.pos_x,p.pos_y);
					var sid=String(p.steamid||'').trim();
					if(!foundMe)$('#dagr-status').textContent='BUSCANDO mi='+MY_STEAM.substr(-6)+' vs '+sid.substr(-6);
					if(sid===MY_STEAM){
						foundMe=true; notFoundCount=0;
						mePos={x:Number(p.pos_x),y:Number(p.pos_y),z:Number(p.pos_z||0),h:Number(p.heading||0),s:Number(p.speed||0)};
						meMarker.setLatLng(ll);
						// Rotate arrow to heading
						var h=Number(p.heading||0);
						if(meMarker._icon){
							var arrow=meMarker._icon.querySelector('.dagr-me-arrow');
							if(arrow)arrow.style.transform='rotate('+h+'deg)';
						}
						if(layerVisible.players){if(!dagrMap.hasLayer(meMarker))dagrMap.addLayer(meMarker)}
						else{dagrMap.removeLayer(meMarker)}
						if(followMe)dagrMap.panTo(ll,{animate:true});
						updHUD(p);
						$('#dagr-status').textContent='MAPA OK | GPS OK';
						$('#dagr-status').style.color='#4ade80';
					}else{
						var cl='#FFB000'; // DAGR endpoint no tiene is_alive, asumir vivo
						var ic=L.divIcon({html:'<div style="width:9px;height:9px;background:'+cl+';border:2px solid #fff;border-radius:50%"></div>',iconSize:[13,13],iconAnchor:[6,6]});
						var mk=L.marker(ll,{icon:ic}).bindTooltip(p.name||p.player_name||'',{direction:'top',offset:[0,-8]});
						layers.players.push(mk);
						if(layerVisible.players)mk.addTo(dagrMap);
					}
				});
				if(!foundMe){
					notFoundCount++;
					if(notFoundCount>=5){$('#dagr-status').textContent='NO ENCONTRADO: steamid no encontrado tras '+notFoundCount+' intentos';$('#dagr-status').style.color='#ef4444'}
					else{$('#dagr-status').textContent='ESPERANDO datos del addon... ('+notFoundCount+'/5)'}
				}
				loadWP();
			});
		},4000);

		/* ── Load markers ── */
		fetch('/wp-json/clan/v1/mission/markers?session='+SID).then(function(r){return r.json()}).then(function(d){
			if(!d||!d.markers)return;
			d.markers.forEach(function(mk){
				var ll=g2ll(mk.pos_x,mk.pos_y);
				var ic=L.divIcon({html:mkIcons[mk.type]||mkDef,iconSize:[18,18],iconAnchor:[9,9]});
				var mkr=L.marker(ll,{icon:ic}).bindTooltip(mk.label||mk.type,{direction:'top',offset:[0,-10]});
				layers.markers.push(mkr);
				if(layerVisible.markers)mkr.addTo(dagrMap);
			});
		});

		loadWP();
	});

	/* ── Button handlers ── */
	$('#btn-center').onclick=function(){followMe=!followMe;this.classList.toggle('active',followMe);if(dagrMap&&mePos&&followMe)dagrMap.panTo(g2ll(mePos.x,mePos.y));toast(followMe?'Siguiendo':'Libre')};

	// + button: show WP form
	$('#btn-add-wp').onclick=function(){
		var f=$('#dagr-wp-form');
		if(f.style.display==='block'){f.style.display='none';this.classList.remove('active');return}
		f.style.display='block';this.classList.add('active');
		$('#wp-x').value='';$('#wp-y').value='';$('#wp-name').value='';
		setTimeout(function(){$('#wp-x').focus()},100);
	};

	// WP form: AÑADIR button (converts grid → meters)
	$('#wp-btn-add').onclick=function(){
		var gx=$('#wp-x').value.trim(),gy=$('#wp-y').value.trim(),nm=$('#wp-name').value||'WP';
		var mx=gridToMeters(gx),my=gridToMeters(gy);
		if(isNaN(mx)||isNaN(my)||gx===''||gy===''){toast('Introduce grid X e Y (1-5 dígitos cada uno)');return}
		if(mx<0||mx>12800||my<0||my>12800){toast('Coordenadas fuera del mapa (0-12800m)');return}
		doAddWP(mx,my,nm);
	};

	// WP form: TOCAR MAPA button (fills grid coords)
	$('#wp-btn-tap').onclick=function(){
		if(!dagrMap){toast('Mapa no cargado aún');return}
		$('#dagr-wp-form').style.display='none';
		addingWP=true;dagrMap.getContainer().style.cursor='crosshair';
		toast('Toca el mapa para seleccionar coordenadas...');
		dagrMap.once('click',function(e){
			var g=ll2g(e.latlng);
			$('#wp-x').value=metersToGrid(g.x);$('#wp-y').value=metersToGrid(g.y);
			$('#dagr-wp-form').style.display='block';
			$('#wp-name').focus();
			// Trigger preview update
			$('#wp-x').dispatchEvent(new Event('input'));
			dagrMap.getContainer().style.cursor='';addingWP=false;
		});
	};

	// WP form: CANCELAR
	$('#wp-btn-cancel').onclick=function(){
		$('#dagr-wp-form').style.display='none';$('#btn-add-wp').classList.remove('active');
		if(addingWP){dagrMap.getContainer().style.cursor='';addingWP=false}
	};

	// Shared add-WP function (used by form + MARK)
	function doAddWP(x,y,nm){
		fetch('/wp-json/clan/v1/microdagr/waypoints',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({token:TOKEN,label:nm,pos_x:x,pos_y:y})}).then(function(r){return r.json()}).then(function(){
			loadWP();
			toast('WP "'+nm+'" en '+metersToGrid(x)+' '+metersToGrid(y));
			$('#dagr-wp-form').style.display='none';$('#btn-add-wp').classList.remove('active');
		});
	}

	// MARK button: open form pre-filled with current position (grid format)
	$('#btn-mark').onclick=function(){
		var f=$('#dagr-wp-form');
		f.style.display='block';$('#btn-add-wp').classList.add('active');
		if(mePos){
			$('#wp-x').value=metersToGrid(mePos.x);$('#wp-y').value=metersToGrid(mePos.y);
			$('#wp-name').value='';
			$('#wp-x').dispatchEvent(new Event('input'));
			toast('Posición actual cargada');
		}else{
			$('#wp-x').value='';$('#wp-y').value='';$('#wp-name').value='';
			toast('Sin GPS — introduce grid manualmente');
		}
		setTimeout(function(){$('#wp-name').focus()},100);
	};

	// WP menu button
	$('#btn-wp-menu').onclick=function(){this.classList.toggle('on');$('#dagr-wp-panel').classList.toggle('open')};

	// LAY button
	$('#btn-layers').onclick=function(){this.classList.toggle('on');$('#dagr-layer-panel').classList.toggle('open')};

	// MAP button: center on player or cycle zoom
	$('#btn-map').onclick=function(){
		if(!dagrMap)return;
		if(mePos){dagrMap.panTo(g2ll(mePos.x,mePos.y),{animate:true});toast('Centrado en posición')}
		else{var z=dagrMap.getZoom(),l=[2,4,6,8],n=l.find(function(v){return v>z})||l[0];dagrMap.setZoom(n,{animate:true});toast('ZOOM '+n)}
	};

	// CMP button: toggle compass overlay
	$('#btn-compass').onclick=function(){
		var ov=$('#dagr-compass-overlay');
		if(ov.style.display==='block'){ov.style.display='none';this.classList.remove('on');toast('Brújula oculta')}
		else{ov.style.display='block';this.classList.add('on');if(mePos){updHUD({pos_x:mePos.x,pos_y:mePos.y,pos_z:mePos.z,heading:mePos.h,speed:mePos.s})};toast('Brújula visible')}
	};

	// MRK button: selector de tipo de marcador NATO
	var placingMarker=null;
	// Poblar la parrilla de tipos con imágenes reales
	natoTypes.forEach(function(t){
		var btn=document.createElement('button');
		btn.className='dagr-phys-btn';
		btn.style.cssText='padding:6px 4px;font-size:9px;min-height:50px;display:flex;flex-direction:column;align-items:center;gap:2px';
		btn.innerHTML='<img src="'+t.img+'" style="width:28px;height:28px;image-rendering:pixelated"><span>'+t.label+'</span>';
		btn.onclick=function(){
			placingMarker=t.id;
			$('#dagr-mrk-panel').style.display='none';
			$('#btn-mrk').classList.remove('on');
			toast('Toca el mapa para colocar '+t.label);
		};
		$('#dagr-mrk-types').appendChild(btn);
	});
	$('#btn-mrk').onclick=function(){
		var p=$('#dagr-mrk-panel');
		if(p.style.display==='block'){p.style.display='none';this.classList.remove('on');placingMarker=null;return}
		p.style.display='block';this.classList.add('on');
	};

	// DRAW button: solo ON/OFF (sin CLEAR). Líneas se borran con doble toque
	$('#btn-draw').onclick=function(){
		if(!dagrMap)return;
		drawing=!drawing;
		this.classList.toggle('on',drawing);
		this.textContent=drawing?'DRAW*':'DRAW';
		if(drawing){
			dagrMap.getContainer().style.cursor='crosshair';
			drawFirst=null;
			if(drawTmp){dagrMap.removeLayer(drawTmp);drawTmp=null}
			// Deseleccionar al entrar en modo dibujo
			selectedLines.forEach(function(l){l.setStyle({color:'#FFB000',weight:3})});
			selectedLines=[];
			toast('DRAW ON — 2 toques = línea');
		}else{
			dagrMap.getContainer().style.cursor='';
			if(drawTmp){dagrMap.removeLayer(drawTmp);drawTmp=null}
			drawFirst=null;
			toast('DRAW OFF');
		}
	};

	/* ── WP actions: delete + navigate (ANT/SIG) ── */
	$('#dagr-wp-items').addEventListener('click',function(e){
		var del=e.target.closest('.wp-del');
		var nav=e.target.closest('.wp-nav-btn');

		// Navegación entre WPs
		if(nav){
			var dir=nav.dataset.dir;
			if(dir==='prev')activeWpIdx=Math.max(0,activeWpIdx-1);
			else activeWpIdx++;
			loadWP();
			return;
		}

		// Borrar WP activo
		if(del){
			var item=document.querySelector('#dagr-wp-items .dagr-wp-item');
			if(!item)return;
			var id=item.dataset.wpId;
			if(!confirm('¿Borrar este waypoint?'))return;
			fetch('/wp-json/clan/v1/microdagr/waypoints/'+id,{method:'DELETE',headers:{'Content-Type':'application/json'},body:JSON.stringify({token:TOKEN})}).then(function(){
				if(activeWpIdx>0)activeWpIdx--;
				loadWP();
				toast('Waypoint eliminado');
			});
		}
	});

	/* ── Layer toggles (global function for inline onchange) ── */
	var layerVisible = {players:true, markers:true, waypoints:true, lines:true};
	window.dagrToggleLayer = function(cb){
		if(!dagrMap)return;
		var k=cb.dataset.layer, v=cb.checked;
		layerVisible[k]=v;
		(layers[k]||[]).forEach(function(m){v?dagrMap.addLayer(m):dagrMap.removeLayer(m)});
		if(k==='players'&&meMarker){v?dagrMap.addLayer(meMarker):dagrMap.removeLayer(meMarker)}
		toast('Capa '+k+(v?' ON':' OFF'));
	};

	/* ── Close panels when tapping map (skip if drawing) ── */
	waitMap(function(){
		dagrMap.on('click',function(e){
			// Modo DRAW: 2 toques = 1 línea
			if(drawing){
				if(!drawFirst){
					// Primer punto
					drawFirst=e.latlng;
					if(drawTmp)dagrMap.removeLayer(drawTmp);
					drawTmp=L.circleMarker(e.latlng,{radius:6,color:'#FFB000',fillColor:'#FFB000',fillOpacity:0.6,weight:2}).addTo(dagrMap);
					toast('Toca el segundo punto...');
				}else{
					// Segundo punto → crear línea (doble toque = borrar, radio amplio)
					var line=L.polyline([drawFirst,e.latlng],{color:'#FFB000',weight:3,opacity:0.8,dashArray:'8,6',lineCap:'round',lineJoin:'round'}).addTo(dagrMap);
					// Capa invisible más gruesa para facilitar el toque
					var hitArea=L.polyline([drawFirst,e.latlng],{color:'transparent',weight:20,opacity:0}).addTo(dagrMap);
					hitArea.on('click',function(ev){
						L.DomEvent.stop(ev);
						var idx=selectedLines.indexOf(line);
						if(idx>=0){
							// Segundo toque: borrar
							dagrMap.removeLayer(line);dagrMap.removeLayer(hitArea);
							var i=layers.lines.indexOf(line);if(i>=0)layers.lines.splice(i,1);
							selectedLines.splice(idx,1);
							toast('Línea borrada');
						}else{
							// Primer toque: seleccionar
							selectedLines.push(line);
							line.setStyle({color:'#ef4444',weight:4});
							toast('Línea seleccionada — toca otra vez para borrar');
						}
					});
					layers.lines.push(line);
					if(!layerVisible.lines)dagrMap.removeLayer(line);
					if(drawTmp){dagrMap.removeLayer(drawTmp);drawTmp=null}
					drawFirst=null;
					var g1=ll2g(e.latlng);
					var d=mePos?Math.round(dist(mePos,{x:g1.x,y:g1.y})):0;
					toast('Línea creada'+(d?' ('+d+'m)':''));
				}
				return;
			}
			// Place NATO marker
			if(placingMarker){
				var g=ll2g(e.latlng);
				var lb=prompt('Etiqueta (opcional):');
				if(lb===null){placingMarker=null;return}
				fetch('/wp-json/clan/v1/mission/markers',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({token:TOKEN,session_id:SID,type:placingMarker,label:lb||'',pos_x:g.x,pos_y:g.y,color:'#FFB000'})}).then(function(r){return r.json()}).then(function(){
					var ic=mkIcons[placingMarker]||mkDef;
					var mk=L.marker(e.latlng,{icon:ic}).bindTooltip(lb||placingMarker,{direction:'top',offset:[0,-14]});
					layers.markers.push(mk);if(layerVisible.markers)mk.addTo(dagrMap);
					toast('Marcador '+placingMarker+' colocado');
				});
				placingMarker=null;
				return;
			}
			// Deselect lines when tapping empty space
			if(selectedLines.length>0){
				selectedLines.forEach(function(l){l.setStyle({color:'#FFB000',weight:3})});
				selectedLines=[];
			}
			if($('#dagr-layer-panel').classList.contains('open')){$('#dagr-layer-panel').classList.remove('open');$('#btn-layers').classList.remove('on')}
		});
	});
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

		// Reordenar waypoints
		register_rest_route( 'clan/v1', '/microdagr/waypoints/reorder', array(
			'methods'  => 'POST',
			'callback' => array( $this, 'reorder_waypoints' ),
			'permission_callback' => '__return_true',
		) );

		// Borrar waypoint
		register_rest_route( 'clan/v1', '/microdagr/waypoints/(?P<id>\d+)', array(
			'methods'  => 'DELETE',
			'callback' => array( $this, 'delete_waypoint' ),
			'permission_callback' => '__return_true',
		) );

		// Endpoint de TEST — simular telemetría
		// Test session
		register_rest_route( 'clan/v1', '/mission/test-session', array(
			'methods'  => 'POST',
			'callback' => array( $this, 'create_test_session' ),
			'permission_callback' => '__return_true',
		) );

		// Simular telemetría (para testing sin addon)
		register_rest_route( 'clan/v1', '/mission/simulate', array(
			'methods'  => 'POST',
			'callback' => array( $this, 'simulate_telemetry_tick' ),
			'permission_callback' => '__return_true',
		) );

		// Finalizar sesión
		register_rest_route( 'clan/v1', '/mission/end-session', array(
			'methods'  => 'POST',
			'callback' => array( $this, 'end_session' ),
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
	 * Finalizar sesión
	 */
	public function end_session( $request ) {
		global $wpdb;
		$data = $request->get_json_params() ?: $request->get_params();
		$session_id = sanitize_text_field( $data['session_id'] ?? '' );
		if ( empty( $session_id ) ) return rest_ensure_response( array( 'error' => 'No session_id' ) );

		$table_sessions = $wpdb->prefix . 'rmm_mission_sessions';
		$updated = $wpdb->update( $table_sessions, array(
			'status'   => 'ended',
			'ended_at' => current_time( 'mysql' ),
		), array( 'session_id' => $session_id ) );

		return rest_ensure_response( array( 'status' => $updated ? 'ended' : 'not found', 'session_id' => $session_id ) );
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
	 * Reordenar waypoints — intercambia order_index entre dos
	 */
	public function reorder_waypoints( $request ) {
		global $wpdb;
		$data = $request->get_json_params() ?: $request->get_params();
		$token = sanitize_text_field( $data['token'] ?? '' );
		$wp_id = intval( $data['id'] ?? 0 );
		$dir   = sanitize_text_field( $data['direction'] ?? 'up' );

		if ( empty( $token ) || ! $wp_id ) {
			return rest_ensure_response( array( 'error' => 'Faltan parámetros' ) );
		}

		$table = $wpdb->prefix . 'rmm_waypoints';

		// Obtener el waypoint actual
		$current = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, order_index FROM $table WHERE id = %d AND token = %s",
			$wp_id, $token
		) );
		if ( ! $current ) return rest_ensure_response( array( 'error' => 'WP no encontrado' ) );

		// Buscar el waypoint adyacente
		if ( $dir === 'up' ) {
			$adjacent = $wpdb->get_row( $wpdb->prepare(
				"SELECT id, order_index FROM $table WHERE token = %s AND is_active = 1 AND order_index < %d ORDER BY order_index DESC LIMIT 1",
				$token, $current->order_index
			) );
		} else {
			$adjacent = $wpdb->get_row( $wpdb->prepare(
				"SELECT id, order_index FROM $table WHERE token = %s AND is_active = 1 AND order_index > %d ORDER BY order_index ASC LIMIT 1",
				$token, $current->order_index
			) );
		}

		if ( ! $adjacent ) return rest_ensure_response( array( 'status' => 'noop', 'msg' => 'Ya está en el extremo' ) );

		// Intercambiar order_index
		$wpdb->update( $table, array( 'order_index' => $adjacent->order_index ), array( 'id' => $current->id ) );
		$wpdb->update( $table, array( 'order_index' => $current->order_index ), array( 'id' => $adjacent->id ) );

		return rest_ensure_response( array( 'status' => 'ok' ) );
	}

	/**
	 * Borrar waypoint
	 */
	public function delete_waypoint( $request ) {
		global $wpdb;
		$id    = intval( $request->get_param( 'id' ) );
		// Token viene en body (DELETE con body)
		$body  = $request->get_json_params() ?: array();
		$token = sanitize_text_field( $body['token'] ?? $request->get_param( 'token' ) ?? '' );

		if ( ! $id || ! $token ) {
			return rest_ensure_response( array( 'error' => 'Faltan parámetros' ) );
		}

		$table = $wpdb->prefix . 'rmm_waypoints';
		// Soft delete: marcar inactivo en vez de borrar físicamente
		$wpdb->update( $table, array( 'is_active' => 0 ), array( 'id' => $id, 'token' => $token ) );

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

	/**
	 * Simular tick de telemetría — movimiento circular con payload JSON configurable
	 * POST /wp-json/clan/v1/mission/simulate
	 * Body: { "session_id": "test_xxx" }
	 */
	public function simulate_telemetry_tick( $request ) {
		global $wpdb;
		$data = $request->get_json_params() ?: $request->get_params();
		$session_id = sanitize_text_field( $data['session_id'] ?? 'test_circle' );

		$table_positions = $wpdb->prefix . 'rmm_mission_positions';
		$table_sessions  = $wpdb->prefix . 'rmm_mission_sessions';
		$table_markers   = $wpdb->prefix . 'rmm_mission_markers';

		// Cargar payload desde BD
		$payload_raw = get_option( 'rmm_simulate_payload', '' );
		$payload     = json_decode( $payload_raw, true );
		if ( ! $payload ) {
			$payload = array(
				'session_id' => $session_id,
				'map'        => 'everon',
				'players'    => array(
					array( 'name' => 'TRAUMAN',     'steamid' => '76561198000000001', 'x' => 5000, 'y' => 3000, 'radius' => 300, 'omega' => 2 ),
					array( 'name' => 'ANTIGRAVITY', 'steamid' => '76561198000000002', 'x' => 5200, 'y' => 3200, 'radius' => 200, 'omega' => -3 ),
					array( 'name' => 'ZULU_1',      'steamid' => '76561198000000003', 'x' => 6000, 'y' => 4500, 'radius' => 400, 'omega' => 1.5 ),
					array( 'name' => 'ENEMY_1',     'steamid' => '76561198000000004', 'x' => 7200, 'y' => 5800, 'radius' => 250, 'omega' => -2 ),
					array( 'name' => 'ENEMY_2',     'steamid' => '76561198000000005', 'x' => 7400, 'y' => 5600, 'radius' => 350, 'omega' => 2.5 ),
				),
				'markers' => array(
					array( 'type' => 'objective', 'label' => 'Base Alpha',    'x' => 5000, 'y' => 3000 ),
					array( 'type' => 'enemy',     'label' => 'Tanque T-72',   'x' => 7200, 'y' => 5800 ),
				),
			);
		}

		$map_name    = $payload['map'] ?? 'everon';
		$players_cfg = $payload['players'] ?? array();
		$markers_cfg = $payload['markers'] ?? array();

		// Contador de ticks (para ángulo continuo)
		$tick_key  = 'rmm_sim_tick_' . $session_id;
		$tick      = intval( get_option( $tick_key, 0 ) ) + 1;
		$tick_time = current_time( 'mysql' );

		// Asegurar sesión activa
		$wpdb->replace( $table_sessions, array(
			'session_id' => $session_id,
			'post_id'    => 0,
			'map_name'   => $map_name,
			'status'     => 'active',
		) );

		// Insertar marcadores (solo primera vez)
		$existing_markers = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM $table_markers WHERE session_id = %s", $session_id
		) );
		if ( ! $existing_markers && ! empty( $markers_cfg ) ) {
			foreach ( $markers_cfg as $m ) {
				$wpdb->insert( $table_markers, array(
					'session_id' => $session_id,
					'marker_id'  => uniqid( 'sim_' ),
					'type'       => sanitize_text_field( $m['type'] ?? 'marker' ),
					'label'      => sanitize_text_field( $m['label'] ?? '' ),
					'pos_x'      => floatval( $m['x'] ?? 0 ),
					'pos_y'      => floatval( $m['y'] ?? 0 ),
					'color'      => sanitize_text_field( $m['color'] ?? '#FFB000' ),
				) );
			}
		}

		// Movimiento circular para cada jugador
		$moved = 0;
		foreach ( $players_cfg as $cfg ) {
			$steamid = sanitize_text_field( $cfg['steamid'] ?? ( 'sim_' . $moved ) );
			$name    = sanitize_text_field( $cfg['name'] ?? ( 'Player_' . $moved ) );
			$cx      = floatval( $cfg['x'] ?? 5000 );
			$cy      = floatval( $cfg['y'] ?? 5000 );
			$radius  = floatval( $cfg['radius'] ?? 300 );
			$omega   = floatval( $cfg['omega'] ?? 2 );         // °/s

			// Ángulo acumulado: omega (°/s) × 5s por tick = omega*5 grados por tick
			$angle_deg = fmod( $omega * $tick * 5, 360 );
			$angle_rad = deg2rad( $angle_deg );

			// Posición circular
			$new_x = $cx + $radius * cos( $angle_rad );
			$new_y = $cy + $radius * sin( $angle_rad );

			// Heading: tangente al círculo (perpendicular al radio)
			$heading = fmod( $angle_deg + 90 + 360, 360 );

			// Velocidad simulada: |omega| * radius / 10 (aproximación km/h)
			$speed = abs( $omega ) * $radius / 10;

			// Mantener dentro del mapa
			$new_x = max( 50, min( 12750, $new_x ) );
			$new_y = max( 50, min( 12750, $new_y ) );

			$wpdb->insert( $table_positions, array(
				'session_id'  => $session_id,
				'steamid'     => $steamid,
				'player_name' => $name,
				'squad'       => sanitize_text_field( $cfg['squad'] ?? 'Alpha' ),
				'faction'     => sanitize_text_field( $cfg['faction'] ?? 'BLUFOR' ),
				'role'        => sanitize_text_field( $cfg['role'] ?? 'Operador' ),
				'pos_x'       => round( $new_x, 1 ),
				'pos_y'       => round( $new_y, 1 ),
				'pos_z'       => floatval( $cfg['z'] ?? rand( 5, 25 ) ),
				'heading'     => round( $heading, 1 ),
				'speed'       => round( $speed, 1 ),
				'is_alive'    => 1,
			) );
			$moved++;
		}

		// Guardar contador de ticks
		update_option( $tick_key, $tick, false );

		return rest_ensure_response( array(
			'status'     => 'tick',
			'tick'       => $tick,
			'moved'      => $moved,
			'session_id' => $session_id,
			'time'       => $tick_time,
		) );
	}

	/**
	 * Metabox: Seleccion de mapa DAGR para eventos
	 */
	public function add_event_map_metabox() {
		add_meta_box(
			'rmm_event_map_metabox',
			'🗺️ Mapa Interactivo DAGR',
			array( $this, 'render_event_map_metabox' ),
			'eventos_partidas',
			'side',
			'default'
		);
	}

	/**
	 * Render metabox: selector de preset DAGR (incluye mapa + markers)
	 */
	public function render_event_map_metabox( $post ) {
		global $wpdb;
		$presets = $wpdb->get_results( "SELECT id, title, map_name FROM {$wpdb->prefix}rmm_dagr_presets ORDER BY title ASC" );
		$current = get_post_meta( $post->ID, '_rmm_event_dagr_preset', true );
		wp_nonce_field( 'rmm_event_map_save', 'rmm_event_map_nonce' );
		?>
		<select name="rmm_event_dagr_preset" style="width:100%;">
			<option value="">-- Sin mapa (desactivado) --</option>
			<?php foreach ( $presets as $p ) : ?>
				<option value="<?php echo esc_attr( $p->id ); ?>" <?php selected( $current, $p->id ); ?>>
					<?php echo esc_html( $p->title ); ?> — 🗺️ <?php echo esc_html( $p->map_name ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<p style="font-size:11px;color:#666;margin-top:6px;">Usa el shortcode <code>[rmm_event_map]</code> en Elementor para mostrar el mapa con sus marcadores.</p>
		<?php
	}

	/**
	 * Guardar seleccion de preset del evento
	 */
	public function save_event_map_metabox( $post_id, $post, $update ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
		if ( ! current_user_can( 'edit_post', $post_id ) ) return;
		if ( ! isset( $_POST['rmm_event_map_nonce'] ) || ! wp_verify_nonce( $_POST['rmm_event_map_nonce'], 'rmm_event_map_save' ) ) return;

		if ( isset( $_POST['rmm_event_dagr_preset'] ) ) {
			$preset_id = intval( $_POST['rmm_event_dagr_preset'] );
			if ( empty( $preset_id ) ) {
				delete_post_meta( $post_id, '_rmm_event_dagr_preset' );
			} else {
				update_post_meta( $post_id, '_rmm_event_dagr_preset', $preset_id );
			}
		}
	}

	/**
	 * Shortcode [rmm_event_map] — Mapa DAGR del evento configurado via preset
	 * Atributos: id (post_id), height
	 */
	public function render_event_map( $atts ) {
		$atts = shortcode_atts( array(
			'id'     => get_the_ID(),
			'height' => '600px',
		), $atts, 'rmm_event_map' );

		$post_id = intval( $atts['id'] );

		// Buscar preset asignado
		$preset_id = get_post_meta( $post_id, '_rmm_event_dagr_preset', true );

		// Fallback: buscar en rmm_mission_maps si no hay meta
		if ( empty( $preset_id ) ) {
			$post_type = get_post_type( $post_id );
			if ( in_array( $post_type, array( 'misiones', 'eventos_partidas' ) ) ) {
				global $wpdb;
				$map_row = $wpdb->get_row( $wpdb->prepare(
					"SELECT preset_id FROM {$wpdb->prefix}rmm_mission_maps WHERE post_id = %d AND enabled = 1", $post_id
				) );
				if ( $map_row && ! empty( $map_row->preset_id ) ) $preset_id = $map_row->preset_id;
			}
		}

		if ( empty( $preset_id ) ) {
			return '<div style="background:#0d1117;border:1px solid #21262d;border-radius:8px;padding:24px;text-align:center;color:#8b949e;font-family:Inter,sans-serif;">🗺️ No hay mapa interactivo ni MicroDAGR asignado</div>';
		}

		// Delegar al mapa tactico usando el preset ID (carga mapa + markers + positions)
		$dagr = new RMM_DAGR_Handler();
		return $dagr->render_tactical_map( array(
			'id'     => intval( $preset_id ),
			'height' => $atts['height'],
		) );
	}
}

// Initialize
new RMM_Mission_Map_Handler();
