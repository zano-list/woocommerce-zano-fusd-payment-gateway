<?php
/**
 * Zano Database Migration
 * 
 * Handles database schema updates for asset support
 *
 * @package Zano_Payment_Gateway
 */

defined('ABSPATH') || exit;

class Zano_Database_Migration {
    
    /**
     * Current database version
     */
    const DB_VERSION = '1.3.0';
    
    /**
     * Run database migrations
     */
    public static function migrate() {
        $current_version = get_option('zano_payment_db_version', '1.0.0');
        
        if (version_compare($current_version, self::DB_VERSION, '<')) {
            self::add_asset_columns();
            
            // Add unique constraint for tx_hash to prevent race conditions
            if (version_compare($current_version, '1.2.0', '<')) {
                self::add_tx_hash_unique_constraint();
            }
            
            // Add block tracking columns
            if (version_compare($current_version, '1.3.0', '<')) {
                self::add_block_tracking_columns();
            }
            
            update_option('zano_payment_db_version', self::DB_VERSION);
        }
    }
    

    
    /**
     * Add asset-specific columns to payments table
     */
    private static function add_asset_columns() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'zano_payments';
        
        // Check if columns already exist
        $columns = $wpdb->get_col("DESCRIBE {$table_name}");
        
        $new_columns = [];
        
        // Handle integrated_address -> wallet_address migration
        if (in_array('integrated_address', $columns) && !in_array('wallet_address', $columns)) {
            $new_columns[] = "CHANGE COLUMN integrated_address wallet_address TEXT NOT NULL";
        } elseif (!in_array('wallet_address', $columns)) {
            $new_columns[] = "ADD COLUMN wallet_address TEXT NOT NULL AFTER payment_id";
        }
        
        // Add missing standard columns
        if (!in_array('updated_at', $columns)) {
            $new_columns[] = "ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at";
        }
        
        if (!in_array('confirmations', $columns)) {
            $new_columns[] = "ADD COLUMN confirmations INT(11) NOT NULL DEFAULT 0 AFTER status";
        }
        
        if (!in_array('received_amount', $columns)) {
            $new_columns[] = "ADD COLUMN received_amount DECIMAL(20,8) NULL AFTER confirmations";
        }
        
        if (!in_array('asset_id', $columns)) {
            $new_columns[] = "ADD COLUMN asset_id VARCHAR(64) NULL AFTER amount";
        }
        
        if (!in_array('asset_symbol', $columns)) {
            $new_columns[] = "ADD COLUMN asset_symbol VARCHAR(10) NULL AFTER asset_id";
        }
        
        if (!in_array('asset_amount', $columns)) {
            $new_columns[] = "ADD COLUMN asset_amount DECIMAL(20,8) NULL AFTER asset_symbol";
        }
        
        if (!in_array('tx_hash', $columns)) {
            $new_columns[] = "ADD COLUMN tx_hash VARCHAR(64) NULL AFTER asset_amount";
        }
        
        if (!in_array('completed_at', $columns)) {
            $new_columns[] = "ADD COLUMN completed_at DATETIME NULL AFTER tx_hash";
        }
        
        if (!empty($new_columns)) {
            $sql = "ALTER TABLE {$table_name} " . implode(', ', $new_columns);
            $result = $wpdb->query($sql);
            
            if ($result === false) {
                error_log("Zano Payment Gateway: Failed to add asset columns: " . $wpdb->last_error);
            } else {
                error_log("Zano Payment Gateway: Successfully added asset columns");
            }
        }
        
        // Add indexes for better performance
        $indexes = $wpdb->get_results("SHOW INDEX FROM {$table_name}");
        $existing_indexes = array_column($indexes, 'Key_name');
        
        if (!in_array('asset_id', $existing_indexes)) {
            $wpdb->query("ALTER TABLE {$table_name} ADD INDEX idx_asset_id (asset_id)");
        }
        
