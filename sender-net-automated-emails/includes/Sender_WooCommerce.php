<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once plugin_dir_path(__FILE__) . 'Sender_Helper.php';

class Sender_WooCommerce
{
    private $sender;
    private $logFilePath;

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
        add_action('sender_get_customer_data', [$this, 'senderBuildOrdersMetaFields'], 10, 2);
        add_action('sender_update_customer_data', [$this, 'senderUpdateCustomerData']);
        add_action('sender_update_customer_background', [$this, 'senderUpdateCustomerBackground'], 10, 2);

        $this->logFilePath = plugin_dir_path(__FILE__) . '../export-log.txt';

        if (is_admin()) {
            if (get_option('sender_subscribe_label') && !empty(get_option('sender_subscribe_to_newsletter_string'))) {
                add_action('edit_user_profile', [$this, 'senderNewsletter']);
            }
            //From wp edit users admin side
            add_action('edit_user_profile_update', [$this, 'senderUpdateCustomerDataAdminPanel'], 10, 1);

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

    public function scheduleSenderExportShopDataCronJob($delay = 5)
    {
        if (!wp_next_scheduled('sender_export_shop_data_cron')) {
            set_transient(Sender_Helper::TRANSIENT_SYNC_IN_PROGRESS, true, 300);
            wp_schedule_single_event(time() + (int)$delay, 'sender_export_shop_data_cron');
        }
    }

    public function senderExportShopDataCronJob()
    {
        $this->logExportDebugInfo('Start', "Sender export data started");

        $retryKey = 'sender_export_retry_count';
        $retry = (int) get_transient($retryKey);

        if (
                !class_exists('WooCommerce') ||
                !did_action('woocommerce_init') ||
                !post_type_exists('shop_order')
        ) {
            if ($retry >= 3) {
                $this->logExportDebugInfo(
                        'Env',
                        'WooCommerce not ready after 3 retries — aborting export'
                );

                delete_transient(Sender_Helper::TRANSIENT_SYNC_IN_PROGRESS);
                delete_transient($retryKey);
                return;
            }

            set_transient($retryKey, $retry + 1, 5 * MINUTE_IN_SECONDS);

            $this->logExportDebugInfo(
                    'Env',
                    'WooCommerce not ready in cron — retry ' . ($retry + 1)
            );

            wp_schedule_single_event(time() + 30, 'sender_export_shop_data_cron');
            return;
        }

        delete_transient($retryKey);

        global $wpdb;

        $this->logExportDebugInfo('Env', 'wpdb->prefix: ' . $wpdb->prefix);
        $this->logExportDebugInfo('Env', 'wpdb->posts: ' . $wpdb->posts);
        $this->logExportDebugInfo('Env', 'wpdb->postmeta: ' . $wpdb->postmeta);

        $sanityOrders = $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='shop_order'"
        );
        $sanityProducts = $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='product'"
        );

        $this->logExportDebugInfo(
                'Sanity',
                "Orders in wpdb->posts: $sanityOrders | Products in wpdb->posts: $sanityProducts"
        );

        $this->exportCustomers();
        $this->exportProducts();
        $this->exportOrders();

        update_option('sender_wocommerce_sync', true);
        update_option('sender_synced_data_date', current_time('Y-m-d H:i:s'));

        delete_transient(Sender_Helper::TRANSIENT_SYNC_IN_PROGRESS);
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

