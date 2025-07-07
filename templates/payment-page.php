<?php
/**
 * Zano Payment Page Template - Modern White Design
 *
 * This template displays the payment instructions and status for Zano payments.
 *
 * @package Zano_Payment_Gateway
 */

defined('ABSPATH') || exit;

// Variables available:
// $order - WC_Order object
// $order_id - Order ID
// $payment - Payment record from database
// $payment_id - Payment ID for reference
// $wallet_address - Zano integrated address (includes payment ID)
// $zano_amount - Amount in Zano
// $order_key - Order key
// $price_display - Current Zano price display string

// Get gateway instance
$gateways = WC()->payment_gateways()->get_available_payment_gateways();
$gateway = isset($gateways['zano_payment']) ? $gateways['zano_payment'] : null;

// Get required confirmations
$required_confirmations = ($gateway && isset($gateway->confirmations)) ? (int) $gateway->confirmations : 10;

// Get test mode status
$is_test_mode = ($gateway && isset($gateway->test_mode)) ? $gateway->test_mode === 'yes' : false;

// Calculate time remaining (15 minutes from payment creation)
$created_time = strtotime($payment['created_at']);
$expires_at = $created_time + (15 * 60); // 15 minutes in seconds
$time_remaining = max(0, $expires_at - time());
$expiration_timestamp = $expires_at * 1000; // Convert to milliseconds for JS

// Asset IDs
$zano_asset_id = 'd6329b5b1f7c0805b5c345f4957554002a2f557845f64d7645dae0e051a6498a';
$fusd_asset_id = '86143388bd056a8f0bab669f78f14873fac8e2dd8d57898cdb725a2d5e2e4f8f';

// Calculate FUSD equivalent (assuming 1:1 ratio for now, should be calculated from actual rates)
$order_total_usd = $order->get_total();
$fusd_amount = $order_total_usd; // 1:1 ratio with USD

// Format amounts for display
$formatted_zano_amount = number_format((float)$zano_amount, 8, '.', '');
$formatted_fusd_amount = number_format((float)$fusd_amount, 6, '.', '');
$usd_equivalent = $price_display ? "≈ {$price_display}" : '';

// Generate proper deeplinks for both assets (using correct Zano wallet format)
$zano_deeplink = "zano:action=send&address={$wallet_address}&amount={$formatted_zano_amount}";
$fusd_deeplink = "zano:action=send&address={$wallet_address}&amount={$formatted_fusd_amount}&asset_id={$fusd_asset_id}";

// For backwards compatibility, also generate QR URLs
$zano_qr_url = add_query_arg(['data' => urlencode($zano_deeplink)], admin_url('admin-ajax.php?action=zano_generate_qr'));
$fusd_qr_url = add_query_arg(['data' => urlencode($fusd_deeplink)], admin_url('admin-ajax.php?action=zano_generate_qr'));
?>

