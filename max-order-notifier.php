<?php
/**
 * Plugin Name: MAX Order Notifier for WooCommerce
 * Plugin URI: https://orenpro.ru/
 * Description: Отправляет уведомления о новых заказах WooCommerce в чат мессенджера MAX
 * Version: 1.0.7
 * Author: orenpro
 * License: GPL v2 or later
 * Text Domain: max-order-notifier
 * Domain Path: /languages
 */

// Защита от прямого доступа
if (!defined('ABSPATH')) {
    exit;
}

// Определяем константы плагина
define('MAX_ORDER_NOTIFIER_VERSION', '1.0.7');
define('MAX_ORDER_NOTIFIER_PATH', plugin_dir_path(__FILE__));
define('MAX_ORDER_NOTIFIER_URL', plugin_dir_url(__FILE__));

// Проверяем наличие WooCommerce
function max_order_notifier_check_woocommerce() {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function() {
            ?>
            <div class="notice notice-error">
                <p><?php _e('MAX Order Notifier requires WooCommerce to be installed and activated.', 'max-order-notifier'); ?></p>
            </div>
            <?php
        });
        return false;
    }
    return true;
}

// Инициализация плагина
add_action('plugins_loaded', 'max_order_notifier_init');

function max_order_notifier_init() {
    if (!max_order_notifier_check_woocommerce()) {
        return;
    }
    
    // Правильный путь к файлу класса
    $class_file = MAX_ORDER_NOTIFIER_PATH . 'includes/class-max-order-notifier.php';
    
    if (file_exists($class_file)) {
        require_once $class_file;
        if (class_exists('MAX_Order_Notifier')) {
            MAX_Order_Notifier::get_instance();
        }
    } else {
        // Если файл не найден, показываем ошибку
        add_action('admin_notices', function() use ($class_file) {
            ?>
            <div class="notice notice-error">
                <p><strong>MAX Order Notifier Error:</strong> Required file not found: <?php echo esc_html($class_file); ?></p>
                <p>Please reinstall the plugin or check file permissions.</p>
            </div>
            <?php
        });
    }
}

// Добавляем ссылку на настройки в список плагинов
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'max_order_notifier_action_links');

function max_order_notifier_action_links($links) {
    $settings_link = '<a href="' . admin_url('admin.php?page=wc-settings&tab=integration&section=max_order_notifier') . '">' . 
                     __('Settings', 'max-order-notifier') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
}

// Регистрируем интеграцию при активации WooCommerce
add_action('before_woocommerce_init', function() {
    if (class_exists('WooCommerce')) {
        require_once MAX_ORDER_NOTIFIER_PATH . 'includes/class-max-order-notifier.php';
    }
});
