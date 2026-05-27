<?php
/**
 * Medal Rules Handler — Sistema automático de condecoraciones
 * 
 * Permite crear reglas que otorgan condecoraciones automáticamente
 * cuando un jugador cumple ciertos requisitos de estadísticas.
 * 
 * @package ReforgerMilsimManagement
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class RMM_Medal_Rules_Handler {

	public function __construct() {
		// Página de administración de reglas
		add_action( 'admin_menu', array( $this, 'register_admin_page' ) );
		
		// AJAX handlers
		add_action( 'wp_ajax_rmm_save_medal_rule', array( $this, 'ajax_save_rule' ) );
		add_action( 'wp_ajax_rmm_delete_medal_rule', array( $this, 'ajax_delete_rule' ) );
		add_action( 'wp_ajax_rmm_toggle_medal_rule', array( $this, 'ajax_toggle_rule' ) );
		add_action( 'wp_ajax_rmm_remove_user_medal', array( $this, 'ajax_remove_user_medal' ) );
		
		// Evaluar reglas tras telemetría y tras confirmar asistencia
		add_action( 'rmm_after_telemetry_update', array( $this, 'evaluate_rules_for_user' ), 10, 3 );
		add_action( 'rmm_after_attendance_update', array( $this, 'evaluate_rules_for_user' ), 10, 2 );
		
		// Mostrar medallas en perfil de usuario admin + botón quitar
		add_action( 'show_user_profile', array( $this, 'render_user_medals_admin' ) );
		add_action( 'edit_user_profile', array( $this, 'render_user_medals_admin' ) );
		add_action( 'init', array( $this, 'ensure_table' ) );
	}

	public function ensure_table() {
		global $wpdb;
		$table = $wpdb->prefix . 'rmm_medal_rules';
		$exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) );
		if ( $exists !== $table ) {
			$charset = $wpdb->get_charset_collate();
			$sql = "CREATE TABLE $table (
				id bigint(20) NOT NULL AUTO_INCREMENT,
				medal_id bigint(20) NOT NULL,
				rule_name varchar(200) DEFAULT '' NOT NULL,
				conditions longtext DEFAULT '' NOT NULL,
				logic varchar(3) DEFAULT 'AND' NOT NULL,
				enabled tinyint(1) DEFAULT 1 NOT NULL,
				created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
				PRIMARY KEY (id)
			) $charset";
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			dbDelta( $sql );
		}
	}

	public function register_admin_page() {
		add_submenu_page(
			'rmm-dashboard',
			__( 'Reglas de Condecoraciones', 'reforger-milsim' ),
			__( '🎖️ Reglas Auto', 'reforger-milsim' ),
			'manage_options',
			'rmm-medal-rules',
			array( $this, 'render_admin_page' )
		);
	}

	public function render_admin_page() {
		global $wpdb;
		$rules_table = $wpdb->prefix . 'rmm_medal_rules';
		$rules = $wpdb->get_results( "SELECT * FROM $rules_table ORDER BY created_at DESC" );
		$medals = get_posts( array( 'post_type' => 'condecoraciones', 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC' ) );
		$labels = $this->get_field_labels();
		?>
		<div class="wrap rmm-dark-theme" style="font-family: 'Inter', sans-serif; background: #0d1117; color: #c9d1d9; padding: 24px; border-radius: 8px; margin-top: 16px;">
			<h1 style="color: #849b4c; text-transform: uppercase; letter-spacing: 0.05em; font-size: 1.4rem;">🎖️ Reglas Automáticas de Condecoraciones</h1>
			<p style="color: #8b949e;">Define condiciones para otorgar condecoraciones automáticamente. Se evalúan al recibir telemetría del addon y al confirmar asistencia a eventos.</p>
			
			<table class="widefat" style="background: #161b22; border: 1px solid #21262d; margin-bottom: 24px;">
				<thead>
					<tr style="background: #1c2128;">
						<th style="color: #c9d1d9; padding: 10px;">Condecoración</th>
						<th style="color: #c9d1d9; padding: 10px;">Nombre</th>
						<th style="color: #c9d1d9; padding: 10px;">Condiciones</th>
						<th style="color: #c9d1d9; padding: 10px;">Estado</th>
						<th style="color: #c9d1d9; padding: 10px;">Acciones</th>
					</tr>
				</thead>
				<tbody id="rmm-rules-list">
					<?php if ( empty( $rules ) ) : ?>
						<tr><td colspan="5" style="padding: 20px; text-align: center; color: #8b949e;">No hay reglas definidas todavía.</td></tr>
					<?php else : ?>
						<?php foreach ( $rules as $rule ) : 
							$medal = get_post( $rule->medal_id );
							$thumb = get_the_post_thumbnail_url( $rule->medal_id, 'metopa-militar' );
							$conds = json_decode( $rule->conditions, true ) ?: array();
							$cond_text = array();
							foreach ( $conds as $c ) {
								$field_label = $labels[ $c['field'] ] ?? $c['field'];
								$cond_text[] = "{$field_label} {$c['op']} {$c['value']}";
							}
							?>
							<tr data-rule-id="<?php echo $rule->id; ?>" style="border-bottom: 1px solid #21262d;">
								<td style="padding: 10px;">
									<?php if ( $thumb ) : ?>
										<img src="<?php echo esc_url( $thumb ); ?>" style="width: 80px; height: 23px; object-fit: cover; display: block;">
									<?php endif; ?>
									<strong style="color: #d2a850; font-size: 0.75rem;"><?php echo $medal ? esc_html( $medal->post_title ) : 'ID: ' . $rule->medal_id; ?></strong>
								</td>
								<td style="padding: 10px; color: #c9d1d9;"><?php echo esc_html( $rule->rule_name ); ?></td>
								<td style="padding: 10px; color: #8b949e; font-size: 0.75rem;">
									<?php echo esc_html( implode( " {$rule->logic} ", $cond_text ) ); ?>
								</td>
								<td style="padding: 10px;">
									<span class="rmm-rule-status" style="display: inline-block; padding: 3px 10px; border-radius: 3px; font-size: 0.65rem; font-weight: 700; text-transform: uppercase; <?php echo $rule->enabled ? 'background: rgba(34,197,94,0.15); color: #22c55e; border: 1px solid rgba(34,197,94,0.3);' : 'background: rgba(139,148,158,0.1); color: #8b949e; border: 1px solid rgba(139,148,158,0.2);'; ?>">
										<?php echo $rule->enabled ? 'Activa' : 'Inactiva'; ?>
									</span>
								</td>
								<td style="padding: 10px;">
									<button class="rmm-toggle-rule-btn button button-small" style="margin-right: 4px;"><?php echo $rule->enabled ? 'Desactivar' : 'Activar'; ?></button>
									<button class="rmm-delete-rule-btn button button-small button-link-delete">Eliminar</button>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<div style="background: #161b22; border: 1px solid #21262d; border-radius: 6px; padding: 20px;">
				<h2 style="color: #849b4c; font-size: 1rem; text-transform: uppercase; margin: 0 0 16px;">➕ Nueva Regla</h2>
				
				<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 16px;">
					<div>
						<label style="color: #8b949e; font-size: 0.7rem; text-transform: uppercase; display: block; margin-bottom: 4px;">Condecoración</label>
						<select id="rmm-new-rule-medal" style="width: 100%; background: #0d1117; color: #c9d1d9; border: 1px solid #21262d; padding: 8px; border-radius: 4px;">
							<?php foreach ( $medals as $m ) : ?>
								<option value="<?php echo $m->ID; ?>"><?php echo esc_html( $m->post_title ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div>
						<label style="color: #8b949e; font-size: 0.7rem; text-transform: uppercase; display: block; margin-bottom: 4px;">Nombre de la regla</label>
						<input type="text" id="rmm-new-rule-name" placeholder="Ej: Médico de Combate Experto" style="width: 100%; background: #0d1117; color: #c9d1d9; border: 1px solid #21262d; padding: 8px; border-radius: 4px;">
					</div>
				</div>

				<label style="color: #8b949e; font-size: 0.7rem; text-transform: uppercase; display: block; margin-bottom: 8px;">Condiciones</label>
				<div id="rmm-conditions-container" style="margin-bottom: 12px;">
					<div class="rmm-condition-row" style="display: flex; gap: 8px; align-items: center; margin-bottom: 8px;">
						<select class="rmm-cond-field" style="background: #0d1117; color: #c9d1d9; border: 1px solid #21262d; padding: 6px; border-radius: 4px;">
							<option value="kills">Bajas (Kills)</option>
							<option value="deaths">Muertes</option>
							<option value="kd_ratio">Ratio K/D</option>
							<option value="shots_fired">Disparos</option>
							<option value="shots_hit">Impactos</option>
							<option value="accuracy">Precisión %</option>
							<option value="hours">Horas jugadas</option>
							<option value="bandages">Vendajes</option>
							<option value="tourniquets">Torniquetes</option>
							<option value="saline">Salino</option>
							<option value="morphine">Morfina</option>
							<option value="epinephrine">Epinefrina</option>
							<option value="attendance">Partidas asistidas</option>
							<option value="days_active">Días en activo</option>
						</select>
						<select class="rmm-cond-op" style="background: #0d1117; color: #c9d1d9; border: 1px solid #21262d; padding: 6px; border-radius: 4px;">
							<option value=">=">>=</option>
							<option value=">">></option>
							<option value="<="><=</option>
							<option value="<"><</option>
							<option value="==">==</option>
						</select>
						<input type="number" class="rmm-cond-value" placeholder="Valor" step="any" style="background: #0d1117; color: #c9d1d9; border: 1px solid #21262d; padding: 6px; border-radius: 4px; width: 100px;">
						<button type="button" class="rmm-remove-condition-btn button button-small button-link-delete">✕</button>
					</div>
				</div>
				
				<div style="display: flex; gap: 8px; align-items: center; margin-bottom: 16px;">
					<button type="button" id="rmm-add-condition-btn" class="button button-small">+ Añadir condición</button>
					<label style="color: #8b949e; font-size: 0.7rem; margin-left: 16px;">Lógica:</label>
					<select id="rmm-new-rule-logic" style="background: #0d1117; color: #c9d1d9; border: 1px solid #21262d; padding: 6px; border-radius: 4px;">
						<option value="AND">Y (todas)</option>
						<option value="OR">O (cualquiera)</option>
					</select>
				</div>

				<button type="button" id="rmm-save-rule-btn" class="button button-primary">💾 Guardar Regla</button>
				<span id="rmm-rule-feedback" style="margin-left: 12px; font-size: 0.75rem; display: none;"></span>
			</div>
		</div>

		<script>
		jQuery(document).ready(function($) {
			function feedback(msg, isError) {
				$('#rmm-rule-feedback').text(msg).css('color', isError ? '#f85149' : '#3fb950').show().delay(3000).fadeOut();
			}

			$('#rmm-add-condition-btn').on('click', function() {
				var row = $('.rmm-condition-row').first().clone();
				row.find('input').val('');
				row.find('select').prop('selectedIndex', 0);
				$('#rmm-conditions-container').append(row);
			});

			$('#rmm-conditions-container').on('click', '.rmm-remove-condition-btn', function() {
				if ( $('.rmm-condition-row').length > 1 ) {
					$(this).closest('.rmm-condition-row').remove();
				}
			});

			$('#rmm-save-rule-btn').on('click', function() {
				var btn = $(this);
				var medalId = $('#rmm-new-rule-medal').val();
				var ruleName = $('#rmm-new-rule-name').val().trim();
				var logic = $('#rmm-new-rule-logic').val();
				var conditions = [];

				if ( ! ruleName ) { feedback('Pon un nombre a la regla', true); return; }

				$('.rmm-condition-row').each(function() {
					var field = $(this).find('.rmm-cond-field').val();
					var op = $(this).find('.rmm-cond-op').val();
					var value = $(this).find('.rmm-cond-value').val();
					if ( value !== '' ) {
						conditions.push({ field: field, op: op, value: parseFloat(value) });
					}
				});

				if ( ! conditions.length ) { feedback('Añade al menos una condición', true); return; }

				btn.prop('disabled', true).text('Guardando...');

				$.post(ajaxurl, {
					action: 'rmm_save_medal_rule',
					medal_id: medalId,
					rule_name: ruleName,
					conditions: JSON.stringify(conditions),
					logic: logic
				}, function(res) {
					if (res.success) location.reload();
					else { feedback('Error: ' + (res.data || 'Desconocido'), true); btn.prop('disabled', false).text('Guardar Regla'); }
				});
			});

			$('#rmm-rules-list').on('click', '.rmm-toggle-rule-btn', function() {
				var btn = $(this), ruleId = btn.closest('tr').data('rule-id');
				btn.prop('disabled', true);
				$.post(ajaxurl, { action: 'rmm_toggle_medal_rule', rule_id: ruleId }, function(res) {
					if (res.success) location.reload();
				});
			});

			$('#rmm-rules-list').on('click', '.rmm-delete-rule-btn', function() {
				if ( ! confirm('Eliminar esta regla permanentemente?') ) return;
				var btn = $(this), row = btn.closest('tr'), ruleId = row.data('rule-id');
				btn.prop('disabled', true);
				$.post(ajaxurl, { action: 'rmm_delete_medal_rule', rule_id: ruleId }, function(res) {
					if (res.success) row.slideUp(200, function() { row.remove(); });
					else { alert('Error al eliminar'); btn.prop('disabled', false); }
				});
			});
		});
		</script>
		<?php
	}

	public function render_user_medals_admin( $user ) {
		global $wpdb;
		
		$current = wp_get_current_user();
		$can_remove = current_user_can( 'manage_options' ) || in_array( 'fundador', (array) $current->roles );
		
		$awards = $wpdb->get_results( $wpdb->prepare(
			"SELECT oc.id as award_id, oc.fecha_obtenida, oc.motivo, p.post_title, p.ID as medal_id
			 FROM {$wpdb->prefix}operador_condecoraciones oc
			 JOIN {$wpdb->posts} p ON oc.condecoracion_id = p.ID
			 WHERE oc.usuario_id = %d
			 ORDER BY oc.fecha_obtenida DESC",
			$user->ID
		) );
		
		if ( empty( $awards ) ) return;
		?>
		<h3>🎖️ Condecoraciones Otorgadas</h3>
		<div id="rmm-user-medals-admin" data-user-id="<?php echo $user->ID; ?>" style="max-width: 800px;">
			<table class="widefat" style="background: #f6f7f7; border-left: 4px solid #d2a850; margin-bottom: 16px;">
				<thead>
					<tr><th style="padding:8px;">Metopa</th><th style="padding:8px;">Nombre</th><th style="padding:8px;">Fecha</th><th style="padding:8px;">Motivo</th><th style="padding:8px; width:60px;"></th></tr>
				</thead>
				<tbody>
					<?php foreach ( $awards as $a ) : 
						$thumb = get_the_post_thumbnail_url( $a->medal_id, 'metopa-militar' );
						?>
						<tr data-award-id="<?php echo $a->award_id; ?>" style="border-bottom:1px solid #e5e5e5;">
							<td style="padding:6px;">
								<?php if ( $thumb ) : ?><img src="<?php echo esc_url( $thumb ); ?>" style="width:80px; height:23px; object-fit:cover;"><?php endif; ?>
							</td>
							<td style="padding:6px; font-weight:600;"><?php echo esc_html( $a->post_title ); ?></td>
							<td style="padding:6px; font-size:0.8rem; color:#555;"><?php echo date( 'd/m/Y', strtotime( $a->fecha_obtenida ) ); ?></td>
							<td style="padding:6px; font-size:0.8rem; color:#666; font-style:italic;"><?php echo esc_html( $a->motivo ); ?></td>
							<?php if ( $can_remove ) : ?>
							<td style="padding:6px; text-align:center;">
								<button class="rmm-remove-medal-btn button button-small button-link-delete" title="Quitar sin dejar rastro">✕</button>
							</td>
							<?php endif; ?>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<script>
		jQuery(document).ready(function($) {
			$('#rmm-user-medals-admin').on('click', '.rmm-remove-medal-btn', function() {
				if ( ! confirm('Quitar esta condecoracion sin dejar rastro en el timeline?') ) return;
				var btn = $(this), row = btn.closest('tr');
				btn.prop('disabled', true);
				$.post(ajaxurl, {
					action: 'rmm_remove_user_medal',
					user_id: <?php echo $user->ID; ?>,
					award_id: row.data('award-id')
				}, function(res) {
					if (res.success) row.slideUp(200, function() { row.remove(); });
					else { alert('Error al quitar'); btn.prop('disabled', false); }
				});
			});
		});
		</script>
		<?php
	}

	/* ── AJAX ── */
	public function ajax_save_rule() {
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Permiso denegado' );
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'rmm_medal_rules', array(
			'medal_id'   => intval( $_POST['medal_id'] ),
			'rule_name'  => sanitize_text_field( $_POST['rule_name'] ),
			'conditions' => wp_unslash( $_POST['conditions'] ),
			'logic'      => sanitize_text_field( $_POST['logic'] ),
			'enabled'    => 1,
		) );
		wp_send_json_success();
	}

	public function ajax_delete_rule() {
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Permiso denegado' );
		global $wpdb;
		$wpdb->delete( $wpdb->prefix . 'rmm_medal_rules', array( 'id' => intval( $_POST['rule_id'] ) ) );
		wp_send_json_success();
	}

	public function ajax_toggle_rule() {
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Permiso denegado' );
		global $wpdb;
		$table = $wpdb->prefix . 'rmm_medal_rules';
		$rule = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", intval( $_POST['rule_id'] ) ) );
		if ( $rule ) {
			$wpdb->update( $table, array( 'enabled' => $rule->enabled ? 0 : 1 ), array( 'id' => $rule->id ) );
		}
		wp_send_json_success();
	}

	public function ajax_remove_user_medal() {
		if ( ! current_user_can( 'edit_user', intval( $_POST['user_id'] ) ) ) wp_send_json_error( 'Permiso denegado' );
		
		$current = wp_get_current_user();
		if ( ! current_user_can( 'manage_options' ) && ! in_array( 'fundador', (array) $current->roles ) ) {
			wp_send_json_error( 'Solo admin o fundador' );
		}
		
		global $wpdb;
		$wpdb->delete( $wpdb->prefix . 'operador_condecoraciones', array( 'id' => intval( $_POST['award_id'] ) ) );
		wp_send_json_success();
	}

	/* ── MOTOR DE EVALUACIÓN ── */

	public function evaluate_rules_for_user( $user_id, $context = 'telemetry' ) {
		global $wpdb;
		$rules = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}rmm_medal_rules WHERE enabled = 1" );
		if ( empty( $rules ) ) return;
		
		$stats = $this->get_user_stats( $user_id );
		
		foreach ( $rules as $rule ) {
			if ( $this->user_has_medal( $user_id, $rule->medal_id ) ) continue;
			
			$conditions = json_decode( $rule->conditions, true );
			if ( empty( $conditions ) ) continue;
			
			if ( $this->evaluate_conditions( $conditions, $rule->logic, $stats ) ) {
				$this->award_medal( $user_id, $rule->medal_id, $rule->rule_name );
			}
		}
	}

	private function evaluate_conditions( $conditions, $logic, $stats ) {
		$results = array();
		foreach ( $conditions as $cond ) {
			$field  = $cond['field'] ?? '';
			$op     = $cond['op'] ?? '>=';
			$value  = floatval( $cond['value'] ?? 0 );
			$actual = floatval( $stats[ $field ] ?? 0 );
			
			switch ( $op ) {
				case '>=': $results[] = $actual >= $value; break;
				case '>':  $results[] = $actual > $value;  break;
				case '<=': $results[] = $actual <= $value; break;
				case '<':  $results[] = $actual < $value;  break;
				case '==': $results[] = $actual == $value; break;
				default:   $results[] = false;
			}
		}
		
		return $logic === 'OR' ? in_array( true, $results, true ) : ! in_array( false, $results, true );
	}

	public function get_user_stats( $user_id ) {
		global $wpdb;
		
		$kills       = intval( get_user_meta( $user_id, 'rmm_kills', true ) ?: 0 );
		$deaths      = intval( get_user_meta( $user_id, 'rmm_deaths', true ) ?: 0 );
		$hours       = floatval( get_user_meta( $user_id, 'rmm_hours', true ) ?: 0 );
		$shots_fired = intval( get_user_meta( $user_id, 'rmm_shots_fired', true ) ?: 0 );
		$shots_hit   = intval( get_user_meta( $user_id, 'rmm_shots_hit', true ) ?: 0 );
		$bandages    = intval( get_user_meta( $user_id, 'rmm_bandages', true ) ?: 0 );
		$tourniquets = intval( get_user_meta( $user_id, 'rmm_tourniquets', true ) ?: 0 );
		$saline      = intval( get_user_meta( $user_id, 'rmm_saline', true ) ?: 0 );
		$morphine    = intval( get_user_meta( $user_id, 'rmm_morphine', true ) ?: 0 );
		$epinephrine = intval( get_user_meta( $user_id, 'rmm_epinephrine', true ) ?: 0 );
		
		$kd_ratio = $deaths > 0 ? round( $kills / $deaths, 2 ) : $kills;
		$accuracy = $shots_fired > 0 ? round( ( $shots_hit / $shots_fired ) * 100, 1 ) : 0;
		
		$attendance = intval( $wpdb->get_var( $wpdb->prepare(
			"SELECT count(*) FROM {$wpdb->prefix}registro_operadores WHERE usuario_id = %d AND estado_asistencia = 'presente'",
			$user_id
		) ) );
		
		$enrol_date  = get_user_meta( $user_id, 'rmm_enrolment_date', true );
		$days_active = ! empty( $enrol_date ) ? max( 0, floor( ( time() - strtotime( $enrol_date ) ) / 86400 ) ) : 0;
		
		return compact(
			'kills', 'deaths', 'kd_ratio', 'hours', 'shots_fired', 'shots_hit',
			'accuracy', 'bandages', 'tourniquets', 'saline', 'morphine', 'epinephrine',
			'attendance', 'days_active'
		);
	}

	private function user_has_medal( $user_id, $medal_id ) {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare(
			"SELECT count(*) FROM {$wpdb->prefix}operador_condecoraciones WHERE usuario_id = %d AND condecoracion_id = %d",
			$user_id, $medal_id
		) ) > 0;
	}

	private function award_medal( $user_id, $medal_id, $reason = '' ) {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'operador_condecoraciones', array(
			'usuario_id'            => $user_id,
			'condecoracion_id'      => $medal_id,
			'motivo'                => $reason ?: 'Otorgada automáticamente por el sistema.',
			'otorgada_por_admin_id' => 0,
			'fecha_obtenida'        => current_time( 'mysql' ),
		) );
	}

	public function get_field_labels() {
		return array(
			'kills'       => 'Bajas', 'deaths' => 'Muertes', 'kd_ratio' => 'Ratio K/D',
			'shots_fired' => 'Disparos', 'shots_hit' => 'Impactos', 'accuracy' => 'Precisión %',
			'hours'       => 'Horas jugadas',
			'bandages'    => 'Vendajes', 'tourniquets' => 'Torniquetes', 'saline' => 'Salino',
			'morphine'    => 'Morfina', 'epinephrine' => 'Epinefrina',
			'attendance'  => 'Partidas asistidas', 'days_active' => 'Días en activo',
		);
	}
}
