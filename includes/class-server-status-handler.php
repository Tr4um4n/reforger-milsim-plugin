<?php
/**
 * Server Status Shortcodes Handler
 * @package ReforgerMilsimManagement
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class RMM_Server_Status_Handler {

    public function __construct() {
        add_shortcode( 'rmm_server_status', array( $this, 'render_server_status' ) );
        add_shortcode( 'rmm_server_resources', array( $this, 'render_server_resources' ) );
        add_shortcode( 'rmm_server_info', array( $this, 'render_server_info' ) );
        
        // AJAX endpoint for live refresh
        add_action( 'wp_ajax_rmm_live_server_data', array( $this, 'ajax_live_server_data' ) );
        add_action( 'wp_ajax_nopriv_rmm_live_server_data', array( $this, 'ajax_live_server_data' ) );
    }

    /**
     * Get cached or fresh server info
     */
    private function get_server_data( $server_id = '' ) {
        if ( empty( $server_id ) ) {
            $server_id = get_option( 'rmm_ptero_stable_server_id', '' );
        }
        if ( empty( $server_id ) ) {
            return null;
        }

        // Try cache (30 seconds)
        $cache_key = 'rmm_server_data_' . $server_id;
        $cached = get_transient( $cache_key );
        if ( $cached !== false ) {
            return $cached;
        }

        try {
            $ptero = new RMM_Pterodactyl_Handler();
            $data = $ptero->get_current_game_info( $server_id );
            set_transient( $cache_key, $data, 30 );
            return $data;
        } catch ( Exception $e ) {
            return array( 'state' => 'error', 'error' => $e->getMessage() );
        }
    }

    /**
     * Format bytes to human readable
     */
    private function format_bytes( $bytes, $precision = 1 ) {
        $units = array( 'B', 'KB', 'MB', 'GB', 'TB' );
        $bytes = max( $bytes, 0 );
        $pow = floor( ( $bytes ? log( $bytes ) : 0 ) / log( 1024 ) );
        $pow = min( $pow, count( $units ) - 1 );
        $bytes /= ( 1 << ( 10 * $pow ) );
        return round( $bytes, $precision ) . ' ' . $units[ $pow ];
    }

    /**
     * Format uptime from milliseconds to human readable
     */
    private function format_uptime( $ms ) {
        $seconds = floor( $ms / 1000 );
        $days = floor( $seconds / 86400 );
        $hours = floor( ( $seconds % 86400 ) / 3600 );
        $minutes = floor( ( $seconds % 3600 ) / 60 );
        
        $parts = array();
        if ( $days > 0 ) $parts[] = $days . 'd';
        if ( $hours > 0 ) $parts[] = $hours . 'h';
        $parts[] = $minutes . 'm';
        return implode( ' ', $parts );
    }

    /**
     * [rmm_server_status] - Estado del servidor (Online/Offline)
     * Params: server_id="", show_uptime="1", show_scenario="1"
     */
    public function render_server_status( $atts ) {
        $atts = shortcode_atts( array(
            'server_id'     => '',
            'show_uptime'   => '1',
            'show_scenario' => '1',
        ), $atts );

        $data = $this->get_server_data( $atts['server_id'] );
        if ( ! $data ) {
            return '<div class="rmm-server-widget rmm-server-error"><i class="fa-solid fa-triangle-exclamation"></i> ' . __( 'Servidor no configurado.', 'reforger-milsim' ) . '</div>';
        }

        $is_online = ( $data['state'] === 'running' );
        $status_text = $is_online ? __( 'EN LÍNEA', 'reforger-milsim' ) : __( 'FUERA DE LÍNEA', 'reforger-milsim' );
        $status_icon = $is_online ? 'fa-solid fa-circle-check' : 'fa-solid fa-circle-xmark';
        $status_color = $is_online ? '#22c55e' : '#ef4444';
        $pulse_class = $is_online ? 'rmm-pulse-online' : '';

        ob_start();
        ?>
        <div class="rmm-server-widget rmm-server-status-widget" style="background: #0d1117; border: 1px solid #21262d; border-radius: 8px; padding: 20px; font-family: 'Inter', sans-serif; color: #c9d1d9;">
            <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 12px;">
                <span class="<?php echo $pulse_class; ?>" style="display: inline-block; width: 14px; height: 14px; border-radius: 50%; background: <?php echo $status_color; ?>; box-shadow: 0 0 12px <?php echo $status_color; ?>80;"></span>
                <i class="<?php echo $status_icon; ?>" style="color: <?php echo $status_color; ?>; font-size: 1.5rem;"></i>
                <span style="font-size: 0.9rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: <?php echo $status_color; ?>;"><?php echo $status_text; ?></span>
            </div>
            
            <?php if ( $is_online ) : ?>
                <?php if ( $atts['show_scenario'] === '1' && ! empty( $data['scenario_name'] ) ) : ?>
                    <div style="font-size: 0.75rem; color: #8b949e; margin-bottom: 6px; display: flex; align-items: center; gap: 6px;">
                        <i class="fa-solid fa-map" style="color: #58a6ff;"></i>
                        <span style="text-transform: uppercase; letter-spacing: 0.04em;"><?php echo esc_html( $data['scenario_name'] ); ?></span>
                    </div>
                <?php endif; ?>
                
                <?php if ( $atts['show_uptime'] === '1' && ! empty( $data['uptime_ms'] ) ) : ?>
                    <div style="font-size: 0.7rem; color: #484f58; display: flex; align-items: center; gap: 6px;">
                        <i class="fa-solid fa-clock"></i>
                        <span><?php echo esc_html( $this->format_uptime( $data['uptime_ms'] ) ); ?></span>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <style>
            @keyframes rmmPulseOnline {
                0%, 100% { opacity: 1; }
                50% { opacity: 0.5; }
            }
            .rmm-pulse-online {
                animation: rmmPulseOnline 2s ease-in-out infinite;
            }
        </style>
        <?php
        return ob_get_clean();
    }

    /**
     * [rmm_server_resources] - Barras de CPU, RAM, Disco
     * Params: server_id="", show_cpu="1", show_ram="1", show_disk="1"
     */
    public function render_server_resources( $atts ) {
        $atts = shortcode_atts( array(
            'server_id' => '',
            'show_cpu'  => '1',
            'show_ram'  => '1',
            'show_disk' => '1',
        ), $atts );

        $data = $this->get_server_data( $atts['server_id'] );
        if ( ! $data ) {
            return '<div class="rmm-server-widget rmm-server-error"><i class="fa-solid fa-triangle-exclamation"></i> ' . __( 'Servidor no configurado.', 'reforger-milsim' ) . '</div>';
        }

        $is_online = ( $data['state'] === 'running' );
        
        // Calculate percentages
        $cpu_pct = isset( $data['cpu_absolute'] ) ? round( $data['cpu_absolute'], 1 ) : 0;
        $mem_used = isset( $data['memory_bytes'] ) ? $data['memory_bytes'] : 0;
        $mem_limit = isset( $data['memory_limit'] ) ? $data['memory_limit'] : 1;
        $mem_pct = $mem_limit > 0 ? round( ( $mem_used / $mem_limit ) * 100, 1 ) : 0;
        $disk_used = isset( $data['disk_bytes'] ) ? $data['disk_bytes'] : 0;
        $disk_limit = isset( $data['disk_limit'] ) ? $data['disk_limit'] : 1;
        $disk_pct = $disk_limit > 0 ? round( ( $disk_used / $disk_limit ) * 100, 1 ) : 0;

        // Color thresholds
        $cpu_color = $cpu_pct > 80 ? '#ef4444' : ( $cpu_pct > 50 ? '#f59e0b' : '#22c55e' );
        $mem_color = $mem_pct > 80 ? '#ef4444' : ( $mem_pct > 50 ? '#f59e0b' : '#22c55e' );
        $disk_color = $disk_pct > 80 ? '#ef4444' : ( $disk_pct > 50 ? '#f59e0b' : '#22c55e' );

        ob_start();
        ?>
        <div class="rmm-server-widget rmm-server-resources-widget" style="background: #0d1117; border: 1px solid #21262d; border-radius: 8px; padding: 20px; font-family: 'Inter', sans-serif; color: #c9d1d9;">
            <h4 style="font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: #8b949e; margin: 0 0 16px; display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-chart-bar" style="color: #58a6ff;"></i> <?php _e( 'Recursos del Servidor', 'reforger-milsim' ); ?>
            </h4>
            
            <?php if ( $atts['show_cpu'] === '1' ) : ?>
            <div style="margin-bottom: 14px;">
                <div style="display: flex; justify-content: space-between; margin-bottom: 4px;">
                    <span style="font-size: 0.6rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: #8b949e;"><i class="fa-solid fa-microchip"></i> CPU</span>
                    <span style="font-size: 0.65rem; font-weight: 700; color: <?php echo $cpu_color; ?>; font-family: monospace;"><?php echo $cpu_pct; ?>%</span>
                </div>
                <div style="background: #161b22; border-radius: 3px; height: 8px; overflow: hidden;">
                    <div style="width: <?php echo min( $cpu_pct, 100 ); ?>%; height: 100%; background: <?php echo $cpu_color; ?>; border-radius: 3px; transition: width 0.6s ease;"></div>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if ( $atts['show_ram'] === '1' ) : ?>
            <div style="margin-bottom: 14px;">
                <div style="display: flex; justify-content: space-between; margin-bottom: 4px;">
                    <span style="font-size: 0.6rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: #8b949e;"><i class="fa-solid fa-memory"></i> RAM</span>
                    <span style="font-size: 0.65rem; font-weight: 700; color: <?php echo $mem_color; ?>; font-family: monospace;"><?php echo $this->format_bytes( $mem_used ); ?> / <?php echo $this->format_bytes( $mem_limit ); ?></span>
                </div>
                <div style="background: #161b22; border-radius: 3px; height: 8px; overflow: hidden;">
                    <div style="width: <?php echo min( $mem_pct, 100 ); ?>%; height: 100%; background: <?php echo $mem_color; ?>; border-radius: 3px; transition: width 0.6s ease;"></div>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if ( $atts['show_disk'] === '1' ) : ?>
            <div style="margin-bottom: 14px;">
                <div style="display: flex; justify-content: space-between; margin-bottom: 4px;">
                    <span style="font-size: 0.6rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: #8b949e;"><i class="fa-solid fa-hard-drive"></i> Disco</span>
                    <span style="font-size: 0.65rem; font-weight: 700; color: <?php echo $disk_color; ?>; font-family: monospace;"><?php echo $this->format_bytes( $disk_used ); ?> / <?php echo $this->format_bytes( $disk_limit ); ?></span>
                </div>
                <div style="background: #161b22; border-radius: 3px; height: 8px; overflow: hidden;">
                    <div style="width: <?php echo min( $disk_pct, 100 ); ?>%; height: 100%; background: <?php echo $disk_color; ?>; border-radius: 3px; transition: width 0.6s ease;"></div>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if ( ! $is_online ) : ?>
                <p style="font-size: 0.65rem; color: #484f58; text-align: center; margin: 10px 0 0; font-style: italic;"><?php _e( 'El servidor está actualmente fuera de línea.', 'reforger-milsim' ); ?></p>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * [rmm_server_info] - Datos de la partida actual
     * Params: server_id=""
     */
    public function render_server_info( $atts ) {
        $atts = shortcode_atts( array(
            'server_id' => '',
        ), $atts );

        $data = $this->get_server_data( $atts['server_id'] );
        if ( ! $data ) {
            return '<div class="rmm-server-widget rmm-server-error"><i class="fa-solid fa-triangle-exclamation"></i> ' . __( 'Servidor no configurado.', 'reforger-milsim' ) . '</div>';
        }

        $is_online = ( $data['state'] === 'running' );
                $server_ip = get_option( 'rmm_server_ip', '' );
                $server_port = get_option( 'rmm_server_port', 2001 );

                ob_start();
        ?>
        <div class="rmm-server-widget rmm-server-info-widget" style="background: #0d1117; border: 1px solid #21262d; border-radius: 8px; padding: 20px; font-family: 'Inter', sans-serif; color: #c9d1d9;">
            <h4 style="font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: #8b949e; margin: 0 0 16px; display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-circle-info" style="color: #58a6ff;"></i> <?php _e( 'Información de Partida', 'reforger-milsim' ); ?>
            </h4>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                <div style="background: #161b22; border: 1px solid #21262d; border-radius: 6px; padding: 10px 12px;">
                    <span style="display: block; font-size: 0.5rem; text-transform: uppercase; letter-spacing: 0.06em; color: #484f58; margin-bottom: 4px;"><i class="fa-solid fa-map"></i> Escenario</span>
                    <span style="font-size: 0.7rem; font-weight: 600; color: #e5e7eb;"><?php echo esc_html( $data['scenario_name'] ?? __( '—', 'reforger-milsim' ) ); ?></span>
                </div>
                
                <div style="background: #161b22; border: 1px solid #21262d; border-radius: 6px; padding: 10px 12px;">
                    <span style="display: block; font-size: 0.5rem; text-transform: uppercase; letter-spacing: 0.06em; color: #484f58; margin-bottom: 4px;"><i class="fa-solid fa-clock"></i> Tiempo Activo</span>
                    <span style="font-size: 0.7rem; font-weight: 600; color: #e5e7eb; font-family: monospace;"><?php echo $is_online ? esc_html( $this->format_uptime( $data['uptime_ms'] ?? 0 ) ) : '—'; ?></span>
                </div>
                
                <div style="background: #161b22; border: 1px solid #21262d; border-radius: 6px; padding: 10px 12px;">
                    <span style="display: block; font-size: 0.5rem; text-transform: uppercase; letter-spacing: 0.06em; color: #484f58; margin-bottom: 4px;"><i class="fa-solid fa-puzzle-piece"></i> Mods Activos</span>
                    <span style="font-size: 0.7rem; font-weight: 600; color: #e5e7eb; font-family: monospace;"><?php echo isset( $data['mods_count'] ) ? $data['mods_count'] : '—'; ?></span>
                </div>
                
                <div style="background: #161b22; border: 1px solid #21262d; border-radius: 6px; padding: 10px 12px;">
                    <span style="display: block; font-size: 0.5rem; text-transform: uppercase; letter-spacing: 0.06em; color: #484f58; margin-bottom: 4px;"><i class="fa-solid fa-database"></i> Persistencia</span>
                    <span style="font-size: 0.7rem; font-weight: 600; color: <?php echo ( ! empty( $data['persistence'] ) ) ? '#22c55e' : '#6b7280'; ?>;">
                        <?php echo ( ! empty( $data['persistence'] ) ) ? __( 'Activa', 'reforger-milsim' ) : __( 'Inactiva', 'reforger-milsim' ); ?>
                    </span>
                </div>
                
                <?php if ( ! empty( $server_ip ) ) : ?>
                <div style="background: #161b22; border: 1px solid #21262d; border-radius: 6px; padding: 10px 12px; grid-column: span 2;">
                    <span style="display: block; font-size: 0.5rem; text-transform: uppercase; letter-spacing: 0.06em; color: #484f58; margin-bottom: 4px;"><i class="fa-solid fa-network-wired"></i> Conexión Directa</span>
                                        <span style="font-size: 0.8rem; font-weight: 700; color: #58a6ff; font-family: monospace; user-select: all; cursor: copy;" title="Clic para copiar"><?php echo esc_html( $server_ip . ':' . $server_port ); ?></span>
                </div>
                <?php endif; ?>
            </div>
            
            <?php if ( ! $is_online ) : ?>
                <p style="font-size: 0.65rem; color: #484f58; text-align: center; margin: 12px 0 0; font-style: italic;"><?php _e( 'El servidor está actualmente fuera de línea.', 'reforger-milsim' ); ?></p>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * AJAX: Live server data for auto-refresh widgets
     */
    public function ajax_live_server_data() {
        $server_id = isset( $_GET['server_id'] ) ? sanitize_text_field( $_GET['server_id'] ) : '';
        $data = $this->get_server_data( $server_id );
        
        if ( ! $data ) {
            wp_send_json_error( array( 'message' => __( 'Servidor no configurado.', 'reforger-milsim' ) ) );
        }
        
        // Add formatted values
        $data['uptime_formatted'] = $this->format_uptime( $data['uptime_ms'] ?? 0 );
        $data['memory_formatted'] = $this->format_bytes( $data['memory_bytes'] ?? 0 );
        $data['memory_limit_formatted'] = $this->format_bytes( $data['memory_limit'] ?? 0 );
        $data['disk_formatted'] = $this->format_bytes( $data['disk_bytes'] ?? 0 );
        $data['disk_limit_formatted'] = $this->format_bytes( $data['disk_limit'] ?? 0 );
        $data['is_online'] = ( $data['state'] === 'running' );
        
        wp_send_json_success( $data );
    }
}