<div class="woocommerce-zano-payment">
    <div class="zano-payment-container">
        
        <!-- Header -->
        <header class="zano-payment-header">
            <h1>Complete Your Payment</h1>
            <p class="zano-payment-instruction">Please send the exact amount to finalize your order #<?php echo esc_html($order_id); ?>.</p>
        </header>
                
                <?php if ($is_test_mode): ?>
        <!-- Test Mode Notice -->
                <div class="zano-test-mode-notice">
            ⚠️ <strong>Test Mode</strong> - This is a test payment. No real funds will be transferred.
                </div>
                <?php endif; ?>
                
        <!-- Timer Card -->
        <div class="zano-timer-card">
            <div class="zano-timer-display" id="payment-timer" data-expires="<?php echo esc_attr($expiration_timestamp); ?>">
                <?php 
                $minutes = floor($time_remaining / 60);
                $seconds = $time_remaining % 60;
                echo sprintf('%02d:%02d', $minutes, $seconds);
                ?>
            </div>
            <p class="zano-timer-label">Time Remaining</p>
        </div>
        
        <!-- Debug Information (only visible in browser console) -->
        <script>
        console.log('PHP Timer Debug Info:', {
            createdAt: '<?php echo esc_js($payment['created_at']); ?>',
            createdTimestamp: <?php echo $created_time; ?>,
            expiresAt: <?php echo $expires_at; ?>,
            expirationTimestamp: <?php echo $expiration_timestamp; ?>,
            timeRemaining: <?php echo $time_remaining; ?>,
            currentPHPTime: <?php echo time(); ?>,
            currentPHPTimeFormatted: '<?php echo date('Y-m-d H:i:s'); ?>'
        });
        </script>

        <!-- Main Content Grid -->
        <div class="zano-payment-content">
            
            <!-- Left Column: Payment Details & Status -->
                <div class="zano-left-column">
                
                <!-- Payment Details Card -->
                <div class="zano-card zano-payment-details-card">
                    <div class="zano-card-header">
                        <h2 class="zano-card-title">Payment Details</h2>
                    </div>
                    <div class="zano-card-content">
                        
                        <!-- Asset Selection -->
                        <div class="zano-asset-selector">
                            <button class="zano-asset-button active" data-asset="zano" onclick="switchAsset('zano')">
                                <img src="<?php echo esc_url(plugin_dir_url(__FILE__) . '../assets/images/zano-icon.png'); ?>" alt="ZANO" class="zano-asset-icon">
                                ZANO
                            </button>
                            <button class="zano-asset-button" data-asset="fusd" onclick="switchAsset('fusd')">
                                <img src="<?php echo esc_url(plugin_dir_url(__FILE__) . '../assets/images/fusd-icon.png'); ?>" alt="FUSD" class="zano-asset-icon">
                                FUSD
                            </button>
                        </div>
                        
                        <!-- ZANO Payment Details -->
                        <div id="zano-details" class="asset-details">
                            <!-- Amount -->
                            <div class="zano-payment-item">
                                <div class="zano-payment-item-header">
                                    <div class="zano-payment-item-label">
                                        <svg class="zano-payment-item-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1"></path>
                                        </svg>
                                        Amount
                                    </div>
                                    <button class="zano-copy-button" data-copy="<?php echo esc_attr($formatted_zano_amount); ?>" title="Copy amount">
                                        <svg class="zano-copy-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
                                        </svg>
                                        Copy
                                    </button>
                                </div>
                                <p class="zano-payment-item-value"><?php echo esc_html($formatted_zano_amount); ?> ZANO</p>
                                <?php if ($usd_equivalent): ?>
                                <p class="zano-payment-item-subvalue"><?php echo esc_html($usd_equivalent); ?></p>
                                <?php endif; ?>
                                <p class="zano-asset-id">Asset ID: <?php echo esc_html($zano_asset_id); ?></p>
                            </div>
                        </div>
                        
                        <!-- FUSD Payment Details -->
                        <div id="fusd-details" class="asset-details" style="display: none;">
                            <!-- Amount -->
                            <div class="zano-payment-item">
                                <div class="zano-payment-item-header">
                                    <div class="zano-payment-item-label">
                                        <svg class="zano-payment-item-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1"></path>
                                        </svg>
                                        Amount
                                    </div>
                                    <button class="zano-copy-button" data-copy="<?php echo esc_attr($formatted_fusd_amount); ?>" title="Copy amount">
                                        <svg class="zano-copy-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
                                        </svg>
                                        Copy
                            </button>
                                </div>
                                <p class="zano-payment-item-value"><?php echo esc_html($formatted_fusd_amount); ?> FUSD</p>
                                <p class="zano-payment-item-subvalue">≈ $<?php echo esc_html($formatted_fusd_amount); ?> USD</p>
                                <p class="zano-asset-id">Asset ID: <?php echo esc_html($fusd_asset_id); ?></p>
                            </div>
                        </div>

                        <!-- Wallet Address (same for both assets) -->
                        <div class="zano-payment-item">
                            <div class="zano-payment-item-header">
                                <div class="zano-payment-item-label">
                                    <svg class="zano-payment-item-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"></path>
                                    </svg>
                                    Wallet Address
                                </div>
                                <button class="zano-copy-button" data-copy="<?php echo esc_attr($wallet_address); ?>" title="Copy address">
                                    <svg class="zano-copy-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
                                    </svg>
                                    Copy
                                </button>
                            </div>
                            <p class="zano-payment-item-value" style="font-size: 0.875rem; word-break: break-all;"><?php echo esc_html($wallet_address); ?></p>
                        </div>

                        </div>
                    </div>
                    
                <!-- Instructions Card -->
                <div class="zano-card zano-instructions-card">
                    <div class="zano-card-header">
                        <h2 class="zano-card-title">How to Pay</h2>
                    </div>
                    <div class="zano-card-content">
                        <div class="zano-instructions">
                            <ol>
                                <li>Select your preferred asset (ZANO or FUSD) above.</li>
                                <li>Copy the wallet address and amount.</li>
                                <li>Open your Zano wallet and paste the details.</li>
                                <li>Submit your transaction and wait for confirmation.</li>
                            </ol>
                        </div>
                    </div>
                </div>


            </div>

            <!-- Right Column: QR Code & Instructions -->
            <div class="zano-right-column">
                
                <!-- QR Code Card -->
                <div class="zano-card zano-qr-card">
                    <div class="zano-card-header">
                        <h2 class="zano-card-title">Scan to Pay</h2>
                    </div>
                    <div class="zano-card-content">
                        <div class="zano-qr-container">
                            <div class="zano-qr-wrapper" id="qr-code-container">
                                <!-- QR code will be generated here by JavaScript -->
                            </div>
                            <div class="zano-deeplink-actions">
                                <button id="copy-deeplink-btn" class="zano-button secondary zano-copy-button" data-copy="">
                                    Copy Payment Link
                                </button>
                            </div>
                            <p class="zano-qr-instruction">Use your Zano wallet app to scan this QR code.</p>
                        </div>
                    </div>
                </div>

                <!-- Payment Status Card -->
                <div class="zano-card zano-status-card">
                    <div class="zano-card-header">
                        <h2 class="zano-card-title">Payment Status</h2>
                    </div>
                    <div class="zano-card-content">
                        <div id="payment-status">
                            <div class="zano-status-indicator waiting">
                                <svg class="zano-status-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                                <span>Waiting for payment confirmation...</span>
                            </div>
                        </div>
                        
                        <button id="check-payment-btn" class="zano-button primary full" onclick="checkPaymentStatus()">
                            Check Payment Status
                        </button>
                    </div>
                </div>

            </div>
                    </div>
                    
        <!-- Footer -->
        <footer class="zano-footer">
            <div class="zano-footer-buttons">
                <a href="<?php echo esc_url(wc_get_account_endpoint_url('orders')); ?>" class="zano-button secondary">
                    View Order Status
                </a>
                <a href="<?php echo esc_url(wc_get_page_permalink('shop')); ?>" class="zano-button outline">
                    Continue Shopping
                </a>
            </div>
            <p class="zano-footer-text">
                Having trouble? 
                <a href="<?php echo esc_url(get_permalink(get_option('woocommerce_myaccount_page_id'))); ?>">Contact Support</a>
            </p>
        </footer>
    </div>
