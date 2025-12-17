<?php
/**
 * Admin Interface Class
 *
 * Handles the plugin admin settings page
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class Jonakyds_Nalda_Sync_Admin {

    /**
     * Constructor
     */
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_post_jonakyds_export_now', array($this, 'handle_manual_export'));
        add_action('admin_post_jonakyds_clear_logs', array($this, 'handle_clear_logs'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_styles'));
        add_action('wp_ajax_jonakyds_test_sftp_connection', array($this, 'handle_test_sftp_connection'));
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            __('Nalda Sync', 'jonakyds-nalda-sync'),
            __('Nalda Sync', 'jonakyds-nalda-sync'),
            'manage_woocommerce',
            'jonakyds-nalda-sync',
            array($this, 'render_admin_page')
        );
    }

    /**
     * Register settings
     */
    public function register_settings() {
        register_setting('jonakyds_nalda_sync_settings', 'jonakyds_nalda_sync_enabled');
        register_setting('jonakyds_nalda_sync_settings', 'jonakyds_nalda_sync_schedule', array($this, 'reschedule_cron_on_change'));
        register_setting('jonakyds_nalda_sync_settings', 'jonakyds_nalda_sync_return_days');
        register_setting('jonakyds_nalda_sync_settings', 'jonakyds_nalda_sync_delivery_days');
        register_setting('jonakyds_nalda_sync_settings', 'jonakyds_nalda_sync_condition');
        register_setting('jonakyds_nalda_sync_settings', 'jonakyds_nalda_sync_default_brand');
        register_setting('jonakyds_nalda_sync_settings', 'jonakyds_nalda_sync_require_gtin');
        // FTP/SFTP settings
        register_setting('jonakyds_nalda_sync_settings', 'jonakyds_nalda_sync_ftp_enabled');
        register_setting('jonakyds_nalda_sync_settings', 'jonakyds_nalda_sync_ftp_type');
        register_setting('jonakyds_nalda_sync_settings', 'jonakyds_nalda_sync_ftp_server');
        register_setting('jonakyds_nalda_sync_settings', 'jonakyds_nalda_sync_ftp_port');
        register_setting('jonakyds_nalda_sync_settings', 'jonakyds_nalda_sync_ftp_username');
        register_setting('jonakyds_nalda_sync_settings', 'jonakyds_nalda_sync_ftp_password');
        register_setting('jonakyds_nalda_sync_settings', 'jonakyds_nalda_sync_ftp_path');
        register_setting('jonakyds_nalda_sync_settings', 'jonakyds_nalda_sync_ftp_ssl');
    }

    /**
     * Reschedule cron when schedule setting changes
     */
    public function reschedule_cron_on_change($new_value) {
        $old_value = get_option('jonakyds_nalda_sync_schedule');
        
        if ($old_value !== $new_value) {
            $timestamp = wp_next_scheduled('jonakyds_nalda_sync_cron');
            if ($timestamp) {
                wp_unschedule_event($timestamp, 'jonakyds_nalda_sync_cron');
            }
            wp_schedule_event(time() + 300, $new_value, 'jonakyds_nalda_sync_cron');
        }
        
        return $new_value;
    }

    /**
     * Get the standard tax rate from WooCommerce
     *
     * @return string Tax rate percentage
     */
    private static function get_wc_tax_rate() {
        if (!wc_tax_enabled()) {
            return '0';
        }
        
        // Get standard tax rates for the base country
        $tax_rates = WC_Tax::get_rates_for_tax_class('');
        if (!empty($tax_rates)) {
            $first_rate = reset($tax_rates);
            return number_format((float)$first_rate->tax_rate, 1, '.', '');
        }
        
        return '0';
    }

    /**
     * Enqueue admin styles
     */
    public function enqueue_admin_styles($hook) {
        if ($hook !== 'woocommerce_page_jonakyds-nalda-sync') {
            return;
        }

        wp_add_inline_style('wp-admin', '
            .jonakyds-sync-container {
                max-width: 1200px;
                margin: 20px 0;
            }
            .jonakyds-card {
                background: #fff;
                border: 1px solid #ccd0d4;
                box-shadow: 0 1px 1px rgba(0,0,0,.04);
                margin-bottom: 20px;
                padding: 20px;
            }
            .jonakyds-card h2 {
                margin-top: 0;
                padding-bottom: 10px;
                border-bottom: 1px solid #eee;
            }
            .jonakyds-form-row {
                margin-bottom: 20px;
            }
            .jonakyds-form-row label {
                display: block;
                margin-bottom: 5px;
                font-weight: 600;
            }
            .jonakyds-form-row input[type="text"],
            .jonakyds-form-row input[type="number"],
            .jonakyds-form-row select {
                width: 100%;
                max-width: 500px;
            }
            .jonakyds-form-row small {
                display: block;
                margin-top: 5px;
                color: #666;
            }
            .jonakyds-form-row-inline {
                display: flex;
                gap: 20px;
                flex-wrap: wrap;
            }
            .jonakyds-form-row-inline > div {
                flex: 1;
                min-width: 200px;
            }
            .jonakyds-log-entry {
                padding: 10px;
                margin-bottom: 10px;
                border-left: 4px solid #ccc;
                background: #f9f9f9;
                position: relative;
            }
            .jonakyds-log-entry.success {
                border-left-color: #46b450;
            }
            .jonakyds-log-entry.log-error {
                border-left-color: #dc3232;
            }
            .jonakyds-log-timestamp {
                font-size: 12px;
                color: #666;
            }
            .jonakyds-log-stats {
                margin-top: 5px;
                font-size: 13px;
                color: #555;
            }
            .jonakyds-csv-info {
                background: #f0f6fc;
                border: 1px solid #c8d7e1;
                border-radius: 4px;
                padding: 15px;
                margin-bottom: 20px;
            }
            .jonakyds-csv-info h3 {
                margin-top: 0;
                margin-bottom: 10px;
            }
            .jonakyds-csv-info-row {
                display: flex;
                gap: 30px;
                flex-wrap: wrap;
                margin-bottom: 10px;
            }
            .jonakyds-csv-info-item {
                display: flex;
                gap: 5px;
            }
            .jonakyds-csv-info-item strong {
                color: #1e3a5f;
            }
            .jonakyds-csv-url {
                background: #fff;
                padding: 10px;
                border-radius: 4px;
                word-break: break-all;
                font-family: monospace;
                font-size: 13px;
                margin-top: 10px;
            }
            .jonakyds-copy-btn {
                margin-left: 10px;
                cursor: pointer;
            }
            
            /* Progress UI */
            .jonakyds-progress-container {
                display: none;
                margin: 20px 0;
                padding: 30px;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                border-radius: 12px;
                box-shadow: 0 10px 30px rgba(102, 126, 234, 0.3);
                color: white;
            }
            .jonakyds-progress-container.active {
                display: block;
            }
            .jonakyds-progress-title {
                font-size: 20px;
                font-weight: 600;
                margin-bottom: 10px;
                text-align: center;
            }
            .jonakyds-progress-message {
                text-align: center;
                margin-bottom: 20px;
                font-size: 14px;
                opacity: 0.9;
            }
            .jonakyds-progress-bar-container {
                background: rgba(255, 255, 255, 0.2);
                border-radius: 50px;
                height: 30px;
                overflow: hidden;
                margin-bottom: 15px;
                position: relative;
            }
            .jonakyds-progress-bar {
                height: 100%;
                background: linear-gradient(90deg, #4ade80 0%, #22c55e 100%);
                border-radius: 50px;
                transition: width 0.3s ease;
                display: flex;
                align-items: center;
                justify-content: center;
                font-weight: 600;
                font-size: 13px;
                box-shadow: 0 2px 10px rgba(34, 197, 94, 0.4);
            }
            .jonakyds-progress-stats {
                display: flex;
                justify-content: space-around;
                margin-top: 20px;
                flex-wrap: wrap;
                gap: 15px;
            }
            .jonakyds-progress-stat {
                background: rgba(255, 255, 255, 0.15);
                padding: 15px 25px;
                border-radius: 8px;
                text-align: center;
                backdrop-filter: blur(10px);
                flex: 1;
                min-width: 120px;
            }
            .jonakyds-progress-stat-value {
                font-size: 28px;
                font-weight: 700;
                display: block;
                margin-bottom: 5px;
            }
            .jonakyds-progress-stat-label {
                font-size: 12px;
                opacity: 0.9;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }
            .jonakyds-sync-button {
                position: relative;
                overflow: hidden;
            }
            .jonakyds-sync-button:disabled {
                opacity: 0.6;
                cursor: not-allowed;
            }
            .jonakyds-spinner {
                display: inline-block;
                width: 16px;
                height: 16px;
                border: 2px solid rgba(255,255,255,0.3);
                border-top-color: white;
                border-radius: 50%;
                animation: spin 0.8s linear infinite;
                margin-right: 8px;
                vertical-align: middle;
            }
            .jonakyds-test-sftp-btn {
                margin-top: 10px;
            }
            .jonakyds-sftp-test-result {
                margin-top: 10px;
                padding: 10px 15px;
                border-radius: 4px;
                display: none;
            }
            .jonakyds-sftp-test-result.success {
                background: #d4edda;
                border: 1px solid #c3e6cb;
                color: #155724;
                display: block;
            }
            .jonakyds-sftp-test-result.error {
                background: #f8d7da;
                border: 1px solid #f5c6cb;
                color: #721c24;
                display: block;
            }
            .jonakyds-sftp-test-result.testing {
                background: #fff3cd;
                border: 1px solid #ffeeba;
                color: #856404;
                display: block;
            }
            }
            @keyframes spin {
                to { transform: rotate(360deg); }
            }
            .jonakyds-complete-message {
                display: none;
                padding: 20px;
                background: #fff;
                border-left: 4px solid #46b450;
                border-radius: 4px;
                margin-top: 20px;
                box-shadow: 0 2px 8px rgba(0,0,0,0.1);
                position: relative;
            }
            .jonakyds-complete-message.show {
                display: block;
            }
            .jonakyds-complete-message.complete-error {
                border-left-color: #dc3232;
            }
            .jonakyds-complete-icon {
                font-size: 24px;
                margin-right: 10px;
            }
            .jonakyds-download-buttons {
                display: flex;
                gap: 10px;
                flex-wrap: wrap;
                margin-top: 15px;
            }
        ');
    }

    /**
     * Render admin page
     */
    public function render_admin_page() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'jonakyds-nalda-sync'));
        }

        $enabled = get_option('jonakyds_nalda_sync_enabled', 'no');
        $schedule = get_option('jonakyds_nalda_sync_schedule', 'hourly');
        $return_days = get_option('jonakyds_nalda_sync_return_days', '14');
        $delivery_days = get_option('jonakyds_nalda_sync_delivery_days', '1');
        $condition = get_option('jonakyds_nalda_sync_condition', 'new');
        $default_brand = get_option('jonakyds_nalda_sync_default_brand', '');
        $require_gtin = get_option('jonakyds_nalda_sync_require_gtin', 'yes');
        
        // FTP/SFTP settings
        $ftp_enabled = get_option('jonakyds_nalda_sync_ftp_enabled', 'no');
        $ftp_type = get_option('jonakyds_nalda_sync_ftp_type', 'ftp');
        $ftp_server = get_option('jonakyds_nalda_sync_ftp_server', '');
        $ftp_port = get_option('jonakyds_nalda_sync_ftp_port', '21');
        $ftp_username = get_option('jonakyds_nalda_sync_ftp_username', '');
        $ftp_password = get_option('jonakyds_nalda_sync_ftp_password', '');
        $ftp_path = get_option('jonakyds_nalda_sync_ftp_path', '/');
        $ftp_ssl = get_option('jonakyds_nalda_sync_ftp_ssl', 'no');
        
        // Get values from WooCommerce settings
        $wc_country = WC()->countries->get_base_country();
        $wc_currency = get_woocommerce_currency();
        $wc_tax_rate = self::get_wc_tax_rate();
        
        $logs = Jonakyds_Nalda_CSV_Exporter::get_logs();
        $csv_info = Jonakyds_Nalda_CSV_Exporter::get_csv_info();
        $next_export = wp_next_scheduled('jonakyds_nalda_sync_cron');
        $last_export = get_option('jonakyds_nalda_sync_last_export', '');

        ?>
        <div class="wrap">
            <h1><?php _e('Nalda Sync Settings', 'jonakyds-nalda-sync'); ?></h1>
            
            <?php settings_errors(); ?>

            <?php if (isset($_GET['export_result'])): ?>
                <div class="notice notice-<?php echo $_GET['export_result'] === 'success' ? 'success' : 'error'; ?> is-dismissible">
                    <p><?php echo esc_html(urldecode($_GET['export_message'])); ?></p>
                </div>
            <?php endif; ?>

            <div class="jonakyds-sync-container">
                <!-- CSV File Info Card -->
                <?php if ($csv_info): ?>
                <div class="jonakyds-card">
                    <h2><?php _e('Current Export File', 'jonakyds-nalda-sync'); ?></h2>
                    <div class="jonakyds-csv-info">
                        <div class="jonakyds-csv-info-row">
                            <div class="jonakyds-csv-info-item">
                                <strong><?php _e('Products:', 'jonakyds-nalda-sync'); ?></strong>
                                <span><?php echo number_format($csv_info['rows']); ?></span>
                            </div>
                            <div class="jonakyds-csv-info-item">
                                <strong><?php _e('File Size:', 'jonakyds-nalda-sync'); ?></strong>
                                <span><?php echo size_format($csv_info['size']); ?></span>
                            </div>
                            <div class="jonakyds-csv-info-item">
                                <strong><?php _e('Last Updated:', 'jonakyds-nalda-sync'); ?></strong>
                                <span><?php 
                                    echo date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($csv_info['modified']));
                                    $tz_string = get_option('timezone_string');
                                    if (empty($tz_string)) {
                                        $tz_string = 'UTC';
                                    }
                                    echo ' (' . esc_html($tz_string) . ')';
                                ?></span>
                            </div>
                        </div>
                        <div class="jonakyds-csv-url">
                            <strong><?php _e('CSV URL:', 'jonakyds-nalda-sync'); ?></strong>
                            <span id="csv-url"><?php echo esc_url($csv_info['url']); ?></span>
                            <button type="button" class="button button-small jonakyds-copy-btn" onclick="copyToClipboard('csv-url')">
                                <?php _e('Copy', 'jonakyds-nalda-sync'); ?>
                            </button>
                        </div>
                        <div class="jonakyds-download-buttons">
                            <a href="<?php echo esc_url($csv_info['url']); ?>" class="button button-primary" download>
                                <?php _e('Download CSV', 'jonakyds-nalda-sync'); ?>
                            </a>
                            <a href="<?php echo esc_url(admin_url('admin.php?jonakyds_nalda_download=1')); ?>" class="button">
                                <?php _e('Download (Force)', 'jonakyds-nalda-sync'); ?>
                            </a>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- WooCommerce Settings Info -->
                <div class="jonakyds-card">
                    <h2><?php _e('Store Settings (from WooCommerce)', 'jonakyds-nalda-sync'); ?></h2>
                    <p class="description"><?php _e('These values are automatically detected from your WooCommerce settings.', 'jonakyds-nalda-sync'); ?></p>
                    <div class="jonakyds-form-row-inline" style="margin-top: 15px;">
                        <div>
                            <strong><?php _e('Country:', 'jonakyds-nalda-sync'); ?></strong>
                            <span><?php echo esc_html($wc_country); ?></span>
                        </div>
                        <div>
                            <strong><?php _e('Currency:', 'jonakyds-nalda-sync'); ?></strong>
                            <span><?php echo esc_html($wc_currency); ?></span>
                        </div>
                        <div>
                            <strong><?php _e('Tax Rate:', 'jonakyds-nalda-sync'); ?></strong>
                            <span><?php echo esc_html($wc_tax_rate); ?>%</span>
                        </div>
                    </div>
                    <p style="margin-top: 15px;"><small><?php printf(__('To change these, go to %sWooCommerce Settings%s.', 'jonakyds-nalda-sync'), '<a href="' . admin_url('admin.php?page=wc-settings') . '">', '</a>'); ?></small></p>
                </div>

                <!-- Settings Card -->
                <div class="jonakyds-card">
                    <h2><?php _e('Export Configuration', 'jonakyds-nalda-sync'); ?></h2>
                    <form method="post" action="options.php">
                        <?php settings_fields('jonakyds_nalda_sync_settings'); ?>
                        
                        <div class="jonakyds-form-row-inline">
                            <div>
                                <label for="jonakyds_nalda_sync_condition">
                                    <?php _e('Default Product Condition', 'jonakyds-nalda-sync'); ?>
                                </label>
                                <select id="jonakyds_nalda_sync_condition" name="jonakyds_nalda_sync_condition">
                                    <option value="new" <?php selected($condition, 'new'); ?>><?php _e('New', 'jonakyds-nalda-sync'); ?></option>
                                    <option value="used" <?php selected($condition, 'used'); ?>><?php _e('Used', 'jonakyds-nalda-sync'); ?></option>
                                    <option value="refurbished" <?php selected($condition, 'refurbished'); ?>><?php _e('Refurbished', 'jonakyds-nalda-sync'); ?></option>
                                </select>
                                <small><?php _e('Default condition for all products', 'jonakyds-nalda-sync'); ?></small>
                            </div>
                            <div>
                                <label for="jonakyds_nalda_sync_delivery_days">
                                    <?php _e('Delivery Time (days)', 'jonakyds-nalda-sync'); ?>
                                </label>
                                <input 
                                    type="number" 
                                    id="jonakyds_nalda_sync_delivery_days" 
                                    name="jonakyds_nalda_sync_delivery_days" 
                                    value="<?php echo esc_attr($delivery_days); ?>" 
                                    min="0"
                                    placeholder="1"
                                />
                            </div>
                            <div>
                                <label for="jonakyds_nalda_sync_return_days">
                                    <?php _e('Return Period (days)', 'jonakyds-nalda-sync'); ?>
                                </label>
                                <input 
                                    type="number" 
                                    id="jonakyds_nalda_sync_return_days" 
                                    name="jonakyds_nalda_sync_return_days" 
                                    value="<?php echo esc_attr($return_days); ?>" 
                                    min="0"
                                    placeholder="14"
                                />
                            </div>
                        </div>

                        <div class="jonakyds-form-row">
                            <label for="jonakyds_nalda_sync_default_brand">
                                <?php _e('Default Brand', 'jonakyds-nalda-sync'); ?>
                            </label>
                            <input 
                                type="text" 
                                id="jonakyds_nalda_sync_default_brand" 
                                name="jonakyds_nalda_sync_default_brand" 
                                value="<?php echo esc_attr($default_brand); ?>" 
                                placeholder="Your Brand Name"
                            />
                            <small><?php _e('Fallback brand when product has no brand attribute set', 'jonakyds-nalda-sync'); ?></small>
                        </div>

                        <div class="jonakyds-form-row">
                            <label for="jonakyds_nalda_sync_require_gtin">
                                <input 
                                    type="checkbox" 
                                    id="jonakyds_nalda_sync_require_gtin" 
                                    name="jonakyds_nalda_sync_require_gtin" 
                                    value="yes"
                                    <?php checked($require_gtin, 'yes'); ?>
                                />
                                <?php _e('Require GTIN (EAN/ISBN/UPC)', 'jonakyds-nalda-sync'); ?>
                            </label>
                            <small><?php _e('If checked, products without a GTIN will be skipped in the export.', 'jonakyds-nalda-sync'); ?></small>
                        </div>

                        <hr style="margin: 25px 0;">

                        <h3><?php _e('Nalda FTP/SFTP Upload', 'jonakyds-nalda-sync'); ?></h3>
                        <p class="description"><?php _e('Configure FTP or SFTP settings to automatically upload the CSV file to Nalda.', 'jonakyds-nalda-sync'); ?></p>

                        <div class="jonakyds-form-row">
                            <label for="jonakyds_nalda_sync_ftp_enabled">
                                <input 
                                    type="checkbox" 
                                    id="jonakyds_nalda_sync_ftp_enabled" 
                                    name="jonakyds_nalda_sync_ftp_enabled" 
                                    value="yes"
                                    <?php checked($ftp_enabled, 'yes'); ?>
                                />
                                <?php _e('Enable FTP/SFTP upload to Nalda', 'jonakyds-nalda-sync'); ?>
                            </label>
                            <small><?php _e('When enabled, the CSV file will be uploaded to the Nalda server after each export.', 'jonakyds-nalda-sync'); ?></small>
                        </div>

                        <div class="jonakyds-form-row">
                            <label for="jonakyds_nalda_sync_ftp_type">
                                <?php _e('Connection Type', 'jonakyds-nalda-sync'); ?>
                            </label>
                            <select id="jonakyds_nalda_sync_ftp_type" name="jonakyds_nalda_sync_ftp_type">
                                <option value="ftp" <?php selected($ftp_type, 'ftp'); ?>><?php _e('FTP (Standard)', 'jonakyds-nalda-sync'); ?></option>
                                <option value="sftp" <?php selected($ftp_type, 'sftp'); ?>><?php _e('SFTP (SSH)', 'jonakyds-nalda-sync'); ?></option>
                            </select>
                            <small><?php _e('FTP is more widely supported. SFTP requires the SSH2 PHP extension.', 'jonakyds-nalda-sync'); ?></small>
                        </div>

                        <div class="jonakyds-form-row" id="jonakyds-ftp-ssl-row">
                            <label for="jonakyds_nalda_sync_ftp_ssl">
                                <input 
                                    type="checkbox" 
                                    id="jonakyds_nalda_sync_ftp_ssl" 
                                    name="jonakyds_nalda_sync_ftp_ssl" 
                                    value="yes"
                                    <?php checked($ftp_ssl, 'yes'); ?>
                                />
                                <?php _e('Use FTP over SSL (FTPS)', 'jonakyds-nalda-sync'); ?>
                            </label>
                            <small><?php _e('Enable for encrypted FTP connection. Only applies to FTP mode.', 'jonakyds-nalda-sync'); ?></small>
                        </div>

                        <div class="jonakyds-form-row-inline">
                            <div>
                                <label for="jonakyds_nalda_sync_ftp_server">
                                    <?php _e('Server', 'jonakyds-nalda-sync'); ?>
                                </label>
                                <input 
                                    type="text" 
                                    id="jonakyds_nalda_sync_ftp_server" 
                                    name="jonakyds_nalda_sync_ftp_server" 
                                    value="<?php echo esc_attr($ftp_server); ?>" 
                                    placeholder="ftp.nalda.ch"
                                />
                            </div>
                            <div>
                                <label for="jonakyds_nalda_sync_ftp_port">
                                    <?php _e('Port', 'jonakyds-nalda-sync'); ?>
                                </label>
                                <input 
                                    type="number" 
                                    id="jonakyds_nalda_sync_ftp_port" 
                                    name="jonakyds_nalda_sync_ftp_port" 
                                    value="<?php echo esc_attr($ftp_port); ?>" 
                                    min="1"
                                    max="65535"
                                    placeholder="21"
                                />
                            </div>
                        </div>

                        <div class="jonakyds-form-row-inline">
                            <div>
                                <label for="jonakyds_nalda_sync_ftp_username">
                                    <?php _e('Username', 'jonakyds-nalda-sync'); ?>
                                </label>
                                <input 
                                    type="text" 
                                    id="jonakyds_nalda_sync_ftp_username" 
                                    name="jonakyds_nalda_sync_ftp_username" 
                                    value="<?php echo esc_attr($ftp_username); ?>" 
                                    placeholder="username"
                                />
                            </div>
                            <div>
                                <label for="jonakyds_nalda_sync_ftp_password">
                                    <?php _e('Password', 'jonakyds-nalda-sync'); ?>
                                </label>
                                <input 
                                    type="password" 
                                    id="jonakyds_nalda_sync_ftp_password" 
                                    name="jonakyds_nalda_sync_ftp_password" 
                                    value="<?php echo esc_attr($ftp_password); ?>" 
                                    placeholder="••••••••"
                                />
                            </div>
                        </div>

                        <div class="jonakyds-form-row">
                            <label for="jonakyds_nalda_sync_ftp_path">
                                <?php _e('Remote Path', 'jonakyds-nalda-sync'); ?>
                            </label>
                            <input 
                                type="text" 
                                id="jonakyds_nalda_sync_ftp_path" 
                                name="jonakyds_nalda_sync_ftp_path" 
                                value="<?php echo esc_attr($ftp_path); ?>" 
                                placeholder="/"
                            />
                            <small><?php _e('The directory on the server where the CSV file will be uploaded.', 'jonakyds-nalda-sync'); ?></small>
                        </div>

                        <div class="jonakyds-form-row">
                            <button type="button" class="button jonakyds-test-sftp-btn" id="jonakyds-test-sftp">
                                <?php _e('Test Connection', 'jonakyds-nalda-sync'); ?>
                            </button>
                            <div id="jonakyds-sftp-test-result" class="jonakyds-sftp-test-result"></div>
                        </div>

                        <hr style="margin: 25px 0;">

                        <div class="jonakyds-form-row">
                            <label for="jonakyds_nalda_sync_enabled">
                                <input 
                                    type="checkbox" 
                                    id="jonakyds_nalda_sync_enabled" 
                                    name="jonakyds_nalda_sync_enabled" 
                                    value="yes"
                                    <?php checked($enabled, 'yes'); ?>
                                />
                                <?php _e('Enable automatic export', 'jonakyds-nalda-sync'); ?>
                            </label>
                            <small><?php _e('When enabled, CSV will be regenerated automatically based on the schedule below.', 'jonakyds-nalda-sync'); ?></small>
                        </div>

                        <div class="jonakyds-form-row">
                            <label for="jonakyds_nalda_sync_schedule">
                                <?php _e('Export Schedule', 'jonakyds-nalda-sync'); ?>
                            </label>
                            <select id="jonakyds_nalda_sync_schedule" name="jonakyds_nalda_sync_schedule">
                                <option value="every_10_minutes" <?php selected($schedule, 'every_10_minutes'); ?>><?php _e('Every 10 Minutes', 'jonakyds-nalda-sync'); ?></option>
                                <option value="hourly" <?php selected($schedule, 'hourly'); ?>><?php _e('Hourly', 'jonakyds-nalda-sync'); ?></option>
                                <option value="twicedaily" <?php selected($schedule, 'twicedaily'); ?>><?php _e('Twice Daily', 'jonakyds-nalda-sync'); ?></option>
                                <option value="daily" <?php selected($schedule, 'daily'); ?>><?php _e('Daily', 'jonakyds-nalda-sync'); ?></option>
                            </select>
                            <?php if ($next_export): ?>
                                <small>
                                    <?php 
                                    $timezone_string = get_option('timezone_string');
                                    if (empty($timezone_string)) {
                                        $timezone_string = 'UTC';
                                    }
                                    
                                    if ($next_export < time()) {
                                        _e('Waiting for WordPress cron to run...', 'jonakyds-nalda-sync');
                                    } else {
                                        printf(
                                            __('Next scheduled export: %s (%s)', 'jonakyds-nalda-sync'),
                                            date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $next_export),
                                            $timezone_string
                                        );
                                    }
                                    ?>
                                </small>
                            <?php endif; ?>
                        </div>

                        <?php submit_button(__('Save Settings', 'jonakyds-nalda-sync')); ?>
                    </form>
                </div>

                <!-- Manual Export Card -->
                <div class="jonakyds-card">
                    <h2><?php _e('Manual Export', 'jonakyds-nalda-sync'); ?></h2>
                    <p><?php _e('Click the button below to generate the CSV file immediately with real-time progress.', 'jonakyds-nalda-sync'); ?></p>
                    
                    <button type="button" id="jonakyds-export-now" class="button button-primary button-hero jonakyds-sync-button">
                        <?php _e('Export Now', 'jonakyds-nalda-sync'); ?>
                    </button>
                    
                    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display: inline-block; margin-left: 10px;">
                        <input type="hidden" name="action" value="jonakyds_export_now" />
                        <?php wp_nonce_field('jonakyds_export_now'); ?>
                        <button type="submit" class="button" title="<?php esc_attr_e('Single request export without progress tracking (for testing)', 'jonakyds-nalda-sync'); ?>">
                            <?php _e('Quick Export (Test)', 'jonakyds-nalda-sync'); ?>
                        </button>
                    </form>

                    <!-- Progress Container -->
                    <div id="jonakyds-progress-container" class="jonakyds-progress-container">
                        <div class="jonakyds-progress-title"><?php _e('Exporting Products...', 'jonakyds-nalda-sync'); ?></div>
                        <div id="jonakyds-progress-message" class="jonakyds-progress-message">
                            <?php _e('Initializing...', 'jonakyds-nalda-sync'); ?>
                        </div>
                        <div class="jonakyds-progress-bar-container">
                            <div id="jonakyds-progress-bar" class="jonakyds-progress-bar" style="width: 0%;">
                                <span id="jonakyds-progress-percent">0%</span>
                            </div>
                        </div>
                        <div class="jonakyds-progress-stats">
                            <div class="jonakyds-progress-stat">
                                <span id="jonakyds-stat-exported" class="jonakyds-progress-stat-value">0</span>
                                <span class="jonakyds-progress-stat-label"><?php _e('Exported', 'jonakyds-nalda-sync'); ?></span>
                            </div>
                            <div class="jonakyds-progress-stat">
                                <span id="jonakyds-stat-skipped" class="jonakyds-progress-stat-value">0</span>
                                <span class="jonakyds-progress-stat-label"><?php _e('Skipped', 'jonakyds-nalda-sync'); ?></span>
                            </div>
                            <div class="jonakyds-progress-stat">
                                <span id="jonakyds-stat-total" class="jonakyds-progress-stat-value">-</span>
                                <span class="jonakyds-progress-stat-label"><?php _e('Total', 'jonakyds-nalda-sync'); ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Completion Message -->
                    <div id="jonakyds-complete-message" class="jonakyds-complete-message"></div>
                </div>

                <script>
                function copyToClipboard(elementId) {
                    const text = document.getElementById(elementId).textContent;
                    navigator.clipboard.writeText(text).then(function() {
                        alert('<?php _e('URL copied to clipboard!', 'jonakyds-nalda-sync'); ?>');
                    });
                }

                jQuery(document).ready(function($) {
                    let exportInterval = null;
                    let currentExportId = null;
                    let isCheckingExport = false;
                    let isExportButtonDisabled = false;
                    let isPollActive = false;
                    
                    // Check for active export on page load
                    checkActiveExport();
                    
                    // Check for active export every 30 seconds (only if not already polling)
                    setInterval(function() {
                        if (!isPollActive) {
                            checkActiveExport();
                        }
                    }, 30000);
                    
                    function checkActiveExport() {
                        if (isCheckingExport || isPollActive) {
                            return;
                        }
                        
                        isCheckingExport = true;
                        
                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'jonakyds_nalda_get_active_export',
                                nonce: '<?php echo wp_create_nonce('jonakyds_nalda_ajax_export'); ?>'
                            },
                            success: function(response) {
                                if (response.success && response.data.active) {
                                    currentExportId = response.data.export_id;
                                    const $button = $('#jonakyds-export-now');
                                    const $progressContainer = $('#jonakyds-progress-container');
                                    const $completeMessage = $('#jonakyds-complete-message');
                                    
                                    isExportButtonDisabled = true;
                                    $button.prop('disabled', true).html('<span class="jonakyds-spinner"></span><?php _e('Exporting...', 'jonakyds-nalda-sync'); ?>');
                                    $progressContainer.addClass('active');
                                    $completeMessage.removeClass('show complete-error');
                                    
                                    const data = response.data.progress;
                                    updateProgress(data.percent || 0, data.message || '<?php _e('Resuming export...', 'jonakyds-nalda-sync'); ?>');
                                    if (data.exported !== undefined) $('#jonakyds-stat-exported').text(data.exported);
                                    if (data.skipped !== undefined) $('#jonakyds-stat-skipped').text(data.skipped);
                                    if (data.total !== undefined && data.total > 0) $('#jonakyds-stat-total').text(data.total);
                                    
                                    // Clear any existing interval before starting new poll
                                    if (exportInterval) {
                                        clearInterval(exportInterval);
                                        exportInterval = null;
                                    }
                                    
                                    isCheckingExport = false;
                                    pollProgress();
                                } else {
                                    isExportButtonDisabled = false;
                                    isCheckingExport = false;
                                }
                            },
                            error: function() {
                                isCheckingExport = false;
                                isExportButtonDisabled = false;
                            }
                        });
                    }
                    
                    $('#jonakyds-export-now').on('click', function() {
                        if (isExportButtonDisabled || isPollActive) {
                            return false;
                        }
                        
                        isExportButtonDisabled = true;
                        
                        // Clear any existing interval
                        if (exportInterval) {
                            clearInterval(exportInterval);
                            exportInterval = null;
                        }
                        
                        const $button = $(this);
                        const $progressContainer = $('#jonakyds-progress-container');
                        const $completeMessage = $('#jonakyds-complete-message');
                        
                        $button.prop('disabled', true).html('<span class="jonakyds-spinner"></span><?php _e('Exporting...', 'jonakyds-nalda-sync'); ?>');
                        $progressContainer.addClass('active');
                        $completeMessage.removeClass('show complete-error');
                        
                        updateProgress(0, '<?php _e('Initializing...', 'jonakyds-nalda-sync'); ?>');
                        $('#jonakyds-stat-exported').text('0');
                        $('#jonakyds-stat-skipped').text('0');
                        $('#jonakyds-stat-total').text('-');
                        
                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'jonakyds_nalda_start_export',
                                nonce: '<?php echo wp_create_nonce('jonakyds_nalda_ajax_export'); ?>'
                            },
                            success: function(response) {
                                if (response.success) {
                                    currentExportId = response.data.export_id;
                                    pollProgress();
                                } else {
                                    if (response.data && response.data.active_export_id) {
                                        currentExportId = response.data.active_export_id;
                                        pollProgress();
                                    } else {
                                        showComplete(false, response.data.message || '<?php _e('Failed to start export', 'jonakyds-nalda-sync'); ?>');
                                        $button.prop('disabled', false).html('<?php _e('Export Now', 'jonakyds-nalda-sync'); ?>');
                                        $progressContainer.removeClass('active');
                                        isExportButtonDisabled = false;
                                        isPollActive = false;
                                    }
                                }
                            },
                            error: function() {
                                showComplete(false, '<?php _e('Failed to start export', 'jonakyds-nalda-sync'); ?>');
                                $button.prop('disabled', false).html('<?php _e('Export Now', 'jonakyds-nalda-sync'); ?>');
                                $progressContainer.removeClass('active');
                                isExportButtonDisabled = false;
                                isPollActive = false;
                            }
                        });
                    });
                    
                    function pollProgress() {
                        isPollActive = true;
                        exportInterval = setInterval(function() {
                            $.ajax({
                                url: ajaxurl,
                                type: 'POST',
                                data: {
                                    action: 'jonakyds_nalda_get_progress',
                                    nonce: '<?php echo wp_create_nonce('jonakyds_nalda_ajax_export'); ?>',
                                    export_id: currentExportId
                                },
                                success: function(response) {
                                    if (response.success) {
                                        const data = response.data;
                                        
                                        updateProgress(data.percent, data.message);
                                        
                                        if (data.exported !== undefined) {
                                            $('#jonakyds-stat-exported').text(data.exported);
                                        }
                                        if (data.skipped !== undefined) {
                                            $('#jonakyds-stat-skipped').text(data.skipped);
                                        }
                                        if (data.total !== undefined && data.total > 0) {
                                            $('#jonakyds-stat-total').text(data.total);
                                        }
                                        
                                        if (data.status === 'complete') {
                                            clearInterval(exportInterval);
                                            exportInterval = null;
                                            isPollActive = false;
                                            const $button = $('#jonakyds-export-now');
                                            const $progressContainer = $('#jonakyds-progress-container');
                                            
                                            isExportButtonDisabled = false;
                                            $button.prop('disabled', false).html('<?php _e('Export Now', 'jonakyds-nalda-sync'); ?>');
                                            
                                            setTimeout(() => {
                                                showComplete(true, data.message);
                                                $progressContainer.removeClass('active');
                                                
                                                setTimeout(() => {
                                                    location.reload();
                                                }, 2000);
                                            }, 500);
                                        } else if (data.status === 'error') {
                                            clearInterval(exportInterval);
                                            exportInterval = null;
                                            isPollActive = false;
                                            const $button = $('#jonakyds-export-now');
                                            const $progressContainer = $('#jonakyds-progress-container');
                                            
                                            isExportButtonDisabled = false;
                                            $button.prop('disabled', false).html('<?php _e('Export Now', 'jonakyds-nalda-sync'); ?>');
                                            showComplete(false, data.message || '<?php _e('Export failed', 'jonakyds-nalda-sync'); ?>');
                                            $progressContainer.removeClass('active');
                                        }
                                    }
                                },
                                error: function() {
                                    // On error, stop polling and reset state
                                    clearInterval(exportInterval);
                                    exportInterval = null;
                                    isPollActive = false;
                                    isExportButtonDisabled = false;
                                    const $button = $('#jonakyds-export-now');
                                    $button.prop('disabled', false).html('<?php _e('Export Now', 'jonakyds-nalda-sync'); ?>');
                                }
                            });
                        }, 1000);
                    }
                    
                    function updateProgress(percent, message) {
                        $('#jonakyds-progress-bar').css('width', percent + '%');
                        $('#jonakyds-progress-percent').text(percent + '%');
                        $('#jonakyds-progress-message').text(message);
                    }
                    
                    function showComplete(success, message) {
                        const $msg = $('#jonakyds-complete-message');
                        const icon = success ? '✓' : '✗';
                        $msg.html('<span class="jonakyds-complete-icon">' + icon + '</span>' + message);
                        $msg.addClass('show');
                        if (!success) {
                            $msg.addClass('complete-error');
                        }
                    }

                    // Toggle SSL option visibility based on connection type
                    function toggleSslOption() {
                        const ftpType = $('#jonakyds_nalda_sync_ftp_type').val();
                        if (ftpType === 'sftp') {
                            $('#jonakyds-ftp-ssl-row').hide();
                        } else {
                            $('#jonakyds-ftp-ssl-row').show();
                        }
                    }
                    
                    // Initial toggle and bind change event
                    toggleSslOption();
                    $('#jonakyds_nalda_sync_ftp_type').on('change', toggleSslOption);

                    // Test FTP/SFTP Connection
                    $('#jonakyds-test-sftp').on('click', function() {
                        const $btn = $(this);
                        const $result = $('#jonakyds-sftp-test-result');
                        
                        // Get current form values
                        const ftpType = $('#jonakyds_nalda_sync_ftp_type').val();
                        const server = $('#jonakyds_nalda_sync_ftp_server').val();
                        const port = $('#jonakyds_nalda_sync_ftp_port').val() || (ftpType === 'sftp' ? '22' : '21');
                        const username = $('#jonakyds_nalda_sync_ftp_username').val();
                        const password = $('#jonakyds_nalda_sync_ftp_password').val();
                        const path = $('#jonakyds_nalda_sync_ftp_path').val() || '/';
                        const ssl = $('#jonakyds_nalda_sync_ftp_ssl').is(':checked') ? 'yes' : 'no';
                        
                        // Validate required fields
                        if (!server || !username || !password) {
                            $result.removeClass('success error testing').addClass('error');
                            $result.text('<?php echo esc_js(__('Please fill in server, username, and password fields.', 'jonakyds-nalda-sync')); ?>');
                            return;
                        }
                        
                        // Show testing state
                        $btn.prop('disabled', true).text('<?php echo esc_js(__('Testing...', 'jonakyds-nalda-sync')); ?>');
                        $result.removeClass('success error').addClass('testing');
                        const connectingMsg = ftpType === 'sftp' ? '<?php echo esc_js(__('Connecting to SFTP server...', 'jonakyds-nalda-sync')); ?>' : '<?php echo esc_js(__('Connecting to FTP server...', 'jonakyds-nalda-sync')); ?>';
                        $result.text(connectingMsg);
                        
                        // Make AJAX request
                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'jonakyds_test_sftp_connection',
                                nonce: '<?php echo wp_create_nonce('jonakyds_test_sftp'); ?>',
                                ftp_type: ftpType,
                                server: server,
                                port: port,
                                username: username,
                                password: password,
                                path: path,
                                ssl: ssl
                            },
                            success: function(response) {
                                $btn.prop('disabled', false).text('<?php echo esc_js(__('Test Connection', 'jonakyds-nalda-sync')); ?>');
                                $result.removeClass('testing');
                                
                                if (response.success) {
                                    $result.addClass('success').text(response.data.message);
                                } else {
                                    $result.addClass('error').text(response.data.message);
                                }
                            },
                            error: function() {
                                $btn.prop('disabled', false).text('<?php echo esc_js(__('Test Connection', 'jonakyds-nalda-sync')); ?>');
                                $result.removeClass('testing').addClass('error');
                                $result.text('<?php echo esc_js(__('An error occurred while testing the connection.', 'jonakyds-nalda-sync')); ?>');
                            }
                        });
                    });
                });
                </script>

                <!-- Export Logs Card -->
                <div class="jonakyds-card">
                    <h2>
                        <?php _e('Export Logs', 'jonakyds-nalda-sync'); ?>
                        <?php if (!empty($logs)): ?>
                            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display: inline-block; float: right;">
                                <input type="hidden" name="action" value="jonakyds_clear_logs" />
                                <?php wp_nonce_field('jonakyds_clear_logs'); ?>
                                <button type="submit" class="button button-small"><?php _e('Clear Logs', 'jonakyds-nalda-sync'); ?></button>
                            </form>
                        <?php endif; ?>
                    </h2>
                    
                    <?php if (empty($logs)): ?>
                        <p><?php _e('No export logs available.', 'jonakyds-nalda-sync'); ?></p>
                    <?php else: ?>
                        <?php foreach (array_reverse($logs) as $log): ?>
                            <div class="jonakyds-log-entry <?php echo $log['success'] ? 'success' : 'log-error'; ?>">
                                <div class="jonakyds-log-timestamp">
                                    <?php 
                                    $timezone_string = get_option('timezone_string');
                                    if (empty($timezone_string)) {
                                        $timezone_string = 'UTC';
                                    }
                                    echo date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($log['timestamp']));
                                    echo ' (' . $timezone_string . ')';
                                    ?>
                                </div>
                                <div class="jonakyds-log-message">
                                    <strong><?php echo esc_html($log['message']); ?></strong>
                                </div>
                                <?php if (isset($log['exported']) || isset($log['skipped'])): ?>
                                    <div class="jonakyds-log-stats">
                                        <?php _e('Exported:', 'jonakyds-nalda-sync'); ?> <?php echo isset($log['exported']) ? $log['exported'] : 0; ?> |
                                        <?php _e('Skipped:', 'jonakyds-nalda-sync'); ?> <?php echo isset($log['skipped']) ? $log['skipped'] : 0; ?>
                                        <?php if (isset($log['sftp_upload']) && $log['sftp_upload']['attempted']): ?>
                                            | <?php _e('SFTP:', 'jonakyds-nalda-sync'); ?> 
                                            <?php if ($log['sftp_upload']['success']): ?>
                                                <span style="color: #46b450;">✓ <?php _e('Uploaded', 'jonakyds-nalda-sync'); ?></span>
                                            <?php else: ?>
                                                <span style="color: #dc3232;">✗ <?php _e('Failed', 'jonakyds-nalda-sync'); ?><?php echo !empty($log['sftp_upload']['error']) ? ' - ' . esc_html($log['sftp_upload']['error']) : ''; ?></span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (isset($log['skip_reasons']) && !empty(array_filter($log['skip_reasons']))): ?>
                                        <div class="jonakyds-log-skip-reasons" style="margin-top: 5px; font-size: 12px; color: #666;">
                                            <em><?php _e('Skip reasons:', 'jonakyds-nalda-sync'); ?></em>
                                            <?php if (!empty($log['skip_reasons']['no_gtin'])): ?>
                                                <span style="margin-left: 10px;"><?php printf(__('No GTIN: %d', 'jonakyds-nalda-sync'), $log['skip_reasons']['no_gtin']); ?></span>
                                            <?php endif; ?>
                                            <?php if (!empty($log['skip_reasons']['no_price'])): ?>
                                                <span style="margin-left: 10px;"><?php printf(__('No price: %d', 'jonakyds-nalda-sync'), $log['skip_reasons']['no_price']); ?></span>
                                            <?php endif; ?>
                                            <?php if (!empty($log['skip_reasons']['product_not_found'])): ?>
                                                <span style="margin-left: 10px;"><?php printf(__('Product not found: %d', 'jonakyds-nalda-sync'), $log['skip_reasons']['product_not_found']); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Handle manual export
     */
    public function handle_manual_export() {
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'jonakyds_export_now')) {
            wp_die(__('Security check failed.', 'jonakyds-nalda-sync'));
        }

        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have sufficient permissions.', 'jonakyds-nalda-sync'));
        }

        $result = Jonakyds_Nalda_CSV_Exporter::export_products();

        $redirect_url = add_query_arg(
            array(
                'page' => 'jonakyds-nalda-sync',
                'export_result' => $result['success'] ? 'success' : 'error',
                'export_message' => urlencode($result['message'])
            ),
            admin_url('admin.php')
        );

        wp_redirect($redirect_url);
        exit;
    }

    /**
     * Handle clear logs
     */
    public function handle_clear_logs() {
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'jonakyds_clear_logs')) {
            wp_die(__('Security check failed.', 'jonakyds-nalda-sync'));
        }

        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have sufficient permissions.', 'jonakyds-nalda-sync'));
        }

        Jonakyds_Nalda_CSV_Exporter::clear_logs();

        wp_redirect(admin_url('admin.php?page=jonakyds-nalda-sync'));
        exit;
    }

    /**
     * Handle FTP/SFTP connection test via AJAX
     */
    public function handle_test_sftp_connection() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'jonakyds_test_sftp')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'jonakyds-nalda-sync')));
        }

        // Check permissions
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('You do not have sufficient permissions.', 'jonakyds-nalda-sync')));
        }

        // Get parameters
        $ftp_type = isset($_POST['ftp_type']) ? sanitize_text_field($_POST['ftp_type']) : 'ftp';
        $server = isset($_POST['server']) ? sanitize_text_field($_POST['server']) : '';
        $port = isset($_POST['port']) ? absint($_POST['port']) : ($ftp_type === 'sftp' ? 22 : 21);
        $username = isset($_POST['username']) ? sanitize_text_field($_POST['username']) : '';
        $password = isset($_POST['password']) ? $_POST['password'] : '';
        $path = isset($_POST['path']) ? sanitize_text_field($_POST['path']) : '/';
        $ssl = isset($_POST['ssl']) && $_POST['ssl'] === 'yes';

        // Validate required fields
        if (empty($server) || empty($username) || empty($password)) {
            wp_send_json_error(array('message' => __('Server, username, and password are required.', 'jonakyds-nalda-sync')));
        }

        // Test based on connection type
        if ($ftp_type === 'sftp') {
            $this->test_sftp_connection($server, $port, $username, $password, $path);
        } else {
            $this->test_ftp_connection($server, $port, $username, $password, $path, $ssl);
        }
    }

    /**
     * Test SFTP connection
     */
    private function test_sftp_connection($server, $port, $username, $password, $path) {
        // Check if SSH2 extension is available
        if (!function_exists('ssh2_connect')) {
            wp_send_json_error(array('message' => __('SSH2 PHP extension is not installed. Please contact your hosting provider to enable it, or use standard FTP instead.', 'jonakyds-nalda-sync')));
        }

        // Try to connect
        $connection = @ssh2_connect($server, $port);
        if (!$connection) {
            wp_send_json_error(array('message' => sprintf(__('Could not connect to SFTP server: %s:%d', 'jonakyds-nalda-sync'), $server, $port)));
        }

        // Try to authenticate
        $auth_result = @ssh2_auth_password($connection, $username, $password);
        if (!$auth_result) {
            wp_send_json_error(array('message' => __('SFTP authentication failed. Please check your username and password.', 'jonakyds-nalda-sync')));
        }

        // Initialize SFTP subsystem
        $sftp = @ssh2_sftp($connection);
        if (!$sftp) {
            wp_send_json_error(array('message' => __('Could not initialize SFTP subsystem.', 'jonakyds-nalda-sync')));
        }

        // Check if the remote path exists and is accessible
        $path = trim($path, '/');
        if (empty($path)) {
            $check_path = '/';
        } else {
            $check_path = '/' . $path;
        }
        
        $stat = @ssh2_sftp_stat($sftp, $check_path);
        if ($stat === false) {
            wp_send_json_error(array('message' => sprintf(__('Connection successful, but remote path "%s" is not accessible or does not exist.', 'jonakyds-nalda-sync'), $check_path)));
        }

        // Success!
        wp_send_json_success(array('message' => __('SFTP connection successful! The server and credentials are working correctly.', 'jonakyds-nalda-sync')));
    }

    /**
     * Test FTP connection
     */
    private function test_ftp_connection($server, $port, $username, $password, $path, $ssl = false) {
        // Check if FTP extension is available
        if (!function_exists('ftp_connect')) {
            wp_send_json_error(array('message' => __('FTP PHP extension is not installed. Please contact your hosting provider to enable it.', 'jonakyds-nalda-sync')));
        }

        // Try to connect
        if ($ssl && function_exists('ftp_ssl_connect')) {
            $connection = @ftp_ssl_connect($server, $port, 30);
        } else {
            $connection = @ftp_connect($server, $port, 30);
        }
        
        if (!$connection) {
            $protocol = $ssl ? 'FTPS' : 'FTP';
            wp_send_json_error(array('message' => sprintf(__('Could not connect to %s server: %s:%d', 'jonakyds-nalda-sync'), $protocol, $server, $port)));
        }

        // Try to login
        $login_result = @ftp_login($connection, $username, $password);
        if (!$login_result) {
            @ftp_close($connection);
            wp_send_json_error(array('message' => __('FTP authentication failed. Please check your username and password.', 'jonakyds-nalda-sync')));
        }

        // Enable passive mode (better compatibility with firewalls)
        @ftp_pasv($connection, true);

        // Check if the remote path exists and is accessible
        $path = trim($path, '/');
        if (!empty($path)) {
            $chdir_result = @ftp_chdir($connection, '/' . $path);
            if (!$chdir_result) {
                @ftp_close($connection);
                wp_send_json_error(array('message' => sprintf(__('Connection successful, but remote path "/%s" is not accessible or does not exist.', 'jonakyds-nalda-sync'), $path)));
            }
        }

        // Close connection
        @ftp_close($connection);

        // Success!
        $protocol = $ssl ? 'FTPS' : 'FTP';
        wp_send_json_success(array('message' => sprintf(__('%s connection successful! The server and credentials are working correctly.', 'jonakyds-nalda-sync'), $protocol)));
    }
}
