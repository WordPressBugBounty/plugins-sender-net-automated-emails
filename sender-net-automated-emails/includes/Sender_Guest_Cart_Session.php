<?php
if (!defined('ABSPATH')) exit;

/** Share only Sender's cart binding between requests that loaded the same Woo guest session. */
final class Sender_Guest_Cart_Session
{
    private const REVISION = 'sender_cart_binding_revision';
    private const RESERVED = 'sender_cart_reserved_at';
    private const KEYS = [
        Sender_Carts::SENDER_ACTIVE_CART_SESSION_KEY, Sender_Carts::SENDER_OWNED_CART_SESSION_KEY,
        Sender_Carts::SENDER_RECOVERED_CART_SESSION_KEY, Sender_Carts::SENDER_PENDING_CART_TRACK_SESSION_KEY,
        Sender_Carts::SENDER_PREVIOUS_CARTS_SESSION_KEY, self::RESERVED,
    ];

    private $running = false;
    private $late = false;

    private function guestCookie($session)
    {
        if (is_user_logged_in() || !$session instanceof WC_Session_Handler) return null;
        $cookie = $session->get_session_cookie();
        if (!$cookie || (string) $cookie[0] !== (string) $session->get_customer_id() || (int) $cookie[1] <= time()) return null;
        return $cookie;
    }

    private function lockKey($customerId): string
    {
        global $wpdb;
        return 'sender_cart_' . substr(hash_hmac('sha256', $wpdb->prefix . '|' . $customerId, wp_salt('auth')), 0, 48);
    }

    /** Read the latest committed proof even when another request is still working. */
    public function refresh($session): void
    {
        $cookie = $this->guestCookie($session);
        if ($cookie && !$this->running) $this->loadBinding($session, $this->lockKey($cookie[0]));
    }

    private function loadBinding($session, $key)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'woocommerce_sessions';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT wc.session_id, binding.session_value
             FROM {$table} wc LEFT JOIN {$table} binding
               ON binding.session_key = %s AND binding.session_expiry > %d
             WHERE wc.session_key = %s AND wc.session_expiry > %d",
            'snd:' . substr($key, -28), time(), $session->get_customer_id(), time()
        ));
        if (!$row || $row->session_value === null) return $row;
        $saved = json_decode($row->session_value, true);
        // Destroying and recreating Woo's session invalidates its former binding.
        if (($saved['session_id'] ?? null) === (int) $row->session_id
            && ($saved['revision'] ?? 0) > (int) $session->get(self::REVISION)) {
            foreach (self::KEYS as $field) {
                $session->set($field, $saved['values'][$field] ?? null);
            }
            $session->set(self::REVISION, $saved['revision']);
        }
        return $row;
    }

    public function run($session, callable $operation)
    {
        $cookie = $this->guestCookie($session);
        if ($this->running || !$cookie) return $operation();
        global $wpdb;
        $key = $this->lockKey($cookie[0]);
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 10)', $key)) !== 1) {
            return false;
        }
        $this->running = true;
        try {
            // Woo loads a snapshot before these hooks. Keep the Sender binding in a
            // separate expiring row so a later Woo session save cannot overwrite it.
            $table = $wpdb->prefix . 'woocommerce_sessions';
            $bindingKey = 'snd:' . substr($key, -28);
            $started = $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true);
            $seenReservation = (float) $session->get(self::RESERVED);
            $row = $this->loadBinding($session, $key);
            if ($wpdb->last_error) return false;
            // A valid cookie without its native session cannot publish a shared binding.
            if (!$row) return false;
            $reservation = (float) $session->get(self::RESERVED);
            $this->late = $reservation > $started || $reservation > $seenReservation;
            $persisted = json_decode((string) $row->session_value, true);
            $before = $this->values($session);
            try {
                $result = $operation();
            } finally {
                $after = $this->values($session);
                // A previous failed write may leave unchanged in-memory values unsaved.
                if (($persisted !== null || array_filter($before) || array_filter($after))
                    && (($persisted['session_id'] ?? null) !== (int) $row->session_id
                        || ($persisted['values'] ?? null) !== $after)) {
                    $revision = (int) $session->get(self::REVISION) + 1;
                    $written = $wpdb->query($wpdb->prepare(
                        "INSERT INTO {$table} (session_key, session_value, session_expiry) VALUES (%s,%s,%d)
                         ON DUPLICATE KEY UPDATE session_value = VALUES(session_value), session_expiry = VALUES(session_expiry)",
                        $bindingKey, wp_json_encode(['session_id' => (int) $row->session_id,
                            'revision' => $revision, 'values' => $after]), (int) $cookie[1]
                    ));
                    if ($written === false) {
                        // This cart has never been sent. Discard its failed allocation
                        // before another request can choose a cart for this session.
                        if (!empty($result['created']) && isset($result['cart'])) {
                            $result['cart']->deleteIfOpen();
                        }
                        foreach ($before as $field => $value) $session->set($field, $value);
                        $result = false;
                    } else {
                        $session->set(self::REVISION, $revision);
                    }
                }
            }
            return $result;
        } finally {
            $this->running = false;
            $this->late = false;
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $key));
        }
    }

    public function cartUpdateIsLate(): bool
    {
        return $this->running && $this->late;
    }

    public function reserved($session): void
    {
        if ($this->running) $session->set(self::RESERVED, microtime(true));
    }

    private function values($session): array
    {
        $values = [];
        foreach (self::KEYS as $field) $values[$field] = $session->get($field);
        return $values;
    }
}
