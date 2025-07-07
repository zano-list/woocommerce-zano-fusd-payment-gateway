<?php
defined('ABSPATH') || exit;

/**
 * WooCommerce Zano Payment Gateway
 *
 * Provides a Zano Payment Gateway for WooCommerce.
 */
class Zano_Payment_Gateway extends WC_Payment_Gateway {

    /**
     * API Instance
     *
     * @var Zano_API
     */
    private $api;

    /**
     * Wallet address
     * 
     * @var string
     */
    public $wallet_address;
    
    /**
     * View key
     * 
     * @var string
     */
    public $view_key;
    
    /**
     * Price buffer
     * 
     * @var float
     */
    public $price_buffer;
    
    /**
     * Required confirmations
     * 
     * @var int
     */
    public $confirmations;
    
    /**
     * API URL
     * 
     * @var string
     */
    public $api_url;
    
    /**
     * Payment ID Verification API URL
     * 
     * @var string
     */
    public $payment_id_api_url;
    
    /**
     * Debug mode
     * 
     * @var bool
     */
    public $debug;
    
    /**
     * Test mode
     * 
     * @var bool
     */
    public $test_mode;

    /**
     * Constructor for the gateway.
     */
    public function __construct() {
        $this->id                 = 'zano_payment';
        $this->icon               = ZANO_PAYMENT_PLUGIN_URL . 'assets/images/zano-icon.png';
        $this->has_fields         = false;
        $this->method_title       = __('Zano Payment', 'zano-payment-gateway');
        $this->method_description = __('Accept Zano cryptocurrency payments directly to your wallet.', 'zano-payment-gateway');
        $this->supports           = ['products'];

        // Load the settings
        $this->init_form_fields();
        $this->init_settings();

        // Define user-set variables
        $this->title              = $this->get_option('title');
        $this->description        = $this->get_option('description');
        $this->enabled            = $this->get_option('enabled');
        $this->wallet_address     = $this->get_option('wallet_address');
        $this->view_key           = $this->get_option('view_key');
        $this->price_buffer       = $this->get_option('price_buffer');
        $this->confirmations      = $this->get_option('confirmations');
        $this->api_url            = $this->get_option('api_url');
        $this->payment_id_api_url = $this->get_option('payment_id_api_url');
        $this->debug              = 'yes' === $this->get_option('debug');
        $this->test_mode          = 'yes' === $this->get_option('test_mode');
        
        // Set test mode constant based on settings
        if (!defined('ZANO_TEST_MODE')) {
            define('ZANO_TEST_MODE', $this->test_mode);
        }

        // Initialize API
        $api_config = [
            'api_url'             => $this->api_url,
            'wallet_address'      => $this->wallet_address,
            'view_key'            => $this->view_key,
            'payment_id_api_url'  => $this->payment_id_api_url,
            'debug'               => $this->debug,
        ];
        
        $this->api = new Zano_API($api_config);

        // Actions
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        add_action('woocommerce_thankyou_' . $this->id, [$this, 'thankyou_page']);
        add_action('woocommerce_email_before_order_table', [$this, 'email_instructions'], 10, 3);
    }

