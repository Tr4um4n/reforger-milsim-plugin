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
		
		// Automatizacion de estados de eventos
		add_action( 'rmm_auto_transition_event_states', array( $this, 'auto_transition_event_states' ) );
		add_action( 'init', array( $this, 'schedule_event_state_cron' ) );
	}

	/**
	 * Programar cron para transiciones automaticas de estado
	 */
	public function schedule_event_state_cron() {
		// Registrar intervalo de 5 minutos
		add_filter( 'cron_schedules', function( $schedules ) {
			$schedules['every_five_minutes'] = array(
				'interval' => 300,
				'display'  => __( 'Cada 5 minutos', 'reforger-milsim' ),
			);
			return $schedules;
		});
		
		if ( ! wp_next_scheduled( 'rmm_auto_transition_event_states' ) ) {
			wp_schedule_event( time(), 'every_five_minutes', 'rmm_auto_transition_event_states' );
		}
	}

	/**
	 * Transicionar automaticamente estados de eventos segun sus fechas
	 */
	public function auto_transition_event_states() {
		$now = current_time( 'mysql' );
		
		$events = get_posts( array(
			'post_type'      => 'eventos_partidas',
			'post_status'    => 'publish',
			'numberposts'    => -1,
			'meta_query'     => array(
				'relation' => 'AND',
				array( 'key' => 'estado', 'value' => array( 'abierta', 'en_curso', 'debriefing' ), 'compare' => 'IN' ),
			),
		) );
		
		foreach ( $events as $event ) {
			$estado       = get_post_meta( $event->ID, 'estado', true );
			$fecha_inicio = get_post_meta( $event->ID, 'fecha_inicio', true );
			$fecha_fin    = get_post_meta( $event->ID, 'fecha_fin', true );
			
			if ( empty( $fecha_inicio ) ) continue;
			$inicio = strtotime( $fecha_inicio );
			$fin    = ! empty( $fecha_fin ) ? strtotime( $fecha_fin ) : $inicio + 7200; // +2h por defecto
			$now_ts = strtotime( $now );
			
			// abierta → en_curso
			if ( $estado === 'abierta' && $now_ts >= $inicio ) {
				update_post_meta( $event->ID, 'estado', 'en_curso' );
			}
			// en_curso → debriefing
			elseif ( $estado === 'en_curso' && $now_ts >= $fin ) {
				update_post_meta( $event->ID, 'estado', 'debriefing' );
			}
			// debriefing → finalizada (1h despues del fin)
			elseif ( $estado === 'debriefing' && $now_ts >= ( $fin + 3600 ) ) {
				update_post_meta( $event->ID, 'estado', 'finalizada' );
			}
		}
	}

	/**
	 * Limpiar cron al desactivar el plugin
	 */
	public static function clear_cron() {
		wp_clear_scheduled_hook( 'rmm_auto_transition_event_states' );
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
		$tz = wp_timezone();
		$now = new DateTime( 'now', $tz );
		$utc_offset = $now->format('Z') / 3600;
		$utc_label = 'UTC' . ($utc_offset >= 0 ? '+' : '') . $utc_offset;

		foreach ( $events_posts as $post ) {
			$inicio = get_post_meta( $post->ID, 'fecha_inicio', true );
			$fin    = get_post_meta( $post->ID, 'fecha_fin', true );
			$estado = get_post_meta( $post->ID, 'estado', true );

			// Reemplazar el espacio por T para formato ISO sin zona horaria, así FullCalendar lo interpreta como hora local.
			$start_formatted = !empty($inicio) ? str_replace(' ', 'T', $inicio) : null;

			// Skip events without start date
			if ( !$start_formatted ) continue;

			$formatted_events[] = array(
				'id'        => $post->ID,
				'title'     => $post->post_title . ' [' . $utc_label . ']',
				'start'     => $start_formatted,
				// Eliminado 'end' para que FullCalendar dibuje el evento como un punto en el tiempo y no deforme el calendario con bloques que abarcan varios días.
				'url'       => get_permalink( $post->ID ),
				'className' => 'rmm-event-status-' . $estado,
				'extendedProps' => array(
					'estado' => $estado
				)
			);
		}

		// Cargar raids del sistema de solicitudes
		$raid_handler = new RMM_Raid_Handler();
		$raid_events = $raid_handler->get_raids_for_calendar( null );
		if ( $raid_events && ! is_wp_error( $raid_events ) ) {
			$raids_data = $raid_events->get_data();
			$formatted_events = array_merge( $formatted_events, $raids_data );
		}

		return rest_ensure_response( $formatted_events );
	}

	/**
	 * FRONTEND: Shortcode [clan_calendario]
	 */
	public function render_calendar_shortcode() {
		// Encolar FullCalendar V6 y el idioma español desde CDN
		wp_enqueue_script( 'fullcalendar-js', 'https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.js', array(), '6.1.10', true );
		wp_enqueue_script( 'fullcalendar-es-js', 'https://unpkg.com/@fullcalendar/core@6.1.10/locales/es.global.min.js', array('fullcalendar-js'), '6.1.10', true );
		
		ob_start();
		?>
		<div id="rmm-calendar-container" class="rmm-dark-theme">
			<div id="rmm-global-calendar"></div>
		</div>

		<style>
			/* Sobrescribir estilos de FullCalendar para modo oscuro táctico */
			:root {
				--fc-border-color: #333;
				--fc-daygrid-event-dot-width: 8px;
			}
			#rmm-calendar-container {
				background: #1a1a1a;
				padding: 20px;
				border-radius: 4px;
				border: 1px solid #333;
				margin-bottom: 30px;
			}
			#rmm-global-calendar {
				--fc-page-bg-color: transparent;
				--fc-list-event-hover-bg-color: #2a2a2a;
				color: #e5e7eb;
				font-family: 'Inter', sans-serif;
			}
			.fc .fc-toolbar-title { 
				font-size: 1.25rem; 
				font-weight: 800; 
				text-transform: uppercase; 
				letter-spacing: 0.05em; 
				color: #849b4c; /* Verde táctico */
			}
			.fc .fc-button-primary { 
				background-color: #2a2a2a !important; 
				border-color: #444 !important; 
				text-transform: uppercase; 
				font-size: 0.75rem; 
				font-weight: bold; 
				color: #aaa !important;
			}
			.fc .fc-button-primary:hover { 
				background-color: #849b4c !important; 
				border-color: #849b4c !important;
				color: #fff !important;
			}
			.fc .fc-button-primary:disabled {
				background-color: #111 !important;
				border-color: #222 !important;
				color: #444 !important;
			}
			.fc .fc-col-header-cell { 
				background-color: #111; 
				padding: 10px 0; 
				font-size: 0.75rem; 
				text-transform: uppercase; 
				color: #888; 
			}
			.fc .fc-daygrid-day-number { 
				padding: 8px; 
				font-size: 0.85rem; 
				color: #aaa; 
			}
			.fc .fc-day-today { 
				background-color: rgba(132, 155, 76, 0.15) !important; /* Fondo verde táctico suave */
			}
			
			/* Colores por estado de misión */
			.rmm-event-status-abierta { background-color: #849b4c !important; border-color: #849b4c !important; color: #fff !important; }
			.rmm-event-status-en_curso { background-color: #d97706 !important; border-color: #d97706 !important; color: #fff !important; }
			.rmm-event-status-debriefing { background-color: #7c3aed !important; border-color: #7c3aed !important; color: #fff !important; }
			.rmm-event-status-finalizada { background-color: #444 !important; border-color: #444 !important; color: #aaa !important; opacity: 0.7; }
			.rmm-event-status-cancelada { background-color: #991b1b !important; border-color: #dc2626 !important; color: #fca5a5 !important; text-decoration: line-through; }
			
			.fc-event { padding: 4px 6px; border-radius: 3px; font-size: 0.75rem; font-weight: 600; cursor: pointer; }

			/* Soporte para móviles */
			@media (max-width: 768px) {
				#rmm-calendar-container { padding: 10px; }
				.fc .fc-toolbar { flex-direction: column; gap: 10px; }
				.fc .fc-toolbar-title { font-size: 1rem; }
				/* Permitir scroll horizontal en la vista de mes si no cabe */
				.fc-dayGridMonth-view {
					overflow-x: auto;
				}
				.fc-dayGridMonth-view .fc-scrollgrid {
					min-width: 600px;
				}
			}
		</style>

		<script>
			jQuery(document).ready(function($) {
				var calendarEl = document.getElementById('rmm-global-calendar');
				if (!calendarEl) return;

				var calendar = new FullCalendar.Calendar(calendarEl, {
					initialView: window.innerWidth < 768 ? 'listWeek' : 'dayGridMonth',
					locale: 'es',
					firstDay: 1, // Lunes
					headerToolbar: {
						left: 'prev,next today',
						center: 'title',
						right: 'dayGridMonth,listWeek'
					},
					buttonText: {
						today: 'Hoy',
						month: 'Mes',
						list: 'Lista'
					},
					events: '<?php echo esc_url( get_rest_url( null, "clan/v1/calendario" ) ); ?>',
					eventClick: function(info) {
						if (info.event.url) {
							window.open(info.event.url, "_self");
							info.jsEvent.preventDefault();
						}
					},
					eventDidMount: function(info) {
						var props = info.event.extendedProps;
						var title = info.event.title;
						if (props.tipo === 'raid') {
							title += ' | ' + props.usuario;
							if (props.servidor) title += ' | ' + props.servidor;
							title += ' | ' + props.participantes + ' confirmados';
						} else {
							title += ' [' + (props.estado || '').toUpperCase() + ']';
						}
						info.el.setAttribute('title', title);
					}
				});
				calendar.render();
			});
		</script>
		<?php
		return ob_get_clean();
	}
}