        if (!in_array('tx_hash', $existing_indexes)) {
            $wpdb->query("ALTER TABLE {$table_name} ADD INDEX idx_tx_hash (tx_hash)");
        }
    }
    
    /**
     * Add block tracking columns
     */
    private static function add_block_tracking_columns() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'zano_payments';
        
        // Check if columns already exist
        $columns = $wpdb->get_col("DESCRIBE {$table_name}");
        
        $new_columns = [];
        
        if (!in_array('received_block', $columns)) {
            $new_columns[] = "ADD COLUMN received_block INT(11) NULL AFTER completed_at";
        }
        
        if (!in_array('current_block', $columns)) {
            $new_columns[] = "ADD COLUMN current_block INT(11) NULL AFTER received_block";
        }
        
        if (!in_array('keeper_block', $columns)) {
            $new_columns[] = "ADD COLUMN keeper_block INT(11) NULL AFTER current_block";
        }
        
        if (!empty($new_columns)) {
            $sql = "ALTER TABLE {$table_name} " . implode(', ', $new_columns);
            $result = $wpdb->query($sql);
            
            if ($result === false) {
                error_log("Zano Payment Gateway: Failed to add block tracking columns: " . $wpdb->last_error);
            } else {
                error_log("Zano Payment Gateway: Successfully added block tracking columns");
            }
        }
    }

    /**
     * Add unique constraint on tx_hash to prevent race conditions
     */
    private static function add_tx_hash_unique_constraint() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'zano_payments';
        
        // First, clean up any duplicate tx_hashes that might exist
        // Keep only the earliest record for each tx_hash
        $wpdb->query("
            DELETE p1 FROM {$table_name} p1
            INNER JOIN {$table_name} p2 
            WHERE p1.tx_hash = p2.tx_hash 
            AND p1.tx_hash IS NOT NULL 
            AND p1.tx_hash != ''
            AND p1.id > p2.id
        ");
        
        // Check if unique constraint already exists
        $constraints = $wpdb->get_results("
            SELECT CONSTRAINT_NAME 
            FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = '{$table_name}' 
            AND CONSTRAINT_TYPE = 'UNIQUE'
        ");
        
        $has_tx_hash_unique = false;
        foreach ($constraints as $constraint) {
            if (strpos($constraint->CONSTRAINT_NAME, 'tx_hash') !== false) {
                $has_tx_hash_unique = true;
                break;
            }
        }
        
        // Add unique constraint if it doesn't exist
        if (!$has_tx_hash_unique) {
            $result = $wpdb->query("ALTER TABLE {$table_name} ADD CONSTRAINT unique_tx_hash UNIQUE (tx_hash)");
            
            if ($result === false) {
                error_log("Zano Payment Gateway: Failed to add unique constraint on tx_hash: " . $wpdb->last_error);
            } else {
                error_log("Zano Payment Gateway: Successfully added unique constraint on tx_hash");
            }
        }
    }
    
    /**
     * Create the payments table if it doesn't exist
     */
    public static function create_payments_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'zano_payments';
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id int(11) NOT NULL AUTO_INCREMENT,
            order_id int(11) NOT NULL,
            payment_id varchar(64) NOT NULL,
            wallet_address text NOT NULL,
            amount decimal(20,8) NOT NULL,
            asset_id varchar(64) NULL,
            asset_symbol varchar(10) NULL,
            asset_amount decimal(20,8) NULL,
            tx_hash varchar(64) NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            confirmations int(11) NOT NULL DEFAULT 0,
            received_amount decimal(20,8) NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            completed_at datetime NULL,
            received_block int(11) NULL,
            current_block int(11) NULL,
            keeper_block int(11) NULL,
            PRIMARY KEY (id),
            KEY idx_order_id (order_id),
            KEY idx_payment_id (payment_id),
            KEY idx_status (status),
            KEY idx_asset_id (asset_id),
            KEY idx_tx_hash (tx_hash),
            UNIQUE KEY unique_tx_hash (tx_hash)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        error_log("Zano Payment Gateway: Created payments table with all columns including block tracking");
    }
    
    /**
     * Check if block tracking columns exist
     */
    public static function has_block_tracking_columns() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'zano_payments';
        
        // Check if table exists first
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'");
        if (!$table_exists) {
            return false;
        }
        
        $columns = $wpdb->get_col("DESCRIBE {$table_name}");
        
        return in_array('received_block', $columns) && 
               in_array('current_block', $columns) && 
               in_array('keeper_block', $columns);
    }
}

// Run migration on plugin activation
register_activation_hook(__FILE__, ['Zano_Database_Migration', 'create_payments_table']);
add_action('plugins_loaded', ['Zano_Database_Migration', 'migrate']); 