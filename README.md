# Nalda Sync (JonakyDS)

A WordPress plugin to automatically export WooCommerce products to CSV format for Nalda marketplace integration.

## Features

- **CSV Product Export**: Generate product feeds in Nalda-compatible CSV format
- **Automatic Scheduling**: Set up automatic exports every 10 minutes, hourly, twice daily, or daily
- **Manual Export**: Trigger exports on-demand with real-time progress tracking
- **Configurable Settings**: Customize country, currency, tax rate, delivery time, and more
- **GTIN Support**: Automatically detects EAN/ISBN/UPC/barcode from product meta fields
- **Variable Products**: Exports each variation as a separate product entry
- **Public/Private Access**: Option to allow public access to the CSV feed URL
- **Downloadable CSV**: Direct download links for the generated CSV file
- **Comprehensive Logging**: Track all export operations with detailed logs
- **Auto Updates**: Automatic updates from GitHub releases

## Requirements

- WordPress 5.0 or higher
- WooCommerce 3.0 or higher
- PHP 7.2 or higher

## Installation

1. Download the latest release from [GitHub](https://github.com/JonakyDS/jonakyds-nalda-sync/releases)
2. Upload the `jonakyds-nalda-sync` folder to `/wp-content/plugins/`
3. Activate the plugin through the 'Plugins' menu in WordPress
4. Go to WooCommerce > Nalda Sync to configure the plugin

## Configuration

### Export Settings

1. **Country**: Select the target country (CH, DE, AT, FR, IT)
2. **Currency**: Choose the currency (CHF, EUR, USD, GBP)
3. **Tax Rate**: Set the applicable tax rate percentage
4. **Delivery Time**: Default delivery time in days
5. **Return Period**: Return period in days
6. **Product Condition**: Default condition (new, used, refurbished)
7. **Language Code**: Optional language code (ger, eng, fra, etc.)
8. **Default Brand**: Fallback brand when product has no brand set
9. **Require GTIN**: Skip products without GTIN if enabled
10. **Public Access**: Allow public access to CSV feed URL

### Automatic Export

1. Enable "Automatic export" checkbox
2. Select export schedule (Every 10 Minutes, Hourly, Twice Daily, Daily)
3. The CSV file will be regenerated automatically at the scheduled interval

## CSV Format

The exported CSV follows the Nalda product feed specification with the following columns:

| Column | Description |
|--------|-------------|
| gtin | Product EAN/ISBN/UPC/barcode |
| title | Product name (with variation attributes) |
| country | Target country code |
| condition | new, used, or refurbished |
| price | Product price |
| tax | Tax rate percentage |
| currency | Currency code |
| delivery_time_days | Delivery time in days |
| stock | Stock quantity |
| return_days | Return period in days |
| main_image_url | Primary product image URL |
| brand | Product brand |
| category | Product category path |
| google_category | Google product category (optional) |
| seller_category | Full category hierarchy |
| description | Product description |
| length_mm | Length in millimeters |
| width_mm | Width in millimeters |
| height_mm | Height in millimeters |
| weight_g | Weight in grams |
| shipping_length_mm | Shipping length |
| shipping_width_mm | Shipping width |
| shipping_height_mm | Shipping height |
| shipping_weight_g | Shipping weight |
| volume_ml | Volume in milliliters |
| size | Product size |
| colour | Product color |
| image_2_url - image_5_url | Additional product images |
| delete_product | Mark for deletion |
| author | Author (for books) |
| language | Language code |
| format | Format (for books) |
| year | Year (for books) |
| publisher | Publisher (for books) |

## GTIN Detection

The plugin automatically looks for GTIN (EAN/ISBN/UPC) in these meta fields:

- `_gtin`, `gtin`
- `_ean`, `ean`
- `_isbn`, `isbn`
- `_upc`, `upc`
- `_barcode`, `barcode`
- `_global_unique_id` (WooCommerce native)

If the product SKU is a valid GTIN format (8-14 digits), it will be used as fallback.

## CSV File Location

The exported CSV is stored at:
```
/wp-content/nalda-exports/nalda-products.csv
```

The URL for the CSV feed is displayed in the admin interface and can be shared with Nalda for automatic feed fetching.

## Usage

### Manual Export

1. Go to WooCommerce > Nalda Sync
2. Click "Export Now" button
3. Monitor real-time progress
4. Download the generated CSV

### Feed URL for Nalda

1. Enable "Allow public CSV access" in settings
2. Copy the CSV URL from the admin interface
3. Provide this URL to Nalda for automatic feed fetching

### Scheduled Export

Once automatic export is enabled, the CSV file will be regenerated at your chosen interval, replacing the previous file. Nalda can fetch the same URL to always get the latest product data.

## Troubleshooting

### Products not appearing in CSV?

- Check if products have a valid GTIN (if "Require GTIN" is enabled)
- Ensure products have a price set
- Verify products are published and not in draft status

### Export takes too long?

- For large catalogs, use scheduled exports instead of manual
- The background process handles large exports efficiently

### CSV URL not accessible?

- Enable "Allow public CSV access" in settings
- Check that the `/wp-content/nalda-exports/` directory is writable
- Verify no security plugins are blocking access

### Variable products showing incorrectly?

- Each variation is exported as a separate product
- Variation attributes are appended to the product title
- Parent product data is inherited where variation data is missing

## Changelog

### 1.0.0
- Initial release
- CSV product export for Nalda marketplace
- Automatic scheduled exports
- Real-time export progress
- GTIN auto-detection
- Variable product support
- GitHub auto-updates

## License

GPL v2 or later - https://www.gnu.org/licenses/gpl-2.0.html

## Author

Jonaky Adhikary - [jonakyds.com](https://jonakyds.com)
