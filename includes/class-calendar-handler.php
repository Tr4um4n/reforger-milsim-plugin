<?php
/**
 * Global Calendar Handler Class
 * @package ReforgerMilsimManagement
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class RMM_Calendar_Handler {

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_calendar_endpoint' ) );
		add_shortcode( 'clan_calendario', array( $this, 'render_calendar_shortcode' ) );
		
		// Auto-inject ORBAT in single pages
		add_filter( 'the_content', array( $this, 'inject_orbat_to_content' ) );
	}

	/**
	 * AUTO-INJECT: Mostrar el ORBAT automáticamente en misiones y eventos
	 */
	public function inject_orbat_to_content( $content ) {
		if ( ! is_singular( array( 'misiones', 'eventos_partidas' ) ) ) return $content;
		
		$shortcode = '[clan_orbat]';
		$header = '<div class="rmm-frontend-header" style="background:#111; padding:20px; border-left:4px solid #2271b1; margin-bottom:30px;">
			<h2 style="color:#fff; margin:0; text-transform:uppercase; letter-spacing:1px;">🛡️ Estructura de Combate (ORBAT)</h2>
			<p style="color:#888; font-size:13px; margin:5px 0 0 0;">Selecciona tu rol para participar en la operación.</p>
		</div>';
		
		return $content . $header . do_shortcode( $shortcode );
	}

	/**
	 * BACKEND: Registrar el endpoint REST para FullCalendar
	 */
	public function register_calendar_endpoint() {
		register_rest_route( 'clan/v1', '/calendario', array(
			'methods'  => 'GET',
			'callback' => array( $this, 'get_calendar_events' ),
			'permission_callback' => '__return_true'
		) );
	}

	/**
	 * CALLBACK: Obtener eventos formateados para FullCalendar
	 */
	public function get_calendar_events() {
		$events_posts = get_posts( array(
			'post_type'      => 'eventos_partidas',
			'numberposts'    => -1,
			'post_status'    => 'publish'
		) );

		$formatted_events = array();

		foreach ( $events_posts as $post ) {
			$inicio = get_post_meta( $post->ID, 'fecha_inicio', true );
			$fin    = get_post_meta( $post->ID, 'fecha_fin', true );
			$estado = get_post_meta( $post->ID, 'estado', true );

			$formatted_events[] = array(
				'id'        => $post->ID,
				'title'     => $post->post_title,
				'start'     => $inicio,
				'end'       => $fin,
				'url'       => get_permalink( $post->ID ),
				'className' => 'rmm-event-status-' . $estado,
				'extendedProps' => array(
					'estado' => $estado
				)
			);
		}

		return rest_ensure_response( $formatted_events );
	}

	/**
	 * FRONTEND: Shortcode [clan_calendario]
	 */
	public function render_calendar_shortcode() {
		// Encolar FullCalendar V6 desde CDN
		wp_enqueue_script( 'fullcalendar-js', 'https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.js', array(), '6.1.10', true );
		
		ob_start();
		?>
		<div id="rmm-calendar-container" class="rmm-dark-theme">
			<div id="rmm-global-calendar" class="bg-gray-900 p-6 rounded-xl shadow-2xl border border-gray-800"></div>
		</div>

		<style>
			/* Sobrescribir estilos de FullCalendar para modo oscuro táctico */
			:root {
				--fc-border-color: #1f2937; /* gray-800 */
				--fc-daygrid-event-dot-width: 8px;
			}
			#rmm-global-calendar {
				--fc-page-bg-color: transparent;
				--fc-list-event-hover-bg-color: #111827;
				color: #e5e7eb;
				font-family: 'Inter', sans-serif;
			}
			.fc .fc-toolbar-title { font-size: 1.25rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; color: #60a5fa; }
			.fc .fc-button-primary { background-color: #1e3a8a !important; border-color: #1e40af !important; text-transform: uppercase; font-size: 0.75rem; font-weight: bold; }
			.fc .fc-button-primary:hover { background-color: #1d4ed8 !important; }
			.fc .fc-col-header-cell { background-color: #111827; padding: 10px 0; font-size: 0.75rem; text-transform: uppercase; color: #9ca3af; }
			.fc .fc-daygrid-day-number { padding: 8px; font-size: 0.85rem; color: #9ca3af; }
			.fc .fc-day-today { background-color: rgba(30, 58, 138, 0.2) !important; }
			
			/* Colores por estado de misión */
			.rmm-event-status-abierta { background-color: #059669 !important; border-color: #047857 !important; }
			.rmm-event-status-en_curso { background-color: #d97706 !important; border-color: #b45309 !important; }
			.rmm-event-status-debriefing { background-color: #7c3aed !important; border-color: #6d28d9 !important; }
			.rmm-event-status-finalizada { background-color: #4b5563 !important; border-color: #374151 !important; opacity: 0.7; }
			
			.fc-event { padding: 2px 5px; border-radius: 4px; font-size: 0.75rem; font-weight: 600; cursor: pointer; }
		</style>

		<script>
		document.addEventListener('DOMContentLoaded', function() {
			var calendarEl = document.getElementById('rmm-global-calendar');
			if (!calendarEl) return;

			var calendar = new FullCalendar.Calendar(calendarEl, {
				initialView: 'dayGridMonth',
				locale: 'es',
				firstDay: 1, // Lunes
				headerToolbar: {
					left: 'prev,next today',
					center: 'title',
					right: 'dayGridMonth,listWeek'
				},
				events: '<?php echo esc_url( get_rest_url( null, "clan/v1/calendario" ) ); ?>',
				eventClick: function(info) {
					if (info.event.url) {
						window.open(info.event.url, "_self");
						info.jsEvent.preventDefault();
					}
				},
				// Tooltip básico con el estado
				eventDidMount: function(info) {
					info.el.setAttribute('title', info.event.title + ' [' + info.event.extendedProps.estado.toUpperCase() + ']');
				}
			});
			calendar.render();
		});
		</script>
		<?php
		return ob_get_clean();
	}
}
