<?php
/**
 * CSV Exporter Class
 *
 * Handles exporting WooCommerce products to Nalda CSV format
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class Jonakyds_Nalda_CSV_Exporter {

    /**
     * CSV Headers matching Nalda format
     */
    private static $csv_headers = array(
        'gtin',
        'title',
        'country',
        'condition',
        'price',
        'tax',
        'currency',
        'delivery_time_days',
        'stock',
        'return_days',
        'main_image_url',
        'brand',
        'category',
        'google_category',
        'seller_category',
        'description',
        'length_mm',
        'width_mm',
        'height_mm',
        'weight_g',
        'shipping_length_mm',
        'shipping_width_mm',
        'shipping_height_mm',
        'shipping_weight_g',
        'volume_ml',
        'size',
        'colour',
        'image_2_url',
        'image_3_url',
        'image_4_url',
        'image_5_url',
        'delete_product',
        'author',
        'language',
        'format',
        'year',
        'publisher'
    );

    /**
     * Export products to CSV
     *
     * @return array Result of the export operation
     */
    public static function export_products() {
        // Increase time limit and memory for large exports
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        $result = array(
            'success' => false,
            'message' => '',
            'exported' => 0,
            'skipped' => 0,
            'errors' => array()
        );

        // Ensure export directory exists
        if (!file_exists(JONAKYDS_NALDA_SYNC_CSV_DIR)) {
            wp_mkdir_p(JONAKYDS_NALDA_SYNC_CSV_DIR);
        }

        // Create temporary file
        $temp_file = JONAKYDS_NALDA_SYNC_CSV_DIR . 'nalda-products-temp.csv';
        $final_file = JONAKYDS_NALDA_SYNC_CSV_DIR . 'nalda-products.csv';

        // Open file for writing
        $handle = fopen($temp_file, 'w');
        if (!$handle) {
            $result['message'] = __('Failed to create CSV file.', 'jonakyds-nalda-sync');
            return $result;
        }

        // Add BOM for UTF-8
        fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));

        // Write headers
        fputcsv($handle, self::$csv_headers);

        // Get plugin settings
        $settings = self::get_export_settings();

        // Disable object cache to prevent memory issues
        wp_suspend_cache_addition(true);

        // Get all published products
        $args = array(
            'status' => 'publish',
            'limit' => -1,
            'type' => array('simple', 'variable'),
            'return' => 'ids',
        );

        $product_ids = wc_get_products($args);

        foreach ($product_ids as $product_id) {
            $product = wc_get_product($product_id);
            
            if (!$product) {
                $result['skipped']++;
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
                            $result['exported']++;
                        } else {
                            $result['skipped']++;
                        }
                    }
                }
            } else {
                // Simple product
                $row = self::build_product_row($product, $settings);
                if ($row) {
                    fputcsv($handle, $row);
                    $result['exported']++;
                } else {
                    $result['skipped']++;
                }
            }

            // Clear product cache
            wc_delete_product_transients($product_id);
        }

        fclose($handle);

        // Re-enable cache
        wp_suspend_cache_addition(false);

        // Replace old file with new one
        if (file_exists($final_file)) {
            unlink($final_file);
        }
        rename($temp_file, $final_file);

        // Update last export time
        update_option('jonakyds_nalda_sync_last_export', current_time('mysql'));

        $result['success'] = true;
        $result['message'] = sprintf(
            __('CSV export completed. Exported: %d products, Skipped: %d', 'jonakyds-nalda-sync'),
            $result['exported'],
            $result['skipped']
        );

        // Log the export
        self::log_export($result);

        return $result;
    }

    /**
     * Get export settings
     *
     * @return array Settings array
     */
    private static function get_export_settings() {
        return array(
            'country' => get_option('jonakyds_nalda_sync_country', 'CH'),
            'currency' => get_option('jonakyds_nalda_sync_currency', 'CHF'),
            'tax_rate' => get_option('jonakyds_nalda_sync_tax_rate', '8.1'),
            'return_days' => get_option('jonakyds_nalda_sync_return_days', '14'),
            'delivery_days' => get_option('jonakyds_nalda_sync_delivery_days', '1'),
            'condition' => get_option('jonakyds_nalda_sync_condition', 'new'),
            'language' => get_option('jonakyds_nalda_sync_language', ''),
            'brand' => get_option('jonakyds_nalda_sync_default_brand', ''),
        );
    }

    /**
     * Build a product row for CSV
     *
     * @param WC_Product $product The product object
     * @param array $settings Export settings
     * @param WC_Product|null $parent Parent product for variations
     * @return array|false Product row data or false if product should be skipped
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
            return false; // Skip products without price
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
            if ($idx >= 4) break; // Max 4 additional images
            $gallery_images[] = wp_get_attachment_url($img_id);
        }

        // Get description
        $description = $parent ? $parent->get_description() : $product->get_description();
        if (empty($description)) {
            $description = $parent ? $parent->get_short_description() : $product->get_short_description();
        }
        $description = wp_strip_all_tags($description);
        $description = html_entity_decode($description, ENT_QUOTES, 'UTF-8');

        // Get dimensions (convert to mm)
        $length = $product->get_length();
        $width = $product->get_width();
        $height = $product->get_height();
        $weight = $product->get_weight();

        // Get dimension unit
        $dimension_unit = get_option('woocommerce_dimension_unit', 'cm');
        $weight_unit = get_option('woocommerce_weight_unit', 'kg');

        // Convert dimensions to mm
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

        // Get color
        $colour = self::get_product_attribute($product, 'color', $parent);
        if (empty($colour)) {
            $colour = self::get_product_attribute($product, 'colour', $parent);
        }

        // Get size
        $size = self::get_product_attribute($product, 'size', $parent);

        // Build the row
        $row = array(
            $gtin,                                          // gtin
            $title,                                         // title
            $settings['country'],                           // country
            $settings['condition'],                         // condition
            number_format((float)$price, 2, '.', ''),       // price
            $settings['tax_rate'],                          // tax
            $settings['currency'],                          // currency
            $settings['delivery_days'],                     // delivery_time_days
            (int)$stock,                                    // stock
            $settings['return_days'],                       // return_days
            $main_image,                                    // main_image_url
            $brand,                                         // brand
            $categories['primary'],                         // category
            '',                                             // google_category
            $categories['seller'],                          // seller_category
            $description,                                   // description
            $length_mm,                                     // length_mm
            $width_mm,                                      // width_mm
            $height_mm,                                     // height_mm
            $weight_g,                                      // weight_g
            '',                                             // shipping_length_mm
            '',                                             // shipping_width_mm
            '',                                             // shipping_height_mm
            '',                                             // shipping_weight_g
            '',                                             // volume_ml
            $size,                                          // size
            $colour,                                        // colour
            isset($gallery_images[0]) ? $gallery_images[0] : '', // image_2_url
            isset($gallery_images[1]) ? $gallery_images[1] : '', // image_3_url
            isset($gallery_images[2]) ? $gallery_images[2] : '', // image_4_url
            isset($gallery_images[3]) ? $gallery_images[3] : '', // image_5_url
            '',                                             // delete_product
            '',                                             // author
            $settings['language'],                          // language
            '',                                             // format
            '',                                             // year
            '',                                             // publisher
        );

        return $row;
    }

    /**
     * Get product GTIN (EAN/ISBN/UPC/etc)
     */
    private static function get_product_gtin($product, $parent = null) {
        // Check common meta keys for GTIN
        $gtin_keys = array(
            '_gtin',
            '_ean',
            '_isbn',
            '_upc',
            '_barcode',
            'gtin',
            'ean',
            'isbn',
            'upc',
            'barcode',
            '_global_unique_id', // WooCommerce native field
        );

        foreach ($gtin_keys as $key) {
            $gtin = $product->get_meta($key);
            if (!empty($gtin)) {
                return $gtin;
            }
        }

        // Check parent product
        if ($parent) {
            foreach ($gtin_keys as $key) {
                $gtin = $parent->get_meta($key);
                if (!empty($gtin)) {
                    return $gtin;
                }
            }
        }

        // Try SKU as fallback if it looks like a GTIN (all numeric, 8-14 digits)
        $sku = $product->get_sku();
        if (!empty($sku) && preg_match('/^[0-9]{8,14}$/', $sku)) {
            return $sku;
        }

        return '';
    }

    /**
     * Get product categories
     */
    private static function get_product_categories($product, $parent = null) {
        $product_id = $parent ? $parent->get_id() : $product->get_id();
        $terms = get_the_terms($product_id, 'product_cat');
        
        $result = array(
            'primary' => '',
            'seller' => '',
        );

        if (!$terms || is_wp_error($terms)) {
            return $result;
        }

        // Build category path
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

    /**
     * Get full category path
     */
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

    /**
     * Get product brand
     */
    private static function get_product_brand($product, $parent = null) {
        $product_id = $parent ? $parent->get_id() : $product->get_id();
        
        // Check common brand taxonomies
        $brand_taxonomies = array('product_brand', 'pa_brand', 'brand', 'pwb-brand');
        
        foreach ($brand_taxonomies as $taxonomy) {
            if (taxonomy_exists($taxonomy)) {
                $terms = get_the_terms($product_id, $taxonomy);
                if ($terms && !is_wp_error($terms)) {
                    return $terms[0]->name;
                }
            }
        }

        // Check meta fields
        $brand_meta_keys = array('_brand', 'brand', '_product_brand');
        foreach ($brand_meta_keys as $key) {
            $brand = $product->get_meta($key);
            if (!empty($brand)) {
                return $brand;
            }
        }

        return '';
    }

    /**
     * Get product attribute
     */
    private static function get_product_attribute($product, $attribute_name, $parent = null) {
        // Check variation attributes first
        if ($product->is_type('variation')) {
            $attr = $product->get_attribute('pa_' . $attribute_name);
            if (!empty($attr)) {
                return $attr;
            }
            $attr = $product->get_attribute($attribute_name);
            if (!empty($attr)) {
                return $attr;
            }
        }

        // Check parent product attributes
        $check_product = $parent ?: $product;
        $attr = $check_product->get_attribute('pa_' . $attribute_name);
        if (!empty($attr)) {
            return $attr;
        }
        $attr = $check_product->get_attribute($attribute_name);
        if (!empty($attr)) {
            return $attr;
        }

        return '';
    }

    /**
     * Convert dimension to mm
     */
    private static function convert_to_mm($value, $unit) {
        if (empty($value)) {
            return '';
        }

        $value = (float)$value;
        
        switch ($unit) {
            case 'm':
                return round($value * 1000);
            case 'cm':
                return round($value * 10);
            case 'mm':
                return round($value);
            case 'in':
                return round($value * 25.4);
            case 'yd':
                return round($value * 914.4);
            default:
                return round($value * 10); // Assume cm
        }
    }

    /**
     * Convert weight to grams
     */
    private static function convert_to_g($value, $unit) {
        if (empty($value)) {
            return '';
        }

        $value = (float)$value;
        
        switch ($unit) {
            case 'kg':
                return round($value * 1000);
            case 'g':
                return round($value);
            case 'lbs':
                return round($value * 453.592);
            case 'oz':
                return round($value * 28.3495);
            default:
                return round($value * 1000); // Assume kg
        }
    }

    /**
     * Log export result
     */
    public static function log_export($result) {
        $logs = get_option('jonakyds_nalda_sync_logs', array());
        
        $log_entry = array(
            'timestamp' => current_time('mysql'),
            'success' => $result['success'],
            'message' => $result['message'],
            'exported' => $result['exported'],
            'skipped' => $result['skipped'],
            'errors' => isset($result['errors']) ? $result['errors'] : array()
        );
        
        // Keep only last 20 logs
        $logs[] = $log_entry;
        if (count($logs) > 20) {
            $logs = array_slice($logs, -20);
        }
        
        update_option('jonakyds_nalda_sync_logs', $logs);
    }

    /**
     * Get export logs
     */
    public static function get_logs() {
        return get_option('jonakyds_nalda_sync_logs', array());
    }

    /**
     * Clear logs
     */
    public static function clear_logs() {
        delete_option('jonakyds_nalda_sync_logs');
    }

    /**
     * Get CSV headers
     */
    public static function get_csv_headers() {
        return self::$csv_headers;
    }

    /**
     * Get CSV file path
     */
    public static function get_csv_file_path() {
        return JONAKYDS_NALDA_SYNC_CSV_DIR . 'nalda-products.csv';
    }

    /**
     * Get CSV file URL
     */
    public static function get_csv_file_url() {
        return JONAKYDS_NALDA_SYNC_CSV_URL . 'nalda-products.csv';
    }

    /**
     * Check if CSV file exists
     */
    public static function csv_exists() {
        return file_exists(self::get_csv_file_path());
    }

    /**
     * Get CSV file info
     */
    public static function get_csv_info() {
        $file_path = self::get_csv_file_path();
        
        if (!file_exists($file_path)) {
            return null;
        }

        return array(
            'path' => $file_path,
            'url' => self::get_csv_file_url(),
            'size' => filesize($file_path),
            'modified' => date('Y-m-d H:i:s', filemtime($file_path)),
            'rows' => self::count_csv_rows($file_path),
        );
    }

    /**
     * Count CSV rows
     */
    private static function count_csv_rows($file_path) {
        $count = 0;
        $handle = fopen($file_path, 'r');
        if ($handle) {
            while (($line = fgets($handle)) !== false) {
                $count++;
            }
            fclose($handle);
        }
        return max(0, $count - 1); // Subtract header row
    }
}
