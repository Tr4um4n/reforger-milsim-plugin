<?php
/**
 * Pterodactyl and Telegram API Handler Class
 *
 * Handles API integration with Pterodactyl Panel and Telegram Notifications.
 *
 * @package ReforgerMilsimManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RMM_Pterodactyl_Handler {

	private $ptero_url;
	private $client_key;
	private $app_key;
	private $telegram_token;
	private $telegram_chat_id;

	public function __construct() {
		$this->ptero_url        = rtrim( get_option( 'rmm_ptero_url', '' ), '/' );
		$this->client_key       = trim( get_option( 'rmm_ptero_client_key', '' ) );
		$this->app_key          = trim( get_option( 'rmm_ptero_app_key', '' ) );
		$this->telegram_token   = trim( get_option( 'rmm_telegram_token', '' ) );
		$this->telegram_chat_id = trim( get_option( 'rmm_telegram_chat_id', '' ) );
	}

	/**
	 * Generic Pterodactyl API call
	 */
	private function call_api( $endpoint, $method = 'GET', $data = null, $use_app = false ) {
		$base = $use_app ? '/api/application' : '/api/client';
		$url  = $this->ptero_url . $base . $endpoint;
		$key  = ( $use_app && ! empty( $this->app_key ) ) ? $this->app_key : $this->client_key;

		if ( empty( $this->ptero_url ) || empty( $key ) ) {
			throw new Exception( __( 'La URL o API Key de Pterodactyl no están configuradas.', 'reforger-milsim' ) );
		}

		$headers = array(
			'Authorization' => 'Bearer ' . $key,
			'Accept'        => 'application/json',
		);

		$args = array(
			'method'    => $method,
			'headers'   => $headers,
			'timeout'   => 30,
			'sslverify' => false,
		);

		if ( $data !== null ) {
			if ( is_string( $data ) ) {
				$args['headers']['Content-Type'] = 'text/plain';
				$args['body']                    = $data;
			} else {
				$args['headers']['Content-Type'] = 'application/json';
				$args['body']                    = wp_json_encode( $data );
			}
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			throw new Exception( $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( $code === 204 ) {
			return true;
		}

		if ( $code < 200 || $code >= 300 ) {
			$error_data = json_decode( $body, true );
			$msg = isset( $error_data['errors'][0]['detail'] ) ? $error_data['errors'][0]['detail'] : 'HTTP ' . $code;
			throw new Exception( __( 'Error del Panel Pterodactyl: ', 'reforger-milsim' ) . $msg );
		}

		return json_decode( $body, true );
	}

	/**
	 * Get configured servers (Stable and Testing)
	 */
	public function get_servers() {
		$servers = array();
		$stable  = get_option( 'rmm_ptero_stable_server_id' );
		$testing = get_option( 'rmm_ptero_testing_server_id' );

		if ( ! empty( $stable ) ) {
			$servers[] = array(
				'id'   => $stable,
				'name' => __( 'Servidor Principal (STABLE)', 'reforger-milsim' ),
			);
		}
		if ( ! empty( $testing ) ) {
			$servers[] = array(
				'id'   => $testing,
				'name' => __( 'Servidor de Pruebas (TESTING)', 'reforger-milsim' ),
			);
		}

		// Fallback to fetch from API if nothing is configured
		if ( empty( $servers ) ) {
			try {
				$endpoint = '/servers';
				$response = $this->call_api( $endpoint, 'GET', null, ! empty( $this->app_key ) );
				if ( isset( $response['data'] ) && is_array( $response['data'] ) ) {
					foreach ( $response['data'] as $srv ) {
						$attr = isset( $srv['attributes'] ) ? $srv['attributes'] : $srv;
						$servers[] = array(
							'id'   => isset( $attr['identifier'] ) ? $attr['identifier'] : ( isset( $attr['uuidShort'] ) ? $attr['uuidShort'] : $attr['id'] ),
							'name' => isset( $attr['name'] ) ? $attr['name'] : 'Reforger Server',
						);
					}
				}
			} catch ( Exception $e ) {
				// No servers found or configuration missing
			}
		}

		return $servers;
	}

	/**
	 * List files in directory
	 */
	public function list_server_files( $server_id, $directory = '/' ) {
		$endpoint = "/servers/{$server_id}/files/list?directory=" . rawurlencode( $directory );
		return $this->call_api( $endpoint, 'GET', null, false );
	}

	/**
	 * Get file contents
	 */
	public function get_file_contents( $server_id, $path ) {
		$endpoint = "/servers/{$server_id}/files/contents?file=" . rawurlencode( $path );
		$url  = $this->ptero_url . '/api/client' . $endpoint;

		$headers = array(
			'Authorization' => 'Bearer ' . $this->client_key,
			'Accept'        => 'application/json',
		);

		$args = array(
			'headers'   => $headers,
			'timeout'   => 30,
			'sslverify' => false,
		);

		$response = wp_remote_get( $url, $args );

		if ( is_wp_error( $response ) ) {
			throw new Exception( $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( $code !== 200 ) {
			throw new Exception( __( 'No se pudo obtener el archivo: ', 'reforger-milsim' ) . $path . ' (HTTP ' . $code . ')' );
		}

		return $body;
	}

	/**
	 * Upload file content
	 */
	public function upload_file( $server_id, $path, $contents ) {
		$endpoint = "/servers/{$server_id}/files/write?file=" . rawurlencode( $path );
		return $this->call_api( $endpoint, 'POST', $contents, false );
	}

	/**
	 * Update Startup Variable
	 */
	public function update_startup_variable( $server_id, $variable, $value ) {
		$endpoint = "/servers/{$server_id}/startup/variable";
		$data     = array(
			'key'   => $variable,
			'value' => $value,
		);
		return $this->call_api( $endpoint, 'PUT', $data, false );
	}

	/**
	 * Send power action (start, stop, restart, kill)
	 */
	public function send_power_action( $server_id, $signal ) {
		$endpoint = "/servers/{$server_id}/power";
		$data     = array(
			'signal' => $signal,
		);
		return $this->call_api( $endpoint, 'POST', $data, false );
	}

	/**
	 * Get server resource usage (CPU, RAM, Disk, Uptime, Network)
	 */
	public function get_server_resources( $server_id ) {
		$endpoint = "/servers/{$server_id}/resources";
		return $this->call_api( $endpoint, 'GET', null, false );
	}

	/**
	 * Get server details (name, status, limits, etc.)
	 */
	public function get_server_details( $server_id ) {
		$endpoint = "/servers/{$server_id}";
		$response = $this->call_api( $endpoint, 'GET', null, false );
		return isset( $response['attributes'] ) ? $response['attributes'] : $response;
	}

	/**
	 * Get and parse the server's config.json for current game info
	 * Returns array with scenario name, mods count, etc.
	 */
	public function get_current_game_info( $server_id ) {
		try {
			$resources = $this->get_server_resources( $server_id );
			$current_state = isset( $resources['attributes']['current_state'] ) ? $resources['attributes']['current_state'] : 'unknown';

			$info = array(
				'state'      => $current_state,
				'uptime_ms'  => isset( $resources['attributes']['resources']['uptime'] ) ? $resources['attributes']['resources']['uptime'] : 0,
				'cpu_absolute' => isset( $resources['attributes']['resources']['cpu_absolute'] ) ? $resources['attributes']['resources']['cpu_absolute'] : 0,
				'memory_bytes' => isset( $resources['attributes']['resources']['memory_bytes'] ) ? $resources['attributes']['resources']['memory_bytes'] : 0,
				'disk_bytes'   => isset( $resources['attributes']['resources']['disk_bytes'] ) ? $resources['attributes']['resources']['disk_bytes'] : 0,
				'network_rx'   => isset( $resources['attributes']['resources']['network_rx_bytes'] ) ? $resources['attributes']['resources']['network_rx_bytes'] : 0,
				'network_tx'   => isset( $resources['attributes']['resources']['network_tx_bytes'] ) ? $resources['attributes']['resources']['network_tx_bytes'] : 0,
			);

			// Try to get config.json for scenario info
			try {
				$config_raw = $this->get_file_contents( $server_id, '/config.json' );
				$config = json_decode( $config_raw, true );
				if ( $config ) {
					$info['scenario_id'] = isset( $config['game']['scenarioId'] ) ? $config['game']['scenarioId'] : '';
					$info['mods_count'] = isset( $config['game']['mods'] ) ? count( $config['game']['mods'] ) : 0;
					$info['mods'] = isset( $config['game']['mods'] ) ? $config['game']['mods'] : array();

					// Extract scenario display name from path
					$scenario = $info['scenario_id'];
					if ( preg_match( '#([^/\\\\]+)\\.conf$#', $scenario, $m ) ) {
						$info['scenario_name'] = str_replace( '_', ' ', $m[1] );
					} elseif ( $scenario ) {
						$info['scenario_name'] = basename( $scenario );
					} else {
						$info['scenario_name'] = __( 'Desconocido', 'reforger-milsim' );
					}

					// Check for persistence
					$info['persistence'] = isset( $config['game']['gameProperties']['persistence'] ) ? true : false;
				}
			} catch ( Exception $e ) {
				$info['scenario_name'] = __( 'No disponible', 'reforger-milsim' );
				$info['mods_count'] = 0;
				$info['mods'] = array();
			}

			// Get server details for limits
			try {
				$details = $this->get_server_details( $server_id );
							$info['server_name'] = isset( $details['name'] ) ? $details['name'] : '';
							// Pterodactyl devuelve límites en MB, convertir a bytes para consistencia
							$info['memory_limit'] = isset( $details['limits']['memory'] ) ? intval( $details['limits']['memory'] ) * 1024 * 1024 : 0;
							$info['disk_limit'] = isset( $details['limits']['disk'] ) ? intval( $details['limits']['disk'] ) * 1024 * 1024 : 0;
							$info['cpu_limit'] = isset( $details['limits']['cpu'] ) ? intval( $details['limits']['cpu'] ) : 0;
			} catch ( Exception $e ) {
				$info['server_name'] = '';
							$info['memory_limit'] = 0;
							$info['disk_limit'] = 0;
							$info['cpu_limit'] = 0;
						}

						return $info;
					} catch ( Exception $e ) {
						return array(
							'state' => 'error',
							'error' => $e->getMessage(),
						);
		}
	}

	/**
	 * Send notification message to Telegram channel/group
	 */
	public function notify_telegram( $text ) {
			// Preferir credenciales de RAIDs, fallback a las principales
			$token = get_option( 'rmm_raid_telegram_token', '' );
			$chat_id = get_option( 'rmm_raid_telegram_chat_id', '' );

			if ( empty( $token ) || empty( $chat_id ) ) {
				$token = $this->telegram_token;
				$chat_id = $this->telegram_chat_id;
			}

			if ( empty( $token ) || empty( $chat_id ) ) {
				return false;
			}

		// Escape Markdown for safety
		$chars = array( '_' );
		foreach ( $chars as $c ) {
			$text = str_replace( $c, '\\' . $c, $text );
		}

		$url = "https://api.telegram.org/bot{$token}/sendMessage";
				$args = array(
					'method'    => 'POST',
					'timeout'   => 15,
					'sslverify' => false,
					'body'      => array(
						'chat_id'    => $chat_id,
						'text'       => $text,
						'parse_mode' => 'Markdown',
					),
				);

		$response = wp_remote_post( $url, $args );

		if ( is_wp_error( $response ) ) {
			error_log( '❌ Telegram API Error: ' . $response->get_error_message() );
			return false;
		}

		return true;
	}

	/**
	 * Performs the complete server preset loading sequence
	 */
	public function load_preset( $server_id, $filename, &$progress = array(), $notify_telegram = true ) {
		$progress[] = __( '🔹 Iniciando proceso de carga de partida...', 'reforger-milsim' );

		// Paso 1: Descargar config.json original
		$progress[] = __( '🔹 Descargando config.json original...', 'reforger-milsim' );
		$config_json = $this->get_file_contents( $server_id, '/config.json' );
		if ( ! $config_json ) {
			throw new Exception( __( 'No se pudo obtener el config.json del servidor.', 'reforger-milsim' ) );
		}

		// Paso 2: Cargar JSON de la partida seleccionada
		$progress[] = sprintf( __( '🔹 Leyendo preset de partida: %s...', 'reforger-milsim' ), $filename );
		$partida_path = str_ends_with( $filename, '.json' ) ? '/partidas/' . $filename : '/partidas/' . $filename . '.json';
		$partida_json = $this->get_file_contents( $server_id, $partida_path );
		if ( ! $partida_json ) {
			throw new Exception( sprintf( __( 'No se encontró el archivo de partida: %s', 'reforger-milsim' ), $partida_path ) );
		}

		// Limpieza de JSON
		$partida_json = preg_replace( '/[\xC2\xA0]/', ' ', $partida_json ); // NBSP
		$partida_json = preg_replace( '/^\xEF\xBB\xBF/', '', $partida_json ); // BOM
		$partida_data = json_decode( $partida_json, true );
		if ( ! $partida_data ) {
			throw new Exception( __( 'Error al decodificar el archivo de partida (JSON inválido).', 'reforger-milsim' ) );
		}

		// Paso 3: Modificar config.json
		$progress[] = __( '🔹 Combinando configuraciones...', 'reforger-milsim' );
		$config_data = json_decode( $config_json, true );
		if ( ! $config_data ) {
			throw new Exception( __( 'Error al decodificar config.json original.', 'reforger-milsim' ) );
		}

		$export      = isset( $partida_data['ptero_export'] ) ? $partida_data['ptero_export'] : $partida_data;
		$mods        = isset( $export['mods'] ) ? $export['mods'] : array();
		$scenario    = isset( $export['scenarioId'] ) ? $export['scenarioId'] : null;
		$persistence = isset( $export['persistence'] ) ? $export['persistence'] : null;
		$name        = isset( $partida_data['name'] ) ? $partida_data['name'] : $filename;

		if ( empty( $scenario ) ) {
			throw new Exception( __( 'El preset no contiene un "scenarioId" válido.', 'reforger-milsim' ) );
		}

		// Inyectar mod TFR ORBAT Link si esta habilitado
		if ( get_option( 'rmm_orbatlink_enabled', '1' ) === '1' ) {
			$orbatlink_id = '695EADEB970201A6';
			$has_orbatlink = false;
			foreach ( $mods as $m ) {
				if ( isset( $m['modId'] ) && strtoupper( $m['modId'] ) === $orbatlink_id ) {
					$has_orbatlink = true;
					break;
				}
			}
			if ( ! $has_orbatlink ) {
				$mods[] = array( 'modId' => $orbatlink_id, 'name' => 'TFR ORBAT Link' );
				$progress[] = __( '🔹 Mod TFR ORBAT Link añadido automaticamente.', 'reforger-milsim' );
			}
		}

		$config_data['game']['mods']       = $mods;
		$config_data['game']['scenarioId'] = $scenario;

		// Persistencia
		if ( $persistence && is_array( $persistence ) ) {
			if ( isset( $persistence['databases'] ) && is_array( $persistence['databases'] ) && empty( $persistence['databases'] ) ) {
				$persistence['databases'] = new stdClass(); // Convertir [] vacío a {} objeto
			}

			if ( ! isset( $config_data['game']['gameProperties'] ) ) {
				$config_data['game']['gameProperties'] = array();
			}
			$config_data['game']['gameProperties']['persistence'] = $persistence;
			$progress[] = __( '🔹 Persistencia habilitada y configurada.', 'reforger-milsim' );
		} else {
			if ( isset( $config_data['game']['gameProperties']['persistence'] ) ) {
				unset( $config_data['game']['gameProperties']['persistence'] );
			}
			$progress[] = __( '🔹 Persistencia deshabilitada.', 'reforger-milsim' );
		}

		// Paso 4: Subir config.json actualizado
		$progress[] = __( '🔹 Subiendo nuevo config.json...', 'reforger-milsim' );
		$new_config_json = json_encode( $config_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		$this->upload_file( $server_id, '/config.json', $new_config_json );

		// Paso 5: Actualizar variable startup SCENARIO_ID
		$progress[] = __( '🔹 Actualizando variable startup SCENARIO_ID...', 'reforger-milsim' );
		$this->update_startup_variable( $server_id, 'SCENARIO_ID', $scenario );

		// Paso 6: Iniciar o Reiniciar Servidor
				$progress[] = __( '♻️ Verificando estado del servidor...', 'reforger-milsim' );
				try {
					$resources = $this->get_server_resources( $server_id );
					$state = $resources['attributes']['current_state'] ?? 'offline';
					if ( $state === 'running' ) {
						$progress[] = __( '♻️ Reiniciando servidor Pterodactyl...', 'reforger-milsim' );
						$this->send_power_action( $server_id, 'restart' );
					} else {
						$progress[] = __( '🚀 Iniciando servidor Pterodactyl...', 'reforger-milsim' );
						$this->send_power_action( $server_id, 'start' );
					}
				} catch ( Exception $e ) {
					// Si falla la verificación, intentar start de todas formas
					$progress[] = __( '🚀 Iniciando servidor...', 'reforger-milsim' );
					$this->send_power_action( $server_id, 'start' );
				}

		// Paso 7: Notificar a Telegram (si está activado)
				if ( $notify_telegram ) {
					$progress[] = __( '📢 Enviando notificación a Telegram...', 'reforger-milsim' );
			
					$num_mods = count( $mods );
					$has_persist = ( $persistence && is_array( $persistence ) ) ? '✅ Sí' : '❌ No';
			
					$msg  = "📢 *Servidor Reforger Actualizado desde la Web*\n\n";
					$msg .= "🎮 Partida: *{$name}*\n";
					$msg .= "🗺️ Escenario: `{$scenario}`\n";
					$msg .= "🧩 Mods activos: *{$num_mods}*\n";
					$msg .= "💾 Persistencia: *{$has_persist}*\n\n";
					$msg .= "♻️ *Servidor reiniciado y aplicando la nueva configuración.*";
			
					$this->notify_telegram( $msg );
				} else {
					$progress[] = __( '🔇 Notificación Telegram omitida (switch desactivado).', 'reforger-milsim' );
				}

		$progress[] = __( '✅ ¡Servidor configurado y reiniciado con éxito!', 'reforger-milsim' );

		// Subir config de TFR ORBAT Link si esta habilitado
		if ( get_option( 'rmm_orbatlink_enabled', '1' ) === '1' ) {
			try {
				$progress[] = __( '🔹 Subiendo config de TFR ORBAT Link...', 'reforger-milsim' );
				$this->upload_orbat_link_config( $server_id );
				$progress[] = __( '✅ Config de ORBAT Link subido.', 'reforger-milsim' );
			} catch ( Exception $e ) {
				$progress[] = '⚠️ ' . $e->getMessage();
			}
		}

		return true;
	}

	/**
	 * Subir archivo de configuracion del addon TFR ORBAT Link al servidor
	 */
	public function upload_orbat_link_config( $server_id ) {
		$config = array(
			'm_sBaseUrl'            => get_option( 'rmm_orbatlink_base_url', 'https://tfr.gure.party' ),
			'm_sRoute'              => get_option( 'rmm_orbatlink_route', '/wp-json/clan/v1/telemetry/push' ),
			'm_sBearerToken'        => get_option( 'rmm_orbatlink_token', get_option( 'rmm_telemetry_auth_key', 'TFR_6F8C2E9A1D4B47C99A1E7D6F3B2A8C10' ) ),
			'm_bDebug'              => get_option( 'rmm_orbatlink_debug', '1' ) === '1',
			'm_bSendOnDisconnect'   => get_option( 'rmm_orbatlink_send_disconnect', '1' ) === '1',
			'm_bSendOnGameEnd'      => get_option( 'rmm_orbatlink_send_gameend', '1' ) === '1',
			'm_bSendTestOnRegister' => get_option( 'rmm_orbatlink_send_test', '0' ) === '1',
			'm_iSendTestDelayMs'    => intval( get_option( 'rmm_orbatlink_test_delay', '5000' ) ),
			'm_bSendPeriodic'       => get_option( 'rmm_orbatlink_send_periodic', '1' ) === '1',
			'm_iSendIntervalMinutes'=> intval( get_option( 'rmm_orbatlink_interval', '5' ) ),
		);

		$json = json_encode( $config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		$path = '/profile/profile/TFR_ORBATLink/config.json';

		$this->upload_file( $server_id, $path, $json );
	}
}
