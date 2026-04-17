<?php

if (!defined('ABSPATH')) {
    exit;
}

class MAX_Order_Notifier {
    
    private static $instance = null;
    private $max_bot_token;
    private $max_chat_id;
    private $order_statuses;
    private $log_enabled;
    private $log;
    
    private function __construct() {
        $this->init_settings();
        $this->init_hooks();
        $this->init_logger();
        $this->log("Плагин инициализирован");
    }
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function init_logger() {
        $this->log_enabled = get_option('max_order_notifier_log_enabled', 'yes');
        if ($this->log_enabled === 'yes' && function_exists('wc_get_logger')) {
            $this->log = wc_get_logger();
        }
    }
    
    private function init_hooks() {
        add_action('woocommerce_checkout_order_processed', array($this, 'send_order_notification'), 10, 3);
        add_action('woocommerce_order_status_changed', array($this, 'send_order_status_notification'), 10, 4);
        add_filter('woocommerce_integrations', array($this, 'add_integration'));
        add_action('admin_post_max_test_notification', array($this, 'handle_test_notification'));
    }
    
    private function init_settings() {
        $this->max_bot_token = get_option('max_order_notifier_bot_token', '');
        $this->max_chat_id = get_option('max_order_notifier_chat_id', '');
        $this->order_statuses = get_option('max_order_notifier_statuses', array('processing', 'completed'));
        
        if (is_array($this->order_statuses)) {
            $this->order_statuses = array_map(function($status) {
                return str_replace('wc-', '', $status);
            }, $this->order_statuses);
        }
        
        $this->log("Настройки загружены. Token: " . ($this->max_bot_token ? "SET" : "EMPTY") . ", Chat ID: " . ($this->max_chat_id ?: "EMPTY"));
    }
    
    private function log($message, $level = 'info') {
        if ($this->log_enabled !== 'yes') {
            return;
        }
        error_log('[MAX Order Notifier] ' . $message);
        if (isset($this->log) && function_exists('wc_get_logger')) {
            $this->log->log($level, $message, array('source' => 'max-order-notifier'));
        }
    }
    
    public function send_order_notification($order_id, $posted_data, $order) {
        $this->log("Сработал хук для заказа #{$order_id}");
        $order = wc_get_order($order_id);
        if (!$order) return;
        
        $order_status = $order->get_status();
        $this->log("Статус заказа #{$order_id}: {$order_status}");
        
        if (in_array($order_status, $this->order_statuses)) {
            $this->send_to_max($this->get_full_order_message($order, $order_status), $order_id);
        }
    }
    
    public function send_order_status_notification($order_id, $old_status, $new_status, $order) {
        $this->log("Изменение статуса #{$order_id}: {$old_status} → {$new_status}");
        
        if (in_array($new_status, $this->order_statuses)) {
            // Всегда отправляем полную информацию о заказе, а не только статус
            $this->send_to_max($this->get_full_order_message($order, $new_status, $old_status), $order_id);
        }
    }
    
