<?php
/**
 * Transactions Table View
 * 
 * This file contains the HTML template for displaying the Zano payment transactions table
 * in the WordPress admin area.
 */

// Prevent direct access
defined('ABSPATH') || exit;

// Variables should be passed from the calling method:
// $transactions, $total_items, $total_pages, $current_page, $duplicate_hashes
?>

<div class="wrap">
    <h1><?php _e('Zano Transactions', 'zano-payment-gateway'); ?></h1>
    
    <!-- Transaction Summary -->
    <?php
    global $wpdb;
    $table_name = $wpdb->prefix . 'zano_payments';
    
    $status_counts = $wpdb->get_results(
        "SELECT status, COUNT(*) as count FROM $table_name GROUP BY status",
        ARRAY_A
    );
    
    $summary = [];
    foreach ($status_counts as $status) {
        $summary[$status['status']] = intval($status['count']);
    }
    ?>
    
    <div class="zano-summary">
        <span><strong><?php echo esc_html(number_format_i18n($total_items)); ?></strong> <?php _e('Total', 'zano-payment-gateway'); ?></span>
        <span class="status-confirmed"><strong><?php echo esc_html($summary['confirmed'] ?? 0); ?></strong> <?php _e('Confirmed', 'zano-payment-gateway'); ?></span>
        <span class="status-pending"><strong><?php echo esc_html($summary['pending'] ?? 0); ?></strong> <?php _e('Pending', 'zano-payment-gateway'); ?></span>
        <span class="status-failed"><strong><?php echo esc_html($summary['failed'] ?? 0); ?></strong> <?php _e('Failed', 'zano-payment-gateway'); ?></span>
    </div>
    
    <!-- Bulk Actions -->
    <div class="tablenav top">
        <div class="alignleft actions">
            <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=zano-transactions&action=update-order-statuses'), 'zano_update_statuses')); ?>" 
               class="button button-primary" 
               onclick="return confirm('<?php _e('This will check all pending payments and update their statuses. This may take a while. Continue?', 'zano-payment-gateway'); ?>');">
                <?php _e('Update Order Statuses', 'zano-payment-gateway'); ?>
            </a>
            
            <span class="description" style="margin-left: 10px; font-style: italic;">
                <?php _e('Smart check: only verifies expired payments (>20min) and unconfirmed transactions that need checking.', 'zano-payment-gateway'); ?>
            </span>
        </div>
        
        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <div class="tablenav-pages">
            <span class="displaying-num">
                <?php printf(_n('%s item', '%s items', $total_items, 'zano-payment-gateway'), number_format_i18n($total_items)); ?>
            </span>
            
            <?php
            $page_links = paginate_links([
                'base' => add_query_arg('paged', '%#%'),
                'format' => '',
                'prev_text' => __('&laquo;'),
                'next_text' => __('&raquo;'),
                'total' => $total_pages,
                'current' => $current_page,
                'type' => 'array'
            ]);
            
            if ($page_links) {
                echo '<span class="pagination-links">';
                echo implode("\n", $page_links);
                echo '</span>';
            }
            ?>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Transactions Table -->
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th scope="col" class="manage-column column-order-id">
                    <?php _e('Order ID', 'zano-payment-gateway'); ?>
                </th>
                <th scope="col" class="manage-column column-payment-id">
                    <?php _e('Payment ID', 'zano-payment-gateway'); ?>
                </th>
                <th scope="col" class="manage-column column-amount">
                    <?php _e('Amount (ZANO)', 'zano-payment-gateway'); ?>
                </th>
                <th scope="col" class="manage-column column-tx-hash">
                    <?php _e('Transaction Hash', 'zano-payment-gateway'); ?>
                </th>
                <th scope="col" class="manage-column column-status">
                    <?php _e('Status', 'zano-payment-gateway'); ?>
                </th>
                <th scope="col" class="manage-column column-block-info">
                    <?php _e('Block Info', 'zano-payment-gateway'); ?>
                </th>
                <th scope="col" class="manage-column column-date">
                    <?php _e('Date', 'zano-payment-gateway'); ?>
                </th>
                <th scope="col" class="manage-column column-age">
                    <?php _e('Age', 'zano-payment-gateway'); ?>
                </th>
                <th scope="col" class="manage-column column-actions">
                    <?php _e('Actions', 'zano-payment-gateway'); ?>
                </th>
            </tr>
        </thead>
        
        <tbody>
            <?php if (empty($transactions)): ?>
                <tr>
                    <td colspan="9" class="no-items">
                        <?php _e('No transactions found.', 'zano-payment-gateway'); ?>
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($transactions as $transaction): ?>
                    <?php
                    $order = wc_get_order($transaction['order_id']);
                    $is_duplicate = !empty($transaction['tx_hash']) && in_array($transaction['tx_hash'], $duplicate_hashes);
                    ?>
                    <tr>
                        <!-- Order ID -->
                        <td class="column-order-id">
                            <?php if ($order): ?>
                                <a href="<?php echo esc_url($order->get_edit_order_url()); ?>" target="_blank">
                                    #<?php echo esc_html($transaction['order_id']); ?>
                                </a>
                                <br><small><?php echo esc_html($order->get_status()); ?></small>
                            <?php else: ?>
                                #<?php echo esc_html($transaction['order_id']); ?>
                                <br><small class="description"><?php _e('processing', 'zano-payment-gateway'); ?></small>
                            <?php endif; ?>
                        </td>
                        
                        <!-- Payment ID -->
                        <td class="column-payment-id">
                            <span title="<?php echo esc_attr($transaction['payment_id']); ?>">
                                <?php echo esc_html($transaction['payment_id']); ?>
                            </span>
                        </td>
                        
                        <!-- Amount -->
                        <td class="column-amount">
                            <?php echo esc_html(number_format($transaction['amount'], 8)); ?>
                            <?php if (!empty($transaction['received_amount'])): ?>
                                <br><small>
                                    <?php _e('Received:', 'zano-payment-gateway'); ?> 
                                    <?php echo esc_html(number_format($transaction['received_amount'], 8)); ?>
                                </small>
                            <?php endif; ?>
                        </td>
                        
                        <!-- Transaction Hash -->
                        <td class="column-tx-hash">
                            <?php if (!empty($transaction['tx_hash'])): ?>
                                <?php echo esc_html(substr($transaction['tx_hash'], 0, 12)); ?>...
                                <br>
                                <a href="https://explorer.zano.org/transaction/<?php echo esc_attr($transaction['tx_hash']); ?>" 
                                   target="_blank" 
                                   style="font-size: 11px;">
                                    <?php _e('View', 'zano-payment-gateway'); ?>
                                </a>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        
                        <!-- Status -->
                        <td class="column-status">
                            <?php
                            $status_label = ucfirst($transaction['status']);
                            if ($transaction['status'] === 'confirmed' && $transaction['confirmations'] > 0) {
                                $status_label = 'confirmed (' . $transaction['confirmations'] . ' confirmations)';
                            }
                            ?>
                            <span class="status-<?php echo esc_attr($transaction['status']); ?>">
                                <?php echo esc_html($status_label); ?>
                            </span>
                            
                            <?php if ($is_duplicate): ?>
                                <br><small style="color: #d63638;">
                                    <?php _e('Duplicate TX Hash', 'zano-payment-gateway'); ?>
                                </small>
                            <?php endif; ?>
                        </td>
                        
                        <!-- Block Info -->
                        <td class="column-block-info">
                            <?php if (!empty($transaction['received_block']) || !empty($transaction['current_block'])): ?>
                                <?php if (!empty($transaction['received_block'])): ?>
                                    <strong><?php _e('Received:', 'zano-payment-gateway'); ?></strong> <?php echo esc_html(number_format($transaction['received_block'])); ?>
                                    <br>
                                <?php endif; ?>
                                <?php if (!empty($transaction['current_block'])): ?>
                                    <strong><?php _e('Current:', 'zano-payment-gateway'); ?></strong> <?php echo esc_html(number_format($transaction['current_block'])); ?>
                                    <br>
                                <?php endif; ?>
                                <?php if (!empty($transaction['keeper_block'])): ?>
                                    <strong><?php _e('Keeper:', 'zano-payment-gateway'); ?></strong> <?php echo esc_html(number_format($transaction['keeper_block'])); ?>
                                <?php endif; ?>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        
                        <!-- Date -->
                        <td class="column-date">
                            <?php
                            $created_time = strtotime($transaction['created_at']);
                            echo esc_html(date('M j, Y g:i a', $created_time));
                            ?>
                        </td>
                        
                        <!-- Age -->
                        <td class="column-age">
                            <?php
                            $created_time = strtotime($transaction['created_at']);
                            echo esc_html(human_time_diff($created_time, current_time('timestamp')) . ' ago');
                            ?>
                        </td>
                        
                        <!-- Actions -->
                        <td class="column-actions">
                            <?php if (!empty($transaction['tx_hash'])): ?>
                                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=zano-transactions&action=clear-tx-hash&payment_id=' . urlencode($transaction['payment_id'])), 'zano_transaction_action')); ?>"
                                   onclick="return confirm('<?php _e('Clear the transaction hash and reset payment to pending?', 'zano-payment-gateway'); ?>');">
                                    <?php _e('Clear TX Hash', 'zano-payment-gateway'); ?>
                                </a>
                                |
                            <?php endif; ?>
                            
                            <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=zano-transactions&action=cancel&payment_id=' . urlencode($transaction['payment_id'])), 'zano_transaction_action')); ?>"
                               onclick="return confirm('<?php _e('Cancel this order?', 'zano-payment-gateway'); ?>');">
                                <?php _e('Cancel', 'zano-payment-gateway'); ?>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    
    <!-- Bottom Pagination -->
    <?php if ($total_pages > 1): ?>
    <div class="tablenav bottom">
        <div class="tablenav-pages">
            <span class="displaying-num">
                <?php printf(_n('%s item', '%s items', $total_items, 'zano-payment-gateway'), number_format_i18n($total_items)); ?>
            </span>
            
            <?php
            if ($page_links) {
                echo '<span class="pagination-links">';
                echo implode("\n", $page_links);
                echo '</span>';
            }
            ?>
        </div>
    </div>
    <?php endif; ?>
    

