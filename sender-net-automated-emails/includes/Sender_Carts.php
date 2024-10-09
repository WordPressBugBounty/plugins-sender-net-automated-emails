<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once 'Sender_Helper.php';

class Sender_Carts
{
    private $sender;
    private $senderUserId = false;

    const TRACK_CART = 'sender-track-cart';
    const UPDATE_CART = 'sender-update-cart';

    const FRAGMENTS_FILTERS = [
        'woocommerce_add_to_cart_fragments',
        'woocommerce_update_order_review_fragments'
    ];

    const SENDER_SUBSCRIBER_ID = 'sender_subscriber_id';

    public function __construct($sender)
    {
        $this->sender = $sender;

        $this->senderAddCartsActions()
            ->senderAddCartsFilters();
    }

    private function senderAddCartsActions()
    {
        //Handle cart changes and convert
        add_action('woocommerce_checkout_order_processed', [$this, 'senderLoadOrderForConvert'], 10, 1);
        add_action('woocommerce_store_api_checkout_order_processed', [$this, 'prepareConvertCart']);
        add_action('woocommerce_cart_updated', [$this, 'senderCartUpdated']);
        add_action('woocommerce_thankyou', [$this, 'senderConvertCart'], 1, 1);

        //Adding subscribe to newsletter checkbox
        add_action('woocommerce_review_order_before_submit', [$this, 'senderAddNewsletterCheck'], 10);
        add_action('woocommerce_edit_account_form', [$this, 'senderAddNewsletterCheck']);
        add_action('woocommerce_register_form', [$this, 'senderAddNewsletterCheck']);

        //Handle sender_newsletter on create/update account
        add_action('woocommerce_created_customer', [$this, 'senderNewsletterHandle'], 10, 1);
        add_action('woocommerce_save_account_details', [$this, 'senderNewsletterHandle'], 10, 1);

        //Handle admin order edit subscribe to newsletter
        add_action('woocommerce_admin_order_data_after_shipping_address', [$this, 'senderAddNewsletterCheck']);

        //Subscribe to newsletter block checkout page
        add_action('wp_enqueue_scripts', [$this, 'senderSubscribeNewsletterBlockEnqueueAssets']);
        add_action('enqueue_block_editor_assets', [$this, 'senderSubscribeNewsletterBlockEnqueueAssets']);

        //Capture email when filling checkout details
        add_action('wp_ajax_trigger_backend_hook', [$this,'triggerEmailCheckout']);
        add_action('wp_ajax_nopriv_trigger_backend_hook', [$this,'triggerEmailCheckout']);

        //Recovered cart visit
        add_action('wp_head', [$this, 'outputSenderTrackVisitorsScript']);

        if (is_admin()) {
            add_action('woocommerce_order_status_changed', [$this, 'senderUpdateOrderStatus']);
            add_action('sender_update_order_status',[$this, 'senderUpdateOrderStatus']);
        }

        return $this;
    }

    private function senderAddCartsFilters()
    {
        add_filter('template_include', [&$this, 'senderRecoverCart'], 99, 1);

        return $this;
    }

    public function senderNewsletterHandle($userId)
    {
        if (!empty($_POST['sender_newsletter'])) {
            update_user_meta($userId, 'email_marketing_consent', Sender_Helper::generateEmailMarketingConsent(Sender_Helper::SUBSCRIBED));
            $this->sender->senderApi->updateCustomer([
                'subscriber_status' => Sender_Helper::UPDATE_STATUS_ACTIVE,
                'sms_status' => Sender_Helper::UPDATE_STATUS_ACTIVE
            ], get_userdata($userId)->user_email);
        } else {
            if (Sender_Helper::shouldChangeChannelStatus($userId, 'user')) {
                update_user_meta(
                    $userId,
                    Sender_Helper::EMAIL_MARKETING_META_KEY,
                    Sender_Helper::generateEmailMarketingConsent(Sender_Helper::UNSUBSCRIBED)
                );
                $this->sender->senderApi->updateCustomer([
                    'subscriber_status' => Sender_Helper::UPDATE_STATUS_UNSUBSCRIBED,
                    'sms_status' => Sender_Helper::UPDATE_STATUS_UNSUBSCRIBED
                ], get_userdata($userId)->user_email);
            }
        }
    }

