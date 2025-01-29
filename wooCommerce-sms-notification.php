<?php
/*
Plugin Name: SMS Notification Plugin
Description: A plugin to send SMS notifications for WooCommerce order updates and custom SMS.
Version: 1.0
Author: Mushfikur Rahman
*/

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Step 1: Create Admin Settings Page
add_action('admin_menu', 'sms_notification_plugin_menu');
function sms_notification_plugin_menu() {
    // Create top-level menu
    add_menu_page(
        'SMS Notifications', // Page title
        'SMS Notifications', // Menu title
        'manage_options',    // Capability
        'sms-notification-plugin', // Menu slug
        'sms_notification_settings_page', // Function
        'dashicons-email',   // Icon
        20                   // Position
    );

    // Create submenu for SMS Logs
    add_submenu_page(
        'sms-notification-plugin', // Parent slug
        'SMS Logs',                // Page title
        'SMS Logs',                // Menu title
        'manage_options',          // Capability
        'sms-log',                 // Menu slug
        'display_sms_logs_page'    // Function
    );

    // Create submenu for Send Custom SMS
    add_submenu_page(
        'sms-notification-plugin', // Parent slug
        'Send Custom SMS',         // Page title
        'Send Custom SMS',         // Menu title
        'manage_options',          // Capability
        'send-custom-sms',         // Menu slug
        'send_custom_sms_page'     // Function
    );
}

