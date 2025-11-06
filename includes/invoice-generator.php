<?php
/**
 * Invoice generation functions for Számlázz.hu
 * 
 * @package SzamlazzHuFluentCart
 */

namespace SzamlazzHuFluentCart;

// Exit if accessed directly
if (!\defined('ABSPATH')) {
    exit;
}

use \SzamlaAgent\SzamlaAgentAPI;
use \SzamlaAgent\Buyer;
use \SzamlaAgent\Seller;
use \SzamlaAgent\Language;
use \SzamlaAgent\Document\Invoice\Invoice;
use \SzamlaAgent\Item\InvoiceItem;

use FluentCart\App\Models\Activity;
use FluentCart\App\Models\Cart;
use FluentCart\App\Models\OrderItem;

/**
 * Get and validate API key from settings
 * 
 * @return string The API key
 * @throws \Exception If API key is not configured
 */
function get_api_key() {
    $api_key = \get_option('szamlazz_hu_agent_api_key', '');
    
    if (empty($api_key)) {
        throw new \Exception('Agent API Key is not configured. Please configure it in Settings > Számlázz.hu');
    }
    
    return $api_key;
}

/**
 * Get VAT number from checkout data
 * 
 * @param int $order_id The order ID
 * @return string|null The VAT number or null
 */
function get_vat_number($order_id) {
    $checkout_data = Cart::where('order_id', $order_id)->first()['checkout_data'];
    return $checkout_data['tax_data']['vat_number'] ?? null;
}

/**
 * Fetch and parse taxpayer data from NAV
 * 
 * @param int $order_id The order ID
 * @param object $agent The SzamlaAgent instance
 * @param string $vat_number The VAT number
 * @return array|null The taxpayer data or null
 */
function get_taxpayer_data($order_id, $agent, $vat_number) {
    try {
        write_log($order_id, 'Fetching taxpayer data from NAV', 'VAT number', $vat_number);
        
        $taxpayer_response = $agent->getTaxPayer($vat_number);
        $taxpayer_xml = $taxpayer_response->getTaxPayerData();
        
        if (!$taxpayer_xml) {
            write_log($order_id, 'No taxpayer data returned from NAV');
            return null;
        }
        
        write_log($order_id, 'Taxpayer XML received, parsing data');
        
        $xml = new \SimpleXMLElement($taxpayer_xml);
        
        // Register namespaces
        $xml->registerXPathNamespace('ns2', 'http://schemas.nav.gov.hu/OSA/3.0/api');
        $xml->registerXPathNamespace('ns3', 'http://schemas.nav.gov.hu/OSA/3.0/base');

        $taxpayerValidity = $xml->xpath('//ns2:taxpayerValidity');
        if ("true" === (string)$taxpayerValidity[0]) {
            write_log($order_id, 'Taxpayer is valid');
        } else {
            write_log($order_id, 'Taxpayer is not valid');
            return;
        }

        $data = [];
        
        // Extract taxpayer name
        $taxpayer_short_name = $xml->xpath('//ns2:taxpayerShortName');
        $taxpayer_name = $xml->xpath('//ns2:taxpayerName');
        
        if (!empty($taxpayer_short_name)) {
            $data['name'] = (string)$taxpayer_short_name[0];
            write_log($order_id, 'Taxpayer name extracted', 'Name', $data['name']);
        } elseif (!empty($taxpayer_name)) {
            $data['name'] = (string)$taxpayer_name[0];
            write_log($order_id, 'Taxpayer name extracted', 'Name', $data['name']);
        }
        
        // Extract VAT ID components
        $taxpayer_id = $xml->xpath('//ns3:taxpayerId');
        $vat_code = $xml->xpath('//ns3:vatCode');
        $county_code = $xml->xpath('//ns3:countyCode');
        
        if (!empty($taxpayer_id) && !empty($vat_code) && !empty($county_code)) {
            $data['vat_id'] = sprintf(
                '%s-%s-%s',
                (string)$taxpayer_id[0],
                (string)$vat_code[0],
                (string)$county_code[0]
            );
            write_log($order_id, 'VAT ID formatted', $data['vat_id']);
        }
        
        // Extract address
        $postal_code = $xml->xpath('//ns3:postalCode');
        $city = $xml->xpath('//ns3:city');
        $street_name = $xml->xpath('//ns3:streetName');
        $public_place = $xml->xpath('//ns3:publicPlaceCategory');
        $number = $xml->xpath('//ns3:number');
        $door = $xml->xpath('//ns3:door');
        
        if (!empty($postal_code)) {
            $data['postcode'] = (string)$postal_code[0];
        }
        
        if (!empty($city)) {
            $data['city'] = (string)$city[0];
        }
        
        if (!empty($street_name)) {
            $address_parts = [(string)$street_name[0]];
            
            if (!empty($public_place)) {
                $address_parts[] = (string)$public_place[0];
            }
            
            if (!empty($number)) {
                $address_parts[] = (string)$number[0];
            }
            
            if (!empty($door)) {
                $address_parts[] = (string)$door[0];
            }
            
            $data['address'] = implode(' ', $address_parts);
        }
        
        if (isset($data['postcode']) && isset($data['city']) && isset($data['address'])) {
            write_log($order_id, 'Taxpayer address extracted', $data['postcode'], $data['city'], $data['address']);
        }
        
        write_log($order_id, 'Taxpayer data successfully parsed from NAV');
        
        return $data;
        
    } catch (\Exception $e) {
        write_log($order_id, 'Failed to fetch taxpayer data', 'Error', $e->getMessage());
        return null;
    }
}