</div>

<script>
// Current selected asset - determine from order/payment data
let currentAsset = 'zano'; // Default fallback

// Try to determine the correct asset from order meta or payment data
<?php
// Check if we have stored asset information for this order
$stored_asset = get_post_meta($order_id, '_zano_selected_asset', true);
if ($stored_asset && in_array($stored_asset, ['zano', 'fusd'])) {
    echo "currentAsset = '" . esc_js($stored_asset) . "';";
} else {
    // Fallback: check if FUSD amount is more prominent or explicitly set
    // This is a heuristic - in a real scenario, you'd want to track this properly
    echo "// No stored asset preference found, using ZANO as default";
}
?>

// Asset data with deeplinks
const assetData = {
    zano: {
        amount: '<?php echo esc_js($formatted_zano_amount); ?>',
        symbol: 'ZANO',
        assetId: '<?php echo esc_js($zano_asset_id); ?>',
        deeplink: '<?php echo $zano_deeplink; ?>',
        qrUrl: '<?php echo esc_js($zano_qr_url); ?>',
        name: 'Zano',
        decimals: 8,
        logoUrl: '<?php echo esc_js(plugin_dir_url(__FILE__) . "../assets/images/zano-icon.png"); ?>'
    },
    fusd: {
        amount: '<?php echo esc_js($formatted_fusd_amount); ?>',
        symbol: 'FUSD',
        assetId: '<?php echo esc_js($fusd_asset_id); ?>',
        deeplink: '<?php echo $fusd_deeplink; ?>',
        qrUrl: '<?php echo esc_js($fusd_qr_url); ?>',
        name: 'Fakechain USD',
        decimals: 6,
        logoUrl: '<?php echo esc_js(plugin_dir_url(__FILE__) . "../assets/images/fusd-icon.png"); ?>'
    }
};

