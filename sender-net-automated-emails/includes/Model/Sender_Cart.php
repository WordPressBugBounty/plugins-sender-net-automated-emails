<?php
if (!defined('ABSPATH')) {
	exit;
}

if (!class_exists('Sender_Model')) {
	require_once 'Sender_Model.php';
}

class Sender_Cart extends Sender_Model
{
	protected $tableName = 'sender_automated_emails_carts';

	protected $id;

	protected $user_id;

	protected $cart_data;

	protected $cart_recovered;

	protected $cart_status;

    protected $updated;

    /** Delete only if this cart is still open and belongs to the same owner. */
    public function deleteIfOpen(): bool
    {
        global $wpdb;
        return $wpdb->delete($this->getTableName(), [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'cart_status' => 0,
        ], ['%d', '%d', '%d']) === 1;
    }

    /** Recovery links are bearer credentials bound to this site, cart and owner. */
    public function getRecoveryToken()
    {
        $message = 'sender-cart-recovery|' . get_current_blog_id() . '|' . $this->id . '|' . $this->user_id;
        return $this->id . '.' . hash_hmac('sha256', $message, wp_salt('auth'));
    }

    public function getRecoveryUrl()
    {
        return add_query_arg('hash', $this->getRecoveryToken(), wc_get_cart_url());
    }
}
