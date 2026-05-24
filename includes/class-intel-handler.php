<?php
/**
 * Intel Coordinates Shortcode Handler
 * @package ReforgerMilsimManagement
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class RMM_Intel_Handler {

	public function __construct() {
			add_shortcode( 'coordenadas_militar', array( $this, 'shortcode_coordenadas_militares' ) );
			add_shortcode( 'hora_zulu', array( $this, 'render_hora_zulu' ) );
		}

	public function shortcode_coordenadas_militares( $atts ) {
		// 1. Configurar atributos por defecto
		$atributos = shortcode_atts(array(
			'color'    => '#849b4c',  // Verde oliva táctico por defecto
			'size'     => '14px',     // Tamaño de letra por defecto
			'layout'   => 'vertical', // 'vertical' u 'horizontal'
			'ip'       => '0',
			'intel'    => '1',
			'location' => '0'
		), $atts);

		// 2. Obtener la IP real del visitante
		$ip_visitante = '';
		if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
			$ip_visitante = $_SERVER['HTTP_CLIENT_IP'];
		} elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
			$ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
			$ip_visitante = trim($ips[0]);
		} else {
			$ip_visitante = $_SERVER['REMOTE_ADDR'];
		}

		// IP de pruebas para entorno local (localhost)
		if ($ip_visitante == '127.0.0.1' || $ip_visitante == '::1') {
			$ip_visitante = '8.8.8.8'; 
		}

		// 3. Consultar la API de geolocalización
		$url = "http://ip-api.com/json/" . $ip_visitante . "?fields=status,lat,lon,countryCode,city";
		$respuesta = wp_remote_get($url, array('timeout' => 2));

		$latitud = "NO DATA";
		$longitud = "NO DATA";
		$ubicacion = "UNKNOWN_LOC";

		if (!is_wp_error($respuesta) && wp_remote_retrieve_response_code($respuesta) == 200) {
			$datos = json_decode(wp_remote_retrieve_body($respuesta), true);
			
			if ($datos && $datos['status'] === 'success') {
				$latitud  = number_format($datos['lat'], 4, '.', '');
				$longitud = number_format($datos['lon'], 4, '.', '');
				$ubicacion = strtoupper($datos['city'] . "_" . $datos['countryCode']);
			}
		}

		// Definir la clase CSS según el layout elegido
		$clase_layout = ($atributos['layout'] === 'horizontal') ? 'intel-layout-horizontal' : 'intel-layout-vertical';

		// 4. Renderizado con estilos dinámicos
		ob_start();
		?>
		<style>
			.intel-coordinates-box {
				font-family: 'Courier New', Courier, monospace;
				font-weight: bold;
				text-transform: uppercase;
				letter-spacing: 1.5px;
				padding: 10px 15px;
				/*border-left: 3px solid <?php echo esc_attr($atributos['color']); ?>;*/
				/*background-color: rgba(0, 0, 0, 0.2);*/
				display: inline-block;
				line-height: 1.5;
				font-size: <?php echo esc_attr($atributos['size']); ?>;
			}
			
			/* Modos de visualización */
			.intel-layout-vertical div {
				display: block;
			}
			.intel-layout-horizontal div {
				display: inline-block;
				margin-right: 15px;
			}
			.intel-layout-horizontal div:last-child {
				margin-right: 0;
			}

			.intel-blink {
				animation: intel-parpadeo 1s infinite;
			}
			@keyframes intel-parpadeo {
				0% { opacity: 1; }
				50% { opacity: 0.1; }
				100% { opacity: 1; }
			}
		</style>

		<div class="intel-coordinates-box <?php echo esc_attr($clase_layout); ?>" style="color: <?php echo esc_attr($atributos['color']); ?>;">
			<?php if ($atributos['intel'] > 0) echo '<div>[INTEL]<span class="intel-blink">_</span></div>';?>
			<?php if ($atributos['ip'] > 0) echo '<div>IP: ' . esc_html($ip_visitante) .'</div>';?>
			<?php if ($atributos['location'] > 0) echo '<div>LOC: ' . esc_html($ubicacion) .'</div>';?>
			<div>LAT:<?php echo esc_html($latitud); ?>N</div>
			<div>LON:<?php echo esc_html($longitud); ?>E</div>
		</div>
		<?php
		return ob_get_clean();
			}

			/**
			 * Shortcode [hora_zulu] — Hora peninsular española en formato militar
			 */
			public function render_hora_zulu( $atts ) {
				$atts = shortcode_atts( array( 'color' => '#CFDC35' ), $atts );

				// Construir fecha con offset GMT de WP
								$gmt = intval( get_option( 'gmt_offset', 2 ) );
								$sign = $gmt >= 0 ? '+' : '';
								$tz = new DateTimeZone( $sign . str_pad( abs( $gmt ), 2, '0', STR_PAD_LEFT ) . ':00' );
								$dt = new DateTime( 'now', new DateTimeZone( 'UTC' ) );
								$dt->setTimezone( $tz );

								// Letra de zona horaria militar: A=UTC+1 (Alpha), B=UTC+2 (Bravo)
								$offset = $dt->getOffset() / 3600;
				$zones = array( 1 => 'A', 2 => 'B', 0 => 'Z', -1 => 'N' );
				$zone_letter = $zones[ $offset ] ?? 'L'; // L = Local

				// Formato estilo militar: DTG (Date Time Group)
				$dtg = $dt->format( 'dHi') . $zone_letter . ' ' . strtoupper( $dt->format( 'M Y' ) );
				$hora = $dt->format( 'H:i' );

				ob_start();
				?>
				<div class="rmm-zulu-time" style="display:inline-block;font-family:'JetBrains Mono','SF Mono',monospace;font-size:0.85rem;color:<?php echo esc_attr( $atts['color'] ); ?>;background:rgba(0,0,0,0.3);border:1px solid rgba(207,220,53,0.3);border-radius:4px;padding:6px 14px;letter-spacing:0.06em;">
					<span style="opacity:0.6;font-size:0.65rem;text-transform:uppercase;">⌚ HORA LOCAL • </span>
					<strong style="font-size:1.1rem;"><?php echo $hora; ?></strong>
					<span style="font-size:0.6rem;opacity:0.5;margin-left:6px;"><?php echo $dtg; ?></span>
				</div>
				<style>
					.rmm-zulu-time strong { letter-spacing:0.08em; }
				</style>
				<?php
				return ob_get_clean();
			}
		}
