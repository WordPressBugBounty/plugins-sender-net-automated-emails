<?php

if (!defined('ABSPATH')) {
    exit;
}

class Sender_Repository
{
    public function senderCreateTables()
    {
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        global $wpdb;

        $wcap_collate = '';

        if ($wpdb->has_cap('collation')) {
            $wcap_collate = $wpdb->get_charset_collate();
        }

        $sender_carts = $wpdb->prefix . 'sender_automated_emails_carts';

        $cartsSql = "CREATE TABLE IF NOT EXISTS $sender_carts (
                             `id` int(11) NOT NULL AUTO_INCREMENT,
                             `user_id` int(11) NOT NULL,
                             `user_type` varchar(15),
                             `session` varchar(50) COLLATE utf8_unicode_ci NOT NULL,
                             `cart_data` text COLLATE utf8_unicode_ci NOT NULL,
                             `cart_recovered` int(11) NOT NULL,
                             `cart_status` int(11) NOT NULL,
                             `created` int(11) NOT NULL,
                             `updated` int(11) NOT NULL,
                             PRIMARY KEY (`id`)
                             ) $wcap_collate";

        $wpdb->query($cartsSql);

        $sender_users = $wpdb->prefix . "sender_automated_emails_users";

        $usersSql = "CREATE TABLE IF NOT EXISTS $sender_users (
            `id` int(15) NOT NULL AUTO_INCREMENT,
            `first_name` text,
            `last_name` text,
            `email` text,
            `created` int(11) NOT NULL,
            `updated` int(11) NOT NULL,
            `wp_user_id` int(11),
            `visitor_id` varchar(32),
            `sender_newsletter` TINYINT(1),
            PRIMARY KEY (`id`)
            ) $wcap_collate";

        $wpdb->query($usersSql);

        $map = [
            'visitor_id' => 'varchar(32)',
            'wp_user_id' => 'int(11)',
            'sender_newsletter' => 'TINYINT(1)'
        ];

        foreach ($map as $column => $type) {
            $columnExists = $wpdb->get_results("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name = '$sender_users' AND column_name = '$column'");
            if (empty($columnExists)) {
                $wpdb->query("ALTER TABLE $sender_users ADD $column $type");
            }
        }

    }
}