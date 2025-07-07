<?php
defined('ABSPATH') || exit;

/**
 * Zano Payment Gateway Factory
 *
 * Factory class for creating and managing plugin dependencies.
 * Implements simple dependency injection for better testability.
 */
class Zano_Factory {

    /**
     * Singleton instances
     *
     * @var array
     */
    private static $instances = [];

    /**
     * Configuration storage
     *
     * @var array
     */
    private static $config = [];

    /**
     * Initialize factory with configuration
     *
     * @param array $config Configuration array
     */
    public static function init($config = []) {
        self::$config = array_merge([
            'api_url' => Zano_Constants::DEFAULT_API_URL,
            'wallet_address' => '',
            'view_key' => '',
            'debug' => false,
            'test_mode' => false
        ], $config);
    }

    /**
     * Create API instance
     *
     * @param array $override_config Optional configuration override
     * @return Zano_API
     */
    public static function create_api($override_config = []) {
        $config = array_merge(self::$config, $override_config);
        return new Zano_API($config);
    }

    /**
     * Get or create API singleton
     *
     * @param array $override_config Optional configuration override
     * @return Zano_API
     */
    public static function get_api($override_config = []) {
        $key = 'api_' . md5(serialize($override_config));
        
        if (!isset(self::$instances[$key])) {
            self::$instances[$key] = self::create_api($override_config);
        }
        
        return self::$instances[$key];
    }

    /**
     * Create transaction monitor instance
     *
     * @return Zano_Transaction_Monitor
     */
    public static function create_transaction_monitor() {
        return new Zano_Transaction_Monitor();
    }

    /**
     * Get or create transaction monitor singleton
     *
     * @return Zano_Transaction_Monitor
     */
    public static function get_transaction_monitor() {
        if (!isset(self::$instances['transaction_monitor'])) {
            self::$instances['transaction_monitor'] = self::create_transaction_monitor();
        }
        
        return self::$instances['transaction_monitor'];
    }

    /**
     * Create QR generator instance
     *
     * @return Zano_QR_Generator
     */
    public static function create_qr_generator() {
        return new Zano_QR_Generator();
    }

    /**
     * Get or create QR generator singleton
     *
     * @return Zano_QR_Generator
     */
    public static function get_qr_generator() {
        if (!isset(self::$instances['qr_generator'])) {
            self::$instances['qr_generator'] = self::create_qr_generator();
        }
        
        return self::$instances['qr_generator'];
    }

    /**
     * Create AJAX handlers instance
     *
     * @return Zano_Ajax_Handlers
     */
    public static function create_ajax_handlers() {
        return new Zano_Ajax_Handlers();
    }

    /**
     * Get or create AJAX handlers singleton
     *
     * @return Zano_Ajax_Handlers
     */
    public static function get_ajax_handlers() {
        if (!isset(self::$instances['ajax_handlers'])) {
            self::$instances['ajax_handlers'] = self::create_ajax_handlers();
        }
        
        return self::$instances['ajax_handlers'];
    }

    /**
     * Create payment page handler instance
     *
     * @return Zano_Payment_Page_Handler
     */
    public static function create_payment_page_handler() {
        return new Zano_Payment_Page_Handler();
    }

    /**
     * Get or create payment page handler singleton
     *
     * @return Zano_Payment_Page_Handler
     */
    public static function get_payment_page_handler() {
        if (!isset(self::$instances['payment_page_handler'])) {
            self::$instances['payment_page_handler'] = self::create_payment_page_handler();
        }
        
        return self::$instances['payment_page_handler'];
    }

    /**
     * Create admin instance
     *
     * @return Zano_Admin
     */
    public static function create_admin() {
        return new Zano_Admin();
    }

    /**
     * Get or create admin singleton
     *
     * @return Zano_Admin
     */
    public static function get_admin() {
        if (!isset(self::$instances['admin'])) {
            self::$instances['admin'] = self::create_admin();
        }
        
        return self::$instances['admin'];
    }

    /**
     * Create database migration instance
     *
     * @return Zano_Database_Migration
     */
    public static function create_database_migration() {
        return new Zano_Database_Migration();
    }

    /**
     * Create transaction handler instance
     *
     * @return Zano_Transaction_Handler
     */
    public static function create_transaction_handler() {
        return new Zano_Transaction_Handler();
    }

    /**
     * Create payment gateway instance
     *
     * @return Zano_Payment_Gateway
     */
    public static function create_payment_gateway() {
        return new Zano_Payment_Gateway();
    }

    /**
     * Set configuration value
     *
     * @param string $key Configuration key
     * @param mixed $value Configuration value
     */
    public static function set_config($key, $value) {
        self::$config[$key] = $value;
    }

    /**
     * Get configuration value
     *
     * @param string $key Configuration key
     * @param mixed $default Default value if key not found
     * @return mixed Configuration value
     */
    public static function get_config($key, $default = null) {
        return self::$config[$key] ?? $default;
    }

    /**
     * Clear all singleton instances (useful for testing)
     */
    public static function clear_instances() {
        self::$instances = [];
    }

    /**
     * Check if instance exists
     *
     * @param string $key Instance key
     * @return bool True if instance exists
     */
    public static function has_instance($key) {
        return isset(self::$instances[$key]);
    }

    /**
     * Remove specific instance
     *
     * @param string $key Instance key
     */
    public static function remove_instance($key) {
        unset(self::$instances[$key]);
    }
} 