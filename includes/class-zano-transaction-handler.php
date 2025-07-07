<?php
/**
 * Zano Transaction Handler
 * 
 * Handles asset-specific transaction completion and storage
 *
 * @package Zano_Payment_Gateway
 */

defined('ABSPATH') || exit;

class Zano_Transaction_Handler {
    
    /**
     * Asset IDs
     */
    const ZANO_ASSET_ID = 'd6329b5b1f7c0805b5c345f4957554002a2f557845f64d7645dae0e051a6498a';
    const FUSD_ASSET_ID = '86143388bd056a8f0bab669f78f14873fac8e2dd8d57898cdb725a2d5e2e4f8f';
    
    /**
     * Asset information
     */
    private static $assets = [
        'd6329b5b1f7c0805b5c345f4957554002a2f557845f64d7645dae0e051a6498a' => [
            'symbol' => 'ZANO',
            'name' => 'Zano',
            'decimals' => 8
        ],
        '86143388bd056a8f0bab669f78f14873fac8e2dd8d57898cdb725a2d5e2e4f8f' => [
            'symbol' => 'FUSD',
            'name' => 'Fakechain USD',
            'decimals' => 6
        ]
    ];
    
    /**
     * Process payment completion with asset information
     */
    public static function complete_payment($order_id, $payment_id, $asset_id, $amount, $tx_hash = null) {
        global $wpdb;
        
        $order = wc_get_order($order_id);
        if (!$order) {
            return false;
        }
        
        // Validate asset ID
        if (!isset(self::$assets[$asset_id])) {
            error_log("Unknown asset ID: $asset_id");
            return false;
        }
        
        $asset_info = self::$assets[$asset_id];
        
        // Update payment record with asset information
        $wpdb->update(
            $wpdb->prefix . 'zano_payments',
            [
                'status' => 'completed',
                'asset_id' => $asset_id,
                'asset_symbol' => $asset_info['symbol'],
                'asset_amount' => $amount,
                'tx_hash' => $tx_hash,
                'completed_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ],
            ['id' => $payment_id],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s'],
            ['%d']
        );
        
        // Add order note with asset information
        $order->add_order_note(sprintf(
            __('Payment completed: %s %s (Asset ID: %s)', 'zano-payment-gateway'),
            $amount,
            $asset_info['symbol'],
            $asset_id
        ));
        
        // Store asset information in order meta
        $order->update_meta_data('_zano_payment_asset_id', $asset_id);
        $order->update_meta_data('_zano_payment_asset_symbol', $asset_info['symbol']);
        $order->update_meta_data('_zano_payment_asset_amount', $amount);
        $order->update_meta_data('_zano_payment_tx_hash', $tx_hash);
        
        // Complete the order
        $order->payment_complete($tx_hash);
        $order->save();
        
        return true;
    }
    
    /**
     * Get asset information by ID
     */
    public static function get_asset_info($asset_id) {
        return isset(self::$assets[$asset_id]) ? self::$assets[$asset_id] : null;
    }
    
    /**
     * Validate asset amount based on decimals
     */
    public static function validate_asset_amount($asset_id, $amount) {
        $asset_info = self::get_asset_info($asset_id);
        if (!$asset_info) {
            return false;
        }
        
        // Check if amount has correct decimal places
        $decimal_places = strlen(substr(strrchr($amount, "."), 1));
        return $decimal_places <= $asset_info['decimals'];
    }
    
    /**
     * Format asset amount for display
     */
    public static function format_asset_amount($asset_id, $amount) {
        $asset_info = self::get_asset_info($asset_id);
        if (!$asset_info) {
            return $amount;
        }
        
        return number_format((float)$amount, $asset_info['decimals'], '.', '');
    }
    
    /**
     * Handle AJAX payment status check with asset information
     */
    public static function ajax_check_payment_status() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['_wpnonce'], 'zano_check_payment')) {
            wp_die('Security check failed');
        }
        
        $payment_id = sanitize_text_field($_POST['payment_id']);
        $order_id = sanitize_text_field($_POST['order_id']);
        $asset_id = sanitize_text_field($_POST['asset_id']);
        $asset_symbol = sanitize_text_field($_POST['asset_symbol']);
        $asset_amount = sanitize_text_field($_POST['asset_amount']);
        
        global $wpdb;
        
        // Get payment record
        $payment = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}zano_payments WHERE id = %d AND order_id = %d",
            $payment_id,
            $order_id
        ), ARRAY_A);
        
        if (!$payment) {
            wp_send_json_error('Payment not found');
        }
        
        // Check if payment has expired (20 minutes without transaction hash)
        if (self::is_payment_expired($payment)) {
            self::mark_payment_as_failed($payment_id, $order_id, 'Payment expired after 20 minutes');
            
            wp_send_json_success([
                'status' => 'failed',
                'message' => 'Payment expired after 20 minutes',
                'asset_symbol' => $asset_symbol,
                'asset_amount' => $asset_amount
            ]);
        }
        
        // Check payment status via API (implement your API call here)
        $status_result = self::check_payment_on_blockchain($payment['wallet_address'], $asset_id, $asset_amount);
        
        if ($status_result['status'] === 'confirmed') {
            // Complete the payment
            self::complete_payment($order_id, $payment_id, $asset_id, $asset_amount, $status_result['tx_hash']);
            
            wp_send_json_success([
                'status' => 'confirmed',
                'asset_symbol' => $asset_symbol,
                'asset_amount' => $asset_amount,
                'tx_hash' => $status_result['tx_hash']
            ]);
        } elseif ($status_result['status'] === 'detected') {
            // Update payment with transaction hash and schedule monitoring
            $wpdb->update(
                $wpdb->prefix . 'zano_payments',
                [
                    'tx_hash' => $status_result['tx_hash'],
                    'status' => 'detected',
                    'confirmations' => $status_result['confirmations'],
                    'updated_at' => current_time('mysql')
                ],
                ['id' => $payment_id],
                ['%s', '%s', '%d', '%s'],
                ['%d']
            );
            
            // Schedule automatic monitoring for this specific transaction
            self::schedule_transaction_monitoring($payment_id, $status_result['tx_hash']);
            
            wp_send_json_success([
                'status' => 'detected',
                'confirmations' => $status_result['confirmations'],
                'asset_symbol' => $asset_symbol,
                'asset_amount' => $asset_amount,
                'tx_hash' => $status_result['tx_hash']
            ]);
        } else {
            wp_send_json_success([
                'status' => 'pending',
                'asset_symbol' => $asset_symbol,
                'asset_amount' => $asset_amount
            ]);
        }
    }
    
    /**
     * Check if payment has expired (20 minutes without transaction hash)
     */
    public static function is_payment_expired($payment) {
        // Only check expiration for pending payments without transaction hash
        if ($payment['status'] !== 'pending' || !empty($payment['tx_hash'])) {
            return false;
        }
        
        $created_time = strtotime($payment['created_at']);
        $current_time = current_time('timestamp');
        $expiration_time = 20 * 60; // 20 minutes in seconds
        
        return ($current_time - $created_time) > $expiration_time;
    }
    
    /**
     * Mark payment as failed
     */
    public static function mark_payment_as_failed($payment_id, $order_id, $reason = 'Payment failed') {
        global $wpdb;
        
        // Update payment status
        $wpdb->update(
            $wpdb->prefix . 'zano_payments',
            [
                'status' => 'failed',
                'updated_at' => current_time('mysql')
            ],
            ['id' => $payment_id],
            ['%s', '%s'],
            ['%d']
        );
        
        // Update order status and add note
        $order = wc_get_order($order_id);
        if ($order) {
            $order->update_status('failed', $reason);
            $order->add_order_note($reason);
            $order->save();
        }
        
        return true;
    }
    
    /**
     * Schedule automatic monitoring for a specific transaction
     * This will check the transaction every 5 minutes until it's confirmed or failed
     */
    public static function schedule_transaction_monitoring($payment_id, $tx_hash) {
        $hook_name = 'zano_monitor_transaction_' . $payment_id;
        
        // Don't schedule if already scheduled
        if (wp_next_scheduled($hook_name, [$payment_id, $tx_hash])) {
            return;
        }
        
        // Schedule to run every 5 minutes for up to 2 hours (24 attempts)
        wp_schedule_event(
            time() + 300, // Start in 5 minutes
            'zano_every_five_minutes',
            $hook_name,
            [$payment_id, $tx_hash]
        );
        
        error_log("Scheduled automatic monitoring for payment $payment_id with tx_hash $tx_hash");
    }
    
    /**
     * Monitor a specific transaction (called by individual cron jobs)
     */
    public static function monitor_specific_transaction($payment_id, $tx_hash) {
        global $wpdb;
        
        // Get payment record
        $payment = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}zano_payments WHERE id = %d",
            $payment_id
        ), ARRAY_A);
        
        if (!$payment) {
            // Payment not found, unschedule the job
            self::unschedule_transaction_monitoring($payment_id);
            return;
        }
        
        // If payment is already confirmed or failed, unschedule monitoring
        if (in_array($payment['status'], ['confirmed', 'failed', 'cancelled'])) {
            self::unschedule_transaction_monitoring($payment_id);
            return;
        }
        
        // Check if monitoring has been running too long (2 hours)
        $created_time = strtotime($payment['created_at']);
        if ((current_time('timestamp') - $created_time) > (2 * 60 * 60)) {
            // Mark as failed and unschedule
            self::mark_payment_as_failed($payment_id, $payment['order_id'], 'Transaction monitoring timeout after 2 hours');
            self::unschedule_transaction_monitoring($payment_id);
            return;
        }
        
        // Check transaction status
        $status_result = self::check_payment_on_blockchain($payment['wallet_address'], $payment['asset_id'], $payment['amount']);
        
        if ($status_result['status'] === 'confirmed') {
            // Transaction confirmed, complete payment
            self::complete_payment($payment['order_id'], $payment_id, $payment['asset_id'], $payment['amount'], $tx_hash);
            self::unschedule_transaction_monitoring($payment_id);
            error_log("Automatic monitoring: Payment $payment_id confirmed and completed");
        } elseif ($status_result['status'] === 'detected') {
            // Update confirmations
            $wpdb->update(
                $wpdb->prefix . 'zano_payments',
                [
                    'confirmations' => $status_result['confirmations'],
                    'updated_at' => current_time('mysql')
                ],
                ['id' => $payment_id],
                ['%d', '%s'],
                ['%d']
            );
            
            // Continue monitoring
            error_log("Automatic monitoring: Payment $payment_id still pending with {$status_result['confirmations']} confirmations");
        } else {
            // Transaction not found or failed, continue monitoring for now
            error_log("Automatic monitoring: Payment $payment_id transaction not found, continuing to monitor");
        }
    }
    
    /**
     * Unschedule monitoring for a specific transaction
     */
    public static function unschedule_transaction_monitoring($payment_id) {
        $hook_name = 'zano_monitor_transaction_' . $payment_id;
        wp_clear_scheduled_hook($hook_name);
        error_log("Unscheduled automatic monitoring for payment $payment_id");
    }
    
    /**
     * Cleanup expired payments (runs via scheduled task)
     */
    public static function cleanup_expired_payments() {
        global $wpdb;
        
        // Find all pending payments older than 20 minutes without transaction hash
        $expired_payments = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}zano_payments 
             WHERE status = 'pending' 
             AND (tx_hash IS NULL OR tx_hash = '') 
             AND created_at < %s",
            date('Y-m-d H:i:s', current_time('timestamp') - (20 * 60))
        ), ARRAY_A);
        
        foreach ($expired_payments as $payment) {
            self::mark_payment_as_failed($payment['id'], $payment['order_id'], 'Payment expired after 20 minutes');
        }
        
        return count($expired_payments);
    }
    
    /**
     * Check payment status on blockchain (implement your API logic here)
     */
    private static function check_payment_on_blockchain($wallet_address, $asset_id, $expected_amount) {
        // TODO: Implement actual blockchain API call
        // This is a placeholder - you'll need to implement the actual API call
        // to check for transactions with the specific asset ID
        
        return [
            'status' => 'pending', // 'pending', 'detected', 'confirmed'
            'confirmations' => 0,
            'tx_hash' => null
        ];
    }
}

// Hook the AJAX handler
add_action('wp_ajax_check_zano_payment', ['Zano_Transaction_Handler', 'ajax_check_payment_status']);
add_action('wp_ajax_nopriv_check_zano_payment', ['Zano_Transaction_Handler', 'ajax_check_payment_status']); 