    /**
     * Initialize Gateway Settings Form Fields.
     */
    public function init_form_fields() {
        $this->form_fields = [
            'enabled' => [
                'title'   => __('Enable/Disable', 'zano-payment-gateway'),
                'type'    => 'checkbox',
                'label'   => __('Enable Zano Payment', 'zano-payment-gateway'),
                'default' => 'no',
            ],
            'test_mode' => [
                'title'       => __('Test Mode', 'zano-payment-gateway'),
                'type'        => 'checkbox',
                'label'       => __('Enable test mode', 'zano-payment-gateway'),
                'description' => __('Test mode uses simulated transactions for testing. No real Zano will be transferred.', 'zano-payment-gateway'),
                'default'     => 'yes',
            ],
            'title' => [
                'title'       => __('Title', 'zano-payment-gateway'),
                'type'        => 'text',
                'description' => __('This controls the title which the user sees during checkout.', 'zano-payment-gateway'),
                'default'     => __('Zano Payment', 'zano-payment-gateway'),
                'desc_tip'    => true,
            ],
            'description' => [
                'title'       => __('Description', 'zano-payment-gateway'),
                'type'        => 'textarea',
                'description' => __('This controls the description which the user sees during checkout.', 'zano-payment-gateway'),
                'default'     => __('Pay with Zano cryptocurrency. Payments are processed instantly and securely.', 'zano-payment-gateway'),
                'desc_tip'    => true,
            ],
            'wallet_address' => [
                'title'       => __('Wallet Address', 'zano-payment-gateway'),
                'type'        => 'text',
                'description' => __('Your Zano wallet address where payments will be sent. This is your public address that starts with "Zx".', 'zano-payment-gateway'),
                'default'     => '',
                'desc_tip'    => true,
            ],
            'view_key' => [
                'title'       => __('Private View Key', 'zano-payment-gateway'),
                'type'        => 'password',
                'description' => __('Your Zano wallet private view key (for monitoring incoming transactions). This allows checking payments but not spending funds. Can be obtained from your Zano wallet settings.', 'zano-payment-gateway'),
                'default'     => '',
                'desc_tip'    => true,
            ],
            'price_buffer' => [
                'title'       => __('Price Buffer (%)', 'zano-payment-gateway'),
                'type'        => 'number',
                'description' => __('Additional percentage to add to the converted ZANO amount to account for price fluctuations (e.g., 1 = add 1%).', 'zano-payment-gateway'),
                'default'     => '1',
                'desc_tip'    => true,
                'custom_attributes' => [
                    'min'  => '0',
                    'max'  => '10',
                    'step' => '0.1',
                ],
            ],
            'confirmations' => [
                'title'       => __('Required Confirmations', 'zano-payment-gateway'),
                'type'        => 'number',
                'description' => __('Number of blockchain confirmations required to consider payment complete.', 'zano-payment-gateway'),
                'default'     => '10',
                'desc_tip'    => true,
            ],
            'api_url' => [
                'title'       => __('Zano Node API URL', 'zano-payment-gateway'),
                'type'        => 'text',
                'description' => __('The URL of your Zano node API. Example: http://37.27.100.59:10500/json_rpc', 'zano-payment-gateway'),
                'default'     => 'http://37.27.100.59:10500/json_rpc',
                'desc_tip'    => true,
            ],
            'payment_id_api_url' => [
                'title'       => __('Payment ID Verification API URL', 'zano-payment-gateway'),
                'type'        => 'text',
                'description' => __('The base URL for Payment ID verification API. Use our free service or set up your own backend. Example: https://zanowordpressplugin.com', 'zano-payment-gateway'),
                'default'     => Zano_Constants::PAYMENT_VERIFICATION_API,
                'desc_tip'    => true,
            ],
            'debug' => [
                'title'       => __('Debug Log', 'zano-payment-gateway'),
                'type'        => 'checkbox',
                'label'       => __('Enable logging', 'zano-payment-gateway'),
                'default'     => 'yes',
                'description' => __('Log payment and API events for debugging', 'zano-payment-gateway'),
            ],
        ];
    }

