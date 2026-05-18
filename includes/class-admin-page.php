<?php
/**
 * Admin Settings & Manual Page
 * @package ReforgerMilsimManagement
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class RMM_Admin_Page {

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
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

		// Sub: Configuración (placeholder for future settings)
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
				'shortcode'   => '[rmm_orbat]',
				'description' => 'Grid de escuadras con cards flex. Muestra slots, reservas y botones de acción.',
				'context'     => 'Misiones (solo lectura), Eventos (interactivo)',
				'params'      => '—',
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
					</tbody>
				</table>
			</div>
		</div>

		<style>
		.rmm-admin-wrap { max-width: 1200px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }

		.rmm-admin-header { background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%); color: #fff; padding: 30px 35px; border-radius: 12px; margin-bottom: 25px; display: flex; align-items: center; justify-content: space-between; box-shadow: 0 4px 20px rgba(0,0,0,0.3); }
		.rmm-admin-header h1 { margin: 0; font-size: 1.8em; font-weight: 800; letter-spacing: -0.5px; }
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
	 * Render: Settings Page (placeholder for Pterodactyl, etc.)
	 */
	public function render_settings_page() {
		?>
		<div class="wrap rmm-admin-wrap">
			<div class="rmm-admin-header">
				<h1>⚙️ Configuración</h1>
				<p class="rmm-version">v<?php echo RMM_VERSION; ?></p>
			</div>

			<div class="rmm-section">
				<h2>🦕 Integración Pterodactyl</h2>
				<p class="rmm-section-desc">Conecta con tu panel Pterodactyl para monitorizar servidores de Arma Reforger: estado, jugadores, rendimiento y partidas activas.</p>
				<div class="rmm-coming-soon">
					<span class="rmm-coming-icon">🚧</span>
					<h3>Próximamente</h3>
					<p>Esta sección está en desarrollo. Se añadirán campos para configurar la URL del panel, API Key, y la selección de servidores a monitorizar.</p>
				</div>
			</div>

			<div class="rmm-section">
				<h2>🔗 Integraciones Futuras</h2>
				<div class="rmm-roadmap-grid">
					<div class="rmm-roadmap-card">
						<span class="rmm-roadmap-status planned">Planificado</span>
						<h4>Pterodactyl Server Monitor</h4>
						<p>Estado del servidor, jugadores online, rendimiento CPU/RAM, logs de partidas.</p>
					</div>
					<div class="rmm-roadmap-card">
						<span class="rmm-roadmap-status planned">Planificado</span>
						<h4>Discord Webhooks</h4>
						<p>Notificaciones automáticas a canales de Discord cuando se crean eventos o cambian estados.</p>
					</div>
					<div class="rmm-roadmap-card">
						<span class="rmm-roadmap-status idea">Idea</span>
						<h4>Estadísticas de Jugador</h4>
						<p>Asistencia a eventos, historial de roles, progresión en condecoraciones.</p>
					</div>
					<div class="rmm-roadmap-card">
						<span class="rmm-roadmap-status idea">Idea</span>
						<h4>Exportación de ORBATs</h4>
						<p>Descargar ORBATs en formato PDF o imagen para briefings offline.</p>
					</div>
				</div>
			</div>
		</div>

		<style>
		.rmm-admin-wrap { max-width: 1200px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }

		.rmm-admin-header { background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%); color: #fff; padding: 30px 35px; border-radius: 12px; margin-bottom: 25px; display: flex; align-items: center; justify-content: space-between; box-shadow: 0 4px 20px rgba(0,0,0,0.3); }
		.rmm-admin-header h1 { margin: 0; font-size: 1.8em; font-weight: 800; }
		.rmm-version { background: rgba(255,255,255,0.15); padding: 5px 14px; border-radius: 20px; font-size: 0.85em; font-weight: 600; }

		.rmm-section { background: #fff; border: 1px solid #e0e0e0; border-radius: 10px; padding: 25px 30px; margin-bottom: 25px; box-shadow: 0 2px 8px rgba(0,0,0,0.04); }
		.rmm-section h2 { margin: 0 0 5px 0; font-size: 1.3em; color: #1a1a2e; }
		.rmm-section-desc { margin: 0 0 20px 0; color: #666; font-size: 0.95em; }

		.rmm-coming-soon { text-align: center; padding: 40px 20px; background: #f8f9ff; border: 2px dashed #c5cae9; border-radius: 12px; }
		.rmm-coming-icon { font-size: 3em; display: block; margin-bottom: 10px; }
		.rmm-coming-soon h3 { margin: 0 0 8px 0; color: #1a1a2e; font-size: 1.4em; }
		.rmm-coming-soon p { margin: 0; color: #777; max-width: 500px; margin: 0 auto; }

		.rmm-roadmap-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px; }
		.rmm-roadmap-card { background: #f8f9fa; border: 1px solid #e8eaf6; border-radius: 8px; padding: 20px; position: relative; }
		.rmm-roadmap-card h4 { margin: 8px 0 6px 0; font-size: 1em; color: #1a1a2e; }
		.rmm-roadmap-card p { margin: 0; font-size: 0.88em; color: #666; line-height: 1.5; }
		.rmm-roadmap-status { font-size: 0.7em; text-transform: uppercase; font-weight: 700; letter-spacing: 0.5px; padding: 3px 10px; border-radius: 10px; }
		.rmm-roadmap-status.planned { background: #e3f2fd; color: #1565c0; }
		.rmm-roadmap-status.idea { background: #fff3e0; color: #e65100; }
		.rmm-roadmap-status.done { background: #e8f5e9; color: #2e7d32; }

		@media (max-width: 960px) { .rmm-roadmap-grid { grid-template-columns: 1fr; } }
		</style>
		<?php
	}
}
