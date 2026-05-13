<?php
/**
 * Plugin Name: TGS HTSoft Stock Converter
 * Description: Quy đổi tồn kho HTSoft <-> TGS theo SKU, hook vào menu Sản phẩm của TGS Shop Management.
 * Version: 1.0.0
 * Author: BIZGPT_AI
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('TGS_HTSOFT_CONVERTER_FILE')) {
    define('TGS_HTSOFT_CONVERTER_FILE', __FILE__);
}
if (!defined('TGS_HTSOFT_CONVERTER_DIR')) {
    define('TGS_HTSOFT_CONVERTER_DIR', plugin_dir_path(__FILE__));
}
if (!defined('TGS_HTSOFT_CONVERTER_URL')) {
    define('TGS_HTSOFT_CONVERTER_URL', plugin_dir_url(__FILE__));
}

require_once TGS_HTSOFT_CONVERTER_DIR . 'includes/class-tgs-htsoft-stock-converter.php';

add_action('plugins_loaded', ['TGS_HTSoft_Stock_Converter', 'init']);
