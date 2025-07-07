<?php
defined('ABSPATH') || exit;

/**
 * Zano Cron Manager
 *
 * Handles all cron job operations for the Zano Payment Gateway.
 * Extracted from utilities for better separation of concerns.
 */
class Zano_Cron_Manager {

    // Cron hook names as constants for better maintainability
    const HOOK_MONITOR_PAYMENTS = 'zano_monitor_payments';
    const HOOK_CLEANUP_EXPIRED = 'zano_cleanup_expired_payments';
    const HOOK_UPDATE_STATUSES = 'zano_update_payment_statuses';

    /**
     * Initialize all cron jobs
     *
     * @return bool True on success
     */
    public static function init_cron_jobs() {
        $success = true;
        
        // Schedule payment monitoring (every 5 minutes)
        if (!self::schedule_payment_monitoring()) {
            $success = false;
        }
        
        // Schedule expired payment cleanup (every hour)
        if (!self::schedule_cleanup_expired()) {
            $success = false;
        }
        
        // Schedule status updates (every 15 minutes)
        if (!self::schedule_status_updates()) {
            $success = false;
        }
        
        return $success;
    }

    /**
     * Schedule payment monitoring cron job
     *
     * @return bool True on success
     */
    public static function schedule_payment_monitoring() {
        if (!wp_next_scheduled(self::HOOK_MONITOR_PAYMENTS)) {
            $result = wp_schedule_event(
                time(),
                Zano_Constants::CRON_INTERVAL_MONITOR,
                self::HOOK_MONITOR_PAYMENTS
            );
            
            if ($result === false) {
                error_log('Failed to schedule Zano payment monitoring cron job');
                return false;
            }
        }
        
        return true;
    }

    /**
     * Schedule expired payment cleanup cron job
     *
     * @return bool True on success
     */
    public static function schedule_cleanup_expired() {
        if (!wp_next_scheduled(self::HOOK_CLEANUP_EXPIRED)) {
            $result = wp_schedule_event(
                time(),
                Zano_Constants::CRON_INTERVAL_CLEANUP,
                self::HOOK_CLEANUP_EXPIRED
            );
            
            if ($result === false) {
                error_log('Failed to schedule Zano expired payment cleanup cron job');
                return false;
            }
        }
        
        return true;
    }

    /**
     * Schedule payment status updates cron job
     *
     * @return bool True on success
     */
    public static function schedule_status_updates() {
        if (!wp_next_scheduled(self::HOOK_UPDATE_STATUSES)) {
            $result = wp_schedule_event(
                time(),
                Zano_Constants::CRON_INTERVAL_STATUS_UPDATE,
                self::HOOK_UPDATE_STATUSES
            );
            
            if ($result === false) {
                error_log('Failed to schedule Zano status update cron job');
                return false;
            }
        }
        
        return true;
    }

    /**
     * Unschedule all cron jobs
     *
     * @return bool True on success
     */
    public static function unschedule_all_cron_jobs() {
        $success = true;
        
        $hooks = [
            self::HOOK_MONITOR_PAYMENTS,
            self::HOOK_CLEANUP_EXPIRED,
            self::HOOK_UPDATE_STATUSES
        ];
        
        foreach ($hooks as $hook) {
            if (!self::unschedule_cron_job($hook)) {
                $success = false;
            }
        }
        
        return $success;
    }

    /**
     * Unschedule a specific cron job
     *
     * @param string $hook Cron job hook name
     * @return bool True on success
     */
    public static function unschedule_cron_job($hook) {
        $timestamp = wp_next_scheduled($hook);
        
        if ($timestamp) {
            $result = wp_unschedule_event($timestamp, $hook);
            
            if ($result === false) {
                error_log("Failed to unschedule cron job: $hook");
                return false;
            }
        }
        
        return true;
    }

    /**
     * Get status of all cron jobs
     *
     * @return array Cron job statuses
     */
    public static function get_cron_status() {
        $hooks = [
            self::HOOK_MONITOR_PAYMENTS => 'Payment Monitoring',
            self::HOOK_CLEANUP_EXPIRED => 'Expired Payment Cleanup',
            self::HOOK_UPDATE_STATUSES => 'Status Updates'
        ];
        
        $status = [];
        
        foreach ($hooks as $hook => $name) {
            $next_run = wp_next_scheduled($hook);
            $status[$hook] = [
                'name' => $name,
                'scheduled' => $next_run !== false,
                'next_run' => $next_run ? date('Y-m-d H:i:s', $next_run) : 'Not scheduled',
                'next_run_timestamp' => $next_run ?: 0
            ];
        }
        
        return $status;
    }

