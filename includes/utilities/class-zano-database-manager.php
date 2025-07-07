<?php
defined('ABSPATH') || exit;

/**
 * Zano Database Manager
 *
 * Handles all database operations for the Zano Payment Gateway.
 * Extracted from utilities for better separation of concerns.
 */
class Zano_Database_Manager {

    /**
     * Get the payments table name
     *
     * @return string Table name with WordPress prefix
     */
    public static function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . Zano_Constants::DB_TABLE_NAME;
    }

    /**
     * Create or update plugin database tables
     *
     * @return bool True on success, false on failure
     */
    public static function create_tables() {
        global $wpdb;
        
        $table_name = self::get_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            order_id bigint(20) NOT NULL,
            payment_id varchar(64) NOT NULL,
            wallet_address text NOT NULL,
            amount decimal(15,8) NOT NULL,
            status varchar(20) NOT NULL DEFAULT '" . Zano_Constants::STATUS_PENDING . "',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            confirmations int(11) NOT NULL DEFAULT 0,
            tx_hash varchar(64) DEFAULT NULL,
            received_amount decimal(15,8) DEFAULT NULL,
            asset_id varchar(64) DEFAULT NULL,
            asset_symbol varchar(10) DEFAULT NULL,
            asset_amount decimal(15,8) DEFAULT NULL,
            received_block bigint(20) DEFAULT NULL,
            current_block bigint(20) DEFAULT NULL,
            keeper_block bigint(20) DEFAULT NULL,
            completed_at datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY order_id (order_id),
            KEY payment_id (payment_id),
            KEY status (status),
            KEY asset_id (asset_id),
            UNIQUE KEY tx_hash (tx_hash)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        $result = dbDelta($sql);
        
        // Verify table creation
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
        
        if (!$table_exists) {
            error_log('Failed to create Zano payments table');
            return false;
        }
        
        // Run any necessary migrations
        self::run_table_migrations();
        
        return true;
    }

    /**
     * Run table migrations for new columns
     *
     * @return bool True on success
     */
    public static function run_table_migrations() {
        global $wpdb;
        
        $table_name = self::get_table_name();
        $columns_added = true;
        
        // Check and add missing columns
        $required_columns = [
            'received_amount' => 'decimal(15,8) DEFAULT NULL',
            'asset_id' => 'varchar(64) DEFAULT NULL', 
            'asset_symbol' => 'varchar(10) DEFAULT NULL',
            'asset_amount' => 'decimal(15,8) DEFAULT NULL',
            'received_block' => 'bigint(20) DEFAULT NULL',
            'current_block' => 'bigint(20) DEFAULT NULL',
            'keeper_block' => 'bigint(20) DEFAULT NULL',
            'completed_at' => 'datetime DEFAULT NULL'
        ];
        
        foreach ($required_columns as $column => $definition) {
            $column_exists = $wpdb->get_results("SHOW COLUMNS FROM $table_name LIKE '$column'");
            
            if (empty($column_exists)) {
                $result = $wpdb->query("ALTER TABLE $table_name ADD COLUMN $column $definition");
                if ($result === false) {
                    error_log("Failed to add column $column to $table_name");
                    $columns_added = false;
                }
            }
        }
        
        return $columns_added;
    }

    /**
     * Check for and resolve duplicate transaction hashes
     *
     * @return bool True on success
     */
    public static function resolve_duplicate_transactions() {
        global $wpdb;
        
        $table_name = self::get_table_name();
        
        // Check if tx_hash column has a UNIQUE constraint
        $has_unique_constraint = self::has_unique_constraint('tx_hash');
        
        // If no unique constraint exists, add it
        if (!$has_unique_constraint) {
            // First resolve any duplicate tx_hash values
            $duplicates_resolved = self::handle_duplicate_hashes();
            
            if ($duplicates_resolved) {
                // Add the unique constraint
                $wpdb->query("ALTER TABLE $table_name ADD UNIQUE KEY tx_hash (tx_hash)");
            }
        }
        
        return true;
    }

    /**
     * Check if a column has a unique constraint
     *
     * @param string $column_name Column name to check
     * @return bool True if unique constraint exists
     */
    private static function has_unique_constraint($column_name) {
        global $wpdb;
        
        $table_name = self::get_table_name();
        $indexes = $wpdb->get_results("SHOW INDEX FROM $table_name WHERE Column_name = '$column_name'");
        
        foreach ($indexes as $index) {
            if ($index->Non_unique == 0) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Handle duplicate transaction hashes
     *
     * @return bool True on success
     */
    private static function handle_duplicate_hashes() {
        global $wpdb;
        
        $table_name = self::get_table_name();
        
        // Find duplicate transaction hashes
        $duplicates = $wpdb->get_results(
            "SELECT tx_hash, COUNT(*) as count FROM $table_name 
             WHERE tx_hash IS NOT NULL 
             GROUP BY tx_hash 
             HAVING COUNT(*) > 1"
        );
        
        if (empty($duplicates)) {
            return true;
        }
        
        // Log the duplicate transaction hashes
        error_log('Found duplicated transaction hashes in Zano payments table:');
        
        foreach ($duplicates as $duplicate) {
            error_log('Transaction hash: ' . $duplicate->tx_hash . ' (used ' . $duplicate->count . ' times)');
            
            // Get all records with this tx_hash
            $records = $wpdb->get_results($wpdb->prepare(
                "SELECT id, order_id, status, created_at FROM $table_name WHERE tx_hash = %s ORDER BY created_at ASC",
                $duplicate->tx_hash
            ));
            
            // Keep only the first record (earliest) with this transaction hash
            $first_record = array_shift($records);
            error_log('Keeping transaction for order: ' . $first_record->order_id);
            
            // Clear the transaction hash from all other records
            foreach ($records as $record) {
                error_log('Clearing transaction hash from order: ' . $record->order_id);
                
                $wpdb->update(
                    $table_name,
                    [
                        'tx_hash' => null,
                        'status' => Zano_Constants::STATUS_PENDING,
                    ],
                    ['id' => $record->id]
                );
                
                // Add a note to the order
                $order = wc_get_order($record->order_id);
                if ($order) {
                    $order->add_order_note(sprintf(
                        __('Payment reset to pending: Transaction %s was already assigned to order #%s', 'zano-payment-gateway'),
                        $duplicate->tx_hash,
                        $first_record->order_id
                    ));
                }
            }
        }
        
        return true;
    }

    /**
     * Get payment record by order ID
     *
     * @param int $order_id Order ID
     * @return array|null Payment record or null if not found
     */
    public static function get_payment_by_order_id($order_id) {
        global $wpdb;
        
        $table_name = self::get_table_name();
        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table_name WHERE order_id = %d", $order_id),
            ARRAY_A
        );
    }

    /**
     * Get payment record by payment ID
     *
     * @param string $payment_id Payment ID
     * @return array|null Payment record or null if not found
     */
    public static function get_payment_by_payment_id($payment_id) {
        global $wpdb;
        
        $table_name = self::get_table_name();
        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table_name WHERE payment_id = %s", $payment_id),
            ARRAY_A
        );
    }

    /**
     * Insert new payment record
     *
     * @param array $payment_data Payment data
     * @return int|false Payment ID on success, false on failure
     */
    public static function insert_payment($payment_data) {
        global $wpdb;
        
        $table_name = self::get_table_name();
        
        // Set defaults
        $payment_data = array_merge([
            'status' => Zano_Constants::STATUS_PENDING,
            'created_at' => current_time('mysql'),
            'confirmations' => 0
        ], $payment_data);
        
        $result = $wpdb->insert($table_name, $payment_data);
        
        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Update payment record
     *
     * @param int $payment_id Payment ID
     * @param array $update_data Data to update
     * @return bool True on success, false on failure
     */
    public static function update_payment($payment_id, $update_data) {
        global $wpdb;
        
        $table_name = self::get_table_name();
        
        // Always update the updated_at timestamp
        $update_data['updated_at'] = current_time('mysql');
        
        $result = $wpdb->update(
            $table_name,
            $update_data,
            ['id' => $payment_id]
        );
        
        return $result !== false;
    }

    /**
     * Delete expired payments
     *
     * @param int $expiry_time Expiry time in seconds (default: 20 minutes)
     * @return int Number of deleted records
     */
    public static function delete_expired_payments($expiry_time = null) {
        global $wpdb;
        
        if ($expiry_time === null) {
            $expiry_time = Zano_Constants::DEFAULT_PAYMENT_TIMEOUT;
        }
        
        $table_name = self::get_table_name();
        $cutoff_time = date('Y-m-d H:i:s', time() - $expiry_time);
        
        $result = $wpdb->query($wpdb->prepare(
            "DELETE FROM $table_name 
             WHERE status = %s 
             AND tx_hash IS NULL 
             AND created_at < %s",
            Zano_Constants::STATUS_PENDING,
            $cutoff_time
        ));
        
        return $result ?: 0;
    }

    /**
     * Get all payment statuses summary
     *
     * @return array Status counts
     */
    public static function get_payment_status_summary() {
        global $wpdb;
        
        $table_name = self::get_table_name();
        $results = $wpdb->get_results(
            "SELECT status, COUNT(*) as count FROM $table_name GROUP BY status",
            ARRAY_A
        );
        
        $summary = [];
        foreach ($results as $result) {
            $summary[$result['status']] = intval($result['count']);
        }
        
        return $summary;
    }

    /**
     * Drop the payments table (use with caution!)
     *
     * @return bool True on success
     */
    public static function drop_table() {
        global $wpdb;
        
        $table_name = self::get_table_name();
        $result = $wpdb->query("DROP TABLE IF EXISTS $table_name");
        
        return $result !== false;
    }
} 