<?php
/**
 * Zano Payment Gateway Admin
 *
 * @package ZanoPaymentGateway
 */

defined('ABSPATH') || exit;

/**
 * Admin functionality class
 */
class Zano_Admin {

    /**
     * Constructor
     */
    public function __construct() {
        $this->init_hooks();
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Add admin menu
        add_action('admin_menu', [$this, 'add_admin_menu']);
    }

    /**
     * Add admin menu items
     */
    public function add_admin_menu() {
        // Only for admin users
        if (!current_user_can('manage_options')) {
            return;
        }
        
        add_management_page(
            'Zano Payment Debug',
            'Zano Payment Debug',
            'manage_options',
            'zano-payment-debug',
            [$this, 'render_debug_page']
        );
        
        // Add transactions page
        add_menu_page(
            'Zano Transactions',
            'Zano Transactions',
            'manage_woocommerce',
            'zano-transactions',
            [$this, 'render_transactions_page'],
            'dashicons-money-alt',
            58
        );
    }

    /**
     * Render the admin debug page
     */
    public function render_debug_page() {
        // Security check
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'Zano-Fusd-Payment-Processor'));
        }
        
        $debug_page = new Zano_Admin_Debug_Page();
        $debug_page->render();
    }

    /**
     * Render the transactions page
     */
    public function render_transactions_page() {
        // Security check
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'Zano-Fusd-Payment-Processor'));
        }
        
        $transactions_page = new Zano_Admin_Transactions_Page();
        $transactions_page->render();
    }
}

/**
 * Debug page class
 */
class Zano_Admin_Debug_Page {

    /**
     * Render debug page
     */
    public function render() {
        // Process form submission
        if (isset($_POST['zano_action']) && isset($_POST['zano_nonce']) && wp_verify_nonce($_POST['zano_nonce'], 'zano_debug_action')) {
            $this->process_debug_action();
        }
        
        $this->display_debug_interface();
    }

    /**
     * Process debug actions
     */
    private function process_debug_action() {
        $action = sanitize_text_field($_POST['zano_action']);
        
        switch ($action) {
            case 'reset_tables':
                $this->reset_tables();
                break;
            case 'manual_cleanup':
                $this->manual_cleanup();
                break;
            case 'reschedule_cron':
                $this->reschedule_cron();
                break;
            case 'test_api':
                $this->test_api();
                break;
            case 'update_order_statuses':
                $result = Zano_Utilities::update_all_order_statuses();
                $this->add_admin_notice('Updated ' . $result . ' order statuses', 'success');
                break;
            case 'test_monitoring':
                // Run the automatic monitoring manually
                if (class_exists('Zano_Transaction_Monitor')) {
                    $monitor = new Zano_Transaction_Monitor();
                    $monitor->check_transactions();
                    $this->add_admin_notice('Automatic monitoring test completed. Check the logs for results.', 'success');
                } else {
                    $this->add_admin_notice('Zano_Transaction_Monitor class not found', 'error');
                }
                break;
        }
    }