    /**
     * Force run payment monitoring
     *
     * @return bool True on success
     */
    public static function force_run_payment_monitoring() {
        if (class_exists('Zano_Transaction_Monitor')) {
            $monitor = new Zano_Transaction_Monitor();
            $monitor->check_transactions();
            return true;
        }
        
        error_log('Zano_Transaction_Monitor class not found');
        return false;
    }

    /**
     * Force run expired payment cleanup
     *
     * @return int Number of cleaned up payments
     */
    public static function force_run_cleanup_expired() {
        return Zano_Database_Manager::delete_expired_payments();
    }

    /**
     * Force run status updates
     *
     * @return bool True on success
     */
    public static function force_run_status_updates() {
        if (class_exists('Zano_Transaction_Handler')) {
            $handler = new Zano_Transaction_Handler();
            return $handler->update_order_statuses();
        }
        
        error_log('Zano_Transaction_Handler class not found');
        return false;
    }

    /**
     * Check if WordPress cron is working properly
     *
     * @return bool True if cron is working
     */
    public static function is_cron_working() {
        // Check if DISABLE_WP_CRON is set
        if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) {
            return false;
        }
        
        // Check if there are any scheduled events
        $crons = _get_cron_array();
        return !empty($crons);
    }

    /**
     * Get WordPress cron information
     *
     * @return array Cron system information
     */
    public static function get_cron_info() {
        return [
            'wp_cron_enabled' => !defined('DISABLE_WP_CRON') || !DISABLE_WP_CRON,
            'cron_working' => self::is_cron_working(),
            'total_scheduled_events' => count(_get_cron_array()),
            'next_cron_run' => wp_next_scheduled('wp_cron'),
            'schedules' => wp_get_schedules()
        ];
    }

    /**
     * Add custom cron intervals
     *
     * @param array $schedules Existing schedules
     * @return array Modified schedules
     */
    public static function add_custom_cron_intervals($schedules) {
        // Add 5-minute interval for payment monitoring
        if (!isset($schedules['every_5_minutes'])) {
            $schedules['every_5_minutes'] = [
                'interval' => 300, // 5 * 60 seconds
                'display' => __('Every 5 Minutes', 'zano-payment-gateway')
            ];
        }
        
        // Add 15-minute interval for status updates
        if (!isset($schedules['every_15_minutes'])) {
            $schedules['every_15_minutes'] = [
                'interval' => 900, // 15 * 60 seconds
                'display' => __('Every 15 Minutes', 'zano-payment-gateway')
            ];
        }
        
        // Add 2-minute interval for intensive monitoring (optional)
        if (!isset($schedules['every_2_minutes'])) {
            $schedules['every_2_minutes'] = [
                'interval' => 120, // 2 * 60 seconds
                'display' => __('Every 2 Minutes', 'zano-payment-gateway')
            ];
        }
        
        return $schedules;
    }

    /**
     * Reschedule all cron jobs
     *
     * @return bool True on success
     */
    public static function reschedule_all_jobs() {
        // First unschedule all existing jobs
        self::unschedule_all_cron_jobs();
        
        // Wait a moment for cleanup
        sleep(1);
        
        // Reschedule all jobs
        return self::init_cron_jobs();
    }

    /**
     * Get missed cron jobs (jobs that should have run but didn't)
     *
     * @return array Missed jobs information
     */
    public static function get_missed_cron_jobs() {
        $missed = [];
        $current_time = time();
        
        $hooks = [
            self::HOOK_MONITOR_PAYMENTS,
            self::HOOK_CLEANUP_EXPIRED,
            self::HOOK_UPDATE_STATUSES
        ];
        
        foreach ($hooks as $hook) {
            $next_run = wp_next_scheduled($hook);
            
            if ($next_run && $next_run < $current_time) {
                $missed[] = [
                    'hook' => $hook,
                    'scheduled_time' => date('Y-m-d H:i:s', $next_run),
                    'delay_seconds' => $current_time - $next_run,
                    'delay_minutes' => round(($current_time - $next_run) / 60, 2)
                ];
            }
        }
        
        return $missed;
    }

    /**
     * Log cron job execution
     *
     * @param string $hook Hook name
     * @param bool $success Whether the job succeeded
     * @param mixed $result Job result
     * @param float $execution_time Execution time in seconds
     */
    public static function log_cron_execution($hook, $success, $result = null, $execution_time = null) {
        $message = sprintf(
            'Cron job %s %s',
            $hook,
            $success ? 'completed successfully' : 'failed'
        );
        
        if ($execution_time !== null) {
            $message .= sprintf(' (%.2f seconds)', $execution_time);
        }
        
        if ($result !== null && !$success) {
            $message .= sprintf(' - Result: %s', print_r($result, true));
        }
        
        Zano_File_Manager::write_log($message, 'cron');
    }
} 