<?php
defined('ABSPATH') || exit;

/**
 * Zano API Handler
 *
 * Handles communication with the Zano payment processor API.
 */
class Zano_API {

    // Constants
    const DEFAULT_TIMEOUT = 30;
    const CONNECTION_TEST_TIMEOUT = 15;
    const PRICE_API_TIMEOUT = 10;
    const MEXC_PRICE_API_URL = 'https://api.mexc.com/api/v3/ticker/price?symbol=ZANOUSDT';
    const JSONRPC_VERSION = '2.0';
    const DEFAULT_BLOCKS_LIMIT = 5;
    const AMOUNT_TOLERANCE_PERCENTAGE = 0.02; // 2% tolerance for amount matching
    const DEFAULT_BUFFER_PERCENTAGE = 1; // 1% price buffer
    
    // Asset configurations
    const ASSET_CONFIGS = [
        'd6329b5b1f7c0805b5c345f4957554002a2f557845f64d7645dae0e051a6498a' => [
            'symbol' => 'ZANO',
            'decimals' => 12,
            'divisor' => 1000000000000 // 10^12
        ],
        '86143388bd056a8f0bab669f78f14873fac8e2dd8d57898cdb725a2d5e2e4f8f' => [
            'symbol' => 'FUSD',
            'decimals' => 4,
            'divisor' => 10000 // 10^4
        ]
    ];
    
    const DEFAULT_ZANO_ASSET_ID = 'd6329b5b1f7c0805b5c345f4957554002a2f557845f64d7645dae0e051a6498a';

    /**
     * API configuration
     */
    private $api_url;
    private $wallet_address;
    private $view_key;
    private $payment_id_api_url;
    private $debug;

    /**
     * Constructor.
     *
     * @param array $config Configuration settings.
     */
    public function __construct($config = []) {
        $this->api_url = $config['api_url'] ?? '';
        $this->wallet_address = $config['wallet_address'] ?? '';
        $this->view_key = $config['view_key'] ?? '';
        $this->payment_id_api_url = $config['payment_id_api_url'] ?? Zano_Constants::PAYMENT_VERIFICATION_API;
        $this->debug = $config['debug'] ?? false;
    }

    /**
     * Check payment status.
     *
     * @param int $order_id Order ID.
     * @return array Payment status.
     */
    public function check_payment_status($order_id) {
        global $wpdb;
        
        $this->log('Checking payment status for order #' . $order_id);
        
        // Get payment details from database
        $payment = $this->get_payment_record($order_id);
        if (!$payment) {
            $this->log('No payment record found for order #' . $order_id);
            return ['status' => 'not_found'];
        }
        
        $this->log('Found payment record: ' . json_encode($payment));
        
        // If already confirmed, return that
        if ($payment['status'] === 'confirmed') {
            return $this->build_confirmed_status_response($payment, $order_id);
        }
        
        // Get payment requirements
        $requirements = $this->get_payment_requirements($payment);
        $this->log(sprintf('Required amount: %f %s, Required confirmations: %d', 
            $requirements['amount'], $requirements['asset_symbol'], $requirements['confirmations']));
        
        // Get recent transactions
        $transactions = $this->get_recent_transactions();
        if (is_wp_error($transactions)) {
            $this->log('Error checking transactions: ' . $transactions->get_error_message());
            return ['status' => 'error', 'message' => $transactions->get_error_message()];
        }
        
        if (empty($transactions)) {
            $this->log('No transactions found for order #' . $order_id);
            return ['status' => 'pending', 'message' => 'No transactions found'];
        }
        
        $this->log('Found ' . count($transactions) . ' transactions');
        
        // Process transactions to find matching payment
        return $this->process_transactions_for_payment($transactions, $payment, $requirements, $order_id);
    }

    /**
     * Generate a unique payment ID.
     *
     * @return string Unique payment ID.
     */
    public function generate_payment_id() {
        // Generate a random 16-byte string for payment ID
        // Format: a hexadecimal string (16 characters)
        return bin2hex(random_bytes(8));
    }

    /**
     * Generate an integrated address from a regular address and payment ID.
     *
     * @param string $payment_id Payment ID to integrate
     * @return string|WP_Error Integrated address or error
     */
    public function generate_integrated_address($payment_id) {
        if (empty($this->wallet_address)) {
            return new WP_Error('missing_address', __('Wallet address not set', 'zano-payment-gateway'));
        }

        $this->log('Generating integrated address for payment ID: ' . $payment_id);

        try {
            $params = [
                'regular_address' => $this->wallet_address,
                'payment_id' => $payment_id
            ];

            $response = $this->api_request('get_integrated_address', $params);
            
            if (is_wp_error($response)) {
                $this->log('Error generating integrated address: ' . $response->get_error_message());
                return $response;
            }

            if (isset($response['integrated_address'])) {
                $this->log('Successfully generated integrated address');
                return $response['integrated_address'];
            } else {
                $this->log('Invalid response from integrated address API');
                return new WP_Error('invalid_response', __('Invalid response from integrated address API', 'zano-payment-gateway'));
            }
        } catch (Exception $e) {
            $this->log('Exception generating integrated address: ' . $e->getMessage());
            return new WP_Error('api_error', $e->getMessage());
        }
    }

