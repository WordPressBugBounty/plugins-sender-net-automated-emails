<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once 'Sender_Helper.php';

class Sender_Webhooks
{
    public $sender;

    //Webhook url formation
    const WEBHOOK_NAMESPACE = 'sender-automated-emails/v2';
    const WEBHOOK_HANDLER = '/webhook_handler';

    #Topics
    const TOPIC_IMPORT_SHOP_DATA = 'import-shop-data';
    const TOPIC_UPDATE_CUSTOMER_BY_ID = 'update-customer-by-id';
    const TOPIC_UPDATE_CUSTOMER_BY_EMAIL = 'update-customer-by-email';
    const TOPIC_DELETE_STORE = 'delete-store';
    const TOPIC_GET_CUSTOMERS_BY_ID = 'get-customers-by-id';
    const TOPIC_GET_CUSTOMERS_BY_EMAIL = 'get-customers-by-email';
    const TOPIC_PLUGIN_VERSION = 'get-plugin-version';

    public function __construct($sender)
    {
        $this->sender = $sender;
        add_action('rest_api_init', [$this, 'sender_register_webhook_topic_handler']);
    }

    public function webhook_permission_callback($request)
    {
        $receivedApiKey = $request->get_header('sender-api-key');
        $storedApiKey = get_option('sender_api_key');

        if ($receivedApiKey === $storedApiKey) {
            return true;
        } else {
            $data = $request->get_json_params();
            if (isset($data['sender_resource_key']) && isset($data['topic']) && $data['topic'] === self::TOPIC_PLUGIN_VERSION){
                return true;
            }
            return false;
        }
    }

    public function sender_register_webhook_topic_handler()
    {
        register_rest_route(self::WEBHOOK_NAMESPACE, self::WEBHOOK_HANDLER, [
            'methods' => 'POST',
            'callback' => [$this, 'sender_webhook_handler'],
            'permission_callback' => [$this, 'webhook_permission_callback'],
        ]);
    }

    public function sender_webhook_handler(WP_REST_Request $request)
    {
        $data = $request->get_json_params();
        if (!isset($data['topic'])) {
            return new WP_REST_Response(['error' => 'Field topic is required.'], 404);
        }

        try {
            switch ($data['topic']) {
                case self::TOPIC_IMPORT_SHOP_DATA:
                    return $this->import_shop_data_callback();
                case self::TOPIC_UPDATE_CUSTOMER_BY_ID:
                    return $this->update_customer_by_id($data);
                case self::TOPIC_UPDATE_CUSTOMER_BY_EMAIL:
                    return $this->update_customer_by_email($data);
                case self::TOPIC_DELETE_STORE:
                    return $this->delete_store();
                case self::TOPIC_GET_CUSTOMERS_BY_ID:
                    return $this->get_customers_by_id($data);
                case self::TOPIC_GET_CUSTOMERS_BY_EMAIL:
                    return $this->get_customers_by_email($data);
                case self::TOPIC_PLUGIN_VERSION:
                    return $this->get_plugin_version();
                default:
                    return new WP_REST_Response(['error' => 'Invalid webhook topic.'], 400);
            }
        } catch (Throwable $e) {
            return new WP_REST_Response(['error' => 'Exception occurred while handling topic: ' . $e->getMessage()], 500);
        }
    }

    public function import_shop_data_callback()
    {
        update_option('sender_wocommerce_sync', false);
        do_action('sender_schedule_sync_cron_job');

        $response = ['message' => __('Started importing wordpress shop data')];
        return new WP_REST_Response($response, 200);
    }

    public function delete_store()
    {
        update_option('sender_store_register', false);
        update_option('sender_account_disconnected', true);

        $response = ['message' => __('Store removed.')];
        return new WP_REST_Response($response, 200);
    }

    public function update_customer_by_id($data)
    {
        $customer_id = $data['id'];
        $customer = get_user_by('id', $customer_id);

        if (!$customer) {
            return new WP_Error('customer_not_found', __('Customer not found.'), ['status' => 404]);
        }

        $meta_fields = ['first_name', 'last_name', 'phone', Sender_Helper::EMAIL_MARKETING_META_KEY];

        foreach ($meta_fields as $field) {
            if ($field === Sender_Helper::EMAIL_MARKETING_META_KEY) {
                if (isset($data['email_marketing_consent']['state'])) {
                    update_user_meta($customer_id, 'sender_newsletter', $this->sender_email_status_as_boolean($data['email_marketing_consent']['state']));
                }
                continue;
            }

            if (isset($data[$field])) {
                update_user_meta($customer_id, $field, sanitize_text_field($data[$field]));
            }
        }

        $response = ['message' => __('Customer information updated.')];
        return new WP_REST_Response($response, 200);
    }