// Step 2: Build the Settings Form
function sms_notification_settings_page() {
    ?>
    <div class="wrap">
        <h2>SMS Notification Settings</h2>
        <form method="post" action="options.php">
            <?php settings_fields('sms_notification_options'); ?>
            <?php do_settings_sections('sms_notification_options'); ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">API Key</th>
                    <td><input type="text" name="sms_notification_api_key" value="<?php echo esc_attr(get_option('sms_notification_api_key')); ?>" /></td>
                </tr>
                <tr valign="top">
                    <th scope="row">Sender ID</th>
                    <td><input type="text" name="sms_notification_sender_id" value="<?php echo esc_attr(get_option('sms_notification_sender_id')); ?>" /></td>
                </tr>
                <tr valign="top">
                    <th scope="row">Order Completion Message</th>
                    <td><textarea name="sms_order_completion_message" rows="4" cols="50"><?php echo esc_textarea(get_option('sms_order_completion_message', 'Your order #ORDER_ID has been completed. Thank you!')); ?></textarea></td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

// Step 3: Display SMS Logs Page
function display_sms_logs_page() {
    // Query to get SMS logs
    $args = [
        'post_type' => 'sms_log',
        'posts_per_page' => -1,
    ];

    $logs = new WP_Query($args);
    echo '<div class="wrap"><h2>SMS Logs</h2>';
    echo '<table class="widefat"><thead><tr><th>Title</th><th>Message</th><th>Date</th></tr></thead><tbody>';

    while ($logs->have_posts()) {
        $logs->the_post();
        echo '<tr>';
        echo '<td>' . get_the_title() . '</td>';
        echo '<td>' . get_the_content() . '</td>';
        echo '<td>' . get_the_date() . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table></div>';
    wp_reset_postdata();
}

// Step 4: Register Settings
add_action('admin_init', 'sms_notification_register_settings');
function sms_notification_register_settings() {
    register_setting('sms_notification_options', 'sms_notification_api_key');
    register_setting('sms_notification_options', 'sms_notification_sender_id');
    register_setting('sms_notification_options', 'sms_order_completion_message');
}

// Step 5: Function to Send SMS Notifications
function send_sms_notification($numbers, $message) {
    $api_key = get_option('sms_notification_api_key');
    $sender_id = get_option('sms_notification_sender_id');
    
    $data = [
        "api_key" => $api_key,
        "senderid" => $sender_id,
        "number" => implode(',', $numbers),
        "message" => $message
    ];
    
    $response = wp_remote_post("http://bulksmsbd.net/api/smsapi", [
        'method' => 'POST',
        'body' => $data
    ]);

    // Log the SMS sending result
    $log_message = '';
    if (is_wp_error($response)) {
        $log_message = 'Failed to send SMS: ' . $response->get_error_message();
    } else {
        $response_body = json_decode(wp_remote_retrieve_body($response));
        if (isset($response_body->code) && $response_body->code == 202) {
            $log_message = 'SMS sent successfully.';
        } else {
            $log_message = 'SMS failed to send. Response: ' . wp_remote_retrieve_body($response);
        }
    }

    // Create a new SMS log post
    $log_post = [
        'post_title' => 'SMS Log for #' . implode(',', $numbers),
        'post_content' => $log_message,
        'post_status' => 'publish',
        'post_type' => 'sms_log',
    ];
    wp_insert_post($log_post);

    return wp_remote_retrieve_body($response);
}

// Step 6: Integrate with WooCommerce Order Status
add_action('woocommerce_order_status_completed', 'send_order_completion_sms');
function send_order_completion_sms($order_id) {
    $order = wc_get_order($order_id);
    $phone = $order->get_billing_phone();
    
    // Check if user opted in
    $sms_opt_in = get_post_meta($order_id, 'sms_opt_in', true);
    if ($sms_opt_in !== 'yes') {
        return; // Exit if user did not opt in
    }

    // Retrieve and replace placeholders in the message template
    $message_template = get_option('sms_order_completion_message', 'Your order #ORDER_ID has been completed. Thank you!');
    $message = str_replace('ORDER_ID', $order_id, $message_template);

    send_sms_notification([$phone], $message);
}

// Step 7: Add Opt-In Checkbox on Checkout
add_action('woocommerce_after_order_notes', 'sms_notification_optin_checkbox');
function sms_notification_optin_checkbox($checkout) {
    echo '<div id="sms_notification_optin">';
    woocommerce_form_field('sms_opt_in', [
        'type' => 'checkbox',
        'class' => ['sms-opt-in-checkbox'],
        'label' => __('Receive SMS notifications about your order status')
    ], $checkout->get_value('sms_opt_in'));
    echo '</div>';
}

// Step 8: Save Opt-In Preference
add_action('woocommerce_checkout_update_order_meta', 'save_sms_opt_in_field');
function save_sms_opt_in_field($order_id) {
    if (!empty($_POST['sms_opt_in'])) {
        update_post_meta($order_id, 'sms_opt_in', 'yes');
    }
}

// Step 9: Register SMS Log Custom Post Type
add_action('init', 'create_sms_log_post_type');
function create_sms_log_post_type() {
    register_post_type('sms_log',
        [
            'labels' => [
                'name' => __('SMS Logs'),
                'singular_name' => __('SMS Log')
            ],
            'public' => false,
            'has_archive' => false,
            'supports' => ['title', 'editor', 'custom-fields'],
            'show_in_menu' => false, // Hide from top-level menu
        ]
    );
}

// Step 10: Add Send Custom SMS Page
function send_custom_sms_page() {
    if (isset($_POST['send_sms'])) {
        $numbers = sanitize_text_field($_POST['sms_numbers']);
        $message = sanitize_textarea_field($_POST['sms_message']);
        $result = send_sms_notification(explode(',', $numbers), $message);
        echo '<div class="updated"><p>' . esc_html($result) . '</p></div>';
    }
    ?>
    <div class="wrap">
        <h2>Send Custom SMS</h2>
        <form method="post" action="">
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">Phone Numbers</th>
                    <td><input type="text" name="sms_numbers" placeholder="e.g., 88016xxxxxxx,88019xxxxxxx" required /></td>
                </tr>
                <tr valign="top">
                    <th scope="row">Message</th>
                    <td><textarea name="sms_message" rows="4" cols="50" required></textarea></td>
                </tr>
            </table>
            <?php submit_button('Send SMS', 'primary', 'send_sms'); ?>
        </form>
    </div>
    <?php
}
?>