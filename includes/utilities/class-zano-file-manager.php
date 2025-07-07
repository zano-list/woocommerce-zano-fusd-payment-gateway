<?php
defined('ABSPATH') || exit;

/**
 * Zano File Manager
 *
 * Handles all file and directory operations for the Zano Payment Gateway.
 * Extracted from utilities for better separation of concerns.
 */
class Zano_File_Manager {

    /**
     * Create plugin directories
     *
     * @return bool True on success
     */
    public static function create_directories() {
        $directories = [
            ZANO_PAYMENT_PLUGIN_DIR . Zano_Constants::IMAGES_DIR,
            ZANO_PAYMENT_PLUGIN_DIR . Zano_Constants::CSS_DIR,
            ZANO_PAYMENT_PLUGIN_DIR . Zano_Constants::JS_DIR,
            ZANO_PAYMENT_PLUGIN_DIR . Zano_Constants::LOGS_DIR,
        ];
        
        $success = true;
        foreach ($directories as $dir) {
            if (!self::ensure_directory_exists($dir)) {
                $success = false;
            }
        }
        
        return $success;
    }

    /**
     * Ensure a directory exists, create if it doesn't
     *
     * @param string $dir Directory path
     * @return bool True if directory exists or was created
     */
    public static function ensure_directory_exists($dir) {
        if (file_exists($dir)) {
            return is_dir($dir);
        }
        
        return wp_mkdir_p($dir);
    }

    /**
     * Create default assets
     *
     * @return bool True on success
     */
    public static function create_default_assets() {
        return self::create_default_icon() && self::create_asset_files();
    }

    /**
     * Create default Zano icon if it doesn't exist
     *
     * @return bool True on success
     */
    public static function create_default_icon() {
        $icon_path = ZANO_PAYMENT_PLUGIN_DIR . Zano_Constants::IMAGES_DIR . 'zano-icon.png';
        
        if (file_exists($icon_path)) {
            return true; // Already exists
        }
        
        // Ensure directory exists
        $icon_dir = dirname($icon_path);
        if (!self::ensure_directory_exists($icon_dir)) {
            return false;
        }
        
        // Create default icon from base64 data
        $icon_data = self::get_default_zano_icon_data();
        $result = file_put_contents($icon_path, base64_decode($icon_data));
        
        return $result !== false;
    }

    /**
     * Create FUSD icon if it doesn't exist
     *
     * @return bool True on success
     */
    public static function create_fusd_icon() {
        $icon_path = ZANO_PAYMENT_PLUGIN_DIR . Zano_Constants::IMAGES_DIR . 'fusd-icon.png';
        
        if (file_exists($icon_path)) {
            return true; // Already exists
        }
        
        // Ensure directory exists
        $icon_dir = dirname($icon_path);
        if (!self::ensure_directory_exists($icon_dir)) {
            return false;
        }
        
        // Create default FUSD icon
        $icon_data = self::get_default_fusd_icon_data();
        $result = file_put_contents($icon_path, base64_decode($icon_data));
        
        return $result !== false;
    }

    /**
     * Create asset files (CSS, JS placeholders)
     *
     * @return bool True on success
     */
    public static function create_asset_files() {
        $success = true;
        
        // Create empty log file with proper permissions
        $log_file = ZANO_PAYMENT_PLUGIN_DIR . Zano_Constants::LOGS_DIR . 'zano-payment.log';
        if (!file_exists($log_file)) {
            $result = file_put_contents($log_file, "# Zano Payment Gateway Log\n");
            if ($result !== false) {
                chmod($log_file, 0644); // Set appropriate permissions
            } else {
                $success = false;
            }
        }
        
        return $success;
    }

    /**
     * Clean up temporary files
     *
     * @return bool True on success
     */
    public static function cleanup_temp_files() {
        $temp_dirs = [
            ZANO_PAYMENT_PLUGIN_DIR . 'tmp/',
            ZANO_PAYMENT_PLUGIN_DIR . 'cache/',
        ];
        
        $success = true;
        foreach ($temp_dirs as $dir) {
            if (file_exists($dir)) {
                $success = self::delete_directory_recursive($dir) && $success;
            }
        }
        
        return $success;
    }

    /**
     * Delete directory and all its contents recursively
     *
     * @param string $dir Directory path
     * @return bool True on success
     */
    public static function delete_directory_recursive($dir) {
        if (!is_dir($dir)) {
            return false;
        }
        
        $files = array_diff(scandir($dir), ['.', '..']);
        
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            
            if (is_dir($path)) {
                self::delete_directory_recursive($path);
            } else {
                unlink($path);
            }
        }
        
