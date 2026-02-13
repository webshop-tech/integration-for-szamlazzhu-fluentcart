<?php

namespace SzamlazzHuFluentCart;

if (!defined('ABSPATH')) {
    exit;
}

use FluentCart\App\Models\Activity;
use FluentCart\App\Models\Cart;
use FluentCart\App\Models\OrderItem;


function get_taxpayer_data($order_id, $api_key, $vat_number) {
    try {
        debug_log($order_id, 'Fetching taxpayer data from NAV', 'VAT number', $vat_number);
        
        $taxpayer_data = get_taxpayer_api($order_id, $api_key, $vat_number);
        
        if (\is_wp_error($taxpayer_data)) {
            debug_log($order_id, 'Failed to fetch taxpayer data', 'Error', $taxpayer_data->get_error_message());
            return null;
        }
        
        if (!empty($taxpayer_data['valid'])) {
            return $taxpayer_data;
        }
        
        debug_log($order_id, 'Taxpayer is not valid');
        return null;
        
    } catch (\Exception $e) {
        debug_log($order_id, 'Failed to fetch taxpayer data', 'Error', $e->getMessage());
        return null;
    }
}

function create_buyer_data($order, $current_order_id, $api_key, $vat_number, $billing_company_name) {
    $order_id = $order->id;
    
    $billing = $order->billing_address;
    if (!$billing) {
        return create_error($current_order_id, 'no_billing_address', "No billing address found for order " . absint($order_id));
    }
    
	$buyer_name = (isset($billing_company_name) && trim($billing_company_name) !== '')
		? $billing_company_name
		: $billing->name;
    $buyer_postcode = $billing->postcode;
    $buyer_city = $billing->city;
    $buyer_address = $billing->address_1 . ($billing->address_2 ? ' ' . $billing->address_2 : '');
    $buyer_country = $billing->country ?? '';
    $buyer_vat_id = null;
    
    if (!empty($vat_number)) {
        $taxpayer_data = get_taxpayer_data($order_id, $api_key, $vat_number);
        
        if ($taxpayer_data) {
            if (isset($taxpayer_data['name'])) {
                $buyer_name = $taxpayer_data['name'];
            }
            if (isset($taxpayer_data['vat_id'])) {
                $buyer_vat_id = $taxpayer_data['vat_id'];
            }
            if (isset($taxpayer_data['postcode'])) {
                $buyer_postcode = $taxpayer_data['postcode'];
            }
            if (isset($taxpayer_data['city'])) {
                $buyer_city = $taxpayer_data['city'];
            }
            if (isset($taxpayer_data['address'])) {
                $buyer_address = $taxpayer_data['address'];
            }
        } else {
            $buyer_vat_id = $buyer_country . $vat_number;
        }
    }
    
    $buyer_data = array(
        'name' => $buyer_name,
        'postcode' => $buyer_postcode,
        'city' => $buyer_city,
        'address' => $buyer_address,
        'country' => $buyer_country,
    );
    
    if (!empty($buyer_vat_id)) {
        $buyer_data['tax_number'] = $buyer_vat_id;
    }
    
    $meta = $billing->meta;
    if (isset($meta['other_data']['email'])) {
        $buyer_data['email'] = $meta['other_data']['email'];
        $buyer_data['send_email'] = false; // Don't send email by default
    }
    
    return $buyer_data;
}

function create_seller_data($current_order_id): array
{
    return array(
        'email_reply_to' => \get_option('admin_email'),
        'email_subject' => 'Invoice for order #' . $current_order_id,
        'email_content' => 'Thank you for your order. Please find your invoice attached.',
    );
}

function build_order_items_data($order, $current_order_id): \WP_Error|array
{
    $order_id = $order->id;
    $items = OrderItem::where('order_id', $order_id)->get();
    
    if ($items->isEmpty()) {
        return create_error($current_order_id, 'no_items', "No items found for order " . absint($order_id));
    }
    
    $quantity_unit = \get_option('szamlazz_hu_quantity_unit', 'db');
    
    $items_data = array();
    
    foreach ($items as $order_item) {
        $taxRate = "0";
        $tax_amount = 0;

        if (\get_option('szamlazz_hu_tax_exempt', '0') == '1') {
            $taxRate = "AAM";
        } else {
            if ($order->tax_behavior != 0) {
                if (isset($order_item->line_meta['tax_config']['rates'][0]['rate'])) {
                    $taxRate = $order_item->line_meta['tax_config']['rates'][0]['rate'];
                }
                $tax_amount = $order_item->tax_amount / 100;
            }
        }

        $net_price = $order_item->line_total / 100;
		$unit_price = $net_price / $order_item->quantity;
        $gross_amount = $net_price + $tax_amount;
        
        $items_data[] = array(
            'name' => $order_item->title,
            'quantity' => $order_item->quantity,
            'unit' => $quantity_unit,
            'unit_price' => $unit_price,
            'vat_rate' => $taxRate,
            'net_price' => $net_price,
            'vat_amount' => $tax_amount,
            'gross_amount' => $gross_amount,
        );
    }
    
    if ($order->shipping_total != 0) {
        $shipping_title = \get_option('szamlazz_hu_shipping_title', 'Szállítás');
        $shipping_net = $order->shipping_total / 100;
        $shipping_vat_amount = 0;
        $shipping_vat_rate = "0";

        if (\get_option('szamlazz_hu_tax_exempt', '0') == '1') {
            $shipping_vat_rate = "AAM";
        } else {
            if ($order->tax_behavior != 0) {
                $shipping_vat = \get_option('szamlazz_hu_shipping_vat', '27');
                $shipping_vat_rate = strval($shipping_vat);
                $shipping_vat_amount = $shipping_net * (floatval($shipping_vat) / 100);
            }
        }
        
        $shipping_gross = $shipping_net + $shipping_vat_amount;
        
        $items_data[] = array(
            'name' => $shipping_title,
            'quantity' => 1,
            'unit' => 'db',
            'unit_price' => $shipping_net,
            'vat_rate' => $shipping_vat_rate,
            'net_price' => $shipping_net,
            'vat_amount' => $shipping_vat_amount,
            'gross_amount' => $shipping_gross,
        );
    }
    
    return $items_data;
}



