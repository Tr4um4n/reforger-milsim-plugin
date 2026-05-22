<?php
/**
 * CPT Handler Class
 *
 * Handles registration of Custom Post Types and their taxonomies.
 *
 * @package ReforgerMilsimManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RMM_CPT_Handler {

	/**
	 * Initialize hooks.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register_cpts' ) );
		add_action( 'add_meta_boxes', array( $this, 'register_raid_metaboxes' ) );
		add_action( 'save_post_raid_eventos', array( $this, 'save_raid_metadata' ) );
	}

	/**
	 * Register Custom Post Types.
	 */
	public function register_cpts() {
		// Register Misiones
		register_post_type( 'misiones', array(
			'labels' => array(
				'name'          => __( 'Misiones', 'reforger-milsim' ),
				'singular_name' => __( 'Misión', 'reforger-milsim' ),
			),
			'public'      => true,
			'has_archive' => true,
			'menu_icon'   => 'dashicons-shield-alt',
			'supports'    => array( 'title', 'editor', 'thumbnail', 'excerpt' ),
			'show_in_rest' => true,
		) );

		// Register Eventos Partidas
		register_post_type( 'eventos_partidas', array(
			'labels' => array(
				'name'          => __( 'Eventos', 'reforger-milsim' ),
				'singular_name' => __( 'Evento', 'reforger-milsim' ),
			),
			'public'      => true,
			'has_archive' => true,
			'menu_icon'   => 'dashicons-calendar-alt',
			'supports'    => array( 'title', 'editor', 'thumbnail' ),
			'show_in_rest' => true,
		) );

		// Register Condecoraciones
		register_post_type( 'condecoraciones', array(
			'labels' => array(
				'name'          => __( 'Condecoraciones', 'reforger-milsim' ),
				'singular_name' => __( 'Condecoración', 'reforger-milsim' ),
			),
			'public'      => true,
			'has_archive' => true,
			'menu_icon'   => 'dashicons-awards',
			'supports'    => array( 'title', 'editor', 'thumbnail' ),
			'show_in_rest' => true,
		) );

		// Register Raid Eventos
		register_post_type( 'raid_eventos', array(
			'labels' => array(
				'name'          => __( 'RAID Eventos', 'reforger-milsim' ),
				'singular_name' => __( 'RAID Evento', 'reforger-milsim' ),
				'add_new'       => __( 'Añadir RAID', 'reforger-milsim' ),
				'add_new_item'  => __( 'Añadir nuevo RAID', 'reforger-milsim' ),
				'edit_item'     => __( 'Editar RAID', 'reforger-milsim' ),
			),
			'public'      => true,
			'has_archive' => true,
			'menu_icon'   => 'dashicons-games',
			'supports'    => array( 'title', 'editor', 'thumbnail' ),
			'show_in_rest' => true,
		) );
	}

	/**
	 * Registrar metaboxes para RAID eventos
	 */
	public function register_raid_metaboxes() {
		add_meta_box(
			'rmm_raid_details',
			__( 'Detalles de la RAID', 'reforger-milsim' ),
			array( $this, 'render_raid_metabox' ),
			'raid_eventos',
			'normal',
			'high'
		);
	}

	/**
	 * Renderizar metabox de RAID
	 */
	public function render_raid_metabox( $post ) {
		$fecha  = get_post_meta( $post->ID, 'raid_fecha', true );
		$hora   = get_post_meta( $post->ID, 'raid_hora', true );
		$servidor = get_post_meta( $post->ID, 'raid_servidor', true );
		$password = get_post_meta( $post->ID, 'raid_password', true );
		$estado = get_post_meta( $post->ID, 'raid_estado', true ) ?: 'activa';
		wp_nonce_field( 'rmm_raid_metabox', 'rmm_raid_metabox_nonce' );
		?>
		<style>
			.rmm-raid-fields label { display:block; font-weight:600; margin-top:12px; }
			.rmm-raid-fields input, .rmm-raid-fields select { width:100%; max-width:400px; }
		</style>
		<div class="rmm-raid-fields">
			<p>
				<label>Fecha</label>
				<input type="date" name="raid_fecha" value="<?php echo esc_attr( $fecha ); ?>">
			</p>
			<p>
				<label>Hora</label>
				<input type="time" name="raid_hora" value="<?php echo esc_attr( $hora ); ?>">
			</p>
			<p>
				<label>Servidor</label>
				<input type="text" name="raid_servidor" value="<?php echo esc_attr( $servidor ); ?>" placeholder="STABLE / TESTING">
			</p>
			<p>
				<label>Contraseña</label>
				<input type="text" name="raid_password" value="<?php echo esc_attr( $password ); ?>">
			</p>
			<p>
				<label>Estado</label>
				<select name="raid_estado">
					<option value="activa" <?php selected( $estado, 'activa' ); ?>>Activa</option>
					<option value="finalizada" <?php selected( $estado, 'finalizada' ); ?>>Finalizada</option>
					<option value="cancelada" <?php selected( $estado, 'cancelada' ); ?>>Cancelada</option>
				</select>
			</p>
		</div>
		<?php
	}

	/**
	 * Guardar metadata de RAID
	 */
	public function save_raid_metadata( $post_id ) {
		if ( ! isset( $_POST['rmm_raid_metabox_nonce'] ) || ! wp_verify_nonce( $_POST['rmm_raid_metabox_nonce'], 'rmm_raid_metabox' ) ) return;
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
		if ( ! current_user_can( 'edit_post', $post_id ) ) return;

		$fields = array( 'raid_fecha', 'raid_hora', 'raid_servidor', 'raid_password', 'raid_estado' );
		foreach ( $fields as $field ) {
			if ( isset( $_POST[ $field ] ) ) {
				update_post_meta( $post_id, $field, sanitize_text_field( $_POST[ $field ] ) );
			}
		}
	}
}
