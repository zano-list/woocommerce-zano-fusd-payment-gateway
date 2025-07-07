<?php
/**
 * Zano Payment Gateway Payment Page Handler
 *
 * @package ZanoPaymentGateway
 */

defined('ABSPATH') || exit;

/**
 * Payment page handler class
 */
class Zano_Payment_Page_Handler {

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
        // Register custom API endpoint for payment page
        add_action('woocommerce_api_zano_payment_page', [$this, 'handle_payment_page']);
    }

    /**
     * Handle payment page display
     */
    public function handle_payment_page() {
        $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
        
        if (!$order_id) {
            wp_redirect(wc_get_page_permalink('checkout'));
            exit;
        }
        
        // Verify order and payment method
        $order = wc_get_order($order_id);
        if (!$order || $order->get_payment_method() !== 'zano_payment') {
            wp_redirect(wc_get_page_permalink('checkout'));
            exit;
        }
        
        // Ensure user has permission to view this order
        // Get order to check key
        $order = wc_get_order($order_id);
        if (!$order || (!current_user_can('view_order', $order_id) && $order->get_order_key() !== $_GET['key'])) {
            wp_redirect(wc_get_page_permalink('checkout'));
            exit;
        }
        
        // Get payment details
        $payment = $this->get_payment_details($order_id);
        
        if (!$payment) {
            wp_redirect($order->get_checkout_payment_url());
            exit;
        }
        
        // Prepare template variables
        $variables = $this->prepare_template_variables($order, $payment);
        
        // Set up page context
        $this->setup_page_context();
        
        // Force-load our scripts and styles
        $this->enqueue_assets();
        
        // Load header
        get_header();
        
        // Load template
        $this->load_template($variables);
        
        // Load footer
        get_footer();
        exit;
    }

    /**
     * Get payment details from database
     */
    private function get_payment_details($order_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'zano_payments';
        
        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table_name WHERE order_id = %d", $order_id),
            ARRAY_A
        );
    }

    /**
     * Prepare template variables
     */
    private function prepare_template_variables($order, $payment) {
        $variables = [];
        $variables['order'] = $order;
        $variables['order_id'] = $order->get_id();
        $variables['payment'] = $payment;
        $variables['payment_id'] = $payment['payment_id'];
        $variables['wallet_address'] = $payment['wallet_address']; // Use integrated address from payment record
        $variables['zano_amount'] = $payment['amount'];
        $variables['order_key'] = $order->get_order_key();
        
        // Get current ZANO price
        $variables['price_display'] = $this->get_price_display();
        
        return $variables;
    }

    /**
     * Get price display string
     */
    private function get_price_display() {
        // Get gateway instance to use its settings
        $gateways = WC()->payment_gateways()->get_available_payment_gateways();
        $gateway = isset($gateways['zano_payment']) ? $gateways['zano_payment'] : null;
        
        if ($gateway) {
            // Get price from API through a function call instead of accessing the private property
            $gateway_settings = get_option('woocommerce_zano_payment_settings', []);
            $api = new Zano_API([
                'api_url'        => $gateway_settings['api_url'] ?? '',
                'wallet_address' => $gateway_settings['wallet_address'] ?? '',
                'view_key'       => $gateway_settings['view_key'] ?? '',
                'payment_id_api_url' => $gateway_settings['payment_id_api_url'] ?? Zano_Constants::PAYMENT_VERIFICATION_API,
                'debug'          => isset($gateway_settings['debug']) && $gateway_settings['debug'] === 'yes',
            ]);
            
            $current_price = $api->get_zano_price();
            return is_wp_error($current_price) ? '' : 
                sprintf(' (1 ZANO ≈ $%s USD)', number_format($current_price, 2));
        }
        
        return '';
    }

    /**
     * Setup page context for proper theme integration
     */
    private function setup_page_context() {
        global $post;
        
        $checkout_page_id = wc_get_page_id('checkout');
        if ($checkout_page_id > 0) {
            // Use the checkout page as our post context
            $post = get_post($checkout_page_id);
        } else {
            // Fallback to creating a temporary post object
            $post = new stdClass();
            $post->ID = 0;
            $post->post_title = __('Zano Payment', 'zano-payment-gateway');
            $post->post_status = 'publish';
            $post->post_type = 'page';
            $post->post_content = '';
            $post->comment_status = 'closed';
            $post->ping_status = 'closed';
            $post = new WP_Post($post);
        }
    }

    /**
     * Enqueue necessary assets
     */
    private function enqueue_assets() {
        if (!wp_script_is('zano-payment-script')) {
            wp_enqueue_script(
                'zano-payment-script',
                ZANO_PAYMENT_PLUGIN_URL . 'assets/js/zano-payment.js',
                ['jquery'],
                ZANO_PAYMENT_VERSION . '.' . time(),
                true
            );
            
            // Localize script
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
        
        if (!wp_style_is('zano-payment-style')) {
            wp_enqueue_style(
                'zano-payment-style',
                ZANO_PAYMENT_PLUGIN_URL . 'assets/css/zano-payment.css',
                [],
                ZANO_PAYMENT_VERSION . '.' . time()
            );
        }
    }

    /**
     * Load payment template
     */
    private function load_template($variables) {
        $template_path = ZANO_PAYMENT_PLUGIN_DIR . 'templates/payment-page.php';
        
        // Load template if it exists
        if (file_exists($template_path)) {
            extract($variables);
            include $template_path;
        } else {
            // Fallback if template doesn't exist
            $this->output_fallback_template($variables);
        }
    }

    /**
     * Output fallback template when main template doesn't exist
     */
    private function output_fallback_template($variables) {
        echo '<div class="woocommerce">';
        echo '<div class="woocommerce-notices-wrapper"></div>';
        echo '<h1>Payment Details</h1>';
        echo '<p>Please send ' . esc_html($variables['payment']['amount']) . ' ZANO to address: ' . esc_html($variables['payment']['wallet_address']) . '</p>';
        echo '<p>Note: This integrated address includes payment tracking. Just send the exact amount to this address.</p>';
        echo '<p><a href="' . esc_url($variables['order']->get_checkout_order_received_url()) . '" class="button">Continue</a></p>';
        echo '</div>';
    }
} 