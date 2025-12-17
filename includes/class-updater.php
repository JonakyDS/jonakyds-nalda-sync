<?php
/**
 * Plugin Updater Class
 *
 * Handles automatic plugin updates from GitHub
 * Follows WordPress plugin standards
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Prevent loading if already loaded
if (class_exists('Jonakyds_Nalda_Sync_Updater')) {
    return;
}

class Jonakyds_Nalda_Sync_Updater {

    /**
     * Plugin file path
     * 
     * @var string
     */
    private $plugin_file;

    /**
     * Plugin slug (folder name)
     * 
     * @var string
     */
    private $plugin_slug;

    /**
     * Plugin basename (folder/file.php)
     * 
     * @var string
     */
    private $basename;

    /**
     * Plugin data from headers
     * 
     * @var array
     */
    private $plugin_data;

    /**
     * GitHub username/organization
     * 
     * @var string
     */
    private $github_username = 'JonakyDS';

    /**
     * GitHub repository name
     * 
     * @var string
     */
    private $github_repository = 'jonakyds-nalda-sync';

    /**
     * Cache key for storing release info
     * 
     * @var string
     */
    private $cache_key = 'jonakyds_nalda_sync_github_release';

    /**
     * Cache duration in seconds (12 hours)
     * 
     * @var int
     */
    private $cache_duration = 43200;

    /**
     * Constructor
     * 
     * @param string $plugin_file Full path to the main plugin file
     */
    public function __construct($plugin_file) {
        $this->plugin_file = $plugin_file;
        $this->basename = plugin_basename($plugin_file);
        $this->plugin_slug = dirname($this->basename);
        
        // Ensure we have plugin data functions
        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $this->plugin_data = get_plugin_data($plugin_file);

        // Register hooks
        $this->init_hooks();
    }

    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        // Check for updates
        add_filter('pre_set_site_transient_update_plugins', array($this, 'check_update'));
        
        // Provide plugin information
        add_filter('plugins_api', array($this, 'plugins_api_filter'), 10, 3);
        
        // Handle source selection (rename directory from GitHub format)
        add_filter('upgrader_source_selection', array($this, 'fix_source_dir'), 10, 4);
        
        // Add action link to check for updates
        add_filter('plugin_action_links_' . $this->basename, array($this, 'plugin_action_links'));
        
        // Handle manual update check
        add_action('admin_init', array($this, 'handle_manual_update_check'));
        
        // Show admin notice after update check
        add_action('admin_notices', array($this, 'admin_notices'));
    }

    /**
     * Check for plugin updates
     * 
     * @param object $transient WordPress update transient
     * @return object Modified transient
     */
    public function check_update($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }

        // Get remote version info
        $remote_info = $this->get_remote_info();

        if (!$remote_info) {
            return $transient;
        }

        // Compare versions
        $current_version = $this->plugin_data['Version'];
        $remote_version = $remote_info['version'];

        if (version_compare($current_version, $remote_version, '<')) {
            $transient->response[$this->basename] = (object) array(
                'slug'         => $this->plugin_slug,
                'plugin'       => $this->basename,
                'new_version'  => $remote_version,
                'url'          => $remote_info['homepage'],
                'package'      => $remote_info['download_url'],
                'icons'        => array(),
                'banners'      => array(),
                'tested'       => $remote_info['tested'],
                'requires'     => $remote_info['requires'],
                'requires_php' => $remote_info['requires_php'],
            );
        } else {
            // No update available - add to no_update to show "up to date"
            $transient->no_update[$this->basename] = (object) array(
                'slug'         => $this->plugin_slug,
                'plugin'       => $this->basename,
                'new_version'  => $current_version,
                'url'          => $remote_info['homepage'],
                'package'      => '',
            );
        }

        return $transient;
    }

    /**
     * Get remote plugin information from GitHub
     * 
     * @param bool $force_check Force check, bypassing cache
     * @return array|false Remote info or false on failure
     */
    private function get_remote_info($force_check = false) {
        // Check cache first
        if (!$force_check) {
            $cached = get_transient($this->cache_key);
            if ($cached !== false) {
                return $cached;
            }
        }

        // Build GitHub API URL
        $api_url = sprintf(
            'https://api.github.com/repos/%s/%s/releases/latest',
            $this->github_username,
            $this->github_repository
        );

        // Make API request
        $response = wp_remote_get($api_url, array(
            'timeout' => 10,
            'headers' => array(
                'Accept'     => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url(),
            ),
        ));

        // Handle errors
        if (is_wp_error($response)) {
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (empty($data) || !isset($data['tag_name'])) {
            return false;
        }

        // Parse version from tag (remove 'v' prefix if present)
        $version = ltrim($data['tag_name'], 'v');

        // Build download URL
        $download_url = isset($data['zipball_url']) ? $data['zipball_url'] : '';
        
        // Look for a .zip asset first (preferred)
        if (!empty($data['assets'])) {
            foreach ($data['assets'] as $asset) {
                if (preg_match('/\.zip$/i', $asset['name'])) {
                    $download_url = $asset['browser_download_url'];
                    break;
                }
            }
        }

        // Build info array
        $info = array(
            'version'      => $version,
            'download_url' => $download_url,
            'homepage'     => $data['html_url'],
            'changelog'    => isset($data['body']) ? $data['body'] : '',
            'tested'       => '6.4',
            'requires'     => '5.0',
            'requires_php' => '7.2',
            'last_updated' => isset($data['published_at']) ? $data['published_at'] : '',
        );

        // Cache the result
        set_transient($this->cache_key, $info, $this->cache_duration);

        return $info;
    }

    /**
     * Filter plugin API for plugin information popup
     * 
     * @param false|object|array $result The result object or array
     * @param string $action The type of information being requested
     * @param object $args Plugin API arguments
     * @return false|object Modified result or false
     */
    public function plugins_api_filter($result, $action, $args) {
        if ($action !== 'plugin_information') {
            return $result;
        }

        if (!isset($args->slug) || $args->slug !== $this->plugin_slug) {
            return $result;
        }

        $remote_info = $this->get_remote_info();

        if (!$remote_info) {
            return $result;
        }

        $plugin_info = new stdClass();
        $plugin_info->name = $this->plugin_data['Name'];
        $plugin_info->slug = $this->plugin_slug;
        $plugin_info->version = $remote_info['version'];
        $plugin_info->author = $this->plugin_data['Author'];
        $plugin_info->homepage = $this->plugin_data['PluginURI'];
        $plugin_info->requires = $remote_info['requires'];
        $plugin_info->tested = $remote_info['tested'];
        $plugin_info->requires_php = $remote_info['requires_php'];
        $plugin_info->downloaded = 0;
        $plugin_info->last_updated = $remote_info['last_updated'];
        $plugin_info->sections = array(
            'description' => $this->plugin_data['Description'],
            'changelog'   => $this->format_changelog($remote_info['changelog']),
        );
        $plugin_info->download_link = $remote_info['download_url'];

        return $plugin_info;
    }

    /**
     * Format changelog from markdown to HTML
     * 
     * @param string $changelog Raw changelog text
     * @return string Formatted HTML
     */
    private function format_changelog($changelog) {
        if (empty($changelog)) {
            return '<p>No changelog available.</p>';
        }

        // Convert markdown headers
        $changelog = preg_replace('/^### (.*)$/m', '<h4>$1</h4>', $changelog);
        $changelog = preg_replace('/^## (.*)$/m', '<h3>$1</h3>', $changelog);
        $changelog = preg_replace('/^# (.*)$/m', '<h2>$1</h2>', $changelog);
        
        // Convert markdown lists
        $changelog = preg_replace('/^\* (.*)$/m', '<li>$1</li>', $changelog);
        $changelog = preg_replace('/^- (.*)$/m', '<li>$1</li>', $changelog);
        
        // Wrap consecutive list items in ul tags
        $changelog = preg_replace('/(<li>.*<\/li>\n)+/', '<ul>$0</ul>', $changelog);
        
        // Convert newlines to paragraphs for non-list content
        $changelog = wpautop($changelog);

        return $changelog;
    }

    /**
     * Fix source directory name after download from GitHub
     * 
     * GitHub zipball extracts to a folder like 'username-repo-hash'
     * This needs to be renamed to match the plugin slug
     * 
     * @param string $source File source location
     * @param string $remote_source Remote file source location
     * @param WP_Upgrader $upgrader WP_Upgrader instance
     * @param array $hook_extra Extra arguments passed to hooked filters
     * @return string|WP_Error Fixed source or error
     */
    public function fix_source_dir($source, $remote_source, $upgrader, $hook_extra) {
        global $wp_filesystem;

        // Only process our plugin
        if (!isset($hook_extra['plugin']) || $hook_extra['plugin'] !== $this->basename) {
            return $source;
        }

        // Get the source directory name (without trailing slash)
        $source_dir = untrailingslashit($source);
        
        // Expected directory path
        $expected_dir = trailingslashit($remote_source) . $this->plugin_slug;

        // If source is already correct, return it
        if ($source_dir === $expected_dir || basename($source_dir) === $this->plugin_slug) {
            return $source;
        }

        // Check if the expected directory already exists and remove it
        if ($wp_filesystem->exists($expected_dir)) {
            $wp_filesystem->delete($expected_dir, true);
        }

        // Rename the directory from GitHub format to plugin slug
        if ($wp_filesystem->move($source_dir, $expected_dir, true)) {
            return trailingslashit($expected_dir);
        }

        return new WP_Error(
            'rename_failed',
            sprintf(
                __('Unable to rename the update directory. Source: %s, Expected: %s', 'jonakyds-nalda-sync'),
                $source_dir,
                $expected_dir
            )
        );
    }

    /**
     * Add plugin action links
     * 
     * @param array $links Existing links
     * @return array Modified links
     */
    public function plugin_action_links($links) {
        $check_update_url = wp_nonce_url(
            add_query_arg(
                array(
                    'jonakyds_nalda_check_update' => '1',
                ),
                admin_url('plugins.php')
            ),
            'jonakyds_nalda_check_update'
        );

        $links['check_update'] = sprintf(
            '<a href="%s">%s</a>',
            esc_url($check_update_url),
            __('Check for updates', 'jonakyds-nalda-sync')
        );

        return $links;
    }

    /**
     * Handle manual update check
     */
    public function handle_manual_update_check() {
        if (!isset($_GET['jonakyds_nalda_check_update']) || $_GET['jonakyds_nalda_check_update'] !== '1') {
            return;
        }

        if (!wp_verify_nonce($_GET['_wpnonce'], 'jonakyds_nalda_check_update')) {
            return;
        }

        if (!current_user_can('update_plugins')) {
            return;
        }

        // Clear cached release info
        delete_transient($this->cache_key);

        // Force check for updates
        $remote_info = $this->get_remote_info(true);

        // Clear update transient to force WordPress to recheck
        delete_site_transient('update_plugins');

        // Set flag for admin notice
        if ($remote_info) {
            $current_version = $this->plugin_data['Version'];
            $remote_version = $remote_info['version'];
            
            if (version_compare($current_version, $remote_version, '<')) {
                set_transient('jonakyds_nalda_sync_update_available', $remote_version, 60);
            } else {
                set_transient('jonakyds_nalda_sync_up_to_date', true, 60);
            }
        } else {
            set_transient('jonakyds_nalda_sync_update_check_failed', true, 60);
        }

        // Redirect back to plugins page
        wp_redirect(admin_url('plugins.php'));
        exit;
    }

    /**
     * Show admin notices for update checks
     */
    public function admin_notices() {
        if (get_transient('jonakyds_nalda_sync_update_available')) {
            $version = get_transient('jonakyds_nalda_sync_update_available');
            delete_transient('jonakyds_nalda_sync_update_available');
            ?>
            <div class="notice notice-info is-dismissible">
                <p>
                    <?php 
                    printf(
                        __('A new version of Nalda Sync (JonakyDS) is available! Version %s. Please update from the plugins page.', 'jonakyds-nalda-sync'),
                        '<strong>' . esc_html($version) . '</strong>'
                    ); 
                    ?>
                </p>
            </div>
            <?php
        }

        if (get_transient('jonakyds_nalda_sync_up_to_date')) {
            delete_transient('jonakyds_nalda_sync_up_to_date');
            ?>
            <div class="notice notice-success is-dismissible">
                <p><?php _e('Nalda Sync (JonakyDS) is up to date!', 'jonakyds-nalda-sync'); ?></p>
            </div>
            <?php
        }

        if (get_transient('jonakyds_nalda_sync_update_check_failed')) {
            delete_transient('jonakyds_nalda_sync_update_check_failed');
            ?>
            <div class="notice notice-error is-dismissible">
                <p><?php _e('Failed to check for updates. Please try again later.', 'jonakyds-nalda-sync'); ?></p>
            </div>
            <?php
        }
    }
}
