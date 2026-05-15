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

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql1 );
		dbDelta( $sql2 );
	}
}
