<?php
/**
 * Zano Payment Gateway AJAX Handlers
 *
 * @package ZanoPaymentGateway
 */

defined('ABSPATH') || exit;

/**
 * AJAX handlers class
 */
class Zano_Ajax_Handlers {

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
        // QR code generation
        add_action('wp_ajax_zano_generate_qr', [$this, 'generate_qr_code']);
        add_action('wp_ajax_nopriv_zano_generate_qr', [$this, 'generate_qr_code']);

        // Payment asset update
        add_action('wp_ajax_zano_update_payment_asset', [$this, 'update_payment_asset']);
        add_action('wp_ajax_nopriv_zano_update_payment_asset', [$this, 'update_payment_asset']);

        // Payment status check
        add_action('wp_ajax_zano_check_payment', [$this, 'check_payment']);
        add_action('wp_ajax_nopriv_zano_check_payment', [$this, 'check_payment']);
    }

    /**
     * AJAX handler for QR code generation
     */
    public function generate_qr_code() {
        // Check if data parameter exists
        if (!isset($_GET['data'])) {
            wp_die('Missing data parameter');
        }
        
        $qr_generator = new Zano_QR_Generator();
        $qr_generator->generate_and_output();
    }

    /**
     * AJAX handler for updating payment asset
     */
    public function update_payment_asset() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'zano_payment_nonce')) {
            wp_die(__('Security check failed', 'zano-payment-gateway'));
        }
        
        $order_id = intval($_POST['order_id'] ?? 0);
        $asset_id = sanitize_text_field($_POST['asset_id'] ?? '');
        $asset_symbol = sanitize_text_field($_POST['asset_symbol'] ?? '');
        $asset_amount = floatval($_POST['asset_amount'] ?? 0);
        
        if (!$order_id || !$asset_id || !$asset_symbol || !$asset_amount) {
            wp_send_json_error(['message' => 'Missing required parameters']);
            return;
        }
        
        // Validate asset ID
        $valid_assets = [
            'd6329b5b1f7c0805b5c345f4957554002a2f557845f64d7645dae0e051a6498a' => 'ZANO',
            '86143388bd056a8f0bab669f78f14873fac8e2dd8d57898cdb725a2d5e2e4f8f' => 'FUSD'
        ];
        
        if (!isset($valid_assets[$asset_id]) || $valid_assets[$asset_id] !== $asset_symbol) {
            wp_send_json_error(['message' => 'Invalid asset']);
            return;
        }
        
        // Update payment record with asset information
        global $wpdb;
        $table_name = $wpdb->prefix . 'zano_payments';
        
        $result = $wpdb->update(
            $table_name,
            [
                'asset_id' => $asset_id,
                'asset_symbol' => $asset_symbol,
                'asset_amount' => $asset_amount,
                'updated_at' => current_time('mysql', true)
            ],
            ['order_id' => $order_id],
            ['%s', '%s', '%f', '%s'],
            ['%d']
        );
        
        if ($result === false) {
            wp_send_json_error(['message' => 'Failed to update payment record']);
            return;
        }
        
        // Store asset preference in order meta for future reference
        update_post_meta($order_id, '_zano_selected_asset', strtolower($asset_symbol));
        update_post_meta($order_id, '_zano_asset_id', $asset_id);
        update_post_meta($order_id, '_zano_asset_amount', $asset_amount);
        
        wp_send_json_success([
            'message' => 'Payment asset updated successfully',
            'asset_id' => $asset_id,
            'asset_symbol' => $asset_symbol,
            'asset_amount' => $asset_amount
        ]);
    }

    /**
     * AJAX handler for checking payment status
     */
    public function check_payment() {
        // Verify nonce for security
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'zano_payment_nonce')) {
            wp_send_json_error([
                'status' => 'error',
                'message' => __('Security verification failed', 'zano-payment-gateway')
            ]);
            return;
        }
        
        // Get order ID from request
        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        
        if (!$order_id) {
            wp_send_json_error([
                'status' => 'error',
                'message' => __('Invalid order ID', 'zano-payment-gateway')
            ]);
            return;
        }
        
        // Get order
        $order = wc_get_order($order_id);
        
        if (!$order) {
            wp_send_json_error([
                'status' => 'error',
                'message' => __('Order not found', 'zano-payment-gateway')
            ]);
            return;
        }
        
        // Get payment gateway instance
        $payment_gateways = WC()->payment_gateways()->payment_gateways();
        $gateway = isset($payment_gateways['zano_payment']) ? $payment_gateways['zano_payment'] : null;
        
        if (!$gateway) {
            wp_send_json_error([
                'status' => 'error',
                'message' => __('Payment gateway not available', 'zano-payment-gateway')
            ]);
            return;
        }
        
        // Create a new API instance
        $gateway_settings = get_option('woocommerce_zano_payment_settings', []);
        $api = new Zano_API([
            'api_url'        => $gateway_settings['api_url'] ?? '',
            'wallet_address' => $gateway_settings['wallet_address'] ?? '',
            'view_key'       => $gateway_settings['view_key'] ?? '',
            'payment_id_api_url' => $gateway_settings['payment_id_api_url'] ?? Zano_Constants::PAYMENT_VERIFICATION_API,
            'debug'          => isset($gateway_settings['debug']) && $gateway_settings['debug'] === 'yes',
        ]);
        
        // Check payment status using the API instance
        $status = $api->check_payment_status($order_id);
        
        // Prepare response data
        $response = [
            'status' => $status['status'],
            'order_id' => $order_id,
            'redirect_url' => $order->get_checkout_order_received_url(),
        ];
        
        // Add additional data if available
        if (isset($status['confirmations'])) {
            $response['confirmations'] = $status['confirmations'];
        }
        
        if (isset($status['tx_hash'])) {
            $response['tx_hash'] = $status['tx_hash'];
        }
        
        if (isset($status['message'])) {
            $response['message'] = $status['message'];
        }
        
        if (isset($status['amount'])) {
            $response['amount'] = $status['amount'];
        }
        
        // Get required confirmations from gateway settings
        $response['required_confirmations'] = $gateway->confirmations;
        
        wp_send_json_success($response);
    }
} 