    /**
     * Формирует полное сообщение о заказе (единый метод для всех случаев)
     */
    private function get_full_order_message($order, $current_status, $old_status = null) {
        $status_labels = wc_get_order_statuses();
        $current_label = isset($status_labels['wc-' . $current_status]) ? $status_labels['wc-' . $current_status] : $current_status;
        
        // Заголовок в зависимости от того, новый заказ или изменение статуса
        if ($old_status) {
            $old_label = isset($status_labels['wc-' . $old_status]) ? $status_labels['wc-' . $old_status] : $old_status;
            $message = "🔄 **СТАТУС ЗАКАЗА ИЗМЕНЕН**\n";
            $message .= "═══════════════════════════\n";
            $message .= "📊 {$old_label} → {$current_label}\n\n";
        } else {
            $message = "🛍️ **НОВЫЙ ЗАКАЗ**\n";
            $message .= "═══════════════════════════\n\n";
        }
        
        // Информация о заказе
        $message .= "📋 **Заказ #{$order->get_order_number()}**\n";
        $message .= "🗓 **Дата:** " . $order->get_date_created()->date_i18n('d.m.Y H:i') . "\n";
        $message .= "💰 **Сумма:** " . strip_tags(wc_price($order->get_total())) . "\n";
        $message .= "💳 **Оплата:** " . $order->get_payment_method_title() . "\n";
        
        // Способ доставки
        $shipping_method = $order->get_shipping_method();
        if (!empty($shipping_method)) {
            $message .= "🚚 **Доставка:** {$shipping_method}\n";
        }
        
        $message .= "\n";
        
        // Товары в заказе
        $message .= "📦 **СОСТАВ ЗАКАЗА:**\n";
        $message .= "────────────────────\n";
        
        $items_count = 0;
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            $product_name = $item->get_name();
            $quantity = $item->get_quantity();
            $item_total = strip_tags(wc_price($item->get_total()));
            
            $sku = $product ? $product->get_sku() : '';
            $sku_text = $sku ? " [{$sku}]" : "";
            
            $message .= "• {$product_name}{$sku_text}\n";
            $message .= "  Кол-во: {$quantity} | Сумма: {$item_total}\n";
            $items_count++;
        }
        
        if ($items_count === 0) {
            $message .= "Товары не найдены\n";
        }
        
        $message .= "────────────────────\n\n";
        
        // Данные клиента
        $message .= "👤 **КЛИЕНТ:**\n";
        $message .= "{$order->get_billing_first_name()} {$order->get_billing_last_name()}\n";
        $message .= "📞 {$order->get_billing_phone()}\n";
        $message .= "📧 {$order->get_billing_email()}\n\n";
        
        // Адрес доставки
        $address = $order->get_formatted_billing_address();
        if (!empty($address) && $address != ", , , ") {
            $message .= "🏠 **АДРЕС ДОСТАВКИ:**\n";
            $clean_address = strip_tags(str_replace('<br/>', "\n", $address));
            $message .= "{$clean_address}\n\n";
        }
        
        // Комментарий клиента
        $customer_note = $order->get_customer_note();
        if (!empty($customer_note)) {
            $message .= "💬 **КОММЕНТАРИЙ:**\n";
            $message .= "{$customer_note}\n\n";
        }
        
        // Ссылка на заказ
        $admin_url = admin_url('post.php?post=' . $order->get_id() . '&action=edit');
        $message .= "🔗 [Перейти к заказу в админке]({$admin_url})";
        
        $this->log("Сформировано полное сообщение для заказа #{$order->get_id()}");
        
        return $message;
    }
    
    /**
     * Отправка сообщения в MAX
     */
    public function send_to_max($message, $order_id = null) {
        $log_prefix = $order_id ? "[Заказ #{$order_id}] " : "[Тест] ";
        
        $this->log($log_prefix . "Отправка в MAX...");
        
        if (empty($this->max_bot_token) || empty($this->max_chat_id)) {
            $this->log($log_prefix . "ОШИБКА: Token или Chat ID не настроены!", 'error');
            return false;
        }
        
        $url = "https://platform-api.max.ru/messages?chat_id=" . (int)$this->max_chat_id;
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => [
                "Authorization: {$this->max_bot_token}",
                "Content-Type: application/json"
            ],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'text' => $message,
                'format' => 'markdown'
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        $this->log($log_prefix . "HTTP код: " . $httpCode);
        
        if ($error) {
            $this->log($log_prefix . "Ошибка cURL: " . $error, 'error');
        }
        
        if ($response) {
            $this->log($log_prefix . "Ответ: " . substr($response, 0, 500));
        }
        
        $success = ($httpCode >= 200 && $httpCode < 300);
        
        if ($success) {
            $this->log($log_prefix . "✅ Сообщение отправлено!");
        } else {
            $this->log($log_prefix . "❌ Ошибка отправки!", 'error');
        }
        
        return $success;
    }
    
    public function add_integration($integrations) {
        $integrations[] = 'MAX_Order_Notifier_Integration';
        return $integrations;
    }
    
    public function handle_test_notification() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $test_message = "🧪 **ТЕСТОВОЕ СООБЩЕНИЕ**\n\n";
        $test_message .= "✅ Плагин MAX Order Notifier работает корректно.\n";
        $test_message .= "🕐 Время: " . current_time('mysql');
        
        $result = $this->send_to_max($test_message);
        
        if ($result) {
            add_settings_error('max_order_notifier', 'test_success', '✅ Тестовое сообщение отправлено!', 'success');
        } else {
            add_settings_error('max_order_notifier', 'test_error', '❌ Ошибка отправки. Проверьте логи.', 'error');
        }
        
        wp_redirect(wp_get_referer());
        exit;
    }
}

