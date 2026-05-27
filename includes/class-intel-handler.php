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
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_zulu_js' ) );
	}

	public function enqueue_zulu_js() {
		wp_add_inline_script( 'jquery', '
			jQuery(function($) {
				function pad(n) { return n < 10 ? "0" + n : n; }
				var months = ["ENE","FEB","MAR","ABR","MAY","JUN","JUL","AGO","SEP","OCT","NOV","DIC"];
				function updateZulu() {
					var d = new Date();
					var hh = pad(d.getHours());
					var mm = pad(d.getMinutes());
					var dtg = pad(d.getDate()) + hh + mm + "L " + months[d.getMonth()] + " " + d.getFullYear();
					var offset = -d.getTimezoneOffset() / 60;
					var utc = "UTC" + (offset >= 0 ? "+" : "") + offset;
					$(".rmm-zulu-time-val").text(hh + ":" + mm);
					$(".rmm-zulu-dtg").text(dtg);
					$(".rmm-zulu-utc").text(utc);
				}
				updateZulu();
				setInterval(updateZulu, 60000);
			});
		' );
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
			 * Shortcode [hora_zulu] — Hora local con actualizacion cada minuto
			 */
			public function render_hora_zulu( $atts ) {
				$atts = shortcode_atts( array( 'color' => '#CFDC35' ), $atts );

				ob_start();
				?>
				<div class="rmm-zulu-time" style="display:inline-block;font-family:'JetBrains Mono','SF Mono',monospace;font-size:1rem;color:<?php echo esc_attr( $atts['color'] ); ?>;background:rgba(0,0,0,0.45);border:1px solid rgba(207,220,53,0.3);border-radius:4px;padding:8px 16px;letter-spacing:0.06em;backdrop-filter:blur(4px);">
					<span style="opacity:0.6;font-size:0.7rem;text-transform:uppercase;">⌚ HORA LOCAL •</span>
					<strong class="rmm-zulu-time-val" style="font-size:1.3rem;letter-spacing:0.08em;">--:--</strong>
					<span class="rmm-zulu-utc" style="font-size:0.6rem;opacity:0.7;margin:0 4px;">UTC</span>
					<span class="rmm-zulu-dtg" style="font-size:0.65rem;opacity:0.5;margin-left:6px;">------ --</span>
				</div>
				<?php
				return ob_get_clean();
			}
		}
