<?php
/**
 * Plugin Name: Nalda Sync (JonakyDS)
 * Plugin URI: https://github.com/JonakyDS/jonakyds-nalda-sync
 * Description: Generate and export WooCommerce products to CSV for Nalda marketplace
 * Version: 1.0.8
 * Author: Jonaky Adhikary
 * Author URI: https://jonakyds.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: jonakyds-nalda-sync
 * Requires at least: 5.0
 * Requires PHP: 7.2
 * WC requires at least: 3.0
 * WC tested up to: 8.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('JONAKYDS_NALDA_SYNC_VERSION', '1.0.8');
define('JONAKYDS_NALDA_SYNC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('JONAKYDS_NALDA_SYNC_PLUGIN_URL', plugin_dir_url(__FILE__));
define('JONAKYDS_NALDA_SYNC_CSV_DIR', WP_CONTENT_DIR . '/nalda-exports/');
define('JONAKYDS_NALDA_SYNC_CSV_URL', content_url('/nalda-exports/'));

// Include required files
require_once JONAKYDS_NALDA_SYNC_PLUGIN_DIR . 'includes/class-csv-exporter.php';
require_once JONAKYDS_NALDA_SYNC_PLUGIN_DIR . 'includes/class-export-handler.php';
require_once JONAKYDS_NALDA_SYNC_PLUGIN_DIR . 'includes/class-admin.php';
require_once JONAKYDS_NALDA_SYNC_PLUGIN_DIR . 'includes/class-updater.php';

/**
 * Initialize the plugin
 */
function jonakyds_nalda_sync_init() {
    // Check if WooCommerce is active
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', 'jonakyds_nalda_sync_woocommerce_missing_notice');
        return;
    }

    // Initialize admin interface
    new Jonakyds_Nalda_Sync_Admin();
    
    // Initialize updater
    if (is_admin()) {
        new Jonakyds_Nalda_Sync_Updater(__FILE__);
    }
}
add_action('plugins_loaded', 'jonakyds_nalda_sync_init');

/**
 * Display admin notice if WooCommerce is not active
 */
function jonakyds_nalda_sync_woocommerce_missing_notice() {
    ?>
    <div class="notice notice-error">
        <p><?php _e('Nalda Sync (JonakyDS) requires WooCommerce to be installed and activated.', 'jonakyds-nalda-sync'); ?></p>
    </div>
    <?php
}

/**
 * Activation hook
 */
function jonakyds_nalda_sync_activate() {
    // Create export directory
    if (!file_exists(JONAKYDS_NALDA_SYNC_CSV_DIR)) {
        wp_mkdir_p(JONAKYDS_NALDA_SYNC_CSV_DIR);
    }
    
    // Create .htaccess for security (allow only CSV files)
    $htaccess_file = JONAKYDS_NALDA_SYNC_CSV_DIR . '.htaccess';
    if (!file_exists($htaccess_file)) {
        $htaccess_content = "# Allow access to CSV files only\n";
        $htaccess_content .= "<FilesMatch \"\\.(csv)$\">\n";
        $htaccess_content .= "    Order Allow,Deny\n";
        $htaccess_content .= "    Allow from all\n";
        $htaccess_content .= "</FilesMatch>\n";
        $htaccess_content .= "\n";
        $htaccess_content .= "# Deny access to everything else\n";
        $htaccess_content .= "<FilesMatch \"^(?!.*\\.csv$)\">\n";
        $htaccess_content .= "    Order Deny,Allow\n";
        $htaccess_content .= "    Deny from all\n";
        $htaccess_content .= "</FilesMatch>\n";
        file_put_contents($htaccess_file, $htaccess_content);
    }
    
    // Create index.php for security
    $index_file = JONAKYDS_NALDA_SYNC_CSV_DIR . 'index.php';
    if (!file_exists($index_file)) {
        file_put_contents($index_file, '<?php // Silence is golden');
    }
    
    // Set default options
    if (!get_option('jonakyds_nalda_sync_schedule')) {
        add_option('jonakyds_nalda_sync_schedule', 'hourly');
    }
    if (!get_option('jonakyds_nalda_sync_enabled')) {
        add_option('jonakyds_nalda_sync_enabled', 'no');
    }
    if (!get_option('jonakyds_nalda_sync_return_days')) {
        add_option('jonakyds_nalda_sync_return_days', '14');
    }
    if (!get_option('jonakyds_nalda_sync_delivery_days')) {
        add_option('jonakyds_nalda_sync_delivery_days', '1');
    }
    if (!get_option('jonakyds_nalda_sync_condition')) {
        add_option('jonakyds_nalda_sync_condition', 'new');
    }
    
    // Schedule cron job
    if (!wp_next_scheduled('jonakyds_nalda_sync_cron')) {
        $schedule = get_option('jonakyds_nalda_sync_schedule', 'hourly');
        wp_schedule_event(time() + 300, $schedule, 'jonakyds_nalda_sync_cron');
    }
}
register_activation_hook(__FILE__, 'jonakyds_nalda_sync_activate');

/**
 * Deactivation hook
 */
function jonakyds_nalda_sync_deactivate() {
    // Clear scheduled cron
    $timestamp = wp_next_scheduled('jonakyds_nalda_sync_cron');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'jonakyds_nalda_sync_cron');
    }
}
register_deactivation_hook(__FILE__, 'jonakyds_nalda_sync_deactivate');

/**
 * Add custom cron schedule for 10 minutes
 */
function jonakyds_nalda_sync_cron_schedules($schedules) {
    $schedules['every_10_minutes'] = array(
        'interval' => 600,
        'display' => __('Every 10 Minutes', 'jonakyds-nalda-sync')
    );
    return $schedules;
}
add_filter('cron_schedules', 'jonakyds_nalda_sync_cron_schedules');

/**
 * Handle CSV file download
 */
function jonakyds_nalda_sync_handle_download() {
    if (isset($_GET['jonakyds_nalda_download']) && $_GET['jonakyds_nalda_download'] === '1') {
        // Check user permissions
        if (!is_user_logged_in() || !current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have permission to download this file.', 'jonakyds-nalda-sync'));
        }
        
        $csv_file = JONAKYDS_NALDA_SYNC_CSV_DIR . 'nalda-products.csv';
        
        if (file_exists($csv_file)) {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="nalda-products.csv"');
            header('Content-Length: ' . filesize($csv_file));
            header('Cache-Control: no-cache, must-revalidate');
            header('Expires: 0');
            readfile($csv_file);
            exit;
        } else {
            wp_die(__('CSV file not found. Please generate the export first.', 'jonakyds-nalda-sync'));
        }
    }
}
add_action('init', 'jonakyds_nalda_sync_handle_download');