    /**
     * Reset database tables
     */
    private function reset_tables() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'zano_payments';
        $wpdb->query("DROP TABLE IF EXISTS $table_name");
        Zano_Utilities::create_database_tables();
        echo '<div class="notice notice-success"><p>Payment database tables have been reset.</p></div>';
    }

    /**
     * Manual cleanup of expired payments
     */
    private function manual_cleanup() {
        if (class_exists('Zano_Transaction_Handler')) {
            $cleaned_count = Zano_Transaction_Handler::cleanup_expired_payments();
            echo '<div class="notice notice-success"><p>Manual cleanup completed. Processed: ' . $cleaned_count . ' expired payments.</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>Zano_Transaction_Handler class not found.</p></div>';
        }
    }

    /**
     * Reschedule cron jobs
     */
    private function reschedule_cron() {
        Zano_Utilities::unschedule_cron_jobs();
        Zano_Utilities::schedule_cron_jobs();
        echo '<div class="notice notice-success"><p>Cron jobs have been rescheduled.</p></div>';
    }

    /**
     * Test API connection
     */
    private function test_api() {
        $gateway_settings = get_option('woocommerce_zano_payment_settings', []);
        $api = new Zano_API([
            'api_url'        => $gateway_settings['api_url'] ?? '',
            'wallet_address' => $gateway_settings['wallet_address'] ?? '',
            'view_key'       => $gateway_settings['view_key'] ?? '',
            'payment_id_api_url' => $gateway_settings['payment_id_api_url'] ?? Zano_Constants::PAYMENT_VERIFICATION_API,
            'debug'          => true,
        ]);
        
        $result = $api->test_connection();
        
        if (is_wp_error($result)) {
            echo '<div class="notice notice-error"><p>API Connection Test Failed: ' . esc_html($result->get_error_message()) . '</p></div>';
        } else {
            echo '<div class="notice notice-success"><p>API Connection Test Successful!</p></div>';
        }
    }

    /**
     * Display debug interface
     */
    private function display_debug_interface() {
        // Count pending payments
        global $wpdb;
        $table_name = $wpdb->prefix . 'zano_payments';
        $pending_count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE status = 'pending'");
        $confirmed_count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE status = 'confirmed'");
        
        // Count expired payments
        $expired_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name 
             WHERE status = 'pending' 
             AND (tx_hash IS NULL OR tx_hash = '') 
             AND created_at < %s",
            date('Y-m-d H:i:s', current_time('timestamp') - (20 * 60))
        ));
        
        ?>
        <div class="wrap">
            <h1>Zano Payment Gateway Debug Tools</h1>
            
            <div class="card">
                <h2>Payment Database Status</h2>
                <p>
                    <strong>Pending Payments:</strong> <?php echo intval($pending_count); ?><br>
                    <strong>Confirmed Payments:</strong> <?php echo intval($confirmed_count); ?><br>
                    <strong>Expired Payments (>20 min):</strong> <?php echo intval($expired_count); ?>
                </p>
                
                <?php if ($expired_count > 0): ?>
                <form method="post">
                    <?php wp_nonce_field('zano_debug_action', 'zano_nonce'); ?>
                    <input type="hidden" name="zano_action" value="manual_cleanup">
                    <p class="submit">
                        <input type="submit" class="button button-primary" value="Clean Up Expired Payments (<?php echo intval($expired_count); ?>)">
                    </p>
                    <p><small>This will mark all payments older than 20 minutes as "failed".</small></p>
                </form>
                <?php endif; ?>
                
                <form method="post" onsubmit="return confirm('Are you sure you want to reset the payment tables? This will delete ALL payment records!');">
                    <?php wp_nonce_field('zano_debug_action', 'zano_nonce'); ?>
                    <input type="hidden" name="zano_action" value="reset_tables">
                    <p class="submit">
                        <input type="submit" class="button button-secondary" value="Reset Payment Database Tables">
                    </p>
                    <p><small>Note: This will delete all payment records and recreate the tables.</small></p>
                </form>
            </div>
            
            <div class="card">
                <h2>Cron Job Status</h2>
                <p>
                    <?php
                    $next_cleanup = wp_next_scheduled('zano_cleanup_expired_payments');
                    $next_check = wp_next_scheduled('zano_check_transactions');
                    
                    if ($next_cleanup) {
                        $cleanup_time = date('Y-m-d H:i:s', $next_cleanup);
                        echo "<strong>Next Cleanup:</strong> $cleanup_time<br>";
                    } else {
                        echo "<strong>Cleanup Cron:</strong> Not scheduled<br>";
                    }
                    
                    if ($next_check) {
                        $check_time = date('Y-m-d H:i:s', $next_check);
                        echo "<strong>Next Transaction Check:</strong> $check_time<br>";
                    } else {
                        echo "<strong>Transaction Check Cron:</strong> Not scheduled<br>";
                    }
                    
                    // Check if WP Cron is disabled
                    if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) {
                        echo "<strong style='color: red;'>Warning:</strong> WP Cron is disabled (DISABLE_WP_CRON = true)<br>";
                    }
                    ?>
                </p>
                
                <form method="post">
                    <?php wp_nonce_field('zano_debug_action', 'zano_nonce'); ?>
                    <input type="hidden" name="zano_action" value="reschedule_cron">
                    <p class="submit">
                        <input type="submit" class="button button-secondary" value="Reschedule Cron Jobs">
                    </p>
                </form>
                
                <form method="post" style="margin-top: 10px;">
                    <?php wp_nonce_field('zano_debug_action', 'zano_nonce'); ?>
                    <input type="hidden" name="zano_action" value="test_monitoring">
                    <p class="submit">
                        <input type="submit" class="button button-primary" value="Test Automatic Monitoring Now" title="Run the automatic transaction monitor manually to test if it's working">
                    </p>
                </form>
            </div>
            
            <div class="card">
                <h2>API Connection Test</h2>
                <form method="post">
                    <?php wp_nonce_field('zano_debug_action', 'zano_nonce'); ?>
                    <input type="hidden" name="zano_action" value="test_api">
                    <p class="submit">
                        <input type="submit" class="button button-primary" value="Test API Connection">
                    </p>
                </form>
            </div>
            
            <div class="card">
                <h2>Debug Logs</h2>
                <p>
                    <?php
                    $log_files = [
                        'zano-api.log' => 'API Logs',
                        'zano-transactions.log' => 'Transaction Logs',
                        'zano-gateway.log' => 'Gateway Logs'
                    ];
                    
                    foreach ($log_files as $file => $title) {
                        $log_path = ZANO_PAYMENT_PLUGIN_DIR . 'logs/' . $file;
                        if (file_exists($log_path)) {
                            $size = size_format(filesize($log_path));
                            echo "<strong>$title:</strong> $size";
                            echo " [<a href='?page=zano-payment-debug&view_log=$file'>View</a>]<br>";
                        } else {
                            echo "<strong>$title:</strong> No log file<br>";
                        }
                    }
                    ?>
                </p>
                
                <?php
                // Display log content if requested
                if (isset($_GET['view_log']) && array_key_exists($_GET['view_log'], $log_files)) {
                    $log_file = sanitize_file_name($_GET['view_log']);
                    $log_path = ZANO_PAYMENT_PLUGIN_DIR . 'logs/' . $log_file;
                    
                    if (file_exists($log_path)) {
                        $log_content = file_get_contents($log_path);
                        if ($log_content) {
                            echo '<h3>' . esc_html($log_files[$log_file]) . '</h3>';
                            echo '<div style="background:#f0f0f1; padding:10px; overflow:auto; max-height:400px; font-family:monospace;">';
                            echo nl2br(esc_html($log_content));
                            echo '</div>';
                        }
                    }
                }
                ?>
            </div>
        </div>
        <?php
    }
}

