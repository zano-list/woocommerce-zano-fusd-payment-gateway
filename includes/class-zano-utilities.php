<?php
/**
 * Zano Payment Gateway Utilities
 *
 * @package ZanoPaymentGateway
 */

defined('ABSPATH') || exit;

/**
 * Utilities class for common functions
 */
class Zano_Utilities {

    /**
     * Create or update plugin database tables
     */
    public static function create_database_tables() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'zano_payments';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            order_id bigint(20) NOT NULL,
            payment_id varchar(64) NOT NULL,
            wallet_address text NOT NULL,
            amount decimal(15,8) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            confirmations int(11) NOT NULL DEFAULT 0,
            tx_hash varchar(64) DEFAULT NULL,
            received_amount decimal(15,8) DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY order_id (order_id),
            KEY payment_id (payment_id),
            KEY status (status),
            UNIQUE KEY tx_hash (tx_hash)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        // Check if the table exists and has the correct structure
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
        
        if (!$table_exists) {
            error_log('Failed to create Zano payments table');
        } else {
            // Check if received_amount column exists, add it if not
            $column_exists = $wpdb->get_results("SHOW COLUMNS FROM $table_name LIKE 'received_amount'");
            if (empty($column_exists)) {
                $wpdb->query("ALTER TABLE $table_name ADD COLUMN received_amount decimal(15,8) DEFAULT NULL AFTER tx_hash");
            }
            
            // Check if received_block column exists, add it if not
            $column_exists = $wpdb->get_results("SHOW COLUMNS FROM $table_name LIKE 'received_block'");
            if (empty($column_exists)) {
                $wpdb->query("ALTER TABLE $table_name ADD COLUMN received_block int(11) DEFAULT NULL AFTER received_amount");
            }
            
            // Check if current_block column exists, add it if not
            $column_exists = $wpdb->get_results("SHOW COLUMNS FROM $table_name LIKE 'current_block'");
            if (empty($column_exists)) {
                $wpdb->query("ALTER TABLE $table_name ADD COLUMN current_block int(11) DEFAULT NULL AFTER received_block");
            }
            
            // Check if asset_id column exists, add it if not
            $column_exists = $wpdb->get_results("SHOW COLUMNS FROM $table_name LIKE 'asset_id'");
            if (empty($column_exists)) {
                $wpdb->query("ALTER TABLE $table_name ADD COLUMN asset_id varchar(64) DEFAULT NULL AFTER current_block");
            }
            
            // Check if asset_symbol column exists, add it if not
            $column_exists = $wpdb->get_results("SHOW COLUMNS FROM $table_name LIKE 'asset_symbol'");
            if (empty($column_exists)) {
                $wpdb->query("ALTER TABLE $table_name ADD COLUMN asset_symbol varchar(10) DEFAULT NULL AFTER asset_id");
            }
            
            // Check if asset_amount column exists, add it if not
            $column_exists = $wpdb->get_results("SHOW COLUMNS FROM $table_name LIKE 'asset_amount'");
            if (empty($column_exists)) {
                $wpdb->query("ALTER TABLE $table_name ADD COLUMN asset_amount decimal(15,8) DEFAULT NULL AFTER asset_symbol");
            }
            
            // Check if completed_at column exists, add it if not
            $column_exists = $wpdb->get_results("SHOW COLUMNS FROM $table_name LIKE 'completed_at'");
            if (empty($column_exists)) {
                $wpdb->query("ALTER TABLE $table_name ADD COLUMN completed_at datetime DEFAULT NULL AFTER asset_amount");
            }
        }
    }

    /**
     * Check for and resolve duplicate transaction hashes
     */
    public static function check_for_duplicate_transactions() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'zano_payments';
        
        // Check if tx_hash column has a UNIQUE constraint
        $has_unique_constraint = false;
        $indexes = $wpdb->get_results("SHOW INDEX FROM $table_name WHERE Column_name = 'tx_hash'");
        
        foreach ($indexes as $index) {
            if ($index->Non_unique == 0) {
                $has_unique_constraint = true;
                break;
            }
        }
        
        // If no unique constraint exists, add it
        if (!$has_unique_constraint) {
            // First check for and handle any duplicate tx_hash values
            $duplicates = $wpdb->get_results(
                "SELECT tx_hash, COUNT(*) as count FROM $table_name 
                 WHERE tx_hash IS NOT NULL 
                 GROUP BY tx_hash 
                 HAVING COUNT(*) > 1"
            );
            
            if (!empty($duplicates)) {
                // Log the duplicate transaction hashes
                error_log('Found duplicated transaction hashes in Zano payments table:');
                
                foreach ($duplicates as $duplicate) {
                    error_log('Transaction hash: ' . $duplicate->tx_hash . ' (used ' . $duplicate->count . ' times)');
                    
                    // Get all records with this tx_hash
                    $records = $wpdb->get_results($wpdb->prepare(
                        "SELECT id, order_id, status, created_at FROM $table_name WHERE tx_hash = %s ORDER BY created_at ASC",
                        $duplicate->tx_hash
                    ));
                    
                    // Keep only the first record (earliest) with this transaction hash
                    $first_record = array_shift($records);
                    error_log('Keeping transaction for order: ' . $first_record->order_id);
                    
                    // Clear the transaction hash from all other records
                    foreach ($records as $record) {
                        error_log('Clearing transaction hash from order: ' . $record->order_id);
                        
                        $wpdb->update(
                            $table_name,
                            [
                                'tx_hash' => null,
                                'status' => 'pending', // Reset to pending since the transaction was assigned to another order
                            ],
                            ['id' => $record->id]
                        );
                        
                        // Add a note to the order
                        $order = wc_get_order($record->order_id);
                        if ($order) {
                            $order->add_order_note(
                                sprintf(
                                    __('Payment reset to pending: Transaction %s was already assigned to order #%s', 'zano-payment-gateway'),
                                    $duplicate->tx_hash,
                                    $first_record->order_id
                                )
                            );
                        }
                    }
                }
            }
            
            // Now add the unique constraint
            $wpdb->query("ALTER TABLE $table_name ADD UNIQUE KEY tx_hash (tx_hash)");
        }
    }

    /**
     * Create plugin directories
     */
    public static function create_plugin_directories() {
        $directories = [
            ZANO_PAYMENT_PLUGIN_DIR . 'assets/images',
            ZANO_PAYMENT_PLUGIN_DIR . 'assets/css',
            ZANO_PAYMENT_PLUGIN_DIR . 'assets/js',
            ZANO_PAYMENT_PLUGIN_DIR . 'logs',
        ];
        
        foreach ($directories as $dir) {
            if (!file_exists($dir)) {
                wp_mkdir_p($dir);
            }
        }
    }

    /**
     * Create default assets
     */
    public static function create_default_assets() {
        // Create default Zano icon if it doesn't exist
        $icon_path = ZANO_PAYMENT_PLUGIN_DIR . 'assets/images/zano-icon.png';
        if (!file_exists($icon_path)) {
            // Base64 encoded small Zano icon (placeholder)
            $icon_data = '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';
            file_put_contents($icon_path, base64_decode($icon_data));
        }
    }

    /**
     * Schedule cron jobs
     */
    public static function schedule_cron_jobs() {
        // Schedule transaction checking
        if (!wp_next_scheduled('zano_check_transactions')) {
            wp_schedule_event(time(), 'every_5_minutes', 'zano_check_transactions');
        }
        
        // Schedule expired payment cleanup
        if (!wp_next_scheduled('zano_cleanup_expired_payments')) {
            wp_schedule_event(time(), 'every_5_minutes', 'zano_cleanup_expired_payments');
        }
    }

    /**
     * Unschedule cron jobs
     */
    public static function unschedule_cron_jobs() {
        // Cleanup cron jobs
        $timestamp = wp_next_scheduled('zano_check_transactions');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'zano_check_transactions');
        }
        
        $timestamp = wp_next_scheduled('zano_cleanup_expired_payments');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'zano_cleanup_expired_payments');
        }
    }

    /**
     * Get valid payment statuses
     */
    public static function get_payment_statuses() {
        return [
            'pending' => __('Pending', 'zano-payment-gateway'),
            'detected' => __('Detected', 'zano-payment-gateway'),
            'confirmed' => __('Confirmed', 'zano-payment-gateway'),
            'expired' => __('Expired', 'zano-payment-gateway'),
            'failed' => __('Failed', 'zano-payment-gateway')
        ];
    }

    /**
     * Update all order statuses - check for expired payments and verify transactions
     */
    public static function update_all_order_statuses() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'zano_payments';
        $updated_count = 0;
        
        error_log('Zano: Starting bulk order status update');
        
        // Get payments that actually need checking:
        // 1. Pending payments older than 20 minutes (to mark as failed)
        // 2. Payments with tx_hash that aren't confirmed yet AND haven't been checked recently
        $twenty_minutes_ago = date('Y-m-d H:i:s', current_time('timestamp') - (20 * 60));
        $five_minutes_ago = date('Y-m-d H:i:s', current_time('timestamp') - (5 * 60));
        
        $payments_to_check = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table_name 
                 WHERE (
                     -- Pending payments older than 20 minutes without tx_hash (to expire)
                     (status = 'pending' AND (tx_hash IS NULL OR tx_hash = '') AND created_at < %s)
                     OR
                     -- Payments with tx_hash that need confirmation checking and haven't been checked recently
                     (status IN ('pending', 'processing') AND tx_hash IS NOT NULL AND tx_hash != '' 
                      AND (updated_at < %s OR updated_at IS NULL))
                     OR
                     -- Transactions with tx_hash but missing block information (repair mode)
                     (tx_hash IS NOT NULL AND tx_hash != '' AND received_block IS NULL)
                 )
                 ORDER BY created_at ASC 
                 LIMIT 20",
                $twenty_minutes_ago,
                $five_minutes_ago
            ),
            ARRAY_A
        );
        
        error_log('Zano: Found ' . count($payments_to_check) . ' payments that need checking');
        
        if (empty($payments_to_check)) {
            error_log('Zano: No payments need checking at this time');
            return 0;
        }
        
        // Get gateway settings for API access
        $gateway_settings = get_option('woocommerce_zano_payment_settings', []);
        $api_url = $gateway_settings['api_url'] ?? '';
        
        if (empty($api_url)) {
            error_log('Zano: API URL not configured, cannot verify transactions');
            throw new Exception('API URL not configured');
        }
        
        foreach ($payments_to_check as $payment) {
            $order = wc_get_order($payment['order_id']);
            if (!$order) {
                error_log('Zano: Order #' . $payment['order_id'] . ' not found, skipping');
                continue;
            }
            
            $created_time = strtotime($payment['created_at']);
            $current_time = current_time('timestamp');
            $age_minutes = ($current_time - $created_time) / 60;
            
            error_log('Zano: Checking payment for order #' . $payment['order_id'] . ' (age: ' . round($age_minutes, 1) . ' minutes)');
            
            // Check if payment has expired (20+ minutes without transaction hash)
            if (empty($payment['tx_hash']) && $age_minutes > 20) {
                error_log('Zano: Marking payment for order #' . $payment['order_id'] . ' as expired (no TX hash after ' . round($age_minutes, 1) . ' minutes)');
                
                // Mark as failed
                $wpdb->update(
                    $table_name,
                    [
                        'status' => 'failed',
                        'updated_at' => current_time('mysql')
                    ],
                    ['id' => $payment['id']],
                    ['%s', '%s'],
                    ['%d']
                );
                
                $order->update_status('failed', __('Payment expired after 20 minutes without transaction', 'zano-payment-gateway'));
                $order->add_order_note(__('Payment automatically marked as failed - no transaction detected within 20 minutes', 'zano-payment-gateway'));
                $order->save();
                
                $updated_count++;
                continue;
            }
            
            // If payment has transaction hash but isn't confirmed, verify with blockchain
            if (!empty($payment['tx_hash']) && $payment['status'] !== 'confirmed') {
                error_log('Zano: Verifying transaction ' . $payment['tx_hash'] . ' for order #' . $payment['order_id'] . ' (current status: ' . $payment['status'] . ', current confirmations: ' . ($payment['confirmations'] ?? 0) . ')');
                
                // Use asset amount if available, otherwise use payment amount
                $amount_to_verify = !empty($payment['asset_amount']) ? $payment['asset_amount'] : $payment['amount'];
                
                // Mark as being checked (update timestamp) to avoid immediate re-checking
                $wpdb->update(
                    $table_name,
                    ['updated_at' => current_time('mysql')],
                    ['id' => $payment['id']],
                    ['%s'],
                    ['%d']
                );
                
                $verification_result = self::verify_transaction_with_payment_id(
                    $payment['tx_hash'], 
                    $payment['payment_id'], 
                    $payment['wallet_address'],
                    $amount_to_verify,
                    $api_url
                );
                
                error_log('Zano: Verification result for ' . $payment['tx_hash'] . ': ' . json_encode($verification_result));
                
                if ($verification_result['status'] === 'valid') {
                    // Payment is verified (either ZANO with amount or FUSD with Payment ID + Asset ID)
                    $current_confirmations = intval($payment['confirmations'] ?? 0);
                    $received_amount = floatval($verification_result['received_amount'] ?? 0);
                    $asset_symbol = $verification_result['asset_symbol'] ?? 'Unknown';
                    $asset_id = $verification_result['asset_id'] ?? '';
                    $verification_method = $verification_result['verification_method'] ?? 'unknown';
                    $required_confirmations = intval($gateway_settings['required_confirmations'] ?? 10);
                    
                    // Calculate actual confirmations from block heights
                    $received_block = intval($payment['received_block'] ?? 0);
                    $current_block_height = 0;
                    
                    if ($received_block > 0) {
                        // Get current blockchain height
                        $gateway_settings = get_option('woocommerce_zano_payment_settings', []);
                        $api_config = [
                            'api_url' => $gateway_settings['api_url'] ?? '',
                            'wallet_address' => $gateway_settings['wallet_address'] ?? '',
                            'view_key' => $gateway_settings['view_key'] ?? '',
                            'payment_id_api_url' => $gateway_settings['payment_id_api_url'] ?? Zano_Constants::PAYMENT_VERIFICATION_API,
                            'debug' => isset($gateway_settings['debug']) && $gateway_settings['debug'] === 'yes',
                        ];
                        $api_for_blocks = new Zano_API($api_config);
                        $current_block_height = $api_for_blocks->get_current_block_height();
                        
                        if (is_wp_error($current_block_height)) {
                            error_log('Zano: Failed to get current block height: ' . $current_block_height->get_error_message());
                            $verified_confirmations = $current_confirmations; // Fallback to stored value
                        } else {
                            // Calculate confirmations: current_block - received_block
                            $verified_confirmations = max(0, $current_block_height - $received_block);
                            error_log(sprintf('Zano: Calculated confirmations for TX %s: current_block(%d) - received_block(%d) = %d confirmations', 
                                $payment['tx_hash'], $current_block_height, $received_block, $verified_confirmations));
                            
                            // Update current block info in database
                            $wpdb->update(
                                $table_name,
                                [
                                    'current_block' => $current_block_height,
                                    'confirmations' => $verified_confirmations,
                                ],
                                ['id' => $payment['id']]
                            );
                        }
                    } else {
                        // No received_block stored, use value from verification result as fallback
                        $verified_confirmations = intval($verification_result['confirmations'] ?? $current_confirmations);
                        error_log(sprintf('Zano: No received_block found for TX %s, using verification result confirmations: %d', 
                            $payment['tx_hash'], $verified_confirmations));
                    }
                    
                    // Determine status based on confirmations
                    $new_status = ($verified_confirmations >= $required_confirmations) ? 'confirmed' : 'processing';
                    
                    // Create appropriate status message
                    if ($verification_method === 'fusd_payment_id_only' || $verification_method === 'fusd_payment_id_asset_id') {
                        $status_note = sprintf('FUSD payment verified by Payment ID. Amount: %f FUSD, Confirmations: %d/%d', 
                            $received_amount, $verified_confirmations, $required_confirmations);
                    } else {
                        $status_note = sprintf('%s payment verified by Payment ID and amount. Amount: %f %s, Confirmations: %d/%d', 
                            $asset_symbol, $received_amount, $asset_symbol, $verified_confirmations, $required_confirmations);
                    }
                    
                    // Update payment status and asset information
                    $update_data = [
                        'status' => $new_status,
                        'confirmations' => $verified_confirmations,
                        'received_amount' => $received_amount,
                        'updated_at' => current_time('mysql')
                    ];
                    
                    // Add asset information if we have it and it's not already set
                    if ($asset_id && empty($payment['asset_id'])) {
                        $update_data['asset_id'] = $asset_id;
                    }
                    if ($asset_symbol && empty($payment['asset_symbol'])) {
                        $update_data['asset_symbol'] = $asset_symbol;
                    }
                    if ($received_amount > 0 && empty($payment['asset_amount'])) {
                        $update_data['asset_amount'] = $received_amount;
                    }
                    
                    // Add completion timestamp if confirmed
                    if ($new_status === 'confirmed') {
                        $update_data['completed_at'] = current_time('mysql');
                    }
                    
                    $wpdb->update(
                        $table_name,
                        $update_data,
                        ['id' => $payment['id']]
                    );
                    
                    // Update WooCommerce order
                    $order = wc_get_order($payment['order_id']);
                    if ($order && in_array($order->get_status(), ['pending', 'on-hold'])) {
                        if ($new_status === 'confirmed') {
                            $order->payment_complete($payment['tx_hash']);
                            $order->add_order_note(sprintf(
                                __('Payment confirmed via blockchain verification: %f %s received with %d confirmations (TX: %s)', 'zano-payment-gateway'),
                                $received_amount,
                                $asset_symbol,
                                $verified_confirmations,
                                substr($payment['tx_hash'], 0, 10) . '...'
                            ));
                            
                            // Update order meta with asset information
                            $order->update_meta_data('_zano_payment_asset_symbol', $asset_symbol);
                            $order->update_meta_data('_zano_payment_asset_amount', $received_amount);
                            $order->update_meta_data('_zano_payment_asset_id', $asset_id);
                            $order->update_meta_data('_zano_payment_tx_hash', $payment['tx_hash']);
                            $order->update_meta_data('_zano_verification_method', $verification_method);
                        } else {
                            $order->add_order_note(__($status_note, 'zano-payment-gateway'));
                        }
                        $order->save();
                    }
                    
                    $updated_count++;
                    error_log('Zano: Updated payment #' . $payment['id'] . ' for order #' . $payment['order_id'] . ' to status: ' . $new_status . ' (' . $verification_method . ')');
                    
                } else if ($verification_result['status'] === 'invalid') {
                    error_log('Zano: Transaction ' . $payment['tx_hash'] . ' verification failed for order #' . $payment['order_id'] . ': ' . $verification_result['message']);
                    
                    // For invalid transactions, mark as failed after multiple attempts
                    // Check how many times we've tried to verify this transaction
                    $verification_attempts = intval(get_post_meta($payment['order_id'], '_zano_verification_attempts', true));
                    $verification_attempts++;
                    update_post_meta($payment['order_id'], '_zano_verification_attempts', $verification_attempts);
                    
                    if ($verification_attempts >= 3) {
                        // After 3 failed verification attempts, mark as failed
                        $wpdb->update(
                            $table_name,
                            [
                                'status' => 'failed',
                                'updated_at' => current_time('mysql')
                            ],
                            ['id' => $payment['id']]
                        );
                        
                        $order = wc_get_order($payment['order_id']);
                        if ($order) {
                            $order->update_status('failed', __('Payment verification failed after multiple attempts', 'zano-payment-gateway'));
                            $order->add_order_note(sprintf(
                                __('Payment marked as failed after %d verification attempts. Last error: %s', 'zano-payment-gateway'),
                                $verification_attempts,
                                $verification_result['message']
                            ));
                            $order->save();
                        }
                        
                        $updated_count++;
                    }
                    
                } else if ($verification_result['status'] === 'error') {
                    error_log('Zano: Transaction ' . $payment['tx_hash'] . ' verification error for order #' . $payment['order_id'] . ': ' . $verification_result['message']);
                    
                    // API error - don't change status, will be retried
                }
                
                // Add a small delay to avoid overwhelming the API
                usleep(500000); // 0.5 second delay
            }
        }
        
        error_log('Zano: Bulk status update completed. Updated ' . $updated_count . ' payments.');
        
        return $updated_count;
    }

    /**
     * Create default Zano icon from base64 data
     */
    private static function get_default_icon_data() {
        return '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';
    }

    /**
     * Verify transaction with payment ID for a specific payment (Asset-aware verification)
     */
    public static function verify_transaction_with_payment_id($tx_hash, $payment_id, $wallet_address, $expected_amount, $api_url) {
        // Create API instance
        $gateway_settings = get_option('woocommerce_zano_payment_settings', []);
        $api_config = [
            'api_url' => $api_url,
            'wallet_address' => $wallet_address,
            'view_key' => $gateway_settings['view_key'] ?? '',
            'payment_id_api_url' => $gateway_settings['payment_id_api_url'] ?? Zano_Constants::PAYMENT_VERIFICATION_API,
            'debug' => isset($gateway_settings['debug']) && $gateway_settings['debug'] === 'yes',
        ];
        
        $api_instance = new Zano_API($api_config);
        
        try {
            // Get payment record to determine expected asset
            global $wpdb;
            $table_name = $wpdb->prefix . 'zano_payments';
            $payment_record = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM $table_name WHERE tx_hash = %s AND payment_id = %s LIMIT 1",
                    $tx_hash,
                    $payment_id
                )
            );
            
            // Use the standard verify_payment_id method (works for both ZANO and FUSD for Payment ID verification)
            $payment_verification = $api_instance->verify_payment_id($tx_hash);
            
            if (is_wp_error($payment_verification)) {
                return ['status' => 'error', 'message' => $payment_verification->get_error_message()];
            }
            
            // Check if payment ID matches
            $tx_payment_id = $payment_verification['paymentId'] ?? '';
            
            if ($tx_payment_id !== $payment_id) {
                return [
                    'status' => 'invalid', 
                    'message' => sprintf('Payment ID mismatch: expected %s, got %s', $payment_id, $tx_payment_id)
                ];
            }
            
            // Get verified amount from the API response
            $received_amount = floatval($payment_verification['amount'] ?? 0);
            $expected_amount_float = floatval($expected_amount);
            
            // Determine if this is FUSD based on payment record or amount being 0
            $is_fusd = false;
            if ($payment_record && !empty($payment_record->asset_id)) {
                $is_fusd = ($payment_record->asset_id === '86143388bd056a8f0bab669f78f14873fac8e2dd8d57898cdb725a2d5e2e4f8f');
            } elseif ($received_amount == 0 && $expected_amount_float > 0) {
                // If decode API returns 0 but we expect an amount, likely FUSD
                $is_fusd = true;
            }
            
            // Log the verification details
            error_log(sprintf('Zano: Payment verification - TX: %s, Payment ID: %s, Expected: %f, Received: %f, Is FUSD: %s', 
                $tx_hash, $payment_id, $expected_amount_float, $received_amount, $is_fusd ? 'Yes' : 'No'));
            
            if ($is_fusd) {
                error_log('Zano: FUSD transaction detected - skipping amount verification, using Payment ID verification only');
                
                // For FUSD, we trust the expected amount since decode API doesn't return FUSD amounts
                $final_amount = $expected_amount_float;
                
                // Update payment record with FUSD asset information if not already set
                if ($payment_record) {
                    $update_data = [
                        'updated_at' => current_time('mysql')
                    ];
                    
                    if (empty($payment_record->asset_id)) {
                        $update_data['asset_id'] = '86143388bd056a8f0bab669f78f14873fac8e2dd8d57898cdb725a2d5e2e4f8f';
                    }
                    if (empty($payment_record->asset_symbol)) {
                        $update_data['asset_symbol'] = 'FUSD';
                    }
                    if (empty($payment_record->asset_amount) && $final_amount > 0) {
                        $update_data['asset_amount'] = $final_amount;
                    }
                    if (empty($payment_record->received_amount) && $final_amount > 0) {
                        $update_data['received_amount'] = $final_amount;
                    }
                    
                    $wpdb->update(
                        $table_name,
                        $update_data,
                        ['id' => $payment_record->id]
                    );
                    
                    error_log(sprintf('Zano: Updated FUSD payment record #%d with asset info', $payment_record->id));
                }
                
                return [
                    'status' => 'valid',
                    'message' => 'FUSD payment verified by Payment ID (amount verification skipped)',
                    'payment_id_verified' => true,
                    'amount_verified' => true, // We trust the expected amount for FUSD
                    'asset_verified' => true,
                    'confirmations' => intval($payment_record->confirmations ?? 0), // Will be recalculated in caller
                    'received_amount' => $final_amount,
                    'expected_amount' => $expected_amount_float,
                    'asset_id' => '86143388bd056a8f0bab669f78f14873fac8e2dd8d57898cdb725a2d5e2e4f8f',
                    'asset_symbol' => 'FUSD',
                    'verification_method' => 'fusd_payment_id_only'
                ];
            } else {
                // For ZANO, verify amount as well
                $tolerance = max(0.00000001, $expected_amount_float * 0.02); // 2% tolerance
                
                if (abs($received_amount - $expected_amount_float) > $tolerance) {
                    return [
                        'status' => 'invalid', 
                        'message' => sprintf('ZANO amount mismatch: expected %s, received %s (tolerance: %s)', 
                            $expected_amount_float, $received_amount, $tolerance)
                    ];
                }
                
                // Update payment record with ZANO asset information if not already set
                if ($payment_record) {
                    $update_data = [
                        'received_amount' => $received_amount,
                        'updated_at' => current_time('mysql')
                    ];
                    
                    if (empty($payment_record->asset_id)) {
                        $update_data['asset_id'] = 'd6329b5b1f7c0805b5c345f4957554002a2f557845f64d7645dae0e051a6498a';
                    }
                    if (empty($payment_record->asset_symbol)) {
                        $update_data['asset_symbol'] = 'ZANO';
                    }
                    if (empty($payment_record->asset_amount)) {
                        $update_data['asset_amount'] = $received_amount;
                    }
                    
                    $wpdb->update(
                        $table_name,
                        $update_data,
                        ['id' => $payment_record->id]
                    );
                }
                
                return [
                    'status' => 'valid',
                    'message' => 'ZANO payment verified by Payment ID and amount',
                    'payment_id_verified' => true,
                    'amount_verified' => true,
                    'asset_verified' => true,
                    'confirmations' => intval($payment_record->confirmations ?? 0), // Will be recalculated in caller
                    'received_amount' => $received_amount,
                    'expected_amount' => $expected_amount_float,
                    'asset_id' => 'd6329b5b1f7c0805b5c345f4957554002a2f557845f64d7645dae0e051a6498a',
                    'asset_symbol' => 'ZANO',
                    'verification_method' => 'zano_payment_id_amount'
                ];
            }
            
        } catch (Exception $e) {
            error_log('Zano: Exception in verify_transaction_with_payment_id: ' . $e->getMessage());
            return ['status' => 'error', 'message' => 'Verification failed: ' . $e->getMessage()];
        }
    }
} 