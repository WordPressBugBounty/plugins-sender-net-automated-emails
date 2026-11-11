<?php

if (!defined('ABSPATH')) {
    exit;
}

class Sender_Repository
{
    // Intentionally survives uninstall: Sender may still retain carts with these IDs.
    const NEXT_CART_ID_OPTION = 'sender_next_cart_id';

    public function rememberNextCartId()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'sender_automated_emails_carts';
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
        if ($wpdb->last_error !== '') {
            return false;
        }
        if (!$exists) {
            return true;
        }
        // SHOW TABLE STATUS can return a cached counter on MySQL 8.
        $definition = $wpdb->get_row("SHOW CREATE TABLE `{$table}`", ARRAY_N);
        if (!$definition || strpos($definition[1], 'AUTO_INCREMENT') === false) {
            return false;
        }
        $next = preg_match('/\bAUTO_INCREMENT=(\d+)/', $definition[1], $matches) ? (int) $matches[1] : 1;
        $saved = $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')
             ON DUPLICATE KEY UPDATE option_value = GREATEST(CAST(option_value AS UNSIGNED), CAST(VALUES(option_value) AS UNSIGNED)), autoload = 'no'",
            self::NEXT_CART_ID_OPTION, (string) $next
        ));
        wp_cache_delete(self::NEXT_CART_ID_OPTION, 'options');
        wp_cache_delete('notoptions', 'options');
        return $saved !== false;
    }

    public function senderCreateTables()
    {
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        global $wpdb;

        $wcap_collate = '';

        if ($wpdb->has_cap('collation')) {
            $wcap_collate = $wpdb->get_charset_collate();
        }

        $sender_carts = $wpdb->prefix . 'sender_automated_emails_carts';
        $nextCartId = max(1, (int) get_option(self::NEXT_CART_ID_OPTION, 1));

        $cartsSql = "CREATE TABLE IF NOT EXISTS $sender_carts (
                             `id` int(11) NOT NULL AUTO_INCREMENT,
                             `user_id` int(11) NOT NULL,
                             `cart_data` text COLLATE utf8_unicode_ci NOT NULL,
                             `cart_recovered` int(11) NOT NULL,
                             `cart_status` int(11) NOT NULL,
                             `created` int(11) NOT NULL,
                             `updated` int(11) NOT NULL,
                             PRIMARY KEY (`id`)
                             ) $wcap_collate AUTO_INCREMENT=$nextCartId";

        $wpdb->query($cartsSql);

        //Removed column on version 2.7.5. After 2-3 releases this query can be removed.
        $remove_columns = ['user_type', 'session'];
        foreach ($remove_columns as $column) {
            $columnExists = $wpdb->get_results("SHOW COLUMNS FROM $sender_carts LIKE '$column'");
            if (!empty($columnExists)) {
                $wpdb->query("ALTER TABLE $sender_carts DROP COLUMN $column");
            }
        }

        $sender_users = $wpdb->prefix . "sender_automated_emails_users";

        $usersSql = "CREATE TABLE IF NOT EXISTS $sender_users (
            `id` int(15) NOT NULL AUTO_INCREMENT,
            `first_name` text,
            `last_name` text,
            `email` text,
            `created` int(11) NOT NULL,
            `updated` int(11) NOT NULL,
            `wp_user_id` int(11),
            `sender_newsletter` TINYINT(1),
            `sender_subscriber_id` varchar(50),
            PRIMARY KEY (`id`)
            ) $wcap_collate";

        $wpdb->query($usersSql);

        $map = [
            'wp_user_id' => 'int(11)',
            'sender_newsletter' => 'TINYINT(1)'
        ];

        foreach ($map as $column => $type) {
            $columnExists = $wpdb->get_results("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name = '$sender_users' AND column_name = '$column'");
            if (empty($columnExists)) {
                $wpdb->query("ALTER TABLE $sender_users ADD $column $type");
            }
        }

        //Removed column on version 2.7.5. After 2-3 releases this query can be removed.
        $visitorColumn = $wpdb->get_results("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name = '$sender_users' AND column_name = 'visitor_id'");
        if (!empty($visitorColumn)) {
            $wpdb->query("ALTER TABLE $sender_users DROP COLUMN visitor_id");
        }

        $this->addSenderSubscriberId();
    }

    public function addSenderSubscriberId()
    {
        global $wpdb;

        $table = $wpdb->prefix . "sender_automated_emails_users";
        $column = 'sender_subscriber_id';

        if (!Sender_Helper::columnExists($table, $column)) {
            $wpdb->query("ALTER TABLE $table ADD COLUMN $column VARCHAR(50)");
            return Sender_Helper::columnExists($table, $column);
        }

        return true;
    }
}