/**
 * Transactions page class
 */
class Zano_Admin_Transactions_Page {

    /**
     * Render transactions page
     */
    public function render() {
        // Process actions
        $this->process_actions();
        
        // Display transactions
        $this->display_transactions();
    }

    /**
     * Process page actions
     */
    private function process_actions() {
        // Handle order status changes
        if (isset($_GET['action']) && isset($_GET['payment_id']) && isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'zano_transaction_action')) {
            $this->handle_transaction_action();
        }

        // Handle bulk update order statuses
        if (isset($_GET['action']) && $_GET['action'] === 'update-order-statuses' && isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'zano_update_statuses')) {
            $this->handle_bulk_status_update();
        }
    }

    /**
     * Handle individual transaction actions
     */
    private function handle_transaction_action() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'zano_payments';
        
        $payment_id = sanitize_text_field($_GET['payment_id']);
        $action = sanitize_text_field($_GET['action']);
        
        $payment = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table_name WHERE payment_id = %s", $payment_id),
            ARRAY_A
        );
        
        if ($payment) {
            $order = wc_get_order($payment['order_id']);
            
            if ($order) {
                switch ($action) {
                    case 'mark-paid':
                        $this->mark_order_paid($order, $payment, $table_name);
                        break;
                    case 'cancel':
                        $this->cancel_order($order, $payment);
                        break;
                    case 'clear-tx-hash':
                        $this->clear_transaction_hash($order, $payment, $table_name);
                        break;
                }
            }
        }
    }

    /**
     * Mark order as paid
     */
    private function mark_order_paid($order, $payment, $table_name) {
        global $wpdb;
        
        $order->update_status('processing', __('Manually marked as paid by admin', 'Zano-Fusd-Payment-Processor'));
        $wpdb->update(
            $table_name,
            ['status' => 'confirmed'],
            ['payment_id' => $payment['payment_id']]
        );
        /* translators: %s: Order ID */
        echo '<div class="notice notice-success"><p>' . sprintf(__('Order #%s has been marked as paid.', 'Zano-Fusd-Payment-Processor'), $payment['order_id']) . '</p></div>';
    }

    /**
     * Cancel order
     */
    private function cancel_order($order, $payment) {
        $order->update_status('cancelled', __('Manually cancelled by admin', 'Zano-Fusd-Payment-Processor'));
        /* translators: %s: Order ID */
        echo '<div class="notice notice-info"><p>' . sprintf(__('Order #%s has been cancelled.', 'Zano-Fusd-Payment-Processor'), $payment['order_id']) . '</p></div>';
    }

    /**
     * Clear transaction hash
     */
    private function clear_transaction_hash($order, $payment, $table_name) {
        global $wpdb;
        
        $wpdb->update(
            $table_name,
            [
                'tx_hash' => null,
                'status' => 'pending',
                'confirmations' => 0,
                'received_amount' => null
            ],
            ['payment_id' => $payment['payment_id']]
        );
        $order->add_order_note(__('Transaction hash cleared by admin - payment marked as pending', 'Zano-Fusd-Payment-Processor'));
        /* translators: %s: Order ID */
        echo '<div class="notice notice-warning"><p>' . sprintf(__('Transaction hash cleared for order #%s.', 'Zano-Fusd-Payment-Processor'), $payment['order_id']) . '</p></div>';
    }

    /**
     * Handle bulk status update
     */
    private function handle_bulk_status_update() {
        // Check if an update was run recently (prevent spam)
        $last_update = get_transient('zano_last_status_update');
        if ($last_update && (time() - $last_update) < 60) {
            echo '<div class="notice notice-warning"><p>' . __('Please wait at least 1 minute between status updates to avoid overloading the API.', 'Zano-Fusd-Payment-Processor') . '</p></div>';
        } else {
            // Set transient to prevent rapid successive updates
            set_transient('zano_last_status_update', time(), 120); // 2 minutes
            
            try {
                $updated_count = Zano_Utilities::update_all_order_statuses();
                if ($updated_count > 0) {
                    /* translators: %d: Number of updated order statuses */
                    echo '<div class="notice notice-success zano-update-notice"><p>' . sprintf(__('Successfully updated %d order statuses. Check the order notes for details.', 'Zano-Fusd-Payment-Processor'), $updated_count) . '</p></div>';
                } else {
                    echo '<div class="notice notice-info zano-update-notice"><p>' . __('No order statuses needed updating. All payments are current.', 'Zano-Fusd-Payment-Processor') . '</p></div>';
                }
            } catch (Exception $e) {
                error_log('Zano status update error: ' . $e->getMessage());
                echo '<div class="notice notice-error zano-update-notice"><p>' . __('An error occurred while updating order statuses. Please check the error logs.', 'Zano-Fusd-Payment-Processor') . '</p></div>';
            }
        }
    }

    /**
     * Display transactions table
     */
    private function display_transactions() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'zano_payments';
        
        // Get transactions with pagination
        $per_page = 20;
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($current_page - 1) * $per_page;
        
        // Get transactions count
        $total_items = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
        $total_pages = ceil($total_items / $per_page);
        
        // Get transactions
        $transactions = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table_name ORDER BY created_at DESC LIMIT %d OFFSET %d",
                $per_page,
                $offset
            ),
            ARRAY_A
        );
        
        // Find duplicate transaction hashes
        $duplicate_tx_hashes = $wpdb->get_results(
            "SELECT tx_hash FROM $table_name 
            WHERE tx_hash IS NOT NULL 
            GROUP BY tx_hash 
            HAVING COUNT(*) > 1",
            ARRAY_A
        );
        
        $duplicate_hashes = [];
        foreach ($duplicate_tx_hashes as $dupe) {
            $duplicate_hashes[] = $dupe['tx_hash'];
        }
        
        // Include the transactions table template
        include ZANO_PAYMENT_PLUGIN_DIR . 'includes/admin/views/transactions-table.php';
    }
} 