<?php
/**
 * Admin Settings & Manual Page
 * @package ReforgerMilsimManagement
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class RMM_Admin_Page {

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'wp_ajax_rmm_get_server_presets', array( $this, 'ajax_get_server_presets' ) );
		add_action( 'wp_ajax_rmm_get_preset_details', array( $this, 'ajax_get_preset_details' ) );
		add_action( 'wp_ajax_rmm_load_preset', array( $this, 'ajax_load_preset' ) );
		add_action( 'wp_ajax_rmm_save_server_preset', array( $this, 'ajax_save_server_preset' ) );
		add_action( 'wp_ajax_rmm_upload_orbatlink_config', array( $this, 'ajax_upload_orbatlink_config' ) );
		add_action( 'wp_ajax_rmm_send_telegram_aviso', array( $this, 'ajax_send_telegram_aviso' ) );
		add_action( 'wp_ajax_rmm_server_power_action', array( $this, 'ajax_server_power_action' ) );
		add_action( 'admin_menu', array( $this, 'restrict_admin_menu' ), 999 );
				add_action( 'admin_bar_menu', array( $this, 'restrict_admin_bar' ), 999 );
				add_action( 'admin_init', array( $this, 'redirect_non_admin_dashboard' ) );
						add_filter( 'ajax_query_attachments_args', array( $this, 'restrict_media_library' ) );
	}

	/**
	 * Enqueue WP Media Library on settings page
	 */
	public function enqueue_admin_assets( $hook ) {
		if ( strpos( $hook, 'rmm-settings' ) === false ) {
			return;
		}
		wp_enqueue_media();
	}


	/**
	 * Register top-level menu and subpages
	 */
	public function register_admin_menu() {
		// Top-level menu
		add_menu_page(
			__( 'Reforger MILSIM', 'reforger-milsim' ),
			__( 'Reforger MILSIM', 'reforger-milsim' ),
			'manage_options',
			'rmm-dashboard',
			array( $this, 'render_manual_page' ),
			'dashicons-shield',
			30
		);

		// Sub: Manual / Shortcodes
		add_submenu_page(
			'rmm-dashboard',
			__( 'Manual & Shortcodes', 'reforger-milsim' ),
			__( '📖 Manual & Shortcodes', 'reforger-milsim' ),
			'manage_options',
			'rmm-dashboard',
			array( $this, 'render_manual_page' )
		);

		// Sub: Gestión de Servidor
		add_submenu_page(
			'rmm-dashboard',
			__( 'Gestión de Servidor', 'reforger-milsim' ),
			__( '🎮 Gestión de Servidor', 'reforger-milsim' ),
			'manage_options',
			'rmm-server-management',
			array( $this, 'render_server_management_page' )
		);

		// Sub: Configuración
		add_submenu_page(
			'rmm-dashboard',
			__( 'Configuración', 'reforger-milsim' ),
			__( '⚙️ Configuración', 'reforger-milsim' ),
			'manage_options',
			'rmm-settings',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Shortcode reference data
	 */
	private function get_shortcodes_reference() {
		return array(
			array(
				'shortcode'   => '[rmm_title]',
				'description' => 'Título limpio del post (misión o evento) sin la fecha añadida automáticamente.',
				'context'     => 'Misiones, Eventos',
				'params'      => '—',
			),
			array(
				'shortcode'   => '[fecha_evento]',
				'description' => 'Fecha formateada del evento con día de la semana. Ej: "Viernes, 15 de mayo a las 20:00".',
				'context'     => 'Eventos',
				'params'      => '—',
			),
			array(
				'shortcode'   => '[rmm_summary]',
				'description' => 'Resumen corto de la misión sincronizado desde la API del Workshop.',
				'context'     => 'Misiones, Eventos (hereda de misión)',
				'params'      => '—',
			),
			array(
				'shortcode'   => '[rmm_description]',
				'description' => 'Descripción / Briefing completo de la misión desde el Workshop.',
				'context'     => 'Misiones, Eventos (hereda de misión)',
				'params'      => '—',
			),
			array(
				'shortcode'   => '[rmm_workshop_url]',
				'description' => 'Enlace/botón al mod en Steam Workshop.',
				'context'     => 'Misiones, Eventos (hereda de misión)',
				'params'      => 'text="Ver en Steam Workshop" class="rmm-workshop-btn"',
			),
			array(
				'shortcode'   => '[rmm_author]',
				'description' => 'Nombre del autor/creador de la misión. Se rellena manualmente en el metabox de Misión.',
				'context'     => 'Misiones, Eventos (hereda de misión)',
				'params'      => '—',
			),
			array(
				'shortcode'   => '[rmm_orbat]',
				'description' => 'Muestra el ORBAT. Por defecto usa estilo MILSIM en misiones y CARDS en eventos.',
				'context'     => 'Misiones, Eventos',
				'params'      => 'mode="milsim|cards"',
			),
			array(
				'shortcode'   => '[rmm_addons_list]',
				'description' => 'Lista colapsable de addons/dependencias requeridos para la misión.',
				'context'     => 'Misiones, Eventos (hereda de misión)',
				'params'      => '—',
			),
			array(
				'shortcode'   => '[clan_orbat]',
				'description' => 'Shortcode legacy. Equivale a [rmm_orbat] + [rmm_addons_list] combinados.',
				'context'     => 'Misiones, Eventos',
				'params'      => '—',
			),
			array(
				'shortcode'   => '[coordenadas_militar]',
				'description' => 'Muestra coordenadas militares geolocalizadas del visitante con estilo táctico tipo terminal.',
				'context'     => 'Global (cualquier página)',
				'params'      => 'color="#849b4c" size="14px" layout="vertical|horizontal" ip="0|1" intel="0|1" location="0|1"',
			),
			array(
				'shortcode'   => '[rmm_missions_grid]',
				'description' => 'Muestra una rejilla (grid) de misiones con estilo táctico, imágenes, autor y badges de addons (ACE/RHS).',
				'context'     => 'Global (cualquier página)',
				'params'      => 'posts_per_page="8"',
			),
			array(
				'shortcode'   => '[rmm_server_manager]',
				'description' => 'Gestor completo de servidor en frontend (lanzador de partidas, generador JSON, avisos Telegram). Solo visible para Fundador, Activo, Aliado y Admin.',
				'context'     => 'Global (pagina restringida)',
				'params'      => '—',
			),
			array(
				'shortcode'   => '[clan_lista_miembros]',
				'description' => 'Muestra una cuadrícula táctica de miembros con su avatar, nombre, pasador y overlay táctico de estadísticas al pasar el ratón. Al hacer clic, lleva al expediente detallado.',
				'context'     => 'Global (cualquier página)',
				'params'      => 'profile_url="[URL_PAGINA_PERFIL]"',
						),
						array(
							'shortcode'   => '[clan_solicitar_raid]',
							'description' => 'Formulario para solicitar una RAID. Solo visible para miembros con rol Activo o Aliado. Envía una notificación a Telegram con fecha, hora, servidor y notas.',
							'context'     => 'Global (cualquier página)',
							'params'      => '—',
						),
			array(
				'shortcode'   => '[clan_perfil_operador]',
				'description' => 'Muestra la ficha militar completa de un operador, con su expediente de condecoraciones en tamaño grande, dossier de combate y cronología de su carrera en el clan.',
				'context'     => 'Global (cualquier página)',
				'params'      => 'operator_id="[ID_DE_OPERADOR]" (por defecto: usuario logueado)',
			),
			array(
				'shortcode'   => '[clan_pasador_medallas]',
				'description' => 'Pasador de diario (Ribbon Rack) que muestra las medallas del operador ordenadas por prioridad visual.',
				'context'     => 'Global (cualquier página)',
				'params'      => 'user_id="[ID]" (por defecto: usuario logueado)',
			),
			array(
				'shortcode'   => '[clan_calendario]',
				'description' => 'Calendario táctico interactivo con todos los eventos. En móvil muestra vista de lista, en escritorio vista mensual.',
				'context'     => 'Global (cualquier página)',
				'params'      => '—',
			),
			array(
				'shortcode'   => '[hora_zulu]',
				'description' => 'Muestra la hora actual en formato ZULU (UTC). Ej: 20:30:00 ZULU.',
				'context'     => 'Global (cualquier página)',
				'params'      => '—',
			),
			array(
				'shortcode'   => '[rmm_last_match]',
				'description' => 'Tarjeta táctica con los datos de la última partida registrada: fecha, horario, escenario, número de jugadores y estadísticas totales acumuladas.',
				'context'     => 'Global (cualquier página)',
				'params'      => '—',
			),
			array(
				'shortcode'   => '[rmm_server_status]',
				'description' => 'Monitor de estado del servidor: ONLINE/OFFLINE, versión, jugadores conectados y mapa actual.',
				'context'     => 'Global (cualquier página)',
				'params'      => 'fill="1" (fuerza altura 100% en contenedores)',
			),
			array(
				'shortcode'   => '[rmm_server_resources]',
				'description' => 'Monitor de recursos del servidor: CPU, RAM y disco con barras de progreso.',
				'context'     => 'Global (cualquier página)',
				'params'      => 'fill="1"',
			),
			array(
				'shortcode'   => '[rmm_server_info]',
				'description' => 'Información de conexión al servidor: IP, puerto y enlace directo para conectar.',
				'context'     => 'Global (cualquier página)',
				'params'      => '—',
			),
			array(
				'shortcode'   => '[raid_apuntarse]',
				'description' => 'Botón para apuntarse a una RAID desde Telegram. Uso interno del bot.',
				'context'     => 'RAIDs (páginas de raid)',
				'params'      => '—',
			),
			array(
				'shortcode'   => '[raid_fecha]',
				'description' => 'Muestra la fecha de la RAID.',
				'context'     => 'RAIDs (páginas de raid)',
				'params'      => '—',
			),
			array(
				'shortcode'   => '[raid_hora]',
				'description' => 'Muestra la hora de la RAID.',
				'context'     => 'RAIDs (páginas de raid)',
				'params'      => '—',
			),
			array(
				'shortcode'   => '[raid_servidor]',
				'description' => 'Muestra el servidor asignado a la RAID.',
				'context'     => 'RAIDs (páginas de raid)',
				'params'      => '—',
			),
			array(
				'shortcode'   => '[raid_participantes]',
				'description' => 'Muestra el número de participantes apuntados a la RAID.',
				'context'     => 'RAIDs (páginas de raid)',
				'params'      => '—',
			),
			array(
				'shortcode'   => '[raid_estado]',
				'description' => 'Muestra el estado actual de la RAID (activa, completada, etc.).',
				'context'     => 'RAIDs (páginas de raid)',
				'params'      => '—',
			),
			array(
				'shortcode'   => '[raid_notas]',
				'description' => 'Muestra las notas adicionales de la RAID.',
				'context'     => 'RAIDs (páginas de raid)',
				'params'      => '—',
			),
			array(
				'shortcode'   => '[raid_solicitante]',
				'description' => 'Muestra el nombre del usuario que solicitó la RAID.',
				'context'     => 'RAIDs (páginas de raid)',
				'params'      => '—',
			),
			array(
				'shortcode'   => '[raid_aprobar]',
				'description' => 'Botones para aprobar o rechazar una solicitud de RAID. Solo visible para administradores.',
				'context'     => 'RAIDs (páginas de raid)',
				'params'      => '—',
			),
			array(
				'shortcode'   => '[raid_lista_participantes]',
				'description' => 'Lista completa de participantes apuntados a la RAID con su nombre y hora de confirmación.',
				'context'     => 'RAIDs (páginas de raid)',
				'params'      => '—',
			),
			array(
				'shortcode'   => '[raid_boton_participar]',
				'description' => 'Botón simple de participar en la RAID.',
				'context'     => 'RAIDs (páginas de raid)',
				'params'      => '—',
			),
			array(
				'shortcode'   => '[raid_faq]',
				'description' => 'Muestra las preguntas frecuentes sobre el sistema de RAIDs.',
				'context'     => 'RAIDs (páginas de raid)',
				'params'      => '—',
			),
		);
	}

	/**
	 * Render: Manual & Shortcodes Page
	 */
	public function render_manual_page() {
		$shortcodes = $this->get_shortcodes_reference();
		?>
		<div class="wrap rmm-admin-wrap">
			<div class="rmm-admin-header">
				<h1>🎖️ Reforger MILSIM Management</h1>
				<p class="rmm-version">v<?php echo RMM_VERSION; ?></p>
			</div>

			<!-- Quick Stats -->
			<div class="rmm-stats-row">
				<?php
				$missions = wp_count_posts('misiones');
				$events   = wp_count_posts('eventos_partidas');
				$medals   = wp_count_posts('condecoraciones');
				?>
				<div class="rmm-stat-card">
					<span class="rmm-stat-number"><?php echo isset($missions->publish) ? $missions->publish : 0; ?></span>
					<span class="rmm-stat-label">Misiones</span>
				</div>
				<div class="rmm-stat-card">
					<span class="rmm-stat-number"><?php echo isset($events->publish) ? $events->publish : 0; ?></span>
					<span class="rmm-stat-label">Eventos</span>
				</div>
				<div class="rmm-stat-card">
					<span class="rmm-stat-number"><?php echo isset($medals->publish) ? $medals->publish : 0; ?></span>
					<span class="rmm-stat-label">Condecoraciones</span>
				</div>
				<div class="rmm-stat-card">
					<span class="rmm-stat-number"><?php echo count($shortcodes); ?></span>
					<span class="rmm-stat-label">Shortcodes</span>
				</div>
			</div>

			<!-- Shortcodes Reference -->
			<div class="rmm-section">
				<h2>📋 Referencia de Shortcodes</h2>
				<p class="rmm-section-desc">Haz clic en cualquier shortcode para copiarlo al portapapeles. Úsalos en Bricks, Elementor o el editor clásico.</p>

				<table class="rmm-shortcode-table">
					<thead>
						<tr>
							<th style="width:220px;">Shortcode</th>
							<th>Descripción</th>
							<th style="width:200px;">Contexto</th>
							<th style="width:250px;">Parámetros Opcionales</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $shortcodes as $sc ) : ?>
						<tr>
							<td>
								<code class="rmm-copy-shortcode" title="Clic para copiar"><?php echo esc_html( $sc['shortcode'] ); ?></code>
							</td>
							<td><?php echo esc_html( $sc['description'] ); ?></td>
							<td><span class="rmm-context-badge"><?php echo esc_html( $sc['context'] ); ?></span></td>
							<td><code class="rmm-params"><?php echo esc_html( $sc['params'] ); ?></code></td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<!-- Usage Tips -->
			<div class="rmm-section">
				<h2>💡 Consejos de Uso</h2>
				<div class="rmm-tips-grid">
					<div class="rmm-tip-card">
						<h4>🔄 Sincronización de Datos</h4>
						<p>Para que <code>[rmm_summary]</code> y <code>[rmm_description]</code> funcionen, debes pulsar <strong>"SYNC DATA"</strong> en la misión y guardar. Si es un evento, selecciona la misión vinculada para heredar los datos automáticamente.</p>
					</div>
					<div class="rmm-tip-card">
						<h4>🧱 Uso en Bricks Builder</h4>
						<p>Añade un elemento <strong>"Shortcode"</strong> o <strong>"Rich Text"</strong> y pega el shortcode directamente. Para <code>[fecha_evento]</code> puedes usar también un bloque de texto con <strong>Datos Dinámicos</strong>.</p>
					</div>
					<div class="rmm-tip-card">
						<h4>🎯 ORBAT Interactivo</h4>
						<p>El shortcode <code>[rmm_orbat]</code> es interactivo solo en <strong>Eventos</strong>. En Misiones muestra la estructura en modo lectura sin botones de reserva.</p>
					</div>
					<div class="rmm-tip-card">
						<h4>🔗 Personalizar Enlace Workshop</h4>
						<p>Puedes cambiar el texto del botón: <code>[rmm_workshop_url text="Ir al Mod"]</code>. También puedes añadir clases CSS propias con el parámetro <code>class</code>.</p>
					</div>
				</div>
			</div>

			<!-- Custom Keys Reference -->
			<div class="rmm-section">
				<h2>🔑 Custom Keys (Post Custom Field)</h2>
				<p class="rmm-section-desc">Si prefieres usar el bloque "Post Custom Field" de Bricks en vez de shortcodes, estas son las keys disponibles:</p>

				<table class="rmm-shortcode-table rmm-keys-table">
					<thead>
						<tr>
							<th style="width:220px;">Custom Key</th>
							<th>Datos que devuelve</th>
							<th style="width:200px;">CPT</th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<td><code class="rmm-copy-shortcode" title="Clic para copiar">fecha_inicio</code></td>
							<td>Fecha/hora de inicio del evento (formato raw: 2026-05-15T20:00)</td>
							<td><span class="rmm-context-badge">Eventos</span></td>
						</tr>
						<tr>
							<td><code class="rmm-copy-shortcode" title="Clic para copiar">fecha_fin</code></td>
							<td>Fecha/hora de fin del evento</td>
							<td><span class="rmm-context-badge">Eventos</span></td>
						</tr>
						<tr>
							<td><code class="rmm-copy-shortcode" title="Clic para copiar">estado</code></td>
							<td>Estado del evento: abierta, en_curso, debriefing, finalizada</td>
							<td><span class="rmm-context-badge">Eventos</span></td>
						</tr>
						<tr>
							<td><code class="rmm-copy-shortcode" title="Clic para copiar">workshop_id</code></td>
							<td>ID del mod en Steam Workshop</td>
							<td><span class="rmm-context-badge">Misiones</span></td>
						</tr>
						<tr>
							<td><code class="rmm-copy-shortcode" title="Clic para copiar">workshop_url</code></td>
							<td>URL directa al mod en Workshop</td>
							<td><span class="rmm-context-badge">Misiones</span></td>
						</tr>
						<tr>
							<td><code class="rmm-copy-shortcode" title="Clic para copiar">mission_api_name</code></td>
							<td>Nombre de la misión obtenido de la API</td>
							<td><span class="rmm-context-badge">Misiones</span></td>
						</tr>
						<tr>
							<td><code class="rmm-copy-shortcode" title="Clic para copiar">rmm_summary</code></td>
							<td>Resumen del mod desde el Workshop</td>
							<td><span class="rmm-context-badge">Misiones, Eventos</span></td>
						</tr>
						<tr>
							<td><code class="rmm-copy-shortcode" title="Clic para copiar">rmm_description</code></td>
							<td>Descripción completa del mod desde el Workshop</td>
							<td><span class="rmm-context-badge">Misiones, Eventos</span></td>
						</tr>
						<tr>
							<td><code class="rmm-copy-shortcode" title="Clic para copiar">rmm_author</code></td>
							<td>Nombre del autor/creador de la misión</td>
							<td><span class="rmm-context-badge">Misiones, Eventos</span></td>
						</tr>
					</tbody>
				</table>
			</div>
		</div>

		<style>
		.rmm-admin-wrap { max-width: 1200px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }

		.rmm-admin-header { background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%); color: #fff; padding: 30px 35px; border-radius: 12px; margin-bottom: 25px; display: flex; align-items: center; justify-content: space-between; box-shadow: 0 4px 20px rgba(0,0,0,0.3); }
		.rmm-admin-header h1 { margin: 0; font-size: 1.8em; font-weight: 800; letter-spacing: -0.5px; color: #ffffff !important; }
		.rmm-version { background: rgba(255,255,255,0.15); padding: 5px 14px; border-radius: 20px; font-size: 0.85em; font-weight: 600; }

		.rmm-stats-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; margin-bottom: 30px; }
		.rmm-stat-card { background: #fff; border: 1px solid #e0e0e0; border-radius: 10px; padding: 20px; text-align: center; box-shadow: 0 2px 8px rgba(0,0,0,0.04); transition: transform 0.2s; }
		.rmm-stat-card:hover { transform: translateY(-2px); box-shadow: 0 4px 15px rgba(0,0,0,0.08); }
		.rmm-stat-number { display: block; font-size: 2.4em; font-weight: 800; color: #0f3460; line-height: 1; }
		.rmm-stat-label { display: block; margin-top: 6px; font-size: 0.85em; color: #888; text-transform: uppercase; letter-spacing: 1px; font-weight: 600; }

		.rmm-section { background: #fff; border: 1px solid #e0e0e0; border-radius: 10px; padding: 25px 30px; margin-bottom: 25px; box-shadow: 0 2px 8px rgba(0,0,0,0.04); }
		.rmm-section h2 { margin: 0 0 5px 0; font-size: 1.3em; color: #1a1a2e; }
		.rmm-section-desc { margin: 0 0 20px 0; color: #666; font-size: 0.95em; }

		.rmm-shortcode-table { width: 100%; border-collapse: collapse; }
		.rmm-shortcode-table th { background: #f8f9fa; padding: 12px 15px; text-align: left; font-size: 0.8em; text-transform: uppercase; letter-spacing: 0.5px; color: #555; border-bottom: 2px solid #e0e0e0; }
		.rmm-shortcode-table td { padding: 14px 15px; border-bottom: 1px solid #f0f0f0; vertical-align: top; font-size: 0.92em; color: #333; }
		.rmm-shortcode-table tbody tr:hover { background: #f8f9ff; }

		.rmm-copy-shortcode { background: #1a1a2e; color: #4fc3f7; padding: 6px 12px; border-radius: 5px; cursor: pointer; font-size: 0.92em; display: inline-block; transition: all 0.2s; user-select: all; font-weight: 600; }
		.rmm-copy-shortcode:hover { background: #0f3460; transform: scale(1.03); }
		.rmm-copy-shortcode.copied { background: #2e7d32 !important; color: #fff !important; }

		.rmm-params { background: #f5f5f5; color: #777; padding: 4px 8px; border-radius: 4px; font-size: 0.85em; }

		.rmm-context-badge { background: #e3f2fd; color: #1565c0; padding: 4px 10px; border-radius: 12px; font-size: 0.8em; font-weight: 600; white-space: nowrap; }

		.rmm-tips-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px; }
		.rmm-tip-card { background: #f8f9ff; border: 1px solid #e8eaf6; border-radius: 8px; padding: 18px 20px; }
		.rmm-tip-card h4 { margin: 0 0 8px 0; font-size: 1em; color: #1a1a2e; }
		.rmm-tip-card p { margin: 0; font-size: 0.9em; color: #555; line-height: 1.5; }
		.rmm-tip-card code { background: #e8eaf6; padding: 2px 6px; border-radius: 3px; font-size: 0.88em; }

		@media (max-width: 960px) {
			.rmm-stats-row { grid-template-columns: repeat(2, 1fr); }
			.rmm-tips-grid { grid-template-columns: 1fr; }
		}
		</style>

		<script>
		jQuery(document).ready(function($) {
			$('.rmm-copy-shortcode').on('click', function() {
				const el = $(this);
				const text = el.text().trim();
				navigator.clipboard.writeText(text).then(function() {
					el.addClass('copied');
					const original = el.text();
					el.text('✓ Copiado');
					setTimeout(function() {
						el.text(original).removeClass('copied');
					}, 1500);
				});
			});
		});
		</script>
		<?php
	}

	/**
	 * Parse telegram ID mappings from textarea text
	 */
	private function parse_telegram_role_text( $text ) {
		$lines = explode( "\n", str_replace( "\r", "", $text ) );
		$users = array();
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( empty( $line ) ) {
				continue;
			}
			$parts = explode( ':', $line, 2 );
			if ( count( $parts ) >= 2 ) {
				$users[] = array(
					'id'   => intval( trim( $parts[0] ) ),
					'name' => trim( $parts[1] ),
				);
			}
		}
		return $users;
	}

	/**
	 * Render: Settings Page (Pterodactyl, Telegram, Steam, etc.)
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'No tienes permisos suficientes para acceder a esta página.', 'reforger-milsim' ) );
		}

		$message = '';
		$status  = 'success';

		// Handle Form Submit
		if ( isset( $_POST['rmm_save_settings'] ) && check_admin_referer( 'rmm_save_settings_action', 'rmm_settings_nonce' ) ) {
			update_option( 'rmm_ptero_url', esc_url_raw( trim( $_POST['rmm_ptero_url'] ) ) );
			update_option( 'rmm_ptero_client_key', sanitize_text_field( trim( $_POST['rmm_ptero_client_key'] ) ) );
			update_option( 'rmm_ptero_app_key', sanitize_text_field( trim( $_POST['rmm_ptero_app_key'] ) ) );
			update_option( 'rmm_ptero_stable_server_id', sanitize_text_field( trim( $_POST['rmm_ptero_stable_server_id'] ) ) );
			update_option( 'rmm_ptero_testing_server_id', sanitize_text_field( trim( $_POST['rmm_ptero_testing_server_id'] ) ) );
			update_option( 'rmm_server_ip', sanitize_text_field( trim( $_POST['rmm_server_ip'] ) ) );
						update_option( 'rmm_server_port', intval( $_POST['rmm_server_port'] ) ?: 2001 );
									update_option( 'rmm_server_cpu_limit', intval( $_POST['rmm_server_cpu_limit'] ) ?: 800 );
									update_option( 'rmm_server_ram_gb', intval( $_POST['rmm_server_ram_gb'] ) ?: 24 );
									update_option( 'rmm_server_disk_gb', intval( $_POST['rmm_server_disk_gb'] ) ?: 200 );
												update_option( 'rmm_raid_password', sanitize_text_field( trim( $_POST['rmm_raid_password'] ) ) );
															update_option( 'rmm_raid_telegram_token', sanitize_text_field( trim( $_POST['rmm_raid_telegram_token'] ) ) );
															update_option( 'rmm_raid_telegram_chat_id', sanitize_text_field( trim( $_POST['rmm_raid_telegram_chat_id'] ) ) );
			update_option( 'rmm_telegram_token', sanitize_text_field( trim( $_POST['rmm_telegram_token'] ) ) );
			update_option( 'rmm_telegram_chat_id', sanitize_text_field( trim( $_POST['rmm_telegram_chat_id'] ) ) );
			update_option( 'rmm_telegram_bot_path', sanitize_text_field( trim( $_POST['rmm_telegram_bot_path'] ) ) );
			update_option( 'rmm_telegram_log_file', sanitize_text_field( trim( $_POST['rmm_telegram_log_file'] ) ) );
			update_option( 'rmm_steam_api_key', sanitize_text_field( trim( $_POST['rmm_steam_api_key'] ) ) );
			update_option( 'rmm_whatsapp_phone', sanitize_text_field( trim( $_POST['rmm_whatsapp_phone'] ) ) );
			update_option( 'rmm_whatsapp_apikey', sanitize_text_field( trim( $_POST['rmm_whatsapp_apikey'] ) ) );
			update_option( 'rmm_telemetry_auth_key', sanitize_text_field( trim( $_POST['rmm_telemetry_auth_key'] ) ) );

			// TFR ORBAT Link
			update_option( 'rmm_orbatlink_enabled', isset( $_POST['rmm_orbatlink_enabled'] ) ? '1' : '0' );
			update_option( 'rmm_orbatlink_base_url', esc_url_raw( trim( $_POST['rmm_orbatlink_base_url'] ) ) );
			update_option( 'rmm_orbatlink_route', sanitize_text_field( trim( $_POST['rmm_orbatlink_route'] ) ) );
			update_option( 'rmm_orbatlink_token', sanitize_text_field( trim( $_POST['rmm_orbatlink_token'] ) ) );
			update_option( 'rmm_orbatlink_debug', isset( $_POST['rmm_orbatlink_debug'] ) ? '1' : '0' );
			update_option( 'rmm_orbatlink_send_disconnect', isset( $_POST['rmm_orbatlink_send_disconnect'] ) ? '1' : '0' );
			update_option( 'rmm_orbatlink_send_gameend', isset( $_POST['rmm_orbatlink_send_gameend'] ) ? '1' : '0' );
			update_option( 'rmm_orbatlink_send_test', isset( $_POST['rmm_orbatlink_send_test'] ) ? '1' : '0' );
			update_option( 'rmm_orbatlink_test_delay', intval( $_POST['rmm_orbatlink_test_delay'] ) ?: 5000 );
			update_option( 'rmm_orbatlink_send_periodic', isset( $_POST['rmm_orbatlink_send_periodic'] ) ? '1' : '0' );
			update_option( 'rmm_orbatlink_interval', intval( $_POST['rmm_orbatlink_interval'] ) ?: 5 );

			// Roles para acceso al gestor de servidor frontend
			$manager_roles = isset( $_POST['rmm_server_manager_roles'] ) ? array_map( 'sanitize_text_field', $_POST['rmm_server_manager_roles'] ) : array( 'fundador', 'editor' );
			update_option( 'rmm_server_manager_roles', $manager_roles );

			// Roles
			$role_admin  = trim( $_POST['rmm_telegram_role_admin'] );
			$role_user   = trim( $_POST['rmm_telegram_role_user'] );
			$role_viewer = trim( $_POST['rmm_telegram_role_viewer'] );

			update_option( 'rmm_telegram_role_admin', $role_admin );
			update_option( 'rmm_telegram_role_user', $role_user );
			update_option( 'rmm_telegram_role_viewer', $role_viewer );

			// Process dynamic ORBAT roles & image uploads
			if ( isset( $_POST['rmm_roles_names'] ) ) {
				$submitted_names     = $_POST['rmm_roles_names'];
				$submitted_old_names = $_POST['rmm_roles_old_names'];
				$submitted_image_ids = $_POST['rmm_roles_image_ids'];
				$submitted_image_urls = $_POST['rmm_roles_image_urls'];
				
				$old_roles_list = rmm_get_orbat_roles();
				$new_roles_list = array();
				
				for ( $i = 0; $i < count( $submitted_names ); $i++ ) {
					$name = sanitize_text_field( trim( $submitted_names[$i] ) );
					if ( empty( $name ) ) {
						continue;
					}
					
					$old_name  = sanitize_text_field( trim( $submitted_old_names[$i] ) );
					$image_id  = intval( $submitted_image_ids[$i] );
					$image_url = esc_url_raw( $submitted_image_urls[$i] );
					
					$new_roles_list[$name] = array(
						'image_id'  => $image_id,
						'image_url' => $image_url,
					);
					
					// If renamed, run propagation across all missions/events ORBATs
					if ( ! empty( $old_name ) && $old_name !== $name && isset( $old_roles_list[$old_name] ) ) {
						$this->propagate_role_rename( $old_name, $name );
					}
				}
				
				update_option( 'rmm_orbat_roles', $new_roles_list );
			}

			$message = __( 'Ajustes guardados correctamente.', 'reforger-milsim' );

			// Sync config.php to bot path if configured
			$bot_path = rtrim( sanitize_text_field( trim( $_POST['rmm_telegram_bot_path'] ) ), '/' );
			if ( ! empty( $bot_path ) && is_dir( $bot_path ) ) {
				$config_file = $bot_path . '/config.php';

				// Parse Roles
				$roles = array(
					'admin'  => $this->parse_telegram_role_text( $role_admin ),
					'user'   => $this->parse_telegram_role_text( $role_user ),
					'viewer' => $this->parse_telegram_role_text( $role_viewer ),
				);

				$config_array = array(
					'telegram_token'   => sanitize_text_field( trim( $_POST['rmm_telegram_token'] ) ),
					'ptero_url'        => esc_url_raw( trim( $_POST['rmm_ptero_url'] ) ),
					'ptero_client_key' => sanitize_text_field( trim( $_POST['rmm_ptero_client_key'] ) ),
					'ptero_app_key'    => sanitize_text_field( trim( $_POST['rmm_ptero_app_key'] ) ),
					'stable_servers'   => array_filter( array( sanitize_text_field( trim( $_POST['rmm_ptero_stable_server_id'] ) ) ) ),
					'testing_servers'  => array_filter( array( sanitize_text_field( trim( $_POST['rmm_ptero_testing_server_id'] ) ) ) ),
					'roles'            => $roles,
					'whatsapp'         => array(
						'phone'  => sanitize_text_field( trim( $_POST['rmm_whatsapp_phone'] ) ),
						'apikey' => sanitize_text_field( trim( $_POST['rmm_whatsapp_apikey'] ) ),
					),
					'log_file'         => sanitize_text_field( trim( $_POST['rmm_telegram_log_file'] ) ?: $bot_path . '/bot.log' ),
				);

				$config_str = "<?php\n// Generado automaticamente por WordPress Reforger MILSIM Plugin\nreturn " . var_export( $config_array, true ) . ";\n";
				
				if ( @file_put_contents( $config_file, $config_str ) !== false ) {
					$message .= ' ' . __( 'Además, se ha sincronizado y actualizado el archivo config.php del bot de Telegram.', 'reforger-milsim' );
				} else {
					$message .= ' ⚠️ ' . __( 'No se pudo escribir en el directorio del bot. Por favor verifica los permisos de escritura.', 'reforger-milsim' );
					$status = 'error';
				}
			}
		}

		// Retrieve values
		$ptero_url        = get_option( 'rmm_ptero_url' );
		$ptero_client_key = get_option( 'rmm_ptero_client_key' );
		$ptero_app_key    = get_option( 'rmm_ptero_app_key' );
		$stable_server    = get_option( 'rmm_ptero_stable_server_id' );
		$testing_server   = get_option( 'rmm_ptero_testing_server_id' );
		$server_ip        = get_option( 'rmm_server_ip' );
				$server_port      = get_option( 'rmm_server_port', 2001 );
		$tg_token         = get_option( 'rmm_telegram_token' );
		$tg_chat_id       = get_option( 'rmm_telegram_chat_id' );
		$tg_bot_path      = get_option( 'rmm_telegram_bot_path' );
		$tg_log_file      = get_option( 'rmm_telegram_log_file' );
		$steam_key        = get_option( 'rmm_steam_api_key' );
		$wa_phone         = get_option( 'rmm_whatsapp_phone' );
		$wa_key           = get_option( 'rmm_whatsapp_apikey' );
		$telemetry_key    = get_option( 'rmm_telemetry_auth_key', 'FSuhjrSF&546VFsHYUCf·/(JHSJHGD49fD' );

		$role_admin  = get_option( 'rmm_telegram_role_admin' );
		$role_user   = get_option( 'rmm_telegram_role_user' );
		$role_viewer = get_option( 'rmm_telegram_role_viewer' );
		?>
		<div class="wrap rmm-admin-wrap">
			<div class="rmm-admin-header">
				<h1>⚙️ Configuración de Integraciones</h1>
				<p class="rmm-version">v<?php echo RMM_VERSION; ?></p>
			</div>

			<?php if ( ! empty( $message ) ) : ?>
				<div class="rmm-alert rmm-alert-<?php echo esc_attr( $status ); ?>">
					<p><?php echo esc_html( $message ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="">
				<?php wp_nonce_field( 'rmm_save_settings_action', 'rmm_settings_nonce' ); ?>

				<!-- Nav Tabs -->
				<ul class="rmm-nav-tabs" style="margin-bottom: 30px;">
					<li class="active"><a href="#integrations-pane">🦕 Integraciones y Claves</a></li>
					<li><a href="#roles-pane">🪖 Tipos de Slot (ORBAT)</a></li>
				</ul>

				<div id="integrations-pane" class="rmm-tab-pane active">
					<!-- Section: Pterodactyl -->
					<div class="rmm-section">
					<h2>🦕 Integración con Pterodactyl</h2>
					<p class="rmm-section-desc">Configuración de credenciales de API para gestionar servidores y ficheros de configuración.</p>

					<div class="rmm-form-grid">
						<div class="rmm-form-group">
							<label for="rmm_ptero_url">URL del Panel Pterodactyl</label>
							<input type="url" name="rmm_ptero_url" id="rmm_ptero_url" value="<?php echo esc_url( $ptero_url ); ?>" class="regular-text" placeholder="https://pterodactyl.gure.party" required>
						</div>

						<div class="rmm-form-group">
							<label for="rmm_ptero_client_key">Client API Key (ptlc_...)</label>
							<input type="password" name="rmm_ptero_client_key" id="rmm_ptero_client_key" value="<?php echo esc_attr( $ptero_client_key ); ?>" class="regular-text" placeholder="ptlc_..." required>
						</div>

						<div class="rmm-form-group">
							<label for="rmm_ptero_app_key">Application API Key (ptla_...) <span class="rmm-optional">(Opcional)</span></label>
							<input type="password" name="rmm_ptero_app_key" id="rmm_ptero_app_key" value="<?php echo esc_attr( $ptero_app_key ); ?>" class="regular-text" placeholder="ptla_...">
						</div>

						<div class="rmm-form-group">
							<label for="rmm_ptero_stable_server_id">ID Servidor Principal (STABLE)</label>
							<input type="text" name="rmm_ptero_stable_server_id" id="rmm_ptero_stable_server_id" value="<?php echo esc_attr( $stable_server ); ?>" class="regular-text" placeholder="e.g. 5e1fb0a1">
						</div>

						<div class="rmm-form-group">
							<label for="rmm_ptero_testing_server_id">ID Servidor de Pruebas (TESTING)</label>
							<input type="text" name="rmm_ptero_testing_server_id" id="rmm_ptero_testing_server_id" value="<?php echo esc_attr( $testing_server ); ?>" class="regular-text" placeholder="e.g. c831a29f">
						</div>

						<div class="rmm-form-group">
							<label for="rmm_server_ip">IP de Conexión del Servidor</label>
														<input type="text" name="rmm_server_ip" id="rmm_server_ip" value="<?php echo esc_attr( $server_ip ); ?>" class="regular-text" placeholder="e.g. 192.168.1.100">
													</div>

													<div class="rmm-form-group">
														<label for="rmm_server_port">Puerto del Juego</label>
														<input type="number" name="rmm_server_port" id="rmm_server_port" value="<?php echo esc_attr( $server_port ); ?>" class="small-text" min="1" max="65535" placeholder="2001">
														<p class="description">Por defecto en Arma Reforger es <strong>2001</strong>. Se muestra como <code>IP:PUERTO</code> en los shortcodes de conexión.</p>
													</div>
					</div>
									</div>

									<!-- Sub-section: Server Capacity -->
									<div class="rmm-section" style="margin-top: 20px;">
										<h3><i class="fa-solid fa-gauge-high"></i> Capacidad del Servidor</h3>
										<p class="rmm-section-desc">Configura los límites reales de hardware para calcular correctamente los porcentajes de uso en los shortcodes.</p>
					
										<div class="rmm-form-grid">
											<div class="rmm-form-group">
												<label for="rmm_server_cpu_limit">Límite de CPU (%)</label>
												<input type="number" name="rmm_server_cpu_limit" id="rmm_server_cpu_limit" value="<?php echo esc_attr( get_option( 'rmm_server_cpu_limit', 800 ) ); ?>" class="small-text" min="100" step="100">
												<p class="description">100% = 1 vCore. 8 vCores = <strong>800%</strong>. Este valor se obtiene automáticamente del panel Pterodactyl si está disponible.</p>
											</div>
											<div class="rmm-form-group">
												<label for="rmm_server_ram_gb">RAM Total (GB)</label>
												<input type="number" name="rmm_server_ram_gb" id="rmm_server_ram_gb" value="<?php echo esc_attr( get_option( 'rmm_server_ram_gb', 24 ) ); ?>" class="small-text" min="1" step="1">
												<p class="description">Memoria RAM total asignada al servidor de juego.</p>
											</div>
											<div class="rmm-form-group">
												<label for="rmm_server_disk_gb">Disco Total (GB)</label>
												<input type="number" name="rmm_server_disk_gb" id="rmm_server_disk_gb" value="<?php echo esc_attr( get_option( 'rmm_server_disk_gb', 200 ) ); ?>" class="small-text" min="1" step="1">
												<p class="description">Espacio en disco total asignado al servidor de juego.</p>
																						</div>
																						<div class="rmm-form-group">
																							<label for="rmm_raid_password">Contraseña RAID</label>
																							<input type="text" name="rmm_raid_password" id="rmm_raid_password" value="<?php echo esc_attr( get_option( 'rmm_raid_password', '' ) ); ?>" class="regular-text" placeholder="Contraseña del servidor de juego">
																							<p class="description">Contraseña que se enviará en las notificaciones de RAID. Los usuarios no la introducen en el formulario.</p>
																																						</div>
																																						<div class="rmm-form-group">
																																							<label for="rmm_raid_telegram_token">Token del Bot de RAIDs</label>
																																							<input type="text" name="rmm_raid_telegram_token" id="rmm_raid_telegram_token" value="<?php echo esc_attr( get_option( 'rmm_raid_telegram_token', '' ) ); ?>" class="regular-text" placeholder="Token del bot @raidsratasdelaestrada_bot">
																																							<p class="description">Bot de Telegram específico para notificaciones de RAID. Distinto del bot principal de avisos.</p>
																																						</div>
																																						<div class="rmm-form-group">
																																							<label for="rmm_raid_telegram_chat_id">ID del Chat/Grupo de RAIDs</label>
																																							<input type="text" name="rmm_raid_telegram_chat_id" id="rmm_raid_telegram_chat_id" value="<?php echo esc_attr( get_option( 'rmm_raid_telegram_chat_id', '-1003157817672' ) ); ?>" class="regular-text" placeholder="-1003157817672">
																																							<p class="description">ID del grupo de Telegram donde se publican las RAIDs. Ya viene preconfigurado.</p>
																																						</div>
										</div>
									</div>

									<!-- Section: Telegram -->
				<div class="rmm-section">
					<h2>📢 Integración con Bot de Telegram</h2>
					<p class="rmm-section-desc">Permite notificar al grupo de Telegram y sincronizar automáticamente las credenciales del Bot en el servidor.</p>

					<div class="rmm-form-grid">
						<div class="rmm-form-group">
							<label for="rmm_telegram_token">Token del Bot de Telegram</label>
							<input type="password" name="rmm_telegram_token" id="rmm_telegram_token" value="<?php echo esc_attr( $tg_token ); ?>" class="regular-text" placeholder="123456789:ABCdefGhI..." required>
						</div>

						<div class="rmm-form-group">
							<label for="rmm_telegram_chat_id">ID de Chat / Grupo para Notificaciones</label>
							<input type="text" name="rmm_telegram_chat_id" id="rmm_telegram_chat_id" value="<?php echo esc_attr( $tg_chat_id ); ?>" class="regular-text" placeholder="e.g. -100123456789" required>
						</div>

						<div class="rmm-form-group">
							<label for="rmm_telegram_bot_path">Ruta Local del Bot en el Servidor (Sincronización)</label>
							<input type="text" name="rmm_telegram_bot_path" id="rmm_telegram_bot_path" value="<?php echo esc_attr( $tg_bot_path ); ?>" class="large-text" placeholder="e.g. /Volumes/Extreme SSD/Proyectos/gure.party/pterobot/pterobot">
							<span class="rmm-field-desc">Si se especifica, se actualizará el archivo `config.php` del bot automáticamente al guardar.</span>
						</div>

						<div class="rmm-form-group">
							<label for="rmm_telegram_log_file">Ruta de logs del Bot</label>
							<input type="text" name="rmm_telegram_log_file" id="rmm_telegram_log_file" value="<?php echo esc_attr( $tg_log_file ); ?>" class="large-text" placeholder="e.g. bot.log">
						</div>
					</div>
				</div>

				<!-- Section: Telegram Users & Roles Mappings -->
				<div class="rmm-section">
					<h2>👥 Control de Acceso por Telegram</h2>
					<p class="rmm-section-desc">Introduce las personas autorizadas para interactuar con el bot de Telegram, con formato <code>ID_TELEGRAM:NombreDeUsuario</code> (un usuario por línea).</p>

					<div class="rmm-form-grid rmm-grid-3">
						<div class="rmm-form-group">
							<label for="rmm_telegram_role_admin">Administradores (Acceso Completo)</label>
							<textarea name="rmm_telegram_role_admin" id="rmm_telegram_role_admin" rows="6" class="large-text code" placeholder="123456789:JuanPerez&#10;987654321:SgtBarron"><?php echo esc_textarea( $role_admin ); ?></textarea>
						</div>

						<div class="rmm-form-group">
							<label for="rmm_telegram_role_user">Usuarios (Lanzadores)</label>
							<textarea name="rmm_telegram_role_user" id="rmm_telegram_role_user" rows="6" class="large-text code" placeholder="112233445:CaboGomez"><?php echo esc_textarea( $role_user ); ?></textarea>
						</div>

						<div class="rmm-form-group">
							<label for="rmm_telegram_role_viewer">Lectores (Solo Visualizar)</label>
							<textarea name="rmm_telegram_role_viewer" id="rmm_telegram_role_viewer" rows="6" class="large-text code" placeholder="998877665:ReclutaLopez"><?php echo esc_textarea( $role_viewer ); ?></textarea>
						</div>
					</div>
				</div>

				<!-- Section: CallMeBot WhatsApp -->
				<div class="rmm-section">
					<h2>💬 WhatsApp (CallMeBot) <span class="rmm-optional">(Opcional)</span></h2>
					<p class="rmm-section-desc">Habilita notificaciones del Bot de Telegram opcionalmente por WhatsApp.</p>

					<div class="rmm-form-grid">
						<div class="rmm-form-group">
							<label for="rmm_whatsapp_phone">Número de Teléfono (+34...)</label>
							<input type="text" name="rmm_whatsapp_phone" id="rmm_whatsapp_phone" value="<?php echo esc_attr( $wa_phone ); ?>" class="regular-text" placeholder="e.g. +34600112233">
						</div>

						<div class="rmm-form-group">
							<label for="rmm_whatsapp_apikey">CallMeBot API Key</label>
							<input type="text" name="rmm_whatsapp_apikey" id="rmm_whatsapp_apikey" value="<?php echo esc_attr( $wa_key ); ?>" class="regular-text" placeholder="e.g. 123456">
						</div>
					</div>
				</div>

				<!-- Section: Steam -->
				<div class="rmm-section">
					<h2>🔑 Steam Web API Key</h2>
					<p class="rmm-section-desc">API Key requerida para consultar dependencias y datos del Steam Workshop.</p>

					<div class="rmm-form-group">
						<label for="rmm_steam_api_key">Steam Web API Key</label>
						<input type="password" name="rmm_steam_api_key" id="rmm_steam_api_key" value="<?php echo esc_attr( $steam_key ); ?>" class="large-text" placeholder="Clave de API de Steam de 32 caracteres">
					</div>
				</div>

				<!-- Section: Telemetry -->
				<div class="rmm-section">
					<h2>📡 Telemetría de Partida (Addon Reforger)</h2>
					<p class="rmm-section-desc">Clave de autorización que usa el addon de estadísticas de Arma Reforger para enviar telemetría al endpoint <code>/wp-json/clan/v1/telemetry/push</code>.</p>

					<div class="rmm-form-group">
						<label for="rmm_telemetry_auth_key">Authorization Key</label>
						<input type="text" name="rmm_telemetry_auth_key" id="rmm_telemetry_auth_key" value="<?php echo esc_attr( $telemetry_key ); ?>" class="large-text" placeholder="Clave secreta compartida con el addon">
					</div>
				</div>

				<!-- Section: TFR ORBAT Link -->
				<div class="rmm-section">
					<h2>🔗 TFR ORBAT Link (Addon de Estadisticas)</h2>
					<p class="rmm-section-desc">Configuracion del addon TFR ORBAT Link que se sube automaticamente al servidor de juego. Este addon envia telemetria de combate (bajas, muertes, disparos, curaciones) al endpoint configurado.</p>

					<div class="rmm-form-group">
						<label><input type="checkbox" name="rmm_orbatlink_enabled" value="1" <?php checked( get_option( 'rmm_orbatlink_enabled', '1' ), '1' ); ?>> Activar ORBAT Link</label>
						<p class="description">Si esta activo, el mod se añade automaticamente a todas las partidas y se sube su archivo de configuracion.</p>
					</div>

					<div class="rmm-form-grid">
						<div class="rmm-form-group">
							<label for="rmm_orbatlink_base_url">Base URL</label>
							<input type="url" name="rmm_orbatlink_base_url" value="<?php echo esc_url( get_option( 'rmm_orbatlink_base_url', 'https://tfrpruebas.gure.party' ) ); ?>" class="regular-text">
						</div>
						<div class="rmm-form-group">
							<label for="rmm_orbatlink_route">Ruta del Endpoint</label>
							<input type="text" name="rmm_orbatlink_route" value="<?php echo esc_attr( get_option( 'rmm_orbatlink_route', '/wp-json/clan/v1/telemetry/push' ) ); ?>" class="regular-text">
						</div>
						<div class="rmm-form-group">
							<label for="rmm_orbatlink_token">Bearer Token</label>
							<input type="text" name="rmm_orbatlink_token" value="<?php echo esc_attr( get_option( 'rmm_orbatlink_token', $telemetry_key ) ); ?>" class="regular-text">
						</div>
					</div>

					<div class="rmm-form-grid rmm-grid-3">
						<div class="rmm-form-group">
							<label><input type="checkbox" name="rmm_orbatlink_debug" value="1" <?php checked( get_option( 'rmm_orbatlink_debug', '1' ), '1' ); ?>> Debug</label>
						</div>
						<div class="rmm-form-group">
							<label><input type="checkbox" name="rmm_orbatlink_send_disconnect" value="1" <?php checked( get_option( 'rmm_orbatlink_send_disconnect', '1' ), '1' ); ?>> Enviar al desconectar</label>
						</div>
						<div class="rmm-form-group">
							<label><input type="checkbox" name="rmm_orbatlink_send_gameend" value="1" <?php checked( get_option( 'rmm_orbatlink_send_gameend', '1' ), '1' ); ?>> Enviar al finalizar partida</label>
						</div>
						<div class="rmm-form-group">
							<label><input type="checkbox" name="rmm_orbatlink_send_periodic" value="1" <?php checked( get_option( 'rmm_orbatlink_send_periodic', '1' ), '1' ); ?>> Envio periodico</label>
						</div>
						<div class="rmm-form-group">
							<label><input type="checkbox" name="rmm_orbatlink_send_test" value="1" <?php checked( get_option( 'rmm_orbatlink_send_test', '0' ), '1' ); ?>> Enviar test al registrar</label>
						</div>
					</div>

					<div class="rmm-form-grid">
						<div class="rmm-form-group">
							<label for="rmm_orbatlink_interval">Intervalo de envio (minutos)</label>
							<input type="number" name="rmm_orbatlink_interval" value="<?php echo intval( get_option( 'rmm_orbatlink_interval', '5' ) ); ?>" min="1" max="60" class="small-text">
						</div>
						<div class="rmm-form-group">
							<label for="rmm_orbatlink_test_delay">Delay del test (ms)</label>
							<input type="number" name="rmm_orbatlink_test_delay" value="<?php echo intval( get_option( 'rmm_orbatlink_test_delay', '5000' ) ); ?>" min="0" class="small-text">
						</div>
					</div>
				</div>

				<!-- Section: Acceso al Gestor de Servidor -->
				<div class="rmm-section">
					<h2>🔐 Acceso al Gestor de Servidor Frontend</h2>
					<p class="rmm-section-desc">Roles que pueden ver el shortcode <code>[rmm_server_manager]</code> en el frontend.</p>
					<div class="rmm-form-grid rmm-grid-3">
						<?php 
						$saved_roles = get_option( 'rmm_server_manager_roles', array( 'fundador', 'editor' ) );
						$all_roles = wp_roles()->roles;
						foreach ( $all_roles as $slug => $data ) : ?>
							<div class="rmm-form-group">
								<label><input type="checkbox" name="rmm_server_manager_roles[]" value="<?php echo $slug; ?>" <?php checked( in_array( $slug, $saved_roles ) ); ?>> <?php echo translate_user_role( $data['name'] ); ?></label>
							</div>
						<?php endforeach; ?>
					</div>
				</div>

				</div> <!-- Closing integrations-pane -->

				<!-- Tab 2: Roles (Tipos de Slot) -->
				<div id="roles-pane" class="rmm-tab-pane">
					<div class="rmm-section">
						<h2>🪖 Tipos de Slot (Roles del ORBAT)</h2>
						<p class="rmm-section-desc">Gestiona los nombres e iconos PNG personalizados (250x250px recomendados) de los slots en el ORBAT de misiones y eventos.</p>

						<table class="rmm-roles-table">
							<thead>
								<tr>
									<th>Nombre del Rol</th>
									<th>Icono PNG (250x250)</th>
									<th style="width: 100px;">Acciones</th>
								</tr>
							</thead>
							<tbody id="rmm-roles-tbody">
								<?php
								$roles_list = rmm_get_orbat_roles();
								foreach ( $roles_list as $role_name => $role_data ) :
									$image_id  = isset( $role_data['image_id'] ) ? intval( $role_data['image_id'] ) : 0;
									$image_url = isset( $role_data['image_url'] ) ? $role_data['image_url'] : '';
								?>
									<tr class="rmm-role-row">
										<td>
											<input type="text" name="rmm_roles_names[]" value="<?php echo esc_attr( $role_name ); ?>" class="regular-text" style="width: 100%; max-width: 350px;" placeholder="Ej. Fusilero de Asalto" required>
											<input type="hidden" name="rmm_roles_old_names[]" value="<?php echo esc_attr( $role_name ); ?>">
										</td>
										<td>
											<div style="display:flex; align-items:center; gap:10px;">
												<div class="rmm-role-icon-preview">
													<?php if ( ! empty( $image_url ) ) : ?>
														<img src="<?php echo esc_url( $image_url ); ?>" style="width:24px; height:24px; object-fit:contain;" />
													<?php else : ?>
														<span class="rmm-role-icon-placeholder" style="font-size:18px;">👤</span>
													<?php endif; ?>
												</div>
												<input type="hidden" class="rmm-role-image-id" name="rmm_roles_image_ids[]" value="<?php echo esc_attr( $image_id ); ?>">
												<input type="hidden" class="rmm-role-image-url" name="rmm_roles_image_urls[]" value="<?php echo esc_url( $image_url ); ?>">
												<button type="button" class="button rmm-upload-role-image">🖼️ Seleccionar PNG</button>
												<button type="button" class="button rmm-remove-role-image">Quitar</button>
											</div>
										</td>
										<td>
											<button type="button" class="button button-link-delete rmm-delete-role-row">🗑️ Eliminar</button>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>

						<div style="margin-top: 25px;">
							<button type="button" id="rmm-add-role-row" class="button button-secondary">➕ Añadir Nuevo Tipo de Slot</button>
						</div>
					</div>
				</div>

				<!-- Submit Button -->
				<div class="rmm-submit-row" style="margin-top: 30px;">
					<button type="submit" name="rmm_save_settings" class="button button-primary button-large">💾 Guardar Configuración</button>
				</div>
			</form>
		</div>

		<!-- JAVASCRIPT LOGIC FOR SETTINGS PAGE -->
		<script>
		jQuery(document).ready(function($) {
			// === TABS SYSTEM ===
			$('.rmm-nav-tabs a').on('click', function(e) {
				e.preventDefault();
				const target = $(this).attr('href');
				$('.rmm-nav-tabs li').removeClass('active');
				$(this).parent().addClass('active');
				$('.rmm-tab-pane').removeClass('active');
				$(target).addClass('active');
			});

			// === WP MEDIA UPLOADER FOR ROLES ===
			let mediaUploader = null;
			let activeRow = null;

			$(document).on('click', '.rmm-upload-role-image', function(e) {
				e.preventDefault();
				activeRow = $(this).closest('.rmm-role-row');

				// Reopen if already created
				if (mediaUploader) {
					mediaUploader.open();
					return;
				}

				// Create frame
				mediaUploader = wp.media({
					title: 'Seleccionar Icono PNG para Rol (250x250)',
					button: {
						text: 'Usar esta imagen'
					},
					multiple: false,
					library: {
						type: 'image'
					}
				});

				mediaUploader.on('select', function() {
					const attachment = mediaUploader.state().get('selection').first().toJSON();
					activeRow.find('.rmm-role-image-id').val(attachment.id);
					
					const imageUrl = (attachment.sizes && attachment.sizes.thumbnail) ? attachment.sizes.thumbnail.url : attachment.url;
					activeRow.find('.rmm-role-image-url').val(imageUrl);

					activeRow.find('.rmm-role-icon-preview').html(
						'<img src="' + imageUrl + '" style="width:24px; height:24px; object-fit:contain;" />'
					);
				});

				mediaUploader.open();
			});

			$(document).on('click', '.rmm-remove-role-image', function(e) {
				e.preventDefault();
				const row = $(this).closest('.rmm-role-row');
				row.find('.rmm-role-image-id').val(0);
				row.find('.rmm-role-image-url').val('');
				row.find('.rmm-role-icon-preview').html('<span class="rmm-role-icon-placeholder" style="font-size:18px;">👤</span>');
			});

			// === ADD/DELETE ROWS ===
			$('#rmm-add-role-row').on('click', function(e) {
				e.preventDefault();
				const tbody = $('#rmm-roles-tbody');
				const rowHtml = `
					<tr class="rmm-role-row">
						<td>
							<input type="text" name="rmm_roles_names[]" class="regular-text" style="width:100%; max-width: 350px;" placeholder="Ej. Fusilero de Asalto" required>
							<input type="hidden" name="rmm_roles_old_names[]" value="">
						</td>
						<td>
							<div style="display:flex; align-items:center; gap:10px;">
								<div class="rmm-role-icon-preview">
									<span class="rmm-role-icon-placeholder" style="font-size:18px;">👤</span>
								</div>
								<input type="hidden" class="rmm-role-image-id" name="rmm_roles_image_ids[]" value="0">
								<input type="hidden" class="rmm-role-image-url" name="rmm_roles_image_urls[]" value="">
								<button type="button" class="button rmm-upload-role-image">🖼️ Seleccionar PNG</button>
								<button type="button" class="button rmm-remove-role-image">Quitar</button>
							</div>
						</td>
						<td>
							<button type="button" class="button button-link-delete rmm-delete-role-row">🗑️ Eliminar</button>
						</td>
					</tr>
				`;
				tbody.append(rowHtml);
			});

			$(document).on('click', '.rmm-delete-role-row', function(e) {
				e.preventDefault();
				if (confirm('¿Estás seguro de que deseas eliminar este tipo de slot? Las misiones existentes conservarán el rol escrito pero no podrás seleccionarlo en nuevos slots.')) {
					$(this).closest('tr').remove();
				}
			});
		});
		</script>

		<style>
		.rmm-admin-wrap { max-width: 1100px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; padding-top: 10px; }
		.rmm-admin-header { background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%); color: #fff; padding: 25px 35px; border-radius: 12px; margin-bottom: 25px; display: flex; align-items: center; justify-content: space-between; box-shadow: 0 4px 20px rgba(0,0,0,0.15); border: 1px solid rgba(255, 255, 255, 0.05); }
		.rmm-admin-header h1 { margin: 0; font-size: 1.8em; font-weight: 800; color: #ffffff !important; }
		.rmm-version { background: rgba(255,255,255,0.1); padding: 5px 12px; border-radius: 20px; font-size: 0.85em; font-weight: 600; border: 1px solid rgba(255,255,255,0.1); }
		
		.rmm-alert { padding: 15px 20px; border-radius: 8px; margin-bottom: 25px; border-left: 4px solid; font-weight: 500; }
		.rmm-alert-success { background-color: #ecfdf5; border-color: #10b981; color: #065f46; }
		.rmm-alert-error { background-color: #fef2f2; border-color: #ef4444; color: #991b1b; }

		.rmm-section { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 30px; margin-bottom: 30px; box-shadow: 0 1px 3px rgba(0,0,0,0.02); }
		.rmm-section h2 { margin: 0 0 5px 0; font-size: 1.4em; color: #0f172a; font-weight: 700; }
		.rmm-section-desc { margin: 0 0 25px 0; color: #64748b; font-size: 0.95em; }

		.rmm-form-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px 25px; }
		.rmm-grid-3 { grid-template-columns: repeat(3, 1fr); }

		/* Tabs System */
		.rmm-nav-tabs { list-style: none; padding: 0; margin: 0 0 30px 0; display: flex; border-bottom: 2px solid #cbd5e1; }
		.rmm-nav-tabs li { margin: 0; }
		.rmm-nav-tabs li a { display: block; padding: 12px 24px; color: #64748b; text-decoration: none; border-bottom: 2px solid transparent; font-weight: 600; font-size: 1.05em; transition: all 0.2s; margin-bottom: -2px; }
		.rmm-nav-tabs li.active a, .rmm-nav-tabs li a:hover { color: #10b981; border-bottom-color: #10b981; }

		.rmm-tab-pane { display: none; }
		.rmm-tab-pane.active { display: block; }

		/* Roles Table */
		.rmm-roles-table { width: 100%; border-collapse: collapse; margin-top: 15px; border: 1px solid #cbd5e1; border-radius: 8px; overflow: hidden; }
		.rmm-roles-table th, .rmm-roles-table td { padding: 15px; text-align: left; border-bottom: 1px solid #cbd5e1; vertical-align: middle; }
		.rmm-roles-table th { background: #f1f5f9; font-weight: 700; color: #1e293b; font-size: 0.9em; text-transform: uppercase; letter-spacing: 0.5px; }
		.rmm-roles-table tr:hover td { background: #f8fafc; }
		.rmm-role-icon-preview { width: 36px; height: 36px; display: flex; align-items: center; justify-content: center; background: #e2e8f0; border-radius: 6px; border: 1px solid #cbd5e1; overflow: hidden; }
		.rmm-form-group { display: flex; flex-direction: column; }
		.rmm-form-group.span-2 { grid-column: span 2; }
		.rmm-form-group label { font-weight: 600; color: #334155; margin-bottom: 8px; font-size: 0.9em; }
		.rmm-form-group input, .rmm-form-group textarea { background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px; padding: 10px 14px; font-size: 0.95em; transition: all 0.2s; color: #1e293b; }
		.rmm-form-group input:focus, .rmm-form-group textarea:focus { border-color: #10b981; outline: none; background: #fff; box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.1); }
		.rmm-optional { font-weight: normal; color: #94a3b8; font-size: 0.85em; }
		.rmm-field-desc { margin-top: 5px; font-size: 0.82em; color: #64748b; }

		.rmm-submit-row { margin-top: 20px; display: flex; justify-content: flex-end; }
		.rmm-submit-row .button-primary { background: #10b981 !important; border-color: #10b981 !important; border-radius: 8px !important; box-shadow: none !important; font-weight: 600 !important; font-size: 1.05em !important; padding: 12px 24px !important; height: auto !important; transition: all 0.2s !important; }
		.rmm-submit-row .button-primary:hover { background: #059669 !important; border-color: #059669 !important; transform: translateY(-1px); }

		textarea.code { font-family: monospace; font-size: 12px; line-height: 1.5; background: #0f172a !important; color: #38bdf8 !important; border-color: #334155 !important; }
		textarea.code:focus { border-color: #10b981 !important; }

		@media (max-width: 782px) {
			.rmm-form-grid { grid-template-columns: 1fr; }
			.rmm-grid-3 { grid-template-columns: 1fr; }
		}
		</style>
		<?php
	}

	/**
	 * Render: Server Management Tools Page
	 */
	public function render_server_management_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'No tienes permisos suficientes para acceder a esta página.', 'reforger-milsim' ) );
		}

		$ptero = new RMM_Pterodactyl_Handler();
		$servers = $ptero->get_servers();
		$api_url = plugins_url( 'api.php', __FILE__ );
		?>
		<div class="wrap rmm-admin-wrap rmm-dark-theme">
			<div class="rmm-admin-header">
				<h1>🎮 Gestión de Servidor Reforger</h1>
				<p class="rmm-version">v<?php echo RMM_VERSION; ?></p>
			</div>

			<!-- Nav Tabs -->
			<ul class="rmm-nav-tabs">
				<li class="active"><a href="#launcher-pane" data-tab="launcher"><i class="fa-solid fa-rocket"></i> Lanzar Partida</a></li>
				<li><a href="#generator-pane" data-tab="generator"><i class="fa-solid fa-file-code"></i> Generador JSON</a></li>
				<li><a href="#avisos-pane" data-tab="avisos"><i class="fa-solid fa-bullhorn"></i> Avisos</a></li>
				<li><a href="#monitor-pane" data-tab="monitor"><i class="fa-solid fa-gauge-high"></i> Monitor</a></li>
			</ul>

			<div class="rmm-tab-content">
				<!-- Tab 1: Lanzador -->
				<div class="rmm-tab-pane active" id="launcher-pane">
					<div class="rmm-section">
						<h2>🚀 Lanzador y Cargador de Presets</h2>
						<p class="rmm-section-desc">Selecciona un servidor Reforger y carga una configuración guardada. Esto aplicará los mods, escenario y reiniciará el servidor de forma automatizada.</p>

						<div class="rmm-form-grid">
							<div class="rmm-form-group">
								<label for="ptero_server_select">Servidor de Destino</label>
								<select id="ptero_server_select" class="rmm-select">
									<option value="">-- Seleccionar Servidor --</option>
									<?php foreach ( $servers as $srv ) : ?>
										<option value="<?php echo esc_attr( $srv['id'] ); ?>"><?php echo esc_html( $srv['name'] ); ?> (<?php echo esc_html( $srv['id'] ); ?>)</option>
									<?php endforeach; ?>
								</select>
							</div>

							<div class="rmm-form-group">
								<label for="ptero_preset_select">Partida / Preset Guardado</label>
								<select id="ptero_preset_select" class="rmm-select" disabled>
									<option value="">-- Selecciona Servidor Primero --</option>
								</select>
							</div>
						</div>

						<!-- Preset Details Visualizer -->
						<div id="ptero_preset_details" class="rmm-preset-details-box d-none">
							<h3 id="ptero_detail_name">--</h3>
							<p id="ptero_detail_desc" class="desc">--</p>
							<div class="meta-row">
								<span class="meta-item">🗺️ Escenario: <code id="ptero_detail_scenario">--</code></span>
								<span class="meta-item">💾 Persistencia: <span id="ptero_detail_persist" class="badge">--</span></span>
								<span class="meta-item">🧩 Addons: <span id="ptero_detail_mods_count">0</span></span>
							</div>

							<div class="mods-section">
								<h4>Lista de Mods Activa</h4>
								<div class="mods-scroll" id="ptero_detail_mods_list"></div>
							</div>

							<div class="launcher-actions">
								<button id="btn_upload_orbatlink" class="button-launch" style="background:#6366f1;box-shadow:0 4px 14px rgba(99,102,241,0.3);margin-right:10px;">🔗 Subir Config ORBAT Link</button>
								<button id="btn_launch_server" class="button-launch">🚀 Lanzar Partida en Servidor</button>
							</div>
						</div>

						<!-- Live Console Status Output -->
						<div id="launcher_console_box" class="rmm-console-box d-none">
							<div class="console-header">
								<span>📟 Consola de Operaciones</span>
								<button class="btn-console-clear" onclick="jQuery('#launcher_console_output').empty();">Clear</button>
							</div>
							<div class="console-output" id="launcher_console_output"></div>
						</div>
					</div>
				</div>

				<!-- Tab 2: Generador JSON -->
				<div class="rmm-tab-pane" id="generator-pane">
					<div class="rmm-section">
						<div class="d-flex justify-content-between align-items-center mb-3">
							<h2>🛠️ Crear y Guardar Configuración (JSON)</h2>
							<div>
								<button type="button" class="rmm-btn btn-outline-info" id="btnLoadPreset">📂 Cargar de LocalStorage</button>
								<button type="button" class="rmm-btn btn-outline-success" id="btnSavePreset">💾 Guardar en LocalStorage</button>
							</div>
						</div>
						<p class="rmm-section-desc">Genera la estructura de configuracion requerida para partidas de Reforger. Permite importar dependencias desde Steam Workshop de forma directa y subir la partida resultante como preset al servidor Pterodactyl.</p>

						<!-- Extractor de dependencias (arriba) -->
						<div style="background:#1c2128;border:1px solid #30363d;border-radius:6px;padding:12px 16px;margin-bottom:16px;">
							<div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;">
								<span style="font-size:1rem;">🔍</span>
								<strong style="color:#849b4c;font-size:0.8rem;text-transform:uppercase;">Extraer dependencias de Steam Workshop</strong>
							</div>
							<p style="color:#8b949e;font-size:0.7rem;margin:0 0 10px;">Pega un ID de mod de Steam (ej: 3010275991) o un UUID de Reforger Workshop. El sistema buscara automaticamente todas sus dependencias y las añadira a la lista de mods.</p>
							<div style="display:flex;gap:8px;">
								<input type="text" id="autoFetchId" placeholder="ID del Mod o Escenario del Workshop" style="flex:1;background:#0d1117;color:#c9d1d9;border:1px solid #21262d;padding:8px;border-radius:4px;">
								<button type="button" id="btnAutoFetch" class="rmm-btn btn-primary" style="white-space:nowrap;">🔍 Extraer e Insertar Dependencias</button>
							</div>
						</div>

						<form id="rmm_json_form" onsubmit="return false;">
							<div class="rmm-form-grid mb-4">
								<div class="rmm-form-group">
									<label>Nombre del Fichero (.json)</label>
									<div class="input-group">
										<input type="text" id="jsonFilename" value="OPERACION_VERSION_AUTOR" required>
										<span class="input-group-text">.json</span>
									</div>
								</div>
								<div class="rmm-form-group">
									<label>Nombre del Escenario / Preset</label>
									<input type="text" id="jsonName" placeholder="Cargando nombre automáticamente..." required>
								</div>
							</div>

							<div class="rmm-form-group mb-4">
								<label>Descripción de la Partida</label>
								<input type="text" id="jsonDesc" placeholder="Ej: Operación cooperativa PVE contra IA..." required>
							</div>

							<div class="rmm-form-group mb-4">
								<label>Scenario ID</label>
								<div class="input-group">
									<select id="scenarioSelect" style="max-width: 200px;">
										<option value="">-- Escenarios Prefijados --</option>
										<option value="{ED7583D38AC6E02D}Missions/GM_Helmand.conf">Helmand (Phoenix Studios)</option>
										<option value="{1A07351573BDF963}Missions/GM_Anizay.conf">Anizay</option>
										<option value="{03D2E1730A11E50C}Missions/GameMaster_Kunar.conf">KUNAR</option>
										<option value="{33FEAA1AF3A99AF2}Missions/GM_Zarichne.conf">Zarichne</option>
									</select>
									<input type="text" id="jsonScenarioId" placeholder="Ej: {E2BAD09BDB9F96AB}Missions/ZARICHNE_ULTIMATE.conf" required>
								</div>
							</div>

							<!-- Persistence block -->
							<div class="rmm-persistence-box mb-4">
								<div class="form-check-switch">
									<input type="checkbox" id="jsonPersistence">
									<label for="jsonPersistence"><strong>Habilitar Persistencia</strong></label>
								</div>
								<div id="persistenceOptions" class="d-none mt-3">
									<div class="rmm-form-grid rmm-grid-3">
										<div class="rmm-form-group">
											<label>Auto-save Intervalo (minutos)</label>
											<input type="number" id="persistInterval" value="5" min="1">
										</div>
										<div class="rmm-form-group">
											<label>Hive ID</label>
											<input type="number" id="persistHive" value="0">
										</div>
										<div class="rmm-form-group">
											<label>Base de Datos (Session)</label>
											<input type="text" id="persistDb" value="main">
										</div>
									</div>
								</div>
							</div>

							<hr class="rmm-divider">

							<!-- Addons Section -->
							<h3>🧩 Añadir Mods y Dependencias</h3>
							<div class="addon-quick-buttons mb-4">
								<button type="button" id="btnAddBase" class="rmm-btn btn-primary">📦 Añadir Base Addons</button>
								<button type="button" id="btnAddACE" class="rmm-btn btn-danger">🚑 Añadir todo ACE</button>
								<button type="button" id="btnAddRHS" class="rmm-btn btn-success">🪖 Añadir todo RHS</button>
							</div>

							<div class="mb-4">
								<label class="rmm-label-bold">Mods Frecuentes (Haz clic para añadir rápido)</label>
								<div id="friendlyContainer" class="friendly-buttons-flow"></div>
							</div>

							<hr class="rmm-divider">

							<!-- Active mods table and editor -->
							<div class="rmm-editor-layout">
								<div class="layout-left">
									<h4>Lista de Mods Activa (<span id="modCount">0</span>)</h4>
									<div class="table-container">
										<table class="rmm-mods-table" id="addedModsTable">
											<thead>
												<tr>
													<th>Mod ID</th>
													<th>Nombre del Mod</th>
													<th style="width:50px;"></th>
												</tr>
											</thead>
											<tbody></tbody>
										</table>
										<div id="emptyModsMsg" class="table-empty">No hay mods añadidos a la lista.</div>
									</div>

									<div class="input-row mt-2">
										<input type="text" id="manualModId" placeholder="Mod ID (32 bytes)" style="max-width: 200px;">
										<input type="text" id="manualModName" placeholder="Nombre descriptivo">
										<button type="button" id="btnAddManual" class="rmm-btn btn-secondary">Añadir Manual</button>
									</div>
								</div>

								<div class="layout-right">
									<label>📝 Live JSON (Editable a mano)</label>
									<textarea id="liveJson" rows="12"></textarea>
									<button type="button" id="btnApplyLive" class="rmm-btn btn-warning mt-2 w-100">Aplicar Cambios JSON al Formulario</button>
								</div>
							</div>

							<!-- Action Buttons -->
							<div class="form-final-actions mt-4">
								<button type="button" id="btnDownloadJson" class="rmm-btn btn-large btn-success">💾 Descargar Fichero .JSON</button>
								<button type="button" id="btnSaveToPtero" class="rmm-btn btn-large btn-primary">⬆️ Guardar como Preset en Panel Pterodactyl</button>
							</div>
						</form>
					</div>
				</div>
			</div>

<!-- Tab 3: Avisos Telegram -->
<div class="rmm-tab-pane" id="avisos-pane">
	<div class="rmm-section">
		<h2><i class="fa-solid fa-bullhorn"></i> Enviar Aviso al Chat de Telegram</h2>
		<p class="rmm-section-desc">Redacta y envía mensajes de aviso al grupo/canal de Telegram configurado. Útil para notificar a la unidad de eventos, cambios de servidor u operaciones.</p>
		
		<div class="rmm-form-group mb-3">
			<label for="aviso_title">Título del Aviso</label>
			<input type="text" id="aviso_title" class="rmm-input" placeholder="Ej: OPERACIÓN HELMAND - VIERNES 20:00" style="width:100%; max-width:600px;">
		</div>
		
		<div class="rmm-form-group mb-3">
			<label for="aviso_message">Mensaje</label>
			<textarea id="aviso_message" class="rmm-textarea" rows="6" placeholder="Escribe aquí el mensaje que se enviará al chat de Telegram. Puedes usar Markdown básico." style="width:100%; max-width:600px;"></textarea>
		</div>
		
		<div class="rmm-form-group mb-3">
			<label>Formato Rápido</label>
			<div class="rmm-quick-buttons" style="display:flex; gap:8px; flex-wrap:wrap;">
				<button type="button" class="rmm-btn btn-outline-info btn-sm" onclick="insertTemplate('reunion')"><i class="fa-solid fa-users"></i> Reunión</button>
				<button type="button" class="rmm-btn btn-outline-warning btn-sm" onclick="insertTemplate('operacion')"><i class="fa-solid fa-shield-halved"></i> Operación</button>
				<button type="button" class="rmm-btn btn-outline-success btn-sm" onclick="insertTemplate('servidor')"><i class="fa-solid fa-server"></i> Servidor</button>
				<button type="button" class="rmm-btn btn-outline-danger btn-sm" onclick="insertTemplate('urgente')"><i class="fa-solid fa-triangle-exclamation"></i> Urgente</button>
				<button type="button" class="rmm-btn btn-outline-info btn-sm" onclick="insertTemplate('recordatorio')"><i class="fa-solid fa-clock"></i> Recordatorio</button>
			</div>
		</div>
		
		<div class="rmm-actions mt-4">
			<button type="button" id="btn_send_aviso" class="rmm-btn btn-primary btn-large"><i class="fa-solid fa-paper-plane"></i> Enviar Aviso a Telegram</button>
			<span id="aviso_status" style="margin-left: 12px; font-size: 0.85rem;"></span>
		</div>
		
		<div class="rmm-aviso-preview mt-4" id="aviso_preview" style="display:none;">
			<h4 style="color:#8b949e; margin-bottom:8px;"><i class="fa-solid fa-eye"></i> Vista Previa</h4>
			<div id="aviso_preview_content" style="background:#161b22; border:1px solid #21262d; border-radius:6px; padding:16px; font-family:monospace; font-size:0.8rem; color:#c9d1d9; white-space:pre-wrap;"></div>
		</div>
	</div>
</div>

<!-- Tab 4: Monitor del Servidor -->
<div class="rmm-tab-pane" id="monitor-pane">
	<div class="rmm-section">
		<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
			<h2 style="margin:0;"><i class="fa-solid fa-gauge-high"></i> Monitor del Servidor en Vivo</h2>
			<button type="button" id="btn_refresh_monitor" class="rmm-btn btn-outline-info btn-sm"><i class="fa-solid fa-rotate"></i> Actualizar</button>
		</div>
		<p class="rmm-section-desc">Estado en tiempo real del servidor Reforger. Se actualiza automáticamente cada 30 segundos.</p>
		
		<div id="monitor_content" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(280px, 1fr)); gap:20px;">
			<!-- Status Card -->
			<div class="rmm-monitor-card" style="background:#161b22; border:1px solid #21262d; border-radius:8px; padding:20px;">
				<h4 style="margin:0 0 14px; font-size:0.7rem; text-transform:uppercase; letter-spacing:0.06em; color:#8b949e;"><i class="fa-solid fa-power-off"></i> Estado</h4>
				<div id="monitor_status">
					<span style="color:#484f58;">Cargando...</span>
				</div>
			</div>
			
			<!-- CPU Card -->
			<div class="rmm-monitor-card" style="background:#161b22; border:1px solid #21262d; border-radius:8px; padding:20px;">
				<h4 style="margin:0 0 14px; font-size:0.7rem; text-transform:uppercase; letter-spacing:0.06em; color:#8b949e;"><i class="fa-solid fa-microchip"></i> CPU</h4>
				<div id="monitor_cpu">
					<span style="color:#484f58;">Cargando...</span>
				</div>
			</div>
			
			<!-- RAM Card -->
			<div class="rmm-monitor-card" style="background:#161b22; border:1px solid #21262d; border-radius:8px; padding:20px;">
				<h4 style="margin:0 0 14px; font-size:0.7rem; text-transform:uppercase; letter-spacing:0.06em; color:#8b949e;"><i class="fa-solid fa-memory"></i> RAM</h4>
				<div id="monitor_ram">
					<span style="color:#484f58;">Cargando...</span>
				</div>
			</div>
			
			<!-- Disco Card -->
			<div class="rmm-monitor-card" style="background:#161b22; border:1px solid #21262d; border-radius:8px; padding:20px;">
				<h4 style="margin:0 0 14px; font-size:0.7rem; text-transform:uppercase; letter-spacing:0.06em; color:#8b949e;"><i class="fa-solid fa-hard-drive"></i> Disco</h4>
				<div id="monitor_disk">
					<span style="color:#484f58;">Cargando...</span>
				</div>
			</div>
			
			<!-- Partida Card -->
			<div class="rmm-monitor-card" style="background:#161b22; border:1px solid #21262d; border-radius:8px; padding:20px;">
				<h4 style="margin:0 0 14px; font-size:0.7rem; text-transform:uppercase; letter-spacing:0.06em; color:#8b949e;"><i class="fa-solid fa-gamepad"></i> Partida Actual</h4>
				<div id="monitor_game">
					<span style="color:#484f58;">Cargando...</span>
				</div>
			</div>
			
			<!-- Acciones Card -->
			<div class="rmm-monitor-card" style="background:#161b22; border:1px solid #21262d; border-radius:8px; padding:20px;">
				<h4 style="margin:0 0 14px; font-size:0.7rem; text-transform:uppercase; letter-spacing:0.06em; color:#8b949e;"><i class="fa-solid fa-play"></i> Acciones Rápidas</h4>
				<div style="display:flex; flex-direction:column; gap:8px;">
					<button type="button" id="btn_monitor_start" class="rmm-btn btn-success btn-sm w-100"><i class="fa-solid fa-play"></i> Iniciar Servidor</button>
					<button type="button" id="btn_monitor_restart" class="rmm-btn btn-warning btn-sm w-100"><i class="fa-solid fa-rotate"></i> Reiniciar Servidor</button>
					<button type="button" id="btn_monitor_stop" class="rmm-btn btn-danger btn-sm w-100"><i class="fa-solid fa-stop"></i> Detener Servidor</button>
				</div>
			</div>
		</div>
	</div>
</div>

		</div>
	</div>

		<!-- LocalStorage Presets Load Modal -->
		<div class="rmm-modal" id="loadModal">
			<div class="rmm-modal-content">
				<div class="modal-header">
					<h3>Cargar Preset de LocalStorage</h3>
					<span class="modal-close" onclick="jQuery('#loadModal').fadeOut();">&times;</span>
				</div>
				<div class="modal-body">
					<div class="list-group" id="presetList"></div>
				</div>
			</div>
		</div>

		<!-- Save to Pterodactyl Modal -->
		<div class="rmm-modal" id="savePteroModal">
			<div class="rmm-modal-content">
				<div class="modal-header">
					<h3>Guardar en Servidor Pterodactyl</h3>
					<span class="modal-close" onclick="jQuery('#savePteroModal').fadeOut();">&times;</span>
				</div>
				<div class="modal-body">
					<div class="rmm-form-group mb-3">
						<label>Selecciona Servidor Pterodactyl</label>
						<select id="save_ptero_server_select" class="rmm-select" style="background:#1e293b; color:#fff; border-color:#475569;">
							<?php foreach ( $servers as $srv ) : ?>
								<option value="<?php echo esc_attr( $srv['id'] ); ?>"><?php echo esc_html( $srv['name'] ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="rmm-form-group mb-3">
						<label>Nombre del Preset (.json)</label>
						<input type="text" id="save_ptero_filename" style="background:#1e293b; color:#fff; border-color:#475569;">
					</div>
					<div class="d-flex justify-content-end mt-4">
						<button type="button" class="rmm-btn btn-secondary me-2" onclick="jQuery('#savePteroModal').fadeOut();">Cancelar</button>
						<button type="button" id="btnConfirmSavePtero" class="rmm-btn btn-success">Subir Preset</button>
					</div>
				</div>
			</div>
		</div>

		<!-- STYLES -->
		<style>
		.rmm-dark-theme { background: #0f172a; color: #f8fafc; padding: 20px; border-radius: 12px; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
		.rmm-dark-theme h1, .rmm-dark-theme h2, .rmm-dark-theme h3, .rmm-dark-theme h4 { color: #f8fafc; }
		.rmm-dark-theme .rmm-admin-header { background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%); border-color: #334155; }
		
		.rmm-nav-tabs { list-style: none; padding: 0; margin: 0 0 20px 0; display: flex; border-bottom: 2px solid #334155; }
		.rmm-nav-tabs li { margin: 0; }
		.rmm-nav-tabs li a { display: block; padding: 12px 24px; color: #94a3b8; text-decoration: none; border-bottom: 2px solid transparent; font-weight: 600; font-size: 1.05em; transition: all 0.2s; margin-bottom: -2px; }
		.rmm-nav-tabs li.active a, .rmm-nav-tabs li a:hover { color: #10b981; border-bottom-color: #10b981; }

		.rmm-tab-pane { display: none; }
		.rmm-tab-pane.active { display: block; }

		.rmm-dark-theme .rmm-section { background: #1e293b; border: 1px solid #334155; border-radius: 12px; padding: 30px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); }
		.rmm-dark-theme .rmm-section-desc { color: #94a3b8; }
		
		.rmm-dark-theme .rmm-form-group label { color: #cbd5e1; font-weight: 600; font-size: 0.9em; margin-bottom: 8px; }
		.rmm-dark-theme .rmm-form-group input, 
		.rmm-dark-theme .rmm-form-group select, 
		.rmm-dark-theme .rmm-form-group textarea { background: #0f172a; border: 1px solid #475569; color: #f8fafc; border-radius: 8px; padding: 10px 14px; transition: all 0.2s; font-size: 0.95em; }
		.rmm-dark-theme .rmm-form-group input:focus, 
		.rmm-dark-theme .rmm-form-group select:focus, 
		.rmm-dark-theme .rmm-form-group textarea:focus { border-color: #10b981; box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.2); outline: none; }

		/* Input group */
		.input-group { display: flex; align-items: stretch; width: 100%; }
		.input-group input, .input-group select { border-top-right-radius: 0 !important; border-bottom-right-radius: 0 !important; flex: 1; }
		.input-group-text { background: #334155; border: 1px solid #475569; border-left: 0; color: #94a3b8; display: flex; align-items: center; padding: 0 16px; border-top-right-radius: 8px; border-bottom-right-radius: 8px; font-weight: 600; }

		.rmm-select { width: 100%; height: 42px; cursor: pointer; }

		/* Preset visualizer box */
		.rmm-preset-details-box { background: #0f172a; border: 1px solid #334155; border-radius: 10px; padding: 25px; margin-top: 30px; }
		.rmm-preset-details-box h3 { margin-top: 0; font-size: 1.5em; color: #10b981; }
		.rmm-preset-details-box .desc { color: #94a3b8; font-size: 0.95em; margin-bottom: 20px; }
		.meta-row { display: flex; gap: 30px; font-size: 0.9em; padding-bottom: 15px; border-bottom: 1px solid #334155; margin-bottom: 20px; }
		.meta-item code { background: #334155; color: #cbd5e1; padding: 3px 8px; border-radius: 4px; font-size: 0.95em; }
		.badge { background: #10b981; color: #fff; padding: 3px 10px; border-radius: 12px; font-size: 0.85em; font-weight: 600; }
		.badge.disabled { background: #ef4444; }

		.mods-section h4 { font-size: 1.1em; color: #f8fafc; margin-bottom: 12px; }
		.mods-scroll { max-height: 250px; overflow-y: auto; background: #1e293b; border: 1px solid #334155; border-radius: 6px; padding: 12px; }
		.mod-pill { background: #0f172a; border: 1px solid #334155; border-radius: 6px; padding: 8px 12px; display: inline-flex; justify-content: space-between; align-items: center; width: 100%; margin-bottom: 6px; box-sizing: border-box; }
		.mod-pill:last-child { margin-bottom: 0; }
		.mod-pill code { color: #38bdf8; font-weight: 600; font-size: 0.9em; margin-right: 15px; }
		.mod-pill span { color: #cbd5e1; font-size: 0.9em; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

		.launcher-actions { margin-top: 25px; display: flex; justify-content: flex-end; }
		.button-launch { background: #10b981; color: #fff; border: 0; padding: 14px 28px; border-radius: 8px; font-size: 1.1em; font-weight: bold; cursor: pointer; transition: all 0.2s; box-shadow: 0 4px 14px rgba(16, 185, 129, 0.3); }
		.button-launch:hover { background: #059669; transform: translateY(-1px); }

		/* Console */
		.rmm-console-box { margin-top: 30px; background: #000; border: 1px solid #334155; border-radius: 8px; overflow: hidden; font-family: monospace; }
		.console-header { background: #1e293b; padding: 10px 15px; display: flex; justify-content: space-between; align-items: center; font-weight: 600; font-size: 0.85em; border-bottom: 1px solid #334155; }
		.btn-console-clear { background: transparent; border: 1px solid #475569; color: #cbd5e1; padding: 2px 8px; border-radius: 4px; cursor: pointer; font-size: 0.8em; }
		.btn-console-clear:hover { background: #334155; color: #fff; }
		.console-output { padding: 15px; max-height: 250px; overflow-y: auto; color: #00ff95; font-size: 0.9em; line-height: 1.5; white-space: pre-wrap; }

		/* Generator specific */
		.rmm-persistence-box { background: #0f172a; border: 1px solid #334155; border-radius: 8px; padding: 20px; }
		.form-check-switch { display: flex; align-items: center; gap: 10px; }
		.form-check-switch input { width: 18px; height: 18px; cursor: pointer; }
		.form-check-switch label { cursor: pointer; color: #f8fafc; font-size: 1em; }
		.rmm-divider { border: 0; border-top: 1px solid #334155; margin: 30px 0; }

		.addon-quick-buttons { display: flex; gap: 15px; }
		.rmm-btn { background: #334155; border: 1px solid #475569; color: #f8fafc; border-radius: 6px; padding: 8px 16px; cursor: pointer; font-weight: 600; font-size: 0.9em; transition: all 0.2s; display: inline-flex; align-items: center; justify-content: center; }
		.rmm-btn:hover { background: #475569; border-color: #64748b; }
		.rmm-btn.btn-primary { background: #2563eb; border-color: #2563eb; }
		.rmm-btn.btn-primary:hover { background: #1d4ed8; }
		.rmm-btn.btn-danger { background: #dc2626; border-color: #dc2626; }
		.rmm-btn.btn-danger:hover { background: #b91c1c; }
		.rmm-btn.btn-success { background: #16a34a; border-color: #16a34a; }
		.rmm-btn.btn-success:hover { background: #15803d; }
		.rmm-btn.btn-warning { background: #ca8a04; border-color: #ca8a04; color: #fff; }
		.rmm-btn.btn-warning:hover { background: #a16207; }
		.rmm-btn.btn-secondary { background: #475569; border-color: #475569; }
		.rmm-btn.btn-secondary:hover { background: #334155; }
		.rmm-btn.btn-outline-info { background: transparent; border-color: #0ea5e9; color: #0ea5e9; }
		.rmm-btn.btn-outline-info:hover { background: #0ea5e9; color: #fff; }
		.rmm-btn.btn-outline-success { background: transparent; border-color: #10b981; color: #10b981; }
		.rmm-btn.btn-outline-success:hover { background: #10b981; color: #fff; }
		.rmm-btn.btn-large { padding: 12px 24px; font-size: 1.05em; border-radius: 8px; }

		.friendly-buttons-flow { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px; }
		.friendly-buttons-flow button { background: #1e293b; border: 1px solid #334155; color: #cbd5e1; border-radius: 4px; padding: 6px 12px; font-size: 0.85em; cursor: pointer; transition: all 0.2s; }
		.friendly-buttons-flow button:hover { background: #334155; border-color: #475569; color: #fff; transform: translateY(-1px); }

		.rmm-editor-layout { display: grid; grid-template-columns: 1.5fr 1fr; gap: 30px; }
		.table-container { background: #0f172a; border: 1px solid #334155; border-radius: 8px; max-height: 350px; overflow-y: auto; padding: 10px; }
		.rmm-mods-table { width: 100%; border-collapse: collapse; text-align: left; }
		.rmm-mods-table th { border-bottom: 2px solid #334155; padding: 10px; font-size: 0.85em; text-transform: uppercase; color: #94a3b8; }
		.rmm-mods-table td { padding: 10px; border-bottom: 1px solid #1e293b; font-size: 0.9em; color: #cbd5e1; }
		.rmm-mods-table tr:hover td { background: #1e293b; }
		.rmm-mods-table code { color: #38bdf8; font-weight: 600; }
		.table-empty { text-align: center; color: #64748b; padding: 30px 10px; font-size: 0.95em; }
		
		.input-row { display: flex; gap: 10px; }
		.input-row input { background: #0f172a; border: 1px solid #334155; color: #fff; border-radius: 6px; padding: 8px 12px; font-size: 0.9em; flex: 1; }
		.input-row input:focus { border-color: #10b981; outline: none; }

		.layout-right textarea { width: 100%; height: 350px; background: #0f172a; border: 1px solid #334155; color: #00ff95; font-family: monospace; border-radius: 8px; padding: 15px; font-size: 11px; line-height: 1.5; box-sizing: border-box; }
		.layout-right textarea:focus { border-color: #10b981; outline: none; }

		.form-final-actions { display: flex; gap: 20px; justify-content: flex-end; }

		/* Modals */
		.rmm-modal { display: none; position: fixed; z-index: 99999; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.8); backdrop-filter: blur(4px); }
		.rmm-modal-content { background-color: #1e293b; margin: 10% auto; padding: 25px; border: 1px solid #334155; width: 500px; border-radius: 12px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.3); color: #f8fafc; }
		.modal-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #334155; margin-bottom: 20px; padding-bottom: 10px; }
		.modal-header h3 { margin: 0; font-size: 1.3em; color: #f8fafc; }
		.modal-close { font-size: 1.8em; font-weight: bold; cursor: pointer; color: #94a3b8; }
		.modal-close:hover { color: #f8fafc; }
		
		.list-group { display: flex; flex-direction: column; gap: 8px; max-height: 300px; overflow-y: auto; }
		.list-group-item { background: #0f172a; border: 1px solid #334155; border-radius: 6px; padding: 12px 18px; color: #f8fafc; cursor: pointer; text-decoration: none; transition: all 0.2s; display: flex; justify-content: space-between; align-items: center; }
		.list-group-item:hover { background: #334155; border-color: #475569; }

		.d-none { display: none !important; }
		.me-2 { margin-right: 8px; }
		.mt-2 { margin-top: 8px; }
		.mt-3 { margin-top: 15px; }
		.mt-4 { margin-top: 20px; }
		.mb-3 { margin-bottom: 15px; }
		.mb-4 { margin-bottom: 20px; }
		.w-100 { width: 100%; }
		.d-flex { display: flex; }
		.justify-content-between { justify-content: space-between; }
		.justify-content-end { justify-content: flex-end; }
		.align-items-center { align-items: center; }
		@keyframes rmmPulseOnline {
			0%, 100% { opacity: 1; }
			50% { opacity: 0.5; }
		}
		.rmm-pulse-online {
			animation: rmmPulseOnline 2s ease-in-out infinite;
		}
		.rmm-monitor-card {
			transition: border-color 0.3s ease, box-shadow 0.3s ease;
		}
		.rmm-monitor-card:hover {
			border-color: #22c55e40;
			box-shadow: 0 0 20px rgba(34,197,94,0.08);
		}
		.rmm-aviso-preview {
			animation: rmmFadeIn 0.3s ease;
		}
		@keyframes rmmFadeIn {
			from { opacity: 0; transform: translateY(-10px); }
			to { opacity: 1; transform: translateY(0); }
		}
		.rmm-input, .rmm-textarea {
			background: #161b22 !important;
			border: 1px solid #30363d !important;
			color: #c9d1d9 !important;
			border-radius: 6px !important;
			padding: 8px 12px !important;
			font-family: 'Inter', sans-serif;
		}
		.rmm-input:focus, .rmm-textarea:focus {
			border-color: #22c55e !important;
			outline: none !important;
			box-shadow: 0 0 0 2px rgba(34,197,94,0.15) !important;
		}
		.rmm-textarea {
			resize: vertical;
			min-height: 140px;
		}
		.w-100 { width: 100% !important; }
		</style>

		<!-- JAVASCRIPT LOGIC -->
		<script>
		jQuery(document).ready(function($) {
			
			// === TABS SYSTEM ===
			$('.rmm-nav-tabs a').on('click', function(e) {
				e.preventDefault();
				const target = $(this).attr('href');
				$('.rmm-nav-tabs li').removeClass('active');
				$(this).parent().addClass('active');
				$('.rmm-tab-pane').removeClass('active');
				$(target).addClass('active');
			});

			// === LANZADOR DE PARTIDAS (TAB 1) ===
			const serverSelect = $('#ptero_server_select');
			const presetSelect = $('#ptero_preset_select');
			const detailBox = $('#ptero_preset_details');
			const btnLaunch = $('#btn_launch_server');
			const consoleBox = $('#launcher_console_box');
			const consoleOutput = $('#launcher_console_output');

			function logConsole(msg, isError = false) {
				const timestamp = new Date().toLocaleTimeString();
				const color = isError ? 'red' : '#00ff95';
				consoleOutput.append(`<div style="color:${color}">[${timestamp}] ${msg}</div>`);
				consoleOutput.scrollTop(consoleOutput[0].scrollHeight);
			}

			serverSelect.on('change', function() {
				const serverId = $(this).val();
				presetSelect.empty().prop('disabled', true);
				detailBox.addClass('d-none');

				if (!serverId) {
					presetSelect.append('<option value="">-- Selecciona Servidor Primero --</option>');
					return;
				}

				presetSelect.append('<option value="">⏳ Cargando partidas...</option>');
				
				$.post(ajaxurl, {
					action: 'rmm_get_server_presets',
					server_id: serverId
				}, function(res) {
					presetSelect.empty();
					if (res.success && res.data && res.data.length > 0) {
						presetSelect.append('<option value="">-- Seleccionar Partida (Preset) --</option>');
						res.data.forEach(function(file) {
							presetSelect.append(`<option value="${file}">${file}</option>`);
						});
						presetSelect.prop('disabled', false);
					} else {
						presetSelect.append('<option value="">⚠️ No hay JSONs en /partidas</option>');
					}
				}).fail(function() {
					presetSelect.empty().append('<option value="">❌ Error al conectar con Pterodactyl</option>');
				});
			});

			presetSelect.on('change', function() {
				const serverId = serverSelect.val();
				const filename = $(this).val();
				detailBox.addClass('d-none');

				if (!serverId || !filename) return;

				$.post(ajaxurl, {
					action: 'rmm_get_preset_details',
					server_id: serverId,
					filename: filename
				}, function(res) {
					if (res.success && res.data) {
						const data = res.data;
						const exportObj = data.ptero_export || data;

						$('#ptero_detail_name').text(data.name || filename);
						$('#ptero_detail_desc').text(data.description || 'Sin descripción.');
						$('#ptero_detail_scenario').text(exportObj.scenarioId || '--');
						
						const hasPersist = exportObj.persistence ? 'Habilitada' : 'Deshabilitada';
						const badge = $('#ptero_detail_persist');
						badge.text(hasPersist);
						if (exportObj.persistence) {
							badge.removeClass('disabled');
						} else {
							badge.addClass('disabled');
						}

						const mods = exportObj.mods || [];
						$('#ptero_detail_mods_count').text(mods.length);

						const listDiv = $('#ptero_detail_mods_list').empty();
						if (mods.length === 0) {
							listDiv.append('<div class="text-muted p-2">Sin mods requeridos.</div>');
						} else {
							mods.forEach(function(m) {
								listDiv.append(`
									<div class="mod-pill">
										<code>${m.modId}</code>
										<span>${m.name || m.modId}</span>
									</div>
								`);
							});
						}

						detailBox.removeClass('d-none');
					} else {
						alert('Error al leer el archivo JSON de partida.');
					}
				});
			});

			// Boton subir solo config ORBAT Link
			$('#btn_upload_orbatlink').on('click', function() {
				const serverId = serverSelect.val();
				if ( ! serverId ) { alert('Selecciona un servidor primero'); return; }
				const btn = $(this);
				btn.prop('disabled', true).text('Subiendo...');
				logConsole('🔹 Subiendo config de ORBAT Link...');
				$.post(ajaxurl, {
					action: 'rmm_upload_orbatlink_config',
					server_id: serverId
				}, function(res) {
					if (res.success) {
						logConsole('✅ Config de ORBAT Link subido correctamente');
					} else {
						logConsole('❌ Error: ' + (res.data || 'Desconocido'), true);
					}
					btn.prop('disabled', false).text('🔗 Subir Config ORBAT Link');
				});
			});

			btnLaunch.on('click', function() {
				const serverId = serverSelect.val();
				const filename = presetSelect.val();

				if (!serverId || !filename) return;

				if (!confirm('¿Seguro que quieres cargar esta partida y reiniciar el servidor?')) return;

				consoleBox.removeClass('d-none');
				consoleOutput.empty();
				logConsole('🚀 Iniciando secuencia de despliegue de partida...');
				btnLaunch.prop('disabled', true);

				$.post(ajaxurl, {
					action: 'rmm_load_preset',
					server_id: serverId,
					filename: filename
				}, function(res) {
					if (res.data && res.data.progress) {
						res.data.progress.forEach(function(step) {
							logConsole(step);
						});
					}

					if (res.success) {
						logConsole('✅ ¡OPERACIÓN COMPLETADA CON ÉXITO! El servidor está reiniciándose.');
					} else {
						logConsole('❌ ERROR EN LA OPERACIÓN: ' + (res.data.error || 'Desconocido'), true);
					}
					btnLaunch.prop('disabled', false);
				}).fail(function(xhr) {
					logConsole('❌ Error de red o tiempo de espera agotado al conectar.', true);
					btnLaunch.prop('disabled', false);
				});
			});

			// === GENERADOR JSON (TAB 2) ===
			const BASE_ADDONS = [
				{ name: "ACE Compass", id: "60C53A9372ED3964" },
				{ name: "ACE Carrying", id: "5DBD560C5148E1DA" },
				{ name: "ACE Chopping", id: "5EB744C5F42E0800" },
				{ name: "ACE Trenches", id: "60EAEA0389DB3CC2" },
				{ name: "ACE Core", id: "60C4CE4888FF4621" },
				{ name: "ACE Magazine Repack", id: "611CB1D409001EB0" },
				{ name: "ACE Radio", id: "64475DC102F2BDA4" },
				{ name: "ACE Finger", id: "606C369BAC3F6CC3" },
				{ name: "ACE Medical Core", id: "60C4C12DAE90727B" },
				{ name: "ACE Captives", id: "646D52AF8BB3FF15" },
				{ name: "ACE Backblast", id: "60E573C9B04CC408" },
				{ name: "ACE Explosives", id: "61B7763A8AEB53B7" },
				{ name: "CRX Enfusion A.I.", id: "5F268647F8A1A1F4" },
				{ name: "Keep Abandoned Vehicles", id: "60E2D7E5A20FABEB" },
				{ name: "Keep Inventory and Bodies", id: "6470B20217F190A4" },
				{ name: "Keep Ragdoll Pose", id: "62113AA293414DBA" },
				{ name: "Map Drawing", id: "656AC01634459D8D" },
				{ name: "Wirecutters 2", id: "62F364B35E9B51B0" },
				{ name: "2-7 Vehicle Mirrors", "id": "661949530F712567" },
				{ name: "TacticalAnimationOverhaul TEST", "id": "61ECB5EFAA346151" },
				{ name: "BetterSounds 4.0 Alpha", id: "597C0CF3A7AA8A99" },
				{ name: "TFR ORBAT Link", id: "695EADEB970201A6" }
			];

			const FRIENDLY_ADDONS = [
				{ name: "ACE Tactical Periscope", id: "62F802951CC8A37E" },
				{ name: "ACE Tactical Ladder", id: "61226BB18D360BDD" },
				{ name: "RHS - Content Pack 01", id: "1337C0DE5DABBEEF" },
				{ name: "RHS - Content Pack 02", id: "BADC0DEDABBEDA5E" },
				{ name: "RHS - Status Quo", id: "595F2BF2F44836FB" },
				{ name: "RHS to Arsenal", id: "65B03287FEB20404" },
				{ name: "Bacon Loadout Editor", id: "606B100247F5C709" },
				{ name: "Bacon Suppressors", id: "5AB301290317994A" },
				{ name: "Realistic Combat Drones", id: "65AD60E204191D37" },
				{ name: "Realistic Combat Drones RHS", id: "65D0FE5B02FEC2D2" },
				{ name: "Immersive Head Movement", id: "6622D3D1E5A3809D" },
				{ name: "Night Vision System", id: "59A30ACC02650E71" },
				{ name: "FORTEX Gear Core", id: "66C2ED22F25B05C9" },
				{ name: "FORTEX Kalashnikov Collection", id: "650EAAF7FBE7D583" },
				{ name: "FORTEX - Russian Tactical Gear", id: "647CB046E0EDE0D9" },
				{ name: "Russian Spetsnaz Patches", id: "65D3358D0A9CE99D" },
				{ name: "Configurable Artillery for PVE", id: "6505032389D88D1E" },
				{ name: "Game Master FX", id: "5994AD5A9F33BE57" },
				{ name: "Zarichne Navmesh FIX", id: "66C8E1E5489E5EC3" },
				{ name: "ConflictPVERemixedVanilla2.0", id: "61B514B96692C049" },
				{ name: "TFR PATCH", id: "66CB5108446AA184" },
				{ name: "TFR US LOADOUTS", id: "684221F8B344391E" },
				{ name: "More scopes to RU side RHS", id: "61E0CB462120AE1C" }
			];

			FRIENDLY_ADDONS.sort((a, b) => a.name.localeCompare(b.name));

			// Fields
			const filenameInput = $('#jsonFilename');
			const nameInput = $('#jsonName');
			const descInput = $('#jsonDesc');
			const scenarioInput = $('#jsonScenarioId');
			const scenarioSelect = $('#scenarioSelect');
			const persistenceCheck = $('#jsonPersistence');
			const persistenceOptions = $('#persistenceOptions');
			const inputAutoSave = $('#persistInterval');
			const inputHive = $('#persistHive');
			const inputDb = $('#persistDb');
			
			const friendlyContainer = $('#friendlyContainer');
			const addedModsTable = $('#addedModsTable').find('tbody');
			const emptyModsMsg = $('#emptyModsMsg');
			const modCountSpan = $('#modCount');
			const liveJson = $('#liveJson');

			let currentMods = [];

			// Filename Sanitization
			filenameInput.on('input', function() {
				let val = $(this).val();
				val = val.replace(/ /g, '_');
				val = val.replace(/[^a-zA-Z0-9_]/g, '#');
				if ($(this).val() !== val) $(this).val(val);
				
				nameInput.val(val.replace(/_/g, ' '));
				updateLiveJson();
			});

			[inputAutoSave, inputHive, inputDb, nameInput, descInput, scenarioInput].forEach(function(el) {
				el.on('input', updateLiveJson);
			});

			scenarioSelect.on('change', function() {
				if ($(this).val()) {
					scenarioInput.val($(this).val());
					updateLiveJson();
				}
			});

			persistenceCheck.on('change', function() {
				if ($(this).is(':checked')) {
					persistenceOptions.removeClass('d-none');
				} else {
					persistenceOptions.addClass('d-none');
				}
				updateLiveJson();
			});

			// Render friendly buttons
			FRIENDLY_ADDONS.forEach(function(m) {
				friendlyContainer.append(`
					<button type="button" class="rmm-friendly-btn" title="ID: ${m.id}" data-id="${m.id}" data-name="${m.name}">${m.name}</button>
				`);
			});

			friendlyContainer.on('click', 'button', function() {
				const id = $(this).data('id');
				const name = $(this).data('name');
				addModToList(id, name);
			});

			// Quick inserts
			$('#btnAddBase').on('click', function() {
				BASE_ADDONS.forEach(function(m) {
					addModToList(m.id, m.name, false);
				});
				renderMods();
			});

			$('#btnAddACE').on('click', function() {
				const all = [...BASE_ADDONS, ...FRIENDLY_ADDONS];
				let count = 0;
				all.forEach(function(m) {
					if (/\bACE\b/i.test(m.name)) {
						if (addModToList(m.id, m.name, false)) count++;
					}
				});
				renderMods();
				if (count > 0) alert(`✅ Añadidos ${count} mods ACE.`);
			});

			$('#btnAddRHS').on('click', function() {
				const all = [...BASE_ADDONS, ...FRIENDLY_ADDONS];
				let count = 0;
				all.forEach(function(m) {
					if (/\bRHS\b/i.test(m.name)) {
						if (addModToList(m.id, m.name, false)) count++;
					}
				});
				renderMods();
				if (count > 0) alert(`✅ Añadidos ${count} mods RHS.`);
			});

			// Manual Insert
			$('#btnAddManual').on('click', function() {
				const id = $('#manualModId').val().trim();
				const name = $('#manualModName').val().trim();
				if (id) {
					addModToList(id, name || id);
					$('#manualModId').val('');
					$('#manualModName').val('');
				}
			});

			// Steam auto-fetch API
			$('#btnAutoFetch').on('click', function() {
				const id = $('#autoFetchId').val().trim();
				if (!id) return;

				const btn = $(this);
				btn.prop('disabled', true).text('⏳ Extrayendo dependencias...');

				// Llamamos a api.php de forma asíncrona pasándole la acción
				$.getJSON('<?php echo esc_url( $api_url ); ?>', {
					action: 'dependencies',
					id: id
				}, function(res) {
					if (res.error) {
						alert('Error de Steam API: ' + res.error);
					} else {
						let count = 0;
						if (res.item && res.item.id) {
							if (addModToList(res.item.id, res.item.title || res.item.id, false)) count++;
						}
						if (res.dependencies && Array.isArray(res.dependencies)) {
							res.dependencies.forEach(function(dep) {
								if (addModToList(dep.modId, dep.name || dep.modId, false)) count++;
							});
						}
						renderMods();
						alert(`✅ Se han insertado/actualizado ${count} mods.`);
						$('#autoFetchId').val('');
					}
					btn.prop('disabled', false).text('🔍 Extraer e Insertar Dependencias');
				}).fail(function() {
					alert('No se pudo conectar con api.php de Steam.');
					btn.prop('disabled', false).text('🔍 Extraer e Insertar Dependencias');
				});
			});

			function addModToList(id, name, autoRender = true) {
				if (currentMods.some(m => m.modId === id)) return false;
				currentMods.push({ modId: id, name: name });
				if (autoRender) renderMods();
				return true;
			}

			window.removeMod = function(id) {
				currentMods = currentMods.filter(m => m.modId !== id);
				renderMods();
			};

			function renderMods() {
				addedModsTable.empty();
				if (currentMods.length === 0) {
					emptyModsMsg.removeClass('d-none');
				} else {
					emptyModsMsg.addClass('d-none');
					currentMods.forEach(function(m) {
						addedModsTable.append(`
							<tr>
								<td><code>${m.modId}</code></td>
								<td>${m.name}</td>
								<td><button type="button" class="rmm-btn btn-danger" style="padding: 2px 6px; font-size: 11px;" onclick="removeMod('${m.modId}')">&times;</button></td>
							</tr>
						`);
					});
				}
				modCountSpan.text(currentMods.length);
				updateLiveJson();
			}

			function buildConfigObject() {
				const config = {
					name: nameInput.val(),
					description: descInput.val(),
					ptero_export: {
						mods: currentMods.map(m => ({ modId: m.modId, name: m.name })),
						scenarioId: scenarioInput.val()
					}
				};

				if (persistenceCheck.is(':checked')) {
					config.ptero_export.persistence = {
						autoSaveInterval: parseInt(inputAutoSave.val()) || 5,
						databases: {},
						hiveId: parseInt(inputHive.val()) || 0,
						storages: {
							session: { database: inputDb.val() || "main" }
						}
					};
				}
				return config;
			}

			function updateLiveJson() {
				const obj = buildConfigObject();
				liveJson.val(JSON.stringify(obj, null, 2));
			}

			// Apply manually modified JSON from textarea
			$('#btnApplyLive').on('click', function() {
				try {
					const obj = JSON.parse(liveJson.val());
					nameInput.val(obj.name || '');
					descInput.val(obj.description || '');

					if (obj.ptero_export) {
						scenarioInput.val(obj.ptero_export.scenarioId || '');

						if (Array.isArray(obj.ptero_export.mods)) {
							currentMods = obj.ptero_export.mods.map(m => ({
								modId: m.modId,
								name: m.name
							}));
							renderMods();
						}

						if (obj.ptero_export.persistence) {
							persistenceCheck.prop('checked', true);
							persistenceOptions.removeClass('d-none');
							const p = obj.ptero_export.persistence;
							inputAutoSave.val(p.autoSaveInterval ?? 5);
							inputHive.val(p.hiveId ?? 0);
							if (p.storages?.session?.database) {
								inputDb.val(p.storages.session.database);
							}
						} else {
							persistenceCheck.prop('checked', false);
							persistenceOptions.addClass('d-none');
						}
					}
					alert('✅ Campos del formulario actualizados con el contenido del JSON.');
				} catch (e) {
					alert('❌ JSON Inválido: ' + e.message);
				}
			});

			// Download json
			$('#btnDownloadJson').on('click', function() {
				const content = liveJson.val();
				const filename = (filenameInput.val() || 'reforger_partida') + '.json';
				const blob = new Blob([content], { type: 'application/json' });
				const url = URL.createObjectURL(blob);

				const a = document.createElement('a');
				a.href = url;
				a.download = filename;
				document.body.appendChild(a);
				a.click();
				document.body.removeChild(a);
				URL.revokeObjectURL(url);
			});

			// Save to localStorage
			$('#btnSavePreset').on('click', function() {
				const name = prompt('Introduce un nombre para el preset (LocalStorage):', filenameInput.val());
				if (!name) return;

				const presets = JSON.parse(localStorage.getItem('rmm_presets') || '{}');
				presets[name] = {
					filename: filenameInput.val(),
					config: buildConfigObject()
				};
				localStorage.setItem('rmm_presets', JSON.stringify(presets));
				alert('✅ Preset guardado en LocalStorage.');
			});

			// Load from localStorage
			$('#btnLoadPreset').on('click', function() {
				const list = $('#presetList').empty();
				const presets = JSON.parse(localStorage.getItem('rmm_presets') || '{}');
				const keys = Object.keys(presets);

				if (keys.length === 0) {
					list.append('<div class="list-group-item">No hay presets guardados en LocalStorage.</div>');
				} else {
					keys.forEach(function(k) {
						list.append(`
							<div class="list-group-item">
								<span class="load-click" style="flex:1; font-weight:600;">📁 ${k}</span>
								<button class="rmm-btn btn-danger btn-delete-preset" style="padding:4px 8px; font-size:11px;">🗑️</button>
							</div>
						`);
					});

					list.find('.load-click').on('click', function() {
						const k = $(this).parent().text().replace('📁 ', '').replace('🗑️', '').trim();
						const data = presets[k];
						if (data) {
							filenameInput.val(data.filename || '');
							liveJson.val(JSON.stringify(data.config, null, 2));
							$('#btnApplyLive').click();
							$('#loadModal').fadeOut();
						}
					});

					list.find('.btn-delete-preset').on('click', function(e) {
						e.stopPropagation();
						const k = $(this).parent().find('.load-click').text().replace('📁 ', '').trim();
						if (confirm(`¿Borrar preset "${k}"?`)) {
							delete presets[k];
							localStorage.setItem('rmm_presets', JSON.stringify(presets));
							$('#btnLoadPreset').click();
						}
					});
				}

				$('#loadModal').fadeIn();
			});

			// Save preset directly to Pterodactyl server
			$('#btnSaveToPtero').on('click', function() {
				const filename = filenameInput.val() + '.json';
				$('#save_ptero_filename').val(filename);
				$('#savePteroModal').fadeIn();
			});

			$('#btnConfirmSavePtero').on('click', function() {
				const serverId = $('#save_ptero_server_select').val();
				const filename = $('#save_ptero_filename').val().trim();
				const content = liveJson.val();

				if (!serverId || !filename) {
					alert('Por favor selecciona el servidor e indica el nombre del fichero.');
					return;
				}

				const btn = $(this);
				btn.prop('disabled', true).text('⏳ Subiendo preset...');

				$.post(ajaxurl, {
					action: 'rmm_save_server_preset',
					server_id: serverId,
					filename: filename,
					content: content
				}, function(res) {
					btn.prop('disabled', false).text('Subir Preset');
					if (res.success) {
						alert('✅ Preset guardado correctamente en la carpeta /partidas/ del servidor.');
						$('#savePteroModal').fadeOut();
						// Recargar select del lanzador si coincide
						if (serverSelect.val() === serverId) {
							serverSelect.trigger('change');
						}
					} else {
						alert('Error al guardar preset: ' + (res.data?.error || 'Desconocido'));
					}
				}).fail(function() {
					btn.prop('disabled', false).text('Subir Preset');
					alert('Error de red al conectar con el servidor.');
				});
			});

			// Inicialización
			updateLiveJson();
		});
		</script>

	<script>
	// ─────────────────────────────────────────────
	// FUNCIONES DE AVISOS
	// ─────────────────────────────────────────────

	var avisoTemplates = {
		reunion: {
			title: '📋 REUNIÓN DE UNIDAD',
			message: '*REUNIÓN DE UNIDAD*\n\n📅 Fecha: [PENDIENTE]\n🕐 Hora: [PENDIENTE]\n📍 Lugar: Canal de Discord / TeamSpeak\n\n*Orden del día:*\n• \n• \n• \n\nConfirma tu asistencia en la web.\n\nAtte. Estado Mayor [=TFR=]'
		},
		operacion: {
			title: '🎯 OPERACIÓN [NOMBRE]',
			message: '*OPERACIÓN PROGRAMADA*\n\n🎮 Misión: [NOMBRE]\n📅 Fecha: [PENDIENTE]\n🕐 Hora: [PENDIENTE]\n🗺️ Mapa: [PENDIENTE]\n\n*Requisitos:*\n• Mods actualizados\n• Asistencia y puntualidad\n\nReserva tu slot en la web. ¡No faltes!\n\nAtte. Estado Mayor [=TFR=]'
		},
		servidor: {
			title: '🖥️ ACTUALIZACIÓN DE SERVIDOR',
			message: '*SERVIDOR ACTUALIZADO*\n\n♻️ El servidor ha sido actualizado.\n\n🎮 Nueva partida: [NOMBRE]\n🗺️ Escenario: [SCENARIO]\n🧩 Mods: [NÚMERO] addons cargados\n\nConecta y verifica que todo funcione correctamente.\n\nAtte. Estado Mayor [=TFR=]'
		},
		urgente: {
			title: '⚠️ AVISO URGENTE',
			message: '*⚠️ AVISO URGENTE*\n\n[MENSAJE]\n\nPor favor, atiende este mensaje lo antes posible.\n\nAtte. Estado Mayor [=TFR=]'
		},
		recordatorio: {
			title: '⏰ RECORDATORIO',
			message: '*RECORDATORIO*\n\n📝 [MENSAJE]\n\nNo olvides revisar la web para más detalles.\n\nAtte. Estado Mayor [=TFR=]'
		}
	};

	function insertTemplate(type) {
		if (avisoTemplates[type]) {
			jQuery('#aviso_title').val(avisoTemplates[type].title);
			jQuery('#aviso_message').val(avisoTemplates[type].message);
			updatePreview();
			jQuery('#aviso_preview').show();
		}
	}

	jQuery('#aviso_title, #aviso_message').on('input', function() {
		updatePreview();
		if (jQuery('#aviso_message').val().trim() !== '') {
			jQuery('#aviso_preview').show();
		}
	});

	function updatePreview() {
		var title = jQuery('#aviso_title').val().trim();
		var msg = jQuery('#aviso_message').val().trim();
		var preview = '';
		if (title) preview += '📢 *' + title + '*\n\n';
		preview += msg;
		jQuery('#aviso_preview_content').text(preview);
	}

	jQuery('#btn_send_aviso').on('click', function() {
		var title = jQuery('#aviso_title').val().trim();
		var message = jQuery('#aviso_message').val().trim();
	
		if (!message) {
			alert('Por favor, escribe un mensaje.');
			return;
		}
	
		var btn = jQuery(this);
		var status = jQuery('#aviso_status');
		btn.prop('disabled', true).text('Enviando...');
		status.html('<span style="color:#f59e0b;"><i class="fa-solid fa-spinner fa-spin"></i> Enviando...</span>');
	
		var fullMessage = '';
		if (title) fullMessage += '📢 *' + title + '*\n\n';
		fullMessage += message;
	
		jQuery.post(ajaxurl, {
			action: 'rmm_send_telegram_aviso',
			message: fullMessage,
			_ajax_nonce: '<?php echo wp_create_nonce( "rmm_send_aviso" ); ?>'
		}, function(response) {
			btn.prop('disabled', false).html('<i class="fa-solid fa-paper-plane"></i> Enviar Aviso a Telegram');
			if (response.success) {
				status.html('<span style="color:#22c55e;"><i class="fa-solid fa-circle-check"></i> ' + response.data + '</span>');
			} else {
				status.html('<span style="color:#ef4444;"><i class="fa-solid fa-circle-xmark"></i> ' + (response.data || 'Error') + '</span>');
			}
		}).fail(function() {
			btn.prop('disabled', false).html('<i class="fa-solid fa-paper-plane"></i> Enviar Aviso a Telegram');
			status.html('<span style="color:#ef4444;"><i class="fa-solid fa-circle-xmark"></i> Error de conexión</span>');
		});
	});

	// ─────────────────────────────────────────────
	// FUNCIONES DE MONITOR
	// ─────────────────────────────────────────────

	var monitorTimer = null;

	function refreshMonitor() {
		jQuery.post(ajaxurl, {
			action: 'rmm_live_server_data',
			_ajax_nonce: '<?php echo wp_create_nonce( "rmm_monitor" ); ?>'
		}, function(response) {
			if (response.success && response.data) {
				var d = response.data;
				var isOnline = d.is_online;
				var statusColor = isOnline ? '#22c55e' : '#ef4444';
				var statusText = isOnline ? 'EN LÍNEA' : 'FUERA DE LÍNEA';
				var pulseClass = isOnline ? 'rmm-pulse-online' : '';
			
				// Status
				jQuery('#monitor_status').html(
					'<div style="display:flex; align-items:center; gap:10px;">' +
					'<span class="' + pulseClass + '" style="display:inline-block; width:12px; height:12px; border-radius:50%; background:' + statusColor + '; box-shadow: 0 0 10px ' + statusColor + '80;"></span>' +
					'<span style="font-weight:700; font-size:1rem; color:' + statusColor + ';">' + statusText + '</span>' +
					'</div>' +
					(d.uptime_formatted ? '<div style="margin-top:8px; font-size:0.65rem; color:#484f58;"><i class="fa-solid fa-clock"></i> ' + d.uptime_formatted + '</div>' : '')
				);
			
				// CPU
				var cpuLimit = parseFloat(d.cpu_limit) || 800;
				var cpuAbsolute = parseFloat(d.cpu_absolute) || 0;
				var cpuPct = cpuLimit > 0 ? Math.round((cpuAbsolute / cpuLimit) * 1000) / 10 : 0;
				var cpuCores = (cpuAbsolute / 100).toFixed(1);
				var cpuCoresTotal = Math.round(cpuLimit / 100);
				var cpuColor = cpuPct > 80 ? '#ef4444' : (cpuPct > 50 ? '#f59e0b' : '#22c55e');
				jQuery('#monitor_cpu').html(
					'<div style="font-size:1.8rem; font-weight:800; color:' + cpuColor + '; font-family:monospace; margin-bottom:4px;">' + cpuPct.toFixed(1) + '%</div>' +
					'<div style="font-size:0.6rem; color:#484f58; margin-bottom:8px;">' + cpuCores + ' / ' + cpuCoresTotal + ' cores</div>' +
					'<div style="background:#0d1117; border-radius:3px; height:6px;"><div style="width:' + Math.min(cpuPct,100) + '%; height:100%; background:' + cpuColor + '; border-radius:3px;"></div></div>'
				);
			
				// RAM
							var memBytes = parseInt(d.memory_bytes) || 0;
							var memLimit = parseInt(d.memory_limit) || 0;
							if (memLimit <= 0) memLimit = <?php echo intval( get_option( 'rmm_server_ram_gb', 24 ) ); ?> * 1024 * 1024 * 1024;
							var memPct = memLimit > 0 ? Math.round((memBytes / memLimit) * 1000) / 10 : 0;
							var memColor = memPct > 80 ? '#ef4444' : (memPct > 50 ? '#f59e0b' : '#22c55e');
							jQuery('#monitor_ram').html(
								'<div style="font-size:1.2rem; font-weight:700; color:' + memColor + '; font-family:monospace; margin-bottom:4px;">' + (d.memory_formatted || '—') + ' / ' + (d.memory_limit_formatted || '—') + '</div>' +
								'<div style="background:#0d1117; border-radius:3px; height:6px;"><div style="width:' + Math.min(memPct,100) + '%; height:100%; background:' + memColor + '; border-radius:3px;"></div></div>'
							);
			
							// Disco
							var diskBytes = parseInt(d.disk_bytes) || 0;
							var diskLimit = parseInt(d.disk_limit) || 0;
							if (diskLimit <= 0) diskLimit = <?php echo intval( get_option( 'rmm_server_disk_gb', 200 ) ); ?> * 1024 * 1024 * 1024;
							var diskPct = diskLimit > 0 ? Math.round((diskBytes / diskLimit) * 1000) / 10 : 0;
							var diskColor = diskPct > 80 ? '#ef4444' : (diskPct > 50 ? '#f59e0b' : '#22c55e');
							jQuery('#monitor_disk').html(
								'<div style="font-size:1.2rem; font-weight:700; color:' + diskColor + '; font-family:monospace; margin-bottom:4px;">' + (d.disk_formatted || '—') + ' / ' + (d.disk_limit_formatted || '—') + '</div>' +
								'<div style="background:#0d1117; border-radius:3px; height:6px;"><div style="width:' + Math.min(diskPct,100) + '%; height:100%; background:' + diskColor + '; border-radius:3px;"></div></div>'
							);
			
				// Partida
				jQuery('#monitor_game').html(
					'<div style="font-size:0.7rem; font-weight:600; color:#e5e7eb; margin-bottom:4px;">' + (d.scenario_name || '—') + '</div>' +
					'<div style="font-size:0.6rem; color:#484f58;">' +
					'<span><i class="fa-solid fa-puzzle-piece"></i> ' + (d.mods_count || 0) + ' mods</span> ' +
					'<span style="margin-left:8px;"><i class="fa-solid fa-database"></i> ' + (d.persistence ? 'Persistencia' : 'Sin persistencia') + '</span>' +
					'</div>'
				);
			
			} else {
				jQuery('#monitor_status, #monitor_cpu, #monitor_ram, #monitor_disk, #monitor_game').html('<span style="color:#ef4444;">Error al cargar datos</span>');
			}
		}).fail(function() {
			// Silent fail
		});
	}

	jQuery('#btn_refresh_monitor').on('click', function() {
		refreshMonitor();
	});

	// Acciones rápidas del monitor
	function sendPowerAction(signal) {
		if (!confirm('¿Estás seguro de que quieres ' + signal + ' el servidor?')) return;
	
		var serverId = jQuery('#ptero_server_select').val() || '<?php echo esc_js( get_option( "rmm_ptero_stable_server_id", "" ) ); ?>';
		jQuery.post(ajaxurl, {
			action: 'rmm_server_power_action',
			server_id: serverId,
			signal: signal,
			_ajax_nonce: '<?php echo wp_create_nonce( "rmm_monitor" ); ?>'
		}, function(response) {
			if (response.success) {
				setTimeout(refreshMonitor, 3000);
			}
			alert(response.data || 'Comando enviado');
		});
	}

	jQuery('#btn_monitor_start').on('click', function() { sendPowerAction('start'); });
	jQuery('#btn_monitor_restart').on('click', function() { sendPowerAction('restart'); });
	jQuery('#btn_monitor_stop').on('click', function() { sendPowerAction('stop'); });

	// Auto-refresh cada 30s cuando la pestaña Monitor está activa
	jQuery('[data-tab="monitor"]').on('click', function() {
		refreshMonitor();
		if (monitorTimer) clearInterval(monitorTimer);
		monitorTimer = setInterval(refreshMonitor, 30000);
	});

	// Limpiar timer al cambiar de pestaña
	jQuery('[data-tab]').not('[data-tab="monitor"]').on('click', function() {
		if (monitorTimer) clearInterval(monitorTimer);
	});

	// Cargar monitor si la URL tiene hash #monitor-pane
	if (window.location.hash === '#monitor-pane') {
		jQuery('[data-tab="monitor"]').click();
	}
	</script>
		<?php
		}

	/**
	 * AJAX: Get server presets list
	 */
	public function ajax_get_server_presets() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Acceso denegado', 'reforger-milsim' ) );
		}

		$server_id = sanitize_text_field( $_POST['server_id'] );
		if ( empty( $server_id ) ) {
			wp_send_json_error( __( 'ID de servidor no válido', 'reforger-milsim' ) );
		}

		try {
			$ptero = new RMM_Pterodactyl_Handler();
			$files = $ptero->list_server_files( $server_id, '/partidas' );
			$files = isset( $files['data'] ) ? $files['data'] : ( isset( $files['attributes'] ) ? $files['attributes'] : $files );
			
			$presets = array();
			if ( is_array( $files ) ) {
				foreach ( $files as $file ) {
					$attrs = isset( $file['attributes'] ) ? $file['attributes'] : $file;
					$name  = isset( $attrs['name'] ) ? $attrs['name'] : '';
					if ( ( isset( $attrs['is_file'] ) && $attrs['is_file'] ) && str_ends_with( $name, '.json' ) ) {
						$presets[] = $name;
					}
				}
			}

			wp_send_json_success( $presets );
		} catch ( Exception $e ) {
			wp_send_json_error( $e->getMessage() );
		}
	}

	/**
	 * AJAX: Get preset file content details
	 */
	public function ajax_get_preset_details() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Acceso denegado', 'reforger-milsim' ) );
		}

		$server_id = sanitize_text_field( $_POST['server_id'] );
		$filename  = sanitize_text_field( $_POST['filename'] );

		if ( empty( $server_id ) || empty( $filename ) ) {
			wp_send_json_error( __( 'Parámetros no válidos', 'reforger-milsim' ) );
		}

		try {
			$ptero = new RMM_Pterodactyl_Handler();
			$path  = str_ends_with( $filename, '.json' ) ? '/partidas/' . $filename : '/partidas/' . $filename . '.json';
			$content = $ptero->get_file_contents( $server_id, $path );
			
			// Clean NBSP, BOM etc
			$content = preg_replace( '/[\xC2\xA0]/', ' ', $content );
			$content = preg_replace( '/^\xEF\xBB\xBF/', '', $content );
			$data = json_decode( $content, true );

			if ( ! $data ) {
				throw new Exception( __( 'JSON inválido o malformado en el preset.', 'reforger-milsim' ) );
			}

			wp_send_json_success( $data );
		} catch ( Exception $e ) {
			wp_send_json_error( $e->getMessage() );
		}
	}

	/**
	 * AJAX: Run preset load sequence
	 */
	public function ajax_load_preset() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Acceso denegado', 'reforger-milsim' ) );
		}

		$server_id = sanitize_text_field( $_POST['server_id'] );
		$filename  = sanitize_text_field( $_POST['filename'] );

		if ( empty( $server_id ) || empty( $filename ) ) {
			wp_send_json_error( __( 'Parámetros no válidos', 'reforger-milsim' ) );
		}

		$progress = array();
		try {
			$ptero = new RMM_Pterodactyl_Handler();
			$ptero->load_preset( $server_id, $filename, $progress );
			wp_send_json_success( array( 'progress' => $progress ) );
		} catch ( Exception $e ) {
			$progress[] = '❌ Error: ' . $e->getMessage();
			wp_send_json_error( array(
				'error'    => $e->getMessage(),
				'progress' => $progress,
			) );
		}
	}

	/**
	 * AJAX: Save preset to server /partidas folder
	 */
	public function ajax_save_server_preset() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Acceso denegado', 'reforger-milsim' ) );
		}

		$server_id = sanitize_text_field( $_POST['server_id'] );
		$filename  = sanitize_text_field( $_POST['filename'] );
		$content   = wp_unslash( $_POST['content'] );

		if ( empty( $server_id ) || empty( $filename ) || empty( $content ) ) {
			wp_send_json_error( __( 'Parámetros obligatorios vacíos', 'reforger-milsim' ) );
		}

		// Sanitize filename to comply with RHS rule (alphanumeric, underscores, ending with .json)
		$filename = str_replace( ' ', '_', $filename );
		$filename = preg_replace( '/[^a-zA-Z0-9_\.]/', '', $filename );
		if ( ! str_ends_with( strtolower( $filename ), '.json' ) ) {
			$filename .= '.json';
		}

		try {
			// Validate JSON structure
			$data = json_decode( $content, true );
			if ( ! $data ) {
				throw new Exception( __( 'El contenido enviado no es un JSON válido.', 'reforger-milsim' ) );
			}

			$ptero = new RMM_Pterodactyl_Handler();
			$path  = '/partidas/' . $filename;
			
			// Pretty print JSON content
			$pretty_content = json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

			$ptero->upload_file( $server_id, $path, $pretty_content );
			wp_send_json_success();
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'error' => $e->getMessage() ) );
		}
	}

	/**
	 * AJAX: Subir config de ORBAT Link manualmente al servidor
	 */
	public function ajax_upload_orbatlink_config() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Acceso denegado', 'reforger-milsim' ) );
		}
		
		$server_id = sanitize_text_field( $_POST['server_id'] );
		if ( empty( $server_id ) ) {
			wp_send_json_error( __( 'Selecciona un servidor', 'reforger-milsim' ) );
		}
		
		try {
			$ptero = new RMM_Pterodactyl_Handler();
			$ptero->upload_orbat_link_config( $server_id );
			wp_send_json_success( __( 'Config de ORBAT Link subido correctamente', 'reforger-milsim' ) );
		} catch ( Exception $e ) {
			wp_send_json_error( $e->getMessage() );
		}
	}

	/**
	 * AJAX: Send Telegram aviso from admin panel
	 */
	public function ajax_send_telegram_aviso() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Acceso denegado', 'reforger-milsim' ) );
		}
		
		check_ajax_referer( 'rmm_send_aviso' );
		
		$message = isset( $_POST['message'] ) ? wp_kses_post( stripslashes( $_POST['message'] ) ) : '';
		if ( empty( $message ) ) {
			wp_send_json_error( __( 'El mensaje está vacío.', 'reforger-milsim' ) );
		}
		
		try {
					// Usar el mismo bot y canal que las RAIDs
					$token = get_option( 'rmm_raid_telegram_token', '' );
					$chat_id = get_option( 'rmm_raid_telegram_chat_id', '-1003157817672' );

					if ( empty( $token ) || empty( $chat_id ) ) {
						wp_send_json_error( __( 'El bot de Telegram no está configurado. Ve a Ajustes.', 'reforger-milsim' ) );
					}

					$text = wp_strip_all_tags( $message );
					$response = wp_remote_post( "https://api.telegram.org/bot{$token}/sendMessage", array(
						'timeout'   => 15,
						'sslverify' => false,
						'body'      => array(
							'chat_id'    => $chat_id,
							'text'       => $text,
							'parse_mode' => 'HTML',
						),
					));

					if ( is_wp_error( $response ) ) {
						wp_send_json_error( $response->get_error_message() );
					}

					$code = wp_remote_retrieve_response_code( $response );
					if ( $code === 200 ) {
						wp_send_json_success( __( 'Mensaje enviado correctamente al canal de Telegram.', 'reforger-milsim' ) );
					} else {
						wp_send_json_error( __( 'Error al enviar. Verifica el token y chat ID.', 'reforger-milsim' ) );
					}
		} catch ( Exception $e ) {
			wp_send_json_error( $e->getMessage() );
		}
	}

	/**
	 * AJAX: Server power action from monitor panel
	 */
	public function ajax_server_power_action() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Acceso denegado', 'reforger-milsim' ) );
		}
		
		check_ajax_referer( 'rmm_monitor' );
		
		$server_id = isset( $_POST['server_id'] ) ? sanitize_text_field( $_POST['server_id'] ) : '';
		$signal = isset( $_POST['signal'] ) ? sanitize_text_field( $_POST['signal'] ) : '';
		
		if ( empty( $server_id ) || empty( $signal ) ) {
			wp_send_json_error( __( 'Faltan parámetros.', 'reforger-milsim' ) );
		}
		
		if ( ! in_array( $signal, array( 'start', 'stop', 'restart', 'kill' ) ) ) {
			wp_send_json_error( __( 'Señal no válida.', 'reforger-milsim' ) );
		}
		
		try {
			$ptero = new RMM_Pterodactyl_Handler();
			$ptero->send_power_action( $server_id, $signal );
			
			$labels = array(
				'start'   => __( 'Servidor iniciado correctamente.', 'reforger-milsim' ),
				'stop'    => __( 'Servidor detenido correctamente.', 'reforger-milsim' ),
				'restart' => __( 'Servidor reiniciado correctamente.', 'reforger-milsim' ),
				'kill'    => __( 'Servidor forzado a detenerse.', 'reforger-milsim' ),
			);
			
			wp_send_json_success( $labels[ $signal ] ?? __( 'Comando ejecutado.', 'reforger-milsim' ) );
		} catch ( Exception $e ) {
			wp_send_json_error( $e->getMessage() );
		}
	}

	/**
	 * Propagate role name change in all saved ORBATs of missions and events.
	 */
	private function propagate_role_rename( $old_name, $new_name ) {
		$posts = get_posts( array(
			'post_type'   => array( 'misiones', 'eventos_partidas' ),
			'numberposts' => -1,
			'post_status' => 'any',
		) );

		foreach ( $posts as $post ) {
			$meta_keys = array( 'orbat_maestro', 'orbat_activo' );
			foreach ( $meta_keys as $key ) {
				$orbat_json = get_post_meta( $post->ID, $key, true );
				
				$orbat = $orbat_json;
				if ( is_string( $orbat_json ) ) {
					$orbat = json_decode( $orbat_json, true );
				}
				
				if ( ! empty( $orbat ) && is_array( $orbat ) ) {
					$changed = false;
					foreach ( $orbat as &$escuadra ) {
						if ( isset( $escuadra['slots'] ) && is_array( $escuadra['slots'] ) ) {
							foreach ( $escuadra['slots'] as &$slot ) {
								if ( isset( $slot['rol'] ) && $slot['rol'] === $old_name ) {
									$slot['rol'] = $new_name;
									$changed = true;
								}
							}
						}
					}
					if ( $changed ) {
						if ( is_string( $orbat_json ) ) {
							update_post_meta( $post->ID, $key, json_encode( $orbat, JSON_UNESCAPED_UNICODE ) );
						} else {
							update_post_meta( $post->ID, $key, $orbat );
						}
					}
				}
			}
		}
	}

	/**
	 * Oculta todos los menús del admin para no-admins, excepto "Perfil"
	 */
	public function restrict_admin_menu() {
		if ( current_user_can( 'administrator' ) ) return;
		
		global $menu, $submenu;
		
		// Solo permitir acceso al perfil
		$allowed = array( 'profile.php' );
		
		// Eliminar menús principales
		if ( is_array( $menu ) ) {
			foreach ( $menu as $i => $item ) {
				if ( isset( $item[2] ) && ! in_array( $item[2], $allowed ) ) {
					unset( $menu[ $i ] );
				}
			}
		}
		
		// Eliminar submenús excepto perfil
		if ( is_array( $submenu ) ) {
			foreach ( $submenu as $parent => $items ) {
				foreach ( $items as $j => $item ) {
					if ( isset( $item[2] ) && ! in_array( $item[2], $allowed ) ) {
						unset( $submenu[ $parent ][ $j ] );
					}
				}
			}
		}
		
		// Renombrar "Perfil" a "Mi Perfil" y cambiar icono
				foreach ( $menu as $i => $item ) {
					if ( isset( $item[2] ) && $item[2] === 'profile.php' ) {
						$menu[ $i ][0] = '👤 Mi Perfil';
						$menu[ $i ][6] = 'dashicons-admin-users';
					}
				}

				// Añadir enlace para volver a la web (directamente a la home)
						$menu[0] = array(
							'🏠 Volver a la Web',
							'read',
							home_url(),
							'',
							'menu-top menu-first',
							'menu-home',
							'dashicons-admin-home'
						);
	}

	/**
	 * Oculta items de la barra superior para no-admins, excepto perfil
	 */
	public function restrict_admin_bar( $wp_admin_bar ) {
		if ( current_user_can( 'administrator' ) ) return;
		
		$allowed = array( 'my-account', 'user-actions', 'user-info', 'edit-profile', 'logout', 'top-secondary' );
		
		$nodes = $wp_admin_bar->get_nodes();
		if ( is_array( $nodes ) ) {
			foreach ( $nodes as $id => $node ) {
				if ( ! in_array( $id, $allowed ) ) {
					$wp_admin_bar->remove_node( $id );
									}
								}
							}
						}

						/**
						 * Redirige a los no-admins fuera del escritorio al intentar acceder a /wp-admin/
						 */
						public function redirect_non_admin_dashboard() {
							if ( current_user_can( 'administrator' ) ) return;
							if ( wp_doing_ajax() ) return;

							$allowed = array( 'profile.php', 'admin-ajax.php', 'async-upload.php' );
							$current = basename( $_SERVER['SCRIPT_NAME'] );

							if ( ! in_array( $current, $allowed ) && strpos( $_SERVER['REQUEST_URI'], '/wp-admin/' ) !== false ) {
								wp_redirect( home_url() );
								exit;
														}
													}

									/**
									 * Restringe la biblioteca de medios: no-admins solo ven sus propios archivos
									 */
									public function restrict_media_library( $args ) {
										if ( current_user_can( 'administrator' ) ) return $args;
										$args['author'] = get_current_user_id();
										return $args;
									}
								}
