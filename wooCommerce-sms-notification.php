<?php
/*
Plugin Name: WooCommerce SMS Notification Plugin
Description: Send SMS notifications to customers for WooCommerce order updates.
Version: 1.0.0
Author: Mushfikur Rahman
*/

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Step 1: Create settings page for SMS API credentials
add_action('admin_menu', 'sms_notification_add_admin_menu');
function sms_notification_add_admin_menu() {
    add_menu_page(
        __('SMS Notifications', 'sms-notification-plugin'),
        __('SMS Notifications', 'sms-notification-plugin'),
        'manage_options',
        'sms-notification-plugin',
        'sms_notification_settings_page',
        'dashicons-email-alt',
        56
    );
}

function sms_notification_settings_page() {
    ?>
    <div class="wrap">
        <h1><?php echo esc_html__('SMS Notification Settings', 'sms-notification-plugin'); ?></h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('sms_notification_settings');
            do_settings_sections('sms-notification-plugin');
            submit_button();
            ?>
        </form>
    </div>
    <?php
}

// Step 2: Register settings for SMS API credentials and message templates
add_action('admin_init', 'sms_notification_settings_init');
function sms_notification_settings_init() {
    register_setting('sms_notification_settings', 'sms_api_key');
    register_setting('sms_notification_settings', 'sms_sender_id');
    register_setting('sms_notification_settings', 'sms_order_completion_message');

    add_settings_section(
        'sms_notification_section',
        __('SMS API Settings', 'sms-notification-plugin'),
        null,
        'sms-notification-plugin'
    );

    add_settings_field(
        'sms_api_key',
        __('API Key', 'sms-notification-plugin'),
        'sms_api_key_render',
        'sms-notification-plugin',
        'sms_notification_section'
    );

    add_settings_field(
        'sms_sender_id',
        __('Sender ID', 'sms-notification-plugin'),
        'sms_sender_id_render',
        'sms-notification-plugin',
        'sms_notification_section'
    );

    add_settings_field(
        'sms_order_completion_message',
        __('Order Completion Message', 'sms-notification-plugin'),
        'sms_order_completion_message_render',
        'sms-notification-plugin',
        'sms_notification_section'
    );
}

function sms_api_key_render() {
    $value = get_option('sms_api_key', '');
    echo '<input type="text" name="sms_api_key" value="' . esc_attr($value) . '" style="width: 100%;">';
}

function sms_sender_id_render() {
    $value = get_option('sms_sender_id', '');
    echo '<input type="text" name="sms_sender_id" value="' . esc_attr($value) . '" style="width: 100%;">';
}

function sms_order_completion_message_render() {
    $value = get_option('sms_order_completion_message', 'Your order #ORDER_ID has been completed. Thank you!');
    echo '<textarea name="sms_order_completion_message" style="width: 100%; height: 100px;">' . esc_textarea($value) . '</textarea>';
}

// Step 3: Function to send SMS
function send_sms_notification($phone_numbers, $message) {
    $api_key = get_option('sms_api_key');
    $sender_id = get_option('sms_sender_id');

    if (!$api_key || !$sender_id) {
        return false;
    }

    $url = 'https://api.example.com/send-sms'; // Replace with your SMS API URL
    $args = [
        'headers' => ['Content-Type' => 'application/json'],
        'body' => json_encode([
            'api_key' => $api_key,
            'sender_id' => $sender_id,
            'phone_numbers' => $phone_numbers,
            'message' => $message
        ])
    ];

    $response = wp_remote_post($url, $args);

    if (is_wp_error($response)) {
        return false;
    }

    $body = wp_remote_retrieve_body($response);
    $result = json_decode($body, true);

    return isset($result['success']) && $result['success'];
}

// Step 4: Send SMS on order status change
add_action('woocommerce_order_status_completed', 'send_sms_on_order_completed', 10, 1);
function send_sms_on_order_completed($order_id) {
    $order = wc_get_order($order_id);
    $phone = $order->get_billing_phone();

    $message_template = get_option('sms_order_completion_message', 'Your order #ORDER_ID has been completed. Thank you!');
    $message = str_replace('ORDER_ID', $order_id, $message_template);

    send_sms_notification([$phone], $message);
}

// Step 5: Add SMS column to WooCommerce Orders table
add_filter('manage_edit-shop_order_columns', 'add_sms_column');
function add_sms_column($columns) {
    $new_columns = [];
    foreach ($columns as $key => $column) {
        $new_columns[$key] = $column;
        if ($key === 'order_status') {
            $new_columns['sms_notification'] = __('SMS Notification', 'sms-notification-plugin');
        }
    }
    return $new_columns;
}

add_action('manage_shop_order_posts_custom_column', 'display_sms_column_content', 10, 2);
function display_sms_column_content($column, $post_id) {
    if ($column === 'sms_notification') {
        $sms_sent = get_post_meta($post_id, '_sms_sent', true);
        echo $sms_sent === 'yes' ? '<span style="color:green;">Sent</span>' : '<span style="color:red;">Not Sent</span>';
    }
}

// Step 6: Bulk action for sending SMS
add_filter('bulk_actions-edit-shop_order', 'add_bulk_sms_action');
function add_bulk_sms_action($bulk_actions) {
    $bulk_actions['send_sms'] = __('Send SMS Notification', 'sms-notification-plugin');
    return $bulk_actions;
}

add_filter('handle_bulk_actions-edit-shop_order', 'handle_bulk_sms_action', 10, 3);
function handle_bulk_sms_action($redirect_url, $action, $order_ids) {
    if ($action === 'send_sms') {
        foreach ($order_ids as $order_id) {
            $order = wc_get_order($order_id);
            $phone = $order->get_billing_phone();

            $message_template = get_option('sms_order_completion_message', 'Your order #ORDER_ID has been completed. Thank you!');
            $message = str_replace('ORDER_ID', $order_id, $message_template);

            send_sms_notification([$phone], $message);
            update_post_meta($order_id, '_sms_sent', 'yes');
        }
        $redirect_url = add_query_arg('bulk_sms_sent', count($order_ids), $redirect_url);
    }
    return $redirect_url;
}

add_action('admin_notices', 'bulk_sms_action_admin_notice');
function bulk_sms_action_admin_notice() {
    if (!empty($_REQUEST['bulk_sms_sent'])) {
        $count = intval($_REQUEST['bulk_sms_sent']);
        printf('<div class="notice notice-success is-dismissible"><p>' . __('Successfully sent SMS to %d orders.', 'sms-notification-plugin') . '</p></div>', $count);
    }
}
