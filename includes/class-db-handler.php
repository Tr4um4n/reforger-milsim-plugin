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

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql1 );
		dbDelta( $sql2 );
		dbDelta( $sql3 );
		dbDelta( $sql4 );
	}
}
