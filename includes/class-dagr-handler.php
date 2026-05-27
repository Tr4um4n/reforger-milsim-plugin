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
		add_action( 'admin_menu', array( $this, 'register_admin_page' ) );
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
				'tiles_path' => 'https://reforger.recoil.org/everon-d012/LODS/{z}/{x}/{y}/tile.jpg',
				'scale_factor' => 0.08, 'edge_offset' => 50,
				'min_x' => 0, 'min_y' => 0, 'max_x' => 12800, 'max_y' => 12800, 'max_zoom' => 5,
			),
			array(
				'map_name' => 'arland', 'display_name' => 'Arland',
				'tiles_path' => 'https://reforger.recoil.org/arland/LODS/{z}/{x}/{y}/tile.jpg',
				'scale_factor' => 0.08, 'edge_offset' => 50,
				'min_x' => 0, 'min_y' => 0, 'max_x' => 4000, 'max_y' => 4000, 'max_zoom' => 4,
			),
		);
		foreach ( $defaults as $map ) {
			$wpdb->replace( $table, $map );
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
		$atts = shortcode_atts( array( 'height' => '600px', 'map' => '' ), $atts );

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

		// Si el path es local, ver si existe; si no, usar CDN
		$local_path = WP_CONTENT_DIR . '/uploads/maps/' . $map_name . '/LODS/0/0/0/tile.jpg';
		if ( empty( $map_config->tiles_path ) && ! file_exists( $local_path ) ) {
			$cdn_fallbacks = array(
				'everon' => 'https://reforger.recoil.org/everon-d012/LODS/{z}/{x}/{y}/tile.jpg',
				'arland' => 'https://reforger.recoil.org/arland/LODS/{z}/{x}/{y}/tile.jpg',
			);
			if ( isset( $cdn_fallbacks[ $map_name ] ) ) {
				$tiles_url = $cdn_fallbacks[ $map_name ];
			}
		}

		$uid = 'dagr-map-' . uniqid();

		wp_enqueue_style( 'leaflet-css', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css', array(), '1.9.4' );
		wp_enqueue_script( 'leaflet-js', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', array(), '1.9.4', true );

		ob_start();
		?>
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
			var tilesUrl = '<?php echo esc_url( $tiles_url ); ?>';

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

			// CRS personalizado
			L.CRS.CustomSimple = L.Util.extend({}, L.CRS, {
				projection: L.Projection.LonLat,
				transformation: new L.Transformation(scale, 0, -scale, 0),
				scale: function(z) { return Math.pow(2, z); },
				zoom: function(s) { return Math.log(s) / Math.LN2; },
				distance: function(a, b) { return Math.sqrt(Math.pow(b.lng-a.lng,2) + Math.pow(b.lat-a.lat,2)); },
				infinite: true
			});

			// TileLayer con Y invertido
			L.TileLayer.InvertedY = L.TileLayer.extend({
				getTileUrl: function(c) {
					c.y = -(c.y + 1);
					return L.TileLayer.prototype.getTileUrl.call(this, c);
				}
			});

			var map = L.map(container, {
				crs: L.CRS.CustomSimple,
				zoom: 3,
				center: [(minY+maxY)/2 + edgeOffset, (minX+maxX)/2 + edgeOffset],
				zoomControl: true,
				attributionControl: false
			});

			new L.TileLayer.InvertedY(tilesUrl, {
				maxZoom: maxZoom,
				minZoom: 0,
				zoomReverse: true,
				bounds: L.latLngBounds(
					[minY + edgeOffset, minX + edgeOffset],
					[maxY + edgeOffset, maxX + edgeOffset]
				)
			}).addTo(map);

			// Conversion de coordenadas de juego a LatLng
			function gameToLatLng(x, y) {
				return L.latLng([y + edgeOffset, x + edgeOffset]);
			}

			// Marcadores de jugadores
			var playerMarkers = {};
			var playerIcons = {};

			function updatePositions() {
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
							var tip = m.label ? (m.label + (m.author ? ' - ' + m.author : '')) : '';
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
			setInterval(updateMapMarkers, 15000);
		})();
		</script>
		<?php
		return ob_get_clean();
	}

	public function register_admin_page() {
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
		$table = $wpdb->prefix . 'rmm_dagr_maps';

		if ( isset( $_POST['rmm_save_dagr_map'] ) ) {
			$wpdb->replace( $table, array(
				'map_name'      => sanitize_text_field( $_POST['map_name'] ),
				'display_name'  => sanitize_text_field( $_POST['display_name'] ),
				'tiles_path'    => esc_url_raw( $_POST['tiles_path'] ),
				'min_x'         => floatval( $_POST['min_x'] ),
				'min_y'         => floatval( $_POST['min_y'] ),
				'max_x'         => floatval( $_POST['max_x'] ),
				'max_y'         => floatval( $_POST['max_y'] ),
				'scale_factor'  => floatval( $_POST['scale_factor'] ),
				'edge_offset'   => intval( $_POST['edge_offset'] ),
				'max_zoom'      => intval( $_POST['max_zoom'] ),
				'enabled'       => isset( $_POST['enabled'] ) ? 1 : 0,
			) );
			echo '<div class="notice notice-success"><p>Mapa guardado.</p></div>';
		}

		$maps = $wpdb->get_results( "SELECT * FROM $table ORDER BY display_name" );
		?>
		<div class="wrap">
			<h1>🗺️ Mapas DAGR</h1>
			<p>Configura los mapas disponibles para el sistema DAGR. Los tiles deben estar en <code>wp-content/uploads/maps/{map_name}/LODS/{z}/{x}/{y}/tile.jpg</code></p>

			<table class="widefat" style="margin-bottom:20px;">
				<thead><tr><th>Mapa</th><th>Tiles</th><th>Bounds</th><th>Zoom</th><th>Activo</th></tr></thead>
				<tbody>
				<?php foreach ( $maps as $m ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $m->display_name ); ?></strong><br><code><?php echo esc_html( $m->map_name ); ?></code></td>
						<td style="font-size:0.8rem;"><?php echo $m->tiles_path ? esc_html( $m->tiles_path ) : '<em>por defecto</em>'; ?></td>
						<td style="font-size:0.8rem;">X: <?php echo $m->min_x; ?>-<?php echo $m->max_x; ?><br>Y: <?php echo $m->min_y; ?>-<?php echo $m->max_y; ?></td>
						<td><?php echo intval( $m->max_zoom ); ?></td>
						<td><?php echo $m->enabled ? '✅' : '❌'; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<h2>Insertar/Editar Mapa</h2>
			<form method="post">
				<table class="form-table">
					<tr><th>Nombre clave</th><td><input name="map_name" required placeholder="everon"></td></tr>
					<tr><th>Nombre visible</th><td><input name="display_name" placeholder="Everon"></td></tr>
					<tr><th>Ruta tiles (opcional)</th><td><input name="tiles_path" style="width:100%" placeholder="Dejar vacio para ruta por defecto"></td></tr>
					<tr><th>Bounds</th><td>X min: <input name="min_x" type="number" value="0" style="width:80px"> max: <input name="max_x" type="number" value="12800" style="width:80px"> Y min: <input name="min_y" type="number" value="0" style="width:80px"> max: <input name="max_y" type="number" value="12800" style="width:80px"></td></tr>
					<tr><th>Scale / Offset / Zoom</th><td>Scale: <input name="scale_factor" type="number" step="0.001" value="0.08" style="width:80px"> Offset: <input name="edge_offset" type="number" value="50" style="width:80px"> Max zoom: <input name="max_zoom" type="number" value="5" style="width:80px"></td></tr>
					<tr><th>Activo</th><td><input type="checkbox" name="enabled" value="1" checked></td></tr>
				</table>
				<button type="submit" name="rmm_save_dagr_map" class="button button-primary">Guardar Mapa</button>
			</form>
		</div>
		<?php
	}
}