        return rmdir($dir);
    }

    /**
     * Get log file path
     *
     * @param string $log_name Log file name (without extension)
     * @return string Full path to log file
     */
    public static function get_log_file_path($log_name = 'zano-payment') {
        return ZANO_PAYMENT_PLUGIN_DIR . Zano_Constants::LOGS_DIR . $log_name . '.log';
    }

    /**
     * Write to log file
     *
     * @param string $message Log message
     * @param string $log_name Log file name
     * @return bool True on success
     */
    public static function write_log($message, $log_name = 'zano-payment') {
        $log_file = self::get_log_file_path($log_name);
        
        // Ensure log directory exists
        $log_dir = dirname($log_file);
        if (!self::ensure_directory_exists($log_dir)) {
            return false;
        }
        
        $timestamp = date('Y-m-d H:i:s');
        $log_message = sprintf("[%s] %s\n", $timestamp, $message);
        
        return file_put_contents($log_file, $log_message, FILE_APPEND | LOCK_EX) !== false;
    }

    /**
     * Get log file contents
     *
     * @param string $log_name Log file name
     * @param int $lines Number of lines to read from end (0 = all)
     * @return string Log contents
     */
    public static function get_log_contents($log_name = 'zano-payment', $lines = 100) {
        $log_file = self::get_log_file_path($log_name);
        
        if (!file_exists($log_file)) {
            return '';
        }
        
        if ($lines === 0) {
            return file_get_contents($log_file);
        }
        
        // Read last N lines efficiently
        $file = file($log_file);
        return implode('', array_slice($file, -$lines));
    }

    /**
     * Clear log file
     *
     * @param string $log_name Log file name
     * @return bool True on success
     */
    public static function clear_log($log_name = 'zano-payment') {
        $log_file = self::get_log_file_path($log_name);
        
        if (!file_exists($log_file)) {
            return true; // Already clear
        }
        
        return file_put_contents($log_file, '') !== false;
    }

    /**
     * Get asset URL
     *
     * @param string $asset_path Relative asset path
     * @return string Full asset URL
     */
    public static function get_asset_url($asset_path) {
        return ZANO_PAYMENT_PLUGIN_URL . $asset_path;
    }

    /**
     * Get image URL
     *
     * @param string $image_name Image filename
     * @return string Full image URL
     */
    public static function get_image_url($image_name) {
        return self::get_asset_url(Zano_Constants::IMAGES_DIR . $image_name);
    }

    /**
     * Check if file exists and is readable
     *
     * @param string $file_path File path
     * @return bool True if file exists and is readable
     */
    public static function is_file_readable($file_path) {
        return file_exists($file_path) && is_readable($file_path);
    }

    /**
     * Get file size in human readable format
     *
     * @param string $file_path File path
     * @return string File size
     */
    public static function get_file_size($file_path) {
        if (!self::is_file_readable($file_path)) {
            return '0 B';
        }
        
        $bytes = filesize($file_path);
        $units = ['B', 'KB', 'MB', 'GB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * Get default Zano icon data (base64 encoded PNG)
     *
     * @return string Base64 encoded icon data
     */
    private static function get_default_zano_icon_data() {
        return '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';
    }

    /**
     * Get default FUSD icon data (base64 encoded PNG)
     *
     * @return string Base64 encoded icon data
     */
    private static function get_default_fusd_icon_data() {
        // Placeholder FUSD icon - would need actual icon data
        return 'iVBORw0KGgoAAAANSUhEUgAAACAAAAAgCAMAAABEpIrGAAAABGdBTUEAALGPC/xhBQAAACBjSFJNAAB6JgAAgIQAAPoAAACA6AAAdTAAAOpgAAA6mAAAF3CculE8AAAB+1BMVEUAAABcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzNcqzP///8vW/EyAAAAqnRSTlMAAQ0WGxsdEwcaZZyqeQYlp+PohjwasfHd0RJTZWZphIhRlqWqfWoRavz8hjPR8aEUaNbXRo3Ioz/f+vAjHO2EBeTbOi0k5IVoFGH8/IcQkNfYQhMOFxPh99cWE+J1Dg8Z5uR8Ihzy+PNKFjL8+ioQ5fBrINX1QBY3pehpER77/IYRWv/+hhJTajrR0qAUaNbXRhIs7PVOBuDXNigk5oIxM+D8mRAU5vBpHvP1QAH8qyMAAAABYktHRKrL';
    }
} 