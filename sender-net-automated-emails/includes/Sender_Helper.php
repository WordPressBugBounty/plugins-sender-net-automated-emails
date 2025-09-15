<?php

if (!defined('ABSPATH')) {
    exit;
}

class Sender_Helper
{
    #Used for email_marketing_consent
    const SUBSCRIBED = 'subscribed';
    const UNSUBSCRIBED = 'unsubscribed';
    const NOT_SUBSCRIBED = 'not_subscribed';
    const EMAIL_MARKETING_META_KEY = 'email_marketing_consent';

    #Used for updating channel status directly
    const UPDATE_STATUS_ACTIVE = 'ACTIVE';
    const UPDATE_STATUS_UNSUBSCRIBED = 'UNSUBSCRIBED';
    const UPDATE_STATUS_NON_SUBSCRIBED = 'NON-SUBSCRIBED';

    #Wocoomerce order statuses
    const ORDER_ON_HOLD = 'wc-on-hold';
    const ORDER_PENDING_PAYMENT = 'wc-pending';
    const ORDER_COMPLETED = 'wc-completed';
    const ORDER_PAID = 'wc-processing';

    #Used for updating sender carts
    const CONVERTED_CART = '2';
    const UNPAID_CART = '3';

    #POST_META
    CONST SENDER_CART_META = 'sender_remote_id';

    const ORDER_NOT_PAID_STATUSES = [
        self::ORDER_ON_HOLD,
        self::ORDER_PENDING_PAYMENT
    ];

    const TRANSIENT_LOG_IN = 'sender_user_logged_in';
    const TRANSIENT_LOG_OUT = 'sender_user_logged_out';
    const TRANSIENT_RECOVER_CART = 'sender_recovered_cart';
    const TRANSIENT_SYNC_FINISHED = 'sender_sync_finished';
    const TRANSIENT_SYNC_IN_PROGRESS = 'sender_sync_in_progress';
    const TRANSIENT_PREPARE_CONVERT = 'sender_prepare_convert';
    const TRANSIENT_SENDER_X_RATE = 'sender_api_rate_limited';

    const SENDER_JS_FILE_NAME = 'sender-wordpress-plugin';

    public static function handleChannelStatus($sender_newsletter = null)
    {
        if (is_array($sender_newsletter) && isset($sender_newsletter['state'])) {
            return $sender_newsletter['state'] === self::SUBSCRIBED ? 1 : 0;
        } else {
            return (int)$sender_newsletter === 1 ? self::SUBSCRIBED : ((int)$sender_newsletter === 0 ? self::UNSUBSCRIBED : self::NOT_SUBSCRIBED);
        }
    }

    public static function generateEmailMarketingConsent($status = null)
    {
        if (!$status) {
            $status = self::handleChannelStatus($status);
        }

        return [
            'state' => $status,
            'opt_in_level' => 'single_opt_in',
            'consent_updated_at' => current_time('Y-m-d H:i:s'),
        ];
    }

    public static function shouldChangeChannelStatus($objectId, $type)
    {
        if ($type === 'user') {
            $emailConsent = get_user_meta($objectId, self::EMAIL_MARKETING_META_KEY, true);
        } elseif ($type === 'order') {
            $emailConsent = get_post_meta($objectId, self::EMAIL_MARKETING_META_KEY, true);
        }

        if (isset($emailConsent['state']) && $emailConsent['state'] === self::SUBSCRIBED) {
            return true;
        }

        #Check for old sender_newsletter if email_marketing_consent is not found
        if ($type === 'user') {
            $oldSenderNewsletter = (int)get_user_meta($objectId, 'sender_newsletter', true);
            if ($oldSenderNewsletter === 1) {
                return true;
            }
        }

        if ($type === 'order') {
            $oldSenderNewsletter = (int)get_post_meta($objectId, 'sender_newsletter', true);
            if ($oldSenderNewsletter === 1) {
                return true;
            }
        }

        return false;
    }

    public static function senderIsWooEnabled()
    {
        include_once(ABSPATH . 'wp-admin/includes/plugin.php');
        return is_plugin_active('woocommerce/woocommerce.php');
    }

    public static function columnExists($table, $column)
    {
        global $wpdb;
        return (bool) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                 WHERE table_name = %s AND column_name = %s",
                $table,
                $column
            )
        );
    }

    public static function normalizeIpToIpv4( string $ip ): string
    {
        if (strpos($ip, '::ffff:') === 0) {
            $maybeIpv4 = substr($ip, 7);
            if (filter_var($maybeIpv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return $maybeIpv4;
            }
        }

        return $ip;
    }

    public static function getProductImageUrl(WC_Product $product, $size='woocommerce_thumbnail'): string {
        $img_id = $product->get_image_id();

        if(empty($img_id) && $product->is_type('variation')){
            $parent = wc_get_product($product->get_parent_id());
            if($parent){
                $img_id = $parent->get_image_id();
            }
        }

        if(empty($img_id)){
            $gallery_ids = $product->get_gallery_image_ids();
            if(empty($gallery_ids) && $product->is_type('variation')){
                $parent = isset($parent) ? $parent : wc_get_product($product->get_parent_id());
                if($parent){
                    $gallery_ids = $parent->get_gallery_image_ids();
                }
            }
            if(!empty($gallery_ids)){
                $img_id = $gallery_ids[0];
            }
        }

        if(!empty($img_id)){
            $src = wp_get_attachment_image_src($img_id, $size);
            if(is_array($src) && !empty($src[0])){
                return (string)$src[0];
            }
            $raw = wp_get_attachment_url($img_id);
            if($raw){
                return (string)$raw;
            }
        }

        if(function_exists('wc_placeholder_img_src')){
            return (string)wc_placeholder_img_src($size);
        }

        return '';
    }

    public static function getProductShortText(WC_Product $product, int $maxLen = 300) : string {
        $text = $product->get_short_description();
        if (!is_string($text) || $text === '' ) {
            $text = $product->get_description();
        }

        if ((!is_string($text) || $text === '') && $product->is_type('variation')) {
            $parent = wc_get_product($product->get_parent_id());
            if ( $parent ) {
                $text = $parent->get_short_description();
                if (!is_string($text) || $text === '') {
                    $text = $parent->get_description();
                }
            }
        }

        if (!is_string($text)) {
            $text = '';
        }

        $text = strip_shortcodes($text);
        $text = wp_strip_all_tags($text, true);
        $text = html_entity_decode($text,ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $text = trim(preg_replace('/\s+/', ' ', $text));

        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($text, 'UTF-8') > $maxLen) {
                $text = mb_substr($text, 0, $maxLen, 'UTF-8') . '…';
            }
        } else {
            if (strlen($text) > $maxLen) {
                $text = substr($text, 0, $maxLen) . '…';
            }
        }

        return $text;
    }


}