    public function update_customer_by_email($data)
    {
        $customer_email = $data['email'];
        $customer = get_user_by('email', $customer_email);

        if (!$customer) {
            global $wpdb;
            $order = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}posts WHERE post_type = 'shop_order' AND ID IN (SELECT post_id FROM {$wpdb->prefix}postmeta WHERE meta_key = '_billing_email' AND meta_value = %s)", $customer_email));
            if (!$order) return new WP_REST_Response(['error' => 'Customer not found'], 404);
            update_post_meta($order->ID, '_billing_first_name', sanitize_text_field($data['first_name'] ?? ''));
            update_post_meta($order->ID, '_billing_last_name', sanitize_text_field($data['last_name'] ?? ''));
            update_post_meta($order->ID, '_billing_phone', sanitize_text_field($data['phone'] ?? ''));
            update_post_meta($order->ID, Sender_Helper::EMAIL_MARKETING_META_KEY, $data[Sender_Helper::EMAIL_MARKETING_META_KEY] ?? '');
        } else {
            update_user_meta($customer->ID, 'first_name', sanitize_text_field($data['first_name'] ?? ''));
            update_user_meta($customer->ID, 'last_name', sanitize_text_field($data['last_name'] ?? ''));
            update_user_meta($customer->ID, 'phone', sanitize_text_field($data['phone'] ?? ''));
            update_user_meta($customer->ID, Sender_Helper::EMAIL_MARKETING_META_KEY, $data[Sender_Helper::EMAIL_MARKETING_META_KEY] ?? '');
        }

        $response = ['message' => __('Customer information updated.')];
        return new WP_REST_Response($response, 200);
    }

    public function get_customers_by_id($data)
    {
        $customerIds = $data['customer_ids'];

        if (empty($customerIds)){
            return new WP_REST_Response(['error' => 'No customer id provided'], 404);
        }

        $args = [
            'include' => $customerIds,
            'order' => 'DESC',
        ];

        $customer_query = new WP_User_Query($args);
        $customer_objects = $customer_query->get_results();

        $customers = [];
        foreach ($customer_objects as $customer_object) {
            $email = $customer_object->user_email;
            $firstname = $customer_object->first_name;
            $lastname = $customer_object->last_name;
            $emailConsent = get_user_meta($customer_object->ID, Sender_Helper::EMAIL_MARKETING_META_KEY, true);

            $customer_data = [
                'id' => $customer_object->ID,
                'email' => $email,
                Sender_Helper::EMAIL_MARKETING_META_KEY => $emailConsent,
                'firstname' => $firstname,
                'lastname' => $lastname,
            ];

            $customers[] = $customer_data;
        }

        if (empty($customers)){
            return new WP_REST_Response(['error' => 'Customers not found'], 404);
        }

        $response = ['customers' => $customers];
        return new WP_REST_Response($response, 200);
    }

    public function get_customers_by_email($data)
    {
        $customer_emails = $data['emails'];

        global $wpdb;

        $email_list = "'" . implode("', '", $customer_emails) . "'";
        $customerResults = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.ID AS post_id, pm1.meta_value AS billing_first_name, pm2.meta_value AS billing_last_name, pm3.meta_value AS email_marketing_consent,
       pm4.meta_value AS billing_email
        FROM {$wpdb->prefix}posts AS p
        LEFT JOIN {$wpdb->prefix}postmeta AS pm1 ON p.ID = pm1.post_id AND pm1.meta_key = '_billing_first_name'
        LEFT JOIN {$wpdb->prefix}postmeta AS pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_billing_last_name'
        LEFT JOIN {$wpdb->prefix}postmeta AS pm3 ON p.ID = pm3.post_id AND pm3.meta_key = 'email_marketing_consent'
        LEFT JOIN {$wpdb->prefix}postmeta AS pm4 ON p.ID = pm4.post_id AND pm4.meta_key = '_billing_email'
        WHERE p.post_type = 'shop_order' AND p.ID IN (
            SELECT pm.post_id 
            FROM {$wpdb->prefix}postmeta AS pm 
            WHERE pm.meta_key = '_billing_email' AND pm.meta_value IN ($email_list)
        )"
            )
        );


        $customers = [];
        foreach ($customerResults as $customer) {
            $email = $customer->billing_email;
            $firstname = $customer->billing_first_name;
            $lastname = $customer->billing_last_name;
            $emailConsent = unserialize($customer->email_marketing_consent);

            $customer_data = [
                'email' => $email,
                Sender_Helper::EMAIL_MARKETING_META_KEY => $emailConsent,
                'firstname' => $firstname,
                'lastname' => $lastname,
            ];

            $customers[] = $customer_data;
        }

        if (empty($customers)){
            return new WP_REST_Response(['error' => 'Customer not found'], 404);
        }

        $response = ['customers' => $customers];
        return new WP_REST_Response($response, 200);
    }

    public function sender_email_status_as_boolean($status = null)
    {
        if ($status === Sender_Helper::SUBSCRIBED) {
            return 1;
        }

        if ($status === Sender_Helper::UNSUBSCRIBED) {
            return 0;
        }

        if ($status === Sender_Helper::NOT_SUBSCRIBED) {
            return '';
        }
    }

    public function get_plugin_version()
    {
        $plugin_version = '';
        $plugin_file_path = plugin_dir_path(__FILE__) . '../sender.php';
        if (file_exists($plugin_file_path)) {
            $plugin_data = get_plugin_data($plugin_file_path);
            if (!empty($plugin_data['Version'])) {
                $plugin_version = $plugin_data['Version'];
            }
        }

        return new WP_REST_Response($plugin_version, 200);
    }

}