<?php
/**
 * Export Handler with AJAX Progress
 *
 * Handles real-time CSV export with progress updates via AJAX polling
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class Jonakyds_Export_Handler {

    /**
     * Start async export
     */
    public static function start_export() {
        // Security check
        check_ajax_referer('jonakyds_ajax_export', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'jonakyds-nalda-sync')));
        }

        // Check if there's already an active export
        $existing_export_id = get_option('jonakyds_nalda_active_export_id');
        if ($existing_export_id) {
            $existing_progress = get_transient('jonakyds_nalda_export_progress_' . $existing_export_id);
            if ($existing_progress && ($existing_progress['status'] === 'running' || $existing_progress['status'] === 'init')) {
                wp_send_json_error(array(
                    'message' => __('An export is already in progress. Please wait for it to complete.', 'jonakyds-nalda-sync'),
                    'active_export_id' => $existing_export_id
                ));
                return;
            }
        }

        // Generate unique export ID
        $export_id = uniqid('export_', true);
        
        // Store as active export
        update_option('jonakyds_nalda_active_export_id', $export_id, false);
        
        // Initialize progress
        self::update_progress($export_id, array(
            'status' => 'running',
            'step' => 'init',
            'percent' => 0,
            'message' => __('Starting export...', 'jonakyds-nalda-sync'),
            'exported' => 0,
            'skipped' => 0,
            'total' => 0
        ));

        // Trigger background process via non-blocking HTTP request
        self::trigger_background_export($export_id);

        wp_send_json_success(array('export_id' => $export_id));
    }

    /**
     * Get export progress
     */
    public static function get_progress() {
        check_ajax_referer('jonakyds_ajax_export', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'jonakyds-nalda-sync')));
        }

        $export_id = isset($_POST['export_id']) ? sanitize_text_field($_POST['export_id']) : '';
        
        if (empty($export_id)) {
            wp_send_json_error(array('message' => __('Invalid export ID', 'jonakyds-nalda-sync')));
        }

        $progress = get_transient('jonakyds_nalda_export_progress_' . $export_id);
        
        if ($progress === false) {
            wp_send_json_error(array('message' => __('Progress not found', 'jonakyds-nalda-sync')));
        }

        wp_send_json_success($progress);
    }

    /**
     * Get active export ID
     */
    public static function get_active_export() {
        check_ajax_referer('jonakyds_ajax_export', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'jonakyds-nalda-sync')));
        }

        $active_export_id = get_option('jonakyds_nalda_active_export_id');
        
        if (!$active_export_id) {
            wp_send_json_success(array('active' => false));
            return;
        }

        $progress = get_transient('jonakyds_nalda_export_progress_' . $active_export_id);
        
        if ($progress === false || $progress['status'] === 'complete' || $progress['status'] === 'error') {
            delete_option('jonakyds_nalda_active_export_id');
            wp_send_json_success(array('active' => false));
            return;
        }

        wp_send_json_success(array(
            'active' => true,
            'export_id' => $active_export_id,
            'progress' => $progress
        ));
    }

    /**
     * Background export process
     */
    public static function background_export($export_id) {
        // Increase time limit and memory
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        // Get export settings
        $settings = array(
            'country' => get_option('jonakyds_nalda_sync_country', 'CH'),
            'currency' => get_option('jonakyds_nalda_sync_currency', 'CHF'),
            'tax_rate' => get_option('jonakyds_nalda_sync_tax_rate', '8.1'),
            'return_days' => get_option('jonakyds_nalda_sync_return_days', '14'),
            'delivery_days' => get_option('jonakyds_nalda_sync_delivery_days', '1'),
            'condition' => get_option('jonakyds_nalda_sync_condition', 'new'),
            'language' => get_option('jonakyds_nalda_sync_language', ''),
            'brand' => get_option('jonakyds_nalda_sync_default_brand', ''),
        );

        // Ensure export directory exists
        if (!file_exists(JONAKYDS_NALDA_SYNC_CSV_DIR)) {
            wp_mkdir_p(JONAKYDS_NALDA_SYNC_CSV_DIR);
        }

        // Update progress
        self::update_progress($export_id, array(
            'status' => 'running',
            'step' => 'counting',
            'percent' => 5,
            'message' => __('Counting products...', 'jonakyds-nalda-sync')
        ));

        // Get product count
        $args = array(
            'status' => 'publish',
            'limit' => -1,
            'type' => array('simple', 'variable'),
            'return' => 'ids',
        );

        $product_ids = wc_get_products($args);
        $total_products = count($product_ids);

        if ($total_products === 0) {
            self::update_progress($export_id, array(
                'status' => 'error',
                'message' => __('No products found to export.', 'jonakyds-nalda-sync')
            ));
            delete_option('jonakyds_nalda_active_export_id');
            return;
        }

        self::update_progress($export_id, array(
            'status' => 'running',
            'step' => 'preparing',
            'percent' => 10,
            'message' => sprintf(__('Found %d products. Preparing export...', 'jonakyds-nalda-sync'), $total_products),
            'total' => $total_products
        ));

        // Create CSV file
        $temp_file = JONAKYDS_NALDA_SYNC_CSV_DIR . 'nalda-products-temp.csv';
        $final_file = JONAKYDS_NALDA_SYNC_CSV_DIR . 'nalda-products.csv';

        $handle = fopen($temp_file, 'w');
        if (!$handle) {
            self::update_progress($export_id, array(
                'status' => 'error',
                'message' => __('Failed to create CSV file.', 'jonakyds-nalda-sync')
            ));
            delete_option('jonakyds_nalda_active_export_id');
            return;
        }

        // Add BOM for UTF-8
        fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));

        // Write headers
        fputcsv($handle, Jonakyds_Nalda_CSV_Exporter::get_csv_headers());

        // Disable object cache
        wp_suspend_cache_addition(true);

        // Export products with progress
        $exported = 0;
        $skipped = 0;
        $processed = 0;
        $batch_size = 25;
        $progress_start = 15;
        $progress_end = 95;

        foreach ($product_ids as $product_id) {
            $product = wc_get_product($product_id);
            
            if (!$product) {
                $skipped++;
                $processed++;
                continue;
            }

            // Handle variable products - export each variation
            if ($product->is_type('variable')) {
                $variations = $product->get_available_variations();
                
                foreach ($variations as $variation_data) {
                    $variation = wc_get_product($variation_data['variation_id']);
                    if ($variation) {
                        $row = self::build_product_row($variation, $settings, $product);
                        if ($row) {
                            fputcsv($handle, $row);
                            $exported++;
                        } else {
                            $skipped++;
                        }
                    }
                }
            } else {
                // Simple product
                $row = self::build_product_row($product, $settings);
                if ($row) {
                    fputcsv($handle, $row);
                    $exported++;
                } else {
                    $skipped++;
                }
            }

            $processed++;

            // Update progress every batch
            if ($processed % $batch_size === 0 || $processed === $total_products) {
                $percent = $progress_start + (($processed / $total_products) * ($progress_end - $progress_start));
                
                self::update_progress($export_id, array(
                    'status' => 'running',
                    'step' => 'exporting',
                    'percent' => round($percent),
                    'message' => sprintf(__('Exporting products (%d/%d)...', 'jonakyds-nalda-sync'), $processed, $total_products),
                    'exported' => $exported,
                    'skipped' => $skipped,
                    'total' => $total_products
                ));

                // Clear product cache
                wp_cache_flush();
            }

            // Clean up memory
            wc_delete_product_transients($product_id);
        }

        fclose($handle);

        // Re-enable cache
        wp_suspend_cache_addition(false);

        // Finalizing
        self::update_progress($export_id, array(
            'status' => 'running',
            'step' => 'finalizing',
            'percent' => 98,
            'message' => __('Finalizing export...', 'jonakyds-nalda-sync'),
            'exported' => $exported,
            'skipped' => $skipped,
            'total' => $total_products
        ));

        // Replace old file with new one
        if (file_exists($final_file)) {
            unlink($final_file);
        }
        rename($temp_file, $final_file);

        // Update last export time
        update_option('jonakyds_nalda_sync_last_export', current_time('mysql'));

        // Log the export
        $result = array(
            'success' => true,
            'message' => sprintf(
                __('CSV export completed. Exported: %d products, Skipped: %d', 'jonakyds-nalda-sync'),
                $exported,
                $skipped
            ),
            'exported' => $exported,
            'skipped' => $skipped,
            'errors' => array()
        );
        Jonakyds_Nalda_CSV_Exporter::log_export($result);

        // Complete
        self::update_progress($export_id, array(
            'status' => 'complete',
            'step' => 'done',
            'percent' => 100,
            'message' => $result['message'],
            'exported' => $exported,
            'skipped' => $skipped,
            'total' => $total_products
        ));

        delete_option('jonakyds_nalda_active_export_id');
    }

    /**
     * Build a product row for CSV (same as CSV Exporter but for background process)
     */
    private static function build_product_row($product, $settings, $parent = null) {
        // Get GTIN (EAN/ISBN/UPC) - required field
        $gtin = self::get_product_gtin($product, $parent);
        
        // Skip products without GTIN unless configured to export them
        $require_gtin = get_option('jonakyds_nalda_sync_require_gtin', 'yes');
        if ($require_gtin === 'yes' && empty($gtin)) {
            return false;
        }

        // Get product title
        $title = $parent ? $parent->get_name() : $product->get_name();
        
        // Add variation attributes to title
        if ($product->is_type('variation')) {
            $attributes = $product->get_variation_attributes();
            $attr_parts = array();
            foreach ($attributes as $key => $value) {
                if (!empty($value)) {
                    $attr_parts[] = $value;
                }
            }
            if (!empty($attr_parts)) {
                $title .= ' - ' . implode(', ', $attr_parts);
            }
        }

        // Get price
        $price = $product->get_price();
        if (empty($price) || $price <= 0) {
            return false;
        }

        // Get stock quantity
        $stock = $product->get_stock_quantity();
        if ($stock === null) {
            $stock = $product->is_in_stock() ? 999 : 0;
        }

        // Get main image
        $image_id = $product->get_image_id();
        if (!$image_id && $parent) {
            $image_id = $parent->get_image_id();
        }
        $main_image = $image_id ? wp_get_attachment_url($image_id) : '';

        // Get gallery images
        $gallery_ids = $product->get_gallery_image_ids();
        if (empty($gallery_ids) && $parent) {
            $gallery_ids = $parent->get_gallery_image_ids();
        }
        $gallery_images = array();
        foreach ($gallery_ids as $idx => $img_id) {
            if ($idx >= 4) break;
            $gallery_images[] = wp_get_attachment_url($img_id);
        }

        // Get description
        $description = $parent ? $parent->get_description() : $product->get_description();
        if (empty($description)) {
            $description = $parent ? $parent->get_short_description() : $product->get_short_description();
        }
        $description = wp_strip_all_tags($description);
        $description = html_entity_decode($description, ENT_QUOTES, 'UTF-8');

        // Get dimensions
        $length = $product->get_length();
        $width = $product->get_width();
        $height = $product->get_height();
        $weight = $product->get_weight();

        $dimension_unit = get_option('woocommerce_dimension_unit', 'cm');
        $weight_unit = get_option('woocommerce_weight_unit', 'kg');

        $length_mm = self::convert_to_mm($length, $dimension_unit);
        $width_mm = self::convert_to_mm($width, $dimension_unit);
        $height_mm = self::convert_to_mm($height, $dimension_unit);
        $weight_g = self::convert_to_g($weight, $weight_unit);

        // Get categories
        $categories = self::get_product_categories($product, $parent);

        // Get brand
        $brand = self::get_product_brand($product, $parent);
        if (empty($brand)) {
            $brand = $settings['brand'];
        }

        // Get color and size
        $colour = self::get_product_attribute($product, 'color', $parent);
        if (empty($colour)) {
            $colour = self::get_product_attribute($product, 'colour', $parent);
        }
        $size = self::get_product_attribute($product, 'size', $parent);

        // Build the row
        return array(
            $gtin,
            $title,
            $settings['country'],
            $settings['condition'],
            number_format((float)$price, 2, '.', ''),
            $settings['tax_rate'],
            $settings['currency'],
            $settings['delivery_days'],
            (int)$stock,
            $settings['return_days'],
            $main_image,
            $brand,
            $categories['primary'],
            '',
            $categories['seller'],
            $description,
            $length_mm,
            $width_mm,
            $height_mm,
            $weight_g,
            '',
            '',
            '',
            '',
            '',
            $size,
            $colour,
            isset($gallery_images[0]) ? $gallery_images[0] : '',
            isset($gallery_images[1]) ? $gallery_images[1] : '',
            isset($gallery_images[2]) ? $gallery_images[2] : '',
            isset($gallery_images[3]) ? $gallery_images[3] : '',
            '',
            '',
            $settings['language'],
            '',
            '',
            '',
        );
    }

    /**
     * Helper methods - duplicated for background process isolation
     */
    private static function get_product_gtin($product, $parent = null) {
        $gtin_keys = array('_gtin', '_ean', '_isbn', '_upc', '_barcode', 'gtin', 'ean', 'isbn', 'upc', 'barcode', '_global_unique_id');

        foreach ($gtin_keys as $key) {
            $gtin = $product->get_meta($key);
            if (!empty($gtin)) {
                return $gtin;
            }
        }

        if ($parent) {
            foreach ($gtin_keys as $key) {
                $gtin = $parent->get_meta($key);
                if (!empty($gtin)) {
                    return $gtin;
                }
            }
        }

        $sku = $product->get_sku();
        if (!empty($sku) && preg_match('/^[0-9]{8,14}$/', $sku)) {
            return $sku;
        }

        return '';
    }

    private static function get_product_categories($product, $parent = null) {
        $product_id = $parent ? $parent->get_id() : $product->get_id();
        $terms = get_the_terms($product_id, 'product_cat');
        
        $result = array('primary' => '', 'seller' => '');

        if (!$terms || is_wp_error($terms)) {
            return $result;
        }

        $cat_paths = array();
        foreach ($terms as $term) {
            $path = self::get_category_path($term);
            $cat_paths[] = $path;
        }

        if (!empty($cat_paths)) {
            $result['primary'] = $cat_paths[0];
            $result['seller'] = implode(' > ', array_unique($cat_paths));
        }

        return $result;
    }

    private static function get_category_path($term) {
        $path = array($term->name);
        
        while ($term->parent != 0) {
            $term = get_term($term->parent, 'product_cat');
            if (!$term || is_wp_error($term)) {
                break;
            }
            array_unshift($path, $term->name);
        }
        
        return implode(' > ', $path);
    }

    private static function get_product_brand($product, $parent = null) {
        $product_id = $parent ? $parent->get_id() : $product->get_id();
        $brand_taxonomies = array('product_brand', 'pa_brand', 'brand', 'pwb-brand');
        
        foreach ($brand_taxonomies as $taxonomy) {
            if (taxonomy_exists($taxonomy)) {
                $terms = get_the_terms($product_id, $taxonomy);
                if ($terms && !is_wp_error($terms)) {
                    return $terms[0]->name;
                }
            }
        }

        $brand_meta_keys = array('_brand', 'brand', '_product_brand');
        foreach ($brand_meta_keys as $key) {
            $brand = $product->get_meta($key);
            if (!empty($brand)) {
                return $brand;
            }
        }

        return '';
    }

    private static function get_product_attribute($product, $attribute_name, $parent = null) {
        if ($product->is_type('variation')) {
            $attr = $product->get_attribute('pa_' . $attribute_name);
            if (!empty($attr)) return $attr;
            $attr = $product->get_attribute($attribute_name);
            if (!empty($attr)) return $attr;
        }

        $check_product = $parent ?: $product;
        $attr = $check_product->get_attribute('pa_' . $attribute_name);
        if (!empty($attr)) return $attr;
        $attr = $check_product->get_attribute($attribute_name);
        if (!empty($attr)) return $attr;

        return '';
    }

    private static function convert_to_mm($value, $unit) {
        if (empty($value)) return '';
        $value = (float)$value;
        
        switch ($unit) {
            case 'm': return round($value * 1000);
            case 'cm': return round($value * 10);
            case 'mm': return round($value);
            case 'in': return round($value * 25.4);
            case 'yd': return round($value * 914.4);
            default: return round($value * 10);
        }
    }

    private static function convert_to_g($value, $unit) {
        if (empty($value)) return '';
        $value = (float)$value;
        
        switch ($unit) {
            case 'kg': return round($value * 1000);
            case 'g': return round($value);
            case 'lbs': return round($value * 453.592);
            case 'oz': return round($value * 28.3495);
            default: return round($value * 1000);
        }
    }

    /**
     * Update progress transient
     */
    public static function update_progress($export_id, $data) {
        $existing = get_transient('jonakyds_nalda_export_progress_' . $export_id);
        
        if ($existing) {
            $data = array_merge($existing, $data);
        }
        
        set_transient('jonakyds_nalda_export_progress_' . $export_id, $data, HOUR_IN_SECONDS);
    }

    /**
     * Trigger background export via non-blocking HTTP request
     */
    private static function trigger_background_export($export_id) {
        $url = add_query_arg(array(
            'action' => 'jonakyds_run_background_export',
            'export_id' => $export_id,
            'nonce' => wp_create_nonce('jonakyds_background_export_' . $export_id)
        ), admin_url('admin-ajax.php'));

        wp_remote_post($url, array(
            'timeout' => 0.01,
            'blocking' => false,
            'sslverify' => false,
            'cookies' => $_COOKIE,
        ));
    }

    /**
     * Handle background export AJAX request
     */
    public static function run_background_export() {
        // Verify nonce
        $export_id = isset($_REQUEST['export_id']) ? sanitize_text_field($_REQUEST['export_id']) : '';
        $nonce = isset($_REQUEST['nonce']) ? $_REQUEST['nonce'] : '';
        
        if (empty($export_id) || !wp_verify_nonce($nonce, 'jonakyds_background_export_' . $export_id)) {
            wp_die('Invalid request');
        }

        // Close connection and continue in background
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } else {
            // For non-FastCGI environments
            ignore_user_abort(true);
            if (ob_get_level()) {
                ob_end_clean();
            }
            header('Connection: close');
            header('Content-Length: 0');
            flush();
        }

        // Run the export
        self::background_export($export_id);
        
        exit;
    }
}

// Register AJAX handlers
add_action('wp_ajax_jonakyds_start_export', array('Jonakyds_Export_Handler', 'start_export'));
add_action('wp_ajax_jonakyds_get_progress', array('Jonakyds_Export_Handler', 'get_progress'));
add_action('wp_ajax_jonakyds_get_active_export', array('Jonakyds_Export_Handler', 'get_active_export'));
add_action('wp_ajax_jonakyds_run_background_export', array('Jonakyds_Export_Handler', 'run_background_export'));
add_action('wp_ajax_nopriv_jonakyds_run_background_export', array('Jonakyds_Export_Handler', 'run_background_export'));

// Register cron export
add_action('jonakyds_nalda_sync_cron', function() {
    $enabled = get_option('jonakyds_nalda_sync_enabled', 'no');
    if ($enabled === 'yes') {
        Jonakyds_Nalda_CSV_Exporter::export_products();
    }
});
