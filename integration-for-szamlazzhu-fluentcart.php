<?php
/**
 * Plugin Name: Integration for Számlázz.hu and FluentCart
 * Plugin URI: https://webshop.tech/integration-for-szamlazzhu-fluentcart/
 * Description: Generates invoices on Számlázz.hu for FluentCart orders
 * Version: 1.1.0
 * Author: Gábor Angyal
 * Author URI: https://webshop.tech
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: integration-for-szamlazzhu-fluentcart
 * Requires Plugins: fluent-cart
 */

namespace SzamlazzHuFluentCart;

if (!\defined('ABSPATH')) {
    exit;
}

require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'utils.php';
require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'database.php';
require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'api.php';
require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'taxpayer.php';
require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'invoice-generator.php';
require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'settings.php';
require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'vat.php';
require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'cache.php';

use FluentCart\App\Models\Order;
use FluentCart\App\Helpers\CartHelper;
use FluentCart\App\Services\Renderer\EUVatRenderer;
use FluentCart\App\Services\Renderer\CartSummaryRender;

\register_activation_hook(__FILE__, __NAMESPACE__ . '\\create_invoices_table');

\add_action('fluent_cart/order_paid_done', function($data) {
    $order = $data['order'];
    create_invoice($order);
}, 10, 1);

\add_action('fluent_cart/payment_status_changed_to_paid', function($data) {
    $order = $data['order'];
    create_invoice($order);
}, 10, 1);

\add_action('fluent_cart/subscription_renewed', function($data) {
    $order = $data['order'];
    $main_order = $data['main_order'];
    create_invoice($order, $main_order);
}, 10, 1);

\add_action('fluent_cart/before_render_redirect_page', function($data) {
    if (!isset($data['is_receipt']) || !$data['is_receipt']) {
        return;
    }
    
    
    if (!isset($data['order_hash'])) {
        return;
    }

    $order_hash = $data['order_hash'];
    
    $order_id = Order::where('uuid', $order_hash)->value('id');

    if(!is_numeric($order_id)) {
        return;
    }
    
    try {
        init_paths();
        
        $api_key = \get_option('szamlazz_hu_agent_api_key', '');
        
        if (empty($api_key)) {
            return;
        }
        
        $invoice_number = get_invoice_number_by_order_id($order_id);
        
        if ($invoice_number) {
            $cached_pdf_path = get_pdf_path($invoice_number);
            
            if ($cached_pdf_path && \file_exists($cached_pdf_path)) {
                serve_pdf_download($cached_pdf_path);
            }
            
            $result = fetch_invoice_pdf($order_id, $api_key, $invoice_number);
            
            if (!is_wp_error($result) && isset($result['success']) && $result['success']) {
                $cache_path = get_cache_path();
                if ($cache_path) {
                    $pdf_dir = $cache_path . DIRECTORY_SEPARATOR . 'pdf';
                    $pdf_filename = $pdf_dir . DIRECTORY_SEPARATOR . $result['filename'];
                    
                    require_once(ABSPATH . 'wp-admin/includes/file.php');
                    WP_Filesystem();
                    global $wp_filesystem;
                    
                    $wp_filesystem->put_contents($pdf_filename, $result['pdf_data'], FS_CHMOD_FILE);
                }
                
                serve_pdf_download(null, $result['pdf_data'], $result['filename']);
            }
        }
        
    } catch (\Exception $e) {
        log_activity($order_id, false, 'Download error: ' . $e->getMessage());
        return;
    }
}, 1);