// QR Code instance
let qrCodeInstance = null;

// Switch between assets
function switchAsset(asset) {
    currentAsset = asset;
    
    // Update button states
    document.querySelectorAll('.zano-asset-button').forEach(btn => {
        btn.classList.remove('active');
    });
    const targetButton = document.querySelector(`[data-asset="${asset}"]`);
    if (targetButton) {
        targetButton.classList.add('active');
    }
    
    // Show/hide asset details
    const zanoDetails = document.getElementById('zano-details');
    const fusdDetails = document.getElementById('fusd-details');
    if (zanoDetails) zanoDetails.style.display = asset === 'zano' ? 'block' : 'none';
    if (fusdDetails) fusdDetails.style.display = asset === 'fusd' ? 'block' : 'none';
    
    // Generate new QR code immediately
    const qrContainer = document.getElementById('qr-code-container');
    if (qrContainer && assetData[asset]) {
        generateQRCodeFast(qrContainer, assetData[asset].deeplink, asset);
    }
    
    // Update deeplink copy button
    updateDeeplinkButton();
    
    // Update payment record with selected asset information
    updatePaymentAsset(asset);
}

// Update payment record with asset information
function updatePaymentAsset(asset) {
    const assetInfo = assetData[asset];
    if (!assetInfo) return;
    
    // Make AJAX request to update payment record
    fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `action=zano_update_payment_asset&order_id=<?php echo esc_js($order_id); ?>&asset_id=${assetInfo.assetId}&asset_symbol=${assetInfo.symbol}&asset_amount=${assetInfo.amount}&nonce=<?php echo wp_create_nonce('zano_payment_nonce'); ?>`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            console.log('Payment asset updated:', data.data);
        } else {
            console.error('Failed to update payment asset:', data.data?.message || 'Unknown error');
        }
    })
    .catch(error => {
        console.error('Error updating payment asset:', error);
    });
}

// Generate QR Code (enhanced version)
function generateQrCodeWithLogo(deeplink, logoAsset) {
    const qrContainer = document.getElementById('qr-code-container');
    if (!qrContainer) return;
    
    // Use the fast method - server-side generation is more reliable
    generateQRCodeFast(qrContainer, deeplink, logoAsset);
}

// Update deeplink copy button when asset changes
function updateDeeplinkButton() {
    const deeplinkButton = document.getElementById('copy-deeplink-btn');
    if (deeplinkButton && assetData[currentAsset]) {
        deeplinkButton.setAttribute('data-copy', assetData[currentAsset].deeplink);
    }
}

