<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once 'Sender_Helper.php';
require_once __DIR__ . '/Sender_Order_Confirmation.php';
require_once __DIR__ . '/Sender_Guest_Cart_Session.php';

class Sender_Carts
{
    private $sender;
    private $guestSession;
    private $senderUserId = false;
    private $inSenderCartUpdated = false;
    private $renderedConvertCartScripts = [];
    private $preparedOrderId = 0;

    const TRACK_CART = 'sender-track-cart';
    const UPDATE_CART = 'sender-update-cart';

    const FRAGMENTS_FILTERS = [
            'woocommerce_add_to_cart_fragments',
            'woocommerce_update_order_review_fragments'
    ];

    const SENDER_SUBSCRIBER_ID = 'sender_subscriber_id';
    const SENDER_ACTIVE_CART_SESSION_KEY = 'sender_active_cart_id';
    const SENDER_RECOVERED_CART_SESSION_KEY = 'sender_verified_recovery';
    const SENDER_OWNED_CART_SESSION_KEY = 'sender_owned_cart';
    const SENDER_ORDER_CART_PROOF_META = '_sender_verified_session_cart';
    const SENDER_PENDING_CART_TRACK_SESSION_KEY = 'sender_pending_cart_track';
    const SENDER_PREVIOUS_CARTS_SESSION_KEY = 'sender_previous_carts';
    const NEWSLETTER_CHECKBOX_ID = 'sender-newsletter-checkbox-subscribe';
    const NEWSLETTER_BLOCK_NAME = 'sender-net-automated-emails/subscribe-newsletter-block';
    const CHECKOUT_CONTACT_BLOCK_NAME = 'woocommerce/checkout-contact-information-block';

    public function __construct($sender)
    {
        $this->sender = $sender;
        $this->guestSession = new Sender_Guest_Cart_Session();

        $this->senderAddCartsActions()
                ->senderAddCartsFilters();
    }

    private function senderAddCartsActions()
    {
        //Handle cart changes and convert
        add_action('woocommerce_checkout_create_order', [$this, 'senderBindSessionCartToOrder'], 20, 1);
        add_action('woocommerce_store_api_checkout_update_order_meta', [$this, 'senderBindSessionCartToOrder'], 20, 1);
        add_action('woocommerce_checkout_order_processed', [$this, 'senderLoadOrderForConvert'], 10, 1);
        add_action('woocommerce_order_status_changed', [$this, 'senderUpdateOrderStatus'], 10, 3);
        add_action('woocommerce_payment_complete', [$this, 'senderHandlePaidOrder'], 10, 1);
        add_action('woocommerce_subscription_payment_complete', [$this, 'senderHandlePaidOrder'], 10, 1);
        add_action('woocommerce_subscription_renewal_payment_complete', [$this, 'senderHandleRenewalPaymentComplete'], 10, 2);

        add_action('woocommerce_store_api_checkout_order_processed', [$this, 'prepareConvertCart']);
        add_action('woocommerce_cart_updated', [$this, 'senderCartUpdated']);

        // Normal flow: thankyou page
        add_action('woocommerce_thankyou', [$this, 'senderConvertThankYouCart'], 5, 1);
        add_action('woocommerce_thankyou', [$this, 'outputConvertCartScript'], 20, 1);

        // AJAX ping to confirm JS executed
        add_action('wp_ajax_nopriv_thankyou_seen', [$this, 'senderMarkThankYouSeen']);
        add_action('wp_ajax_thankyou_seen', [$this, 'senderMarkThankYouSeen']);

        // Scheduled check
        add_action('sender_check_thankyou_seen', [$this, 'runFallbackIfNotSeen']);

        //Adding subscribe to newsletter checkbox
        add_action('woocommerce_review_order_before_submit', [$this, 'senderAddNewsletterCheck'], 10);
        add_action('woocommerce_edit_account_form', [$this, 'senderAddNewsletterCheck']);
        add_action('woocommerce_register_form', [$this, 'senderAddNewsletterCheck']);

        //Handle sender_newsletter on create/update account
        add_action('sender_track_user_action', [$this, 'trackUser']);
        add_action('woocommerce_save_account_details', [$this, 'trackUser'], 10, 1);

        //Handle admin order edit subscribe to newsletter
        add_action('woocommerce_admin_order_data_after_shipping_address', [$this, 'senderAddNewsletterCheck']);

        //Subscribe to newsletter block checkout page
        add_action('wp_enqueue_scripts', [$this, 'senderSubscribeNewsletterBlockEnqueueAssets']);
        add_action('enqueue_block_editor_assets', [$this, 'senderSubscribeNewsletterBlockEnqueueAssets']);
        add_filter('render_block', [$this, 'senderRenderCheckoutNewsletter'], 10, 2);

        //Capture email when filling checkout details
        add_action('wp_ajax_trigger_backend_hook', [$this,'triggerEmailCheckout']);
        add_action('wp_ajax_nopriv_trigger_backend_hook', [$this,'triggerEmailCheckout']);

        //Recovered cart visit
        add_action('wp_head', [$this, 'outputSenderTrackVisitorsScript']);

        return $this;
    }

    private function senderAddCartsFilters()
    {
        add_filter('template_include', [&$this, 'senderRecoverCart'], 99, 1);
        add_filter('template_include', [$this, 'senderInjectThankYouScript'], 999);
        return $this;
    }

