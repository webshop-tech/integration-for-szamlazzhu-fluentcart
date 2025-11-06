<?php
/**
 * Utility functions for Számlázz.hu plugin
 * 
 * @package SzamlazzHuFluentCart
 */

namespace SzamlazzHuFluentCart;

// Exit if accessed directly
if (!\defined('ABSPATH')) {
    exit;
}

use FluentCart\App\Models\Activity;

/**
 * Logging function - only works when WP_DEBUG is enabled
 * 
 * @param int $order_id The order ID
 * @param string $message The message to log
 * @param mixed ...$args Variable-length argument list to be concatenated with commas
 */
function write_log($order_id, $message, ...$args) {
    // Only log if WP_DEBUG is enabled
    if (!\defined('WP_DEBUG') || !WP_DEBUG) {
        return;
    }
    
    // Concatenate message with additional arguments using commas
    if (!empty($args)) {
        $formatted_message = $message . ', ' . \implode(', ', $args);
    } else {
        $formatted_message = $message;
    }
    
    // Create order Activity with info status
    Activity::create([
        'status' => 'info',
        'log_type' => 'activity',
        'module_type' => 'FluentCart\App\Models\Order',
        'module_id' => $order_id,
        'module_name' => 'order',
        'title' => 'Számlázz.hu debug info',
        'content' => $formatted_message
    ]);
}
