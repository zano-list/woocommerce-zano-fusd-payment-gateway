<?php
defined('ABSPATH') || exit;

/**
 * Zano Form Builder
 *
 * Handles form field generation for the Zano Payment Gateway admin settings.
 * Extracted from payment gateway class for better separation of concerns.
 */
class Zano_Form_Builder {

    /**
     * Get all form fields for the payment gateway settings
     *
     * @return array Form fields configuration
     */
    public static function get_form_fields() {
        return [
            // Basic Settings Section
            'basic_settings' => [
                'title' => __('Basic Settings', 'zano-payment-gateway'),
                'type' => 'title',
                'description' => __('Configure basic payment gateway settings.', 'zano-payment-gateway'),
            ],
            
            'enabled' => self::get_enabled_field(),
            'title' => self::get_title_field(),
            'description' => self::get_description_field(),
            'icon' => self::get_icon_field(),
            
            // Wallet Settings Section  
            'wallet_settings' => [
                'title' => __('Wallet Settings', 'zano-payment-gateway'),
                'type' => 'title',
                'description' => __('Configure your Zano wallet connection settings.', 'zano-payment-gateway'),
            ],
            
            'wallet_address' => self::get_wallet_address_field(),
            'private_view_key' => self::get_private_view_key_field(),
            'api_url' => self::get_api_url_field(),
            
            // Payment Settings Section
            'payment_settings' => [
                'title' => __('Payment Settings', 'zano-payment-gateway'),
                'type' => 'title',
                'description' => __('Configure payment processing options.', 'zano-payment-gateway'),
            ],
            
            'required_confirmations' => self::get_confirmations_field(),
            'price_buffer' => self::get_price_buffer_field(),
            'payment_timeout' => self::get_payment_timeout_field(),
            'accepted_currencies' => self::get_accepted_currencies_field(),
            
            // Advanced Settings Section
            'advanced_settings' => [
                'title' => __('Advanced Settings', 'zano-payment-gateway'),
                'type' => 'title',
                'description' => __('Advanced configuration options for developers.', 'zano-payment-gateway'),
            ],
            
            'debug_mode' => self::get_debug_mode_field(),
            'webhook_secret' => self::get_webhook_secret_field(),
            'custom_css' => self::get_custom_css_field(),
        ];
    }

    /**
     * Get enabled field configuration
     *
     * @return array Field configuration
     */
    private static function get_enabled_field() {
        return [
            'title' => __('Enable/Disable', 'zano-payment-gateway'),
            'type' => 'checkbox',
            'label' => __('Enable Zano Payment Gateway', 'zano-payment-gateway'),
            'default' => 'no',
            'description' => __('Enable this payment gateway to accept Zano payments.', 'zano-payment-gateway'),
            'desc_tip' => true,
        ];
    }

    /**
     * Get title field configuration
     *
     * @return array Field configuration
     */
    private static function get_title_field() {
        return [
            'title' => __('Title', 'zano-payment-gateway'),
            'type' => 'text',
            'description' => __('The title customers see during checkout.', 'zano-payment-gateway'),
            'default' => __('Zano Payment', 'zano-payment-gateway'),
            'desc_tip' => true,
        ];
    }

    /**
     * Get description field configuration
     *
     * @return array Field configuration
     */
    private static function get_description_field() {
        return [
            'title' => __('Description', 'zano-payment-gateway'),
            'type' => 'textarea',
            'description' => __('The description customers see during checkout.', 'zano-payment-gateway'),
            'default' => __('Pay securely with Zano cryptocurrency. Fast, private, and secure transactions.', 'zano-payment-gateway'),
            'desc_tip' => true,
            'css' => 'height: 60px;',
        ];
    }

    /**
     * Get icon field configuration
     *
     * @return array Field configuration
     */
    private static function get_icon_field() {
        return [
            'title' => __('Payment Icon', 'zano-payment-gateway'),
            'type' => 'text',
            'description' => __('URL to the payment method icon. Leave empty for default Zano icon.', 'zano-payment-gateway'),
            'default' => Zano_File_Manager::get_image_url('zano-icon.png'),
            'desc_tip' => true,
            'placeholder' => 'https://example.com/icon.png',
        ];
    }

