<?php

if (!defined('ABSPATH')) {
    exit;
}

/** Browser permissions only. Payment callbacks and cron do not depend on these checks. */
class Sender_Order_Confirmation
{
    public static function canView($order): bool
    {
        if (!self::hasOrderAccess($order)) {
            return false;
        }

        // Blocks returns immediately for registered orders once account access is checked.
        if (self::usesConfirmationBlocks()) {
            return (bool) $order->get_customer_id() || !self::blocksGuestNeedsVerification($order);
        }

        $email = self::verifiedEmail('check_submission', ['wc_verify_email']);
        if (is_callable(['Automattic\\WooCommerce\\Internal\\Utilities\\Users', 'should_user_verify_order_email'])) {
            return !\Automattic\WooCommerce\Internal\Utilities\Users::should_user_verify_order_email(
                $order->get_id(), $email, 'order-received'
            );
        }

        // Compatibility for WooCommerce versions without the shared verification utility.
        if (!$order->get_billing_email() || ((int) $order->get_customer_id() === get_current_user_id() && is_user_logged_in())
            || self::withinGracePeriod($order)) {
            return true;
        }
        $session = WC()->session;
        $customer = $session instanceof WC_Session ? $session->get('customer') : null;
        $sessionEmail = is_array($customer) && is_string($customer['email'] ?? null) ? $customer['email'] : '';
        $required = !($sessionEmail && strcasecmp($sessionEmail, $order->get_billing_email()) === 0)
            && !($email && strcasecmp($email, $order->get_billing_email()) === 0)
            && !current_user_can('read_private_shop_orders');
        return !apply_filters('woocommerce_order_email_verification_required', $required, $order, 'order-received');
    }

    public static function canConfirm($order): bool
    {
        $receipt = $_GET['sender_confirmation'] ?? null;
        // The receipt is issued only after the page passes WooCommerce verification.
        // It is not accepted by canView(), so it cannot bypass a later verification page.
        return self::hasOrderAccess($order) && is_string($receipt)
            && (bool) wp_verify_nonce(wp_unslash($receipt), self::nonceAction($order));
    }

    public static function confirmationNonce($order): string
    {
        return wp_create_nonce(self::nonceAction($order));
    }

    private static function nonceAction($order): string
    {
        return 'sender_confirm_order_' . $order->get_id() . '_' . hash('sha256',
            $order->get_order_key() . '|' . $order->get_customer_id() . '|' . $order->get_billing_email());
    }

    private static function hasOrderAccess($order): bool
    {
        $key = $_GET['key'] ?? null;
        if (!$order || !is_string($key) || $key === '' || !hash_equals($order->get_order_key(), wp_unslash($key))) {
            return false;
        }
        $customerId = (int) $order->get_customer_id();
        return !$customerId || !apply_filters('woocommerce_order_received_verify_known_shoppers', true)
            || $customerId === get_current_user_id();
    }

    private static function usesConfirmationBlocks(): bool
    {
        global $_wp_current_template_content, $_wp_current_template_id;
        // Checkout Blocks can still render the Classic confirmation shortcode. Inspect
        // the resolved confirmation template, rather than the checkout page setting.
        $content = is_string($_wp_current_template_content) ? $_wp_current_template_content : '';
        $post = get_post();
        if ($post) $content .= $post->post_content;
        if (strpos($content, 'wp:woocommerce/order-confirmation-') !== false) return true;
        if (strpos($content, '[woocommerce_checkout') !== false) return false;
        // Patterns and template parts can hold the confirmation blocks outside
        // the top-level content. Do not fall back to weaker Classic session rules.
        return (is_string($_wp_current_template_id) && substr($_wp_current_template_id, -20) === '//order-confirmation')
            || (function_exists('wp_is_block_theme') && wp_is_block_theme());
    }

    private static function withinGracePeriod($order): bool
    {
        $grace = (int) apply_filters('woocommerce_order_email_verification_grace_period',
            10 * MINUTE_IN_SECONDS, $order, 'order-received');
        $created = $order->get_date_created();
        return $created instanceof WC_DateTime && time() - $created->getTimestamp() <= $grace;
    }

    private static function blocksGuestNeedsVerification($order): bool
    {
        $session = WC()->session;
        if ($session instanceof WC_Session && $order->get_id() === (int) $session->get('store_api_draft_order')) {
            return false;
        }
        if (self::withinGracePeriod($order)) {
            return false;
        }
        $email = self::verifiedEmail('_wpnonce', ['wc_verify_email', 'wc_create_account']);
        if ($order->get_billing_email() && $email === $order->get_billing_email()) {
            return false;
        }
        return (bool) apply_filters('woocommerce_order_email_verification_required', true, $order, 'order-received');
    }

    private static function verifiedEmail(string $field, array $actions): ?string
    {
        $nonce = $_POST[$field] ?? null;
        $email = $_POST['email'] ?? null;
        if (!is_string($nonce) || !is_string($email)) {
            return null;
        }
        foreach ($actions as $action) {
            if (wp_verify_nonce(sanitize_key(wp_unslash($nonce)), $action)) {
                return sanitize_email(wp_unslash($email));
            }
        }
        return null;
    }
}
