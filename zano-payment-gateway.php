<?php
/**
 * Plugin Name: Zano Payment Gateway
 * Plugin URI: https://zano.org
 * Description: A non-custodial payment processor for WordPress/WooCommerce that allows merchants to accept Zano cryptocurrency.
 * Version: 1.0.0
 * Author: Zano Team
 * Author URI: https://zano.org
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: zano-payment-gateway
 * Domain Path: /languages
 * WC requires at least: 3.0.0
 * WC tested up to: 8.5.2
 * Requires PHP: 7.4
 */

/*
This program is free software; you can redistribute it and/or
modify it under the terms of the GNU General Public License
as published by the Free Software Foundation; either version 2
of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
*/

defined('ABSPATH') || exit;

// Define plugin constants
define('ZANO_PAYMENT_VERSION', '1.0.0');
define('ZANO_PAYMENT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ZANO_PAYMENT_PLUGIN_URL', plugin_dir_url(__FILE__));
define('ZANO_PAYMENT_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Set test mode based on settings (will be overridden by settings later)
if (!defined('ZANO_TEST_MODE')) {
    define('ZANO_TEST_MODE', false); // Default to production mode
}

// Check if WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    return;
}

/**
 * Main plugin class
 */
final class Zano_Payment_Plugin {
    
    /**
     * Plugin instance
     */
    private static $instance = null;
    
    /**
     * Get plugin instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        $this->init_hooks();
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Plugin lifecycle hooks
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
        
        // Core hooks
        add_action('plugins_loaded', [$this, 'init']);
        add_filter('woocommerce_payment_gateways', [$this, 'add_gateway_class']);
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'add_settings_link']);
        add_filter('cron_schedules', [$this, 'add_cron_schedules']);
        
        // Asset hooks - Load with higher priority to override theme styles
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets'], 20);
        
        // HPOS compatibility
        add_action('before_woocommerce_init', [$this, 'declare_hpos_compatibility']);
        
        // WooCommerce Blocks compatibility
        add_action('before_woocommerce_init', [$this, 'declare_blocks_compatibility']);
        add_action('woocommerce_blocks_loaded', [$this, 'register_blocks_support']);
        
        // Custom endpoints
        add_action('init', [$this, 'add_custom_endpoints']);
        add_filter('query_vars', [$this, 'add_query_vars']);
        
        // Database maintenance
        add_action('plugins_loaded', [$this, 'check_database_tables']);
        
        // Cron job hooks - connecting scheduled jobs to their handlers
        add_action('zano_monitor_payments', [$this, 'monitor_payments_cron']);
        add_action('zano_cleanup_expired_payments', [$this, 'cleanup_expired_payments']);
        add_action('zano_update_payment_statuses', [$this, 'update_payment_statuses_cron']);
        add_action('zano_check_transactions', [$this, 'check_transactions_cron']); // Legacy hook for backward compatibility
        
        // Individual transaction monitoring hooks (dynamic hooks)
        add_action('init', [$this, 'register_individual_transaction_hooks']);
    }
    
    /**
     * Include required files.
     * This is called from the init() method so that all files are loaded after WooCommerce.
     */
    private function include_files() {
        // Constants and factory first
        require_once ZANO_PAYMENT_PLUGIN_DIR . 'includes/class-zano-constants.php';
        require_once ZANO_PAYMENT_PLUGIN_DIR . 'includes/class-zano-factory.php';
        
        // Utilities (new refactored classes)
        require_once ZANO_PAYMENT_PLUGIN_DIR . 'includes/utilities/class-zano-database-manager.php';
        require_once ZANO_PAYMENT_PLUGIN_DIR . 'includes/utilities/class-zano-file-manager.php';
        require_once ZANO_PAYMENT_PLUGIN_DIR . 'includes/utilities/class-zano-cron-manager.php';
        require_once ZANO_PAYMENT_PLUGIN_DIR . 'includes/utilities/class-zano-form-builder.php';
        
        // Core classes
        require_once ZANO_PAYMENT_PLUGIN_DIR . 'includes/class-zano-payment-gateway.php';
        require_once ZANO_PAYMENT_PLUGIN_DIR . 'includes/class-zano-blocks-support.php';
        require_once ZANO_PAYMENT_PLUGIN_DIR . 'includes/class-zano-api.php';
        require_once ZANO_PAYMENT_PLUGIN_DIR . 'includes/class-zano-transaction-handler.php';
        require_once ZANO_PAYMENT_PLUGIN_DIR . 'includes/class-zano-transaction-monitor.php';
        require_once ZANO_PAYMENT_PLUGIN_DIR . 'includes/class-zano-database-migration.php';
        
        // Utilities and handlers
        require_once ZANO_PAYMENT_PLUGIN_DIR . 'includes/class-zano-ajax-handlers.php';
        require_once ZANO_PAYMENT_PLUGIN_DIR . 'includes/class-zano-payment-page-handler.php';
        require_once ZANO_PAYMENT_PLUGIN_DIR . 'includes/class-zano-qr-generator.php';
        require_once ZANO_PAYMENT_PLUGIN_DIR . 'includes/class-zano-utilities.php';
        
        // Admin functionality
        if (is_admin()) {
            require_once ZANO_PAYMENT_PLUGIN_DIR . 'includes/admin/class-zano-admin.php';
        }
    }
    
