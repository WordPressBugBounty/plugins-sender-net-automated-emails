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
        $isRunning = get_transient(Sender_Helper::TRANSIENT_SYNC_IN_PROGRESS);
        $isFinished = get_transient(Sender_Helper::TRANSIENT_SYNC_FINISHED);

        wp_send_json_success([
            'is_running' => (bool)$isRunning,
            'is_finished' => (bool)$isFinished,
        ]);
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
