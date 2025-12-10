<?php

namespace SzamlazzHuFluentCart;

if (!\defined('ABSPATH')) {
    exit;
}

use FluentCart\App\Helpers\CartHelper;
use FluentCart\App\Services\Renderer\EUVatRenderer;
use FluentCart\App\Services\Renderer\CartSummaryRender;

function replace_eu_vat_header($content) {
    return preg_replace(
        '/<h4[^>]*id="eu-vat-heading"[^>]*>(.*?)<\/h4>/s',
        '<h4 id="eu-vat-heading" class="fct_form_section_header_label">' . \esc_html(__('Hungarian VAT ID', 'integration-for-szamlazzhu-fluentcart')) . '</h4>',
        $content
    );
}

function handleVatValidation() {
    $cart = CartHelper::getCart();

    $checkoutData = $cart->checkout_data ?? [];

    if ($checkoutData['form_data']['billing_country'] !== 'HU')
        return;

    \nocache_headers();
    if (!isset($_REQUEST['_wpnonce']) || !\wp_verify_nonce(\sanitize_text_field(\wp_unslash($_REQUEST['_wpnonce'])), 'fluentcart')) {
        \wp_send_json(['message' => __('Security check failed', 'fluent-cart')], 403);
    }
    
    $vatNumber = isset($_REQUEST['vat_number']) ? \sanitize_text_field(\wp_unslash($_REQUEST['vat_number'])) : '';
    
    if (empty($vatNumber)) {
        \wp_send_json(['message' => __('VAT number is required', 'fluent-cart')], 422);
    }
    
    $api_key = \get_option('szamlazz_hu_agent_api_key', '');
    if (empty($api_key)) {
        \wp_send_json(['message' => __('Számlázz.hu API key is not configured', 'fluent-cart')], 422);
    }
    
    $taxPayerData = get_taxpayer_api(0, $api_key, $vatNumber);
    
    if (\is_wp_error($taxPayerData)) {
        \wp_send_json(['message' => $taxPayerData->get_error_message()], 422);
    }
    
    if (empty($taxPayerData['valid'])) {
        \wp_send_json(['message' => __('VAT number is not valid!', 'fluent-cart')], 422);
    }
    
    
    
    $address = '';
    if (!empty($taxPayerData['address'])) {
        $addressParts = [];
        if (!empty($taxPayerData['postcode'])) {
            $addressParts[] = $taxPayerData['postcode'];
        }
        if (!empty($taxPayerData['city'])) {
            $addressParts[] = $taxPayerData['city'];
        }
        if (!empty($taxPayerData['address'])) {
            $addressParts[] = $taxPayerData['address'];
        }
        if (!empty($addressParts)) {
            $address = implode(', ', $addressParts);
        }
    }
    
    $taxData = $checkoutData['tax_data'];
    $taxData['valid'] = true;
    $taxData['country'] = 'HU';
    $taxData['vat_number'] = $taxPayerData['vat_id'];
    $taxData['name'] = $taxPayerData['name'] ?? '';
    $taxData['address'] = $address;
   
    $checkoutData['tax_data'] = $taxData;
    $cart->checkout_data = $checkoutData;
    $cart->save();
    
    ob_start();
    (new CartSummaryRender($cart))->render(false);
    $cartSummaryInner = ob_get_clean();

    
    \ob_start();
    (new EUVatRenderer(true))->render($cart);
    $euVatView = \ob_get_clean();
    $euVatView = replace_eu_vat_header($euVatView);
    
        wp_send_json([
            'success'   => true,
            'message'   => __('VAT has been applied successfully', 'fluent-cart'),
            'tax_data'  => $taxData,
            'fragments' => [
                [
                    'selector' => '[data-fluent-cart-checkout-page-cart-items-wrapper]',
                    'content'  => $cartSummaryInner,
                    'type'     => 'replace'
                ],
                [
                    'selector' => '[data-fluent-cart-checkout-page-tax-wrapper]',
                    'content'  => $euVatView,
                    'type'     => 'replace'
                ]
            ],
        ], 200);
}

function renameEuVatHeader($fragments, $args)
{
    $cart = CartHelper::getCart();
    $checkoutData = $cart->checkout_data ?? [];

    if ($checkoutData['form_data']['billing_country'] !== 'HU')
        return $fragments;

    if (empty($fragments) || !is_array($fragments)) {
        return $fragments;
    }
    
    foreach ($fragments as $key => $fragment) {
        if (isset($fragment['selector']) && $fragment['selector'] === '[data-fluent-cart-checkout-page-tax-wrapper]') {
            if (isset($fragment['content'])) {
                $fragments[$key]['content'] = replace_eu_vat_header($fragment['content']);
            }
        }
    }
    
    return $fragments;
}

\add_filter('fluent_cart/checkout/after_patch_checkout_data_fragments',  __NAMESPACE__ . '\\renameEuVatHeader', 20, 2);
\add_action('wp_ajax_fluent_cart_validate_vat', __NAMESPACE__ . '\\handleVatValidation', 1);
\add_action('wp_ajax_nopriv_fluent_cart_validate_vat', __NAMESPACE__ . '\\handleVatValidation', 1);