// Класс интеграции для настроек
if (!class_exists('MAX_Order_Notifier_Integration')) {
    class MAX_Order_Notifier_Integration extends WC_Integration {
        
        public function __construct() {
            $this->id = 'max_order_notifier';
            $this->method_title = 'MAX Messenger Notifier';
            $this->method_description = 'Отправляет уведомления о заказах в мессенджер MAX';
            
            $this->init_form_fields();
            $this->init_settings();
            
            add_action('woocommerce_update_options_integration_' . $this->id, array($this, 'process_admin_options'));
        }
        
        public function init_form_fields() {
            $logs_url = admin_url('admin.php?page=wc-status&tab=logs');
            
            $this->form_fields = array(
                'max_bot_token' => array(
                    'title' => 'Bot Token',
                    'type' => 'text',
                    'description' => 'Токен бота из MAX',
                    'default' => '',
                    'css' => 'width: 100%;'
                ),
                'max_chat_id' => array(
                    'title' => 'Chat ID',
                    'type' => 'text',
                    'description' => 'ID чата (отрицательное число для канала)',
                    'default' => '',
                    'css' => 'width: 100%;'
                ),
                'order_statuses' => array(
                    'title' => 'Статусы заказов',
                    'type' => 'multiselect',
                    'class' => 'wc-enhanced-select',
                    'options' => wc_get_order_statuses(),
                    'description' => 'При каких статусах отправлять уведомления',
                    'default' => array('wc-processing', 'wc-completed')
                ),
                'log_enabled' => array(
                    'title' => 'Логирование',
                    'type' => 'checkbox',
                    'label' => 'Включить логирование',
                    'description' => sprintf('<a href="%s">Просмотреть логи</a>', $logs_url),
                    'default' => 'yes'
                ),
                'test_section' => array(
                    'title' => 'Тестирование',
                    'type' => 'title',
                    'description' => 'Проверка настроек'
                ),
                'test_button' => array(
                    'title' => 'Тест',
                    'type' => 'button',
                    'description' => 'Отправить тестовое сообщение',
                    'class' => 'button-secondary'
                )
            );
        }
        
        public function process_admin_options() {
            parent::process_admin_options();
            
            update_option('max_order_notifier_bot_token', $this->get_option('max_bot_token'));
            update_option('max_order_notifier_chat_id', $this->get_option('max_chat_id'));
            update_option('max_order_notifier_statuses', $this->get_option('order_statuses'));
            update_option('max_order_notifier_log_enabled', $this->get_option('log_enabled'));
            
            error_log('[MAX] Настройки сохранены');
            
            if (isset($_POST['test_button'])) {
                $notifier = MAX_Order_Notifier::get_instance();
                $result = $notifier->send_to_max('🧪 Тестовое сообщение от MAX Order Notifier');
                
                add_action('admin_notices', function() use ($result) {
                    if ($result) {
                        echo '<div class="notice notice-success"><p>✅ Тест отправлен!</p></div>';
                    } else {
                        echo '<div class="notice notice-error"><p>❌ Ошибка теста. Проверьте логи.</p></div>';
                    }
                });
            }
        }
    }
}