    /**
     * Process the payment and return the result.
     *
     * @param int $order_id Order ID.
     * @return array
     */
    public function process_payment($order_id) {
        $order = wc_get_order($order_id);

        if (!$order) {
            wc_add_notice(__('Order not found', 'zano-payment-gateway'), 'error');
            return [
                'result' => 'failure',
                'redirect' => '',
            ];
        }
        
        // Get order total in store currency (assumed to be USD)
        $usd_amount = floatval($order->get_total());
        
        if ($this->debug) {
            $this->log('Processing payment for order #' . $order_id . ' with amount $' . $usd_amount . ' USD');
        }
        
        // Get buffer percentage from settings
        $buffer_percent = floatval($this->price_buffer);
        
        // Convert USD to ZANO with configured buffer
        $zano_amount = $this->api->convert_usd_to_zano($usd_amount, $buffer_percent);
        
        if (is_wp_error($zano_amount)) {
            // If price API fails, fall back to 1:1 conversion (this will only happen if MEXC API is down)
            $this->log('Error converting to ZANO: ' . $zano_amount->get_error_message() . '. Falling back to direct amount.');
            $zano_amount = $usd_amount;
        }
        
        // Format amount to max 8 decimal places to avoid rounding issues
        $zano_amount = number_format($zano_amount, 8, '.', '');
        
        // Log the final amount
        if ($this->debug) {
            $this->log('Final payment amount for order #' . $order_id . ': ' . $zano_amount . ' ZANO (with ' . $buffer_percent . '% buffer)');
        }
        
        // Generate a payment ID
        $payment_id = $this->api->generate_payment_id();
        
        // Generate integrated address
        $integrated_address = $this->api->generate_integrated_address($payment_id);
        if (is_wp_error($integrated_address)) {
            if ($this->debug) {
                $this->log('Error generating integrated address: ' . $integrated_address->get_error_message());
            }
            wc_add_notice(__('Error generating payment address. Please try again.', 'zano-payment-gateway'), 'error');
            return [
                'result' => 'failure',
                'redirect' => '',
            ];
        }
        
        // Insert payment record
        global $wpdb;
        $table_name = $wpdb->prefix . 'zano_payments';
        
        $wpdb->insert(
            $table_name,
            [
                'order_id' => $order_id,
                'payment_id' => $payment_id,
                'wallet_address' => $integrated_address,
                'amount' => $zano_amount,
                'status' => 'pending',
                'created_at' => current_time('mysql', true),
                'updated_at' => current_time('mysql', true),
            ],
            [
                '%d',
                '%s',
                '%s',
                '%f',
                '%s',
                '%s',
                '%s'
            ]
        );
        
        if ($wpdb->last_error) {
            if ($this->debug) {
                $this->log('Database error when creating payment record: ' . $wpdb->last_error);
            }
            wc_add_notice(__('Error processing payment. Please try again.', 'zano-payment-gateway'), 'error');
            return [
                'result' => 'failure',
                'redirect' => '',
            ];
        }
        
        // Update order status
        $order->update_status('on-hold', __('Awaiting Zano payment', 'zano-payment-gateway'));
        
        // Add order note with payment details
        $order->add_order_note(sprintf(
            __('Awaiting payment of %s ZANO to integrated address %s (converted from $%s USD with %s%% buffer). Payment ID: %s', 'zano-payment-gateway'),
            $zano_amount,
            $integrated_address,
            $usd_amount,
            $buffer_percent,
            $payment_id
        ));
        
        // Reduce stock levels
        wc_reduce_stock_levels($order_id);

        // Remove cart
        WC()->cart->empty_cart();

        // Redirect to payment page instead of thank you page
        return [
            'result' => 'success',
            'redirect' => add_query_arg(['order_id' => $order_id], WC()->api_request_url('zano_payment_page')),
        ];
    }

    /**
     * Output for the order received page.
     *
     * @param int $order_id Order ID.
     */
    public function thankyou_page($order_id) {
        $order = wc_get_order($order_id);
        
        if (!$order || $order->get_payment_method() !== $this->id) {
            return;
        }
            
        // Don't show payment instructions if the order is already processing or completed
        if (in_array($order->get_status(), ['processing', 'completed'])) {
            echo '<div class="woocommerce-notice woocommerce-notice--success">';
            echo '<p>' . __('Payment has been confirmed and your order is being processed. Thank you!', 'zano-payment-gateway') . '</p>';
            echo '</div>';
            return;
        }
        
        // For pending orders, show a message indicating payment is being processed
        echo '<div class="woocommerce-notice woocommerce-notice--info">';
        echo '<p>' . __('Your payment has been detected and will be confirmed soon. We are waiting for blockchain confirmations to complete your order.', 'zano-payment-gateway') . '</p>';
        echo '<p>' . __('You will receive an email confirmation once your payment is fully processed. Thank you for your patience!', 'zano-payment-gateway') . '</p>';
        echo '</div>';
    }

