<?php
/**
 * Telemetry Push Handler
 * 
 * Recibe estadísticas de combate desde el addon de Arma Reforger
 * y actualiza los metadatos de los operadores en WordPress.
 * 
 * Endpoint: POST /wp-json/clan/v1/telemetry/push
 * Header:   Authorization: <clave configurada en ajustes>
 * 
 * @package ReforgerMilsimManagement
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class RMM_Telemetry_Handler {

	/** @var string Clave de autorización esperada */
	private $auth_key;

	public function __construct() {
		$this->auth_key = get_option( 'rmm_telemetry_auth_key', 'TFR_6F8C2E9A1D4B47C99A1E7D6F3B2A8C10' );
		add_action( 'rest_api_init', array( $this, 'register_endpoint' ) );
	}

	/**
	 * Registrar ruta REST
	 */
	public function register_endpoint() {
		register_rest_route( 'clan/v1', '/telemetry/push', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_telemetry_push' ),
			'permission_callback' => array( $this, 'validate_auth' ),
		) );
	}

	/**
	 * Validar clave de autorización.
	 * 
	 * Orden de comprobación:
	 * 1. Header Authorization (estándar HTTP)
	 * 2. Header X-TFR-Token (alternativa si Apache/Nginx elimina Authorization)
	 * 3. Campo "token" en el body JSON (formato nuevo del addon)
	 */
	public function validate_auth( $request ) {
		// 1. Intentar obtener el token de los headers
		$token = $request->get_header( 'Authorization' );
		if ( empty( $token ) ) {
			$token = $request->get_header( 'X-TFR-Token' );
		}
		
		// 2. Si no viene en headers, buscar en el body JSON
		if ( empty( $token ) ) {
			$body = $request->get_body();
			if ( ! empty( $body ) ) {
				$data = json_decode( $body, true );
				if ( $data && isset( $data['token'] ) ) {
					$token = $data['token'];
				}
			}
		}
		
		if ( empty( $token ) ) {
			return new WP_Error(
				'rmm_telemetry_no_auth',
				__( 'Token requerido (header Authorization, X-TFR-Token o campo "token" en el body).', 'reforger-milsim' ),
				array( 'status' => 401 )
			);
		}

		if ( $token !== $this->auth_key ) {
			return new WP_Error(
				'rmm_telemetry_bad_auth',
				__( 'Token inválido.', 'reforger-milsim' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Procesar POST de telemetría
	 */
	public function handle_telemetry_push( $request ) {
		$body = $request->get_body();
		$data = json_decode( $body, true );

		if ( ! $data ) {
			return new WP_REST_Response( array(
				'success' => false,
				'error'   => 'JSON inválido o cuerpo vacío.',
			), 400 );
		}

		$results = array(
			'players_updated'  => 0,
			'players_notfound' => 0,
			'errors'           => array(),
		);

		// Soporta payload con array de jugadores o un único jugador
		$players = array();

		if ( isset( $data['players'] ) && is_array( $data['players'] ) ) {
			$players = $data['players'];
		} elseif ( isset( $data['steamid'] ) || isset( $data['steamid_64'] ) || isset( $data['bohemia_uid'] ) ) {
			$players = array( $data );
		}

		if ( empty( $players ) ) {
			return new WP_REST_Response( array(
				'success' => false,
				'error'   => 'No se encontraron datos de jugadores en el payload. Esperado: "players": [...] o campos directos steamid/bohemia_uid.',
			), 400 );
		}

		foreach ( $players as $player_data ) {
			$user = $this->find_user( $player_data );

			if ( ! $user ) {
				$identifier = $player_data['steamid'] ?? $player_data['steamid_64'] ?? $player_data['bohemia_uid'] ?? 'desconocido';
				$results['players_notfound']++;
				$results['errors'][] = "Usuario no encontrado para identificador: $identifier";
				continue;
			}

			$this->update_player_stats( $user->ID, $player_data );
			$results['players_updated']++;
			
			// Disparar hook para evaluar reglas de condecoraciones y tracking de sesion
			do_action( 'rmm_after_telemetry_update', $user->ID, 'telemetry', $player_data );
			
			// Si el payload incluye scenario_name, actualizar la sesion activa
			if ( ! empty( $data['scenario_name'] ) || ! empty( $data['scenario_id'] ) ) {
				global $wpdb;
				$sess_table = $wpdb->prefix . 'rmm_match_sessions';
				$active = $wpdb->get_row( "SELECT id FROM $sess_table WHERE ended_at IS NULL ORDER BY started_at DESC LIMIT 1" );
				if ( $active ) {
					$update = array();
					if ( ! empty( $data['scenario_name'] ) ) $update['scenario_name'] = sanitize_text_field( $data['scenario_name'] );
					if ( ! empty( $data['scenario_id'] ) ) $update['scenario_id'] = sanitize_text_field( $data['scenario_id'] );
					if ( ! empty( $update ) ) $wpdb->update( $sess_table, $update, array( 'id' => $active->id ) );
				}
			}

			// Procesar marcadores DAGR si vienen en el payload
			if ( ! empty( $data['markers'] ) && is_array( $data['markers'] ) ) {
				$map = sanitize_text_field( $data['map'] ?? ( $data['scenario_name'] ?? '' ) );
				$key = 'dagr_markers_' . ( $map ?: 'all' );
				$clean = array();
				foreach ( $data['markers'] as $m ) {
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
				set_transient( $key, $clean, 3600 );
			}
		}

		return new WP_REST_Response( array(
			'success'  => true,
			'message'  => sprintf(
				__( 'Telemetría procesada: %d actualizados, %d no encontrados.', 'reforger-milsim' ),
				$results['players_updated'],
				$results['players_notfound']
			),
			'details'  => $results,
		), 200 );
	}

	/**
	 * Buscar usuario de WordPress por SteamID64 o Bohemia UID
	 */
	private function find_user( $player_data ) {
		$steam_id    = $player_data['steamid_64'] ?? $player_data['steamid'] ?? '';
		$bohemia_uid = $player_data['bohemia_uid'] ?? '';

		// Intentar por SteamID64 primero
		if ( ! empty( $steam_id ) ) {
			$users = get_users( array(
				'meta_key'   => 'steamid_64',
				'meta_value' => $steam_id,
				'number'     => 1,
			) );
			if ( ! empty( $users ) ) {
				return $users[0];
			}
		}

		// Intentar por Bohemia UID
		if ( ! empty( $bohemia_uid ) ) {
			$users = get_users( array(
				'meta_key'   => 'bohemia_uid',
				'meta_value' => $bohemia_uid,
				'number'     => 1,
			) );
			if ( ! empty( $users ) ) {
				return $users[0];
			}
		}

		return null;
	}

	/**
	 * Actualizar estadísticas del jugador en user_meta
	 * 
	 * Usa update_user_meta con valores acumulativos (suma lo nuevo a lo existente)
	 * si el payload incluye el flag "cumulative": false, reemplaza en vez de sumar.
	 */
	private function update_player_stats( $user_id, $data ) {
		$cumulative = isset( $data['cumulative'] ) ? (bool) $data['cumulative'] : true;

		// Campos que se actualizan y sus posibles nombres en el JSON
		$fields = array(
			'rmm_kills'       => array( 'kills' ),
			'rmm_deaths'      => array( 'deaths' ),
			'rmm_hours'       => array( 'hours', 'playtime_hours' ),
			'rmm_shots_fired' => array( 'shots_fired', 'shotsFired' ),
			'rmm_shots_hit'   => array( 'shots_hit', 'shotsHit' ),
			'rmm_bandages'    => array( 'medical_bandages_applied', 'medical _bandages_applied' ),
			'rmm_tourniquets' => array( 'medical_tourniquets_applied', 'medical _tourniquets_applied' ),
			'rmm_saline'      => array( 'medical_saline_applied', 'medical _saline_applied' ),
			'rmm_morphine'    => array( 'medical_morphine_applied', 'medical _morphine_applied' ),
			'rmm_epinephrine' => array( 'medical_epinephrine_applied', 'medical _epinephrine_applied' ),
			'rmm_dist_walked' => array( 'distance_walked_m' ),
			'rmm_dist_vehicle'=> array( 'distance_in_vehicle_m' ),
			'rmm_dist_total'  => array( 'distance_total_m' ),
			'rmm_veh_destroyed'=> array( 'vehicles_destroyed_total' ),
			'rmm_veh_light'   => array( 'vehicles_destroyed_light' ),
			'rmm_veh_heavy'   => array( 'vehicles_destroyed_heavy' ),
			'rmm_veh_air'     => array( 'vehicles_destroyed_air' ),
			'rmm_veh_sea'     => array( 'vehicles_destroyed_sea' ),
			'rmm_veh_static'  => array( 'vehicles_destroyed_static' ),
			'rmm_explosives'  => array( 'placed_explosives_detonated' ),
			'rmm_pos_x'      => array( 'pos_x' ),
			'rmm_pos_y'      => array( 'pos_y' ),
			'rmm_pos_z'      => array( 'pos_z' ),
			'rmm_heading'    => array( 'heading' ),
			'rmm_map'        => array( 'map' ),
		);

		// Convertir tiempo a horas como float (NO int) para no perder precisión
						if ( isset( $data['playtime_seconds'] ) && ! isset( $data['hours'] ) && ! isset( $data['playtime_hours'] ) && ! isset( $data['playtime_minutes'] ) ) {
							$data['playtime_hours'] = round( intval( $data['playtime_seconds'] ) / 3600, 4 );
						} elseif ( isset( $data['playtime_second'] ) && ! isset( $data['hours'] ) && ! isset( $data['playtime_hours'] ) && ! isset( $data['playtime_minutes'] ) ) {
							$data['playtime_hours'] = round( intval( $data['playtime_second'] ) / 3600, 4 );
						} elseif ( isset( $data['playtime_minutes'] ) && ! isset( $data['hours'] ) && ! isset( $data['playtime_hours'] ) ) {
							$data['playtime_hours'] = round( intval( $data['playtime_minutes'] ) / 60, 4 );
						}

				foreach ( $fields as $meta_key => $aliases ) {
					$value = null;
					foreach ( $aliases as $alias ) {
						if ( isset( $data[ $alias ] ) && is_numeric( $data[ $alias ] ) ) {
							$value = $data[ $alias ];
							break;
						}
					}

					if ( $value === null ) continue;

					// Para horas y posiciones usamos floatval, el resto intval
					if ( $meta_key === 'rmm_hours' || str_starts_with( $meta_key, 'rmm_pos_' ) || $meta_key === 'rmm_heading' ) {
						$current = floatval( get_user_meta( $user_id, $meta_key, true ) ?: 0 );
						// Posiciones y heading NUNCA se acumulan (son estado actual, no total)
						$no_accumulate = str_starts_with( $meta_key, 'rmm_pos_' ) || $meta_key === 'rmm_heading';
						if ( $cumulative && ! $no_accumulate ) {
							update_user_meta( $user_id, $meta_key, round( $current + floatval( $value ), 4 ) );
						} else {
							update_user_meta( $user_id, $meta_key, round( floatval( $value ), 4 ) );
						}
					} elseif ( $meta_key === 'rmm_map' ) {
						// String: guardar tal cual
						update_user_meta( $user_id, $meta_key, sanitize_text_field( $value ) );
					} else {
						$current = intval( get_user_meta( $user_id, $meta_key, true ) ?: 0 );
						if ( $cumulative ) {
							update_user_meta( $user_id, $meta_key, $current + intval( $value ) );
						} else {
							update_user_meta( $user_id, $meta_key, intval( $value ) );
						}
					}
		}
	}
}
