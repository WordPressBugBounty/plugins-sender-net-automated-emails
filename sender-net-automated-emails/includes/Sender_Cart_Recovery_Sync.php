<?php

if (!defined('ABSPATH')) {
    exit;
}

/** Migrate existing carts after upgrading, without replaying cart activity events. */
class Sender_Cart_Recovery_Sync
{
    const ACTION = 'sender_sync_cart_recovery_urls';
    const STATE = 'sender_cart_recovery_sync';
    private $api;

    public function __construct($api)
    {
        $this->api = $api;
        add_action('init', [$this, 'initialize']);
        add_action('add_option_sender_store_register', [$this, 'initialize'], 10, 0);
        add_action('update_option_sender_store_register', [$this, 'initialize'], 10, 0);
        add_action('update_option_sender_account_disconnected', [$this, 'initialize'], 10, 0);
        add_action(self::ACTION, [$this, 'process']);
    }

    private function connected()
    {
        return get_option('sender_api_key') && get_option('sender_store_register')
            && !get_option('sender_account_disconnected') && function_exists('wc_get_cart_url');
    }

    public static function getStatus()
    {
        $state = maybe_unserialize(self::getStateValue());
        $matchesStore = is_array($state) && ($state['store'] ?? '') === (string) get_option('sender_store_register');
        $done = $matchesStore && !empty($state['done']);
        $connected = get_option('sender_api_key') && get_option('sender_store_register')
            && !get_option('sender_account_disconnected');
        $next = wp_next_scheduled(self::ACTION);
        $running = $matchesStore && !empty($state['started_at']) && time() - $state['started_at'] < 120;
        $attention = !$done && !$running && (!$next || $next < time() - 300 || !empty($state['failed']));
        return [
            'status' => $done ? 'completed' : (!$connected ? 'paused' : ($attention ? 'attention' : 'running')),
            'done' => $done,
            'can_resume' => !$done && $connected && $attention,
            'sent' => $matchesStore ? (int) ($state['sent'] ?? 0) : 0,
            'sent_known' => $matchesStore && array_key_exists('sent', $state),
        ];
    }

    public function resume()
    {
        if (!$this->connected() || !self::getStatus()['can_resume']) {
            return false;
        }
        $previous = self::getStateValue();
        $state = maybe_unserialize($previous);
        if (is_array($state)) {
            if (!empty($state['done']) || (!empty($state['started_at']) && time() - $state['started_at'] < 120)) {
                return false;
            }
            unset($state['failed'], $state['started_at']);
            $state['attempt_id'] = wp_generate_uuid4();
            if (!self::replaceState($previous, $state)) {
                return false;
            }
        }
        wp_clear_scheduled_hook(self::ACTION);
        $this->initialize();
        return (bool) wp_next_scheduled(self::ACTION);
    }

    public function initialize()
    {
        if (!$this->connected()) {
            return;
        }
        $store = (string) get_option('sender_store_register');
        $previous = self::getStateValue();
        $state = maybe_unserialize($previous);
        if (!is_array($state) || ($state['store'] ?? '') !== $store
            || (empty($state['done']) && ($state['source'] ?? '') !== 'sender')) {
            $state = ['store' => $store, 'source' => 'sender', 'cursor' => null,
                'done' => false, 'sent' => 0, 'created_at' => time()];
            if (!self::replaceState($previous, $state)) {
                return;
            }
        }
        if (!$state['done']) {
            $this->schedule(10);
        }
    }

    public function process()
    {
        if (!$this->connected()) {
            return;
        }
        $previous = self::getStateValue();
        $state = maybe_unserialize($previous);
        if (!is_array($state) || $state['store'] !== (string) get_option('sender_store_register') || $state['done']) {
            return;
        }
        if (!empty($state['started_at']) && time() - $state['started_at'] < 120) {
            $this->schedule(300);
            return;
        }
        unset($state['failed']);
        $state['started_at'] = time();
        $state['last_attempt_at'] = $state['started_at'];
        $state['attempt_id'] = wp_generate_uuid4();
        if (!self::replaceState($previous, $state)) {
            $this->schedule(300);
            return;
        }
        // Leave a retry scheduled even if this request terminates before the API responds.
        $this->schedule(300);
        global $wpdb;
        $page = $this->api->senderGetCartRecoveryCandidates($state['cursor'] ?? null);
        if ($page === false) {
            $this->failed($state, 'candidates');
            return;
        }
        $carts = [];
        foreach ($page['ids'] as $id) {
            $cart = (new Sender_Cart())->find($id);
            if ($wpdb->last_error !== '') {
                $this->failed($state, 'cart_lookup');
                return;
            }
            if (!$cart || (int) $cart->cart_status !== 0) {
                continue;
            }
            $user = (new Sender_User())->find($cart->user_id);
            if ($wpdb->last_error !== '') {
                $this->failed($state, 'owner_lookup');
                return;
            }
            $email = $user ? (string) $user->email : '';
            // Match the API limit: malformed local data must not block the rest of a page.
            if ($user && strlen($email) <= 255 && is_email($email)) {
                $carts[] = ['id' => (string) $cart->id, 'email' => $email, 'url' => $cart->getRecoveryUrl()];
            }
        }
        if ($carts && !$this->api->senderSyncCartRecoveryUrls(['event_id' => wp_generate_uuid4(), 'carts' => $carts])) {
            $this->failed($state, 'submission');
            return;
        }
        // Only the attempt that claimed this page can publish its progress.
        $claimed = maybe_serialize($state);
        $state['cursor'] = $page['cursor'];
        $state['done'] = $page['cursor'] === null;
        $state['sent'] = (int) ($state['sent'] ?? 0) + count($carts);
        if ($state['done']) {
            $state['completed_at'] = time();
        }
        unset($state['started_at'], $state['attempt_id'], $state['last_error']);
        if (!self::replaceState($claimed, $state)) {
            $this->schedule(300);
            return;
        }
        wp_clear_scheduled_hook(self::ACTION);
        if (!$state['done']) {
            $this->schedule(10);
        }
    }

    private function failed($state, $step)
    {
        $claimed = maybe_serialize($state);
        $state['failed'] = true;
        $state['last_error'] = ['step' => $step] + (in_array($step, ['candidates', 'submission'], true)
            ? $this->api->getCartRecoveryRequestError() : ['reason' => 'database_error']);
        unset($state['started_at'], $state['attempt_id']);
        self::replaceState($claimed, $state);
        $this->schedule(300);
    }

    private static function getStateValue()
    {
        global $wpdb;
        // Bypass per-request and persistent option caches when coordinating workers.
        return $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::STATE
        ));
    }

    private static function replaceState($previous, array $state)
    {
        global $wpdb;
        if ($previous === null) {
            $changed = $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
                self::STATE, maybe_serialize($state)
            ));
        } else {
            $changed = $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = %s, autoload = 'no' WHERE option_name = %s AND BINARY option_value = %s",
                maybe_serialize($state), self::STATE, $previous
            ));
        }
        wp_cache_delete(self::STATE, 'options');
        foreach (['alloptions', 'notoptions'] as $key) {
            $cached = wp_cache_get($key, 'options');
            if (is_array($cached) && array_key_exists(self::STATE, $cached)) {
                wp_cache_delete($key, 'options');
            }
        }
        return $changed === 1;
    }

    private function schedule($delay)
    {
        if (!wp_next_scheduled(self::ACTION)) {
            wp_schedule_single_event(time() + $delay, self::ACTION);
        }
    }
}
