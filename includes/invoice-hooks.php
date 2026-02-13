<?php

namespace SzamlazzHuFluentCart;

if ( ! defined( 'ABSPATH' ) ) exit;


\add_action('fluent_cart/order_paid_done', function($data) {
    $order = $data['order'];
    create_invoice($order);
}, 10, 1);

\add_action('fluent_cart/order_status_changed', function($data) {
    $order = $data['order'];
    $order_id = $order->id;
    if ($data['new_status'] =='completed') {
        create_invoice($order);
    }
}, 10, 1);

\add_action('fluent_cart/order_refunded', function($data) {
    $order = $data['order'];
    $order_id = $order->id;
    if ($data['type'] == 'full') {
        $api_key = \get_option('szamlazz_hu_agent_api_key', '');
        $invoice_number = get_invoice_number_by_order_id($order_id);
        $invoice_number = cancel_invoice_api($order_id, $api_key, $invoice_number);
        if (!\is_wp_error($invoice_number)) {
            update_invoice($order_id, $invoice_number);
            log_activity($order_id, true, "Cancel invoice created: $invoice_number.");
        }
    } else {
        log_activity($order_id, false, "Partial refund is not supported yet. Create invoice manually.");
    }
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