/**
 * Create buyer object from order data
 * 
 * @param object $order The order object
 * @param object $agent The SzamlaAgent instance
 * @param string|null $vat_number The VAT number
 * @return Buyer The buyer object
 * @throws \Exception If billing address is not found
 */
function create_buyer($order, $agent, $vat_number = null) {
    $order_id = $order->id;
    
    // Get billing address
    $billing = $order->billing_address;
    if (!$billing) {
        throw new \Exception("No billing address found for order $order_id");
    }
    
    // Initialize with billing address defaults
    $buyer_name = $billing->name;
    $buyer_postcode = $billing->postcode;
    $buyer_city = $billing->city;
    $buyer_address = $billing->address_1 . ($billing->address_2 ? ' ' . $billing->address_2 : '');
    $buyer_vat_id = $billing->country . $vat_number;
    
    // If VAT number is provided, try to get taxpayer data from NAV
    if (!empty($vat_number)) {
        $taxpayer_data = get_taxpayer_data($order_id, $agent, $vat_number);
        
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
        }
    }
    
    // Create buyer
    $buyer = new Buyer(
        $buyer_name,
        $buyer_postcode,
        $buyer_city,
        $buyer_address
    );
    
    // Set VAT ID if available
    if (!empty($buyer_vat_id)) {
        $buyer->setTaxNumber($buyer_vat_id);
    }
    
    // Set buyer email if available
    $meta = $billing->meta;
    if (isset($meta['other_data']['email'])) {
        $buyer->setEmail($meta['other_data']['email']);
    }
    
    return $buyer;
}

/**
 * Create seller object with email settings
 * 
 * @param int $order_id The order ID
 * @return Seller The seller object
 */
function create_seller($order_id) {
    $seller = new Seller();
    
    // Configure email settings
    $seller->setEmailReplyTo(\get_option('admin_email'));
    $seller->setEmailSubject('Invoice for order #' . $order_id);
    $seller->setEmailContent('Thank you for your order. Please find your invoice attached.');
    
    return $seller;
}

/**
 * Add order items to invoice
 * 
 * @param Invoice $invoice The invoice object
 * @param object $order The order object
 * @throws \Exception If no items found
 */
function add_order_items($invoice, $order) {
    $order_id = $order->id;
    $items = OrderItem::where('order_id', $order_id)->get();
    
    if ($items->isEmpty()) {
        throw new \Exception("No items found for order $order_id");
    }
    
    // Get quantity unit from settings
    $quantity_unit = \get_option('szamlazz_hu_quantity_unit', 'db');
    
    write_log($order_id, 'Adding order items', 'Item count', $items->count());
    
    foreach ($items as $order_item) {
        $taxRate = "0";
        if ($order->tax_behavior != 0) {
            if (isset($order_item->line_meta['tax_config']['rates'][0]['rate'])) {
                $taxRate = $order_item->line_meta['tax_config']['rates'][0]['rate'];
            }
        }
        
        $item = new InvoiceItem(
            $order_item->title,
            $order_item->unit_price / 100,
            $order_item->quantity,
            $quantity_unit,
            strval($taxRate)
        );
        
        $item->setNetPrice($order_item->line_total / 100);
        $tax_amount = 0;
        if ($order->tax_behavior != 0) {
            $tax_amount = $order_item->tax_amount / 100;
        }
        $item->setVatAmount($tax_amount);
        $item->setGrossAmount($order_item->line_total / 100 + $tax_amount);
        
        write_log(
            $order_id, 
            'Item', 
            $order_item->title, 
            'Qty', 
            $order_item->quantity, 
            'Unit price', 
            $order_item->unit_price / 100,
            'Tax rate', 
            $taxRate . '%',
            'Net', 
            $order_item->line_total / 100,
            'VAT', 
            $order_item->tax_amount / 100,
            'Gross', 
            ($order_item->line_total + $order_item->tax_amount) / 100
        );
        
        $invoice->addItem($item);
    }
    if ($order->shipping_total != 0) {
        // Get shipping title from settings
        $shipping_title = \get_option('szamlazz_hu_shipping_title', 'Szállítás');
        
        $item = new InvoiceItem($shipping_title, $order->shipping_total / 100);
        $item->setNetPrice($order->shipping_total / 100);
        if ($order->tax_behavior != 0) {
            // Get shipping VAT rate from settings
            $shipping_vat = \get_option('szamlazz_hu_shipping_vat', 27);
            $vat_multiplier = 1 + ($shipping_vat / 100);
            
            $item->setVatAmount($order->shipping_total * ($shipping_vat / 100) / 100);
            $item->setVat(strval($shipping_vat));
            $item->setGrossAmount($order->shipping_total * $vat_multiplier / 100);
        } else {
            $item->setVatAmount(0);
            $item->setVat("0");
            $item->setGrossAmount($order->shipping_total / 100);
        }
        $invoice->addItem($item);
    }
}