    public function senderWpNewsletterHandle($userId, ?bool $newsletter = null):void
    {
        if ($newsletter === null) {
            $newsletter = Sender_Helper::submittedNewsletterConsent();
        }
        // An untouched unchecked box does not revoke consent given elsewhere.
        if ($newsletter === null) {
            return;
        }
        $user = get_userdata($userId);
        if (! $user || empty($user->user_email)) {
            return;
        }
        $email = strtolower($user->user_email);

        $consentStatus = $newsletter ? Sender_Helper::SUBSCRIBED : Sender_Helper::UNSUBSCRIBED;
        update_user_meta(
                $userId,
                Sender_Helper::EMAIL_MARKETING_META_KEY,
                Sender_Helper::generateEmailMarketingConsent($consentStatus)
        );
        $payload = [
                'subscriber_status' => $newsletter ? Sender_Helper::UPDATE_STATUS_ACTIVE : Sender_Helper::UPDATE_STATUS_UNSUBSCRIBED,
        ];
        // Keep the legacy fallback consistent with the explicit choice as well.
        update_user_meta($userId, 'sender_newsletter', $newsletter ? 1 : 0);

        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action(
                    'sender_update_customer_background',
                    [$email, $payload]
            );
        }
    }

    public function handleGuestConvertCart($order, bool $updateCart = true)
    {
        $billingEmail = $order->get_billing_email();
        if (!empty($billingEmail)) {
            $senderUser = (new Sender_User())->findBy('email', $billingEmail);

            if (!$senderUser){
                $senderUser = new Sender_User();
                $senderUser->email = $billingEmail;
                $senderUser->save();
            }

            if (!empty($_POST['sender_newsletter'])) {
                $newsletter = true;
            }

            $this->senderUserId = $senderUser->id;

            $visitorData = [
                    'email' => $order->get_billing_email(),
                    'firstname' => $order->get_billing_first_name(),
                    'lastname' => $order->get_billing_last_name(),
                    'phone' => $order->get_billing_phone(),
            ];

            if(isset($newsletter)){
                $visitorData['newsletter'] = $newsletter;
            }

            $this->sender->senderApi->senderTrackNotRegisteredUsers($visitorData);
            // Checkout may update a cart that this session already owns. If capture has
            // never tracked one, it can stay local until the customer pays.
            if ($updateCart) {
                if (is_user_logged_in()) {
                    $this->senderCartUpdated();
                } else {
                    $update = $this->withGuestCartSession(function () {
                        return $this->updateSessionCart(true);
                    });
                    $this->sendShoppingCart($update);
                }
            }

            return true;
        }else{
            return false;
        }
    }

    public function senderLoadOrderForConvert($orderId)
    {
        $order = wc_get_order($orderId);
        if (!$order) {
            return false;
        }

        if (!$this->prepareConvertCart($order)) {
            return false;
        }

        return $this->senderConvertCart($orderId);
    }

    private function withGuestCartSession(callable $operation)
    {
        $wc = $this->senderGetWoo();
        return $this->guestSession->run($wc ? $wc->session : null, $operation);
    }

    /** Keep the session's cart identity before payment can outlive the session. */
    public function senderBindSessionCartToOrder($order): void
    {
        if (!$order instanceof WC_Order || $order->get_meta(Sender_Helper::SENDER_CART_META, true)) {
            return;
        }
        $wc = $this->senderGetWoo();
        $this->guestSession->refresh($wc ? $wc->session : null);
        $cart = is_user_logged_in()
            ? $this->senderFindActiveCart($this->senderFindCurrentUser())
            : $this->senderFindOwnedSessionCart();
        if (!$this->senderCartOwnerMatchesOrderEmail($cart, $order)) {
            return;
        }
        // Separate proof from conversion preparation; the order is still unpaid.
        $order->update_meta_data(self::SENDER_ORDER_CART_PROOF_META, $cart->getRecoveryToken());
        $this->senderCopyPendingTrackingToOrder($order, $cart);
        // WooCommerce saves buffered metadata with a new order; existing orders
        // can persist it immediately without creating or saving the order here.
        if ($order->get_id()) {
            $order->save_meta_data();
        }
    }

    private function senderFindOrderSessionCart($order)
    {
        $cart = $this->senderFindRecoveryCart($order->get_meta(self::SENDER_ORDER_CART_PROOF_META, true));
        return $this->senderCartOwnerMatchesOrderEmail($cart, $order) ? $cart : null;
    }

    private function senderCartOwnerMatchesOrderEmail($cart, $order): bool
    {
        $owner = $cart ? (new Sender_User())->find($cart->user_id) : null;
        return $owner && strtolower((string) $owner->email) === strtolower((string) $order->get_billing_email());
    }

    private function senderCopyPendingTrackingToOrder($order, $cart): void
    {
        if (WC()->session && (int) WC()->session->get(self::SENDER_PENDING_CART_TRACK_SESSION_KEY) === (int) $cart->id) {
            $order->update_meta_data(Sender_Helper::SENDER_CART_TRACK_PENDING, $cart->id);
        }
    }

    private function orderHasLinkedCart($order): bool
    {
        $id = $order->get_meta(Sender_Helper::SENDER_CART_META, true);
        return $id && (bool) (new Sender_Cart())->find($id);
    }

    private function cartUpdatesPaused(): bool
    {
        return $this->preparedOrderId > 0 || WC()->session && (int) WC()->session->get(Sender_Helper::TRANSIENT_PREPARE_CONVERT, 0) > time();
    }

    public function prepareConvertCart($order)
    {
        if ($this->orderHasLinkedCart($order)) return true;
        // The shopper may have started another cart since this order saved its proof.
        $orderCart = $this->senderFindOrderSessionCart($order);
        $liveCart = $this->senderFindOwnedSessionCart();
        $differentLiveCart = $orderCart && $liveCart && (int) $orderCart->id !== (int) $liveCart->id;
        $previousUserId = $this->senderUserId;
        if (is_user_logged_in()) {
            $currentUser = wp_get_current_user();
            $senderUser = (new Sender_User())->findBy('email', strtolower($currentUser->user_email));
            if (!$senderUser) {
                if (!$this->handleGuestConvertCart($order)){
                    return false;
                }

                $senderUser = (new Sender_User())->findBy('email', strtolower($currentUser->user_email));
            } elseif (!$differentLiveCart) {
                $this->senderCartUpdated();
            }
        } else {
            if (!$this->handleGuestConvertCart($order, false)) return false;
            $senderUser = (new Sender_User())->findBy('email', strtolower($order->get_billing_email()));
        }

        if (!$senderUser) {
            return false;
        }

        $update = $this->withGuestCartSession(function () use ($order, $senderUser) {
            return $this->linkCartToOrder($order, $senderUser);
        });
        if (!$update) {
            $this->senderUserId = $previousUserId;
            // Checkout already saved the verified identity. The existing fallback
            // can finish binding after a busy session writer releases the cart.
            if ($this->senderFindOrderSessionCart($order)) {
                $this->scheduleThankYouFallbackCheck($order->get_id());
            }
            return false;
        }

        $this->sendShoppingCart($update);
        $newsletter = Sender_Helper::submittedNewsletterConsent();
        if ($newsletter !== null) {
            $order->update_meta_data(
                    Sender_Helper::EMAIL_MARKETING_META_KEY,
                    Sender_Helper::generateEmailMarketingConsent($newsletter ? Sender_Helper::SUBSCRIBED : Sender_Helper::UNSUBSCRIBED)
            );
            $order->save_meta_data();
        }

        if (get_current_user_id()){
            $this->trackUser();
        }

        $liveCart = $this->senderFindOwnedSessionCart();
        $differentLiveCart = $liveCart && (int) $liveCart->id !== (int) $order->get_meta(Sender_Helper::SENDER_CART_META, true);
        if ($differentLiveCart) {
            $this->senderUserId = $previousUserId;
        } elseif (WC()->session) {
            WC()->session->set(Sender_Helper::TRANSIENT_PREPARE_CONVERT, time() + 300);
        }

        return true;
    }

    private function linkCartToOrder($order, $senderUser)
    {
        $cart = $this->senderFindOrderSessionCart($order) ?: $this->senderFindActiveCart($senderUser);
        $liveCart = $this->senderFindOwnedSessionCart();
        $differentLiveCart = $cart && $liveCart && (int) $cart->id !== (int) $liveCart->id;
        $update = null;
        if ($cart && !is_user_logged_in() && !$differentLiveCart) {
            // Preserve a final quantity change while binding this same cart to the order.
            $update = $this->updateSessionCart(true);
            $order->update_meta_data(self::SENDER_ORDER_CART_PROOF_META, $cart->getRecoveryToken());
        }
        if (!$cart && !is_user_logged_in()) {
            // Guest checkout may have unloaded the Woo session. Create from this
            // order instead of selecting another guest's cart by billing email.
            // Logged-in checkout retains its existing no-active-cart behavior.
            $cart = $this->ensureSenderCartForOrder($order);
        }

        if(!$cart || $cart->cart_status === (int) Sender_Helper::CONVERTED_CART){
            return false;
        }

        // Reserve this exact cart for the order; preparation is not API success.
        $cart->cart_status = Sender_Helper::UNPAID_CART;
        $cart->save();
        $order->update_meta_data(Sender_Helper::SENDER_CART_META, $cart->id);
        $this->senderCopyPendingTrackingToOrder($order, $cart);
        $order->update_meta_data('_sender_checkout_store', (string) get_option('sender_store_register'));
        $order->save_meta_data();
        if (!$differentLiveCart) {
            $this->preparedOrderId = $order->get_id();
            $this->guestSession->reserved(WC()->session);
        }
        return isset($update['cart']) ? $update : true;
    }

    public function senderConvertCart($orderId)
    {
        $order = wc_get_order($orderId);
        if (!$order) {
            return false;
        }

        if (!$this->orderHasLinkedCart($order)){
            if(!$this->prepareConvertCart($order)){
                return false;
            }
        }

        if (is_user_logged_in()) {
            $currentUser = wp_get_current_user();
            $senderUser = (new Sender_User())->findBy('email', $currentUser->user_email);
        }else{
            $order = wc_get_order($orderId);
            if ($order) {
                $billingEmail = $order->get_billing_email();
                if (!empty($billingEmail)) {
                    $senderUser = (new Sender_User())->findBy('email', $billingEmail);
                }
            }
        }

        if (empty($senderUser)){
            return false;
        }

        $cart = (new Sender_Cart())->find($order->get_meta(Sender_Helper::SENDER_CART_META, true));

        if (!$cart){
            return false;
        }

        $list = get_option('sender_customers_list');
        $wcOrder = wc_get_order($orderId);
        $email = strtolower($wcOrder->get_billing_email());
        $firstname = $wcOrder->get_billing_first_name();
        $lastname = $wcOrder->get_billing_last_name();
        $phone = $wcOrder->get_billing_phone();

        $subtotal = $wcOrder->get_subtotal();
        $discount = $wcOrder->get_total_discount();
        $tax = $wcOrder->get_total_tax();
        $shipping_charge = $wcOrder->get_shipping_total();
        $total = $wcOrder->get_total();
        $order_date = date('d/m/Y', strtotime($wcOrder->get_date_created()));
        $payment_method = $wcOrder->get_payment_method_title();

        $billing = [
                'first_name' => $wcOrder->get_billing_first_name(),
                'last_name' => $wcOrder->get_billing_last_name(),
                'address' => $wcOrder->get_billing_address_1(),
                'city' => $wcOrder->get_billing_city(),
                'state' => $wcOrder->get_billing_state(),
                'zip' => $wcOrder->get_billing_postcode(),
                'country' => $wcOrder->get_billing_country(),
                'customer_ip' => $wcOrder->get_customer_ip_address(),
        ];

        $shipping = [
                'first_name' => $wcOrder->get_shipping_first_name(),
                'last_name' => $wcOrder->get_shipping_last_name(),
                'address' => $wcOrder->get_shipping_address_1(),
                'city' => $wcOrder->get_shipping_city(),
                'state' => $wcOrder->get_shipping_state(),
                'zip' => $wcOrder->get_shipping_postcode(),
                'country' => $wcOrder->get_shipping_country(),
                'shipping_charge' => number_format($shipping_charge, 2),
                'payment_method' => $payment_method,
        ];

        $orderDetails = [
                'total' => number_format($total, 2),
                'subtotal' => number_format($subtotal, 2),
                'discount' => number_format($discount, 2),
                'tax' => number_format($tax, 2),
                'order_date' => $order_date,
        ];

        $cartData = [
                'external_id' => $cart->id,
                'email' => $email,
                'firstname' => $firstname,
                'lastname' => $lastname,
                'resource_key' => $this->senderGetResourceKey(),
                'phone' => $phone,
                'order_id' => (string)$orderId,
                'billing' => $billing,
                'shipping' => $shipping,
                'order_details' => $orderDetails,
                'store_id' => get_option('sender_store_register') ?: ''
        ];

        if ($list) {
            $cartData['list_id'] = $list;
        }

        $wpUserId = get_current_user_id();
        if ($wpUserId){
            $cartData['customer_id'] = $wpUserId;
        }

        $order->update_meta_data(Sender_Helper::SENDER_CART_META, $cart->id);
        $order->update_meta_data(Sender_Helper::SENDER_CART_DATA, $cartData);
        $order->save_meta_data();
        do_action('sender_update_customer_data', $email, true);

        if (is_user_logged_in()) {
            set_transient(Sender_Helper::TRANSIENT_LOG_IN, 1, 0);
        }

        $liveCart = $this->senderFindOwnedSessionCart();
        if (WC()->session && (!$liveCart || (int) $liveCart->id === (int) $cart->id)) {
            WC()->session->__unset(Sender_Helper::TRANSIENT_PREPARE_CONVERT);
        }
        if ((int) $this->preparedOrderId === (int) $orderId) $this->preparedOrderId = 0;
        return true;
    }

    public function senderPrepareCartData($cart)
    {
        $items = $this->senderGetCart();
        $total = $this->senderGetWoo()->cart->total;
        $user = (new Sender_User())->find($cart->user_id);

        if (!$user){
            return false;
        }

        $storeId = $this->senderGetOrRegisterStoreId();

        $cartUrl = $cart->getRecoveryUrl();

        $data = [
                "external_id" => $cart->id,
                "url" => $cartUrl,
                "currency" => get_woocommerce_currency(),
                "order_total" => (string)$total,
                "products" => [],
                'resource_key' => $this->senderGetResourceKey(),
                'store_id' => $storeId,
                'email' => $user->email,
                'subscriber_id' => $user->sender_subscriber_id,
                'ip_address' => Sender_Helper::normalizeIpToIpv4(WC_Geolocation::get_ip_address()),
        ];

        $priceUpdatedAt = sprintf('%.6F', microtime(true));

        foreach ($items as $values) {

            $_product = wc_get_product($values['data']->get_id());
            $regularPrice = (float) $_product->get_regular_price();
            $salePrice = (float) $_product->get_sale_price();

            // Default to current price (sale or regular)
            $price = (float) $_product->get_price();
            $discount = 0;
            $oldPrice = null;

            // Only calculate discount if sale price is valid and lower than regular price
            if ($_product->is_on_sale() && $regularPrice > 0 && $price < $regularPrice) {
                $discount = round(100 - ($salePrice / $regularPrice * 100));
                $oldPrice = $regularPrice;
            }

            $image_url = Sender_Helper::getProductImageUrl($_product);
            $description = Sender_Helper::getProductShortText($_product);

            $sku = $this->senderGetProductSku($_product);

            $prod = [
                    'sku' => (string) $sku,
                    'name' => (string)$_product->get_title(),
                    'price' => (string) $price,
                    'qty' => $values['quantity'],
                    'image' => $image_url,
                    'product_id' => $_product->get_id(),
                    'price_updated_at' => $priceUpdatedAt,
                    'description' => $description,
            ];

            if ($oldPrice !== null) {
                $prod['old_price'] = (string) $oldPrice;
                $prod['discount'] = (string) $discount;
            }

            $data['products'][] = $prod;
        }

        return $data;
    }

    /** Preserve the existing registration repair only at callers that already require it. */
    private function senderGetOrRegisterStoreId()
    {
        $storeId = get_option('sender_store_register') ?: '';
        if (empty($storeId)) {
            $this->sender->senderHandleAddStore();
            $storeId = get_option('sender_store_register') ?: '';
        }
        return $storeId;
    }

    private function senderGetProductSku($product)
    {
        $isVariation = $product->is_type('variation');
        $sku = $product->get_sku();
        if ($isVariation && !$sku) {
            $parent = wc_get_product($product->get_parent_id());
            $sku = $parent ? $parent->get_sku() : '';
        }
        return $sku;
    }

    public function trackUser()
    {
        if (!is_user_logged_in()) {
            return false;
        }

        $wpUser = wp_get_current_user();
        $wpId = $wpUser->ID;
        $user = (new Sender_User())->findBy('wp_user_id', $wpId);

        if (isset($_POST['sender_newsletter'])){
            $this->senderWpNewsletterHandle($wpId);
        }

        if (!$user) {
            $user = new Sender_User();
            $user->wp_user_id = $wpId;
            $user->email = strtolower($wpUser->user_email);
            $user->save();
            $this->sender->senderApi->senderTrackRegisteredUsers($wpId);
            return true;
        }

        $this->sender->senderApi->senderApiShutdownCallback("senderTrackRegisteredUsers", $wpId);

        return true;
    }

    /**
     * @return array|null
     */
    public function senderCartUpdated()
    {
        $update = $this->withGuestCartSession(function () {
            return $this->updateSessionCart();
        });
        return $this->sendShoppingCart($update);
    }

    private function updateSessionCart(bool $checkout = false)
    {
        if (isset($_GET['hash']) || $this->inSenderCartUpdated || $this->cartUpdatesPaused() || $this->guestSession->cartUpdateIsLate()) return null;
        $this->inSenderCartUpdated = true;

        try {
            $wc = $this->senderGetWoo();
            // Checkout can fire this hook after the shopper session has been unloaded.
            if (!$wc || !$wc->session || !isset($wc->cart) || !is_object($wc->cart)) {
                return null;
            }

            // An unreadable cart must not be treated as a customer emptying it.
            try {
                $items = $wc->cart->get_cart();
            } catch (Throwable $e) {
                return null;
            }
            if (!is_array($items)) {
                return null;
            }
            $cartData = serialize($items);
            $hasIdentity = $this->senderUserId || (!empty($items) && $this->trackUser()) || isset($_COOKIE[self::SENDER_SUBSCRIBER_ID]);

            $senderUser = $this->senderFindCurrentUser();
            $cart = $this->senderFindActiveCart($senderUser);

            if ($checkout && (!$cart || !$items)) return null;
            if (!$hasIdentity && !($cart instanceof Sender_Cart)) {
                return null;
            }

            // If Woo cart is empty now, delete mapped Sender cart and stop
            if (empty($items)) {
                if (function_exists('is_order_received_page') && is_order_received_page()) {
                    return null;
                }
                if ($this->cartUpdatesPaused()) {
                    return null;
                }

                if ($cart instanceof Sender_Cart && $cart->deleteIfOpen()) {
                    $this->sender->senderApi->senderApiShutdownCallback("senderDeleteCart", $cart->id);
                }
                $this->senderClearActiveCartSessionMap();
                return ['status' => 'empty'];
            }

            // Update existing cart
            if ($cart instanceof Sender_Cart) {
                $cart->cart_data = $cartData;
                $changed = $cart->isDirty();
                $cart->update();

                $pending = (int) $wc->session->get(self::SENDER_PENDING_CART_TRACK_SESSION_KEY) === (int) $cart->id;
                if (!$changed && (!$pending || $checkout)) {
                    return [
                            'status' => 'skipped',
                            'reason' => 'cart_unchanged'
                    ];
                }

                return ['cart' => $cart, 'created' => false, 'pending' => $pending];
            }

            // Create new cart
            if (!$this->senderUserId && !$senderUser) {
                $senderUser = $this->senderGetVisitor();
                if (!$senderUser) { return [
                        'status' => 'skipped',
                        'reason' => 'no_sender_user'
                ]; }
            }

            $newCart = new Sender_Cart();
            $newCart->cart_data = $cartData;
            $newCart->user_id   = $this->senderUserId ?: $senderUser->id;
            $newCart->cart_recovered = 0;
            $newCart->cart_status = 0;
            $newCart->save();

            if ($wc && $wc->session) {
                $wc->session->set(self::SENDER_ACTIVE_CART_SESSION_KEY, (int)$newCart->id);
                // Only server-created session state authorizes subsequent guest writes.
                $wc->session->set(self::SENDER_OWNED_CART_SESSION_KEY, $newCart->getRecoveryToken());
                if ($this->senderUserId) {
                    $wc->session->set(self::SENDER_PENDING_CART_TRACK_SESSION_KEY, (int) $newCart->id);
                }
                $wc->session->set_customer_session_cookie(true);
            }

            return ['cart' => $newCart, 'created' => true, 'pending' => (bool) $this->senderUserId];
        } finally {
            $this->inSenderCartUpdated = false;
        }
    }

    /** Send the saved cart after the session lock is released; preserve skipped results. */
    private function sendShoppingCart($update)
    {
        if (!isset($update['cart'])) return $update;
        $cart = $update['cart'];
        $created = $update['created'];
        $pending = $update['pending'];
        $payload = $this->senderPrepareCartData($cart);
        if (!$payload) return ['payload' => null];
        if ($pending) return $this->senderTrackSessionCart($cart, $payload);

        $ajax = wp_doing_ajax();
        if ($ajax && get_option('woocommerce_cart_redirect_after_add') !== 'yes') {
            if ($created) {
                return ['status' => 'sent', 'payload' => $payload, 'response' => $this->sender->senderApi->senderTrackCart($payload)];
            }
            $this->handleCartFragmentsFilters(json_encode($payload), self::UPDATE_CART);
            return $payload;
        }
        $this->sender->senderApi->senderApiShutdownCallback($created ? 'senderTrackCart' : 'senderUpdateCart', $payload);
        return ['status' => $ajax && !$created ? 'sent' : 'queued', 'method' => $created ? 'create' : 'update', 'payload' => $payload];
    }

    private function senderTrackSessionCart($cart, array $payload): array
    {
        $response = $this->sender->senderApi->senderTrackCart($payload);
        $result = ['status' => 'sent', 'payload' => $payload, 'response' => $response];
        if ($this->senderCartTrackingAccepted($response)) {
            $this->withGuestCartSession(function () use ($cart, $result) {
                $wc = $this->senderGetWoo();
                if (!$wc || !$wc->session || (int) $wc->session->get(self::SENDER_PENDING_CART_TRACK_SESSION_KEY) !== (int) $cart->id) return;
                $wc->session->__unset(self::SENDER_PENDING_CART_TRACK_SESSION_KEY);
                $owned = $this->senderFindOwnedSessionCart();
                if (!$owned || (int) $owned->id !== (int) $cart->id) return;
                $user = (new Sender_User())->find($cart->user_id);
                if ($user) $this->senderCleanupPreviousCart(null, $user, $result);
            });
        }
        return $result;
    }

    public function handleCartFragmentsFilters($cartData, $type)
    {
        switch ($type) {
            case self::TRACK_CART:
                $method = 'trackCart';
                break;
            case self::UPDATE_CART:
                $method = 'updateCart';
                break;
        }

        if (isset($method)) {
            foreach (self::FRAGMENTS_FILTERS as $filterName) {
                add_filter($filterName, function ($fragments) use ($cartData, $type, $method) {
                    ob_start();
                    ?>
                    <script id="<?php echo $type ?>">
                        sender('<?php echo $method; ?>', <?php echo $cartData; ?>)
                    </script>
                    <?php $fragments['script#' . $type] = ob_get_clean();
                    return $fragments;
                });
            }
        }
    }

    public function senderGetVisitor()
    {
        if (is_user_logged_in()){
            $wpUser = wp_get_current_user();
            $user = (new Sender_User())->findBy('wp_user_id', $wpUser->ID);
            if (!$user && isset($wpUser->user_email)) {
                $user = new Sender_User();
                $user->email = $wpUser->user_email;
                $user->wp_user_id = $wpUser->ID;
                $user->save();
            }
            return $user;
        } elseif (isset($_COOKIE[self::SENDER_SUBSCRIBER_ID])) {
            $subscriberId = sanitize_text_field($_COOKIE[self::SENDER_SUBSCRIBER_ID]);
            $senderUser = (new Sender_User())->findBy('sender_subscriber_id', $subscriberId);

            if (!$senderUser) {
                $senderUser = new Sender_User();
                $senderUser->sender_subscriber_id = $subscriberId;
                $senderUser->save();
            }

            return $senderUser;
        }

        return false;
    }

    public function senderGetCart()
    {
        $woo = $this->senderGetWoo();

        if (!$woo || !isset($woo->cart) || !is_object($woo->cart)) {
            return [];
        }

        try {
            return $woo->cart->get_cart();
        } catch (Throwable $e) {
            return [];
        }
    }

    public function senderGetWoo()
    {
        if (function_exists('WC') && WC() instanceof WooCommerce) {
            return WC();
        }

        global $woocommerce;
        if ($woocommerce instanceof WooCommerce) {
            return $woocommerce;
        }

        return null;
    }

    public function senderGetResourceKey()
    {
        $key = get_option('sender_resource_key');

        if (!$key) {
            $user = $this->senderGetAccount();
            $key = $user->account->resource_key;
            update_option('sender_resource_key', $key);
        }

        return $key;
    }

    public function senderAddNewsletterCheck($order = null)
    {
        if (get_option('sender_subscribe_label') && !empty(get_option('sender_subscribe_to_newsletter_string'))) {
            if (is_admin() && $order instanceof WC_Order) {
                $emailMarketingConsent = $order->get_meta(Sender_Helper::EMAIL_MARKETING_META_KEY);
                $currentValue = !empty($emailMarketingConsent) ? Sender_Helper::handleChannelStatus($emailMarketingConsent) : $order->get_meta('sender_newsletter');
            } elseif (is_account_page() && get_current_user_id()) {
                $emailMarketingConsent = get_user_meta(get_current_user_id(), Sender_Helper::EMAIL_MARKETING_META_KEY, true);
                $currentValue = !empty($emailMarketingConsent) ? Sender_Helper::handleChannelStatus($emailMarketingConsent) : get_user_meta(get_current_user_id(), 'sender_newsletter', true);
            } else {
                $currentValue = get_option('sender_checkbox_newsletter_on_checkout') ? true : false;
            }

            woocommerce_form_field(
                    'sender_newsletter', ['type'  => 'hidden'], 0
            );
            woocommerce_form_field(
                    'sender_newsletter_changed', ['type' => 'hidden'], 0
            );

            woocommerce_form_field('sender_newsletter', array(
                    'type' => 'checkbox',
                    'class' => array('form-row mycheckbox'),
                    'label_class' => array('woocommerce-form__label woocommerce-form__label-for-checkbox checkbox'),
                    'input_class' => array('woocommerce-form__input woocommerce-form__input-checkbox input-checkbox'),
                    'label' => get_option('sender_subscribe_to_newsletter_string'),
                    'checked' => get_option('sender_checkbox_newsletter_on_checkout') == '1',
            ), $currentValue);
        }
    }

    /** On-hold checkouts retain the purchase-conversion behavior of earlier releases. */
    private function canConvertOrder($order): bool
    {
        return $order->is_paid() || $order->has_status('on-hold');
    }

    public function senderConvertCartFallback($order_id): bool
    {
        $order_id = absint($order_id);
        if (!$order_id || get_transient(Sender_Helper::TRANSIENT_SENDER_THANK_YOU . $order_id)) {
            return false;
        }

        $order = wc_get_order($order_id);
        if (!$order) return false;
        $cartData = $order->get_meta(Sender_Helper::SENDER_CART_DATA, true);
        $cartId = $order->get_meta(Sender_Helper::SENDER_CART_META, true);

        if (empty($cartData) || empty($cartId)) {
            $this->senderConvertCart($order_id);
            $order = wc_get_order($order_id);
            $cartData = $order->get_meta(Sender_Helper::SENDER_CART_DATA, true);
            $cartId = $order->get_meta(Sender_Helper::SENDER_CART_META, true);
        }

        if (empty($cartData) || empty($cartId)) {
            return false;
        }

        $cart = (new Sender_Cart())->find($cartId);
        if ($cart && (string) $cart->cart_status === (string) Sender_Helper::CONVERTED_CART) {
            set_transient(Sender_Helper::TRANSIENT_SENDER_THANK_YOU . $order_id, -1, 3600);
            return true;
        }

        if (!$this->canConvertOrder($order)) return false;
        if ($order->get_meta(Sender_Helper::SENDER_CART_TRACK_PENDING, true)
            && (!$cart || !$this->trackPendingOrderCart($cart, $order))) {
            return false;
        }

        if (!$this->sender->senderApi->senderConvertCart($cartId, $cartData)) {
            return false;
        }

        if ($cart) {
            $cart->cart_status = Sender_Helper::CONVERTED_CART;
            $cart->save();
        }
        set_transient(Sender_Helper::TRANSIENT_SENDER_THANK_YOU . $order_id, -1, 3600);
        return true;
    }

    private function orderCartData($orderId)
    {
        $order = wc_get_order($orderId);
        return $order ? $order->get_meta(Sender_Helper::SENDER_CART_DATA, true) : null;
    }

    public function addConvertCartScript($order_id)
    {
        $order_id = absint($order_id);
        if (!$order_id || isset($this->renderedConvertCartScripts[$order_id])) {
            return '';
        }
        $order = wc_get_order($order_id);
        if (!$this->senderCanAccessOrder($order)) {
            return '';
        }

        $cartData = $this->orderCartData($order_id);

        if (empty($cartData)) {
            $this->senderConvertCart($order_id);
            $cartData = $this->orderCartData($order_id);
            if (empty($cartData)) {
                return '';
            }
        }

        $order = wc_get_order($order_id);
        if ($order && $order->get_meta(Sender_Helper::SENDER_CART_TRACK_PENDING, true)) {
            $cart = (new Sender_Cart())->find($order->get_meta(Sender_Helper::SENDER_CART_META, true));
            if (!$this->canConvertOrder($order) || !$cart || !$this->trackPendingOrderCart($cart, $order)) {
                return '';
            }
        }

        $this->renderedConvertCartScripts[$order_id] = true;

        ob_start(); ?>
        <script>
            <?php if ($order && $this->canConvertOrder($order)) : ?>
                sender('convertCart', <?php echo wp_json_encode($cartData); ?>);
            <?php endif; ?>

            fetch(<?php echo wp_json_encode(add_query_arg([
                'action' => 'thankyou_seen', 'order_id' => $order_id, 'key' => $order->get_order_key(),
                'sender_confirmation' => Sender_Order_Confirmation::confirmationNonce($order),
            ], admin_url('admin-ajax.php'))); ?>)
                .catch(() => {});
        </script>
        <?php
        return ob_get_clean();
    }

    public function outputConvertCartScript($order_id)
    {
        if (!$this->senderCanAccessOrder(wc_get_order($order_id))) {
            return;
        }
        $this->scheduleThankYouFallbackCheck($order_id);
        echo $this->addConvertCartScript($order_id);
    }

    public function senderConvertThankYouCart($order_id)
    {
        if ($this->senderCanAccessOrder(wc_get_order($order_id))) {
            return $this->senderConvertCart($order_id);
        }
        return false;
    }

    private function scheduleThankYouFallbackCheck($order_id)
    {
        $order_id = absint($order_id);
        if (!$order_id) {
            return;
        }

        if (!wp_next_scheduled('sender_check_thankyou_seen', [$order_id])) {
            wp_schedule_single_event(time() + 300, 'sender_check_thankyou_seen', [$order_id]);
        }
    }

    public function triggerEmailCheckout()
    {
        if (!isset($_POST['email']) || !is_string($_POST['email']) || empty($_POST['email'])) {
            return wp_send_json_error('Email is required');
        }

        $sanitizedEmail = strtolower(sanitize_email(wp_unslash($_POST['email'])));
        if (!is_email($sanitizedEmail)) {
            return wp_send_json_error('A valid email is required');
        }

        if (is_user_logged_in() && $sanitizedEmail !== strtolower(wp_get_current_user()->user_email)) {
            return wp_send_json_error('Email must match the logged-in customer', 403);
        }

        $names = [];
        foreach (['firstname', 'lastname'] as $field) {
            if (isset($_POST[$field]) && is_string($_POST[$field])) {
                $name = sanitize_text_field(wp_unslash($_POST[$field]));
                if ($name !== '') {
                    $names[$field] = $name;
                }
            }
        }
        $senderUser = (new Sender_User())->findBy('email', $sanitizedEmail);
        if (is_user_logged_in() && $senderUser
                && (!$senderUser->wp_user_id || (int) $senderUser->wp_user_id === get_current_user_id())) {
            if ($names || isset($_POST['newsletter'])) {
                $payload = $names;
                $newsletter = Sender_Helper::submittedNewsletterConsent('newsletter', 'newsletter_changed');
                if ($newsletter !== null) {
                    $payload['newsletter'] = $newsletter;
                    $this->senderWpNewsletterHandle(get_current_user_id(), $newsletter);
                }
                $response = $this->sender->senderApi->senderTrackRegisteredUsers(get_current_user_id(), $payload);
                if (!$response || empty($response->subscriber_id)) {
                    return wp_send_json_error('Subscriber not updated');
                }
            }
            // Link the contact, but this email match does not authorize any earlier cart.
            if (!$senderUser->wp_user_id) {
                $senderUser->wp_user_id = get_current_user_id();
                $senderUser->save();
            }
            $this->senderUserId = $senderUser->id;

            $cartUpdateResponse = $this->senderCartUpdated();

            return wp_send_json_success([
                    'cart_response' => $cartUpdateResponse,
                    'subscriber_response' => [
                            'status' => 'existing_user'
                    ],
            ]);
        }

        $newsletter = isset($_POST['newsletter']) && (int) $_POST['newsletter'] === 1;
        $trackNotRegisteredUserPayload = array_merge([
                'email' => $sanitizedEmail,
                'newsletter' => $newsletter
        ], $names);
        $response = $this->sender->senderApi->senderTrackNotRegisteredUsers($trackNotRegisteredUserPayload);

        if (!$response || !isset($response->subscriber_id) || empty($response->subscriber_id)) {
            return wp_send_json_error('Subscriber not created');
        }

        $cartUpdateResponse = $this->withGuestCartSession(function () use ($sanitizedEmail, $response) {
            return $this->captureSubscriberCart($sanitizedEmail, $response->subscriber_id);
        });
        if ($cartUpdateResponse === false) {
            return wp_send_json_error('Cart capture could not finish. Please retry.', 503);
        }
        $cartUpdateResponse = $this->sendShoppingCart($cartUpdateResponse);
        return wp_send_json_success([
                'cart_response'       => $cartUpdateResponse,
                'subscriber_response' => [
                        'payload' => $trackNotRegisteredUserPayload ?? null,
                        'response' => $response
                ],
        ]);
    }

    private function captureSubscriberCart($sanitizedEmail, $subscriberId)
    {
        if ($this->guestSession->cartUpdateIsLate() || $this->cartUpdatesPaused()) return ['status' => 'skipped', 'reason' => 'order_prepared'];
        $senderUser = (new Sender_User())->findBy('sender_subscriber_id', $subscriberId);

        if (!$senderUser) {
            $senderUser = (new Sender_User())->findBy('email', $sanitizedEmail);
        }

        if (!$senderUser) {
            $senderUser = new Sender_User();
            $senderUser->email = $sanitizedEmail;
            $senderUser->sender_subscriber_id = $subscriberId;

            if (!$senderUser->save()) {
                return false;
            }
        } else {
            $updated = false;

            if (empty($senderUser->email)) {
                $senderUser->email = $sanitizedEmail;
                $updated = true;
            }

            if (empty($senderUser->sender_subscriber_id)) {
                $senderUser->sender_subscriber_id = $subscriberId;
                $updated = true;
            }

            if ($updated) {
                $senderUser->update();
            }
        }

        // Capture ownership before adopting the submitted email's identity.
        // A subscriber cookie alone must never select a previous cart for deletion.
        $previousCart = !is_user_logged_in() ? $this->senderFindOwnedSessionCart() : null;
        $wc = $this->senderGetWoo();
        $session = $wc ? $wc->session : null;
        if ($session && $previousCart && (int) $previousCart->user_id !== (int) $senderUser->id) {
            $tokens = (array) $session->get(self::SENDER_PREVIOUS_CARTS_SESSION_KEY, []);
            $tokens[] = $previousCart->getRecoveryToken();
            $session->set(self::SENDER_PREVIOUS_CARTS_SESSION_KEY, array_values(array_unique($tokens)));
        }
        $this->senderUserId = $senderUser->id;
        $work = $this->updateSessionCart();
        if (is_array($work)) $this->senderCleanupPreviousCart($previousCart, $senderUser, $work);
        return $work;
    }

    //Use to convert carts which got confirmed payment
    public function senderUpdateOrderStatus($orderId, $oldStatus = null, $newStatus = null)
    {
        if ($newStatus !== null) {
            $newOrderStatus = $newStatus;
            if (strpos($newOrderStatus, 'wc-') !== 0) {
                $newOrderStatus = 'wc-' . $newOrderStatus;
            }
        } elseif (isset($_POST['order_status'])) {
            $newOrderStatus = $_POST['order_status'];
        } else {
            return;
        }

        if (!is_admin()) {
            // A gateway or REST request can also confirm a bank transfer.
            if ($oldStatus === 'on-hold' && in_array($newOrderStatus, [Sender_Helper::ORDER_PAID, Sender_Helper::ORDER_COMPLETED], true)) {
                $order = wc_get_order($orderId);
                if ($order && $order->is_paid() && $order->get_meta('_sender_checkout_store', true)
                    && $order->get_meta('_sender_checkout_store', true) === (string) get_option('sender_store_register')
                    && !$this->senderHandlePaidOrder($orderId)) {
                    $this->scheduleThankYouFallbackCheck($orderId);
                }
            }
            return;
        }

        switch ($newOrderStatus) {
            case Sender_Helper::ORDER_PAID:
                // Manual bank-transfer approval does not fire payment_complete.
                $order = wc_get_order($orderId);
                if ($order && $order->is_paid()) {
                    $this->senderHandlePaidOrder($orderId);
                }
                return;
            case Sender_Helper::ORDER_COMPLETED:
                $this->senderHandlePaidOrder($orderId);

                // Fall through to report the final status after conversion.
            case Sender_Helper::ORDER_PENDING_PAYMENT:
                $order = wc_get_order($orderId);
                $senderRemoteCartId = $order ? $order->get_meta(Sender_Helper::SENDER_CART_META, true) : null;
                if (empty($senderRemoteCartId)) {
                    return;
                }

                $cart = (new Sender_Cart())->findByAttributes(
                        [
                                'id' => $senderRemoteCartId,
                        ]
                );

                if (!$cart) {
                    return;
                }

                $cartStatus = [
                        "external_id" => $cart->id,
                        'order_id' => (string)$orderId,
                        'cart_status' => $newOrderStatus,
                        'resource_key' => $this->senderGetResourceKey(),
                ];

                $this->sender->senderApi->senderUpdateCartStatus($cart->id, $cartStatus);
                return;
        }
    }

    public function senderHandlePaidOrder($orderId)
    {
        $orderId = $this->resolveOrderId($orderId);
        if (!$orderId) {
            return false;
        }

        $order = wc_get_order($orderId);
        if (!$order) {
            return false;
        }

        $cart = $this->ensureSenderCartForOrder($order);
        if (!$cart) {
            return false;
        }

        if ((string) $cart->cart_status === (string) Sender_Helper::CONVERTED_CART) {
            return true;
        }

        if (!$this->trackPendingOrderCart($cart, $order)) {
            return false;
        }

        $cartData = $this->buildConvertCartPayload($cart, $order);
        if (!$this->sender->senderApi->senderConvertCart($cart->id, $cartData)) {
            return false;
        }

        $cart->cart_status = Sender_Helper::CONVERTED_CART;
        $cart->save();


        do_action('sender_update_customer_data', $cartData['email'], true);

        return true;
    }

    public function senderHandleRenewalPaymentComplete($subscription, $renewalOrder = null)
    {
        if (is_object($renewalOrder) && method_exists($renewalOrder, 'get_id')) {
            return $this->senderHandlePaidOrder($renewalOrder->get_id());
        }

        return $this->senderHandlePaidOrder($subscription);
    }

    private function resolveOrderId($orderOrId)
    {
        if (is_numeric($orderOrId)) {
            return absint($orderOrId);
        }

        if (is_object($orderOrId) && method_exists($orderOrId, 'get_id')) {
            return absint($orderOrId->get_id());
        }

        return 0;
    }

    private function ensureSenderCartForOrder($order)
    {
        $senderRemoteCartId = $order->get_meta(Sender_Helper::SENDER_CART_META, true);
        if (!empty($senderRemoteCartId)) {
            $existingCart = (new Sender_Cart())->findByAttributes(['id' => $senderRemoteCartId]);
            if ($existingCart) {
                return $existingCart;
            }
        }

        $sessionCart = $this->senderFindOrderSessionCart($order);
        if ($sessionCart) {
            $order->update_meta_data(Sender_Helper::SENDER_CART_META, $sessionCart->id);
            $order->save_meta_data();
            return $sessionCart;
        }

        $billingEmail = strtolower((string) $order->get_billing_email());
        if ($billingEmail === '') {
            return false;
        }

        $senderUser = (new Sender_User())->findBy('email', $billingEmail);
        if (!$senderUser) {
            $senderUser = new Sender_User();
            $senderUser->email = $billingEmail;
            $senderUser->first_name = $order->get_billing_first_name();
            $senderUser->last_name = $order->get_billing_last_name();
            $senderUser->save();
        }

        $cart = new Sender_Cart();
        $cart->cart_data = serialize($this->serializeOrderItems($order));
        $cart->user_id = $senderUser->id;
        $cart->cart_recovered = 0;
        $cart->cart_status = Sender_Helper::UNPAID_CART;
        $cart->save();

        // Checkout preparation stays local until the order is ready for conversion.
        $order->update_meta_data(Sender_Helper::SENDER_CART_META, $cart->id);
        $order->update_meta_data(Sender_Helper::SENDER_CART_TRACK_PENDING, $cart->id);
        $order->save();

        return $cart;
    }

    private function trackPendingOrderCart($cart, $order): bool
    {
        if ((int) $order->get_meta(Sender_Helper::SENDER_CART_TRACK_PENDING, true) !== (int) $cart->id) {
            return true;
        }

        $senderUser = (new Sender_User())->find($cart->user_id);
        if (!$senderUser) {
            return false;
        }
        // Registration must resolve the same destination used by the cooldown and payload.
        $storeId = $this->senderGetOrRegisterStoreId();
        $retryKey = 'sender_cart_track_retry_' . $order->get_id() . '_' . $cart->id
            . '_' . hash('sha256', (string) $storeId);
        if (get_transient($retryKey)) {
            $this->scheduleThankYouFallbackCheck($order->get_id());
            return false;
        }

        // Share the cooldown across payment callbacks, page loads and cron.
        // Keep the pending marker until Sender acknowledges this exact cart.
        set_transient($retryKey, true, 5 * MINUTE_IN_SECONDS);
        $response = $this->sender->senderApi->senderTrackCart($this->buildTrackCartPayload($cart, $order, $senderUser, $storeId));
        if (!$this->senderCartTrackingAccepted($response)) {
            $this->scheduleThankYouFallbackCheck($order->get_id());
            return false;
        }

        delete_transient($retryKey);
        $order->delete_meta_data(Sender_Helper::SENDER_CART_TRACK_PENDING);
        $order->save_meta_data();
        return true;
    }

    private function serializeOrderItems($order): array
    {
        $serializedItems = [];

        foreach ($order->get_items() as $itemId => $item) {
            $product = $item->get_product();
            if (!$product) {
                continue;
            }

            $variationId = $item->get_variation_id();
            $variationAttributes = $variationId ? wc_get_product_variation_attributes($variationId) : [];

            $serializedItems[] = [
                    'key' => $itemId,
                    'product_id' => $item->get_product_id(),
                    'variation_id' => $variationId,
                    'variation' => $variationAttributes,
                    'quantity' => $item->get_quantity(),
                    'data_hash' => md5(serialize($item->get_data())),
                    'line_tax_data' => [
                            'subtotal' => [],
                            'total' => []
                    ],
                    'line_subtotal' => $item->get_subtotal(),
                    'line_subtotal_tax' => $item->get_subtotal_tax(),
                    'line_total' => $item->get_total(),
                    'line_tax' => $item->get_total_tax(),
                    'data' => serialize($product)
            ];
        }

        return $serializedItems;
    }

    private function buildTrackCartPayload($cart, $order, $senderUser, $storeId): array
    {
        $cartUrl = $cart->getRecoveryUrl();

        return [
                'external_id' => (int) $cart->id,
                'url' => $cartUrl,
                'currency' => get_woocommerce_currency(),
                'order_total' => (string) $order->get_total(),
                'products' => $this->buildProductsPayload($order),
                'resource_key' => $this->senderGetResourceKey(),
                'store_id' => $storeId,
                'email' => $senderUser->email,
                'subscriber_id' => $senderUser->sender_subscriber_id,
        ];
    }

    private function buildConvertCartPayload($cart, $order): array
    {
        $subtotal = $order->get_subtotal();
        $discount = $order->get_total_discount();
        $tax = $order->get_total_tax();
        $shippingCharge = $order->get_shipping_total();
        $total = $order->get_total();
        $orderDate = date('d/m/Y', strtotime($order->get_date_created()));
        $paymentMethod = $order->get_payment_method_title();

        $billing = [
                'first_name' => $order->get_billing_first_name(),
                'last_name' => $order->get_billing_last_name(),
                'address' => $order->get_billing_address_1(),
                'city' => $order->get_billing_city(),
                'state' => $order->get_billing_state(),
                'zip' => $order->get_billing_postcode(),
                'country' => $order->get_billing_country(),
                'customer_ip' => $order->get_customer_ip_address(),
        ];

        $shipping = [
                'first_name' => $order->get_shipping_first_name(),
                'last_name' => $order->get_shipping_last_name(),
                'address' => $order->get_shipping_address_1(),
                'city' => $order->get_shipping_city(),
                'state' => $order->get_shipping_state(),
                'zip' => $order->get_shipping_postcode(),
                'country' => $order->get_shipping_country(),
                'shipping_charge' => number_format($shippingCharge, 2),
                'payment_method' => $paymentMethod,
        ];

        $orderDetails = [
                'total' => number_format($total, 2),
                'subtotal' => number_format($subtotal, 2),
                'discount' => number_format($discount, 2),
                'tax' => number_format($tax, 2),
                'order_date' => $orderDate,
        ];

        $storeId = $this->senderGetOrRegisterStoreId();

        $cartData = [
                'external_id' => $cart->id,
                'email' => strtolower($order->get_billing_email()),
                'firstname' => $order->get_billing_first_name(),
                'lastname' => $order->get_billing_last_name(),
                'resource_key' => $this->senderGetResourceKey(),
                'phone' => $order->get_billing_phone(),
                'order_id' => (string) $order->get_id(),
                'billing' => $billing,
                'shipping' => $shipping,
                'order_details' => $orderDetails,
                'store_id' => $storeId,
        ];

        $list = get_option('sender_customers_list');
        if ($list) {
            $cartData['list_id'] = $list;
        }

        $user = get_user_by('email', $cartData['email']);
        if ($user) {
            $cartData['customer_id'] = $user->ID;
        }

        $order->update_meta_data(Sender_Helper::SENDER_CART_DATA, $cartData);
        $order->save_meta_data();

        return $cartData;
    }

    private function buildProductsPayload($order): array
    {
        $products = [];

        foreach ($order->get_items() as $item) {
            $variationId = $item->get_variation_id();
            $productId = $variationId ?: $item->get_product_id();
            $product = wc_get_product($productId);

            if (!$product) {
                continue;
            }

            $regularPrice = (float) $product->get_regular_price();
            $salePrice = (float) $product->get_sale_price();
            $price = $salePrice > 0 ? $salePrice : $regularPrice;
            $discount = 0;
            $oldPrice = null;

            if ($salePrice > 0 && $salePrice < $regularPrice) {
                $discount = round(100 - ($salePrice / $regularPrice * 100));
                $oldPrice = $regularPrice;
            }

            $sku = $this->senderGetProductSku($product);

            $payload = [
                    'sku' => (string) $sku,
                    'name' => (string) $product->get_title(),
                    'price' => (string) $price,
                    'qty' => (int) $item->get_quantity(),
                    'image' => get_the_post_thumbnail_url($item->get_product_id()),
                    'product_id' => $item->get_product_id(),
            ];

            if ($oldPrice !== null) {
                $payload['old_price'] = (string) $oldPrice;
                $payload['discount'] = (string) $discount;
            }

            $products[] = $payload;
        }

        return $products;
    }

    public function senderRecoverCart($template)
    {
        if (!isset($_GET['hash']) || !is_string($_GET['hash'])) {
            return $template;
        }

        $token = wp_unslash($_GET['hash']);
        $cart = $this->senderFindRecoveryCart($token);
        $senderUser = $this->senderFindCurrentUser();
        if (!$cart || (is_user_logged_in() && (!$senderUser || (int) $cart->user_id !== (int) $senderUser->id))) {
            wp_safe_redirect(wc_get_cart_url());
            exit;
        }

        $cartData = unserialize($cart->cart_data, ['allowed_classes' => false]);
        $wc = $this->senderGetWoo();
        if (!is_array($cartData) || !$cartData || !$wc || !$wc->cart || !$wc->session) {
            return $template;
        }

        $wooCart = $wc->cart;
        $restored = false;
        foreach ($cartData as $product) {
            if (!is_array($product) || empty($product['product_id']) || empty($product['quantity'])) {
                continue;
            }
            $productId = (int) $product['product_id'];
            $quantity = (int) $product['quantity'];
            $variationId = (int) ($product['variation_id'] ?? 0);
            $variation = (array) ($product['variation'] ?? []);
            if ($quantity <= 0) {
                continue;
            }

            // Repeated clicks must not add the saved quantities again.
            $key = $wooCart->find_product_in_cart($wooCart->generate_cart_id($productId, $variationId, $variation));
            $existing = $key ? $wooCart->get_cart_item($key) : [];
            $missing = max(0, $quantity - (int) ($existing['quantity'] ?? 0));
            if ($missing === 0 || $wooCart->add_to_cart($productId, $missing, $variationId, $variation)) {
                $restored = true;
            }
        }

        if (!$restored) {
            return $template;
        }

        $wooCart->calculate_totals();
        (new WC_Cart_Session($wooCart))->set_session();
        // Persist the restored Woo session before sharing its verified cart binding.
        $wc->session->set_customer_session_cookie(true);
        $wc->session->save_data();
        $bound = $this->withGuestCartSession(function () use ($wc, $cart, $token) {
            $wc->session->set(self::SENDER_ACTIVE_CART_SESSION_KEY, (int) $cart->id);
            $wc->session->set(self::SENDER_RECOVERED_CART_SESSION_KEY, $token);
            $wc->session->set(self::SENDER_OWNED_CART_SESSION_KEY, $token);
            return true;
        });
        if (!$bound) return $template;
        $wc->session->save_data();

        $cart->cart_recovered = '1';
        $cart->save();

        if (is_user_logged_in()) {
            set_transient(Sender_Helper::TRANSIENT_RECOVER_CART, '1', 15);
        }

        wp_safe_redirect(wc_get_cart_url());
        exit;
    }

    public function outputSenderTrackVisitorsScript()
    {
        if (get_transient(Sender_Helper::TRANSIENT_RECOVER_CART)){
            if (is_user_logged_in()){
                $current_user = wp_get_current_user();
                $user_email = $current_user->user_email;
                wp_localize_script(Sender_Helper::SENDER_JS_FILE_NAME, 'senderTrackVisitorData', ['email' => $user_email]);
                delete_transient(Sender_Helper::TRANSIENT_RECOVER_CART);
            }
        }
    }

    /**
     * Classic checkout has a template hook; Blocks needs content inside its
     * Contact Information block. Render from current settings without rewriting
     * the page or changing the legacy Gutenberg save format.
     */
    public function senderRenderCheckoutNewsletter($content, $block)
    {
        $name = $block['blockName'] ?? '';
        if ($name !== self::NEWSLETTER_BLOCK_NAME
                && $name !== self::CHECKOUT_CONTACT_BLOCK_NAME) {
            return $content;
        }

        $label = $this->senderSubscribeNewsletterText();
        $newsletter = '';
        if ($label !== null && $label !== '') {
            $checkboxId = self::NEWSLETTER_CHECKBOX_ID;
            $checked = (bool) get_option('sender_checkbox_newsletter_on_checkout');
            ob_start();
            include __DIR__ . '/../templates/checkout-newsletter-block.php';
            $newsletter = ob_get_clean();
        }

        // Refresh existing saved Sender blocks, including hiding them when off.
        if ($name === self::NEWSLETTER_BLOCK_NAME) {
            return $newsletter;
        }

        // Child blocks have already rendered: preserve an existing Sender control
        // in its chosen position rather than inserting another one.
        if ($newsletter === '' || strpos($content, 'id="' . self::NEWSLETTER_CHECKBOX_ID . '"') !== false) {
            return $content;
        }

        // WooCommerce's Contact Information block has a div wrapper. Keep the
        // control inside it so checkout hydration retains it in that section.
        $end = strrpos($content, '</div>');
        if ($end === false) {
            return $content;
        }
        return substr($content, 0, $end) . $newsletter . substr($content, $end);
    }

    //Block checkout subscribe to newsletter
    public function senderSubscribeNewsletterBlockEnqueueAssets()
    {
        $storeId = $this->senderGetOrRegisterStoreId();

        wp_enqueue_script(
                'subscribe-newsletter-block',
                plugins_url('js/subscribe-newsletter.block.js', __FILE__),
                ['wp-blocks', 'wp-element', 'wp-i18n'],
                filemtime(plugin_dir_path(__FILE__) . 'js/subscribe-newsletter.block.js')
        );

        $checkBoxActive = false;
        if (get_option('sender_checkbox_newsletter_on_checkout')) {
            $checkBoxActive = true;
        }

        wp_localize_script(
                'subscribe-newsletter-block',
                'senderNewsletter',
                [
                        'storeId' => $storeId,
                        'senderCheckbox' => $this->senderSubscribeNewsletterText(),
                        'senderAjax' => [
                                'ajaxUrl' => admin_url('admin-ajax.php'),
                        ],
                        'checkboxActive' => $checkBoxActive ?: false,
                ]
        );
    }

    public function senderSubscribeNewsletterText()
    {
        if (get_option('sender_subscribe_label') && !empty(get_option('sender_subscribe_to_newsletter_string'))) {
            return get_option('sender_subscribe_to_newsletter_string');
        }
    }

    private function senderFindRecoveryCart($token)
    {
        if (!is_string($token) || !preg_match('/\A([1-9][0-9]*)\.[a-f0-9]{64}\z/', $token, $matches)) {
            return null;
        }

        $cart = (new Sender_Cart())->find($matches[1]);
        if (!$cart || (int) $cart->cart_status !== 0 || !hash_equals($cart->getRecoveryToken(), $token)) {
            return null;
        }
        return $cart;
    }

    private function senderFindCurrentUser()
    {
        if (is_user_logged_in()) {
            $currentUser = wp_get_current_user();
            return (new Sender_User())->findBy('email', strtolower($currentUser->user_email));
        }
        if ($this->senderUserId) {
            return (new Sender_User())->find($this->senderUserId);
        }
        if (isset($_COOKIE[self::SENDER_SUBSCRIBER_ID]) && is_string($_COOKIE[self::SENDER_SUBSCRIBER_ID])) {
            return (new Sender_User())->findBy('sender_subscriber_id', sanitize_text_field(wp_unslash($_COOKIE[self::SENDER_SUBSCRIBER_ID])));
        }
        return null;
    }

    /** Retire an owned guest cart after acknowledged replacement or confirmed emptying. */
    private function senderCleanupPreviousCart($previousCart, $senderUser, $cartUpdateResponse)
    {
        if (is_user_logged_in() || $this->cartUpdatesPaused()
            || (function_exists('is_order_received_page') && is_order_received_page())) {
            return;
        }
        $status = $cartUpdateResponse['status'] ?? null;
        if ($status !== 'empty' && ($status !== 'sent'
            || !$this->senderCartTrackingAccepted($cartUpdateResponse['response'] ?? null))) {
            return;
        }

        $wc = $this->senderGetWoo();
        $session = $wc ? $wc->session : null;
        $tokens = $session ? (array) $session->get(self::SENDER_PREVIOUS_CARTS_SESSION_KEY, []) : [];
        if ($previousCart) {
            $tokens[] = $previousCart->getRecoveryToken();
        }
        foreach (array_unique($tokens) as $token) {
            // Revalidate after the API call; a cart may have been paid or reassigned.
            $cart = $this->senderFindRecoveryCart($token);
            if ($cart && (int) $cart->user_id !== (int) $senderUser->id && $cart->deleteIfOpen()) {
                $this->sender->senderApi->senderApiShutdownCallback('senderDeleteCart', $cart->id);
            }
        }
        if ($session) {
            $session->__unset(self::SENDER_PREVIOUS_CARTS_SESSION_KEY);
        }
    }

    private function senderCartTrackingAccepted($response): bool
    {
        if (is_object($response)) {
            return ($response->success ?? null) === true;
        }
        if (!is_string($response)) {
            return false;
        }
        return $response === 'Cart successfully tracked'
            || preg_match('/\ACart (?:successfully tracked|updated successfully) with id [1-9][0-9]*\z/', $response) === 1;
    }

    /** Only the active cart explicitly authorized in this Woo session; never a contact lookup. */
    private function senderFindOwnedSessionCart()
    {
        $wc = $this->senderGetWoo();
        if (!$wc || !$wc->session) {
            return null;
        }
        $id = (int) $wc->session->get(self::SENDER_ACTIVE_CART_SESSION_KEY);
        if (!$id) {
            return null;
        }
        foreach ([self::SENDER_OWNED_CART_SESSION_KEY, self::SENDER_RECOVERED_CART_SESSION_KEY] as $key) {
            $cart = $this->senderFindRecoveryCart($wc->session->get($key));
            if ($cart && (int) $cart->id === $id) {
                return $cart;
            }
        }
        return $this->senderFindLegacySessionCart($wc->session, $id);
    }

    /** Preserve pre-token carts using their persisted Woo session, never a contact lookup. */
    private function senderFindLegacySessionCart($session, int $id)
    {
        if (!$session instanceof WC_Session_Handler
            || $session->get(self::SENDER_OWNED_CART_SESSION_KEY)
            || $session->get(self::SENDER_RECOVERED_CART_SESSION_KEY)) {
            return null;
        }
        $cookie = $session->get_session_cookie();
        if (!$cookie || (string) $cookie[0] !== (string) $session->get_customer_id()
            || (int) $cookie[1] <= time()) {
            return null;
        }
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT session_value, session_expiry FROM {$wpdb->prefix}woocommerce_sessions WHERE session_key = %s",
            $session->get_customer_id()
        ));
        if (!$row || (int) $row->session_expiry <= time()) {
            return null;
        }
        $stored = @unserialize($row->session_value, ['allowed_classes' => false]);
        if (!is_array($stored) || !isset($stored[self::SENDER_ACTIVE_CART_SESSION_KEY])
            || !ctype_digit((string) $stored[self::SENDER_ACTIVE_CART_SESSION_KEY])
            || (int) $stored[self::SENDER_ACTIVE_CART_SESSION_KEY] !== $id
            || !empty($stored[self::SENDER_OWNED_CART_SESSION_KEY])
            || !empty($stored[self::SENDER_RECOVERED_CART_SESSION_KEY])) {
            return null;
        }
        $cart = (new Sender_Cart())->find($id);
        if ($cart && (int) $cart->cart_status === 0 && (new Sender_User())->find($cart->user_id)) {
            // Retain the existing Woo session contract without turning an old
            // binding into a new long-lived Sender session credential.
            return $cart;
        }
        return null;
    }

    /** Contact/account links identify a customer; cart access requires this session's proof. */
    private function senderFindActiveCart($senderUser)
    {
        $wc = $this->senderGetWoo();
        if ($wc && $wc->session) {
            $id = (int) $wc->session->get(self::SENDER_ACTIVE_CART_SESSION_KEY);
            if ($id) {
                $cart = $this->senderFindOwnedSessionCart();
                if ($cart && (int) $cart->cart_status === 0) {
                    if (is_user_logged_in()) {
                        if ($senderUser && (int) $cart->user_id === (int) $senderUser->id) {
                            return $cart;
                        }
                    } elseif (!$this->senderUserId || (int) $cart->user_id === (int) $this->senderUserId) {
                        return $cart;
                    }
                }
                // Reject mappings without signed proof or a matching persisted Woo session.
                $this->senderClearActiveCartSessionMap();
            }
        }

        return null;
    }

    /** Clear the active Sender cart id from Woo session */
    private function senderClearActiveCartSessionMap()
    {
        $wc = $this->senderGetWoo();
        if ($wc && $wc->session) {
            $wc->session->set(self::SENDER_ACTIVE_CART_SESSION_KEY, null);
            $wc->session->__unset(self::SENDER_RECOVERED_CART_SESSION_KEY);
            $wc->session->__unset(self::SENDER_OWNED_CART_SESSION_KEY);
            $wc->session->__unset(self::SENDER_PENDING_CART_TRACK_SESSION_KEY);
        }
    }

    public function senderMarkThankYouSeen()
    {
        $order_id = intval($_GET['order_id'] ?? 0);
        if (!Sender_Order_Confirmation::canConfirm(wc_get_order($order_id))) {
            wp_send_json_error('Order access denied', 403);
            return;
        }
        $converted = false;

        if ($order_id) {
            // Confirm the remote cart conversion server-side before suppressing the fallback.
            $converted = $this->senderConvertCartFallback($order_id);

            if ($converted) {
                set_transient(Sender_Helper::TRANSIENT_SENDER_THANK_YOU . $order_id, 1, 3600);
            }
        }

        wp_send_json_success([
                'order_id' => $order_id,
                'converted' => $converted,
        ]);
    }

    private function senderCanAccessOrder($order): bool
    {
        return Sender_Order_Confirmation::canView($order);
    }

    public function runFallbackIfNotSeen($order_id)
    {
        if (!get_transient(Sender_Helper::TRANSIENT_SENDER_THANK_YOU . $order_id)) {
            $this->senderConvertCartFallback($order_id);
        }
    }

    /**
     * Inject convertCart script on order-received pages (e.g., Braintree redirect)
     */
    public function senderInjectThankYouScript($template)
    {
        if (!function_exists('is_order_received_page') || !is_order_received_page()) {
            return $template;
        }

        global $wp;
        $order_id = absint($wp->query_vars['order-received'] ?? 0);
        if (!$order_id) {
            return $template;
        }
        if (!$this->senderCanAccessOrder(wc_get_order($order_id))) {
            return $template;
        }

        // Avoid duplicate injection
        if (get_transient('sender_thankyou_script_' . $order_id)) {
            return $template;
        }

        $cartData = $this->orderCartData($order_id);
        if (empty($cartData)) {
            $this->senderConvertCart($order_id);
            $cartData = $this->orderCartData($order_id);
            if (empty($cartData)) {
                return $template;
            }
        }

        // Mark as injected
        set_transient('sender_thankyou_script_' . $order_id, 1, 600);

        add_action('wp_footer', function() use ($order_id) {
            echo $this->addConvertCartScript($order_id);
        }, 5);

        $this->scheduleThankYouFallbackCheck($order_id);

        return $template;
    }

}