// Timer functionality
function updateTimer() {
    const timerElement = document.getElementById('payment-timer');
    if (!timerElement) {
        console.error('Timer element not found!');
        return;
    }
    
    const expiresAt = parseInt(timerElement.dataset.expires);
    const now = Date.now();
    const timeLeft = Math.max(0, Math.floor((expiresAt - now) / 1000));
    
    // Debug logging for timer issues
    if (window.zanoTimerDebug) {
        console.log('Timer Debug:', {
            expiresAt: expiresAt,
            now: now,
            timeLeft: timeLeft,
            expiresAtFormatted: new Date(expiresAt).toISOString(),
            nowFormatted: new Date(now).toISOString()
        });
    }
    
    if (timeLeft <= 0) {
        timerElement.textContent = '00:00';
        timerElement.parentElement.style.background = 'linear-gradient(135deg, #dc2626 0%, #991b1b 100%)';
        console.log('Payment timer expired');
        return;
    }
    
    const minutes = Math.floor(timeLeft / 60);
    const seconds = timeLeft % 60;
    timerElement.textContent = `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
    
    // Make it urgent when less than 3 minutes
    if (timeLeft < 180) {
        timerElement.parentElement.style.background = 'linear-gradient(135deg, #dc2626 0%, #991b1b 100%)';
    }
}

// Debug timer initialization
console.log('Zano Payment Timer Initialized:', {
    element: document.getElementById('payment-timer'),
    expiresAtAttribute: document.getElementById('payment-timer')?.dataset.expires,
    currentTime: Date.now(),
    timeUntilExpiry: (parseInt(document.getElementById('payment-timer')?.dataset.expires || 0) - Date.now()) / 1000
});

// Enable debug mode if needed (you can enable this in console: window.zanoTimerDebug = true)
if (window.location.search.includes('timer_debug=1')) {
    window.zanoTimerDebug = true;
    console.log('Timer debug mode enabled');
}

// Run initial timer update
updateTimer();

// Update timer every second
setInterval(updateTimer, 1000);

// Payment status checking
let isChecking = false;

function checkPaymentStatus() {
    if (isChecking) return;
    
    isChecking = true;
    const button = document.getElementById('check-payment-btn');
    const originalText = button.innerHTML;
    
    button.innerHTML = '<span class="zano-spinner"></span>Checking...';
    button.disabled = true;
    
    // Make AJAX request to check payment status
    fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `action=zano_check_payment&order_id=<?php echo esc_js($order_id); ?>&nonce=<?php echo wp_create_nonce('zano_payment_nonce'); ?>`
    })
    .then(response => response.json())
    .then(data => {
        const statusContainer = document.getElementById('payment-status');
        
        if (data.success) {
            if (data.data.status === 'confirmed') {
                const assetInfo = data.data.asset_symbol || assetData[currentAsset].symbol;
                const amountInfo = data.data.asset_amount || assetData[currentAsset].amount;
                statusContainer.innerHTML = `
                    <div class="zano-status-indicator confirmed">
                        <svg class="zano-status-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        <span>Payment confirmed! ${amountInfo} ${assetInfo} received. Order is being processed.</span>
                    </div>
                `;
                button.innerHTML = 'Payment Confirmed ✓';
                button.disabled = true;
                
                // Store payment info in local storage for order tracking
                localStorage.setItem('zano_payment_info', JSON.stringify({
                    orderId: '<?php echo esc_js($order_id); ?>',
                    assetSymbol: assetInfo,
                    amount: amountInfo,
                    assetId: assetData[currentAsset].assetId,
                    timestamp: new Date().toISOString()
                }));
                
                // Redirect after a short delay
                setTimeout(() => {
                    window.location.href = '<?php echo esc_url($order->get_checkout_order_received_url()); ?>';
                }, 2000);
                
            } else if (data.data.status === 'detected') {
                const assetInfo = data.data.asset_symbol || assetData[currentAsset].symbol;
                statusContainer.innerHTML = `
                    <div class="zano-status-indicator waiting">
                        <svg class="zano-status-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        <span>${assetInfo} payment detected! Waiting for confirmations (${data.data.confirmations || 0}/${<?php echo $required_confirmations; ?>})...</span>
                    </div>
                    <div class="zano-status-note">
                        <p>You can safely close this page. Your order will be automatically confirmed after ${<?php echo $required_confirmations; ?>} confirmations.</p>
                    </div>
                `;
                
                // Always redirect when payment is detected, regardless of confirmation count
                setTimeout(() => {
                    window.location.href = data.data.redirect_url || '<?php echo esc_url($order->get_checkout_order_received_url()); ?>';
                }, 2000);
            } else if (data.data.status === 'pending') {
                const assetInfo = assetData[currentAsset].symbol;
                statusContainer.innerHTML = `
                    <div class="zano-status-indicator pending">
                        <svg class="zano-status-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        <span>Waiting for ${assetInfo} payment... No transaction detected yet.</span>
                    </div>
                `;
            } else if (data.data.status === 'failed') {
                const message = data.data.message || 'Payment failed';
                statusContainer.innerHTML = `
                    <div class="zano-status-indicator error">
                        <svg class="zano-status-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        <span>${message}</span>
                    </div>
                `;
                button.innerHTML = 'Payment Failed';
                button.disabled = true;
                
                // Redirect to checkout after a delay to retry payment
                setTimeout(() => {
                    window.location.href = '<?php echo esc_url($order->get_checkout_payment_url()); ?>';
                }, 3000);
            }
        } else {
            console.error('Payment check failed:', data.data);
        }
    })
    .catch(error => {
        console.error('Payment check error:', error);
    })
    .finally(() => {
        isChecking = false;
        button.innerHTML = originalText;
        button.disabled = false;
    });
}

// Auto-check payment status every 30 seconds
setInterval(checkPaymentStatus, 10000);

// Fast QR code generation function with logo support
function generateQRCodeFast(container, text, logoAsset) {
        // Clear container first
        container.innerHTML = '';
        
        // Add loading state
        const loadingDiv = document.createElement('div');
        loadingDiv.style.cssText = `
            width: 200px; 
            height: 200px; 
            background: #f8fafc; 
            border-radius: 8px; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            color: #64748b;
            font-size: 14px;
            border: 2px dashed #cbd5e1;
        `;
        loadingDiv.textContent = 'Loading QR...';
        container.appendChild(loadingDiv);
        
        // Use server-side QR generation with logo support
        let qrUrl = '<?php echo admin_url('admin-ajax.php'); ?>?action=zano_generate_qr&data=' + encodeURIComponent(text);
        if (logoAsset) {
            qrUrl += '&logo=' + encodeURIComponent(logoAsset);
        }
        
        const img = document.createElement('img');
        img.src = qrUrl;
        img.alt = 'Payment QR Code';
        img.style.cssText = `
            width: 200px;
            height: 200px;
            border-radius: 8px;
            display: block;
            margin: 0 auto;
        `;
        
        img.onload = function() {
            container.innerHTML = '';
            container.appendChild(img);
        };
        
        img.onerror = function() {
            container.innerHTML = `
                <div style="width: 200px; height: 200px; background: #fef2f2; border-radius: 8px; display: flex; align-items: center; justify-content: center; color: #dc2626; text-align: center; padding: 20px; border: 2px dashed #fca5a5;">
                    QR Code<br>Unavailable
                </div>
            `;
        };
    }

// Copy functionality for buttons
function initializeCopyButtons() {
    const copyButtons = document.querySelectorAll('.zano-copy-button');
    
    copyButtons.forEach(function(button) {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            const textToCopy = this.getAttribute('data-copy');
            const originalText = this.textContent;
            
            // Function to show success feedback
            const showSuccess = () => {
                this.textContent = 'Copied!';
                this.classList.add('copied');
                setTimeout(() => {
                    this.textContent = originalText;
                    this.classList.remove('copied');
                }, 1500);
            };
            
            // Function to show error feedback
            const showError = () => {
                this.textContent = 'Failed!';
                this.classList.add('error');
                setTimeout(() => {
                    this.textContent = originalText;
                    this.classList.remove('error');
                }, 1500);
            };
            
            // Try modern clipboard API first
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(textToCopy)
                    .then(showSuccess)
                    .catch((err) => {
                        console.log('Clipboard API failed, trying fallback:', err);
                        fallbackCopy(textToCopy, showSuccess, showError);
                    });
            } else {
                // Use fallback method for older browsers or non-secure contexts
                fallbackCopy(textToCopy, showSuccess, showError);
            }
        });
    });
}

// Fallback copy method for older browsers
function fallbackCopy(text, onSuccess, onError) {
    const textArea = document.createElement('textarea');
    textArea.value = text;
    textArea.style.position = 'fixed';
    textArea.style.left = '-999999px';
    textArea.style.top = '-999999px';
    document.body.appendChild(textArea);
    textArea.focus();
    textArea.select();
    
    try {
        const successful = document.execCommand('copy');
        if (successful) {
            onSuccess();
        } else {
            onError();
        }
    } catch (err) {
        console.error('Fallback copy failed:', err);
        onError();
    } finally {
        document.body.removeChild(textArea);
    }
}

// Load QR code library and initialize immediately
(function() {
    
    // Initialize immediately when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializePaymentPage);
    } else {
        initializePaymentPage();
    }
    
    function initializePaymentPage() {
        // Initialize copy buttons
        initializeCopyButtons();
        
        // Initialize the correct asset button and details
        switchAsset(currentAsset);
        
        // Initialize deeplink copy button
        updateDeeplinkButton();
        
        // Generate initial QR code immediately
        const qrContainer = document.getElementById('qr-code-container');
        if (qrContainer && assetData[currentAsset]) {
            generateQRCodeFast(qrContainer, assetData[currentAsset].deeplink, currentAsset);
        }
        
        console.log('Payment initialized with asset:', currentAsset);
    }
})();
</script> 