    /**
     * Get wallet address field configuration
     *
     * @return array Field configuration
     */
    private static function get_wallet_address_field() {
        return [
            'title' => __('Wallet Address', 'zano-payment-gateway'),
            'type' => 'text',
            'description' => __('Your Zano wallet address starting with "Zx". This is where payments will be received.', 'zano-payment-gateway'),
            'default' => '',
            'desc_tip' => true,
            'custom_attributes' => [
                'required' => 'required',
                'pattern' => '^Zx[a-zA-Z0-9]{95}$',
                'title' => 'Must be a valid Zano address starting with Zx',
            ],
            'css' => 'width: 100%; font-family: monospace;',
        ];
    }

    /**
     * Get private view key field configuration
     *
     * @return array Field configuration
     */
    private static function get_private_view_key_field() {
        return [
            'title' => __('Private View Key', 'zano-payment-gateway'),
            'type' => 'password',
            'description' => __('Your wallet\'s private view key for transaction monitoring. This key is used to verify payments without accessing your funds.', 'zano-payment-gateway'),
            'default' => '',
            'desc_tip' => true,
            'custom_attributes' => [
                'required' => 'required',
                'pattern' => '^[a-fA-F0-9]{64}$',
                'title' => 'Must be a 64-character hexadecimal string',
            ],
            'css' => 'width: 100%; font-family: monospace;',
        ];
    }

    /**
     * Get API URL field configuration
     *
     * @return array Field configuration
     */
    private static function get_api_url_field() {
        return [
            'title' => __('Zano Node API URL', 'zano-payment-gateway'),
            'type' => 'text',
            'description' => __('The RPC endpoint URL for the Zano node. Use the default unless you have your own node.', 'zano-payment-gateway'),
            'default' => Zano_Constants::DEFAULT_API_URL,
            'desc_tip' => true,
            'custom_attributes' => [
                'required' => 'required',
                'pattern' => '^https?://.*',
                'title' => 'Must be a valid HTTP or HTTPS URL',
            ],
            'css' => 'width: 100%;',
        ];
    }

    /**
     * Get confirmations field configuration
     *
     * @return array Field configuration
     */
    private static function get_confirmations_field() {
        return [
            'title' => __('Required Confirmations', 'zano-payment-gateway'),
            'type' => 'number',
            'description' => __('Number of blockchain confirmations required before marking payment as complete.', 'zano-payment-gateway'),
            'default' => Zano_Constants::DEFAULT_CONFIRMATIONS,
            'desc_tip' => true,
            'custom_attributes' => [
                'min' => '1',
                'max' => '100',
                'step' => '1',
            ],
        ];
    }

    /**
     * Get price buffer field configuration
     *
     * @return array Field configuration
     */
    private static function get_price_buffer_field() {
        return [
            'title' => __('Price Buffer (%)', 'zano-payment-gateway'),
            'type' => 'number',
            'description' => __('Additional percentage added to the required amount to account for price fluctuations.', 'zano-payment-gateway'),
            'default' => Zano_Constants::DEFAULT_BUFFER_PERCENTAGE,
            'desc_tip' => true,
            'custom_attributes' => [
                'min' => '0',
                'max' => '20',
                'step' => '0.1',
            ],
        ];
    }

    /**
     * Get payment timeout field configuration
     *
     * @return array Field configuration
     */
    private static function get_payment_timeout_field() {
        return [
            'title' => __('Payment Timeout (minutes)', 'zano-payment-gateway'),
            'type' => 'number',
            'description' => __('How long to wait for payment before marking the order as failed.', 'zano-payment-gateway'),
            'default' => Zano_Constants::DEFAULT_PAYMENT_TIMEOUT / 60, // Convert seconds to minutes
            'desc_tip' => true,
            'custom_attributes' => [
                'min' => '5',
                'max' => '60',
                'step' => '1',
            ],
        ];
    }

    /**
     * Get accepted currencies field configuration
     *
     * @return array Field configuration
     */
    private static function get_accepted_currencies_field() {
        return [
            'title' => __('Accepted Currencies', 'zano-payment-gateway'),
            'type' => 'multiselect',
            'description' => __('Select which Zano-based currencies to accept.', 'zano-payment-gateway'),
            'default' => ['ZANO', 'FUSD'],
            'desc_tip' => true,
            'options' => [
                'ZANO' => __('Zano (ZANO)', 'zano-payment-gateway'),
                'FUSD' => __('Fakechain USD (FUSD)', 'zano-payment-gateway'),
            ],
            'css' => 'min-height: 60px;',
        ];
    }

