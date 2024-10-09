<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once plugin_dir_path(__FILE__) . 'Sender_Helper.php';

class Sender_WooCommerce
{
    private $sender;
    private $tablePrefix;

    public function __construct($sender, $update = false)
    {
        if (!Sender_Helper::senderIsWooEnabled()){
            return;
        }

        $this->sender = $sender;
        add_action('woocommerce_single_product_summary', [$this, 'senderAddProductImportScript'], 10, 2);

        //Declare action for cron job to sync data from interface
        add_action('sender_export_shop_data_cron', [$this, 'senderExportShopDataCronJob']);

        //Declare action for cron job to sync from webhook
        add_action('sender_schedule_sync_cron_job', [$this, 'scheduleSenderExportShopDataCronJob']);

        //Get order counts data
        add_action('sender_get_customer_data', [$this, 'senderGetCustomerData'], 10, 2);

        if (is_admin()) {
            if (get_option('sender_subscribe_label') && !empty(get_option('sender_subscribe_to_newsletter_string'))) {
                add_action('edit_user_profile', [$this, 'senderNewsletter']);
            }
            //From wp edit users admin side
            add_action('edit_user_profile_update', [$this, 'senderUpdateCustomerData'], 10, 1);

            //From woocommerce admin side
            add_action('woocommerce_process_shop_order_meta', [$this, 'senderAddUserAfterManualOrderCreation'], 51);

            add_action('before_delete_post', [$this, 'senderRemoveSubscriber']);
        }

        if ($update) {
            if (!get_option('sender_wocommerce_sync')) {
                $storeActive = $this->sender->senderApi->senderGetStore();
                if (!$storeActive && !isset($storeActive->xRate)) {
                    $this->sender->senderHandleAddStore();
                    $storeActive = true;
                }

                if ($storeActive && get_option('sender_store_register')) {
                    $this->scheduleSenderExportShopDataCronJob();
                }
            }
        }

    }

    //Adding default delay of 60 seconds.Using delay 30 seconds when called from interface.
    public function scheduleSenderExportShopDataCronJob($delay = 5)
    {
        if (!wp_next_scheduled('sender_export_shop_data_cron')) {
            wp_schedule_single_event(time() + (int)$delay, 'sender_export_shop_data_cron');
        }
    }

    public function senderExportShopDataCronJob()
    {
        $this->getTablePrefix();
        $this->exportCustomers();
        $this->exportProducts();
        $this->exportOrders();
        update_option('sender_wocommerce_sync', true);
        update_option('sender_synced_data_date', current_time('Y-m-d H:i:s'));

        // Set a transient to indicate that the sync has finished
        set_transient(Sender_Helper::TRANSIENT_SYNC_FINISHED, true, 30);

        return true;
    }

    public function senderRemoveSubscriber($postId)
    {
        if (get_post_type($postId) === 'shop_order') {
            $billingEmail = get_post_meta($postId, '_billing_email', true);
            if (!empty($billingEmail)) {
                $this->sender->senderApi->deleteSubscribers(['subscribers' => [$billingEmail]]);
            }
        }
    }

    public function senderNewsletter($user)
    {
        $emailConsent = get_user_meta($user->ID, Sender_Helper::EMAIL_MARKETING_META_KEY, true);
        if (!empty($emailConsent)) {
            $currentValue = Sender_Helper::handleChannelStatus($emailConsent);
        }

        if (!isset($currentValue)) {
            $currentValue = (int)get_user_meta($user->ID, 'sender_newsletter', true);
        }
        ?>
        <div>
            <h3>Newsletter Subscription</h3>
            <table class="form-table">
                <tbody>
                <tr class="show-admin-bar user-admin-bar-front-wrap">
                    <th scope="row"><?php _e('Subscribed to newsletter', 'sender-net-automated-emails')?></th>
                    <td>
                        <label for="sender_newsletter">
                            <input name="sender_newsletter" type="checkbox"
                                <?php echo $currentValue === 1 ? 'checked' : '' ?> value="1">
                        </label>
                        <br>
                        <br>
                        <span><?php _e('You should ask your customers for permission before you subscribe them to your marketing emails.','sender-net-automated-emails')?></span>
                    </td>
                </tr>
                </tbody>
            </table>
        </div>
        <?php
    }


