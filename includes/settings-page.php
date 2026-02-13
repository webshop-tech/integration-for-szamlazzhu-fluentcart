<?php

namespace SzamlazzHuFluentCart;

if ( ! defined( 'ABSPATH' ) ) exit;

function settings_page() {
    if (!\current_user_can('manage_options')) {
        return;
    }

    \settings_errors('szamlazz_hu_messages');
    ?>
    <div class="wrap">
        <h1><?php echo \esc_html(\get_admin_page_title()); ?></h1>

        <form action="options.php" method="post">
            <?php
            \settings_fields('szamlazz_hu_fluentcart_settings');
            \do_settings_sections('integration-for-szamlazzhu-fluentcart');
            \submit_button(\__('Save Settings', 'integration-for-szamlazzhu-fluentcart'));
            ?>
        </form>
        <h1><?php echo \esc_html__('Actions', 'integration-for-szamlazzhu-fluentcart'); ?></h1>

        <h2><?php echo \esc_html__('Shipping VAT Settings', 'integration-for-szamlazzhu-fluentcart'); ?></h2>

        <?php
        $current_rates = getShippingTaxRates();
        $selected_vat = \get_option('szamlazz_hu_shipping_vat', '27');
        $is_button_disabled = empty($current_rates) || (count($current_rates) === 1 && $current_rates[0] == strval($selected_vat));
        ?>
        <form action="<?php echo \esc_url(\admin_url('options-general.php?page=integration-for-szamlazzhu-fluentcart')); ?>" method="post" style="margin-top: 20px;">
            <?php \wp_nonce_field('szamlazz_hu_apply_shipping_vat_action', 'szamlazz_hu_apply_shipping_vat_nonce'); ?>
            <input type="hidden" name="szamlazz_hu_apply_shipping_vat" value="1" />
            <?php \submit_button(\__('Apply Shipping VAT to All Tax Rates', 'integration-for-szamlazzhu-fluentcart'), 'primary', 'submit', false, $is_button_disabled ? ['disabled' => true] : []); ?>
        </form>

        <h2><?php echo \esc_html__('Cache Management', 'integration-for-szamlazzhu-fluentcart'); ?></h2>
        <?php
        $cache_size = get_cache_size();
        $formatted_size = format_bytes($cache_size);
        ?>
        <p><?php echo \esc_html__('Current cache size:', 'integration-for-szamlazzhu-fluentcart'); ?> <strong><?php echo \esc_html($formatted_size); ?></strong></p>
        <p class="description"><?php echo \esc_html__('Clearing the cache will delete all cached PDFs, XMLs, and logs.', 'integration-for-szamlazzhu-fluentcart'); ?></p>

        <form action="<?php echo \esc_url(\admin_url('options-general.php?page=integration-for-szamlazzhu-fluentcart')); ?>" method="post" style="margin-top: 20px;">
            <?php \wp_nonce_field('szamlazz_hu_clear_cache_action', 'szamlazz_hu_clear_cache_nonce'); ?>
            <input type="hidden" name="szamlazz_hu_clear_cache" value="1" />
            <?php \submit_button(\__('Clear Cache', 'integration-for-szamlazzhu-fluentcart'), 'secondary', 'submit', false); ?>
        </form>
    </div>
    <?php
}

function format_bytes($bytes, $precision = 2): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];

    for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
        $bytes /= 1024;
    }

    return round($bytes, $precision) . ' ' . $units[$i];
}