    /**
     * Get debug mode field configuration
     *
     * @return array Field configuration
     */
    private static function get_debug_mode_field() {
        return [
            'title' => __('Debug Mode', 'zano-payment-gateway'),
            'type' => 'checkbox',
            'label' => __('Enable debug logging', 'zano-payment-gateway'),
            'default' => 'no',
            'description' => __('Enable detailed logging for troubleshooting. Logs are stored in the plugin logs directory.', 'zano-payment-gateway'),
            'desc_tip' => true,
        ];
    }

    /**
     * Get webhook secret field configuration
     *
     * @return array Field configuration
     */
    private static function get_webhook_secret_field() {
        return [
            'title' => __('Webhook Secret', 'zano-payment-gateway'),
            'type' => 'password',
            'description' => __('Optional webhook secret for additional security. Leave empty to disable webhook verification.', 'zano-payment-gateway'),
            'default' => '',
            'desc_tip' => true,
            'css' => 'width: 100%; font-family: monospace;',
        ];
    }

    /**
     * Get custom CSS field configuration
     *
     * @return array Field configuration
     */
    private static function get_custom_css_field() {
        return [
            'title' => __('Custom CSS', 'zano-payment-gateway'),
            'type' => 'textarea',
            'description' => __('Add custom CSS to style the payment page.', 'zano-payment-gateway'),
            'default' => '',
            'desc_tip' => true,
            'css' => 'height: 120px; font-family: monospace;',
        ];
    }