    public function handleGuestConvertCart($order)
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
            $this->senderCartUpdated();

            return true;
        }else{
            return false;
        }
    }

    public function senderLoadOrderForConvert($orderId)
    {
        $order = wc_get_order($orderId);
        return $this->prepareConvertCart($order);
    }

    public function prepareConvertCart($order)
    {
        $orderId = $order->get_id();
        if (is_user_logged_in()) {
            $currentUser = wp_get_current_user();
            $senderUser = (new Sender_User())->findBy('email', strtolower($currentUser->user_email));
            if (!$senderUser) {
                if (!$this->handleGuestConvertCart($order)){
                    return false;
                }

                $senderUser = (new Sender_User())->findBy('email', strtolower($currentUser->user_email));
            }else{
                $this->senderCartUpdated();
            }
        }else{
            if ($order) {
                if (!$this->handleGuestConvertCart($order)){
                    return false;
                }

                $senderUser = (new Sender_User())->findBy('email', strtolower($order->get_billing_email()));
            }
        }

        if (!isset($senderUser)) {
            return false;
        }

        $cart = (new Sender_Cart())->findByAttributes(
            [
                'user_id' => $senderUser->id,
                'cart_status' => 0
            ],
            'created DESC'
        );

        if(!$cart){
            return false;
        }

        $cart->cart_status = Sender_Helper::CONVERTED_CART;
        $cart->save();

        //Update order && user meta
        if (!empty($_POST['sender_newsletter'])) {
            update_post_meta($orderId, Sender_Helper::EMAIL_MARKETING_META_KEY, Sender_Helper::generateEmailMarketingConsent(Sender_Helper::SUBSCRIBED));
        } else {
            if (Sender_Helper::shouldChangeChannelStatus($orderId, 'order')) {
                update_post_meta(
                    $orderId,
                    Sender_Helper::EMAIL_MARKETING_META_KEY,
                    Sender_Helper::generateEmailMarketingConsent(Sender_Helper::UNSUBSCRIBED)
                );
            } elseif (is_user_logged_in() && Sender_Helper::shouldChangeChannelStatus(get_current_user_id(), 'user')) {
                update_user_meta(
                    get_current_user_id(),
                    Sender_Helper::EMAIL_MARKETING_META_KEY,
                    Sender_Helper::generateEmailMarketingConsent(Sender_Helper::UNSUBSCRIBED)
                );
            }
        }

        if (get_current_user_id()){
            $this->trackUser();
        }

        set_transient(Sender_Helper::TRANSIENT_PREPARE_CONVERT, '1', 5);
    }

    public function senderConvertCart($orderId)
    {
        if (!get_transient(Sender_Helper::TRANSIENT_PREPARE_CONVERT)){
            $this->prepareConvertCart(wc_get_order($orderId));
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

        $cart = (new Sender_Cart())->findByAttributes(
            [
                'user_id' => $senderUser->id,
                'cart_status' => Sender_Helper::CONVERTED_CART
            ],
            'created DESC'
        );

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
            'country' => $wcOrder->get_billing_country()
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
            'store_id' => get_option('sender_store_register') ?: '',
        ];

        if ($list) {
            $cartData['list_id'] = $list;
        }

        $wpUserId = get_current_user_id();
        if ($wpUserId){
            $cartData['customer_id'] = $wpUserId;
        }

        update_post_meta($orderId, Sender_Helper::SENDER_CART_META, $cart->id);
        add_action('sender_add_convert_cart_script', [&$this, 'addConvertCartScript'], 10, 1);
        do_action('sender_add_convert_cart_script', $cartData);
        do_action('sender_get_customer_data', $email, true);

        if (is_user_logged_in()) {
            set_transient(Sender_Helper::TRANSIENT_LOG_IN, 1, 0);
        }
    }

    public function senderPrepareCartData($cart)
    {
        $items = $this->senderGetCart();
        $total = $this->senderGetWoo()->cart->total;
        $user = (new Sender_User())->find($cart->user_id);

        if (!$user){
            return;
        }

        $baseUrl = wc_get_cart_url();
        $lastCharacter = substr($baseUrl, -1);

        if (strcmp($lastCharacter, '/') === 0) {
            $cartUrl = rtrim($baseUrl, '/') . '?hash=' . $cart->id;
        } else {
            $cartUrl = $baseUrl . '&hash=' . $cart->id;
        }

        $data = [
            "external_id" => $cart->id,
            "url" => $cartUrl,
            "currency" => get_option('woocommerce_currency'),
            "order_total" => (string)$total,
            "products" => [],
            'resource_key' => $this->senderGetResourceKey(),
            'store_id' => get_option('sender_store_register') ?: '',
            'email' => $user->email,
            'subscriber_id' => $user->sender_subscriber_id,
        ];

        foreach ($items as $item => $values) {

            $_product = wc_get_product($values['data']->get_id());
            $regularPrice = (int)get_post_meta($values['product_id'], '_regular_price', true);
            $salePrice = (int)get_post_meta($values['product_id'], '_sale_price', true);

            if ($regularPrice <= 0) {
                $regularPrice = 1;
            }

            $discount = round(100 - ($salePrice / $regularPrice * 100));

            $image_url = get_the_post_thumbnail_url($_product->get_id());
            if (!$image_url) {
                $gallery_image_ids = $_product->get_gallery_image_ids();
                if (!empty($gallery_image_ids)) {
                    $image_url = wp_get_attachment_url($gallery_image_ids[0]);
                }
            }

            if (!$image_url) {
                $image_url = '';
            }

            $prod = [
                'sku' => $_product->get_sku(),
                'name' => (string)$_product->get_title(),
                'price' => (string)$regularPrice,
                'price_display' => (string)$_product->get_price() . get_woocommerce_currency_symbol(),
                'discount' => (string)$discount,
                'qty' => $values['quantity'],
                'image' => $image_url,
                'product_id' => $_product->get_id(),
                'description' => strip_tags($_product->get_description()),
            ];

            $data['products'][] = $prod;
        }

        return $data;
    }

    public function trackUser()
    {
        if (!is_user_logged_in()) {
            return false;
        }

        $wpUser = wp_get_current_user();
        $wpId = $wpUser->ID;

        $user = (new Sender_User())->findBy('wp_user_id', $wpId);

        if (!$user) {
            $user = new Sender_User();
            $user->wp_user_id = $wpId;
            $user->email = strtolower($wpUser->user_email);
            $user->save();
            $this->sender->senderApi->senderTrackRegisteredUsers($wpId);
        }

        if (isset($_POST['sender_newsletter'])){
            $this->senderNewsletterHandle($wpId);
        }

        if ($user->isDirty()) {
            $this->sender->senderApi->senderApiShutdownCallback("senderTrackRegisteredUsers", $wpId);
        }

        $emailMarketingConsent = get_user_meta($wpId, Sender_Helper::EMAIL_MARKETING_META_KEY, true);
        if (empty($emailMarketingConsent)) {
            $this->updateUserEmailMarketingConsent($user->email, $wpId);
        }

        return true;
    }

    public function senderCartUpdated()
    {
        if (isset($_GET['hash'])){
            return;
        }

        if (!$this->senderUserId && !$this->trackUser() && !isset($_COOKIE[self::SENDER_SUBSCRIBER_ID])) {
            return;
        }

        $items = $this->senderGetCart();

        $cartData = serialize($items);

        if (!$this->senderGetWoo()->session->get_session_cookie()) {
            if (empty($items)){
                return;
            }

            //Making the woocommerce cookie active when adding from general view
            WC()->session->set_customer_session_cookie(true);
        }

        if(isset($_COOKIE['sender_recovered_cart'])){
            $cart = (new Sender_Cart())->find($_COOKIE['sender_recovered_cart']);
        }

        //Look for possible cart NOT converted in a connected user
        if (!isset($cart)){
            if (is_user_logged_in()) {
                $currentUser = wp_get_current_user();
                $user = (new Sender_User())->findBy('email', $currentUser->user_email);
            }elseif($this->senderUserId){
                $user = (new Sender_User())->find($this->senderUserId);
            }elseif (isset($_COOKIE[self::SENDER_SUBSCRIBER_ID])){
                $user = (new Sender_User())->findBy('sender_subscriber_id', $_COOKIE[self::SENDER_SUBSCRIBER_ID]);
            }

            #find if current user has any abandoned carts
            if (!empty($user)){
                $cart = (new Sender_Cart())->findByAttributes(
                    [
                        'user_id' => $user->id,
                        'cart_status' => 0
                    ],
                    'created DESC'
                );
            }
        }

        if (empty($items) && isset($cart) && $cart instanceof Sender_Cart) {
            #Keep converted carts and unpaid carts
            if ($cart->cart_status == Sender_Helper::CONVERTED_CART || $cart->status === Sender_Helper::UNPAID_CART) {
                $cart = false;
            }else {
                $cart->delete();
                $this->sender->senderApi->senderApiShutdownCallback("senderDeleteCart", $cart->id);
                return;
            }
        }

        //Update cart
        if (isset($cart) && $cart instanceof Sender_Cart && !empty($items)) {
            $oldUpdatedValue = $cart->updated;
            $cart->cart_data = $cartData;
            $cart->update();

            //Fetch model for comparing updated value after changes
            $updatedCart = (new Sender_Cart())->find($cart->id);

            if ($oldUpdatedValue === $updatedCart->updated){
                return;
            }

            $cartData = $this->senderPrepareCartData($cart);

            if (!$cartData) {
                return;
            }

            if (wp_doing_ajax()) {
                if (get_option('woocommerce_cart_redirect_after_add') === 'yes') {
                    $this->sender->senderApi->senderApiShutdownCallback("senderUpdateCart", $cartData);
                    return;
                }
                $this->handleCartFragmentsFilters(json_encode($cartData), self::UPDATE_CART);
            } else {
                $this->sender->senderApi->senderApiShutdownCallback("senderUpdateCart", $cartData);
            }

            return;
        }

        if (!empty($items)) {
            if (!$this->senderUserId){
                if (!$senderUser = $this->senderGetVisitor()) {
                    return;
                }
            }

            $newCart = new Sender_Cart();
            $newCart->cart_data = $cartData;
            $newCart->user_id = $this->senderUserId ?: $senderUser->id;
            $newCart->save();

            $cartData = $this->senderPrepareCartData($newCart);
            if (!$cartData) {
                return;
            }

            //Guest checkout
            if ($this->senderUserId){
                $senderUser = (new Sender_User())->find($this->senderUserId);
                if ($senderUser) {
                    $cartData['email'] = $senderUser->email;
                    $this->sender->senderApi->senderTrackCart($cartData);
                }
                return;
            }

            if (wp_doing_ajax()) {
                if (get_option('woocommerce_cart_redirect_after_add') === 'yes') {
                    $this->sender->senderApi->senderApiShutdownCallback("senderTrackCart", $cartData);
                    return;
                }

                $this->sender->senderApi->senderTrackCart($cartData);
            } else {
                $this->sender->senderApi->senderApiShutdownCallback("senderTrackCart", $cartData);
            }
        }
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
            $senderUser = new Sender_User();
            $senderUser->sender_subscriber_id = $_COOKIE[self::SENDER_SUBSCRIBER_ID];
            $senderUser->save();
            return $senderUser;
        }

        return false;
    }

    public function senderGetCart()
    {
        return $this->senderGetWoo()->cart->get_cart();
    }

    public function senderGetWoo()
    {
        global $woocommerce;

        if (function_exists('WC')) {
            return WC();
        }

        return $woocommerce;
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

    public function senderAddNewsletterCheck($order)
    {
        if (get_option('sender_subscribe_label') && !empty(get_option('sender_subscribe_to_newsletter_string'))) {
            if (is_admin()) {
                $emailMarketingConsent = $order->get_meta(Sender_Helper::EMAIL_MARKETING_META_KEY);
                if (!empty($emailMarketingConsent)) {
                    $currentValue = Sender_Helper::handleChannelStatus($emailMarketingConsent);
                } else {
                    $currentValue = $order->get_meta('sender_newsletter');
                }
            } else {
                $emailMarketingConsent = get_user_meta(get_current_user_id(),
                    Sender_Helper::EMAIL_MARKETING_META_KEY, true);
                if (!empty($emailMarketingConsent)) {
                    $currentValue = Sender_Helper::handleChannelStatus($emailMarketingConsent);
                } else {
                    $currentValue = get_user_meta(get_current_user_id(), 'sender_newsletter', true);
                }
            }

            woocommerce_form_field('sender_newsletter', array(
                'type' => 'checkbox',
                'class' => array('form-row mycheckbox'),
                'label_class' => array('woocommerce-form__label woocommerce-form__label-for-checkbox checkbox'),
                'input_class' => array('woocommerce-form__input woocommerce-form__input-checkbox input-checkbox'),
                'label' => get_option('sender_subscribe_to_newsletter_string'),
            ), $currentValue);
        }
    }

    public function addConvertCartScript($cartData)
    {
        ob_start();
        echo "
			<script>
			sender('convertCart', " . json_encode($cartData) . ")
            </script>
		";
    }

    public function addTrackCartScript($cartData)
    {
        ob_start();
        ?>
        <script>
            sender('trackCart', <?php echo json_encode($cartData); ?>);
        </script>
        <?php
    }

    public function addStatusCartUpdateScript($cartData)
    {
        ob_start();
        echo "
			<script>
			sender('statusCartUpdate', " . json_encode($cartData) . ")
            </script>
		";
    }

    public function triggerEmailCheckout()
    {
        if (isset($_POST['email']) && !empty($_POST['email'])) {
            $sanitizedEmail = strtolower(sanitize_text_field($_POST['email']));
            $response = $this->sender->senderApi->senderTrackNotRegisteredUsers(['email' => $sanitizedEmail]);
            if($response) {
                $senderUser = (new Sender_User())->findBy('email', $sanitizedEmail);
                if (!$senderUser) {
                    $senderUser = new Sender_User();
                    $senderUser->email = $sanitizedEmail;

                    if (!$senderUser->save()) {
                        return wp_send_json_error('Error saving user');
                    }
                }
                $this->senderUserId = $senderUser->id;
                $this->senderCartUpdated();

                return wp_send_json_success($response);
            }
            return wp_send_json_error('Subscriber not created');
        }
        return wp_send_json_error('Email is required');
    }

    //Use to convert carts which got confirmed payment
    public function senderUpdateOrderStatus($orderId)
    {
        if (!isset($_POST['order_status'])) {
            return;
        }

        $newOrderStatus = $_POST['order_status'];
        $senderRemoteCartId = get_post_meta($orderId, Sender_Helper::SENDER_CART_META, true);

        if (!empty($senderRemoteCartId)){
            #Check if cart exists
            $cart = (new Sender_Cart())->findByAttributes(
                [
                    'id' => $senderRemoteCartId,
                ]
            );

            if (!$cart){
                return;
            }

            switch ($newOrderStatus) {
                case Sender_Helper::ORDER_PAID:
                    $wcOrder = wc_get_order($orderId);
                    $list = get_option('sender_customers_list');

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
                        'country' => $wcOrder->get_billing_country()
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
                        'email' => strtolower($wcOrder->get_billing_email()),
                        'firstname' => $wcOrder->get_billing_first_name(),
                        'lastname' => $wcOrder->get_billing_last_name(),
                        'resource_key' => $this->senderGetResourceKey(),
                        'phone' => $wcOrder->get_billing_phone(),
                        'order_id' => (string)$orderId,
                        'billing' => $billing,
                        'shipping' => $shipping,
                        'order_details' => $orderDetails,
                        'store_id' => get_option('sender_store_register') ?: '',
                    ];

                    if ($list) {
                        $cartData['list_id'] = $list;
                    }

                    $user = get_user_by('email', $cartData['email']);

                    if ($user) {
                        $cartData['customer_id'] = $user->ID;
                    }

                    if ($this->sender->senderApi->senderConvertCart($cart->id, $cartData)) {
                        $cart->cart_status = Sender_Helper::CONVERTED_CART;
                        $cart->save();
                        do_action('sender_get_customer_data', $cartData['email'], true);
                    }
                    return;
                case Sender_Helper::ORDER_COMPLETED || Sender_Helper::ORDER_PENDING_PAYMENT:
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
    }

    public function updateUserEmailMarketingConsent($email, $userId)
    {
        $subscriber = $this->sender->senderApi->getSubscriber($email);
        if ($subscriber) {
            if (isset($subscriber->data->status->email)) {
                $emailStatusFromSender = strtoupper($subscriber->data->status->email);
                switch ($emailStatusFromSender) {
                    case Sender_Helper::UPDATE_STATUS_ACTIVE:
                        $status = Sender_Helper::SUBSCRIBED;
                        break;
                    case Sender_Helper::UPDATE_STATUS_UNSUBSCRIBED:
                        $status = Sender_Helper::UNSUBSCRIBED;
                        break;
                }

                if (isset($status)) {
                    update_user_meta(
                        $userId,
                        Sender_Helper::EMAIL_MARKETING_META_KEY,
                        Sender_Helper::generateEmailMarketingConsent($status)
                    );
                }
            }
        }
    }

    public function senderRecoverCart($template)
    {
        if (!isset($_GET['hash'])) {
            return $template;
        }

        $cartId = sanitize_text_field($_GET['hash']);

        $cart = (new Sender_Cart())->find($cartId);
        if (!$cart || $cart->cart_recovered || $cart->cart_status == Sender_Helper::CONVERTED_CART) {
            return wp_redirect(wc_get_cart_url());
        }

        $cart->cart_recovered = '1';
        $cart->save();

        $cartData = unserialize($cart->cart_data);

        if (empty($cartData)) {
            return $template;
        }

        $wooCart = new WC_Cart();

        foreach ($cartData as $product) {
            $wooCart->add_to_cart(
                (int)$product['product_id'],
                (int)$product['quantity'],
                (int)$product['variation_id'],
                $product['variation']
            );
        }

        if (is_user_logged_in()){
            set_transient(Sender_Helper::TRANSIENT_RECOVER_CART, '1', 5);
        }

        setcookie('sender_recovered_cart', $cartId, time() + 3600, COOKIEPATH, COOKIE_DOMAIN);
        new WC_Cart_Session($wooCart);
        return wp_redirect(wc_get_cart_url());
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

    //Block checkout subscribe to newsletter
    public function senderSubscribeNewsletterBlockEnqueueAssets()
    {
        wp_enqueue_script(
            'subscribe-newsletter-block',
            plugins_url('js/subscribe-newsletter.block.js', __FILE__),
            ['wp-blocks', 'wp-i18n', 'wp-element'],
            filemtime(plugin_dir_path(__FILE__) . 'js/subscribe-newsletter.block.js')
        );

        wp_localize_script(
            'subscribe-newsletter-block',
            'senderNewsletter',
            [
                'storeId' => get_option('sender_store_register'),
                'senderCheckbox' => $this->senderSubscribeNewsletterText(),
                'senderAjax' => admin_url('admin-ajax.php'),
            ]
        );
    }

    public function senderSubscribeNewsletterText()
    {
        if (get_option('sender_subscribe_label') && !empty(get_option('sender_subscribe_to_newsletter_string'))) {
            return get_option('sender_subscribe_to_newsletter_string');
        }
    }

}