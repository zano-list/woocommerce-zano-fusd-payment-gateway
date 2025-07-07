<?php
defined('ABSPATH') || exit;

/**
 * Zano Payment Gateway Constants
 *
 * Centralized constants for the entire plugin to improve maintainability
 * and make configuration easier for open source contributors.
 */
final class Zano_Constants {

    // Plugin Meta
    const PLUGIN_VERSION = '1.0.0';
    const MIN_PHP_VERSION = '7.3';
    const MIN_WC_VERSION = '3.0.0';
    const TESTED_WC_VERSION = '8.5.2';

    // Database
    const DB_TABLE_NAME = 'zano_payments';
    const DB_VERSION_OPTION = 'zano_payment_db_version';
    
    // Payment Settings Defaults
    const DEFAULT_CONFIRMATIONS = 10;
    const DEFAULT_PRICE_BUFFER = 1; // 1%
    const DEFAULT_API_URL = 'http://37.27.100.59:10500/json_rpc';
    const DEFAULT_PAYMENT_TIMEOUT = 1200; // 20 minutes in seconds
    
    // Asset Configuration
    const ZANO_ASSET_ID = 'd6329b5b1f7c0805b5c345f4957554002a2f557845f64d7645dae0e051a6498a';
    const FUSD_ASSET_ID = '86143388bd056a8f0bab669f78f14873fac8e2dd8d57898cdb725a2d5e2e4f8f';
    
    const ASSET_DECIMALS = [
        self::ZANO_ASSET_ID => 12,
        self::FUSD_ASSET_ID => 4
    ];
    
    const ASSET_NAMES = [
        self::ZANO_ASSET_ID => 'ZANO',
        self::FUSD_ASSET_ID => 'FUSD'
    ];
    
    // API Endpoints
    const MEXC_PRICE_API = 'https://api.mexc.com/api/v3/ticker/price?symbol=ZANOUSDT';
    const PAYMENT_VERIFICATION_API = 'https://zanowordpressplugin.com';
    
    // Timeouts (seconds)
    const API_TIMEOUT_DEFAULT = 30;
    const API_TIMEOUT_CONNECTION_TEST = 15;
    const API_TIMEOUT_PRICE_CHECK = 10;
    
    // Cron Schedules
    const CRON_TRANSACTION_CHECK = 'every_5_minutes';
    const CRON_CLEANUP_EXPIRED = 'hourly';
    const CRON_INTERVAL_MONITOR = 'every_5_minutes';
    const CRON_INTERVAL_CLEANUP = 'hourly';
    const CRON_INTERVAL_STATUS_UPDATE = 'every_15_minutes';
    
    // Payment Status Values
    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_CONFIRMED = 'confirmed';
    const STATUS_FAILED = 'failed';
    const STATUS_EXPIRED = 'expired';
    
    // File Paths
    const ASSETS_DIR = 'assets/';
    const IMAGES_DIR = self::ASSETS_DIR . 'images/';
    const CSS_DIR = self::ASSETS_DIR . 'css/';
    const JS_DIR = self::ASSETS_DIR . 'js/';
    const LOGS_DIR = 'logs/';
    
    // UI Constants
    const AMOUNT_TOLERANCE_PERCENT = 2; // 2% tolerance for amount matching
    const QR_CODE_SIZE = 200;
    const PAYMENT_ID_LENGTH = 16; // characters
    
    // Error Messages
    const ERROR_WALLET_ADDRESS_INVALID = 'Invalid wallet address format';
    const ERROR_VIEW_KEY_INVALID = 'Invalid view key format';
    const ERROR_API_URL_INVALID = 'Invalid API URL format';
    const ERROR_CURL_NOT_AVAILABLE = 'cURL extension is not available';
    
    // Success Messages
    const SUCCESS_PAYMENT_CONFIRMED = 'Payment confirmed successfully';
    const SUCCESS_SETTINGS_SAVED = 'Settings saved successfully';
    
    /**
     * Get asset configuration by asset ID
     *
     * @param string $asset_id Asset ID
     * @return array Asset configuration
     */
    public static function get_asset_config($asset_id = null) {
        $asset_id = $asset_id ?: self::ZANO_ASSET_ID;
        
        return [
            'id' => $asset_id,
            'name' => self::ASSET_NAMES[$asset_id] ?? 'UNKNOWN',
            'decimals' => self::ASSET_DECIMALS[$asset_id] ?? 12,
            'divisor' => pow(10, self::ASSET_DECIMALS[$asset_id] ?? 12)
        ];
    }
    
    /**
     * Get all supported assets
     *
     * @return array All asset configurations
     */
    public static function get_supported_assets() {
        return [
            self::ZANO_ASSET_ID => self::get_asset_config(self::ZANO_ASSET_ID),
            self::FUSD_ASSET_ID => self::get_asset_config(self::FUSD_ASSET_ID)
        ];
    }
    
    /**
     * Get cron schedule configuration
     *
     * @return array Cron schedules
     */
    public static function get_cron_schedules() {
        return [
            self::CRON_TRANSACTION_CHECK => [
                'interval' => 300, // 5 minutes
                'display' => __('Every 5 minutes', 'zano-payment-gateway')
            ],
            'every_15_minutes' => [
                'interval' => 900, // 15 minutes
                'display' => __('Every 15 minutes', 'zano-payment-gateway')
            ]
        ];
    }
    
    /**
     * Get all payment statuses
     *
     * @return array Payment statuses
     */
    public static function get_payment_statuses() {
        return [
            self::STATUS_PENDING,
            self::STATUS_PROCESSING,
            self::STATUS_CONFIRMED,
            self::STATUS_FAILED,
            self::STATUS_EXPIRED
        ];
    }
    
    /**
     * Validate if status is valid
     *
     * @param string $status Status to validate
     * @return bool True if valid
     */
    public static function is_valid_status($status) {
        return in_array($status, self::get_payment_statuses(), true);
    }
    
    /**
     * Private constructor to prevent instantiation
     */
    private function __construct() {}
} 