    /**
     * Initialize components
     */
    private function init_components() {
        // Initialize transaction monitor
        new Zano_Transaction_Monitor();
        
        // Initialize AJAX handlers
        new Zano_Ajax_Handlers();
        
        // Initialize payment page handler
        new Zano_Payment_Page_Handler();
        
        // Initialize admin if in admin area
        if (is_admin()) {
            new Zano_Admin();
        }
    }
    
    /**
     * Declare HPOS compatibility
     */
    public function declare_hpos_compatibility() {
        if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        }
    }
    
    /**
     * Declare blocks compatibility
     */
    public function declare_blocks_compatibility() {
        if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
        }
    }
    
    /**
     * Register blocks support
     */
    public function register_blocks_support() {
        if (class_exists('Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry')) {
            add_action(
                'woocommerce_blocks_payment_method_type_registration',
                function(Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry) {
                    $payment_method_registry->register(new Zano_Blocks_Support());
                }
            );
        }
    }
    
    /**
     * Plugin activation
     */
    public function activate() {
        // Include required files for activation
        if (!class_exists('Zano_Constants')) {
            require_once ZANO_PAYMENT_PLUGIN_DIR . 'includes/class-zano-constants.php';
        }
        if (!class_exists('Zano_Database_Manager')) {
            require_once ZANO_PAYMENT_PLUGIN_DIR . 'includes/utilities/class-zano-database-manager.php';
        }
        if (!class_exists('Zano_File_Manager')) {
            require_once ZANO_PAYMENT_PLUGIN_DIR . 'includes/utilities/class-zano-file-manager.php';
        }
        if (!class_exists('Zano_Cron_Manager')) {
            require_once ZANO_PAYMENT_PLUGIN_DIR . 'includes/utilities/class-zano-cron-manager.php';
        }

        // Manually add custom cron schedules so they are available for scheduling.
        add_filter('cron_schedules', ['Zano_Cron_Manager', 'add_custom_cron_intervals']);

        // Create database tables with proper migration
        Zano_Database_Manager::create_tables();
        
        // Create plugin directories and assets
        Zano_File_Manager::create_directories();
        Zano_File_Manager::create_default_assets();
        
        // Schedule cron jobs
        Zano_Cron_Manager::init_cron_jobs();
        
        // It's good practice to remove the filter after use in activation context.
        remove_filter('cron_schedules', ['Zano_Cron_Manager', 'add_custom_cron_intervals']);

        // Force database migration check
        $this->force_database_migration();
    }
    
    /**
     * Force database migration to add missing columns
     */
    private function force_database_migration() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . Zano_Constants::DB_TABLE_NAME;
        
        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
        
        if (!$table_exists) {
            // Table doesn't exist, create it
            Zano_Database_Manager::create_tables();
            return;
        }
        
        // Check for missing columns and add them
        $required_columns = [
            'asset_id' => 'varchar(64) DEFAULT NULL',
            'asset_symbol' => 'varchar(10) DEFAULT NULL', 
            'asset_amount' => 'decimal(15,8) DEFAULT NULL',
            'received_amount' => 'decimal(15,8) DEFAULT NULL',
            'received_block' => 'bigint(20) DEFAULT NULL',
            'current_block' => 'bigint(20) DEFAULT NULL',
            'keeper_block' => 'bigint(20) DEFAULT NULL',
            'completed_at' => 'datetime DEFAULT NULL'
        ];
        
        foreach ($required_columns as $column => $definition) {
            $column_exists = $wpdb->get_results("SHOW COLUMNS FROM $table_name LIKE '$column'");
            
            if (empty($column_exists)) {
                $result = $wpdb->query("ALTER TABLE $table_name ADD COLUMN $column $definition");
                if ($result === false) {
                    error_log("Failed to add column $column to $table_name: " . $wpdb->last_error);
                } else {
                    error_log("Successfully added column $column to $table_name");
                }
            }
        }
        
        // Run duplicate transaction resolution
        Zano_Database_Manager::resolve_duplicate_transactions();
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Include cron manager if not already loaded
        if (!class_exists('Zano_Cron_Manager')) {
            require_once ZANO_PAYMENT_PLUGIN_DIR . 'includes/utilities/class-zano-cron-manager.php';
        }
        
        Zano_Cron_Manager::unschedule_all_cron_jobs();
    }
    
    /**
     * Initialize plugin
     */
    public function init() {
        // Load required files
        $this->include_files();
        // Initialize components
        $this->init_components();
        // Load plugin text domain
        load_plugin_textdomain('zano-payment-gateway', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }
    
    /**
     * Add payment gateway class
     */
    public function add_gateway_class($gateways) {
        $gateways[] = 'Zano_Payment_Gateway';
        return $gateways;
    }
    
    /**
     * Add settings link
     */
    public function add_settings_link($links) {
        $settings_link = '<a href="admin.php?page=wc-settings&tab=checkout&section=zano_payment">Settings</a>';
        array_unshift($links, $settings_link);
        return $links;
    }
    
    /**
     * Add custom cron schedules
     */
    public function add_cron_schedules($schedules) {
        return Zano_Cron_Manager::add_custom_cron_intervals($schedules);
    }
    
    /**
     * Enqueue frontend assets
     */
    public function enqueue_frontend_assets() {
        $is_payment_page = isset($_GET['wc-api']) && $_GET['wc-api'] === 'zano_payment_page';
        
        if ($is_payment_page || is_checkout_pay_page() || is_checkout() || is_wc_endpoint_url('order-received')) {
            wp_enqueue_style(
                'zano-payment-style',
                ZANO_PAYMENT_PLUGIN_URL . 'assets/css/zano-payment.css',
                [], // No dependencies to load after theme styles
                ZANO_PAYMENT_VERSION,
                'all' // Load for all media
            );
            
            // Ensure our CSS loads after theme styles
            wp_add_inline_style('zano-payment-style', '');
            
            wp_enqueue_script(
                'qrcode-js',
                'https://cdn.jsdelivr.net/npm/qrcode@1.5.3/build/qrcode.min.js',
                [],
                '1.5.3',
                true
            );

            wp_enqueue_script(
                'zano-payment-script',
                ZANO_PAYMENT_PLUGIN_URL . 'assets/js/zano-payment.js',
                ['jquery', 'qrcode-js'],
                ZANO_PAYMENT_VERSION,
                true
            );
            
            wp_localize_script(
                'zano-payment-script',
                'zano_params',
                [
                    'ajax_url' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('zano_payment_nonce'),
                    'confirmed_text' => __('Payment confirmed! Your order is being processed.', 'zano-payment-gateway'),
                    'detected_text' => __('Payment detected! Waiting for confirmations: %s', 'zano-payment-gateway'),
                    'pending_text' => __('Waiting for payment...', 'zano-payment-gateway'),
                    'failed_text' => __('Payment failed or expired after 20 minutes.', 'zano-payment-gateway'),
                    'check_text' => __('Check for Payment', 'zano-payment-gateway'),
                    'checking_text' => __('Checking...', 'zano-payment-gateway')
                ]
            );
        }
    }
    
    /**
     * Add custom endpoints
     */
    public function add_custom_endpoints() {
        add_rewrite_endpoint('zano-payment', EP_ALL);
    }
    
    /**
     * Add query vars
     */
    public function add_query_vars($vars) {
        $vars[] = 'order_id';
        return $vars;
    }
    
    /**
     * Check database tables
     */
    public function check_database_tables() {
        if (!is_admin()) {
            return;
        }
        
        $check_option = get_option('zano_db_check', 0);
        $current_time = time();
        
        // Only check once a day
        if ($check_option > ($current_time - 86400)) {
            return;
        }
        
        update_option('zano_db_check', $current_time);
        Zano_Database_Manager::create_tables();
        Zano_Database_Manager::resolve_duplicate_transactions();
    }
    
    /**
     * Register individual transaction monitoring hooks
     */
    public function register_individual_transaction_hooks() {
        // This method will be called on init to register any existing transaction monitoring hooks
        // We use a wildcard approach to handle dynamic cron hooks
        
        // Get all scheduled cron jobs
        $cron_jobs = get_option('cron', []);
        
        foreach ($cron_jobs as $timestamp => $jobs) {
            if (is_array($jobs)) {
                foreach ($jobs as $hook => $job_data) {
                    // Check if this is a Zano transaction monitoring hook
                    if (strpos($hook, 'zano_monitor_transaction_') === 0) {
                        // Register the action hook for this specific transaction
                        add_action($hook, function($payment_id, $tx_hash) {
                            if (class_exists('Zano_Transaction_Handler')) {
                                Zano_Transaction_Handler::monitor_specific_transaction($payment_id, $tx_hash);
                            }
                        }, 10, 2);
                    }
                }
            }
        }
    }

    /**
     * Cleanup expired payments (cron handler)
     */
    public function cleanup_expired_payments() {
        if (class_exists('Zano_Database_Manager')) {
            $cleaned_count = Zano_Database_Manager::delete_expired_payments();
            if ($cleaned_count > 0) {
                error_log("Zano: Cleaned up $cleaned_count expired payments");
            }
        }
    }

    /**
     * Monitor payments cron handler
     */
    public function monitor_payments_cron() {
        // Use the transaction monitor to check for new transactions
        if (class_exists('Zano_Transaction_Monitor')) {
            $monitor = new Zano_Transaction_Monitor();
            $monitor->check_transactions();
        }
    }

    /**
     * Update payment statuses cron handler
     */
    public function update_payment_statuses_cron() {
        if (class_exists('Zano_Utilities')) {
            $updated_count = Zano_Utilities::update_all_order_statuses();
            if ($updated_count > 0) {
                error_log("Zano: Updated $updated_count payment statuses");
            }
        }
    }

    /**
     * Check transactions cron handler (legacy - for backward compatibility)
     */
    public function check_transactions_cron() {
        // Delegate to the monitor payments handler
        $this->monitor_payments_cron();
    }
}

// Initialize the plugin
Zano_Payment_Plugin::get_instance();