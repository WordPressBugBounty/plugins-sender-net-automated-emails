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
    'sender_sync_finished',
    'sender_sync_state',
    'sender_sync_worker_debug',
    'sender_sync_schedule_debug',
    'sender_sync_cron_request',
    'sender_sync_cron_boot',
    'sender_subscribe_to_newsletter_string',
    'sender_subscribe_label',
    'sender_forms_data_last_update',
    'sender_cart_recovery_sync',
];

global $wpdb;

require_once __DIR__ . '/includes/Sender_Repository.php';
if (!(new Sender_Repository())->rememberNextCartId()) {
    wp_die(__('Could not preserve Sender cart IDs. Please retry deleting the plugin.', 'sender-net-automated-emails'));
}
wp_clear_scheduled_hook('sender_sync_cart_recovery_urls');
wp_clear_scheduled_hook('sender_export_shop_data_cron');
foreach (_get_cron_array() ?: [] as $timestamp => $hooks) {
    foreach (['sender_export_shop_data_batch', 'sender_export_shop_data_batch_cron', 'sender_recover_shop_export'] as $hook) {
        foreach ($hooks[$hook] ?? [] as $event) wp_unschedule_event($timestamp, $hook, $event['args']);
    }
}
if (function_exists('as_unschedule_all_actions') && did_action('action_scheduler_init')) {
    as_unschedule_all_actions('', [], 'sender-shop-export');
}

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
