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
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		// AJAX Handlers
		add_action( 'wp_ajax_sync_reforger_api', array( $this, 'ajax_sync_workshop' ) );
		add_action( 'wp_ajax_get_mission_orbat', array( $this, 'ajax_get_mission_orbat' ) );
		add_action( 'wp_ajax_set_workshop_thumbnail', array( $this, 'ajax_set_workshop_thumbnail' ) );
		add_action( 'wp_ajax_sync_mission_to_event', array( $this, 'ajax_sync_mission_to_event' ) );
		add_action( 'wp_ajax_save_orbat_preset', array( $this, 'ajax_save_orbat_preset' ) );
		add_action( 'wp_ajax_get_orbat_presets', array( $this, 'ajax_get_orbat_presets' ) );
		add_action( 'wp_ajax_delete_orbat_preset', array( $this, 'ajax_delete_orbat_preset' ) );
	}

	/**
	 * Enqueue Admin Scripts and CSS
	 */
	public function enqueue_admin_assets( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ) ) ) return;
		
		$post_type = get_post_type();
		if ( ! in_array( $post_type, array( 'misiones', 'eventos_partidas' ) ) ) return;

		// Load Select2 for better UI
		wp_enqueue_style( 'select2-css', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css' );
		wp_enqueue_script( 'select2-js', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js', array( 'jquery' ), '4.1.0', true );

		// Localize data for JS
		$medals = get_posts( array( 'post_type' => 'condecoraciones', 'numberposts' => -1 ) );
		$medals_data = array();
		foreach ( $medals as $m ) {
			$medals_data[] = array( 'id' => $m->ID, 'text' => $m->post_title );
		}

		// Get users for assignment in events
		$users = get_users( array( 'fields' => array( 'ID', 'display_name' ) ) );
		$users_data = array();
		foreach ( $users as $u ) {
			$users_data[] = array( 'id' => $u->ID, 'text' => $u->display_name );
		}

		$roles_data = rmm_get_orbat_roles();
		wp_localize_script( 'jquery', 'rmmAdminData', array(
			'ajax_url'   => admin_url( 'admin-ajax.php' ),
			'nonce'      => wp_create_nonce( 'rmm_admin_nonce' ),
			'roles'      => array_keys( $roles_data ),
			'roles_imgs' => $roles_data,
			'medals'     => $medals_data,
			'users'      => $users_data,
			'is_event'   => ( $post_type === 'eventos_partidas' )
		) );
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
		$api_url = plugins_url( 'api.php', __FILE__ );
		$response = wp_remote_get( add_query_arg( array( 'action' => 'dependencies', 'id' => $id ), $api_url ) );

		if ( is_wp_error( $response ) ) wp_send_json_error( 'Error de conexión con la API' );

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! $body || ! isset( $body['item'] ) ) wp_send_json_error( 'ID de Workshop no encontrado' );

		wp_send_json_success( array(
			'title' => $body['item']['title'] ?? '',
			'author' => $body['item']['author'] ?? '',
			'url'   => $body['item']['url'] ?? '',
			'image' => $body['item']['image'] ?? '',
			'summary' => $body['item']['summary'] ?? '',
			'description' => $body['item']['description'] ?? '',
			'dependencies' => isset($body['dependencies']) ? array_column( $body['dependencies'], 'name' ) : array()
		) );
	}

	/**
	 * AJAX: Obtener ORBAT de una misión específica
	 */
	public function ajax_get_mission_orbat() {
		check_ajax_referer( 'rmm_admin_nonce', 'nonce' );
		$mission_id = intval( $_POST['mission_id'] );
		
		$orbat = get_post_meta( $mission_id, 'orbat_maestro', true );
		if ( empty( $orbat ) ) wp_send_json_error( 'Esta misión no tiene ORBAT configurado.' );
		
		$addons = get_post_meta( $mission_id, 'addons_requeridos', true );
		$addons_text = is_array($addons) ? implode("\n", $addons) : $addons;
		
		$mission_post = get_post( $mission_id );
		
		wp_send_json_success( array(
			'orbat' => $orbat,
			'addons' => $addons_text,
			'content' => $mission_post ? $mission_post->post_content : ''
		) );
	}

	/**
	 * AJAX: Sideload Workshop Image
	 */
	public function ajax_set_workshop_thumbnail() {
		check_ajax_referer( 'rmm_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) wp_send_json_error( 'No permission' );

		$post_id = intval( $_POST['post_id'] );
		$image_url = esc_url_raw( $_POST['image_url'] );

		if ( empty($image_url) ) wp_send_json_error('No URL provided');
		if ( has_post_thumbnail( $post_id ) ) wp_send_json_success('Already has thumbnail');

		require_once(ABSPATH . 'wp-admin/includes/media.php');
		require_once(ABSPATH . 'wp-admin/includes/file.php');
		require_once(ABSPATH . 'wp-admin/includes/image.php');

		$attach_id = media_sideload_image($image_url, $post_id, null, 'id');
		if ( is_wp_error($attach_id) ) {
			wp_send_json_error( $attach_id->get_error_message() );
		}

		set_post_thumbnail( $post_id, $attach_id );
		wp_send_json_success( array( 'message' => 'Thumbnail set', 'attach_id' => $attach_id ) );
	}

	/**
	 * AJAX: Sincronizar datos de Misión a Evento
	 */
	public function ajax_sync_mission_to_event() {
		check_ajax_referer( 'rmm_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) wp_send_json_error( 'No permission' );

		$mission_id = intval( $_POST['mission_id'] );
		$mission = get_post( $mission_id );
		if ( ! $mission ) wp_send_json_error( 'Misión no encontrada' );

		$orbat = get_post_meta( $mission_id, 'orbat_maestro', true );
		$workshop_id = get_post_meta( $mission_id, 'workshop_id', true );
		$workshop_url = get_post_meta( $mission_id, 'workshop_url', true );
		$addons = get_post_meta( $mission_id, 'addons_requeridos', true );
		$thumbnail_id = get_post_thumbnail_id( $mission_id );
		$summary = get_post_meta( $mission_id, 'rmm_summary', true );
		$description = get_post_meta( $mission_id, 'rmm_description', true );
		
		// Copiar imagen destacada al evento (el JS ya tiene el post_id en res.data)
		// La copia real se hace en el JS al recibir el thumbnail_id

		wp_send_json_success( array(
			'title'       => $mission->post_title,
			'content'     => $mission->post_content,
			'orbat'       => $orbat,
			'workshop_id' => $workshop_id,
			'workshop_url' => $workshop_url,
			'addons'      => $addons,
			'thumbnail_id' => $thumbnail_id,
			'summary'     => $summary,
			'description' => $description
		) );
	}

	/**
	 * AJAX: Guardar Preset de ORBAT
	 */
	public function ajax_save_orbat_preset() {
		check_ajax_referer( 'rmm_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) wp_send_json_error( 'No permission' );

		$name = sanitize_text_field( $_POST['preset_name'] );
		$orbat_data = json_decode( stripslashes( $_POST['orbat_data'] ), true );

		if ( empty($name) ) wp_send_json_error( 'El nombre del preset no puede estar vacío.' );
		if ( ! is_array($orbat_data) ) wp_send_json_error( 'Datos de ORBAT inválidos.' );

		$presets = get_option( 'rmm_orbat_presets', array() );
		$presets[$name] = $orbat_data;

		update_option( 'rmm_orbat_presets', $presets );
		wp_send_json_success( 'Preset guardado correctamente.' );
	}

	/**
	 * AJAX: Obtener Presets de ORBAT
	 */
	public function ajax_get_orbat_presets() {
		check_ajax_referer( 'rmm_admin_nonce', 'nonce' );
		$presets = get_option( 'rmm_orbat_presets', array() );
		wp_send_json_success( $presets );
	}

	/**
	 * AJAX: Eliminar Preset de ORBAT
	 */
	public function ajax_delete_orbat_preset() {
		check_ajax_referer( 'rmm_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) wp_send_json_error( 'No permission' );

		$name = sanitize_text_field( $_POST['preset_name'] );
		$presets = get_option( 'rmm_orbat_presets', array() );

		if ( isset( $presets[$name] ) ) {
			unset( $presets[$name] );
			update_option( 'rmm_orbat_presets', $presets );
			wp_send_json_success( 'Preset eliminado.' );
		} else {
			wp_send_json_error( 'Preset no encontrado.' );
		}
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
			<label style="display:block; margin-bottom:10px; font-weight:bold; letter-spacing:1px; color:#aaa; text-transform:uppercase; font-size:10px;">Workshop Interface</label>
			<div style="margin-bottom:15px;">
				<input type="text" name="workshop_id" id="workshop_id" value="<?php echo esc_attr($workshop_id); ?>" style="width:100%; box-sizing:border-box; background:#111; border:1px solid #444; color:#fff; padding:8px; margin-bottom:8px;" placeholder="ID del Workshop...">
				<button type="button" id="btn-sync-workshop" class="button button-primary" style="width:100%; text-align:center;">SYNC DATA</button>
			</div>
			<div id="api-preview" style="background:#111; padding:12px; border:1px solid #333; border-radius:4px; margin-bottom:15px; <?php echo $mission_name ? '' : 'display:none;'; ?>">
				<div style="color:#2271b1; font-weight:bold; margin-bottom:5px;"><?php _e('CONECTADO', 'reforger-milsim'); ?></div>
				<p style="margin:0; font-size:13px;"><strong>Misión:</strong> <span id="prev-name"><?php echo esc_html($mission_name); ?></span></p>
				<p style="margin:5px 0 0 0; font-size:12px;"><a id="prev-url" href="<?php echo esc_url($workshop_url); ?>" target="_blank" style="color:#72aee6;">Ver en Steam Workshop</a></p>
			</div>
			<input type="hidden" name="mission_api_name" id="hidden-api-name" value="<?php echo esc_attr($mission_name); ?>">
			<input type="hidden" name="workshop_url" id="hidden-api-url" value="<?php echo esc_attr($workshop_url); ?>">
			<input type="hidden" name="workshop_image_url" id="workshop_image_url" value="">
			<textarea style="display:none;" name="rmm_summary" id="hidden-summary"><?php echo esc_textarea(get_post_meta($post->ID, 'rmm_summary', true)); ?></textarea>
			<textarea style="display:none;" name="rmm_description" id="hidden-description"><?php echo esc_textarea(get_post_meta($post->ID, 'rmm_description', true)); ?></textarea>
			<div style="margin-top:15px;">
				<label style="display:block; margin-bottom:5px; font-size:11px; color:#aaa;">AUTOR DE LA MISIÓN</label>
				<input type="text" name="rmm_author" id="rmm_author" value="<?php echo esc_attr(get_post_meta($post->ID, 'rmm_author', true)); ?>" style="width:100%; background:#111; border:1px solid #444; color:#fff; padding:8px; box-sizing:border-box;" placeholder="Nombre del creador del mod...">
			</div>
			<div style="margin-top:15px;">
				<label style="display:block; margin-bottom:5px; font-size:11px; color:#aaa;">LISTA DE DEPENDENCIAS</label>
				<textarea name="addons_requeridos_text" id="addons_requeridos" readonly style="width:100%; background:#111; border:1px solid #333; color:#888; font-family:monospace; font-size:11px;" rows="4"><?php echo esc_textarea($addons_text); ?></textarea>
			</div>
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
				btn.prop('disabled', false).text('SYNC DATA');
				if(res.success) {
					jQuery('#prev-name, #hidden-api-name').text(res.data.title).val(res.data.title);
					jQuery('#prev-url, #hidden-api-url').attr('href', res.data.url).text('Ver en Workshop').val(res.data.url);
					jQuery('#addons_requeridos').val(res.data.dependencies.join("\n"));
					if(res.data.summary) jQuery('#hidden-summary').val(res.data.summary);
					if(res.data.description) jQuery('#hidden-description').val(res.data.description);
					if(res.data.author) jQuery('#rmm_author').val(res.data.author);
					jQuery('#api-preview').slideDown();
					
					// Auto-descargar y setear imagen destacada via AJAX
					if (res.data.image && jQuery('#post_ID').length) {
						let postId = jQuery('#post_ID').val();
						jQuery.post(rmmAdminData.ajax_url, {
							action: 'set_workshop_thumbnail',
							post_id: postId,
							image_url: res.data.image,
							nonce: rmmAdminData.nonce
						}, function(imgRes) {
							if(imgRes.success) {
								if(imgRes.data && imgRes.data.attach_id && typeof wp !== 'undefined' && wp.data && wp.data.dispatch('core/editor')) {
									wp.data.dispatch('core/editor').editPost({ featured_media: imgRes.data.attach_id });
								}
							} else if(imgRes.data !== 'Already has thumbnail') {
								console.error("Error descargando imagen destacada:", imgRes.data);
								alert("Aviso: No se pudo descargar la imagen destacada. Error: " + imgRes.data);
							}
						});
					}
					
					// Inject auto-summary into editor if empty
					let contentText = "<h3>Operación: " + res.data.title + "</h3>\n";
					if (res.data.summary) {
						contentText += "<blockquote><strong>Resumen:</strong> " + res.data.summary.replace(/\n/g, "<br>") + "</blockquote>\n";
					}
					if (res.data.description) {
						contentText += "<h4>Briefing Oficial:</h4>\n<p>" + res.data.description.replace(/\n/g, "<br>") + "</p>\n";
					}
					contentText += "<p><strong>Enlace oficial:</strong> <a href='" + res.data.url + "' target='_blank'>Steam Workshop</a></p>\n";
					contentText += "[clan_orbat]\n";
					
					if (typeof wp !== 'undefined' && wp.data && wp.data.select('core/editor')) {
						let currentContent = wp.data.select('core/editor').getEditedPostAttribute('content');
						if (!currentContent || currentContent.trim() === '') {
							wp.data.dispatch('core/editor').editPost({ content: contentText });
						}
					} else if (typeof tinymce !== 'undefined' && tinymce.activeEditor && !tinymce.activeEditor.isHidden()) {
						let currentContent = tinymce.activeEditor.getContent();
						if (!currentContent || currentContent.trim() === '') {
							tinymce.activeEditor.setContent(contentText);
						}
					} else if (jQuery('#content').length) {
						let currentContent = jQuery('#content').val();
						if (!currentContent || currentContent.trim() === '') {
							jQuery('#content').val(contentText);
						}
					}
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
		<textarea style="display:none;" name="rmm_summary" id="hidden-summary"><?php echo esc_textarea(get_post_meta($post->ID, 'rmm_summary', true)); ?></textarea>
		<textarea style="display:none;" name="rmm_description" id="hidden-description"><?php echo esc_textarea(get_post_meta($post->ID, 'rmm_description', true)); ?></textarea>
		<script>
		jQuery('select[name="mision_id"]').on('change', function() {
			const missionId = jQuery(this).val();
			if(!missionId) return;
			
			if(!confirm('¿Quieres importar todos los datos (Título, ORBAT, Imagen, Briefing) de la misión seleccionada? Esto sobrescribirá lo actual.')) return;
			
			jQuery.post(rmmAdminData.ajax_url, {
				action: 'sync_mission_to_event',
				mission_id: missionId,
				nonce: rmmAdminData.nonce
			}, function(res) {
				if(res.success) {
					// 1. Título
					if(jQuery('#title').length) jQuery('#title').val(res.data.title);
					if(typeof wp !== 'undefined' && wp.data && wp.data.dispatch('core/editor')) {
						wp.data.dispatch('core/editor').editPost({ title: res.data.title });
					}
					
					// 2. ORBAT
					if(jQuery('#rmm-orbat-data-input').length) {
						jQuery('#rmm-orbat-data-input').val(JSON.stringify(res.data.orbat)).trigger('change');
						if(typeof window.renderORBAT === 'function') window.renderORBAT();
					}
					
					// 3. Workshop & Addons
					jQuery('#workshop_id').val(res.data.workshop_id);
					jQuery('#addons_requeridos').val(res.data.addons.join("\n"));
					if(res.data.summary) jQuery('#hidden-summary').val(res.data.summary);
					if(res.data.description) jQuery('#hidden-description').val(res.data.description);
					
					// 4. Imagen Destacada
					if(res.data.thumbnail_id) {
						if (typeof wp !== 'undefined' && wp.media && wp.media.featuredImage) {
							// Funciona en editor clasico y Gutenberg
							wp.media.featuredImage.set( parseInt(res.data.thumbnail_id) );
						} else if (typeof wp !== 'undefined' && wp.data && wp.data.dispatch('core/editor')) {
							wp.data.dispatch('core/editor').editPost({ featured_media: parseInt(res.data.thumbnail_id) });
						}
					}
					
					// 5. Contenido / Briefing
					if (typeof wp !== 'undefined' && wp.data && wp.data.dispatch('core/editor')) {
						wp.data.dispatch('core/editor').editPost({ content: res.data.content });
					} else if (typeof tinymce !== 'undefined' && tinymce.activeEditor) {
						tinymce.activeEditor.setContent(res.data.content);
					} else {
						// Fallback para editor clasico sin TinyMCE activo
						var ta = document.getElementById('content');
						if (ta && ta.value !== undefined) ta.value = res.data.content;
					}
					
					alert('Datos de misión importados correctamente.');
				} else alert(res.data);
			});
		});
		</script>
		<p><label><strong>Inicio</strong></label><input type="datetime-local" name="fecha_inicio" value="<?php echo $fecha_inicio; ?>" style="width:100%; padding:5px; border:1px solid #ccc;"></p>
		<p><label><strong>Fin</strong></label><input type="datetime-local" name="fecha_fin" value="<?php echo $fecha_fin; ?>" style="width:100%; padding:5px; border:1px solid #ccc;"></p>
		<p><label><strong>Estado</strong></label><select name="estado" class="widefat">
			<?php foreach(['abierta','en_curso','debriefing','finalizada','cancelada'] as $s) echo '<option value="'.$s.'" '.selected($estado,$s,false).'>'.ucfirst($s).'</option>'; ?>
		</select></p>
		<p><label><strong>Motivo de cancelación</strong></label><input type="text" name="motivo_cancelacion" value="<?php echo esc_attr( get_post_meta($post->ID, 'motivo_cancelacion', true) ); ?>" style="width:100%; padding:5px; border:1px solid #ccc;" placeholder="Solo si el estado es Cancelada"></p>
		<p><label><strong>Medalla de Premio</strong></label><select name="condecoracion_premio" class="widefat">
			<option value="">-- Ninguna --</option>
			<?php foreach($medallas as $m) echo '<option value="'.$m->ID.'" '.selected($condecoracion_id,$m->ID,false).'>'.$m->post_title.'</option>'; ?>
		</select></p>
		<p>
			<button type="button" id="rmm-resend-telegram-btn" class="button" data-post-id="<?php echo $post->ID; ?>" style="margin-top:8px;">📢 Reenviar a Telegram</button>
			<span id="rmm-resend-feedback" style="margin-left:10px;font-size:12px;display:none;"></span>
		</p>
		<script>
		jQuery(document).ready(function($) {
			$('#rmm-resend-telegram-btn').on('click', function() {
				var btn = $(this);
				btn.prop('disabled', true).text('Enviando...');
				$.post(ajaxurl, {
					action: 'rmm_resend_telegram_event',
					post_id: btn.data('post-id')
				}, function(res) {
					var fb = $('#rmm-resend-feedback');
					if (res.success) {
						fb.css('color','#00a32a').text('Enviado correctamente').show();
					} else {
						fb.css('color','#d63638').text('Error: ' + (res.data || 'Desconocido')).show();
					}
					btn.prop('disabled', false).text('Reenviar a Telegram');
				});
			});
		});
		</script>
		<?php
	}

	/**
	 * RENDER: Gestor de ORBAT (Refactorizado con Select2 y Roles)
	 */
	public function render_orbat_metabox( $post ) {
		wp_nonce_field( 'rmm_save_metadata', 'rmm_metadata_nonce' );
		$meta_key = ( $post->post_type === 'misiones' ) ? 'orbat_maestro' : 'orbat_activo';
		$orbat_data = get_post_meta( $post->ID, $meta_key, true );
		$orbat_json = ! empty( $orbat_data ) ? wp_json_encode( $orbat_data ) : '[]';

		?>
		<div id="rmm-orbat-app" style="padding:10px;">
			<?php if ( get_post_type($post->ID) === 'eventos_partidas' ) : ?>
			<div class="rmm-import-box" style="margin-bottom:20px; padding:15px; background:#1e3a8a22; border:1px solid #1e3a8a; border-radius:4px;">
				<p style="margin:0 0 10px 0; font-size:12px; color:#93c5fd;"><strong>HERENCIA DE MISIÓN:</strong> Puedes importar la estructura de escuadras definida en la misión seleccionada.</p>
				<button type="button" class="button" id="rmm-pull-mission-orbat">📥 IMPORTAR ORBAT DE MISIÓN</button>
			</div>
			<?php endif; ?>

			<!-- Presets Section -->
			<div class="rmm-presets-box" style="margin-bottom:20px; padding:15px; background:#2a2a2a; border:1px solid #444; border-radius:4px;">
				<p style="margin:0 0 10px 0; font-size:11px; color:#aaa; text-transform:uppercase; letter-spacing:0.5px;"><strong>Plantillas de ORBAT</strong></p>
				<div style="display:flex; gap:10px; align-items:center;">
					<select id="rmm-preset-select" style="background:#111 !important; color:#fff !important; border:1px solid #444 !important; flex-grow:1;">
						<option value="">-- Seleccionar Plantilla --</option>
					</select>
					<button type="button" class="button" id="rmm-load-preset">Cargar</button>
					<button type="button" class="button" id="rmm-save-preset" style="background:#2271b1; color:#fff; border-color:#2271b1;">Guardar Actual</button>
					<button type="button" class="button" id="rmm-delete-preset" style="color:#ff4d4d; border-color:#d63638;">Borrar</button>
				</div>
			</div>

			<div id="rmm-squads-container"></div>
			<div style="margin-top:20px; padding-top:20px; border-top:1px solid #333;">
				<button type="button" class="button button-primary" id="rmm-add-squad" style="background:#2271b1; border-color:#2271b1;">+ AÑADIR ESCUADRA TÁCTICA</button>
			</div>
			<input type="hidden" name="rmm_orbat_data" id="rmm-orbat-data-input" value='<?php echo esc_attr($orbat_json); ?>'>
		</div>
		<style>
			.rmm-squad-card { background:#222 !important; border:1px solid #444 !important; border-radius:4px; margin-bottom:20px; overflow:hidden; }
			.rmm-squad-header { background:#2a2a2a; padding:10px 15px; display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #333; }
			.rmm-squad-title { border:none !important; background:none !important; color:#fff !important; font-size:14px !important; font-weight:bold !important; width:100%; box-shadow:none !important; }
			.rmm-slot-row { display:grid; grid-template-columns: 180px 1fr 120px 40px; gap:10px; padding:12px 15px; border-bottom:1px solid #333; align-items:center; }
			.rmm-slot-row:last-child { border-bottom:none; }
			.rmm-slot-row select, .rmm-slot-row input { background:#111 !important; border:1px solid #444 !important; color:#eee !important; font-size:12px !important; }
			.rmm-remove-btn { color:#ff4d4d; cursor:pointer; font-size:18px; opacity:0.6; transition:opacity 0.2s; }
			.rmm-remove-btn:hover { opacity:1; }
			.select2-container--default .select2-selection--multiple { background-color: #111 !important; border: 1px solid #444 !important; }
			.select2-container--default .select2-selection--multiple .select2-selection__choice { background-color: #2271b1 !important; border: none !important; color: #fff !important; }
			.rmm-add-slot-container { padding:10px 15px; background:#1a1a1a; }
		</style>
		<script>
		jQuery(document).ready(function($) {
			const container = $('#rmm-squads-container');
			const input = $('#rmm-orbat-data-input');
			let data = JSON.parse(input.val() || '[]');
			if(typeof data === 'string') data = JSON.parse(data);

			// --- PRESETS LOGIC ---
			function loadPresetsList() {
				$.post(rmmAdminData.ajax_url, {
					action: 'get_orbat_presets',
					nonce: rmmAdminData.nonce
				}, function(res) {
					if(res.success) {
						const select = $('#rmm-preset-select');
						select.find('option:not(:first)').remove();
						const presets = res.data;
						for (const name in presets) {
							select.append(new Option(name, name));
						}
					}
				});
			}
			loadPresetsList();

			$('#rmm-save-preset').on('click', function() {
				const name = prompt('Introduce el nombre de la plantilla:');
				if (!name) return;
				
				$.post(rmmAdminData.ajax_url, {
					action: 'save_orbat_preset',
					preset_name: name,
					orbat_data: JSON.stringify(data),
					nonce: rmmAdminData.nonce
				}, function(res) {
					alert(res.data);
					if(res.success) loadPresetsList();
				});
			});

			$('#rmm-load-preset').on('click', function() {
				const name = $('#rmm-preset-select').val();
				if (!name) return alert('Selecciona una plantilla primero.');
				if (data.length > 0 && !confirm('Esto borrará el ORBAT actual. ¿Continuar?')) return;
				
				$.post(rmmAdminData.ajax_url, {
					action: 'get_orbat_presets',
					nonce: rmmAdminData.nonce
				}, function(res) {
					if(res.success) {
						const presets = res.data;
						if (presets[name]) {
							data = presets[name];
							render();
							updateInput();
						}
					}
				});
			});

			$('#rmm-delete-preset').on('click', function() {
				const name = $('#rmm-preset-select').val();
				if (!name) return alert('Selecciona una plantilla primero.');
				if (!confirm(`¿Seguro que quieres borrar la plantilla "${name}"?`)) return;
				
				$.post(rmmAdminData.ajax_url, {
					action: 'delete_orbat_preset',
					preset_name: name,
					nonce: rmmAdminData.nonce
				}, function(res) {
					alert(res.data);
					if(res.success) loadPresetsList();
				});
			});
			// --- END PRESETS LOGIC ---

			// Logic to pull ORBAT from mission if event is new and mission is selected
			const postType = '<?php echo get_post_type($post->ID); ?>';
			if(postType === 'eventos_partidas' && data.length === 0) {
				const missionId = $('select[name="mision_id"]').val();
				if(missionId) {
					// This would ideally be an AJAX call, but for now we suggest saving and pulling
					// We'll implement a "Pull from Mission" button to make it explicit
				}
			}

			function render() {
				container.empty();
				data.forEach((squad, sIdx) => {
					const card = $(`<div class="rmm-squad-card">
						<div class="rmm-squad-header">
							<input type="text" class="rmm-squad-title" value="${squad.escuadra || ''}" placeholder="NOMBRE DE ESCUADRA (Ej: ALPHA 1-1)">
							<span class="rmm-remove-btn dashicons dashicons-trash" title="Borrar Escuadra"></span>
						</div>
						<div class="rmm-frequencies-box" style="padding:10px; background:rgba(0,0,0,0.1); border-bottom:1px solid #333;">
							<label style="font-size:10px; color:#aaa; display:block; margin-bottom:5px;">FRECUENCIAS DE RADIO</label>
							<div class="rmm-freq-list"></div>
							<button type="button" class="button button-small rmm-add-freq">+ Añadir Radio</button>
						</div>
						<div class="rmm-slots-list"></div>
						<div class="rmm-add-slot-container">
							<button type="button" class="button rmm-add-slot">+ AÑADIR ROL</button>
						</div>
					</div>`);

					// Render Frequencies
					squad.frequencies = squad.frequencies || [];
					squad.frequencies.forEach((freq, fIdx) => {
						const freqRow = $(`<div class="rmm-freq-row" style="display:flex; gap:5px; margin-bottom:5px; align-items:center;">
							<input type="text" class="freq-purpose" value="${freq.purpose || ''}" placeholder="Propósito (ej: GLOBAL)" style="width:30%;">
							<select class="freq-type" style="width:20%;">
								<option value="SR" ${freq.type==='SR'?'selected':''}>Corta (SR)</option>
								<option value="LR" ${freq.type==='LR'?'selected':''}>Larga (LR)</option>
							</select>
							<input type="text" class="freq-channel" value="${freq.channel || ''}" placeholder="Ch" style="width:15%;">
							<input type="text" class="freq-value" value="${freq.frequency || ''}" placeholder="MHz" style="width:25%;">
							<span class="rmm-remove-freq dashicons dashicons-no-alt" title="Quitar" style="cursor:pointer; color:#a00;"></span>
						</div>`);

						const updateFreqData = () => {
							const type = freqRow.find('.freq-type').val();
							const ch = freqRow.find('.freq-channel').val();
							
							// Presets
							const srPresets = { '1': '310', '2': '311', '3': '312', '4': '313', '5': '314', '6': '315' };
							const lrPresets = { '1': '42', '2': '43', '3': '44', '4': '45', '5': '46', '6': '47' };
							
							if (type === 'SR' && srPresets[ch]) {
								freqRow.find('.freq-value').val(srPresets[ch]);
								squad.frequencies[fIdx].frequency = srPresets[ch];
							} else if (type === 'LR' && lrPresets[ch]) {
								freqRow.find('.freq-value').val(lrPresets[ch]);
								squad.frequencies[fIdx].frequency = lrPresets[ch];
							}
						};

						freqRow.find('.freq-purpose').on('change', function() { squad.frequencies[fIdx].purpose = $(this).val(); updateInput(); });
						freqRow.find('.freq-type').on('change', function() { 
							squad.frequencies[fIdx].type = $(this).val(); 
							updateFreqData();
							updateInput(); 
						});
						freqRow.find('.freq-channel').on('change', function() { 
							squad.frequencies[fIdx].channel = $(this).val(); 
							updateFreqData();
							updateInput(); 
						});
						freqRow.find('.freq-value').on('change', function() { squad.frequencies[fIdx].frequency = $(this).val(); updateInput(); });
						
						freqRow.find('.rmm-remove-freq').on('click', () => { squad.frequencies.splice(fIdx, 1); render(); updateInput(); });
						
						card.find('.rmm-freq-list').append(freqRow);
					});

					card.find('.rmm-add-freq').on('click', () => {
						squad.frequencies.push({ purpose: '', type: 'SR', channel: '', frequency: '' });
						render(); updateInput();
					});

					squad.slots.forEach((slot, rIdx) => {
						const row = $(`<div class="rmm-slot-row" style="display:flex; align-items:center; gap:10px;">
							<div class="rmm-slot-icon-preview" style="width:24px; height:24px; display:flex; align-items:center; justify-content:center; background:#444; border-radius:4px; overflow:hidden;"></div>
							<select class="slot-role-sel" style="flex:1;"></select>
							<select class="orbat-medals-select" multiple style="width:100%"></select>
							<div class="rmm-slot-status">
								${rmmAdminData.is_event ? `<select class="slot-user-sel" style="width:100%;"><option value="">VACANTE</option></select>` : `<span style="color:#aaa; font-size:10px;">VACANTE</span>`}
							</div>
							<span class="rmm-remove-btn dashicons dashicons-no-alt" title="Quitar Rol"></span>
						</div>`);

						const iconPreview = row.find('.rmm-slot-icon-preview');
						function updateIconPreview(roleName) {
							const roleInfo = rmmAdminData.roles_imgs[roleName];
							if (roleInfo && roleInfo.image_url) {
								iconPreview.html('<img src="' + roleInfo.image_url + '" style="width:100%; height:100%; object-fit:contain;" />');
							} else {
								iconPreview.html('<span style="font-size:14px; color:#fff;">👤</span>');
							}
						}

						rmmAdminData.roles.forEach(r => row.find('.slot-role-sel').append(new Option(r, r, r===slot.rol, r===slot.rol)));
						
						updateIconPreview(slot.rol || rmmAdminData.roles[0]);

						rmmAdminData.medals.forEach(m => {
							const selected = (slot.condecoraciones_requeridas || []).includes(m.id);
							row.find('.orbat-medals-select').append(new Option(m.text, m.id, selected, selected));
						});

						if (rmmAdminData.is_event) {
							const userSel = row.find('.slot-user-sel');
							rmmAdminData.users.forEach(u => {
								userSel.append(new Option(u.text, u.id, u.id == slot.usuario_id, u.id == slot.usuario_id));
							});
							userSel.on('change', function() {
								data[sIdx].slots[rIdx].usuario_id = $(this).val() ? parseInt($(this).val()) : null;
								updateInput();
							});
						}

						row.find('.slot-role-sel').on('change', function() {
							const val = $(this).val();
							data[sIdx].slots[rIdx].rol = val;
							updateIconPreview(val);
							updateInput();
						});

						row.find('.orbat-medals-select').select2({ placeholder: "Requisitos" }).on('change', function() {
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
					card.find('.rmm-remove-btn').first().on('click', () => { if(confirm('¿Borrar escuadra completa?')) { data.splice(sIdx, 1); render(); updateInput(); } });
					card.find('.rmm-squad-title').on('change', function() { data[sIdx].escuadra = $(this).val(); updateInput(); });
					container.append(card);
				});
			}

			function updateInput() { input.val(JSON.stringify(data)); }
			$('#rmm-add-squad').on('click', () => { data.push({ escuadra: '', slots: [] }); render(); updateInput(); });
			
			$('#rmm-pull-mission-orbat').on('click', function() {
				const missionId = $('select[name="mision_id"]').val();
				if(!missionId) return alert('Primero selecciona una misión en el panel lateral.');
				if(data.length > 0 && !confirm('Esto borrará el ORBAT actual. ¿Continuar?')) return;
				
				$.post(rmmAdminData.ajax_url, {
					action: 'get_mission_orbat',
					mission_id: missionId,
					nonce: rmmAdminData.nonce
				}, function(res) {
					if(res.success) {
						data = typeof res.data.orbat === 'string' ? JSON.parse(res.data.orbat) : res.data.orbat;
						render();
						updateInput();
						
						// Inject Addons
						if (res.data.addons && $('#addons_requeridos').length) {
							$('#addons_requeridos').val(res.data.addons);
						}
						
						// Inject Content if empty
						if (res.data.content) {
							if (typeof wp !== 'undefined' && wp.data && wp.data.select('core/editor')) {
								let currentContent = wp.data.select('core/editor').getEditedPostAttribute('content');
								if (!currentContent || currentContent.trim() === '') {
									wp.data.dispatch('core/editor').editPost({ content: res.data.content });
								}
							} else if (typeof tinymce !== 'undefined' && tinymce.activeEditor && !tinymce.activeEditor.isHidden()) {
								let currentContent = tinymce.activeEditor.getContent();
								if (!currentContent || currentContent.trim() === '') {
									tinymce.activeEditor.setContent(res.data.content);
								}
							} else if ($('#content').length) {
								let currentContent = $('#content').val();
								if (!currentContent || currentContent.trim() === '') {
									$('#content').val(res.data.content);
								}
							}
						}
						alert('Datos de la misión importados correctamente.');
					} else alert(res.data);
				});
			});

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
		$fields = array( 'workshop_id', 'mission_api_name', 'workshop_url', 'mision_id', 'fecha_inicio', 'fecha_fin', 'estado', 'motivo_cancelacion', 'condecoracion_premio', 'rmm_author' );
		foreach ( $fields as $f ) {
			if ( isset( $_POST[$f] ) ) update_post_meta( $post_id, $f, sanitize_text_field( $_POST[$f] ) );
		}
		
		$textarea_fields = array( 'rmm_summary', 'rmm_description' );
		foreach ( $textarea_fields as $f ) {
			if ( isset( $_POST[$f] ) ) update_post_meta( $post_id, $f, sanitize_textarea_field( $_POST[$f] ) );
		}
		
		// Save Addons (processed as array from text)
		if ( isset( $_POST['addons_requeridos_text'] ) ) {
			$addons = array_filter( array_map( 'trim', explode( "\n", $_POST['addons_requeridos_text'] ) ) );
			update_post_meta( $post_id, 'addons_requeridos', $addons );
		}
	}
}
