<?php

namespace SzamlazzHuFluentCart;

if ( ! defined( 'ABSPATH' ) ) exit;
function add_settings_fields(): void
{
    \add_settings_section(
        'szamlazz_hu_api_section',
        \__('API Settings', 'integration-for-szamlazzhu-fluentcart'),
        function () {
            echo '<p>' . \esc_html__('Enter your Számlázz.hu API credentials below.', 'integration-for-szamlazzhu-fluentcart') . '</p>';
        },
        'integration-for-szamlazzhu-fluentcart'
    );

    \add_settings_field(
        'szamlazz_hu_agent_api_key',
        \__('Agent API Key', 'integration-for-szamlazzhu-fluentcart'),
        function () {
            $value = \get_option('szamlazz_hu_agent_api_key', '');
            echo '<input type="password" name="szamlazz_hu_agent_api_key" value="' . \esc_attr($value) . '" class="regular-text" autocomplete="off" />';
            echo '<p class="description"><a href="https://tudastar.szamlazz.hu/gyik/kulcs" target="_blank" rel="noopener noreferrer">' . \esc_html__('What is this?', 'integration-for-szamlazzhu-fluentcart') . '</a></p>';
        },
        'integration-for-szamlazzhu-fluentcart',
        'szamlazz_hu_api_section'
    );

    \add_settings_section(
        'szamlazz_hu_invoice_section',
        \__('Invoice Settings', 'integration-for-szamlazzhu-fluentcart'),
        null,
        'integration-for-szamlazzhu-fluentcart'
    );

    \add_settings_field(
        'szamlazz_hu_invoice_language',
        \__('Invoice Language', 'integration-for-szamlazzhu-fluentcart'),
        function () {
            $value = \get_option('szamlazz_hu_invoice_language', LANGUAGE_HU);
            $languages = [
                LANGUAGE_HU => \__('Magyar (Hungarian)', 'integration-for-szamlazzhu-fluentcart'),
                LANGUAGE_EN => \__('English', 'integration-for-szamlazzhu-fluentcart'),
                LANGUAGE_DE => \__('Deutsch (German)', 'integration-for-szamlazzhu-fluentcart'),
                LANGUAGE_IT => \__('Italiano (Italian)', 'integration-for-szamlazzhu-fluentcart'),
                LANGUAGE_RO => \__('Română (Romanian)', 'integration-for-szamlazzhu-fluentcart'),
                LANGUAGE_SK => \__('Slovenčina (Slovak)', 'integration-for-szamlazzhu-fluentcart'),
                LANGUAGE_HR => \__('Hrvatski (Croatian)', 'integration-for-szamlazzhu-fluentcart'),
                LANGUAGE_FR => \__('Français (French)', 'integration-for-szamlazzhu-fluentcart'),
                LANGUAGE_ES => \__('Español (Spanish)', 'integration-for-szamlazzhu-fluentcart'),
                LANGUAGE_CZ => \__('Čeština (Czech)', 'integration-for-szamlazzhu-fluentcart'),
                LANGUAGE_PL => \__('Polski (Polish)', 'integration-for-szamlazzhu-fluentcart')
            ];
            echo '<select name="szamlazz_hu_invoice_language">';
            foreach ($languages as $code => $name) {
                echo '<option value="' . \esc_attr($code) . '" ' . ($code == $value ? 'selected>' : '>') . \esc_html($name) . '</option>';
            }
            echo '</select>';
        },
        'integration-for-szamlazzhu-fluentcart',
        'szamlazz_hu_invoice_section'
    );

    \add_settings_field(
        'szamlazz_hu_invoice_type',
        \__('Invoice Type', 'integration-for-szamlazzhu-fluentcart'),
        function () {
            $value = \get_option('szamlazz_hu_invoice_type', INVOICE_TYPE_P_INVOICE);
            $types = [
                INVOICE_TYPE_P_INVOICE => \__('Paper Invoice', 'integration-for-szamlazzhu-fluentcart'),
                INVOICE_TYPE_E_INVOICE => \__('E-Invoice', 'integration-for-szamlazzhu-fluentcart')
            ];
            echo '<select name="szamlazz_hu_invoice_type">';
            foreach ($types as $type_value => $type_name) {
                echo '<option value="' . \esc_attr($type_value) . '" ' . ($type_value == $value ? 'selected>' : '>') . \esc_html($type_name) . '</option>';
            }
            echo '</select>';
        },
        'integration-for-szamlazzhu-fluentcart',
        'szamlazz_hu_invoice_section'
    );

    \add_settings_field(
        'szamlazz_hu_quantity_unit',
        \__('Quantity Unit', 'integration-for-szamlazzhu-fluentcart'),
        function () {
            $value = \get_option('szamlazz_hu_quantity_unit', 'db');
            echo '<input type="text" name="szamlazz_hu_quantity_unit" value="' . \esc_attr($value) . '" class="regular-text" />';
        },
        'integration-for-szamlazzhu-fluentcart',
        'szamlazz_hu_invoice_section'
    );

    \add_settings_field(
        'szamlazz_hu_shipping_title',
        \__('Shipping Title', 'integration-for-szamlazzhu-fluentcart'),
        function () {
            $value = \get_option('szamlazz_hu_shipping_title', 'Szállítás');
            echo '<input type="text" name="szamlazz_hu_shipping_title" value="' . \esc_attr($value) . '" class="regular-text" />';
        },
        'integration-for-szamlazzhu-fluentcart',
        'szamlazz_hu_invoice_section'
    );

    \add_settings_field(
        'szamlazz_hu_shipping_vat',
        \__('Shipping VAT Rate', 'integration-for-szamlazzhu-fluentcart'),
        function () {
            $value = \get_option('szamlazz_hu_shipping_vat', 27);
            $options = [0, 5, 18, 27];
            echo '<select name="szamlazz_hu_shipping_vat">';
            foreach ($options as $option) {
                echo '<option value="' . \esc_attr($option) . '" ' . ($option == $value ? 'selected>' : '>') . \esc_html($option) . '%</option>';
            }
            echo '</select>';
        },
        'integration-for-szamlazzhu-fluentcart',
        'szamlazz_hu_invoice_section'
    );

    \add_settings_field(
        'szamlazz_hu_apply_shipping_vat_field',
        \__('Apply to Tax Rates', 'integration-for-szamlazzhu-fluentcart'),
        function () {
            $current_rates = getShippingTaxRates();
            $selected_vat = \get_option('szamlazz_hu_shipping_vat', 27);

            if (empty($current_rates)) {
                echo '<p class="description" style="color: #dc3232;"><strong>' . \esc_html__('Warning:', 'integration-for-szamlazzhu-fluentcart') . '</strong> ' . \esc_html__('No tax rates found. Please configure tax rates in FluentCart first.', 'integration-for-szamlazzhu-fluentcart') . '</p>';
            } elseif (count($current_rates) === 1 && $current_rates[0] == $selected_vat) {
                echo '<p class="description" style="color: #46b450;">' . \esc_html__('All tax rates are already set to', 'integration-for-szamlazzhu-fluentcart') . ' ' . \esc_html($selected_vat) . '%</p>';
            } else {
                echo '<p class="description">' . \esc_html__('Current shipping VAT rates in use:', 'integration-for-szamlazzhu-fluentcart') . ' ' . \esc_html(\implode(', ', $current_rates)) . '%</p>';
            }
        },
        'integration-for-szamlazzhu-fluentcart',
        'szamlazz_hu_invoice_section'
    );
}
