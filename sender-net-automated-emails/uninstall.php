<?php

if (!defined('WP_UNINSTALL_PLUGIN')) exit;
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Sender_API')) {
    require_once 'includes/Sender_API.php';
}

$availableSettings = [
    'sender_api_key',
    'sender_resource_key',
    'sender_allow_tracking',
    'sender_customers_list',
    'sender_registration_list',
    'sender_account_message',
    'sender_store_register',
    'sender_account_disconnected',
    'sender_account_title',
    'sender_account_plan_type',
    'sender_groups_data',
    'sender_forms_data',
    'sender_wocommerce_sync',
    'sender_synced_data_date',
    'sender_subscribe_to_newsletter_string',
    'sender_subscribe_label',
    'sender_forms_data_last_update',
];

global $wpdb;

$senderApi = new Sender_API();
$senderApi->senderDeleteStore();

$tables = [
    "sender_automated_emails_carts",
    "sender_automated_emails_users"
];

foreach ($tables as $table) {
    $name = $wpdb->prefix . $table;
    $wpdb->query("DROP TABLE IF EXISTS {$name}");
}

foreach ($availableSettings as $setting) {
    delete_option($setting);
}

?>