function generate_invoice($order, $current_order_id) {
    $order_id = $order->id;
    
    debug_log($current_order_id, 'Starting invoice generation', 'Currency', $order->currency);
    
    $api_key = \get_option('szamlazz_hu_agent_api_key', '');
    
    if (empty($api_key)) {
        return create_error($current_order_id, 'api_error', 'API Key not configured.');
    }

    $checkout_data = Cart::where('order_id', $order_id)->first()['checkout_data'];
    $vat_number = $checkout_data['tax_data']['vat_number'] ?? null;
    $billing_company_name = $checkout_data['form_data']['billing_company_name'] ?? null;
    
    if ($vat_number) {
        debug_log($current_order_id, 'VAT number found', $vat_number);
    } else {
        debug_log($current_order_id, 'No VAT number provided');
    }
    
    $buyer_data = create_buyer_data($order, $current_order_id, $api_key, $vat_number, $billing_company_name);
    if (\is_wp_error($buyer_data)) {
        return $buyer_data;
    }
    
    $seller_data = create_seller_data($current_order_id);
    
    $invoice_type = \get_option('szamlazz_hu_invoice_type', '1');
    
    $invoice_language = \get_option('szamlazz_hu_invoice_language', 'hu');
    
    $invoice_type_name = ($invoice_type == strval(INVOICE_TYPE_E_INVOICE)) ? 'E-Invoice' : 'Paper Invoice';
    debug_log($order_id, 'Invoice type set to', $invoice_type_name);
    debug_log($order_id, 'Invoice language set to', $invoice_language);
    
    $items_data = build_order_items_data($order, $current_order_id);
    if (\is_wp_error($items_data)) {
        return $items_data;
    }
    
    $today = gmdate('Y-m-d');
    $due_date = gmdate('Y-m-d', strtotime('+8 days'));
    
    $header_data = array(
        'issue_date' => $today,
        'fulfillment_date' => $today,
        'due_date' => $due_date,
        'payment_method' => 'Átutalás',
        'currency' => $order->currency,
        'language' => $invoice_language,
        'comment' => '',
        'order_number' => strval($current_order_id),
        'proforma_number' => '',
        'prepayment_invoice' => 'false',
        'final_invoice' => 'false',
        'corrective_invoice' => 'false',
        'corrective_invoice_number' => '',
        'proforma' => 'false',
        'invoice_prefix' => '',
        'paid' => 'false',
    );
    
    $params = array(
        'invoice_type' => $invoice_type,
        'download_pdf' => true,
        'header' => $header_data,
        'seller' => $seller_data,
        'buyer' => $buyer_data,
        'items' => $items_data,
    );
    
    debug_log($current_order_id, 'Generating invoice via API');
    
    return generate_invoice_api($current_order_id, $api_key, $params);
}

function create_invoice($order, $main_order = null): void
{
    $order_id = $order->id;
    if ($order->total_amount == 0 && \get_option('szamlazz_hu_zero_invoice', '1') == '0') {
        debug_log($order_id, 'Skipping invoice creation for order with 0 total', 'Order ID', $order_id, 'Main order ID', $main_order->id);
        return;
    }
    if ($main_order === null)
        $main_order = $order;
    
    debug_log($order_id, 'Invoice creation triggered', 'Order ID', $order_id, 'Main order ID', $main_order->id);
    
    init_paths();
    
    $existing_invoice_number = get_invoice_number_by_order_id($order_id);
    if ($existing_invoice_number) {
        debug_log($order_id, 'Invoice already exists', $existing_invoice_number);
        return;
    }
    
    $result = generate_invoice($main_order, $order_id);
    
    if (\is_wp_error($result)) {
        $error_message = 'Failed to generate invoice: ' . $result->get_error_message();
        debug_log($order_id, 'Invoice generation failed', 'Error', $error_message);
        log_activity($order_id, false, $error_message);
        return;
    }
    
    if (!empty($result['success']) && !empty($result['invoice_number'])) {
        $invoice_number = $result['invoice_number'];
        
        debug_log($order_id, 'Invoice generated successfully', 'Invoice number', $invoice_number);
        
        save_invoice($order_id, $invoice_number);
        
        $message = sprintf('Számlázz.hu invoice created: %s', $invoice_number);
        log_activity($order_id, true, $message);
    } else {
        $error_message = 'Failed to generate invoice: Unknown error';
        debug_log($order_id, 'Invoice generation failed', 'Error', $error_message);
        log_activity($order_id, false, $error_message);
    }
}
