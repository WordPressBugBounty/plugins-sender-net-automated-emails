<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once 'Sender_Helper.php';

class Sender_API
{
    private $senderBaseUrl = 'https://api.sender.net/v2/';
    private $senderStatsBaseUrl = 'https://stats.sender.net/commerce/';

    public function __construct()
    {
        if( !function_exists('get_plugin_data') ){
            require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
        }

        add_filter('http_headers_useragent', [$this, 'senderPluginVersionUserAgent']);
    }

    public function senderPluginVersionUserAgent($user_agent)
    {
        $plugin_version = '';
        $plugin_file_path = plugin_dir_path(__FILE__) . '../sender.php';
        if (file_exists($plugin_file_path)) {
            $plugin_data = get_plugin_data($plugin_file_path);
            if (!empty($plugin_data['Version'])) {
                $plugin_version = $plugin_data['Version'];
            }
        }

        $user_agent .= '; Sender/' . $plugin_version;
        return $user_agent;
    }

    public function senderGetBaseArguments()
    {
        return [
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'Authorization' => 'Bearer ' . get_option('sender_api_key'),
            ],
        ];
    }

    public function senderBaseRequestArguments($delete = false)
    {
        if ($delete) {
            return array_merge($this->senderGetBaseArguments(), ['method' => 'DELETE']);
        }

        return $this->senderGetBaseArguments();
    }

    public function senderGetAccount()
    {
        $data = wp_remote_request($this->senderBaseUrl . 'users', $this->senderBaseRequestArguments());
        return $this->senderBuildResponse($data);
    }

    public function senderGetForms()
    {
        $data = wp_remote_request($this->senderBaseUrl . 'forms?type=embed&is_active=1&limit=100', $this->senderBaseRequestArguments());
        return $this->senderBuildResponse($data);
    }

    public function senderGetGroups()
    {
        $page = 1;
        $allGroups = [];

        do {
            $data = wp_remote_request($this->senderBaseUrl . 'tags?limit=1000&page=' . $page, $this->senderBaseRequestArguments());
            $response = $this->senderBuildResponse($data);
            if (!isset($response->data)){
                return false;
            }
            if (isset($response->data)) {
                $allGroups = array_merge($allGroups, $response->data);
            }

            $page++;
        } while ($page <= $response->meta->last_page);

        return $allGroups;
    }

    public function senderGetCart($cartHash)
    {
        $data = wp_remote_request($this->senderStatsBaseUrl . 'carts/' . $cartHash, $this->senderBaseRequestArguments());
        return $this->senderBuildStatsResponse($data);
    }

    public function senderDeleteCart($wpCartId)
    {
        $data = ['resource_key' => $this->senderGetResourceKey()];
        $params = array_merge($this->senderBaseRequestArguments(true), ['body' => json_encode($data)]);
        $response = wp_remote_request($this->senderStatsBaseUrl . 'carts/' . $wpCartId, $params);

        return $this->senderBuildStatsResponse($response);
    }

    public function senderUpdateCart(array $cartParams)
    {
        $cartParams['resource_key'] = $this->senderGetResourceKey();
        $params = array_merge($this->senderBaseRequestArguments(), ['body' => json_encode($cartParams), 'method' => 'PATCH']);

        $response = wp_remote_request($this->senderStatsBaseUrl . 'carts/' . $cartParams['external_id'], $params);

        return $this->senderBuildStatsResponse($response);
    }

    public function senderTrackRegisteredUsers($userId)
    {
        $user = get_userdata($userId);
        $list = get_option('sender_registration_list');

        if (isset($user->user_email)) {
            $firstname = !empty($user->first_name) ? $user->first_name : get_user_meta($userId, 'billing_first_name', true);
            $lastname = !empty($user->last_name) ? $user->last_name : get_user_meta($userId, 'billing_last_name', true);
            $phone = get_user_meta($userId, 'billing_phone', true);

            $data = [
                'resource_key' => $this->senderGetResourceKey(),
                'email' => $user->user_email,
                'firstname' => $firstname,
                'lastname' => $lastname,
                'store_id' => get_option('sender_store_register') ?: '',
                'customer_id' => $userId,
            ];

            if (!empty($phone)){
                $data['phone'] = $phone;
            }

            if ($list) {
                $data['list_id'] = $list;
            }

            if ($emailConsent = get_user_meta($userId, Sender_Helper::EMAIL_MARKETING_META_KEY, true)) {
                if (isset($emailConsent['state']) && $emailConsent['state'] === Sender_Helper::SUBSCRIBED) {
                    $data['newsletter'] = true;
                }
            }

            $data['ip_address'] = $this->getClientIp();

            $params = array_merge($this->senderBaseRequestArguments(), ['body' => json_encode($data)]);
            $response = wp_remote_post($this->senderStatsBaseUrl . 'create_subscriber', $params);

            return $this->senderBuildStatsResponse($response);
        }
    }

    public function senderTrackNotRegisteredUsers($userData)
    {
        if (isset($userData['email'])) {
            $userData['store_id'] = get_option('sender_store_register') ?: '';
            $userData['ip_address'] = $this->getClientIp();
            $userData['resource_key'] = $this->senderGetResourceKey();

            $params = array_merge($this->senderBaseRequestArguments(), ['body' => json_encode($userData)]);
            $response = wp_remote_post($this->senderStatsBaseUrl . 'create_subscriber', $params);

            return $this->senderBuildStatsResponse($response);
        }
    }

    public function senderTrackRegisterUserCallback($userId)
    {
        $this->senderApiShutdownCallback('senderTrackRegisteredUsers', $userId);
    }

    public function senderApiShutdownCallback($callback, $params)
    {
        register_shutdown_function([$this, $callback], $params);
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

    private function senderBuildResponse($response)
    {
        $responseCode = wp_remote_retrieve_response_code($response);
        if (is_wp_error($response) || $responseCode != 200) {
            if ($responseCode == 429) {
                return json_decode(json_encode(['xRate' => true]));
            }

            //Handle 401 unathorized response
            if ($responseCode === 401) {
                update_option('sender_api_key', false);
                update_option('sender_account_disconnected', true);
            }

            return false;
        }

        return json_decode($response['body']);
    }

    private function senderBuildStatsResponse($response)
    {
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) != 200) {
            return false;
        }

        return json_decode($response['body']);
    }

    public function senderGetStore()
    {
        $response = wp_remote_request($this->senderBaseUrl . 'stores/' . get_option('sender_store_register'), $this->senderBaseRequestArguments());

        return $this->senderBuildResponse($response);
    }

    public function senderAddStore()
    {
        $domain = get_site_url();
        $name = get_bloginfo('name');
        $domain = isset($domain) && !empty($domain) ? $domain : get_home_url();
        $name = isset($name) && !empty($name) ? $name : $domain;

        $storeParams = [
            'domain' => $domain,
            'name' => $name,
            'type' => 'wordpress'
        ];

        $params = array_merge($this->senderBaseRequestArguments(), ['body' => json_encode($storeParams)]);

        $response = wp_remote_post($this->senderBaseUrl . 'stores', $params);

        return $this->senderBuildResponse($response);
    }

    public function senderDeleteStore($deleteSubscribers = false)
    {
        $bodyParams = [
            'delete_subscribers' => $deleteSubscribers
        ];

        $removingStoreParams = array_merge($this->senderBaseRequestArguments(true), ['body' => json_encode($bodyParams)]);
        $response = wp_remote_request($this->senderBaseUrl . 'stores/' . get_option('sender_store_register'), $removingStoreParams);

        return $this->senderBuildResponse($response);
    }

    public function senderExportData($exportData)
    {
        $params = array_merge($this->senderBaseRequestArguments(), ['body' => json_encode($exportData), 'data_format' => 'body']);

        $response = wp_remote_post($this->senderBaseUrl . 'stores/' . get_option('sender_store_register') . '/import_shop_data', $params);

        return $this->senderBuildResponse($response);
    }

    public function senderTrackCart(array $cartParams)
    {
        $params = array_merge($this->senderBaseRequestArguments(), ['body' => json_encode($cartParams)]);

        $response = wp_remote_post($this->senderStatsBaseUrl . 'carts', $params);

        return $this->senderBuildStatsResponse($response);
    }

    public function updateCustomer(array $data, $email)
    {
        $data['email'] = $email;
        $data['store_id'] = get_option('sender_store_register');
        $data['resource_key'] = $this->senderGetResourceKey();
        $params = array_merge($this->senderBaseRequestArguments(), ['body' => json_encode($data), 'method' => 'PATCH']);

        $response = wp_remote_request($this->senderStatsBaseUrl . 'subscribers/store/update', $params);
        return $this->senderBuildResponse($response);
    }

    public function deleteSubscribers(array $data)
    {
        $params = array_merge($this->senderBaseRequestArguments(), ['body' => json_encode($data), 'method' => 'DELETE']);
        $response = wp_remote_request($this->senderBaseUrl . 'subscribers/' , $params);

        return $this->senderBuildResponse($response);
    }

    public function senderConvertCart($cartId, $cartData)
    {
        $url = $this->senderStatsBaseUrl . 'carts/' . $cartId . '/convert';

        $params = array_merge($this->senderBaseRequestArguments(), ['body' => json_encode($cartData)]);
        $response = wp_remote_post($url, $params);
        return $this->senderBuildStatsResponse($response);
    }

    public function senderUpdateCartStatus($cartId, $cartStatusData)
    {
        $url = $this->senderStatsBaseUrl . 'carts/' . $cartId . '/status';

        $params = array_merge($this->senderBaseRequestArguments(), ['body' => json_encode($cartStatusData), 'method' => 'PATCH']);
        $response = wp_remote_post($url, $params);
        return $this->senderBuildStatsResponse($response);
    }

    public function getSubscriber($email = false)
    {
        if (!$email || empty($email)){
            return;
        }

        $response = wp_remote_request($this->senderBaseUrl . 'subscribers/' . $email, $this->senderBaseRequestArguments());
        return $this->senderBuildResponse($response);
    }

    public function getClientIp()
    {
        if (isset($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } elseif (isset($_SERVER['HTTP_X_FORWARDED'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED'];
        } elseif (isset($_SERVER['HTTP_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_FORWARDED_FOR'];
        } elseif (isset($_SERVER['HTTP_FORWARDED'])) {
            $ip = $_SERVER['HTTP_FORWARDED'];
        } else {
            $ip = $_SERVER['REMOTE_ADDR'];
        }

        return $ip;
    }
}