</div>

<style>
/* Clean, professional styling for Zano transactions table */
.zano-update-notice {
    margin: 5px 0 15px 0;
}

/* Horizontal transaction summary */
.zano-summary {
    background: #f9f9f9;
    border: 1px solid #e1e1e1;
    border-radius: 3px;
    padding: 12px 16px;
    margin: 15px 0 20px 0;
    display: flex;
    gap: 30px;
    align-items: center;
    font-size: 13px;
}

.zano-summary span {
    display: flex;
    align-items: center;
    gap: 4px;
}

/* Status styling - only colors for statuses */
.status-confirmed {
    color: #008a00;
    font-weight: 500;
}

.status-pending {
    color: #d63638;
    font-weight: 500;
}

.status-processing {
    color: #0073aa;
    font-weight: 500;
}

.status-failed {
    color: #d63638;
    font-weight: 500;
}

/* Column widths */
.column-order-id {
    width: 80px;
}

.column-payment-id {
    width: 120px;
    font-family: monospace;
    font-size: 12px;
}

.column-amount {
    width: 120px;
    font-family: monospace;
}

.column-tx-hash {
    width: 120px;
    font-family: monospace;
    font-size: 11px;
}

.column-status {
    width: 140px;
}

.column-block-info {
    width: 120px;
    font-size: 11px;
}

.column-date {
    width: 120px;
    font-size: 12px;
}

.column-age {
    width: 100px;
    font-size: 12px;
    color: #666;
}

.column-actions {
    width: 120px;
    font-size: 11px;
}

/* Clean table styling */
.wp-list-table th {
    font-weight: 600;
}

.wp-list-table td {
    vertical-align: top;
    padding: 8px 10px;
}

.wp-list-table small {
    color: #666;
    font-size: 11px;
}

/* Responsive */
@media screen and (max-width: 782px) {
    .wp-list-table td.column-payment-id,
    .wp-list-table td.column-block-info,
    .wp-list-table td.column-date {
        display: none;
    }
}
</style> 