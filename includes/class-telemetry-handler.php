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
		);

		// Si viene en minutos, convertir a horas
		if ( isset( $data['playtime_minutes'] ) && ! isset( $data['hours'] ) && ! isset( $data['playtime_hours'] ) ) {
			$data['playtime_hours'] = round( intval( $data['playtime_minutes'] ) / 60, 2 );
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

			$current = intval( get_user_meta( $user_id, $meta_key, true ) ?: 0 );

			if ( $cumulative ) {
				update_user_meta( $user_id, $meta_key, $current + intval( $value ) );
			} else {
				update_user_meta( $user_id, $meta_key, intval( $value ) );
			}
		}
	}
}
