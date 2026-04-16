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
        
        // Добавляем тестовый хук для ручной проверки
        add_action('admin_post_max_test_notification', array($this, 'handle_test_notification'));
    }
    
    private function init_settings() {
        $this->max_bot_token = get_option('max_order_notifier_bot_token', '');
        $this->max_chat_id = get_option('max_order_notifier_chat_id', '');
        $this->order_statuses = get_option('max_order_notifier_statuses', array('processing', 'completed'));
    }
    
    private function log($message, $level = 'info') {
        if ($this->log_enabled !== 'yes') {
            return;
        }
        
        $log_message = '[MAX Order Notifier] ' . $message;
        
        // Записываем в error_log WordPress
        error_log($log_message);
        
        // Записываем в WooCommerce логгер
        if (isset($this->log) && function_exists('wc_get_logger')) {
            $this->log->log($level, $message, array('source' => 'max-order-notifier'));
        }
    }
    
    public function send_order_notification($order_id, $posted_data, $order) {
        $this->log("Хук woocommerce_checkout_order_processed сработал для заказа #{$order_id}");
        
        $order = wc_get_order($order_id);
        if (!$order) {
            $this->log("Не удалось получить объект заказа #{$order_id}", 'error');
            return;
        }
        
        $order_status = $order->get_status();
        $this->log("Статус заказа #{$order_id}: {$order_status}");
        $this->log("Отслеживаемые статусы: " . print_r($this->order_statuses, true));
        
        if (in_array($order_status, $this->order_statuses)) {
            $this->log("Статус соответствует условиям, отправляем уведомление");
            $message = $this->format_order_message($order);
            $this->send_to_max($message, $order_id);
        } else {
            $this->log("Статус не в списке отслеживаемых, пропускаем");
        }
    }
    
    public function send_order_status_notification($order_id, $old_status, $new_status, $order) {
        $this->log("Хук woocommerce_order_status_changed сработал: заказ #{$order_id}, статус изменен с {$old_status} на {$new_status}");
        
        if (in_array($new_status, $this->order_statuses)) {
            $this->log("Новый статус в списке отслеживаемых, отправляем уведомление");
            $message = $this->format_status_message($order, $old_status, $new_status);
            $this->send_to_max($message, $order_id);
        } else {
            $this->log("Новый статус {$new_status} не в списке отслеживаемых, пропускаем");
        }
    }
    
    private function format_order_message($order) {
        $items = array();
        foreach ($order->get_items() as $item_id => $item) {
            $product = $item->get_product();
            $items[] = sprintf(
                "• %s x %d — %s",
                $item->get_name(),
                $item->get_quantity(),
                strip_tags(wc_price($item->get_total()))
            );
        }
        
        $message = "🛍️ **НОВЫЙ ЗАКАЗ**\n\n";
        $message .= "📋 **Заказ #{$order->get_order_number()}**\n";
        $message .= "📅 Дата: " . $order->get_date_created()->date_i18n('d.m.Y H:i') . "\n";
        $message .= "💰 Сумма: " . strip_tags(wc_price($order->get_total())) . "\n";
        $message .= "💳 Оплата: " . $order->get_payment_method_title() . "\n";
        $message .= "🚚 Доставка: " . $order->get_shipping_method() . "\n\n";
        
        $message .= "📦 **Товары:**\n" . implode("\n", $items) . "\n\n";
        
        $message .= "👤 **Клиент:**\n";
        $message .= "{$order->get_billing_first_name()} {$order->get_billing_last_name()}\n";
        $message .= "📞 {$order->get_billing_phone()}\n";
        $message .= "📧 {$order->get_billing_email()}\n\n";
        
        $billing_address = $order->get_formatted_billing_address();
        if ($billing_address) {
            $message .= "🏠 **Адрес доставки:**\n" . $billing_address . "\n";
        }
        
        $this->log("Сформировано сообщение для заказа #{$order->get_order_number()}, длина: " . strlen($message));
        
        return $message;
    }
    
    private function format_status_message($order, $old_status, $new_status) {
        $status_labels = wc_get_order_statuses();
        $old_label = isset($status_labels['wc-' . $old_status]) ? $status_labels['wc-' . $old_status] : $old_status;
        $new_label = isset($status_labels['wc-' . $new_status]) ? $status_labels['wc-' . $new_status] : $new_status;
        
        $message = "🔄 **Статус заказа #{$order->get_order_number()} изменен**\n\n";
        $message .= "📊 Изменение: {$old_label} → {$new_label}\n";
        $message .= "💰 Сумма: " . strip_tags(wc_price($order->get_total())) . "\n";
        $message .= "👤 Клиент: {$order->get_billing_first_name()} {$order->get_billing_last_name()}\n";
        
        return $message;
    }
    
    public function send_to_max($message, $order_id = null) {
        $log_prefix = $order_id ? "[Заказ #{$order_id}] " : "[Тест] ";
        
        $this->log($log_prefix . "Начинаем отправку в MAX");
        $this->log($log_prefix . "Bot token: " . ($this->max_bot_token ? substr($this->max_bot_token, 0, 10) . '...' : 'НЕ ЗАДАН'));
        $this->log($log_prefix . "Chat ID: " . ($this->max_chat_id ?: 'НЕ ЗАДАН'));
        
        if (empty($this->max_bot_token) || empty($this->max_chat_id)) {
            $this->log($log_prefix . "ОШИБКА: Bot token или Chat ID не настроены", 'error');
            return false;
        }
        
        // Пробуем разные возможные эндпоинты MAX API
        $endpoints = array(
            'https://api.max.ru/bot/' . $this->max_bot_token . '/sendMessage',
            'https://api.max.ru/v1/messages/send',
            'https://max.ru/api/send',
        );
        
        $success = false;
        $last_error = '';
        
        foreach ($endpoints as $endpoint) {
            $this->log($log_prefix . "Пробуем эндпоинт: " . $endpoint);
            
            $payload = array(
                'chat_id' => $this->max_chat_id,
                'text' => $message,
                'parse_mode' => 'Markdown'
            );
            
            $response = wp_remote_post($endpoint, array(
                'timeout' => 15,
                'headers' => array(
                    'Content-Type' => 'application/json',
                ),
                'body' => json_encode($payload)
            ));
            
            $this->log($log_prefix . "Ответ получен");
            
            if (is_wp_error($response)) {
                $error_msg = $response->get_error_message();
                $this->log($log_prefix . "WP_Error: " . $error_msg, 'error');
                $last_error = $error_msg;
                continue;
            }
            
            $status_code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            
            $this->log($log_prefix . "HTTP статус: " . $status_code);
            $this->log($log_prefix . "Тело ответа: " . $body);
            
            if ($status_code >= 200 && $status_code < 300) {
                $this->log($log_prefix . "УСПЕХ! Сообщение отправлено через эндпоинт: " . $endpoint);
                $success = true;
                break;
            } else {
                $this->log($log_prefix . "Ошибка HTTP {$status_code}: {$body}", 'error');
                $last_error = "HTTP {$status_code}: {$body}";
            }
        }
        
        if (!$success) {
            $this->log($log_prefix . "ВСЕ ПОПЫТКИ ОТПРАВКИ НЕ УДАЛИСЬ. Последняя ошибка: {$last_error}", 'error');
            
            // Сохраняем неудачную попытку в отдельную таблицу
            $this->save_failed_attempt($order_id, $message, $last_error);
        }
        
        return $success;
    }
    
    private function save_failed_attempt($order_id, $message, $error) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'max_order_notifier_failed';
        
        // Создаем таблицу если её нет
        $wpdb->query("
            CREATE TABLE IF NOT EXISTS {$table_name} (
                id INT AUTO_INCREMENT PRIMARY KEY,
                order_id INT NULL,
                message TEXT,
                error TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
        
        $wpdb->insert($table_name, array(
            'order_id' => $order_id,
            'message' => $message,
            'error' => $error
        ));
        
        $this->log("Неудачная попытка сохранена в таблицу {$table_name}");
    }
    
    public function add_integration($integrations) {
        $integrations[] = 'MAX_Order_Notifier_Integration';
        return $integrations;
    }
    
    public function handle_test_notification() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $test_message = "🧪 Тестовое сообщение от MAX Order Notifier\nВремя: " . current_time('mysql');
        $result = $this->send_to_max($test_message);
        
        if ($result) {
            add_settings_error('max_order_notifier', 'test_success', 'Тестовое сообщение отправлено!', 'success');
        } else {
            add_settings_error('max_order_notifier', 'test_error', 'Ошибка отправки. Проверьте логи.', 'error');
        }
        
        wp_redirect(wp_get_referer());
        exit;
    }
}

