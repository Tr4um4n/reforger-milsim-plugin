<?php
/**
 * Metabox Handler Class - Refactored Phase 3
 * @package ReforgerMilsimManagement
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class RMM_Metabox_Handler {

	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'register_metaboxes' ) );
		add_action( 'save_post', array( $this, 'save_all_metadata' ) );
		// AJAX Handler for Workshop Sync
		add_action( 'wp_ajax_sync_reforger_api', array( $this, 'ajax_sync_workshop' ) );
	}

	public function register_metaboxes() {
		add_meta_box( 'rmm_orbat_manager', __( 'Gestor de ORBAT Pro', 'reforger-milsim' ), array( $this, 'render_orbat_metabox' ), array( 'misiones', 'eventos_partidas' ), 'normal', 'high' );
		add_meta_box( 'rmm_mission_config', __( 'Configuración de Misión', 'reforger-milsim' ), array( $this, 'render_mission_metabox' ), 'misiones', 'side' );
		add_meta_box( 'rmm_event_config', __( 'Configuración de Evento', 'reforger-milsim' ), array( $this, 'render_event_metabox' ), 'eventos_partidas', 'side' );
	}

	/**
	 * AJAX: Sincronización con Workshop API
	 */
	public function ajax_sync_workshop() {
		check_ajax_referer( 'rmm_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) wp_send_json_error( 'No permission' );

		$id = sanitize_text_field( $_POST['workshop_id'] );
		$response = wp_remote_get( "https://steam.gure.party/api.php?action=dependencies&id=$id" );

		if ( is_wp_error( $response ) ) wp_send_json_error( 'Error de conexión con la API' );

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! $body || ! isset( $body['item'] ) ) wp_send_json_error( 'ID de Workshop no encontrado' );

		wp_send_json_success( array(
			'title' => $body['item']['title'],
			'url'   => $body['item']['url'],
			'dependencies' => array_column( $body['dependencies'], 'name' )
		) );
	}

	/**
	 * RENDER: Configuración de Misión
	 */
	public function render_mission_metabox( $post ) {
		$workshop_id = get_post_meta( $post->ID, 'workshop_id', true );
		$mission_name = get_post_meta( $post->ID, 'mission_api_name', true );
		$workshop_url = get_post_meta( $post->ID, 'workshop_url', true );
		$addons = get_post_meta( $post->ID, 'addons_requeridos', true );
		$addons_text = is_array($addons) ? implode("\n", $addons) : $addons;

		?>
		<div class="rmm-api-sync-box">
			<label><strong>Workshop ID</strong></label>
			<div style="display:flex; gap:5px; margin: 5px 0;">
				<input type="text" name="workshop_id" id="workshop_id" value="<?php echo esc_attr($workshop_id); ?>" class="widefat" placeholder="66B69C25...">
				<button type="button" id="btn-sync-workshop" class="button button-primary">Sync</button>
			</div>
			<div id="api-preview" style="background:#f0f0f1; padding:10px; border-radius:4px; font-size:12px; <?php echo $mission_name ? '' : 'display:none;'; ?>">
				<p><strong>Misión:</strong> <span id="prev-name"><?php echo esc_html($mission_name); ?></span></p>
				<p><a id="prev-url" href="<?php echo esc_url($workshop_url); ?>" target="_blank">Ver en Workshop</a></p>
			</div>
			<input type="hidden" name="mission_api_name" id="hidden-api-name" value="<?php echo esc_attr($mission_name); ?>">
			<input type="hidden" name="workshop_url" id="hidden-api-url" value="<?php echo esc_attr($workshop_url); ?>">
			<p>
				<label><strong>Dependencias (Addons)</strong></label>
				<textarea name="addons_requeridos_text" id="addons_requeridos" readonly class="widefat" rows="5"><?php echo esc_textarea($addons_text); ?></textarea>
			</p>
		</div>
		<script>
		jQuery('#btn-sync-workshop').on('click', function() {
			const id = jQuery('#workshop_id').val();
			if(!id) return alert('Introduce un ID');
			const btn = jQuery(this).prop('disabled', true).text('...');
			
			jQuery.post(rmmAdminData.ajax_url, {
				action: 'sync_reforger_api',
				workshop_id: id,
				nonce: rmmAdminData.nonce
			}, function(res) {
				btn.prop('disabled', false).text('Sync');
				if(res.success) {
					jQuery('#prev-name, #hidden-api-name').text(res.data.title).val(res.data.title);
					jQuery('#prev-url, #hidden-api-url').attr('href', res.data.url).text('Ver en Workshop').val(res.data.url);
					jQuery('#addons_requeridos').val(res.data.dependencies.join("\n"));
					jQuery('#api-preview').show();
				} else alert(res.data);
			});
		});
		</script>
		<?php
	}

	/**
	 * RENDER: Configuración de Evento
	 */
	public function render_event_metabox( $post ) {
		$mision_id = get_post_meta( $post->ID, 'mision_id', true );
		$fecha_inicio = get_post_meta( $post->ID, 'fecha_inicio', true );
		$fecha_fin = get_post_meta( $post->ID, 'fecha_fin', true );
		$estado = get_post_meta( $post->ID, 'estado', true );
		$condecoracion_id = get_post_meta( $post->ID, 'condecoracion_premio', true );

		$misiones = get_posts( array( 'post_type' => 'misiones', 'numberposts' => -1 ) );
		$medallas = get_posts( array( 'post_type' => 'condecoraciones', 'numberposts' => -1 ) );

		?>
		<p><label><strong>Misión</strong></label><select name="mision_id" class="widefat">
			<option value="">-- Elige Misión --</option>
			<?php foreach($misiones as $m) echo '<option value="'.$m->ID.'" '.selected($mision_id,$m->ID,false).'>'.$m->post_title.'</option>'; ?>
		</select></p>
		<p><label><strong>Inicio</strong></label><input type="datetime-local" name="fecha_inicio" value="<?php echo $fecha_inicio; ?>" class="widefat"></p>
		<p><label><strong>Fin</strong></label><input type="datetime-local" name="fecha_fin" value="<?php echo $fecha_fin; ?>" class="widefat"></p>
		<p><label><strong>Estado</strong></label><select name="estado" class="widefat">
			<?php foreach(['abierta','en_curso','debriefing','finalizada'] as $s) echo '<option value="'.$s.'" '.selected($estado,$s,false).'>'.ucfirst($s).'</option>'; ?>
		</select></p>
		<p><label><strong>Medalla de Premio</strong></label><select name="condecoracion_premio" class="widefat">
			<option value="">-- Ninguna --</option>
			<?php foreach($medallas as $m) echo '<option value="'.$m->ID.'" '.selected($condecoracion_id,$m->ID,false).'>'.$m->post_title.'</option>'; ?>
		</select></p>
		<?php
	}

	/**
	 * RENDER: Gestor de ORBAT (Refactorizado con Select2 y Roles)
	 */
	public function render_orbat_metabox( $post ) {
		wp_nonce_field( 'rmm_save_metadata', 'rmm_metadata_nonce' );
		$meta_key = ( $post->post_type === 'misiones' ) ? 'orbat_maestro' : 'orbat_activo';
		$orbat_json = get_post_meta( $post->ID, $meta_key, true );
		if ( empty( $orbat_json ) ) $orbat_json = '[]';

		?>
		<div id="rmm-orbat-app">
			<div id="rmm-squads-container"></div>
			<button type="button" class="button button-primary" id="rmm-add-squad">Añadir Escuadra</button>
			<input type="hidden" name="rmm_orbat_data" id="rmm-orbat-data-input" value='<?php echo esc_attr(json_encode($orbat_json)); ?>'>
		</div>
		<style>
			.rmm-squad-card { background:#fff; border:1px solid #ccd0d4; padding:15px; margin-bottom:15px; border-left:4px solid #2271b1; }
			.rmm-slot-row { display:grid; grid-template-columns: 1.5fr 2fr auto; gap:10px; padding:10px; border-bottom:1px solid #eee; align-items:center; }
			.rmm-slot-row select { width:100% !important; }
			.rmm-status-badge { background:#e7f5ed; color:#184a33; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:bold; }
		</style>
		<script>
		jQuery(document).ready(function($) {
			const container = $('#rmm-squads-container');
			const input = $('#rmm-orbat-data-input');
			let data = JSON.parse(input.val() || '[]');
			if(typeof data === 'string') data = JSON.parse(data);

			function render() {
				container.empty();
				data.forEach((squad, sIdx) => {
					const card = $(`<div class="rmm-squad-card">
						<div style="display:flex; justify-content:space-between; margin-bottom:10px;">
							<input type="text" value="${squad.escuadra}" placeholder="Alpha" style="font-weight:bold; border:none; background:none;">
							<span class="rmm-remove-btn" style="cursor:pointer; color:#d63638;">&times;</span>
						</div>
						<div class="rmm-slots-list"></div>
						<button type="button" class="button rmm-add-slot" style="margin-top:10px;">+ Slot</button>
					</div>`);

					squad.slots.forEach((slot, rIdx) => {
						const row = $(`<div class="rmm-slot-row">
							<select class="slot-role-sel"></select>
							<select class="orbat-medals-select" multiple style="width:100%"></select>
							<div style="display:flex; align-items:center; gap:5px;">
								${(slot.usuario_id && rmmAdminData.is_event) ? `<span class="rmm-status-badge">User ID: ${slot.usuario_id}</span>` : ''}
								<span class="rmm-remove-btn" style="cursor:pointer; color:#d63638;">&times;</span>
							</div>
						</div>`);

						// Populate Roles
						rmmAdminData.roles.forEach(r => row.find('.slot-role-sel').append(new Option(r, r, r===slot.rol, r===slot.rol)));
						
						// Populate Medals (Select2)
						rmmAdminData.medals.forEach(m => {
							const selected = (slot.condecoraciones_requeridas || []).includes(m.id);
							row.find('.orbat-medals-select').append(new Option(m.text, m.id, selected, selected));
						});

						row.find('.slot-role-sel').on('change', function() { data[sIdx].slots[rIdx].rol = $(this).val(); updateInput(); });
						row.find('.orbat-medals-select').select2({ placeholder: "Medallas Requeridas" }).on('change', function() {
							data[sIdx].slots[rIdx].condecoraciones_requeridas = $(this).val().map(Number);
							updateInput();
						});
						
						row.find('.rmm-remove-btn').on('click', () => { data[sIdx].slots.splice(rIdx, 1); render(); updateInput(); });
						card.find('.rmm-slots-list').append(row);
					});

					card.find('.rmm-add-slot').on('click', () => {
						data[sIdx].slots.push({ id: crypto.randomUUID(), rol:'', usuario_id: null, condecoraciones_requeridas: [] });
						render(); updateInput();
					});
					card.find('.rmm-remove-btn').first().on('click', () => { data.splice(sIdx, 1); render(); updateInput(); });
					card.find('input').first().on('change', function() { data[sIdx].escuadra = $(this).val(); updateInput(); });
					container.append(card);
				});
			}

			function updateInput() { input.val(JSON.stringify(data)); }
			$('#rmm-add-squad').on('click', () => { data.push({ escuadra: '', slots: [] }); render(); updateInput(); });
			
			rmmAdminData.is_event = <?php echo get_post_type($post->ID) === 'eventos_partidas' ? 'true' : 'false'; ?>;
			render();
		});
		</script>
		<?php
	}

	public function save_all_metadata( $post_id ) {
		if ( ! isset( $_POST['rmm_metadata_nonce'] ) || ! wp_verify_nonce( $_POST['rmm_metadata_nonce'], 'rmm_save_metadata' ) ) return;
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;

		// Save ORBAT
		if ( isset( $_POST['rmm_orbat_data'] ) ) {
			$data = json_decode( stripslashes( $_POST['rmm_orbat_data'] ), true );
			if( is_array($data) ) {
				$key = ( get_post_type($post_id) === 'misiones' ) ? 'orbat_maestro' : 'orbat_activo';
				update_post_meta( $post_id, $key, $data );
			}
		}

		// Save Mission/Event fields
		$fields = array( 'workshop_id', 'mission_api_name', 'workshop_url', 'mision_id', 'fecha_inicio', 'fecha_fin', 'estado', 'condecoracion_premio' );
		foreach ( $fields as $f ) {
			if ( isset( $_POST[$f] ) ) update_post_meta( $post_id, $f, sanitize_text_field( $_POST[$f] ) );
		}
		
		// Save Addons (processed as array from text)
		if ( isset( $_POST['addons_requeridos_text'] ) ) {
			$addons = array_filter( array_map( 'trim', explode( "\n", $_POST['addons_requeridos_text'] ) ) );
			update_post_meta( $post_id, 'addons_requeridos', $addons );
		}
	}
}