    /**
     * Add content to the WC emails.
     *
     * @param WC_Order $order Order object.
     * @param bool     $sent_to_admin Sent to admin.
     * @param bool     $plain_text Email format: plain text or HTML.
     */
    public function email_instructions($order, $sent_to_admin, $plain_text = false) {
        if ($sent_to_admin || $order->get_payment_method() !== $this->id) {
            return;
        }
        
        // Don't show instructions if the order is already paid
        if (in_array($order->get_status(), ['processing', 'completed'])) {
            return;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'zano_payments';
        
        $payment = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table_name WHERE order_id = %d", $order->get_id()),
            ARRAY_A
        );

        if (!$payment) {
            return;
        }

        $amount = $payment['amount'];
        
        if ($plain_text) {
            echo "\n\n" . __('Payment Instructions:', 'zano-payment-gateway') . "\n\n";
            echo __('Amount:', 'zano-payment-gateway') . ' ' . $amount . " ZANO\n";
            echo __('Address:', 'zano-payment-gateway') . ' ' . $payment['wallet_address'] . "\n";
            echo __('Note: This integrated address includes payment tracking. Just send the exact amount to this address.', 'zano-payment-gateway') . "\n";
        } else {
            echo '<h2>' . __('Payment Instructions', 'zano-payment-gateway') . '</h2>';
            echo '<p>' . __('Please send the exact amount of Zano to the following address:', 'zano-payment-gateway') . '</p>';
            echo '<ul>';
            echo '<li><strong>' . __('Amount:', 'zano-payment-gateway') . '</strong> ' . $amount . ' ZANO</li>';
            echo '<li><strong>' . __('Address:', 'zano-payment-gateway') . '</strong> ' . $payment['wallet_address'] . '</li>';
            echo '</ul>';
            echo '<p>' . __('Note: This integrated address includes payment tracking. Your payment will be confirmed after it reaches the required number of confirmations on the Zano blockchain.', 'zano-payment-gateway') . '</p>';
        }
    }

    /**
     * Check if API connection is working.
     *
     * @return bool|WP_Error
     */
    public function check_api_connection() {
        return $this->api->test_connection();
    }

    /**
     * Validate API URL Field.
     *
     * @param string $key Field key.
     * @param string $value Field value.
     * @return string
     */
    public function validate_api_url_field($key, $value) {
        if (empty($value)) {
            WC_Admin_Settings::add_error(esc_html__('API URL is required.', 'zano-payment-gateway'));
            return '';
        }
        
        // Ensure URL has protocol
        if (!preg_match('~^(?:f|ht)tps?://~i', $value)) {
            $value = 'http://' . $value;
        }
        
        // Add /json_rpc if missing
        if (!preg_match('/json_rpc$/', $value)) {
            if (substr($value, -1) !== '/') {
                $value .= '/';
                        }
            $value .= 'json_rpc';
                    }
        
        return $value;
    }
    
    /**
     * Validate wallet address field.
     *
     * @param string $key Field key.
     * @param string $value Field value.
     * @return string
     */
    public function validate_wallet_address_field($key, $value) {
        if (empty($value)) {
            WC_Admin_Settings::add_error(esc_html__('Wallet address is required.', 'zano-payment-gateway'));
            return '';
        }
        
        // Check if wallet address starts with Zx
        if (substr($value, 0, 2) !== 'Zx') {
            WC_Admin_Settings::add_error(esc_html__('Invalid Zano wallet address. Address must start with "Zx".', 'zano-payment-gateway'));
        }
        
        return $value;
    }
    
    /**
     * Validate view key field.
     *
     * @param string $key Field key.
     * @param string $value Field value.
     * @return string
     */
    public function validate_view_key_field($key, $value) {
        if (empty($value)) {
            WC_Admin_Settings::add_error(esc_html__('View key is required for payment verification.', 'zano-payment-gateway'));
            return '';
        }
        
        // Check if view key is a valid hex string (40 or 64 characters)
        if (!ctype_xdigit($value) || (strlen($value) !== 40 && strlen($value) !== 64)) {
            WC_Admin_Settings::add_error(esc_html__('Invalid view key format. View key must be a 40 or 64 character hexadecimal string.', 'zano-payment-gateway'));
        }
        
        return $value;
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
            
            $log_file = ZANO_PAYMENT_PLUGIN_DIR . 'logs/zano-gateway.log';
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

    /**
     * Render admin options with additional information
     */
    public function admin_options() {
        ?>
        <h2><?php echo esc_html($this->get_method_title()); ?> <?php echo esc_html__('Settings', 'zano-payment-gateway'); ?></h2>
        
        <?php
        // Get current ZANO price
        $price = $this->api->get_zano_price();
        if (!is_wp_error($price)) {
            echo '<div class="notice notice-info inline">';
            echo '<p>' . sprintf(
                __('Current ZANO price: $%s USD (from MEXC Exchange)', 'zano-payment-gateway'),
                number_format($price, 2)
            ) . '</p>';
            echo '</div>';
        }
        ?>
        
        <table class="form-table">
            <?php $this->generate_settings_html(); ?>
        </table>
        <?php
    }
} 