    public function senderUpdateCustomerData($userId)
    {
        $changedFields = [];
        if (isset($_POST['sender_newsletter'])) {
            update_user_meta(
                $userId,
                Sender_Helper::EMAIL_MARKETING_META_KEY,
                Sender_Helper::generateEmailMarketingConsent(Sender_Helper::SUBSCRIBED)
            );
            $changedFields['subscriber_status'] = Sender_Helper::UPDATE_STATUS_ACTIVE;
            $changedFields['sms_status'] = Sender_Helper::UPDATE_STATUS_ACTIVE;
        } else {
            if (Sender_Helper::shouldChangeChannelStatus($userId, 'user')) {
                update_user_meta(
                    $userId,
                    Sender_Helper::EMAIL_MARKETING_META_KEY,
                    Sender_Helper::generateEmailMarketingConsent(Sender_Helper::UNSUBSCRIBED)
                );
                $changedFields['subscriber_status'] = Sender_Helper::UPDATE_STATUS_UNSUBSCRIBED;
                $changedFields['sms_status'] = Sender_Helper::UPDATE_STATUS_UNSUBSCRIBED;
            }
        }

        $oldUserData = get_userdata($userId);

        $oldFirstname = $oldUserData->first_name;
        $oldLastName = $oldUserData->last_name;

        $updatedFirstName = $_POST['first_name'] ?: $_POST['billing_first_name'] ?: '';
        $updatedLastName = $_POST['last_name'] ?: $_POST['billing_last_name'] ?: '';

        if ($oldFirstname !== $updatedFirstName) {
            $changedFields['firstname'] = $updatedFirstName;
        }

        if ($oldLastName !== $updatedLastName) {
            $changedFields['lastname'] = $updatedLastName;
        }

        $oldUserMetaData = get_user_meta($userId);
        if (!empty($oldUserMetaData['billing_phone'][0])) {
            $oldPhone = $oldUserMetaData['billing_phone'][0];
            $updatedPhone = $_POST['billing_phone'] ?: '';

            if ($oldPhone !== $updatedPhone){
                $changedFields['phone'] = $updatedPhone;
            }
        }

        if (!empty($changedFields)) {
            $this->sender->senderApi->updateCustomer($changedFields, get_userdata($userId)->user_email);
        }
    }

    public function senderAddUserAfterManualOrderCreation($orderId)
    {
        $postMeta = get_post_meta($orderId);

        if (!isset($postMeta['_billing_email'][0])) {
            return;
        }

        $email = $postMeta['_billing_email'][0];
        $senderUser = (new Sender_User())->findBy('email', $email);

        #Order update, created from interface
        if (isset($postMeta[Sender_Helper::SENDER_CART_META]) || $senderUser) {
            $subscriberData = [];
            if (isset($_POST['_billing_first_name'])) {
                $subscriberData['firstname'] = $_POST['_billing_first_name'];
            }

            if (isset($_POST['_billing_last_name'])) {
                $subscriberData['lastname'] = $_POST['_billing_last_name'];
            }

            if (isset($_POST['_billing_phone'])) {
                $subscriberData['phone'] = $_POST['_billing_phone'];
            }

            $channelStatusData = $this->handleSenderNewsletterFromDashboard($orderId, $subscriberData, true);
            $subscriberData = array_merge($subscriberData, $channelStatusData);

            $this->sender->senderApi->updateCustomer($subscriberData, $email);
            $emailMarketingConset = get_post_meta($orderId, Sender_Helper::EMAIL_MARKETING_META_KEY, true);
            if (empty($emailMarketingConset)) {
                $this->updateEmailMarketingConsent($email, $orderId);
            }
        } else {
            #New order created from woocomerce dashboard
            $subscriberData = [
                'email' => $email
            ];

            if (isset($_POST['_billing_first_name'])) {
                $subscriberData['firstname'] = $_POST['_billing_first_name'];
            }

            if (isset($_POST['_billing_last_name'])) {
                $subscriberData['lastname'] = $_POST['_billing_last_name'];
            }

            if (get_option('sender_customers_list')) {
                $subscriberData['list_id'] = get_option('sender_customers_list');
            }

            if (isset($_POST['_billing_phone'])) {
                $subscriberData['phone'] = $_POST['_billing_phone'];
            }

            $channelStatusData = $this->handleSenderNewsletterFromDashboard($orderId, $subscriberData, false);
            $subscriberData = array_merge($subscriberData, $channelStatusData);
            $this->sender->senderApi->senderTrackNotRegisteredUsers($subscriberData);

            $senderUser = new Sender_User();
            $senderUser->email = $email;
            if (isset($subscriberData['firstname'])) {
                $senderUser->first_name = $subscriberData['firstname'];
            }

            if (isset($subscriberData['lastname'])) {
                $senderUser->last_name = $subscriberData['lastname'];
            }

            $senderUser->save();

            $emailMarketingConset = get_post_meta($orderId, Sender_Helper::EMAIL_MARKETING_META_KEY, true);
            if (empty($emailMarketingConset)) {
                $this->updateEmailMarketingConsent($email, $orderId);
            }

            $this->senderProcessOrderFromWoocommerceDashboard($orderId, $senderUser);
        }
    }