    /**
     * Verify payment ID from transaction using external API.
     *
     * @param string $tx_hash Transaction hash
     * @return array|WP_Error Payment verification result
     */
    public function verify_payment_id($tx_hash) {
        if (empty($this->wallet_address) || empty($this->view_key)) {
            return new WP_Error('missing_credentials', __('Wallet address or view key not set', 'zano-payment-gateway'));
        }

        $this->log('Verifying payment ID for transaction: ' . $tx_hash);

        try {
            // Build API URL for payment ID verification
            $base_url = rtrim($this->payment_id_api_url, '/');
            $api_url = $base_url . '/api/decode-transaction/' . $tx_hash;
            $api_url = add_query_arg([
                'walletAddress' => $this->wallet_address,
                'privateViewKey' => $this->view_key
            ], $api_url);

            $this->log('Calling payment ID verification API: ' . $api_url);

            // Make API request
            $response = wp_remote_get($api_url, [
                'timeout' => self::CONNECTION_TEST_TIMEOUT,
                'headers' => [
                    'Content-Type' => 'application/json'
                ]
            ]);

            if (is_wp_error($response)) {
                $this->log('Payment ID verification API error: ' . $response->get_error_message());
                return $response;
            }

            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->log('Invalid JSON response from payment ID verification API');
                return new WP_Error('invalid_json', __('Invalid JSON response from payment ID verification API', 'zano-payment-gateway'));
            }

            if (!isset($data['success']) || !$data['success']) {
                $error_message = isset($data['error']) ? $data['error'] : 'Unknown error';
                $this->log('Payment ID verification failed: ' . $error_message);
                return new WP_Error('verification_failed', $error_message);
            }

            $this->log('Payment ID verification successful: ' . json_encode($data));
            return $data;

        } catch (Exception $e) {
            $this->log('Exception verifying payment ID: ' . $e->getMessage());
            return new WP_Error('api_error', $e->getMessage());
        }
    }

    /**
     * Get recent transactions from the Zano blockchain.
     *
     * @return array|WP_Error Recent transactions or error.
     */
    public function get_recent_transactions() {
        if (empty($this->wallet_address) || empty($this->view_key)) {
            return new WP_Error('missing_credentials', __('Wallet address or view key not set', 'zano-payment-gateway'));
        }
        
        // Format view key correctly - IMPORTANT: Use full view key
        $view_key = trim($this->view_key);
        
        // Don't truncate the view key - Zano requires the full key
        // Previous code was truncating to 40 characters which was causing the "Invalid params" error
        
        $this->log('Getting recent transactions with find_outs_in_recent_blocks method');
        $this->log('Using wallet address: ' . $this->wallet_address);
        $this->log('Using view key: ' . substr($view_key, 0, 5) . '...');
        
        // Try with cURL
        try {
            if (!function_exists('curl_init')) {
                return new WP_Error('curl_not_available', __('cURL is not available on this server', 'zano-payment-gateway'));
            }
            
            // Important: Zano API expects parameters in a specific format
            $params = array(
                'address' => $this->wallet_address,
                'viewkey' => $view_key,
                'blocks_limit' => self::DEFAULT_BLOCKS_LIMIT
            );
            
            $post_data = json_encode([
                'jsonrpc' => self::JSONRPC_VERSION,
                'id' => '1',
                'method' => 'find_outs_in_recent_blocks',
                'params' => $params
            ]);
            
            $this->log('API request payload: ' . $post_data);
            
            // Initialize cURL session
            $ch = curl_init($this->api_url);
                
            // Set cURL options
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_TIMEOUT, self::DEFAULT_TIMEOUT);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        
            // Execute cURL session and get response
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_error = curl_error($ch);
            
            // Close cURL session
            curl_close($ch);
            
            // Handle connection errors
            if ($response === false) {
                $this->log('Transaction fetch failed: ' . $curl_error);
                return new WP_Error('api_error', sprintf(__('API request failed: %s', 'zano-payment-gateway'), $curl_error));
            }
            
            // Check for HTTP error codes
            if ($http_code !== 200) {
                $this->log('Transaction fetch failed with HTTP code ' . $http_code);
                return new WP_Error('api_error', sprintf(__('API request failed with code %s', 'zano-payment-gateway'), $http_code));
            }
            
            // Log response for debugging
            $this->log('Raw Transaction Response: ' . $response);
            
            // Decode response
            $data = json_decode($response, true);
            
            // Check for JSON decoding errors
        if (NULL === $data) {
                $this->log('Transaction response JSON decoding failed');
                return new WP_Error('api_error', __('Invalid API response format', 'zano-payment-gateway'));
        }
        
        // Check for API-level errors
        if (isset($data['error'])) {
                $error_msg = isset($data['error']['message']) ? $data['error']['message'] : json_encode($data['error']);
                $this->log('API returned error: ' . $error_msg);
                return new WP_Error('api_error', $error_msg);
            }
            
            return $this->process_transaction_outputs($data['result'] ?? []);
            
        } catch (Exception $e) {
            $this->log('Exception in get_recent_transactions: ' . $e->getMessage());
            return new WP_Error('api_error', $e->getMessage());
        }
    }
    
    /**
     * Process transaction outputs into a standardized format
     *
     * @param array $result API result data
     * @return array Formatted transactions
     */
    private function process_transaction_outputs($result) {
        $transactions = [];
        
        $this->log('Processing API result: ' . json_encode($result));
        
        // Check if the result has the expected structure
        if (empty($result) || !is_array($result)) {
            $this->log('Empty or invalid result data');
            return [];
        }
        
        // Check blockchain height for calculating confirmations
        $blockchain_height = isset($result['blockchain_top_block_height']) ? 
            intval($result['blockchain_top_block_height']) : 0;
        
        $this->log('Current blockchain height: ' . $blockchain_height);
        
        // Define asset information for proper decimal conversion
        $asset_info = self::ASSET_CONFIGS;
        
        if (!empty($result['outputs']) && is_array($result['outputs'])) {
            foreach ($result['outputs'] as $output) {
                $this->log('Processing output: ' . json_encode($output));
                
                // Skip invalid outputs
                if (!isset($output['tx_id'])) {
                    $this->log('Skipping output without tx_id');
                    continue;
                }
                
                // Extract transaction data
                $tx_id = $output['tx_id'];
                
                // Get asset ID - important for multi-asset support
                $asset_id = isset($output['asset_id']) ? $output['asset_id'] : 
                           self::DEFAULT_ZANO_ASSET_ID;
                
                // Get asset information
                $asset = isset($asset_info[$asset_id]) ? $asset_info[$asset_id] : $asset_info[self::DEFAULT_ZANO_ASSET_ID];
                
                // Get amount, with fallback for different possible field names
                $amount_raw = isset($output['amount']) ? $output['amount'] : 
                              (isset($output['value']) ? $output['value'] : 0);
                
                // Convert amount from atomic units using correct decimals for the asset
                $amount = (float)($amount_raw / $asset['divisor']);
                
                $this->log(sprintf(
                    'Converted amount: %f %s (from %s atomic units) - Asset ID: %s',
                    $amount,
                    $asset['symbol'],
                    $amount_raw,
                    $asset_id
                ));
                
                // Get block height with fallback
                $block_height = isset($output['tx_block_height']) ? intval($output['tx_block_height']) : -1;
                
                // Calculate confirmations
                $confirmations = ($block_height > 0 && $blockchain_height > 0) ? 
                    ($blockchain_height - $block_height) : 0;
                
                // Ensure confirmations is never negative
                $confirmations = max(0, $confirmations);
                
                $this->log(sprintf(
                    'Transaction: hash=%s, amount=%f %s, asset_id=%s, block_height=%d, confirmations=%d',
                    $tx_id, 
                    $amount, 
                    $asset['symbol'],
                    $asset_id,
                    $block_height,
                    $confirmations
                ));
                    
                $transactions[] = [
                    'tx_hash' => $tx_id,
                    'amount' => $amount,
                    'asset_id' => $asset_id,
                    'asset_symbol' => $asset['symbol'],
                    'confirmations' => $confirmations,
                    'timestamp' => time(), // The API doesn't provide timestamp, use current time
                    'is_income' => true,
                    'block_height' => $block_height
                ];
            }
        }
        
        $this->log('Found ' . count($transactions) . ' recent transaction outputs');
        return $transactions;
    }

    /**
     * Test the connection to the Zano node.
     * 
     * @return bool|WP_Error True on success, error object on failure
     */
    public function test_connection() {
        $this->log("Testing connection to Zano node: {$this->api_url}");
        
        if (empty($this->api_url)) {
            return new WP_Error('missing_api_url', __('API URL not configured', 'zano-payment-gateway'));
        }
        
        // Use cURL to test connection
        if (!function_exists('curl_init')) {
            return new WP_Error('curl_not_available', __('cURL is not available on this server', 'zano-payment-gateway'));
        }
            
        try {
            $post_data = json_encode([
                'jsonrpc' => self::JSONRPC_VERSION,
                'id' => '1',
                'method' => 'get_info',
                'params' => new stdClass() // Empty object instead of empty array for compatibility
            ]);
                    
            // Initialize cURL session
            $ch = curl_init($this->api_url);
                    
            // Set cURL options
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_TIMEOUT, self::CONNECTION_TEST_TIMEOUT);
                                
            // Execute cURL session and get response
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_error = curl_error($ch);
            
            // Close cURL session
            curl_close($ch);
            
            if ($response === false) {
                $this->log('cURL connection test failed: ' . $curl_error);
                return new WP_Error('connection_failed', $curl_error);
            }
            
            if ($http_code !== 200) {
                $this->log('cURL connection test failed with HTTP code ' . $http_code);
                return new WP_Error('api_error', 'API request failed with HTTP code ' . $http_code);
                        }
            
            $this->log("Connection test successful!");
            return true;
            
        } catch (Exception $e) {
            $this->log('Exception during connection test: ' . $e->getMessage());
            return new WP_Error('exception', $e->getMessage());
        }
    }

    /**
     * Make API request to the Zano node/API.
     *
     * @param string $method API method to call.
     * @param array  $params Parameters for the API call.
     * @return array|WP_Error API response or error.
     */
    public function api_request($method, $params = []) {
        if (empty($this->api_url)) {
            return new WP_Error('api_not_configured', __('API URL not configured', 'zano-payment-gateway'));
        }

        // Log the request details
        $this->log(sprintf('Making API request to %s', $this->api_url));
        $this->log(sprintf('Method: %s, Params: %s', $method, json_encode($params)));

        try {
            // Handle empty params - Zano API requires empty object instead of empty array
            if (empty($params) || $params === []) {
                $params = new stdClass(); // Empty object instead of empty array
            }
            
            // Create the JSON-RPC request
            $request_data = json_encode([
                'jsonrpc' => self::JSONRPC_VERSION,
                'id' => '1',
                'method' => $method,
                'params' => $params
            ]);
            
            // Check if cURL is available
            if (!function_exists('curl_init')) {
                return new WP_Error('curl_not_available', __('cURL is not available on this server', 'zano-payment-gateway'));
            }
            
            // Initialize cURL session
            $ch = curl_init($this->api_url);
            
            // Set cURL options
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $request_data);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_TIMEOUT, self::DEFAULT_TIMEOUT);
            
            // Execute cURL session and get response
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_error = curl_error($ch);
            
            // Close cURL session
            curl_close($ch);
            
            // Handle connection errors
            if ($response === false) {
                $this->log('cURL request failed: ' . $curl_error);
                return new WP_Error('api_error', sprintf(__('API request failed: %s', 'zano-payment-gateway'), $curl_error));
            }
            
            // Check for HTTP error codes
            if ($http_code !== 200) {
                $this->log('API request failed with HTTP code ' . $http_code);
                return new WP_Error('api_error', sprintf(__('API request failed with code %s', 'zano-payment-gateway'), $http_code));
            }
            
            // Decode response
            $data = json_decode($response, true);
            
            // Check for JSON decoding errors
            if (NULL === $data) {
                $this->log('API response JSON decoding failed');
                $this->log('Raw response: ' . substr($response, 0, 500));
                return new WP_Error('api_error', __('Invalid API response format', 'zano-payment-gateway'));
            }
            
            // Check for API-level errors
            if (isset($data['error'])) {
                $this->log(sprintf('API returned error: %s', json_encode($data['error'])));
                return new WP_Error('api_error', $data['error']['message'] ?? __('Unknown API error', 'zano-payment-gateway'));
            }
            
            // Return API response data
            $this->log('API request successful');
            return $data['result'] ?? [];
            
        } catch (Exception $e) {
            $this->log(sprintf('Exception during API request: %s', $e->getMessage()));
            return new WP_Error('api_error', $e->getMessage());
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
            
            $log_file = ZANO_PAYMENT_PLUGIN_DIR . 'logs/zano-api.log';
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
     * Get the current ZANO price in USD from MEXC Exchange
     *
     * @return float|WP_Error Current ZANO price or error
     */
    public function get_zano_price() {
        $this->log('Getting current ZANO price from MEXC Exchange');
        
        try {
            // Initialize cURL session
            $ch = curl_init(self::MEXC_PRICE_API_URL);
            
            // Set cURL options
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, self::PRICE_API_TIMEOUT);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            
            // Execute cURL session and get response
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_error = curl_error($ch);
            
            // Close cURL session
            curl_close($ch);
            
            // Handle connection errors
            if ($response === false) {
                $this->log('Price fetch failed: ' . $curl_error);
                return new WP_Error('api_error', sprintf(__('Price API request failed: %s', 'zano-payment-gateway'), $curl_error));
            }
            
            // Check for HTTP error codes
            if ($http_code !== 200) {
                $this->log('Price fetch failed with HTTP code ' . $http_code);
                return new WP_Error('api_error', sprintf(__('Price API request failed with code %s', 'zano-payment-gateway'), $http_code));
            }
            
            $this->log('Raw Price Response: ' . $response);
            
            // Decode response
            $data = json_decode($response, true);
            
            // Check for JSON decoding errors
            if (NULL === $data) {
                $this->log('Price response JSON decoding failed');
                return new WP_Error('api_error', __('Invalid price API response format', 'zano-payment-gateway'));
            }
            
            // Extract the price
            if (isset($data['price'])) {
                $price = floatval($data['price']);
                $this->log('Current ZANO price: $' . $price . ' USD');
                return $price;
            }
            
            $this->log('Price not found in response');
            return new WP_Error('price_not_found', __('Could not find ZANO price in API response', 'zano-payment-gateway'));
            
        } catch (Exception $e) {
            $this->log('Exception in get_zano_price: ' . $e->getMessage());
            return new WP_Error('api_error', $e->getMessage());
        }
    }
    
    /**
     * Convert USD amount to ZANO amount
     *
     * @param float $usd_amount Amount in USD
     * @param float $buffer_percent Optional buffer percentage (default: 1%)
     * @return float|WP_Error ZANO amount or error
     */
    public function convert_usd_to_zano($usd_amount, $buffer_percent = self::DEFAULT_BUFFER_PERCENTAGE) {
        // Get current ZANO price
        $zano_price = $this->get_zano_price();
        
        if (is_wp_error($zano_price)) {
            return $zano_price;
        }
        
        // Calculate ZANO amount
        $zano_amount = $usd_amount / $zano_price;
        
        // Add buffer percentage
        $buffer_multiplier = 1 + ($buffer_percent / 100);
        $zano_amount_with_buffer = $zano_amount * $buffer_multiplier;
        
        $this->log(sprintf(
            'Converted $%f USD to %f ZANO (with %f%% buffer: %f ZANO)',
            $usd_amount,
            $zano_amount,
            $buffer_percent,
            $zano_amount_with_buffer
        ));
        
        return $zano_amount_with_buffer;
    }

    /**
     * Get transaction details by hash using get_tx_details API
     *
     * @param string $tx_hash Transaction hash
     * @return array|WP_Error Transaction details or error
     */
    public function get_transaction_details($tx_hash) {
        if (empty($this->api_url)) {
            return new WP_Error('missing_api_url', __('API URL not set', 'zano-payment-gateway'));
        }
        
        $this->log('Getting transaction details for hash: ' . $tx_hash);
        
        try {
            $params = [
                'tx_hash' => $tx_hash
            ];
            
            $result = $this->api_request('get_tx_details', $params);
            
            if (is_wp_error($result)) {
                $this->log('Transaction details request failed: ' . $result->get_error_message());
                return $result;
            }
            
            $this->log('Transaction details retrieved: ' . json_encode($result));
            return $result;
            
        } catch (Exception $e) {
            $this->log('Exception getting transaction details: ' . $e->getMessage());
            return new WP_Error('api_error', $e->getMessage());
        }
    }
    
    /**
     * Update transaction confirmation status using keeper_block from get_tx_details
     *
     * @param string $tx_hash Transaction hash
     * @param int $payment_id Payment record ID
     * @return array|WP_Error Updated confirmation status or error
     */
    public function update_transaction_confirmations($tx_hash, $payment_id) {
        global $wpdb;
        
        $this->log("Updating confirmations for transaction: {$tx_hash}");
        
        // Get current blockchain height first (using the improved /getheight endpoint)
        $current_block_height = $this->get_current_block_height();
        if (is_wp_error($current_block_height)) {
            $this->log("Failed to get current block height: " . $current_block_height->get_error_message());
            $current_block_height = 0;
        }
        
        // Get the payment record to check if we have a received_block stored
        $table_name = $wpdb->prefix . 'zano_payments';
        $payment_record = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $payment_id),
            ARRAY_A
        );
        
        if (!$payment_record) {
            $error_msg = "Payment record not found for ID: {$payment_id}";
            $this->log($error_msg);
            return new WP_Error('payment_not_found', $error_msg);
        }
        
        // Get transaction details from the blockchain
        $tx_details = $this->get_transaction_details($tx_hash);
        
        $keeper_block = -1;
        $confirmations = 0;
        
        if (!is_wp_error($tx_details)) {
            // Extract keeper_block from tx_details
            $keeper_block = isset($tx_details['result']['tx_info']['keeper_block']) ? 
                intval($tx_details['result']['tx_info']['keeper_block']) : -1;
            
            $this->log(sprintf(
                "Transaction details from get_tx_details: keeper_block=%d, current_block_height=%d",
                $keeper_block,
                $current_block_height
            ));
        } else {
            $this->log("Failed to get transaction details: " . $tx_details->get_error_message());
        }
        
        // Calculate confirmations based on different scenarios
        if ($keeper_block > 0 && $current_block_height > 0) {
            // Transaction is confirmed - use keeper_block for accurate calculation
            $confirmations = max(0, $current_block_height - $keeper_block + 1);
            $this->log(sprintf(
                "Transaction confirmed: keeper_block=%d, current_block=%d, confirmations=%d",
                $keeper_block,
                $current_block_height,
                $confirmations
            ));
        } else {
            // Transaction is unconfirmed (keeper_block = -1)
            // Check if we have a received_block from when transaction was first detected
            $received_block = isset($payment_record['received_block']) ? intval($payment_record['received_block']) : 0;
            
            if ($received_block > 0 && $current_block_height > 0) {
                // Calculate confirmations based on when we first detected the transaction
                $confirmations = max(0, $current_block_height - $received_block + 1);
                $this->log(sprintf(
                    "Transaction unconfirmed but using received_block: received_block=%d, current_block=%d, confirmations=%d",
                    $received_block,
                    $current_block_height,
                    $confirmations
                ));
            } else {
                // Fallback: check recent transactions to see if we can find confirmation info
                $this->log("Transaction unconfirmed and no received_block - checking recent transactions");
                
                $recent_transactions = $this->get_recent_transactions();
                if (!is_wp_error($recent_transactions)) {
                    foreach ($recent_transactions as $transaction) {
                        if (($transaction['tx_hash'] ?? '') === $tx_hash) {
                            $tx_confirmations = intval($transaction['confirmations'] ?? 0);
                            $tx_block_height = intval($transaction['block_height'] ?? 0);
                            
                            $this->log(sprintf(
                                "Found transaction in recent transactions: confirmations=%d, block_height=%d",
                                $tx_confirmations,
                                $tx_block_height
                            ));
                            
                            // Use the confirmations from recent transactions
                            $confirmations = $tx_confirmations;
                            
                            // If we have block height, use it as keeper_block
                            if ($tx_block_height > 0) {
                                $keeper_block = $tx_block_height;
                            }
                            break;
                        }
                    }
                } else {
                    $this->log("Failed to get recent transactions: " . $recent_transactions->get_error_message());
                }
            }
        }
        
        $this->log(sprintf(
            "Final confirmation calculation: keeper_block=%d, current_block_height=%d, confirmations=%d",
            $keeper_block,
            $current_block_height,
            $confirmations
        ));
        
        // Update the database with confirmation info
        $update_data = [
            'current_block' => $current_block_height,
            'confirmations' => $confirmations,
            'updated_at' => current_time('mysql')
        ];
        
        // Handle keeper_block and received_block updates carefully
        if ($keeper_block > 0) {
            // Transaction is confirmed - update keeper_block
            $update_data['keeper_block'] = $keeper_block;
            
            // Only set received_block if it's not already set
            // Once received_block is set, it should never be changed
            if (empty($payment_record['received_block'])) {
                $update_data['received_block'] = $keeper_block;
                $this->log(sprintf(
                    "Setting received_block to keeper_block=%d for confirmed transaction",
                    $keeper_block
                ));
            } else {
                $this->log(sprintf(
                    "Preserving existing received_block=%s for confirmed transaction (keeper_block=%d)",
                    $payment_record['received_block'],
                    $keeper_block
                ));
            }
        } else {
            // Transaction is still unconfirmed (keeper_block = -1)
            // NEVER overwrite received_block - it should stay as originally set when transaction was first detected
            $this->log(sprintf(
                "Transaction still unconfirmed (keeper_block=%d), preserving original received_block=%s",
                $keeper_block,
                $payment_record['received_block'] ?? 'null'
            ));
            
            // If somehow received_block is not set, we have a problem - log it but don't guess
            if (empty($payment_record['received_block'])) {
                $this->log("WARNING: Transaction is unconfirmed but received_block is not set. This should not happen.");
            }
        }
        
        $update_result = $wpdb->update(
            $table_name,
            $update_data,
            ['id' => $payment_id]
        );
        
        if ($update_result === false) {
            $error_msg = "Failed to update confirmation data: " . $wpdb->last_error;
            $this->log($error_msg);
            return new WP_Error('db_update_failed', $error_msg);
        }
        
        $this->log(sprintf(
            "Updated confirmations for payment %d: keeper_block=%d, confirmations=%d",
            $payment_id,
            $keeper_block,
            $confirmations
        ));
        
        return [
            'keeper_block' => $keeper_block,
            'current_block' => $current_block_height,
            'confirmations' => $confirmations,
            'tx_confirmed' => $keeper_block > 0 || $confirmations > 0
        ];
    }

    /**
     * Get current blockchain height using the /getheight endpoint
     *
     * @return int|WP_Error Current block height or error
     */
    public function get_current_block_height() {
        if (empty($this->api_url)) {
            return new WP_Error('missing_api_url', __('API URL not set', 'zano-payment-gateway'));
        }
        
        try {
            // Use the simple getheight endpoint as suggested
            $this->log('Getting current blockchain height using getheight endpoint');
            
            // Make a direct HTTP request to the /getheight endpoint
            $height_url = str_replace('/json_rpc', '/getheight', $this->api_url);
            
            $response = wp_remote_get($height_url, [
                'timeout' => 30,
                'headers' => [
                    'Content-Type' => 'application/json',
                ]
            ]);
            
            if (is_wp_error($response)) {
                $this->log('Height request failed: ' . $response->get_error_message());
                return $response;
            }
            
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                $error_msg = 'Invalid JSON response from getheight endpoint: ' . json_last_error_msg();
                $this->log($error_msg);
                return new WP_Error('json_decode_error', $error_msg);
            }
            
            // Check if the response has the expected structure
            if (!isset($data['height']) || !isset($data['status'])) {
                $this->log('Unexpected response format from getheight: ' . $body);
                return new WP_Error('unexpected_response', 'Unexpected response format from getheight endpoint');
            }
            
            if ($data['status'] !== 'OK') {
                $error_msg = 'getheight endpoint returned error status: ' . $data['status'];
                $this->log($error_msg);
                return new WP_Error('api_error', $error_msg);
            }
            
            $blockchain_height = intval($data['height']);
            $this->log('Current blockchain height from getheight: ' . $blockchain_height);
            
            return $blockchain_height;
            
        } catch (Exception $e) {
            $this->log('Exception getting blockchain height: ' . $e->getMessage());
            return new WP_Error('api_error', $e->getMessage());
        }
    }

    /**
     * Get payment record from database.
     *
     * @param int $order_id Order ID.
     * @return array|null Payment record or null if not found.
     */
    private function get_payment_record($order_id) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'zano_payments';
        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table_name WHERE order_id = %d", $order_id),
            ARRAY_A
        );
    }

    /**
     * Build confirmed status response.
     *
     * @param array $payment Payment record.
     * @param int $order_id Order ID.
     * @return array Status response.
     */
    private function build_confirmed_status_response($payment, $order_id) {
        $this->log('Payment already confirmed for order #' . $order_id);
        
        return [
            'status' => 'confirmed',
            'tx_hash' => $payment['tx_hash'] ?? null,
            'confirmations' => $payment['confirmations'] ?? 0,
            'amount' => $payment['received_amount'] ?? $payment['amount']
        ];
    }

    /**
     * Get payment requirements from payment record and settings.
     *
     * @param array $payment Payment record.
     * @return array Payment requirements.
     */
    private function get_payment_requirements($payment) {
        $gateway_settings = get_option('woocommerce_zano_payment_settings', []);
        $required_confirmations = isset($gateway_settings['confirmations']) 
            ? intval($gateway_settings['confirmations']) 
            : 10;
        
        $required_amount = !empty($payment['asset_amount']) 
            ? floatval($payment['asset_amount']) 
            : floatval($payment['amount']);
        
        $expected_asset_symbol = !empty($payment['asset_symbol']) 
            ? $payment['asset_symbol'] 
            : 'ZANO';
        
        return [
            'amount' => $required_amount,
            'asset_symbol' => $expected_asset_symbol,
            'confirmations' => $required_confirmations,
            'asset_id' => $payment['asset_id'] ?? self::DEFAULT_ZANO_ASSET_ID,
            'payment_id' => $payment['payment_id']
        ];
    }

    /**
     * Process transactions to find matching payment.
     *
     * @param array $transactions List of transactions.
     * @param array $payment Payment record.
     * @param array $requirements Payment requirements.
     * @param int $order_id Order ID.
     * @return array Payment status response.
     */
    private function process_transactions_for_payment($transactions, $payment, $requirements, $order_id) {
        foreach ($transactions as $transaction) {
            $result = $this->check_transaction_match($transaction, $payment, $requirements, $order_id);
            
            if ($result !== null) {
                return $result;
            }
        }
        
        // No matching transaction found
        $this->log('No matching transaction found for order #' . $order_id);
        return ['status' => 'pending'];
    }

    /**
     * Check if a transaction matches the payment requirements.
     *
     * @param array $transaction Transaction data.
     * @param array $payment Payment record.
     * @param array $requirements Payment requirements.
     * @param int $order_id Order ID.
     * @return array|null Status response or null if no match.
     */
    private function check_transaction_match($transaction, $payment, $requirements, $order_id) {
        $tx_hash = $transaction['tx_hash'] ?? null;
        $tx_amount = floatval($transaction['amount'] ?? 0);
        $tx_asset_id = $transaction['asset_id'] ?? self::DEFAULT_ZANO_ASSET_ID;
        $tx_asset_symbol = $transaction['asset_symbol'] ?? 'ZANO';
        $confirmations = intval($transaction['confirmations'] ?? 0);
        
        $this->log_transaction_details($tx_hash, $tx_amount, $tx_asset_symbol, $tx_asset_id, $confirmations);
        
        // Check asset ID match
        if (!$this->is_asset_match($payment, $tx_asset_id, $tx_asset_symbol, $tx_hash)) {
            return null; // Skip this transaction
        }
        
        // Verify payment ID - CRITICAL for preventing race conditions
        $payment_verification = $this->verify_payment_id_for_transaction($tx_hash, $requirements['payment_id']);
        if ($payment_verification === null) {
            return null; // Skip this transaction
        }
        
        // Check if transaction is already claimed
        if ($this->is_transaction_already_claimed($tx_hash, $order_id)) {
            return null; // Skip this transaction
        }
        
        // Use verified amount if available
        $verified_amount = floatval($payment_verification['amount'] ?? 0);
        if ($verified_amount > 0) {
            $tx_amount = $verified_amount;
            $this->log(sprintf('Using verified amount from API: %f %s', $tx_amount, $tx_asset_symbol));
        }
        
        // Check amount match
        if (!$this->is_amount_match($tx_amount, $requirements['amount'], $requirements['asset_symbol'], $tx_hash)) {
            return null; // Skip this transaction
        }
        
        // Claim and process the transaction
        return $this->claim_and_process_transaction($transaction, $payment, $requirements, $order_id, $payment_verification);
    }

    /**
     * Log transaction details for debugging.
     *
     * @param string $tx_hash Transaction hash.
     * @param float $tx_amount Transaction amount.
     * @param string $tx_asset_symbol Asset symbol.
     * @param string $tx_asset_id Asset ID.
     * @param int $confirmations Number of confirmations.
     */
    private function log_transaction_details($tx_hash, $tx_amount, $tx_asset_symbol, $tx_asset_id, $confirmations) {
        $this->log(sprintf(
            'Transaction found - hash: %s, amount: %f %s, asset_id: %s, confirmations: %d',
            $tx_hash,
            $tx_amount,
            $tx_asset_symbol,
            $tx_asset_id,
            $confirmations
        ));
    }

    /**
     * Check if asset ID matches payment requirements.
     *
     * @param array $payment Payment record.
     * @param string $tx_asset_id Transaction asset ID.
     * @param string $tx_asset_symbol Transaction asset symbol.
     * @param string $tx_hash Transaction hash.
     * @return bool True if asset matches or no filter needed.
     */
    private function is_asset_match($payment, $tx_asset_id, $tx_asset_symbol, $tx_hash) {
        if (!empty($payment['asset_id']) && $payment['asset_id'] !== $tx_asset_id) {
            $this->log(sprintf(
                'Asset ID mismatch for transaction %s - expected: %s (%s), got: %s (%s) - SKIPPING transaction',
                $tx_hash,
                $payment['asset_id'],
                $payment['asset_symbol'] ?? 'Unknown',
                $tx_asset_id,
                $tx_asset_symbol
            ));
            return false;
        }
        return true;
    }

    /**
     * Verify payment ID for transaction and handle errors.
     *
     * @param string $tx_hash Transaction hash.
     * @param string $expected_payment_id Expected payment ID.
     * @return array|null Payment verification result or null if failed.
     */
    private function verify_payment_id_for_transaction($tx_hash, $expected_payment_id) {
        $payment_verification = $this->verify_payment_id($tx_hash);
        
        if (is_wp_error($payment_verification)) {
            $this->log('Payment ID verification failed for transaction ' . $tx_hash . ': ' . 
                $payment_verification->get_error_message() . ' - SKIPPING transaction');
            return null;
        }
        
        $tx_payment_id = $payment_verification['paymentId'] ?? '';
        
        if ($tx_payment_id !== $expected_payment_id) {
            $this->log(sprintf(
                'Payment ID mismatch for transaction %s - expected: %s, got: %s - SKIPPING transaction (this prevents race conditions)',
                $tx_hash,
                $expected_payment_id,
                $tx_payment_id
            ));
            return null;
        }
        
        $this->log(sprintf(
            'Payment ID verified for transaction %s - payment ID: %s matches expected: %s',
            $tx_hash,
            $tx_payment_id,
            $expected_payment_id
        ));
        
        return $payment_verification;
    }

    /**
     * Check if transaction is already claimed by another order.
     *
     * @param string $tx_hash Transaction hash.
     * @param int $order_id Current order ID.
     * @return bool True if already claimed.
     */
    private function is_transaction_already_claimed($tx_hash, $order_id) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'zano_payments';
        $existing_payment = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM $table_name WHERE tx_hash = %s AND order_id != %d",
                $tx_hash,
                $order_id
            )
        );
        
        if ($existing_payment) {
            $this->log(sprintf(
                'CRITICAL ERROR: Transaction %s was already used for order #%d! This should not happen with proper Payment ID verification.',
                $tx_hash,
                $existing_payment->order_id
            ));
            return true;
        }
        
        return false;
    }

    /**
     * Check if transaction amount matches payment requirements.
     *
     * @param float $tx_amount Transaction amount.
     * @param float $required_amount Required amount.
     * @param string $asset_symbol Asset symbol.
     * @param string $tx_hash Transaction hash.
     * @return bool True if amount matches within tolerance.
     */
    private function is_amount_match($tx_amount, $required_amount, $asset_symbol, $tx_hash) {
        $amount_diff = abs($tx_amount - $required_amount);
        $amount_match = ($amount_diff <= $required_amount * self::AMOUNT_TOLERANCE_PERCENTAGE);
        
        if (!$amount_match) {
            $this->log(sprintf(
                'Amount mismatch for transaction %s - expected: %f %s, got: %f %s, difference: %f %s - SKIPPING transaction',
                $tx_hash,
                $required_amount,
                $asset_symbol,
                $tx_amount,
                $asset_symbol,
                $amount_diff,
                $asset_symbol
            ));
            return false;
        }
        
        $this->log(sprintf(
            'BOTH Payment ID and Amount verified for transaction %s - Amount: %f %s (required: %f %s, difference: %f %s)',
            $tx_hash,
            $tx_amount,
            $asset_symbol,
            $required_amount,
            $asset_symbol,
            $amount_diff,
            $asset_symbol
        ));
        
        return true;
    }

    /**
     * Claim and process a matching transaction.
     *
     * @param array $transaction Transaction data.
     * @param array $payment Payment record.
     * @param array $requirements Payment requirements.
     * @param int $order_id Order ID.
     * @param array $payment_verification Payment verification data.
     * @return array Status response.
     */
    private function claim_and_process_transaction($transaction, $payment, $requirements, $order_id, $payment_verification) {
        global $wpdb;
        
        $tx_hash = $transaction['tx_hash'];
        $tx_amount = floatval($payment_verification['amount'] ?? $transaction['amount']);
        $tx_asset_id = $transaction['asset_id'] ?? self::DEFAULT_ZANO_ASSET_ID;
        $tx_asset_symbol = $transaction['asset_symbol'] ?? 'ZANO';
        $confirmations = max(0, intval($transaction['confirmations'] ?? 0));
        $tx_payment_id = $payment_verification['paymentId'] ?? '';
        
        // Attempt atomic transaction claiming
        $claim_result = $this->attempt_transaction_claim($payment, $transaction, $tx_hash, $tx_amount, $confirmations);
        
        if (!$claim_result) {
            return ['status' => 'pending']; // Transaction was claimed by another process
        }
        
        $this->log(sprintf('Successfully claimed transaction %s for order #%d', $tx_hash, $order_id));
        
        // Check confirmation status and update order if needed
        if ($confirmations >= $requirements['confirmations']) {
            return $this->complete_confirmed_payment($payment, $order_id, $tx_hash, $tx_amount, $tx_asset_id, $tx_asset_symbol, $tx_payment_id, $confirmations);
        } else {
            return $this->handle_detected_payment($payment, $order_id, $tx_hash, $tx_amount, $tx_asset_id, $tx_asset_symbol, $tx_payment_id, $confirmations, $requirements['confirmations']);
        }
    }

    /**
     * Attempt to atomically claim a transaction.
     *
     * @param array $payment Payment record.
     * @param array $transaction Transaction data.
     * @param string $tx_hash Transaction hash.
     * @param float $tx_amount Transaction amount.
     * @param int $confirmations Number of confirmations.
     * @return bool True if successfully claimed.
     */
    private function attempt_transaction_claim($payment, $transaction, $tx_hash, $tx_amount, $confirmations) {
        global $wpdb;
        
        $wpdb->suppress_errors(true);
        
        // Get blockchain height and transaction block information
        $current_blockchain_height = $this->get_current_block_height();
        if (is_wp_error($current_blockchain_height)) {
            $current_blockchain_height = 0;
        }
        
        $tx_block_height = $transaction['block_height'] ?? -1;
        $received_block = ($tx_block_height > 0) ? $tx_block_height : $current_blockchain_height;
        
        $this->log(sprintf(
            'Block info for transaction %s: tx_block_height=%d, current_blockchain_height=%d, received_block=%d',
            $tx_hash,
            $tx_block_height,
            $current_blockchain_height,
            $received_block
        ));
        
        // Prepare update data
        $update_data = [
            'tx_hash' => $tx_hash,
            'confirmations' => $confirmations,
            'received_amount' => $tx_amount,
        ];
        
        // Add block information if columns exist
        $table_name = $wpdb->prefix . 'zano_payments';
        $columns = $wpdb->get_col("DESCRIBE {$table_name}");
        if (in_array('received_block', $columns)) {
            $update_data['received_block'] = $received_block;
            $update_data['current_block'] = $current_blockchain_height;
            $update_data['keeper_block'] = ($tx_block_height > 0) ? $tx_block_height : null;
        } else {
            $this->log('Block columns not found - updating without block information. Run database migration to enable block tracking.');
        }
        
        $claim_result = $wpdb->update(
            $table_name,
            $update_data,
            [
                'id' => $payment['id'],
                'tx_hash' => null  // Only update if tx_hash is currently null
            ]
        );
        
        $db_error = $wpdb->last_error;
        $wpdb->suppress_errors(false);
        
        if (!empty($db_error)) {
            $this->log(sprintf(
                'Database constraint prevented claiming transaction %s for order #%d: %s',
                $tx_hash,
                $payment['order_id'],
                $db_error
            ));
            return false;
        }
        
        return $claim_result > 0;
    }

    /**
     * Complete a confirmed payment.
     *
     * @param array $payment Payment record.
     * @param int $order_id Order ID.
     * @param string $tx_hash Transaction hash.
     * @param float $tx_amount Transaction amount.
     * @param string $tx_asset_id Asset ID.
     * @param string $tx_asset_symbol Asset symbol.
     * @param string $tx_payment_id Payment ID.
     * @param int $confirmations Number of confirmations.
     * @return array Status response.
     */
    private function complete_confirmed_payment($payment, $order_id, $tx_hash, $tx_amount, $tx_asset_id, $tx_asset_symbol, $tx_payment_id, $confirmations) {
        global $wpdb;
        
        $this->log('Payment confirmed with ' . $confirmations . ' confirmations via Payment ID verification');
        
        // Update payment status to confirmed
        $table_name = $wpdb->prefix . 'zano_payments';
        $wpdb->update(
            $table_name,
            ['status' => 'confirmed'],
            ['id' => $payment['id']]
        );
        
        // Update WooCommerce order
        $this->update_woocommerce_order($order_id, $tx_payment_id, $tx_amount, $tx_asset_symbol, $tx_hash);
        
        // Get updated confirmations from database
        $saved_confirmations = $this->get_saved_confirmations($payment['id']);
        
        $this->log(sprintf(
            'Returning confirmed status: blockchain_confirmations=%d, saved_confirmations=%d',
            $confirmations,
            $saved_confirmations
        ));

        return [
            'status' => 'confirmed',
            'tx_hash' => $tx_hash,
            'confirmations' => $saved_confirmations,
            'amount' => $tx_amount,
            'asset_id' => $tx_asset_id,
            'asset_symbol' => $tx_asset_symbol,
            'asset_amount' => $tx_amount,
            'payment_id' => $tx_payment_id,
            'matched_by_payment_id' => true
        ];
    }

    /**
     * Handle detected but unconfirmed payment.
     *
     * @param array $payment Payment record.
     * @param int $order_id Order ID.
     * @param string $tx_hash Transaction hash.
     * @param float $tx_amount Transaction amount.
     * @param string $tx_asset_id Asset ID.
     * @param string $tx_asset_symbol Asset symbol.
     * @param string $tx_payment_id Payment ID.
     * @param int $confirmations Number of confirmations.
     * @param int $required_confirmations Required confirmations.
     * @return array Status response.
     */
    private function handle_detected_payment($payment, $order_id, $tx_hash, $tx_amount, $tx_asset_id, $tx_asset_symbol, $tx_payment_id, $confirmations, $required_confirmations) {
        global $wpdb;
        
        $this->log('Payment detected via Payment ID but waiting for confirmations: ' . $confirmations . '/' . $required_confirmations);
        
        // Update payment status to 'processing' to indicate transaction was found
        $table_name = $wpdb->prefix . 'zano_payments';
        $wpdb->update(
            $table_name,
            ['status' => 'processing', 'updated_at' => current_time('mysql')],
            ['id' => $payment['id']]
        );
        
        // Get updated confirmations from database
        $saved_confirmations = $this->get_saved_confirmations($payment['id']);
        
        $this->log(sprintf(
            'Returning detected status: blockchain_confirmations=%d, saved_confirmations=%d',
            $confirmations,
            $saved_confirmations
        ));
        
        return [
            'status' => 'detected',
            'tx_hash' => $tx_hash,
            'confirmations' => $saved_confirmations,
            'amount' => $tx_amount,
            'asset_id' => $tx_asset_id,
            'asset_symbol' => $tx_asset_symbol,
            'asset_amount' => $tx_amount,
            'payment_id' => $tx_payment_id,
            'matched_by_payment_id' => true,
            'required_confirmations' => $required_confirmations
        ];
    }

    /**
     * Update WooCommerce order status.
     *
     * @param int $order_id Order ID.
     * @param string $payment_id Payment ID.
     * @param float $amount Amount.
     * @param string $asset_symbol Asset symbol.
     * @param string $tx_hash Transaction hash.
     */
    private function update_woocommerce_order($order_id, $payment_id, $amount, $asset_symbol, $tx_hash) {
        if (!function_exists('wc_get_order')) {
            return;
        }
        
        $order = wc_get_order($order_id);
        if (!$order || !in_array($order->get_status(), ['on-hold', 'pending'])) {
            return;
        }
        
        $order->update_status('processing', __('Payment confirmed via Payment ID verification', 'zano-payment-gateway'));
        $order->add_order_note(sprintf(
            __('Zano payment confirmed via Payment ID verification. Payment ID: %s, Amount: %f %s, Transaction ID: %s', 'zano-payment-gateway'),
            $payment_id,
            $amount,
            $asset_symbol,
            $tx_hash
        ));
        $order->save();
    }

    /**
     * Get saved confirmations from database.
     *
     * @param int $payment_id Payment ID.
     * @return int Number of confirmations.
     */
    private function get_saved_confirmations($payment_id) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'zano_payments';
        $updated_payment = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $payment_id),
            ARRAY_A
        );
        
        return $updated_payment ? intval($updated_payment['confirmations']) : 0;
    }
} 