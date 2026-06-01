<?php
/**
 * DAGR Handler — Mapa Tactico en tiempo real
 * @package ReforgerMilsimManagement
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class RMM_DAGR_Handler {

	public function __construct() {
		add_shortcode( 'rmm_tactical_map', array( $this, 'render_tactical_map' ) );
		add_action( 'init', array( $this, 'ensure_table' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_endpoints' ) );
		add_action( 'admin_menu', array( $this, 'register_admin_pages' ) );
		add_action( 'admin_init', array( $this, 'handle_map_actions' ) );

		// Simulación cron
		add_filter( 'cron_schedules', array( $this, 'add_cron_intervals' ) );
		add_action( 'rmm_simulate_tick', array( $this, 'run_simulation_tick' ) );
	}

	public function ensure_table() {
		global $wpdb;
		$table = $wpdb->prefix . 'rmm_dagr_maps';
		$exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) );
		if ( $exists !== $table ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			RMM_DB_Handler::create_tables();
		}
		$this->insert_default_maps();
	}

	private function insert_default_maps() {
		global $wpdb;
		$table = $wpdb->prefix . 'rmm_dagr_maps';
		$defaults = array(
			array(
				'map_name' => 'everon', 'display_name' => 'Everon',
				//'tiles_path' => 'https://reforger.recoil.org/map-tiles/everon/{z}/{x}/{y}/tile.jpg',
				'tiles_path' => '../mapas/mapa_everon/{z}/{x}/{y}/tile.jpg',
				'scale_factor' => 0.08, 'edge_offset' => 50,
				'min_x' => 0, 'min_y' => 0, 'max_x' => 12800, 'max_y' => 12800, 'max_zoom' => 5,
			),
			array(
				'map_name' => 'arland', 'display_name' => 'Arland',
				//'tiles_path' => 'https://reforger.recoil.org/arland/LODS/{z}/{x}/{y}/tile.jpg',
				'tiles_path' => '../mapas/mapa_arland/{z}/{x}/{y}/tile.jpg',
				'scale_factor' => 0.08, 'edge_offset' => 50,
				'min_x' => 0, 'min_y' => 0, 'max_x' => 4096, 'max_y' => 4096, 'max_zoom' => 4,
			),
			array(
				'map_name' => 'cain', 'display_name' => 'Kolguyev',
				//'tiles_path' => 'https://reforger.recoil.org/map-tiles/cain/{z}/{x}/{y}/tile.jpg',
				'tiles_path' => '../mapas/mapa_cain/{z}/{x}/{y}/tile.jpg',
				'scale_factor' => 0.08, 'edge_offset' => 50,
				'min_x' => 0, 'min_y' => 0, 'max_x' => 12800, 'max_y' => 12800, 'max_zoom' => 5,
			),
		);
		foreach ( $defaults as $map ) {
					$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE map_name = %s", $map['map_name'] ) );
					if ( ! $exists ) {
						$wpdb->insert( $table, $map );
					}
				}
	}

	/**
	 * Handle Map CRUD actions from admin
	 */
	public function handle_map_actions() {
		global $wpdb;
		$table = $wpdb->prefix . 'rmm_dagr_maps';

		// Save / Update map
		if ( isset( $_POST['rmm_dagr_map_save'] ) && check_admin_referer( 'rmm_dagr_map_nonce' ) ) {
			$id = intval( $_POST['map_id'] );
			$data = array(
				'map_name'     => sanitize_key( $_POST['map_name'] ),
				'display_name' => sanitize_text_field( $_POST['display_name'] ),
				'tiles_path'   => sanitize_text_field( wp_unslash( $_POST['tiles_path'] ) ),
				'scale_factor' => floatval( $_POST['scale_factor'] ),
				'edge_offset'  => intval( $_POST['edge_offset'] ),
				'min_x'        => intval( $_POST['min_x'] ),
				'min_y'        => intval( $_POST['min_y'] ),
				'max_x'        => intval( $_POST['max_x'] ),
				'max_y'        => intval( $_POST['max_y'] ),
				'max_zoom'     => intval( $_POST['max_zoom'] ),
				'enabled'      => isset( $_POST['enabled'] ) ? 1 : 0,
			);

			if ( $id > 0 ) {
				$wpdb->update( $table, $data, array( 'id' => $id ) );
			} else {
				$wpdb->insert( $table, $data );
			}
			wp_redirect( admin_url( 'admin.php?page=rmm-dagr-maps&tab=maps&saved=1' ) );
			exit;
		}

		// Delete map
		if ( isset( $_GET['map_delete'] ) && check_admin_referer( 'rmm_dagr_map_delete' ) ) {
			$id = intval( $_GET['map_delete'] );
			$wpdb->delete( $table, array( 'id' => $id ) );
			wp_redirect( admin_url( 'admin.php?page=rmm-dagr-maps&tab=maps&deleted=1' ) );
			exit;
		}

		// Toggle enabled
		if ( isset( $_GET['map_toggle'] ) && check_admin_referer( 'rmm_dagr_map_toggle' ) ) {
			$id = intval( $_GET['map_toggle'] );
			$current = $wpdb->get_var( $wpdb->prepare( "SELECT enabled FROM $table WHERE id = %d", $id ) );
			$wpdb->update( $table, array( 'enabled' => $current ? 0 : 1 ), array( 'id' => $id ) );
			wp_redirect( admin_url( 'admin.php?page=rmm-dagr-maps&tab=maps' ) );
			exit;
		}
	}

	public function register_rest_endpoints() {
		register_rest_route( 'clan/v1', '/dagr/positions', array(
			'methods'  => 'GET',
			'callback' => array( $this, 'get_positions' ),
			'permission_callback' => '__return_true',
		));
		register_rest_route( 'clan/v1', '/dagr/markers', array(
			'methods'  => 'GET',
			'callback' => array( $this, 'get_markers' ),
			'permission_callback' => '__return_true',
		));
		register_rest_route( 'clan/v1', '/dagr/markers', array(
			'methods'  => 'POST',
			'callback' => array( $this, 'receive_markers' ),
			'permission_callback' => '__return_true',
		));
	}

	public function get_positions( $request ) {
		$map = sanitize_text_field( $request->get_param( 'map' ) ?: '' );
		$players = array();

		$users = get_users( array( 'number' => 100 ) );
		foreach ( $users as $user ) {
			$px = get_user_meta( $user->ID, 'rmm_pos_x', true );
			$py = get_user_meta( $user->ID, 'rmm_pos_y', true );
			$pm = get_user_meta( $user->ID, 'rmm_map', true );

			if ( $px === '' || $py === '' ) continue;
			if ( $map && $pm && $pm !== $map ) continue;

			$players[] = array(
				'id'       => $user->ID,
				'name'     => $user->display_name,
				'pos_x'    => floatval( $px ),
				'pos_y'    => floatval( $py ),
				'pos_z'    => floatval( get_user_meta( $user->ID, 'rmm_pos_z', true ) ?: 0 ),
				'heading'  => floatval( get_user_meta( $user->ID, 'rmm_heading', true ) ?: 0 ),
				'steamid'  => get_user_meta( $user->ID, 'steamid_64', true ),
			);
		}

		return rest_ensure_response( array( 'players' => $players, 'count' => count( $players ) ) );
	}

	public function get_markers( $request ) {
		$map = sanitize_text_field( $request->get_param( 'map' ) ?: '' );
		$key = 'dagr_markers_' . ( $map ?: 'all' );
		$markers = get_transient( $key );
		if ( ! is_array( $markers ) ) $markers = array();
		return rest_ensure_response( array( 'markers' => $markers, 'count' => count( $markers ) ) );
	}

	public function receive_markers( $request ) {
		$body = $request->get_body();
		$data = json_decode( $body, true );
		if ( ! $data ) {
			return new WP_REST_Response( array( 'error' => 'JSON invalido' ), 400 );
		}

		$map = sanitize_text_field( $data['map'] ?? '' );
		$markers = $data['markers'] ?? array();
		if ( empty( $markers ) ) {
			return new WP_REST_Response( array( 'error' => 'No markers provided' ), 400 );
		}

		// Validar y limpiar cada marker
		$clean = array();
		foreach ( $markers as $m ) {
			$clean[] = array(
				'id'     => sanitize_text_field( $m['id'] ?? uniqid('m') ),
				'type'   => sanitize_text_field( $m['type'] ?? 'marker' ),
				'label'  => sanitize_text_field( $m['label'] ?? '' ),
				'pos_x'  => floatval( $m['pos_x'] ?? 0 ),
				'pos_y'  => floatval( $m['pos_y'] ?? 0 ),
				'color'  => sanitize_text_field( $m['color'] ?? '#d2a850' ),
				'author' => sanitize_text_field( $m['author'] ?? '' ),
				'time'   => current_time( 'mysql' ),
			);
		}

		$key = 'dagr_markers_' . ( $map ?: 'all' );
		set_transient( $key, $clean, 3600 ); // 1 hora

		return rest_ensure_response( array( 'success' => true, 'saved' => count( $clean ) ) );
	}

	public function render_tactical_map( $atts ) {
		global $wpdb;
		$atts = shortcode_atts( array( 'height' => '600px', 'map' => '', 'markers' => '', 'positions' => '', 'id' => '' ), $atts );

		// Si hay ID, cargar preset de BD
				$from_preset = false;
				if ( ! empty( $atts['id'] ) ) {
					$presets_table = $wpdb->prefix . 'rmm_dagr_presets';
					$preset = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $presets_table WHERE id = %d", intval( $atts['id'] ) ) );
					if ( $preset ) {
						$atts['map']       = $preset->map_name;
						$atts['markers']   = $preset->markers;
						$atts['positions'] = $preset->positions;
						$atts['height']    = $preset->height;
						$from_preset = true;
					}
				}

				// Parsear markers/positions (BD = JSON plano, shortcode attr = base64)
				if ( $from_preset ) {
					$markers_raw = ! empty( $atts['markers'] ) ? $atts['markers'] : '';
					$positions_raw = ! empty( $atts['positions'] ) ? $atts['positions'] : '';
				} else {
					$markers_raw = ! empty( $atts['markers'] ) ? base64_decode( $atts['markers'] ) : '';
					$positions_raw = ! empty( $atts['positions'] ) ? base64_decode( $atts['positions'] ) : '';
				}
		$static_markers = $markers_raw ? json_decode( $markers_raw, true ) : array();
		$static_positions = $positions_raw ? json_decode( $positions_raw, true ) : array();
		if ( ! is_array( $static_markers ) ) $static_markers = array();
		if ( ! is_array( $static_positions ) ) $static_positions = array();
		$has_static_data = ! empty( $static_markers ) || ! empty( $static_positions );

		$map_name = sanitize_text_field( $atts['map'] );
		$active = null;

		// Si no se especifica mapa, buscar sesion activa
		if ( empty( $map_name ) ) {
			$sessions = $wpdb->prefix . 'rmm_match_sessions';
			$active = $wpdb->get_row( "SELECT * FROM $sessions WHERE ended_at IS NULL ORDER BY started_at DESC LIMIT 1" );

			if ( ! $active ) {
				return '<div style="background:#0d1117;border:1px solid #21262d;border-radius:8px;padding:24px;text-align:center;color:#8b949e;font-family:Inter,sans-serif;">Sin señal GPS: No hay partida activa. Usa <code>[rmm_tactical_map map="everon"]</code></div>';
			}

			$map_name = ! empty( $active->scenario_name ) ? sanitize_title( $active->scenario_name ) : '';
			if ( ! $map_name && ! empty( $active->scenario_id ) ) {
				$map_name = sanitize_title( basename( $active->scenario_id ) );
			}
		}

		// Buscar mapa en la BD de DAGR
		$dagr_table = $wpdb->prefix . 'rmm_dagr_maps';
		$map_config = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM $dagr_table WHERE enabled = 1 AND map_name = %s", $map_name
		) );

		if ( ! $map_config ) {
			$display = $active ? ( $active->scenario_name ?: $map_name ) : $map_name;
			return '<div style="background:#0d1117;border:1px solid #21262d;border-radius:8px;padding:24px;text-align:center;color:#8b949e;font-family:Inter,sans-serif;">Sin señal GPS: Mapa no reconocido <strong>' . esc_html( $display ) . '</strong></div>';
		}

		$tiles_url = ! empty( $map_config->tiles_path )
			? $map_config->tiles_path
			: content_url( 'uploads/maps/' . $map_name . '/LODS/{z}/{x}/{y}/tile.jpg' );

		// Convertir paths relativos a URL absoluta
		if ( strpos( $tiles_url, 'http' ) !== 0 ) {
			$clean = preg_replace( '#^(\.\./)+#', '', $tiles_url );
			$tiles_url = site_url( '/' . ltrim( $clean, '/' ) );
		}

		// Si el path es local, ver si existe; si no, usar CDN
		$local_path = WP_CONTENT_DIR . '/uploads/maps/' . $map_name . '/LODS/4/4/4/tile.jpg';
		$local_fallback = content_url( 'uploads/maps/' . $map_name . '/LODS/{z}/{x}/{y}/tile.jpg' );
		if ( empty( $map_config->tiles_path ) ) {
			// Si no hay path configurado, usar local si existe, sino CDN
			if ( file_exists( $local_path ) ) {
				$tiles_url = $local_fallback;
			} else {
				$cdn_fallbacks = array(
					'everon' => 'https://reforger.recoil.org/map-tiles/everon/{z}/{x}/{y}/tile.jpg',
					'arland' => 'https://reforger.recoil.org/map-tiles/arland/{z}/{x}/{y}/tile.jpg',
				);
				if ( isset( $cdn_fallbacks[ $map_name ] ) ) {
					$tiles_url = $cdn_fallbacks[ $map_name ];
				}
			}
		}

		$uid = 'dagr-map-' . uniqid();

		wp_enqueue_style( 'leaflet-css', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css', array(), '1.9.4' );

		ob_start();
		?>
		<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css">
		<script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js"></script>
		<div id="<?php echo $uid; ?>" style="width:100%;height:<?php echo esc_attr( $atts['height'] ); ?>;background:#0d1117;border:1px solid #21262d;border-radius:8px;position:relative;">
			<div class="dagr-mode-toggle" style="position:absolute;top:10px;right:10px;z-index:1000;display:flex;gap:4px;">
				<button class="dagr-mode-btn active" data-mode="personal" style="background:#1a1d21;color:#849b4c;border:1px solid #849b4c;padding:6px 12px;border-radius:4px;cursor:pointer;font-size:0.7rem;font-weight:700;text-transform:uppercase;font-family:Inter,sans-serif;">👤 Yo</button>
				<button class="dagr-mode-btn" data-mode="global" style="background:#1a1d21;color:#555;border:1px solid #333;padding:6px 12px;border-radius:4px;cursor:pointer;font-size:0.7rem;font-weight:700;text-transform:uppercase;font-family:Inter,sans-serif;">🌍 Global</button>
			</div>
		</div>
		<script>
		(function() {
			var container = document.getElementById('<?php echo $uid; ?>');
			if (!container || typeof L === 'undefined') return;

			var scale = <?php echo floatval( $map_config->scale_factor ); ?>;
			var edgeOffset = <?php echo intval( $map_config->edge_offset ); ?>;
			var maxZoom = <?php echo intval( $map_config->max_zoom ); ?>;
			var minX = <?php echo floatval( $map_config->min_x ); ?>;
			var minY = <?php echo floatval( $map_config->min_y ); ?>;
			var maxX = <?php echo floatval( $map_config->max_x ); ?>;
			var maxY = <?php echo floatval( $map_config->max_y ); ?>;
			var mode = localStorage.getItem('dagr_mode') || 'personal';
			var currentUserId = <?php echo get_current_user_id() ?: 0; ?>;
			var tilesUrl = '<?php echo esc_js( $tiles_url ); ?>';

			/* DATOS ESTATICOS via shortcode */
			var staticMarkers = <?php echo json_encode( $static_markers ); ?>;
			var staticPositions = <?php echo json_encode( $static_positions ); ?>;
			var hasStaticData = <?php echo $has_static_data ? 'true' : 'false'; ?>;

			// Toggle buttons
			var toggleContainer = container.querySelector('.dagr-mode-toggle');
			toggleContainer.querySelectorAll('.dagr-mode-btn').forEach(function(btn) {
				var m = btn.dataset.mode;
				if (m === mode) {
					btn.classList.add('active');
					btn.style.color = '#849b4c';
					btn.style.borderColor = '#849b4c';
				} else {
					btn.classList.remove('active');
					btn.style.color = '#555';
					btn.style.borderColor = '#333';
				}
			});

			container.addEventListener('click', function(e) {
				var btn = e.target.closest('.dagr-mode-btn');
				if (!btn) return;
				mode = btn.dataset.mode;
				localStorage.setItem('dagr_mode', mode);
				toggleContainer.querySelectorAll('.dagr-mode-btn').forEach(function(b) {
					b.classList.remove('active');
					b.style.color = '#555';
					b.style.borderColor = '#333';
				});
				btn.classList.add('active');
				btn.style.color = '#849b4c';
				btn.style.borderColor = '#849b4c';
				updatePositions();
			});

			// CRS personalizado de EnfusionMapMaker (1/12.5 scale, InvertedY, zoomReverse)
			L.CRS.CustomSimple = L.Util.extend({}, L.CRS, {
				projection: L.Projection.LonLat,
				transformation: new L.Transformation(1/12.5, 0, -1/12.5, 0),
				scale: function(z) { return Math.pow(2, z); },
				zoom: function(s) { return Math.log(s) / Math.LN2; },
				distance: function(a, b) { return Math.sqrt(Math.pow(b.lng-a.lng,2) + Math.pow(b.lat-a.lat,2)); },
				infinite: true
			});

			// Invertir Y para tiles LODS (igual que el juego)
			L.TileLayer.InvertedY = L.TileLayer.extend({
				getTileUrl: function(c) {
					c.y = -(c.y + 1);
					return L.TileLayer.prototype.getTileUrl.call(this, c);
				}
			});

			// Bounds del mapa Everon (0,0 a 12800,12800 + offset 50)
			var bounds = L.latLngBounds(
				L.latLng([0 + edgeOffset, 0 + edgeOffset]),
				L.latLng([maxY + edgeOffset, maxX + edgeOffset])
			);

			var map = L.map(container, {
				crs: L.CRS.CustomSimple,
				zoom: 3,
				center: bounds.getCenter(),
				maxZoom: maxZoom,
				minZoom: 0,
				zoomControl: true,
				attributionControl: false
			});
			container._rmmMap = map; // Exponer para MicroDAGR mobile

			new L.TileLayer.InvertedY(tilesUrl, {
				maxZoom: maxZoom,
				minZoom: 0,
				zoomReverse: true,
				bounds: bounds,
				errorTileUrl: ''
			}).addTo(map);

			// Conversion de coordenadas de juego (EnfusionMapMaker: +50 offset)
			function gameToLatLng(x, y) {
				return L.latLng([y + edgeOffset, x + edgeOffset]);
			}

			// Marcadores de jugadores
						var playerMarkers = {};
						var playerIcons = {};

						// ── Coordenadas en el puntero ──
						var coordDiv = document.createElement('div');
						coordDiv.style.cssText = 'position:absolute;bottom:8px;left:8px;z-index:1000;background:rgba(0,0,0,0.75);color:#CFDC35;font-family:monospace;font-size:0.7rem;padding:4px 10px;border-radius:4px;pointer-events:none;letter-spacing:0.05em;';
						coordDiv.textContent = 'X:---- Y:----';
						container.appendChild(coordDiv);

						map.on('mousemove', function(e) {
										var x = Math.round(e.latlng.lng - edgeOffset);
										var y = Math.round(e.latlng.lat - edgeOffset);
										coordDiv.textContent = 'X:' + padCoord(x) + ' Y:' + padCoord(y);
									});

									function padCoord(v) {
										var m = Math.max(0, Math.min(Math.round(v), 12800));
										// Convertir metros a grid: quitar ceros finales
										var s = String(m);
										if (m % 1000 === 0) return String(m / 1000).padStart(1, '0'); // 4000 → 4 (1-2 dígitos)
										if (m % 100 === 0) return String(m / 100).padStart(3, '0');   // 4800 → 048 (3 dígitos)
										if (m % 10 === 0) return String(m / 10).padStart(4, '0');    // 4890 → 0489 (4 dígitos)
										return s.padStart(5, '0');                                      // 4895 → 04895 (5 dígitos)
									}

			function updatePositions() {
				if ( hasStaticData ) {
					staticPositions.forEach(function(p) {
						var latlng = gameToLatLng(p.pos_x, p.pos_y);
						var color = p.color || '#58a6ff';
						var size = '10px';
						var icon = L.divIcon({
							className: 'dagr-player-marker',
							html: '<div style="width:'+size+';height:'+size+';background:'+color+';border:2px solid #fff;border-radius:50%;box-shadow:0 0 8px ' + color + ';" title="' + (p.name||'') + '"></div>',
							iconSize: [14,14],
							iconAnchor: [7,7]
						});
						L.marker(latlng, { icon: icon }).addTo(map).bindTooltip(p.name||'', { direction:'top', offset:[0,-8] });
					});
					return;
				}
				var url = '<?php echo rest_url( 'clan/v1/dagr/positions' ); ?>?map=<?php echo urlencode( $map_name ); ?>';
				fetch(url).then(function(r) { return r.json(); }).then(function(data) {
					if (!data.players) return;
					var seen = {};

					data.players.forEach(function(p) {
						if (mode === 'personal' && p.id != currentUserId) return;
						seen[p.id] = true;
						var latlng = gameToLatLng(p.pos_x, p.pos_y);
						var isMe = (p.id == currentUserId);

						if (playerMarkers[p.id]) {
							playerMarkers[p.id].setLatLng(latlng);
						} else {
							var color = isMe ? '#849b4c' : '#58a6ff';
							var size = isMe ? '14px' : '10px';
							var icon = L.divIcon({
								className: 'dagr-player-marker',
								html: '<div style="width:' + size + ';height:' + size + ';background:' + color + ';border:2px solid #fff;border-radius:50%;box-shadow:0 0 8px ' + color + ';" title="' + p.name + '"></div>',
								iconSize: [parseInt(size)+4, parseInt(size)+4],
								iconAnchor: [(parseInt(size)+4)/2, (parseInt(size)+4)/2]
							});
							playerMarkers[p.id] = L.marker(latlng, { icon: icon }).addTo(map);
							playerMarkers[p.id].bindTooltip(p.name, { direction: 'top', offset: [0, -8] });
						}
					});

					// Eliminar marcadores de jugadores que ya no estan
					for (var id in playerMarkers) {
						if (!seen[id]) {
							map.removeLayer(playerMarkers[id]);
							delete playerMarkers[id];
						}
					}
				});
			}

			updatePositions();
			setInterval(updatePositions, 10000);

			// === Marcadores de mapa (objetivos, POIs) ===
			var mapMarkers = {};
			var markerColors = {
				'objective': '#22c55e',
				'completed': '#d2a850',
				'danger': '#ef4444',
				'info': '#58a6ff',
				'marker': '#a371f7'
			};
			var markerIcons = {
				'objective': '<div style="width:16px;height:16px;background:#22c55e;border:2px solid #fff;border-radius:3px;transform:rotate(45deg);box-shadow:0 0 8px rgba(34,197,94,0.5);"></div>',
				'completed': '<div style="width:14px;height:14px;background:#d2a850;border:2px solid #fff;border-radius:50%;box-shadow:0 0 8px rgba(210,168,80,0.5);"></div>',
				'danger': '<div style="width:0;height:0;border-left:8px solid transparent;border-right:8px solid transparent;border-bottom:16px solid #ef4444;filter:drop-shadow(0 0 4px rgba(239,68,68,0.5));"></div>',
				'info': '<div style="width:14px;height:14px;background:#58a6ff;border:2px solid #fff;border-radius:2px;box-shadow:0 0 8px rgba(88,166,255,0.5);"></div>',
				'marker': '<div style="width:12px;height:12px;background:#a371f7;border:2px solid #fff;border-radius:50%;box-shadow:0 0 8px rgba(163,113,247,0.5);"></div>'
			};

			function updateMapMarkers() {
				if ( hasStaticData ) {
					staticMarkers.forEach(function(m) {
						var latlng = gameToLatLng(m.pos_x, m.pos_y);
						var html = markerIcons[m.type] || markerIcons['marker'];
						var icon = L.divIcon({ className: 'dagr-map-marker', html: html, iconSize: [20,20], iconAnchor: [10,10] });
						// Tooltip con coordenadas grid
						var prec = m.precision || 5;
						var gx = prec <= 2 ? Math.round(m.pos_x / 1000) : prec === 3 ? Math.round(m.pos_x / 100) : prec === 4 ? Math.round(m.pos_x / 10) : m.pos_x;
						var gy = prec <= 2 ? Math.round(m.pos_y / 1000) : prec === 3 ? Math.round(m.pos_y / 100) : prec === 4 ? Math.round(m.pos_y / 10) : m.pos_y;
						var gridStr = String(gx).padStart(prec <= 2 ? 2 : prec, '0') + ' ' + String(gy).padStart(prec <= 2 ? 2 : prec, '0');
						var tip = (m.label || m.type) + ' [' + gridStr + ']';
						L.marker(latlng, { icon: icon }).addTo(map).bindTooltip(tip, { direction:'top', offset:[0,-12] });
					});
					return;
				}
				var url = '<?php echo rest_url( 'clan/v1/dagr/markers' ); ?>?map=<?php echo urlencode( $map_name ); ?>';
				fetch(url).then(function(r) { return r.json(); }).then(function(data) {
					if (!data.markers) return;
					var seen = {};

					data.markers.forEach(function(m) {
						seen[m.id] = true;
						var latlng = gameToLatLng(m.pos_x, m.pos_y);

						if (mapMarkers[m.id]) {
							mapMarkers[m.id].setLatLng(latlng);
						} else {
							var html = markerIcons[m.type] || markerIcons['marker'];
							var icon = L.divIcon({
								className: 'dagr-map-marker',
								html: html,
								iconSize: [20, 20],
								iconAnchor: [10, 10]
							});
							mapMarkers[m.id] = L.marker(latlng, { icon: icon }).addTo(map);
								var prec = m.precision || 5;
								var gx = prec <= 2 ? Math.round(m.pos_x / 1000) : prec === 3 ? Math.round(m.pos_x / 100) : prec === 4 ? Math.round(m.pos_x / 10) : m.pos_x;
								var gy = prec <= 2 ? Math.round(m.pos_y / 1000) : prec === 3 ? Math.round(m.pos_y / 100) : prec === 4 ? Math.round(m.pos_y / 10) : m.pos_y;
								var gridStr = String(gx).padStart(prec <= 2 ? 2 : prec, '0') + ' ' + String(gy).padStart(prec <= 2 ? 2 : prec, '0');
								var tip = m.label ? (m.label + (m.author ? ' - ' + m.author : '') + ' [' + gridStr + ']') : '';
								if (tip) mapMarkers[m.id].bindTooltip(tip, { direction: 'top', offset: [0, -12] });
						}
					});

					for (var id in mapMarkers) {
						if (!seen[id]) {
							map.removeLayer(mapMarkers[id]);
							delete mapMarkers[id];
						}
					}
				});
			}

			updateMapMarkers();
			if ( ! hasStaticData ) {
				setInterval(updatePositions, 10000);
				setInterval(updateMapMarkers, 15000);
			}
		})();
		</script>
		<?php
		return ob_get_clean();
	}

	public function register_admin_pages() {
		add_submenu_page(
			'rmm-dashboard',
			__( 'Mapas DAGR', 'reforger-milsim' ),
			__( '🗺️ Mapas DAGR', 'reforger-milsim' ),
			'manage_options',
			'rmm-dagr-maps',
			array( $this, 'render_admin_page' )
		);
	}

	public function render_admin_page() {
			global $wpdb;
			$table = $wpdb->prefix . 'rmm_dagr_presets';

			// Handle save
			if ( isset( $_POST['rmm_dagr_save'] ) && check_admin_referer( 'rmm_dagr_nonce' ) ) {
				$id = intval( $_POST['preset_id'] );
				$data = array(
					'title'     => sanitize_text_field( $_POST['title'] ),
					'map_name'  => sanitize_text_field( $_POST['map_name'] ),
					'markers'   => wp_unslash( $_POST['markers'] ),
					'positions' => wp_unslash( $_POST['positions'] ),
					'height'    => sanitize_text_field( $_POST['height'] ),
				);
				if ( $id > 0 ) {
					$wpdb->update( $table, $data, array( 'id' => $id ) );
				} else {
					$wpdb->insert( $table, $data );
					$id = $wpdb->insert_id;
				}
				echo '<div class="notice notice-success"><p>Mapa guardado. Shortcode: <code>[rmm_tactical_map id="' . $id . '"]</code></p></div>';
			}

			// Handle delete
			if ( isset( $_GET['delete'] ) && wp_verify_nonce( $_GET['_wpnonce'], 'rmm_dagr_delete' ) ) {
				$wpdb->delete( $table, array( 'id' => intval( $_GET['delete'] ) ) );
				echo '<div class="notice notice-success"><p>Mapa eliminado.</p></div>';
			}

			// Load presets
			$presets = $wpdb->get_results( "SELECT * FROM $table ORDER BY updated_at DESC" );

			// Load for edit
			$editing = null;
			if ( isset( $_GET['edit'] ) ) {
				$editing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", intval( $_GET['edit'] ) ) );
			}

			// Parse existing data for builder
			$edit_markers = $editing ? json_decode( $editing->markers, true ) : array(
				array( 'id' => 'obj1', 'type' => 'objective', 'label' => 'Base', 'pos_x' => 5000, 'pos_y' => 3000, 'precision' => 5 )
			);
			$edit_positions = $editing ? json_decode( $editing->positions, true ) : array();

			?>
			<div class="wrap">
				<h1>🗺️ DAGR — Gestión</h1>

				<?php
				$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'presets';
				$tabs = array(
					'maps'       => '🗺️ Mapas',
					'presets'    => '📋 Presets',
					'simulation' => '🔄 Simulación',
				);
				echo '<h2 class="nav-tab-wrapper">';
				foreach ( $tabs as $slug => $label ) {
					$active = ( $tab === $slug ) ? 'nav-tab-active' : '';
					echo '<a href="?page=rmm-dagr-maps&tab=' . esc_attr( $slug ) . '" class="nav-tab ' . $active . '">' . esc_html( $label ) . '</a>';
				}
				echo '</h2>';
				?>

				<?php if ( $tab === 'maps' ) : ?>
					<?php
					$dagr_maps = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}rmm_dagr_maps ORDER BY map_name ASC" );
					$map_editing = null;
					if ( isset( $_GET['map_edit'] ) ) {
						$map_editing = $wpdb->get_row( $wpdb->prepare(
							"SELECT * FROM {$wpdb->prefix}rmm_dagr_maps WHERE id = %d",
							intval( $_GET['map_edit'] )
						) );
					}
					if ( isset( $_GET['saved'] ) ) {
						echo '<div class="notice notice-success is-dismissible"><p>Mapa guardado.</p></div>';
					}
					if ( isset( $_GET['deleted'] ) ) {
						echo '<div class="notice notice-success is-dismissible"><p>Mapa eliminado.</p></div>';
					}
					?>

					<div class="card" style="max-width:100%; padding:20px; margin-bottom:20px;">
						<h2><?php echo $map_editing ? 'Editar' : 'Nuevo'; ?> Mapa</h2>
						<form method="post" id="rmm-dagr-map-form">
							<?php wp_nonce_field( 'rmm_dagr_map_nonce' ); ?>
							<input type="hidden" name="map_id" value="<?php echo $map_editing ? intval( $map_editing->id ) : 0; ?>">

							<table class="form-table">
								<tr><th>ID interno</th><td><input type="text" name="map_name" value="<?php echo $map_editing ? esc_attr( $map_editing->map_name ) : ''; ?>" class="regular-text" required placeholder="everon, arland, cain..."></td></tr>
								<tr><th>Nombre visible</th><td><input type="text" name="display_name" value="<?php echo $map_editing ? esc_attr( $map_editing->display_name ) : ''; ?>" class="regular-text" required placeholder="Everon"></td></tr>
								<tr><th>Tiles URL</th><td><input type="text" name="tiles_path" value="<?php echo $map_editing ? esc_attr( $map_editing->tiles_path ) : ''; ?>" class="large-text" placeholder="../mapas/mapa_everon/{z}/{x}/{y}/tile.jpg"></td></tr>
								<tr><th>Scale Factor</th><td><input type="number" step="0.01" name="scale_factor" value="<?php echo $map_editing ? esc_attr( $map_editing->scale_factor ) : '0.08'; ?>" class="small-text"></td></tr>
								<tr><th>Edge Offset</th><td><input type="number" name="edge_offset" value="<?php echo $map_editing ? esc_attr( $map_editing->edge_offset ) : '50'; ?>" class="small-text"></td></tr>
								<tr><th>Min X</th><td><input type="number" name="min_x" value="<?php echo $map_editing ? esc_attr( $map_editing->min_x ) : '0'; ?>" class="small-text"></td></tr>
								<tr><th>Min Y</th><td><input type="number" name="min_y" value="<?php echo $map_editing ? esc_attr( $map_editing->min_y ) : '0'; ?>" class="small-text"></td></tr>
								<tr><th>Max X</th><td><input type="number" name="max_x" value="<?php echo $map_editing ? esc_attr( $map_editing->max_x ) : ''; ?>" class="small-text" placeholder="12800"></td></tr>
								<tr><th>Max Y</th><td><input type="number" name="max_y" value="<?php echo $map_editing ? esc_attr( $map_editing->max_y ) : ''; ?>" class="small-text" placeholder="12800"></td></tr>
								<tr><th>Max Zoom</th><td><input type="number" name="max_zoom" value="<?php echo $map_editing ? esc_attr( $map_editing->max_zoom ) : '5'; ?>" class="small-text"></td></tr>
								<tr><th>Activo</th><td><label><input type="checkbox" name="enabled" value="1" <?php checked( $map_editing ? $map_editing->enabled : true ); ?>> Visible en el mapa táctico</label></td></tr>
							</table>

							<p class="submit">
								<input type="submit" name="rmm_dagr_map_save" class="button button-primary" value="<?php echo $map_editing ? 'Actualizar' : 'Crear'; ?> Mapa">
								<?php if ( $map_editing ) : ?>
									<a href="?page=rmm-dagr-maps&tab=maps" class="button">Cancelar</a>
								<?php endif; ?>
							</p>
						</form>
					</div>

					<div class="card" style="max-width:100%; padding:20px;">
						<h2>Mapas Configurados</h2>
						<table class="wp-list-table widefat striped">
							<thead><tr><th>ID</th><th>Nombre</th><th>Display</th><th>Tiles</th><th>Dimensiones</th><th>Zoom</th><th>Estado</th><th>Acciones</th></tr></thead>
							<tbody>
							<?php if ( empty( $dagr_maps ) ) : ?>
								<tr><td colspan="8">No hay mapas configurados.</td></tr>
							<?php else : foreach ( $dagr_maps as $m ) : ?>
								<tr>
									<td><?php echo intval( $m->id ); ?></td>
									<td><code><?php echo esc_html( $m->map_name ); ?></code></td>
									<td><?php echo esc_html( $m->display_name ); ?></td>
									<td><code style="font-size:11px;"><?php echo esc_html( substr( $m->tiles_path, 0, 40 ) ); ?>...</code></td>
									<td><?php echo intval( $m->max_x ); ?> × <?php echo intval( $m->max_y ); ?></td>
									<td><?php echo intval( $m->max_zoom ); ?></td>
									<td><?php echo $m->enabled ? '<span style="color:green;">✅ Activo</span>' : '<span style="color:red;">⏸ Inactivo</span>'; ?></td>
									<td>
										<a href="?page=rmm-dagr-maps&tab=maps&map_edit=<?php echo intval( $m->id ); ?>" class="button button-small">Editar</a>
										<a href="?page=rmm-dagr-maps&tab=maps&map_toggle=<?php echo intval( $m->id ); ?>&_wpnonce=<?php echo wp_create_nonce( 'rmm_dagr_map_toggle' ); ?>" class="button button-small"><?php echo $m->enabled ? 'Desactivar' : 'Activar'; ?></a>
										<a href="?page=rmm-dagr-maps&tab=maps&map_delete=<?php echo intval( $m->id ); ?>&_wpnonce=<?php echo wp_create_nonce( 'rmm_dagr_map_delete' ); ?>" class="button button-small" onclick="return confirm('¿Eliminar mapa <?php echo esc_js( $m->display_name ); ?>?')" style="color:#a00;">Eliminar</a>
									</td>
								</tr>
							<?php endforeach; endif; ?>
							</tbody>
						</table>
					</div>

				<?php elseif ( $tab === 'simulation' ) : ?>
					<?php
					// Handle form actions
					if ( isset( $_POST['rmm_sim_save'] ) && check_admin_referer( 'rmm_sim_nonce' ) ) {
						$payload = wp_unslash( $_POST['sim_payload'] );
						json_decode( $payload );
						if ( json_last_error() !== JSON_ERROR_NONE ) {
							echo '<div class="notice notice-error"><p>JSON inválido: ' . esc_html( json_last_error_msg() ) . '</p></div>';
						} else {
							update_option( 'rmm_simulate_payload', $payload, false );
							echo '<div class="notice notice-success"><p>Payload guardado correctamente.</p></div>';
						}
					}

					if ( isset( $_POST['rmm_sim_toggle'] ) && check_admin_referer( 'rmm_sim_nonce' ) ) {
						$active = get_option( 'rmm_simulate_active', false );
						if ( $active ) {
							wp_clear_scheduled_hook( 'rmm_simulate_tick' );
							update_option( 'rmm_simulate_active', false, false );
							echo '<div class="notice notice-warning"><p>⏸ Simulación DESACTIVADA. El cron se ha detenido.</p></div>';
						} else {
							if ( ! wp_next_scheduled( 'rmm_simulate_tick' ) ) {
								wp_schedule_event( time(), 'every_5_seconds', 'rmm_simulate_tick' );
							}
							update_option( 'rmm_simulate_active', true, false );
							echo '<div class="notice notice-success"><p>▶ Simulación ACTIVADA. Jugadores moviéndose en círculos cada 5s.</p></div>';
						}
					}

					$active   = get_option( 'rmm_simulate_active', false );
					$payload  = get_option( 'rmm_simulate_payload', '' );
					$next_cron = wp_next_scheduled( 'rmm_simulate_tick' );

					if ( empty( $payload ) ) {
						$payload = '{
  "session_id": "test_circle",
  "map": "everon",
  "players": [
    {"name": "TRAUMAN", "steamid": "76561198000000001", "x": 5000, "y": 3000, "radius": 300, "omega": 2},
    {"name": "ANTIGRAVITY", "steamid": "76561198000000002", "x": 5200, "y": 3200, "radius": 200, "omega": -3},
    {"name": "ZULU_1", "steamid": "76561198000000003", "x": 6000, "y": 4500, "radius": 400, "omega": 1.5},
    {"name": "ENEMY_1", "steamid": "76561198000000004", "x": 7200, "y": 5800, "radius": 250, "omega": -2},
    {"name": "ENEMY_2", "steamid": "76561198000000005", "x": 7400, "y": 5600, "radius": 350, "omega": 2.5}
  ],
  "markers": [
    {"type": "objective", "label": "Base Alpha", "x": 5000, "y": 3000},
    {"type": "enemy", "label": "Tanque T-72", "x": 7200, "y": 5800}
  ]
}';
					}
					?>

					<div class="card" style="max-width:960px; padding:24px; margin-bottom:20px;">
						<h2>🔄 Simulación de Telemetría</h2>
						<p style="color:#555;font-size:14px;">Genera datos de jugadores moviéndose <strong>en círculos</strong> con rotación continua del heading. Ideal para probar el MicroDAGR sin el addon del juego.</p>

						<div style="display:flex; gap:20px; margin:20px 0; align-items:center; flex-wrap:wrap;">
							<div style="background:<?php echo $active ? '#d4edda' : '#f8d7da'; ?>; padding:12px 24px; border-radius:6px; font-weight:bold; font-size:16px; border:1px solid <?php echo $active ? '#c3e6cb' : '#f5c6cb'; ?>">
								<?php echo $active ? '✅ SIMULACIÓN ACTIVA' : '⏸ SIMULACIÓN INACTIVA'; ?>
							</div>
							<?php if ( $next_cron ) : ?>
							<div style="color:#666; font-size:13px;">
								🕐 Próximo tick: <?php echo human_time_diff( $next_cron ); ?><br>
								⏱ Intervalo: cada 5 segundos
							</div>
							<?php endif; ?>
						</div>

						<form method="post" style="margin-bottom:0;">
							<?php wp_nonce_field( 'rmm_sim_nonce' ); ?>
							<button type="submit" name="rmm_sim_toggle" class="button <?php echo $active ? 'button-secondary' : 'button-primary'; ?>" style="font-size:16px!important; padding:12px 36px!important; height:auto!important;">
								<?php echo $active ? '⏸ DESACTIVAR Simulación' : '▶ ACTIVAR Simulación'; ?>
							</button>
						</form>
					</div>

					<div class="card" style="max-width:960px; padding:24px;">
						<h2>📋 Payload JSON</h2>
						<p style="color:#555; margin-bottom:12px;">
							Define jugadores y marcadores.<br>
							<strong>Cada jugador se mueve en círculo:</strong> <code>x,y</code> = centro, <code>radius</code> = radio (metros), <code>omega</code> = velocidad angular (°/s).<br>
							El <strong>heading</strong> rota continuamente 360°/s. La <strong>velocidad</strong> se calcula como <code>|omega| × radio / 10</code> km/h.
						</p>

						<form method="post">
							<?php wp_nonce_field( 'rmm_sim_nonce' ); ?>
							<textarea name="sim_payload" rows="18" style="width:100%; font-family:'Courier New',monospace; font-size:13px; background:#1a1a2e; color:#e0e0e0; padding:12px; border-radius:4px; border:1px solid #333;"><?php echo esc_textarea( $payload ); ?></textarea>
							<p class="submit">
								<button type="submit" name="rmm_sim_save" class="button button-primary">💾 Guardar Payload</button>
								<a href="?page=rmm-dagr-maps&tab=simulation" class="button">🔄 Recargar</a>
							</p>
						</form>

						<div style="background:#f0f6fc; border-left:4px solid #0073aa; padding:12px; margin-top:16px;">
							<strong>📱 URL para probar en móvil (con auto-simulación):</strong><br>
							<code style="word-break:break-all;font-size:12px;"><?php echo site_url('/microdagr?token=test_microdagr&session=test_circle'); ?></code>
							<br><small style="color:#666;">Al ser sesión <code>test_*</code>, el cliente activa auto-simulación cada 2s sin esperar al cron del servidor.</small>
						</div>
					</div>

				<?php else : ?>

				<?php /* Presets tab — existing content */ ?>
					<form method="post" id="rmm-dagr-form">
						<?php wp_nonce_field( 'rmm_dagr_nonce' ); ?>
						<input type="hidden" name="preset_id" value="<?php echo $editing ? $editing->id : 0; ?>">
						<input type="hidden" name="markers" id="dagr_markers_json" value="<?php echo $editing ? esc_attr($editing->markers) : esc_attr('[{\"id\":\"obj1\",\"type\":\"objective\",\"label\":\"Base\",\"pos_x\":5000,\"pos_y\":3000,\"precision\":5}]'); ?>">
						<input type="hidden" name="positions" id="dagr_positions_json" value="<?php echo $editing ? esc_attr($editing->positions) : '[]'; ?>">

						<table class="form-table">
							<tr><th>Título</th><td><input type="text" name="title" value="<?php echo $editing ? esc_attr($editing->title) : ''; ?>" class="regular-text" required></td></tr>
							<tr><th>Mapa</th><td><select name="map_name" id="dagr_map_select">
								<?php
								$available_maps = $wpdb->get_results( "SELECT map_name, display_name FROM {$wpdb->prefix}rmm_dagr_maps WHERE enabled = 1 ORDER BY display_name ASC" );
								if ( ! empty( $available_maps ) ) {
									foreach ( $available_maps as $am ) {
										echo '<option value="' . esc_attr( $am->map_name ) . '">' . esc_html( $am->display_name ) . '</option>';
									}
								} else {
									echo '<option value="everon">Everon</option>';
								}
								?></select></td></tr>
							<tr><th>Altura</th><td><input type="text" name="height" value="<?php echo $editing ? esc_attr($editing->height) : '600px'; ?>" class="small-text" placeholder="600px"></td></tr>
						</table>

						<!-- Builder de Marcadores -->
						<div style="background:#f9f9f9; border:1px solid #ccd0d4; border-radius:8px; padding:16px; margin:16px 0;">
							<h3 style="margin-top:0;">🎯 Marcadores</h3>
							<table class="widefat" id="dagr-markers-table">
								<thead><tr><th>Tipo</th><th style="width:180px;">Etiqueta</th><th style="width:100px;">X (m)</th><th style="width:100px;">Y (m)</th><th style="width:60px;"></th></tr></thead>
								<tbody></tbody>
							</table>
							<button type="button" class="button" id="dagr-add-marker" style="margin-top:10px;">+ Añadir Marcador</button>
						</div>

						<!-- Builder de Posiciones -->
						<div style="background:#f9f9f9; border:1px solid #ccd0d4; border-radius:8px; padding:16px; margin:16px 0;">
							<h3 style="margin-top:0;">📍 Posiciones</h3>
							<table class="widefat" id="dagr-positions-table">
								<thead><tr><th>Nombre</th><th style="width:100px;">X (m)</th><th style="width:100px;">Y (m)</th><th style="width:100px;">Color</th><th style="width:60px;"></th></tr></thead>
								<tbody></tbody>
							</table>
							<button type="button" class="button" id="dagr-add-position" style="margin-top:10px;">+ Añadir Posición</button>
						</div>

						<p class="submit">
							<button type="submit" name="rmm_dagr_save" class="button button-primary">Guardar</button>
							<?php if ( $editing ) : ?>
								<a href="?page=rmm-dagr-maps" class="button">Cancelar</a>
							<?php endif; ?>
						</p>
					</form>
				</div>

				<div class="card" style="max-width:100%; padding:20px;">
					<h2>Mapas Guardados</h2>
					<table class="wp-list-table widefat striped">
						<thead><tr><th>ID</th><th>Título</th><th>Mapa</th><th>Shortcode</th><th>Acciones</th></tr></thead>
						<tbody>
						<?php if ( empty( $presets ) ) : ?>
							<tr><td colspan="5">No hay mapas guardados.</td></tr>
						<?php else : foreach ( $presets as $p ) : ?>
							<tr>
								<td><?php echo $p->id; ?></td>
								<td><?php echo esc_html( $p->title ); ?></td>
								<td><?php echo esc_html( $p->map_name ); ?></td>
								<td><code>[rmm_tactical_map id="<?php echo $p->id; ?>"]</code>
									<button class="button button-small" onclick="navigator.clipboard.writeText('[rmm_tactical_map id=&quot;<?php echo $p->id; ?>&quot;]')">📋 Copiar</button></td>
								<td>
									<a href="?page=rmm-dagr-maps&tab=presets&edit=<?php echo $p->id; ?>" class="button button-small">Editar</a>
									<a href="?page=rmm-dagr-maps&tab=presets&delete=<?php echo $p->id; ?>&_wpnonce=<?php echo wp_create_nonce('rmm_dagr_delete'); ?>" class="button button-small" onclick="return confirm('¿Eliminar?')">Eliminar</a>
								</td>
							</tr>
						<?php endforeach; endif; ?>
							</tbody>
						</table>
					</div>

					<?php endif; /* end presets tab */ ?>
				</div>

			<script>
			jQuery(function($) {
				var markerTypes = ['objective','completed','danger','info','marker'];
				var markerIcons = { objective:'🟢', completed:'🟡', danger:'🔴', info:'🔵', marker:'🟣' };
				var nextId = 1;

				function updateMarkersJSON() {
					var markers = [];
					$('#dagr-markers-table tbody tr').each(function() {
						var row = $(this);
						var rawX = $.trim(row.find('.m-x').val()) || '0';
						var rawY = $.trim(row.find('.m-y').val()) || '0';
						var cleanX = rawX.replace(/[^0-9]/g, '') || '0';
						var cleanY = rawY.replace(/[^0-9]/g, '') || '0';
						var precX = cleanX.length;
						var precY = cleanY.length;
						var prec = Math.min(Math.max(1, precX), Math.max(1, precY));
						var adjX = cleanX.substring(0, prec) || '0';
						var adjY = cleanY.substring(0, prec) || '0';
						var mx = g2m(adjX);
						var my = g2m(adjY);
						markers.push({
							id: row.data('id') || ('m'+nextId++),
							type: row.find('.m-type').val(),
							label: row.find('.m-label').val(),
							pos_x: mx,
							pos_y: my,
							precision: prec
						});
					});
					var json = JSON.stringify(markers);
					$('#dagr_markers_json').val(json);
					console.log('Markers JSON:', json);
				}

				function updatePositionsJSON() {
					var positions = [];
					$('#dagr-positions-table tbody tr').each(function() {
						var row = $(this);
						var rawX = $.trim(row.find('.p-x').val()) || '0';
						var rawY = $.trim(row.find('.p-y').val()) || '0';
						var cleanX = rawX.replace(/[^0-9]/g, '') || '0';
						var cleanY = rawY.replace(/[^0-9]/g, '') || '0';
						var precX = cleanX.length;
						var precY = cleanY.length;
						var prec = Math.min(Math.max(1, precX), Math.max(1, precY));
						var adjX = cleanX.substring(0, prec) || '0';
						var adjY = cleanY.substring(0, prec) || '0';
						positions.push({
							name: row.find('.p-name').val(),
							pos_x: g2m(adjX),
							pos_y: g2m(adjY),
							color: row.find('.p-color').val(),
							precision: prec
						});
					});
					var json = JSON.stringify(positions);
					$('#dagr_positions_json').val(json);
					console.log('Positions JSON:', json);
				}

				function g2m(v) {
					// Trabajar con string directo para preservar ceros iniciales
					var s = String(v).replace(/[^0-9]/g, '');
					if (s.length === 0) return 0;
					var n = parseInt(s);
					if (s.length <= 2) return Math.min(n * 1000, 12800);
					if (s.length === 3) return Math.min(n * 100, 12800);
					if (s.length === 4) return Math.min(n * 10, 12800);
					return Math.min(n, 12800);
				}

				function m2g(m, prec) {
					if (!prec) prec = 5;
					var v = Math.max(0, Math.min(Math.round(m), 12800));
					// Siempre formatear con EXACTAMENTE `prec` dígitos
					if (prec === 2) return String(Math.round(v / 1000)).padStart(2, '0'); // 8000 → 08
					if (prec === 3) return String(Math.round(v / 100)).padStart(3, '0');   // 8400 → 084
					if (prec === 4) return String(Math.round(v / 10)).padStart(4, '0');    // 8420 → 0842
					return String(v).padStart(5, '0');                                        // 8429 → 08429
				}

				function addMarkerRow(data) {
				data = data || { id: 'm'+(nextId++), type:'info', label:'', pos_x:5000, pos_y:3000, precision:5 };
				var px = m2g(data.pos_x || 5000, data.precision || 5);
				var py = m2g(data.pos_y || 3000, data.precision || 5);
				var options = markerTypes.map(function(t) {
					return '<option value="'+t+'"'+(t===data.type?' selected':'')+'>'+ (markerIcons[t]||'') +' '+t+'</option>';
				}).join('');
				var row = '<tr data-id="'+data.id+'">' +
					'<td><select class="m-type" style="width:100%;">'+options+'</select></td>' +
					'<td><input type="text" class="m-label" value="'+ (data.label||'') +'" placeholder="Label" style="width:100%;"></td>' +
					'<td><input type="text" class="m-x" value="'+ px +'" placeholder="00000-12800" maxlength="5" style="width:100%;" title="Grid 1-5 dígitos"></td>' +
					'<td><input type="text" class="m-y" value="'+ py +'" placeholder="00000-12800" maxlength="5" style="width:100%;" title="Grid 1-5 dígitos"></td>' +
					'<td><button type="button" class="button button-small dagr-remove-row">✕</button></td>' +
				'</tr>';
					$('#dagr-markers-table tbody').append(row);
					updateMarkersJSON();
				}

				function addPositionRow(data) {
				data = data || { name:'', pos_x:5000, pos_y:3000, color:'#58a6ff', precision:5 };
					var px = m2g(data.pos_x || 5000, data.precision || 5);
					var py = m2g(data.pos_y || 3000, data.precision || 5);
					var row = '<tr>' +
						'<td><input type="text" class="p-name" value="'+ (data.name||'') +'" placeholder="Nombre" style="width:100%;"></td>' +
						'<td><input type="text" class="p-x" value="'+ px +'" placeholder="00000-12800" maxlength="5" style="width:100%;" title="Grid 1-5 dígitos"></td>' +
						'<td><input type="text" class="p-y" value="'+ py +'" placeholder="00000-12800" maxlength="5" style="width:100%;" title="Grid 1-5 dígitos"></td>' +
						'<td><input type="color" class="p-color" value="'+ (data.color||'#58a6ff') +'" style="width:50px;"></td>' +
						'<td><button type="button" class="button button-small dagr-remove-row">✕</button></td>' +
					'</tr>';
					$('#dagr-positions-table tbody').append(row);
					updatePositionsJSON();
				}

				// Init from existing data (coordenadas ya en metros, sin conversión)
						var initMarkers = <?php echo json_encode( $edit_markers ); ?>;
				var initPositions = <?php echo json_encode( $edit_positions ); ?>;
				if ( initMarkers && initMarkers.length ) {
					initMarkers.forEach(function(m) { addMarkerRow(m); });
				} else {
					addMarkerRow();
				}
				if ( initPositions && initPositions.length ) {
					initPositions.forEach(function(p) { addPositionRow(p); });
				}

				// Event handlers
				$('#dagr-add-marker').on('click', function() { addMarkerRow(); });
				$('#dagr-add-position').on('click', function() { addPositionRow(); });
				$(document).on('click', '.dagr-remove-row', function() {
					$(this).closest('tr').remove();
					updateMarkersJSON();
					updatePositionsJSON();
				});
				$(document).on('change input', '#dagr-markers-table input, #dagr-markers-table select', updateMarkersJSON);
				$(document).on('change input', '#dagr-positions-table input', updatePositionsJSON);

				// Set map select value
				<?php if ( $editing ) : ?>
				$('#dagr_map_select').val('<?php echo esc_js( $editing->map_name ); ?>');
				<?php endif; ?>
			});
			</script>
			<?php
		}

		/**
		 * Añadir intervalo de 5 segundos al cron de WordPress
		 */
		public function add_cron_intervals( $schedules ) {
			$schedules['every_5_seconds'] = array(
				'interval' => 5,
				'display'  => __( 'Cada 5 segundos', 'reforger-milsim' ),
			);
			return $schedules;
		}

		/**
		 * Ejecutar un tick de simulación desde el cron
		 */
		public function run_simulation_tick() {
			$map_handler = new RMM_Mission_Map_Handler();
			// Usar el session_id del payload guardado
			$payload = get_option( 'rmm_simulate_payload', '' );
			$data    = json_decode( $payload, true );
			$session_id = $data['session_id'] ?? 'test_circle';

			$request = new WP_REST_Request( 'POST', '/clan/v1/mission/simulate' );
			$request->set_body_params( array( 'session_id' => $session_id ) );
			$map_handler->simulate_telemetry_tick( $request );
		}
	}
