<?php
defined('ABSPATH') || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Zano Payment Gateway Blocks Support
 */
final class Zano_Blocks_Support extends AbstractPaymentMethodType {
    
    private $gateway;
    
    protected $name = 'zano_payment';

    /**
     * Initialize the payment method type.
     */
    public function initialize() {
        $this->settings = get_option('woocommerce_zano_payment_settings', []);
        
        // Initialize gateway instance
        $gateways = WC()->payment_gateways->payment_gateways();
        $this->gateway = isset($gateways[$this->name]) ? $gateways[$this->name] : null;
    }

    /**
     * Returns if this payment method should be active.
     *
     * @return boolean
     */
    public function is_active() {
        return !empty($this->settings['enabled']) && 'yes' === $this->settings['enabled'];
    }

    /**
     * Returns an array of scripts/handles to be registered for this payment method.
     *
     * @return array
     */
    public function get_payment_method_script_handles() {
        wp_register_script(
            'wc-zano-blocks-integration',
            plugin_dir_url(dirname(__FILE__)) . 'assets/js/blocks/zano-payment-blocks.js',
            [
                'wc-blocks-registry',
                'wc-settings',
                'wp-element',
                'wp-html-entities',
            ],
            filemtime(plugin_dir_path(dirname(__FILE__)) . 'assets/js/blocks/zano-payment-blocks.js'),
            true
        );

        return ['wc-zano-blocks-integration'];
    }

    /**
     * Returns an array of key=>value pairs of data made available to payment methods script.
     *
     * @return array
     */
    public function get_payment_method_data() {
        return [
            'title'       => $this->get_setting('title'),
            'description' => $this->get_setting('description'),
            'icon'        => ZANO_PAYMENT_PLUGIN_URL . 'assets/images/zano-icon.png',
            'supports'    => $this->get_supported_features(),
        ];
    }

    /**
     * Get supported features for this payment method.
     *
     * @return array
     */
    public function get_supported_features() {
        return $this->gateway ? array_filter($this->gateway->supports, [$this->gateway, 'supports']) : ['products'];
    }
} 