<?php
defined('ABSPATH') || exit;

/**
 * Zano Transaction Monitor
 *
 * Monitors the Zano blockchain for incoming transactions to process payments.
 */
class Zano_Transaction_Monitor {

    /**
     * API instance
     *
     * @var Zano_API
     */
    private $api;

    /**
     * Debug mode
     *
     * @var bool
     */
    private $debug;

    /**
     * Constructor
     */
    public function __construct() {
        $gateway_settings = get_option('woocommerce_zano_payment_settings', []);
        $this->debug = isset($gateway_settings['debug']) && $gateway_settings['debug'] === 'yes';
        
        // Initialize API
        $api_config = [
            'api_url'            => $gateway_settings['api_url'] ?? '',
            'wallet_address'     => $gateway_settings['wallet_address'] ?? '',
            'view_key'           => $gateway_settings['view_key'] ?? '',
            'payment_id_api_url' => $gateway_settings['payment_id_api_url'] ?? Zano_Constants::PAYMENT_VERIFICATION_API,
            'debug'              => $this->debug,
        ];
        
        $this->api = new Zano_API($api_config);
        
        // Register cron hooks
        add_action('zano_check_transactions', [$this, 'check_transactions']);
    }

    /**
     * Check for new transactions and update pending payments
     */
    public function check_transactions() {
        global $wpdb;
        
        // Get payments that need checking - both pending and processing
        $table_name = $wpdb->prefix . 'zano_payments';
        $payments_to_check = $wpdb->get_results(
            "SELECT * FROM $table_name 
             WHERE status IN ('pending', 'processing') 
             ORDER BY created_at ASC", 
            ARRAY_A
        );
        
        if (empty($payments_to_check)) {
            $this->log('No payments to check (pending or processing)');
            return;
        }
        
        $this->log(sprintf('Found %d payments to check (pending: %d, processing: %d)', 
            count($payments_to_check),
            count(array_filter($payments_to_check, function($p) { return $p['status'] === 'pending'; })),
            count(array_filter($payments_to_check, function($p) { return $p['status'] === 'processing'; }))
        ));
        
        try {
            // Get recent transactions via API
            $transactions = $this->api->get_recent_transactions();
            
            if (is_wp_error($transactions)) {
                $this->log(sprintf('Error getting transactions: %s', $transactions->get_error_message()));
                return;
            }
            
            if (empty($transactions)) {
                $this->log('No recent transactions found');
                return;
            }
            
            $this->log(sprintf('Retrieved %d recent transactions', count($transactions)));
            
            // Process each payment that needs checking
            foreach ($payments_to_check as $payment) {
                $this->process_payment($payment, $transactions);
            }

            // Look for pending payments that are older than 15 minutes and haven't been paid
            $expired_payments = $wpdb->get_results(
                "SELECT p.* FROM $table_name p
                LEFT JOIN $wpdb->posts o ON p.order_id = o.ID
                WHERE p.status = 'pending'
                AND p.created_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)
                AND o.post_status IN ('wc-pending', 'wc-on-hold')",
                ARRAY_A
            );

            if (!empty($expired_payments)) {
                $this->log("Found " . count($expired_payments) . " expired pending payments");
                
                foreach ($expired_payments as $payment) {
                    $order = wc_get_order($payment['order_id']);
                    if ($order) {
                        $this->log("Cancelling expired payment for order #{$payment['order_id']}");
                        $order->update_status('cancelled', __('Payment expired - no transaction received within 15 minutes', 'zano-payment-gateway'));
                        
                        // Update payment status to expired
                        $wpdb->update(
                            $table_name,
                            ['status' => 'expired'],
                            ['id' => $payment['id']]
                        );
                    }
                }
            }
        } catch (Exception $e) {
            $this->log('Exception in check_transactions: ' . $e->getMessage());
        }
    }

    /**
     * Process a payment against transactions
     *
     * @param array $payment Payment record
     * @param array $transactions Recent transactions
     */
    private function process_payment($payment, $transactions) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'zano_payments';
        
        $this->log(sprintf('Processing payment #%d for order #%d', $payment['id'], $payment['order_id']));
        
        // Extract the payment details
        $required_amount = floatval($payment['amount']);
        
        // Get required confirmations from settings
        $gateway_settings = get_option('woocommerce_zano_payment_settings', []);
        $required_confirmations = isset($gateway_settings['confirmations']) ? 
            intval($gateway_settings['confirmations']) : 10;
        
        $this->log(sprintf('Required amount: %f ZANO', $required_amount));
        
        // Check if any transaction matches using PAYMENT ID VERIFICATION
        $matched_tx = null;
        foreach ($transactions as $tx) {
            $tx_amount = floatval($tx['amount'] ?? 0);
            $tx_hash = $tx['tx_hash'] ?? 'unknown';
            $tx_asset_id = $tx['asset_id'] ?? '';
            $tx_asset_symbol = $tx['asset_symbol'] ?? 'Unknown';
            
            $this->log(sprintf('Checking transaction: %s, amount: %f %s (Asset ID: %s)', 
                $tx_hash, $tx_amount, $tx_asset_symbol, $tx_asset_id));
            
            // CRITICAL: Verify Payment ID FIRST - this prevents wrong assignment
            $payment_verification = $this->api->verify_payment_id($tx_hash);
            if (is_wp_error($payment_verification)) {
                $this->log('Payment ID verification failed for transaction ' . $tx_hash . ': ' . $payment_verification->get_error_message() . ' - SKIPPING transaction');
                continue;
            }
            
            // Check if payment ID matches - MUST match exactly
            $tx_payment_id = $payment_verification['paymentId'] ?? '';
            $expected_payment_id = $payment['payment_id'];
            
            if ($tx_payment_id !== $expected_payment_id) {
                $this->log(sprintf(
                    'Payment ID mismatch for transaction %s - expected: %s, got: %s - SKIPPING transaction (prevents wrong assignment)',
                    $tx_hash,
                    $expected_payment_id,
                    $tx_payment_id
                ));
                continue;
            }
            
            $this->log(sprintf('Payment ID verified for transaction %s - Payment ID: %s matches', $tx_hash, $tx_payment_id));
            
            // Check if this transaction hash has already been used for another payment
            $existing_payment = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM $table_name WHERE tx_hash = %s AND id != %d",
                    $tx_hash,
                    $payment['id']
                )
            );
            
            if ($existing_payment) {
                $this->log(sprintf('CRITICAL ERROR: Transaction %s already used for order #%d! Skipping.', 
                    $tx_hash, $existing_payment->order_id));
                continue;
            }
            
            // Determine if this is FUSD based on asset ID from recent transactions
            $is_fusd = ($tx_asset_id === '86143388bd056a8f0bab669f78f14873fac8e2dd8d57898cdb725a2d5e2e4f8f');
            
            if ($is_fusd) {
                // For FUSD: only verify Payment ID, use amount from recent transactions
                $this->log(sprintf('FUSD transaction detected - using Payment ID verification only. Amount from recent transactions: %f FUSD', $tx_amount));
                
                $matched_tx = array_merge($tx, [
                    'verified_amount' => $tx_amount, // Use amount from find_outs_in_recent_blocks
                    'verified_asset_id' => $tx_asset_id,
                    'verified_asset_symbol' => $tx_asset_symbol,
                    'verification_method' => 'fusd_payment_id_recent_blocks'
                ]);
                break;
            } else {
                // For ZANO: verify both Payment ID and amount
                $verified_amount = floatval($payment_verification['amount'] ?? 0);
                if ($verified_amount > 0) {
                    $tx_amount = $verified_amount; // Use amount from verification API for ZANO
                    $this->log(sprintf('Using verified amount from decode API: %f ZANO', $tx_amount));
                }
                
                // Check if amount matches (with 2% tolerance for rounding issues)
                $amount_diff = abs($tx_amount - $required_amount);
                $amount_match = ($amount_diff <= $required_amount * 0.02);
                
                if ($amount_match) {
                    $this->log(sprintf('ZANO Payment ID and Amount verified for transaction %s - Amount: %f ZANO (required: %f ZANO, difference: %f)',
                        $tx_hash, $tx_amount, $required_amount, $amount_diff));
                    
                    $matched_tx = array_merge($tx, [
                        'verified_amount' => $tx_amount,
                        'verified_asset_id' => $tx_asset_id,
                        'verified_asset_symbol' => $tx_asset_symbol,
                        'verification_method' => 'zano_payment_id_amount'
                    ]);
                    break;
                } else {
                    $this->log(sprintf(
                        'Amount mismatch for ZANO transaction %s - expected: %f ZANO, got: %f ZANO, difference: %f ZANO - SKIPPING transaction',
                        $tx_hash,
                        $required_amount,
                        $tx_amount,
                        $amount_diff
                    ));
                }
            }
        }
        
        // Process the matched transaction (if any)
        if ($matched_tx) {
            $tx_hash = $matched_tx['tx_hash'] ?? '';
            $tx_amount = floatval($matched_tx['verified_amount'] ?? $matched_tx['amount'] ?? 0);
            $confirmations = intval($matched_tx['confirmations'] ?? 0);
            $asset_id = $matched_tx['verified_asset_id'] ?? $matched_tx['asset_id'] ?? '';
            $asset_symbol = $matched_tx['verified_asset_symbol'] ?? $matched_tx['asset_symbol'] ?? 'Unknown';
            $verification_method = $matched_tx['verification_method'] ?? 'unknown';
            
            // Double-check that this transaction hasn't been processed elsewhere
            $tx_used = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM $table_name WHERE tx_hash = %s AND id != %d",
                    $tx_hash,
                    $payment['id']
                )
            );
            
            if ($tx_used > 0) {
                $this->log(sprintf('Transaction %s was already assigned to another order - skipping', $tx_hash));
                return;
            }
            
            $this->log(sprintf('Processing matched transaction: hash=%s, amount=%f %s, confirmations=%d, method=%s', 
                $tx_hash, $tx_amount, $asset_symbol, $confirmations, $verification_method));
            
            // For mempool transactions (negative block height), set confirmations to 0
            if ($confirmations < 0) {
                $confirmations = 0;
            }
            
            // Determine the new status based on confirmations
            $new_status = ($confirmations >= $required_confirmations) ? 'confirmed' : 'processing';
            
            // Update payment with transaction info and asset details
            $update_data = [
                'tx_hash' => $tx_hash,
                'confirmations' => $confirmations,
                'received_amount' => $tx_amount,
                'status' => $new_status,
                'updated_at' => current_time('mysql')
            ];
            
            // Add asset information if we have it and it's not already set
            if ($asset_id && empty($payment['asset_id'])) {
                $update_data['asset_id'] = $asset_id;
            }
            if ($asset_symbol && empty($payment['asset_symbol'])) {
                $update_data['asset_symbol'] = $asset_symbol;
            }
            if ($tx_amount > 0 && empty($payment['asset_amount'])) {
                $update_data['asset_amount'] = $tx_amount;
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
            
            if ($new_status === 'confirmed') {
                $this->log(sprintf('Transaction confirmed with %d confirmations - updating order status', $confirmations));
                
                // Update corresponding WooCommerce order
                $order = wc_get_order($payment['order_id']);
                if ($order) {
                    // Only update order if it's not already completed
                    if (in_array($order->get_status(), ['on-hold', 'pending', 'processing'])) {
                        $order->payment_complete($tx_hash);
                        $order->add_order_note(sprintf(
                            __('%s payment confirmed via %s. Amount: %f %s, Transaction ID: %s, Confirmations: %d', 'zano-payment-gateway'),
                            $asset_symbol,
                            $verification_method === 'fusd_payment_id_recent_blocks' ? 'Payment ID verification' : 'Payment ID + Amount verification',
                            $tx_amount,
                            $asset_symbol,
                            $tx_hash,
                            $confirmations
                        ));
                        
                        // Update order meta with asset information
                        $order->update_meta_data('_zano_payment_asset_symbol', $asset_symbol);
                        $order->update_meta_data('_zano_payment_asset_amount', $tx_amount);
                        $order->update_meta_data('_zano_payment_asset_id', $asset_id);
                        $order->update_meta_data('_zano_payment_tx_hash', $tx_hash);
                        $order->update_meta_data('_zano_verification_method', $verification_method);
                        
                        $order->save();
                        
                        $this->log(sprintf('Order #%d completed with %s payment', $payment['order_id'], $asset_symbol));
                    }
                }
            } else {
                $this->log(sprintf('Transaction detected but waiting for confirmations: %d/%d - status set to processing', 
                    $confirmations, $required_confirmations));
                
                // Update order note if it's the first time we detect the transaction
                if ($payment['status'] === 'pending') {
                    $order = wc_get_order($payment['order_id']);
                    if ($order) {
                        $order->add_order_note(sprintf(
                            __('%s payment detected on blockchain. Amount: %f %s, Transaction ID: %s, Confirmations: %d/%d', 'zano-payment-gateway'),
                            $asset_symbol,
                            $tx_amount,
                            $asset_symbol,
                            $tx_hash,
                            $confirmations,
                            $required_confirmations
                        ));
                        $order->save();
                    }
                }
            }
        } else {
            $this->log(sprintf('No matching transaction found for payment #%d (order #%d).', $payment['id'], $payment['order_id']));
        }
    }

    /**
     * Log debug messages.
     *
     * @param string $message Message to log.
     */
    private function log($message) {
        if ($this->debug) {
            if (!is_dir(ZANO_PAYMENT_PLUGIN_DIR . 'logs')) {
                mkdir(ZANO_PAYMENT_PLUGIN_DIR . 'logs', 0755, true);
            }
            
            $log_file = ZANO_PAYMENT_PLUGIN_DIR . 'logs/zano-transactions.log';
            $timestamp = date('Y-m-d H:i:s');
            $log_message = sprintf("[%s] %s\n", $timestamp, $message);
            
            file_put_contents($log_file, $log_message, FILE_APPEND);
            
            // Additionally log to WC logger if available
            if (function_exists('wc_get_logger')) {
                $logger = wc_get_logger();
                $logger->debug($message, ['source' => 'zano-payment']);
            }
        }
    }
} 