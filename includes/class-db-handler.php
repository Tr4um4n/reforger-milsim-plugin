<?php
/**
 * DB Handler Class
 *
 * Handles custom table creation and database updates.
 *
 * @package ReforgerMilsimManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RMM_DB_Handler {

	/**
	 * Create or update custom tables.
	 */
	public static function create_tables() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		// Table 1: wp_registro_operadores
		$table_operators = $wpdb->prefix . 'registro_operadores';
		$sql1 = "CREATE TABLE $table_operators (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			evento_id bigint(20) NOT NULL,
			mision_id bigint(20) NOT NULL,
			usuario_id bigint(20) NOT NULL,
			rol_apuntado varchar(100) DEFAULT '' NOT NULL,
			rol_jugado varchar(100) DEFAULT '' NOT NULL,
			estado_asistencia varchar(50) DEFAULT 'ausente' NOT NULL,
			fecha_registro datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY  (id),
			KEY evento_id (evento_id),
			KEY usuario_id (usuario_id)
		) $charset_collate;";

		// Table 2: wp_operador_condecoraciones
		$table_medals = $wpdb->prefix . 'operador_condecoraciones';
		$sql2 = "CREATE TABLE $table_medals (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			usuario_id bigint(20) NOT NULL,
			condecoracion_id bigint(20) NOT NULL,
			fecha_obtenida datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			otorgada_por_admin_id bigint(20) DEFAULT 0 NOT NULL,
			motivo text DEFAULT '' NOT NULL,
			PRIMARY KEY  (id),
			KEY usuario_id (usuario_id),
			KEY condecoracion_id (condecoracion_id)
		) $charset_collate;";

		// Table 3: wp_raid_solicitudes
		$table_raids = $wpdb->prefix . 'raid_solicitudes';
		$sql3 = "CREATE TABLE $table_raids (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			usuario_id bigint(20) NOT NULL,
			fecha date NOT NULL,
			hora time NOT NULL,
			servidor varchar(100) DEFAULT '' NOT NULL,
			password varchar(100) DEFAULT '' NOT NULL,
			notas text DEFAULT '' NOT NULL,
			estado varchar(20) DEFAULT 'activa' NOT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY (id),
			KEY usuario_id (usuario_id),
			KEY fecha (fecha)
		) $charset_collate;";

		// Table 4: wp_raid_participantes
		$table_raid_parts = $wpdb->prefix . 'raid_participantes';
		$sql4 = "CREATE TABLE $table_raid_parts (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			raid_id bigint(20) NOT NULL,
			telegram_user_id varchar(50) DEFAULT '' NOT NULL,
			telegram_username varchar(100) DEFAULT '' NOT NULL,
			nombre varchar(200) DEFAULT '' NOT NULL,
			confirmed_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY (id),
			KEY raid_id (raid_id)
		) $charset_collate;";

		// Table 5: wp_rmm_medal_rules
		$table_medal_rules = $wpdb->prefix . 'rmm_medal_rules';
		$sql5 = "CREATE TABLE $table_medal_rules (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			medal_id bigint(20) NOT NULL,
			rule_name varchar(200) DEFAULT '' NOT NULL,
			conditions longtext DEFAULT '' NOT NULL,
			logic varchar(3) DEFAULT 'AND' NOT NULL,
			enabled tinyint(1) DEFAULT 1 NOT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY (id),
			KEY medal_id (medal_id)
		) $charset_collate;";

		// Table 6: wp_rmm_match_sessions
		$table_sessions = $wpdb->prefix . 'rmm_match_sessions';
		$sql6 = "CREATE TABLE $table_sessions (
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
			PRIMARY KEY (id),
			KEY started_at (started_at)
		) $charset_collate;";

		// Table 7: wp_rmm_dagr_maps
		$table_dagr = $wpdb->prefix . 'rmm_dagr_maps';
		$sql7 = "CREATE TABLE $table_dagr (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			map_name varchar(100) NOT NULL,
			display_name varchar(200) DEFAULT '' NOT NULL,
			tiles_path varchar(500) DEFAULT '' NOT NULL,
			min_x float DEFAULT 0 NOT NULL,
			min_y float DEFAULT 0 NOT NULL,
			max_x float DEFAULT 12800 NOT NULL,
			max_y float DEFAULT 12800 NOT NULL,
			scale_factor float DEFAULT 0.08 NOT NULL,
			edge_offset int(11) DEFAULT 50 NOT NULL,
			max_zoom int(11) DEFAULT 5 NOT NULL,
			enabled tinyint(1) DEFAULT 1 NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY map_name (map_name)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql1 );
		dbDelta( $sql2 );
		dbDelta( $sql3 );
		dbDelta( $sql4 );
		dbDelta( $sql5 );
		dbDelta( $sql6 );
		dbDelta( $sql7 );
	}
}
