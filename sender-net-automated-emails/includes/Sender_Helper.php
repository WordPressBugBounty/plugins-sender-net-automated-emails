<?php

if (!defined('ABSPATH')) {
    exit;
}

class Sender_Helper
{
    public static function submittedNewsletterConsent(): ?bool
    {
        if (!isset($_POST['sender_newsletter']) || !is_scalar($_POST['sender_newsletter'])) {
            return null;
        }
        if ((string) $_POST['sender_newsletter'] === '1') {
            return true;
        }
        // Classic checkout always posts a hidden zero, even for an untouched box.
        return isset($_POST['sender_newsletter_changed']) && $_POST['sender_newsletter_changed'] === '1'
            ? false : null;
    }

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

    const SENDER_CART_DATA = '_sender_cart_data';

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
    const TRANSIENT_SENDER_THANK_YOU = 'sender_thankyou_seen_';

    const SYNC_STATE_OPTION = 'sender_sync_state';
    const SYNC_WORKER_DEBUG_OPTION = 'sender_sync_worker_debug';
    const SYNC_SCHEDULE_DEBUG_OPTION = 'sender_sync_schedule_debug';

    public static function getSyncState(): array
    {
        $state = maybe_unserialize(self::getSyncStateValue());
        if (is_array($state) && !empty($state['status'])) {
            return $state;
        }

        // Compatibility with exports started before this update.
        return [
            'status' => get_transient(self::TRANSIENT_SYNC_IN_PROGRESS) ? 'running'
                : ((get_option(self::TRANSIENT_SYNC_FINISHED, false) || get_transient(self::TRANSIENT_SYNC_FINISHED)) ? 'completed' : 'unknown'),
            'stage' => '',
        ];
    }

    private static function getSyncStateValue()
    {
        global $wpdb;
        // Read the worker's latest state, including on sites with persistent object caches.
        return $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            self::SYNC_STATE_OPTION
        ));
    }

    public static function queueSyncJob($delay, $existingOnly = false)
    {
        $previous = self::getSyncStateValue();
        $state = maybe_unserialize($previous);
        if ($existingOnly && (!is_array($state) || ($state['status'] ?? '') !== 'queued')) {
            return false;
        }
        if (is_array($state) && in_array($state['status'] ?? '', ['running', 'cancelling'], true)) {
            return false;
        }
        if (is_array($state) && ($state['status'] ?? '') === 'queued') {
            return $state;
        }
        $state = [
            'status' => 'queued',
            'job_id' => wp_generate_uuid4(),
            'stage' => '',
            'not_before' => time() + max(0, (int) $delay),
            'updated_at' => current_time('mysql'),
        ];
        if ($previous === null) {
            return add_option(self::SYNC_STATE_OPTION, $state, '', false) ? $state : false;
        }
        return self::replaceSyncState($previous, $state) ? $state : false;
    }

    private static function replaceSyncState($previous, array $state): bool
    {
        global $wpdb;
        $changed = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND BINARY option_value = %s",
            maybe_serialize($state), self::SYNC_STATE_OPTION, $previous
        ));
        if ($changed !== 1) {
            return false;
        }
        wp_cache_delete(self::SYNC_STATE_OPTION, 'options');
        return true;
    }

    public static function claimSyncJob(&$reason = null)
    {
        $previous = self::getSyncStateValue();
        $state = maybe_unserialize($previous);
        if (!is_array($state) || ($state['status'] ?? '') !== 'queued') {
            $reason = 'state_not_queued';
            return false;
        }
        if (($state['not_before'] ?? 0) > time()) {
            $reason = 'not_due_yet';
            return false;
        }

        $state['job_id'] = $state['job_id'] ?? wp_generate_uuid4();
        $state['status'] = 'running';
        $state['stage'] = 'starting';
        $state['updated_at'] = current_time('mysql');
        // Compare-and-swap: cron, multiple tabs, and AJAX must not start the same job twice.
        $claimed = self::replaceSyncState($previous, $state);
        global $wpdb;
        $reason = $claimed ? 'claimed' : ($wpdb->last_error ? 'database_error' : 'state_changed');
        return $claimed ? $state : false;
    }

    public static function cancelSyncJob($jobId): bool
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $previous = self::getSyncStateValue();
            $state = maybe_unserialize($previous);
            if (!is_array($state) || ($state['job_id'] ?? '') !== $jobId) {
                return false;
            }
            if (in_array($state['status'], ['cancelled', 'cancelling'], true)) {
                return true;
            }
            if (!in_array($state['status'], ['queued', 'running'], true)) {
                return false;
            }
            if ($state['status'] === 'queued') {
                // Clear before releasing the queued state, so a new sync's event
                // cannot be removed by a late cancellation request.
                wp_clear_scheduled_hook('sender_export_shop_data_cron');
            }
            $state['status'] = $state['status'] === 'queued' ? 'cancelled' : 'cancelling';
            $state['updated_at'] = current_time('mysql');
            if (self::replaceSyncState($previous, $state)) {
                return true;
            }
        }
        return false;
    }

    public static function updateClaimedSyncJob(array $workerState): array
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $previous = self::getSyncStateValue();
            $state = maybe_unserialize($previous);
            if (!is_array($state) || ($state['job_id'] ?? null) !== ($workerState['job_id'] ?? null)
                || !in_array($state['status'], ['running', 'cancelling'], true)) {
                throw new \RuntimeException('Sync worker no longer owns this job.', 499);
            }
            if ($state['status'] === 'cancelling') {
                if (!in_array($workerState['status'], ['failed', 'cancelled'], true)) {
                    throw new \RuntimeException('Sync cancellation requested.', 499);
                }
                $workerState['status'] = 'cancelled';
            }
            // Identical same-second heartbeats need no database write.
            if (maybe_serialize($workerState) === $previous || self::replaceSyncState($previous, $workerState)) {
                return $workerState;
            }
        }
        throw new \RuntimeException('Unable to persist sync progress.');
    }

    public static function getSyncDiagnostics(array $state): array
    {
        $now = time();
        $scheduled = wp_next_scheduled('sender_export_shop_data_cron');
        $lock = get_transient('doing_cron');
        return [
            'checked_at_utc' => gmdate('Y-m-d H:i:s', $now),
            'eligible_at_utc' => isset($state['not_before']) ? gmdate('Y-m-d H:i:s', $state['not_before']) : null,
            'scheduled_at_utc' => $scheduled ? gmdate('Y-m-d H:i:s', $scheduled) : null,
            'overdue_seconds' => $scheduled ? max(0, $now - $scheduled) : null,
            'wp_cron_disabled' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
            'alternate_wp_cron' => defined('ALTERNATE_WP_CRON') && ALTERNATE_WP_CRON,
            'cron_lock_age_seconds' => is_numeric($lock) ? max(0, (int) floor(microtime(true) - (float) $lock)) : null,
            'cron_lock_timeout_seconds' => defined('WP_CRON_LOCK_TIMEOUT') ? WP_CRON_LOCK_TIMEOUT : 60,
            // This is the last callback attempt, possibly from an earlier sync.
            'last_worker_attempt' => get_option(self::SYNC_WORKER_DEBUG_OPTION, null),
            'last_schedule_attempt' => get_option(self::SYNC_SCHEDULE_DEBUG_OPTION, null),
        ];
    }

    public static function isSyncRunning(): bool
    {
        return in_array(self::getSyncState()['status'], ['queued', 'running', 'cancelling'], true);
    }

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