// Класс интеграции для настроек WooCommerce
if (!class_exists('MAX_Order_Notifier_Integration')) {
    class MAX_Order_Notifier_Integration extends WC_Integration {
        
        public function __construct() {
            $this->id = 'max_order_notifier';
            $this->method_title = __('MAX Messenger Notifier', 'max-order-notifier');
            $this->method_description = __('Отправляет уведомления о заказах в чат мессенджера MAX', 'max-order-notifier');
            
            $this->init_form_fields();
            $this->init_settings();
            
            $this->max_bot_token = $this->get_option('max_bot_token');
            $this->max_chat_id = $this->get_option('max_chat_id');
            $this->order_statuses = $this->get_option('order_statuses', array('processing', 'completed'));
            $this->log_enabled = $this->get_option('log_enabled', 'yes');
            
            add_action('woocommerce_update_options_integration_' . $this->id, array($this, 'process_admin_options'));
        }
        
        public function init_form_fields() {
            $logs_url = '';
            if (function_exists('wc_get_log_file_path')) {
                $logs_url = admin_url('admin.php?page=wc-status&tab=logs');
            }
            
            $this->form_fields = array(
                'max_bot_token' => array(
                    'title' => __('MAX Bot Token', 'max-order-notifier'),
                    'type' => 'text',
                    'description' => __('Токен вашего MAX бота', 'max-order-notifier'),
                    'desc_tip' => true,
                    'default' => ''
                ),
                'max_chat_id' => array(
                    'title' => __('MAX Chat ID', 'max-order-notifier'),
                    'type' => 'text',
                    'description' => __('ID чата для отправки уведомлений', 'max-order-notifier'),
                    'desc_tip' => true,
                    'default' => ''
                ),
                'order_statuses' => array(
                    'title' => __('Статусы заказов для уведомлений', 'max-order-notifier'),
                    'type' => 'multiselect',
                    'class' => 'wc-enhanced-select',
                    'css' => 'width: 400px;',
                    'options' => wc_get_order_statuses(),
                    'description' => __('Выберите статусы заказов, при которых будут отправляться уведомления', 'max-order-notifier'),
                    'default' => array('wc-processing', 'wc-completed')
                ),
                'log_enabled' => array(
                    'title' => __('Включить логирование', 'max-order-notifier'),
                    'type' => 'checkbox',
                    'label' => __('Записывать события и ошибки в лог', 'max-order-notifier'),
                    'description' => $logs_url ? sprintf(__('<a href="%s">Просмотреть логи</a>', 'max-order-notifier'), $logs_url) : '',
                    'default' => 'yes'
                ),
                'test_section' => array(
                    'title' => __('Тестирование', 'max-order-notifier'),
                    'type' => 'title',
                    'description' => __('Отправьте тестовое сообщение для проверки настроек', 'max-order-notifier')
                ),
                'test_button' => array(
                    'title' => __('Тестовое сообщение', 'max-order-notifier'),
                    'type' => 'button',
                    'description' => __('Нажмите для отправки тестового уведомления', 'max-order-notifier'),
                    'class' => 'button-secondary'
                )
            );
        }
        
        public function process_admin_options() {
            parent::process_admin_options();
            
            if (isset($_POST['test_button'])) {
                $this->send_test_message();
            }
        }
        
        private function send_test_message() {
            $notifier = MAX_Order_Notifier::get_instance();
            $result = $notifier->send_to_max('🧪 Тестовое сообщение от MAX Order Notifier для WooCommerce');
            
            if ($result) {
                add_action('admin_notices', function() {
                    echo '<div class="notice notice-success"><p>✅ Тестовое сообщение успешно отправлено!</p></div>';
                });
            } else {
                add_action('admin_notices', function() {
                    echo '<div class="notice notice-error"><p>❌ Ошибка отправки тестового сообщения. Проверьте логи.</p></div>';
                });
            }
        }
    }
}