    /**
     * Validate form field data
     *
     * @param array $fields Field data to validate
     * @return array Validation results
     */
    public static function validate_form_fields($fields) {
        $errors = [];
        $warnings = [];
        
        // Validate wallet address
        if (!empty($fields['wallet_address'])) {
            if (!self::validate_wallet_address($fields['wallet_address'])) {
                $errors[] = __('Invalid wallet address format. Must start with "Zx" and be 97 characters long.', 'zano-payment-gateway');
            }
        } else {
            $errors[] = __('Wallet address is required.', 'zano-payment-gateway');
        }
        
        // Validate private view key
        if (!empty($fields['private_view_key'])) {
            if (!self::validate_private_view_key($fields['private_view_key'])) {
                $errors[] = __('Invalid private view key format. Must be 64 hexadecimal characters.', 'zano-payment-gateway');
            }
        } else {
            $errors[] = __('Private view key is required.', 'zano-payment-gateway');
        }
        
        // Validate API URL
        if (!empty($fields['api_url'])) {
            if (!filter_var($fields['api_url'], FILTER_VALIDATE_URL)) {
                $errors[] = __('Invalid API URL format.', 'zano-payment-gateway');
            }
        } else {
            $errors[] = __('API URL is required.', 'zano-payment-gateway');
        }
        
        // Validate numeric fields
        if (!empty($fields['required_confirmations'])) {
            $confirmations = intval($fields['required_confirmations']);
            if ($confirmations < 1 || $confirmations > 100) {
                $errors[] = __('Required confirmations must be between 1 and 100.', 'zano-payment-gateway');
            }
            if ($confirmations < 10) {
                $warnings[] = __('Using less than 10 confirmations may increase the risk of double-spending attacks.', 'zano-payment-gateway');
            }
        }
        
        if (!empty($fields['price_buffer'])) {
            $buffer = floatval($fields['price_buffer']);
            if ($buffer < 0 || $buffer > 20) {
                $errors[] = __('Price buffer must be between 0% and 20%.', 'zano-payment-gateway');
            }
        }
        
        if (!empty($fields['payment_timeout'])) {
            $timeout = intval($fields['payment_timeout']);
            if ($timeout < 5 || $timeout > 60) {
                $errors[] = __('Payment timeout must be between 5 and 60 minutes.', 'zano-payment-gateway');
            }
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * Validate wallet address format
     *
     * @param string $address Wallet address
     * @return bool True if valid
     */
    private static function validate_wallet_address($address) {
        return preg_match('/^Zx[a-zA-Z0-9]{95}$/', $address);
    }

    /**
     * Validate private view key format
     *
     * @param string $key Private view key
     * @return bool True if valid
     */
    private static function validate_private_view_key($key) {
        return preg_match('/^[a-fA-F0-9]{64}$/', $key);
    }

    /**
     * Generate field HTML
     *
     * @param string $key Field key
     * @param array $field Field configuration
     * @param mixed $value Current value
     * @return string HTML output
     */
    public static function generate_field_html($key, $field, $value = null) {
        $field_html = '';
        $field_type = $field['type'] ?? 'text';
        
        switch ($field_type) {
            case 'title':
                $field_html = self::generate_title_html($field);
                break;
            case 'text':
            case 'password':
                $field_html = self::generate_text_field_html($key, $field, $value);
                break;
            case 'textarea':
                $field_html = self::generate_textarea_html($key, $field, $value);
                break;
            case 'checkbox':
                $field_html = self::generate_checkbox_html($key, $field, $value);
                break;
            case 'number':
                $field_html = self::generate_number_field_html($key, $field, $value);
                break;
            case 'multiselect':
                $field_html = self::generate_multiselect_html($key, $field, $value);
                break;
            default:
                $field_html = self::generate_text_field_html($key, $field, $value);
        }
        
        return $field_html;
    }

    /**
     * Generate title HTML
     *
     * @param array $field Field configuration
     * @return string HTML output
     */
    private static function generate_title_html($field) {
        $title = $field['title'] ?? '';
        $description = $field['description'] ?? '';
        
        $html = "<h3>{$title}</h3>";
        if (!empty($description)) {
            $html .= "<p class='description'>{$description}</p>";
        }
        
        return $html;
    }

    /**
     * Generate text field HTML
     *
     * @param string $key Field key
     * @param array $field Field configuration
     * @param mixed $value Current value
     * @return string HTML output
     */
    private static function generate_text_field_html($key, $field, $value) {
        $type = $field['type'] ?? 'text';
        $css = $field['css'] ?? '';
        $custom_attributes = $field['custom_attributes'] ?? [];
        $placeholder = $field['placeholder'] ?? '';
        
        $attributes = '';
        foreach ($custom_attributes as $attr => $attr_value) {
            $attributes .= " {$attr}='{$attr_value}'";
        }
        
        $html = "<input type='{$type}' name='{$key}' id='{$key}' value='" . esc_attr($value) . "'";
        $html .= " style='{$css}' placeholder='{$placeholder}'{$attributes} />";
        
        return $html;
    }

    /**
     * Generate textarea HTML
     *
     * @param string $key Field key
     * @param array $field Field configuration
     * @param mixed $value Current value
     * @return string HTML output
     */
    private static function generate_textarea_html($key, $field, $value) {
        $css = $field['css'] ?? '';
        
        return "<textarea name='{$key}' id='{$key}' style='{$css}'>" . esc_textarea($value) . "</textarea>";
    }

    /**
     * Generate checkbox HTML
     *
     * @param string $key Field key
     * @param array $field Field configuration
     * @param mixed $value Current value
     * @return string HTML output
     */
    private static function generate_checkbox_html($key, $field, $value) {
        $label = $field['label'] ?? '';
        $checked = ($value === 'yes') ? 'checked' : '';
        
        return "<input type='checkbox' name='{$key}' id='{$key}' value='yes' {$checked} /> <label for='{$key}'>{$label}</label>";
    }

    /**
     * Generate number field HTML
     *
     * @param string $key Field key
     * @param array $field Field configuration
     * @param mixed $value Current value
     * @return string HTML output
     */
    private static function generate_number_field_html($key, $field, $value) {
        $custom_attributes = $field['custom_attributes'] ?? [];
        
        $attributes = '';
        foreach ($custom_attributes as $attr => $attr_value) {
            $attributes .= " {$attr}='{$attr_value}'";
        }
        
        return "<input type='number' name='{$key}' id='{$key}' value='" . esc_attr($value) . "'{$attributes} />";
    }

    /**
     * Generate multiselect HTML
     *
     * @param string $key Field key
     * @param array $field Field configuration
     * @param mixed $value Current value
     * @return string HTML output
     */
    private static function generate_multiselect_html($key, $field, $value) {
        $options = $field['options'] ?? [];
        $css = $field['css'] ?? '';
        $selected_values = is_array($value) ? $value : [$value];
        
        $html = "<select name='{$key}[]' id='{$key}' multiple style='{$css}'>";
        
        foreach ($options as $option_key => $option_label) {
            $selected = in_array($option_key, $selected_values) ? 'selected' : '';
            $html .= "<option value='{$option_key}' {$selected}>{$option_label}</option>";
        }
        
        $html .= "</select>";
        
        return $html;
    }
} 