    public function updateEmailMarketingConsent($email, $id)
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
                    update_post_meta(
                        $id,
                        Sender_Helper::EMAIL_MARKETING_META_KEY,
                        Sender_Helper::generateEmailMarketingConsent($status)
                    );
                }
            }
        }
    }

    private function handleSenderNewsletterFromDashboard($orderId, $subscriberData, $updateSubscriber)
    {
        $channelStatusData = [];
        $attachSubscriber = [];

        if (isset($_POST['sender_newsletter'])) {
            update_post_meta(
                $orderId,
                Sender_Helper::EMAIL_MARKETING_META_KEY,
                Sender_Helper::generateEmailMarketingConsent(Sender_Helper::SUBSCRIBED)
            );
            if ($updateSubscriber) {
                $channelStatusData['subscriber_status'] = Sender_Helper::UPDATE_STATUS_ACTIVE;
                $channelStatusData['sms_status'] = Sender_Helper::UPDATE_STATUS_ACTIVE;
            } else {
                $attachSubscriber['newsletter'] = true;
            }
        } else {
            if (Sender_Helper::shouldChangeChannelStatus($orderId, 'order')) {
                update_post_meta(
                    $orderId,
                    Sender_Helper::EMAIL_MARKETING_META_KEY,
                    Sender_Helper::generateEmailMarketingConsent(Sender_Helper::UNSUBSCRIBED)
                );
                if ($updateSubscriber) {
                    $channelStatusData['subscriber_status'] = Sender_Helper::UPDATE_STATUS_UNSUBSCRIBED;
                    $channelStatusData['sms_status'] = Sender_Helper::UPDATE_STATUS_UNSUBSCRIBED;
                }
            } elseif (isset($subscriberData['phone'])) {
                if ($updateSubscriber) {
                    $channelStatusData['sms_status'] = Sender_Helper::UPDATE_STATUS_NON_SUBSCRIBED;
                }
            }
        }

        if (!empty($channelStatusData)){
            return $channelStatusData;
        }

        if (!empty($attachSubscriber)){
            return $attachSubscriber;
        }

        return [];
    }

    public function senderAddProductImportScript()
    {
        global $product;

        $id = $product->get_id();

        $pImage = get_the_post_thumbnail_url($id);
        if (!$pImage) {
            $gallery = $product->get_gallery_image_ids();
            if (!empty($gallery)) {
                $pImage = wp_get_attachment_url($gallery[0]);
            }
        }

        if ($product->is_type('grouped')) {
            $pPriceHtml = $product->get_price_html();
            preg_match_all('/[\d,]+/', $pPriceHtml, $matches);
            $pPrice = implode(' - ', $matches[0]);
        } else {
            $pPrice = (float) $product->get_regular_price();
        }

        $pName = str_replace("\"", '\\"', $product->get_name());
        $pDescription = htmlspecialchars($product->get_description(), ENT_QUOTES, 'UTF-8');
        $pCurrency = get_option('woocommerce_currency');
        $pQty = $product->get_stock_quantity() ? $product->get_stock_quantity() : 1;
        $pRating = $product->get_average_rating();
        $pOnSale = $product->is_on_sale();
        $pDiscount = 0;

        if ($pOnSale && !empty($product->get_sale_price())) {
            $pSalePrice = $product->get_sale_price();
            $pDiscount = round((string)100 - ($pSalePrice / $pPrice * 100));
        }

        $jsonData = [
            "name" => $pName,
            "image" => $pImage,
            "description" => $pDescription,
            "price" => $pPrice,
            "currency" => $pCurrency,
            "quantity" => $pQty,
            "rating" => $pRating,
        ];

        if (isset($pSalePrice)) {
            $jsonData['is_on_sale'] = $pOnSale;
            $jsonData["special_price"] = (float)$pSalePrice;
            $jsonData["discount"] = "-" . $pDiscount . "%";
        }

        ob_start();
        ?>
        <script type="application/sender+json"><?php echo json_encode($jsonData); ?></script>
        <?php
        $script_code = ob_get_clean();
        echo $script_code;
    }

    private function getWooClientsOrderCompleted($chunkSize, $offset = 0)
    {
        global $wpdb;
        return $wpdb->get_results("
            SELECT DISTINCT
                pm1.meta_value AS first_name,
                pm2.meta_value AS last_name,
                pm3.meta_value AS phone,
                pm4.meta_value AS email,
                pm5.meta_value AS newsletter,
                pm6.meta_value AS email_marketing_consent
            FROM
                " . $this->tablePrefix . "posts AS o
                LEFT JOIN " . $this->tablePrefix . "postmeta AS pm1 ON o.ID = pm1.post_id AND pm1.meta_key = '_billing_first_name'
                LEFT JOIN " . $this->tablePrefix . "postmeta AS pm2 ON o.ID = pm2.post_id AND pm2.meta_key = '_billing_last_name'
                LEFT JOIN " . $this->tablePrefix . "postmeta AS pm3 ON o.ID = pm3.post_id AND pm3.meta_key = '_billing_phone'
                LEFT JOIN " . $this->tablePrefix . "postmeta AS pm4 ON o.ID = pm4.post_id AND pm4.meta_key = '_billing_email'
                LEFT JOIN " . $this->tablePrefix . "postmeta AS pm5 ON o.ID = pm5.post_id AND pm5.meta_key = 'sender_newsletter'
                LEFT JOIN " . $this->tablePrefix . "postmeta AS pm6 ON o.ID = pm6.post_id AND pm6.meta_key = 'email_marketing_consent'
            WHERE
                o.post_type = 'shop_order'
                AND o.post_status IN ('wc-completed', 'wc-on-hold')
                AND pm4.meta_value IS NOT NULL
            LIMIT $chunkSize
            OFFSET $offset
        ");
    }

    private function getWooClientsOrderNotCompleted($chunkSize = null, $offset = 0)
    {
        global $wpdb;
        return $wpdb->get_results("
             SELECT DISTINCT
                pm1.meta_value AS first_name,
                pm2.meta_value AS last_name,
                pm3.meta_value AS phone,
                pm4.meta_value AS email,
                pm5.meta_value AS newsletter,
                pm6.meta_value AS email_marketing_consent
            FROM
                " . $this->tablePrefix . "posts AS o
                LEFT JOIN " . $this->tablePrefix . "postmeta AS pm1 ON o.ID = pm1.post_id AND pm1.meta_key = '_billing_first_name'
                LEFT JOIN " . $this->tablePrefix . "postmeta AS pm2 ON o.ID = pm2.post_id AND pm2.meta_key = '_billing_last_name'
                LEFT JOIN " . $this->tablePrefix . "postmeta AS pm3 ON o.ID = pm3.post_id AND pm3.meta_key = '_billing_phone'
                LEFT JOIN " . $this->tablePrefix . "postmeta AS pm4 ON o.ID = pm4.post_id AND pm4.meta_key = '_billing_email'
                LEFT JOIN " . $this->tablePrefix . "postmeta AS pm5 ON o.ID = pm5.post_id AND pm5.meta_key = 'sender_newsletter'
                LEFT JOIN " . $this->tablePrefix . "postmeta AS pm6 ON o.ID = pm6.post_id AND pm6.meta_key = 'email_marketing_consent'
            WHERE
                o.post_type = 'shop_order'
                AND o.post_status NOT IN ('wc-completed', 'wc-on-hold')
                AND pm4.meta_value IS NOT NULL
            LIMIT $chunkSize
            OFFSET $offset
        ");
    }

    public function exportCustomers()
    {
        global $wpdb;
        $chunkSize = 200;

        #Extract customers which completed order
        $totalCompleted = $wpdb->get_var("SELECT COUNT(DISTINCT pm.meta_value)
        FROM
            " . $this->tablePrefix . "posts AS o
            LEFT JOIN " . $this->tablePrefix . "postmeta AS pm ON o.ID = pm.post_id AND pm.meta_key = '_billing_email'
        WHERE
            o.post_type = 'shop_order'
            AND o.post_status IN ('wc-completed', 'wc-on-hold', 'wc-processing')
            AND pm.meta_value IS NOT NULL");

        $clientCompleted = 0;
        if ($totalCompleted > $chunkSize) {
            $loopTimes = floor($totalCompleted / $chunkSize);
            for ($x = 0; $x <= $loopTimes; $x++) {
                $woocommerceClientOrdersCompleted = $this->getWooClientsOrderCompleted($chunkSize, $clientCompleted);
                $customerList = json_decode(json_encode($woocommerceClientOrdersCompleted), true);
                $this->sendWoocommerceCustomersToSender($customerList, get_option('sender_customers_list'));
                $clientCompleted += $chunkSize;
            }
        } else {
            $woocommerceClientOrdersCompleted = $this->getWooClientsOrderCompleted($chunkSize);
            $customerList = json_decode(json_encode($woocommerceClientOrdersCompleted), true);
            $this->sendWoocommerceCustomersToSender($customerList, get_option('sender_customers_list'));
        }

        #Extract customers which did not complete order
        $totalNotCompleted = $wpdb->get_var("SELECT COUNT(DISTINCT pm.meta_value)
        FROM
            " . $this->tablePrefix . "posts AS o
            LEFT JOIN " . $this->tablePrefix . "postmeta AS pm ON o.ID = pm.post_id AND pm.meta_key = '_billing_email'
        WHERE
            o.post_type = 'shop_order'
            AND o.post_status NOT IN ('wc-completed', 'wc-on-hold', 'wc-processing')
            AND pm.meta_value IS NOT NULL");

        $clientNotCompleted = 0;
        if ($totalNotCompleted > $chunkSize) {
            $loopTimes = floor($totalNotCompleted / $chunkSize);
            for ($x = 0; $x <= $loopTimes; $x++) {
                $woocommerceClientOrdersNotCompleted = $this->getWooClientsOrderNotCompleted($chunkSize, $clientNotCompleted);
                $customerList = json_decode(json_encode($woocommerceClientOrdersNotCompleted), true);
                $this->sendWoocommerceCustomersToSender($customerList);
                $clientNotCompleted += $chunkSize;
            }
        } else {
            $woocommerceClientOrdersNotCompleted = $this->getWooClientsOrderNotCompleted($chunkSize);
            $customerList = json_decode(json_encode($woocommerceClientOrdersNotCompleted), true);
            $this->sendWoocommerceCustomersToSender($customerList);
        }

        #Extract WP users with role customer. Registrations
        $usersQuery = new WP_User_Query(['fields' => 'id', 'role' => 'customer']);
        $usersCount = $usersQuery->get_total();
        $usersExported = 0;
        if ($usersCount > $chunkSize) {
            $loopTimes = floor($usersCount / $chunkSize);
            for ($x = 0; $x <= $loopTimes; $x++) {
                $usersQuery = new WP_User_Query([
                    'fields' => 'id',
                    'role' => 'customer',
                    'number' => $chunkSize,
                    'offset' => $usersExported
                ]);
                $customerList = json_decode(json_encode($usersQuery->get_results(), true));
                $this->sendUsersToSender($customerList);
                $usersExported += $chunkSize;
            }
        } else {
            $customerList = json_decode(json_encode($usersQuery->get_results(), true));
            $this->sendUsersToSender($customerList);
        }
    }

    public function sendWoocommerceCustomersToSender($customers, $list = null)
    {
        $customersExportData = [];
        foreach ($customers as $customer) {
            if ($list) {
                $customer['tags'] = [$list];
            }

            if (isset($customer[Sender_Helper::EMAIL_MARKETING_META_KEY])) {
                $customer[Sender_Helper::EMAIL_MARKETING_META_KEY] = unserialize($customer[Sender_Helper::EMAIL_MARKETING_META_KEY]);
            } else {
                //Removing null values
                unset($customer[Sender_Helper::EMAIL_MARKETING_META_KEY]);
            }

            if (isset($customer['newsletter'])) {
                $customer['newsletter'] = (bool)$customer['newsletter'];
            } else {
                //Removing null values
                unset($customer['newsletter']);
            }

            $customFields = $this->senderGetCustomerData($customer['email']);
            if (!empty($customFields)) {
                $customer['fields'] = $customFields;
            }
            $customersExportData[] = $customer;
        }

        $this->sender->senderApi->senderExportData(['customers' => $customersExportData]);
    }

    public function senderGetCustomerData($email, $update = false)
    {
        if (empty($email)) {
            return;
        }

        global $wpdb;
        $orders = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT pm.post_id
        FROM {$wpdb->postmeta} AS pm
        WHERE pm.meta_key = '_billing_email'
        AND pm.meta_value = %s",
                $email
            )
        );

        $totalSpent = 0;
        $ordersCount = count($orders);
        if ($ordersCount > 0) {
            foreach ($orders as $key => $orderId) {
                $totalSpent += get_post_meta($orderId, '_order_total', true);
                $isLastIteration = ($key === ($ordersCount - 1));
                if ($isLastIteration) {
                    $last_order_name = '#' . $orderId;
                    $last_order_currency = get_post_meta($orderId, '_order_currency', true);
                }
            }
            $ordersData = [
                'orders_count' => $ordersCount,
                'total_spent' => $totalSpent,
                'last_order_number' => $last_order_name,
                'currency' => $last_order_currency,
            ];
        }

        if($update && isset($ordersData)){
            $this->sender->senderApi->updateCustomer(['fields' => $ordersData], $email);
            return true;
        }

        if (isset($ordersData)) {
            return $ordersData;
        }
    }

    public function sendUsersToSender($customers)
    {
        $customersExportData = [];
        foreach ($customers as $customerId) {
            $customer = get_user_meta($customerId);
            if (!empty($customer['billing_email'][0])) {
                $email = $customer['billing_email'][0];
            } elseif (!empty(get_userdata($customerId)->user_email)) {
                $email = get_userdata($customerId)->user_email;
            } else {
                continue;
            }

            $data = [
                'id' => $customerId,
                'email' => $email,
                'firstname' => $customer['first_name'][0] ?: null,
                'lastname' => $customer['last_name'][0] ?: null,
                'tags' => [get_option('sender_registration_list')],
            ];

            if (isset($customer['billing_phone'][0])) {
                $data['phone'] = $customer['billing_phone'][0];
            }

            if (isset($customer['newsletter'])) {
                $customer['newsletter'] = (bool)$customer['newsletter'];
            } else {
                //Removing null values
                unset($customer['newsletter']);
            }

            //Adding email_marketing_consent if present
            if (isset($customer[Sender_Helper::EMAIL_MARKETING_META_KEY][0])) {
                $data[Sender_Helper::EMAIL_MARKETING_META_KEY] = unserialize($customer[Sender_Helper::EMAIL_MARKETING_META_KEY][0]);
            }

            $customFields = $this->senderGetCustomerData($email);
            if (!empty($customFields)) {
                $data['fields'] = $customFields;
            }

            $customersExportData[] = $data;
        }

        $this->sender->senderApi->senderExportData(['customers' => $customersExportData]);
    }

    public function exportProducts()
    {
        global $wpdb;
        $productsCount = $wpdb->get_var('SELECT COUNT(*) FROM ' . $this->tablePrefix . 'posts 
                      INNER JOIN ' . $this->tablePrefix . 'wc_product_meta_lookup ON ' . $this->tablePrefix . 'wc_product_meta_lookup.product_id = ' . $this->tablePrefix . 'posts.id
                      WHERE post_type = "product"');

        $chunkSize = 50;
        $productsExported = 0;
        $loopTimes = floor($productsCount / $chunkSize);
        $currency = get_option('woocommerce_currency');

        for ($x = 0; $x <= $loopTimes; $x++) {
            $productExportData = [];
            $products = $wpdb->get_results('SELECT * FROM ' . $this->tablePrefix . 'posts 
                      INNER JOIN ' . $this->tablePrefix . 'wc_product_meta_lookup ON ' . $this->tablePrefix . 'wc_product_meta_lookup.product_id = ' . $this->tablePrefix . 'posts.id
                      WHERE post_type = "product" LIMIT ' . $chunkSize . '
             OFFSET ' . $productsExported);

            foreach ($products as $product) {
                $image = '';
                if (get_post_thumbnail_id($product->ID)) {
                    $image = wp_get_attachment_image_src(get_post_thumbnail_id($product->ID))[0];
                }

                $productExportData[] = [
                    'title' => $product->post_title,
                    'description' => $product->post_content,
                    'sku' => $product->sku,
                    'quantity' => $product->stock_quantity,
                    'remote_productId' => $product->product_id,
                    'image' => [$image],
                    'price' => number_format($product->max_price, 2),
                    'status' => $product->post_status,
                    'created_at' => $product->post_date,
                    'updated_at' => $product->post_modified,
                    'currency' => $currency,
                ];
            }

            $productsExported += $chunkSize;

            $this->sender->senderApi->senderExportData(['products' => $productExportData]);
        }
        return true;
    }

    public function exportOrders()
    {
        global $wpdb;
        $totalOrders = $wpdb->get_var(
            'SELECT COUNT(*) FROM ' . $this->tablePrefix . 'posts WHERE post_type = "shop_order" AND post_status != "trash" AND post_status != "auto-draft"'
        );

        $chunkSize = 50;
        $ordersExported = 0;
        $loopTimes = floor($totalOrders / $chunkSize);

        for ($x = 0; $x <= $loopTimes; $x++) {
            $ordersExportData = [];
            $chunkedOrders = $wpdb->get_results(
                'SELECT * FROM ' . $this->tablePrefix . 'posts WHERE post_type = "shop_order" AND post_status != "trash" AND post_status != "auto-draft" LIMIT ' . $chunkSize . ' OFFSET ' . $ordersExported);

            foreach ($chunkedOrders as $order) {
                $remoteId = get_post_meta($order->ID, Sender_Helper::SENDER_CART_META, true);
                if (!$remoteId) {
                    $remoteId = get_post_meta($order->ID, '_order_key', true);
                }

                $orderData = [
                    'status' => $order->post_status,
                    'updated_at' => $order->post_modified,
                    'created_at' => $order->post_date,
                    'remoteId' => $remoteId,
                    'name' => $order->post_name,
                    'currency' => get_option('woocommerce_currency'),
                    'orderId' => $order->ID,
                    'email' => get_post_meta($order->ID, '_billing_email', true),
                    'firstname' => get_post_meta($order->ID, '_billing_first_name', true),
                    'lastname' => get_post_meta($order->ID, '_billing_last_name', true),
                    'phone' => get_post_meta($order->ID, '_billing_phone', true),
                ];

                $productsData = $wpdb->get_results('SELECT * FROM ' . $this->tablePrefix . 'wc_order_product_lookup
            INNER JOIN ' . $this->tablePrefix . 'wc_product_meta_lookup on ' . $this->tablePrefix . 'wc_product_meta_lookup.product_id = ' . $this->tablePrefix . 'wc_order_product_lookup.product_id
            LEFT JOIN ' . $this->tablePrefix . 'posts on ' . $this->tablePrefix . 'posts.id = ' . $this->tablePrefix . 'wc_order_product_lookup.product_id
            where ' . $this->tablePrefix . 'wc_order_product_lookup.order_id = ' . $order->ID);

                $orderData['products'] = [];
                $orderPrice = 0;
                foreach ($productsData as $key => $product) {
                    $regularPrice = $product->min_price;
                    $salePrice = $product->max_price;

                    if ($regularPrice <= 0) {
                        $regularPrice = 1;
                    }

                    $discount = round(100 - ($salePrice / $regularPrice * 100));
                    $orderPrice += $product->max_price * $product->product_qty;
                    $orderData['products'][$key] = [
                        'product_id' => $product->ID,
                        'sku' => $product->sku,
                        'name' => $product->post_title,
                        'price' => $product->max_price,
                        'qty' => $product->product_qty,
                        'discount' => (string)$discount,
                        'currency' => get_option('woocommerce_currency'),
                        'image' => get_the_post_thumbnail_url($product->product_id),
                    ];
                }

                $orderData['price'] = $orderPrice;
                $ordersExportData[] = $orderData;
            }
            $this->sender->senderApi->senderExportData(['orders' => $ordersExportData]);
            $ordersExported += $chunkSize;
        }
    }

    public function getTablePrefix()
    {
        global $wpdb;
        $this->tablePrefix = $wpdb->prefix;
    }

    public function senderProcessOrderFromWoocommerceDashboard($orderId, $senderUser)
    {
        #Process order
        $order = wc_get_order($orderId);
        $items = $order->get_items();
        if (empty($items)){
            return;
        }

        $serializedItems = array();
        foreach ($items as $item_id => $item) {
            $product = $item->get_product();
            $variation_id = $item->get_variation_id();
            $variation_attributes = wc_get_product_variation_attributes($variation_id);
            $serializedItem = array(
                'key' => $item_id,
                'product_id' => $item->get_product_id(),
                'variation_id' => $variation_id,
                'variation' => $variation_attributes,
                'quantity' => $item->get_quantity(),
                'data_hash' => md5(serialize($item->get_data())),
                'line_tax_data' => array(
                    'subtotal' => array(),
                    'total' => array()
                ),
                'line_subtotal' => $item->get_subtotal(),
                'line_subtotal_tax' => $item->get_subtotal_tax(),
                'line_total' => $item->get_total(),
                'line_tax' => $item->get_total_tax(),
                'data' => serialize($product)
            );

            $serializedItems[] = $serializedItem;
        }

        $result = serialize($serializedItems);

        $cart = new Sender_Cart();
        $cart->cart_data = $result;
        $cart->user_id = $senderUser->id;
        $cart->cart_status = Sender_Helper::UNPAID_CART;
        $cart->save();

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
            "order_total" => (string)$order->get_total(),
            "products" => [],
            'resource_key' => get_option('sender_resource_key'),
            'store_id' => get_option('sender_store_register') ?: '',
            'email' => $senderUser->email,
        ];

        foreach ($items as $item => $values) {
            $_product = wc_get_product($values->get_product_id());
            $regularPrice = (int) get_post_meta($values->get_product_id(), '_regular_price', true);
            $salePrice = (int) get_post_meta($values->get_product_id(), '_sale_price', true);

            if ($regularPrice <= 0) {
                $regularPrice = 1;
            }

            $discount = round(100 - ($salePrice / $regularPrice * 100));

            $prod = [
                'sku' => $_product->get_sku(),
                'name' => $_product->get_title(),
                'price' => (string) $regularPrice,
                'price_display' => (string) $_product->get_price() . get_option('woocommerce_currency'),
                'discount' => (string) $discount,
                'qty' => $values->get_quantity(),
                'image' => get_the_post_thumbnail_url($values->get_product_id()),
                'product_id' => $values->get_product_id()
            ];

            $data['products'][] = $prod;
        }

        $this->sender->senderApi->senderTrackCart($data);

        #Add sender_remote_id in wp_post
        update_post_meta($orderId, Sender_Helper::SENDER_CART_META, $cart->id);

        #Handle status of cart
        do_action('sender_update_order_status', $orderId);

    }
}