    public function senderUpdateCustomerDataAdminPanel($userId)
    {
        $changedData = [];
        if (isset($_POST['sender_newsletter'])) {
            update_user_meta(
                $userId,
                Sender_Helper::EMAIL_MARKETING_META_KEY,
                Sender_Helper::generateEmailMarketingConsent(Sender_Helper::SUBSCRIBED)
            );
            $changedData['subscriber_status'] = Sender_Helper::UPDATE_STATUS_ACTIVE;
            $changedData['sms_status'] = Sender_Helper::UPDATE_STATUS_ACTIVE;
        } else {
            if (Sender_Helper::shouldChangeChannelStatus($userId, 'user')) {
                update_user_meta(
                    $userId,
                    Sender_Helper::EMAIL_MARKETING_META_KEY,
                    Sender_Helper::generateEmailMarketingConsent(Sender_Helper::UNSUBSCRIBED)
                );
                $changedData['subscriber_status'] = Sender_Helper::UPDATE_STATUS_UNSUBSCRIBED;
                $changedData['sms_status'] = Sender_Helper::UPDATE_STATUS_UNSUBSCRIBED;
            }
        }

        $oldUserData = get_userdata($userId);

        $oldFirstname = $oldUserData->first_name;
        $oldLastName = $oldUserData->last_name;

        $updatedFirstName = $_POST['first_name'] ?: $_POST['billing_first_name'] ?: '';
        $updatedLastName = $_POST['last_name'] ?: $_POST['billing_last_name'] ?: '';

        if ($oldFirstname !== $updatedFirstName) {
            $changedData['firstname'] = $updatedFirstName;
        }

        if ($oldLastName !== $updatedLastName) {
            $changedData['lastname'] = $updatedLastName;
        }

        $oldUserMetaData = get_user_meta($userId);
        if (!empty($oldUserMetaData['billing_phone'][0])) {
            $oldPhone = $oldUserMetaData['billing_phone'][0];
            $updatedPhone = $_POST['billing_phone'] ?: '';

            if ($oldPhone !== $updatedPhone){
                $changedData['phone'] = $updatedPhone;
            }
        }

        $email = get_userdata($userId)->user_email;

        $ordersDataFields = $this->senderBuildOrdersMetaFields($email);
        if (!empty($ordersDataFields)){
            $changedData['fields'] = $ordersDataFields;
        }

        if (!empty($changedFields)) {
            $this->sender->senderApi->updateCustomer($changedData, get_userdata($userId)->user_email);
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

        $billingCountry = isset($_POST['_billing_country']) ? $_POST['_billing_country'] : '';

        #Order update, created from interface
        if (isset($postMeta[Sender_Helper::SENDER_CART_META]) || $senderUser) {
            $subscriberData = [];
            if (isset($_POST['_billing_first_name'])) {
                $subscriberData['firstname'] = $_POST['_billing_first_name'];
            }

            if (isset($_POST['_billing_last_name'])) {
                $subscriberData['lastname'] = $_POST['_billing_last_name'];
            }

            if (!empty($_POST['_billing_phone'])) {
                $normalized = $this->normalizePhoneE164($_POST['_billing_phone'], $billingCountry);
                if ($normalized !== '') {
                    $subscriberData['phone'] = $normalized;
                }
            }

            $channelStatusData = $this->handleSenderNewsletterFromDashboard($orderId, $subscriberData, true);
            $subscriberData = array_merge($subscriberData, $channelStatusData);

            $this->sender->senderApi->updateCustomer($subscriberData, $email);
            $emailMarketingConsent = get_post_meta($orderId, Sender_Helper::EMAIL_MARKETING_META_KEY, true);
            if (empty($emailMarketingConsent)) {
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
                $normalized = $this->normalizePhoneE164($_POST['_billing_phone'], $billingCountry);
                if ($normalized !== '') {
                    $subscriberData['phone'] = $normalized;
                }
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

        if ($product->is_type('grouped') || $product->is_type('variable')) {
            $children = $product->get_children();
            $prices = [];

            foreach ($children as $child_id) {
                $child = wc_get_product($child_id);
                if ($child) {
                    $price = (float) $child->get_price();
                    if ($price > 0) {
                        $prices[] = $price;
                    }
                }
            }

            if (!empty($prices)) {
                $min_price = min($prices);
                $max_price = max($prices);
                $pPrice = $min_price === $max_price ? (float)$min_price : "{$min_price} - {$max_price}";
            } else {
                $pPrice = 0;
            }
        } else {
            $pPrice = (float) $product->get_regular_price();
        }

        $pName = str_replace("\"", '\\"', $product->get_name());

        $pDescription = is_string($product->get_short_description()) && !empty($product->get_short_description())
            ? strip_shortcodes(strip_tags($product->get_short_description()))
            : (is_string($product->get_description()) ? strip_shortcodes(strip_tags($product->get_description())) : '');

        $pCurrency = get_woocommerce_currency();
        $pQty = $product->get_stock_quantity() ? $product->get_stock_quantity() : 1;
        $pRating = $product->get_average_rating();
        $pOnSale = $product->is_on_sale();
        $pDiscount = 0;

        if ($pOnSale && !empty($product->get_sale_price())) {
            $pSalePrice = (float)$product->get_sale_price();
            if ($pPrice > 0) {
                $pDiscount = round(100 - ($pSalePrice / $pPrice * 100));
            } else {
                $pDiscount = 0;
            }
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
            FROM {$wpdb->posts} AS o
                LEFT JOIN {$wpdb->postmeta} AS pm1 ON o.ID = pm1.post_id AND pm1.meta_key = '_billing_first_name'
                LEFT JOIN {$wpdb->postmeta} AS pm2 ON o.ID = pm2.post_id AND pm2.meta_key = '_billing_last_name'
                LEFT JOIN {$wpdb->postmeta} AS pm3 ON o.ID = pm3.post_id AND pm3.meta_key = '_billing_phone'
                LEFT JOIN {$wpdb->postmeta} AS pm4 ON o.ID = pm4.post_id AND pm4.meta_key = '_billing_email'
                LEFT JOIN {$wpdb->postmeta} AS pm5 ON o.ID = pm5.post_id AND pm5.meta_key = 'sender_newsletter'
                LEFT JOIN {$wpdb->postmeta} AS pm6 ON o.ID = pm6.post_id AND pm6.meta_key = 'email_marketing_consent'
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
            FROM {$wpdb->posts} AS o
                LEFT JOIN {$wpdb->postmeta} AS pm1 ON o.ID = pm1.post_id AND pm1.meta_key = '_billing_first_name'
                LEFT JOIN {$wpdb->postmeta} AS pm2 ON o.ID = pm2.post_id AND pm2.meta_key = '_billing_last_name'
                LEFT JOIN {$wpdb->postmeta} AS pm3 ON o.ID = pm3.post_id AND pm3.meta_key = '_billing_phone'
                LEFT JOIN {$wpdb->postmeta} AS pm4 ON o.ID = pm4.post_id AND pm4.meta_key = '_billing_email'
                LEFT JOIN {$wpdb->postmeta} AS pm5 ON o.ID = pm5.post_id AND pm5.meta_key = 'sender_newsletter'
                LEFT JOIN {$wpdb->postmeta} AS pm6 ON o.ID = pm6.post_id AND pm6.meta_key = 'email_marketing_consent'
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
        FROM {$wpdb->posts} AS o
            LEFT JOIN {$wpdb->postmeta} AS pm ON o.ID = pm.post_id AND pm.meta_key = '_billing_email'
        WHERE
            o.post_type = 'shop_order'
            AND o.post_status IN ('wc-completed', 'wc-on-hold', 'wc-processing')
            AND pm.meta_value IS NOT NULL");

        $this->logExportDebugInfo('CustomersExport', "Total customers with completed orders: $totalCompleted");

        $clientCompleted = 0;
        if ($totalCompleted > $chunkSize) {
            $loopTimes = floor($totalCompleted / $chunkSize);
            for ($x = 0; $x <= $loopTimes; $x++) {
                $this->logExportDebugInfo('CompletedOrders Chunk', "Offset: $clientCompleted | Chunk size: $chunkSize");
                $woocommerceClientOrdersCompleted = $this->getWooClientsOrderCompleted($chunkSize, $clientCompleted);
                $customerList = json_decode(json_encode($woocommerceClientOrdersCompleted), true);
                $this->sendWoocommerceCustomersToSender($customerList, get_option('sender_customers_list'));
                $clientCompleted += $chunkSize;
            }
        } else {
            $this->logExportDebugInfo('CompletedOrders Chunk', "Offset: 0 | Chunk size: $chunkSize");
            $woocommerceClientOrdersCompleted = $this->getWooClientsOrderCompleted($chunkSize);
            $customerList = json_decode(json_encode($woocommerceClientOrdersCompleted), true);
            $this->sendWoocommerceCustomersToSender($customerList, get_option('sender_customers_list'));
        }

        #Extract customers which did not complete order
        $totalNotCompleted = $wpdb->get_var("SELECT COUNT(DISTINCT pm.meta_value)
        FROM {$wpdb->posts} AS o
            LEFT JOIN {$wpdb->postmeta} AS pm ON o.ID = pm.post_id AND pm.meta_key = '_billing_email'
        WHERE
            o.post_type = 'shop_order'
            AND o.post_status NOT IN ('wc-completed', 'wc-on-hold', 'wc-processing')
            AND pm.meta_value IS NOT NULL");

        $this->logExportDebugInfo('CustomersExport', "Total customers with incomplete orders: $totalNotCompleted");

        $clientNotCompleted = 0;
        if ($totalNotCompleted > $chunkSize) {
            $loopTimes = floor($totalNotCompleted / $chunkSize);
            for ($x = 0; $x <= $loopTimes; $x++) {
                $this->logExportDebugInfo('NotCompletedOrders Chunk', "Offset: $clientNotCompleted | Chunk size: $chunkSize");
                $woocommerceClientOrdersNotCompleted = $this->getWooClientsOrderNotCompleted($chunkSize, $clientNotCompleted);
                $customerList = json_decode(json_encode($woocommerceClientOrdersNotCompleted), true);
                $this->sendWoocommerceCustomersToSender($customerList);
                $clientNotCompleted += $chunkSize;
            }
        } else {
            $this->logExportDebugInfo('NotCompletedOrders Chunk', "Offset: 0 | Chunk size: $chunkSize");
            $woocommerceClientOrdersNotCompleted = $this->getWooClientsOrderNotCompleted($chunkSize);
            $customerList = json_decode(json_encode($woocommerceClientOrdersNotCompleted), true);
            $this->sendWoocommerceCustomersToSender($customerList);
        }

        #Extract WP users with role customer. Registrations
        $usersQuery = new WP_User_Query(['fields' => 'id', 'role' => 'customer']);
        $usersCount = $usersQuery->get_total();

        $this->logExportDebugInfo('CustomersExport', "Total registered WP customers: $usersCount");

        $usersExported = 0;
        if ($usersCount > $chunkSize) {
            $loopTimes = floor($usersCount / $chunkSize);
            for ($x = 0; $x <= $loopTimes; $x++) {
                $this->logExportDebugInfo('WPUsers Chunk', "Offset: $usersExported | Chunk size: $chunkSize");
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
            $this->logExportDebugInfo('WPUsers Chunk', "Offset: 0 | Chunk size: $chunkSize");
            $customerList = json_decode(json_encode($usersQuery->get_results(), true));
            $this->sendUsersToSender($customerList);
        }

        $this->logExportDebugInfo('CustomersExport', 'Customer export finished successfully');

    }

    private function checkRateLimitation()
    {
        while (get_transient(Sender_Helper::TRANSIENT_SENDER_X_RATE)) {
            sleep(5);
        }

        return true;
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

            $this->checkRateLimitation();
            $customFields = $this->senderBuildOrdersMetaFields($customer['email']);
            if (!empty($customFields)) {
                $customer['fields'] = $customFields;
            }
            $customersExportData[] = $customer;
        }

        $this->checkRateLimitation();
        $this->sender->senderApi->senderExportData(['customers' => $customersExportData]);
    }

    public function senderUpdateCustomerData($email): bool
    {
        if (empty($email)) {
            return false;
        }

        //Build orders + totals + last order
        $customerData = $this->senderBuildOrdersMetaFields($email);

        //not a customer exit
        if (empty($customerData)) {
            return false;
        }

        $payload = ['fields' => $customerData];

        $listId = get_option('sender_customers_list');
        if (!empty($listId)) {
            $payload['tags'] = [$listId];
        }

        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action(
                    'sender_update_customer_background',
                    [$email, $payload]
            );
            return true;
        }

        // Direct update
        $this->sender->senderApi->updateCustomer($payload, $email);
        return true;
    }

    public function senderBuildOrdersMetaFields($email): array
    {
        if (empty($email)) {
            return [];
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

        $ordersCount = count($orders);
        if ($ordersCount === 0) {
            return [];
        }

        $totalSpent = 0.0;
        foreach ($orders as $key => $orderId) {
            $totalSpent += (float) get_post_meta($orderId, '_order_total', true);

            if ($key === $ordersCount - 1) {
                $last_order_name     = '#' . $orderId;
                $last_order_currency = get_post_meta($orderId, '_order_currency', true);
            }
        }

        return [
                'orders_count'      => $ordersCount,
                'total_spent'       => $totalSpent,
                'last_order_number' => $last_order_name,
                'currency'          => $last_order_currency,
        ];
    }

    public function sendUsersToSender($customers)
    {
        $customersExportData = [];
        $autoSubscribeEnabled = get_option('sender_subscribe_label');

        foreach ($customers as $customerId) {
            $customer = get_user_meta($customerId);

            if (!empty($customer['billing_email'][0])) {
                $email = $customer['billing_email'][0];
            } elseif (!empty(get_userdata($customerId)->user_email)) {
                $email = get_userdata($customerId)->user_email;
            } else {
                continue;
            }

            $emailConsent = isset($customer[Sender_Helper::EMAIL_MARKETING_META_KEY][0])
                    ? maybe_unserialize($customer[Sender_Helper::EMAIL_MARKETING_META_KEY][0])
                    : [];

            $isSubscribed = is_array($emailConsent)
                    && isset($emailConsent['state'])
                    && $emailConsent['state'] === Sender_Helper::SUBSCRIBED;

            if (!$autoSubscribeEnabled && !$isSubscribed) {
                continue;
            }

            $data = [
                    'id'        => $customerId,
                    'email'     => $email,
                    'firstname' => $customer['first_name'][0] ?? null,
                    'lastname'  => $customer['last_name'][0] ?? null,
            ];

            $mappingEnabled = get_option('sender_enable_role_group_mapping');
            if ($mappingEnabled) {
                $list = $this->getSenderListForUser($customerId);
                if (!empty($list)) {
                    $data['tags'] = [$list];
                }
            } else {
                $data['tags'] = [get_option('sender_registration_list')];
            }

            if (!empty($customer['billing_phone'][0])) {
                $data['phone'] = $customer['billing_phone'][0];
            }

            $data['newsletter'] = $isSubscribed;

            if (!empty($emailConsent)) {
                $data[Sender_Helper::EMAIL_MARKETING_META_KEY] = $emailConsent;
            }

            $this->checkRateLimitation();
            $customFields = $this->senderBuildOrdersMetaFields($email);
            if (!empty($customFields)) {
                $data['fields'] = $customFields;
            }

            $customersExportData[] = $data;
        }

        if (!empty($customersExportData)) {
            $this->checkRateLimitation();
            $this->sender->senderApi->senderExportData(['customers' => $customersExportData]);
        }
    }

    public function exportProducts()
    {
        try {
            global $wpdb;
            $productsCount = (int) $wpdb->get_var(
                    "SELECT COUNT(*)
                 FROM {$wpdb->posts}
                 INNER JOIN {$wpdb->wc_product_meta_lookup}
                     ON {$wpdb->wc_product_meta_lookup}.product_id = {$wpdb->posts}.ID
                 WHERE post_type = 'product'"
            );

            if ($wpdb->last_error) {
                $this->logExportDebugInfo('DB Error', $wpdb->last_error);
                return false;
            }

            $this->logExportDebugInfo('ExportProducts', "Total products: $productsCount");

            $chunkSize = 10;
            $productsExported = 0;
            $loopTimes = ceil($productsCount / $chunkSize);
            $currency = get_woocommerce_currency();

            for ($x = 0; $x < $loopTimes; $x++) {
                $productExportData = [];
                $products = $wpdb->get_results(
                        "SELECT *
                         FROM {$wpdb->posts}
                         INNER JOIN {$wpdb->wc_product_meta_lookup}
                             ON {$wpdb->wc_product_meta_lookup}.product_id = {$wpdb->posts}.ID
                         WHERE post_type = 'product'
                         ORDER BY {$wpdb->posts}.ID ASC
                         LIMIT {$chunkSize}
                         OFFSET {$productsExported}"
                );

                if ($wpdb->last_error) {
                    $this->logExportDebugInfo('DB Error', $wpdb->last_error);
                    break;
                }

                $this->logExportDebugInfo('Export Chunk', "Offset: $productsExported | Products Fetched: " . count($products));

                foreach ($products as $product) {
                    $wcProduct = wc_get_product($product->ID);
                    if (!$wcProduct) {
                        $this->logExportDebugInfo('Skipped', "Product ID {$product->ID} could not be loaded");
                        continue;
                    }

                    if ($wcProduct->is_type('variation')) {
                        $parent_id = $wcProduct->get_parent_id();
                        $parent    = wc_get_product($parent_id);
                        $sku = $parent ? $parent->get_sku() : '';
                    } else {
                        $sku = $wcProduct->get_sku();
                    }

                    $productExportData[] = [
                            'title'       => $product->post_title,
                            'description' => Sender_Helper::getProductShortText($wcProduct),
                            'sku'         => $sku,
                            'quantity'    => (int) ($wcProduct->get_stock_quantity() ?? 0),
                            'remote_productId' => $product->ID,
                            'image'       => [Sender_Helper::getProductImageUrl($wcProduct)],
                            'price'       => number_format((float)$wcProduct->get_price(), 2),
                            'status'      => $product->post_status,
                            'created_at'  => $product->post_date,
                            'updated_at'  => $product->post_modified,
                            'currency'    => $currency,
                    ];
                }

                $productsExported += count($products);

                $this->checkRateLimitation();

                if (!empty($productExportData)) {
                    $response = $this->sender->senderApi->senderExportData(['products' => $productExportData]);

                    if (is_array($response) && isset($response['error'])) {
                        $this->logExportDebugInfo('API Error', json_encode($response));
                    } elseif (is_object($response) && property_exists($response, 'error')) {
                        $this->logExportDebugInfo('API Error', json_encode($response));
                    }
                }
            }

            $this->logExportDebugInfo('ExportProducts', "Export complete. Total exported: $productsExported");
            return true;
        } catch (\Throwable $e) {
            $this->logExportDebugInfo(
                    'Fatal Export Error',
                    sprintf(
                            '%s in %s on line %d | Trace: %s',
                            $e->getMessage(),
                            $e->getFile(),
                            $e->getLine(),
                            substr($e->getTraceAsString(), 0, 500)
                    )
            );
            return false;
        }
    }

    public function exportOrders()
    {
        global $wpdb;
        $totalOrders = $wpdb->get_var(
                "SELECT COUNT(*)
             FROM {$wpdb->posts}
             WHERE post_type = 'shop_order'
               AND post_status NOT IN ('trash', 'auto-draft')"
        );

        $this->logExportDebugInfo('ExportOrders', "Total orders: $totalOrders");

        $statuses = [
                'wc-pending', 'wc-processing', 'wc-on-hold',
                'wc-completed', 'wc-cancelled', 'wc-refunded',
                'wc-failed'
        ];

        foreach ($statuses as $status) {
            $count = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'shop_order' AND post_status = %s", $status
            ));
            $this->logExportDebugInfo('Order Status Count', "$status = $count");
        }

        $chunkSize = 50;
        $ordersExported = 0;
        $loopTimes = floor($totalOrders / $chunkSize);

        for ($x = 0; $x <= $loopTimes; $x++) {
            $ordersExportData = [];
            $chunkedOrders = $wpdb->get_results(
                    "SELECT *
                     FROM {$wpdb->posts}
                     WHERE post_type = 'shop_order'
                       AND post_status NOT IN ('trash', 'auto-draft')
                     LIMIT {$chunkSize}
                     OFFSET {$ordersExported}"
            );

            $this->logExportDebugInfo('Export Chunk', "Offset: $ordersExported | Orders Fetched: " . count($chunkedOrders));

            foreach ($chunkedOrders as $order) {
                $remoteId = get_post_meta($order->ID, Sender_Helper::SENDER_CART_META, true);
                if (!$remoteId) {
                    $remoteId = get_post_meta($order->ID, '_order_key', true);
                }

                $billingCountry   = get_post_meta($order->ID, '_billing_country', true);
                $shippingCountry  = get_post_meta($order->ID, '_shipping_country', true);
                $billingPhoneRaw  = get_post_meta($order->ID, '_billing_phone', true);
                $shippingPhoneRaw = get_post_meta($order->ID, '_shipping_phone', true);

                $billingPhoneNormalized  = $this->normalizePhoneE164($billingPhoneRaw,  $billingCountry);
                $billingPhoneNormalized  = $billingPhoneNormalized !== '' ? $billingPhoneNormalized : null;

                $shippingPhoneNormalized = $this->normalizePhoneE164($shippingPhoneRaw, $shippingCountry);
                $shippingPhoneNormalized = $shippingPhoneNormalized !== '' ? $shippingPhoneNormalized : null;

                $wcOrder = wc_get_order($order->ID);
                $customerIp = $wcOrder ? $wcOrder->get_customer_ip_address() : null;
                $customerId = $wcOrder ? (int) ($wcOrder->get_customer_id() ?: $wcOrder->get_user_id()) : 0;

                $orderData = [
                        'status' => $order->post_status,
                        'updated_at' => $order->post_modified,
                        'created_at' => $order->post_date,
                        'remoteId' => $remoteId,
                        'name' => $order->post_name,
                        'currency' => get_woocommerce_currency(),
                        'orderId' => $wcOrder ? (string) $wcOrder->get_order_number() : '',
                        'email' => get_post_meta($order->ID, '_billing_email', true),
                        'firstname' => get_post_meta($order->ID, '_billing_first_name', true),
                        'lastname' => get_post_meta($order->ID, '_billing_last_name', true),
                ];

                // only add if valid WP user is linked
                if ($customerId > 0) {
                    $orderData['customer_id'] = $customerId;
                }

                if ($billingPhoneNormalized !== null && $billingPhoneNormalized !== '') {
                    $orderData['phone'] = $billingPhoneNormalized;
                }

                $paymentMethod       = get_post_meta($order->ID, '_payment_method', true);
                $paymentMethodTitle  = get_post_meta($order->ID, '_payment_method_title', true);

                $orderData['order_details'] = [
                        'total'     => number_format((float) $wcOrder->get_total(), 2),
                        'subtotal'  => number_format((float) $wcOrder->get_subtotal(), 2),
                        'discount'  => number_format((float) $wcOrder->get_discount_total(), 2),
                        'tax'       => number_format((float) $wcOrder->get_total_tax(), 2),
                        'order_date'=> $wcOrder->get_date_created() ? $wcOrder->get_date_created()->date('Y-m-d H:i:s') : null,
                ];

                $billing = [
                        'first_name' => get_post_meta($order->ID, '_billing_first_name', true),
                        'last_name'  => get_post_meta($order->ID, '_billing_last_name', true),
                        'company'    => get_post_meta($order->ID, '_billing_company', true),
                        'address_1'  => get_post_meta($order->ID, '_billing_address_1', true),
                        'address_2'  => get_post_meta($order->ID, '_billing_address_2', true),
                        'city'       => get_post_meta($order->ID, '_billing_city', true),
                        'state'      => get_post_meta($order->ID, '_billing_state', true),
                        'postcode'   => get_post_meta($order->ID, '_billing_postcode', true),
                        'country'    => $billingCountry,
                        'email'      => get_post_meta($order->ID, '_billing_email', true),
                        'phone'      => $billingPhoneNormalized,
                        'payment_method' => $paymentMethod,
                        'payment_method_title' => $paymentMethodTitle,
                ];

                $orderData['billing'] = array_filter($billing, function ($v) {
                    return $v !== '' && $v !== null;
                });

                $shipping = [
                        'first_name' => get_post_meta($order->ID, '_shipping_first_name', true),
                        'last_name'  => get_post_meta($order->ID, '_shipping_last_name', true),
                        'company'    => get_post_meta($order->ID, '_shipping_company', true),
                        'address_1'  => get_post_meta($order->ID, '_shipping_address_1', true),
                        'address_2'  => get_post_meta($order->ID, '_shipping_address_2', true),
                        'city'       => get_post_meta($order->ID, '_shipping_city', true),
                        'state'      => get_post_meta($order->ID, '_shipping_state', true),
                        'postcode'   => get_post_meta($order->ID, '_shipping_postcode', true),
                        'country'    => $shippingCountry,
                        'phone'      => $shippingPhoneNormalized,
                ];

                $orderData['shipping'] = array_filter($shipping, function ($v) {
                    return $v !== '' && $v !== null;
                });

                if (!empty($customerIp)) {
                    $orderData['registration_ip'] = $customerIp;
                    $orderData['billing']['customer_ip']  = $customerIp;
                    $orderData['shipping']['customer_ip'] = $customerIp;
                }

                $orderProductTable = $wpdb->prefix . 'wc_order_product_lookup';

                $productsData = $wpdb->get_results(
                        $wpdb->prepare(
                                "SELECT *
                                 FROM {$orderProductTable}
                                 WHERE order_id = %d",
                                $order->ID
                        )
                );

                $orderData['products'] = [];
                $orderPrice = 0;
                foreach ($productsData as $key => $product) {
                    $wcProduct = wc_get_product($product->variation_id ?: $product->product_id);

                    if ($wcProduct) {
                        $regularPrice = (float) $wcProduct->get_regular_price();
                        $salePrice = (float) $wcProduct->get_sale_price();

                        $price = $salePrice > 0 ? $salePrice : $regularPrice;
                        $discount = 0;
                        $oldPrice = null;

                        if ($salePrice > 0 && $salePrice < $regularPrice) {
                            $discount = round(100 - ($salePrice / $regularPrice * 100));
                            $oldPrice = $regularPrice;
                        }

                        $orderPrice += $price * $product->product_qty;

                        $sku = $wcProduct->get_sku();

                        if (!$sku && $wcProduct->is_type('variation')) {
                            $parent = wc_get_product($wcProduct->get_parent_id());
                            if ($parent) {
                                $sku = $parent->get_sku();
                            }
                        }

                        $productData = [
                                'product_id' => $wcProduct->get_id(),
                                'sku' => $sku ?: null,
                                'name' => $wcProduct->get_name(),
                                'price' => (string) $price,
                                'qty' => $product->product_qty,
                                'currency' => get_woocommerce_currency(),
                                'image' => get_the_post_thumbnail_url($product->product_id),
                        ];

                        if ($oldPrice !== null) {
                            $productData['old_price'] = (string) $oldPrice;
                            $productData['discount']  = (string) $discount;
                        }

                        $orderData['products'][$key] = $productData;

                    } else {
                        $orderData['products'][$key] = [
                                'product_id' => $product->ID,
                                'sku' => $product->sku,
                                'name' => $product->post_title,
                                'price' => $product->max_price,
                                'qty' => $product->product_qty,
                                'discount' => '0',
                                'currency' => get_woocommerce_currency(),
                                'image' => get_the_post_thumbnail_url($product->product_id),
                        ];

                        $orderPrice += $product->max_price * $product->product_qty;
                    }
                }

                $orderData['price'] = $orderPrice;
                $ordersExportData[] = $orderData;
            }

            $this->checkRateLimitation();
            $this->sender->senderApi->senderExportData(['orders' => $ordersExportData]);
            $ordersExported += $chunkSize;
        }
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
            "currency" => get_woocommerce_currency(),
            "order_total" => (string)$order->get_total(),
            "products" => [],
            'resource_key' => get_option('sender_resource_key'),
            'store_id' => get_option('sender_store_register') ?: '',
            'email' => $senderUser->email,
        ];

        foreach ($items as $item => $values) {
            $_product = wc_get_product($values->get_product_id());
            $regularPrice = (float) $_product->get_regular_price();
            $salePrice    = (float) $_product->get_sale_price();

            $price     = $salePrice > 0 ? $salePrice : $regularPrice;
            $discount  = 0;
            $oldPrice  = null;

            if ($salePrice > 0 && $salePrice < $regularPrice) {
                $discount = round(100 - ($salePrice / $regularPrice * 100));
                $oldPrice = $regularPrice;
            }

            $prod = [
                    'sku' => (string) $_product->get_sku(),
                    'name' => (string) $_product->get_title(),
                    'price'       => (string) $price,
                    'qty'         => (int) $values->get_quantity(),
                    'image'       => get_the_post_thumbnail_url($values->get_product_id()),
                    'product_id'  => $values->get_product_id(),
            ];

            if ($oldPrice !== null) {
                $prod['old_price'] = (string) $oldPrice;
                $prod['discount']  = (string) $discount;
            }

            $data['products'][] = $prod;
        }

        $this->sender->senderApi->senderTrackCart($data);

        #Add sender_remote_id in wp_post
        update_post_meta($orderId, Sender_Helper::SENDER_CART_META, $cart->id);
    }

    private function logExportDebugInfo($step, $data)
    {
        try {
            if (!is_writable(dirname($this->logFilePath))) {
                error_log("[Sender Plugin] Log directory not writable.");
                return;
            }

            if ($step === 'Start') {
                file_put_contents($this->logFilePath, '');
            }

            $timestamp = date('Y-m-d H:i:s');
            $log = "[$timestamp] [$step] $data" . PHP_EOL;
            if (file_put_contents($this->logFilePath, $log, FILE_APPEND) === false) {
                error_log("[Sender Plugin] Failed to write to export log.");
            }
        } catch (\Throwable $e) {
            error_log("[Sender Plugin] Log exception: " . $e->getMessage());
        }
    }

    /**
     * Normalize a phone number to an E.164-like string using WooCommerce's country calling code.
     * Examples:
     *   raw: "4802130814", country: "US" => "+14802130814"
     *   raw: "+1 (480) 213-0814"         => "+14802130814"
     *   raw: "067696602", country: "LT"  => "+37067696602"
     *   raw: "01632102000", country: "BD"=> "+8801632102000"
     */
    private function normalizePhoneE164($rawPhone, $countryIso2)
    {
        try {
            $rawPhone = trim((string) $rawPhone);
            if ($rawPhone === '') {
                return '';
            }

            $iso = strtoupper((string) $countryIso2);

            if (substr($rawPhone, 0, 1) === '+') {
                return '+' . preg_replace('/\D+/', '', substr($rawPhone, 1));
            }

            if (strpos($rawPhone, '00') === 0) {
                $rest = preg_replace('/\D+/', '', substr($rawPhone, 2));
                return $rest !== '' ? '+' . $rest : '';
            }
            if (strpos($rawPhone, '011') === 0) {
                $rest = preg_replace('/\D+/', '', substr($rawPhone, 3));
                return $rest !== '' ? '+' . $rest : '';
            }

            $digits = preg_replace('/\D+/', '', $rawPhone);
            if ($digits === '') {
                return '';
            }

            $cc = '';
            if ($iso !== '') {
                try {
                    $countries = new \WC_Countries();
                    $ccVal = $countries->get_country_calling_code($iso);
                    if (is_array($ccVal)) {
                        $ccVal = reset($ccVal);
                    }
                    $ccDigits = preg_replace('/\D+/', '', (string) $ccVal);
                    if ($ccDigits !== '') {
                        $cc = '+' . $ccDigits;
                    }
                } catch (\Throwable $e) {
                    $cc = '';
                }
            }

            if ($cc !== '') {
                $ccDigits = substr($cc, 1);
                if ($ccDigits !== '' && strpos($digits, $ccDigits) === 0) {
                    return '+' . $digits;
                }

                $national = ltrim($digits, '0');
                return $cc . $national;
            }

            return '+' . ltrim($digits, '0');
        } catch (\Throwable $e) {
            return '';
        }
    }

    public function senderUpdateCustomerBackground($email, $updateData)
    {
        if (empty($email) || empty($updateData)) {
            return;
        }

        $this->sender->senderApi->updateCustomer($updateData, $email);
    }

    private function getSenderListForUser($userId)
    {
        $list = '';
        $mappingEnabled = get_option('sender_enable_role_group_mapping');
        $map = (array) get_option('sender_role_group_map') ?: [];

        $user = get_userdata($userId);
        if (!$user) {
            return get_option('sender_registration_list');
        }

        $roles = (array) ($user->roles ?? []);

        if ($mappingEnabled) {
            foreach ($roles as $r) {
                if (!empty($map[$r])) {
                    $list = trim($map[$r]);
                    break;
                }
            }

            if (empty($list)) {
                $list = get_option('sender_registration_list');
            }
        } else {
            $list = get_option('sender_registration_list');
        }

        return $list;
    }

}