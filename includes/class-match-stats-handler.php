<?php
/**
 * Match Stats Handler — Tracking de partidas y shortcode de última misión
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class RMM_Match_Stats_Handler {

	public function __construct() {
		add_shortcode( 'rmm_last_match', array( $this, 'render_last_match' ) );
		add_action( 'rmm_after_telemetry_update', array( $this, 'track_match_session' ), 10, 3 );
		add_action( 'init', array( $this, 'ensure_table' ) );
	}

	public function ensure_table() {
		global $wpdb;
		$table = $wpdb->prefix . 'rmm_match_sessions';
		$exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) );
		if ( $exists !== $table ) {
			$charset = $wpdb->get_charset_collate();
			$sql = "CREATE TABLE $table (
				id bigint(20) NOT NULL AUTO_INCREMENT,
				started_at datetime NOT NULL,
				ended_at datetime DEFAULT NULL,
				scenario_name varchar(200) DEFAULT '' NOT NULL,
				scenario_id varchar(100) DEFAULT '' NOT NULL,
				total_kills int(11) DEFAULT 0 NOT NULL,
				total_deaths int(11) DEFAULT 0 NOT NULL,
				total_shots_fired int(11) DEFAULT 0 NOT NULL,
				total_shots_hit int(11) DEFAULT 0 NOT NULL,
				total_bandages int(11) DEFAULT 0 NOT NULL,
				total_tourniquets int(11) DEFAULT 0 NOT NULL,
				total_saline int(11) DEFAULT 0 NOT NULL,
				total_morphine int(11) DEFAULT 0 NOT NULL,
				total_epinephrine int(11) DEFAULT 0 NOT NULL,
				total_playtime_seconds int(11) DEFAULT 0 NOT NULL,
				player_count int(11) DEFAULT 0 NOT NULL,
				PRIMARY KEY (id)
			) $charset";
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			dbDelta( $sql );
		}
	}

	public function render_last_match( $atts ) {
		global $wpdb;
		$table = $wpdb->prefix . 'rmm_match_sessions';
		
		// Crear tabla si no existe
		static $table_checked = false;
		if ( ! $table_checked ) {
			$table_checked = true;
			$exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) );
			if ( $exists !== $table ) {
				$charset = $wpdb->get_charset_collate();
				$sql = "CREATE TABLE $table ( id bigint(20) NOT NULL AUTO_INCREMENT, started_at datetime NOT NULL, ended_at datetime DEFAULT NULL, scenario_name varchar(200) DEFAULT '' NOT NULL, scenario_id varchar(100) DEFAULT '' NOT NULL, total_kills int(11) DEFAULT 0 NOT NULL, total_deaths int(11) DEFAULT 0 NOT NULL, total_shots_fired int(11) DEFAULT 0 NOT NULL, total_shots_hit int(11) DEFAULT 0 NOT NULL, total_bandages int(11) DEFAULT 0 NOT NULL, total_tourniquets int(11) DEFAULT 0 NOT NULL, total_saline int(11) DEFAULT 0 NOT NULL, total_morphine int(11) DEFAULT 0 NOT NULL, total_epinephrine int(11) DEFAULT 0 NOT NULL, total_playtime_seconds int(11) DEFAULT 0 NOT NULL, player_count int(11) DEFAULT 0 NOT NULL, PRIMARY KEY (id) ) $charset";
				require_once ABSPATH . 'wp-admin/includes/upgrade.php';
				dbDelta( $sql );
			}
		}
		
		$session = $wpdb->get_row( "SELECT * FROM $table WHERE ended_at IS NOT NULL ORDER BY ended_at DESC LIMIT 1" );
		
		if ( ! $session ) {
			return '<div class="rmm-last-match" style="font-family:Inter,sans-serif;color:#8b949e;padding:16px;background:#0d1117;border:1px solid #21262d;border-radius:6px;">No hay partidas registradas todavia.</div>';
		}

		$date     = date_i18n( 'l, j \d\e F \d\e Y', strtotime( $session->started_at ) );
		$time     = date( 'H:i', strtotime( $session->started_at ) ) . ' - ' . ( $session->ended_at ? date( 'H:i', strtotime( $session->ended_at ) ) : '...' );
		$scenario = $session->scenario_name ?: ( $session->scenario_id ?: 'Desconocido' );
		$has_stats = $session->total_kills > 0 || $session->total_deaths > 0 || $session->total_shots_fired > 0;
		$duration  = $session->total_playtime_seconds > 0 ? $this->format_duration( $session->total_playtime_seconds ) : '';

		ob_start();
		?>
		<div class="rmm-last-match" style="font-family: 'Inter', system-ui, sans-serif; background: linear-gradient(135deg, #0d1117 0%, #161b22 100%); border: 1px solid #21262d; border-radius: 8px; padding: 20px; color: #c9d1d9; max-width: 500px;">
			<div style="display: flex; align-items: center; gap: 10px; margin-bottom: 14px; padding-bottom: 10px; border-bottom: 1px solid #21262d;">
				<span style="font-size: 1.4rem;">🎮</span>
				<div>
					<div style="font-size: 0.65rem; color: #849b4c; text-transform: uppercase; letter-spacing: 0.1em; font-weight: 700;">Ultima Partida</div>
					<div style="font-size: 0.85rem; color: #8b949e;"><?php echo esc_html( $date ); ?></div>
				</div>
			</div>
			<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 14px;">
				<div style="background: #0d1117; border: 1px solid #21262d; border-radius: 5px; padding: 10px;">
					<span style="display: block; font-size: 0.55rem; color: #484f58; text-transform: uppercase; letter-spacing: 0.06em;">Horario</span>
					<strong style="color: #c9d1d9; font-family: monospace; font-size: 0.8rem;"><?php echo esc_html( $time ); ?>h (UTC+2)</strong>
				</div>
				<div style="background: #0d1117; border: 1px solid #21262d; border-radius: 5px; padding: 10px;">
					<span style="display: block; font-size: 0.55rem; color: #484f58; text-transform: uppercase; letter-spacing: 0.06em;">Jugadores</span>
					<strong style="color: #c9d1d9; font-family: monospace; font-size: 0.8rem;"><?php echo intval( $session->player_count ); ?></strong>
				</div>
			</div>
			<div style="background: #0d1117; border: 1px solid #21262d; border-radius: 5px; padding: 10px; margin-bottom: 14px;">
				<span style="display: block; font-size: 0.55rem; color: #484f58; text-transform: uppercase; letter-spacing: 0.06em;">Escenario</span>
				<strong style="color: #58a6ff; font-size: 0.85rem;"><?php echo esc_html( $scenario ); ?></strong>
				<?php if ( $duration ) : ?>
					<span style="display: block; font-size: 0.6rem; color: #484f58; margin-top: 4px;">Duracion: <?php echo esc_html( $duration ); ?></span>
				<?php endif; ?>
			</div>
			<?php if ( $has_stats ) : ?>
			<div style="background: rgba(132,155,76,0.05); border: 1px solid rgba(132,155,76,0.15); border-radius: 5px; padding: 12px;">
				<div style="font-size: 0.6rem; color: #849b4c; text-transform: uppercase; letter-spacing: 0.08em; font-weight: 700; margin-bottom: 10px;">Estadisticas Totales</div>
			
				<div class="rmm-match-stats-grid" style="display: grid; grid-template-columns: repeat(5, 1fr); gap: 6px;">
				
					<div class="rmm-match-stat-card" style="background: #0d1117; border: 1px solid #21262d; border-radius: 5px; padding: 8px 5px; text-align: center; transition: transform 0.2s, border-color 0.2s;">
						<span style="font-size: 0.5rem; color: #484f58; text-transform: uppercase; letter-spacing: 0.05em; display: block; margin-bottom: 4px;">Bajas</span>
						<strong style="color: #f85149; font-family: 'JetBrains Mono', monospace; font-size: 1rem;"><?php echo intval( $session->total_kills ); ?></strong>
					</div>
				
					<div class="rmm-match-stat-card" style="background: #0d1117; border: 1px solid #21262d; border-radius: 5px; padding: 8px 5px; text-align: center; transition: transform 0.2s, border-color 0.2s;">
						<span style="font-size: 0.5rem; color: #484f58; text-transform: uppercase; letter-spacing: 0.05em; display: block; margin-bottom: 4px;">Muertes</span>
						<strong style="color: #fca5a5; font-family: 'JetBrains Mono', monospace; font-size: 1rem;"><?php echo intval( $session->total_deaths ); ?></strong>
					</div>
				
					<div class="rmm-match-stat-card" style="background: #0d1117; border: 1px solid #21262d; border-radius: 5px; padding: 8px 5px; text-align: center; transition: transform 0.2s, border-color 0.2s;">
						<span style="font-size: 0.5rem; color: #484f58; text-transform: uppercase; letter-spacing: 0.05em; display: block; margin-bottom: 4px;">Disparos</span>
						<strong style="color: #f59e0b; font-family: 'JetBrains Mono', monospace; font-size: 1rem;"><?php echo intval( $session->total_shots_fired ); ?></strong>
					</div>
				
					<div class="rmm-match-stat-card" style="background: #0d1117; border: 1px solid #21262d; border-radius: 5px; padding: 8px 5px; text-align: center; transition: transform 0.2s, border-color 0.2s;">
						<span style="font-size: 0.5rem; color: #484f58; text-transform: uppercase; letter-spacing: 0.05em; display: block; margin-bottom: 4px;">Vendajes</span>
						<strong style="color: #f778ba; font-family: 'JetBrains Mono', monospace; font-size: 1rem;"><?php echo intval( $session->total_bandages ); ?></strong>
					</div>
				
					<div class="rmm-match-stat-card" style="background: #0d1117; border: 1px solid #21262d; border-radius: 5px; padding: 8px 5px; text-align: center; transition: transform 0.2s, border-color 0.2s;">
						<span style="font-size: 0.5rem; color: #484f58; text-transform: uppercase; letter-spacing: 0.05em; display: block; margin-bottom: 4px;">Vehiculos</span>
						<strong style="color: #f78166; font-family: 'JetBrains Mono', monospace; font-size: 1rem;"><?php echo intval( $session->total_shots_hit ); ?></strong>
					</div>
				
				</div>
			
				<style>
				.rmm-match-stat-card:hover {
					transform: translateY(-3px);
					border-color: #849b4c;
				}
				@media (max-width: 480px) {
					.rmm-match-stats-grid { grid-template-columns: repeat(3, 1fr) !important; }
				}
				</style>
			</div>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	public function track_match_session( $user_id, $context, $player_data = array() ) {
		global $wpdb;
		$table = $wpdb->prefix . 'rmm_match_sessions';
		$now = current_time( 'mysql' );

		$active = $wpdb->get_row( "SELECT * FROM $table WHERE ended_at IS NULL ORDER BY started_at DESC LIMIT 1" );

		if ( $active ) {
			$elapsed = ( strtotime( $now ) - strtotime( $active->started_at ) ) / 60;
			if ( $elapsed > 45 ) {
				$wpdb->update( $table, array( 'ended_at' => $now ), array( 'id' => $active->id ) );
				$active = null;
			}
		}

		if ( ! $active ) {
			$wpdb->insert( $table, array( 'started_at' => $now, 'scenario_name' => '', 'scenario_id' => '' ) );
			$active_id = $wpdb->insert_id;
		} else {
			$active_id = $active->id;
		}

		// Acumular stats del jugador en la sesion activa
		$updates = array();
		$stat_map = array(
			'kills' => 'total_kills', 'deaths' => 'total_deaths',
			'shots_fired' => 'total_shots_fired', 'shots_hit' => 'total_shots_hit',
			'medical_bandages_applied' => 'total_bandages', 'medical_tourniquets_applied' => 'total_tourniquets',
			'medical_saline_applied' => 'total_saline', 'medical_morphine_applied' => 'total_morphine',
			'medical_epinephrine_applied' => 'total_epinephrine',
			'vehicles_destroyed_total' => 'total_shots_hit',
		);
		
		foreach ( $stat_map as $data_key => $col ) {
			if ( isset( $player_data[ $data_key ] ) && intval( $player_data[ $data_key ] ) > 0 ) {
				$val = intval( $player_data[ $data_key ] );
				$updates[] = "$col = $col + $val";
			}
		}
		
		// Playtime
		if ( isset( $player_data['playtime_seconds'] ) ) {
			$updates[] = 'total_playtime_seconds = total_playtime_seconds + ' . intval( $player_data['playtime_seconds'] );
		} elseif ( isset( $player_data['playtime_minutes'] ) ) {
			$updates[] = 'total_playtime_seconds = total_playtime_seconds + ' . ( intval( $player_data['playtime_minutes'] ) * 60 );
		}
		
		// Incrementar player_count (1 por usuario unico por sesion, simplificado)
		$updates[] = 'player_count = CASE WHEN player_count < 100 THEN player_count + 1 ELSE player_count END';
		
		if ( ! empty( $updates ) ) {
			$wpdb->query( "UPDATE $table SET " . implode( ', ', $updates ) . " WHERE id = $active_id" );
		}
	}

	public static function finalize_session( $session_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'rmm_match_sessions';

		$wpdb->update( $table, array(
			'total_kills'       => self::sum_user_meta( 'rmm_kills' ),
			'total_deaths'      => self::sum_user_meta( 'rmm_deaths' ),
			'total_shots_fired' => self::sum_user_meta( 'rmm_shots_fired' ),
			'total_shots_hit'   => self::sum_user_meta( 'rmm_shots_hit' ),
			'total_bandages'    => self::sum_user_meta( 'rmm_bandages' ),
			'total_tourniquets' => self::sum_user_meta( 'rmm_tourniquets' ),
			'total_saline'      => self::sum_user_meta( 'rmm_saline' ),
			'total_morphine'    => self::sum_user_meta( 'rmm_morphine' ),
			'total_epinephrine' => self::sum_user_meta( 'rmm_epinephrine' ),
			'ended_at'          => current_time( 'mysql' ),
		), array( 'id' => $session_id ) );
	}

	private static function sum_user_meta( $key ) {
		global $wpdb;
		return intval( $wpdb->get_var( $wpdb->prepare(
			"SELECT SUM( CAST(meta_value AS UNSIGNED) ) FROM {$wpdb->usermeta} WHERE meta_key = %s",
			$key
		) ) );
	}

	private function format_duration( $seconds ) {
		$h = floor( $seconds / 3600 );
		$m = floor( ( $seconds % 3600 ) / 60 );
		if ( $h > 0 ) return "{$h}h {$m}min";
		return "{$m}min";
	}
}
