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
    const TRANSIENT_PREPARE_CONVERT = 'sender_prepare_convert';

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

}
