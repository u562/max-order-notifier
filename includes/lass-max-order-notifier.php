
<?php

class MAX_Order_Notifier {
    
    private static $instance = null;
    private $max_bot_token;
    private $max_chat_id;
    private $webhook_url;
    private $order_statuses;
    
    private function __construct() {
        $this->init_hooks();
        $this->init_settings();
    }
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function init_hooks() {
        add_action('woocommerce_api_max_order_webhook', array($this, 'handle_webhook_request'));
        add_action('woocommerce_checkout_order_processed', array($this, 'send_order_notification'), 10, 3);
        add_action('woocommerce_order_status_changed', array($this, 'send_order_status_notification'), 10, 4);
        add_filter('woocommerce_integrations', array($this, 'add_integration'));
    }
    
    private function init_settings() {
        $this->max_bot_token = get_option('max_order_notifier_bot_token', '');
        $this->max_chat_id = get_option('max_order_notifier_chat_id', '');
        $this->order_statuses = get_option('max_order_notifier_statuses', array('processing', 'completed'));
    }
    
    public function add_integration($integrations) {
        $integrations[] = 'MAX_Order_Notifier_Integration';
        return $integrations;
    }
    
    public function send_order_notification($order_id, $posted_data, $order) {
        $order = wc_get_order($order_id);
        $order_status = $order->get_status();
        
        if (in_array($order_status, $this->order_statuses)) {
            $this->send_to_max($this->format_order_message($order));
        }
    }
    
    public function send_order_status_notification($order_id, $old_status, $new_status, $order) {
        if (in_array($new_status, $this->order_statuses)) {
            $message = $this->format_status_message($order, $old_status, $new_status);
            $this->send_to_max($message);
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
    
    private function send_to_max($message) {
        if (empty($this->max_bot_token) || empty($this->max_chat_id)) {
            error_log('MAX Order Notifier: Bot token or chat ID not configured');
            return false;
        }
        
        $api_url = 'https://api.max.ru/bot/' . $this->max_bot_token . '/sendMessage';
        
        $payload = array(
            'chat_id' => $this->max_chat_id,
            'text' => $message,
            'parse_mode' => 'Markdown'
        );
        
        $response = wp_remote_post($api_url, array(
            'timeout' => 10,
            'headers' => array(
                'Content-Type' => 'application/json',
            ),
            'body' => json_encode($payload)
        ));
        
        if (is_wp_error($response)) {
            error_log('MAX Order Notifier Error: ' . $response->get_error_message());
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);
        
        return isset($result['ok']) && $result['ok'] === true;
    }
    
    public function handle_webhook_request() {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input || !isset($input['order_id'])) {
            status_header(400);
            echo json_encode(array('error' => 'Invalid webhook data'));
            exit;
        }
        
        $order_id = intval($input['order_id']);
        $order = wc_get_order($order_id);
        
        if ($order) {
            $this->send_to_max($this->format_order_message($order));
            status_header(200);
            echo json_encode(array('success' => true));
        } else {
            status_header(404);
            echo json_encode(array('error' => 'Order not found'));
        }
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
            
            add_action('woocommerce_update_options_integration_' . $this->id, array($this, 'process_admin_options'));
        }
        
        public function init_form_fields() {
            $this->form_fields = array(
                'max_bot_token' => array(
                    'title' => __('MAX Bot Token', 'max-order-notifier'),
                    'type' => 'text',
                    'description' => __('Токен вашего MAX бота. Получите его у @MasterBot в MAX', 'max-order-notifier'),
                    'desc_tip' => true,
                    'default' => ''
                ),
                'max_chat_id' => array(
                    'title' => __('MAX Chat ID', 'max-order-notifier'),
                    'type' => 'text',
                    'description' => __('ID чата или пользователя для отправки уведомлений', 'max-order-notifier'),
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
            $result = $notifier->send_to_max('✅ Тестовое сообщение от MAX Order Notifier для WooCommerce');
            
            if ($result) {
                add_action('admin_notices', function() {
                    echo '<div class="notice notice-success"><p>' . __('Тестовое сообщение успешно отправлено!', 'max-order-notifier') . '</p></div>';
                });
            } else {
                add_action('admin_notices', function() {
                    echo '<div class="notice notice-error"><p>' . __('Ошибка отправки тестового сообщения. Проверьте настройки.', 'max-order-notifier') . '</p></div>';
                });
            }
        }
    }
}
