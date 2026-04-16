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
        add_action('admin_post_max_test_notification', array($this, 'handle_test_notification'));
    }
    

private function init_settings() {
    $settings = get_option('woocommerce_max_order_notifier_settings', array());
    
    $this->log('Debug settings: ' . print_r($settings, true)); // ← временная отладка
    
    $this->max_bot_token = isset($settings['max_bot_token']) ? $settings['max_bot_token'] : '';
    $this->max_chat_id = isset($settings['max_chat_id']) ? $settings['max_chat_id'] : '';
    $this->order_statuses = get_option('max_order_notifier_statuses', array('processing', 'completed'));
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
        $this->log("Хук woocommerce_checkout_order_processed сработал для заказа #{$order_id}");
        
        $order = wc_get_order($order_id);
        if (!$order) {
            $this->log("Не удалось получить объект заказа #{$order_id}", 'error');
            return;
        }
        
        $order_status = $order->get_status();
        $this->log("Статус заказа #{$order_id}: {$order_status}");
        
        if (in_array($order_status, $this->order_statuses)) {
            $message = $this->format_order_message($order);
            $this->send_to_max($message, $order_id);
        } else {
            $this->log("Статус не в списке отслеживаемых, пропускаем");
        }
    }
    
    public function send_order_status_notification($order_id, $old_status, $new_status, $order) {
        $this->log("Хук woocommerce_order_status_changed: заказ #{$order_id}, {$old_status} → {$new_status}");
        
        if (in_array($new_status, $this->order_statuses)) {
            $message = $this->format_status_message($order, $old_status, $new_status);
            $this->send_to_max($message, $order_id);
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
        
        // Добавляем ссылку на заказ в админке
        $admin_url = admin_url('post.php?post=' . $order->get_id() . '&action=edit');
        $message .= "🔗 [Перейти к заказу в админке]($admin_url)";
        
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
        $message .= "📞 Телефон: {$order->get_billing_phone()}\n\n";
        
        $admin_url = admin_url('post.php?post=' . $order->get_id() . '&action=edit');
        $message .= "🔗 [Перейти к заказу]($admin_url)";
        
        return $message;
    }
    
    public function send_to_max($message, $order_id = null) {
        $log_prefix = $order_id ? "[Заказ #{$order_id}] " : "[Тест] ";
        
        $this->log($log_prefix . "Начинаем отправку в MAX");
        $this->log($log_prefix . "Bot token: " . ($this->max_bot_token ? substr($this->max_bot_token, 0, 20) . '...' : 'НЕ ЗАДАН'));
        $this->log($log_prefix . "Chat ID: " . ($this->max_chat_id ?: 'НЕ ЗАДАН'));
        
        if (empty($this->max_bot_token) || empty($this->max_chat_id)) {
            $this->log($log_prefix . "ОШИБКА: Bot token или Chat ID не настроены", 'error');
            return false;
        }
        
        // Правильный URL API MAX из рабочего скрипта
        $url = "https://platform-api.max.ru/messages?chat_id=" . (int)$this->max_chat_id;
        
        $this->log($log_prefix . "URL запроса: " . $url);
        
        // Инициализируем cURL
        $ch = curl_init($url);
        
        // Настраиваем параметры cURL
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
            CURLOPT_SSL_VERIFYPEER => false, // Для тестирования
        ]);
        
        // Выполняем запрос
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        curl_close($ch);
        
        $this->log($log_prefix . "HTTP код: " . $httpCode);
        
        if ($error) {
            $this->log($log_prefix . "Ошибка cURL: " . $error, 'error');
        }
        
        if ($response) {
            $this->log($log_prefix . "Ответ сервера: " . $response);
        }
        
        if ($httpCode >= 200 && $httpCode < 300) {
            $this->log($log_prefix . "✅ Сообщение успешно отправлено в MAX!");
            return true;
        } else {
            $this->log($log_prefix . "❌ Ошибка отправки. HTTP код: {$httpCode}", 'error');
            $this->save_failed_attempt($order_id, $message, "HTTP {$httpCode}: {$response}");
            return false;
        }
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
        
        $test_message = "🧪 **Тестовое сообщение от MAX Order Notifier**\n\n";
        $test_message .= "✅ Плагин успешно подключен к API MAX\n";
        $test_message .= "🕐 Время: " . current_time('mysql') . "\n";
        $test_message .= "🔗 WooCommerce интегрирован корректно";
        
        $result = $this->send_to_max($test_message);
        
        if ($result) {
            add_settings_error('max_order_notifier', 'test_success', '✅ Тестовое сообщение успешно отправлено в MAX!', 'success');
        } else {
            add_settings_error('max_order_notifier', 'test_error', '❌ Ошибка отправки. Проверьте логи.', 'error');
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
            $logs_url = admin_url('admin.php?page=wc-status&tab=logs');
            
            $this->form_fields = array(
                'max_bot_token' => array(
                    'title' => __('MAX Bot Token', 'max-order-notifier'),
                    'type' => 'text',
                    'description' => __('Токен бота в формате app:xxx или другой (как в рабочем скрипте)', 'max-order-notifier'),
                    'desc_tip' => true,
                    'default' => '',
                    'placeholder' => 'tLqINlA3kJc8s1euYgChr1owvjP2Z1S_Z3DgLO9fr5cMz5ic'
                ),
                'max_chat_id' => array(
                    'title' => __('MAX Chat ID', 'max-order-notifier'),
                    'type' => 'text',
                    'description' => __('ID чата или канала (отрицательное число для канала)', 'max-order-notifier'),
                    'desc_tip' => true,
                    'default' => '',
                    'placeholder' => '-73583470536035'
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
                    'description' => sprintf(__('<a href="%s">Просмотреть логи WooCommerce</a>', 'max-order-notifier'), $logs_url),
                    'default' => 'yes'
                ),
                'test_section' => array(
                    'title' => __('Тестирование', 'max-order-notifier'),
                    'type' => 'title',
                    'description' => __('Отправьте тестовое сообщение для проверки настроек', 'max-order-notifier')
                ),
                'test_button' => array(
                    'title' => __('Отправить тест', 'max-order-notifier'),
                    'type' => 'button',
                    'description' => __('Нажмите для отправки тестового уведомления в MAX', 'max-order-notifier'),
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
            $result = $notifier->send_to_max('🧪 Тестовое сообщение от MAX Order Notifier');
            
            if ($result) {
                add_action('admin_notices', function() {
                    echo '<div class="notice notice-success is-dismissible"><p>✅ Тестовое сообщение успешно отправлено в MAX!</p></div>';
                });
            } else {
                add_action('admin_notices', function() {
                    echo '<div class="notice notice-error is-dismissible"><p>❌ Ошибка отправки тестового сообщения. Проверьте настройки токена и Chat ID.</p></div>';
                });
            }
        }
    }
}