/**
 * Log invoice activity
 * 
 * @param int $order_id The order ID
 * @param bool $success Whether the operation was successful
 * @param string $message The message to log
 */
function log_activity($order_id, $success, $message) {
    Activity::create([
        'status' => $success ? 'success' : 'failed',
        'log_type' => 'activity',
        'module_type' => 'FluentCart\App\Models\Order',
        'module_id' => $order_id,
        'module_name' => 'order',
        'title' => $success ? 'Számlázz.hu invoice successfully generated' : 'Számlázz.hu invoice generation failed',
        'content' => $message
    ]);
}

/**
 * Generate invoice via Számlázz.hu API
 * 
 * @param object $order The order object
 * @return object The invoice generation result
 * @throws \Exception If API key is not configured
 */
function generate_invoice($order) {
    $order_id = $order->id;
    
    write_log($order_id, 'Starting invoice generation', 'Order ID', $order_id, 'Currency', $order->currency);
    
    // Get and validate API key
    $api_key = get_api_key();
    // Create Számla Agent
    $agent = SzamlaAgentAPI::create($api_key);
    $agent->setPdfFileSave(false);
    
    // Get VAT number from checkout data
    $vat_number = get_vat_number($order_id);
    if ($vat_number) {
        write_log($order_id, 'VAT number found', $vat_number);
    } else {
        write_log($order_id, 'No VAT number provided');
    }
    
    // Create buyer with taxpayer data if available
    $buyer = create_buyer($order, $agent, $vat_number);
    write_log($order_id, 'Buyer created', 'Name', $buyer->getName(), 'City', $buyer->getCity());
    
    // Create seller with email settings
    $seller = create_seller($order_id);
    
    // Get invoice type from settings
    $invoice_type = \get_option('szamlazz_hu_invoice_type', Invoice::INVOICE_TYPE_P_INVOICE);
    
    // Create invoice
    $invoice = new Invoice($invoice_type);
    $invoice->setBuyer($buyer);
    $invoice->setSeller($seller);
    $invoice->getHeader()->setCurrency($order->currency);
    
    // Get invoice language from settings
    $invoice_language = \get_option('szamlazz_hu_invoice_language', Language::LANGUAGE_HU);
    $invoice->getHeader()->setLanguage($invoice_language);
    
    $invoice_type_name = ($invoice_type == Invoice::INVOICE_TYPE_E_INVOICE) ? 'E-Invoice' : 'Paper Invoice';
    write_log($order_id, 'Invoice type set to', $invoice_type_name);
    write_log($order_id, 'Invoice language set to', $invoice_language);
    
    // Add order items to invoice
    add_order_items($invoice, $order);
    
    write_log($order_id, 'Generating invoice via API');
    
    // Generate invoice
    return $agent->generateInvoice($invoice);
}

/**
 * Main invoice creation function
 * 
 * @param object $order The order object
 * @param object|null $main_order The main order object (for subscriptions)
 */
function create_invoice($order, $main_order = null) {
    $order_id = $order->id;
    if ($main_order === null)
        $main_order = $order;
    $main_order_id = $main_order->id;
    
    try {
        write_log($order_id, 'Invoice creation triggered', 'Order ID', $order_id, 'Main order ID', $main_order_id);
        
        // Initialize paths and ensure folders exist
        init_paths();
        
        // Check if invoice already exists
        $existing = get_invoice_by_order_id($order_id);
        if ($existing) {
            $message = sprintf('Invoice already exists: %s', $existing->invoice_number);
            write_log($order_id, 'Invoice already exists', $existing->invoice_number);
            log_activity($order_id, true, $message);
            return;
        }
        
        $result = generate_invoice($main_order);
        // Check if invoice was created successfully
        if ($result->isSuccess()) {
            $invoice_number = $result->getDocumentNumber();
            
            write_log($order_id, 'Invoice generated successfully', 'Invoice number', $invoice_number);
            
            // Save to database
            save_invoice($order_id, $result);
            
            // Log success
            $message = sprintf('Számlázz.hu invoice created: %s', $invoice_number);
            log_activity($order_id, true, $message);
        } else {
            throw new \Exception('Failed to generate invoice: ' . $result->getMessage());
        }
        
    } catch (\Exception $e) {
        write_log($order_id, 'Invoice generation failed', 'Error', $e->getMessage());
        log_activity($order_id, false, $e->getMessage());
    }
}
