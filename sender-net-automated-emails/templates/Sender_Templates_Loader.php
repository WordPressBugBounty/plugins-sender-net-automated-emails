<?php

if (!defined('ABSPATH')) {
    exit;
}

class Sender_Templates_Loader
{
    public $sender;

    public function __construct($sender)
    {
        $this->sender = $sender;

        add_action('wp_ajax_checkSyncStatus', [$this, 'checkSyncStatus']);
        add_action('wp_ajax_sender_cancel_shop_sync', [$this, 'cancelShopSync']);
        add_action('admin_post_sender_sync_log', [$this, 'downloadSyncLog']);
        add_action('admin_menu', [&$this, 'senderInitSidebar'], 2, 2);
        add_action('admin_post_sender_debug_download', [$this, 'downloadDebugFile']);
    }

    function senderInitSidebar()
    {
        add_action('admin_post_submit-sender-settings', 'senderSubmitForm');
        add_menu_page('Sender Automated Emails Marketing', 'Sender.net', 'manage_options', 'sender-settings', [&$this, 'senderAddSidebar'], plugin_dir_url($this->sender->senderBaseFile) . 'assets/images/settings.png');
    }

    function senderHandleFormPost()
    {
        check_admin_referer( 'sender_admin_referer' );

        $changes = [];
        foreach ($_POST as $name => $value) {

            if (strpos($name, 'hidden_checkbox') !== false && !isset($_POST[str_replace('_hidden_checkbox', '', $name)])) {
                $changes[str_replace('_hidden_checkbox', '', $name)] = false;
            } else {
                $changes[$name] = $value;
            }
        }

        $map = [];

        if (!empty($_POST['sender_role_group_map_roles']) && !empty($_POST['sender_role_group_map_groups'])) {
            $roles = (array) $_POST['sender_role_group_map_roles'];
            $groups = (array) $_POST['sender_role_group_map_groups'];

            foreach ($roles as $i => $roleSlug) {
                $roleSlug = sanitize_text_field($roleSlug);
                $groupId  = sanitize_text_field($groups[$i] ?? '');
                if (!empty($roleSlug) && $groupId !== '0' && $groupId !== '') {
                    $map[$roleSlug] = $groupId;
                }
            }
        }

        $changes['sender_role_group_map'] = $map;

        $this->sender->updateSettings($changes);
    }

    function senderAddSidebar()
    {
        if ($_POST) {
            $this->senderHandleFormPost();
        }

        $this->sender->checkApiKey();

        $apiKey = get_option('sender_api_key');
        $wooEnabled = $this->sender->senderIsWooEnabled();

        if ($apiKey && !get_option('sender_account_disconnected')) {
            $groups = $this->sender->senderApi->senderGetGroups();
            if ($groups) {
                $groupsDataSenderOption = $this->extractGroupsData($groups);
                if (!empty($groupsDataSenderOption)) {
                    update_option('sender_groups_data', $groupsDataSenderOption);
                }
            }

            if (!get_option('sender_store_register')) {
                $this->sender->senderHandleAddStore();
            }
        }

        $this->checkDb();

        require_once('settings.php');
    }

    private function extractGroupsData($groups)
    {
        $groupsDataSenderOption = [];

        foreach ($groups as $group) {
            $groupsDataSenderOption[$group->id] = $group->title;
        }

        return $groupsDataSenderOption;
    }

    private function checkDb()
    {
        if (get_transient('sender_db_verified')) {
            return;
        }

        global $wpdb;

        $table = $wpdb->prefix . 'sender_automated_emails_users';
        $column = 'sender_subscriber_id';

        if (!Sender_Helper::columnExists($table, $column)) {
            require_once(__DIR__ . '/../includes/Sender_Repository.php');
            $success = (new Sender_Repository())->addSenderSubscriberId();

            if ($success) {
                set_transient('sender_db_verified', true, DAY_IN_SECONDS);
                wp_safe_redirect(admin_url('admin.php?page=sender-settings'));
                return;
            }
            return;
        }

        set_transient('sender_db_verified', true, DAY_IN_SECONDS);
    }

    public function checkSyncStatus() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(null, 403);
            return;
        }
        $state = Sender_Helper::getSyncState();
        $response = [
            'is_running' => in_array($state['status'], ['queued', 'running', 'cancelling'], true),
            'job_id' => $state['job_id'] ?? '',
            'is_finished' => $state['status'] === 'completed',
            'status' => $state['status'],
            'stage' => $state['stage'] ?? '',
            'updated_at' => $state['updated_at'] ?? null,
            'synced_at' => $state['status'] === 'completed'
                ? ($state['synced_at'] ?? get_option('sender_synced_data_date', ''))
                : get_option('sender_synced_data_date', ''),
        ];
        if ($state['status'] === 'queued') {
            $response['sync_debug'] = Sender_Helper::getSyncDiagnostics($state);
        }
        wp_send_json_success($response);
    }

    public function cancelShopSync()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(null, 403);
            return;
        }
        check_ajax_referer('sender_cancel_shop_sync', 'nonce');
        $jobId = isset($_POST['job_id']) && is_string($_POST['job_id']) ? sanitize_text_field(wp_unslash($_POST['job_id'])) : '';
        if (!Sender_Helper::cancelSyncJob($jobId)) {
            wp_send_json_error(null, 409);
            return;
        }
        $this->checkSyncStatus();
    }

    public function downloadSyncLog()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Access denied.', '', ['response' => 403]);
        }
        check_admin_referer('sender_sync_log');
        nocache_headers();
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="export-log.txt"');
        $state = Sender_Helper::getSyncState();
        echo 'Sync status: ' . $state['status'] . "\n";
        echo 'Downloaded at (UTC): ' . gmdate('Y-m-d H:i:s') . "\n";
        if ($state['status'] === 'queued' || ($state['status'] === 'cancelled' && empty($state['stage']))) {
            echo wp_json_encode(Sender_Helper::getSyncDiagnostics($state), JSON_PRETTY_PRINT) . "\n";
            echo "This job has not started. Any log below is from the previous export.\n";
        }
        $path = plugin_dir_path(__FILE__) . '../export-log.txt';
        if (is_readable($path)) {
            echo "\nLatest export log:\n";
            readfile($path);
        } else {
            echo "No export log is available yet.\n";
        }
        exit;
    }

    public function downloadDebugFile()
    {
        header("Content-Type: text/plain");
        header("Content-Disposition: attachment; filename=sender-debug-info.txt");

        $info = [];

        $info['timestamp']          = current_time('mysql');
        $info['wp_version']         = get_bloginfo('version');
        $info['php_version']        = phpversion();
        $info['server']             = $_SERVER['SERVER_SOFTWARE'] ?? '';

        $info['plugin_version'] = get_option('sender_plugin_version');

        $theme = wp_get_theme();
        $info['active_theme'] = [
            'name'    => $theme->get('Name'),
            'version' => $theme->get('Version'),
        ];

        $info['active_plugins'] = get_option('active_plugins');

        if (class_exists('WooCommerce')) {
            $info['woocommerce_version'] = WC()->version;
        }

        $info['settings'] = [];
        foreach ($this->sender->getAvailableSettings() as $key => $default) {
            if ($key === 'sender_api_key') {
                continue;
            }

            $val = get_option($key, $default);
            $info['settings'][$key] = maybe_unserialize($val);
        }

        echo print_r($info, true);
        exit;
    }

}
